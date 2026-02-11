<?php
declare(strict_types=1);

require_once __DIR__.'/../../core/auth.php';
require_login();
require_role(['admin', 'organizer']);
require_once __DIR__.'/../../core/db.php';

const EXAM_ALLOWED_ROLES = ['admin', 'organizer'];
const REMAINDER_KEEP_SMALL = 'keep_small';
const REMAINDER_REDISTRIBUTE = 'redistribute';

function exams_init_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS exam_students (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        exam_id INTEGER NOT NULL,
        student_id INTEGER NOT NULL,
        subject_id INTEGER,
        khoi TEXT,
        lop TEXT,
        room_id INTEGER,
        sbd TEXT,
        UNIQUE(exam_id, student_id, subject_id)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS rooms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        exam_id INTEGER NOT NULL,
        subject_id INTEGER NOT NULL,
        khoi TEXT NOT NULL,
        ten_phong TEXT NOT NULL
    )');

    // Legacy table kept for compatibility (old versions may still read it)
    $pdo->exec('CREATE TABLE IF NOT EXISTS exam_subject_configs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        exam_id INTEGER NOT NULL,
        grade TEXT NOT NULL,
        subject_id INTEGER NOT NULL,
        mark_type TEXT,
        duration INTEGER,
        session TEXT,
        coefficient REAL,
        UNIQUE(exam_id, grade, subject_id)
    )');

    // New enhanced configuration table
    $pdo->exec('CREATE TABLE IF NOT EXISTS exam_subject_config (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        exam_id INTEGER NOT NULL,
        subject_id INTEGER NOT NULL,
        khoi TEXT NOT NULL,
        hinh_thuc_thi TEXT NOT NULL,
        component_count INTEGER NOT NULL,
        weight_1 REAL,
        weight_2 REAL,
        scope_mode TEXT NOT NULL DEFAULT "entire_grade",
        UNIQUE(exam_id, subject_id, khoi)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS exam_subject_classes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        exam_id INTEGER NOT NULL,
        subject_id INTEGER NOT NULL,
        khoi TEXT NOT NULL,
        lop TEXT NOT NULL,
        UNIQUE(exam_id, subject_id, khoi, lop)
    )');

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_exam_sbd_unique ON exam_students(exam_id, sbd)');
}

exams_init_schema($pdo);

function exams_get_csrf_token(): string
{
    if (empty($_SESSION['exam_csrf'])) {
        $_SESSION['exam_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['exam_csrf'];
}

function exams_verify_csrf(?string $token): bool
{
    $session = $_SESSION['exam_csrf'] ?? '';
    return is_string($token) && is_string($session) && $session !== '' && hash_equals($session, $token);
}

function exams_set_flash(string $type, string $message): void
{
    $_SESSION['exam_flash'] = ['type' => $type, 'message' => $message];
}

function exams_display_flash(): string
{
    $flash = $_SESSION['exam_flash'] ?? null;
    unset($_SESSION['exam_flash']);
    if (!is_array($flash)) {
        return '';
    }

    $map = [
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        'info' => 'alert-info',
    ];
    $class = $map[$flash['type'] ?? 'info'] ?? 'alert-info';

    return '<div class="alert ' . $class . '">' . htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>';
}

function detectGradeFromClassName(string $className): ?string
{
    if (preg_match('/(\d+)/', $className, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

function generateExamSBD(int $examId, string $grade, int $runningNumber): string
{
    $gradePart = str_pad(preg_replace('/\D/', '', $grade) ?: '0', 2, '0', STR_PAD_LEFT);
    $runPart = str_pad((string) $runningNumber, 4, '0', STR_PAD_LEFT);

    return $examId . $gradePart . $runPart;
}

/**
 * @param array<int, array<string, mixed>> $students
 * @return array<int, array<int, array<string, mixed>>>
 */
function distributeStudentsByRoomCount(array $students, int $roomCount, string $remainderMode = REMAINDER_KEEP_SMALL): array
{
    if ($roomCount <= 0) {
        throw new InvalidArgumentException('Số phòng phải > 0');
    }

    $total = count($students);
    if ($total === 0) {
        return [];
    }

    $roomCount = min($roomCount, $total);
    $base = intdiv($total, $roomCount);
    $remainder = $total % $roomCount;

    $sizes = array_fill(0, $roomCount, $base);

    if ($remainder > 0) {
        if ($remainderMode === REMAINDER_REDISTRIBUTE) {
            for ($i = $roomCount - $remainder; $i < $roomCount; $i++) {
                $sizes[$i]++;
            }
        } else {
            for ($i = 0; $i < $remainder; $i++) {
                $sizes[$i]++;
            }
        }
    }

    $rooms = [];
    $offset = 0;
    foreach ($sizes as $size) {
        $rooms[] = array_slice($students, $offset, $size);
        $offset += $size;
    }

    return $rooms;
}

/**
 * @param array<int, array<string, mixed>> $students
 * @return array<int, array<int, array<string, mixed>>>
 */
function distributeStudentsByRoomSize(array $students, int $roomSize, string $remainderMode = REMAINDER_KEEP_SMALL): array
{
    if ($roomSize <= 0) {
        throw new InvalidArgumentException('Sĩ số phòng phải > 0');
    }

    $total = count($students);
    if ($total === 0) {
        return [];
    }

    if ($remainderMode === REMAINDER_KEEP_SMALL) {
        return array_chunk($students, $roomSize);
    }

    $roomCount = (int) ceil($total / $roomSize);
    return distributeStudentsByRoomCount($students, $roomCount, REMAINDER_REDISTRIBUTE);
}

/**
 * @return array<int, array<string,mixed>>
 */
function exams_get_all_exams(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, ten_ky_thi, nam, ngay_thi FROM exams ORDER BY id DESC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array<int, string>
 */
function getClassesByGrade(PDO $pdo, int $examId, string $khoi): array
{
    $stmt = $pdo->prepare('SELECT DISTINCT lop FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NULL AND khoi = :khoi AND lop IS NOT NULL AND lop <> "" ORDER BY lop');
    $stmt->execute([':exam_id' => $examId, ':khoi' => $khoi]);
    return array_map(static fn(array $row): string => (string) $row['lop'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * @return array<int, array<string,mixed>>
 */
function getStudentsForSubjectScope(PDO $pdo, int $examId, int $subjectId, string $khoi): array
{
    $cfgStmt = $pdo->prepare('SELECT scope_mode FROM exam_subject_config WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi LIMIT 1');
    $cfgStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
    $cfg = $cfgStmt->fetch(PDO::FETCH_ASSOC);

    if (!$cfg) {
        return [];
    }

    $scopeMode = (string) ($cfg['scope_mode'] ?? 'entire_grade');

    if ($scopeMode === 'specific_classes') {
        $classStmt = $pdo->prepare('SELECT lop FROM exam_subject_classes WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
        $classStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
        $classes = array_map(static fn(array $r): string => (string) $r['lop'], $classStmt->fetchAll(PDO::FETCH_ASSOC));

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

    $stmt = $pdo->prepare('SELECT student_id, khoi, lop, sbd FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NULL AND khoi = :khoi AND sbd IS NOT NULL AND sbd <> ""');
    $stmt->execute([':exam_id' => $examId, ':khoi' => $khoi]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function exams_wizard_steps(PDO $pdo, int $examId): array
{
    $countStudents = (int) $pdo->query('SELECT COUNT(*) FROM exam_students WHERE exam_id = ' . $examId . ' AND subject_id IS NULL')->fetchColumn();
    $countSbd = (int) $pdo->query('SELECT COUNT(*) FROM exam_students WHERE exam_id = ' . $examId . ' AND subject_id IS NULL AND sbd IS NOT NULL AND sbd <> ""')->fetchColumn();
    $countConfigs = (int) $pdo->query('SELECT COUNT(*) FROM exam_subject_config WHERE exam_id = ' . $examId)->fetchColumn();
    $countRooms = (int) $pdo->query('SELECT COUNT(*) FROM rooms WHERE exam_id = ' . $examId)->fetchColumn();

    return [
        1 => ['label' => 'Tạo kỳ thi', 'done' => true, 'url' => '/index.php'],
        2 => ['label' => 'Gán học sinh', 'done' => $countStudents > 0],
        3 => ['label' => 'Sinh SBD', 'done' => $countStudents > 0 && $countSbd === $countStudents],
        4 => ['label' => 'Cấu hình môn theo khối', 'done' => $countConfigs > 0],
        5 => ['label' => 'Phân phòng', 'done' => $countRooms > 0],
        6 => ['label' => 'In danh sách phòng', 'done' => $countRooms > 0],
    ];
}
