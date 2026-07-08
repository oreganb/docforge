<?php

namespace DocForge\Exporters;

use DocForge\Trust\KnowledgeScore;

/**
 * Forge Data — Markdown renderer for dataset (`doc_class: dataset`) reports.
 *
 * Produces a report an LLM can reason from without seeing the raw rows: schema
 * and inferred types, distributions, a correlation matrix, and a Key Findings
 * section of measured, located facts. The `context` profile drops distributions
 * and keeps schema + findings for a minimal query payload.
 */
class DataMarkdownExporter
{
    /** @param array<string,mixed> $doc */
    public function export(array $doc, $profileMode = 'full')
    {
        $profile = isset($doc['profile']) ? $doc['profile'] : array();
        $quality = isset($doc['quality']) ? $doc['quality'] : array();
        $fp = isset($doc['fingerprint']) ? $doc['fingerprint'] : array();
        $title = isset($doc['title']) ? $doc['title'] : 'Dataset';
        $score = isset($quality['knowledge_score']) ? (int) $quality['knowledge_score'] : 0;
        $compact = ($profileMode === 'context');

        $lines = $this->frontmatter($doc, $profile, $quality, $title, $score);
        $lines[] = '# ' . $title;
        $lines[] = '';
        $lines[] = '## 1. Overview';
        $lines[] = '';
        $lines[] = isset($doc['summaries']['short']) ? $doc['summaries']['short'] : '';
        $lines[] = '';
        if (!empty($profile['truncated'])) {
            $lines[] = '_Only the first ' . number_format($profile['rows_scanned'])
                . ' of ' . number_format($profile['row_count'])
                . ' rows were scanned (row cap); statistics are computed over the scanned rows._';
            $lines[] = '';
        }

        $lines[] = '## 2. Knowledge Score';
        $lines[] = '';
        $lines[] = '**' . $score . '%** ' . KnowledgeScore::starString($score);
        $lines[] = '';
        if (!empty($quality['sub_scores'])) {
            $lines[] = '| Dimension | Score |';
            $lines[] = '|---|---:|';
            foreach ($quality['sub_scores'] as $k => $v) {
                $lines[] = '| ' . ucfirst($k) . ' | ' . (int) $v . '% |';
            }
            $lines[] = '';
        }

        $lines[] = '## 3. Dataset Metadata';
        $lines[] = '';
        $lines[] = '- **SHA-256:** `' . (isset($fp['sha256']) ? $fp['sha256'] : '') . '`';
        $lines[] = '- **Source:** ' . (isset($fp['source_name']) ? $fp['source_name'] : '');
        $lines[] = '- **Format:** ' . (isset($profile['format']) ? $profile['format'] : '');
        $lines[] = '- **Rows:** ' . number_format(isset($profile['row_count']) ? $profile['row_count'] : 0);
        $lines[] = '- **Columns:** ' . (isset($profile['column_count']) ? $profile['column_count'] : 0);
        $lines[] = '- **Size:** ' . $this->formatBytes(isset($fp['size_bytes']) ? $fp['size_bytes'] : 0);
        $lines[] = '- **Extracted:** ' . (isset($fp['extracted_at']) ? $fp['extracted_at'] : gmdate('c'));
        if (!empty($fp['duplicate_of']) && !empty($fp['duplicate_of']['report_id'])) {
            $dup = $fp['duplicate_of'];
            $when = isset($dup['created_at']) ? substr($dup['created_at'], 0, 10) : '';
            $lines[] = '- **Duplicate:** previously processed'
                . ($when !== '' ? ' on ' . $when : '') . ' as report #' . $dup['report_id'];
        }
        $lines[] = '';

        $lines[] = '## 4. Quality Verdict';
        $lines[] = '';
        $lines[] = isset($quality['verdict']) ? $quality['verdict'] : '';
        $lines[] = '';
        if (!empty($quality['issues'])) {
            $lines[] = '**Issues**';
            $lines[] = '';
            foreach ($quality['issues'] as $issue) {
                $lines[] = '- ' . $issue;
            }
            $lines[] = '';
        }
        if (!empty($quality['notes'])) {
            $lines[] = '**Transparency notes**';
            $lines[] = '';
            foreach ($quality['notes'] as $note) {
                $lines[] = '- _' . $note . '_';
            }
            $lines[] = '';
        }

        // Key Findings (measured, located facts).
        if (!empty($doc['summaries']['key_findings'])) {
            $lines[] = '## 5. Key Findings';
            $lines[] = '';
            foreach ($doc['summaries']['key_findings'] as $f) {
                $lines[] = '- ' . $f;
            }
            $lines[] = '';
        }

        // Column profile.
        $lines[] = '## 6. Column Profile';
        $lines[] = '';
        foreach ($this->columnTable($profile, $compact) as $tl) {
            $lines[] = $tl;
        }
        $lines[] = '';

        // Correlation matrix (dropped in compact mode).
        if (!$compact && !empty($profile['correlations']['columns'])) {
            $lines[] = '## 7. Correlation Matrix';
            $lines[] = '';
            $lines[] = '_Pearson correlation between numeric columns (measured)._';
            $lines[] = '';
            foreach ($this->correlationTable($profile['correlations']) as $tl) {
                $lines[] = $tl;
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<int,string>
     */
    private function frontmatter(array $doc, array $profile, array $quality, $title, $score)
    {
        $fp = isset($doc['fingerprint']) ? $doc['fingerprint'] : array();
        $flags = array();
        foreach (isset($profile['columns']) ? $profile['columns'] : array() as $c) {
            if (!empty($c['pii'])) {
                $flags[] = 'pii';
                break;
            }
        }
        if (!empty($profile['duplicate_rows'])) {
            $flags[] = 'duplicates';
        }
        if (!empty($profile['truncated'])) {
            $flags[] = 'row_capped';
        }
        foreach (isset($quality['issues']) ? $quality['issues'] : array() as $i) {
            if (stripos($i, 'mixed-type') !== false) {
                $flags[] = 'mixed_type';
                break;
            }
        }
        $flags = array_values(array_unique($flags));

        $dup = (!empty($fp['duplicate_of']) && !empty($fp['duplicate_of']['report_id']))
            ? (int) $fp['duplicate_of']['report_id'] : null;

        $lines = array();
        $lines[] = '---';
        $lines[] = 'title: ' . $this->yaml($title);
        $lines[] = 'doc_class: dataset';
        $lines[] = 'knowledge_score: ' . (int) $score;
        $lines[] = 'verdict_flags: ' . (empty($flags) ? '[]' : '[' . implode(', ', $flags) . ']');
        $lines[] = 'source: ' . $this->yaml(isset($fp['source_name']) ? $fp['source_name'] : '');
        $lines[] = 'format: ' . $this->yaml(isset($profile['format']) ? $profile['format'] : '');
        $lines[] = 'rows: ' . (isset($profile['row_count']) ? (int) $profile['row_count'] : 0);
        $lines[] = 'columns: ' . (isset($profile['column_count']) ? (int) $profile['column_count'] : 0);
        $lines[] = 'fingerprint: ' . $this->yaml(isset($fp['sha256']) ? $fp['sha256'] : '');
        $lines[] = 'duplicate_of: ' . ($dup === null ? 'null' : $dup);
        $lines[] = 'extracted_at: ' . $this->yaml(isset($fp['extracted_at']) ? $fp['extracted_at'] : gmdate('c'));
        $lines[] = '---';
        $lines[] = '';
        return $lines;
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<int,string>
     */
    private function columnTable(array $profile, $compact)
    {
        $cols = isset($profile['columns']) ? $profile['columns'] : array();
        $out = array();
        $out[] = '| Column | Type | Non-null | Null % | Distinct | Summary |';
        $out[] = '|---|---|---:|---:|---:|---|';
        foreach ($cols as $c) {
            $out[] = '| ' . $this->cell($c['name'])
                . ' | ' . $c['type'] . ($c['pii'] ? ' 🔒' : '')
                . ' | ' . number_format($c['count'])
                . ' | ' . number_format($c['null_rate'] * 100, 1) . '%'
                . ' | ' . number_format($c['distinct'])
                . ' | ' . $this->cell($this->summarise($c, $compact)) . ' |';
        }
        return $out;
    }

    /** One-cell distribution summary for a column. */
    private function summarise(array $c, $compact)
    {
        if (in_array($c['type'], array('integer', 'float'), true) && isset($c['mean'])) {
            $s = 'min ' . $c['min'] . ', max ' . $c['max'] . ', mean ' . $c['mean'];
            if (!$compact) {
                $s .= ', median ' . $c['median'] . ', std ' . $c['std'];
                if (!empty($c['outliers'])) {
                    $s .= ', ' . $c['outliers'] . ' outlier(s)';
                }
            }
            return $s;
        }
        if ($c['type'] === 'date' && isset($c['date_min'])) {
            return $c['date_min'] . ' → ' . $c['date_max'];
        }
        if (!empty($c['top'])) {
            $parts = array();
            foreach ($c['top'] as $t) {
                $parts[] = $t['value'] . ' (' . $t['count'] . ')';
            }
            return 'top: ' . implode('; ', $parts);
        }
        if ($c['pii']) {
            return $c['pii_reason'];
        }
        return '';
    }

    /**
     * @param array<string,mixed> $corr
     * @return array<int,string>
     */
    private function correlationTable(array $corr)
    {
        $names = $corr['columns'];
        $matrix = $corr['matrix'];
        $out = array();
        $out[] = '| | ' . implode(' | ', array_map(array($this, 'cell'), $names)) . ' |';
        $out[] = '|---' . str_repeat('|---:', count($names)) . '|';
        foreach ($names as $i => $name) {
            $cells = array($this->cell($name));
            foreach ($matrix[$i] as $v) {
                $cells[] = $v === null ? '—' : number_format($v, 2);
            }
            $out[] = '| ' . implode(' | ', $cells) . ' |';
        }
        return $out;
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

    private function formatBytes($bytes)
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 0) . ' KB';
        }
        return $bytes . ' B';
    }
}
