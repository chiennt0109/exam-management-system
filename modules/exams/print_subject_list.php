<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';

$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$examStmt = $pdo->prepare('SELECT ten_ky_thi FROM exams WHERE id = :id LIMIT 1');
$examStmt->execute([':id' => $examId]);
$examName = trim((string) ($examStmt->fetchColumn() ?: 'KỲ THI HIỆN TẠI'));

$subjectStmt = $pdo->prepare('SELECT es.subject_id, sub.ten_mon
    FROM exam_subjects es
    INNER JOIN subjects sub ON sub.id = es.subject_id
    WHERE es.exam_id = :exam_id
    ORDER BY es.sort_order ASC, sub.ten_mon ASC');
$subjectStmt->execute([':exam_id' => $examId]);
$subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);

$classStmt = $pdo->prepare('SELECT DISTINCT trim(st.lop) AS lop
    FROM exam_students es
    INNER JOIN students st ON st.id = es.student_id
    WHERE es.exam_id = :exam_id AND es.subject_id IS NULL AND trim(coalesce(st.lop, "")) <> ""
    ORDER BY lop ASC');
$classStmt->execute([':exam_id' => $examId]);
$classOptions = array_values(array_filter(array_map(static fn(array $r): string => (string) ($r['lop'] ?? ''), $classStmt->fetchAll(PDO::FETCH_ASSOC))));

$filterClass = trim((string) ($_GET['class'] ?? ''));
$perPageOptions = [50, 100, 200];
$perPage = (int) ($_GET['per_page'] ?? 100);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 100;
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$export = (string) ($_GET['export'] ?? '');
$exportFile = (string) ($_GET['file'] ?? 'pdf');
if (!in_array($exportFile, ['pdf', 'excel'], true)) {
    $exportFile = 'pdf';
}

$where = ' WHERE es.exam_id = :exam_id AND es.subject_id IS NULL';
$params = [':exam_id' => $examId];
if ($filterClass !== '') {
    $where .= ' AND trim(coalesce(st.lop, "")) = :lop';
    $params[':lop'] = $filterClass;
}

$countStmt = $pdo->prepare('SELECT COUNT(*)
    FROM exam_students es
    INNER JOIN students st ON st.id = es.student_id' . $where);
$countStmt->execute($params);
$totalRows = (int) ($countStmt->fetchColumn() ?: 0);

if ($export === '1') {
    $page = 1;
    $perPage = max(1, $totalRows);
}
$totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listSql = 'SELECT es.student_id, es.sbd, st.hoten, st.lop, st.ngaysinh
    FROM exam_students es
    INNER JOIN students st ON st.id = es.student_id' . $where . '
    ORDER BY st.lop, es.sbd, st.hoten
    LIMIT :limit OFFSET :offset';
$listStmt = $pdo->prepare($listSql);
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v, PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$listRows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$studentIds = array_values(array_filter(array_map(static fn(array $r): int => (int) ($r['student_id'] ?? 0), $listRows)));
$roomByStudentSubject = [];
if (!empty($studentIds) && !empty($subjects)) {
    $ph = implode(',', array_fill(0, count($studentIds), '?'));
    $mapStmt = $pdo->prepare('SELECT es.student_id, es.subject_id, r.ten_phong
        FROM exam_students es
        LEFT JOIN rooms r ON r.id = es.room_id
        WHERE es.exam_id = ? AND es.subject_id IS NOT NULL AND es.student_id IN (' . $ph . ')');
    $mapStmt->execute(array_merge([$examId], $studentIds));
    foreach ($mapStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $sid = (int) ($m['student_id'] ?? 0);
        $subId = (int) ($m['subject_id'] ?? 0);
        if ($sid > 0 && $subId > 0) {
            $roomByStudentSubject[$sid][$subId] = (string) ($m['ten_phong'] ?? '');
        }
    }
}

if ($export === '1') {
    $classesToExport = $filterClass !== '' ? [$filterClass] : $classOptions;
    if (empty($classesToExport)) {
        $classesToExport = ['--'];
    }

    $allRowsByClass = [];
    if ($classesToExport !== ['--']) {
        $phClass = implode(',', array_fill(0, count($classesToExport), '?'));
        $allStmt = $pdo->prepare('SELECT es.student_id, es.sbd, st.hoten, st.lop, st.ngaysinh
            FROM exam_students es
            INNER JOIN students st ON st.id = es.student_id
            WHERE es.exam_id = ? AND es.subject_id IS NULL AND trim(coalesce(st.lop, "")) IN (' . $phClass . ')
            ORDER BY st.lop, es.sbd, st.hoten');
        $allStmt->execute(array_merge([$examId], $classesToExport));
        foreach ($allStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lop = trim((string) ($r['lop'] ?? ''));
            if ($lop === '') {
                continue;
            }
            $allRowsByClass[$lop][] = $r;
        }
    }
    foreach ($classesToExport as $lop) {
        $allRowsByClass[$lop] = $allRowsByClass[$lop] ?? [];
    }

    $allStudentIds = [];
    foreach ($allRowsByClass as $rows) {
        foreach ($rows as $r) {
            $sid = (int) ($r['student_id'] ?? 0);
            if ($sid > 0) {
                $allStudentIds[$sid] = true;
            }
        }
    }
    $allStudentIds = array_keys($allStudentIds);

    $allRoomByStudentSubject = [];
    if (!empty($allStudentIds) && !empty($subjects)) {
        $ph = implode(',', array_fill(0, count($allStudentIds), '?'));
        $mapStmt = $pdo->prepare('SELECT es.student_id, es.subject_id, r.ten_phong
            FROM exam_students es
            LEFT JOIN rooms r ON r.id = es.room_id
            WHERE es.exam_id = ? AND es.subject_id IS NOT NULL AND es.student_id IN (' . $ph . ')');
        $mapStmt->execute(array_merge([$examId], $allStudentIds));
        foreach ($mapStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $sid = (int) ($m['student_id'] ?? 0);
            $subId = (int) ($m['subject_id'] ?? 0);
            if ($sid > 0 && $subId > 0) {
                $allRoomByStudentSubject[$sid][$subId] = (string) ($m['ten_phong'] ?? '');
            }
        }
    }

    if ($exportFile === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="danh_sach_niem_yet_theo_lop_exam_' . $examId . '.xls"');
        $xmlEscape = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        echo '<Styles>';
        echo '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Times New Roman" ss:Size="12"/></Style>';
        echo '<Style ss:ID="HeadL"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1" ss:Size="14"/></Style>';
        echo '<Style ss:ID="HeadR"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1" ss:Size="16"/></Style>';
        echo '<Style ss:ID="HeadS"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1" ss:Size="12"/></Style>';
        echo '<Style ss:ID="TH"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Font ss:Bold="1"/></Style>';
        echo '<Style ss:ID="C"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>';
        echo '<Style ss:ID="L"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Borders><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>';
        echo '</Styles>';

        foreach ($classesToExport as $lop) {
            $rows = $allRowsByClass[$lop] ?? [];
            $sheetName = substr(preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $lop) ?: 'Class', 0, 31);
            echo '<Worksheet ss:Name="' . $xmlEscape($sheetName) . '"><Table ss:ExpandedColumnCount="' . (4 + count($subjects)) . '">';
            foreach ([5,10,32,13] as $w) echo '<Column ss:Width="' . ($w * 6.5) . '"/>';
            foreach ($subjects as $_) echo '<Column ss:Width="120"/>';
            echo '<Row ss:Height="24"><Cell ss:MergeAcross="3" ss:MergeDown="1" ss:StyleID="HeadL"><Data ss:Type="String">' . $xmlEscape("TRƯỜNG THPT CHUYÊN TRẦN PHÚ
" . $examName) . '</Data></Cell><Cell ss:Index="5" ss:MergeAcross="' . max(0, count($subjects) - 1) . '" ss:StyleID="HeadR"><Data ss:Type="String">DANH SÁCH NIÊM YẾT</Data></Cell></Row>';
            echo '<Row ss:Height="20"><Cell ss:Index="5" ss:MergeAcross="' . max(0, count($subjects) - 1) . '" ss:StyleID="HeadS"><Data ss:Type="String">Lớp: ' . $xmlEscape($lop) . '</Data></Cell></Row>';
            echo '<Row ss:Height="10"></Row>';
            echo '<Row><Cell ss:StyleID="TH"><Data ss:Type="String">STT</Data></Cell><Cell ss:StyleID="TH"><Data ss:Type="String">SBD</Data></Cell><Cell ss:StyleID="TH"><Data ss:Type="String">Họ tên</Data></Cell><Cell ss:StyleID="TH"><Data ss:Type="String">Ngày sinh</Data></Cell>';
            foreach ($subjects as $sub) echo '<Cell ss:StyleID="TH"><Data ss:Type="String">' . $xmlEscape((string) ($sub['ten_mon'] ?? '')) . '</Data></Cell>';
            echo '</Row>';
            foreach ($rows as $i => $row) {
                $sid = (int) ($row['student_id'] ?? 0);
                $dob = (string) ($row['ngaysinh'] ?? '');
                $ts = strtotime($dob);
                $dobFmt = $ts ? date('d/m/Y', $ts) : $dob;
                echo '<Row><Cell ss:StyleID="C"><Data ss:Type="Number">' . ($i + 1) . '</Data></Cell><Cell ss:StyleID="C"><Data ss:Type="String">' . $xmlEscape((string) ($row['sbd'] ?? '')) . '</Data></Cell><Cell ss:StyleID="L"><Data ss:Type="String">' . $xmlEscape((string) ($row['hoten'] ?? '')) . '</Data></Cell><Cell ss:StyleID="C"><Data ss:Type="String">' . $xmlEscape($dobFmt) . '</Data></Cell>';
                foreach ($subjects as $sub) {
                    $subId = (int) ($sub['subject_id'] ?? 0);
                    echo '<Cell ss:StyleID="L"><Data ss:Type="String">' . $xmlEscape((string) ($allRoomByStudentSubject[$sid][$subId] ?? '')) . '</Data></Cell>';
                }
                echo '</Row>';
            }
            echo '</Table><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><PageSetup><Layout x:Orientation="Portrait"/><PageMargins x:Top="0.7" x:Bottom="0.7" x:Left="0.7" x:Right="0.7"/></PageSetup><Print><ValidPrinterInfo/><PaperSizeIndex>9</PaperSizeIndex><FitWidth>1</FitWidth><FitHeight>1</FitHeight></Print></WorksheetOptions></Worksheet>';
        }
        echo '</Workbook>';
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>DANH SÁCH NIÊM YẾT</title><style>@page{size:A4 portrait;margin:20mm 15mm}body{font-family:"Times New Roman",serif;margin:0;color:#000}.page{page-break-after:always}.header{display:grid;grid-template-columns:1fr 1fr;column-gap:12px}.left,.right{text-align:center;line-height:1.3}.title{font-size:16px;font-weight:700}.sub{font-size:14px;font-weight:700}.meta{font-size:13px;margin-top:6px}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{border:1px solid #333;padding:4px 6px;font-size:12px}th{font-weight:700;text-align:left}.center{text-align:center}</style></head><body>';
    foreach ($classesToExport as $lop) {
        $rows = $allRowsByClass[$lop] ?? [];
        echo '<section class="page"><div class="header"><div class="left"><div class="sub">TRƯỜNG THPT CHUYÊN TRẦN PHÚ</div><div class="sub">' . htmlspecialchars($examName) . '</div></div><div class="right"><div class="title">DANH SÁCH NIÊM YẾT</div><div class="meta">Lớp: <strong>' . htmlspecialchars($lop) . '</strong></div></div></div>';
        echo '<table><thead><tr><th style="width:6%">STT</th><th style="width:10%">SBD</th><th style="width:34%">Họ tên</th><th style="width:14%">Ngày sinh</th>';
        foreach ($subjects as $sub) {
            echo '<th>' . htmlspecialchars((string) ($sub['ten_mon'] ?? ''), ENT_QUOTES, 'UTF-8') . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $i => $row) {
            $sid = (int) ($row['student_id'] ?? 0);
            $dob = (string) ($row['ngaysinh'] ?? '');
            $ts = strtotime($dob);
            $dobFmt = $ts ? date('d/m/Y', $ts) : $dob;
            echo '<tr><td class="center">' . ($i + 1) . '</td><td class="center">' . htmlspecialchars((string) ($row['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) ($row['hoten'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td class="center">' . htmlspecialchars($dobFmt, ENT_QUOTES, 'UTF-8') . '</td>';
            foreach ($subjects as $sub) {
                $subId = (int) ($sub['subject_id'] ?? 0);
                echo '<td>' . htmlspecialchars((string) ($allRoomByStudentSubject[$sid][$subId] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
            }
            echo '</tr>';
        }
        if (empty($rows)) {
            echo '<tr><td class="center" colspan="' . (4 + count($subjects)) . '">Không có dữ liệu.</td></tr>';
        }
        echo '</tbody></table></section>';
    }
    echo '<script>window.print();</script></body></html>';
    exit;
}


$baseQuery = [
    'class' => $filterClass,
    'per_page' => $perPage,
];

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
<?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
<div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm"><div class="card-header bg-primary text-white d-flex justify-content-between align-items-center"><strong>DANH SÁCH NIÊM YẾT</strong>
<a class="btn btn-light btn-sm" href="<?= BASE_URL ?>/modules/exams/print_rooms.php">Quay lại B6</a>
</div><div class="card-body">
<form method="get" action="<?= BASE_URL ?>/modules/exams/print_subject_list.php" class="row g-2 mb-3">
<div class="col-md-2">
<label class="form-label">Chế độ xem</label>
<select class="form-select" disabled><option>Theo lớp</option></select>
</div>
<div class="col-md-3">
<label class="form-label">Lớp</label>
<select class="form-select" name="class">
<option value="">-- Tất cả lớp --</option>
<?php foreach ($classOptions as $lop): ?>
<option value="<?= htmlspecialchars($lop, ENT_QUOTES, 'UTF-8') ?>" <?= $filterClass === $lop ? 'selected' : '' ?>><?= htmlspecialchars($lop, ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="col-md-2">
<label class="form-label">Số dòng/trang</label>
<select class="form-select" name="per_page"><?php foreach($perPageOptions as $opt): ?><option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option><?php endforeach; ?></select>
</div>
<div class="col-md-1 align-self-end"><button class="btn btn-primary w-100" type="submit">Lọc</button></div>
<div class="col-md-2 align-self-end"><a class="btn btn-outline-success w-100" href="<?= BASE_URL ?>/modules/exams/print_subject_list.php?<?= http_build_query(array_merge($baseQuery, ['export' => 1, 'file' => 'excel'])) ?>">Xuất Excel</a></div>
<div class="col-md-2 align-self-end"><a class="btn btn-outline-secondary w-100" target="_blank" href="<?= BASE_URL ?>/modules/exams/print_subject_list.php?<?= http_build_query(array_merge($baseQuery, ['export' => 1, 'file' => 'pdf'])) ?>">Xuất PDF</a></div>
</form>

<div class="table-responsive">
<table class="table table-bordered table-sm align-middle"><thead><tr><th>STT</th><th>SBD</th><th>Họ tên</th><th>Ngày sinh</th><th>Lớp</th><?php foreach ($subjects as $sub): ?><th><?= htmlspecialchars((string) ($sub['ten_mon'] ?? ''), ENT_QUOTES, 'UTF-8') ?></th><?php endforeach; ?></tr></thead><tbody>
<?php if (empty($listRows)): ?>
<tr><td colspan="<?= 5 + count($subjects) ?>" class="text-center">Không có dữ liệu.</td></tr>
<?php else: foreach ($listRows as $i => $row): $sid=(int)($row['student_id']??0); $dob=(string)($row['ngaysinh']??''); $ts=strtotime($dob); ?>
<tr><td><?= $offset + $i + 1 ?></td><td><?= htmlspecialchars((string) ($row['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($row['hoten'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($ts ? date('d/m/Y',$ts) : $dob, ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($row['lop'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><?php foreach ($subjects as $sub): $subId=(int)($sub['subject_id']??0); ?><td><?= htmlspecialchars((string) ($roomByStudentSubject[$sid][$subId] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><?php endforeach; ?></tr>
<?php endforeach; endif; ?>
</tbody></table></div>

<?php if ($totalPages > 1): ?>
<?php $mk = static fn(int $target): string => BASE_URL . '/modules/exams/print_subject_list.php?' . http_build_query(array_merge($baseQuery, ['page' => $target])); ?>
<nav><ul class="pagination pagination-sm">
<li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><?= $page <= 1 ? '<span class="page-link">Trước</span>' : '<a class="page-link" href="'.htmlspecialchars($mk($page-1), ENT_QUOTES, 'UTF-8').'">Trước</a>' ?></li>
<?php for ($p=max(1,$page-5); $p<=min($totalPages,$page+5); $p++): ?>
<li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($mk($p), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a></li>
<?php endfor; ?>
<li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><?= $page >= $totalPages ? '<span class="page-link">Sau</span>' : '<a class="page-link" href="'.htmlspecialchars($mk($page+1), ENT_QUOTES, 'UTF-8').'">Sau</a>' ?></li>
</ul></nav>
<?php endif; ?>

</div></div></div></div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
