<?php

namespace DocForge\Trust;

/**
 * Quality Assessment Engine (FR-17) — Phase 1 minimal verdict.
 */
class QualityEngine
{
    /**
     * @param array<string,mixed> $doc
     * @return array{issues:array<int,string>,verdict:string}
     */
    public static function assess(array $doc)
    {
        $issues = array();
        $blocks = isset($doc['blocks']) ? count($doc['blocks']) : 0;
        if ($blocks === 0) {
            $issues[] = 'No readable content blocks were extracted from this document.';
        }
        if (empty($doc['summaries']['short'])) {
            $issues[] = 'Summary could not be generated — the extract may be too short.';
        }
        if (isset($doc['statistics']['word_count']) && $doc['statistics']['word_count'] < 50) {
            $issues[] = 'Document is very short; analysis confidence is reduced.';
        }
        $verdict = empty($issues)
            ? 'Extraction completed with no significant quality issues detected.'
            : 'Extraction completed with ' . count($issues) . ' quality note(s). See Quality Verdict in the full report.';
        return array('issues' => $issues, 'verdict' => $verdict);
    }
}
