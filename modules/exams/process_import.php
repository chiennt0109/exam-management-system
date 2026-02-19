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
$targetComponent = (string) ($preview['target_component'] ?? 'total');

$selectExisting = $pdo->prepare('SELECT 1 FROM exam_scores WHERE exam_id = :exam_id AND subject_id = :subject_id AND student_id = :student_id LIMIT 1');
$deleteScore = $pdo->prepare('DELETE FROM exam_scores WHERE exam_id = :exam_id AND subject_id = :subject_id AND student_id = :student_id');
$upsertScore = $pdo->prepare('INSERT INTO exam_scores (exam_id, student_id, subject_id, score, updated_at)
    VALUES (:exam_id, :student_id, :subject_id, :score, :updated_at)
    ON CONFLICT(exam_id, student_id, subject_id)
    DO UPDATE SET score = excluded.score, updated_at = excluded.updated_at');

$selectScoreRow = $pdo->prepare('SELECT component_1, component_2, component_3, total_score FROM scores WHERE exam_id = :exam_id AND student_id = :student_id AND subject_id = :subject_id LIMIT 1');
$upsertScoreRow = $pdo->prepare('INSERT INTO scores (exam_id, student_id, subject_id, component_1, component_2, component_3, total_score, diem, scorer_id, updated_at)
    VALUES (:exam_id, :student_id, :subject_id, :c1, :c2, :c3, :total, :total, NULL, :updated_at)
    ON CONFLICT(exam_id, student_id, subject_id)
    DO UPDATE SET component_1 = excluded.component_1, component_2 = excluded.component_2, component_3 = excluded.component_3, total_score = excluded.total_score, diem = excluded.diem, updated_at = excluded.updated_at');

$updated = 0;
$deleted = 0;
$skipped = 0;

try {
    $pdo->beginTransaction();

    foreach ($validRows as $row) {
        $studentId = (int) ($row['student_id'] ?? 0);
        $rowSubjectId = (int) ($row['subject_id'] ?? $subjectId);
        if ($studentId <= 0) {
            $skipped++;
            continue;
        }

        $parsedScore = $row['parsed_score'];
        $isNullScore = $parsedScore === null;

$selectExisting->execute([':exam_id' => $examId, ':subject_id' => $rowSubjectId, ':student_id' => $studentId]);
        $exists = (bool) $selectExisting->fetchColumn();

        if ($exists && $strategy === 'skip_existing') {
            $skipped++;
            continue;
        }

        if ($isNullScore) {
$deleteScore->execute([':exam_id' => $examId, ':subject_id' => $rowSubjectId, ':student_id' => $studentId]);
            $deleted++;
            continue;
        }

        if ($exists && $strategy === 'overwrite') {
$deleteScore->execute([':exam_id' => $examId, ':subject_id' => $rowSubjectId, ':student_id' => $studentId]);
        }

        $selectScoreRow->execute([':exam_id' => $examId, ':student_id' => $studentId, ':subject_id' => $rowSubjectId]);
        $oldRow = $selectScoreRow->fetch(PDO::FETCH_ASSOC) ?: ['component_1' => null, 'component_2' => null, 'component_3' => null, 'total_score' => null];
        $c1 = $oldRow['component_1'] === null ? null : (float) $oldRow['component_1'];
        $c2 = $oldRow['component_2'] === null ? null : (float) $oldRow['component_2'];
        $c3 = $oldRow['component_3'] === null ? null : (float) $oldRow['component_3'];

        if ($targetComponent === 'component_1') {
            $c1 = (float) $parsedScore;
        } elseif ($targetComponent === 'component_2') {
            $c2 = (float) $parsedScore;
        } elseif ($targetComponent === 'component_3') {
            $c3 = (float) $parsedScore;
        }

        $total = $targetComponent === 'total'
            ? (float) $parsedScore
            : ((($c1 ?? 0.0) + ($c2 ?? 0.0) + ($c3 ?? 0.0)));

        $upsertScoreRow->execute([
            ':exam_id' => $examId,
            ':student_id' => $studentId,
            ':subject_id' => $rowSubjectId,
            ':c1' => $c1,
            ':c2' => $c2,
            ':c3' => $c3,
            ':total' => $total,
            ':updated_at' => date('c'),
        ]);

        $upsertScore->execute([
            ':exam_id' => $examId,
            ':student_id' => $studentId,
            ':subject_id' => $rowSubjectId,
            ':score' => $total,
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
