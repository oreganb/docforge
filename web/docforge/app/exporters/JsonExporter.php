<?php

namespace DocForge\Exporters;

class JsonExporter
{
    /**
     * @param array<string,mixed> $doc
     */
    public function export(array $doc)
    {
        return json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
