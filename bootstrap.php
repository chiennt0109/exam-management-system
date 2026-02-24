<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

if (!defined('BASE_URL')) {
    $baseUrl = '';

    if (!empty($_SERVER['BASE_URL'])) {
        $baseUrl = (string) $_SERVER['BASE_URL'];
    } else {
        $docRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        $basePath = rtrim(str_replace('\\', '/', BASE_PATH), '/');

        if ($docRoot !== '' && str_starts_with($basePath, $docRoot)) {
            $relative = trim(substr($basePath, strlen($docRoot)), '/');
            $baseUrl = $relative === '' ? '' : '/' . $relative;
        } else {
            $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
            $scriptDir = str_replace('\\', '/', dirname($scriptName));
            $baseUrl = $scriptDir === '/' || $scriptDir === '.' ? '' : rtrim($scriptDir, '/');
        }
    }

    if ($baseUrl !== '' && $baseUrl[0] !== '/') {
        $baseUrl = '/' . $baseUrl;
    }

    define('BASE_URL', rtrim($baseUrl, '/'));
}
