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
        * { box-sizing: border-box; }
        body { margin:0; font-family: Arial; }
        .header { background:#2c3e50; color:#fff; padding:10px 20px; }

        /* Force app shell layout so sidebar and main content always stay on one row */
        .container {
            display:flex !important;
            flex-direction: row !important;
            align-items: stretch;
            width: 100%;
            min-height: calc(100vh - 44px);
        }
        .sidebar {
            width:220px;
            min-width:220px;
            background:#ecf0f1;
            min-height: calc(100vh - 44px);
            flex: 0 0 220px;
        }
        .content {
            flex:1 1 auto;
            min-width:0;
            padding:20px;
        }

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
    | <a href="<?= htmlspecialchars(app_url('logout.php'), ENT_QUOTES, 'UTF-8') ?>" style="color:#fff">Đăng xuất</a>
</div>
