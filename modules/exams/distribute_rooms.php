<?php
declare(strict_types=1);

require_once __DIR__.'/_common.php';

$csrf = exams_get_csrf_token();
$exams = exams_get_all_exams($pdo);
$examId = max(0, (int) ($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        exams_set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: distribute_rooms.php?exam_id=' . $examId);
        exit;
    }

    if ($examId <= 0) {
        exams_set_flash('error', 'Vui lòng chọn kỳ thi.');
        header('Location: distribute_rooms.php');
        exit;
    }

    $method = (string) ($_POST['method'] ?? 'room_count');
    $remainderMode = (string) ($_POST['remainder_mode'] ?? REMAINDER_KEEP_SMALL);
    if (!in_array($remainderMode, [REMAINDER_KEEP_SMALL, REMAINDER_REDISTRIBUTE], true)) {
        $remainderMode = REMAINDER_KEEP_SMALL;
    }

    $roomCount = max(0, (int) ($_POST['room_count'] ?? 0));
    $roomSize = max(0, (int) ($_POST['room_size'] ?? 0));

    $baseCount = (int) $pdo->query('SELECT COUNT(*) FROM exam_students WHERE exam_id = ' . $examId . ' AND subject_id IS NULL')->fetchColumn();
    $configCount = (int) $pdo->query('SELECT COUNT(*) FROM exam_subject_config WHERE exam_id = ' . $examId)->fetchColumn();

    if ($baseCount <= 0) {
        exams_set_flash('warning', 'Chưa có học sinh cho kỳ thi.');
        header('Location: distribute_rooms.php?exam_id=' . $examId);
        exit;
    }

    if ($configCount <= 0) {
        exams_set_flash('warning', 'Chưa cấu hình môn theo khối.');
        header('Location: distribute_rooms.php?exam_id=' . $examId);
        exit;
    }

    if ($method === 'room_count' && $roomCount <= 0) {
        exams_set_flash('error', 'Số phòng phải lớn hơn 0.');
        header('Location: distribute_rooms.php?exam_id=' . $examId);
        exit;
    }
    if ($method === 'room_size' && $roomSize <= 0) {
        exams_set_flash('error', 'Sĩ số tối đa/phòng phải lớn hơn 0.');
        header('Location: distribute_rooms.php?exam_id=' . $examId);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $delSubjectRows = $pdo->prepare('DELETE FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NOT NULL');
        $delSubjectRows->execute([':exam_id' => $examId]);
        $delRooms = $pdo->prepare('DELETE FROM rooms WHERE exam_id = :exam_id');
        $delRooms->execute([':exam_id' => $examId]);

        $configs = $pdo->prepare('SELECT c.khoi, c.subject_id, s.ma_mon, s.ten_mon
            FROM exam_subject_config c
            INNER JOIN subjects s ON s.id = c.subject_id
            WHERE c.exam_id = :exam_id
            ORDER BY c.khoi, c.subject_id');
        $configs->execute([':exam_id' => $examId]);
        $configRows = $configs->fetchAll(PDO::FETCH_ASSOC);


        $insertRoom = $pdo->prepare('INSERT INTO rooms (exam_id, subject_id, khoi, ten_phong) VALUES (:exam_id, :subject_id, :khoi, :ten_phong)');
        $insertExamStudent = $pdo->prepare('INSERT INTO exam_students (exam_id, student_id, subject_id, khoi, lop, room_id, sbd)
            VALUES (:exam_id, :student_id, :subject_id, :khoi, :lop, :room_id, :sbd)');

        $createdRooms = 0;
        foreach ($configRows as $cfg) {
            $grade = (string) $cfg['khoi'];
            $subjectId = (int) $cfg['subject_id'];
            $maMon = (string) $cfg['ma_mon'];
            $gradeStudents = getStudentsForSubjectScope($pdo, $examId, $subjectId, $grade);

            if (empty($gradeStudents)) {
                continue;
            }

            usort($gradeStudents, fn($a, $b) => strcmp((string) ($a['sbd'] ?? ''), (string) ($b['sbd'] ?? '')));

            $roomsData = $method === 'room_count'
                ? distributeStudentsByRoomCount($gradeStudents, $roomCount, $remainderMode)
                : distributeStudentsByRoomSize($gradeStudents, $roomSize, $remainderMode);

            foreach ($roomsData as $idx => $roomStudents) {
                if (empty($roomStudents)) {
                    continue;
                }

                $roomName = 'P' . $grade . '-' . $maMon . '-' . str_pad((string) ($idx + 1), 2, '0', STR_PAD_LEFT);
                $insertRoom->execute([
                    ':exam_id' => $examId,
                    ':subject_id' => $subjectId,
                    ':khoi' => $grade,
                    ':ten_phong' => $roomName,
                ]);
                $roomId = (int) $pdo->lastInsertId();
                $createdRooms++;

                foreach ($roomStudents as $student) {
                    $insertExamStudent->execute([
                        ':exam_id' => $examId,
                        ':student_id' => (int) $student['student_id'],
                        ':subject_id' => $subjectId,
                        ':khoi' => (string) $student['khoi'],
                        ':lop' => (string) $student['lop'],
                        ':room_id' => $roomId,
                        ':sbd' => (string) $student['sbd'],
                    ]);
                }
            }
        }

        $pdo->commit();
        exams_set_flash('success', 'Đã phân phòng xong. Tổng số phòng tạo: ' . $createdRooms);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        exams_set_flash('error', 'Phân phòng thất bại: ' . $e->getMessage());
    }

    header('Location: distribute_rooms.php?exam_id=' . $examId);
    exit;
}

$summary = [];
if ($examId > 0) {
    $stmt = $pdo->prepare('SELECT r.khoi, r.ten_phong, sub.ten_mon, COUNT(es.id) AS total
        FROM rooms r
        LEFT JOIN subjects sub ON sub.id = r.subject_id
        LEFT JOIN exam_students es ON es.room_id = r.id
        WHERE r.exam_id = :exam_id
        GROUP BY r.id
        ORDER BY r.khoi, sub.ten_mon, r.ten_phong');
    $stmt->execute([':exam_id' => $examId]);
    $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$wizard = $examId > 0 ? exams_wizard_steps($pdo, $examId) : [];

require_once __DIR__.'/../../layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Bước 5: Phân phòng thi theo môn + khối</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>

                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-6">
                        <select name="exam_id" class="form-select" required>
                            <option value="">-- Chọn kỳ thi --</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int)$exam['id'] ?>" <?= $examId === (int)$exam['id'] ? 'selected' : '' ?>>#<?= (int)$exam['id'] ?> - <?= htmlspecialchars((string)$exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></option>
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

                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Phương pháp</label>
                                <select class="form-select" name="method" id="methodSelect">
                                    <option value="room_count">Method 1 - Theo tổng số phòng</option>
                                    <option value="room_size">Method 2 - Theo sĩ số tối đa/phòng</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Số phòng (N)</label>
                                <input class="form-control" type="number" name="room_count" value="5" min="1">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Sĩ số max (M)</label>
                                <input class="form-control" type="number" name="room_size" value="24" min="1">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Xử lý dư</label>
                                <select class="form-select" name="remainder_mode">
                                    <option value="keep_small">A - Phòng cuối nhỏ hơn</option>
                                    <option value="redistribute">B - Redistribute phần dư</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-success w-100" type="submit">Phân phòng</button>
                            </div>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead><tr><th>Khối</th><th>Môn</th><th>Phòng</th><th>Số TS</th></tr></thead>
                            <tbody>
                                <?php if (empty($summary)): ?>
                                    <tr><td colspan="4" class="text-center">Chưa phân phòng.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($summary as $r): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)$r['khoi'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$r['ten_mon'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$r['ten_phong'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= (int)$r['total'] ?></td>
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
