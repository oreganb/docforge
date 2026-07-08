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

    /** @param array<string,mixed> $ir */
    private function deriveTitle(array $ir, $sourceName)
    {
        if (!empty($ir['blocks'])) {
            foreach ($ir['blocks'] as $b) {
                if ($b['type'] === 'heading' && strlen($b['text']) > 3) {
                    return mb_substr($b['text'], 0, 255);
                }
            }
        }
        return pathinfo($sourceName, PATHINFO_FILENAME);
    }

    /**
     * @param array<string,mixed> $ir
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function buildKnowledgeLayer(array $ir, array $meta, $title)
    {
        $quality = QualityEngine::assess($ir);
        $scores = KnowledgeScore::compute($ir);
        return array(
            'version' => $this->config['app']['knowledge_layer_version'],
            'title' => $title,
            'fingerprint' => array(
                'sha256' => $meta['fingerprint'],
                'size_bytes' => $meta['size_bytes'],
                'mime' => $meta['mime'],
                'source_name' => $meta['source_name'],
                'language' => isset($ir['language']) ? $ir['language'] : 'en',
            ),
            'source' => array(
                'type' => $meta['source_type'],
                'name' => $meta['source_name'],
            ),
            'pages' => array(array('number' => 1)),
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
            'provenance' => array(
                array('section' => 'extraction', 'method' => 'parser', 'tool' => isset($ir['parser']) ? $ir['parser'] : 'unknown'),
            ),
        );
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
