<?php

namespace DocForge\Redaction;

use DocForge\Exporters\RedactionReportExporter;

/**
 * Orchestrates detection, application, output scan, and report assembly.
 */
class RedactionEngine
{
    /** @var array<string,mixed> */
    private $config;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string,mixed> $doc extracted IR (blocks, full_text, …)
     * @param string $mode mask|token
     * @return array<string,mixed>
     */
    public function redactDocument(array $doc, $mode, $sourceName)
    {
        $mode = ($mode === 'mask') ? 'mask' : 'token';
        $detector = new RedactionDetector($this->config);
        $applier = new RedactionApplier($this->config, $mode);

        $allSpans = array();
        $fields = $this->collectTextFields($doc);
        $redacted = $doc;

        foreach ($fields as $ref => $text) {
            $fieldSpans = $detector->detect($text);
            foreach ($fieldSpans as $span) {
                $span['field'] = $ref;
                $allSpans[] = $span;
            }
            $newText = $applier->apply($text, $fieldSpans);
            $this->setField($redacted, $ref, $newText);
        }

        $stats = $this->buildStats($allSpans);
        $retainMap = !empty($this->config['map']['retain']) && $mode === 'token';
        $map = $retainMap ? $applier->getMap() : array();

        $report = array(
            'title' => isset($doc['title']) ? $doc['title'] : pathinfo($sourceName, PATHINFO_FILENAME),
            'source_name' => $sourceName,
            'mode' => $mode,
            'stats' => $stats,
            'spans' => $allSpans,
            'map_retained' => $retainMap,
            'redaction_map' => $map,
            'tier3_note' => $this->tier3Note(),
            'blocks' => isset($redacted['blocks']) ? $redacted['blocks'] : array(),
            'full_text' => isset($redacted['full_text']) ? $redacted['full_text'] : '',
        );

        $exporter = new RedactionReportExporter();
        $markdown = $exporter->export($report);
        $scan = RedactionScanner::scan($markdown, $allSpans);
        if (!$scan['ok']) {
            throw new \RuntimeException(
                'Redaction failed: ' . count($scan['leaks']) . ' surface form(s) survived in the export '
                . '(e.g. "' . $scan['leaks'][0] . '"). The file was not released.'
            );
        }
        $report['markdown'] = $markdown;
        $report['scan'] = $scan;

        return $report;
    }

    /** @return array<string,string> ref => text */
    private function collectTextFields(array $doc)
    {
        $fields = array();
        $blocks = isset($doc['blocks']) ? $doc['blocks'] : array();
        if (!empty($blocks)) {
            foreach ($blocks as $i => $b) {
                if (!empty($b['text'])) {
                    $fields['block:' . $i] = (string) $b['text'];
                }
            }
        } elseif (!empty($doc['full_text'])) {
            $fields['full_text'] = (string) $doc['full_text'];
        }
        return $fields;
    }

    private function setField(array &$doc, $ref, $text)
    {
        if ($ref === 'full_text') {
            $doc['full_text'] = $text;
            return;
        }
        if (preg_match('/^block:(\d+)$/', $ref, $m)) {
            $doc['blocks'][(int) $m[1]]['text'] = $text;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $spans
     * @return array<string,mixed>
     */
    private function buildStats(array $spans)
    {
        $byCat = array();
        $byTier = array(1 => 0, 2 => 0, 3 => 0);
        foreach ($spans as $s) {
            $c = $s['category'];
            if (!isset($byCat[$c])) {
                $byCat[$c] = 0;
            }
            $byCat[$c]++;
            $t = (int) $s['tier'];
            if (isset($byTier[$t])) {
                $byTier[$t]++;
            }
        }
        return array(
            'total' => count($spans),
            'by_category' => $byCat,
            'by_tier' => $byTier,
        );
    }

    private function tier3Note()
    {
        $tier3 = isset($this->config['tier3']) ? $this->config['tier3'] : array();
        if (!empty($tier3['ner'])) {
            return 'Tier 3 uses statistical NER plus gazetteers — review before external release.';
        }
        return 'Tier 3 (names/addresses) uses gazetteers and context patterns only — statistical; review before external release.';
    }
}
