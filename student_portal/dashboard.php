<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
student_require_login();

$student = student_portal_student();
$examStmt = $pdo->prepare('SELECT * FROM exams WHERE id = :id LIMIT 1');
$examStmt->execute([':id' => $student['exam_id']]);
$exam = $examStmt->fetch(PDO::FETCH_ASSOC) ?: [];

student_portal_render_header('Trang chủ học sinh');
?>
<main class="portal-main">
    <section class="card">
        <h1>Xin chào: <?= htmlspecialchars($student['name'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p><strong>Số định danh:</strong> <?= htmlspecialchars($student['identifier'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Kỳ thi:</strong> <?= htmlspecialchars((string) ($exam['ten_ky_thi'] ?? 'Chưa cấu hình'), ENT_QUOTES, 'UTF-8') ?></p>
    </section>

    <section class="card-grid">
        <a class="menu-card" href="<?= BASE_URL ?>/student_portal/register_subjects.php"><i class="fa-solid fa-list-check"></i><span>Đăng ký môn thi</span></a>
        <a class="menu-card" href="<?= BASE_URL ?>/student_portal/rooms.php"><i class="fa-solid fa-door-open"></i><span>Xem phòng thi</span></a>
        <a class="menu-card" href="<?= BASE_URL ?>/student_portal/scores.php"><i class="fa-solid fa-chart-column"></i><span>Xem điểm</span></a>
        <a class="menu-card" href="<?= BASE_URL ?>/student_portal/logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Đăng xuất</span></a>
    </section>
</main>
<?php student_portal_render_footer(); ?>
