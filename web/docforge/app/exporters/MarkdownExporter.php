<?php

namespace DocForge\Exporters;

use DocForge\Trust\KnowledgeScore;

class MarkdownExporter
{
    /**
     * @param array<string,mixed> $doc
     */
    public function export(array $doc)
    {
        $title = isset($doc['title']) ? $doc['title'] : 'Untitled';
        $score = isset($doc['quality']['knowledge_score']) ? $doc['quality']['knowledge_score'] : 0;
        $subs = isset($doc['quality']['sub_scores']) ? $doc['quality']['sub_scores'] : array();
        $lines = $this->frontmatter($doc, $title, $score);
        $lines[] = '# ' . $title;
        $lines[] = '';
        $lines[] = '## 1. Executive Summary';
        $lines[] = '';
        $short = isset($doc['summaries']['short']) ? $doc['summaries']['short'] : '_No summary available._';
        $lines[] = $short;
        $lines[] = '';
        $lines[] = '_Extractive summary — not generative._';
        $lines[] = '';
        $lines[] = '## 2. Knowledge Score';
        $lines[] = '';
        $lines[] = '**' . $score . '%** ' . KnowledgeScore::starString($score);
        $lines[] = '';
        if (!empty($subs)) {
            $lines[] = '| Dimension | Score |';
            $lines[] = '|---|---:|';
            foreach ($subs as $k => $v) {
                $val = is_numeric($v) ? $v . '%' : 'n/a';
                $lines[] = '| ' . ucfirst($k) . ' | ' . $val . ' |';
            }
            $lines[] = '';
        }
        $lines[] = '## 3. Document Metadata';
        $lines[] = '';
        $fp = isset($doc['fingerprint']) ? $doc['fingerprint'] : array();
        $lines[] = '- **SHA-256:** `' . (isset($fp['sha256']) ? $fp['sha256'] : '') . '`';
        $lines[] = '- **Source:** ' . (isset($fp['source_name']) ? $fp['source_name'] : '');
        $lines[] = '- **MIME:** ' . (isset($fp['mime']) ? $fp['mime'] : 'unknown');
        $lines[] = '- **Size:** ' . $this->formatBytes(isset($fp['size_bytes']) ? $fp['size_bytes'] : 0);
        $lines[] = '- **Pages:** ' . (isset($fp['page_count']) ? $fp['page_count'] : 1);
        $lang = isset($fp['language']) ? $fp['language'] : 'unknown';
        $lines[] = '- **Language:** ' . ($lang === 'und'
            ? 'undetermined (input too short for reliable detection; English stopwords used)'
            : $lang);
        $lines[] = '- **Extracted:** ' . (isset($fp['extracted_at']) ? $fp['extracted_at'] : gmdate('c'));
        if (!empty($fp['duplicate_of']) && !empty($fp['duplicate_of']['report_id'])) {
            $dup = $fp['duplicate_of'];
            $when = isset($dup['created_at']) ? substr($dup['created_at'], 0, 10) : '';
            $lines[] = '- **Duplicate:** previously processed'
                . ($when !== '' ? ' on ' . $when : '')
                . ' as report #' . $dup['report_id'];
        }
        $lines[] = '';
        $lines[] = '## 4. Quality Verdict';
        $lines[] = '';
        $lines[] = isset($doc['quality']['verdict']) ? $doc['quality']['verdict'] : '';
        $lines[] = '';
        if (!empty($doc['quality']['issues'])) {
            $lines[] = '**Issues**';
            $lines[] = '';
            foreach ($doc['quality']['issues'] as $issue) {
                $lines[] = '- ' . $issue;
            }
            $lines[] = '';
        }
        if (!empty($doc['quality']['notes'])) {
            $lines[] = '**Transparency notes**';
            $lines[] = '';
            foreach ($doc['quality']['notes'] as $note) {
                $lines[] = '- _' . $note . '_';
            }
            $lines[] = '';
        }
        if (!empty($doc['sections'])) {
            $lines[] = '## 5. Contents';
            $lines[] = '';
            foreach ($doc['sections'] as $sec) {
                $indent = str_repeat('  ', max(0, (isset($sec['level']) ? $sec['level'] : 2) - 1));
                $lines[] = $indent . '- ' . $sec['title'];
            }
            $lines[] = '';
            $lines[] = '## 6. Structure';
            $lines[] = '';
            foreach ($doc['sections'] as $sec) {
                $lines[] = '### ' . $sec['title'] . ' (' . $sec['word_count'] . ' words)';
                $lines[] = '';
            }
        }
        if (!empty($doc['summaries']['key_findings'])) {
            $lines[] = '## 7. Key Findings';
            $lines[] = '';
            foreach ($doc['summaries']['key_findings'] as $f) {
                $lines[] = '- ' . $f;
            }
            $lines[] = '';
        } elseif (!empty($doc['summaries']['findings_note'])) {
            // Honest empty beats a redundant fill (FR-4.3).
            $lines[] = '## 7. Key Findings';
            $lines[] = '';
            $lines[] = '_' . $doc['summaries']['findings_note'] . '_';
            $lines[] = '';
        }
        if (!empty($doc['keyphrases'])) {
            $lines[] = '## 8. Key Phrases';
            $lines[] = '';
            foreach ($doc['keyphrases'] as $kp) {
                $lines[] = '- **' . $kp['phrase'] . '** (' . $kp['score'] . ')';
            }
            $lines[] = '';
        }
        if (!empty($doc['references'])) {
            $lines[] = '## 9. References';
            $lines[] = '';
            foreach ($doc['references'] as $ref) {
                $line = '- ' . $ref['raw'];
                if (!empty($ref['doi'])) {
                    $line .= ' — DOI: ' . $ref['doi'];
                }
                $lines[] = $line;
            }
            $lines[] = '';
        }
        if (!empty($doc['statistics'])) {
            $lines[] = '## 13. Statistics';
            $lines[] = '';
            $s = $doc['statistics'];
            $lines[] = '- Words: ' . (isset($s['word_count']) ? $s['word_count'] : 0);
            $lines[] = '- Reading time: ~' . (isset($s['reading_time_minutes']) ? $s['reading_time_minutes'] : 1) . ' min';
            $lines[] = '- Flesch reading ease: ' . (isset($s['flesch_reading_ease']) ? $s['flesch_reading_ease'] : 'n/a');
            $lines[] = '';
        }
        if (!empty($doc['blocks'])) {
            $lines[] = '## 14. Full Extracted Text';
            $lines[] = '';
            foreach ($doc['blocks'] as $b) {
                if ($b['type'] === 'heading') {
                    if (end($lines) !== '') {
                        $lines[] = ''; // blank line before a heading (Markdown requirement)
                    }
                    $lvl = isset($b['level']) ? min(6, $b['level'] + 1) : 3;
                    $lines[] = str_repeat('#', $lvl) . ' ' . $b['text'];
                    $lines[] = '';
                } elseif ($b['type'] === 'list') {
                    $lines[] = '- ' . $b['text'];
                } elseif ($b['type'] === 'note') {
                    if (end($lines) !== '') {
                        $lines[] = '';
                    }
                    $lines[] = '_' . $b['text'] . '_';
                    $lines[] = '';
                } elseif ($b['type'] === 'caption') {
                    if (end($lines) !== '') {
                        $lines[] = '';
                    }
                    $lines[] = '**' . $b['text'] . '**';
                    $lines[] = '';
                } elseif ($b['type'] === 'table' && !empty($b['rows'])) {
                    if (end($lines) !== '') {
                        $lines[] = '';
                    }
                    foreach ($this->renderTable($b['rows']) as $tl) {
                        $lines[] = $tl;
                    }
                    $lines[] = '';
                } else {
                    $lines[] = $b['text'];
                    $lines[] = '';
                }
            }
        }
        return implode("\n", $lines);
    }

    /**
     * YAML frontmatter so a report can be triaged programmatically before a
     * word is read. Triage-critical keys (class, score, flags) lead.
     *
     * @param array<string,mixed> $doc
     * @return array<int,string>
     */
    private function frontmatter(array $doc, $title, $score)
    {
        $fp = isset($doc['fingerprint']) ? $doc['fingerprint'] : array();
        $flags = $this->verdictFlags($doc);
        $dup = (!empty($fp['duplicate_of']) && !empty($fp['duplicate_of']['report_id']))
            ? (int) $fp['duplicate_of']['report_id'] : null;

        $lines = array();
        $lines[] = '---';
        $lines[] = 'title: ' . $this->yaml($title);
        $lines[] = 'doc_class: ' . $this->yaml($this->docClass($doc));
        $lines[] = 'knowledge_score: ' . (int) $score;
        $lines[] = 'verdict_flags: ' . (empty($flags)
            ? '[]'
            : '[' . implode(', ', $flags) . ']');
        $lines[] = 'source: ' . $this->yaml(isset($fp['source_name']) ? $fp['source_name'] : '');
        $lines[] = 'type: ' . $this->yaml($this->docType($doc));
        $lines[] = 'pages: ' . $this->yaml(isset($fp['page_count']) ? (string) $fp['page_count'] : '1');
        $lines[] = 'language: ' . $this->yaml(isset($fp['language']) ? $fp['language'] : 'unknown');
        $lines[] = 'fingerprint: ' . $this->yaml(isset($fp['sha256']) ? $fp['sha256'] : '');
        $lines[] = 'duplicate_of: ' . ($dup === null ? 'null' : $dup);
        $lines[] = 'extracted_at: ' . $this->yaml(isset($fp['extracted_at']) ? $fp['extracted_at'] : gmdate('c'));
        $lines[] = '---';
        $lines[] = '';
        return $lines;
    }

    /** Quote a scalar for YAML (double-quoted, minimal escaping). */
    private function yaml($value)
    {
        $value = (string) $value;
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);
        $value = preg_replace('/\s*\R\s*/u', ' ', $value);
        return '"' . $value . '"';
    }

    /**
     * Short machine flags derived from the quality verdict, so a caller can
     * "skip anything with content_loss" without parsing prose.
     *
     * @param array<string,mixed> $doc
     * @return array<int,string>
     */
    private function verdictFlags(array $doc)
    {
        $flags = array();
        foreach (isset($doc['quality']['issues']) ? $doc['quality']['issues'] : array() as $issue) {
            $i = strtolower($issue);
            if (strpos($i, 'no readable content') !== false) {
                $flags[] = 'no_content';
            } elseif (strpos($i, 'body content') !== false || strpos($i, 'content loss') !== false
                || strpos($i, 'probable extraction failure') !== false || strpos($i, 'words recovered') !== false) {
                $flags[] = 'content_loss';
            } elseif (strpos($i, 'contamination') !== false || strpos($i, 'glyph') !== false) {
                $flags[] = 'contamination';
            } elseif (strpos($i, 'mojibake') !== false || strpos($i, 'encoding artefact') !== false) {
                $flags[] = 'mojibake';
            } elseif (strpos($i, 'very short') !== false) {
                $flags[] = 'short_document';
            } elseif (strpos($i, 'summary could not') !== false) {
                $flags[] = 'no_summary';
            }
        }
        foreach (isset($doc['quality']['notes']) ? $doc['quality']['notes'] : array() as $note) {
            if (stripos($note, 'extraction artefacts in the source') !== false) {
                $flags[] = 'source_artefacts';
            }
            if (stripos($note, 'math-dense region') !== false) {
                $flags[] = 'equation_degraded';
            }
        }
        return array_values(array_unique($flags));
    }

    /** @param array<string,mixed> $doc */
    private function docClass(array $doc)
    {
        $strategy = isset($doc['summaries']['strategy']) ? $doc['summaries']['strategy'] : '';
        if ($strategy === 'structure-templated') {
            return 'list-dominant';
        }
        foreach (isset($doc['blocks']) ? $doc['blocks'] : array() as $b) {
            if (isset($b['type']) && $b['type'] === 'table') {
                return 'form';
            }
        }
        return 'prose';
    }

    /** @param array<string,mixed> $doc */
    private function docType(array $doc)
    {
        if (!empty($doc['source']['type'])) {
            return strtoupper($doc['source']['type']);
        }
        $mime = isset($doc['fingerprint']['mime']) ? $doc['fingerprint']['mime'] : '';
        $map = array(
            'application/pdf' => 'PDF',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
            'text/markdown' => 'MD',
            'text/plain' => 'TXT',
        );
        return isset($map[$mime]) ? $map[$mime] : 'unknown';
    }

    /**
     * Render a grid as a GFM table. The first row is treated as the header.
     *
     * @param array<int,array<int,string>> $rows
     * @return array<int,string>
     */
    private function renderTable(array $rows)
    {
        $cols = 0;
        foreach ($rows as $r) {
            $cols = max($cols, count($r));
        }
        if ($cols === 0) {
            return array();
        }
        $out = array();
        $header = array_pad($rows[0], $cols, '');
        $out[] = '| ' . implode(' | ', array_map(array($this, 'cell'), $header)) . ' |';
        $out[] = '| ' . implode(' | ', array_fill(0, $cols, '---')) . ' |';
        $count = count($rows);
        for ($i = 1; $i < $count; $i++) {
            $row = array_pad($rows[$i], $cols, '');
            $out[] = '| ' . implode(' | ', array_map(array($this, 'cell'), $row)) . ' |';
        }
        return $out;
    }

    /** Make a string safe for a single GFM table cell. */
    private function cell($s)
    {
        $s = (string) $s;
        $s = preg_replace('/\s*\R\s*/u', ' ', $s); // no line breaks inside a cell
        $s = str_replace('|', '\\|', $s);          // escape column delimiter
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
