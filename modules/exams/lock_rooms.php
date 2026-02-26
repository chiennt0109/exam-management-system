<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/modules/exams/distribute_rooms.php');
    exit;
}

$examId = max(0, (int) ($_POST['exam_id'] ?? 0));
if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
    exams_set_flash('error', 'CSRF token không hợp lệ.');
    header('Location: ' . BASE_URL . '/modules/exams/distribute_rooms.php?exam_id=' . $examId);
    exit;
}
if ($examId <= 0) {
    exams_set_flash('error', 'Thiếu kỳ thi để khoá phân phòng.');
    header('Location: ' . BASE_URL . '/modules/exams/distribute_rooms.php');
    exit;
}

try {
    exams_assert_exam_unlocked_for_write($pdo, $examId);
    $pdo->beginTransaction();
    // Khoá phân phòng chỉ khoá giai đoạn phân phòng, KHÔNG khoá toàn bộ kỳ thi.
    $pdo->prepare('UPDATE exams
        SET distribution_locked = 1,
            rooms_locked = 1,
            is_locked = 1
        WHERE id = :id')->execute([':id' => $examId]);
    $pdo->commit();
    exams_set_flash('success', 'Đã khoá phân phòng thành công.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    exams_set_flash('error', 'Không thể khoá phân phòng.');
}

header('Location: ' . BASE_URL . '/modules/exams/adjust_rooms.php?exam_id=' . $examId);
exit;
