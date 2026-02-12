<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';

require_once BASE_PATH . '/modules/exams/_common.php';

$exams = exams_get_all_exams($pdo);
$examId = max(0, (int) ($_GET['exam_id'] ?? 0));
$rows = [];
if ($examId > 0) {
    $rows = checkDuplicateSBD($pdo, $examId);
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-warning"><strong>Kiểm tra SBD trùng</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>
                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-6">
                        <select name="exam_id" class="form-select" required>
                            <option value="">-- Chọn kỳ thi --</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>#<?= (int) $exam['id'] ?> - <?= htmlspecialchars((string) $exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3"><button class="btn btn-primary" type="submit">Kiểm tra</button></div>
                    <?php if ($examId > 0): ?>
                        <div class="col-md-3"><a class="btn btn-outline-secondary" href="export_duplicates.php?exam_id=<?= $examId ?>">Xuất Excel danh sách lỗi</a></div>
                    <?php endif; ?>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead><tr><th>SBD</th><th>Họ tên</th><th>Mã học sinh</th><th>Exam ID</th><th>Lớp</th></tr></thead>
                        <tbody>
                        <?php if ($examId <= 0): ?>
                            <tr><td colspan="5" class="text-center">Vui lòng chọn kỳ thi.</td></tr>
                        <?php elseif (empty($rows)): ?>
                            <tr><td colspan="5" class="text-center">Không phát hiện SBD trùng.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $r['sbd'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($r['hoten'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) ($r['student_id'] ?? 0) ?></td>
                                <td><?= (int) ($r['exam_id'] ?? 0) ?></td>
                                <td><?= htmlspecialchars((string) ($r['lop'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
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
