<?php
require_once __DIR__.'/../../core/auth.php';
require_login();
require_role(['admin']);
require_once __DIR__.'/../../core/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, sbd, hoten, ngaysinh, lop, truong FROM students WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header('Location: index.php');
    exit;
}

$errors = [];
$formData = $student;

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
        $updateStmt = $pdo->prepare('UPDATE students SET sbd = :sbd, hoten = :hoten, ngaysinh = :ngaysinh, lop = :lop, truong = :truong WHERE id = :id');
        $updateStmt->execute([
            ':sbd' => $formData['sbd'],
            ':hoten' => $formData['hoten'],
            ':ngaysinh' => $formData['ngaysinh'],
            ':lop' => $formData['lop'],
            ':truong' => $formData['truong'],
            ':id' => $id
        ]);

        header('Location: index.php?msg=updated');
        exit;
    }
}

require_once __DIR__.'/../../layout/header.php';
?>

<style>

    .students-layout {
        display: flex;
        align-items: stretch;
        width: 100%;
        min-height: calc(100vh - 44px);
    }
    .students-layout > .sidebar {
        flex: 0 0 220px;
        width: 220px;
        min-width: 220px;
    }
    .students-main {
        flex: 1 1 auto;
        min-width: 0;
        padding: 20px;
    }
    .window-box { background:#fff; border:1px solid #dbe3ec; border-radius:14px; box-shadow:0 12px 28px rgba(44,62,80,.15); overflow:hidden; }
    .window-title { background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff; padding:12px 16px; }
    .window-content { background:#f4f8fc; padding:18px; }
    .field { margin-bottom:10px; }
    .field label { display:block; font-weight:700; margin-bottom:4px; }
    .field input { width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; }
    .notice { background:#fee2e2; color:#991b1b; border-radius:8px; padding:10px; margin-bottom:12px; }
    .btn { border:none; border-radius:8px; padding:10px 12px; color:#fff; cursor:pointer; }
    .btn-primary { background:#2563eb; }
</style>

<div class="students-layout">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>

    <div class="students-main">
        <div class="window-box" style="max-width:760px;">
            <div class="window-title"><strong>Chỉnh sửa học sinh #<?= (int) $student['id'] ?></strong></div>
            <div class="window-content">
                <?php if (!empty($errors)): ?>
                    <div class="notice">
                        <ul style="margin:0;padding-left:18px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <div class="field">
                        <label>Số báo danh (SBD) *</label>
                        <input type="text" name="sbd" value="<?= htmlspecialchars($formData['sbd'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="field">
                        <label>Họ tên *</label>
                        <input type="text" name="hoten" value="<?= htmlspecialchars($formData['hoten'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="field">
                        <label>Ngày sinh</label>
                        <input type="date" name="ngaysinh" value="<?= htmlspecialchars($formData['ngaysinh'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="field">
                        <label>Lớp</label>
                        <input type="text" name="lop" value="<?= htmlspecialchars($formData['lop'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="field">
                        <label>Trường</label>
                        <input type="text" name="truong" value="<?= htmlspecialchars($formData['truong'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <button class="btn btn-primary" type="submit">💾 Cập nhật</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__.'/../../layout/footer.php'; ?>
