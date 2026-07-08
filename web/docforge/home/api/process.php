<?php
/**
 * Web worker — shared-hosting fallback for the CLI worker.
 *
 * exec() is disabled on most shared hosts, so the upload endpoint (and the
 * job status poll) fire a non-blocking HTTP request here. This script detaches
 * from the client connection and processes the job in the background.
 */

$config = require dirname(__DIR__) . '/app/core/bootstrap.php';

use DocForge\Core\Database;
use DocForge\Core\Pipeline;

$token = isset($_REQUEST['token']) ? $_REQUEST['token'] : '';
$jobId = isset($_REQUEST['job_id']) ? $_REQUEST['job_id'] : '';

// Authorise via the master token (cron / manual) OR a per-job HMAC token
// (safe to hand to the browser — it only unlocks that single job).
$master = $config['app']['worker_token'];
$masterOk = hash_equals((string) $master, (string) $token);
$jobOk = $jobId !== '' && hash_equals(hash_hmac('sha256', $jobId, $master), (string) $token);
if (!$masterOk && !$jobOk) {
    http_response_code(403);
    exit('Forbidden');
}

// Detach from the client so the request returns immediately.
ignore_user_abort(true);
set_time_limit(0);
@ini_set('max_execution_time', '0');

$payload = json_encode(array('ok' => true, 'dispatched' => true));
if (function_exists('fastcgi_finish_request')) {
    header('Content-Type: application/json');
    echo $payload;
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    header('Content-Type: application/json');
    echo $payload;
    litespeed_finish_request();
} else {
    // mod_php fallback: flush and close the connection manually.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json');
    echo $payload;
    header('Content-Length: ' . ob_get_length());
    header('Connection: close');
    @ob_end_flush();
    @flush();
    if (session_id() !== '') {
        session_write_close();
    }
}

$pdo = Database::connect($config);

if ($jobId !== '' && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $jobId)) {
    df_process_job($pdo, $config, $jobId);
} else {
    // No job id: drain the queue (cron / manual safety net).
    $ids = $pdo->query("SELECT id FROM df_jobs WHERE state = 'queued' ORDER BY created_at ASC LIMIT 10")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        df_process_job($pdo, $config, $id);
    }
}

function df_process_job(PDO $pdo, array $config, $jobId)
{
    // Atomic claim: only one worker can move a job out of 'queued'.
    $claim = $pdo->prepare("UPDATE df_jobs SET state = 'running', updated_at = NOW() WHERE id = ? AND state = 'queued'");
    $claim->execute(array($jobId));
    if ($claim->rowCount() === 0) {
        return; // already claimed or not queued
    }

    $stmt = $pdo->prepare('SELECT * FROM df_job_uploads WHERE job_id = ?');
    $stmt->execute(array($jobId));
    $upload = $stmt->fetch();
    if (!$upload) {
        $pdo->prepare("UPDATE df_jobs SET state = 'failed', error = 'Upload metadata missing', updated_at = NOW() WHERE id = ?")
            ->execute(array($jobId));
        return;
    }

    // Capture fatal errors (E_ERROR, memory/time limits) that bypass try/catch
    // so the job doesn't get stuck in 'running' forever.
    register_shutdown_function(function () use ($pdo, $jobId) {
        $err = error_get_last();
        if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
            $msg = $err['message'] . ' @ ' . $err['file'] . ':' . $err['line'];
            $pdo->prepare("UPDATE df_jobs SET state = 'failed', error = ?, updated_at = NOW() WHERE id = ? AND state = 'running'")
                ->execute(array($msg, $jobId));
        }
    });

    try {
        $pipeline = new Pipeline($config, $pdo, $jobId);
        $meta = array(
            'fingerprint' => $upload['fingerprint'],
            'source_name' => $upload['source_name'],
            'source_type' => $upload['source_type'],
            'size_bytes' => (int) $upload['size_bytes'],
            'mime' => $upload['mime'],
        );
        $pipeline->run($jobId, $upload['file_path'], $meta);
        $pdo->prepare('DELETE FROM df_job_uploads WHERE job_id = ?')->execute(array($jobId));
    } catch (\Throwable $e) {
        $pdo->prepare("UPDATE df_jobs SET state = 'failed', error = ?, updated_at = NOW() WHERE id = ?")
            ->execute(array($e->getMessage(), $jobId));
    }
}
