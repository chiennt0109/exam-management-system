<?php
declare(strict_types=1);

require_once __DIR__.'/_common.php';

$csrf = exams_get_csrf_token();
$exams = exams_get_all_exams($pdo);
$examId = max(0, (int) ($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0));
$mode = (string) ($_POST['mode'] ?? 'manual');


function backfillExamKhoi(PDO $pdo, int $examId): void
{
    if ($examId <= 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT id, lop FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NULL AND (khoi IS NULL OR trim(khoi) = "" OR lower(khoi) = "unknown")');
    $stmt->execute([':exam_id' => $examId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return;
    }

    $up = $pdo->prepare('UPDATE exam_students SET khoi = :khoi WHERE id = :id');
    foreach ($rows as $row) {
        $lop = (string) ($row['lop'] ?? '');
        $detected = detectGradeFromClassName($lop);
        if ($detected !== null && $detected !== '') {
            $up->execute([':khoi' => $detected, ':id' => (int) $row['id']]);
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        exams_set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: assign_students.php?exam_id=' . $examId);
        exit;
    }

    if ($examId <= 0) {
        exams_set_flash('error', 'Vui lòng chọn kỳ thi.');
        header('Location: assign_students.php');
        exit;
    }

    backfillExamKhoi($pdo, $examId);

    /** @var array<int> $studentIds */
    $studentIds = [];

    if ($mode === 'manual') {
        $studentIds = array_values(array_unique(array_map('intval', (array) ($_POST['student_ids'] ?? []))));
    } elseif ($mode === 'class') {
        $className = trim((string) ($_POST['class_name'] ?? ''));
        if ($className !== '') {
            $stmt = $pdo->prepare('SELECT id FROM students WHERE lop = :lop');
            $stmt->execute([':lop' => $className]);
            $studentIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
        }
    } elseif ($mode === 'filter') {
        $exactClass = trim((string) ($_POST['filter_class'] ?? ''));
        $grade = trim((string) ($_POST['filter_grade'] ?? ''));
        $pattern = trim((string) ($_POST['filter_pattern'] ?? ''));

        $where = [];
        $params = [];
        if ($exactClass !== '') {
            $where[] = 'lop = :exact_class';
            $params[':exact_class'] = $exactClass;
        }
        if ($pattern !== '') {
            $where[] = 'lop LIKE :pattern';
            $params[':pattern'] = '%' . $pattern . '%';
        }

        $sql = 'SELECT id, lop FROM students' . ($where ? (' WHERE ' . implode(' AND ', $where)) : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $detected = detectGradeFromClassName((string) ($row['lop'] ?? ''));
            if ($grade === '' || $detected === $grade) {
                $studentIds[] = (int) $row['id'];
            }
        }
        $studentIds = array_values(array_unique($studentIds));
    }

    if (empty($studentIds)) {
        exams_set_flash('warning', 'Không có học sinh phù hợp để thêm vào kỳ thi.');
        header('Location: assign_students.php?exam_id=' . $examId);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $selStudent = $pdo->prepare('SELECT id, lop FROM students WHERE id = :id LIMIT 1');
        $check = $pdo->prepare('SELECT id FROM exam_students WHERE exam_id = :exam_id AND student_id = :student_id LIMIT 1');
        $ins = $pdo->prepare('INSERT INTO exam_students (exam_id, student_id, subject_id, khoi, lop, room_id, sbd) VALUES (:exam_id, :student_id, NULL, :khoi, :lop, NULL, NULL)');

        $added = 0;
        $duplicateCount = 0;
        foreach ($studentIds as $studentId) {
            if ($studentId <= 0) {
                continue;
            }

            $check->execute([':exam_id' => $examId, ':student_id' => $studentId]);
            if ($check->fetch(PDO::FETCH_ASSOC)) {
                $duplicateCount++;
                continue;
            }

            $selStudent->execute([':id' => $studentId]);
            $student = $selStudent->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                continue;
            }

            $lop = (string) ($student['lop'] ?? '');
            $khoi = detectGradeFromClassName($lop) ?? '';
            if ($khoi === '') {
                continue;
            }

            $ins->execute([
                ':exam_id' => $examId,
                ':student_id' => $studentId,
                ':khoi' => $khoi,
                ':lop' => $lop,
            ]);
            $added++;
        }

        $pdo->commit();
        if ($added > 0) {
            $msg = 'Đã thêm ' . $added . ' học sinh vào kỳ thi.';
            if ($duplicateCount > 0) {
                $msg .= ' Bỏ qua ' . $duplicateCount . ' học sinh đã tồn tại trong kỳ thi này.';
            }
            exams_set_flash('success', $msg);
        } else {
            exams_set_flash('error', 'Học sinh đã tồn tại trong kỳ thi này.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        exams_set_flash('error', 'Lỗi khi thêm học sinh vào kỳ thi.');
    }

    header('Location: assign_students.php?exam_id=' . $examId);
    exit;
}

$classStmt = $pdo->query('SELECT DISTINCT lop FROM students WHERE lop IS NOT NULL AND lop <> "" ORDER BY lop');
$classes = array_map(fn($row) => (string) $row['lop'], $classStmt->fetchAll(PDO::FETCH_ASSOC));

$students = [];
if ($examId > 0) {
    backfillExamKhoi($pdo, $examId);
    $stmt = $pdo->query('SELECT id, sbd, hoten, lop, truong FROM students ORDER BY lop, hoten LIMIT 500');
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$assignedCount = 0;
if ($examId > 0) {
    $assignedCount = (int) $pdo->query('SELECT COUNT(*) FROM exam_students WHERE exam_id = ' . $examId . ' AND subject_id IS NULL')->fetchColumn();
}

$wizard = $examId > 0 ? exams_wizard_steps($pdo, $examId) : [];

require_once __DIR__.'/../../layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Bước 2: Gán học sinh vào kỳ thi</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>

                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Kỳ thi</label>
                        <select name="exam_id" class="form-select" required>
                            <option value="">-- Chọn kỳ thi --</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>
                                    #<?= (int) $exam['id'] ?> - <?= htmlspecialchars((string) $exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string) $exam['nam'], ENT_QUOTES, 'UTF-8') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 align-self-end">
                        <button class="btn btn-primary" type="submit">Tải dữ liệu</button>
                    </div>
                </form>

                <?php if ($examId > 0): ?>
                    <div class="mb-3 small text-muted">Đã gán: <strong><?= $assignedCount ?></strong> học sinh.</div>

                    <div class="mb-3">
                        <?php foreach ($wizard as $index => $step): ?>
                            <span class="badge <?= $step['done'] ? 'bg-success' : 'bg-secondary' ?> me-1">B<?= $index ?>: <?= htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>

                    <form method="post" class="mb-4 border rounded p-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="exam_id" value="<?= $examId ?>">

                        <h5>Mode A — Chọn thủ công</h5>
                        <input type="hidden" name="mode" value="manual">
                        <div class="table-responsive" style="max-height:300px;overflow:auto;">
                            <table class="table table-sm table-bordered">
                                <thead><tr><th></th><th>Họ tên</th><th>Lớp</th><th>SBD</th><th>Trường</th><th>Khối detect</th></tr></thead>
                                <tbody>
                                <?php foreach ($students as $s): ?>
                                    <?php $khoi = detectGradeFromClassName((string) ($s['lop'] ?? '')) ?? 'N/A'; ?>
                                    <tr>
                                        <td><input type="checkbox" name="student_ids[]" value="<?= (int) $s['id'] ?>"></td>
                                        <td><?= htmlspecialchars((string) $s['hoten'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $s['lop'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $s['sbd'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $s['truong'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <button class="btn btn-success mt-2" type="submit">Thêm theo chọn tay</button>
                    </form>

                    <form method="post" class="mb-4 border rounded p-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="exam_id" value="<?= $examId ?>">
                        <input type="hidden" name="mode" value="class">
                        <h5>Mode B — Thêm cả lớp</h5>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <select class="form-select" name="class_name" required>
                                    <option value="">-- Chọn lớp --</option>
                                    <?php foreach ($classes as $className): ?>
                                        <option value="<?= htmlspecialchars($className, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($className, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3"><button class="btn btn-success" type="submit">Thêm cả lớp</button></div>
                        </div>
                    </form>

                    <form method="post" class="mb-3 border rounded p-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="exam_id" value="<?= $examId ?>">
                        <input type="hidden" name="mode" value="filter">
                        <h5>Mode C — Lọc theo điều kiện</h5>
                        <div class="row g-2">
                            <div class="col-md-4"><input class="form-control" name="filter_class" placeholder="Lớp chính xác (vd: 11_TIN)"></div>
                            <div class="col-md-3"><input class="form-control" name="filter_grade" placeholder="Khối (vd: 11)"></div>
                            <div class="col-md-3"><input class="form-control" name="filter_pattern" placeholder="Pattern lớp (vd: TIN)"></div>
                            <div class="col-md-2"><button class="btn btn-success w-100" type="submit">Lọc + thêm</button></div>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info">Vui lòng chọn kỳ thi trước khi gán học sinh.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__.'/../../layout/footer.php'; ?>
