<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';

require_once BASE_PATH . '/modules/exams/_common.php';
require_role(['admin', 'organizer']);

$csrf = exams_get_csrf_token();
$errors = [];
$currentExamId = getCurrentExamId();

$columns = $pdo->query('PRAGMA table_info(exams)')->fetchAll(PDO::FETCH_ASSOC);
$hasTrangThai = false;
$hasDeletedAt = false;
$hasScorePublished = false;
foreach ($columns as $col) {
    if (($col['name'] ?? '') === 'trang_thai') {
        $hasTrangThai = true;
    }
    if (($col['name'] ?? '') === 'deleted_at') {
        $hasDeletedAt = true;
    }
    if (($col['name'] ?? '') === 'is_score_published') {
        $hasScorePublished = true;
    }
}
if (!$hasDeletedAt) {
    $pdo->exec('ALTER TABLE exams ADD COLUMN deleted_at TEXT');
    $hasDeletedAt = true;
}
if (!$hasScorePublished) {
    $pdo->exec('ALTER TABLE exams ADD COLUMN is_score_published INTEGER DEFAULT 0');
    $hasScorePublished = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }

    $action = (string) ($_POST['action'] ?? 'create');

    if (empty($errors) && $action === 'set_default_exam') {
        $selectedExamId = max(0, (int) ($_POST['exam_id'] ?? 0));
        try {
            $pdo->beginTransaction();
                $pdo->exec('UPDATE exams SET is_default = 0');
                $pdo->prepare('UPDATE exams SET is_default = 1 WHERE id = :id')->execute([':id' => $selectedExamId]);
                $pdo->commit();
                exams_set_flash('success', 'Đã đặt kỳ thi mặc định cho toàn hệ thống.');
                header('Location: ' . BASE_URL . '/modules/exams/index.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = 'Không thể đặt kỳ thi mặc định.';
        }
    }




    if (empty($errors) && $action === 'toggle_score_publish') {
        if (current_user_role() !== 'admin') {
            $errors[] = 'Chỉ admin mới có quyền công bố điểm.';
        }
        $examId = max(0, (int) ($_POST['exam_id'] ?? 0));
        $publish = (int) ($_POST['publish'] ?? 0) === 1 ? 1 : 0;
        if (empty($errors) && $examId > 0) {
            $stmt = $pdo->prepare('UPDATE exams SET is_score_published = :published WHERE id = :id');
            $stmt->execute([':published' => $publish, ':id' => $examId]);
            exams_set_flash('success', $publish === 1 ? 'Đã công bố điểm cho kỳ thi.' : 'Đã ẩn kết quả kỳ thi.');
            header('Location: ' . BASE_URL . '/modules/exams/index.php');
            exit;
        }
    }

    if (empty($errors) && $action === 'create') {
        $tenKyThi = trim((string) ($_POST['ten_ky_thi'] ?? ''));
        $nam = (int) ($_POST['nam'] ?? 0);
        $ngayThi = trim((string) ($_POST['ngay_thi'] ?? ''));
        $trangThai = trim((string) ($_POST['trang_thai'] ?? 'draft'));

        if ($tenKyThi === '') {
            $errors[] = 'Tên kỳ thi không được để trống.';
        }
        if ($nam < 2000 || $nam > 2100) {
            $errors[] = 'Năm không hợp lệ.';
        }
        if ($ngayThi !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ngayThi)) {
            $errors[] = 'Ngày thi phải đúng định dạng YYYY-MM-DD.';
        }

        if (empty($errors)) {
            try {
                if ($hasTrangThai) {
                    $stmt = $pdo->prepare('INSERT INTO exams (ten_ky_thi, nam, ngay_thi, trang_thai, deleted_at) VALUES (:ten_ky_thi, :nam, :ngay_thi, :trang_thai, NULL)');
                    $stmt->execute([
                        ':ten_ky_thi' => $tenKyThi,
                        ':nam' => $nam,
                        ':ngay_thi' => $ngayThi,
                        ':trang_thai' => $trangThai,
                    ]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO exams (ten_ky_thi, nam, ngay_thi, deleted_at) VALUES (:ten_ky_thi, :nam, :ngay_thi, NULL)');
                    $stmt->execute([
                        ':ten_ky_thi' => $tenKyThi,
                        ':nam' => $nam,
                        ':ngay_thi' => $ngayThi,
                    ]);
                }

                exams_set_flash('success', 'Đã tạo kỳ thi mới.');
                header('Location: ' . BASE_URL . '/modules/exams/index.php');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Không thể tạo kỳ thi.';
            }
        }
    }

    if (empty($errors) && in_array($action, ['soft_delete', 'hard_delete', 'restore'], true)) {
        $examId = max(0, (int) ($_POST['exam_id'] ?? 0));
        if ($examId <= 0) {
            $errors[] = 'Thiếu kỳ thi cần thao tác.';
        } else {
            try {
                $pdo->beginTransaction();
                if ($action === 'soft_delete') {
                    if ($hasTrangThai) {
                        $stmt = $pdo->prepare('UPDATE exams SET deleted_at = :deleted_at, trang_thai = "deleted" WHERE id = :id');
                        $stmt->execute([':deleted_at' => date('c'), ':id' => $examId]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE exams SET deleted_at = :deleted_at WHERE id = :id');
                        $stmt->execute([':deleted_at' => date('c'), ':id' => $examId]);
                    }
                    exams_set_flash('success', 'Đã xóa tạm kỳ thi.');
                } elseif ($action === 'restore') {
                    if ($hasTrangThai) {
                        $stmt = $pdo->prepare('UPDATE exams SET deleted_at = NULL, trang_thai = "draft" WHERE id = :id');
                        $stmt->execute([':id' => $examId]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE exams SET deleted_at = NULL WHERE id = :id');
                        $stmt->execute([':id' => $examId]);
                    }
                    exams_set_flash('success', 'Đã khôi phục kỳ thi.');
                } else {
                    $pdo->prepare('DELETE FROM exam_subject_classes WHERE exam_id = :exam_id')->execute([':exam_id' => $examId]);
                    $pdo->prepare('DELETE FROM exam_subject_config WHERE exam_id = :exam_id')->execute([':exam_id' => $examId]);
                    $pdo->prepare('DELETE FROM rooms WHERE exam_id = :exam_id')->execute([':exam_id' => $examId]);
                    $pdo->prepare('DELETE FROM exam_students WHERE exam_id = :exam_id')->execute([':exam_id' => $examId]);
                    $pdo->prepare('DELETE FROM exams WHERE id = :id')->execute([':id' => $examId]);
                    exams_set_flash('success', 'Đã xóa thật kỳ thi và toàn bộ dữ liệu liên quan.');
                }
                $pdo->commit();
                header('Location: ' . BASE_URL . '/modules/exams/index.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Không thể thực hiện thao tác xóa kỳ thi.';
            }
        }
    }
}

$selectTrangThai = $hasTrangThai ? ', trang_thai' : '';
$exams = $pdo->query('SELECT id, ten_ky_thi, nam, ngay_thi, deleted_at, is_default' . $selectTrangThai . ' FROM exams ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$currentExamId = getCurrentExamId();

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-primary text-white"><strong>Bước 1: Tạo kỳ thi mới</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <form method="post" class="row g-2 mb-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="set_default_exam">
                    <div class="col-md-8">
                        <label class="form-label">Chọn kỳ thi mặc định</label>
                        <select class="form-select" name="exam_id" required>
                            <option value="">-- Chọn kỳ thi --</option>
                            <?php foreach ($exams as $exam): ?>
                                <?php if (!empty($exam['deleted_at'])) { continue; } ?>
                                <option value="<?= (int) $exam['id'] ?>" <?= $currentExamId === (int) $exam['id'] ? 'selected' : '' ?>>#<?= (int) $exam['id'] ?> - <?= htmlspecialchars((string) $exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 align-self-end"><button class="btn btn-primary" type="submit">Lưu kỳ thi mặc định</button></div>
                </form>

                <form method="post" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="col-md-4"><label class="form-label">Tên kỳ thi *</label><input class="form-control" name="ten_ky_thi" required></div>
                    <div class="col-md-2"><label class="form-label">Năm *</label><input class="form-control" type="number" name="nam" min="2000" max="2100" value="<?= date('Y') ?>" required></div>
                    <div class="col-md-3"><label class="form-label">Ngày thi</label><input class="form-control" type="date" name="ngay_thi"></div>
                    <?php if ($hasTrangThai): ?><div class="col-md-3"><label class="form-label">Trạng thái</label><select class="form-select" name="trang_thai"><option value="draft">draft</option><option value="open">open</option><option value="closed">closed</option></select></div><?php endif; ?>
                    <div class="col-12"><button class="btn btn-success" type="submit">Tạo kỳ thi</button></div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-light"><strong>Danh sách kỳ thi & điều hướng workflow</strong></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle">
                        <thead><tr><th>ID</th><th>Tên kỳ thi</th><th>Năm</th><th>Ngày thi</th><th>Trạng thái</th><th>Mặc định</th><th>Workflow</th><th>Công bố điểm</th><th>Xóa</th></tr></thead>
                        <tbody>
                        <?php if (empty($exams)): ?>
                            <tr><td colspan="9" class="text-center">Chưa có kỳ thi.</td></tr>
                        <?php else: foreach ($exams as $exam): ?>
                            <?php $isDeleted = !empty($exam['deleted_at']); ?>
                            <tr class="<?= $isDeleted ? 'table-warning' : '' ?>">
                                <td><?= (int) $exam['id'] ?></td>
                                <td><?= htmlspecialchars((string) $exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $exam['nam'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $exam['ngay_thi'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?= $hasTrangThai ? htmlspecialchars((string) ($exam['trang_thai'] ?? ''), ENT_QUOTES, 'UTF-8') : '<em>N/A</em>' ?>
                                    <?php if ($isDeleted): ?><span class="badge bg-warning text-dark ms-1">đã xóa tạm</span><?php endif; ?>
                                </td>
                                <td class="d-flex flex-wrap gap-1">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="set_default_exam">
                                        <input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>">
                                        <button class="btn btn-sm <?= $currentExamId === (int) $exam['id'] ? 'btn-success' : 'btn-outline-success' ?>" type="submit">Đặt mặc định</button>
                                    </form>
                                    <?php if ($currentExamId === (int) $exam['id']): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="assign_students.php">B2</a>
                                        <a class="btn btn-sm btn-outline-primary" href="generate_sbd.php">B3</a>
                                        <a class="btn btn-sm btn-outline-primary" href="configure_subjects.php">B4</a>
                                        <a class="btn btn-sm btn-outline-primary" href="distribute_rooms.php">B5</a>
                                        <a class="btn btn-sm btn-outline-primary" href="print_rooms.php">B6</a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (current_user_role() === 'admin'): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="toggle_score_publish">
                                            <input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>">
                                            <input type="hidden" name="publish" value="<?= ((int) ($exam['is_score_published'] ?? 0) === 1) ? 0 : 1 ?>">
                                            <button class="btn btn-sm <?= ((int) ($exam['is_score_published'] ?? 0) === 1) ? 'btn-danger' : 'btn-outline-danger' ?>" type="submit">
                                                <?= ((int) ($exam['is_score_published'] ?? 0) === 1) ? 'Ẩn điểm' : 'CÔNG BỐ ĐIỂM' ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= ((int) ($exam['is_score_published'] ?? 0) === 1) ? 'Đã công bố' : 'Chưa công bố' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <?php if (!$isDeleted): ?>
                                            <form method="post" onsubmit="return confirm('Xóa tạm kỳ thi này?')">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="soft_delete"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>">
                                                <button class="btn btn-sm btn-outline-warning">Xóa tạm</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" onsubmit="return confirm('Khôi phục kỳ thi này?')">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>">
                                                <button class="btn btn-sm btn-outline-success">Khôi phục</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" onsubmit="return confirm('XÓA THẬT kỳ thi và toàn bộ dữ liệu liên quan?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="hard_delete"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>">
                                            <button class="btn btn-sm btn-danger">Xóa thật</button>
                                        </form>
                                    </div>
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
