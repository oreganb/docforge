<?php
/**
 * CLI worker — process a queued job.
 * Usage: php worker.php <job_id>
 */

if (php_sapi_name() !== 'cli') {
    exit('CLI only');
}

$jobId = isset($argv[1]) ? $argv[1] : '';
if ($jobId === '') {
    fwrite(STDERR, "Usage: php worker.php <job_id>\n");
    exit(1);
}

$config = require __DIR__ . '/../core/bootstrap.php';
\DocForge\Core\Database::connect($config);
$pdo = \DocForge\Core\Database::pdo();

$stmt = $pdo->prepare('SELECT * FROM df_job_uploads WHERE job_id = ?');
$stmt->execute(array($jobId));
$upload = $stmt->fetch();
if (!$upload) {
    fwrite(STDERR, "Job upload metadata not found: $jobId\n");
    exit(1);
}

$logger = new \DocForge\Core\Logger($config['storage']['logs']);
\DocForge\Core\Events::on('StageCompleted', function ($p) use ($logger) {
    $logger->info('Stage completed', $p);
});
\DocForge\Core\Events::on('DocumentIngested', function ($p) use ($logger) {
    $logger->info('Document ingested', $p);
});

try {
    $pipeline = new \DocForge\Core\Pipeline($config, $pdo, $jobId);
    $meta = array(
        'fingerprint' => $upload['fingerprint'],
        'source_name' => $upload['source_name'],
        'source_type' => $upload['source_type'],
        'size_bytes' => (int) $upload['size_bytes'],
        'mime' => $upload['mime'],
    );
    $pipeline->run($jobId, $upload['file_path'], $meta);
    $pdo->prepare('DELETE FROM df_job_uploads WHERE job_id = ?')->execute(array($jobId));
    exit(0);
} catch (\Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
