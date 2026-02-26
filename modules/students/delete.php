<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/core/auth.php';
require_login();
require_role(['admin']);
require_once BASE_PATH . '/core/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: ' . BASE_URL . '/modules/students/index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, sbd, hoten FROM students WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header('Location: ' . BASE_URL . '/modules/students/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirmDelete = $_POST['confirm_delete'] ?? '';
    if ($confirmDelete === 'yes') {
        $deleteStmt = $pdo->prepare('DELETE FROM students WHERE id = :id');
        $deleteStmt->execute([':id' => $id]);
    }

    header('Location: ' . BASE_URL . '/modules/students/index.php?msg=deleted_one');
    exit;
}

require_once BASE_PATH . '/layout/header.php';
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
    .window-title { background:linear-gradient(135deg,#ef4444,#dc2626); color:#fff; padding:12px 16px; }
    .window-content { background:#fef2f2; padding:18px; }
    .btn { border:none; border-radius:8px; padding:10px 12px; color:#fff; cursor:pointer; text-decoration:none; display:inline-block; }
    .btn-danger { background:#dc2626; }
    .btn-secondary { background:#64748b; }
</style>

<div class="students-layout">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>

    <div class="students-main">
        <div class="window-box" style="max-width:680px;">
            <div class="window-title"><strong>Xóa học sinh</strong></div>
            <div class="window-content">
                <p><strong>Bạn có chắc chắn muốn xóa học sinh sau?</strong></p>
                <ul>
                    <li>ID: <strong><?= (int) $student['id'] ?></strong></li>
                    <li>SBD: <strong><?= htmlspecialchars($student['sbd'], ENT_QUOTES, 'UTF-8') ?></strong></li>
                    <li>Họ tên: <strong><?= htmlspecialchars($student['hoten'], ENT_QUOTES, 'UTF-8') ?></strong></li>
                </ul>

                <form method="post" style="display:flex; gap:8px;">
                    <input type="hidden" name="confirm_delete" value="yes">
                    <button type="submit" class="btn btn-danger">🗑️ Xác nhận xóa</button>
                    <a href="<?= BASE_URL ?>/modules/students/index.php" class="btn btn-secondary">↩ Hủy</a>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once BASE_PATH . '/layout/footer.php'; ?>
