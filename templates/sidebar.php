<div class="sidebar">
<ul>

<?php if ($_SESSION['user']['role'] === 'admin'): ?>
    <li><a href="/diemthi/exam-management-system/index.php">Dashboard</a></li>
    <li><a href="/diemthi/exam-management-system/modules/users/">Quản lý người dùng</a></li>
    <li><a href="/diemthi/exam-management-system/modules/students/">Học sinh</a></li>
    <li><a href="/diemthi/exam-management-system/modules/subjects/">Môn học</a></li>
    <li><a href="/diemthi/exam-management-system/modules/exams/">Tổ chức kỳ thi</a></li>
    <li><a href="/diemthi/exam-management-system/modules/exams/assign_students.php">B2: Gán học sinh</a></li>
    <li><a href="/diemthi/exam-management-system/modules/exams/generate_sbd.php">B3: Sinh SBD</a></li>
    <li><a href="/diemthi/exam-management-system/modules/exams/configure_subjects.php">B4: Cấu hình môn</a></li>
    <li><a href="/diemthi/exam-management-system/modules/exams/distribute_rooms.php">B5: Phân phòng</a></li>
    <li><a href="/diemthi/exam-management-system/modules/exams/print_rooms.php">B6: In DS phòng</a></li>
<?php endif; ?>

<?php if ($_SESSION['user']['role'] === 'organizer'): ?>
    <li><a href="/diemthi/exam-management-system/modules/exams/">Tổ chức kỳ thi</a></li>
    <li><a href="/diemthi/exam-management-system/modules/exams/assign_students.php">B2: Gán học sinh</a></li>
    <li><a href="/diemthi/exam-management-system/modules/exams/generate_sbd.php">B3: Sinh SBD</a></li>
    <li><a href="/diemthi/exam-management-system/modules/exams/configure_subjects.php">B4: Cấu hình môn</a></li>
    <li><a href="/diemthi/exam-management-system/modules/exams/distribute_rooms.php">B5: Phân phòng</a></li>
    <li><a href="/diemthi/exam-management-system/modules/exams/print_rooms.php">B6: In DS phòng</a></li>
<?php endif; ?>

<?php if ($_SESSION['user']['role'] === 'scorer'): ?>
    <li><a href="/diemthi/exam-management-system/modules/scores/">Nhập điểm</a></li>
<?php endif; ?>

</ul>
</div>
