<?php
require_once __DIR__ . '/../bootstrap.php';
session_start();
require_once BASE_PATH . '/core/db.php';

function isWriteRequest(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_maintenance_mode(): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => 'maintenance_mode']);
        return ((string) $stmt->fetchColumn()) === '1';
    } catch (Throwable $e) {
        return false;
    }
}

/* ========= LOGIN ========= */
function login($username, $password) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return false;

    $isValid = false;
    if (is_string($user['password']) && password_verify($password, $user['password'])) {
        $isValid = true;
    } else {
        $salt = 'exam_system_salt';
        $hash = hash('sha256', $password . $salt);
        $isValid = ($hash === $user['password']);
    }

    if (!$isValid) {
        return false;
    }

    $role = (string) ($user['role'] ?? '');
    $_SESSION['user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'role' => $role,
    ];
    // Keep lightweight mirror for legacy code/sidebar compatibility
    $_SESSION['role'] = $role;

    // Only show maintenance notice to non-admins, but do not block login/dashboard read access.
    if (is_maintenance_mode() && $role !== 'admin') {
        $_SESSION['maintenance_notice'] = 'Kỳ thi đang được mở bởi quản trị viên. Vui lòng chờ.';
    }

    return true;
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
    $role = (string) ($_SESSION['user']['role'] ?? $_SESSION['role'] ?? '');
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        echo '⛔ Bạn không có quyền truy cập chức năng này';
        exit;
    }
}
