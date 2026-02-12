<?php
session_start();
require_once __DIR__ . '/db.php';

function app_base_path(): string {
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $markers = ['/modules/', '/core/', '/templates/', '/layout/'];
    foreach ($markers as $marker) {
        $pos = strpos($scriptName, $marker);
        if ($pos !== false) {
            $base = rtrim(substr($scriptName, 0, $pos), '/');
            return $base;
        }
    }

    $dir = str_replace('\\', '/', dirname($scriptName));
    $base = ($dir === '/' || $dir === '\\' || $dir === '.') ? '' : rtrim($dir, '/');
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
    header("Location: ../../login.php");
    exit;
}

/* ========= MIDDLEWARE ========= */
function require_login() {
    if (!isset($_SESSION['user'])) {
        header("Location: ../../login.php");
        exit;
    }
}

function require_role(array $roles) {
    require_login();
    if (!in_array($_SESSION['user']['role'], $roles)) {
        die("⛔ Bạn không có quyền truy cập chức năng này");
    }
}
