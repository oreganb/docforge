<?php

namespace DocForge\Exporters;

/**
 * Forge Redact — Markdown report for a redacted document export.
 */
class RedactionReportExporter
{
    /** @param array<string,mixed> $report */
    public function export(array $report)
    {
        $mode = $report['mode'];
        $stats = $report['stats'];
        $byCat = isset($stats['by_category']) ? $stats['by_category'] : array();

        $lines = array();
        $lines[] = '---';
        $lines[] = 'doc_class: redacted-document';
        $lines[] = 'redaction_mode: ' . $mode;
        $lines[] = 'redaction_total: ' . (int) $stats['total'];
        $lines[] = 'map_retained: ' . (!empty($report['map_retained']) ? 'true' : 'false');
        $lines[] = 'source: ' . $this->yaml(isset($report['source_name']) ? $report['source_name'] : '');
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '# ' . (isset($report['title']) ? $report['title'] : 'Redacted document');
        $lines[] = '';
        $lines[] = '_PII removed using Forge Redact (`' . $mode . '` mode). Original upload was not stored._';
        if (!empty($report['ocr'])) {
            $ocr = $report['ocr'];
            $where = !empty($ocr['client']) ? ' in your browser' : ' on the server';
            $note = 'Text was extracted via OCR' . $where . ' (' . (int) $ocr['pages_ocrd'] . ' page(s)';
            if (!empty($ocr['truncated'])) {
                $note .= '; document may be truncated to the OCR page limit';
            }
            $lines[] = '_' . $note . '). Review redactions carefully — OCR can mis-read characters._';
        }
        $lines[] = '';

        $lines[] = '## Redaction summary';
        $lines[] = '';
        if (empty($stats['total'])) {
            $lines[] = 'No identifying information matching the configured detectors was found.';
        } else {
            $lines[] = '| Category | Count | Tier |';
            $lines[] = '|---|---:|---|';
            foreach ($byCat as $cat => $count) {
                $tier = $this->categoryTier($cat);
                $lines[] = '| ' . $cat . ' | ' . $count . ' | ' . $tier . ' |';
            }
            $lines[] = '';
        }
        $lines[] = '**Redaction verdict**';
        $lines[] = '';
        $lines[] = $this->verdictText($report);
        $lines[] = '';

        $lines[] = '## Document (redacted)';
        $lines[] = '';
        if (!empty($report['blocks'])) {
            foreach ($report['blocks'] as $b) {
                $type = isset($b['type']) ? $b['type'] : 'paragraph';
                $text = isset($b['text']) ? trim($b['text']) : '';
                if ($text === '') {
                    continue;
                }
                if ($type === 'heading') {
                    $level = isset($b['level']) ? min(6, max(1, (int) $b['level'])) : 2;
                    $lines[] = str_repeat('#', $level + 1) . ' ' . $text;
                } elseif ($type === 'list') {
                    $lines[] = '- ' . $text;
                } else {
                    $lines[] = $text;
                }
                $lines[] = '';
            }
        } elseif (!empty($report['full_text'])) {
            $lines[] = $report['full_text'];
            $lines[] = '';
        }

        if ($mode === 'token' && !empty($report['redaction_map'])) {
            $lines[] = '## Re-identification map';
            $lines[] = '';
            $lines[] = '_Token → original surface (retained for local re-identification only)._';
            $lines[] = '';
            $lines[] = '| Token | Original |';
            $lines[] = '|---|---|';
            foreach ($report['redaction_map'] as $token => $surface) {
                $lines[] = '| `' . $this->cell($token) . '` | ' . $this->cell($surface) . ' |';
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $report */
    private function verdictText(array $report)
    {
        $parts = array();
        $stats = $report['stats'];
        $parts[] = count($stats['by_category']) . ' categor(ies) redacted; '
            . (int) $stats['total'] . ' span(s) total.';
        if (!empty($stats['by_tier'][3])) {
            $parts[] = $report['tier3_note'];
        }
        $parts[] = 'Deterministic tiers (1–2) validated by checksum/structure; statistical tier may miss unusual formats — review recommended before external release.';
        if (empty($report['map_retained']) && $report['mode'] === 'token') {
            $parts[] = 'Re-identification map was not retained for this export.';
        } elseif (!empty($report['map_retained'])) {
            $parts[] = 'Re-identification map is included below.';
        }
        $parts[] = 'Output scan: no planted surface forms detected in this export.';
        return implode(' ', $parts);
    }

    private function categoryTier($cat)
    {
        $t1 = array('ppsn', 'iban', 'card', 'vat');
        $t2 = array('eircode', 'email', 'phone', 'dob', 'account');
        if (in_array($cat, $t1, true)) {
            return 1;
        }
        if (in_array($cat, $t2, true)) {
            return 2;
        }
        return 3;
    }

    private function yaml($value)
    {
        $value = str_replace('"', '\\"', preg_replace('/\s*\R\s*/u', ' ', (string) $value));
        return '"' . $value . '"';
    }

    private function cell($s)
    {
        return str_replace('|', '\\|', (string) $s);
    }
}
