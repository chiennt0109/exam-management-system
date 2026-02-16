<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_role(['admin', 'organizer']);

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'CSRF token không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$examId = getCurrentExamId();
$postExamId = (int) ($_POST['exam_id'] ?? 0);
if ($examId <= 0 || $postExamId !== $examId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Kỳ thi không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$studentId = (int) ($_POST['student_id'] ?? 0);
$subjectId = (int) ($_POST['subject_id'] ?? 0);
$checked = (string) ($_POST['checked'] ?? '0') === '1';

if ($action !== 'toggle' || $studentId <= 0 || $subjectId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Dữ liệu không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    exams_assert_exam_unlocked_for_write($pdo, $examId);

    $studentOk = $pdo->prepare('SELECT 1 FROM exam_students WHERE exam_id = :exam_id AND student_id = :student_id AND subject_id IS NULL LIMIT 1');
    $studentOk->execute([':exam_id' => $examId, ':student_id' => $studentId]);
    if (!$studentOk->fetchColumn()) {
        throw new RuntimeException('Học sinh không thuộc kỳ thi hiện tại.');
    }

    $subjectOk = $pdo->prepare('SELECT 1 FROM exam_subjects WHERE exam_id = :exam_id AND subject_id = :subject_id LIMIT 1');
    $subjectOk->execute([':exam_id' => $examId, ':subject_id' => $subjectId]);
    if (!$subjectOk->fetchColumn()) {
        throw new RuntimeException('Môn học chưa có trong ma trận kỳ thi.');
    }

    if ($checked) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO exam_student_subjects (exam_id, student_id, subject_id) VALUES (:exam_id, :student_id, :subject_id)');
        $stmt->execute([':exam_id' => $examId, ':student_id' => $studentId, ':subject_id' => $subjectId]);
    } else {
        $stmt = $pdo->prepare('DELETE FROM exam_student_subjects WHERE exam_id = :exam_id AND student_id = :student_id AND subject_id = :subject_id');
        $stmt->execute([':exam_id' => $examId, ':student_id' => $studentId, ':subject_id' => $subjectId]);
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
