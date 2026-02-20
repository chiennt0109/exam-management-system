<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';

$csrf = exams_get_csrf_token();
$exams = exams_get_all_exams($pdo);
$subjects = $pdo->query('SELECT id, ma_mon, ten_mon FROM subjects ORDER BY ten_mon')->fetchAll(PDO::FETCH_ASSOC);
$subjectLabels = [];
foreach ($subjects as $subjectRow) {
    $subjectLabels[(int) ($subjectRow['id'] ?? 0)] = (string) (($subjectRow['ma_mon'] ?? '') . ' - ' . ($subjectRow['ten_mon'] ?? ''));
}

function exams_format_date_vn(?string $date): string
{
    $date = trim((string) $date);
    if ($date === '') {
        return '';
    }

    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }

    return date('d/m/Y', $ts);
}

$examId = exams_resolve_current_exam_from_request();
if ($examId <= 0) {
    exams_set_flash('warning', 'Vui lòng chọn kỳ thi hiện tại trước khi thao tác.');
    header('Location: ' . BASE_URL . '/modules/exams/index.php');
    exit;
}
$fixedExamContext = getCurrentExamId() > 0;
$subjectId = max(0, (int) ($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0));
$khoi = trim((string) ($_GET['khoi'] ?? $_POST['khoi'] ?? ''));

$viewMode = (string) ($_GET['view_mode'] ?? 'room');
if (!in_array($viewMode, ['room', 'class'], true)) {
    $viewMode = 'room';
}
$filterRoomId = max(0, (int) ($_GET['room_id'] ?? 0));
$filterClass = trim((string) ($_GET['class'] ?? ''));
$perPageOptions = [20, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 20);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 20;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$ctx = $_SESSION['distribution_context'] ?? null;
if ($examId > 0 && is_array($ctx) && (int) ($ctx['exam_id'] ?? 0) === $examId) {
    if ($subjectId <= 0) {
        $subjectId = (int) ($ctx['subject_id'] ?? 0);
    }
    if ($khoi === '') {
        $khoi = (string) ($ctx['khoi'] ?? '');
    }
}

$selectedSubjectLabel = $subjectLabels[$subjectId] ?? '';

$examLocked = false;
if ($examId > 0) {
    $l = $pdo->prepare('SELECT distribution_locked, rooms_locked FROM exams WHERE id = :id LIMIT 1');
    $l->execute([':id' => $examId]);
    $r = $l->fetch(PDO::FETCH_ASSOC) ?: [];
    $examLocked = ((int) ($r['distribution_locked'] ?? 0)) === 1 || ((int) ($r['rooms_locked'] ?? 0)) === 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        exams_set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: ' . BASE_URL . '/modules/exams/adjust_rooms.php');
        exit;
    }

    if ($examId <= 0 || $subjectId <= 0 || $khoi === '') {
        exams_set_flash('error', 'Vui lòng chọn kỳ thi, môn và khối để tinh chỉnh.');
        header('Location: ' . BASE_URL . '/modules/exams/adjust_rooms.php?' . http_build_query(['exam_id' => $examId]));
        exit;
    }

    $_SESSION['distribution_context'] = ['exam_id' => $examId, 'subject_id' => $subjectId, 'khoi' => $khoi];

    if ($examLocked) {
        exams_set_flash('error', 'Dữ liệu đã khoá phân phòng, không thể tinh chỉnh.');
        header('Location: ' . BASE_URL . '/modules/exams/adjust_rooms.php?' . http_build_query(['exam_id' => $examId, 'subject_id' => $subjectId, 'khoi' => $khoi]));
        exit;
    }

    exams_assert_exam_unlocked_for_write($pdo, $examId);

    $action = (string) ($_POST['action'] ?? '');

    try {
        $pdo->beginTransaction();

        if ($action === 'move_student') {
            $roomId = (int) ($_POST['target_room_id'] ?? 0);
            $esId = (int) ($_POST['exam_student_id'] ?? 0);
            $check = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE id=:id AND exam_id=:exam_id AND subject_id=:subject_id AND khoi=:khoi');
            $check->execute([':id' => $roomId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
            if ((int) $check->fetchColumn() <= 0) {
                throw new RuntimeException('Phòng đích không hợp lệ.');
            }
            $up = $pdo->prepare('UPDATE exam_students SET room_id = :room_id WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
            $up->execute([':room_id' => $roomId, ':id' => $esId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
            if ($up->rowCount() <= 0) {
                throw new RuntimeException('Không thể chuyển thí sinh.');
            }
            exams_set_flash('success', 'Đã chuyển thí sinh sang phòng mới.');
        } elseif ($action === 'remove_student') {
            $esId = (int) ($_POST['exam_student_id'] ?? 0);
            $up = $pdo->prepare('UPDATE exam_students SET room_id = NULL WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
            $up->execute([':id' => $esId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
            if ($up->rowCount() <= 0) {
                throw new RuntimeException('Không thể bỏ phòng thí sinh.');
            }
            exams_set_flash('success', 'Đã bỏ thí sinh khỏi phòng.');
        } elseif ($action === 'merge_rooms') {
            $target = (int) ($_POST['target_room_id'] ?? 0);
            $old = (int) ($_POST['old_room_id'] ?? 0);
            if ($target <= 0 || $old <= 0 || $target === $old) {
                throw new RuntimeException('Phòng gộp không hợp lệ.');
            }
            $pdo->prepare('UPDATE exam_students SET room_id = :target WHERE room_id = :old AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi')
                ->execute([':target' => $target, ':old' => $old, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
            $pdo->prepare('DELETE FROM rooms WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi')
                ->execute([':id' => $old, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
            exams_set_flash('success', 'Đã gộp phòng thành công.');
        } elseif ($action === 'rename_room') {
            $roomId = (int) ($_POST['room_id'] ?? 0);
            $newName = trim((string) ($_POST['new_room_name'] ?? ''));
            if ($newName === '') {
                throw new RuntimeException('Tên phòng không được để trống.');
            }
            $dup = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi AND ten_phong = :ten AND id <> :id');
            $dup->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi, ':ten' => $newName, ':id' => $roomId]);
            if ((int) $dup->fetchColumn() > 0) {
                throw new RuntimeException('Tên phòng đã tồn tại.');
            }
            $up = $pdo->prepare('UPDATE rooms SET ten_phong = :ten WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
            $up->execute([':ten' => $newName, ':id' => $roomId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
            if ($up->rowCount() <= 0) {
                throw new RuntimeException('Không thể đổi tên phòng.');
            }
            exams_set_flash('success', 'Đã đổi tên phòng.');
        } else {
            throw new RuntimeException('Hành động không hợp lệ.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        exams_set_flash('error', $e->getMessage());
    }

    header('Location: ' . BASE_URL . '/modules/exams/adjust_rooms.php?' . http_build_query(['exam_id' => $examId, 'subject_id' => $subjectId, 'khoi' => $khoi]));
    exit;
}


$rooms = [];
$roomMap = [];
$classOptions = [];
$assignedStudents = [];
$filteredStudents = [];
$pagedStudents = [];
$classViewSubjects = [];
$classViewSubjectByStudent = [];
$totalRows = 0;
$totalPages = 1;
$offset = 0;

if ($examId > 0 && $subjectId > 0 && $khoi !== '') {
    $roomStmt = $pdo->prepare('SELECT id AS room_id, ten_phong AS room_name FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi ORDER BY ten_phong');
    $roomStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $rooms = $roomStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rooms as $room) {
        $roomMap[(int) ($room['room_id'] ?? 0)] = (string) ($room['room_name'] ?? '');
    }
    if ($filterRoomId > 0 && !isset($roomMap[$filterRoomId])) {
        $filterRoomId = 0;
    }

    // Dataset for operation tabs (selected subject only)
    $all = $pdo->prepare('SELECT es.id, es.student_id, s.hoten AS name, s.ngaysinh, es.lop, es.sbd, es.room_id
        FROM exam_students es
        JOIN students s ON s.id = es.student_id
        WHERE es.exam_id = :exam_id AND es.subject_id = :subject_id AND es.khoi = :khoi
        ORDER BY es.lop, es.sbd, s.hoten');
    $all->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $assignedStudents = $all->fetchAll(PDO::FETCH_ASSOC);

    if ($viewMode === 'class') {
        // In class view: show each student with all subjects they take and corresponding room.
        $baseStmt = $pdo->prepare('SELECT es.student_id, s.hoten AS name, s.ngaysinh, es.lop, es.sbd
            FROM exam_students es
            JOIN students s ON s.id = es.student_id
            WHERE es.exam_id = :exam_id AND es.subject_id IS NULL AND es.khoi = :khoi
            ORDER BY es.lop, es.sbd, s.hoten');
        $baseStmt->execute([':exam_id' => $examId, ':khoi' => $khoi]);
        $filteredStudents = $baseStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($filteredStudents as $st) {
            $lop = trim((string) ($st['lop'] ?? ''));
            if ($lop !== '') {
                $classOptions[$lop] = true;
            }
        }
        ksort($classOptions);
        $classOptions = array_keys($classOptions);

        if ($filterClass !== '') {
            $filteredStudents = array_values(array_filter($filteredStudents, static fn(array $st): bool => (string) ($st['lop'] ?? '') === $filterClass));
        }

        $subjectStmt = $pdo->prepare('SELECT DISTINCT sub.id AS subject_id, sub.ten_mon
            FROM exam_students es
            INNER JOIN subjects sub ON sub.id = es.subject_id
            WHERE es.exam_id = :exam_id AND es.khoi = :khoi AND es.subject_id IS NOT NULL
            ORDER BY sub.ten_mon');
        $subjectStmt->execute([':exam_id' => $examId, ':khoi' => $khoi]);
        $classViewSubjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);

        $mapStmt = $pdo->prepare('SELECT es.student_id, es.subject_id, r.ten_phong
            FROM exam_students es
            LEFT JOIN rooms r ON r.id = es.room_id
            WHERE es.exam_id = :exam_id AND es.khoi = :khoi AND es.subject_id IS NOT NULL');
        $mapStmt->execute([':exam_id' => $examId, ':khoi' => $khoi]);
        foreach ($mapStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (int) ($row['student_id'] ?? 0);
            $subId = (int) ($row['subject_id'] ?? 0);
            if ($sid > 0 && $subId > 0) {
                $classViewSubjectByStudent[$sid][$subId] = (string) ($row['ten_phong'] ?? '');
            }
        }
    } else {
        foreach ($assignedStudents as $st) {
            $lop = trim((string) ($st['lop'] ?? ''));
            if ($lop !== '') {
                $classOptions[$lop] = true;
            }
        }
        ksort($classOptions);
        $classOptions = array_keys($classOptions);

        // Room view should only show students currently assigned to at least one room.
        $filteredStudents = array_values(array_filter($assignedStudents, static fn(array $st): bool => (int) ($st['room_id'] ?? 0) > 0));
        if ($filterRoomId > 0) {
            $filteredStudents = array_values(array_filter($filteredStudents, static fn(array $st): bool => (int) ($st['room_id'] ?? 0) === $filterRoomId));
        }
        if ($filterClass !== '') {
            $filteredStudents = array_values(array_filter($filteredStudents, static fn(array $st): bool => (string) ($st['lop'] ?? '') === $filterClass));
        }
    }

    $totalRows = count($filteredStudents);
    $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $pagedStudents = array_slice($filteredStudents, $offset, $perPage);
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Tinh chỉnh phòng thi</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>

                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-4">
                        <?php if ($fixedExamContext): ?>
                            <input type="hidden" name="exam_id" value="<?= $examId ?>">
                            <div class="form-control bg-light">#<?= $examId ?> - Kỳ thi hiện tại</div>
                        <?php else: ?>
                            <select name="exam_id" class="form-select" required>
                                <option value="">-- Chọn kỳ thi --</option>
                                <?php foreach ($exams as $exam): ?>
                                    <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>#<?= (int) $exam['id'] ?> - <?= htmlspecialchars((string) $exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <select name="subject_id" class="form-select" required>
                            <option value="">-- Chọn môn --</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $subjectId === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $s['ma_mon'] . ' - ' . (string) $s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><input class="form-control" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>" placeholder="Khối" required></div>
                    <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Mở tinh chỉnh</button></div>
                </form>

                <div class="mb-3 d-flex gap-2">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/modules/exams/distribute_rooms.php?<?= http_build_query(['exam_id' => $examId]) ?>">← Quay lại phân phòng</a>
                    <form method="post" action="<?= BASE_URL ?>/modules/exams/lock_rooms.php" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="exam_id" value="<?= $examId ?>">
                        <button class="btn btn-outline-danger btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Khoá phân phòng</button>
                    </form>
                </div>

                <?php if ($examLocked): ?>
                    <div class="alert alert-warning">Dữ liệu đã khóa phân phòng. Chỉ còn thao tác in danh sách phòng.</div>
                <?php endif; ?>

                <?php if (empty($rooms)): ?>
                    <div class="alert alert-info">Chưa có phòng cho tổ hợp kỳ thi/môn/khối này.</div>
                <?php else: ?>
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-student-ops" type="button" role="tab">Chuyển / Bỏ thí sinh</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-room-ops" type="button" role="tab">Gộp / Đổi tên phòng</button>
                        </li>
                    </ul>

                    <div class="tab-content border border-top-0 p-3 mb-3">
                        <div class="tab-pane fade show active" id="tab-student-ops" role="tabpanel">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6"><input type="text" id="searchStudentSelectMove" class="form-control form-control-sm" placeholder="Tìm thí sinh để chuyển phòng..."></div>
                                <div class="col-md-6"><input type="text" id="searchStudentSelectRemove" class="form-control form-control-sm" placeholder="Tìm thí sinh để bỏ khỏi phòng..."></div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="move_student">
                                        <div class="col-12"><select class="form-select" id="studentSelectMove" name="exam_student_id" required><?php foreach ($assignedStudents as $st): ?><option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars((string) (($st['sbd'] ?? '') . ' - ' . ($st['name'] ?? 'N/A') . ' - ' . ($st['lop'] ?? '') . ' - ' . ($roomMap[(int) ($st['room_id'] ?? 0)] ?? 'Chưa phòng')), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-12"><select class="form-select" name="target_room_id" required><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['room_id'] ?>"><?= htmlspecialchars((string) $r['room_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Chuyển phòng</button></div>
                                    </form>
                                </div>
                                <div class="col-md-6">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="remove_student">
                                        <div class="col-12"><select class="form-select" id="studentSelectRemove" name="exam_student_id" required><?php foreach ($assignedStudents as $st): ?><option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars((string) (($st['sbd'] ?? '') . ' - ' . ($st['name'] ?? 'N/A') . ' - ' . ($st['lop'] ?? '') . ' - ' . ($roomMap[(int) ($st['room_id'] ?? 0)] ?? 'Chưa phòng')), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-12"><button class="btn btn-outline-danger btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Bỏ khỏi phòng</button></div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="tab-room-ops" role="tabpanel">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6"><input type="text" id="searchOldRoomSelect" class="form-control form-control-sm" placeholder="Tìm phòng cần gộp..."></div>
                                <div class="col-md-6"><input type="text" id="searchRenameRoomSelect" class="form-control form-control-sm" placeholder="Tìm phòng cần đổi tên..."></div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="merge_rooms">
                                        <div class="col-6"><select class="form-select" id="oldRoomSelect" name="old_room_id" required><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['room_id'] ?>"><?= htmlspecialchars((string) $r['room_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-6"><select class="form-select" name="target_room_id" required><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['room_id'] ?>"><?= htmlspecialchars((string) $r['room_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-12"><button class="btn btn-warning btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Gộp phòng</button></div>
                                    </form>
                                </div>
                                <div class="col-md-6">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="rename_room">
                                        <div class="col-6"><select class="form-select" id="renameRoomSelect" name="room_id" required><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['room_id'] ?>"><?= htmlspecialchars((string) $r['room_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-6"><input class="form-control" name="new_room_name" placeholder="Tên phòng mới" required></div>
                                        <div class="col-12"><button class="btn btn-secondary btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Đổi tên phòng</button></div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="border rounded p-3 mb-3 bg-light">
                        <form method="get" class="row g-2 align-items-end">
                            <input type="hidden" name="exam_id" value="<?= $examId ?>">
                            <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
                            <input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="col-md-3">
                                <label class="form-label">Chế độ xem</label>
                                <select class="form-select" id="viewMode" name="view_mode">
                                    <option value="room" <?= $viewMode === 'room' ? 'selected' : '' ?>>Theo phòng thi</option>
                                    <option value="class" <?= $viewMode === 'class' ? 'selected' : '' ?>>Theo lớp</option>
                                </select>
                            </div>
                            <div class="col-md-3" id="filterRoomWrap">
                                <label class="form-label">Phòng thi</label>
                                <select class="form-select" name="room_id">
                                    <option value="0">-- Tất cả phòng --</option>
                                    <?php foreach ($rooms as $r): ?>
                                        <option value="<?= (int) $r['room_id'] ?>" <?= $filterRoomId === (int) $r['room_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $r['room_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3" id="filterClassWrap">
                                <label class="form-label">Lớp</label>
                                <select class="form-select" name="class">
                                    <option value="">-- Tất cả lớp --</option>
                                    <?php foreach ($classOptions as $lop): ?>
                                        <option value="<?= htmlspecialchars($lop, ENT_QUOTES, 'UTF-8') ?>" <?= $filterClass === $lop ? 'selected' : '' ?>><?= htmlspecialchars($lop, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Số dòng/trang</label>
                                <select class="form-select" name="per_page"><?php foreach ($perPageOptions as $opt): ?><option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option><?php endforeach; ?></select>
                            </div>
                            <div class="col-md-1 d-grid"><button class="btn btn-primary" type="submit">Lọc</button></div>
                        </form>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-bordered table-sm">
                            <thead><tr><th>STT</th><th>SBD</th><th>Họ tên</th><th>Ngày sinh</th><th>Lớp</th><?php if ($viewMode === 'class'): ?><?php foreach ($classViewSubjects as $sub): ?><th><?= htmlspecialchars((string) ($sub['ten_mon'] ?? ''), ENT_QUOTES, 'UTF-8') ?></th><?php endforeach; ?><?php endif; ?></tr></thead>
                            <tbody>
                            <?php if (empty($pagedStudents)): ?>
                                <tr><td colspan="<?= $viewMode === 'class' ? 5 + count($classViewSubjects) : 5 ?>" class="text-center">Không có dữ liệu phù hợp.</td></tr>
                            <?php else: foreach ($pagedStudents as $idx => $st): ?>
                                <tr>
                                    <td><?= $offset + $idx + 1 ?></td>
                                    <td><?= htmlspecialchars((string) ($st['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($st['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(exams_format_date_vn((string) ($st['ngaysinh'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($st['lop'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <?php if ($viewMode === 'class'): ?>
                                        <?php $studentId = (int) ($st['student_id'] ?? 0); ?>
                                        <?php foreach ($classViewSubjects as $sub): ?>
                                            <?php $subId = (int) ($sub['subject_id'] ?? 0); ?>
                                            <td><?= htmlspecialchars((string) ($classViewSubjectByStudent[$studentId][$subId] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <?php $mk = static fn(int $targetPage): string => BASE_URL . '/modules/exams/adjust_rooms.php?' . http_build_query(['exam_id'=>$examId,'subject_id'=>$subjectId,'khoi'=>$khoi,'view_mode'=>$viewMode,'room_id'=>$filterRoomId,'class'=>$filterClass,'per_page'=>$perPage,'page'=>$targetPage]); ?>
                        <nav>
                            <ul class="pagination pagination-sm flex-wrap">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><?= $page <= 1 ? '<span class="page-link">Trang trước</span>' : '<a class="page-link" href="'.htmlspecialchars($mk($page-1), ENT_QUOTES, 'UTF-8').'">Trang trước</a>' ?></li>
                                <?php for ($p = max(1, $page - 5); $p <= min($totalPages, $page + 5); $p++): ?>
                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($mk($p), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a></li>
                                <?php endfor; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><?= $page >= $totalPages ? '<span class="page-link">Trang sau</span>' : '<a class="page-link" href="'.htmlspecialchars($mk($page+1), ENT_QUOTES, 'UTF-8').'">Trang sau</a>' ?></li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const viewModeEl = document.getElementById('viewMode');
const filterRoomWrap = document.getElementById('filterRoomWrap');
const filterClassWrap = document.getElementById('filterClassWrap');

function filterSelectOptions(inputId, selectId) {
  const input = document.getElementById(inputId);
  const select = document.getElementById(selectId);
  if (!input || !select) return;
  const kw = input.value.trim().toLowerCase();
  Array.from(select.options).forEach((opt) => {
    opt.hidden = !(kw === '' || opt.text.toLowerCase().includes(kw));
  });
}
function refreshAdjustFilterMode(){
  if(!viewModeEl) return;
  const isRoom=viewModeEl.value==='room';
  if(filterRoomWrap) filterRoomWrap.style.display=isRoom?'':'none';
  if(filterClassWrap) filterClassWrap.style.display=isRoom?'none':'';
}
viewModeEl?.addEventListener('change', refreshAdjustFilterMode);
refreshAdjustFilterMode();


const selectFilterMap = {
  searchStudentSelectMove: 'studentSelectMove',
  searchStudentSelectRemove: 'studentSelectRemove',
  searchOldRoomSelect: 'oldRoomSelect',
  searchRenameRoomSelect: 'renameRoomSelect',
};
Object.entries(selectFilterMap).forEach(([inputId, selectId]) => {
  const input = document.getElementById(inputId);
  input?.addEventListener('input', () => filterSelectOptions(inputId, selectId));
});

</script>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
