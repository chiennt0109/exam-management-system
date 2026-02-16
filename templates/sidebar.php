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
    <li><a href="<?= BASE_URL ?>/modules/exams/">Tổ chức kỳ thi</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/assign_students.php">B2: Gán học sinh</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/generate_sbd.php">B3: Sinh SBD</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/configure_subjects.php">B4: Cấu hình môn</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/distribute_rooms.php">B5: Phân phòng</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/print_rooms.php">B6: In DS phòng</a></li>
    <?php if ($currentExamMode === 2): ?>
        <li><a href="<?= BASE_URL ?>/modules/exams/print_subject_list.php">B6b: DS theo môn</a></li>
        <li><a href="<?= BASE_URL ?>/modules/exams/report_subject_stats.php">Thống kê theo môn</a></li>
    <?php endif; ?>
    <li><a href="<?= BASE_URL ?>/modules/exams/scoring_assignment.php">Phân công chấm</a></li>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'organizer', 'scorer'], true)): ?>
    <li><a href="<?= BASE_URL ?>/modules/exams/scoring.php">Nhập điểm</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/import_scores.php">Import điểm Excel</a></li>
<?php endif; ?>

</ul>
</div>
