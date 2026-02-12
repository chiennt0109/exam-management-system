<?php
declare(strict_types=1);

define('BASE_PATH', __DIR__);
define('BASE_URL', '/exam-management-system');
session_start();

require_once BASE_PATH . '/templates/header.php';
?>

<div class="container">
<?php require_once BASE_PATH . '/templates/sidebar.php'; ?>

<div class="content">
    <h2>Dashboard</h2>
    <p>Chào mừng bạn đến với hệ thống quản lý kỳ thi.</p>

    <ul>
        <li>✔ Quản lý học sinh</li>
        <li>✔ Quản lý môn học</li>
        <li>✔ Tổ chức kỳ thi</li>
        <li>✔ Nhập điểm & thống kê</li>
    </ul>
</div>
</div>

<?php require_once BASE_PATH . '/templates/footer.php'; ?>
