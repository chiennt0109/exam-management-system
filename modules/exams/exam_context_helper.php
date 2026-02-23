<?php
declare(strict_types=1);

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

/**
 * Trả về kỳ thi mặc định toàn cục.
 * - Nếu chưa có default và chỉ có 1 kỳ thi active -> tự động set default cho kỳ thi đó.
 * - Nếu có nhiều kỳ thi active nhưng chưa default -> trả về null.
 *
 * @return array<string,mixed>|null
 */
function getCurrentExam(): ?array
{
    global $pdo;

    $cols = array_column($pdo->query('PRAGMA table_info(exams)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $hasDeletedAt = in_array('deleted_at', $cols, true);
    $activeWhere = $hasDeletedAt ? '(deleted_at IS NULL OR trim(deleted_at) = "")' : '1=1';

    $defaultStmt = $pdo->query('SELECT * FROM exams WHERE ' . $activeWhere . ' AND is_default = 1 ORDER BY id DESC LIMIT 1');
    $defaultExam = $defaultStmt ? $defaultStmt->fetch(PDO::FETCH_ASSOC) : false;
    if (is_array($defaultExam)) {
        return $defaultExam;
    }

    $countStmt = $pdo->query('SELECT COUNT(*) FROM exams WHERE ' . $activeWhere);
    $activeCount = (int) ($countStmt ? $countStmt->fetchColumn() : 0);
    if ($activeCount !== 1) {
        return null;
    }

    $onlyStmt = $pdo->query('SELECT * FROM exams WHERE ' . $activeWhere . ' ORDER BY id DESC LIMIT 1');
    $onlyExam = $onlyStmt ? $onlyStmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!is_array($onlyExam)) {
        return null;
    }

    $onlyId = (int) ($onlyExam['id'] ?? 0);
    if ($onlyId <= 0) {
        return null;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE exams SET is_default = 0');
        $stmt = $pdo->prepare('UPDATE exams SET is_default = 1 WHERE id = :id');
        $stmt->execute([':id' => $onlyId]);
        $pdo->commit();
        $onlyExam['is_default'] = 1;
        return $onlyExam;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return null;
    }
}

function getCurrentExamId(): int
{
    $exam = getCurrentExam();
    return (int) ($exam['id'] ?? 0);
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

    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE exams SET is_default = 0');
        $pdo->prepare('UPDATE exams SET is_default = 1 WHERE id = :id')->execute([':id' => $examId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function clearCurrentExam(): void
{
    // Không dùng session cho kỳ thi mặc định. Hàm giữ lại để tương thích ngược.
}

/** @return array{id:int,ten_ky_thi:string,distribution_locked:int,rooms_locked:int,exam_locked:int}|null */
function getCurrentExamInfo(): ?array
{
    $exam = getCurrentExam();
    if (!$exam) {
        return null;
    }

    global $pdo;
    ensureExamLockColumns($pdo);

    return [
        'id' => (int) ($exam['id'] ?? 0),
        'ten_ky_thi' => (string) ($exam['ten_ky_thi'] ?? ''),
        'distribution_locked' => (int) ($exam['distribution_locked'] ?? 0),
        'rooms_locked' => (int) ($exam['rooms_locked'] ?? 0),
        'exam_locked' => (int) ($exam['exam_locked'] ?? 0),
    ];
}

function requireCurrentExamId(string $redirectPath = '/modules/exams/index.php'): int
{
    $examId = getCurrentExamId();
    if ($examId <= 0) {
        $_SESSION['exam_flash'] = ['type' => 'warning', 'message' => 'Chưa có kỳ thi mặc định. Vui lòng vào Quản lý kỳ thi để chọn kỳ thi mặc định.'];
        header('Location: ' . BASE_URL . $redirectPath);
        exit;
    }

    return $examId;
}
