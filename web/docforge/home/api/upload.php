<?php

$config = require dirname(__DIR__) . '/app/core/bootstrap.php';

use DocForge\Core\ApiResponse;
use DocForge\Core\Csrf;
use DocForge\Core\Database;
use DocForge\Core\FileValidator;
use DocForge\Core\Ulid;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('POST required', 405);
}

$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!Csrf::validate($token)) {
    ApiResponse::error('Invalid security token. Refresh the page and try again.', 403);
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    ApiResponse::error('No file was uploaded, or the upload failed.');
}

$file = $_FILES['file'];
$tmpPath = $file['tmp_name'];
$originalName = $file['name'];

$inspect = FileValidator::inspect($tmpPath, $originalName, $config['limits']['max_upload_bytes']);
if (!$inspect['ok']) {
    ApiResponse::error($inspect['error']);
}

$fingerprint = FileValidator::fingerprint($tmpPath);
$pdo = Database::connect($config);

$dupStmt = $pdo->prepare('SELECT id FROM df_reports WHERE fingerprint = ? ORDER BY id DESC LIMIT 1');
$dupStmt->execute(array($fingerprint));
$dup = $dupStmt->fetch();
$duplicateOf = $dup ? (int) $dup['id'] : null;

$jobId = Ulid::generate();
$destName = $jobId . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
$destPath = $config['storage']['uploads_tmp'] . '/' . $destName;

if (!move_uploaded_file($tmpPath, $destPath)) {
    ApiResponse::error('Could not save uploaded file.');
}

$pdo->prepare(
    'INSERT INTO df_jobs (id, state, percent, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())'
)->execute(array($jobId, 'queued'));

$pdo->prepare(
    'INSERT INTO df_job_uploads (job_id, file_path, fingerprint, source_name, source_type, size_bytes, mime, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
)->execute(array(
    $jobId,
    $destPath,
    $fingerprint,
    $originalName,
    $inspect['type'],
    filesize($destPath),
    $inspect['mime'],
));

// Best-effort server-side dispatch (works where loopback HTTP is allowed).
// On hosts that block self-requests, the browser kicks the worker instead
// using the per-job dispatch token below.
df_dispatch_worker($jobId, $config['app']['worker_token']);

$response = array(
    'ok' => true,
    'job_id' => $jobId,
    'dispatch_token' => hash_hmac('sha256', $jobId, $config['app']['worker_token']),
);
if ($duplicateOf !== null) {
    $response['duplicate_of'] = $duplicateOf;
}
ApiResponse::json($response);
