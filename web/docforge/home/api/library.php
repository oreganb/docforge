<?php

$config = require dirname(__DIR__) . '/app/core/bootstrap.php';

use DocForge\Core\ApiResponse;
use DocForge\Core\Database;

$pdo = Database::connect($config);

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : '';
$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
$perPage = (int) $config['limits']['library_per_page'];
$offset = ($page - 1) * $perPage;

$where = array('1=1');
$params = array();

if ($q !== '') {
    $where[] = 'MATCH(title, excerpt) AGAINST (? IN BOOLEAN MODE)';
    $params[] = $q . '*';
}
if ($type !== '') {
    $where[] = 'source_type = ?';
    $params[] = strtoupper($type);
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM df_reports WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetch()['c'];

$listParams = array_merge($params, array($perPage, $offset));
$stmt = $pdo->prepare(
    "SELECT id, title, excerpt, source_type, size_bytes, created_at, knowledge_score
     FROM df_reports WHERE $whereSql ORDER BY created_at DESC LIMIT ? OFFSET ?"
);
foreach ($listParams as $i => $val) {
    $stmt->bindValue($i + 1, $val, is_int($val) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
}
$stmt->execute();
$items = $stmt->fetchAll();

ApiResponse::json(array(
    'ok' => true,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'items' => $items,
));
