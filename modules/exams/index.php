<?php
declare(strict_types=1);

require_once __DIR__.'/_common.php';

$csrf = exams_get_csrf_token();
$errors = [];

$hasTrangThai = false;
$columns = $pdo->query('PRAGMA table_info(exams)')->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    if (($col['name'] ?? '') === 'trang_thai') {
        $hasTrangThai = true;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }

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
                $stmt = $pdo->prepare('INSERT INTO exams (ten_ky_thi, nam, ngay_thi, trang_thai) VALUES (:ten_ky_thi, :nam, :ngay_thi, :trang_thai)');
                $stmt->execute([
                    ':ten_ky_thi' => $tenKyThi,
                    ':nam' => $nam,
                    ':ngay_thi' => $ngayThi,
                    ':trang_thai' => $trangThai,
                ]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO exams (ten_ky_thi, nam, ngay_thi) VALUES (:ten_ky_thi, :nam, :ngay_thi)');
                $stmt->execute([
                    ':ten_ky_thi' => $tenKyThi,
                    ':nam' => $nam,
                    ':ngay_thi' => $ngayThi,
                ]);
            }

            exams_set_flash('success', 'Đã tạo kỳ thi mới.');
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Không thể tạo kỳ thi.';
        }
    }
}

$selectTrangThai = $hasTrangThai ? ', trang_thai' : '';
$exams = $pdo->query('SELECT id, ten_ky_thi, nam, ngay_thi' . $selectTrangThai . ' FROM exams ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__.'/../../layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-primary text-white"><strong>Bước 1: Tạo kỳ thi mới</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <form method="post" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="col-md-4">
                        <label class="form-label">Tên kỳ thi *</label>
                        <input class="form-control" name="ten_ky_thi" required placeholder="VD: Kỳ thi HSG cấp trường">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Năm *</label>
                        <input class="form-control" type="number" name="nam" min="2000" max="2100" value="<?= date('Y') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Ngày thi</label>
                        <input class="form-control" type="date" name="ngay_thi">
                    </div>
                    <?php if ($hasTrangThai): ?>
                        <div class="col-md-3">
                            <label class="form-label">Trạng thái</label>
                            <select class="form-select" name="trang_thai">
                                <option value="draft">draft</option>
                                <option value="open">open</option>
                                <option value="closed">closed</option>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <button class="btn btn-success" type="submit">Tạo kỳ thi</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-light"><strong>Danh sách kỳ thi & điều hướng workflow</strong></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle">
                        <thead><tr><th>ID</th><th>Tên kỳ thi</th><th>Năm</th><th>Ngày thi</th><th>Trạng thái</th><th>Workflow</th></tr></thead>
                        <tbody>
                            <?php if (empty($exams)): ?>
                                <tr><td colspan="6" class="text-center">Chưa có kỳ thi.</td></tr>
                            <?php else: ?>
                                <?php foreach ($exams as $exam): ?>
                                    <tr>
                                        <td><?= (int) $exam['id'] ?></td>
                                        <td><?= htmlspecialchars((string) $exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $exam['nam'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $exam['ngay_thi'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= $hasTrangThai ? htmlspecialchars((string) ($exam['trang_thai'] ?? ''), ENT_QUOTES, 'UTF-8') : '<em>N/A</em>' ?></td>
                                        <td class="d-flex flex-wrap gap-1">
                                            <a class="btn btn-sm btn-outline-primary" href="assign_students.php?exam_id=<?= (int) $exam['id'] ?>">B2</a>
                                            <a class="btn btn-sm btn-outline-primary" href="generate_sbd.php?exam_id=<?= (int) $exam['id'] ?>">B3</a>
                                            <a class="btn btn-sm btn-outline-primary" href="configure_subjects.php?exam_id=<?= (int) $exam['id'] ?>">B4</a>
                                            <a class="btn btn-sm btn-outline-primary" href="distribute_rooms.php?exam_id=<?= (int) $exam['id'] ?>">B5</a>
                                            <a class="btn btn-sm btn-outline-primary" href="print_rooms.php?exam_id=<?= (int) $exam['id'] ?>">B6</a>
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
</div>
<?php require_once __DIR__.'/../../layout/footer.php'; ?>
