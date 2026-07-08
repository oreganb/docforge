<?php

namespace DocForge\Core;

use DocForge\Analysis\KeyphraseModule;
use DocForge\Analysis\ReferenceModule;
use DocForge\Analysis\StatsModule;
use DocForge\Analysis\StructureModule;
use DocForge\Analysis\SummaryModule;
use DocForge\Exporters\JsonExporter;
use DocForge\Exporters\MarkdownExporter;
use DocForge\Plugins\ParserRegistry;
use DocForge\Trust\KnowledgeScore;
use DocForge\Trust\QualityEngine;

/**
 * Phase 1 pipeline worker (PRD §11 stage map).
 */
class Pipeline
{
    /** @var array<string,mixed> */
    private $config;

    /** @var \PDO */
    private $pdo;

    /** @var ProgressWriter */
    private $progress;

    /** @var Logger */
    private $logger;

    /** PRD §11 weights — Phase 1 subset (no OCR/image stages) */
    private static $stages = array(
        array('phase' => 'Forge Read', 'stage' => 'reading document', 'tool' => 'fingerprint', 'weight' => 2),
        array('phase' => 'Forge Read', 'stage' => 'extracting content', 'tool' => 'parser', 'weight' => 15),
        array('phase' => 'Forge Understand', 'stage' => 'structural analysis', 'tool' => 'heading tree', 'weight' => 2),
        array('phase' => 'Forge Analyse', 'stage' => 'identifying references', 'tool' => 'pattern matcher', 'weight' => 9),
        array('phase' => 'Forge Analyse', 'stage' => 'building summary', 'tool' => 'TextRank', 'weight' => 12),
        array('phase' => 'Forge Analyse', 'stage' => 'extracting key phrases', 'tool' => 'rake-php-plus', 'weight' => 4),
        array('phase' => 'Forge Verify', 'stage' => 'assessing quality', 'tool' => 'DocForge QA', 'weight' => 3),
        array('phase' => 'Forge Verify', 'stage' => 'computing Knowledge Score', 'tool' => 'score composer', 'weight' => 1),
        array('phase' => 'Forge Build', 'stage' => 'generating report', 'tool' => 'report builder', 'weight' => 2),
        array('phase' => 'Forge Build', 'stage' => 'generating JSON export', 'tool' => 'serialiser', 'weight' => 1),
        array('phase' => 'Forge Publish', 'stage' => 'saving to library', 'tool' => 'library writer', 'weight' => 3),
    );

    /** @param array<string,mixed> $config */
    public function __construct(array $config, \PDO $pdo, $jobId)
    {
        $this->config = $config;
        $this->pdo = $pdo;
        $this->progress = new ProgressWriter($pdo, $jobId);
        $this->logger = new Logger($config['storage']['logs']);
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function run($jobId, $filePath, array $meta)
    {
        $this->progress->markRunning();
        $pct = 0;
        $ir = null;
        $plugin = null;

        try {
            foreach (self::$stages as $stage) {
                $this->emitStage($jobId, $stage['stage']);
                $this->progress->update($stage['phase'], $stage['stage'], $stage['tool'], $pct);

                if ($stage['stage'] === 'reading document') {
                    // fingerprint already computed at upload
                } elseif ($stage['stage'] === 'extracting content') {
                    $parsed = ParserRegistry::parse($filePath, $meta['source_type'], $meta['mime']);
                    $plugin = $parsed['plugin'];
                    $ir = $parsed['ir'];
                    // FR-9: delete original once IR is built
                    if (is_file($filePath)) {
                        unlink($filePath);
                    }
                    $plugin->cleanup();
                } elseif ($stage['stage'] === 'structural analysis' && $ir !== null) {
                    // Pre-process before any analysis. PDF/plain-text extraction
                    // loses structure and leaks page furniture, so re-segment from
                    // text. DOCX/Markdown already carry reliable heading blocks
                    // (styles / #), so keep those as-is.
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
                        $ir['removed_chrome'] = $norm['removed_chrome'];
                        $ir['header'] = $norm['header'];
                    }
                    // Derive list-dominance + heading labels from whatever blocks
                    // we ended up with, so the summariser can pick its strategy.
                    $blocks = isset($ir['blocks']) ? $ir['blocks'] : array();
                    $ir['list_ratio'] = TextNormalizer::listRatio($blocks);
                    $ir['headings'] = TextNormalizer::headingTitles($blocks);

                    $mod = new StructureModule();
                    $ir = array_merge($ir, $mod->analyse($ir));
                } elseif ($stage['stage'] === 'identifying references' && $ir !== null) {
                    $mod = new ReferenceModule();
                    $ir = array_merge($ir, $mod->analyse($ir));
                } elseif ($stage['stage'] === 'building summary' && $ir !== null) {
                    $mod = new SummaryModule();
                    $ir = array_merge($ir, $mod->analyse($ir));
                    $mod2 = new StatsModule();
                    $ir = array_merge($ir, $mod2->analyse($ir));
                } elseif ($stage['stage'] === 'extracting key phrases' && $ir !== null) {
                    $mod = new KeyphraseModule();
                    $ir = array_merge($ir, $mod->analyse($ir));
                }

                $pct += $stage['weight'];
                $this->progress->update($stage['phase'], $stage['stage'], $stage['tool'], min(99, $pct));
            }

            if ($ir === null) {
                throw new \RuntimeException('Pipeline failed to build document representation.');
            }

            $title = $this->deriveTitle($ir, $meta['source_name']);
            $doc = $this->buildKnowledgeLayer($ir, $meta, $title);
            $reportId = $this->publish($jobId, $doc, $meta);

            Events::emit('KnowledgeBuilt', array('job_id' => $jobId));
            Events::emit('ReportPublished', array('job_id' => $jobId, 'report_id' => $reportId));

            $this->progress->markComplete();
            return $reportId;
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), array('job_id' => $jobId, 'at' => $e->getFile() . ':' . $e->getLine()));
            $this->progress->markFailed($e->getMessage());
            if (is_file($filePath)) {
                @unlink($filePath);
            }
            throw $e;
        }
    }

    private function emitStage($jobId, $stage)
    {
        Events::emit('StageCompleted', array('job_id' => $jobId, 'stage' => $stage));
    }

    /**
     * Title heuristic, best identity first: embedded document-metadata title →
     * repeated running header (furniture in the body but the document's name) →
     * first heading → filename. Avoids titling a report "1. Leadership".
     *
     * @param array<string,mixed> $ir
     */
    private function deriveTitle(array $ir, $sourceName)
    {
        // 1. Embedded metadata title (PDF /Title, DOCX core properties).
        if (!empty($ir['meta_title']) && $this->looksLikeTitle($ir['meta_title'])) {
            return mb_substr(trim($ir['meta_title']), 0, 255);
        }
        // 2. Running header banner reconstructed from removed page furniture.
        if (!empty($ir['header']) && $this->looksLikeTitle($ir['header'])) {
            return mb_substr(trim($ir['header']), 0, 255);
        }
        // 3. First real heading in the body.
        if (!empty($ir['blocks'])) {
            foreach ($ir['blocks'] as $b) {
                if ($b['type'] === 'heading' && strlen($b['text']) > 3) {
                    return mb_substr($b['text'], 0, 255);
                }
            }
        }
        // 4. Fall back to the uploaded filename.
        return pathinfo($sourceName, PATHINFO_FILENAME);
    }

    /** Reject junk metadata (empty, numeric-only, or "Microsoft Word - ..."). */
    private function looksLikeTitle($value)
    {
        $value = trim((string) $value);
        if (mb_strlen($value) < 3 || mb_strlen($value) > 200) {
            return false;
        }
        if (!preg_match('/\p{L}/u', $value)) {
            return false; // no letters — e.g. a stray page number
        }
        if (preg_match('/^microsoft word\s*-/i', $value)) {
            return false; // authoring-tool placeholder, not a real title
        }
        return true;
    }

    /**
     * @param array<string,mixed> $ir
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function buildKnowledgeLayer(array $ir, array $meta, $title)
    {
        $quality = QualityEngine::assess($ir, $meta);
        $scores = KnowledgeScore::compute($ir);
        $pageCount = isset($ir['page_count']) ? max(1, (int) $ir['page_count']) : 1;
        $pages = array();
        for ($p = 1; $p <= $pageCount; $p++) {
            $pages[] = array('number' => $p);
        }
        // Flowed formats (DOCX/TXT/MD) have no fixed pagination — reporting
        // "1 page" for a 20-minute read misleads. Disclose it honestly.
        $flowed = strtoupper($meta['source_type']) !== 'PDF';
        $pageDisplay = $flowed ? 'n/a (flowed format)' : $pageCount;
        return array(
            'version' => $this->config['app']['knowledge_layer_version'],
            'title' => $title,
            'fingerprint' => array(
                'sha256' => $meta['fingerprint'],
                'size_bytes' => $meta['size_bytes'],
                'mime' => $meta['mime'],
                'source_name' => $meta['source_name'],
                'language' => isset($ir['language']) ? $ir['language'] : 'en',
                'page_count' => $pageDisplay,
                'extracted_at' => gmdate('c'),
                'duplicate_of' => $this->findDuplicate($meta['fingerprint']),
            ),
            'source' => array(
                'type' => $meta['source_type'],
                'name' => $meta['source_name'],
            ),
            'pages' => $pages,
            'blocks' => isset($ir['blocks']) ? $ir['blocks'] : array(),
            'sections' => isset($ir['sections']) ? $ir['sections'] : array(),
            'tables' => array(),
            'figures' => array(),
            'entities' => array(),
            'references' => isset($ir['references']) ? $ir['references'] : array(),
            'summaries' => isset($ir['summaries']) ? $ir['summaries'] : array(),
            'keyphrases' => isset($ir['keyphrases']) ? $ir['keyphrases'] : array(),
            'statistics' => isset($ir['statistics']) ? $ir['statistics'] : array(),
            'quality' => array_merge($quality, $scores),
            'provenance' => $this->buildProvenance($ir),
        );
    }

    /**
     * @param array<string,mixed> $ir
     * @return array<int,array<string,string>>
     */
    private function buildProvenance(array $ir)
    {
        $provenance = array(
            array('section' => 'extraction', 'method' => 'parser', 'tool' => isset($ir['parser']) ? $ir['parser'] : 'unknown'),
        );
        // When language couldn't be trusted (too little text), record that we
        // declared it undetermined and fell back to English stopwords.
        if (!empty($ir['language_fallback']) || (isset($ir['language']) && $ir['language'] === 'und')) {
            $provenance[] = array(
                'section' => 'language',
                'method' => 'fallback',
                'tool' => 'undetermined; English stopwords used (input below reliable-detection threshold)',
            );
        }
        return $provenance;
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $meta
     */
    private function publish($jobId, array $doc, array $meta)
    {
        $mdExporter = new MarkdownExporter();
        $jsonExporter = new JsonExporter();
        $slug = $jobId;
        $mdPath = 'reports/' . $slug . '.md';
        $jsonPath = 'reports/' . $slug . '.json';
        $mdFull = $this->config['storage']['reports'] . '/' . $slug . '.md';
        $jsonFull = $this->config['storage']['reports'] . '/' . $slug . '.json';

        file_put_contents($mdFull, $mdExporter->export($doc));
        file_put_contents($jsonFull, $jsonExporter->export($doc));

        $excerpt = isset($doc['summaries']['short']) ? $doc['summaries']['short'] : '';
        $score = isset($doc['quality']['knowledge_score']) ? $doc['quality']['knowledge_score'] : 0;

        $stmt = $this->pdo->prepare(
            'INSERT INTO df_reports (job_id, fingerprint, title, source_name, source_type, size_bytes, excerpt, knowledge_score, md_path, json_path, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute(array(
            $jobId,
            $meta['fingerprint'],
            $this->clip($doc['title'], 255),
            $this->clip($meta['source_name'], 255),
            $meta['source_type'],
            $meta['size_bytes'],
            $excerpt,
            $score,
            $mdPath,
            $jsonPath,
        ));
        $reportId = (int) $this->pdo->lastInsertId();

        foreach (isset($doc['keyphrases']) ? $doc['keyphrases'] : array() as $kp) {
            $phrase = $this->clip($kp['phrase'], 128);
            if ($phrase === '') {
                continue;
            }
            $this->pdo->prepare(
                'INSERT INTO df_report_keyphrases (report_id, phrase, score) VALUES (?, ?, ?)'
            )->execute(array($reportId, $phrase, $kp['score']));
        }
        foreach (isset($doc['references']) ? $doc['references'] : array() as $ref) {
            $this->pdo->prepare(
                'INSERT INTO df_report_references (report_id, raw, doi, url) VALUES (?, ?, ?, ?)'
            )->execute(array($reportId, $ref['raw'], $this->clip($ref['doi'], 128), $ref['url']));
        }

        return $reportId;
    }

    /**
     * FR-2 duplicate surfacing: find the earliest prior report with the same
     * content fingerprint, so a re-run is self-documenting.
     *
     * @return array{report_id:int,created_at:string}|null
     */
    private function findDuplicate($fingerprint)
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, created_at FROM df_reports WHERE fingerprint = ? ORDER BY created_at ASC, id ASC LIMIT 1'
            );
            $stmt->execute(array($fingerprint));
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                return array(
                    'report_id' => (int) $row['id'],
                    'created_at' => (string) $row['created_at'],
                );
            }
        } catch (\Throwable $e) {
            // Non-fatal: duplicate surfacing is advisory only.
        }
        return null;
    }

    /** Truncate a string to a column length (multibyte-safe); preserves null. */
    private function clip($value, $max)
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
