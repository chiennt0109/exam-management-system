<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';

require_once BASE_PATH . '/modules/exams/_common.php';

$csrf = exams_get_csrf_token();
$exams = exams_get_all_exams($pdo);
$examId = exams_resolve_current_exam_from_request();
if ($examId <= 0) {
    exams_set_flash('warning', 'Vui lòng chọn kỳ thi hiện tại trước khi thao tác.');
    header('Location: ' . BASE_URL . '/modules/exams/index.php');
    exit;
}
$fixedExamContext = getCurrentExamId() > 0;
$mode = (string) ($_POST['mode'] ?? 'manual');
$activeTab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'manual');
$searchAssigned = trim((string) ($_GET['q_assigned'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

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
        $detected = detectGradeFromClassName((string) ($row['lop'] ?? ''));
        if ($detected !== null && $detected !== '') {
            $up->execute([':khoi' => $detected, ':id' => (int) $row['id']]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        exams_set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: ' . BASE_URL . '/modules/exams/assign_students.php?exam_id=' . $examId);
        exit;
    }

    exams_assert_exam_unlocked_for_write($pdo, $examId);

    if ($examId <= 0) {
        exams_set_flash('error', 'Vui lòng chọn kỳ thi.');
        header('Location: ' . BASE_URL . '/modules/exams/assign_students.php');
        exit;
    }
    if (exams_is_locked($pdo, $examId)) {
        exams_set_flash('error', 'Kỳ thi đã khoá phân phòng, không thể chỉnh sửa danh sách học sinh.');
        header('Location: ' . BASE_URL . '/modules/exams/assign_students.php');
        exit;
    }

    backfillExamKhoi($pdo, $examId);

    $action = (string) ($_POST['action'] ?? 'add');
    if ($action === 'remove_student') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        if ($studentId <= 0) {
            exams_set_flash('error', 'Thiếu học sinh cần loại khỏi kỳ thi.');
            header('Location: ' . BASE_URL . '/modules/exams/assign_students.php?' . http_build_query(['exam_id' => $examId, 'tab' => 'selected']));
            exit;
        }

        try {
            $pdo->beginTransaction();
            $del = $pdo->prepare('DELETE FROM exam_students WHERE exam_id = :exam_id AND student_id = :student_id');
            $del->execute([':exam_id' => $examId, ':student_id' => $studentId]);
            $pdo->commit();
            exams_set_flash('success', 'Đã loại học sinh khỏi kỳ thi.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            exams_set_flash('error', 'Không thể loại học sinh khỏi kỳ thi.');
        }

        header('Location: ' . BASE_URL . '/modules/exams/assign_students.php?' . http_build_query(['exam_id' => $examId, 'tab' => 'selected']));
        exit;
    }

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

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $detected = detectGradeFromClassName((string) ($row['lop'] ?? ''));
            if ($grade === '' || $detected === $grade) {
                $studentIds[] = (int) $row['id'];
            }
        }
        $studentIds = array_values(array_unique($studentIds));
    }

    if (empty($studentIds)) {
        exams_set_flash('warning', 'Không có học sinh phù hợp để thêm vào kỳ thi.');
        header('Location: ' . BASE_URL . '/modules/exams/assign_students.php?' . http_build_query(['exam_id' => $examId, 'tab' => $activeTab]));
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

    header('Location: ' . BASE_URL . '/modules/exams/assign_students.php?' . http_build_query(['exam_id' => $examId, 'tab' => $activeTab]));
    exit;
}

$classStmt = $pdo->query('SELECT DISTINCT lop FROM students WHERE lop IS NOT NULL AND lop <> "" ORDER BY lop');
$classes = array_map(static fn(array $row): string => (string) $row['lop'], $classStmt->fetchAll(PDO::FETCH_ASSOC));

$students = [];
if ($examId > 0) {
    backfillExamKhoi($pdo, $examId);
    $stmt = $pdo->query('SELECT id, sbd, hoten, lop, truong FROM students ORDER BY lop, hoten LIMIT 500');
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$assignedCount = 0;
$selectedRows = [];
$totalSelected = 0;
if ($examId > 0) {
    $assignedCount = (int) $pdo->query('SELECT COUNT(*) FROM exam_students WHERE exam_id = ' . $examId . ' AND subject_id IS NULL')->fetchColumn();

    $where = ' WHERE es.exam_id = :exam_id AND es.subject_id IS NULL';
    $params = [':exam_id' => $examId];
    if ($searchAssigned !== '') {
        $where .= ' AND (s.hoten LIKE :q OR es.lop LIKE :q OR es.sbd LIKE :q)';
        $params[':q'] = '%' . $searchAssigned . '%';
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM exam_students es INNER JOIN students s ON s.id = es.student_id' . $where);
    $countStmt->execute($params);
    $totalSelected = (int) $countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $listStmt = $pdo->prepare('SELECT es.student_id, es.khoi, es.lop, es.sbd, s.hoten
        FROM exam_students es
        INNER JOIN students s ON s.id = es.student_id' . $where . '
        ORDER BY es.lop, s.hoten
        LIMIT :limit OFFSET :offset');
    foreach ($params as $k => $v) {
        $listStmt->bindValue($k, $v);
    }
    $listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $selectedRows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalPages = max(1, (int) ceil($totalSelected / $perPage));
$wizard = $examId > 0 ? exams_wizard_steps($pdo, $examId) : [];

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Bước 2: Gán học sinh vào kỳ thi</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>

                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Kỳ thi</label>
                        <?php if ($fixedExamContext): ?><input type="hidden" name="exam_id" value="<?= $examId ?>"><div class="form-control bg-light">#<?= $examId ?> - Kỳ thi hiện tại</div><?php else: ?><select name="exam_id" class="form-select" required>
                            <option value="">-- Chọn kỳ thi --</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>#<?= (int) $exam['id'] ?> - <?= htmlspecialchars((string) $exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string) $exam['nam'], ENT_QUOTES, 'UTF-8') ?>)</option>
                            <?php endforeach; ?>
                        </select><?php endif; ?>
                    </div>
                    <div class="col-md-3 align-self-end"><button class="btn btn-primary" type="submit">Tải dữ liệu</button></div>
                </form>

                <?php if ($examId > 0): ?>
                    <div class="mb-3 small text-muted">Đã gán: <strong><?= $assignedCount ?></strong> học sinh.</div>
                    <div class="mb-3"><?php foreach ($wizard as $index => $step): ?><span class="badge <?= $step['done'] ? 'bg-success' : 'bg-secondary' ?> me-1">B<?= $index ?>: <?= htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?></div>

                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item"><button class="nav-link <?= $activeTab === 'manual' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-manual" type="button">Mode A: Chọn thủ công</button></li>
                        <li class="nav-item"><button class="nav-link <?= $activeTab === 'class' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-class" type="button">Mode B: Theo lớp</button></li>
                        <li class="nav-item"><button class="nav-link <?= $activeTab === 'filter' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-filter" type="button">Mode C: Theo bộ lọc</button></li>
                    </ul>
                    <div class="tab-content border border-top-0 p-3 mb-3">
                        <div class="tab-pane fade <?= $activeTab === 'manual' ? 'show active' : '' ?>" id="tab-manual">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="exam_id" value="<?= $examId ?>">
                                <input type="hidden" name="mode" value="manual">
                                <input type="hidden" name="tab" value="manual">
                                <div class="table-responsive" style="max-height:320px;overflow:auto;">
                                    <table class="table table-sm table-bordered">
                                        <thead><tr><th></th><th>Họ tên</th><th>Lớp</th><th>SBD cũ</th><th>Trường</th><th>Khối detect</th></tr></thead>
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
                        </div>

                        <div class="tab-pane fade <?= $activeTab === 'class' ? 'show active' : '' ?>" id="tab-class">
                            <form method="post" class="row g-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="exam_id" value="<?= $examId ?>">
                                <input type="hidden" name="mode" value="class">
                                <input type="hidden" name="tab" value="class">
                                <div class="col-md-6">
                                    <label class="form-label">Chọn lớp</label>
                                    <select class="form-select" name="class_name" required>
                                        <option value="">-- Chọn lớp --</option>
                                        <?php foreach ($classes as $className): ?><option value="<?= htmlspecialchars($className, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($className, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 align-self-end"><button class="btn btn-success" type="submit">Thêm toàn bộ lớp</button></div>
                            </form>
                        </div>

                        <div class="tab-pane fade <?= $activeTab === 'filter' ? 'show active' : '' ?>" id="tab-filter">
                            <form method="post" class="row g-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="exam_id" value="<?= $examId ?>">
                                <input type="hidden" name="mode" value="filter">
                                <input type="hidden" name="tab" value="filter">
                                <div class="col-md-4"><label class="form-label">Lớp chính xác</label><input class="form-control" name="filter_class" placeholder="VD: 11A1"></div>
                                <div class="col-md-4"><label class="form-label">Mẫu lớp (contains)</label><input class="form-control" name="filter_pattern" placeholder="VD: A"></div>
                                <div class="col-md-2"><label class="form-label">Khối</label><input class="form-control" name="filter_grade" placeholder="VD: 11"></div>
                                <div class="col-md-2 align-self-end"><button class="btn btn-success w-100" type="submit">Thêm theo bộ lọc</button></div>
                            </form>
                        </div>
                    </div>

                    <div class="card border">
                        <div class="card-header bg-light"><strong>Danh sách đã chọn vào kỳ thi</strong></div>
                        <div class="card-body">
                            <form method="get" class="row g-2 mb-3">
                                <input type="hidden" name="exam_id" value="<?= $examId ?>">
                                <input type="hidden" name="tab" value="selected">
                                <div class="col-md-4"><input class="form-control" name="q_assigned" value="<?= htmlspecialchars($searchAssigned, ENT_QUOTES, 'UTF-8') ?>" placeholder="Lọc theo tên / lớp / SBD"></div>
                                <div class="col-md-2"><button class="btn btn-outline-primary" type="submit">Lọc</button></div>
                            </form>

                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle">
                                    <thead><tr><th>Họ tên</th><th>Lớp</th><th>Khối</th><th>SBD</th><th></th></tr></thead>
                                    <tbody>
                                    <?php if (empty($selectedRows)): ?>
                                        <tr><td colspan="5" class="text-center">Chưa có học sinh được gắn.</td></tr>
                                    <?php else: foreach ($selectedRows as $r): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($r['hoten'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($r['lop'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($r['khoi'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($r['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <form method="post" onsubmit="return confirm('Loại học sinh này khỏi kỳ thi?')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="exam_id" value="<?= $examId ?>">
                                                    <input type="hidden" name="action" value="remove_student">
                                                    <input type="hidden" name="student_id" value="<?= (int) $r['student_id'] ?>">
                                                    <button class="btn btn-sm btn-outline-danger">Loại khỏi kỳ thi</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($totalPages > 1): ?>
                                <nav>
                                    <ul class="pagination pagination-sm">
                                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?<?= http_build_query(['exam_id' => $examId, 'page' => $i, 'q_assigned' => $searchAssigned, 'tab' => 'selected']) ?>"><?= $i ?></a>
                                            </li>
                                        <?php endfor; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
