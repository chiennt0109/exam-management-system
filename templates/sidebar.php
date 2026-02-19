<?php
// Lấy role
$role = function_exists('current_user_role')
    ? current_user_role()
    : strtolower(trim((string) ($_SESSION['role'] ?? $_SESSION['user']['role'] ?? '')));

// Lấy exam_mode hiện tại
$currentExamMode = 1;

if (function_exists('getCurrentExamId')) {
    require_once BASE_PATH . '/core/db.php';

    $eid = getCurrentExamId();

    if ($eid > 0) {
        $stmtMode = $pdo->prepare("
            SELECT exam_mode 
            FROM exams 
            WHERE id = :id 
            LIMIT 1
        ");
        $stmtMode->execute([':id' => $eid]);

        $m = (int) ($stmtMode->fetchColumn() ?: 1);
        $currentExamMode = in_array($m, [1, 2], true) ? $m : 1;
    }
}
?>

<div class="sidebar">
<ul>

<?php $role = function_exists('current_user_role') ? current_user_role() : strtolower(trim((string) ($_SESSION['role'] ?? $_SESSION['user']['role'] ?? ''))); ?>
<?php $currentExamMode = 1; if (function_exists('getCurrentExamId')) { require_once BASE_PATH . '/core/db.php'; $eid = getCurrentExamId(); if ($eid > 0) { $stmtMode = $pdo->prepare('SELECT exam_mode FROM exams WHERE id = :id LIMIT 1'); $stmtMode->execute([':id' => $eid]); $m = (int) ($stmtMode->fetchColumn() ?: 1); $currentExamMode = in_array($m, [1,2], true) ? $m : 1; } } ?>

<li><a href="<?= BASE_URL ?>/index.php">Dashboard</a></li>

<?php if ($role === 'admin'): ?>
    <li><a href="<?= BASE_URL ?>/modules/users/">Quản lý người dùng</a></li>
    <li><a href="<?= BASE_URL ?>/modules/students/">Học sinh</a></li>
    <li><a href="<?= BASE_URL ?>/modules/subjects/">Môn học</a></li>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'organizer'], true)): ?>
<li>
    <details>
        <summary>Tổ chức kỳ thi</summary>
        <ul>
            <li><a href="<?= BASE_URL ?>/modules/exams/">B1: Tạo kỳ thi</a></li>
            <li><a href="<?= BASE_URL ?>/modules/exams/assign_students.php">B2: Gán học sinh</a></li>
            <li><a href="<?= BASE_URL ?>/modules/exams/generate_sbd.php">B3: Sinh SBD</a></li>
            <li><a href="<?= BASE_URL ?>/modules/exams/configure_subjects.php">B4: Cấu hình môn</a></li>
            <li><a href="<?= BASE_URL ?>/modules/exams/distribute_rooms.php">B5: Phân phòng</a></li>            
            <li><a href="<?= BASE_URL ?>/modules/exams/print_rooms.php">B6: In danh sách</a></li>
        </ul>
    </details>
</li>
<li><a href="<?= BASE_URL ?>/modules/exams/scoring_assignment.php">Phân công chấm</a></li>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'organizer', 'scorer'], true)): ?>
    <li><a href="<?= BASE_URL ?>/modules/exams/scoring.php">Nhập điểm</a></li>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'organizer'], true)): ?>
<li>
    <details>
        <summary>Thống kê</summary>
        <ul>
            <li><a href="<?= BASE_URL ?>/modules/exams/stats_score_ranges.php">Phổ điểm theo môn</a></li>
            <li><a href="<?= BASE_URL ?>/modules/exams/stats_subject_rankings.php">Bảng điểm xếp hạng</a></li>
            <li><a href="<?= BASE_URL ?>/modules/exams/stats_combinations.php">Thống kê theo tổ hợp</a></li>
            <?php if ($currentExamMode === 2): ?><li><a href="<?= BASE_URL ?>/modules/exams/report_subject_stats.php">Thống kê mode 2</a></li><?php endif; ?>
        </ul>
    </details>
</li>
<?php endif; ?>

</ul>
</div>
