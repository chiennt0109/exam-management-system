<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/core/auth.php';
require_login();
require_role(['admin']);
require_once BASE_PATH . '/core/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: ' . BASE_URL . '/modules/subjects/index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, ma_mon, ten_mon, he_so, COALESCE(is_mandatory,0) AS is_mandatory FROM subjects WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$subject = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$subject) {
    header('Location: ' . BASE_URL . '/modules/subjects/index.php');
    exit;
}

$errors = [];
$formData = $subject;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['ma_mon'] = trim($_POST['ma_mon'] ?? '');
    $formData['ten_mon'] = trim($_POST['ten_mon'] ?? '');
    $formData['he_so'] = trim($_POST['he_so'] ?? '1');
    $formData['is_mandatory'] = isset($_POST['is_mandatory']) ? 1 : 0;

    if ($formData['ma_mon'] === '') $errors[] = 'Mã môn không được để trống.';
    if ($formData['ten_mon'] === '') $errors[] = 'Tên môn không được để trống.';
    if (!is_numeric($formData['he_so']) || (float) $formData['he_so'] <= 0) $errors[] = 'Hệ số phải là số > 0.';

    if (empty($errors)) {
        try {
            $update = $pdo->prepare('UPDATE subjects SET ma_mon = :ma_mon, ten_mon = :ten_mon, he_so = :he_so, is_mandatory = :is_mandatory WHERE id = :id');
            $update->execute([
                ':ma_mon' => $formData['ma_mon'],
                ':ten_mon' => $formData['ten_mon'],
                ':he_so' => (float) $formData['he_so'],
                ':is_mandatory' => (int) $formData['is_mandatory'],
                ':id' => $id
            ]);
            header('Location: ' . BASE_URL . '/modules/subjects/index.php?msg=updated');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Mã môn đã tồn tại hoặc dữ liệu không hợp lệ.';
        }
    }
}

require_once BASE_PATH . '/layout/header.php';
?>

<style>
    .subjects-layout { display:flex; align-items:stretch; width:100%; min-height:calc(100vh - 44px); }
    .subjects-layout > .sidebar { flex:0 0 220px; width:220px; min-width:220px; }
    .subjects-main { flex:1 1 auto; min-width:0; padding:20px; }
    .card { background:#fff; border:1px solid #dbe3ec; border-radius:14px; box-shadow:0 12px 28px rgba(44,62,80,.15); max-width:760px; }
    .head { background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff; padding:12px 16px; }
    .body { background:#f4f8fc; padding:16px; }
    .field { margin-bottom:10px; }
    .field label { display:block; font-weight:700; margin-bottom:4px; }
    .field input { width:100%; padding:9px; border:1px solid #cbd5e1; border-radius:8px; }
    .error { background:#fee2e2; color:#991b1b; border-radius:8px; padding:10px; margin-bottom:10px; }
    .btn { display:inline-block; text-decoration:none; border:none; border-radius:8px; color:#fff; padding:9px 12px; cursor:pointer; }
    .btn-primary { background:#2563eb; }
    .btn-secondary { background:#64748b; }
</style>

<div class="subjects-layout">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div class="subjects-main">
        <div class="card">
            <div class="head"><strong>Sửa môn học #<?= (int) $subject['id'] ?></strong></div>
            <div class="body">
                <?php if (!empty($errors)): ?>
                    <div class="error"><ul style="margin:0; padding-left:18px;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <form method="post">
                    <div class="field"><label>Mã môn *</label><input type="text" name="ma_mon" value="<?= htmlspecialchars($formData['ma_mon'], ENT_QUOTES, 'UTF-8') ?>" required></div>
                    <div class="field"><label>Tên môn *</label><input type="text" name="ten_mon" value="<?= htmlspecialchars($formData['ten_mon'], ENT_QUOTES, 'UTF-8') ?>" required></div>
                    <div class="field"><label>Hệ số *</label><input type="number" step="0.01" min="0.01" name="he_so" value="<?= htmlspecialchars((string)$formData['he_so'], ENT_QUOTES, 'UTF-8') ?>" required></div>
                    <div class="field"><label><input type="checkbox" name="is_mandatory" value="1" <?= ((int)($formData['is_mandatory'] ?? 0) === 1) ? 'checked' : '' ?>> Môn bắt buộc</label></div>
                    <button class="btn btn-primary" type="submit">💾 Cập nhật</button>
                    <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/subjects/index.php">↩ Quay lại</a>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once BASE_PATH . '/layout/footer.php'; ?>
$subjectCols = array_column($pdo->query('PRAGMA table_info(subjects)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('is_mandatory', $subjectCols, true)) {
    $pdo->exec('ALTER TABLE subjects ADD COLUMN is_mandatory INTEGER DEFAULT 0');
}
