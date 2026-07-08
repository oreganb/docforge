<?php

$config = require dirname(__DIR__) . '/app/core/bootstrap.php';

use DocForge\Core\ApiResponse;
use DocForge\Core\Database;

$pdo = Database::connect($config);

$id = isset($_GET['id']) ? $_GET['id'] : '';
if ($id === '' || !preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id)) {
    ApiResponse::error('Job id required');
}

$stmt = $pdo->prepare('SELECT * FROM df_jobs WHERE id = ?');
$stmt->execute(array($id));
$job = $stmt->fetch();
if (!$job) {
    ApiResponse::error('Job not found', 404);
}

$reportId = null;
if ($job['state'] === 'complete') {
    $r = $pdo->prepare('SELECT id FROM df_reports WHERE job_id = ? LIMIT 1');
    $r->execute(array($id));
    $row = $r->fetch();
    if ($row) {
        $reportId = (int) $row['id'];
    }
}

ApiResponse::json(array(
    'ok' => true,
    'state' => $job['state'],
    'phase' => $job['phase'],
    'stage' => $job['stage'],
    'tool' => $job['tool'],
    'percent' => (int) $job['percent'],
    'report_id' => $reportId,
    'error' => $job['error'],
));
