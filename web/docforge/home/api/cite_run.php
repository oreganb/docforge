<?php
/**
 * Forge Cite (standalone) — upload a working document + candidate reference(s),
 * run lexical suitability analysis, return rendered HTML. Nothing is saved to
 * the library; uploaded files are deleted after processing.
 */

$config = require dirname(__DIR__) . '/app/core/bootstrap.php';
require_once dirname(__DIR__) . '/app/lib/Parsedown.php';

use DocForge\Core\ApiResponse;
use DocForge\Core\Csrf;
use DocForge\Core\CiteProcessor;
use DocForge\Core\FileValidator;

@ini_set('memory_limit', '512M');
set_time_limit(120);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('POST required', 405);
}

$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!Csrf::validate($token)) {
    ApiResponse::error('Invalid security token. Refresh the page and try again.', 403);
}

if (empty($_FILES['working']) || $_FILES['working']['error'] !== UPLOAD_ERR_OK) {
    ApiResponse::error('Upload your report, proposal, or working document.');
}

$refFiles = isset($_FILES['references']) ? $_FILES['references'] : null;
if ($refFiles === null || empty($refFiles['name'])
    || (is_array($refFiles['name']) && $refFiles['name'][0] === '')
    || (!is_array($refFiles['name']) && $refFiles['name'] === '')) {
    ApiResponse::error('Upload at least one candidate reference to check.');
}

$maxBytes = $config['limits']['max_upload_bytes'];
$tmpDir = $config['storage']['uploads_tmp'];
$paths = array();

try {
    $workingInspect = FileValidator::inspect(
        $_FILES['working']['tmp_name'],
        $_FILES['working']['name'],
        $maxBytes
    );
    if (!$workingInspect['ok']) {
        ApiResponse::error($workingInspect['error']);
    }
    if (in_array($workingInspect['type'], array('CSV', 'TSV', 'XLSX', 'JSON'), true)) {
        ApiResponse::error('Forge Cite needs a document (PDF, DOCX, Markdown, or text), not a dataset.');
    }

    $workingPath = $tmpDir . '/cite_w_' . bin2hex(random_bytes(8)) . '_' . basename($_FILES['working']['name']);
    if (!move_uploaded_file($_FILES['working']['tmp_name'], $workingPath)) {
        ApiResponse::error('Could not save the working document.');
    }
    $paths[] = $workingPath;
    $workingMeta = array(
        'name' => $_FILES['working']['name'],
        'type' => $workingInspect['type'],
        'mime' => $workingInspect['mime'],
    );

    $references = array();
    $names = $refFiles['name'];
    $tmps = $refFiles['tmp_name'];
    $errs = $refFiles['error'];
    if (!is_array($names)) {
        $names = array($names);
        $tmps = array($tmps);
        $errs = array($errs);
    }
    foreach ($names as $i => $name) {
        if ($name === '' || $errs[$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        $inspect = FileValidator::inspect($tmps[$i], $name, $maxBytes);
        if (!$inspect['ok']) {
            throw new \RuntimeException($name . ': ' . $inspect['error']);
        }
        if (in_array($inspect['type'], array('CSV', 'TSV', 'XLSX', 'JSON'), true)) {
            throw new \RuntimeException($name . ' is a dataset — upload a document instead.');
        }
        $dest = $tmpDir . '/cite_r_' . bin2hex(random_bytes(8)) . '_' . basename($name);
        if (!move_uploaded_file($tmps[$i], $dest)) {
            throw new \RuntimeException('Could not save reference: ' . $name);
        }
        $paths[] = $dest;
        $references[] = array(
            'path' => $dest,
            'name' => $name,
            'type' => $inspect['type'],
            'mime' => $inspect['mime'],
        );
    }

    if (empty($references)) {
        ApiResponse::error('No valid reference files were uploaded.');
    }

    $result = CiteProcessor::run($workingPath, $references, $workingMeta);

    $markdown = $result['markdown'];
    $body = $markdown;
    if (preg_match('/\A---\R(.*?)\R---\R?/s', $body, $fm)) {
        $body = substr($body, strlen($fm[0]));
    }
    $body = preg_replace('/\A\s*#\s+[^\n]*\R+/', '', $body);

    $parsedown = new Parsedown();
    $parsedown->setSafeMode(true);
    $html = $parsedown->text($body);

    $refNames = array();
    foreach ($references as $r) {
        $refNames[] = $r['name'];
    }

    ApiResponse::json(array(
        'ok' => true,
        'working_title' => $result['working_title'],
        'working_name' => $workingMeta['name'],
        'reference_names' => $refNames,
        'reference_count' => count($refNames),
        'markdown' => $markdown,
        'html' => $html,
    ));
} catch (\Throwable $e) {
    ApiResponse::error($e->getMessage(), 422);
} finally {
    foreach ($paths as $p) {
        if (is_file($p)) {
            @unlink($p);
        }
    }
}
