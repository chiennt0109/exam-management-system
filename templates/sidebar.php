<div class="sidebar">
<ul>

<?php if ($_SESSION['user']['role'] === 'admin'): ?>
    <li><a href="<?= BASE_URL ?>/index.php">Dashboard</a></li>
    <li><a href="<?= BASE_URL ?>/modules/users/">Quản lý người dùng</a></li>
    <li><a href="<?= BASE_URL ?>/modules/students/">Học sinh</a></li>
    <li><a href="<?= BASE_URL ?>/modules/subjects/">Môn học</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/">Tổ chức kỳ thi</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/assign_students.php">B2: Gán học sinh</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/generate_sbd.php">B3: Sinh SBD</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/configure_subjects.php">B4: Cấu hình môn</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/distribute_rooms.php">B5: Phân phòng</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/print_rooms.php">B6: In DS phòng</a></li>
<?php endif; ?>

<?php if ($_SESSION['user']['role'] === 'organizer'): ?>
    <li><a href="<?= BASE_URL ?>/modules/exams/">Tổ chức kỳ thi</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/assign_students.php">B2: Gán học sinh</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/generate_sbd.php">B3: Sinh SBD</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/configure_subjects.php">B4: Cấu hình môn</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/distribute_rooms.php">B5: Phân phòng</a></li>
    <li><a href="<?= BASE_URL ?>/modules/exams/print_rooms.php">B6: In DS phòng</a></li>
<?php endif; ?>

<?php if ($_SESSION['user']['role'] === 'scorer'): ?>
    <?php if (is_dir(__DIR__ . '/../modules/scores')): ?>
        <li><a href="<?= BASE_URL ?>/modules/scores/">Nhập điểm</a></li>
    <?php else: ?>
        <li><span style="color:#777;display:block;">Nhập điểm (chưa cấu hình)</span></li>
    <?php endif; ?>
<?php endif; ?>

</ul>
</div>
