<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_role(['admin', 'organizer']);

$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$examStmt = $pdo->prepare('SELECT ten_ky_thi FROM exams WHERE id = :id LIMIT 1');
$examStmt->execute([':id' => $examId]);
$examName = trim((string) ($examStmt->fetchColumn() ?: 'KỲ THI HIỆN TẠI'));

$classStmt = $pdo->prepare('SELECT DISTINCT trim(st.lop) AS lop
    FROM exam_students es
    INNER JOIN students st ON st.id = es.student_id
    WHERE es.exam_id = :exam_id AND es.subject_id IS NULL AND trim(coalesce(st.lop, "")) <> ""
    ORDER BY lop ASC');
$classStmt->execute([':exam_id' => $examId]);
$classOptions = array_values(array_filter(array_map(static fn(array $r): string => (string) ($r['lop'] ?? ''), $classStmt->fetchAll(PDO::FETCH_ASSOC))));

$filterClass = trim((string) ($_GET['class'] ?? ''));
$export = (string) ($_GET['export'] ?? '');
$paper = (string) ($_GET['paper'] ?? 'A4L');
$allowedPaper = ['A3L', 'A3P', 'A4L', 'A4P'];
if (!in_array($paper, $allowedPaper, true)) {
    $paper = 'A4L';
}

$subjectStmt = $pdo->prepare('SELECT cfg.subject_id, sub.ten_mon, MAX(COALESCE(cfg.component_count,1)) AS component_count,
        MIN(COALESCE(es.sort_order, 999999)) AS sort_order
    FROM exam_subject_config cfg
    INNER JOIN subjects sub ON sub.id = cfg.subject_id
    LEFT JOIN exam_subjects es ON es.exam_id = cfg.exam_id AND es.subject_id = cfg.subject_id
    WHERE cfg.exam_id = :exam_id
    GROUP BY cfg.subject_id, sub.ten_mon
    ORDER BY sort_order ASC, sub.ten_mon ASC');
$subjectStmt->execute([':exam_id' => $examId]);
$subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);

$where = ' WHERE es.exam_id = :exam_id AND es.subject_id IS NULL';
$params = [':exam_id' => $examId];
if ($filterClass !== '') {
    $where .= ' AND trim(coalesce(st.lop, "")) = :lop';
    $params[':lop'] = $filterClass;
}

$studentSql = 'SELECT es.student_id, es.sbd, st.hoten, st.ngaysinh, st.lop
    FROM exam_students es
    INNER JOIN students st ON st.id = es.student_id' . $where . '
    ORDER BY st.lop, es.sbd, st.hoten';
$studentStmt = $pdo->prepare($studentSql);
$studentStmt->execute($params);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

$studentIds = array_values(array_filter(array_map(static fn(array $r): int => (int) ($r['student_id'] ?? 0), $students)));
$scoreMap = [];
if (!empty($studentIds) && !empty($subjects)) {
    $ph = implode(',', array_fill(0, count($studentIds), '?'));
    $scoreStmt = $pdo->prepare('SELECT student_id, subject_id, component_1, component_2, component_3, total_score, diem
        FROM scores
        WHERE exam_id = ? AND student_id IN (' . $ph . ')');
    $scoreStmt->execute(array_merge([$examId], $studentIds));
    foreach ($scoreStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = (int) ($r['student_id'] ?? 0);
        $subId = (int) ($r['subject_id'] ?? 0);
        if ($sid > 0 && $subId > 0) {
            $scoreMap[$sid][$subId] = $r;
        }
    }
}

$formatDate = static function(string $raw): string {
    $ts = strtotime($raw);
    return $ts ? date('d/m/Y', $ts) : $raw;
};
$formatScore = static function($v): string {
    if ($v === null || $v === '') {
        return '';
    }
    return number_format((float) $v, 2);
};

$subjectColumns = [];
foreach ($subjects as $sub) {
    $count = max(1, min(3, (int) ($sub['component_count'] ?? 1)));
    $cols = [];
    if ($count === 1) {
        $cols[] = ['key' => 'total', 'label' => (string) ($sub['ten_mon'] ?? '')];
    } else {
        $cols[] = ['key' => 'component_1', 'label' => 'TL'];
        if ($count >= 2) {
            $cols[] = ['key' => 'component_2', 'label' => 'TN'];
        }
        if ($count >= 3) {
            $cols[] = ['key' => 'component_3', 'label' => 'Nói'];
        }
        $cols[] = ['key' => 'total', 'label' => 'Tổng'];
    }
    $subjectColumns[] = [
        'subject_id' => (int) ($sub['subject_id'] ?? 0),
        'name' => (string) ($sub['ten_mon'] ?? ''),
        'columns' => $cols,
    ];
}

if ($export === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bang_diem_exam_' . $examId . '.xls"');
    $x = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');

    $detailColCount = 0;
    foreach ($subjectColumns as $sc) {
        $detailColCount += count($sc['columns']);
    }
    $totalCols = 4 + $detailColCount;

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<?mso-application progid="Excel.Sheet"?>';
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    echo '<Styles><Style ss:ID="TH"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1"/><Borders><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style><Style ss:ID="TD"><Alignment ss:Vertical="Center"/><Borders><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style><Style ss:ID="C"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style></Styles>';
    echo '<Worksheet ss:Name="BangDiem"><Table ss:ExpandedColumnCount="' . $totalCols . '">';
    echo '<Row><Cell ss:MergeAcross="2" ss:StyleID="TH"><Data ss:Type="String">TRƯỜNG THPT CHUYÊN TRẦN PHÚ</Data></Cell><Cell ss:Index="4" ss:MergeAcross="' . ($totalCols - 4) . '" ss:MergeDown="1" ss:StyleID="TH"><Data ss:Type="String">BẢNG ĐIỂM</Data></Cell></Row>';
    echo '<Row><Cell ss:MergeAcross="2" ss:StyleID="TH"><Data ss:Type="String">' . $x($examName) . '</Data></Cell></Row>';

    echo '<Row>';
    echo '<Cell ss:MergeDown="1" ss:StyleID="TH"><Data ss:Type="String">STT</Data></Cell>';
    echo '<Cell ss:MergeDown="1" ss:StyleID="TH"><Data ss:Type="String">SBD</Data></Cell>';
    echo '<Cell ss:MergeDown="1" ss:StyleID="TH"><Data ss:Type="String">Họ và tên</Data></Cell>';
    echo '<Cell ss:MergeDown="1" ss:StyleID="TH"><Data ss:Type="String">Ngày sinh</Data></Cell>';
    foreach ($subjectColumns as $sc) {
        $span = count($sc['columns']);
        if ($span > 1) {
            echo '<Cell ss:MergeAcross="' . ($span - 1) . '" ss:StyleID="TH"><Data ss:Type="String">' . $x($sc['name']) . '</Data></Cell>';
        } else {
            echo '<Cell ss:MergeDown="1" ss:StyleID="TH"><Data ss:Type="String">' . $x($sc['name']) . '</Data></Cell>';
        }
    }
    echo '</Row>';

    echo '<Row>';
    foreach ($subjectColumns as $sc) {
        if (count($sc['columns']) > 1) {
            foreach ($sc['columns'] as $c) {
                echo '<Cell ss:StyleID="TH"><Data ss:Type="String">' . $x($c['label']) . '</Data></Cell>';
            }
        }
    }
    echo '</Row>';

    foreach ($students as $i => $st) {
        $sid = (int) ($st['student_id'] ?? 0);
        echo '<Row>';
        echo '<Cell ss:StyleID="C"><Data ss:Type="Number">' . ($i + 1) . '</Data></Cell>';
        echo '<Cell ss:StyleID="C"><Data ss:Type="String">' . $x((string) ($st['sbd'] ?? '')) . '</Data></Cell>';
        echo '<Cell ss:StyleID="TD"><Data ss:Type="String">' . $x((string) ($st['hoten'] ?? '')) . '</Data></Cell>';
        echo '<Cell ss:StyleID="C"><Data ss:Type="String">' . $x($formatDate((string) ($st['ngaysinh'] ?? ''))) . '</Data></Cell>';
        foreach ($subjectColumns as $sc) {
            $subId = (int) $sc['subject_id'];
            $score = $scoreMap[$sid][$subId] ?? [];
            foreach ($sc['columns'] as $c) {
                $val = '';
                if ($c['key'] === 'total') {
                    $val = $formatScore($score['total_score'] ?? ($score['diem'] ?? null));
                } else {
                    $val = $formatScore($score[$c['key']] ?? null);
                }
                echo '<Cell ss:StyleID="C"><Data ss:Type="String">' . $x($val) . '</Data></Cell>';
            }
        }
        echo '</Row>';
    }
    echo '</Table></Worksheet></Workbook>';
    exit;
}

if ($export === 'pdf') {
    [$paperSize, $paperOrientation] = match ($paper) {
        'A3L' => ['A3', 'landscape'],
        'A3P' => ['A3', 'portrait'],
        'A4P' => ['A4', 'portrait'],
        default => ['A4', 'landscape'],
    };
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh'));

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>BẢNG ĐIỂM</title><style>@page{size:' . $paperSize . ' ' . $paperOrientation . ';margin:14mm 10mm}body{font-family:"Times New Roman",serif}.center{text-align:center}.right{text-align:right}.sheet{width:100%}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{border:1px solid #333;padding:4px 6px;font-size:12px}th{font-weight:700;text-align:center}thead{display:table-header-group}tr{page-break-inside:avoid}.footer{margin-top:24px}.sign{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:8px}</style></head><body>';
    echo '<div class="sheet">';
    echo '<table><tr><td colspan="3" class="center"><strong>TRƯỜNG THPT CHUYÊN TRẦN PHÚ</strong></td><td colspan="' . (1 + array_sum(array_map(static fn($s) => count($s['columns']), $subjectColumns))) . '" rowspan="2" class="center"><strong>BẢNG ĐIỂM</strong></td></tr>';
    echo '<tr><td colspan="3" class="center"><strong>' . htmlspecialchars($examName, ENT_QUOTES, 'UTF-8') . '</strong></td></tr></table>';

    echo '<table><thead>';
    echo '<tr><th rowspan="2">STT</th><th rowspan="2">SBD</th><th rowspan="2">Họ và tên</th><th rowspan="2">Ngày sinh</th>';
    foreach ($subjectColumns as $sc) {
        if (count($sc['columns']) > 1) {
            echo '<th colspan="' . count($sc['columns']) . '">' . htmlspecialchars($sc['name'], ENT_QUOTES, 'UTF-8') . '</th>';
        } else {
            echo '<th rowspan="2">' . htmlspecialchars($sc['name'], ENT_QUOTES, 'UTF-8') . '</th>';
        }
    }
    echo '</tr><tr>';
    foreach ($subjectColumns as $sc) {
        if (count($sc['columns']) > 1) {
            foreach ($sc['columns'] as $c) {
                echo '<th>' . htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') . '</th>';
            }
        }
    }
    echo '</tr></thead><tbody>';
    foreach ($students as $i => $st) {
        $sid = (int) ($st['student_id'] ?? 0);
        echo '<tr><td class="center">' . ($i + 1) . '</td><td class="center">' . htmlspecialchars((string) ($st['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) ($st['hoten'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td class="center">' . htmlspecialchars($formatDate((string) ($st['ngaysinh'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td>';
        foreach ($subjectColumns as $sc) {
            $subId = (int) $sc['subject_id'];
            $score = $scoreMap[$sid][$subId] ?? [];
            foreach ($sc['columns'] as $c) {
                $val = $c['key'] === 'total'
                    ? $formatScore($score['total_score'] ?? ($score['diem'] ?? null))
                    : $formatScore($score[$c['key']] ?? null);
                echo '<td class="center">' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '</td>';
            }
        }
        echo '</tr>';
    }
    if (empty($students)) {
        $colspan = 4 + array_sum(array_map(static fn($s) => count($s['columns']), $subjectColumns));
        echo '<tr><td colspan="' . $colspan . '" class="center">Không có dữ liệu.</td></tr>';
    }
    echo '</tbody></table>';

    echo '<div class="footer"><div class="right">Hải Phòng, ngày ' . $now->format('d') . ' tháng ' . $now->format('m') . ' năm ' . $now->format('Y') . '</div><div class="sign"><div class="center"><strong>Người lập</strong></div><div class="center"><strong>BAN GIÁM HIỆU</strong></div></div></div>';
    echo '</div><script>window.print();</script></body></html>';
    exit;
}

$baseQuery = ['class' => $filterClass, 'paper' => $paper];
require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
<?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
<div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm">
<div class="card-header bg-primary text-white"><strong>Bảng điểm</strong></div>
<div class="card-body">
<form method="get" class="row g-2 mb-3">
    <div class="col-md-4"><label class="form-label">Lớp</label><select class="form-select" name="class"><option value="">-- Tất cả lớp --</option><?php foreach ($classOptions as $lop): ?><option value="<?= htmlspecialchars($lop, ENT_QUOTES, 'UTF-8') ?>" <?= $filterClass === $lop ? 'selected' : '' ?>><?= htmlspecialchars($lop, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">Khổ giấy</label><select class="form-select" name="paper"><?php foreach (['A3L'=>'A3 ngang','A3P'=>'A3 dọc','A4L'=>'A4 ngang','A4P'=>'A4 dọc'] as $k=>$lbl): ?><option value="<?= $k ?>" <?= $paper===$k?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2 align-self-end"><button class="btn btn-primary w-100">Lọc</button></div>
    <div class="col-md-1 align-self-end"><a class="btn btn-outline-success w-100" href="<?= BASE_URL ?>/modules/exams/scoreboard.php?<?= http_build_query(array_merge($baseQuery, ['export' => 'excel'])) ?>">Excel</a></div>
    <div class="col-md-2 align-self-end"><a class="btn btn-outline-secondary w-100" target="_blank" href="<?= BASE_URL ?>/modules/exams/scoreboard.php?<?= http_build_query(array_merge($baseQuery, ['export' => 'pdf'])) ?>">PDF</a></div>
</form>
<div class="table-responsive"><table class="table table-bordered table-sm align-middle"><thead>
<tr><th rowspan="2">STT</th><th rowspan="2">SBD</th><th rowspan="2">Họ và tên</th><th rowspan="2">Ngày sinh</th><?php foreach ($subjectColumns as $sc): ?><?php if (count($sc['columns']) > 1): ?><th colspan="<?= count($sc['columns']) ?>" class="text-center"><?= htmlspecialchars($sc['name'], ENT_QUOTES, 'UTF-8') ?></th><?php else: ?><th rowspan="2" class="text-center"><?= htmlspecialchars($sc['name'], ENT_QUOTES, 'UTF-8') ?></th><?php endif; ?><?php endforeach; ?></tr>
<tr><?php foreach ($subjectColumns as $sc): ?><?php if (count($sc['columns']) > 1): ?><?php foreach ($sc['columns'] as $c): ?><th class="text-center"><?= htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') ?></th><?php endforeach; ?><?php endif; ?><?php endforeach; ?></tr>
</thead><tbody>
<?php if (empty($students)): ?>
<tr><td colspan="<?= 4 + array_sum(array_map(static fn($s): int => count($s['columns']), $subjectColumns)) ?>" class="text-center">Không có dữ liệu.</td></tr>
<?php else: foreach ($students as $i => $st): $sid = (int) ($st['student_id'] ?? 0); ?>
<tr><td><?= $i + 1 ?></td><td><?= htmlspecialchars((string) ($st['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($st['hoten'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($formatDate((string) ($st['ngaysinh'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td><?php foreach ($subjectColumns as $sc): $subId = (int) $sc['subject_id']; $score = $scoreMap[$sid][$subId] ?? []; foreach ($sc['columns'] as $c): $val = $c['key']==='total' ? $formatScore($score['total_score'] ?? ($score['diem'] ?? null)) : $formatScore($score[$c['key']] ?? null); ?><td class="text-center"><?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?></td><?php endforeach; endforeach; ?></tr>
<?php endforeach; endif; ?>
</tbody></table></div>
</div></div></div></div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
