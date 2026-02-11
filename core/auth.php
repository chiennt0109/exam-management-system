<?php
session_start();
require_once __DIR__ . '/db.php';

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

    // HASH PHP 5.4
    $salt = 'exam_system_salt';
    $hash = hash('sha256', $password . $salt);

    if ($hash === $user['password']) {
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
    header("Location: Diemthi/exam-management-system/login.php");
    exit;
}

/* ========= MIDDLEWARE ========= */
function require_login() {
    if (!isset($_SESSION['user'])) {
        header("Location: /login.php");
        exit;
    }
}

function require_role(array $roles) {
    require_login();
    if (!in_array($_SESSION['user']['role'], $roles)) {
        die("⛔ Bạn không có quyền truy cập chức năng này");
    }
}
