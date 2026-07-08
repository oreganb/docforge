<?php

namespace DocForge\Analysis;

/**
 * Forge Cite — reference suitability analysis (stage one, lexical).
 *
 * Scores how suitable each candidate reference is for a working document, per
 * passage, using Okapi BM25 over the references as the document collection and
 * each working-document passage as the query. Every affinity is a measured
 * lexical association with its evidence terms shown — nothing is claimed or
 * generated, consistent with the trust layer. A neural re-rank (sentence
 * embeddings) is the deferred Stage 2.
 */
class CitationAnalyzer
{
    const K1 = 1.5;
    const B = 0.75;
    const MAX_PASSAGES = 80;
    /** Minimum affinity for a match to be surfaced in the annotation. */
    const MIN_AFFINITY = 0.15;

    /** @var array<string,bool> */
    private $stop;

    public function __construct()
    {
        $this->stop = array_fill_keys(explode(' ',
            'the a an and or but if then else of to in on at by for with about against between into '
            . 'through during before after above below from up down out off over under again further once here '
            . 'there all any both each few more most other some such no nor not only own same so than too very '
            . 'can will just should now is are was were be been being have has had do does did doing this that '
            . 'these those it its it\'s they them their what which who whom whose when where why how as also may '
            . 'might must shall would could we you your our us he she his her him i me my mine ours theirs '
            . 'per via etc eg ie within without upon among across around because while whereas thus hence '
            . 'et al fig figure table section chapter'
        ), true);
    }

    /**
     * @param array<string,mixed> $working  ['title','sha256','doc']
     * @param array<int,array<string,mixed>> $references each ['id','title','sha256','source_name','doc']
     * @return array<string,mixed>
     */
    public function analyse(array $working, array $references)
    {
        $passages = $this->passages(isset($working['doc']) ? $working['doc'] : array());
        if (empty($passages)) {
            throw new \RuntimeException('The working document has no analysable passages.');
        }

        // Build the reference collection: token frequencies + lengths.
        $refDocs = array();
        $df = array();
        $totalLen = 0;
        foreach ($references as $i => $ref) {
            $tokens = $this->tokenize($this->docText(isset($ref['doc']) ? $ref['doc'] : array()));
            $tf = array();
            foreach ($tokens as $t) {
                $tf[$t] = isset($tf[$t]) ? $tf[$t] + 1 : 1;
            }
            $len = count($tokens);
            $totalLen += $len;
            foreach (array_keys($tf) as $t) {
                $df[$t] = isset($df[$t]) ? $df[$t] + 1 : 1;
            }
            $refDocs[$i] = array('tf' => $tf, 'len' => $len);
        }
        $n = count($references);
        $avgdl = $n > 0 ? $totalLen / $n : 1;

        // Score every (passage, reference) pair.
        $scores = array();   // [pIdx][refIdx] => score
        $shared = array();   // [pIdx][refIdx] => [term => contribution]
        $globalMax = 0.0;
        foreach ($passages as $pIdx => $passage) {
            $qtf = array();
            foreach ($this->tokenize($passage['text']) as $t) {
                $qtf[$t] = isset($qtf[$t]) ? $qtf[$t] + 1 : 1;
            }
            foreach ($refDocs as $refIdx => $rd) {
                $score = 0.0;
                $terms = array();
                foreach ($qtf as $term => $q) {
                    if (!isset($rd['tf'][$term])) {
                        continue;
                    }
                    $idf = log(1 + ($n - $df[$term] + 0.5) / ($df[$term] + 0.5));
                    $f = $rd['tf'][$term];
                    $denom = $f + self::K1 * (1 - self::B + self::B * ($rd['len'] / max(1, $avgdl)));
                    $contrib = $idf * ($f * (self::K1 + 1)) / max(1e-9, $denom);
                    $contrib *= min($q, 5); // modest weight for repeated query terms
                    $score += $contrib;
                    $terms[$term] = $contrib;
                }
                $scores[$pIdx][$refIdx] = $score;
                $shared[$pIdx][$refIdx] = $terms;
                if ($score > $globalMax) {
                    $globalMax = $score;
                }
            }
        }
        $norm = $globalMax > 0 ? $globalMax : 1.0;

        // Assemble per-passage matches and per-reference aggregates.
        $refAffinities = array_fill(0, $n, array());
        $passOut = array();
        foreach ($passages as $pIdx => $passage) {
            $matches = array();
            foreach ($refDocs as $refIdx => $rd) {
                $aff = round($scores[$pIdx][$refIdx] / $norm, 2);
                $refAffinities[$refIdx][] = $aff;
                if ($aff >= self::MIN_AFFINITY) {
                    $matches[] = array(
                        'ref_index' => $refIdx,
                        'ref_title' => (string) $references[$refIdx]['title'],
                        'affinity' => $aff,
                        'shared' => $this->topTerms($shared[$pIdx][$refIdx], 6),
                    );
                }
            }
            usort($matches, function ($a, $b) {
                return $b['affinity'] <=> $a['affinity'];
            });
            $passOut[] = array(
                'label' => $passage['label'],
                'title' => $passage['title'],
                'text' => $passage['text'],
                'matches' => array_slice($matches, 0, 3),
            );
        }

        // Reference suitability = mean of its top-k *matched* passage affinities
        // (passages it does not associate with are excluded so a reference that
        // is strongly suited to one section is not diluted by unrelated ones).
        // Coverage separately reports how much of the document it supports.
        $refOut = array();
        $passageTotal = count($passOut);
        foreach ($references as $refIdx => $ref) {
            $matched = array();
            foreach ($refAffinities[$refIdx] as $aff) {
                if ($aff >= self::MIN_AFFINITY) {
                    $matched[] = $aff;
                }
            }
            rsort($matched);
            if (empty($matched)) {
                $suitability = 0;
            } else {
                $k = min(5, count($matched));
                $suitability = (int) round(100 * (array_sum(array_slice($matched, 0, $k)) / $k));
            }
            $coverage = count($matched);

            // Best matched passages for this reference (with evidence).
            $best = array();
            foreach ($passOut as $p) {
                foreach ($p['matches'] as $m) {
                    if ($m['ref_index'] === $refIdx) {
                        $best[] = array(
                            'label' => $p['label'],
                            'affinity' => $m['affinity'],
                            'shared' => $m['shared'],
                        );
                    }
                }
            }
            usort($best, function ($a, $b) {
                return $b['affinity'] <=> $a['affinity'];
            });
            $refOut[] = array(
                'id' => isset($ref['id']) ? $ref['id'] : null,
                'title' => (string) $ref['title'],
                'sha256' => isset($ref['sha256']) ? $ref['sha256'] : '',
                'source_name' => isset($ref['source_name']) ? $ref['source_name'] : '',
                'suitability' => $suitability,
                'coverage' => $coverage,
                'passage_total' => $passageTotal,
                'top_passages' => array_slice($best, 0, 5),
            );
        }
        usort($refOut, function ($a, $b) {
            return $b['suitability'] <=> $a['suitability'];
        });

        $seed = array((string) (isset($working['sha256']) ? $working['sha256'] : ''));
        foreach ($references as $ref) {
            $seed[] = (string) (isset($ref['sha256']) ? $ref['sha256'] : '');
        }
        sort($seed);

        return array(
            'generated_at' => gmdate('c'),
            'fingerprint' => hash('sha256', implode('|', $seed)),
            'working' => array(
                'title' => (string) (isset($working['title']) ? $working['title'] : 'Document'),
                'sha256' => (string) (isset($working['sha256']) ? $working['sha256'] : ''),
                'passage_count' => count($passOut),
            ),
            'references' => $refOut,
            'passages' => $passOut,
        );
    }

    /**
     * Segment a working document into passages (one per heading section). Falls
     * back to fixed-size word windows when the document has no headings.
     *
     * @param array<string,mixed> $doc
     * @return array<int,array{label:string,title:string,text:string}>
     */
    private function passages(array $doc)
    {
        $blocks = isset($doc['blocks']) ? $doc['blocks'] : array();
        $passages = array();
        $cur = null;
        foreach ($blocks as $b) {
            $type = isset($b['type']) ? $b['type'] : 'paragraph';
            $text = $this->blockText($b);
            if ($type === 'heading') {
                if ($cur !== null && trim($cur['text']) !== '') {
                    $passages[] = $cur;
                }
                $cur = array('label' => $text !== '' ? $text : 'Section', 'title' => $text, 'text' => '');
                continue;
            }
            if ($text === '') {
                continue;
            }
            if ($cur === null) {
                $cur = array('label' => 'Preamble', 'title' => '', 'text' => '');
            }
            $cur['text'] = trim($cur['text'] . ' ' . $text);
        }
        if ($cur !== null && trim($cur['text']) !== '') {
            $passages[] = $cur;
        }

        // No headings (or one giant passage): window the full text instead.
        if (count($passages) <= 1) {
            $full = trim($this->docText($doc));
            if ($full !== '') {
                $words = preg_split('/\s+/u', $full);
                $windowed = array();
                $size = 120;
                $total = ceil(count($words) / $size);
                for ($i = 0, $p = 1; $i < count($words); $i += $size, $p++) {
                    $chunk = implode(' ', array_slice($words, $i, $size));
                    $windowed[] = array(
                        'label' => 'Passage ' . $p . ' of ' . $total,
                        'title' => '',
                        'text' => $chunk,
                    );
                }
                if (!empty($windowed)) {
                    $passages = $windowed;
                }
            }
        }

        return array_slice($passages, 0, self::MAX_PASSAGES);
    }

    /** @param array<string,mixed> $doc */
    private function docText(array $doc)
    {
        if (!empty($doc['full_text']) && is_string($doc['full_text'])) {
            return $doc['full_text'];
        }
        $parts = array();
        foreach (isset($doc['blocks']) ? $doc['blocks'] : array() as $b) {
            $t = $this->blockText($b);
            if ($t !== '') {
                $parts[] = $t;
            }
        }
        return implode(' ', $parts);
    }

    /** @param array<string,mixed> $b */
    private function blockText(array $b)
    {
        if (!empty($b['text'])) {
            return (string) $b['text'];
        }
        // Flatten a structured table block, if present.
        if (isset($b['rows']) && is_array($b['rows'])) {
            $cells = array();
            foreach ($b['rows'] as $row) {
                if (is_array($row)) {
                    foreach ($row as $cell) {
                        $cells[] = (string) $cell;
                    }
                }
            }
            return implode(' ', $cells);
        }
        return '';
    }

    /**
     * @param string $text
     * @return array<int,string>
     */
    private function tokenize($text)
    {
        $text = mb_strtolower((string) $text);
        $text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text);
        $tokens = array();
        foreach (preg_split('/\s+/u', $text) as $tok) {
            $tok = trim($tok, "-");
            if (mb_strlen($tok) < 3) {
                continue;
            }
            if (preg_match('/^\d+$/', $tok)) {
                continue; // bare numbers carry no citation signal
            }
            if (isset($this->stop[$tok])) {
                continue;
            }
            $tokens[] = $tok;
        }
        return $tokens;
    }

    /**
     * @param array<string,float> $terms
     * @return array<int,string>
     */
    private function topTerms(array $terms, $limit)
    {
        arsort($terms);
        return array_slice(array_keys($terms), 0, $limit);
    }
}
