<?php
/**
 * Forge Merge — combine any number of forged library reports into one
 * compilation Markdown file and stream it as a download.
 *
 * No parsing or analysis happens here: the Knowledge Layer already normalised
 * every format, so this only reads stored reports and stitches them.
 */

$config = require dirname(__DIR__) . '/app/core/bootstrap.php';

use DocForge\Core\ApiResponse;
use DocForge\Core\Csrf;
use DocForge\Core\Database;
use DocForge\Exporters\CompilationExporter;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('POST required', 405);
}

$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!Csrf::validate($token)) {
    ApiResponse::error('Invalid security token. Refresh the page and try again.', 403);
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
if (count($ids) < 2) {
    ApiResponse::error('Select at least two reports to merge.');
}

$profile = (isset($_POST['profile']) && $_POST['profile'] === 'context') ? 'context' : 'full';

$pdo = Database::connect($config);
$storageRoot = dirname(__DIR__) . '/storage/';

// Fetch the selected reports (order preserved by the user's selection order).
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT * FROM df_reports WHERE id IN ($placeholders)");
$stmt->execute($ids);
$rows = array();
foreach ($stmt->fetchAll() as $row) {
    $rows[(int) $row['id']] = $row;
}

$documents = array();
$index = 0;
$fingerprintSeed = array();
foreach ($ids as $id) {
    if (!isset($rows[$id])) {
        continue; // skip a deleted / missing id rather than fail the whole merge
    }
    $row = $rows[$id];
    $doc = array();
    if (!empty($row['json_path'])) {
        $jsonFull = $storageRoot . $row['json_path'];
        if (is_file($jsonFull)) {
            $decoded = json_decode((string) file_get_contents($jsonFull), true);
            if (is_array($decoded)) {
                $doc = $decoded;
            }
        }
    }
    $index++;
    $sha = isset($doc['fingerprint']['sha256']) ? $doc['fingerprint']['sha256'] : $row['fingerprint'];
    $fingerprintSeed[] = (string) $sha;
    $documents[] = array(
        'index' => $index,
        'title' => (string) $row['title'],
        'sha256' => (string) $sha,
        'type' => (string) $row['source_type'],
        'knowledge_score' => (int) $row['knowledge_score'],
        'source_name' => (string) $row['source_name'],
        'doc' => $doc,
    );
}

if (count($documents) < 2) {
    ApiResponse::error('Not enough of the selected reports could be loaded to merge.');
}

// Cross-document head: union key phrases (aggregate weight) + de-duplicated refs.
$keyphrases = df_union_keyphrases($pdo, $ids);
$references = df_dedup_references($pdo, $ids);

// A deterministic fingerprint for this exact set (order-independent).
$seed = $fingerprintSeed;
sort($seed);
$fingerprint = hash('sha256', implode('|', $seed));

$compilation = array(
    'fingerprint' => $fingerprint,
    'generated_at' => gmdate('c'),
    'profile' => $profile,
    'documents' => $documents,
    'keyphrases' => $keyphrases,
    'references' => $references,
);

$exporter = new CompilationExporter();
$markdown = $exporter->export($compilation);

$filename = 'docforge-compilation-' . gmdate('Ymd-His')
    . ($profile === 'context' ? '-context' : '') . '.md';

header('Content-Type: text/markdown; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($markdown));
echo $markdown;
exit;

/**
 * Union of key phrases across the selected reports, aggregated by summed score.
 *
 * @param int[] $ids
 * @return array<int,array{phrase:string,score:float}>
 */
function df_union_keyphrases(PDO $pdo, array $ids)
{
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT phrase, SUM(score) AS s FROM df_report_keyphrases
         WHERE report_id IN ($placeholders)
         GROUP BY phrase ORDER BY s DESC LIMIT 30"
    );
    $stmt->execute($ids);
    $out = array();
    foreach ($stmt->fetchAll() as $r) {
        $out[] = array('phrase' => $r['phrase'], 'score' => (float) $r['s']);
    }
    return $out;
}

/**
 * De-duplicated reference list across the selected reports. Two references are
 * the same when their DOI matches, else their URL, else their normalised text.
 *
 * @param int[] $ids
 * @return array<int,array{raw:string,doi:?string,url:?string}>
 */
function df_dedup_references(PDO $pdo, array $ids)
{
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT raw, doi, url FROM df_report_references WHERE report_id IN ($placeholders)"
    );
    $stmt->execute($ids);
    $seen = array();
    $out = array();
    foreach ($stmt->fetchAll() as $r) {
        if (!empty($r['doi'])) {
            $key = 'doi:' . strtolower(trim($r['doi']));
        } elseif (!empty($r['url'])) {
            $key = 'url:' . strtolower(trim($r['url']));
        } else {
            $key = 'raw:' . strtolower(preg_replace('/\s+/', ' ', trim((string) $r['raw'])));
        }
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = array('raw' => $r['raw'], 'doi' => $r['doi'], 'url' => $r['url']);
    }
    return $out;
}
