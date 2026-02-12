<?php
require_once __DIR__ . '/../bootstrap.php';
session_start();
require_once BASE_PATH . '/core/db.php';

function app_base_path(): string {
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $envBase = trim((string) ($_SERVER['APP_BASE_PATH'] ?? ''));
    if ($envBase !== '') {
        $base = '/' . trim($envBase, '/');
        return $base;
    }

    $candidates = [];
    foreach (['SCRIPT_NAME', 'PHP_SELF', 'REQUEST_URI'] as $key) {
        $val = str_replace('\\', '/', (string) ($_SERVER[$key] ?? ''));
        if ($val !== '') {
            $candidates[] = $val;
        }
    }

    $markers = ['exam-management-system/modules/', 'exam-management-system/core/', 'exam-management-system/templates/', 'exam-management-system/layout/'];
    $best = '';
    foreach ($candidates as $candidate) {
        $path = parse_url($candidate, PHP_URL_PATH) ?: '';
        if ($path === '') {
            continue;
        }

        $detected = '';
        foreach ($markers as $marker) {
            $pos = strpos($path, $marker);
            if ($pos !== false) {
                $detected = rtrim(substr($path, 0, $pos), '/');
                break;
            }
        }

        if ($detected === '') {
            $dir = str_replace('\\', '/', dirname($path));
            $detected = ($dir === '/' || $dir === '\\' || $dir === '.') ? '' : rtrim($dir, '/');
        }

        if (strlen($detected) > strlen($best)) {
            $best = $detected;
        }
    }

    $base = $best;
    return $base;
}

function app_url(string $path = ''): string {
    $base = app_base_path();
    $cleanPath = ltrim($path, '/');
    if ($cleanPath === '') {
        return $base === '' ? '/' : $base . '/';
    }

    return ($base === '' ? '' : $base) . '/' . $cleanPath;
}

/* ========= LOGIN ========= */
function login($username, $password) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT * FROM users
        WHERE username = ? AND active = 1
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return false;

    $isValid = false;

    if (is_string($user['password']) && password_verify($password, $user['password'])) {
        $isValid = true;
    } else {
        // Legacy SHA-256 compatibility for older accounts
        $salt = 'exam_system_salt';
        $hash = hash('sha256', $password . $salt);
        $isValid = ($hash === $user['password']);
    }

    if ($isValid) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role']
        ];
        return true;
    }
    return false;
}

/* ========= LOGOUT ========= */
function logout() {
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

/* ========= MIDDLEWARE ========= */
function require_login() {
    if (!isset($_SESSION['user'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function require_role(array $roles) {
    require_login();
    if (!in_array($_SESSION['user']['role'], $roles, true)) {
        die("⛔ Bạn không có quyền truy cập chức năng này");
    }
}
