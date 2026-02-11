<div class="sidebar">
<ul>

<?php if ($_SESSION['user']['role'] === 'admin'): ?>
    <li><a href="/diemthi/exam-management-system/index.php">Dashboard</a></li>
    <li><a href="/diemthi/exam-management-system/modules/users/">Quản lý người dùng</a></li>
    <li><a href="/diemthi/exam-management-system/modules/students/">Học sinh</a></li>
    <li><a href="/diemthi/exam-management-system/modules/subjects/">Môn học</a></li>
<?php endif; ?>

<?php if ($_SESSION['user']['role'] === 'organizer'): ?>
    <li><a href="/diemthi/exam-management-system/modules/exams/">Tổ chức kỳ thi</a></li>
    <li><a href="/diemthi/exam-management-system/modules/exams/rooms.php">Phân phòng thi</a></li>
<?php endif; ?>

<?php if ($_SESSION['user']['role'] === 'scorer'): ?>
    <li><a href="/diemthi/exam-management-system/modules/scores/">Nhập điểm</a></li>
<?php endif; ?>

</ul>
</div>
