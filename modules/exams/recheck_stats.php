<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';

$pdo->exec('CREATE TABLE IF NOT EXISTS student_recheck_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exam_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    subject_id INTEGER NOT NULL,
    room_id INTEGER,
    component_1 REAL,
    component_2 REAL,
    component_3 REAL,
    note TEXT,
    status TEXT DEFAULT "pending",
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(exam_id, student_id, subject_id)
)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_recheck_exam_subject_room ON student_recheck_requests(exam_id, subject_id, room_id)');

$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$roomId = (int) ($_GET['room_id'] ?? 0);
$export = (string) ($_GET['export'] ?? '');

$examStmt = $pdo->prepare('SELECT ten_ky_thi FROM exams WHERE id = :id LIMIT 1');
$examStmt->execute([':id' => $examId]);
$examName = (string) ($examStmt->fetchColumn() ?: 'Kỳ thi hiện tại');

$subjectsStmt = $pdo->prepare('SELECT DISTINCT s.id, s.ten_mon
    FROM student_recheck_requests rr
    INNER JOIN subjects s ON s.id = rr.subject_id
    WHERE rr.exam_id = :exam_id
    ORDER BY s.ten_mon');
$subjectsStmt->execute([':exam_id' => $examId]);
$subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);

$roomsStmt = $pdo->prepare('SELECT DISTINCT r.id, r.ten_phong
    FROM student_recheck_requests rr
    LEFT JOIN rooms r ON r.id = rr.room_id
    WHERE rr.exam_id = :exam_id AND rr.room_id IS NOT NULL
    ORDER BY r.ten_phong');
$roomsStmt->execute([':exam_id' => $examId]);
$rooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);

$where = ' WHERE rr.exam_id = :exam_id';
$params = [':exam_id' => $examId];
if ($subjectId > 0) {
    $where .= ' AND rr.subject_id = :subject_id';
    $params[':subject_id'] = $subjectId;
}
if ($roomId > 0) {
    $where .= ' AND rr.room_id = :room_id';
    $params[':room_id'] = $roomId;
}

$sql = 'SELECT rr.*, st.sbd, st.hoten, st.lop, sub.ten_mon, COALESCE(rm.ten_phong, "") AS ten_phong,
        sc.component_1 AS score_component_1, sc.component_2 AS score_component_2, sc.component_3 AS score_component_3, rr.created_at
    FROM student_recheck_requests rr
    INNER JOIN students st ON st.id = rr.student_id
    INNER JOIN subjects sub ON sub.id = rr.subject_id
    LEFT JOIN rooms rm ON rm.id = rr.room_id
    LEFT JOIN scores sc ON sc.exam_id = rr.exam_id AND sc.student_id = rr.student_id AND sc.subject_id = rr.subject_id' . $where . '
    ORDER BY rm.ten_phong ASC, sub.ten_mon ASC, st.sbd ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$componentDefs = [
    1 => ['req' => 'component_1', 'score' => 'score_component_1', 'label' => 'Tự luận'],
    2 => ['req' => 'component_2', 'score' => 'score_component_2', 'label' => 'Trắc nghiệm'],
    3 => ['req' => 'component_3', 'score' => 'score_component_3', 'label' => 'Nói'],
];
$componentGroups = [1 => [], 2 => [], 3 => []];
foreach ($rows as $row) {
    $roomKey = trim((string) ($row['ten_phong'] ?? ''));
    if ($roomKey === '') {
        $roomKey = 'Chưa phân phòng';
    }

    foreach ($componentDefs as $componentNo => $def) {
        $requestVal = $row[$def['req']] ?? null;
        if ($requestVal === null || $requestVal === '') {
            continue;
        }

        if (!isset($componentGroups[$componentNo][$roomKey])) {
            $componentGroups[$componentNo][$roomKey] = [];
        }
        $componentGroups[$componentNo][$roomKey][] = $row;
    }
}

$renderValue = static function(array $row, string $key): string {
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
        return '-';
    }
    return number_format((float) $row[$key], 2);
};

$formatHanoi = static function(?string $raw): string {
    $v = trim((string) $raw);
    if ($v === "") {
        return "-";
    }
    $dt = DateTimeImmutable::createFromFormat("Y-m-d H:i:s", $v, new DateTimeZone("Asia/Ho_Chi_Minh"));
    if ($dt instanceof DateTimeImmutable) {
        return $dt->format("d/m/Y H:i:s");
    }
    return $v;
};

$renderHtml = static function(bool $forPdf = false) use ($componentGroups, $componentDefs, $examName, $examId, $renderValue, $formatHanoi): string {
    ob_start();
    ?>
    <!doctype html>
    <html lang="vi"><head><meta charset="UTF-8"><title>Thống kê phúc tra</title>
    <style>
        body{font-family:"Times New Roman",serif;font-size:14px}
        h1,h2,h3{text-align:center}
        .meta{text-align:center;margin-bottom:10px}
        table{width:100%;border-collapse:collapse;margin:10px 0}
        th,td{border:1px solid #222;padding:6px}
        th{background:#f2f2f2}
        .room-title{margin-top:10px;font-weight:bold}
        .component-section{margin-top:16px}
        <?php if ($forPdf): ?>
        .component-section{page-break-before:always}
        .component-section:first-of-type{page-break-before:auto}
        <?php endif; ?>
    </style></head><body>
    <h1>DANH SÁCH HỌC SINH PHÚC TRA</h1>
    <div class="meta">Kỳ thi #<?= (int) $examId ?> - <?= htmlspecialchars($examName, ENT_QUOTES, 'UTF-8') ?></div>
    <?php foreach ([1, 2, 3] as $componentNo): if (empty($componentGroups[$componentNo])) { continue; } ?>
        <section class="component-section">
            <h2>Thành phần: <?= htmlspecialchars($componentDefs[$componentNo]['label'], ENT_QUOTES, 'UTF-8') ?></h2>
            <?php foreach ($componentGroups[$componentNo] as $roomName => $roomRows): ?>
                <div class="room-title">Phòng: <?= htmlspecialchars((string) $roomName, ENT_QUOTES, 'UTF-8') ?></div>
                <table><thead><tr>
                    <th>STT</th><th>SBD</th><th>Họ tên</th><th>Lớp</th><th>Môn</th><th>Phòng</th>
                    <th>Điểm hiện tại</th><th>Đăng ký phúc tra</th><th>Ngày giờ đăng ký (Hà Nội)</th>
                </tr></thead><tbody>
                <?php $i=1; foreach ($roomRows as $r): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars((string) ($r['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($r['hoten'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($r['lop'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($r['ten_mon'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($r['ten_phong'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $renderValue($r, (string) $componentDefs[$componentNo]['score']) ?></td>
                        <td><?= $renderValue($r, (string) $componentDefs[$componentNo]['req']) ?></td>
                        <td><?= $formatHanoi((string) ($r['created_at'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    </body></html>
    <?php
    return (string) ob_get_clean();
};

$buildExcelXml = static function() use ($componentGroups, $componentDefs, $examName, $examId, $renderValue, $formatHanoi): string {
    $xmlEscape = static function(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };

    $sheetTitleBase = 'Thống kê phúc tra - #' . $examId . ' - ' . $examName;
    $sheetXmlParts = [];

    foreach ([1, 2, 3] as $componentNo) {
        if (empty($componentGroups[$componentNo])) {
            continue;
        }

        $sheetName = $componentDefs[$componentNo]['label'];
        $rowsXml = [];
        $rowsXml[] = '<Row>'
            . '<Cell><Data ss:Type="String">STT</Data></Cell>'
            . '<Cell><Data ss:Type="String">SBD</Data></Cell>'
            . '<Cell><Data ss:Type="String">Họ tên</Data></Cell>'
            . '<Cell><Data ss:Type="String">Lớp</Data></Cell>'
            . '<Cell><Data ss:Type="String">Môn</Data></Cell>'
            . '<Cell><Data ss:Type="String">Phòng</Data></Cell>'
            . '<Cell><Data ss:Type="String">Điểm hiện tại</Data></Cell>'
            . '<Cell><Data ss:Type="String">Đăng ký phúc tra</Data></Cell>'
            . '<Cell><Data ss:Type="String">Ngày giờ đăng ký (Hà Nội)</Data></Cell>'
            . '</Row>';

        foreach ($componentGroups[$componentNo] as $roomName => $roomRows) {
            $rowsXml[] = '<Row>'
                . '<Cell><Data ss:Type="String">Phòng: ' . $xmlEscape((string) $roomName) . '</Data></Cell>'
                . '</Row>';

            $i = 1;
            foreach ($roomRows as $r) {
                $rowsXml[] = '<Row>'
                    . '<Cell><Data ss:Type="Number">' . $i++ . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $xmlEscape((string) ($r['sbd'] ?? '')) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $xmlEscape((string) ($r['hoten'] ?? '')) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $xmlEscape((string) ($r['lop'] ?? '')) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $xmlEscape((string) ($r['ten_mon'] ?? '')) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $xmlEscape((string) ($r['ten_phong'] ?? '')) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $xmlEscape($renderValue($r, (string) $componentDefs[$componentNo]['score'])) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $xmlEscape($renderValue($r, (string) $componentDefs[$componentNo]['req'])) . '</Data></Cell>'
                    . '<Cell><Data ss:Type="String">' . $xmlEscape($formatHanoi((string) ($r['created_at'] ?? ''))) . '</Data></Cell>'
                    . '</Row>';
            }
        }

        $sheetXmlParts[] = '<Worksheet ss:Name="' . $xmlEscape($sheetName) . '"><Table>' . implode('', $rowsXml) . '</Table></Worksheet>';
    }

    if (empty($sheetXmlParts)) {
        $sheetXmlParts[] = '<Worksheet ss:Name="TongHop"><Table>'
            . '<Row><Cell><Data ss:Type="String">Không có dữ liệu phúc tra cho bộ lọc hiện tại.</Data></Cell></Row>'
            . '</Table></Worksheet>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<?mso-application progid="Excel.Sheet"?>'
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
        . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
        . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
        . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
        . ' xmlns:html="http://www.w3.org/TR/REC-html40">'
        . '<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">'
        . '<Title>' . $xmlEscape($sheetTitleBase) . '</Title>'
        . '</DocumentProperties>'
        . implode('', $sheetXmlParts)
        . '</Workbook>';
};

if ($export === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="thong_ke_phuc_tra_exam_' . $examId . '.xls"');
    echo $buildExcelXml();
    exit;
}
if ($export === 'pdf') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline; filename="thong_ke_phuc_tra_exam_' . $examId . '.html"');
    echo $renderHtml(true);
    exit;
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-info text-dark"><strong>Thống kê phúc tra</strong></div>
            <div class="card-body">
                <div class="alert alert-secondary">Kỳ thi mặc định: <strong>#<?= $examId ?> - <?= htmlspecialchars($examName, ENT_QUOTES, 'UTF-8') ?></strong></div>
                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-4">
                        <select class="form-select" name="subject_id">
                            <option value="0">-- Tất cả môn --</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $subjectId === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select class="form-select" name="room_id">
                            <option value="0">-- Tất cả phòng --</option>
                            <?php foreach ($rooms as $r): ?>
                                <option value="<?= (int) $r['id'] ?>" <?= $roomId === (int) $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $r['ten_phong'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Lọc</button>
                        <a class="btn btn-outline-success" href="<?= BASE_URL ?>/modules/exams/recheck_stats.php?<?= htmlspecialchars(http_build_query(['subject_id' => $subjectId, 'room_id' => $roomId, 'export' => 'excel']), ENT_QUOTES, 'UTF-8') ?>">Xuất Excel</a>
                        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/modules/exams/recheck_stats.php?<?= htmlspecialchars(http_build_query(['subject_id' => $subjectId, 'room_id' => $roomId, 'export' => 'pdf']), ENT_QUOTES, 'UTF-8') ?>">Xuất PDF</a>
                    </div>
                </form>

                <?php foreach ([1, 2, 3] as $componentNo): if (empty($componentGroups[$componentNo])) { continue; } ?>
                    <h5 class="mt-3">Thành phần: <?= htmlspecialchars((string) $componentDefs[$componentNo]['label'], ENT_QUOTES, 'UTF-8') ?></h5>
                    <?php foreach ($componentGroups[$componentNo] as $roomName => $roomRows): ?>
                    <h6>Phòng: <?= htmlspecialchars((string) $roomName, ENT_QUOTES, 'UTF-8') ?></h6>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle">
                            <thead class="table-light"><tr>
                                <th>STT</th><th>SBD</th><th>Họ tên</th><th>Lớp</th><th>Môn</th><th>Phòng</th>
                                <th>Điểm hiện tại</th><th>Đăng ký phúc tra</th><th>Ngày giờ đăng ký (Hà Nội)</th>
                            </tr></thead><tbody>
                            <?php $i = 1; foreach ($roomRows as $r): ?>
                                <tr>
                                    <td><?= $i++ ?></td>
                                    <td><?= htmlspecialchars((string) ($r['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['hoten'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['lop'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['ten_mon'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['ten_phong'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= $renderValue($r, (string) $componentDefs[$componentNo]['score']) ?></td>
                                    <td><?= $renderValue($r, (string) $componentDefs[$componentNo]['req']) ?></td>
                                    <td><?= $formatHanoi((string) ($r['created_at'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody></table>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?><div class="alert alert-info">Chưa có đăng ký phúc tra cho kỳ thi mặc định.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
