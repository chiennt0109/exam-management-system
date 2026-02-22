<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';

if (isset($_SESSION['student_id'])) {
    header('Location: ' . BASE_URL . '/student_portal/dashboard.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $day = str_pad((string) max(1, (int) ($_POST['day'] ?? 0)), 2, '0', STR_PAD_LEFT);
    $month = str_pad((string) max(1, (int) ($_POST['month'] ?? 0)), 2, '0', STR_PAD_LEFT);
    $year = (string) max(1900, (int) ($_POST['year'] ?? 0));
    $birthday = sprintf('%s-%s-%s', $year, $month, $day);

    $stmt = $pdo->prepare('SELECT * FROM students WHERE (COALESCE(sbd,"") = :username OR CAST(id AS TEXT) = :username) AND COALESCE(ngaysinh,"") = :birthday LIMIT 1');
    $stmt->execute([':username' => $username, ':birthday' => $birthday]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        $exam = student_portal_default_exam($pdo);
        session_regenerate_id(true);
        $_SESSION['student_id'] = (int) $student['id'];
        $_SESSION['student_name'] = (string) ($student['hoten'] ?? '');
        $_SESSION['student_identifier'] = (string) (($student['sbd'] ?? '') !== '' ? $student['sbd'] : $student['id']);
        $_SESSION['student_exam_default'] = (int) ($exam['id'] ?? 0);
        $_SESSION['student_birthdate'] = (string) ($student['ngaysinh'] ?? '');
        $_SESSION['student_class'] = (string) ($student['lop'] ?? '');
        header('Location: ' . BASE_URL . '/student_portal/dashboard.php');
        exit;
    }

    $error = 'Sai số định danh hoặc ngày sinh.';
}

student_portal_render_header('Đăng nhập cổng học sinh');
?>
<main class="portal-main narrow">
    <section class="card">
        <h1><i class="fa-solid fa-user"></i> Đăng nhập học sinh</h1>
        <?php if ($error !== ''): ?><div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="post" class="form-grid">
            <label>Số định danh/SBD</label>
            <input type="text" name="username" required>

            <label>Ngày sinh</label>
            <div class="inline-3">
                <input type="number" name="day" min="1" max="31" placeholder="Ngày" required>
                <input type="number" name="month" min="1" max="12" placeholder="Tháng" required>
                <input type="number" name="year" min="1900" max="2100" placeholder="Năm" required>
            </div>

            <button type="submit" class="btn primary"><i class="fa-solid fa-right-to-bracket"></i> Đăng nhập</button>
        </form>
    </section>
</main>
<?php student_portal_render_footer(); ?>
