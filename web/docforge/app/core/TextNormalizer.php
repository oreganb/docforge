<?php

namespace DocForge\Core;

/**
 * Pre-processing for extracted text (SPECS Design Principle 7).
 *
 * Raw PDF/plain-text extraction leaks page furniture (running headers/footers,
 * page numbers) and loses list/heading structure. This normaliser:
 *   - removes standalone page numbers and their adjacent running header/footer
 *   - detects numbered section headings ("1. Leadership") and bullet lists
 *   - merges wrapped continuation lines back into their logical line
 *   - rebuilds clean blocks + clean full_text for all downstream analysis
 */
class TextNormalizer
{
    const BULLET = '/^\s*[\x{2022}\x{2023}\x{25AA}\x{25CF}\x{25E6}\x{2043}\x{2219}\x{00B7}\x{2713}\x{2714}\x{2717}\x{2718}\x{2610}\x{2611}\x{2612}*\x{2013}\x{2014}-]\s+/u';
    const NUMBERED_HEADING = '/^\s*\d+\.\s+[A-Z]/u';
    const PAGE_NUMBER = '/^\s*\d{1,4}\s*$/';

    /**
     * @param string $rawText
     * @return array{full_text:string,blocks:array,list_ratio:float,headings:array,removed_chrome:array}
     */
    public static function normalize($rawText, array $rawBlocks = array())
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $rawText);
        $count = count($lines);
        $removedMeta = array(); // list of array{idx:int,text:string,num:bool}
        $drop = array_fill(0, $count, false);

        // Pass 1 — strip page numbers and the running header/footer that hugs them.
        for ($i = 0; $i < $count; $i++) {
            if (!preg_match(self::PAGE_NUMBER, $lines[$i]) || trim($lines[$i]) === '') {
                continue;
            }
            $drop[$i] = true;
            $removedMeta[] = array('idx' => $i, 'text' => trim($lines[$i]), 'num' => true);
            self::dropAdjacentChrome($lines, $drop, $removedMeta, $i, -1); // footer above the number
            self::dropAdjacentChrome($lines, $drop, $removedMeta, $i, 1);  // header below the number
        }

        // Pass 2 — classify surviving lines into blocks, merging wrapped lines.
        $blocks = array();
        $pending = null; // open paragraph/list block awaiting continuation lines
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
            $line = trim($lines[$i]);
            if ($line === '') {
                $flush();
                continue;
            }
            if (preg_match(self::NUMBERED_HEADING, $line) && mb_strlen($line) < 90) {
                $flush();
                $blocks[] = array('type' => 'heading', 'text' => $line, 'level' => 2);
                continue;
            }
            if (preg_match(self::BULLET, $line)) {
                $flush();
                $pending = array('type' => 'list', 'text' => preg_replace(self::BULLET, '', $line));
                continue;
            }
            // Continuation of the current list item / paragraph, else new paragraph.
            if ($pending !== null) {
                $pending['text'] .= ' ' . $line;
            } else {
                $pending = array('type' => 'paragraph', 'text' => $line);
            }
        }
        $flush();

        // If normalisation found no structure at all, keep the parser's blocks.
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
            'header' => self::headerCandidate($removedMeta),
        );
    }

    /** Walk outward from a page-number line removing short header/footer lines. */
    private static function dropAdjacentChrome(array $lines, array &$drop, array &$removedMeta, $from, $dir)
    {
        $count = count($lines);
        $seen = 0;
        $j = $from + $dir;
        while ($j >= 0 && $j < $count && $seen < 2) {
            $t = trim($lines[$j]);
            if ($t === '') {
                $j += $dir;
                continue;
            }
            // Stop at real content: bullets, numbered headings, sentence-like lines.
            if (preg_match(self::BULLET, $t) || preg_match(self::NUMBERED_HEADING, $t)) {
                break;
            }
            if (preg_match('/[.!?,;:]$/', $t) || mb_strlen($t) > 80) {
                break;
            }
            $drop[$j] = true;
            $removedMeta[] = array('idx' => $j, 'text' => $t, 'num' => false);
            $seen++;
            $j += $dir;
        }
    }

    /**
     * Reconstruct the running-header text (document identity) from the removed
     * furniture. It is furniture in the body but the best title candidate, so
     * we rebuild it in reading order, dropping page numbers and duplicates.
     */
    private static function headerCandidate(array $removedMeta)
    {
        $seen = array();
        $items = array();
        foreach ($removedMeta as $m) {
            if (!empty($m['num'])) {
                continue; // page number, not header text
            }
            $key = mb_strtolower(preg_replace('/\s+/', ' ', $m['text']));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = $m;
        }
        usort($items, function ($a, $b) {
            return $a['idx'] - $b['idx'];
        });
        $parts = array();
        foreach ($items as $m) {
            $parts[] = $m['text'];
        }
        $header = trim(implode(' ', $parts));
        // Must look like a title: has letters and reasonable length.
        if (mb_strlen($header) < 5 || !preg_match('/\p{L}/u', $header)) {
            return '';
        }
        return $header;
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

    /** Clean heading labels (leading "N." numbering stripped) for any block set. */
    public static function headingTitles(array $blocks)
    {
        $titles = array();
        foreach ($blocks as $b) {
            if ($b['type'] === 'heading') {
                // Strip the leading "N." numbering for a clean label.
                $titles[] = trim(preg_replace('/^\s*\d+\.\s*/', '', $b['text']));
            }
        }
        return $titles;
    }
}
