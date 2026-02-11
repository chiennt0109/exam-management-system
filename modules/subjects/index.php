<?php
require_once __DIR__.'/../../core/auth.php';
require_login();
require_role(['admin']);
require_once __DIR__.'/../../core/db.php';

$flash = $_GET['msg'] ?? '';
$stmt = $pdo->query('SELECT id, ma_mon, ten_mon, he_so FROM subjects ORDER BY id DESC');
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__.'/../../layout/header.php';
?>

<style>
    .subjects-layout { display:flex; align-items:stretch; width:100%; min-height:calc(100vh - 44px); }
    .subjects-layout > .sidebar { flex:0 0 220px; width:220px; min-width:220px; }
    .subjects-main { flex:1 1 auto; min-width:0; padding:20px; }
    .panel { background:#fff; border:1px solid #dbe3ec; border-radius:14px; box-shadow:0 12px 28px rgba(44,62,80,.15); overflow:hidden; }
    .panel-head { background:linear-gradient(135deg,#3b82f6,#2563eb); color:#fff; padding:12px 16px; display:flex; justify-content:space-between; align-items:center; }
    .panel-body { background:#f4f8fc; padding:16px; }
    .btn { display:inline-block; text-decoration:none; padding:8px 12px; border-radius:8px; color:#fff; border:none; cursor:pointer; }
    .btn-success { background:#16a34a; }
    .btn-warning { background:#f59e0b; }
    .btn-danger { background:#ef4444; }
    .notice { padding:10px; border-radius:8px; margin-bottom:10px; background:#dcfce7; color:#166534; }
    table { width:100%; border-collapse:collapse; background:#fff; }
    th, td { border:1px solid #e5e7eb; padding:8px; }
    th { background:#eff6ff; color:#1d4ed8; }
</style>

<div class="subjects-layout">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>

    <div class="subjects-main">
        <div class="panel">
            <div class="panel-head">
                <strong>Quản lý môn học</strong>
                <a class="btn btn-success" href="create.php">+ Thêm môn học</a>
            </div>
            <div class="panel-body">
                <?php if (in_array($flash, ['created', 'updated', 'deleted'], true)): ?>
                    <div class="notice">
                        <?= $flash === 'created' ? 'Đã thêm môn học.' : ($flash === 'updated' ? 'Đã cập nhật môn học.' : 'Đã xóa môn học.') ?>
                    </div>
                <?php endif; ?>

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Mã môn</th>
                            <th>Tên môn</th>
                            <th>Hệ số</th>
                            <th style="width:130px;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($subjects)): ?>
                            <tr><td colspan="5" style="text-align:center;">Chưa có môn học.</td></tr>
                        <?php else: ?>
                            <?php foreach ($subjects as $subject): ?>
                                <tr>
                                    <td><?= (int) $subject['id'] ?></td>
                                    <td><?= htmlspecialchars($subject['ma_mon'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($subject['ten_mon'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) $subject['he_so'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <a class="btn btn-warning" href="edit.php?id=<?= (int) $subject['id'] ?>" title="Sửa">✏️</a>
                                        <a class="btn btn-danger" href="delete.php?id=<?= (int) $subject['id'] ?>" title="Xóa">🗑️</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__.'/../../layout/footer.php'; ?>
