<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_once BASE_PATH . '/modules/exams/score_utils.php';
require_role(['admin', 'scorer']);

$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$role = (string) ($_SESSION['user']['role'] ?? '');
$userId = (int) ($_SESSION['user']['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/modules/exams/scoring.php');
    exit;
}

$subjectId = max(0, (int) ($_POST['subject_id'] ?? 0));
$roomId = max(0, (int) ($_POST['room_id'] ?? 0));
$redirectUrl = BASE_URL . '/modules/exams/scoring.php?' . http_build_query(['subject_id' => $subjectId, 'room_id' => $roomId]);

if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
    exams_set_flash('error', 'CSRF token không hợp lệ.');
    header('Location: ' . $redirectUrl);
    exit;
}

if (!exams_is_exam_locked($pdo, $examId)) {
    exams_set_flash('error', 'Kỳ thi chưa khoá toàn bộ, chưa thể nhập điểm.');
    header('Location: ' . $redirectUrl);
    exit;
}

$scoringClosedStmt = $pdo->prepare('SELECT COALESCE(scoring_closed, 0) FROM exams WHERE id = :id LIMIT 1');
$scoringClosedStmt->execute([':id' => $examId]);
$isScoringClosed = ((int) ($scoringClosedStmt->fetchColumn() ?: 0)) === 1;
if ($isScoringClosed && $role !== 'admin') {
    exams_set_flash('error', 'Kỳ thi đã kết thúc nhập điểm. Chỉ admin mới có thể chỉnh sửa.');
    header('Location: ' . $redirectUrl);
    exit;
}

if ($roomId <= 0 || $subjectId <= 0) {
    exams_set_flash('error', 'Vui lòng chọn môn và phòng thi.');
    header('Location: ' . $redirectUrl);
    exit;
}

$roomStmt = $pdo->prepare('SELECT khoi FROM rooms WHERE id = :room_id AND exam_id = :exam_id AND subject_id = :subject_id LIMIT 1');
$roomStmt->execute([':room_id' => $roomId, ':exam_id' => $examId, ':subject_id' => $subjectId]);
$roomKhoi = (string) ($roomStmt->fetchColumn() ?: '');

$cfgStmt = $pdo->prepare('SELECT component_count, tong_diem, diem_tu_luan, diem_trac_nghiem, diem_noi
    FROM exam_subject_config
    WHERE exam_id = :exam_id AND subject_id = :subject_id
    ORDER BY id DESC LIMIT 1');
$cfgStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId]);
$cfg = $cfgStmt->fetch(PDO::FETCH_ASSOC) ?: ['component_count' => 1, 'tong_diem' => 10, 'diem_tu_luan' => 10, 'diem_trac_nghiem' => 0, 'diem_noi' => 0];
$componentCount = max(1, min(3, (int) ($cfg['component_count'] ?? 1)));
$max1 = (float) ($cfg['diem_tu_luan'] ?? 10);
$max2 = (float) ($cfg['diem_trac_nghiem'] ?? 0);
$max3 = (float) ($cfg['diem_noi'] ?? 0);
$totalMax = (float) ($cfg['tong_diem'] ?? 10);

$componentLabels = ['component_1'];
if ($componentCount >= 2) {
    $componentLabels[] = 'component_2';
}
if ($componentCount >= 3) {
    $componentLabels[] = 'component_3';
}

$allowedComponents = $componentLabels;
if ($role === 'scorer') {
    $aStmt = $pdo->prepare('SELECT component_name
        FROM score_assignments
        WHERE exam_id = :exam_id AND subject_id = :subject_id AND user_id = :user_id
          AND ((room_id IS NOT NULL AND room_id = :room_id) OR (room_id IS NULL AND khoi = :khoi))');
    $aStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':user_id' => $userId, ':room_id' => $roomId, ':khoi' => $roomKhoi]);
    $assignments = $aStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($assignments)) {
        exams_set_flash('error', 'Bạn không có quyền nhập điểm cho lựa chọn này.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    if (!in_array('total', $assignments, true)) {
        $allowedComponents = array_values(array_intersect($componentLabels, $assignments));
    }
}

$rowsPayload = $_POST['rows'] ?? [];
$changedRows = 0;
$clearedRows = 0;

try {
    $pdo->beginTransaction();

    $sel = $pdo->prepare('SELECT id, exam_id, student_id, subject_id, component_1, component_2, component_3
        FROM scores
        WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id LIMIT 1');
    $up = $pdo->prepare('UPDATE scores
        SET component_1 = :c1, component_2 = :c2, component_3 = :c3,
            total_score = :total, diem = :total, scorer_id = :scorer, updated_at = :updated
        WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id');

    $upExamScore = $pdo->prepare('INSERT INTO exam_scores (exam_id, student_id, subject_id, score, updated_at)
        VALUES (:exam_id, :student_id, :subject_id, :score, :updated_at)
        ON CONFLICT(exam_id, student_id, subject_id) DO UPDATE SET
            score = excluded.score,
            updated_at = excluded.updated_at');
    $deleteExamScore = $pdo->prepare('DELETE FROM exam_scores WHERE exam_id = :exam_id AND student_id = :student_id AND subject_id = :subject_id');

    foreach ((array) $rowsPayload as $scoreIdRaw => $vals) {
        $scoreId = (int) $scoreIdRaw;
        $sel->execute([':id' => $scoreId, ':exam_id' => $examId, ':subject_id' => $subjectId]);
        $old = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$old) {
            continue;
        }

        $oldC1 = $old['component_1'] === null ? null : (float) $old['component_1'];
        $oldC2 = $old['component_2'] === null ? null : (float) $old['component_2'];
        $oldC3 = $old['component_3'] === null ? null : (float) $old['component_3'];

        $c1 = in_array('component_1', $allowedComponents, true)
            ? parseSmartScore((string) ($vals['c1'] ?? ''), $max1)
            : $oldC1;
        $c2 = $componentCount >= 2
            ? (in_array('component_2', $allowedComponents, true)
                ? parseSmartScore((string) ($vals['c2'] ?? ''), $max2)
                : $oldC2)
            : null;
        $c3 = $componentCount >= 3
            ? (in_array('component_3', $allowedComponents, true)
                ? parseSmartScore((string) ($vals['c3'] ?? ''), $max3)
                : $oldC3)
            : null;

        $changed = false;
        if (in_array('component_1', $allowedComponents, true) && $c1 !== $oldC1) { $changed = true; }
        if ($componentCount >= 2 && in_array('component_2', $allowedComponents, true) && $c2 !== $oldC2) { $changed = true; }
        if ($componentCount >= 3 && in_array('component_3', $allowedComponents, true) && $c3 !== $oldC3) { $changed = true; }
        if (!$changed) {
            continue;
        }

        $sum = 0.0;
        $hasAny = false;
        foreach ([$c1, $c2, $c3] as $part) {
            if ($part !== null) {
                $sum += $part;
                $hasAny = true;
            }
        }
        $total = $hasAny ? round($sum, 2) : null;

        if ($total !== null && $total > $totalMax) {
            throw new RuntimeException('Tổng điểm vượt quá tổng điểm môn.');
        }

        $up->execute([
            ':c1' => $c1,
            ':c2' => $c2,
            ':c3' => $c3,
            ':total' => $total,
            ':scorer' => $userId,
            ':updated' => date('c'),
            ':id' => $scoreId,
            ':exam_id' => $examId,
            ':subject_id' => $subjectId,
        ]);

        $changedRows++;
        if ($total === null) {
            $clearedRows++;
            $deleteExamScore->execute([
                ':exam_id' => $examId,
                ':student_id' => (int) $old['student_id'],
                ':subject_id' => (int) $old['subject_id'],
            ]);
        } else {
            $upExamScore->execute([
                ':exam_id' => $examId,
                ':student_id' => (int) $old['student_id'],
                ':subject_id' => (int) $old['subject_id'],
                ':score' => $total,
                ':updated_at' => date('c'),
            ]);
        }
    }

    $pdo->commit();
    exams_set_flash('success', 'Đã lưu điểm.');
    if ($changedRows > 0) {
        exams_set_flash('warning', sprintf('Đã thay đổi %d dòng, trong đó xoá trắng %d dòng.', $changedRows, $clearedRows));
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    exams_set_flash('error', $e->getMessage());
}

header('Location: ' . $redirectUrl);
exit;
