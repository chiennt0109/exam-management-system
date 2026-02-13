<?php
declare(strict_types=1);

function getCurrentExamId(): int
{
    $examId = (int) ($_SESSION['current_exam_id'] ?? 0);
    if ($examId <= 0) {
        return 0;
    }

    global $pdo;
    $stmt = $pdo->prepare('SELECT 1 FROM exams WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $examId]);
    if (!$stmt->fetchColumn()) {
        unset($_SESSION['current_exam_id']);
        return 0;
    }

    return $examId;
}

function setCurrentExam(int $examId): void
{
    global $pdo;
    if ($examId <= 0) {
        throw new InvalidArgumentException('Kỳ thi không hợp lệ.');
    }
    $stmt = $pdo->prepare('SELECT id FROM exams WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $examId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Kỳ thi không tồn tại.');
    }

    $_SESSION['current_exam_id'] = $examId;
}

function clearCurrentExam(): void
{
    unset($_SESSION['current_exam_id']);
}

function ensureExamLockColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $cols = array_column($pdo->query('PRAGMA table_info(exams)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('distribution_locked', $cols, true)) {
        $pdo->exec('ALTER TABLE exams ADD COLUMN distribution_locked INTEGER DEFAULT 0');
    }
    if (!in_array('rooms_locked', $cols, true)) {
        $pdo->exec('ALTER TABLE exams ADD COLUMN rooms_locked INTEGER DEFAULT 0');
    }
    if (!in_array('exam_locked', $cols, true)) {
        $pdo->exec('ALTER TABLE exams ADD COLUMN exam_locked INTEGER DEFAULT 0');
    }

    $checked = true;
}

/** @return array{id:int,ten_ky_thi:string,distribution_locked:int,rooms_locked:int,exam_locked:int}|null */
function getCurrentExamInfo(): ?array
{
    $examId = getCurrentExamId();
    if ($examId <= 0) {
        return null;
    }

    global $pdo;
    ensureExamLockColumns($pdo);

    $stmt = $pdo->prepare('SELECT id, ten_ky_thi, distribution_locked, rooms_locked, exam_locked FROM exams WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $examId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        clearCurrentExam();
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'ten_ky_thi' => (string) $row['ten_ky_thi'],
        'distribution_locked' => (int) ($row['distribution_locked'] ?? 0),
        'rooms_locked' => (int) ($row['rooms_locked'] ?? 0),
        'exam_locked' => (int) ($row['exam_locked'] ?? 0),
    ];
}

function requireCurrentExamId(string $redirectPath = '/modules/exams/index.php'): int
{
    $examId = getCurrentExamId();
    if ($examId <= 0) {
        $_SESSION['exam_flash'] = ['type' => 'warning', 'message' => 'Vui lòng chọn kỳ thi hiện tại trước khi thao tác.'];
        header('Location: ' . BASE_URL . $redirectPath);
        exit;
    }

    return $examId;
}
