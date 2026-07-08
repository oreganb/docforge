<?php

namespace DocForge\Core;

/**
 * Colour-codes Forge Cite affinity / suitability scores in rendered HTML.
 *
 * Tiers (0–1 affinity, or equivalent %):
 *   ≥ 0.85  great  — green
 *   ≥ 0.75  okay   — light orange
 *   > 0.65  poor   — light red
 *   ≤ 0.65  none   — no highlight
 */
class CiteHighlighter
{
    /**
     * @param float $score 0–1 affinity (or pass percent/100)
     * @return string|null great|okay|poor
     */
    public static function tier($score)
    {
        if ($score >= 0.85) {
            return 'great';
        }
        if ($score >= 0.75) {
            return 'okay';
        }
        if ($score > 0.65) {
            return 'poor';
        }
        return null;
    }

    /** @param int|float $percent 0–100 suitability */
    public static function tierFromPercent($percent)
    {
        return self::tier((float) $percent / 100.0);
    }

    /**
     * Highlight each passage paragraph in §2 Annotated Document using the best
     * match affinity for that passage (same tier thresholds as score badges).
     *
     * @param array<int,array<string,mixed>> $passages analyzer output passages
     */
    public static function highlightPassages($html, array $passages)
    {
        if (empty($passages)) {
            return $html;
        }

        $start = stripos($html, '<h2>2. Annotated Document</h2>');
        if ($start === false) {
            return $html;
        }
        $end = stripos($html, '<h2>3. Per-Reference Evidence</h2>', $start);
        $head = substr($html, 0, $start);
        $section = $end !== false ? substr($html, $start, $end - $start) : substr($html, $start);
        $tail = $end !== false ? substr($html, $end) : '';

        $idx = 0;
        $section = preg_replace_callback(
            '/<h3>[^<]*<\/h3>\s*<p>(.*?)<\/p>/s',
            function ($m) use ($passages, &$idx) {
                if (!isset($passages[$idx])) {
                    return $m[0];
                }
                $passage = $passages[$idx];
                $idx++;

                $best = 0.0;
                foreach (isset($passage['matches']) ? $passage['matches'] : array() as $match) {
                    $aff = isset($match['affinity']) ? (float) $match['affinity'] : 0.0;
                    if ($aff > $best) {
                        $best = $aff;
                    }
                }
                $tier = self::tier($best);
                if ($tier === null) {
                    return $m[0];
                }

                return preg_replace(
                    '/<p>/',
                    '<p class="df-cite-passage df-cite-passage-' . $tier . '">',
                    $m[0],
                    1
                );
            },
            $section
        );

        return $head . $section . $tail;
    }

    /**
     * Post-process Parsedown HTML: wrap scored values in tier spans.
     */
    public static function highlightHtml($html)
    {
        // Blockquote annotations: "affinity 0.98"
        $html = preg_replace_callback(
            '/affinity\s+(\d+\.\d{2})/i',
            function ($m) {
                return self::replaceScore('affinity ', $m[1], true);
            },
            $html
        );

        // Table / inline affinity cells: 0.00–1.00
        $html = preg_replace_callback(
            '/<td>(\d\.\d{2})<\/td>/',
            function ($m) {
                $score = (float) $m[1];
                if ($score > 1.0) {
                    return $m[0];
                }
                $tier = self::tier($score);
                if ($tier === null) {
                    return $m[0];
                }
                return '<td>' . self::span($m[1], $tier) . '</td>';
            },
            $html
        );

        // Suitability percentages in tables and headings.
        $html = preg_replace_callback(
            '/<td>(\d{1,3})%<\/td>/',
            function ($m) {
                $tier = self::tierFromPercent((int) $m[1]);
                if ($tier === null) {
                    return $m[0];
                }
                return '<td>' . self::span($m[1] . '%', $tier) . '</td>';
            },
            $html
        );

        $html = preg_replace_callback(
            '/—\s*(\d{1,3})%\s*\(covers/',
            function ($m) {
                $tier = self::tierFromPercent((int) $m[1]);
                if ($tier === null) {
                    return $m[0];
                }
                return '— ' . self::span($m[1] . '%', $tier) . ' (covers';
            },
            $html
        );

        // Parenthesised affinities in "Best passages" cells: (0.98)
        $html = preg_replace_callback(
            '/\((\d\.\d{2})\)/',
            function ($m) {
                $score = (float) $m[1];
                if ($score > 1.0) {
                    return $m[0];
                }
                $tier = self::tier($score);
                if ($tier === null) {
                    return $m[0];
                }
                return '(' . self::span($m[1], $tier) . ')';
            },
            $html
        );

        return $html;
    }

    private static function replaceScore($prefix, $num, $keepPrefix)
    {
        $tier = self::tier((float) $num);
        if ($tier === null) {
            return $prefix . $num;
        }
        return ($keepPrefix ? $prefix : '') . self::span($num, $tier);
    }

    private static function span($text, $tier)
    {
        return '<span class="df-cite-tier df-cite-tier-' . htmlspecialchars($tier) . '">'
            . htmlspecialchars($text) . '</span>';
    }
}
