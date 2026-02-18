<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';

require_once BASE_PATH . '/modules/exams/_common.php';

$roomCols = array_column($pdo->query('PRAGMA table_info(rooms)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('scope_identifier', $roomCols, true)) {
    $pdo->exec('ALTER TABLE rooms ADD COLUMN scope_identifier TEXT DEFAULT "entire_grade"');
}

$examCols = array_column($pdo->query('PRAGMA table_info(exams)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('distribution_locked', $examCols, true)) {
    $pdo->exec('ALTER TABLE exams ADD COLUMN distribution_locked INTEGER DEFAULT 0');
}
if (!in_array('rooms_locked', $examCols, true)) {
    $pdo->exec('ALTER TABLE exams ADD COLUMN rooms_locked INTEGER DEFAULT 0');
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
 * @return array<int, array<string,mixed>>
 */
function getConfiguredScopeGroups(PDO $pdo, int $examId): array
{
    $cfgStmt = $pdo->prepare('SELECT id, subject_id, khoi, scope_mode FROM exam_subject_config WHERE exam_id = :exam_id ORDER BY subject_id, khoi, id');
    $cfgStmt->execute([':exam_id' => $examId]);
    $rows = $cfgStmt->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($rows as $row) {
        $cfgId = (int) ($row['id'] ?? 0);
        $subjectId = (int) ($row['subject_id'] ?? 0);
        $khoi = (string) ($row['khoi'] ?? '');
        $scopeMode = (string) ($row['scope_mode'] ?? 'entire_grade');
        if ($subjectId <= 0 || $khoi === '') {
            continue;
        }

        $classes = [];
        if ($scopeMode === 'specific_classes') {
            $classStmt = $pdo->prepare('SELECT lop FROM exam_subject_classes WHERE exam_config_id = :config_id ORDER BY lop');
            $classStmt->execute([':config_id' => $cfgId]);
            $classes = array_map(static fn(array $r): string => (string) $r['lop'], $classStmt->fetchAll(PDO::FETCH_ASSOC));
            if (empty($classes)) {
                continue;
            }
        }

        $scopeIdentifier = getScopeIdentifier($scopeMode, $classes);
        $key = $subjectId . '|' . $khoi . '|' . $scopeIdentifier;
        $groups[$key] = [
            'subject_id' => $subjectId,
            'khoi' => $khoi,
            'scope_mode' => $scopeMode,
            'scope_identifier' => $scopeIdentifier,
            'classes' => $classes,
        ];
    }

    // If entire_grade exists for a subject+grade, keep only entire_grade for that subject+grade.
    $entireMap = [];
    foreach ($groups as $g) {
        if ($g['scope_mode'] === 'entire_grade') {
            $entireMap[$g['subject_id'] . '|' . $g['khoi']] = true;
        }
    }

    $filtered = [];
    foreach ($groups as $g) {
        $k = $g['subject_id'] . '|' . $g['khoi'];
        if (!empty($entireMap[$k]) && $g['scope_mode'] !== 'entire_grade') {
            continue;
        }
        $filtered[] = $g;
    }

    return $filtered;
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
    $roomStmt = $pdo->prepare('SELECT id FROM rooms WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi LIMIT 1');
    $roomStmt->execute([':id' => $targetRoomId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    if (!$roomStmt->fetch(PDO::FETCH_ASSOC)) {
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
    if ($roomAId <= 0 || $roomBId <= 0 || $roomAId === $roomBId) {
        throw new RuntimeException('Phòng gộp không hợp lệ.');
    }

    $roomStmt = $pdo->prepare('SELECT id, scope_identifier FROM rooms WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi LIMIT 1');
    $roomStmt->execute([':id' => $roomAId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $roomA = $roomStmt->fetch(PDO::FETCH_ASSOC);
    $roomStmt->execute([':id' => $roomBId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $roomB = $roomStmt->fetch(PDO::FETCH_ASSOC);

    if (!$roomA || !$roomB) {
        throw new RuntimeException('Không tìm thấy đủ 2 phòng để gộp.');
    }
    if ((string) $roomA['scope_identifier'] !== (string) $roomB['scope_identifier']) {
        throw new RuntimeException('Không thể gộp phòng khác phạm vi.');
    }

    $move = $pdo->prepare('UPDATE exam_students SET room_id = :room_a WHERE room_id = :room_b AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
    $move->execute([':room_a' => $roomAId, ':room_b' => $roomBId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);

    $del = $pdo->prepare('DELETE FROM rooms WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
    $del->execute([':id' => $roomBId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
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
    if ($up->rowCount() <= 0) {
        throw new RuntimeException('Không thể đổi tên phòng.');
    }
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
        throw new RuntimeException('Học sinh đã tồn tại trong kỳ thi này.');
    }

    $baseStmt = $pdo->prepare('SELECT khoi, lop, sbd FROM exam_students WHERE exam_id = :exam_id AND student_id = :student_id AND subject_id IS NULL LIMIT 1');
    $baseStmt->execute([':exam_id' => $examId, ':student_id' => $studentId]);
    $base = $baseStmt->fetch(PDO::FETCH_ASSOC);

    $studentKhoi = '';
    $studentLop = '';
    $sbd = '';

    if ($base) {
        $studentKhoi = (string) ($base['khoi'] ?? '');
        $studentLop = (string) ($base['lop'] ?? '');
        $sbd = (string) ($base['sbd'] ?? '');
        if ($sbd === '') {
            $sbd = generateNextSBD($pdo, $examId);
            $upBase = $pdo->prepare('UPDATE exam_students SET sbd = :sbd WHERE exam_id = :exam_id AND student_id = :student_id AND subject_id IS NULL AND sbd IS NULL');
            $upBase->execute([':sbd' => $sbd, ':exam_id' => $examId, ':student_id' => $studentId]);
        }
    } else {
        $studentStmt = $pdo->prepare('SELECT lop FROM students WHERE id = :id LIMIT 1');
        $studentStmt->execute([':id' => $studentId]);
        $lop = (string) ($studentStmt->fetchColumn() ?: '');
        if ($lop === '') {
            throw new RuntimeException('Không tìm thấy thông tin thí sinh để thêm.');
        }

        $studentKhoi = detectGradeFromClassName($lop) ?? $khoi;
        $studentLop = $lop;

        $sbd = generateNextSBD($pdo, $examId);

        $insBase = $pdo->prepare('INSERT INTO exam_students (exam_id, student_id, subject_id, khoi, lop, room_id, sbd) VALUES (:exam_id, :student_id, NULL, :khoi, :lop, NULL, :sbd)');
        $insBase->execute([
            ':exam_id' => $examId,
            ':student_id' => $studentId,
            ':khoi' => $studentKhoi,
            ':lop' => $studentLop,
            ':sbd' => $sbd,
        ]);
    }

    if ($studentKhoi !== $khoi) {
        throw new RuntimeException('Thí sinh không thuộc khối đang tinh chỉnh.');
    }

    $insSubject = $pdo->prepare('INSERT INTO exam_students (exam_id, student_id, subject_id, khoi, lop, room_id, sbd) VALUES (:exam_id, :student_id, :subject_id, :khoi, :lop, :room_id, :sbd)');
    $insSubject->execute([
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
    $stmt = $pdo->prepare('SELECT es.id, es.student_id, es.lop, es.sbd, s.hoten
        FROM exam_students es
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
    $scopePart = $scopeIdentifier === 'entire_grade'
        ? 'ALL'
        : substr(strtoupper(preg_replace('/[^A-Z0-9]/', '', $scopeIdentifier) ?: 'SCOPE'), -6);

    return $safeCode . '-' . $khoi . '-' . $scopePart . '-' . str_pad((string) $roomIndex, 2, '0', STR_PAD_LEFT);
}

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
$examModeStmt = $pdo->prepare('SELECT exam_mode FROM exams WHERE id = :id LIMIT 1');
$examModeStmt->execute([':id' => $examId]);
$examMode = (int) ($examModeStmt->fetchColumn() ?: 1);
if (!in_array($examMode, [1, 2], true)) {
    $examMode = 1;
}
exams_debug_log_context($pdo, $examId);
$subjectId = max(0, (int) ($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0));
$khoi = trim((string) ($_GET['khoi'] ?? $_POST['khoi'] ?? ''));
$activeTab = (string) ($_GET['tab'] ?? 'adjust');
if (!in_array($activeTab, ['adjust', 'unassigned'], true)) {
    $activeTab = 'adjust';
}
$adjustView = (string) ($_GET['adjust_view'] ?? 'room');
if (!in_array($adjustView, ['room', 'class'], true)) {
    $adjustView = 'room';
}
$adjustRoomId = max(0, (int) ($_GET['adjust_room_id'] ?? 0));
$adjustClass = trim((string) ($_GET['adjust_class'] ?? ''));
$adjustPerPageOptions = [20, 50, 100];
$adjustPerPage = (int) ($_GET['adjust_per_page'] ?? 20);
if (!in_array($adjustPerPage, $adjustPerPageOptions, true)) {
    $adjustPerPage = 20;
}
$adjustPage = max(1, (int) ($_GET['adjust_page'] ?? 1));

$ctx = $_SESSION['distribution_context'] ?? null;
if ($examId > 0 && is_array($ctx) && (int) ($ctx['exam_id'] ?? 0) === $examId) {
    if ($subjectId <= 0 && !empty($ctx['subject_id'])) {
        $subjectId = (int) $ctx['subject_id'];
    }
    if ($khoi === '' && !empty($ctx['khoi'])) {
        $khoi = (string) $ctx['khoi'];
    }
}

$examLocked = false;
if ($examId > 0) {
    $lockStmt = $pdo->prepare('SELECT distribution_locked, rooms_locked FROM exams WHERE id = :id LIMIT 1');
    $lockStmt->execute([':id' => $examId]);
    $rowLock = $lockStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $examLocked = ((int) ($rowLock['distribution_locked'] ?? 0)) === 1 || ((int) ($rowLock['rooms_locked'] ?? 0)) === 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        exams_set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: ' . BASE_URL . '/modules/exams/distribute_rooms.php');
        exit;
    }

    try {
        exams_guard_write_access($pdo, $examId);
    } catch (Throwable $e) {
        exams_set_flash('error', $e->getMessage());
        header('Location: ' . BASE_URL . '/modules/exams/distribute_rooms.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? 'auto_distribute');
    $redirectParams = ['exam_id' => $examId, 'tab' => $activeTab];

    if ($subjectId > 0) {
        $redirectParams['subject_id'] = $subjectId;
    }
    if ($khoi !== '') {
        $redirectParams['khoi'] = $khoi;
    }
    if ($subjectId > 0 && $khoi !== '') {
        $_SESSION['distribution_context'] = ['exam_id' => $examId, 'subject_id' => $subjectId, 'khoi' => $khoi];
    }

    try {
        if ($examId <= 0) {
            throw new RuntimeException('Vui lòng chọn kỳ thi.');
        }

        if ($action === 'unlock_distribution') {
            if (exams_is_exam_locked($pdo, $examId)) {
                throw new RuntimeException('Kỳ thi đã khoá toàn bộ, không thể mở khoá phân phòng.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE exams SET distribution_locked = 0, rooms_locked = 0 WHERE id = :id')->execute([':id' => $examId]);
            $pdo->commit();
            exams_set_flash('success', 'Đã mở khoá phân phòng.');
        } elseif ($action === 'lock_distribution' || $action === 'lock_rooms') {
            if ($examLocked) {
                throw new RuntimeException('Kỳ thi đã khóa phân phòng trước đó.');
            }

            $pdo->beginTransaction();
            $up = $pdo->prepare('UPDATE exams SET distribution_locked = 1, rooms_locked = 1 WHERE id = :id');
            $up->execute([':id' => $examId]);
            $pdo->commit();
            exams_set_flash('success', 'Đã khóa phân phòng. Chỉ còn thao tác in danh sách phòng.');
        } else {
            if ($examLocked) {
                throw new RuntimeException('Kỳ thi đã khóa phân phòng. Không thể chỉnh sửa thêm.');
            }

            if ($action === 'auto_distribute') {
                $mode = (string) ($_POST['distribution_mode'] ?? 'by_total_rooms');
                $remainder = (string) ($_POST['remainder_option'] ?? REMAINDER_KEEP_SMALL);
                $totalRooms = max(0, (int) ($_POST['total_rooms'] ?? 0));
                $maxStudents = max(0, (int) ($_POST['max_students_per_room'] ?? 0));
                $overwrite = (($_POST['overwrite_existing'] ?? '') === '1');

                if (!in_array($mode, ['by_total_rooms', 'by_max_students'], true)) {
                    throw new RuntimeException('Chế độ phân phòng không hợp lệ.');
                }
                if (!in_array($remainder, [REMAINDER_KEEP_SMALL, REMAINDER_REDISTRIBUTE], true)) {
                    throw new RuntimeException('Tùy chọn xử lý dư không hợp lệ.');
                }
                if ($mode === 'by_total_rooms' && $totalRooms <= 0) {
                    throw new RuntimeException('Số phòng phải > 0.');
                }
                if ($mode === 'by_max_students' && $maxStudents <= 0) {
                    throw new RuntimeException('Số thí sinh tối đa mỗi phòng phải > 0.');
                }

                $baseCountStmt = $pdo->prepare('SELECT COUNT(*) FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NULL');
                $baseCountStmt->execute([':exam_id' => $examId]);
                if ((int) $baseCountStmt->fetchColumn() <= 0) {
                    throw new RuntimeException('Chưa có thí sinh được gán cho kỳ thi.');
                }

                $groups = [];
                if ($examMode === 2) {
                    $groupStmt = $pdo->prepare('SELECT DISTINCT ess.subject_id, es.khoi
                        FROM exam_student_subjects ess
                        INNER JOIN exam_students es ON es.exam_id = ess.exam_id AND es.student_id = ess.student_id AND es.subject_id IS NULL
                        WHERE ess.exam_id = :exam_id AND es.khoi IS NOT NULL AND trim(es.khoi) <> ""
                        ORDER BY ess.subject_id, es.khoi');
                    $groupStmt->execute([':exam_id' => $examId]);
                    foreach ($groupStmt->fetchAll(PDO::FETCH_ASSOC) as $gRow) {
                        $groups[] = [
                            'subject_id' => (int) ($gRow['subject_id'] ?? 0),
                            'khoi' => (string) ($gRow['khoi'] ?? ''),
                            'scope_mode' => 'entire_grade',
                            'scope_identifier' => 'entire_grade',
                            'classes' => [],
                        ];
                    }
                } else {
                    $groups = getConfiguredScopeGroups($pdo, $examId);
                }
                if (empty($groups)) {
                    throw new RuntimeException($examMode === 2
                        ? 'Chưa có dữ liệu ma trận môn để phân phòng (mode 2).'
                        : 'Không có cấu hình môn/khối để phân phòng.');
                }

                $roomCountStmt = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE exam_id = :exam_id');
                $roomCountStmt->execute([':exam_id' => $examId]);
                if ((int) $roomCountStmt->fetchColumn() > 0 && !$overwrite) {
                    throw new RuntimeException('Đã có dữ liệu phân phòng. Vui lòng bật ghi đè để chạy tự động lại.');
                }

                $subjectMapStmt = $pdo->query('SELECT id, ma_mon FROM subjects');
                $subjectCodeMap = [];
                foreach ($subjectMapStmt->fetchAll(PDO::FETCH_ASSOC) as $sub) {
                    $subjectCodeMap[(int) $sub['id']] = (string) $sub['ma_mon'];
                }

                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NOT NULL')->execute([':exam_id' => $examId]);
                $pdo->prepare('DELETE FROM rooms WHERE exam_id = :exam_id')->execute([':exam_id' => $examId]);

                $insertRoom = $pdo->prepare('INSERT INTO rooms (exam_id, subject_id, khoi, ten_phong, scope_identifier) VALUES (:exam_id, :subject_id, :khoi, :ten, :scope_identifier)');
                $insertExamStudent = $pdo->prepare('INSERT INTO exam_students (exam_id, student_id, subject_id, khoi, lop, room_id, sbd) VALUES (:exam_id, :student_id, :subject_id, :khoi, :lop, :room_id, :sbd)');

                $assignedInSubject = [];
                $firstDistributedContext = null;
                foreach ($groups as $g) {
                    $subId = (int) $g['subject_id'];
                    $groupKhoi = (string) $g['khoi'];
                    $scopeMode = (string) $g['scope_mode'];
                    $scopeIdentifier = (string) $g['scope_identifier'];
                    $classes = (array) $g['classes'];

                    if ($examMode === 2) {
                        $eligibleStmt = $pdo->prepare('SELECT DISTINCT es.student_id, es.khoi, es.lop, es.sbd
                            FROM exam_student_subjects ess
                            INNER JOIN exam_students es ON es.exam_id = ess.exam_id AND es.student_id = ess.student_id AND es.subject_id IS NULL
                            WHERE ess.exam_id = :exam_id AND ess.subject_id = :subject_id AND es.khoi = :khoi AND es.sbd IS NOT NULL AND es.sbd <> ""');
                        $eligibleStmt->execute([':exam_id' => $examId, ':subject_id' => $subId, ':khoi' => $groupKhoi]);
                        $eligibleStudents = $eligibleStmt->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $eligibleStudents = getEligibleStudentsByScope($pdo, $examId, $groupKhoi, $scopeMode, $classes);
                    }
                    if (empty($eligibleStudents)) {
                        continue;
                    }
                    if ($firstDistributedContext === null) {
                        $firstDistributedContext = ['exam_id' => $examId, 'subject_id' => $subId, 'khoi' => $groupKhoi];
                    }

                    $eligibleStudents = array_values(array_filter($eligibleStudents, static function (array $row) use (&$assignedInSubject, $subId): bool {
                        $sid = (int) ($row['student_id'] ?? 0);
                        if ($sid <= 0) {
                            return false;
                        }
                        if (isset($assignedInSubject[$subId][$sid])) {
                            return false;
                        }
                        $assignedInSubject[$subId][$sid] = true;
                        return true;
                    }));
                    if (empty($eligibleStudents)) {
                        continue;
                    }

                    usort($eligibleStudents, static fn(array $a, array $b): int => strcmp((string) ($a['sbd'] ?? ''), (string) ($b['sbd'] ?? '')));
                    $roomGroups = $mode === 'by_total_rooms'
                        ? distributeByRoomCount($eligibleStudents, $totalRooms, $remainder)
                        : distributeByMaxStudents($eligibleStudents, $maxStudents, $remainder);

                    $subjectCode = $subjectCodeMap[$subId] ?? 'SUB';
                    $roomIndex = 1;
                    foreach ($roomGroups as $groupStudents) {
                        $roomName = generateRoomName($subjectCode, $groupKhoi, $scopeIdentifier, $roomIndex);
                        $insertRoom->execute([
                            ':exam_id' => $examId,
                            ':subject_id' => $subId,
                            ':khoi' => $groupKhoi,
                            ':ten' => $roomName,
                            ':scope_identifier' => $scopeIdentifier,
                        ]);
                        $roomId = (int) $pdo->lastInsertId();

                        foreach ($groupStudents as $student) {
                            $insertExamStudent->execute([
                                ':exam_id' => $examId,
                                ':student_id' => (int) $student['student_id'],
                                ':subject_id' => $subId,
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
                if ($firstDistributedContext !== null) {
                    $_SESSION['distribution_context'] = $firstDistributedContext;
                    $redirectParams['subject_id'] = (int) $firstDistributedContext['subject_id'];
                    $redirectParams['khoi'] = (string) $firstDistributedContext['khoi'];
                }
                exams_set_flash('success', 'Đã phân phòng tự động cho toàn bộ môn/khối theo cấu hình.');
            } else {
                if ($subjectId <= 0 || $khoi === '') {
                    throw new RuntimeException('Vui lòng chọn môn và khối để tinh chỉnh thủ công.');
                }

                if ($action === 'move_student') {
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

                    $roomsStmt = $pdo->prepare('SELECT id, scope_identifier FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi ORDER BY scope_identifier, id');
                    $roomsStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
                    $rows = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($rows)) {
                        throw new RuntimeException('Chưa có phòng để đánh số lại.');
                    }

                    $pdo->beginTransaction();
                    $scopeCounters = [];
                    foreach ($rows as $room) {
                        $scope = (string) ($room['scope_identifier'] ?? 'entire_grade');
                        $scopeCounters[$scope] = ($scopeCounters[$scope] ?? 0) + 1;
                        renameRoom($pdo, (int) $room['id'], generateRoomName($subjectCode, $khoi, $scope, $scopeCounters[$scope]), $examId, $subjectId, $khoi);
                    }
                    $pdo->commit();
                    exams_set_flash('success', 'Đã reset tên phòng theo thứ tự mới.');
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
                    throw new RuntimeException('Hành động không hợp lệ.');
                }
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        exams_set_flash('error', $e->getMessage());
    }

    header('Location: ' . BASE_URL . '/modules/exams/distribute_rooms.php?' . http_build_query($redirectParams));
    exit;
}

$manualSubjectGrades = [];
if ($examId > 0) {
    $sgStmt = $pdo->prepare('SELECT DISTINCT subject_id, khoi FROM exam_subject_config WHERE exam_id = :exam_id ORDER BY subject_id, khoi');
    $sgStmt->execute([':exam_id' => $examId]);
    $manualSubjectGrades = $sgStmt->fetchAll(PDO::FETCH_ASSOC);
}

$manualSubjects = [];
$manualGrades = [];
$manualGradesBySubject = [];
$allManualGrades = [];
foreach ($manualSubjectGrades as $sg) {
    $sid = (int) ($sg['subject_id'] ?? 0);
    $gr = (string) ($sg['khoi'] ?? '');
    if ($sid > 0) {
        $manualSubjects[$sid] = true;
    }
    if ($sid > 0 && $gr !== '') {
        $manualGradesBySubject[$sid][] = $gr;
        $allManualGrades[$gr] = true;
    }
    if ($subjectId > 0 && $sid === $subjectId && $gr !== '') {
        $manualGrades[$gr] = true;
    }
}
foreach ($manualGradesBySubject as $sid => $grades) {
    $manualGradesBySubject[$sid] = array_values(array_unique($grades));
    sort($manualGradesBySubject[$sid]);
}
if ($subjectId <= 0) {
    $manualGrades = $allManualGrades;
}

// Fallback: derive manual subject/grade pairs from distributed data if config lacks khoi values.
if ($examId > 0) {
    $fallbackStmt = $pdo->prepare('SELECT DISTINCT subject_id, khoi FROM rooms WHERE exam_id = :exam_id AND khoi IS NOT NULL AND trim(khoi) <> "" UNION SELECT DISTINCT subject_id, khoi FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NOT NULL AND khoi IS NOT NULL AND trim(khoi) <> ""');
    $fallbackStmt->execute([':exam_id' => $examId]);
    foreach ($fallbackStmt->fetchAll(PDO::FETCH_ASSOC) as $fb) {
        $sid = (int) ($fb['subject_id'] ?? 0);
        $gr = trim((string) ($fb['khoi'] ?? ''));
        if ($sid <= 0 || $gr === '') {
            continue;
        }
        $manualSubjects[$sid] = true;
        $manualGradesBySubject[$sid] = $manualGradesBySubject[$sid] ?? [];
        if (!in_array($gr, $manualGradesBySubject[$sid], true)) {
            $manualGradesBySubject[$sid][] = $gr;
            sort($manualGradesBySubject[$sid]);
        }
        $allManualGrades[$gr] = true;
        if ($subjectId > 0 && $subjectId === $sid) {
            $manualGrades[$gr] = true;
        }
    }
    if ($subjectId <= 0) {
        $manualGrades = $allManualGrades;
    }
}

$rooms = [];
$roomSummary = [];
$assignedStudents = [];
$unassignedStudents = [];
$availableStudents = [];
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

    $stuStmt = $pdo->prepare('SELECT es.id, es.student_id, es.lop, es.sbd, es.room_id, s.hoten, s.ngaysinh, r.ten_phong
        FROM exam_students es
        LEFT JOIN students s ON s.id = es.student_id
        LEFT JOIN rooms r ON r.id = es.room_id
        WHERE es.exam_id = :exam_id AND es.subject_id = :subject_id AND es.khoi = :khoi
        ORDER BY es.lop, es.sbd');
    $stuStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $assignedStudents = $stuStmt->fetchAll(PDO::FETCH_ASSOC);

    $unassignedStudents = getUnassignedStudents($pdo, $examId, $subjectId, $khoi);

    $availStmt = $pdo->prepare('SELECT es.student_id, s.hoten, es.lop, es.sbd
        FROM exam_students es
        INNER JOIN students s ON s.id = es.student_id
        WHERE es.exam_id = :exam_id
          AND es.subject_id IS NULL
          AND es.khoi = :khoi
          AND es.student_id NOT IN (
              SELECT student_id FROM exam_students WHERE exam_id = :exam_id AND subject_id = :subject_id
          )
        ORDER BY es.lop, es.sbd');
    $availStmt->execute([':exam_id' => $examId, ':khoi' => $khoi, ':subject_id' => $subjectId]);
    $availableStudents = $availStmt->fetchAll(PDO::FETCH_ASSOC);
}

$roomOptionsMap = [];
foreach ($rooms as $room) {
    $roomOptionsMap[(int) ($room['id'] ?? 0)] = (string) ($room['ten_phong'] ?? '');
}
if ($adjustView === 'room' && $adjustRoomId > 0 && !isset($roomOptionsMap[$adjustRoomId])) {
    $adjustRoomId = 0;
}

$classOptions = [];
foreach ($assignedStudents as $st) {
    $lop = trim((string) ($st['lop'] ?? ''));
    if ($lop !== '') {
        $classOptions[$lop] = true;
    }
}
ksort($classOptions);
$classOptions = array_keys($classOptions);

$filteredAssignedStudents = $assignedStudents;
if ($adjustView === 'room') {
    if ($adjustRoomId > 0) {
        $filteredAssignedStudents = array_values(array_filter($filteredAssignedStudents, static fn(array $st): bool => (int) ($st['room_id'] ?? 0) === $adjustRoomId));
    }
} else {
    if ($adjustClass !== '') {
        $filteredAssignedStudents = array_values(array_filter($filteredAssignedStudents, static fn(array $st): bool => (string) ($st['lop'] ?? '') === $adjustClass));
    }
}

$adjustTotalRows = count($filteredAssignedStudents);
$adjustTotalPages = max(1, (int) ceil($adjustTotalRows / max(1, $adjustPerPage)));
if ($adjustPage > $adjustTotalPages) {
    $adjustPage = $adjustTotalPages;
}
$adjustOffset = ($adjustPage - 1) * $adjustPerPage;
$adjustPageRows = array_slice($filteredAssignedStudents, $adjustOffset, $adjustPerPage);


require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Bước 5: Phân phòng thi</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>

                <form method="get" class="row g-2 mb-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label">Kỳ thi</label>
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
                    <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Tải dữ liệu</button></div>
                    <div class="col-md-4 d-flex flex-wrap gap-2 justify-content-md-end">
                        <?php if ($examId > 0 && $examLocked): ?>
                            <span class="badge bg-danger align-self-center">Kỳ thi đã khóa phân phòng</span>
                        <?php elseif ($examId > 0): ?>
                            <span class="badge bg-success align-self-center">Đang mở chỉnh sửa phân phòng</span>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($examId > 0 && $examLocked): ?>
                    <form method="post" class="mb-3 d-flex justify-content-end">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="exam_id" value="<?= $examId ?>">
                        <input type="hidden" name="action" value="unlock_distribution">
                        <button class="btn btn-outline-warning btn-sm" type="submit">Mở khoá phân phòng</button>
                    </form>
                <?php endif; ?>

                <?php if ($examId > 0): ?>
                    <form method="post" class="border rounded p-3 mb-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="auto_distribute">
                        <input type="hidden" name="exam_id" value="<?= $examId ?>">
                                <input type="hidden" name="tab" value="adjust">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label d-block">Chế độ phân phòng tự động</label>
                                <div class="form-check"><input class="form-check-input" type="radio" name="distribution_mode" value="by_total_rooms" checked><label class="form-check-label">Theo tổng số phòng</label></div>
                                <div class="form-check"><input class="form-check-input" type="radio" name="distribution_mode" value="by_max_students"><label class="form-check-label">Theo sĩ số tối đa mỗi phòng</label></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label d-block">Xử lý phần dư</label>
                                <div class="form-check"><input class="form-check-input" type="radio" name="remainder_option" value="keep_small" checked><label class="form-check-label">Giữ phòng cuối nhỏ hơn</label></div>
                                <div class="form-check"><input class="form-check-input" type="radio" name="remainder_option" value="redistribute"><label class="form-check-label">Phân bổ lại phần dư</label></div>
                            </div>
                            <div class="col-md-3"><label class="form-label">Tổng số phòng / nhóm</label><input class="form-control" type="number" min="1" name="total_rooms" value="5"></div>
                            <div class="col-md-3"><label class="form-label">Sĩ số tối đa / phòng</label><input class="form-control" type="number" min="1" name="max_students_per_room" value="24"></div>
                            <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="overwrite_existing" value="1" id="overwrite_existing"><label class="form-check-label" for="overwrite_existing">Cho phép ghi đè toàn bộ phân phòng của kỳ thi này</label></div><div id="overwriteWarningText" class="small text-danger mt-1 d-none">Cảnh báo: hệ thống sẽ xóa toàn bộ phòng và dữ liệu gán phòng hiện tại của kỳ thi này trước khi phân phòng lại.</div></div>
                            <div class="col-12 d-flex gap-2">
                                <button class="btn btn-success" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Phân phòng tự động</button>
                            </div>
                        </div>
                    </form>

                    <?php if (!$examLocked): ?>
                        <form method="post" class="mb-3 d-flex justify-content-end">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="lock_rooms">
                            <input type="hidden" name="exam_id" value="<?= $examId ?>">
                            <button class="btn btn-outline-danger" type="submit">Khoá phân phòng</button>
                        </form>
                    <?php endif; ?>

                    <?php
                        $canAdjust = false;
                        if ($examId > 0 && $subjectId > 0 && $khoi !== '') {
                            $checkRoomsStmt = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
                            $checkRoomsStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
                            $canAdjust = ((int) $checkRoomsStmt->fetchColumn()) > 0;
                        }
                    ?>
                    <?php if ($canAdjust): ?>
                        <form method="get" class="mb-3 d-flex justify-content-end gap-2">
                            <input type="hidden" name="exam_id" value="<?= $examId ?>">
                            <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
                            <input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="tab" value="adjust">
                            <button class="btn btn-primary" type="submit">Tinh chỉnh phòng thi</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-secondary py-2 mb-3">Phân phòng xong sẽ mở đầy đủ công cụ tinh chỉnh theo môn/khối.</div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($examId > 0): ?>
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item"><button class="nav-link <?= $activeTab === 'adjust' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-adjust" type="button">Tinh chỉnh theo môn</button></li>
                        <li class="nav-item"><button class="nav-link <?= $activeTab === 'unassigned' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-unassigned" type="button">Chưa phân phòng</button></li>
                    </ul>

                    <div class="tab-content border border-top-0 p-3">
                        <div class="tab-pane fade <?= $activeTab === 'adjust' ? 'show active' : '' ?>" id="tab-adjust">
                            <form method="get" class="row g-2 mb-3">
                                <div class="col-12"><small class="text-muted">Chọn môn, nhập khối và bấm <strong>Tinh chỉnh</strong> để mở các chức năng tinh chỉnh phòng thi.</small></div>
                                <input type="hidden" name="exam_id" value="<?= $examId ?>">
                                <div class="col-md-7">
                                    <label class="form-label">Môn (tinh chỉnh)</label>
                                    <select name="subject_id" id="manualSubjectSelect" class="form-select" required>
                                        <option value="">-- Chọn môn --</option>
                                        <?php foreach ($subjects as $s): ?>
                                            <?php if (!isset($manualSubjects[(int) $s['id']])) { continue; } ?>
                                            <option value="<?= (int) $s['id'] ?>" <?= $subjectId === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $s['ma_mon'] . ' - ' . (string) $s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Khối (tinh chỉnh)</label>
                                    <input name="khoi" id="manualKhoiSelect" class="form-control" list="manualKhoiOptions" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>" placeholder="Nhập khối, ví dụ: 10" required>
                                    <datalist id="manualKhoiOptions">
                                        <?php foreach (array_keys($manualGrades) as $g): ?>
                                            <option value="<?= htmlspecialchars($g, ENT_QUOTES, 'UTF-8') ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div class="col-12 d-grid d-md-flex justify-content-md-end">
                                    <button class="btn btn-primary px-4" type="submit">Tinh chỉnh phòng thi</button>
                                </div>
                            </form>

                            <?php if ($subjectId > 0 && $khoi !== ''): ?>
                                <div class="alert alert-success py-2">Đã vào chế độ tinh chỉnh cho môn và khối đã chọn. Dùng các nút chức năng bên dưới để thực hiện tinh chỉnh phân phòng.</div>

                                <?php if ($hasDistribution): ?>
                                    <div class="card border-0 bg-light mb-3">
                                        <div class="card-body">
                                            <form method="get" class="row g-2 align-items-end">
                                                <input type="hidden" name="exam_id" value="<?= $examId ?>">
                                                <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
                                                <input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="tab" value="adjust">
                                                <div class="col-md-3">
                                                    <label class="form-label">Chế độ xem</label>
                                                    <select class="form-select" name="adjust_view" id="adjustViewSelect">
                                                        <option value="room" <?= $adjustView === 'room' ? 'selected' : '' ?>>Theo phòng thi</option>
                                                        <option value="class" <?= $adjustView === 'class' ? 'selected' : '' ?>>Theo lớp</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-3" id="adjustRoomFilterWrap" <?= $adjustView === 'room' ? '' : 'style="display:none;"' ?>>
                                                    <label class="form-label">Phòng thi</label>
                                                    <select class="form-select" name="adjust_room_id">
                                                        <option value="0">-- Tất cả phòng --</option>
                                                        <?php foreach ($rooms as $room): ?>
                                                            <option value="<?= (int) $room['id'] ?>" <?= $adjustRoomId === (int) $room['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $room['ten_phong'], ENT_QUOTES, 'UTF-8') ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3" id="adjustClassFilterWrap" <?= $adjustView === 'class' ? '' : 'style="display:none;"' ?>>
                                                    <label class="form-label">Lớp</label>
                                                    <select class="form-select" name="adjust_class">
                                                        <option value="">-- Tất cả lớp --</option>
                                                        <?php foreach ($classOptions as $lop): ?>
                                                            <option value="<?= htmlspecialchars($lop, ENT_QUOTES, 'UTF-8') ?>" <?= $adjustClass === $lop ? 'selected' : '' ?>><?= htmlspecialchars($lop, ENT_QUOTES, 'UTF-8') ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">Số dòng/trang</label>
                                                    <select class="form-select" name="adjust_per_page">
                                                        <?php foreach ($adjustPerPageOptions as $opt): ?>
                                                            <option value="<?= $opt ?>" <?= $adjustPerPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4 d-flex gap-2">
                                                    <button class="btn btn-primary" type="submit">Lọc danh sách</button>
                                                    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/modules/exams/distribute_rooms.php?<?= http_build_query(['exam_id' => $examId, 'subject_id' => $subjectId, 'khoi' => $khoi, 'tab' => 'adjust']) ?>">Bỏ lọc</a>
                                                </div>
                                            </form>
                                        </div>
                                    </div>

                                    <div class="table-responsive mb-2"><table class="table table-bordered table-sm"><thead><tr><th>STT</th><th>SBD</th><th>Họ tên</th><th>Ngày sinh</th><th>Lớp</th></tr></thead><tbody>
                                        <?php if (empty($adjustPageRows)): ?>
                                            <tr><td colspan="5" class="text-center">Không có thí sinh phù hợp bộ lọc.</td></tr>
                                        <?php else: foreach ($adjustPageRows as $idx => $st): ?>
                                            <tr>
                                                <td><?= $adjustOffset + $idx + 1 ?></td>
                                                <td><?= htmlspecialchars((string) ($st['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) ($st['hoten'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) ($st['ngaysinh'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) ($st['lop'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody></table></div>

                                    <?php if ($adjustTotalPages > 1): ?>
                                        <?php
                                            $adjustLink = static fn(int $targetPage): string => BASE_URL . '/modules/exams/distribute_rooms.php?' . http_build_query([
                                                'exam_id' => $examId,
                                                'subject_id' => $subjectId,
                                                'khoi' => $khoi,
                                                'tab' => 'adjust',
                                                'adjust_view' => $adjustView,
                                                'adjust_room_id' => $adjustRoomId,
                                                'adjust_class' => $adjustClass,
                                                'adjust_per_page' => $adjustPerPage,
                                                'adjust_page' => $targetPage,
                                            ]);
                                        ?>
                                        <nav class="mb-3">
                                            <ul class="pagination pagination-sm flex-wrap">
                                                <li class="page-item <?= $adjustPage <= 1 ? 'disabled' : '' ?>"><?= $adjustPage <= 1 ? '<span class="page-link">Trang trước</span>' : '<a class="page-link" href="' . htmlspecialchars($adjustLink($adjustPage - 1), ENT_QUOTES, 'UTF-8') . '">Trang trước</a>' ?></li>
                                                <?php for ($p = max(1, $adjustPage - 5); $p <= min($adjustTotalPages, $adjustPage + 5); $p++): ?>
                                                    <li class="page-item <?= $p === $adjustPage ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($adjustLink($p), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a></li>
                                                <?php endfor; ?>
                                                <li class="page-item <?= $adjustPage >= $adjustTotalPages ? 'disabled' : '' ?>"><?= $adjustPage >= $adjustTotalPages ? '<span class="page-link">Trang sau</span>' : '<a class="page-link" href="' . htmlspecialchars($adjustLink($adjustPage + 1), ENT_QUOTES, 'UTF-8') . '">Trang sau</a>' ?></li>
                                            </ul>
                                        </nav>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <div class="card border-info mb-3">
                                    <div class="card-header bg-info-subtle"><strong>Mô tả các chức năng tinh chỉnh phòng thi</strong></div>
                                    <div class="card-body py-2">
                                        <ul class="mb-0 ps-3">
                                            <li><strong>Chuyển phòng:</strong> chuyển thí sinh từ phòng hiện tại sang phòng đích.</li>
                                            <li><strong>Bỏ khỏi phòng:</strong> đưa thí sinh về trạng thái chưa phân phòng.</li>
                                            <li><strong>Gộp / Đổi tên / Reset tên phòng:</strong> quản lý cấu trúc và tên phòng thi.</li>
                                            <li><strong>Thêm thí sinh vào phòng:</strong> thêm thí sinh cùng khối vào phòng phù hợp.</li>
                                        </ul>
                                    </div>
                                </div>
                                <?php if ($hasDistribution): ?>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <h6>Chuyển / Bỏ phòng thí sinh</h6>
                                            <form method="post" class="row g-2 mb-2">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="adjust"><input type="hidden" name="action" value="move_student">
                                                <div class="col-12"><select class="form-select" name="exam_student_id" required><?php foreach ($assignedStudents as $st): ?><option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars((string) (($st['sbd'] ?? '') . ' - ' . ($st['hoten'] ?? 'N/A') . ' - ' . ($st['lop'] ?? '') . ' - ' . ($st['ten_phong'] ?? 'Chưa phòng')), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                                <div class="col-12"><select class="form-select" name="target_room_id" required><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars((string) ($room['ten_phong'] . ' [' . $room['scope_identifier'] . ']'), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                                <div class="col-12"><button class="btn btn-primary btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Chuyển phòng</button></div>
                                            </form>

                                            <form method="post" class="row g-2">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="adjust"><input type="hidden" name="action" value="remove_student">
                                                <div class="col-12"><select class="form-select" name="exam_student_id" required><?php foreach ($assignedStudents as $st): ?><option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars((string) (($st['sbd'] ?? '') . ' - ' . ($st['hoten'] ?? 'N/A') . ' - ' . ($st['lop'] ?? '') . ' - ' . ($st['ten_phong'] ?? 'Chưa phòng')), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                                <div class="col-12"><button class="btn btn-outline-danger btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Bỏ khỏi phòng</button></div>
                                            </form>
                                        </div>

                                        <div class="col-md-6">
                                            <h6>Gộp / Đổi tên phòng</h6>
                                            <form method="post" class="row g-2 mb-2">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="adjust"><input type="hidden" name="action" value="merge_rooms">
                                                <div class="col-6"><select class="form-select" name="room_a_id" required><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars((string) $room['ten_phong'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                                <div class="col-6"><select class="form-select" name="room_b_id" required><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars((string) $room['ten_phong'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                                <div class="col-12"><button class="btn btn-warning btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Gộp B vào A</button></div>
                                            </form>

                                            <form method="post" class="row g-2 mb-2">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="adjust"><input type="hidden" name="action" value="rename_room">
                                                <div class="col-6"><select class="form-select" name="room_id" required><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars((string) $room['ten_phong'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                                <div class="col-6"><input class="form-control" type="text" name="new_room_name" placeholder="Tên phòng mới" required></div>
                                                <div class="col-12"><button class="btn btn-secondary btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Đổi tên phòng</button></div>
                                            </form>

                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="adjust"><input type="hidden" name="action" value="reset_room_names">
                                                <button class="btn btn-outline-primary btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Reset tên phòng</button>
                                            </form>
                                        </div>

                                        <div class="col-12">
                                            <h6>Thêm thí sinh vào phòng</h6>
                                            <form method="post" class="row g-2">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="adjust"><input type="hidden" name="action" value="add_student_to_room">
                                                <div class="col-md-7"><select class="form-select" name="student_id" required><?php foreach ($availableStudents as $st): ?><option value="<?= (int) $st['student_id'] ?>"><?= htmlspecialchars((string) (($st['sbd'] ?? '') . ' - ' . ($st['hoten'] ?? 'N/A') . ' - ' . ($st['lop'] ?? '')), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                                <div class="col-md-3"><select class="form-select" name="target_room_id" required><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars((string) $room['ten_phong'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                                <div class="col-md-2"><button class="btn btn-success btn-sm w-100" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Thêm</button></div>
                                            </form>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php $suggestGrades = $manualGradesBySubject[$subjectId] ?? []; ?>
                                    <div class="alert alert-warning mb-2">Chưa có phòng cho môn/khối đang chọn. Hãy chạy “Phân phòng tự động”.</div>
                                    <?php if (!empty($suggestGrades)): ?>
                                        <div class="alert alert-secondary py-2 mb-0">Khối khả dụng cho môn này: <strong><?= htmlspecialchars(implode(', ', $suggestGrades), ENT_QUOTES, 'UTF-8') ?></strong>. Vui lòng chọn đúng khối để xem/điều chỉnh.</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="alert alert-info">Chọn môn + khối để vào chế độ tinh chỉnh.</div>
                            <?php endif; ?>
                        </div>

                        <div class="tab-pane fade <?= $activeTab === 'unassigned' ? 'show active' : '' ?>" id="tab-unassigned">
                            <?php if ($subjectId > 0 && $khoi !== ''): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="exam_id" value="<?= $examId ?>"><input type="hidden" name="subject_id" value="<?= $subjectId ?>"><input type="hidden" name="khoi" value="<?= htmlspecialchars($khoi, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="unassigned"><input type="hidden" name="action" value="assign_unassigned_bulk">
                                    <div class="row g-2 mb-2"><div class="col-md-4"><select class="form-select" name="target_room_id" required><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>"><?= htmlspecialchars((string) $room['ten_phong'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div><div class="col-md-3"><button class="btn btn-primary btn-sm" type="submit" <?= $examLocked ? 'disabled' : '' ?>>Gán phòng cho danh sách chọn</button></div></div>
                                    <div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th><input type="checkbox" id="chkAll"></th><th>SBD</th><th>Họ tên</th><th>Lớp</th></tr></thead><tbody>
                                    <?php if (empty($unassignedStudents)): ?>
                                        <tr><td colspan="4" class="text-center">Không có thí sinh chưa phân phòng.</td></tr>
                                    <?php else: foreach ($unassignedStudents as $st): ?>
                                        <tr><td><input type="checkbox" name="unassigned_ids[]" value="<?= (int) $st['id'] ?>"></td><td><?= htmlspecialchars((string) ($st['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($st['hoten'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($st['lop'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                                    <?php endforeach; endif; ?>
                                    </tbody></table></div>
                                </form>
                            <?php else: ?>
                                <div class="alert alert-info">Chọn môn + khối để xem thí sinh chưa phân phòng.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('chkAll')?.addEventListener('change', function () {
    document.querySelectorAll('input[name="unassigned_ids[]"]').forEach(cb => cb.checked = this.checked);
});

const manualGradesBySubject = <?= json_encode($manualGradesBySubject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const allManualGrades = <?= json_encode(array_values(array_keys($allManualGrades)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const manualSubjectSelect = document.getElementById('manualSubjectSelect');
const manualKhoiSelect = document.getElementById('manualKhoiSelect');

function refreshManualKhoiOptions() {
    const manualKhoiOptions = document.getElementById('manualKhoiOptions');
    if (!manualKhoiOptions) return;

    const selectedSubject = manualSubjectSelect ? parseInt(manualSubjectSelect.value || '0', 10) : 0;
    const options = selectedSubject > 0
        ? (manualGradesBySubject[selectedSubject] || [])
        : allManualGrades;

    manualKhoiOptions.innerHTML = '';
    options.forEach((grade) => {
        const op = document.createElement('option');
        op.value = grade;
        manualKhoiOptions.appendChild(op);
    });
}

manualSubjectSelect?.addEventListener('change', refreshManualKhoiOptions);
refreshManualKhoiOptions();


const adjustViewSelect = document.getElementById('adjustViewSelect');
const adjustRoomFilterWrap = document.getElementById('adjustRoomFilterWrap');
const adjustClassFilterWrap = document.getElementById('adjustClassFilterWrap');

function refreshAdjustViewFilters() {
    if (!adjustViewSelect) return;
    const isRoomView = adjustViewSelect.value === 'room';
    if (adjustRoomFilterWrap) {
        adjustRoomFilterWrap.style.display = isRoomView ? '' : 'none';
    }
    if (adjustClassFilterWrap) {
        adjustClassFilterWrap.style.display = isRoomView ? 'none' : '';
    }
}

adjustViewSelect?.addEventListener('change', refreshAdjustViewFilters);
refreshAdjustViewFilters();

const overwriteCheckbox = document.getElementById('overwrite_existing');
const overwriteWarningText = document.getElementById('overwriteWarningText');
const confirmOverwriteMessage = 'Bạn đang chọn ghi đè toàn bộ phân phòng của kỳ thi này. Hệ thống sẽ xóa toàn bộ dữ liệu phòng và gán phòng hiện có trước khi phân phòng lại. Bạn có chắc chắn tiếp tục?';
overwriteCheckbox?.addEventListener('change', function () {
    if (overwriteWarningText) {
        overwriteWarningText.classList.toggle('d-none', !this.checked);
    }
});

document.querySelectorAll('form').forEach((f) => {
    f.addEventListener('submit', (e) => {
        const cb = f.querySelector('#overwrite_existing');
        if (cb && cb.checked) {
            if (!window.confirm(confirmOverwriteMessage)) {
                e.preventDefault();
            }
        }
    });
});

</script>

<?php require_once BASE_PATH . '/layout/footer.php'; ?>
