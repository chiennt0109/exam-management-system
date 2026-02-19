<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_role(['admin', 'organizer', 'scorer']);

$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/modules/exams/import_scores.php');
    exit;
}

if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
    exams_set_flash('error', 'CSRF token không hợp lệ.');
    header('Location: ' . BASE_URL . '/modules/exams/import_scores.php');
    exit;
}

$strategy = (string) ($_POST['strategy'] ?? 'cancel');
$allowed = ['overwrite', 'skip_existing', 'cancel'];
if (!in_array($strategy, $allowed, true)) {
    $strategy = 'cancel';
}

$preview = (array) ($_SESSION['score_import_preview'] ?? []);
if (($preview['exam_id'] ?? 0) !== $examId || empty($preview['valid_rows'])) {
    exams_set_flash('error', 'Không có dữ liệu preview để lưu.');
    header('Location: ' . BASE_URL . '/modules/exams/import_scores.php');
    exit;
}

if ($strategy === 'cancel') {
    unset($_SESSION['score_import_preview'], $_SESSION['score_import_draft']);
    exams_set_flash('success', 'Đã hủy import.');
    header('Location: ' . BASE_URL . '/modules/exams/import_scores.php');
    exit;
}

$subjectId = (int) ($preview['subject_id'] ?? 0);
$validRows = (array) ($preview['valid_rows'] ?? []);

$selectExisting = $pdo->prepare('SELECT 1 FROM exam_scores WHERE exam_id = :exam_id AND subject_id = :subject_id AND student_id = :student_id LIMIT 1');
$deleteScore = $pdo->prepare('DELETE FROM exam_scores WHERE exam_id = :exam_id AND subject_id = :subject_id AND student_id = :student_id');
$upsertScore = $pdo->prepare('INSERT INTO exam_scores (exam_id, student_id, subject_id, score, updated_at)
    VALUES (:exam_id, :student_id, :subject_id, :score, :updated_at)
    ON CONFLICT(exam_id, student_id, subject_id)
    DO UPDATE SET score = excluded.score, updated_at = excluded.updated_at');

$updated = 0;
$deleted = 0;
$skipped = 0;

try {
    $pdo->beginTransaction();

    foreach ($validRows as $row) {
        $studentId = (int) ($row['student_id'] ?? 0);
        if ($studentId <= 0) {
            $skipped++;
            continue;
        }

        $parsedScore = $row['parsed_score'];
        $isNullScore = $parsedScore === null;

        $selectExisting->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':student_id' => $studentId]);
        $exists = (bool) $selectExisting->fetchColumn();

        if ($exists && $strategy === 'skip_existing') {
            $skipped++;
            continue;
        }

        if ($isNullScore) {
            $deleteScore->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':student_id' => $studentId]);
            $deleted++;
            continue;
        }

        if ($exists && $strategy === 'overwrite') {
            $deleteScore->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':student_id' => $studentId]);
        }

        $upsertScore->execute([
            ':exam_id' => $examId,
            ':student_id' => $studentId,
            ':subject_id' => $subjectId,
            ':score' => (float) $parsedScore,
            ':updated_at' => date('c'),
        ]);
        $updated++;
    }

    $pdo->commit();
    unset($_SESSION['score_import_preview'], $_SESSION['score_import_draft']);
    exams_set_flash('success', sprintf('Import hoàn tất. Cập nhật: %d, Xóa: %d, Bỏ qua: %d.', $updated, $deleted, $skipped));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    exams_set_flash('error', 'Import thất bại: ' . $e->getMessage());
}

header('Location: ' . BASE_URL . '/modules/exams/import_scores.php');
exit;
