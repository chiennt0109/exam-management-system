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

$classesStmt = $pdo->prepare('SELECT DISTINCT lop FROM exam_students WHERE exam_id = :exam_id AND lop IS NOT NULL AND lop <> "" ORDER BY lop');
$classesStmt->execute([':exam_id' => $examId]);
$classList = $classesStmt->fetchAll(PDO::FETCH_COLUMN);

$selectedClass = trim((string) ($_GET['MaLop'] ?? $_GET['ma_lop'] ?? ($classList[0] ?? '')));
if (!in_array($selectedClass, $classList, true)) {
    $selectedClass = (string) ($classList[0] ?? '');
}

$subjectStmt = $pdo->prepare('SELECT DISTINCT s.id, COALESCE(s.ten_mon,"") AS ten_mon
    FROM exam_scores es
    INNER JOIN subjects s ON s.id = es.subject_id
    INNER JOIN students st ON st.id = es.student_id
    WHERE es.exam_id = :exam_id AND COALESCE(st.lop,"") = :lop
    ORDER BY s.ten_mon');
$subjectStmt->execute([':exam_id' => $examId, ':lop' => $selectedClass]);
$subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);
$subjectIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $subjects);

$students = [];
$avg = [];
if ($selectedClass !== '') {
    $stuStmt = $pdo->prepare('SELECT id, COALESCE(hoten,"") AS hoten, COALESCE(ngaysinh,"") AS ngaysinh, COALESCE(lop,"") AS lop
        FROM students WHERE COALESCE(lop,"") = :lop ORDER BY hoten');
    $stuStmt->execute([':lop' => $selectedClass]);
    foreach ($stuStmt->fetchAll(PDO::FETCH_ASSOC) as $stu) {
        $students[(int) ($stu['id'] ?? 0)] = [
            'hoten' => (string) ($stu['hoten'] ?? ''),
            'ngaysinh' => (string) ($stu['ngaysinh'] ?? ''),
            'lop' => (string) ($stu['lop'] ?? ''),
            'scores' => [],
            'total' => null,
        ];
    }

    if (!empty($students) && !empty($subjectIds)) {
        $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
        $params = array_merge([$examId], $subjectIds, [$selectedClass]);
        $sql = 'SELECT es.student_id, es.subject_id, es.score
            FROM exam_scores es
            INNER JOIN students st ON st.id = es.student_id
            WHERE es.exam_id = ? AND es.subject_id IN (' . $placeholders . ') AND COALESCE(st.lop,"") = ?';
        $scoreStmt = $pdo->prepare($sql);
        $scoreStmt->execute($params);

        foreach ($scoreStmt->fetchAll(PDO::FETCH_ASSOC) as $sc) {
            $sid = (int) ($sc['student_id'] ?? 0);
            $subId = (int) ($sc['subject_id'] ?? 0);
            $score = $sc['score'] === null ? null : (float) $sc['score'];
            if (!isset($students[$sid])) {
                continue;
            }
            $students[$sid]['scores'][$subId] = $score;
        }

        foreach ($students as &$student) {
            $sum = 0.0;
            $count = 0;
            foreach ($subjectIds as $subId) {
                $val = $student['scores'][$subId] ?? null;
                if ($val !== null) {
                    $sum += (float) $val;
                    $count++;
                    $avg[$subId]['sum'] = ($avg[$subId]['sum'] ?? 0.0) + (float) $val;
                    $avg[$subId]['count'] = ($avg[$subId]['count'] ?? 0) + 1;
                }
            }
            $student['total'] = $count > 0 ? round($sum, 2) : null;
        }
        unset($student);
    }
}

$rows = array_values($students);

$export = (string) ($_GET['export'] ?? '');
if ($export === 'excel') {
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        throw new RuntimeException('Thiếu thư viện PhpSpreadsheet để export Excel.');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $headers = ['STT', 'Họ tên', 'Ngày sinh', 'Mã lớp'];
    foreach ($subjects as $subject) {
        $headers[] = (string) ($subject['ten_mon'] ?? 'Môn');
    }
    $headers[] = 'Tổng điểm';
    $sheet->fromArray($headers, null, 'A1');

    $rowNum = 2;
    foreach ($rows as $i => $row) {
        $line = [$i + 1, $row['hoten'], $row['ngaysinh'], $row['lop']];
        foreach ($subjectIds as $subId) {
            $line[] = $row['scores'][$subId] ?? null;
        }
        $line[] = $row['total'];
        $sheet->fromArray($line, null, 'A' . $rowNum);
        $rowNum++;
    }

    $avgLine = ['', 'ĐIỂM TRUNG BÌNH', '', ''];
    foreach ($subjectIds as $subId) {
        $sum = (float) ($avg[$subId]['sum'] ?? 0);
        $count = (int) ($avg[$subId]['count'] ?? 0);
        $avgLine[] = $count > 0 ? round($sum / $count, 2) : null;
    }
    $avgLine[] = null;
    $sheet->fromArray($avgLine, null, 'A' . $rowNum);

    foreach (range('A', chr(ord('A') + max(4, count($headers) - 1))) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="ThongKeLop_TongHop_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $selectedClass) . '.xlsx"');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

if ($export === 'pdf') {
    $html = '<h3 style="text-align:center">Thống kê tổng hợp theo lớp: ' . htmlspecialchars($selectedClass, ENT_QUOTES, 'UTF-8') . '</h3>';
    $html .= '<table border="1" cellpadding="5" cellspacing="0" width="100%"><thead><tr><th>STT</th><th>Họ tên</th><th>Ngày sinh</th><th>Mã lớp</th>';
    foreach ($subjects as $subject) {
        $html .= '<th>' . htmlspecialchars((string) ($subject['ten_mon'] ?? 'Môn'), ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $html .= '<th>Tổng</th></tr></thead><tbody>';
    foreach ($rows as $i => $row) {
        $html .= '<tr><td>' . ($i + 1) . '</td><td>' . htmlspecialchars((string) $row['hoten'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) $row['ngaysinh'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) $row['lop'], ENT_QUOTES, 'UTF-8') . '</td>';
        foreach ($subjectIds as $subId) {
            $v = $row['scores'][$subId] ?? null;
            $html .= '<td>' . ($v === null ? '' : number_format((float) $v, 2)) . '</td>';
        }
        $html .= '<td>' . ($row['total'] === null ? '' : number_format((float) $row['total'], 2)) . '</td></tr>';
    }
    $html .= '<tr><td></td><td><strong>ĐIỂM TRUNG BÌNH</strong></td><td></td><td></td>';
    foreach ($subjectIds as $subId) {
        $sum = (float) ($avg[$subId]['sum'] ?? 0);
        $count = (int) ($avg[$subId]['count'] ?? 0);
        $html .= '<td><strong>' . ($count > 0 ? number_format($sum / $count, 2) : '') . '</strong></td>';
    }
    $html .= '<td></td></tr>';
    if (empty($rows)) {
        $html .= '<tr><td colspan="' . (5 + count($subjectIds)) . '">Không có dữ liệu</td></tr>';
    }
    $html .= '</tbody></table>';

    if (class_exists(\Mpdf\Mpdf::class)) {
        $mpdf = new \Mpdf\Mpdf(['format' => 'A4']);
        $mpdf->WriteHTML($html);
        $mpdf->Output('ThongKeLop_TongHop_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $selectedClass) . '.pdf', 'D');
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="UTF-8"><title>PDF Export</title></head><body>' . $html . '<script>window.print()</script></body></html>';
    exit;
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Thống kê tổng hợp theo lớp</strong></div>
            <div class="card-body">
                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Mã lớp</label>
                        <select class="form-select" name="MaLop">
                            <?php foreach ($classList as $classCode): ?>
                                <option value="<?= htmlspecialchars((string) $classCode, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedClass === (string) $classCode ? 'selected' : '' ?>><?= htmlspecialchars((string) $classCode, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-grid align-items-end"><button class="btn btn-primary mt-4">Xem</button></div>
                    <div class="col-md-3 d-grid align-items-end"><a class="btn btn-outline-success mt-4" href="<?= BASE_URL ?>/modules/exams/statistics_by_class.php?<?= http_build_query(['MaLop' => $selectedClass, 'export' => 'excel']) ?>">Export Excel</a></div>
                    <div class="col-md-3 d-grid align-items-end"><a class="btn btn-outline-danger mt-4" href="<?= BASE_URL ?>/modules/exams/statistics_by_class.php?<?= http_build_query(['MaLop' => $selectedClass, 'export' => 'pdf']) ?>">Export PDF</a></div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead>
                        <tr>
                            <th>STT</th><th>Họ tên</th><th>Ngày sinh</th><th>Mã lớp</th>
                            <?php foreach ($subjects as $subject): ?>
                                <th><?= htmlspecialchars((string) ($subject['ten_mon'] ?? 'Môn'), ENT_QUOTES, 'UTF-8') ?></th>
                            <?php endforeach; ?>
                            <th>Tổng điểm</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $i => $row): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars((string) $row['hoten'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $row['ngaysinh'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $row['lop'], ENT_QUOTES, 'UTF-8') ?></td>
                                <?php foreach ($subjectIds as $subId): ?>
                                    <?php $v = $row['scores'][$subId] ?? null; ?>
                                    <td><?= $v === null ? '' : number_format((float) $v, 2) ?></td>
                                <?php endforeach; ?>
                                <td><?= $row['total'] === null ? '' : number_format((float) $row['total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="<?= 5 + count($subjectIds) ?>" class="text-center">Không có dữ liệu.</td></tr>
                        <?php endif; ?>
                        <tr class="table-warning">
                            <td></td><td><strong>ĐIỂM TRUNG BÌNH</strong></td><td></td><td></td>
                            <?php foreach ($subjectIds as $subId): ?>
                                <?php $sum = (float) ($avg[$subId]['sum'] ?? 0); $count = (int) ($avg[$subId]['count'] ?? 0); ?>
                                <td><strong><?= $count > 0 ? number_format($sum / $count, 2) : '' ?></strong></td>
                            <?php endforeach; ?>
                            <td></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
