<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
student_require_login();

$student = student_portal_student();
$csrf = student_portal_csrf_token();

$examStmt = $pdo->prepare('SELECT * FROM exams WHERE id = :id LIMIT 1');
$examStmt->execute([':id' => $student['exam_id']]);
$exam = $examStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$canRegister = $exam && (int) ($exam['is_locked'] ?? 0) === 0;

$subjectsStmt = $pdo->prepare('SELECT s.id, s.ten_mon FROM exam_subjects es INNER JOIN subjects s ON s.id = es.subject_id WHERE es.exam_id = :exam_id ORDER BY es.sort_order ASC, s.ten_mon ASC');
$subjectsStmt->execute([':exam_id' => $student['exam_id']]);
$subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);

$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$canRegister) {
        $message = 'Kỳ thi đã khóa, không thể đăng ký môn.';
    } elseif (!student_portal_verify_csrf($_POST['csrf_token'] ?? null)) {
        $message = 'CSRF token không hợp lệ.';
    } else {
        $chosen = array_map('intval', (array) ($_POST['subjects'] ?? []));
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM student_exam_subjects WHERE exam_id = :exam AND student_id = :student');
            $del->execute([':exam' => $student['exam_id'], ':student' => $student['id']]);

            $ins = $pdo->prepare('INSERT INTO student_exam_subjects (exam_id, student_id, subject_id, created_at) VALUES (:exam, :student, :subject, :created_at)');
            foreach ($chosen as $subjectId) {
                $ins->execute([
                    ':exam' => $student['exam_id'],
                    ':student' => $student['id'],
                    ':subject' => $subjectId,
                    ':created_at' => date('c'),
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

$selectedStmt = $pdo->prepare('SELECT subject_id FROM student_exam_subjects WHERE exam_id = :exam AND student_id = :student');
$selectedStmt->execute([':exam' => $student['exam_id'], ':student' => $student['id']]);
$selected = array_map('intval', $selectedStmt->fetchAll(PDO::FETCH_COLUMN));

student_portal_render_header('Đăng ký môn thi');
?>
<main class="portal-main">
    <section class="card">
        <h1><i class="fa-solid fa-list-check"></i> Đăng ký môn thi</h1>
        <?php if ($message !== ''): ?><div class="alert-info"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <p>Trạng thái kỳ thi: <strong><?= $canRegister ? 'Đang mở đăng ký' : 'Đã khóa đăng ký' ?></strong></p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <table class="portal-table">
                <thead><tr><th>Môn</th><th>Chọn</th></tr></thead>
                <tbody>
                <?php foreach ($subjects as $subject): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $subject['ten_mon'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><input type="checkbox" name="subjects[]" value="<?= (int) $subject['id'] ?>" <?= in_array((int) $subject['id'], $selected, true) ? 'checked' : '' ?> <?= $canRegister ? '' : 'disabled' ?>></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($subjects)): ?><tr><td colspan="2">Không có môn thi được cấu hình.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <div class="actions"><button class="btn primary" <?= $canRegister ? '' : 'disabled' ?>>Lưu đăng ký</button></div>
        </form>
        <p><a href="<?= BASE_URL ?>/student_portal/dashboard.php">← Quay lại dashboard</a></p>
    </section>
</main>
<?php student_portal_render_footer(); ?>
