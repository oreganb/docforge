<?php
/**
 * Forge Redact (standalone) — upload a document, redact PII, return on-page HTML.
 * Nothing is saved to the library.
 */

$config = require dirname(__DIR__) . '/app/core/bootstrap.php';
require_once dirname(__DIR__) . '/app/lib/Parsedown.php';

use DocForge\Core\ApiResponse;
use DocForge\Core\Csrf;
use DocForge\Core\FileValidator;
use DocForge\Core\RedactProcessor;

@ini_set('memory_limit', '512M');
set_time_limit(300);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('POST required', 405);
}

$token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!Csrf::validate($token)) {
    ApiResponse::error('Invalid security token. Refresh the page and try again.', 403);
}

$mode = (isset($_POST['mode']) && $_POST['mode'] === 'mask') ? 'mask' : 'token';
$retainMap = !isset($_POST['retain_map']) || $_POST['retain_map'] === '1' || $_POST['retain_map'] === 'true';
$clientText = isset($_POST['client_text']) ? trim((string) $_POST['client_text']) : '';
$useClientText = $clientText !== '';

if (!$useClientText && (empty($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK)) {
    ApiResponse::error('Upload a document to redact.');
}

$maxBytes = $config['limits']['max_upload_bytes'];
$tmpDir = $config['storage']['uploads_tmp'];
$path = null;
$sourceName = 'document.txt';

try {
    $runConfig = $config;
    $runConfig['redact_retain_map'] = $retainMap && $mode === 'token';

    if ($useClientText) {
        if (mb_strlen($clientText) < 20) {
            ApiResponse::error('Extracted text is too short to redact.');
        }
        $sourceName = isset($_POST['source_name'])
            ? basename((string) $_POST['source_name'])
            : 'document.txt';
        $extra = array();
        if (!empty($_POST['client_ocr'])) {
            $extra['ocr'] = array(
                'pages_ocrd' => isset($_POST['ocr_pages']) ? (int) $_POST['ocr_pages'] : 0,
                'truncated' => !empty($_POST['ocr_truncated']),
                'client' => true,
            );
        }
        $result = RedactProcessor::runFromText($clientText, $sourceName, $mode, $runConfig, $extra);
    } else {
        $inspect = FileValidator::inspect(
            $_FILES['document']['tmp_name'],
            $_FILES['document']['name'],
            $maxBytes
        );
        if (!$inspect['ok']) {
            ApiResponse::error($inspect['error']);
        }
        if (in_array($inspect['type'], array('CSV', 'TSV', 'XLSX', 'JSON'), true)) {
            ApiResponse::error('Forge Redact supports documents (PDF, DOCX, Markdown, text), not datasets.');
        }

        $path = $tmpDir . '/redact_' . bin2hex(random_bytes(8)) . '_' . basename($_FILES['document']['name']);
        if (!move_uploaded_file($_FILES['document']['tmp_name'], $path)) {
            ApiResponse::error('Could not save the uploaded file.');
        }

        $meta = array(
            'name' => $_FILES['document']['name'],
            'type' => $inspect['type'],
            'mime' => $inspect['mime'],
        );
        $sourceName = $meta['name'];
        $result = RedactProcessor::run($path, $meta, $mode, $runConfig);
    }

    $markdown = $result['markdown'];
    $body = $markdown;
    if (preg_match('/\A---\R(.*?)\R---\R?/s', $body, $fm)) {
        $body = substr($body, strlen($fm[0]));
    }
    $body = preg_replace('/\A\s*#\s+[^\n]*\R+/', '', $body);

    $parsedown = new Parsedown();
    $parsedown->setSafeMode(true);
    $html = $parsedown->text($body);

    ApiResponse::json(array(
        'ok' => true,
        'title' => $result['title'],
        'source_name' => $sourceName,
        'mode' => $mode,
        'stats' => $result['stats'],
        'map_retained' => !empty($result['map_retained']),
        'redaction_map' => isset($result['redaction_map']) ? $result['redaction_map'] : array(),
        'markdown' => $markdown,
        'html' => $html,
    ));
} catch (\Throwable $e) {
    ApiResponse::error($e->getMessage(), 422);
} finally {
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}
