<div class="sidebar">
<ul>

<?php $role = function_exists('current_user_role') ? current_user_role() : strtolower(trim((string) ($_SESSION['role'] ?? $_SESSION['user']['role'] ?? ''))); ?>

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
    <li><a href="<?= BASE_URL ?>/modules/exams/scoring_assignment.php">Phân công chấm</a></li>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'organizer', 'scorer'], true)): ?>
    <li><a href="<?= BASE_URL ?>/modules/exams/scoring.php">Nhập điểm</a></li>
<?php endif; ?>

</ul>
</div>
