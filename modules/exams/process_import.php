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
if (!in_array($strategy, ['overwrite', 'skip_existing', 'cancel'], true)) {
    $strategy = 'cancel';
}

$preview = (array) ($_SESSION['score_import_preview'] ?? []);
$validRows = (array) ($preview['valid_rows'] ?? []);
if (($preview['exam_id'] ?? 0) !== $examId || empty($validRows)) {
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

$selectExisting = $pdo->prepare('SELECT 1 FROM exam_scores WHERE exam_id = :exam_id AND subject_id = :subject_id AND student_id = :student_id LIMIT 1');
$deleteExamScore = $pdo->prepare('DELETE FROM exam_scores WHERE exam_id = :exam_id AND subject_id = :subject_id AND student_id = :student_id');
$upsertExamScore = $pdo->prepare('INSERT INTO exam_scores (exam_id, student_id, subject_id, score, updated_at)
    VALUES (:exam_id, :student_id, :subject_id, :score, :updated_at)
    ON CONFLICT(exam_id, student_id, subject_id)
    DO UPDATE SET score = excluded.score, updated_at = excluded.updated_at');

$selectScore = $pdo->prepare('SELECT id, component_1, component_2, component_3, total_score FROM scores WHERE exam_id = :exam_id AND student_id = :student_id AND subject_id = :subject_id LIMIT 1');
$insertScore = $pdo->prepare('INSERT INTO scores (exam_id, student_id, subject_id, component_1, component_2, component_3, total_score, diem, scorer_id, updated_at)
    VALUES (:exam_id, :student_id, :subject_id, :c1, :c2, :c3, :total, :total, NULL, :updated_at)');
$updateScore = $pdo->prepare('UPDATE scores
    SET component_1 = :c1, component_2 = :c2, component_3 = :c3, total_score = :total, diem = :total, updated_at = :updated_at
    WHERE id = :id');

$updated = 0;
$deleted = 0;
$skipped = 0;

try {
    $pdo->beginTransaction();

    foreach ($validRows as $row) {
        $studentId = (int) ($row['student_id'] ?? 0);
        $subjectId = (int) ($row['subject_id'] ?? 0);
        $component = (string) ($row['component_name'] ?? '');
        $parsedScore = $row['parsed_score'];

        if ($studentId <= 0 || $subjectId <= 0) {
            $skipped++;
            continue;
        }

        $selectExisting->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':student_id' => $studentId]);
        $exists = (bool) $selectExisting->fetchColumn();
        if ($exists && $strategy === 'skip_existing') {
            $skipped++;
            continue;
        }

        $selectScore->execute([':exam_id' => $examId, ':student_id' => $studentId, ':subject_id' => $subjectId]);
        $old = $selectScore->fetch(PDO::FETCH_ASSOC) ?: ['id'=>0,'component_1'=>null,'component_2'=>null,'component_3'=>null,'total_score'=>null];
        $scoreRowId = (int) ($old['id'] ?? 0);
        $c1 = $old['component_1'] === null ? null : (float) $old['component_1'];
        $c2 = $old['component_2'] === null ? null : (float) $old['component_2'];
        $c3 = $old['component_3'] === null ? null : (float) $old['component_3'];

        if ($component === 'component_1') {
            $c1 = $parsedScore === null ? null : (float) $parsedScore;
        } elseif ($component === 'component_2') {
            $c2 = $parsedScore === null ? null : (float) $parsedScore;
        } elseif ($component === 'component_3') {
            $c3 = $parsedScore === null ? null : (float) $parsedScore;
        } else {
            $skipped++;
            continue;
        }

        $parts = [$c1, $c2, $c3];
        $sum = 0.0;
        $hasAny = false;
        foreach ($parts as $part) {
            if ($part !== null) {
                $sum += (float) $part;
                $hasAny = true;
            }
        }
        $total = $hasAny ? round($sum, 2) : null;

        if ($total === null) {
            if ($exists && $strategy === 'overwrite') {
                $deleteExamScore->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':student_id' => $studentId]);
                $deleted++;
            } else {
                $skipped++;
            }
            continue;
        }

        $scoreParams = [
            ':exam_id' => $examId,
            ':student_id' => $studentId,
            ':subject_id' => $subjectId,
            ':c1' => $c1,
            ':c2' => $c2,
            ':c3' => $c3,
            ':total' => $total,
            ':updated_at' => date('c'),
        ];
        if ($scoreRowId > 0) {
            $updateScore->execute([
                ':id' => $scoreRowId,
                ':c1' => $scoreParams[':c1'],
                ':c2' => $scoreParams[':c2'],
                ':c3' => $scoreParams[':c3'],
                ':total' => $scoreParams[':total'],
                ':updated_at' => $scoreParams[':updated_at'],
            ]);
        } else {
            $insertScore->execute($scoreParams);
        }
        $upsertExamScore->execute([
            ':exam_id' => $examId,
            ':student_id' => $studentId,
            ':subject_id' => $subjectId,
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
