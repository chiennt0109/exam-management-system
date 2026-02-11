<?php
declare(strict_types=1);

require_once __DIR__.'/_common.php';

$csrf = exams_get_csrf_token();
$exams = exams_get_all_exams($pdo);
$subjects = $pdo->query('SELECT id, ma_mon, ten_mon, he_so FROM subjects ORDER BY ten_mon')->fetchAll(PDO::FETCH_ASSOC);

$examId = max(0, (int) ($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        exams_set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: configure_subjects.php?exam_id=' . $examId);
        exit;
    }

    if ($examId <= 0) {
        exams_set_flash('error', 'Vui lòng chọn kỳ thi.');
        header('Location: configure_subjects.php');
        exit;
    }

    $baseReady = (int) $pdo->query('SELECT COUNT(*) FROM exam_students WHERE exam_id = ' . $examId . ' AND subject_id IS NULL AND sbd IS NOT NULL AND sbd <> ""')->fetchColumn();
    if ($baseReady <= 0) {
        exams_set_flash('warning', 'Cần hoàn thành bước gán học sinh + sinh SBD trước khi cấu hình môn.');
        header('Location: configure_subjects.php?exam_id=' . $examId);
        exit;
    }

    $action = (string) ($_POST['action'] ?? 'add');

    try {
        if ($action === 'delete') {
            $configId = (int) ($_POST['config_id'] ?? 0);
            $del = $pdo->prepare('DELETE FROM exam_subject_configs WHERE id = :id AND exam_id = :exam_id');
            $del->execute([':id' => $configId, ':exam_id' => $examId]);
            exams_set_flash('success', 'Đã xóa cấu hình môn.');
        } else {
            $grade = trim((string) ($_POST['grade'] ?? ''));
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            $markType = trim((string) ($_POST['mark_type'] ?? ''));
            $duration = (int) ($_POST['duration'] ?? 0);
            $session = trim((string) ($_POST['session'] ?? ''));
            $coefficient = (float) ($_POST['coefficient'] ?? 1);

            if ($grade === '' || $subjectId <= 0) {
                throw new RuntimeException('Grade và Subject là bắt buộc.');
            }

            $upsert = $pdo->prepare('INSERT INTO exam_subject_configs (exam_id, grade, subject_id, mark_type, duration, session, coefficient)
                VALUES (:exam_id, :grade, :subject_id, :mark_type, :duration, :session, :coefficient)
                ON CONFLICT(exam_id, grade, subject_id)
                DO UPDATE SET mark_type = excluded.mark_type, duration = excluded.duration, session = excluded.session, coefficient = excluded.coefficient');
            $upsert->execute([
                ':exam_id' => $examId,
                ':grade' => $grade,
                ':subject_id' => $subjectId,
                ':mark_type' => $markType,
                ':duration' => $duration,
                ':session' => $session,
                ':coefficient' => $coefficient,
            ]);
            exams_set_flash('success', 'Đã lưu cấu hình môn theo khối.');
        }
    } catch (Throwable $e) {
        exams_set_flash('error', 'Không thể lưu cấu hình môn. ' . $e->getMessage());
    }

    header('Location: configure_subjects.php?exam_id=' . $examId);
    exit;
}

$grades = [];
$configRows = [];
if ($examId > 0) {
    $gradeStmt = $pdo->prepare('SELECT DISTINCT khoi FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NULL AND khoi IS NOT NULL AND khoi <> "" ORDER BY khoi');
    $gradeStmt->execute([':exam_id' => $examId]);
    $grades = array_map(fn($r) => (string) $r['khoi'], $gradeStmt->fetchAll(PDO::FETCH_ASSOC));

    $cfgStmt = $pdo->prepare('SELECT c.id, c.grade, c.mark_type, c.duration, c.session, c.coefficient, s.ma_mon, s.ten_mon
        FROM exam_subject_configs c
        INNER JOIN subjects s ON s.id = c.subject_id
        WHERE c.exam_id = :exam_id
        ORDER BY c.grade, s.ten_mon');
    $cfgStmt->execute([':exam_id' => $examId]);
    $configRows = $cfgStmt->fetchAll(PDO::FETCH_ASSOC);
}

$wizard = $examId > 0 ? exams_wizard_steps($pdo, $examId) : [];

require_once __DIR__.'/../../layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Bước 4: Cấu hình môn theo khối</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>

                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-6">
                        <select name="exam_id" class="form-select" required>
                            <option value="">-- Chọn kỳ thi --</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>#<?= (int) $exam['id'] ?> - <?= htmlspecialchars((string)$exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3"><button class="btn btn-primary" type="submit">Tải dữ liệu</button></div>
                </form>

                <?php if ($examId > 0): ?>
                    <div class="mb-3">
                        <?php foreach ($wizard as $index => $step): ?>
                            <span class="badge <?= $step['done'] ? 'bg-success' : 'bg-secondary' ?> me-1">B<?= $index ?>: <?= htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>

                    <form method="post" class="border rounded p-3 mb-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="exam_id" value="<?= $examId ?>">
                        <input type="hidden" name="action" value="add">
                        <div class="row g-2">
                            <div class="col-md-2">
                                <label class="form-label">Khối</label>
                                <select class="form-select" name="grade" required>
                                    <option value="">-- Chọn --</option>
                                    <?php foreach ($grades as $grade): ?>
                                        <option value="<?= htmlspecialchars($grade, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($grade, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Môn học</label>
                                <select class="form-select" name="subject_id" required>
                                    <option value="">-- Chọn môn --</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?= (int) $subject['id'] ?>"><?= htmlspecialchars((string)$subject['ma_mon'] . ' - ' . (string)$subject['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2"><label class="form-label">Hình thức</label><input class="form-control" name="mark_type" placeholder="Tự luận"></div>
                            <div class="col-md-2"><label class="form-label">Thời lượng</label><input class="form-control" name="duration" type="number" min="0" value="0"></div>
                            <div class="col-md-2"><label class="form-label">Buổi</label><input class="form-control" name="session" placeholder="Sáng"></div>
                            <div class="col-md-2"><label class="form-label">Hệ số</label><input class="form-control" name="coefficient" type="number" step="0.01" value="1"></div>
                        </div>
                        <button class="btn btn-success mt-2" type="submit">Lưu cấu hình</button>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead><tr><th>Khối</th><th>Môn</th><th>Hình thức</th><th>Thời lượng</th><th>Buổi</th><th>Hệ số</th><th></th></tr></thead>
                            <tbody>
                                <?php if (empty($configRows)): ?>
                                    <tr><td colspan="7" class="text-center">Chưa có cấu hình.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($configRows as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) $row['grade'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$row['ma_mon'] . ' - ' . (string)$row['ten_mon'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) $row['mark_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) $row['duration'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) $row['session'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) $row['coefficient'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <form method="post" onsubmit="return confirm('Xóa cấu hình này?')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="exam_id" value="<?= $examId ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="config_id" value="<?= (int) $row['id'] ?>">
                                                    <button class="btn btn-sm btn-danger">Xóa</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__.'/../../layout/footer.php'; ?>
