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
            }
        }
        return $ir;
    }

    /** Convert a string to valid UTF-8, replacing/removing invalid byte sequences. */
    private static function toUtf8($s)
    {
        if (!is_string($s) || $s === '' || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        // The bytes are almost always Windows-1252 (a superset of Latin-1);
        // convert the whole string, then drop anything still invalid.
        $enc = mb_detect_encoding($s, array('UTF-8', 'Windows-1252', 'ISO-8859-1'), true);
        $s = mb_convert_encoding($s, 'UTF-8', $enc && $enc !== 'UTF-8' ? $enc : 'Windows-1252');
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        return $clean === false ? $s : $clean;
    }
}
