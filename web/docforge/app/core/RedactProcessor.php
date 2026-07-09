<?php

namespace DocForge\Core;

use DocForge\Redaction\RedactionEngine;

/**
 * Standalone Forge Redact — extract, detect PII, redact, export. No library.
 */
class RedactProcessor
{
    /**
     * @param array<string,mixed> $meta name, type, mime
     * @return array<string,mixed>
     */
    public static function run($filePath, array $meta, $mode, array $appConfig)
    {
        $doc = CiteProcessor::extractDoc($filePath, $meta);
        $doc['title'] = self::deriveTitle($doc, $meta['name']);

        $redactionConfig = self::redactionConfig($appConfig);
        if (isset($appConfig['redact_retain_map'])) {
            $redactionConfig['map']['retain'] = (bool) $appConfig['redact_retain_map'];
        }

        $engine = new RedactionEngine($redactionConfig);
        return $engine->redactDocument($doc, $mode, $meta['name']);
    }

    /** @param array<string,mixed> $ir */
    private static function deriveTitle(array $ir, $sourceName)
    {
        if (!empty($ir['meta_title'])) {
            return trim($ir['meta_title']);
        }
        if (!empty($ir['header'])) {
            return trim($ir['header']);
        }
        $base = pathinfo($sourceName, PATHINFO_FILENAME);
        return $base !== '' ? $base : 'Document';
    }

    /** @param array<string,mixed> $appConfig */
    private static function redactionConfig(array $appConfig)
    {
        $defaults = require dirname(__DIR__) . '/config/redaction.php';
        if (!empty($appConfig['redaction']) && is_array($appConfig['redaction'])) {
            return array_replace_recursive($defaults, $appConfig['redaction']);
        }
        return $defaults;
    }
}
