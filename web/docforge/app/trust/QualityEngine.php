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
    const GLYPHS = '/[\x{2022}\x{2023}\x{25AA}\x{25CF}\x{25E6}\x{2043}\x{2219}\x{00B7}\x{2713}\x{2714}\x{2717}\x{2718}\x{2610}\x{2611}\x{2612}]/u';

    /** Minimum extracted words per KB before we suspect body-content loss. */
    private static $wordsPerKbFloor = array('DOCX' => 10.0, 'PDF' => 2.0, 'TXT' => 20.0, 'MD' => 20.0);

    /**
     * @param array<string,mixed> $ir    the in-progress document representation
     * @param array<string,mixed> $meta  upload metadata (size, type) for floor checks
     * @return array{issues:array<int,string>,notes:array<int,string>,verdict:string}
     */
    public static function assess(array $ir, array $meta = array())
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

        // Content-loss invariants (corpus #002). Any one catches a document
        // whose structure survived but whose body was silently dropped.
        foreach (self::contentLoss($ir, $meta) as $c) {
            $issues[] = $c;
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

    /**
     * Three cheap, mechanically-checkable content-loss signals (corpus #002):
     *   (a) structure detected but every section is empty,
     *   (b) extracted words-per-KB below the format floor,
     *   (c) full text is essentially just the heading list.
     *
     * @return array<int,string>
     */
    private static function contentLoss(array $ir, array $meta)
    {
        $found = array();
        $sections = isset($ir['sections']) ? $ir['sections'] : array();
        $sectionCount = count($sections);

        // (a) all / near-all sections empty
        if ($sectionCount >= 3) {
            $empty = 0;
            foreach ($sections as $s) {
                if ((int) (isset($s['word_count']) ? $s['word_count'] : 0) === 0) {
                    $empty++;
                }
            }
            if ($empty / $sectionCount >= 0.9) {
                $found[] = 'Structure was detected but ' . $empty . ' of ' . $sectionCount
                    . ' sections have no body content — probable extraction failure '
                    . '(e.g. table-based content not read). Treat body content as unreliable.';
            }
        }

        // (b) words-per-KB below the format floor
        $size = isset($meta['size_bytes']) ? (int) $meta['size_bytes'] : 0;
        $type = isset($meta['source_type']) ? strtoupper($meta['source_type']) : '';
        $words = isset($ir['statistics']['word_count']) ? (int) $ir['statistics']['word_count'] : 0;
        if ($size > 4096 && isset(self::$wordsPerKbFloor[$type])) {
            $perKb = $words / ($size / 1024);
            if ($perKb < self::$wordsPerKbFloor[$type]) {
                $found[] = 'Only ' . $words . ' words recovered from a '
                    . round($size / 1024) . ' KB ' . $type . ' (' . round($perKb, 1)
                    . ' words/KB, below the ' . self::$wordsPerKbFloor[$type]
                    . ' floor) — body content may be missing.';
            }
        }

        // (c) full text is basically just the heading list
        $fullLen = isset($ir['full_text']) ? mb_strlen(trim($ir['full_text'])) : 0;
        if ($fullLen > 0 && !empty($ir['blocks'])) {
            $headingLen = 0;
            foreach ($ir['blocks'] as $b) {
                if (isset($b['type']) && $b['type'] === 'heading') {
                    $headingLen += mb_strlen($b['text']);
                }
            }
            if ($headingLen / $fullLen >= 0.8) {
                $found[] = 'Extracted text is almost entirely section headings ('
                    . round(100 * $headingLen / $fullLen) . '%) — body content is likely missing.';
            }
        }

        return $found;
    }

    /** @return array<int,string> */
    private static function contamination(array $ir)
    {
        $found = array();
        foreach (isset($ir['keyphrases']) ? $ir['keyphrases'] : array() as $kp) {
            $phrase = isset($kp['phrase']) ? $kp['phrase'] : '';
            // A bullet glyph is the reliable contamination signal; page/section
            // number bleed is now removed upstream by TextNormalizer, and a bare
            // trailing number is usually legitimate ("grade 7", "pilot 1").
            if (preg_match(self::GLYPHS, $phrase)) {
                $found[] = 'Keyphrase contamination detected ("' . $phrase
                    . '") — a list glyph leaked into analysis.';
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
