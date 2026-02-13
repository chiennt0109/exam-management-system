<?php
require_once __DIR__ . '/bootstrap.php';
require_once BASE_PATH . '/core/auth.php';

$error = '';
$maintenanceNotice = (string) ($_SESSION['maintenance_notice'] ?? '');
unset($_SESSION['maintenance_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login($_POST['username'] ?? '', $_POST['password'] ?? '')) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }

    if ($maintenanceNotice === '') {
        $maintenanceNotice = (string) ($_SESSION['maintenance_notice'] ?? '');
        unset($_SESSION['maintenance_notice']);
    }
    $error = 'Sai tài khoản hoặc mật khẩu';
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đăng nhập hệ thống thi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h4 class="mb-3">ĐĂNG NHẬP HỆ THỐNG THI</h4>
                    <?php if ($maintenanceNotice !== ''): ?>
                        <div class="alert alert-warning"><?= htmlspecialchars($maintenanceNotice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <div class="mb-3">
                            <input class="form-control" name="username" placeholder="Tên đăng nhập" required>
                        </div>
                        <div class="mb-3">
                            <input class="form-control" name="password" type="password" placeholder="Mật khẩu" required>
                        </div>
                        <button class="btn btn-primary" type="submit">Đăng nhập</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
