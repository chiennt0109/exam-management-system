<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';

$csrf = exams_get_csrf_token();
$exams = exams_get_all_exams($pdo);
$subjects = $pdo->query('SELECT id, ma_mon, ten_mon FROM subjects ORDER BY ten_mon')->fetchAll(PDO::FETCH_ASSOC);

$examId = exams_resolve_current_exam_from_request();
if ($examId <= 0) {
    exams_set_flash('warning', 'Vui lòng chọn kỳ thi hiện tại trước khi thao tác.');
    header('Location: ' . BASE_URL . '/modules/exams/index.php');
    exit;
}
$fixedExamContext = getCurrentExamId() > 0;
$subjectId = max(0, (int) ($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0));
$khoi = trim((string) ($_GET['khoi'] ?? $_POST['khoi'] ?? ''));

$ctx = $_SESSION['distribution_context'] ?? null;
if ($examId > 0 && is_array($ctx) && (int) ($ctx['exam_id'] ?? 0) === $examId) {
    if ($subjectId <= 0) {
        $subjectId = (int) ($ctx['subject_id'] ?? 0);
    }
    if ($khoi === '') {
        $khoi = (string) ($ctx['khoi'] ?? '');
    }
}

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
$studentsByRoom = [];
$assignedStudents = [];
if ($examId > 0 && $subjectId > 0 && $khoi !== '') {
    $roomStmt = $pdo->prepare('SELECT id AS room_id, ten_phong AS room_name FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi ORDER BY ten_phong');
    $roomStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $rooms = $roomStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rooms as $r) {
        $stu = $pdo->prepare('SELECT es.id, s.hoten AS name, es.sbd
            FROM exam_students es
            JOIN students s ON s.id = es.student_id
            WHERE es.room_id = :room_id
            ORDER BY es.sbd, s.hoten');
        $stu->execute([':room_id' => (int) $r['room_id']]);
        $studentsByRoom[(int) $r['room_id']] = $stu->fetchAll(PDO::FETCH_ASSOC);
    }

    $all = $pdo->prepare('SELECT es.id, s.hoten AS name, es.sbd, es.room_id FROM exam_students es JOIN students s ON s.id = es.student_id WHERE es.exam_id = :exam_id AND es.subject_id = :subject_id AND es.khoi = :khoi ORDER BY s.hoten');
    $all->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $assignedStudents = $all->fetchAll(PDO::FETCH_ASSOC);
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
                            <?php if ($fixedExamContext): ?><input type="hidden" name="exam_id" value="<?= $examId ?>"><div class="form-control bg-light">#<?= $examId ?> - Kỳ thi hiện tại</div><?php else: ?><select name="exam_id" class="form-select" required>
                                <option value="">-- Chọn kỳ thi --</option>
                                <?php foreach ($exams as $exam): ?>
                                    <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>#<?= (int) $exam['id'] ?> - <?= htmlspecialchars((string) $exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select><?php endif; ?>
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
                    <div class="row g-3 mb-4">
                        <?php foreach ($rooms as $r): ?>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header"><?= htmlspecialchars((string) $r['room_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach (($studentsByRoom[(int) $r['room_id']] ?? []) as $st): ?>
                                            <li class="list-group-item"><?= htmlspecialchars((string) ($st['sbd'] . ' - ' . $st['name']), ENT_QUOTES, 'UTF-8') ?></li>
                                        <?php endforeach; ?>
                                        <?php if (empty($studentsByRoom[(int) $r['room_id']] ?? [])): ?>
                                            <li class="list-group-item text-muted">(Trống)</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <h6>Chuyển / Bỏ thí sinh khỏi phòng</h6>
                            <form method="post" class="row g-2 mb-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="move_student">
                                <div class="col-12"><select class="form-select" name="exam_student_id" required><?php foreach ($assignedStudents as $st): ?><option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars((string) (($st['sbd'] ?? '') . ' - ' . ($st['name'] ?? 'N/A')), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                <div class="col-12"><select class="form-select" name="target_room_id" required><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['room_id'] ?>"><?= htmlspecialchars((string) $r['room_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                <div class="col-12"><button class="btn btn-primary btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Chuyển phòng</button></div>
                            </form>
                            <form method="post" class="row g-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="remove_student">
                                <div class="col-12"><select class="form-select" name="exam_student_id" required><?php foreach ($assignedStudents as $st): ?><option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars((string) (($st['sbd'] ?? '') . ' - ' . ($st['name'] ?? 'N/A')), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                <div class="col-12"><button class="btn btn-outline-danger btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Bỏ khỏi phòng</button></div>
                            </form>
                        </div>

                        <div class="col-md-6">
                            <h6>Gộp / Đổi tên phòng</h6>
                            <form method="post" class="row g-2 mb-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="merge_rooms">
                                <div class="col-6"><select class="form-select" name="old_room_id" required><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['room_id'] ?>"><?= htmlspecialchars((string) $r['room_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                <div class="col-6"><select class="form-select" name="target_room_id" required><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['room_id'] ?>"><?= htmlspecialchars((string) $r['room_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                <div class="col-12"><button class="btn btn-warning btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Gộp phòng</button></div>
                            </form>
                            <form method="post" class="row g-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="rename_room">
                                <div class="col-6"><select class="form-select" name="room_id" required><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['room_id'] ?>"><?= htmlspecialchars((string) $r['room_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                <div class="col-6"><input class="form-control" name="new_room_name" placeholder="Tên phòng mới" required></div>
                                <div class="col-12"><button class="btn btn-secondary btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Đổi tên phòng</button></div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
