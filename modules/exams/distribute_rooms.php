<?php
declare(strict_types=1);

require_once __DIR__.'/_common.php';

$roomCols = array_column($pdo->query('PRAGMA table_info(rooms)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('scope_identifier', $roomCols, true)) {
    $pdo->exec('ALTER TABLE rooms ADD COLUMN scope_identifier TEXT DEFAULT "entire_grade"');
}

/**
 * @return array<int, array<string,mixed>>
 */
function getScopeGroups(PDO $pdo, int $examId, int $subjectId, string $khoi): array
{
    $cfgStmt = $pdo->prepare('SELECT id, scope_mode FROM exam_subject_config WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi ORDER BY id ASC');
    $cfgStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $rows = $cfgStmt->fetchAll(PDO::FETCH_ASSOC);
    $groups = [];

    foreach ($rows as $row) {
        $configId = (int) ($row['id'] ?? 0);
        $scopeMode = (string) ($row['scope_mode'] ?? 'entire_grade');
        $classes = [];

        if ($scopeMode === 'specific_classes') {
            $classStmt = $pdo->prepare('SELECT lop FROM exam_subject_classes WHERE exam_config_id = :config_id ORDER BY lop');
            $classStmt->execute([':config_id' => $configId]);
            $classes = array_map(static fn(array $r): string => (string) $r['lop'], $classStmt->fetchAll(PDO::FETCH_ASSOC));
            if (empty($classes)) {
                continue;
            }
        }

        $scopeIdentifier = getScopeIdentifier($scopeMode, $classes);
        $groups[$scopeIdentifier] = [
            'scope_identifier' => $scopeIdentifier,
            'scope_mode' => $scopeMode,
            'classes' => $classes,
        ];
    }

    if (empty($groups)) {
        return [];
    }

    // entire_grade supersedes all specific class scopes.
    foreach ($groups as $g) {
        if ($g['scope_mode'] === 'entire_grade') {
            return [$g];
        }
    }

    return array_values($groups);
}

/**
 * @param array<int,string> $classes
 */
function getScopeIdentifier(string $scopeMode, array $classes = []): string
{
    if ($scopeMode === 'entire_grade') {
        return 'entire_grade';
    }

    sort($classes);
    return 'specific_' . sha1(implode('|', $classes));
}

/**
 * @param array<int,string> $classes
 * @return array<int, array<string,mixed>>
 */
function getEligibleStudentsByScope(PDO $pdo, int $examId, string $khoi, string $scopeMode, array $classes): array
{
    if ($scopeMode === 'entire_grade') {
        $stmt = $pdo->prepare('SELECT student_id, khoi, lop, sbd FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NULL AND khoi = :khoi AND sbd IS NOT NULL AND sbd <> ""');
        $stmt->execute([':exam_id' => $examId, ':khoi' => $khoi]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($classes)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($classes), '?'));
    $sql = 'SELECT student_id, khoi, lop, sbd FROM exam_students WHERE exam_id = ? AND subject_id IS NULL AND khoi = ? AND lop IN (' . $placeholders . ') AND sbd IS NOT NULL AND sbd <> ""';
    $params = array_merge([$examId, $khoi], $classes);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array<int, array<string,mixed>>
 */
function getRoomsByScope(PDO $pdo, int $examId, int $subjectId, string $khoi, string $scopeIdentifier): array
{
    $stmt = $pdo->prepare('SELECT id, ten_phong, scope_identifier FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi AND scope_identifier = :scope_identifier ORDER BY ten_phong');
    $stmt->execute([
        ':exam_id' => $examId,
        ':subject_id' => $subjectId,
        ':khoi' => $khoi,
        ':scope_identifier' => $scopeIdentifier,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function moveStudentToRoom(PDO $pdo, int $examStudentId, int $targetRoomId, int $examId, int $subjectId, string $khoi): void
{
    $stmt = $pdo->prepare('SELECT id, scope_identifier FROM rooms WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi LIMIT 1');
    $stmt->execute([':id' => $targetRoomId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$room) {
        throw new RuntimeException('Phòng đích không hợp lệ.');
    }

    $up = $pdo->prepare('UPDATE exam_students SET room_id = :room_id WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
    $up->execute([':room_id' => $targetRoomId, ':id' => $examStudentId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    if ($up->rowCount() <= 0) {
        throw new RuntimeException('Không thể chuyển thí sinh sang phòng đích.');
    }
}

function removeStudentFromRoom(PDO $pdo, int $examStudentId, int $examId, int $subjectId, string $khoi): void
{
    $up = $pdo->prepare('UPDATE exam_students SET room_id = NULL WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
    $up->execute([':id' => $examStudentId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    if ($up->rowCount() <= 0) {
        throw new RuntimeException('Không thể bỏ phân phòng cho thí sinh.');
    }
}

function mergeRooms(PDO $pdo, int $roomAId, int $roomBId, int $examId, int $subjectId, string $khoi): void
{
    $get = $pdo->prepare('SELECT id, ten_phong, scope_identifier FROM rooms WHERE id IN (:a, :b) AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
    $get->execute([':a' => $roomAId, ':b' => $roomBId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $rooms = $get->fetchAll(PDO::FETCH_ASSOC);
    if (count($rooms) !== 2) {
        throw new RuntimeException('Phòng gộp không hợp lệ.');
    }

    $scopeA = (string) ($rooms[0]['scope_identifier'] ?? '');
    $scopeB = (string) ($rooms[1]['scope_identifier'] ?? '');
    if ($scopeA !== $scopeB) {
        throw new RuntimeException('Không thể gộp phòng khác phạm vi.');
    }

    $move = $pdo->prepare('UPDATE exam_students SET room_id = :room_a WHERE room_id = :room_b AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
    $move->execute([':room_a' => $roomAId, ':room_b' => $roomBId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);

    $del = $pdo->prepare('DELETE FROM rooms WHERE id = :room_b AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
    $del->execute([':room_b' => $roomBId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
}

function renameRoom(PDO $pdo, int $roomId, string $newName, int $examId, int $subjectId, string $khoi): void
{
    $newName = trim($newName);
    if ($newName === '') {
        throw new RuntimeException('Tên phòng không được rỗng.');
    }

    $dup = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi AND ten_phong = :ten AND id <> :id');
    $dup->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi, ':ten' => $newName, ':id' => $roomId]);
    if ((int) $dup->fetchColumn() > 0) {
        throw new RuntimeException('Tên phòng đã tồn tại.');
    }

    $up = $pdo->prepare('UPDATE rooms SET ten_phong = :ten WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
    $up->execute([':ten' => $newName, ':id' => $roomId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
}

function addStudentToRoom(PDO $pdo, int $examId, int $subjectId, string $khoi, int $roomId, int $studentId): void
{
    $roomStmt = $pdo->prepare('SELECT id FROM rooms WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi LIMIT 1');
    $roomStmt->execute([':id' => $roomId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    if (!$roomStmt->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Phòng nhận không hợp lệ.');
    }

    $dup = $pdo->prepare('SELECT COUNT(*) FROM exam_students WHERE exam_id = :exam_id AND student_id = :student_id AND subject_id = :subject_id');
    $dup->execute([':exam_id' => $examId, ':student_id' => $studentId, ':subject_id' => $subjectId]);
    if ((int) $dup->fetchColumn() > 0) {
        throw new RuntimeException('Thí sinh đã có trong môn thi này.');
    }

    $baseRow = $pdo->prepare('SELECT khoi, lop, sbd FROM exam_students WHERE exam_id = :exam_id AND student_id = :student_id AND subject_id IS NULL LIMIT 1');
    $baseRow->execute([':exam_id' => $examId, ':student_id' => $studentId]);
    $base = $baseRow->fetch(PDO::FETCH_ASSOC);

    if ($base) {
        $studentKhoi = (string) ($base['khoi'] ?? '');
        $studentLop = (string) ($base['lop'] ?? '');
        $sbd = (string) ($base['sbd'] ?? '');
    } else {
        $studentStmt = $pdo->prepare('SELECT lop FROM students WHERE id = :id LIMIT 1');
        $studentStmt->execute([':id' => $studentId]);
        $lop = (string) ($studentStmt->fetchColumn() ?: '');
        if ($lop === '') {
            throw new RuntimeException('Không tìm thấy thông tin thí sinh.');
        }
        $studentKhoi = detectGradeFromClassName($lop) ?? $khoi;
        $studentLop = $lop;

        $maxStmt = $pdo->prepare('SELECT MAX(CAST(substr(sbd, -4) AS INTEGER)) FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NULL AND khoi = :khoi AND sbd IS NOT NULL AND sbd <> ""');
        $maxStmt->execute([':exam_id' => $examId, ':khoi' => $studentKhoi]);
        $next = ((int) $maxStmt->fetchColumn()) + 1;
        $sbd = generateExamSBD($examId, $studentKhoi, max(1, $next));

        $insBase = $pdo->prepare('INSERT INTO exam_students (exam_id, student_id, subject_id, khoi, lop, room_id, sbd) VALUES (:exam_id, :student_id, NULL, :khoi, :lop, NULL, :sbd)');
        $insBase->execute([':exam_id' => $examId, ':student_id' => $studentId, ':khoi' => $studentKhoi, ':lop' => $studentLop, ':sbd' => $sbd]);
    }

    if ($studentKhoi !== $khoi) {
        throw new RuntimeException('Thí sinh không thuộc khối đã chọn.');
    }

    $insSub = $pdo->prepare('INSERT INTO exam_students (exam_id, student_id, subject_id, khoi, lop, room_id, sbd) VALUES (:exam_id, :student_id, :subject_id, :khoi, :lop, :room_id, :sbd)');
    $insSub->execute([
        ':exam_id' => $examId,
        ':student_id' => $studentId,
        ':subject_id' => $subjectId,
        ':khoi' => $studentKhoi,
        ':lop' => $studentLop,
        ':room_id' => $roomId,
        ':sbd' => $sbd,
    ]);
}

/**
 * @return array<int, array<string,mixed>>
 */
function getUnassignedStudents(PDO $pdo, int $examId, int $subjectId, string $khoi): array
{
    $stmt = $pdo->prepare('SELECT es.id, es.student_id, es.lop, es.sbd, s.hoten FROM exam_students es
        LEFT JOIN students s ON s.id = es.student_id
        WHERE es.exam_id = :exam_id AND es.subject_id = :subject_id AND es.khoi = :khoi AND es.room_id IS NULL
        ORDER BY es.lop, es.sbd');
    $stmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    for ($i = $roomCount - $remainder; $i < $roomCount; $i++) {
        if ($i >= 0) {
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

    return distributeByRoomCount($students, (int) ceil($total / $maxStudentsPerRoom), $remainderOption);
}

function generateRoomName(string $subjectCode, string $khoi, string $scopeIdentifier, int $roomIndex): string
{
    $safeCode = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($subjectCode))) ?: 'SUB';
    $scopePart = $scopeIdentifier === 'entire_grade' ? 'ALL' : substr(strtoupper(preg_replace('/[^A-Z0-9]/', '', $scopeIdentifier) ?: 'SCP'), -6);
    return $safeCode . '-' . $khoi . '-' . $scopePart . '-' . str_pad((string) $roomIndex, 2, '0', STR_PAD_LEFT);
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

    $action = (string) ($_POST['action'] ?? 'auto_distribute');

    if ($examId <= 0 || $subjectId <= 0 || $khoi === '') {
        exams_set_flash('error', 'Phải chọn đủ Kỳ thi + Môn + Khối.');
        header('Location: distribute_rooms.php');
        exit;
    }

    try {
        if ($action === 'auto_distribute') {
            $mode = (string) ($_POST['distribution_mode'] ?? 'by_total_rooms');
            $remainder = (string) ($_POST['remainder_option'] ?? REMAINDER_KEEP_SMALL);
            $totalRooms = max(0, (int) ($_POST['total_rooms'] ?? 0));
            $maxStudents = max(0, (int) ($_POST['max_students_per_room'] ?? 0));
            $overwrite = (($_POST['overwrite_existing'] ?? '') === '1');

            if (!in_array($mode, ['by_total_rooms', 'by_max_students'], true)) {
                throw new RuntimeException('Distribution mode không hợp lệ.');
            }
            if (!in_array($remainder, [REMAINDER_KEEP_SMALL, REMAINDER_REDISTRIBUTE], true)) {
                throw new RuntimeException('Remainder option không hợp lệ.');
            }
            if ($mode === 'by_total_rooms' && $totalRooms <= 0) {
                throw new RuntimeException('total_rooms phải > 0.');
            }
            if ($mode === 'by_max_students' && $maxStudents <= 0) {
                throw new RuntimeException('max_students_per_room phải > 0.');
            }

            $scopeGroups = getScopeGroups($pdo, $examId, $subjectId, $khoi);
            if (empty($scopeGroups)) {
                throw new RuntimeException('Không có cấu hình phạm vi cho môn/khối đã chọn.');
            }

            $subjectCodeStmt = $pdo->prepare('SELECT ma_mon FROM subjects WHERE id = :id LIMIT 1');
            $subjectCodeStmt->execute([':id' => $subjectId]);
            $subjectCode = (string) ($subjectCodeStmt->fetchColumn() ?: 'SUB');

            $existingStmt = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
            $existingStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
            if ((int) $existingStmt->fetchColumn() > 0 && !$overwrite) {
                throw new RuntimeException('Đã có phân phòng cho môn/khối này. Vui lòng bật ghi đè để tiếp tục.');
            }

            $pdo->beginTransaction();
            $delRows = $pdo->prepare('DELETE FROM exam_students WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
            $delRows->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
            $delRooms = $pdo->prepare('DELETE FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
            $delRooms->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);

            $insertRoom = $pdo->prepare('INSERT INTO rooms (exam_id, subject_id, khoi, ten_phong, scope_identifier) VALUES (:exam_id, :subject_id, :khoi, :ten, :scope_identifier)');
            $insertExamStudent = $pdo->prepare('INSERT INTO exam_students (exam_id, student_id, subject_id, khoi, lop, room_id, sbd) VALUES (:exam_id, :student_id, :subject_id, :khoi, :lop, :room_id, :sbd)');

            $assignedStudentIds = [];
            foreach ($scopeGroups as $group) {
                $scopeIdentifier = (string) $group['scope_identifier'];
                $eligibleStudents = getEligibleStudentsByScope($pdo, $examId, $khoi, (string) $group['scope_mode'], (array) $group['classes']);
                $eligibleStudents = array_values(array_filter($eligibleStudents, static function (array $row) use (&$assignedStudentIds): bool {
                    $sid = (int) ($row['student_id'] ?? 0);
                    if ($sid <= 0 || isset($assignedStudentIds[$sid])) {
                        return false;
                    }
                    $assignedStudentIds[$sid] = true;
                    return true;
                }));

                if (empty($eligibleStudents)) {
                    continue;
                }
                usort($eligibleStudents, static fn(array $a, array $b): int => strcmp((string) ($a['sbd'] ?? ''), (string) ($b['sbd'] ?? '')));

                $roomGroups = $mode === 'by_total_rooms'
                    ? distributeByRoomCount($eligibleStudents, $totalRooms, $remainder)
                    : distributeByMaxStudents($eligibleStudents, $maxStudents, $remainder);

                $roomIndex = 1;
                foreach ($roomGroups as $roomStudents) {
                    $roomName = generateRoomName($subjectCode, $khoi, $scopeIdentifier, $roomIndex);
                    $insertRoom->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi, ':ten' => $roomName, ':scope_identifier' => $scopeIdentifier]);
                    $roomId = (int) $pdo->lastInsertId();

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
                    $roomIndex++;
                }
            }

            $pdo->commit();
            exams_set_flash('success', 'Phân phòng tự động thành công. Đã chuyển sang chế độ tinh chỉnh phòng thi.');
        } elseif ($action === 'move_student') {
            $pdo->beginTransaction();
            moveStudentToRoom($pdo, (int) ($_POST['exam_student_id'] ?? 0), (int) ($_POST['target_room_id'] ?? 0), $examId, $subjectId, $khoi);
            $pdo->commit();
            exams_set_flash('success', 'Đã chuyển thí sinh sang phòng mới.');
        } elseif ($action === 'remove_student') {
            $pdo->beginTransaction();
            removeStudentFromRoom($pdo, (int) ($_POST['exam_student_id'] ?? 0), $examId, $subjectId, $khoi);
            $pdo->commit();
            exams_set_flash('success', 'Đã bỏ phân phòng cho thí sinh.');
        } elseif ($action === 'merge_rooms') {
            $pdo->beginTransaction();
            mergeRooms($pdo, (int) ($_POST['room_a_id'] ?? 0), (int) ($_POST['room_b_id'] ?? 0), $examId, $subjectId, $khoi);
            $pdo->commit();
            exams_set_flash('success', 'Đã gộp phòng thành công.');
        } elseif ($action === 'rename_room') {
            $pdo->beginTransaction();
            renameRoom($pdo, (int) ($_POST['room_id'] ?? 0), (string) ($_POST['new_room_name'] ?? ''), $examId, $subjectId, $khoi);
            $pdo->commit();
            exams_set_flash('success', 'Đã đổi tên phòng.');
        } elseif ($action === 'reset_room_names') {
            $subjectCodeStmt = $pdo->prepare('SELECT ma_mon FROM subjects WHERE id = :id LIMIT 1');
            $subjectCodeStmt->execute([':id' => $subjectId]);
            $subjectCode = (string) ($subjectCodeStmt->fetchColumn() ?: 'SUB');

            $rooms = $pdo->prepare('SELECT id, scope_identifier FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi ORDER BY scope_identifier, id');
            $rooms->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
            $rows = $rooms->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                throw new RuntimeException('Chưa có phòng để đánh số lại.');
            }

            $pdo->beginTransaction();
            $counters = [];
            foreach ($rows as $room) {
                $scope = (string) ($room['scope_identifier'] ?? 'entire_grade');
                $counters[$scope] = ($counters[$scope] ?? 0) + 1;
                renameRoom($pdo, (int) $room['id'], generateRoomName($subjectCode, $khoi, $scope, $counters[$scope]), $examId, $subjectId, $khoi);
            }
            $pdo->commit();
            exams_set_flash('success', 'Đã đánh số lại toàn bộ phòng.');
        } elseif ($action === 'add_student_to_room') {
            $pdo->beginTransaction();
            addStudentToRoom($pdo, $examId, $subjectId, $khoi, (int) ($_POST['target_room_id'] ?? 0), (int) ($_POST['student_id'] ?? 0));
            $pdo->commit();
            exams_set_flash('success', 'Đã thêm thí sinh vào phòng.');
        } elseif ($action === 'assign_unassigned_bulk') {
            $targetRoomId = (int) ($_POST['target_room_id'] ?? 0);
            $ids = $_POST['unassigned_ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                throw new RuntimeException('Vui lòng chọn ít nhất 1 thí sinh chưa phân phòng.');
            }

            $pdo->beginTransaction();
            foreach ($ids as $id) {
                moveStudentToRoom($pdo, (int) $id, $targetRoomId, $examId, $subjectId, $khoi);
            }
            $pdo->commit();
            exams_set_flash('success', 'Đã gán phòng cho các thí sinh đã chọn.');
        } else {
            throw new RuntimeException('Action không hợp lệ.');
        }
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

$rooms = [];
$roomSummary = [];
$assignedStudents = [];
$unassignedStudents = [];
$hasDistribution = false;
if ($examId > 0 && $subjectId > 0 && $khoi !== '') {
    $roomStmt = $pdo->prepare('SELECT id, ten_phong, scope_identifier FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi ORDER BY scope_identifier, ten_phong');
    $roomStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $rooms = $roomStmt->fetchAll(PDO::FETCH_ASSOC);
    $hasDistribution = !empty($rooms);

    $sumStmt = $pdo->prepare('SELECT r.id, r.ten_phong, r.scope_identifier, COUNT(es.id) AS total
        FROM rooms r
        LEFT JOIN exam_students es ON es.room_id = r.id
        WHERE r.exam_id = :exam_id AND r.subject_id = :subject_id AND r.khoi = :khoi
        GROUP BY r.id
        ORDER BY r.scope_identifier, r.ten_phong');
    $sumStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $roomSummary = $sumStmt->fetchAll(PDO::FETCH_ASSOC);

    $stuStmt = $pdo->prepare('SELECT es.id, es.student_id, es.lop, es.sbd, es.room_id, s.hoten, r.ten_phong
        FROM exam_students es
        LEFT JOIN students s ON s.id = es.student_id
        LEFT JOIN rooms r ON r.id = es.room_id
        WHERE es.exam_id = :exam_id AND es.subject_id = :subject_id AND es.khoi = :khoi
        ORDER BY es.lop, es.sbd');
    $stuStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $assignedStudents = $stuStmt->fetchAll(PDO::FETCH_ASSOC);

    $unassignedStudents = getUnassignedStudents($pdo, $examId, $subjectId, $khoi);
}

$availableStudents = [];
if ($examId > 0 && $subjectId > 0 && $khoi !== '') {
    $availStmt = $pdo->prepare('SELECT es.student_id, s.hoten, es.lop, es.sbd
        FROM exam_students es
        INNER JOIN students s ON s.id = es.student_id
        WHERE es.exam_id = :exam_id AND es.subject_id IS NULL AND es.khoi = :khoi
          AND es.student_id NOT IN (SELECT student_id FROM exam_students WHERE exam_id = :exam_id AND subject_id = :subject_id)
        ORDER BY es.lop, es.sbd');
    $availStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $availableStudents = $availStmt->fetchAll(PDO::FETCH_ASSOC);
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
                    <div class="col-md-2 align-self-end"><button class="btn btn-primary w-100" type="submit">Tải dữ liệu</button></div>
                </form>

                <?php if ($examId > 0): ?>
                    <div class="mb-3"><?php foreach ($wizard as $index => $step): ?><span class="badge <?= $step['done'] ? 'bg-success' : 'bg-secondary' ?> me-1">B<?= $index ?>: <?= htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?></div>
                <?php endif; ?>

                <form method="post" class="border rounded p-3 mb-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="auto_distribute">
                    <input type="hidden" name="exam_id" value="<?= $examId ?>">
                    <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
                    <input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label d-block">Distribution mode</label>
                            <div class="form-check"><input class="form-check-input" type="radio" name="distribution_mode" value="by_total_rooms" checked><label class="form-check-label">Mode A — by total room count</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" name="distribution_mode" value="by_max_students"><label class="form-check-label">Mode B — by max students per room</label></div>
                        </div>
                        <div class="col-md-6"><label class="form-label d-block">Remainder option</label>
                            <div class="form-check"><input class="form-check-input" type="radio" name="remainder_option" value="keep_small" checked><label class="form-check-label">Option 1 — last room smaller</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" name="remainder_option" value="redistribute"><label class="form-check-label">Option 2 — redistribute remainder across last rooms</label></div>
                        </div>
                        <div class="col-md-3"><label class="form-label">total_rooms</label><input type="number" min="1" class="form-control" name="total_rooms" value="5"></div>
                        <div class="col-md-3"><label class="form-label">max_students_per_room</label><input type="number" min="1" class="form-control" name="max_students_per_room" value="24"></div>
                        <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="overwrite_existing" value="1" id="overwrite_existing"><label class="form-check-label" for="overwrite_existing">Cho phép ghi đè phân phòng hiện tại của môn/khối này</label></div></div>
                        <div class="col-12"><button class="btn btn-success" type="submit">Phân phòng</button></div>
                    </div>
                </form>

                <?php if ($hasDistribution): ?>
                    <ul class="nav nav-tabs" id="distTabs" role="tablist">
                        <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-rooms" type="button">Danh sách phòng</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-adjust" type="button">Tinh chỉnh phòng</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-unassigned" type="button">Thí sinh chưa phân phòng</button></li>
                    </ul>
                    <div class="tab-content border border-top-0 p-3">
                        <div class="tab-pane fade show active" id="tab-rooms">
                            <div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th>Scope</th><th>Phòng</th><th>Số thí sinh</th></tr></thead><tbody>
                            <?php foreach ($roomSummary as $row): ?>
                                <tr><td><?= htmlspecialchars((string) $row['scope_identifier'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) $row['ten_phong'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['total'] ?></td></tr>
                            <?php endforeach; ?>
                            </tbody></table></div>
                        </div>

                        <div class="tab-pane fade" id="tab-adjust">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <h6>Chuyển / Bỏ phòng thí sinh</h6>
                                    <form method="post" class="row g-2 mb-2">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="move_student">
                                        <div class="col-12"><select class="form-select" name="exam_student_id" required><?php foreach ($assignedStudents as $st): ?><option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars((string)($st['sbd'].' - '.($st['hoten'] ?? 'N/A').' - '.($st['lop'] ?? '').' - '.($st['ten_phong'] ?? 'Chưa có phòng')), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-12"><select class="form-select" name="target_room_id" required><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars((string)($room['ten_phong'].' ['.$room['scope_identifier'].']'), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Chuyển phòng</button></div>
                                    </form>
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="remove_student">
                                        <div class="col-12"><select class="form-select" name="exam_student_id" required><?php foreach ($assignedStudents as $st): ?><option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars((string)($st['sbd'].' - '.($st['hoten'] ?? 'N/A').' - '.($st['lop'] ?? '').' - '.($st['ten_phong'] ?? 'Chưa có phòng')), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-12"><button class="btn btn-outline-danger btn-sm" type="submit">Bỏ khỏi phòng</button></div>
                                    </form>
                                </div>

                                <div class="col-md-6">
                                    <h6>Gộp / Đổi tên phòng</h6>
                                    <form method="post" class="row g-2 mb-2">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="merge_rooms">
                                        <div class="col-6"><select class="form-select" name="room_a_id" required><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars((string) $room['ten_phong'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-6"><select class="form-select" name="room_b_id" required><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars((string) $room['ten_phong'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-12"><button class="btn btn-warning btn-sm" type="submit">Gộp phòng B vào A</button></div>
                                    </form>

                                    <form method="post" class="row g-2 mb-2">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="rename_room">
                                        <div class="col-6"><select class="form-select" name="room_id" required><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars((string) $room['ten_phong'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-6"><input class="form-control" type="text" name="new_room_name" placeholder="Tên phòng mới" required></div>
                                        <div class="col-12"><button class="btn btn-secondary btn-sm" type="submit">Đổi tên phòng</button></div>
                                    </form>

                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="reset_room_names">
                                        <button class="btn btn-outline-primary btn-sm" type="submit">Reset toàn bộ số phòng</button>
                                    </form>
                                </div>

                                <div class="col-12">
                                    <h6>Thêm thí sinh vào phòng</h6>
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="add_student_to_room">
                                        <div class="col-md-7"><select class="form-select" name="student_id" required><?php foreach ($availableStudents as $st): ?><option value="<?= (int) $st['student_id'] ?>"><?= htmlspecialchars((string)($st['sbd'].' - '.$st['hoten'].' - '.$st['lop']), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-md-3"><select class="form-select" name="target_room_id" required><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars((string) $room['ten_phong'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div class="col-md-2"><button class="btn btn-success btn-sm w-100" type="submit">Thêm</button></div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="tab-unassigned">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="assign_unassigned_bulk">
                                <div class="row g-2 mb-2"><div class="col-md-4"><select class="form-select" name="target_room_id" required><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars((string) $room['ten_phong'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div><div class="col-md-3"><button class="btn btn-primary btn-sm" type="submit">Gán phòng cho danh sách chọn</button></div></div>
                                <div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th><input type="checkbox" id="chkAll"></th><th>SBD</th><th>Họ tên</th><th>Lớp</th></tr></thead><tbody>
                                <?php if (empty($unassignedStudents)): ?>
                                    <tr><td colspan="4" class="text-center">Không có thí sinh chưa phân phòng.</td></tr>
                                <?php else: foreach ($unassignedStudents as $st): ?>
                                    <tr><td><input type="checkbox" name="unassigned_ids[]" value="<?= (int) $st['id'] ?>"></td><td><?= htmlspecialchars((string) $st['sbd'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($st['hoten'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) $st['lop'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                                <?php endforeach; endif; ?>
                                </tbody></table></div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">Chưa có dữ liệu phân phòng. Vui lòng chạy "Phân phòng" trước khi tinh chỉnh.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>document.getElementById('chkAll')?.addEventListener('change', function(){document.querySelectorAll('input[name="unassigned_ids[]"]').forEach(cb=>cb.checked=this.checked);});</script>

<?php require_once __DIR__.'/../../layout/footer.php'; ?>
