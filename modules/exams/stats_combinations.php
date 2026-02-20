<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_role(['admin', 'organizer', 'scorer']);

$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$csrf = exams_get_csrf_token();

$autoloadCandidates = [
    BASE_PATH . '/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];
foreach ($autoloadCandidates as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

/**
 * @return array<string, array{label:string, subjects:array<int, array{code:string,name:string,keywords:array<int, string>}>}>
 */
function comboDefinitions(): array
{
    return [
        'A' => [
            'label' => 'A (Toán + Lý + Hóa)',
            'subjects' => [
                ['code' => 'TO', 'name' => 'Toán', 'keywords' => ['TO', 'TOAN']],
                ['code' => 'LI', 'name' => 'Lý', 'keywords' => ['LI', 'LY', 'VATLY', 'VAT_LY']],
                ['code' => 'HOA', 'name' => 'Hóa', 'keywords' => ['HOA', 'HOAHOC', 'HOA_HOC']],
            ],
        ],
        'A1' => [
            'label' => 'A1 (Toán + Lý + Tiếng Anh)',
            'subjects' => [
                ['code' => 'TO', 'name' => 'Toán', 'keywords' => ['TO', 'TOAN']],
                ['code' => 'LI', 'name' => 'Lý', 'keywords' => ['LI', 'LY', 'VATLY', 'VAT_LY']],
                ['code' => 'ANH', 'name' => 'Tiếng Anh', 'keywords' => ['ANH', 'TA', 'TIENGANH', 'TIENG_ANH', 'ENGLISH']],
            ],
        ],
        'B' => [
            'label' => 'B (Toán + Hóa + Sinh)',
            'subjects' => [
                ['code' => 'TO', 'name' => 'Toán', 'keywords' => ['TO', 'TOAN']],
                ['code' => 'HOA', 'name' => 'Hóa', 'keywords' => ['HOA', 'HOAHOC', 'HOA_HOC']],
                ['code' => 'SINH', 'name' => 'Sinh', 'keywords' => ['SINH', 'SINHHOC', 'SINH_HOC']],
            ],
        ],
        'C' => [
            'label' => 'C (Văn + Sử + Địa)',
            'subjects' => [
                ['code' => 'VAN', 'name' => 'Văn', 'keywords' => ['VAN', 'NGUVAN', 'NGU_VAN']],
                ['code' => 'SU', 'name' => 'Sử', 'keywords' => ['SU', 'LICHSU', 'LICH_SU']],
                ['code' => 'DIA', 'name' => 'Địa', 'keywords' => ['DIA', 'DIALY', 'DIA_LY']],
            ],
        ],
        'D' => [
            'label' => 'D (Toán + Văn + Tiếng Anh)',
            'subjects' => [
                ['code' => 'TO', 'name' => 'Toán', 'keywords' => ['TO', 'TOAN']],
                ['code' => 'VAN', 'name' => 'Văn', 'keywords' => ['VAN', 'NGUVAN', 'NGU_VAN']],
                ['code' => 'ANH', 'name' => 'Tiếng Anh', 'keywords' => ['ANH', 'TA', 'TIENGANH', 'TIENG_ANH', 'ENGLISH']],
            ],
        ],
    ];
}

function normalizeToken(string $value): string
{
    return strtoupper(trim($value));
}

/**
 * @param array<int, array{id:int,ma_mon:string,ten_mon:string}> $subjects
 * @param array<int, string> $keywords
 */
function resolveSubjectId(array $subjects, array $keywords): int
{
    $keywordLookup = array_fill_keys(array_map('normalizeToken', $keywords), true);
    foreach ($subjects as $subject) {
        $code = normalizeToken((string) ($subject['ma_mon'] ?? ''));
        $name = normalizeToken((string) ($subject['ten_mon'] ?? ''));
        if (isset($keywordLookup[$code])) {
            return (int) ($subject['id'] ?? 0);
        }
        foreach (array_keys($keywordLookup) as $keyword) {
            if ($keyword !== '' && (str_contains($name, $keyword) || str_contains($code, $keyword))) {
                return (int) ($subject['id'] ?? 0);
            }
        }
    }

    return 0;
}

/**
 * @param array<int, array{ma_hs:int,ho_dem:string,ten:string,ngay_sinh:string,ma_lop:string,mon1:float,mon2:float,mon3:float,tong_diem:float,xep_hang:int}> $rows
 */
function exportComboExcel(array $rows, string $comboCode, array $subjectLabels): void
{
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        throw new RuntimeException('Thiếu thư viện PhpSpreadsheet để export Excel.');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('ToHop_' . $comboCode);

    $headers = ['STT', 'Họ đệm', 'Tên', 'Ngày sinh', 'Mã lớp', $subjectLabels[0], $subjectLabels[1], $subjectLabels[2], 'Tổng điểm', 'Xếp hạng'];
    $sheet->fromArray($headers, null, 'A1');

    $rowNum = 2;
    foreach ($rows as $i => $row) {
        $sheet->fromArray([
            $i + 1,
            $row['ho_dem'],
            $row['ten'],
            $row['ngay_sinh'],
            $row['ma_lop'],
            $row['mon1'],
            $row['mon2'],
            $row['mon3'],
            $row['tong_diem'],
            $row['xep_hang'],
        ], null, 'A' . $rowNum);
        $rowNum++;
    }

    foreach (range('A', 'J') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="ThongKeToHop_' . $comboCode . '.xlsx"');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/**
 * @param array<int, array{ma_hs:int,ho_dem:string,ten:string,ngay_sinh:string,ma_lop:string,mon1:float,mon2:float,mon3:float,tong_diem:float,xep_hang:int}> $rows
 */
function exportComboPdf(array $rows, string $comboCode, array $subjectLabels): void
{
    $html = '<h3 style="text-align:center">Thống kê theo tổ hợp ' . htmlspecialchars($comboCode, ENT_QUOTES, 'UTF-8') . '</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" width="100%">';
    $html .= '<thead><tr><th>STT</th><th>Họ đệm</th><th>Tên</th><th>Ngày sinh</th><th>Mã lớp</th><th>' . htmlspecialchars($subjectLabels[0], ENT_QUOTES, 'UTF-8') . '</th><th>' . htmlspecialchars($subjectLabels[1], ENT_QUOTES, 'UTF-8') . '</th><th>' . htmlspecialchars($subjectLabels[2], ENT_QUOTES, 'UTF-8') . '</th><th>Tổng điểm</th><th>Xếp hạng</th></tr></thead><tbody>';
    foreach ($rows as $i => $row) {
        $html .= '<tr>'
            . '<td>' . ($i + 1) . '</td>'
            . '<td>' . htmlspecialchars($row['ho_dem'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars($row['ten'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars($row['ngay_sinh'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . htmlspecialchars($row['ma_lop'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td>' . number_format((float) $row['mon1'], 2) . '</td>'
            . '<td>' . number_format((float) $row['mon2'], 2) . '</td>'
            . '<td>' . number_format((float) $row['mon3'], 2) . '</td>'
            . '<td>' . number_format((float) $row['tong_diem'], 2) . '</td>'
            . '<td>' . (int) $row['xep_hang'] . '</td>'
            . '</tr>';
    }
    if (empty($rows)) {
        $html .= '<tr><td colspan="10" style="text-align:center">Không có dữ liệu</td></tr>';
    }
    $html .= '</tbody></table>';

    if (class_exists(\Mpdf\Mpdf::class)) {
        $mpdf = new \Mpdf\Mpdf(['format' => 'A4']);
        $mpdf->WriteHTML($html);
        $mpdf->Output('ThongKeToHop_' . $comboCode . '.pdf', 'D');
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="UTF-8"><title>PDF Export</title></head><body>' . $html . '<script>window.print()</script></body></html>';
    exit;
}

$comboDefs = comboDefinitions();
$comboCode = strtoupper(trim((string) ($_GET['TenToHop'] ?? $_GET['to_hop'] ?? 'A')));
if (!isset($comboDefs[$comboCode])) {
    $comboCode = 'A';
}
$combo = $comboDefs[$comboCode];

$subjectsStmt = $pdo->prepare('SELECT id, COALESCE(ma_mon, "") AS ma_mon, COALESCE(ten_mon, "") AS ten_mon FROM subjects ORDER BY ten_mon');
$subjectsStmt->execute();
$allSubjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);

$resolvedSubjectIds = [];
$subjectLabels = [];
foreach ($combo['subjects'] as $definition) {
    $resolvedSubjectIds[] = resolveSubjectId($allSubjects, $definition['keywords']);
    $subjectLabels[] = $definition['name'];
}

$rows = [];
$missingSubjects = in_array(0, $resolvedSubjectIds, true);
if (!$missingSubjects) {
    $sql = 'SELECT
            st.id AS ma_hs,
            COALESCE(st.hoten, "") AS ho_ten,
            COALESCE(st.ngaysinh, "") AS ngay_sinh,
            COALESCE(st.lop, "") AS ma_lop,
            d1.score AS mon1,
            d2.score AS mon2,
            d3.score AS mon3
        FROM students st
        INNER JOIN exam_scores d1 ON d1.student_id = st.id AND d1.exam_id = :exam_id AND d1.subject_id = :s1
        INNER JOIN exam_scores d2 ON d2.student_id = st.id AND d2.exam_id = :exam_id AND d2.subject_id = :s2
        INNER JOIN exam_scores d3 ON d3.student_id = st.id AND d3.exam_id = :exam_id AND d3.subject_id = :s3
        ORDER BY (d1.score + d2.score + d3.score) DESC, st.hoten ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':exam_id' => $examId,
        ':s1' => $resolvedSubjectIds[0],
        ':s2' => $resolvedSubjectIds[1],
        ':s3' => $resolvedSubjectIds[2],
    ]);

    $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $lastScore = null;
    $lastRank = 0;
    foreach ($rawRows as $idx => $raw) {
        $fullName = trim((string) ($raw['ho_ten'] ?? ''));
        $nameParts = preg_split('/\s+/u', $fullName) ?: [];
        $ten = (string) array_pop($nameParts);
        $hoDem = trim(implode(' ', $nameParts));

        $tong = round((float) ($raw['mon1'] ?? 0) + (float) ($raw['mon2'] ?? 0) + (float) ($raw['mon3'] ?? 0), 2);
        $rank = ($lastScore !== null && abs($tong - $lastScore) < 0.0001) ? $lastRank : ($idx + 1);
        $lastScore = $tong;
        $lastRank = $rank;

        $rows[] = [
            'ma_hs' => (int) ($raw['ma_hs'] ?? 0),
            'ho_dem' => $hoDem,
            'ten' => $ten,
            'ngay_sinh' => (string) ($raw['ngay_sinh'] ?? ''),
            'ma_lop' => (string) ($raw['ma_lop'] ?? ''),
            'mon1' => (float) ($raw['mon1'] ?? 0),
            'mon2' => (float) ($raw['mon2'] ?? 0),
            'mon3' => (float) ($raw['mon3'] ?? 0),
            'tong_diem' => $tong,
            'xep_hang' => $rank,
        ];
    }
}

$export = (string) ($_GET['export'] ?? '');
if ($export === 'excel') {
    exportComboExcel($rows, $comboCode, $subjectLabels);
}
if ($export === 'pdf') {
    exportComboPdf($rows, $comboCode, $subjectLabels);
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Thống kê theo tổ hợp</strong></div>
            <div class="card-body">
                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Tổ hợp</label>
                        <select name="TenToHop" class="form-select">
                            <?php foreach ($comboDefs as $code => $def): ?>
                                <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= $comboCode === $code ? 'selected' : '' ?>><?= htmlspecialchars($def['label'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-grid align-items-end"><button class="btn btn-primary mt-4">Xem</button></div>
                    <div class="col-md-3 d-grid align-items-end"><a class="btn btn-outline-success mt-4" href="<?= BASE_URL ?>/modules/exams/stats_combinations.php?<?= http_build_query(['TenToHop' => $comboCode, 'export' => 'excel']) ?>">Export Excel</a></div>
                    <div class="col-md-3 d-grid align-items-end"><a class="btn btn-outline-danger mt-4" href="<?= BASE_URL ?>/modules/exams/stats_combinations.php?<?= http_build_query(['TenToHop' => $comboCode, 'export' => 'pdf']) ?>">Export PDF</a></div>
                </form>

                <?php if ($missingSubjects): ?>
                    <div class="alert alert-warning">Không tìm thấy đủ môn cho tổ hợp <?= htmlspecialchars($comboCode, ENT_QUOTES, 'UTF-8') ?> trong kỳ thi hiện tại.</div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle">
                        <thead>
                        <tr>
                            <th>STT</th><th>Họ đệm</th><th>Tên</th><th>Ngày sinh</th><th>Mã lớp</th>
                            <th><?= htmlspecialchars($subjectLabels[0], ENT_QUOTES, 'UTF-8') ?></th>
                            <th><?= htmlspecialchars($subjectLabels[1], ENT_QUOTES, 'UTF-8') ?></th>
                            <th><?= htmlspecialchars($subjectLabels[2], ENT_QUOTES, 'UTF-8') ?></th>
                            <th>Tổng điểm</th><th>Xếp hạng</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $i => $r): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($r['ho_dem'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($r['ten'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($r['ngay_sinh'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($r['ma_lop'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format((float) $r['mon1'], 2) ?></td>
                                <td><?= number_format((float) $r['mon2'], 2) ?></td>
                                <td><?= number_format((float) $r['mon3'], 2) ?></td>
                                <td><strong><?= number_format((float) $r['tong_diem'], 2) ?></strong></td>
                                <td><?= (int) $r['xep_hang'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="10" class="text-center">Không có dữ liệu.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
