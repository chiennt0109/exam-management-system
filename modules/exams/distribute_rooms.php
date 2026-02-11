<?php
declare(strict_types=1);

require_once __DIR__.'/_common.php';

/**
 * @return array<string,mixed>|null
 */
function getSubjectConfigForGrade(PDO $pdo, int $examId, int $subjectId, string $khoi): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM exam_subject_config WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi LIMIT 1');
    $stmt->execute([
        ':exam_id' => $examId,
        ':subject_id' => $subjectId,
        ':khoi' => $khoi,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * @return array<int, array<string,mixed>>
 */
function getEligibleStudentsForDistribution(PDO $pdo, int $examId, int $subjectId, string $khoi): array
{
    // Reuse shared scope-aware helper tied to exam_subject_config + exam_subject_classes
    return getStudentsForSubjectScope($pdo, $examId, $subjectId, $khoi);
}

/**
 * @param array<int, array<string,mixed>> $students
 * @return array<int,int>
 */
function handleRemainderOptionA(array $students, int $roomCount): array
{
    $total = count($students);
    if ($roomCount <= 0 || $total === 0) {
        return [];
    }

    $roomCount = min($roomCount, $total);
    $base = intdiv($total, $roomCount);
    $remainder = $total % $roomCount;

    $sizes = array_fill(0, $roomCount, $base);
    // keep last room smaller => distribute extra to early rooms
    for ($i = 0; $i < $remainder; $i++) {
        $sizes[$i]++;
    }

    return $sizes;
}

/**
 * @param array<int, array<string,mixed>> $students
 * @return array<int,int>
 */
function handleRemainderOptionB(array $students, int $roomCount): array
{
    $total = count($students);
    if ($roomCount <= 0 || $total === 0) {
        return [];
    }

    $roomCount = min($roomCount, $total);
    $base = intdiv($total, $roomCount);
    $remainder = $total % $roomCount;

    $sizes = array_fill(0, $roomCount, $base);
    // redistribute remainder across last rooms, number of rooms = remainder
    if ($remainder > 0) {
        for ($i = $roomCount - $remainder; $i < $roomCount; $i++) {
            $sizes[$i]++;
        }
    }

    return $sizes;
}

/**
 * @param array<int, array<string,mixed>> $students
 * @return array<int, array<int, array<string,mixed>>>
 */
function distributeByRoomCount(array $students, int $totalRooms, string $remainderOption): array
{
    if ($totalRooms <= 0) {
        throw new InvalidArgumentException('total_rooms phải > 0');
    }

    $sizes = $remainderOption === REMAINDER_REDISTRIBUTE
        ? handleRemainderOptionB($students, $totalRooms)
        : handleRemainderOptionA($students, $totalRooms);

    $chunks = [];
    $offset = 0;
    foreach ($sizes as $size) {
        if ($size <= 0) {
            continue;
        }
        $chunks[] = array_slice($students, $offset, $size);
        $offset += $size;
    }

    return $chunks;
}

/**
 * @param array<int, array<string,mixed>> $students
 * @return array<int, array<int, array<string,mixed>>>
 */
function distributeByMaxStudents(array $students, int $maxStudentsPerRoom, string $remainderOption): array
{
    if ($maxStudentsPerRoom <= 0) {
        throw new InvalidArgumentException('max_students_per_room phải > 0');
    }

    $total = count($students);
    if ($total === 0) {
        return [];
    }

    $roomCount = (int) ceil($total / $maxStudentsPerRoom);
    return distributeByRoomCount($students, $roomCount, $remainderOption);
}

function generateRoomName(string $subjectCode, string $khoi, int $roomIndex): string
{
    $subjectCode = strtoupper(trim($subjectCode));
    $safeCode = preg_replace('/[^A-Z0-9]/', '', $subjectCode) ?: 'SUB';

    return $safeCode . '-' . $khoi . '-' . str_pad((string) $roomIndex, 2, '0', STR_PAD_LEFT);
}

$csrf = exams_get_csrf_token();
$exams = exams_get_all_exams($pdo);
$subjects = $pdo->query('SELECT id, ma_mon, ten_mon FROM subjects ORDER BY ten_mon')->fetchAll(PDO::FETCH_ASSOC);

$examId = max(0, (int) ($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0));
$subjectId = max(0, (int) ($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0));
$khoi = trim((string) ($_GET['khoi'] ?? $_POST['khoi'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        exams_set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: distribute_rooms.php');
        exit;
    }

    $mode = (string) ($_POST['distribution_mode'] ?? 'by_total_rooms');
    $remainder = (string) ($_POST['remainder_option'] ?? REMAINDER_KEEP_SMALL);
    $totalRooms = max(0, (int) ($_POST['total_rooms'] ?? 0));
    $maxStudents = max(0, (int) ($_POST['max_students_per_room'] ?? 0));
    $overwrite = (($_POST['overwrite_existing'] ?? '') === '1');

    if ($examId <= 0 || $subjectId <= 0 || $khoi === '') {
        exams_set_flash('error', 'Phải chọn đủ Kỳ thi + Môn + Khối.');
        header('Location: distribute_rooms.php');
        exit;
    }

    if (!in_array($mode, ['by_total_rooms', 'by_max_students'], true)) {
        exams_set_flash('error', 'Distribution mode không hợp lệ.');
        header('Location: distribute_rooms.php?' . http_build_query(['exam_id' => $examId, 'subject_id' => $subjectId, 'khoi' => $khoi]));
        exit;
    }

    if (!in_array($remainder, [REMAINDER_KEEP_SMALL, REMAINDER_REDISTRIBUTE], true)) {
        exams_set_flash('error', 'Remainder option không hợp lệ.');
        header('Location: distribute_rooms.php?' . http_build_query(['exam_id' => $examId, 'subject_id' => $subjectId, 'khoi' => $khoi]));
        exit;
    }

    if ($mode === 'by_total_rooms' && $totalRooms <= 0) {
        exams_set_flash('error', 'total_rooms phải > 0.');
        header('Location: distribute_rooms.php?' . http_build_query(['exam_id' => $examId, 'subject_id' => $subjectId, 'khoi' => $khoi]));
        exit;
    }

    if ($mode === 'by_max_students' && $maxStudents <= 0) {
        exams_set_flash('error', 'max_students_per_room phải > 0.');
        header('Location: distribute_rooms.php?' . http_build_query(['exam_id' => $examId, 'subject_id' => $subjectId, 'khoi' => $khoi]));
        exit;
    }

    try {
        $config = getSubjectConfigForGrade($pdo, $examId, $subjectId, $khoi);
        if (!$config) {
            throw new RuntimeException('Không có cấu hình môn cho khối đã chọn.');
        }

        $eligibleStudents = getEligibleStudentsForDistribution($pdo, $examId, $subjectId, $khoi);
        if (empty($eligibleStudents)) {
            throw new RuntimeException('Không có học sinh trong phạm vi phân phòng của môn/khối này.');
        }

        usort($eligibleStudents, static fn(array $a, array $b): int => strcmp((string) ($a['sbd'] ?? ''), (string) ($b['sbd'] ?? '')));

        $existingStmt = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
        $existingStmt->execute([
            ':exam_id' => $examId,
            ':subject_id' => $subjectId,
            ':khoi' => $khoi,
        ]);
        $existingCount = (int) $existingStmt->fetchColumn();

        if ($existingCount > 0 && !$overwrite) {
            throw new RuntimeException('Đã có phân phòng cho exam+subject+khoi này. Vui lòng bật ghi đè để tiếp tục.');
        }

        $subjectCodeStmt = $pdo->prepare('SELECT ma_mon FROM subjects WHERE id = :id LIMIT 1');
        $subjectCodeStmt->execute([':id' => $subjectId]);
        $subjectCode = (string) ($subjectCodeStmt->fetchColumn() ?: 'SUB');

        $roomGroups = $mode === 'by_total_rooms'
            ? distributeByRoomCount($eligibleStudents, $totalRooms, $remainder)
            : distributeByMaxStudents($eligibleStudents, $maxStudents, $remainder);

        if (empty($roomGroups)) {
            throw new RuntimeException('Không thể tạo phân bổ phòng từ dữ liệu hiện tại.');
        }

        $pdo->beginTransaction();

        // clear previous distribution for this exam+subject+grade
        $clearRows = $pdo->prepare('DELETE FROM exam_students WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
        $clearRows->execute([
            ':exam_id' => $examId,
            ':subject_id' => $subjectId,
            ':khoi' => $khoi,
        ]);

        $clearRooms = $pdo->prepare('DELETE FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
        $clearRooms->execute([
            ':exam_id' => $examId,
            ':subject_id' => $subjectId,
            ':khoi' => $khoi,
        ]);

        $insertRoom = $pdo->prepare('INSERT INTO rooms (exam_id, subject_id, khoi, ten_phong) VALUES (:exam_id, :subject_id, :khoi, :ten_phong)');
        $insertExamStudent = $pdo->prepare('INSERT INTO exam_students (exam_id, student_id, subject_id, khoi, lop, room_id, sbd)
            VALUES (:exam_id, :student_id, :subject_id, :khoi, :lop, :room_id, :sbd)');

        $roomIndex = 1;
        foreach ($roomGroups as $group) {
            $roomName = generateRoomName($subjectCode, $khoi, $roomIndex);
            $insertRoom->execute([
                ':exam_id' => $examId,
                ':subject_id' => $subjectId,
                ':khoi' => $khoi,
                ':ten_phong' => $roomName,
            ]);
            $roomId = (int) $pdo->lastInsertId();

            foreach ($group as $student) {
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

            $roomIndex++;
        }

        $pdo->commit();
        exams_set_flash('success', 'Phân phòng thành công cho môn/khối đã chọn.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        exams_set_flash('error', $e->getMessage());
    }

    header('Location: distribute_rooms.php?' . http_build_query(['exam_id' => $examId, 'subject_id' => $subjectId, 'khoi' => $khoi]));
    exit;
}

$grades = [];
if ($examId > 0) {
    $gradeStmt = $pdo->prepare('SELECT DISTINCT khoi FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NULL AND khoi IS NOT NULL AND khoi <> "" ORDER BY khoi');
    $gradeStmt->execute([':exam_id' => $examId]);
    $grades = array_map(static fn(array $r): string => (string) $r['khoi'], $gradeStmt->fetchAll(PDO::FETCH_ASSOC));
}

$summary = [];
if ($examId > 0 && $subjectId > 0 && $khoi !== '') {
    $sumStmt = $pdo->prepare('SELECT r.ten_phong, COUNT(es.id) AS total
        FROM rooms r
        LEFT JOIN exam_students es ON es.room_id = r.id
        WHERE r.exam_id = :exam_id AND r.subject_id = :subject_id AND r.khoi = :khoi
        GROUP BY r.id
        ORDER BY r.ten_phong');
    $sumStmt->execute([
        ':exam_id' => $examId,
        ':subject_id' => $subjectId,
        ':khoi' => $khoi,
    ]);
    $summary = $sumStmt->fetchAll(PDO::FETCH_ASSOC);
}

$wizard = $examId > 0 ? exams_wizard_steps($pdo, $examId) : [];

require_once __DIR__.'/../../layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Bước 5: Phân phòng thi (nâng cao)</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>

                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Kỳ thi</label>
                        <select name="exam_id" class="form-select" required>
                            <option value="">-- Chọn kỳ thi --</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>#<?= (int) $exam['id'] ?> - <?= htmlspecialchars((string) $exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Môn học</label>
                        <select name="subject_id" class="form-select" required>
                            <option value="">-- Chọn môn --</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $subjectId === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$s['ma_mon'].' - '.(string)$s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Khối</label>
                        <select name="khoi" class="form-select" required>
                            <option value="">-- Chọn --</option>
                            <?php foreach ($grades as $g): ?>
                                <option value="<?= htmlspecialchars($g, ENT_QUOTES, 'UTF-8') ?>" <?= $khoi === $g ? 'selected' : '' ?>><?= htmlspecialchars($g, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 align-self-end">
                        <button class="btn btn-primary w-100" type="submit">Tải dữ liệu</button>
                    </div>
                </form>

                <?php if ($examId > 0): ?>
                    <div class="mb-3">
                        <?php foreach ($wizard as $index => $step): ?>
                            <span class="badge <?= $step['done'] ? 'bg-success' : 'bg-secondary' ?> me-1">B<?= $index ?>: <?= htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="border rounded p-3 mb-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="exam_id" value="<?= $examId ?>">
                    <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
                    <input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label d-block">Distribution mode</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="distribution_mode" id="modeA" value="by_total_rooms" checked>
                                <label class="form-check-label" for="modeA">Mode A — by total room count</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="distribution_mode" id="modeB" value="by_max_students">
                                <label class="form-check-label" for="modeB">Mode B — by max students per room</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label d-block">Remainder option</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="remainder_option" id="optA" value="keep_small" checked>
                                <label class="form-check-label" for="optA">Option 1 — last room smaller</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="remainder_option" id="optB" value="redistribute">
                                <label class="form-check-label" for="optB">Option 2 — redistribute remainder across last rooms</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">total_rooms</label>
                            <input type="number" min="1" class="form-control" name="total_rooms" value="5">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">max_students_per_room</label>
                            <input type="number" min="1" class="form-control" name="max_students_per_room" value="24">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="overwrite_existing" name="overwrite_existing" value="1">
                                <label class="form-check-label" for="overwrite_existing">Cho phép ghi đè nếu đã có phân phòng exam+subject+grade này</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-success" type="submit">Phân phòng</button>
                        </div>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead><tr><th>Phòng</th><th>Số thí sinh</th></tr></thead>
                        <tbody>
                            <?php if (empty($summary)): ?>
                                <tr><td colspan="2" class="text-center">Chưa có dữ liệu phân phòng cho lựa chọn hiện tại.</td></tr>
                            <?php else: ?>
                                <?php foreach ($summary as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $row['ten_phong'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= (int) $row['total'] ?></td>
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
