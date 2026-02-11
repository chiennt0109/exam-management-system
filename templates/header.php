<?php
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../core/auth.php';
require_login();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Hệ thống quản lý kỳ thi</title>
    <style>
        body { margin:0; font-family: Arial; }
        .header { background:#2c3e50; color:#fff; padding:10px 20px; }
        .container { display:flex; }
        .sidebar { width:220px; background:#ecf0f1; min-height:100vh; }
        .content { flex:1; padding:20px; }
        .sidebar ul { list-style:none; padding:0; margin:0; }
        .sidebar li { padding:10px; }
        .sidebar li a { text-decoration:none; color:#333; display:block; }
        .sidebar li a:hover { background:#ddd; }
    </style>
</head>
<body>

<div class="header">
    Xin chào <b><?= $_SESSION['user']['username'] ?></b>
    | Quyền: <b><?= $_SESSION['user']['role'] ?></b>
    | <a href="/diemthi/exam-management-system/logout.php" style="color:#fff">Đăng xuất</a>
</div>
