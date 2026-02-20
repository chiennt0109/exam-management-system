<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

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
        <div>
            <div class="portal-title">CỔNG HỌC SINH</div>
            <div class="portal-subtitle">Hệ thống tra cứu thông tin kỳ thi</div>
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
