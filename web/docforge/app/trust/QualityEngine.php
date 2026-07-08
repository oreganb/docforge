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
    const GLYPHS = '/[\x{2022}\x{2023}\x{25AA}\x{25CF}\x{25E6}\x{2043}\x{2219}\x{00B7}\x{2713}\x{2714}\x{2717}\x{2718}\x{2610}\x{2611}\x{2612}\x{2700}-\x{27BF}\x{E000}-\x{F8FF}]/u';

    /**
     * Mojibake signature — a reinterpreted UTF-8 lead byte (U+00C2–U+00F4)
     * hard against a continuation character. ParserRegistry repairs this at
     * ingest; this is the trust-layer safety net that flags any residue.
     */
    const MOJIBAKE = '/[\x{00C2}-\x{00F4}][\x{0080}-\x{00BF}\x{20AC}\x{2122}\x{2018}\x{2019}\x{201C}\x{201D}\x{2013}\x{2014}\x{2026}]/u';

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

        // Encoding safety net: mojibake should have been repaired at ingest, so
        // any residue is a genuine issue worth flagging rather than hiding.
        $fullText = isset($ir['full_text']) ? (string) $ir['full_text'] : '';
        if ($fullText !== '' && preg_match_all(self::MOJIBAKE, $fullText) >= 3) {
            $issues[] = 'Character-encoding artefacts (mojibake, e.g. "Ã", "Â", "â€") remain in the '
                . 'text — some accented or non-Latin characters may be misrendered.';
        }

        // Transparency: disclose page furniture removed before analysis.
        $chrome = isset($ir['removed_chrome']) ? array_values(array_unique($ir['removed_chrome'])) : array();
        if (!empty($chrome)) {
            $shown = array_slice($chrome, 0, 6);
            $notes[] = 'Removed ' . count($ir['removed_chrome']) . ' line(s) of page furniture '
                . '(running headers/footers, page numbers) before analysis: "'
                . implode('", "', $shown) . '"' . (count($chrome) > 6 ? ' …' : '') . '.';
        }

        // Tables: distinguish structure-preserved (GFM, from DOCX cell nodes)
        // from structure-flattened (a marker inserted where a flowed source lost
        // the cell boundaries).
        $tableCount = 0;
        $flattenedTables = 0;
        foreach (isset($ir['blocks']) ? $ir['blocks'] : array() as $b) {
            if (!isset($b['type'])) {
                continue;
            }
            if ($b['type'] === 'table') {
                $tableCount++;
            } elseif ($b['type'] === 'note' && isset($b['text'])
                && stripos($b['text'], 'structure not preserved') !== false) {
                $flattenedTables++;
            }
        }

        // FR-6 omitted-sections audit — name every dimension not analysed.
        $notAnalysed = array('Entities', 'Figures / image analysis');
        if ($tableCount === 0 && $flattenedTables === 0) {
            $notAnalysed[] = 'Tables (none detected)';
        }
        $refs = isset($ir['references']) ? count($ir['references']) : 0;
        if ($refs === 0) {
            $notAnalysed[] = 'References (none detected)';
        }
        $notes[] = 'Not analysed in this phase: ' . implode(', ', $notAnalysed)
            . '. These are excluded from the Knowledge Score (reported n/a) rather than scored as complete.';

        if ($tableCount > 0) {
            $notes[] = $tableCount . ' table(s) preserved as Markdown with row/column structure intact '
                . '(cell boundaries retained from the source; not semantically analysed in this phase).';
        }
        if ($flattenedTables > 0) {
            $notes[] = $flattenedTables . ' table region(s) detected in a flowed source (PDF/TXT) where cell '
                . 'boundaries are not recoverable — rows are shown inline and marked "[table: structure not '
                . 'preserved]" rather than presented as prose.';
        }

        // Degraded equations: a text-layer extraction cannot faithfully preserve
        // mathematical notation. Declare it rather than shipping mangled symbols
        // silently (interim until Phase 3 math-OCR).
        $mathRegions = 0;
        foreach (isset($ir['blocks']) ? $ir['blocks'] : array() as $b) {
            if (!empty($b['math_degraded'])) {
                $mathRegions++;
            }
        }
        if ($mathRegions > 0) {
            $notes[] = $mathRegions . ' math-dense region(s) detected where notation is degraded by '
                . 'text-layer extraction (symbols/superscripts may be lost or reordered). Marked '
                . '"[equation: notation degraded in text-layer extraction]"; faithful equation capture '
                . 'arrives with Phase 3 math-OCR.';
        }

        // Declared source artefacts (extract-never-fix): if the source itself
        // contains intra-word splits (e.g. "minimi ses"), we reproduce them
        // verbatim and attribute the damage to the source rather than repairing.
        $artefacts = self::sourceArtefacts($ir);
        if (!empty($artefacts)) {
            $examples = array_slice($artefacts, 0, 3);
            $notes[] = 'Possible extraction artefacts in the source: ' . count($artefacts)
                . ' intra-word split(s) detected (e.g. "' . implode('", "', $examples) . '"). '
                . 'Reproduced verbatim per extract-never-fix; the source, not DocForge, introduced these.';
        }

        // Numbering is a deliberate convention, not an error: section IDs are
        // fixed per FR-6 so a given dimension always has the same number across
        // reports. Unproduced sections (e.g. 10–12) are skipped, not renumbered.
        $notes[] = 'Section numbers follow the fixed FR-6 scheme so each dimension keeps a stable ID '
            . 'across reports; sections not produced in this phase (e.g. 10–12) are skipped by design '
            . 'rather than renumbered.';

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

    /**
     * Detect intra-word split artefacts already present in the source text
     * ("minimi ses", "utili sation", "revolutioni se"). Conservative by design:
     * only a short allow-list of word-endings is treated as a split, so we
     * under-report rather than falsely accuse legitimate two-word phrases.
     *
     * @return array<int,string> unique example fragments
     */
    private static function sourceArtefacts(array $ir)
    {
        $text = isset($ir['full_text']) ? (string) $ir['full_text'] : '';
        if ($text === '') {
            return array();
        }
        // A real word of 3+ lowercase letters, a single space, then a bare
        // word-ending fragment that almost never stands alone as a word.
        $suffix = 'se|ses|sing|sation|isation|ization|tion|sion|sions|ity|ities|ment|ments|ised|ized|ising|izing';
        $stop = array(
            'the', 'and', 'for', 'are', 'was', 'her', 'his', 'she', 'you', 'our',
            'out', 'not', 'can', 'has', 'had', 'but', 'all', 'any', 'one', 'two',
            'its', 'who', 'why', 'how', 'may', 'per', 'use', 'new',
        );
        if (!preg_match_all('/\b(\p{Ll}{3,})[ ](' . $suffix . ')\b/u', $text, $m, PREG_SET_ORDER)) {
            return array();
        }
        $found = array();
        foreach ($m as $hit) {
            if (in_array(mb_strtolower($hit[1]), $stop, true)) {
                continue;
            }
            $frag = $hit[1] . ' ' . $hit[2];
            $found[mb_strtolower($frag)] = $frag;
        }
        return array_values($found);
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
