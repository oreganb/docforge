<?php

$config = require dirname(__DIR__) . '/app/core/bootstrap.php';

use DocForge\Core\ApiResponse;
use DocForge\Core\Database;

$pdo = Database::connect($config);

$id = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
$fmt = isset($_GET['fmt']) ? $_GET['fmt'] : 'md';

if ($id <= 0) {
    ApiResponse::error('Report id required');
}

$stmt = $pdo->prepare('SELECT * FROM df_reports WHERE id = ?');
$stmt->execute(array($id));
$report = $stmt->fetch();
if (!$report) {
    ApiResponse::error('Report not found', 404);
}

$storageRoot = dirname(__DIR__) . '/storage/';
if ($fmt === 'json') {
    $path = $storageRoot . $report['json_path'];
    $mime = 'application/json';
    $ext = 'json';
} else {
    $path = $storageRoot . $report['md_path'];
    $mime = 'text/markdown';
    $ext = 'md';
}

if (!is_file($path)) {
    ApiResponse::error('Export file is missing on disk.', 404);
}

$safeTitle = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $report['title']);
$filename = $safeTitle . '.' . $ext;

header('Content-Type: ' . $mime . '; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
