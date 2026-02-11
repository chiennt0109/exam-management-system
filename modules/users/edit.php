<?php
declare(strict_types=1);

require_once __DIR__.'/_common.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    set_flash('error', 'ID không hợp lệ.');
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, username, role, active FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    set_flash('error', 'Không tìm thấy user.');
    header('Location: index.php');
    exit;
}

$csrf = users_get_csrf_token();
$errors = [];
$formData = [
    'role' => (string) $user['role'],
    'active' => (int) $user['active'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!users_verify_csrf_token(is_string($token) ? $token : null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }

    $role = trim((string) ($_POST['role'] ?? ''));
    $active = isset($_POST['active']) ? 1 : 0;
    $newPassword = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $formData['role'] = $role;
    $formData['active'] = $active;

    if (!in_array($role, USER_ALLOWED_ROLES, true)) {
        $errors[] = 'Role không hợp lệ.';
    }

    if ((int) $_SESSION['user']['id'] === (int) $user['id'] && $role !== 'admin') {
        $errors[] = 'Không thể tự hạ quyền tài khoản hiện tại.';
    }

    if ($newPassword !== '') {
        if (mb_strlen($newPassword) < 6) {
            $errors[] = 'Mật khẩu mới tối thiểu 6 ký tự.';
        }
        if ($newPassword !== $confirm) {
            $errors[] = 'Xác nhận mật khẩu mới không khớp.';
        }
    }

    if (empty($errors)) {
        try {
            if ($newPassword !== '') {
                $update = $pdo->prepare('UPDATE users SET role = :role, active = :active, password = :password WHERE id = :id');
                $update->execute([
                    ':role' => $role,
                    ':active' => $active,
                    ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
                    ':id' => $id,
                ]);
            } else {
                $update = $pdo->prepare('UPDATE users SET role = :role, active = :active WHERE id = :id');
                $update->execute([
                    ':role' => $role,
                    ':active' => $active,
                    ':id' => $id,
                ]);
            }

            set_flash('success', 'Đã cập nhật người dùng.');
            header('Location: index.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Không thể cập nhật người dùng.';
        }
    }
}

require_once __DIR__.'/../../layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="students-layout" style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>
    <div class="students-main" style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm" style="max-width:760px;">
            <div class="card-header bg-warning"><strong>Sửa user: <?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select class="form-select" name="role" required>
                            <?php foreach (USER_ALLOWED_ROLES as $role): ?>
                                <option value="<?= $role ?>" <?= $formData['role'] === $role ? 'selected' : '' ?>><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" value="1" id="active" name="active" <?= (int)$formData['active'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="active">Active</label>
                    </div>
                    <hr>
                    <p class="text-muted">Để trống nếu không đổi mật khẩu.</p>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password">
                    </div>

                    <button type="submit" class="btn btn-primary">Cập nhật</button>
                    <a href="index.php" class="btn btn-outline-secondary">Quay lại</a>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__.'/../../layout/footer.php'; ?>
