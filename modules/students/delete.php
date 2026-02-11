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

$stmt = $pdo->prepare('SELECT id, sbd, hoten FROM students WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirmDelete = $_POST['confirm_delete'] ?? '';
    if ($confirmDelete === 'yes') {
        $deleteStmt = $pdo->prepare('DELETE FROM students WHERE id = :id');
        $deleteStmt->execute([':id' => $id]);
    }

    header('Location: index.php');
    exit;
}

require_once __DIR__.'/../../layout/header.php';
?>

<div class="container">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>

    <div class="content">
        <h2>Xóa học sinh</h2>
        <div class="alert alert-warning" style="background:#fff3cd;color:#664d03;padding:16px;border-radius:8px;max-width:680px;">
            <p style="margin-top:0;"><strong>Bạn có chắc chắn muốn xóa học sinh này?</strong></p>
            <ul>
                <li>ID: <strong><?= (int) $student['id'] ?></strong></li>
                <li>SBD: <strong><?= htmlspecialchars($student['sbd'], ENT_QUOTES, 'UTF-8') ?></strong></li>
                <li>Họ tên: <strong><?= htmlspecialchars($student['hoten'], ENT_QUOTES, 'UTF-8') ?></strong></li>
            </ul>

            <form method="post" style="display:flex;gap:10px;">
                <input type="hidden" name="confirm_delete" value="yes">
                <button type="submit" class="btn btn-danger" style="padding:8px 14px;background:#dc3545;color:#fff;border:none;border-radius:4px;">Xác nhận xóa</button>
                <a href="index.php" class="btn btn-secondary" style="padding:8px 14px;background:#6c757d;color:#fff;text-decoration:none;border-radius:4px;">Hủy</a>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__.'/../../layout/footer.php'; ?>
