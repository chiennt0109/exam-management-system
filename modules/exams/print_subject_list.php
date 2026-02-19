<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';

$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$examStmt = $pdo->prepare('SELECT ten_ky_thi, exam_mode FROM exams WHERE id = :id LIMIT 1');
$examStmt->execute([':id' => $examId]);
$exam = $examStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$examName = trim((string) ($exam['ten_ky_thi'] ?? ''));
if ($examName === '') {
    $examName = 'KỲ THI HIỆN TẠI';
}

$classStmt = $pdo->prepare('SELECT DISTINCT trim(st.lop) AS lop
    FROM exam_students es
    INNER JOIN students st ON st.id = es.student_id
    WHERE es.exam_id = :exam_id AND trim(coalesce(st.lop, "")) <> ""
    ORDER BY lop');
$classStmt->execute([':exam_id' => $examId]);
$classOptions = array_values(array_filter(array_map(static fn($r) => (string) ($r['lop'] ?? ''), $classStmt->fetchAll(PDO::FETCH_ASSOC))));

$className = trim((string) ($_GET['class'] ?? ($classOptions[0] ?? '')));
$export = (string) ($_GET['export'] ?? '');

$list = [];
if ($className !== '') {
    $listStmt = $pdo->prepare('SELECT es.sbd, st.hoten, st.lop, st.ngaysinh
        FROM exam_students es
        INNER JOIN students st ON st.id = es.student_id
        WHERE es.exam_id = :exam_id AND trim(coalesce(st.lop, "")) = :lop
        ORDER BY es.sbd, st.hoten');
    $listStmt->execute([':exam_id' => $examId, ':lop' => $className]);
    foreach ($listStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $dob = (string) ($r['ngaysinh'] ?? '');
        $ts = strtotime($dob);
        $list[] = [
            'sbd' => (string) ($r['sbd'] ?? ''),
            'hoten' => (string) ($r['hoten'] ?? ''),
            'lop' => (string) ($r['lop'] ?? ''),
            'ngaysinh' => $ts ? date('d/m/Y', $ts) : $dob,
        ];
    }
}

if ($export === '1') {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>In theo lớp</title><style>@page{size:A4 portrait;margin:20mm 15mm}body{font-family:"Times New Roman",serif;margin:0;color:#000}.page{page-break-after:always}.header{display:grid;grid-template-columns:1fr 1fr;column-gap:12px}.left,.right{text-align:center;line-height:1.3}.title{font-size:16px;font-weight:700}.sub{font-size:14px;font-weight:700}.meta{font-size:13px;margin-top:6px}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{border:1px solid #333;padding:4px 6px;font-size:12px}th{font-weight:700;text-align:center}.center{text-align:center}.footer{text-align:right;margin-top:8px;font-size:13px}.sign{display:inline-block;text-align:center}.sig-space{height:54px}</style></head><body>';
    echo '<section class="page">';
    echo '<div class="header"><div class="left"><div class="sub">TRƯỜNG THPT CHUYÊN TRẦN PHÚ</div><div class="sub">' . htmlspecialchars($examName) . '</div></div>';
    echo '<div class="right"><div class="title">DANH SÁCH NIÊM YẾT</div><div class="meta">Lớp: <strong>' . htmlspecialchars($className) . '</strong></div></div></div>';
    echo '<table><thead><tr><th style="width:8%">STT</th><th style="width:14%">SBD</th><th>Họ và tên</th><th style="width:17%">Ngày sinh</th><th style="width:14%">Lớp</th><th style="width:18%">Ghi chú</th></tr></thead><tbody>';
    foreach ($list as $i => $row) {
        echo '<tr><td class="center">' . ($i + 1) . '</td><td class="center">' . htmlspecialchars($row['sbd']) . '</td><td>' . htmlspecialchars($row['hoten']) . '</td><td class="center">' . htmlspecialchars($row['ngaysinh']) . '</td><td class="center">' . htmlspecialchars($row['lop']) . '</td><td></td></tr>';
    }
    if (empty($list)) {
        echo '<tr><td colspan="6" class="center">Không có dữ liệu.</td></tr>';
    }
    echo '</tbody></table>';
    echo '<div class="footer"><div class="sign"><div><em>Hải Phòng, ngày ... tháng ... năm 2026</em></div><div><strong>CHỦ TỊCH HỘI ĐỒNG</strong></div><div class="sig-space"></div></div></div>';
    echo '</section></body></html>';
    exit;
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
<?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
<div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm"><div class="card-header bg-primary text-white d-flex justify-content-between align-items-center"><strong>B6b: Danh sách niêm yết theo lớp</strong>
<a class="btn btn-light btn-sm" href="<?= BASE_URL ?>/modules/exams/print_rooms.php">Quay lại B6</a>
</div><div class="card-body">
<form method="get" action="<?= BASE_URL ?>/modules/exams/print_subject_list.php" class="row g-2 mb-3">
<div class="col-md-4"><label class="form-label">Lớp</label><select class="form-select" name="class">
<?php foreach ($classOptions as $lop): ?>
<option value="<?= htmlspecialchars($lop, ENT_QUOTES, 'UTF-8') ?>" <?= $className === $lop ? 'selected' : '' ?>><?= htmlspecialchars($lop, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
</select></div>
<div class="col-md-2 align-self-end"><button class="btn btn-primary w-100" type="submit">Xem</button></div>
<div class="col-md-3 align-self-end"><a class="btn btn-outline-secondary w-100" target="_blank" href="<?= BASE_URL ?>/modules/exams/print_subject_list.php?<?= http_build_query(['class' => $className, 'export' => 1]) ?>">In danh sách</a></div>
</form>
<table class="table table-sm table-bordered"><thead><tr><th>STT</th><th>SBD</th><th>Họ tên</th><th>Ngày sinh</th><th>Lớp</th></tr></thead><tbody>
<?php if (empty($list)): ?><tr><td colspan="5" class="text-center">Không có dữ liệu.</td></tr><?php else: foreach($list as $i => $row): ?>
<tr><td><?= $i + 1 ?></td><td><?= htmlspecialchars($row['sbd'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['hoten'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['ngaysinh'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['lop'], ENT_QUOTES, 'UTF-8') ?></td></tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div></div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
