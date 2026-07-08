<?php

$config = require dirname(__DIR__) . '/app/core/bootstrap.php';

use DocForge\Core\ApiResponse;
use DocForge\Core\Csrf;
use DocForge\Core\Database;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('POST required', 405);
}

$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!Csrf::validate($token)) {
    ApiResponse::error('Invalid security token. Refresh the page and try again.', 403);
}

$id = (int) (isset($_POST['id']) ? $_POST['id'] : 0);
if ($id <= 0) {
    ApiResponse::error('Report id required');
}

$pdo = Database::connect($config);

$stmt = $pdo->prepare('SELECT * FROM df_reports WHERE id = ?');
$stmt->execute(array($id));
$report = $stmt->fetch();
if (!$report) {
    ApiResponse::error('Report not found', 404);
}

// Remove the export files first (best-effort — a missing file must not block the
// database cleanup). Paths are app-generated and stored relative to storage/.
$storageRoot = dirname(__DIR__) . '/storage/';
foreach (array('md_path', 'json_path') as $col) {
    if (!empty($report[$col])) {
        $path = $storageRoot . $report[$col];
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

// Delete the report and its queryable substrate rows together.
$pdo->beginTransaction();
try {
    foreach (array('df_report_keyphrases', 'df_report_entities', 'df_report_references') as $table) {
        $pdo->prepare("DELETE FROM $table WHERE report_id = ?")->execute(array($id));
    }
    $pdo->prepare('DELETE FROM df_reports WHERE id = ?')->execute(array($id));
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ApiResponse::error('Could not delete the report. Please try again.', 500);
}

ApiResponse::json(array('ok' => true, 'id' => $id));
