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
        $lines = array();
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
        $lines[] = '- **Language:** ' . (isset($fp['language']) ? $fp['language'] : 'unknown');
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
                } else {
                    $lines[] = $b['text'];
                    $lines[] = '';
                }
            }
        }
        return implode("\n", $lines);
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
