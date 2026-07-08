<?php

namespace DocForge\Core;

use DocForge\Analysis\CitationAnalyzer;
use DocForge\Exporters\CitationExporter;
use DocForge\Plugins\ParserRegistry;

/**
 * Standalone Forge Cite — extract uploaded documents and score reference
 * suitability without touching the library or job queue.
 */
class CiteProcessor
{
    /** Document types suitable for citation analysis (not datasets). */
    private static $allowedTypes = array('PDF', 'DOCX', 'MD', 'TXT');

    /**
     * @param string $workingPath temp path to the working document
     * @param array<int,array{path:string,name:string,type:string,mime:string}> $references
     * @return array{markdown:string,analysis:array<string,mixed>}
     */
    public static function run($workingPath, array $references, array $workingMeta)
    {
        $workingDoc = self::extractDoc($workingPath, $workingMeta);
        $working = array(
            'title' => self::deriveTitle($workingDoc, $workingMeta['name']),
            'sha256' => FileValidator::fingerprint($workingPath),
            'doc' => $workingDoc,
        );

        $refPayload = array();
        foreach ($references as $ref) {
            $doc = self::extractDoc($ref['path'], $ref);
            $refPayload[] = array(
                'id' => null,
                'title' => self::deriveTitle($doc, $ref['name']),
                'sha256' => FileValidator::fingerprint($ref['path']),
                'source_name' => $ref['name'],
                'doc' => $doc,
            );
        }

        $analysis = (new CitationAnalyzer())->analyse($working, $refPayload);
        $markdown = (new CitationExporter())->export($analysis);

        return array(
            'markdown' => $markdown,
            'analysis' => $analysis,
            'working_title' => $working['title'],
        );
    }

    /**
     * @param array<string,mixed> $meta keys: name, type, mime
     * @return array<string,mixed>
     */
    public static function extractDoc($filePath, array $meta)
    {
        if (!in_array($meta['type'], self::$allowedTypes, true)) {
            throw new \RuntimeException(
                'Forge Cite supports PDF, DOCX, Markdown, and plain text only — not datasets.'
            );
        }

        $parsed = ParserRegistry::parse($filePath, $meta['type'], $meta['mime']);
        $ir = $parsed['ir'];
        $parsed['plugin']->cleanup();

        if (!empty($ir['kind']) && $ir['kind'] === 'dataset') {
            throw new \RuntimeException('This file looks like a dataset, not a document.');
        }

        $parser = isset($ir['parser']) ? $ir['parser'] : '';
        $unreliable = strpos($parser, 'PdfParser') !== false
            || strpos($parser, 'TxtParser') !== false;
        if ($unreliable) {
            $norm = TextNormalizer::normalize(
                isset($ir['full_text']) ? $ir['full_text'] : '',
                isset($ir['blocks']) ? $ir['blocks'] : array()
            );
            $ir['full_text'] = $norm['full_text'];
            $ir['blocks'] = $norm['blocks'];
        }

        return $ir;
    }

    /**
     * @param array<string,mixed> $ir
     */
    private static function deriveTitle(array $ir, $sourceName)
    {
        if (!empty($ir['meta_title']) && self::looksLikeTitle($ir['meta_title'])) {
            return trim($ir['meta_title']);
        }
        if (!empty($ir['header']) && self::looksLikeTitle($ir['header'])) {
            return trim($ir['header']);
        }
        foreach (isset($ir['blocks']) ? $ir['blocks'] : array() as $b) {
            if (isset($b['type']) && $b['type'] === 'heading' && !empty($b['text'])
                && self::looksLikeTitle($b['text'])) {
                return trim($b['text']);
            }
        }
        $base = pathinfo($sourceName, PATHINFO_FILENAME);
        return $base !== '' ? $base : 'Document';
    }

    private static function looksLikeTitle($text)
    {
        $t = trim((string) $text);
        if ($t === '' || mb_strlen($t) > 200) {
            return false;
        }
        if (preg_match('/^\d+\.\s*$/', $t)) {
            return false;
        }
        return true;
    }
}
