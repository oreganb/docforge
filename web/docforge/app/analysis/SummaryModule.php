<?php

namespace DocForge\Analysis;

use PhpScience\TextRank\TextRankFacade;
use PhpScience\TextRank\Tool\StopWords\English;
use PhpScience\TextRank\Tool\Summarize;

class SummaryModule extends AbstractModule
{
    public function applies(array $ir)
    {
        return !empty($ir['full_text']) && strlen($ir['full_text']) > 100;
    }

    /** Cap the text fed to TextRank to keep the O(n^2) graph fast on large docs. */
    const MAX_CHARS = 40000;

    /** A document is "list-dominant" when most blocks are bullet items. */
    const LIST_DOMINANT = 0.5;

    /**
     * A sentence carries a genuine finding when it has real quantitative signal
     * (a percentage or a multi-digit quantity) or a conclusive verb. Single
     * digits (e.g. "Grade 7", "Admin II") are labels, not findings.
     */
    const FINDING_SIGNAL = '/%|\d{2,}|\b(therefore|thus|conclude[ds]?|concluded|significant(ly)?|increase[ds]?|decrease[ds]?|reduced|improved)\b/i';

    /** @var array<int,string> normalised furniture lines removed upstream */
    private $furniture = array();

    public function analyse(array $ir)
    {
        // Cross-check source: a Key Finding must never echo a line the furniture
        // detector removed (running headers/footers, metadata blocks).
        $this->furniture = array();
        foreach (isset($ir['removed_chrome']) ? $ir['removed_chrome'] : array() as $line) {
            $norm = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $line)));
            if (mb_strlen($norm) >= 8) {
                $this->furniture[$norm] = true;
            }
        }

        // List-dominant documents (competency frameworks, checklists, spec
        // sheets) give sentence-ranking no signal — ranked prose assumptions
        // don't hold. Switch to a deterministic, structure-templated summary
        // assembled entirely from the extracted section headings.
        $headings = isset($ir['headings']) ? $ir['headings'] : array();
        $listRatio = isset($ir['list_ratio']) ? $ir['list_ratio'] : 0.0;
        if ($listRatio >= self::LIST_DOMINANT && count($headings) >= 2) {
            return $this->templatedSummary($ir, $headings);
        }

        $text = $ir['full_text'];
        $sentences = $this->sentences($text);
        $short = '';
        $extended = '';
        $findings = array();

        // Template documents (forms, appraisals) repeat their scaffolding by
        // design, and TextRank rewards repetition — so boilerplate label lines
        // out-rank real prose. Mirror the page-furniture detector one level
        // down: sentences that recur near-identically across the document are
        // scaffolding, so strip them before ranking and keep them out of the
        // summary/findings.
        $templates = $this->templateSignatures($sentences);
        $ranked = array();
        try {
            $deScaffolded = array();
            foreach ($sentences as $s) {
                if (!isset($templates[$this->signature($s)])) {
                    $deScaffolded[] = $s;
                }
            }
            // Guard: if stripping leaves too little, rank the original text.
            $rankText = count($deScaffolded) >= 3 ? implode(' ', $deScaffolded) : $text;
            $input = strlen($rankText) > self::MAX_CHARS ? substr($rankText, 0, self::MAX_CHARS) : $rankText;
            $facade = new TextRankFacade();
            $facade->setStopWords(new English());
            // Returns an array of the most important sentences (index => sentence).
            $topSentences = $facade->summarizeTextFreely($input, 12, 8, Summarize::GET_ALL_IMPORTANT);
            $ranked = array_values(array_filter(array_map('trim', $topSentences)));
        } catch (\Throwable $e) {
            $ranked = array();
        }

        // Belt and braces: drop any scaffolding that still slipped through.
        $ranked = array_values(array_filter($ranked, function ($s) use ($templates) {
            return !isset($templates[$this->signature($s)]);
        }));

        if (empty($ranked)) {
            // Fallback: lead sentences that are not scaffolding.
            foreach ($sentences as $s) {
                if (!isset($templates[$this->signature($s)])) {
                    $ranked[] = $s;
                }
                if (count($ranked) >= 8) {
                    break;
                }
            }
            if (empty($ranked)) {
                $ranked = array_slice($sentences, 0, 8);
            }
        }

        $shortParts = array_slice($ranked, 0, 5);
        $short = $this->trimWords(implode(' ', $shortParts), 150);
        $extended = $this->trimWords(implode(' ', $ranked), 500);
        foreach ($ranked as $sentence) {
            if (preg_match(self::FINDING_SIGNAL, $sentence)
                && $this->isGenuineFinding($sentence)
                && !isset($templates[$this->signature($sentence)])) {
                $findings[] = $sentence;
            }
        }

        if ($short === '' && !empty($sentences)) {
            $short = $this->trimWords($sentences[0], 150);
        }

        // Order by quantitative density so a data-rich finding ("€750,000") beats
        // an incidental dateline, without a fragile "is-this-metadata" filter.
        $findings = array_slice($this->rankByDensity($findings), 0, 5);
        return array(
            'summaries' => array(
                'short' => $short,
                'extended' => $extended,
                'key_findings' => $findings,
                'findings_note' => empty($findings)
                    ? 'No quantitative or conclusive findings detected.'
                    : '',
            ),
        );
    }

    /**
     * Build a heading-templated summary for list-dominant documents. Every word
     * comes from the extracted structure — no generation, no invented content.
     */
    private function templatedSummary(array $ir, array $headings)
    {
        $lead = $this->leadSentence($ir);
        $n = count($headings);
        $joined = implode('; ', $headings);
        $body = 'Defines ' . $this->numberWord($n) . ' sections: ' . $joined . '.';
        $short = trim(($lead !== '' ? $lead . ' ' : '') . $body);

        // Key Findings must not simply echo the Contents tree. Only surface
        // genuinely quantitative/conclusive sentences from the body; if a
        // list-type document has none, say so honestly (FR-4.3).
        $findings = $this->extractFindings($ir);

        return array(
            'summaries' => array(
                'short' => $this->trimWords($short, 150),
                'extended' => $this->trimWords($short, 500),
                'key_findings' => $findings,
                'findings_note' => empty($findings)
                    ? 'No quantitative or conclusive findings detected (list-type document).'
                    : '',
                'strategy' => 'structure-templated',
            ),
        );
    }

    /**
     * Scan body blocks (lists + paragraphs, not headings) for sentences that
     * carry quantitative or conclusive signal. Returns up to five, or an empty
     * array when the document is purely descriptive.
     *
     * @return array<int,string>
     */
    private function extractFindings(array $ir)
    {
        if (empty($ir['blocks']) || !is_array($ir['blocks'])) {
            return array();
        }
        // Collect every body sentence so we can suppress recurring scaffolding
        // (e.g. "Start Month: … | End Month: …") the same way the summary does.
        $sentences = array();
        foreach ($ir['blocks'] as $block) {
            $type = isset($block['type']) ? $block['type'] : '';
            if ($type !== 'list' && $type !== 'paragraph') {
                continue;
            }
            foreach ($this->sentences((string) $block['text']) as $sentence) {
                $sentences[] = trim($sentence);
            }
        }
        $templates = $this->templateSignatures($sentences);
        $candidates = array();
        foreach ($sentences as $sentence) {
            if (preg_match(self::FINDING_SIGNAL, $sentence)
                && $this->isGenuineFinding($sentence)
                && !isset($templates[$this->signature($sentence)])) {
                $candidates[] = $sentence;
            }
        }
        // Rank by quantitative density, then keep the strongest five.
        return array_slice($this->rankByDensity($candidates), 0, 5);
    }

    /**
     * Order findings by quantitative density (currency, percentages, multi-digit
     * quantities, conclusive verbs) so data-rich statements lead and incidental
     * datelines fall away — a ranking, not an exclusion filter.
     *
     * @param array<int,string> $findings
     * @return array<int,string>
     */
    private function rankByDensity(array $findings)
    {
        // De-duplicate while preserving first appearance.
        $seen = array();
        $unique = array();
        foreach ($findings as $f) {
            $key = mb_strtolower(preg_replace('/\s+/', ' ', trim($f)));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $f;
        }
        // Stable sort by descending density (usort is not stable, so carry index).
        $indexed = array();
        foreach ($unique as $i => $f) {
            $indexed[] = array('i' => $i, 'text' => $f, 'score' => $this->densityScore($f));
        }
        usort($indexed, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['i'] - $b['i'];
            }
            return $b['score'] - $a['score'];
        });
        $out = array();
        foreach ($indexed as $row) {
            $out[] = $row['text'];
        }
        return $out;
    }

    private function densityScore($sentence)
    {
        $s = (string) $sentence;
        $score = 0;
        $score += 4 * preg_match_all('/[€£$]/u', $s);                 // currency
        $score += 3 * preg_match_all('/\d+(?:\.\d+)?\s*%/u', $s);      // percentages
        $score += 2 * preg_match_all('/\b\d{2,}\b/u', $s);            // multi-digit quantities
        $score += 1 * preg_match_all('/\b(therefore|thus|conclude[ds]?|significant(ly)?|increase[ds]?|decrease[ds]?|reduced|improved)\b/i', $s);
        return $score;
    }

    /** First sentence of the first paragraph block (document lead-in), if any. */
    private function leadSentence(array $ir)
    {
        if (empty($ir['blocks']) || !is_array($ir['blocks'])) {
            return '';
        }
        foreach ($ir['blocks'] as $block) {
            if (isset($block['type']) && $block['type'] === 'heading') {
                return ''; // reached the first heading before any prose lead-in
            }
            if (isset($block['type']) && $block['type'] === 'paragraph' && trim($block['text']) !== '') {
                $parts = $this->sentences($block['text']);
                return isset($parts[0]) ? trim($parts[0]) : trim($block['text']);
            }
        }
        return '';
    }

    private function numberWord($n)
    {
        $words = array(1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
            6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
            11 => 'eleven', 12 => 'twelve');
        return isset($words[$n]) ? $words[$n] : (string) $n;
    }

    /**
     * A finding must be document substance, not chrome: it may not echo a line
     * the furniture detector removed, nor read like a metadata/table row (a run
     * of dates with no prose).
     */
    private function isGenuineFinding($sentence)
    {
        $norm = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $sentence)));
        if ($norm === '') {
            return false;
        }
        foreach ($this->furniture as $fLine => $_) {
            if (mb_strpos($norm, $fLine) !== false) {
                return false; // echoes removed furniture
            }
        }
        // Multiple slashed dates ⇒ a table/revision row, not a finding.
        if (preg_match_all('#\b\d{1,2}/\d{1,2}/\d{2,4}\b#', $sentence) >= 2) {
            return false;
        }
        return true;
    }

    private function sentences($text)
    {
        $parts = preg_split('/(?<=[.!?])\s+/', trim($text));
        return array_values(array_filter($parts));
    }

    /**
     * Signature for near-identical matching. Label lines ("Position: X",
     * "Competency Alignment (Grade 7): Y") share the part before the colon, so
     * that is the signature; other sentences use their normalised whole text.
     */
    private function signature($sentence)
    {
        $s = trim((string) $sentence);
        if (preg_match('/^(.{2,60}?):\s/u', $s, $m)) {
            return mb_strtolower(preg_replace('/\s+/', ' ', trim($m[1])));
        }
        return mb_strtolower(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Signatures that recur across the document (≥ 2 occurrences) are template
     * scaffolding. Returns them as a set keyed by signature.
     *
     * @param array<int,string> $sentences
     * @return array<string,bool>
     */
    private function templateSignatures(array $sentences)
    {
        $counts = array();
        foreach ($sentences as $s) {
            $sig = $this->signature($s);
            if ($sig === '') {
                continue;
            }
            $counts[$sig] = isset($counts[$sig]) ? $counts[$sig] + 1 : 1;
        }
        $templates = array();
        foreach ($counts as $sig => $n) {
            if ($n >= 2) {
                $templates[$sig] = true;
            }
        }
        return $templates;
    }

    private function trimWords($text, $max)
    {
        $words = preg_split('/\s+/', trim($text));
        if (count($words) <= $max) {
            return trim($text);
        }
        return implode(' ', array_slice($words, 0, $max)) . '…';
    }

    protected static function toolName()
    {
        return 'TextRank';
    }

    public function confidence()
    {
        return 72;
    }
}
