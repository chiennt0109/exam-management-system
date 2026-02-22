<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
student_require_login();

$student = student_portal_student();
$csrf = student_portal_csrf_token();

$exam = student_portal_get_exam($pdo, $student['exam_id']);
$examMode = in_array((int) ($exam['exam_mode'] ?? 1), [1, 2], true) ? (int) $exam['exam_mode'] : 1;
$canRegister = $exam ? student_portal_can_register_subjects($exam) : false;

$baseInfoStmt = $pdo->prepare('SELECT COALESCE(khoi, "") AS khoi, COALESCE(lop, "") AS lop
    FROM exam_students
    WHERE exam_id = :exam_id AND student_id = :student_id AND subject_id IS NULL
    LIMIT 1');
$baseInfoStmt->execute([':exam_id' => $student['exam_id'], ':student_id' => $student['id']]);
$baseInfo = $baseInfoStmt->fetch(PDO::FETCH_ASSOC) ?: ['khoi' => '', 'lop' => ''];
$studentKhoi = trim((string) ($baseInfo['khoi'] ?? ''));
$studentLop = trim((string) ($baseInfo['lop'] ?? ''));

if ($examMode === 1) {
    $subjectsStmt = $pdo->prepare('SELECT DISTINCT s.id, s.ten_mon
        FROM exam_subject_config esc
        INNER JOIN subjects s ON s.id = esc.subject_id
        LEFT JOIN exam_subject_classes cls ON cls.exam_config_id = esc.id
        WHERE esc.exam_id = :exam_id
          AND (
            (esc.scope_mode = "entire_grade" AND esc.khoi = :khoi)
            OR (esc.scope_mode = "specific_classes" AND cls.lop = :lop)
          )
        ORDER BY s.ten_mon ASC');
    $subjectsStmt->execute([
        ':exam_id' => $student['exam_id'],
        ':khoi' => $studentKhoi,
        ':lop' => $studentLop,
    ]);
    $subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $subjectsStmt = $pdo->prepare('SELECT s.id, s.ten_mon FROM exam_subjects es INNER JOIN subjects s ON s.id = es.subject_id WHERE es.exam_id = :exam_id ORDER BY es.sort_order ASC, s.ten_mon ASC');
    $subjectsStmt->execute([':exam_id' => $student['exam_id']]);
    $subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);
}

$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($examMode !== 2) {
        $message = 'Chế độ kiểm tra định kỳ chỉ hiển thị danh sách môn thi, không yêu cầu đăng ký.';
    } elseif (!$canRegister) {
        $message = 'Kỳ thi đã khóa, không thể đăng ký môn.';
    } elseif (!student_portal_verify_csrf($_POST['csrf_token'] ?? null)) {
        $message = 'CSRF token không hợp lệ.';
    } else {
        $chosen = array_values(array_unique(array_map('intval', (array) ($_POST['subjects'] ?? []))));
        $allowedSubjectIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $subjects);
        $chosen = array_values(array_filter($chosen, static fn(int $id): bool => in_array($id, $allowedSubjectIds, true)));

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM exam_student_subjects WHERE exam_id = :exam AND student_id = :student');
            $del->execute([':exam' => $student['exam_id'], ':student' => $student['id']]);

            $ins = $pdo->prepare('INSERT OR IGNORE INTO exam_student_subjects (exam_id, student_id, subject_id) VALUES (:exam, :student, :subject)');
            foreach ($chosen as $subjectId) {
                $ins->execute([
                    ':exam' => $student['exam_id'],
                    ':student' => $student['id'],
                    ':subject' => $subjectId,
                ]);
            }
            $pdo->commit();
            $message = 'Đã cập nhật đăng ký môn thi.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = 'Không thể lưu đăng ký môn thi.';
        }
    }
}

$selected = [];
if ($examMode === 2) {
    $selectedStmt = $pdo->prepare('SELECT subject_id FROM exam_student_subjects WHERE exam_id = :exam AND student_id = :student');
    $selectedStmt->execute([':exam' => $student['exam_id'], ':student' => $student['id']]);
    $selected = array_map('intval', $selectedStmt->fetchAll(PDO::FETCH_COLUMN));
}

student_portal_render_header('Đăng ký môn thi');
?>
<main class="portal-main">
    <?php student_portal_render_student_info($pdo); ?>

    <section class="card">
        <h1><i class="fa-solid fa-list-check"></i> Đăng ký môn thi</h1>
        <?php if ($message !== ''): ?><div class="alert-info"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <p><strong>Chế độ kỳ thi:</strong> <?= $examMode === 1 ? '1 - Kiểm tra định kỳ' : '2 - Tốt nghiệp THPT' ?></p>
        <?php if ($examMode === 2): ?>
            <p>Trạng thái kỳ thi: <strong><?= $canRegister ? 'Đang mở đăng ký' : 'Đã khóa đăng ký' ?></strong></p>
        <?php else: ?>
            <p>Chế độ này chỉ hiển thị các môn thi được cấu hình cho kỳ thi.</p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <table class="portal-table">
                <thead><tr><th>Môn</th><th>Chọn</th></tr></thead>
                <tbody>
                <?php foreach ($subjects as $subject): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $subject['ten_mon'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><input type="checkbox" name="subjects[]" value="<?= (int) $subject['id'] ?>" <?= in_array((int) $subject['id'], $selected, true) ? 'checked' : '' ?> <?= ($canRegister && $examMode === 2) ? '' : 'disabled' ?>></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($subjects)): ?><tr><td colspan="2">Không có môn thi được cấu hình.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <div class="actions"><button class="btn primary" <?= ($canRegister && $examMode === 2) ? '' : 'disabled' ?>><?= $examMode === 2 ? 'Lưu đăng ký' : 'Danh sách chỉ xem' ?></button></div>
        </form>
        <p><a href="<?= BASE_URL ?>/student_portal/dashboard.php">← Quay lại dashboard</a></p>
    </section>
</main>
<?php student_portal_render_footer(); ?>
