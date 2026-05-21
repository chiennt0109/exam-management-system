<?php
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        $length = strlen($needle);
        return substr($haystack, -$length) === $needle;
    }
}

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
