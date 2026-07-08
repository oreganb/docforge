<?php

$config = require dirname(__DIR__) . '/app/core/bootstrap.php';

use DocForge\Core\ApiResponse;
use DocForge\Core\Database;

$pdo = Database::connect($config);

$id = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
if ($id <= 0) {
    ApiResponse::error('Report id required');
}

$stmt = $pdo->prepare(
    'SELECT id, title, excerpt, source_type, knowledge_score, created_at FROM df_reports WHERE id = ?'
);
$stmt->execute(array($id));
$report = $stmt->fetch();
if (!$report) {
    ApiResponse::error('Report not found', 404);
}

$score = (int) $report['knowledge_score'];
$subs = array();
$jsonPath = dirname(__DIR__) . '/storage/reports/';
$files = glob($jsonPath . '*.json');
// load sub-scores from json if available
$jobStmt = $pdo->prepare('SELECT job_id FROM df_reports WHERE id = ?');
$jobStmt->execute(array($id));
$jobRow = $jobStmt->fetch();
if ($jobRow) {
    $jf = $jsonPath . $jobRow['job_id'] . '.json';
    if (is_file($jf)) {
        $data = json_decode(file_get_contents($jf), true);
        if (isset($data['quality']['sub_scores'])) {
            $subs = $data['quality']['sub_scores'];
        }
    }
}

ApiResponse::json(array(
    'ok' => true,
    'report' => array(
        'id' => (int) $report['id'],
        'title' => $report['title'],
        'excerpt' => $report['excerpt'],
        'knowledge_score' => $score,
        'sub_scores' => $subs,
        'stars' => \DocForge\Trust\KnowledgeScore::starString($score),
    ),
));
