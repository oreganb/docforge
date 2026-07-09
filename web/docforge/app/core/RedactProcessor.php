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

        return self::redactDoc($doc, $meta['name'], $mode, $appConfig);
    }

    /**
     * Redact pre-extracted text (e.g. browser OCR for scanned PDFs).
     *
     * @param array<string,mixed> $extra optional keys: ocr
     * @return array<string,mixed>
     */
    public static function runFromText($text, $sourceName, $mode, array $appConfig, array $extra = array())
    {
        $doc = self::docFromText($text);
        $doc['title'] = self::deriveTitle($doc, $sourceName);
        if (!empty($extra['ocr']) && is_array($extra['ocr'])) {
            $doc['ocr'] = $extra['ocr'];
            $doc['extraction'] = 'ocr';
        }
        return self::redactDoc($doc, $sourceName, $mode, $appConfig);
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private static function redactDoc(array $doc, $sourceName, $mode, array $appConfig)
    {
        $redactionConfig = self::redactionConfig($appConfig);
        if (isset($appConfig['redact_retain_map'])) {
            $redactionConfig['map']['retain'] = (bool) $appConfig['redact_retain_map'];
        }

        $engine = new RedactionEngine($redactionConfig);
        return $engine->redactDocument($doc, $mode, $sourceName);
    }

    /** @return array<string,mixed> */
    private static function docFromText($text)
    {
        $text = trim((string) $text);
        $blocks = array();
        $paragraphs = preg_split('/\n\s*\n/', $text);
        foreach ($paragraphs as $i => $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            if (strlen($para) < 100 && !preg_match('/[.!?]$/', $para)) {
                $blocks[] = array(
                    'type' => 'heading',
                    'text' => $para,
                    'level' => 2,
                    'location' => 'block:' . $i,
                );
            } else {
                $blocks[] = array(
                    'type' => 'paragraph',
                    'text' => $para,
                    'location' => 'block:' . $i,
                );
            }
        }
        return array('blocks' => $blocks, 'full_text' => $text);
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
