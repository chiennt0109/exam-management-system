<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
student_require_login();

$student = student_portal_student();
$examStmt = $pdo->prepare('SELECT * FROM exams WHERE id = :id LIMIT 1');
$examStmt->execute([':id' => $student['exam_id']]);
$exam = $examStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$canViewScores = $exam
    && (int) ($exam['is_score_published'] ?? 0) === 1
    && (int) ($exam['is_score_entry_locked'] ?? 0) === 1;

$scores = [];
$total = 0.0;
$avg = 0.0;
$classify = '';
if ($canViewScores) {
    $stmt = $pdo->prepare('SELECT s.ten_mon AS ma_mon, es.score AS diem
        FROM exam_scores es
        INNER JOIN subjects s ON s.id = es.subject_id
        WHERE es.student_id = :student_id AND es.exam_id = :exam_id
        ORDER BY s.ten_mon');
    $stmt->execute([':student_id' => $student['id'], ':exam_id' => $student['exam_id']]);
    $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($scores)) {
        $vals = array_map(static fn(array $r): float => (float) ($r['diem'] ?? 0), $scores);
        $total = array_sum($vals);
        $avg = $total / count($vals);
        $classify = $avg >= 8.0 ? 'Giỏi' : ($avg >= 6.5 ? 'Khá' : ($avg >= 5.0 ? 'Trung bình' : 'Yếu'));
    }
}

student_portal_render_header('Xem điểm');
?>
<main class="portal-main">
    <section class="card">
        <h1><i class="fa-solid fa-chart-column"></i> Kết quả thi</h1>
        <?php if (!$canViewScores): ?>
            <div class="alert-error">Điểm chưa được công bố hoặc chưa khóa nhập điểm.</div>
        <?php else: ?>
            <table class="portal-table">
                <thead><tr><th>Môn</th><th>Điểm</th></tr></thead>
                <tbody>
                <?php foreach ($scores as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($row['ma_mon'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format((float) ($row['diem'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($scores)): ?><tr><td colspan="2">Chưa có điểm.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <div class="summary">
                <p><strong>Tổng điểm:</strong> <?= number_format($total, 2) ?></p>
                <p><strong>Trung bình:</strong> <?= number_format($avg, 2) ?></p>
                <p><strong>Xếp loại:</strong> <?= htmlspecialchars($classify, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php endif; ?>
        <p><a href="<?= BASE_URL ?>/student_portal/dashboard.php">← Quay lại dashboard</a></p>
    </section>
</main>
<?php student_portal_render_footer(); ?>
