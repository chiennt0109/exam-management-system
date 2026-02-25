<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/core/auth.php';
require_login();
require_role(['admin']);
require_once BASE_PATH . '/core/db.php';
require_once __DIR__ . '/_common.php';

classes_ensure_schema($pdo);
$syncResult = classes_sync_from_students($pdo);

$flash = $_GET['msg'] ?? '';
if ($syncResult['created_count'] > 0) {
    $flash = 'synced_' . $syncResult['created_count'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $className = trim((string) ($_POST['class_name'] ?? ''));
        $subjectId = (int) ($_POST['specialized_subject_id'] ?? 0);
        if ($className !== '') {
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO classes (class_name, specialized_subject_id) VALUES (:class_name, :subject_id)');
            $stmt->execute([':class_name' => $className, ':subject_id' => $subjectId > 0 ? $subjectId : null]);
        }
        header('Location: ' . BASE_URL . '/modules/classes/index.php?msg=created');
        exit;
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $className = trim((string) ($_POST['class_name'] ?? ''));
        $subjectId = (int) ($_POST['specialized_subject_id'] ?? 0);
        if ($id > 0 && $className !== '') {
            $stmt = $pdo->prepare('UPDATE classes SET class_name = :class_name, specialized_subject_id = :subject_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([':class_name' => $className, ':subject_id' => $subjectId > 0 ? $subjectId : null, ':id' => $id]);
        }
        header('Location: ' . BASE_URL . '/modules/classes/index.php?msg=updated');
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM classes WHERE id = :id')->execute([':id' => $id]);
        }
        header('Location: ' . BASE_URL . '/modules/classes/index.php?msg=deleted');
        exit;
    }
}

$subjects = $pdo->query('SELECT id, ten_mon FROM subjects ORDER BY ten_mon')->fetchAll(PDO::FETCH_ASSOC);
$classRows = $pdo->query('SELECT c.id, c.class_name, c.specialized_subject_id, s.ten_mon
    FROM classes c
    LEFT JOIN subjects s ON s.id = c.specialized_subject_id
    ORDER BY c.class_name')->fetchAll(PDO::FETCH_ASSOC);

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
<?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
<div style="flex:1;padding:20px;min-width:0;">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white"><strong>🏫 Quản lý lớp</strong></div>
        <div class="card-body">
            <?php if (str_starts_with((string)$flash, 'synced_')): ?>
                <div class="alert alert-info">🔄 Tự động đồng bộ lớp từ danh sách học sinh: đã tạo mới <?= (int) str_replace('synced_', '', (string) $flash) ?> lớp.</div>
            <?php elseif ($flash === 'created'): ?>
                <div class="alert alert-success">✅ Đã thêm lớp mới.</div>
            <?php elseif ($flash === 'updated'): ?>
                <div class="alert alert-success">✏️ Đã cập nhật lớp.</div>
            <?php elseif ($flash === 'deleted'): ?>
                <div class="alert alert-success">🗑️ Đã xóa lớp.</div>
            <?php elseif ($syncResult['created_count'] === 0): ?>
                <div class="alert alert-secondary">ℹ️ Không phát hiện lớp mới cần tạo từ danh sách học sinh.</div>
            <?php endif; ?>

            <form method="post" class="row g-2 border rounded p-3 mb-3">
                <input type="hidden" name="action" value="create">
                <div class="col-md-5"><label class="form-label">Tên lớp</label><input class="form-control" name="class_name" required></div>
                <div class="col-md-5"><label class="form-label">Môn chuyên</label>
                    <select class="form-select" name="specialized_subject_id">
                        <option value="0">-- Chọn môn chuyên --</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars((string) $s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end"><button class="btn btn-success w-100" type="submit">➕ Thêm lớp</button></div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle">
                    <thead><tr><th>STT</th><th>Tên lớp</th><th>Môn chuyên</th><th>Thao tác</th></tr></thead>
                    <tbody>
                    <?php if (empty($classRows)): ?>
                        <tr><td colspan="4" class="text-center">Chưa có lớp.</td></tr>
                    <?php else: foreach ($classRows as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <form method="post" class="d-flex gap-2">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <input class="form-control form-control-sm" name="class_name" value="<?= htmlspecialchars((string) $r['class_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                            </td>
                            <td>
                                    <select class="form-select form-select-sm" name="specialized_subject_id">
                                        <option value="0">-- Chọn môn chuyên --</option>
                                        <?php foreach ($subjects as $s): ?>
                                            <option value="<?= (int) $s['id'] ?>" <?= (int) ($r['specialized_subject_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                            </td>
                            <td class="d-flex gap-2">
                                    <button class="btn btn-sm btn-primary" type="submit">💾 Sửa</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Xóa lớp này?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">🗑️ Xóa</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
