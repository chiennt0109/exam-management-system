<div class="sidebar">
<ul>

<?php if ($_SESSION['user']['role'] === 'admin'): ?>
    <li><a href="<?= htmlspecialchars(app_url('index.php'), ENT_QUOTES, 'UTF-8') ?>">Dashboard</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/users/'), ENT_QUOTES, 'UTF-8') ?>">Quản lý người dùng</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/students/'), ENT_QUOTES, 'UTF-8') ?>">Học sinh</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/subjects/'), ENT_QUOTES, 'UTF-8') ?>">Môn học</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/exams/'), ENT_QUOTES, 'UTF-8') ?>">Tổ chức kỳ thi</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/exams/assign_students.php'), ENT_QUOTES, 'UTF-8') ?>">B2: Gán học sinh</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/exams/generate_sbd.php'), ENT_QUOTES, 'UTF-8') ?>">B3: Sinh SBD</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/exams/configure_subjects.php'), ENT_QUOTES, 'UTF-8') ?>">B4: Cấu hình môn</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/exams/distribute_rooms.php'), ENT_QUOTES, 'UTF-8') ?>">B5: Phân phòng</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/exams/print_rooms.php'), ENT_QUOTES, 'UTF-8') ?>">B6: In DS phòng</a></li>
<?php endif; ?>

<?php if ($_SESSION['user']['role'] === 'organizer'): ?>
    <li><a href="<?= htmlspecialchars(app_url('modules/exams/'), ENT_QUOTES, 'UTF-8') ?>">Tổ chức kỳ thi</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/exams/assign_students.php'), ENT_QUOTES, 'UTF-8') ?>">B2: Gán học sinh</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/exams/generate_sbd.php'), ENT_QUOTES, 'UTF-8') ?>">B3: Sinh SBD</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/exams/configure_subjects.php'), ENT_QUOTES, 'UTF-8') ?>">B4: Cấu hình môn</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/exams/distribute_rooms.php'), ENT_QUOTES, 'UTF-8') ?>">B5: Phân phòng</a></li>
    <li><a href="<?= htmlspecialchars(app_url('modules/exams/print_rooms.php'), ENT_QUOTES, 'UTF-8') ?>">B6: In DS phòng</a></li>
<?php endif; ?>

<?php if ($_SESSION['user']['role'] === 'scorer'): ?>
    <?php if (is_dir(__DIR__ . '/../modules/scores')): ?>
        <li><a href="<?= htmlspecialchars(app_url('modules/scores/'), ENT_QUOTES, 'UTF-8') ?>">Nhập điểm</a></li>
    <?php else: ?>
        <li><span style="color:#777;display:block;">Nhập điểm (chưa cấu hình)</span></li>
    <?php endif; ?>
<?php endif; ?>

</ul>
</div>
