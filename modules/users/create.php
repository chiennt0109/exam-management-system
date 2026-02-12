<?php
declare(strict_types=1);

require_once __DIR__.'/_common.php';

$csrf = users_get_csrf_token();
$errors = [];
$formData = [
    'username' => '',
    'role' => 'organizer',
    'active' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!users_verify_csrf_token(is_string($token) ? $token : null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $role = trim((string) ($_POST['role'] ?? ''));
    $active = isset($_POST['active']) ? 1 : 0;

    $formData['username'] = $username;
    $formData['role'] = $role;
    $formData['active'] = $active;

    if ($username === '' || mb_strlen($username) < 4) {
        $errors[] = 'Username bắt buộc và tối thiểu 4 ký tự.';
    }
    if (mb_strlen($password) < 6) {
        $errors[] = 'Password tối thiểu 6 ký tự.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Xác nhận mật khẩu không khớp.';
    }
    if (!in_array($role, USER_ALLOWED_ROLES, true)) {
        $errors[] = 'Role không hợp lệ.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare('INSERT INTO users (username, password, role, active) VALUES (:username, :password, :role, :active)');
            $stmt->execute([
                ':username' => $username,
                ':password' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => $role,
                ':active' => $active,
            ]);

            set_flash('success', 'Đã tạo người dùng mới.');
            header('Location: index.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Username đã tồn tại hoặc dữ liệu không hợp lệ.';
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
            <div class="card-header bg-primary text-white"><strong>Tạo người dùng</strong></div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" name="username" required value="<?= htmlspecialchars($formData['username'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" name="confirm_password" required>
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

                    <button type="submit" class="btn btn-primary">Lưu</button>
                    <a href="index.php" class="btn btn-outline-secondary">Quay lại</a>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__.'/../../layout/footer.php'; ?>
