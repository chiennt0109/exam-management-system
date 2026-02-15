<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';

require_once BASE_PATH . '/modules/users/_common.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    set_flash('error', 'ID không hợp lệ.');
    header('Location: ' . BASE_URL . '/modules/users/index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    set_flash('error', 'Không tìm thấy user.');
    header('Location: ' . BASE_URL . '/modules/users/index.php');
    exit;
}

$csrf = users_get_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!users_verify_csrf_token(is_string($token) ? $token : null)) {
        set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: ' . BASE_URL . '/modules/users/index.php');
        exit;
    }

    if ((int) $_SESSION['user']['id'] === (int) $user['id']) {
        set_flash('error', 'Không thể xóa tài khoản đang đăng nhập.');
        header('Location: ' . BASE_URL . '/modules/users/index.php');
        exit;
    }

    if (($_POST['confirm_delete'] ?? '') === 'yes') {
        try {
            $del = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $del->execute([':id' => $id]);
            set_flash('success', 'Đã xóa user.');
        } catch (PDOException $e) {
            set_flash('error', 'Không thể xóa user.');
        }
    }

    header('Location: ' . BASE_URL . '/modules/users/index.php');
    exit;
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="students-layout" style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div class="students-main" style="flex:1;padding:20px;min-width:0;">
        <div class="card border-danger shadow-sm" style="max-width:700px;">
            <div class="card-header bg-danger text-white"><strong>Xóa người dùng</strong></div>
            <div class="card-body">
                <?php if ((int) $_SESSION['user']['id'] === (int) $user['id']): ?>
                    <div class="alert alert-warning">Bạn không thể xóa tài khoản đang đăng nhập.</div>
                    <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-secondary">Quay lại</a>
                <?php else: ?>
                    <p>Bạn có chắc muốn xóa người dùng này?</p>
                    <ul>
                        <li>ID: <strong><?= (int) $user['id'] ?></strong></li>
                        <li>Username: <strong><?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?></strong></li>
                        <li>Role: <strong><?= htmlspecialchars((string)$user['role'], ENT_QUOTES, 'UTF-8') ?></strong></li>
                    </ul>
                    <form method="post" class="d-flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="confirm_delete" value="yes">
                        <button type="submit" class="btn btn-danger">Xác nhận xóa</button>
                        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline-secondary">Hủy</a>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
