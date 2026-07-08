<?php

namespace DocForge\Plugins;

class ParserRegistry
{
    /** @return ParserPluginInterface[] */
    public static function all()
    {
        return array(
            // DataParser first: an XLSX is a PK/zip whose MIME contains
            // "officedocument", which DocxParser would otherwise claim.
            new DataParser(),
            new PdfParser(),
            new DocxParser(),
            new MdParser(),
            new TxtParser(),
        );
    }

    /**
     * @return array{plugin:ParserPluginInterface,ir:array<string,mixed>}
     */
    public static function parse($filePath, $type, $mime)
    {
        $bytes = file_get_contents($filePath, false, null, 0, 8192);
        if ($bytes === false) {
            throw new \RuntimeException('Could not read uploaded file.');
        }
        foreach (self::all() as $plugin) {
            if ($plugin->detect($bytes, $mime)) {
                $ir = $plugin->extract($filePath);
                $ir['parser'] = get_class($plugin);
                return array('plugin' => $plugin, 'ir' => self::sanitizeIr($ir));
            }
        }
        if ($type === 'TXT' || $type === 'MD') {
            $plugin = $type === 'MD' ? new MdParser() : new TxtParser();
            $ir = $plugin->extract($filePath);
            $ir['parser'] = get_class($plugin);
            return array('plugin' => $plugin, 'ir' => self::sanitizeIr($ir));
        }
        throw new \RuntimeException('No parser available for this file type.');
    }

    /**
     * Scrub all extracted text to valid UTF-8. PDF/DOCX extraction can emit
     * Windows-1252 / Latin-1 bytes that MySQL's utf8mb4 columns reject.
     *
     * @param array<string,mixed> $ir
     * @return array<string,mixed>
     */
    private static function sanitizeIr(array $ir)
    {
        if (isset($ir['full_text'])) {
            $ir['full_text'] = self::toUtf8($ir['full_text']);
        }
        if (!empty($ir['blocks']) && is_array($ir['blocks'])) {
            foreach ($ir['blocks'] as $i => $block) {
                if (isset($block['text'])) {
                    $ir['blocks'][$i]['text'] = self::toUtf8($block['text']);
                }
                // Table blocks carry rows of cells instead of a text string.
                if (isset($block['rows']) && is_array($block['rows'])) {
                    foreach ($block['rows'] as $r => $row) {
                        foreach ((array) $row as $c => $cell) {
                            $ir['blocks'][$i]['rows'][$r][$c] = self::toUtf8((string) $cell);
                        }
                    }
                }
            }
        }
        return $ir;
    }

    /** Convert a string to valid, storable UTF-8: fix encoding, drop invalid
     *  byte sequences and control characters that utf8mb4 columns reject. */
    private static function toUtf8($s)
    {
        if (!is_string($s) || $s === '') {
            return $s;
        }
        // If not valid UTF-8, assume Windows-1252 (superset of Latin-1).
        if (!mb_check_encoding($s, 'UTF-8')) {
            $enc = mb_detect_encoding($s, array('UTF-8', 'Windows-1252', 'ISO-8859-1'), true);
            $s = mb_convert_encoding($s, 'UTF-8', $enc && $enc !== 'UTF-8' ? $enc : 'Windows-1252');
        }
        // Repair "mojibake" — valid UTF-8 that is actually UTF-8 which was once
        // mis-decoded as Latin-1/CP1252 and re-encoded (e.g. "Ã¼" for "ü",
        // "Î³" for "γ", "Â©" for "©"). smalot's text layer produces this on some
        // PDFs. This must run AFTER the check above, because mojibake is itself
        // technically valid UTF-8.
        $s = self::fixMojibake($s);
        // Always drop any residual invalid sequences.
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        if ($clean !== false) {
            $s = $clean;
        }
        // Strip control characters (C0 + C1 + DEL), keeping tab/newline/CR.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}]/u', '', $s);
        if ($s === null) {
            return '';
        }
        // Normalise to NFC so canonically-equivalent forms compare/store uniformly.
        if (class_exists('\\Normalizer') && !\Normalizer::isNormalized($s, \Normalizer::FORM_C)) {
            $nfc = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if (is_string($nfc)) {
                $s = $nfc;
            }
        }
        return $s;
    }

    /**
     * Reverse one (or two) layers of UTF-8-as-CP1252 double-encoding.
     *
     * We work surgically: only substrings that match a mojibake signature — a
     * reinterpreted UTF-8 lead byte (U+00C2–U+00F4) followed by 1–3 continuation
     * characters (U+0080–U+00BF or a CP1252 punctuation codepoint) — are
     * re-decoded. Legitimate text (a lone "é", real Greek, etc.) never matches
     * this two-plus-character shape, so it is left untouched.
     */
    private static function fixMojibake($s)
    {
        if ($s === '' || !mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        // CP1252 renderings of bytes 0x80–0x9F, which appear as continuation
        // characters when 3-byte UTF-8 (e.g. curly quotes, "â€™") is mangled.
        $cp1252 = '\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}'
            . '\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}'
            . '\x{201D}\x{2022}\x{2013}\x{2014}\x{02DC}\x{2122}\x{0161}\x{203A}'
            . '\x{0153}\x{017E}\x{0178}';
        $lead = '\x{00C2}-\x{00F4}';
        $cont = '\x{0080}-\x{00BF}' . $cp1252;
        $pattern = '/(?:[' . $lead . '][' . $cont . ']{1,3})+/u';

        for ($pass = 0; $pass < 2; $pass++) {
            if (!preg_match($pattern, $s)) {
                break;
            }
            $s = preg_replace_callback($pattern, function ($m) {
                // Re-interpret the run's code points as CP1252 bytes; those bytes
                // are the original UTF-8 the source intended.
                $bytes = @mb_convert_encoding($m[0], 'Windows-1252', 'UTF-8');
                if ($bytes !== false && $bytes !== '' && mb_check_encoding($bytes, 'UTF-8')) {
                    return $bytes;
                }
                return $m[0];
            }, $s);
            if (!is_string($s)) {
                return '';
            }
        }
        return $s;
    }
}
