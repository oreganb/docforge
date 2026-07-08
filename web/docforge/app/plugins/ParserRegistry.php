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
                return array('plugin' => $plugin, 'ir' => $ir);
            }
        }
        if ($type === 'TXT' || $type === 'MD') {
            $plugin = $type === 'MD' ? new MdParser() : new TxtParser();
            $ir = $plugin->extract($filePath);
            $ir['parser'] = get_class($plugin);
            return array('plugin' => $plugin, 'ir' => $ir);
        }
        throw new \RuntimeException('No parser available for this file type.');
    }
}
