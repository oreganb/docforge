<?php
/**
 * DocForge configuration — adjust for your environment.
 * Production overrides: config.local.php (not in git).
 */

$config = array(
    'db' => array(
        'host' => getenv('DOCFORGE_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DOCFORGE_DB_PORT') ?: '3306',
        'name' => getenv('DOCFORGE_DB_NAME') ?: 'docforge',
        'user' => getenv('DOCFORGE_DB_USER') ?: 'docforge',
        'pass' => getenv('DOCFORGE_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ),
    'storage' => array(
        'uploads_tmp' => dirname(__DIR__, 2) . '/storage/uploads-tmp',
        'reports' => dirname(__DIR__, 2) . '/storage/reports',
        'logs' => dirname(__DIR__, 2) . '/storage/logs',
    ),
    'limits' => array(
        'max_upload_bytes' => 500 * 1024 * 1024, // FR-2 / OQ-2
        'library_per_page' => 20,
        'upload_sweep_seconds' => 3600, // FR-9 sweeper
    ),
    'app' => array(
        'base_path' => '/web/docforge/home',
        'knowledge_layer_version' => '1.0',
        // Shared-hosting fallback: exec() is disabled, so jobs are processed
        // via an HTTP self-dispatch to api/process.php. This token guards it.
        'worker_token' => getenv('DOCFORGE_WORKER_TOKEN') ?: 'df-worker-2026',
    ),
    'tables' => array(
        'jobs' => 'df_jobs',
        'job_uploads' => 'df_job_uploads',
        'reports' => 'df_reports',
        'report_keyphrases' => 'df_report_keyphrases',
        'report_entities' => 'df_report_entities',
        'report_references' => 'df_report_references',
    ),
);

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}

return $config;
