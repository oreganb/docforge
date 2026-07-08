<?php
/**
 * One-time schema installer — delete after successful run.
 * Visit: https://docforge.ultrasoftware.ie/install.php?key=df-setup-2026
 */
$key = isset($_GET['key']) ? $_GET['key'] : '';
if ($key !== 'df-setup-2026') {
    http_response_code(403);
    exit('Forbidden');
}

$config = require __DIR__ . '/app/config/config.php';
$db = $config['db'];

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
    $pdo = new PDO($dsn, $db['user'], $db['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
} catch (Exception $e) {
    exit('DB connect failed: ' . htmlspecialchars($e->getMessage()));
}

$sql = file_get_contents(__DIR__ . '/app/config/schema.sql');
$statements = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql)));
$done = array();
foreach ($statements as $stmt) {
    if ($stmt === '' || strpos($stmt, '--') === 0) {
        continue;
    }
    $pdo->exec($stmt);
    if (preg_match('/CREATE TABLE.*?`?(\w+)`?/i', $stmt, $m)) {
        $done[] = $m[1];
    } elseif (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $stmt, $m)) {
        $done[] = $m[1];
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo "Schema installed.\n\nTables:\n";
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    echo " - $t\n";
}
echo "\nDelete install.php from the server now.\n";
