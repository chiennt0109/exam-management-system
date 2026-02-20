<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
student_require_login();

$student = student_portal_student();
$exam = student_portal_get_exam($pdo, $student['exam_id']);
$canViewRooms = $exam ? student_portal_can_view_rooms($exam) : false;

$hasExamRoomsTable = (bool) $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='exam_rooms'")->fetchColumn();

$rooms = [];
if ($canViewRooms) {
    if ($hasExamRoomsTable) {
        $stmt = $pdo->prepare('SELECT * FROM exam_rooms WHERE student_id = :student_id AND exam_id = :exam_id ORDER BY subject_id');
        $stmt->execute([':student_id' => $student['id'], ':exam_id' => $student['exam_id']]);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare('SELECT sb.subject_id, COALESCE(s.ten_mon, "") AS ten_mon, COALESCE(r.ten_phong, "") AS ten_phong, COALESCE(sb.sbd, "") AS sbd, sb.rowid AS stt
            FROM exam_students sb
            LEFT JOIN rooms r ON r.id = sb.room_id
            LEFT JOIN subjects s ON s.id = sb.subject_id
            WHERE sb.exam_id = :exam_id AND sb.student_id = :student_id AND sb.subject_id IS NOT NULL
            ORDER BY s.ten_mon');
        $stmt->execute([':exam_id' => $student['exam_id'], ':student_id' => $student['id']]);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

student_portal_render_header('Xem phòng thi');
?>
<main class="portal-main">
    <section class="card">
        <h1><i class="fa-solid fa-door-open"></i> Xem phòng thi</h1>
        <?php if (!$canViewRooms): ?>
            <div class="alert-error">Kỳ thi chưa khóa phân phòng. Vui lòng quay lại sau.</div>
        <?php else: ?>
            <table class="portal-table">
                <thead><tr><th>Môn</th><th>Phòng thi</th><th>SBD</th><th>Số thứ tự</th></tr></thead>
                <tbody>
                <?php foreach ($rooms as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($row['ten_mon'] ?? $row['subject_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($row['ten_phong'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($row['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($row['stt'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rooms)): ?><tr><td colspan="4">Chưa có dữ liệu phòng thi.</td></tr><?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p><a href="<?= BASE_URL ?>/student_portal/dashboard.php">← Quay lại dashboard</a></p>
    </section>
</main>
<?php student_portal_render_footer(); ?>
