<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/core/db.php';

/* ===== CẤU HÌNH ===== */
$username = 'admin';
$password = '123456';
$role = 'admin';

/* ===== HASH KIỂU PHP 5.4 ===== */
$salt = 'exam_system_salt';   // cố định, dùng toàn hệ thống
$hash = hash('sha256', $password . $salt);

/* ===== INSERT ===== */
$sql = "INSERT OR IGNORE INTO users(username, password, role, active)
        VALUES (:u, :p, :r, 1)";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':u' => $username,
    ':p' => $hash,
    ':r' => $role
]);

echo "✅ Đã tạo tài khoản admin / 123456";
