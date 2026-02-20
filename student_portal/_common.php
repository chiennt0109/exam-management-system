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

    $pdo->exec('CREATE TABLE IF NOT EXISTS student_exam_subjects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        exam_id INTEGER NOT NULL,
        student_id INTEGER NOT NULL,
        subject_id INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(exam_id, student_id, subject_id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_student_exam_subjects_student ON student_exam_subjects(student_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_student_exam_subjects_exam ON student_exam_subjects(exam_id)');
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

function student_portal_student(): array
{
    return [
        'id' => (int) ($_SESSION['student_id'] ?? 0),
        'name' => (string) ($_SESSION['student_name'] ?? ''),
        'identifier' => (string) ($_SESSION['student_identifier'] ?? ''),
        'exam_id' => (int) ($_SESSION['student_exam_default'] ?? 0),
    ];
}

student_portal_init_schema($pdo);
