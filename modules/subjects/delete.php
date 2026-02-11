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

$stmt = $pdo->prepare('SELECT id, ma_mon, ten_mon FROM subjects WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$subject = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$subject) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['confirm_delete'] ?? '') === 'yes') {
        $del = $pdo->prepare('DELETE FROM subjects WHERE id = :id');
        $del->execute([':id' => $id]);
    }
    header('Location: index.php?msg=deleted');
    exit;
}

require_once __DIR__.'/../../layout/header.php';
?>

<style>
    .subjects-layout { display:flex; align-items:stretch; width:100%; min-height:calc(100vh - 44px); }
    .subjects-layout > .sidebar { flex:0 0 220px; width:220px; min-width:220px; }
    .subjects-main { flex:1 1 auto; min-width:0; padding:20px; }
    .card { background:#fff; border:1px solid #dbe3ec; border-radius:14px; box-shadow:0 12px 28px rgba(44,62,80,.15); max-width:700px; }
    .head { background:linear-gradient(135deg,#ef4444,#dc2626); color:#fff; padding:12px 16px; }
    .body { background:#fef2f2; padding:16px; }
    .btn { display:inline-block; text-decoration:none; border:none; border-radius:8px; color:#fff; padding:9px 12px; cursor:pointer; }
    .btn-danger { background:#dc2626; }
    .btn-secondary { background:#64748b; }
</style>

<div class="subjects-layout">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>
    <div class="subjects-main">
        <div class="card">
            <div class="head"><strong>Xóa môn học</strong></div>
            <div class="body">
                <p><strong>Bạn có chắc chắn muốn xóa môn học này?</strong></p>
                <ul>
                    <li>ID: <strong><?= (int) $subject['id'] ?></strong></li>
                    <li>Mã môn: <strong><?= htmlspecialchars($subject['ma_mon'], ENT_QUOTES, 'UTF-8') ?></strong></li>
                    <li>Tên môn: <strong><?= htmlspecialchars($subject['ten_mon'], ENT_QUOTES, 'UTF-8') ?></strong></li>
                </ul>
                <form method="post" style="display:flex; gap:8px;">
                    <input type="hidden" name="confirm_delete" value="yes">
                    <button class="btn btn-danger" type="submit">🗑️ Xác nhận xóa</button>
                    <a class="btn btn-secondary" href="index.php">↩ Hủy</a>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__.'/../../layout/footer.php'; ?>
