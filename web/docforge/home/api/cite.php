<?php
/**
 * Forge Cite — score candidate references for suitability against a working
 * document and stream the analysis Markdown as a download.
 *
 * Reads already-forged reports from the library; no new parsing happens here.
 */

$config = require dirname(__DIR__) . '/app/core/bootstrap.php';

use DocForge\Core\ApiResponse;
use DocForge\Core\Csrf;
use DocForge\Core\Database;
use DocForge\Analysis\CitationAnalyzer;
use DocForge\Exporters\CitationExporter;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('POST required', 405);
}

$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!Csrf::validate($token)) {
    ApiResponse::error('Invalid security token. Refresh the page and try again.', 403);
}

$workingId = isset($_POST['working_id']) ? (int) $_POST['working_id'] : 0;
if ($workingId <= 0) {
    ApiResponse::error('Choose which report is your working document.');
}

$rawIds = isset($_POST['ids']) ? $_POST['ids'] : array();
if (!is_array($rawIds)) {
    $rawIds = array($rawIds);
}
$ids = array();
foreach ($rawIds as $raw) {
    $id = (int) $raw;
    if ($id > 0 && !in_array($id, $ids, true)) {
        $ids[] = $id;
    }
}
if (!in_array($workingId, $ids, true)) {
    $ids[] = $workingId;
}
$refIds = array_values(array_filter($ids, function ($id) use ($workingId) {
    return $id !== $workingId;
}));
if (empty($refIds)) {
    ApiResponse::error('Select at least one candidate reference in addition to your document.');
}

$pdo = Database::connect($config);
$storageRoot = dirname(__DIR__) . '/storage/';

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT * FROM df_reports WHERE id IN ($placeholders)");
$stmt->execute($ids);
$rows = array();
foreach ($stmt->fetchAll() as $row) {
    $rows[(int) $row['id']] = $row;
}

$loadDoc = function ($row) use ($storageRoot) {
    if (empty($row['json_path'])) {
        return array();
    }
    $jsonFull = $storageRoot . $row['json_path'];
    if (!is_file($jsonFull)) {
        return array();
    }
    $decoded = json_decode((string) file_get_contents($jsonFull), true);
    return is_array($decoded) ? $decoded : array();
};

if (!isset($rows[$workingId])) {
    ApiResponse::error('The working document could not be loaded.');
}
$wRow = $rows[$workingId];
$wDoc = $loadDoc($wRow);
$working = array(
    'title' => (string) $wRow['title'],
    'sha256' => (string) $wRow['fingerprint'],
    'doc' => $wDoc,
);

$references = array();
foreach ($refIds as $id) {
    if (!isset($rows[$id])) {
        continue;
    }
    $row = $rows[$id];
    $references[] = array(
        'id' => (int) $id,
        'title' => (string) $row['title'],
        'sha256' => (string) $row['fingerprint'],
        'source_name' => (string) $row['source_name'],
        'doc' => $loadDoc($row),
    );
}
if (empty($references)) {
    ApiResponse::error('None of the selected references could be loaded.');
}

try {
    $analysis = (new CitationAnalyzer())->analyse($working, $references);
} catch (\Throwable $e) {
    ApiResponse::error($e->getMessage(), 422);
}

$markdown = (new CitationExporter())->export($analysis);
$filename = 'docforge-cite-' . gmdate('Ymd-His') . '.md';

header('Content-Type: text/markdown; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($markdown));
echo $markdown;
exit;
