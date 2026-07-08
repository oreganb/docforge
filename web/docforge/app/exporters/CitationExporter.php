<?php

namespace DocForge\Exporters;

/**
 * Forge Cite — Markdown renderer for a reference-suitability analysis
 * (`doc_class: citation-analysis`).
 *
 * Renders a suitability table, the working document annotated passage-by-passage
 * with its best-matching references and evidence terms, and a per-reference
 * evidence section. The frontmatter carries both the working fingerprint and
 * every reference fingerprint, so the analysis is reproducible and traceable to
 * exact document versions.
 */
class CitationExporter
{
    /** @param array<string,mixed> $a analyzer output */
    public function export(array $a)
    {
        $working = $a['working'];
        $refs = $a['references'];
        $passages = $a['passages'];

        $lines = $this->frontmatter($a);
        $lines[] = '# Reference Suitability — ' . $working['title'];
        $lines[] = '';
        $lines[] = 'Scored ' . count($refs) . ' candidate reference(s) against '
            . $working['passage_count'] . ' passage(s) of the working document using '
            . 'BM25 lexical association. Every affinity is measured; evidence terms are shown. '
            . 'Nothing is generated or inferred.';
        $lines[] = '';

        // 1. Suitability summary.
        $lines[] = '## 1. Suitability Summary';
        $lines[] = '';
        $lines[] = '| Reference | Suitability | Coverage | Best passages | Top shared terms |';
        $lines[] = '|---|---:|---:|---|---|';
        foreach ($refs as $r) {
            $bestLabels = array();
            $termSet = array();
            foreach (array_slice($r['top_passages'], 0, 2) as $p) {
                $bestLabels[] = $p['label'] . ' (' . number_format($p['affinity'], 2) . ')';
                foreach ($p['shared'] as $t) {
                    $termSet[$t] = true;
                }
            }
            $coverage = isset($r['coverage']) ? $r['coverage'] : 0;
            $total = isset($r['passage_total']) ? $r['passage_total'] : 0;
            $lines[] = '| ' . $this->cell($r['title'])
                . ' | ' . $r['suitability'] . '%'
                . ' | ' . $coverage . '/' . $total
                . ' | ' . $this->cell(empty($bestLabels) ? '—' : implode('; ', $bestLabels))
                . ' | ' . $this->cell(empty($termSet) ? '—' : implode(', ', array_slice(array_keys($termSet), 0, 6)))
                . ' |';
        }
        $lines[] = '';

        // 2. Annotated document.
        $lines[] = '## 2. Annotated Document';
        $lines[] = '';
        foreach ($passages as $p) {
            if ($p['title'] !== '') {
                $lines[] = '### ' . $p['title'];
            } else {
                $lines[] = '### ' . $p['label'];
            }
            $lines[] = '';
            $lines[] = $p['text'];
            $lines[] = '';
            if (empty($p['matches'])) {
                $lines[] = '> _No candidate reference exceeds the affinity threshold for this passage._';
            } else {
                foreach ($p['matches'] as $m) {
                    $lines[] = '> **[' . $this->inline($m['ref_title']) . ' — affinity '
                        . number_format($m['affinity'], 2) . ']** shared terms: '
                        . implode(', ', $m['shared']);
                }
            }
            $lines[] = '';
        }

        // 3. Per-reference evidence.
        $lines[] = '## 3. Per-Reference Evidence';
        $lines[] = '';
        foreach ($refs as $r) {
            $cov = isset($r['coverage']) ? $r['coverage'] : 0;
            $tot = isset($r['passage_total']) ? $r['passage_total'] : 0;
            $lines[] = '### ' . $r['title'] . ' — ' . $r['suitability']
                . '% (covers ' . $cov . '/' . $tot . ' passages)';
            $lines[] = '';
            $lines[] = '- **Source:** ' . ($r['source_name'] !== '' ? $r['source_name'] : '—');
            $lines[] = '- **Fingerprint:** `' . $r['sha256'] . '`';
            $lines[] = '';
            if (empty($r['top_passages'])) {
                $lines[] = '_No passage of the working document associates with this reference above the threshold._';
                $lines[] = '';
                continue;
            }
            $lines[] = '| Passage | Affinity | Shared terms |';
            $lines[] = '|---|---:|---|';
            foreach ($r['top_passages'] as $p) {
                $lines[] = '| ' . $this->cell($p['label'])
                    . ' | ' . number_format($p['affinity'], 2)
                    . ' | ' . $this->cell(implode(', ', $p['shared'])) . ' |';
            }
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = '_Method: Okapi BM25 (k1=1.5, b=0.75) over reference texts; affinity is the '
            . 'passage–reference score normalised to the strongest match in this comparison. '
            . 'Lexical stage only — a sentence-embedding re-rank is the deferred Stage 2._';

        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $a */
    private function frontmatter(array $a)
    {
        $refShas = array();
        foreach ($a['references'] as $r) {
            if ($r['sha256'] !== '') {
                $refShas[] = $r['sha256'];
            }
        }
        $lines = array();
        $lines[] = '---';
        $lines[] = 'title: ' . $this->yaml('Reference Suitability — ' . $a['working']['title']);
        $lines[] = 'doc_class: citation-analysis';
        $lines[] = 'working_fingerprint: ' . $this->yaml($a['working']['sha256']);
        $lines[] = 'reference_fingerprints: [' . implode(', ', array_map(array($this, 'yaml'), $refShas)) . ']';
        $lines[] = 'reference_count: ' . count($a['references']);
        $lines[] = 'fingerprint: ' . $this->yaml($a['fingerprint']);
        $lines[] = 'generated_at: ' . $this->yaml($a['generated_at']);
        $lines[] = '---';
        $lines[] = '';
        return $lines;
    }

    private function yaml($value)
    {
        $value = (string) $value;
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);
        $value = preg_replace('/\s*\R\s*/u', ' ', $value);
        return '"' . $value . '"';
    }

    private function cell($s)
    {
        $s = (string) $s;
        $s = preg_replace('/\s*\R\s*/u', ' ', $s);
        $s = str_replace('|', '\\|', $s);
        return trim($s);
    }

    private function inline($s)
    {
        $s = (string) $s;
        $s = preg_replace('/\s*\R\s*/u', ' ', $s);
        return trim(str_replace(array('[', ']'), array('(', ')'), $s));
    }
}
