<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: text/html; charset=UTF-8');
require_once BASE_PATH . '/core/auth.php';
require_login();

$currentExamInfo = null;
$defaultExamWarning = '';
$role = function_exists('current_user_role') ? current_user_role() : strtolower(trim((string) ($_SESSION['role'] ?? $_SESSION['user']['role'] ?? '')));
if (in_array($role, ['admin', 'organizer'], true)) {
    require_once BASE_PATH . '/core/db.php';
    require_once BASE_PATH . '/modules/exams/exam_context_helper.php';
    $currentExamInfo = getCurrentExamInfo();
    if ($currentExamInfo === null) {
        $examCount = (int) $pdo->query("SELECT COUNT(*) FROM exams WHERE deleted_at IS NULL OR trim(deleted_at) = ''")->fetchColumn();
        if ($examCount > 1 && $role === 'admin') {
            $defaultExamWarning = 'Hệ thống có nhiều kỳ thi nhưng chưa chọn kỳ thi mặc định. Vui lòng vào Quản lý kỳ thi để cấu hình.';
        }
    }
}
$role = $role ?? (function_exists('current_user_role') ? current_user_role() : '');
$currentExamInfo = $currentExamInfo ?? null;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <base href="<?= BASE_URL ?>/">
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
    | Quyền: <b><?= htmlspecialchars((string) $role, ENT_QUOTES, 'UTF-8') ?></b>
    <?php if ($currentExamInfo !== null): ?>
        | Kỳ thi hiện tại: <b><?= htmlspecialchars((string) $currentExamInfo['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></b>
        <?php if (($currentExamInfo['distribution_locked'] ?? 0) === 1 || ($currentExamInfo['rooms_locked'] ?? 0) === 1): ?>
            <span class="badge bg-danger">ĐÃ KHOÁ PHÂN PHÒNG</span>
        <?php endif; ?>
        <?php if (($currentExamInfo['exam_locked'] ?? 0) === 1): ?>
            <span class="badge bg-dark">ĐÃ KHOÁ KỲ THI</span>
        <?php endif; ?>
    <?php endif; ?>
    | <a href="<?= BASE_URL ?>/logout.php" style="color:#fff">Đăng xuất</a>
</div>
<?php if (!empty($_SESSION['maintenance_notice'])): ?><div style="background:#fff3cd;color:#664d03;padding:8px 20px;border-bottom:1px solid #ffecb5;"><?= htmlspecialchars((string) $_SESSION['maintenance_notice'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($defaultExamWarning !== ''): ?><div style="background:#f8d7da;color:#842029;padding:8px 20px;border-bottom:1px solid #f5c2c7;"><?= htmlspecialchars($defaultExamWarning, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
