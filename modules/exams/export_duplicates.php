<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';

require_once BASE_PATH . '/modules/exams/_common.php';

$examId = max(0, (int) ($_GET['exam_id'] ?? 0));
if ($examId <= 0) {
    exams_set_flash('error', 'Vui lòng chọn kỳ thi để xuất lỗi SBD.');
    header('Location: ' . BASE_URL . '/modules/exams/check_duplicates.php');
    exit;
}

$rows = checkDuplicateSBD($pdo, $examId);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="duplicate_sbd_exam_' . $examId . '.csv"');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'wb');
fputcsv($out, ['SBD', 'Họ tên', 'Mã học sinh', 'Lớp']);
foreach ($rows as $r) {
    fputcsv($out, [
        (string) ($r['sbd'] ?? ''),
        (string) ($r['hoten'] ?? ''),
        (string) ($r['student_id'] ?? ''),
        (string) ($r['lop'] ?? ''),
    ]);
}
fclose($out);
exit;
