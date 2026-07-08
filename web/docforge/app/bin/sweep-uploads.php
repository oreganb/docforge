<?php
/**
 * Upload sweeper — deletes files in uploads-tmp older than 1 hour (FR-9).
 * Cron: 0 * * * * php /path/to/web/docforge/app/bin/sweep-uploads.php
 */

if (php_sapi_name() !== 'cli') {
    exit('CLI only');
}

$config = require __DIR__ . '/../core/bootstrap.php';
$dir = $config['storage']['uploads_tmp'];
$maxAge = (int) $config['limits']['upload_sweep_seconds'];
$cutoff = time() - $maxAge;

foreach (glob($dir . '/*') as $file) {
    if (is_file($file) && filemtime($file) < $cutoff) {
        unlink($file);
    }
}

echo "Sweep complete.\n";
