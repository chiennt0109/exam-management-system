<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

function student_portal_logo_data_uri(): string
{
    static $logo = null;
    if ($logo !== null) {
        return $logo;
    }

    $file = BASE_PATH . '/image_string.txt';
    if (!is_file($file)) {
        $logo = '';
        return $logo;
    }

    $raw = trim((string) file_get_contents($file));
    if ($raw === '' || stripos($raw, 'data:image/') !== 0) {
        $logo = '';
        return $logo;
    }

    $logo = $raw;
    return $logo;
}


function student_portal_render_header(string $title): void
{
    ?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/student_portal/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="portal-shell">
    <header class="portal-header">
        <div class="portal-header-brand">
            <?php $portalLogo = student_portal_logo_data_uri(); ?>
            <?php if ($portalLogo !== ''): ?>
                <img src="<?= htmlspecialchars($portalLogo, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="portal-logo">
            <?php endif; ?>
            <div>
                <div class="portal-title">CỔNG HỌC SINH</div>
                <div class="portal-subtitle">Hệ thống tra cứu thông tin kỳ thi</div>
            </div>
        </div>
    </header>
<?php
}

function student_portal_render_footer(): void
{
    ?>
</div>
</body>
</html>
<?php
}


function student_portal_render_student_info(PDO $pdo): void
{
    if (!isset($_SESSION['student_id'])) {
        return;
    }

    $student = student_portal_student();
    $profile = student_portal_student_profile($pdo, (int) $student['id']);
    $birth = student_portal_format_date((string) ($student['ngaysinh'] !== '' ? $student['ngaysinh'] : $profile['ngaysinh']));
    $lop = (string) ($student['lop'] !== '' ? $student['lop'] : $profile['lop']);

    ?>
    <section class="card student-info-card">
        <h1>Thông tin học sinh</h1>
        <p><strong>Họ và tên:</strong> <?= htmlspecialchars((string) $student['name'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Số định danh:</strong> <?= htmlspecialchars((string) $student['identifier'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Ngày sinh:</strong> <?= htmlspecialchars($birth, ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Lớp:</strong> <?= htmlspecialchars($lop, ENT_QUOTES, 'UTF-8') ?></p>
    </section>
    <?php
}

