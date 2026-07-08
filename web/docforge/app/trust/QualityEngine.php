<?php

namespace DocForge\Trust;

/**
 * Quality Assessment Engine (FR-17, Design Principle 7).
 *
 * The trust layer's one job is to never let degradation pass silently. It runs
 * concrete checks over the analysis outputs and, per FR-6, explicitly names
 * every dimension that was NOT analysed so nothing is omitted without a note.
 *
 * @param array<string,mixed> $ir  the in-progress document representation
 */
class QualityEngine
{
    /** Bullet / list glyphs that must never survive into analysis outputs. */
    const GLYPHS = '/[\x{2022}\x{2023}\x{25AA}\x{25CF}\x{25E6}\x{2043}\x{2219}\x{00B7}]/u';

    /**
     * @return array{issues:array<int,string>,notes:array<int,string>,verdict:string}
     */
    public static function assess(array $ir)
    {
        $issues = array();
        $notes = array();

        $blocks = isset($ir['blocks']) ? count($ir['blocks']) : 0;
        if ($blocks === 0) {
            $issues[] = 'No readable content blocks were extracted from this document.';
        }
        if (empty($ir['summaries']['short'])) {
            $issues[] = 'Summary could not be generated — the extract may be too short.';
        }
        $wordCount = isset($ir['statistics']['word_count']) ? (int) $ir['statistics']['word_count'] : 0;
        if ($wordCount > 0 && $wordCount < 50) {
            $issues[] = 'Document is very short (' . $wordCount . ' words); analysis confidence is reduced.';
        }

        // Contamination guard: list glyphs or page/section-number bleed must not
        // leak into keyphrases or the summary (the run-one defect).
        foreach (self::contamination($ir) as $c) {
            $issues[] = $c;
        }

        // Transparency: disclose page furniture removed before analysis.
        $chrome = isset($ir['removed_chrome']) ? array_values(array_unique($ir['removed_chrome'])) : array();
        if (!empty($chrome)) {
            $shown = array_slice($chrome, 0, 6);
            $notes[] = 'Removed ' . count($ir['removed_chrome']) . ' line(s) of page furniture '
                . '(running headers/footers, page numbers) before analysis: "'
                . implode('", "', $shown) . '"' . (count($chrome) > 6 ? ' …' : '') . '.';
        }

        // FR-6 omitted-sections audit — name every dimension not analysed.
        $notAnalysed = array('Entities', 'Tables', 'Figures / image analysis');
        $refs = isset($ir['references']) ? count($ir['references']) : 0;
        if ($refs === 0) {
            $notAnalysed[] = 'References (none detected)';
        }
        $notes[] = 'Not analysed in this phase: ' . implode(', ', $notAnalysed)
            . '. These are excluded from the Knowledge Score (reported n/a) rather than scored as complete.';

        if (!empty($issues)) {
            $verdict = 'Extraction completed with ' . count($issues) . ' quality issue(s)'
                . (!empty($notes) ? ' and ' . count($notes) . ' transparency note(s)' : '') . ' — see below.';
        } elseif (!empty($notes)) {
            $verdict = 'Extraction completed. No content-quality issues detected; '
                . count($notes) . ' transparency note(s) below.';
        } else {
            $verdict = 'Extraction completed with no significant quality issues detected.';
        }

        return array('issues' => $issues, 'notes' => $notes, 'verdict' => $verdict);
    }

    /** @return array<int,string> */
    private static function contamination(array $ir)
    {
        $found = array();
        foreach (isset($ir['keyphrases']) ? $ir['keyphrases'] : array() as $kp) {
            $phrase = isset($kp['phrase']) ? $kp['phrase'] : '';
            if (preg_match(self::GLYPHS, $phrase) || preg_match('/\s\d{1,3}$/', $phrase)) {
                $found[] = 'Keyphrase contamination detected ("' . $phrase
                    . '") — a list glyph or page/section number leaked into analysis.';
                break;
            }
        }
        $short = isset($ir['summaries']['short']) ? $ir['summaries']['short'] : '';
        if ($short !== '' && preg_match(self::GLYPHS, $short)) {
            $found[] = 'Summary contains list glyphs — bullet markers were not stripped before segmentation.';
        }
        return $found;
    }
}
