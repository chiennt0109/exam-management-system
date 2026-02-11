<?php
require_once __DIR__.'/../../core/auth.php';
require_login();
require_role(['admin']);
require_once __DIR__.'/../../core/db.php';

$errors = [];
$formData = [
    'sbd' => '',
    'hoten' => '',
    'ngaysinh' => '',
    'lop' => '',
    'truong' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['sbd'] = trim($_POST['sbd'] ?? '');
    $formData['hoten'] = trim($_POST['hoten'] ?? '');
    $formData['ngaysinh'] = trim($_POST['ngaysinh'] ?? '');
    $formData['lop'] = trim($_POST['lop'] ?? '');
    $formData['truong'] = trim($_POST['truong'] ?? '');

    if ($formData['sbd'] === '') {
        $errors[] = 'SBD không được để trống.';
    }

    if ($formData['hoten'] === '') {
        $errors[] = 'Họ tên không được để trống.';
    }

    if ($formData['ngaysinh'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $formData['ngaysinh'])) {
        $errors[] = 'Ngày sinh không hợp lệ (định dạng YYYY-MM-DD).';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare('INSERT INTO students (sbd, hoten, ngaysinh, lop, truong) VALUES (:sbd, :hoten, :ngaysinh, :lop, :truong)');
        $stmt->execute([
            ':sbd' => $formData['sbd'],
            ':hoten' => $formData['hoten'],
            ':ngaysinh' => $formData['ngaysinh'],
            ':lop' => $formData['lop'],
            ':truong' => $formData['truong']
        ]);

        header('Location: index.php');
        exit;
    }
}

require_once __DIR__.'/../../layout/header.php';
?>

<div class="container">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>

    <div class="content">
        <h2>Thêm học sinh</h2>
        <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 0 10px rgba(0,0,0,0.08);max-width:720px;">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" style="background:#f8d7da;color:#842029;padding:10px;border-radius:4px;margin-bottom:12px;">
                    <ul style="margin:0;padding-left:18px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" class="row g-3">
                <div class="mb-3" style="margin-bottom:10px;">
                    <label class="form-label"><strong>Số báo danh (SBD) *</strong></label>
                    <input type="text" name="sbd" class="form-control" style="width:100%;padding:8px;" value="<?= htmlspecialchars($formData['sbd'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="mb-3" style="margin-bottom:10px;">
                    <label class="form-label"><strong>Họ tên *</strong></label>
                    <input type="text" name="hoten" class="form-control" style="width:100%;padding:8px;" value="<?= htmlspecialchars($formData['hoten'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="mb-3" style="margin-bottom:10px;">
                    <label class="form-label"><strong>Ngày sinh</strong></label>
                    <input type="date" name="ngaysinh" class="form-control" style="width:100%;padding:8px;" value="<?= htmlspecialchars($formData['ngaysinh'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="mb-3" style="margin-bottom:10px;">
                    <label class="form-label"><strong>Lớp</strong></label>
                    <input type="text" name="lop" class="form-control" style="width:100%;padding:8px;" value="<?= htmlspecialchars($formData['lop'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="mb-3" style="margin-bottom:14px;">
                    <label class="form-label"><strong>Trường</strong></label>
                    <input type="text" name="truong" class="form-control" style="width:100%;padding:8px;" value="<?= htmlspecialchars($formData['truong'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div style="display:flex;gap:10px;">
                    <button type="submit" class="btn btn-primary" style="padding:8px 14px;background:#007bff;color:#fff;border:none;border-radius:4px;">Lưu học sinh</button>
                    <a href="index.php" class="btn btn-secondary" style="padding:8px 14px;background:#6c757d;color:#fff;text-decoration:none;border-radius:4px;">Quay lại</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__.'/../../layout/footer.php'; ?>
