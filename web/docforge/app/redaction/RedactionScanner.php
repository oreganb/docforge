<?php

namespace DocForge\Redaction;

/**
 * Post-export leak scan (FORGE_REDACT_SPECS.md §2 invariant).
 */
class RedactionScanner
{
    /**
     * @param array<int,array<string,mixed>> $spans original spans (pre-redaction)
     * @return array{ok:bool,leaks:array<int,string>}
     */
    public static function scan($output, array $spans)
    {
        $leaks = array();
        // The re-identification map deliberately contains originals — exclude it.
        $pos = stripos($output, '## Re-identification map');
        if ($pos !== false) {
            $output = substr($output, 0, $pos);
        }
        $surfaces = array();
        foreach ($spans as $s) {
            if (!empty($s['surface'])) {
                $surfaces[$s['surface']] = true;
            }
        }
        $normOut = self::normalize($output);
        foreach (array_keys($surfaces) as $surface) {
            if (self::containsSurface($normOut, $surface)) {
                $leaks[] = $surface;
            }
        }
        return array('ok' => empty($leaks), 'leaks' => $leaks);
    }

    private static function containsSurface($haystack, $surface)
    {
        $needle = self::normalize($surface);
        if ($needle === '' || mb_strlen($needle) < 3) {
            return false;
        }
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }

    private static function normalize($text)
    {
        $text = mb_strtolower((string) $text);
        return preg_replace('/\s+/u', ' ', $text);
    }
}
