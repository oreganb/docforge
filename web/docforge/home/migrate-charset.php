<?php
/**
 * One-off migration — convert df_ tables to utf8mb4 so valid Unicode
 * (e.g. ✓, emoji) stores without SQLSTATE[HY000] 1366. Delete after running.
 * Visit: https://docforge.ultrasoftware.ie/migrate-charset.php?key=df-setup-2026
 */
$key = isset($_GET['key']) ? $_GET['key'] : '';
if ($key !== 'df-setup-2026') {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/app/config/config.php';
$db = $config['db'];

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
    $pdo = new PDO($dsn, $db['user'], $db['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
} catch (Exception $e) {
    exit('DB connect failed: ' . $e->getMessage());
}

$tables = array(
    'df_jobs', 'df_job_uploads', 'df_reports',
    'df_report_keyphrases', 'df_report_entities', 'df_report_references',
);

echo "Converting tables to utf8mb4 / utf8mb4_unicode_ci\n";
echo str_repeat('-', 52) . "\n";
foreach ($tables as $t) {
    try {
        $before = $pdo->query("SHOW TABLE STATUS LIKE '" . $t . "'")->fetch(PDO::FETCH_ASSOC);
        $beforeColl = $before ? $before['Collation'] : '(missing)';
        $pdo->exec('ALTER TABLE `' . $t . '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $after = $pdo->query("SHOW TABLE STATUS LIKE '" . $t . "'")->fetch(PDO::FETCH_ASSOC);
        $afterColl = $after ? $after['Collation'] : '(missing)';
        echo sprintf("OK   %-22s %s -> %s\n", $t, $beforeColl, $afterColl);
    } catch (Exception $e) {
        echo sprintf("FAIL %-22s %s\n", $t, $e->getMessage());
    }
}

echo "\nDone. Delete migrate-charset.php from the server now.\n";
