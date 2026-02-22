<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once BASE_PATH . '/core/db.php';

function student_portal_init_schema(PDO $pdo): void
{
    $examCols = array_column($pdo->query('PRAGMA table_info(exams)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('is_locked', $examCols, true)) {
        $pdo->exec('ALTER TABLE exams ADD COLUMN is_locked INTEGER DEFAULT 0');
    }
    if (!in_array('is_score_entry_locked', $examCols, true)) {
        $pdo->exec('ALTER TABLE exams ADD COLUMN is_score_entry_locked INTEGER DEFAULT 0');
    }
    if (!in_array('is_score_published', $examCols, true)) {
        $pdo->exec('ALTER TABLE exams ADD COLUMN is_score_published INTEGER DEFAULT 0');
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS student_recheck_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        exam_id INTEGER NOT NULL,
        student_id INTEGER NOT NULL,
        subject_id INTEGER NOT NULL,
        room_id INTEGER,
        component_1 REAL,
        component_2 REAL,
        component_3 REAL,
        note TEXT,
        status TEXT DEFAULT "pending",
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(exam_id, student_id, subject_id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_recheck_exam_subject_room ON student_recheck_requests(exam_id, subject_id, room_id)');

}

function student_portal_default_exam(PDO $pdo): ?array
{
    $stmt = $pdo->query('SELECT * FROM exams WHERE COALESCE(is_default,0)=1 ORDER BY id DESC LIMIT 1');
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($exam) {
        return $exam;
    }

    $stmt = $pdo->query('SELECT * FROM exams ORDER BY id DESC LIMIT 1');
    $fallback = $stmt->fetch(PDO::FETCH_ASSOC);
    return $fallback ?: null;
}

function student_require_login(): void
{
    if (!isset($_SESSION['student_id'])) {
        header('Location: ' . BASE_URL . '/student_portal/login.php');
        exit;
    }
}

function student_portal_csrf_token(): string
{
    if (empty($_SESSION['student_portal_csrf'])) {
        $_SESSION['student_portal_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['student_portal_csrf'];
}

function student_portal_verify_csrf(?string $token): bool
{
    return is_string($token) && isset($_SESSION['student_portal_csrf']) && hash_equals($_SESSION['student_portal_csrf'], $token);
}


function student_portal_get_exam(PDO $pdo, int $examId): ?array
{
    if ($examId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM exams WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $examId]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);

    return $exam ?: null;
}

function student_portal_can_register_subjects(array $exam): bool
{
    $isLocked = (int) ($exam['is_locked'] ?? 0) === 1;
    $distributionLocked = (int) ($exam['distribution_locked'] ?? 0) === 1;
    $roomsLocked = (int) ($exam['rooms_locked'] ?? 0) === 1;
    $examLocked = (int) ($exam['exam_locked'] ?? 0) === 1;

    return !$isLocked && !$distributionLocked && !$roomsLocked && !$examLocked;
}

function student_portal_can_view_rooms(array $exam): bool
{
    return (int) ($exam['is_locked'] ?? 0) === 1
        || (int) ($exam['distribution_locked'] ?? 0) === 1
        || (int) ($exam['rooms_locked'] ?? 0) === 1
        || (int) ($exam['exam_locked'] ?? 0) === 1;
}

function student_portal_can_view_scores(array $exam): bool
{
    $published = (int) ($exam['is_score_published'] ?? 0) === 1;
    $scoreEntryLocked = (int) ($exam['is_score_entry_locked'] ?? 0) === 1
        || (int) ($exam['scoring_closed'] ?? 0) === 1
        || (int) ($exam['exam_locked'] ?? 0) === 1;

    return $published && $scoreEntryLocked;
}


function student_portal_format_date(string $date): string
{
    $date = trim($date);
    if ($date === '') {
        return '';
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if ($dt instanceof DateTime) {
        return $dt->format('d/m/Y');
    }

    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $date;
}

function student_portal_student_profile(PDO $pdo, int $studentId): array
{
    if ($studentId <= 0) {
        return ['ngaysinh' => '', 'lop' => ''];
    }

    $stmt = $pdo->prepare('SELECT COALESCE(ngaysinh, "") AS ngaysinh, COALESCE(lop, "") AS lop FROM students WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['ngaysinh' => '', 'lop' => ''];

    return [
        'ngaysinh' => (string) ($row['ngaysinh'] ?? ''),
        'lop' => (string) ($row['lop'] ?? ''),
    ];
}

function student_portal_student(): array
{
    return [
        'id' => (int) ($_SESSION['student_id'] ?? 0),
        'name' => (string) ($_SESSION['student_name'] ?? ''),
        'identifier' => (string) ($_SESSION['student_identifier'] ?? ''),
        'exam_id' => (int) ($_SESSION['student_exam_default'] ?? 0),
        'ngaysinh' => (string) ($_SESSION['student_birthdate'] ?? ''),
        'lop' => (string) ($_SESSION['student_class'] ?? ''),
    ];
}

student_portal_init_schema($pdo);
