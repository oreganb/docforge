<?php
/**
 * Application bootstrap — loads autoloader, config, session.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$appRoot = dirname(__DIR__);
require_once $appRoot . '/vendor/autoload.php';

spl_autoload_register(function ($class) use ($appRoot) {
    $prefix = 'DocForge\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $parts = explode('\\', $relative);
    $className = array_pop($parts);
    $dirMap = array(
        'KnowledgeLayer' => 'knowledge-layer',
    );
    $dirParts = array();
    foreach ($parts as $part) {
        $dirParts[] = isset($dirMap[$part]) ? $dirMap[$part] : strtolower($part);
    }
    $file = $appRoot . '/' . implode('/', $dirParts) . '/' . $className . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$config = require $appRoot . '/config/config.php';

if (!function_exists('df_dispatch_worker')) {
    /**
     * Fire a non-blocking HTTP request to api/process.php so a queued job is
     * processed in the background. Used because exec() is disabled on shared
     * hosting. Best-effort: failures are swallowed (the status poll retries).
     */
    function df_dispatch_worker($jobId, $token)
    {
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        // Always dispatch over plain HTTP to avoid self-signed / mismatched
        // TLS issues; the worker endpoint does not require HTTPS.
        $dir = isset($_SERVER['SCRIPT_NAME']) ? rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') : '/api';
        $path = $dir . '/process.php';
        $query = http_build_query(array('job_id' => $jobId, 'token' => $token));
        $url = 'http://' . $host . $path . '?' . $query;

        // Preferred: curl with a short timeout. process.php detaches
        // (fastcgi/litespeed finish_request) or keeps running via
        // ignore_user_abort() even if curl gives up waiting.
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_CONNECTTIMEOUT => 2,
            ));
            curl_exec($ch);
            curl_close($ch);
            return true;
        }

        // Fallback: raw socket. Read one line so the server fully receives
        // the request before we drop the connection.
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, 80, $errno, $errstr, 3);
        if (!$fp) {
            return false;
        }
        $req = "GET {$path}?{$query} HTTP/1.1\r\n";
        $req .= "Host: {$host}\r\n";
        $req .= "Connection: Close\r\n";
        $req .= "\r\n";
        fwrite($fp, $req);
        stream_set_timeout($fp, 2);
        fgets($fp, 128);
        fclose($fp);
        return true;
    }
}

foreach (array('uploads_tmp', 'reports', 'logs') as $dir) {
    $path = $config['storage'][$dir];
    if (!is_dir($path)) {
        mkdir($path, 0750, true);
    }
}

return $config;
