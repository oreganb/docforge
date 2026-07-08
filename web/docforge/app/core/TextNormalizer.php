<?php

namespace DocForge\Core;

/**
 * Pre-processing for extracted text (SPECS Design Principle 7).
 *
 * Raw PDF/plain-text extraction leaks page furniture (running headers/footers,
 * page numbers) and loses list/heading structure. This normaliser:
 *   - removes page markers ("12", "12 | P a g e") and the running header/footer
 *     block that recurs at page boundaries — while leaving one-off content
 *     (table headers, section titles) intact,
 *   - detects real section headings (decimal "1.0", or short numbered titles)
 *     without mistaking long numbered list items for chapters,
 *   - drops dotted table-of-contents leader lines,
 *   - merges wrapped continuation lines back into their logical line,
 *   - rebuilds clean blocks + clean full_text for all downstream analysis.
 */
class TextNormalizer
{
    const BULLET = '/^\s*[\x{2022}\x{2023}\x{25AA}\x{25CF}\x{25E6}\x{2043}\x{2219}\x{00B7}\x{2713}\x{2714}\x{2717}\x{2718}\x{2610}\x{2611}\x{2612}*\x{2013}\x{2014}-]\s+/u';
    /** A short single-dot numbered title ("1. Leadership"). */
    const NUMBERED_ITEM = '/^\s*\d+\.\s+[A-Z]/u';
    /** A decimal section number ("1.0", "2.1", "3.2.1 ..."). */
    const SECTION_NUM = '/^\s*\d+\.\d+(\.\d+)*\s+\S/u';
    /** A standalone page number line. */
    const PAGE_NUMBER = '/^\s*\d{1,4}\s*$/';
    /** A "N | P a g e" / "Page N" footer, tolerant of letter-spacing. */
    const PAGE_LABEL = '/^\s*\d{0,4}\s*\|?\s*p\s*a\s*g\s*e\b/iu';
    /** A dotted TOC leader ("Introduction .......... 10"). */
    const DOT_LEADER = '/\.(\s?\.){3,}/u';

    /** A recurring line is furniture only if it repeats at least this often. */
    const FURNITURE_MIN_REPEATS = 2;
    /** ...and at least one occurrence sits within this many lines of a page marker. */
    const FURNITURE_WINDOW = 12;

    /**
     * @param string $rawText
     * @return array{full_text:string,blocks:array,list_ratio:float,headings:array,removed_chrome:array,header:string}
     */
    public static function normalize($rawText, array $rawBlocks = array())
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $rawText);
        $count = count($lines);
        $trimmed = array();
        for ($i = 0; $i < $count; $i++) {
            $trimmed[$i] = trim($lines[$i]);
        }
        $drop = array_fill(0, $count, false);
        $removedMeta = array(); // list of array{idx:int,text:string,num:bool}

        // Pass 1 — frequency of each line's page-invariant signature.
        $freq = array();
        for ($i = 0; $i < $count; $i++) {
            if ($trimmed[$i] === '') {
                continue;
            }
            $sig = self::numberlessSig($trimmed[$i]);
            if ($sig === '') {
                continue;
            }
            $freq[$sig] = isset($freq[$sig]) ? $freq[$sig] + 1 : 1;
        }

        // Pass 0 — locate page markers, then strip the running header/footer that
        // hugs each one. Two regimes keep this precise:
        //   * If the document paginates with "N | P a g e" labels, those are the
        //     only true markers; bare numbers are content (e.g. a "5" table row).
        //     The header block recurs on every page, so we remove neighbours only
        //     when they repeat (recurrence-gated) within a small window — this
        //     never touches one-off content like "Revision History".
        //   * Otherwise (a PDF with a bare page number and a header that appears
        //     once), we walk upward from the number removing short non-sentence
        //     chrome, which is the footer/header banner above it.
        $labels = array();
        for ($i = 0; $i < $count; $i++) {
            if ($trimmed[$i] !== '' && preg_match(self::PAGE_LABEL, $trimmed[$i])) {
                $labels[] = $i;
            }
        }
        if (!empty($labels)) {
            foreach ($labels as $m) {
                $drop[$m] = true;
                $removedMeta[] = array('idx' => $m, 'text' => $trimmed[$m], 'num' => true);
                self::dropChrome($trimmed, $freq, $drop, $removedMeta, $m, -1, true, $count);
                self::dropChrome($trimmed, $freq, $drop, $removedMeta, $m, 1, true, $count);
            }
        } else {
            for ($i = 0; $i < $count; $i++) {
                if ($trimmed[$i] === '' || $drop[$i]) {
                    continue;
                }
                if (preg_match(self::PAGE_NUMBER, $lines[$i]) && self::hasBlankNeighbour($trimmed, $i, $count)) {
                    $drop[$i] = true;
                    $removedMeta[] = array('idx' => $i, 'text' => $trimmed[$i], 'num' => true);
                    self::dropChrome($trimmed, $freq, $drop, $removedMeta, $i, -1, false, $count);
                }
            }
        }

        // Does the document expose a decimal section skeleton ("1.0", "2.0")?
        // If so, plain "N." lines are list items, not chapters.
        $decimalSections = 0;
        for ($i = 0; $i < $count; $i++) {
            if (!$drop[$i] && $trimmed[$i] !== '' && preg_match(self::SECTION_NUM, $trimmed[$i])
                && mb_strlen($trimmed[$i]) < 150) {
                $decimalSections++;
            }
        }
        $hasDecimalSkeleton = $decimalSections >= 2;

        // Pass 2 — classify surviving lines into blocks, merging wrapped lines.
        $blocks = array();
        $pending = null;
        $flush = function () use (&$blocks, &$pending) {
            if ($pending !== null) {
                $pending['text'] = trim($pending['text']);
                if ($pending['text'] !== '') {
                    $blocks[] = $pending;
                }
                $pending = null;
            }
        };

        for ($i = 0; $i < $count; $i++) {
            if ($drop[$i]) {
                continue;
            }
            $line = $trimmed[$i];
            if ($line === '') {
                $flush();
                continue;
            }
            // Dotted TOC leaders are navigation, not body content.
            if (preg_match(self::DOT_LEADER, $line)) {
                $flush();
                continue;
            }
            // Decimal section heading — the document's real skeleton. A TOC
            // entry can wrap so the number sits on a line whose continuation
            // carries the dot leader; skip those so the contents page does not
            // duplicate the real headings.
            if (preg_match(self::SECTION_NUM, $line) && mb_strlen($line) < 150) {
                if (self::nextIsDotLeader($trimmed, $i, $count)) {
                    $flush();
                    continue;
                }
                $flush();
                $blocks[] = array('type' => 'heading', 'text' => $line, 'level' => 2);
                continue;
            }
            // Short numbered title — a heading only when there is no decimal
            // skeleton and the line is not a wrapped list item.
            if (!$hasDecimalSkeleton
                && preg_match(self::NUMBERED_ITEM, $line)
                && self::isShortTitle($line)
                && !self::nextStartsLower($trimmed, $i, $count)) {
                $flush();
                $blocks[] = array('type' => 'heading', 'text' => $line, 'level' => 2);
                continue;
            }
            if (preg_match(self::BULLET, $line)) {
                $flush();
                $pending = array('type' => 'list', 'text' => preg_replace(self::BULLET, '', $line));
                continue;
            }
            if ($pending !== null) {
                $pending['text'] .= ' ' . $line;
            } else {
                $pending = array('type' => 'paragraph', 'text' => $line);
            }
        }
        $flush();

        if (empty($blocks) && !empty($rawBlocks)) {
            $blocks = $rawBlocks;
        }

        $removed = array();
        foreach ($removedMeta as $m) {
            $removed[] = $m['text'];
        }

        return array(
            'full_text' => self::rebuildText($blocks),
            'blocks' => $blocks,
            'list_ratio' => self::listRatio($blocks),
            'headings' => self::headingTitles($blocks),
            'removed_chrome' => $removed,
            'header' => self::titleFromFurniture($removedMeta, $lines),
        );
    }

    /** Page-invariant signature: lowercase, whitespace-collapsed, digits removed. */
    private static function numberlessSig($text)
    {
        $s = preg_replace('/\d+/', '', $text);
        $s = preg_replace('/\s+/', ' ', $s);
        return mb_strtolower(trim($s));
    }

    /** Does this line have at least one blank neighbour (not buried in a table)? */
    private static function hasBlankNeighbour(array $trimmed, $i, $count)
    {
        $prevBlank = ($i === 0) || ($trimmed[$i - 1] === '');
        $nextBlank = ($i === $count - 1) || ($trimmed[$i + 1] === '');
        return $prevBlank || $nextBlank;
    }

    /**
     * Walk outward from a page marker at $from in direction $dir, dropping the
     * header/footer chrome that hugs it. In recurrence mode a line is chrome
     * only if it repeats elsewhere (safe for many-page running headers); the
     * walk is bounded by FURNITURE_WINDOW so mid-page content is never reached.
     * Otherwise short non-sentence lines are treated as chrome (the once-only
     * banner above a PDF page number), bounded to a few lines.
     */
    private static function dropChrome(array $trimmed, array $freq, array &$drop, array &$removedMeta, $from, $dir, $recurrence, $count)
    {
        $maxLines = $recurrence ? self::FURNITURE_WINDOW : 4;
        $steps = 0;
        $seen = 0;
        $j = $from + $dir;
        while ($j >= 0 && $j < $count && $steps < self::FURNITURE_WINDOW && $seen < $maxLines) {
            $steps++;
            $t = $trimmed[$j];
            if ($t === '') {
                $j += $dir;
                continue;
            }
            if ($recurrence) {
                $sig = self::numberlessSig($t);
                if ($sig === '' || $freq[$sig] < self::FURNITURE_MIN_REPEATS) {
                    break; // reached one-off content
                }
            } else {
                if (preg_match(self::BULLET, $t) || preg_match(self::NUMBERED_ITEM, $t)
                    || preg_match(self::SECTION_NUM, $t)
                    || preg_match('/[.!?,;:]$/u', $t) || mb_strlen($t) > 80) {
                    break; // reached real content
                }
            }
            if (!$drop[$j]) {
                $drop[$j] = true;
                $removedMeta[] = array('idx' => $j, 'text' => $t, 'num' => false);
                $seen++;
            }
            $j += $dir;
        }
    }

    /** A short, title-like numbered line (not a "label: long clause" list item). */
    private static function isShortTitle($line)
    {
        $wc = str_word_count($line);
        if ($wc < 1 || $wc > 12) {
            return false;
        }
        if (mb_strlen($line) > 90) {
            return false;
        }
        // "Label: two or more words" is a list item, not a heading.
        if (preg_match('/:\s+\S+\s+\S+/u', $line)) {
            return false;
        }
        return true;
    }

    /** Is the next non-empty line a dotted TOC leader (so this is a TOC entry)? */
    private static function nextIsDotLeader(array $trimmed, $i, $count)
    {
        for ($j = $i + 1; $j < $count; $j++) {
            if ($trimmed[$j] === '') {
                continue;
            }
            return (bool) preg_match(self::DOT_LEADER, $trimmed[$j]);
        }
        return false;
    }

    /** Does the next non-empty line begin lowercase (a wrapped continuation)? */
    private static function nextStartsLower(array $trimmed, $i, $count)
    {
        for ($j = $i + 1; $j < $count; $j++) {
            if ($trimmed[$j] === '') {
                continue;
            }
            return (bool) preg_match('/^\p{Ll}/u', $trimmed[$j]);
        }
        return false;
    }

    /**
     * Derive the best title from the removed furniture. The running header is
     * the document's identity; where a fuller, non-repeating variant of it sits
     * near the top ("… 2025-2028"), promote that so the title is never itself a
     * bare furniture line.
     */
    private static function titleFromFurniture(array $removedMeta, array $rawLines)
    {
        // Title-like furniture lines (no colon labels, reasonable length).
        $cand = array(); // sig => array{text:string,idx:int,freq:int}
        foreach ($removedMeta as $m) {
            if (!empty($m['num'])) {
                continue;
            }
            if (!self::isTitleLike($m['text'])) {
                continue;
            }
            $sig = mb_strtolower(preg_replace('/\s+/', ' ', $m['text']));
            if (!isset($cand[$sig])) {
                $cand[$sig] = array('text' => $m['text'], 'idx' => $m['idx'], 'freq' => 0);
            }
            $cand[$sig]['freq']++;
        }
        if (empty($cand)) {
            return '';
        }
        $distinct = array_values($cand);
        usort($distinct, function ($a, $b) {
            return $a['idx'] - $b['idx'];
        });

        if (count($distinct) <= 3) {
            // A short multi-line banner (e.g. two-line PDF header).
            $parts = array();
            foreach ($distinct as $d) {
                $parts[] = $d['text'];
            }
            $seed = trim(implode(' ', $parts));
        } else {
            // Many candidates — take the most-repeated single header line.
            usort($distinct, function ($a, $b) {
                return $b['freq'] - $a['freq'];
            });
            $seed = $distinct[0]['text'];
        }

        $enriched = self::enrichTitle($rawLines, $seed);
        $title = $enriched !== '' ? $enriched : $seed;

        if (mb_strlen($title) < 5 || !preg_match('/\p{L}/u', $title)) {
            return '';
        }
        return $title;
    }

    /** A title-ish line: has letters, no colon label, not overly long/short. */
    private static function isTitleLike($text)
    {
        $t = trim($text);
        $len = mb_strlen($t);
        if ($len < 5 || $len > 70) {
            return false;
        }
        if (!preg_match('/\p{L}/u', $t) || strpos($t, ':') !== false) {
            return false;
        }
        if (preg_match(self::DOT_LEADER, $t) || preg_match(self::PAGE_LABEL, $t)) {
            return false;
        }
        $wc = str_word_count($t);
        return $wc >= 1 && $wc <= 10;
    }

    /**
     * Look near the top of the document for a fuller, non-repeating variant of
     * the header identity (contains the seed but is longer, no colon).
     */
    private static function enrichTitle(array $rawLines, $seed)
    {
        if ($seed === '') {
            return '';
        }
        $limit = min(35, count($rawLines));
        for ($i = 0; $i < $limit; $i++) {
            $t = trim($rawLines[$i]);
            if ($t === '' || strpos($t, ':') !== false) {
                continue;
            }
            if (mb_strlen($t) > mb_strlen($seed) && mb_strlen($t) <= 100
                && mb_stripos($t, $seed) !== false
                && !preg_match(self::DOT_LEADER, $t)) {
                return $t;
            }
        }
        return '';
    }

    /** @param array $blocks */
    private static function rebuildText(array $blocks)
    {
        $out = array();
        foreach ($blocks as $b) {
            $out[] = $b['text'];
        }
        return implode("\n", $out);
    }

    /** Bullet-block ratio for any block set (used to detect list-dominant docs). */
    public static function listRatio(array $blocks)
    {
        $total = count($blocks);
        if ($total === 0) {
            return 0.0;
        }
        $lists = 0;
        foreach ($blocks as $b) {
            if ($b['type'] === 'list') {
                $lists++;
            }
        }
        return round($lists / $total, 3);
    }

    /** Clean heading labels (leading numbering stripped) for any block set. */
    public static function headingTitles(array $blocks)
    {
        $titles = array();
        foreach ($blocks as $b) {
            if ($b['type'] === 'heading') {
                // Strip a leading "N." or "N.M" numbering for a clean label.
                $titles[] = trim(preg_replace('/^\s*\d+(\.\d+)*\.?\s*/', '', $b['text']));
            }
        }
        return $titles;
    }
}
