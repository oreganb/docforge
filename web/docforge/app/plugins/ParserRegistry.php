<?php

namespace DocForge\Plugins;

class ParserRegistry
{
    /** @return ParserPluginInterface[] */
    public static function all()
    {
        return array(
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
        // Always drop any residual invalid sequences.
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        if ($clean !== false) {
            $s = $clean;
        }
        // Strip control characters (C0 + C1 + DEL), keeping tab/newline/CR.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}]/u', '', $s);
        return $s === null ? '' : $s;
    }
}
