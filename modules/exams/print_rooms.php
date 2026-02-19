<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';

$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$csrf = exams_get_csrf_token();
$role = (string) ($_SESSION['user']['role'] ?? '');
$examModeStmt = $pdo->prepare('SELECT exam_mode, ten_ky_thi FROM exams WHERE id = :id LIMIT 1');
$examModeStmt->execute([':id' => $examId]);
$examMeta = $examModeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$examMode = (int) ($examMeta['exam_mode'] ?? 1);
$examName = trim((string) ($examMeta['ten_ky_thi'] ?? ''));
if ($examName === '') {
    $examName = 'KIỂM TRA GIỮA KỲ II - KHỐI 12';
}
if (!in_array($examMode, [1, 2], true)) {
    $examMode = 1;
}

$lockState = exams_get_lock_state($pdo, $examId);
$isExamLocked = $lockState['exam_locked'] === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        exams_set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: ' . BASE_URL . '/modules/exams/print_rooms.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        $pdo->beginTransaction();
        if ($action === 'lock_exam') {
            exams_assert_exam_unlocked_for_write($pdo, $examId);
            $pdo->prepare('UPDATE exams SET exam_locked = 1, distribution_locked = 1, rooms_locked = 1 WHERE id = :id')->execute([':id' => $examId]);
            exams_clear_maintenance_mode($pdo);
            exams_set_flash('success', 'Đã khoá kỳ thi. Có thể in/export danh sách phòng và nhập điểm.');
        } elseif ($action === 'unlock_exam') {
            if ($role !== 'admin') {
                throw new RuntimeException('Chỉ admin mới được mở khoá kỳ thi.');
            }
            $pdo->prepare('UPDATE exams SET exam_locked = 0 WHERE id = :id')->execute([':id' => $examId]);
            exams_set_maintenance_mode($pdo, $examId, (int) ($_SESSION['user']['id'] ?? 0));
            exams_set_flash('warning', 'Đã mở khoá kỳ thi bởi admin. Hệ thống vào chế độ bảo trì tạm thời.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        exams_set_flash('error', $e->getMessage());
    }

    header('Location: ' . BASE_URL . '/modules/exams/print_rooms.php');
    exit;
}

$subjectFilter = max(0, (int) ($_GET['subject_id'] ?? 0));
$search = trim((string) ($_GET['search'] ?? ''));
$perPageOptions = [10, 20, 50];
$perPage = (int) ($_GET['per_page'] ?? 10);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 10;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$subjectOptionsStmt = $pdo->prepare('SELECT DISTINCT sub.id, sub.ten_mon
    FROM rooms r
    INNER JOIN subjects sub ON sub.id = r.subject_id
    WHERE r.exam_id = :exam_id
    ORDER BY sub.ten_mon');
$subjectOptionsStmt->execute([':exam_id' => $examId]);
$subjectOptions = $subjectOptionsStmt->fetchAll(PDO::FETCH_ASSOC);

$where = ' WHERE r.exam_id = :exam_id';
$params = [':exam_id' => $examId];
if ($subjectFilter > 0) {
    $where .= ' AND r.subject_id = :subject_id';
    $params[':subject_id'] = $subjectFilter;
}
if ($search !== '') {
    $where .= ' AND (lower(r.ten_phong) LIKE :kw OR EXISTS (
        SELECT 1 FROM exam_students es
        LEFT JOIN students st ON st.id = es.student_id
        WHERE es.room_id = r.id AND (
            lower(coalesce(st.hoten, "")) LIKE :kw OR lower(coalesce(es.sbd, "")) LIKE :kw OR lower(coalesce(st.lop, "")) LIKE :kw
        )
    ))';
    $params[':kw'] = '%' . mb_strtolower($search) . '%';
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM rooms r' . $where);
$countStmt->execute($params);
$totalRooms = (int) ($countStmt->fetchColumn() ?: 0);
$totalPages = max(1, (int) ceil($totalRooms / max(1, $perPage)));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$roomSql = 'SELECT r.id AS room_id, r.ten_phong, r.khoi, sub.ten_mon, sub.id AS subject_id
    FROM rooms r
    INNER JOIN subjects sub ON sub.id = r.subject_id' . $where . '
    ORDER BY sub.ten_mon, r.khoi, r.ten_phong
    LIMIT :limit OFFSET :offset';
$roomStmt = $pdo->prepare($roomSql);
foreach ($params as $k => $v) {
    $roomStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$roomStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$roomStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$roomStmt->execute();
$roomRows = $roomStmt->fetchAll(PDO::FETCH_ASSOC);

$roomGroups = [];
$roomIds = [];
foreach ($roomRows as $row) {
    $rid = (int) ($row['room_id'] ?? 0);
    if ($rid <= 0) {
        continue;
    }
    $roomIds[] = $rid;
    $roomGroups[$rid] = [
        'room_id' => $rid,
        'ten_phong' => (string) ($row['ten_phong'] ?? ''),
        'khoi' => (string) ($row['khoi'] ?? ''),
        'ten_mon' => (string) ($row['ten_mon'] ?? ''),
        'subject_id' => (int) ($row['subject_id'] ?? 0),
        'students' => [],
    ];
}

$studentSubjectsMap = [];
if ($examMode === 2) {
    $subMapStmt = $pdo->prepare('SELECT ess.student_id, GROUP_CONCAT(sub.ten_mon, ", ") AS mon_thi
        FROM exam_student_subjects ess
        INNER JOIN subjects sub ON sub.id = ess.subject_id
        WHERE ess.exam_id = :exam_id
        GROUP BY ess.student_id');
    $subMapStmt->execute([':exam_id' => $examId]);
    foreach ($subMapStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $studentSubjectsMap[(int) ($r['student_id'] ?? 0)] = (string) ($r['mon_thi'] ?? '');
    }
}

if (!empty($roomIds)) {
    $ph = implode(',', array_fill(0, count($roomIds), '?'));
    $stuSql = 'SELECT es.room_id, es.student_id, es.sbd, st.hoten, st.lop, st.ngaysinh
        FROM exam_students es
        LEFT JOIN students st ON st.id = es.student_id
        WHERE es.room_id IN (' . $ph . ')
        ORDER BY es.room_id, es.sbd';
    $stuStmt = $pdo->prepare($stuSql);
    $stuStmt->execute($roomIds);
    foreach ($stuStmt->fetchAll(PDO::FETCH_ASSOC) as $st) {
        $rid = (int) ($st['room_id'] ?? 0);
        if (!isset($roomGroups[$rid])) {
            continue;
        }
        $dob = (string) ($st['ngaysinh'] ?? '');
        $ts = strtotime($dob);
        $studentId = (int) ($st['student_id'] ?? 0);
        $roomGroups[$rid]['students'][] = [
            'sbd' => (string) ($st['sbd'] ?? ''),
            'hoten' => (string) ($st['hoten'] ?? ''),
            'lop' => (string) ($st['lop'] ?? ''),
            'ngaysinh' => $ts ? date('d/m/Y', $ts) : $dob,
            'mon_thi' => (string) ($studentSubjectsMap[$studentId] ?? ''),
        ];
    }
}

$export = (string) ($_GET['export'] ?? '');
$exportFile = (string) ($_GET['file'] ?? 'excel');
if (!in_array($exportFile, ['excel', 'pdf'], true)) {
    $exportFile = 'excel';
}
$isPdfExport = $exportFile === 'pdf';
if (in_array($export, ['format1', 'format2'], true)) {
    if (!$isExamLocked) {
        exams_set_flash('warning', 'Phải khoá kỳ thi trước khi export danh sách phòng.');
        header('Location: ' . BASE_URL . '/modules/exams/print_rooms.php');
        exit;
    }

    // export all matching rooms (ignore pagination)
    $allRoomStmt = $pdo->prepare('SELECT r.id AS room_id, r.ten_phong, r.khoi, sub.ten_mon, sub.id AS subject_id
        FROM rooms r
        INNER JOIN subjects sub ON sub.id = r.subject_id' . $where . '
        ORDER BY sub.ten_mon, r.khoi, r.ten_phong');
    $allRoomStmt->execute($params);
    $allRooms = $allRoomStmt->fetchAll(PDO::FETCH_ASSOC);
    $allGroups = [];
    $allIds = [];
    foreach ($allRooms as $row) {
        $rid = (int) ($row['room_id'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        $allIds[] = $rid;
        $allGroups[$rid] = [
            'room_id' => $rid,
            'ten_phong' => (string) ($row['ten_phong'] ?? ''),
            'khoi' => (string) ($row['khoi'] ?? ''),
            'ten_mon' => (string) ($row['ten_mon'] ?? ''),
            'students' => [],
        ];
    }
    if (!empty($allIds)) {
        $ph = implode(',', array_fill(0, count($allIds), '?'));
        $stuStmt = $pdo->prepare('SELECT es.room_id, es.student_id, es.sbd, st.hoten, st.lop, st.ngaysinh
            FROM exam_students es
            LEFT JOIN students st ON st.id = es.student_id
            WHERE es.room_id IN (' . $ph . ')
            ORDER BY es.room_id, es.sbd');
        $stuStmt->execute($allIds);
        foreach ($stuStmt->fetchAll(PDO::FETCH_ASSOC) as $st) {
            $rid = (int) ($st['room_id'] ?? 0);
            if (!isset($allGroups[$rid])) {
                continue;
            }
            $dob = (string) ($st['ngaysinh'] ?? '');
            $ts = strtotime($dob);
            $allGroups[$rid]['students'][] = [
                'sbd' => (string) ($st['sbd'] ?? ''),
                'hoten' => (string) ($st['hoten'] ?? ''),
                'lop' => (string) ($st['lop'] ?? ''),
                'ngaysinh' => $ts ? date('d/m/Y', $ts) : $dob,
            ];
        }
    }

    $filename = 'danh_sach_phong_' . $export . '_exam_' . $examId . ($isPdfExport ? '.html' : '.xls');
    if ($isPdfExport) {
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: inline; filename="' . $filename . '"');
    } else {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }
    $year = '2026';

    if (!$isPdfExport) {
        $xmlEscape = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        echo '<Styles>';
        echo '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Times New Roman" ss:Size="12"/></Style>';
        echo '<Style ss:ID="HeaderLeft"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1" ss:Size="14"/></Style>';
        echo '<Style ss:ID="HeaderRightTitle"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1" ss:Size="16"/></Style>';
        echo '<Style ss:ID="HeaderRightSub"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1" ss:Size="12"/></Style>';
        echo '<Style ss:ID="TableHead"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><Font ss:Bold="1"/></Style>';
        echo '<Style ss:ID="CellCenter"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="0"/><Borders><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>';
        echo '<Style ss:ID="CellLeft"><Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="0"/><Borders><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>';
        echo '<Style ss:ID="FooterRight"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:Italic="1"/></Style>';
        echo '<Style ss:ID="Sign"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1"/></Style>';
        echo '</Styles>';

        foreach ($allGroups as $group) {
            $students = array_values($group['students']);
            $sheetName = substr(preg_replace('/[^\p{L}\p{N}_-]+/u', '_', (string) $group['ten_phong']) ?: ('Room_' . (string) $group['room_id']), 0, 31);
            echo '<Worksheet ss:Name="' . $xmlEscape($sheetName) . '">';
            echo '<Table ss:ExpandedColumnCount="8" ss:DefaultRowHeight="18">';
            foreach ([5,10,28,12,12,8,8,18] as $w) {
                echo '<Column ss:Width="' . ($w * 6.5) . '"/>';
            }

            echo '<Row ss:Height="24">';
            echo '<Cell ss:MergeAcross="3" ss:MergeDown="1" ss:StyleID="HeaderLeft"><Data ss:Type="String">' . $xmlEscape("TRƯỜNG THPT CHUYÊN TRẦN PHÚ
" . $examName) . '</Data></Cell>';
            echo '<Cell ss:Index="5" ss:MergeAcross="3" ss:StyleID="HeaderRightTitle"><Data ss:Type="String">' . $xmlEscape($export === 'format1' ? 'DANH SÁCH NIÊM YẾT' : 'PHIẾU THU BÀI') . '</Data></Cell>';
            echo '</Row>';
            echo '<Row ss:Height="20"><Cell ss:Index="5" ss:MergeAcross="3" ss:StyleID="HeaderRightSub"><Data ss:Type="String">' . $xmlEscape('PHÒNG: ' . (string) $group['ten_phong']) . '</Data></Cell></Row>';
            echo '<Row ss:Height="20"><Cell ss:Index="5" ss:MergeAcross="3" ss:StyleID="HeaderRightSub"><Data ss:Type="String">' . $xmlEscape('Môn: ' . (string) $group['ten_mon']) . '</Data></Cell></Row>';
            echo '<Row ss:Height="10"></Row>';

            if ($export === 'format1') {
                echo '<Row>';
                foreach (['STT','SBD','Họ và tên','Ngày sinh','Lớp','Ghi chú'] as $h) {
                    echo '<Cell ss:StyleID="TableHead"><Data ss:Type="String">' . $xmlEscape($h) . '</Data></Cell>';
                }
                echo '</Row>';
                foreach ($students as $i => $st) {
                    echo '<Row><Cell ss:StyleID="CellCenter"><Data ss:Type="Number">' . ($i + 1) . '</Data></Cell><Cell ss:StyleID="CellCenter"><Data ss:Type="String">' . $xmlEscape((string) $st['sbd']) . '</Data></Cell><Cell ss:StyleID="CellLeft"><Data ss:Type="String">' . $xmlEscape((string) $st['hoten']) . '</Data></Cell><Cell ss:StyleID="CellCenter"><Data ss:Type="String">' . $xmlEscape((string) $st['ngaysinh']) . '</Data></Cell><Cell ss:StyleID="CellCenter"><Data ss:Type="String">' . $xmlEscape((string) $st['lop']) . '</Data></Cell><Cell ss:StyleID="CellCenter"><Data ss:Type="String"></Data></Cell></Row>';
                }
                echo '<Row ss:Height="18"></Row>';
                echo '<Row><Cell ss:Index="5" ss:MergeAcross="1" ss:StyleID="FooterRight"><Data ss:Type="String">' . $xmlEscape('Hải Phòng, ngày ... tháng ... năm ' . $year) . '</Data></Cell></Row>';
                echo '<Row><Cell ss:Index="5" ss:MergeAcross="1" ss:StyleID="Sign"><Data ss:Type="String">CHỦ TỊCH HỘI ĐỒNG</Data></Cell></Row>';
            } else {
                echo '<Row>';
                foreach (['STT','SBD','Họ và tên','Ngày sinh','Lớp','Số tờ','Mã đề','Ghi chú / Ký tên'] as $h) {
                    echo '<Cell ss:StyleID="TableHead"><Data ss:Type="String">' . $xmlEscape($h) . '</Data></Cell>';
                }
                echo '</Row>';
                foreach ($students as $i => $st) {
                    echo '<Row><Cell ss:StyleID="CellCenter"><Data ss:Type="Number">' . ($i + 1) . '</Data></Cell><Cell ss:StyleID="CellCenter"><Data ss:Type="String">' . $xmlEscape((string) $st['sbd']) . '</Data></Cell><Cell ss:StyleID="CellLeft"><Data ss:Type="String">' . $xmlEscape((string) $st['hoten']) . '</Data></Cell><Cell ss:StyleID="CellCenter"><Data ss:Type="String">' . $xmlEscape((string) $st['ngaysinh']) . '</Data></Cell><Cell ss:StyleID="CellCenter"><Data ss:Type="String">' . $xmlEscape((string) $st['lop']) . '</Data></Cell><Cell ss:StyleID="CellCenter"><Data ss:Type="String"></Data></Cell><Cell ss:StyleID="CellCenter"><Data ss:Type="String"></Data></Cell><Cell ss:StyleID="CellCenter"><Data ss:Type="String"></Data></Cell></Row>';
                }
                echo '<Row ss:Height="18"></Row>';
                echo '<Row><Cell ss:MergeAcross="7" ss:StyleID="CellLeft"><Data ss:Type="String">' . $xmlEscape('Trong đó: - Số học sinh tham dự: ...... - Số học sinh vắng: ...... - SBD vắng: ...................................') . '</Data></Cell></Row>';
                echo '<Row><Cell ss:MergeAcross="7" ss:StyleID="CellLeft"><Data ss:Type="String">' . $xmlEscape('Tổng số bài: ..........    Tổng mã đề: ..........') . '</Data></Cell></Row>';
                echo '<Row ss:Height="12"></Row>';
                echo '<Row><Cell ss:MergeAcross="1" ss:StyleID="Sign"><Data ss:Type="String">GIÁM THỊ 1</Data></Cell><Cell ss:Index="4" ss:MergeAcross="1" ss:StyleID="Sign"><Data ss:Type="String">GIÁM THỊ 2</Data></Cell><Cell ss:Index="7" ss:MergeAcross="1" ss:StyleID="Sign"><Data ss:Type="String">CHỦ TỊCH HỘI ĐỒNG</Data></Cell></Row>';
            }

            echo '</Table>';
            echo '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><PageSetup><Layout x:Orientation="Portrait"/><PageMargins x:Top="0.7" x:Bottom="0.7" x:Left="0.7" x:Right="0.7"/></PageSetup><Print><ValidPrinterInfo/><PaperSizeIndex>9</PaperSizeIndex><FitWidth>1</FitWidth><FitHeight>1</FitHeight></Print></WorksheetOptions>';
            echo '</Worksheet>';
        }

        echo '</Workbook>';
        exit;
    }

    $bodyClass = $isPdfExport ? 'export-pdf' : 'export-excel';
    $fitFontSize = static function (string $text, int $base = 11, int $min = 8, int $threshold = 18): int {
        $len = mb_strlen(trim($text));
        if ($len <= $threshold) {
            return $base;
        }
        $reduce = (int) ceil(($len - $threshold) / 8);
        return max($min, $base - $reduce);
    };
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Export phòng thi</title><style>@page{size:A4 portrait;margin:20mm 15mm}body{font-family:"Times New Roman",serif;margin:0;color:#000}.print-toolbar{display:none}.export-page{page-break-after:always;min-height:calc(297mm - 40mm);display:flex;flex-direction:column}.header-grid{display:grid;grid-template-columns:1fr 1fr;column-gap:12px;align-items:start}.header-left,.header-right{text-align:center;line-height:1.25}.title-main{font-size:16px;font-weight:700}.title-sub{font-size:14px;font-weight:700}.room-subject{margin-top:6px;font-size:13px}.table-wrap{margin-top:8px}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #333;padding:4px 6px;font-size:12px}th{text-align:center;font-weight:700}.right{text-align:right}.center{text-align:center}.footer-right{margin-top:8px;text-align:right;font-size:13px;line-height:1.6}.footer-signature{display:inline-block;text-align:center}.summary{font-size:12px;line-height:1.5;margin-top:8px}.signature-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:14px;text-align:center;font-weight:700}.small-note{font-size:11px;font-style:italic;margin-top:4px}.sig-space{height:54px}.nowrap{white-space:nowrap}.col-tight{width:1%;white-space:nowrap}.name-cell{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:11px;line-height:1.2}.class-cell{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:11px;line-height:1.2}.export-excel .print-toolbar{display:none!important}.export-excel .export-page{page-break-after:always}@media screen{body{padding:24px}.print-toolbar{display:flex;position:sticky;top:0;background:#fff;padding:8px 0 12px;gap:8px;z-index:5}.export-page{min-height:auto;padding:0;margin-bottom:20px}}@media print{.print-toolbar{display:none}}</style></head><body class="' . $bodyClass . '">';
    if ($isPdfExport) {
        echo '<div class="print-toolbar"><button type="button" onclick="window.print()">In / Lưu PDF</button><a href="' . htmlspecialchars((string) ($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/modules/exams/print_rooms.php')), ENT_QUOTES, 'UTF-8') . '">Quay lại</a></div>';
    } else {
        echo '<xml><x:ExcelWorkbook xmlns:x="urn:schemas-microsoft-com:office:excel"><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Export</x:Name><x:WorksheetOptions><x:Print><x:ValidPrinterInfo/><x:PaperSizeIndex>9</x:PaperSizeIndex><x:Scale>100</x:Scale><x:FitWidth>1</x:FitWidth><x:FitHeight>0</x:FitHeight><x:HorizontalResolution>600</x:HorizontalResolution><x:VerticalResolution>600</x:VerticalResolution></x:Print><x:PageSetup><x:Layout x:Orientation="Portrait"/></x:PageSetup></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>';
    }

    foreach ($allGroups as $group) {
        $students = array_values($group['students']);
        $maxRows = $export === 'format1' ? 30 : 28;
        $displayStudents = array_slice($students, 0, $maxRows);
        $truncated = count($students) > $maxRows;

        echo '<section class="export-page">';
        if ($export === 'format1') {
            echo '<div class="header-grid">';
            echo '<div class="header-left"><div class="title-sub">TRƯỜNG THPT CHUYÊN TRẦN PHÚ</div><div class="title-sub">' . htmlspecialchars($examName) . '</div></div>';
            echo '<div class="header-right"><div class="title-main">DANH SÁCH NIÊM YẾT</div><div class="room-subject">PHÒNG: <strong>' . htmlspecialchars($group['ten_phong']) . '</strong></div><div class="room-subject">Môn: <strong>' . htmlspecialchars($group['ten_mon']) . '</strong></div></div>';
            echo '</div>';
            echo '<div class="table-wrap"><table><thead><tr><th class="col-tight">STT</th><th class="col-tight">SBD</th><th>Họ và tên</th><th style="width:17%">Ngày sinh</th><th style="width:13%">Lớp</th><th style="width:18%">Ghi chú</th></tr></thead><tbody>';
            foreach ($displayStudents as $i => $st) {
                $nameSize = $fitFontSize((string) ($st['hoten'] ?? ''));
                $classSize = $fitFontSize((string) ($st['lop'] ?? ''), 11, 8, 10);
                echo '<tr><td class="center col-tight">' . ($i + 1) . '</td><td class="center nowrap col-tight">' . htmlspecialchars($st['sbd']) . '</td><td class="name-cell" style="font-size:' . $nameSize . 'px">' . htmlspecialchars($st['hoten']) . '</td><td class="center">' . htmlspecialchars($st['ngaysinh']) . '</td><td class="center class-cell" style="font-size:' . $classSize . 'px">' . htmlspecialchars($st['lop']) . '</td><td></td></tr>';
            }
            echo '</tbody></table></div>';
            if ($truncated) {
                echo '<div class="small-note">Danh sách vượt quá ' . $maxRows . ' học sinh, chỉ hiển thị ' . $maxRows . ' học sinh đầu tiên trên trang in.</div>';
            }
            echo '<div class="footer-right"><div class="footer-signature"><div><em>Hải Phòng, ngày ... tháng ... năm ' . $year . '</em></div><div><strong>CHỦ TỊCH HỘI ĐỒNG</strong></div><div class="sig-space"></div></div></div>';
        } else {
            echo '<div class="header-grid">';
            echo '<div class="header-left"><div class="title-sub">TRƯỜNG THPT CHUYÊN TRẦN PHÚ</div><div class="title-sub">' . htmlspecialchars($examName) . '</div></div>';
            echo '<div class="header-right"><div class="title-main">PHIẾU THU BÀI</div><div class="room-subject">PHÒNG: <strong>' . htmlspecialchars($group['ten_phong']) . '</strong></div><div class="room-subject">Môn: <strong>' . htmlspecialchars($group['ten_mon']) . '</strong></div></div>';
            echo '</div>';
            echo '<div class="table-wrap"><table><thead><tr><th class="col-tight">STT</th><th class="col-tight">SBD</th><th style="width:25%">Họ và tên</th><th style="width:14%">Ngày sinh</th><th style="width:9%">Lớp</th><th style="width:8%">Số tờ</th><th style="width:8%">Mã đề</th><th style="width:18%">Ghi chú / Ký tên</th></tr></thead><tbody>';
            foreach ($displayStudents as $i => $st) {
                $nameSize = $fitFontSize((string) ($st['hoten'] ?? ''));
                $classSize = $fitFontSize((string) ($st['lop'] ?? ''), 11, 8, 10);
                echo '<tr><td class="center col-tight">' . ($i + 1) . '</td><td class="center nowrap col-tight">' . htmlspecialchars($st['sbd']) . '</td><td class="name-cell" style="font-size:' . $nameSize . 'px">' . htmlspecialchars($st['hoten']) . '</td><td class="center">' . htmlspecialchars($st['ngaysinh']) . '</td><td class="center class-cell" style="font-size:' . $classSize . 'px">' . htmlspecialchars($st['lop']) . '</td><td></td><td></td><td></td></tr>';
            }
            echo '</tbody></table></div>';
            if ($truncated) {
                echo '<div class="small-note">Danh sách vượt quá ' . $maxRows . ' học sinh, chỉ hiển thị ' . $maxRows . ' học sinh đầu tiên trên trang in.</div>';
            }
            echo '<div class="summary"><div><strong>Trong đó:</strong> - Số học sinh tham dự: ...... &nbsp;&nbsp; - Số học sinh vắng: ...... &nbsp;&nbsp; - SBD vắng: ...................................</div><div style="margin-top:6px">Tổng số bài: .......... &nbsp;&nbsp;&nbsp; Tổng mã đề: ..........</div></div>';
            echo '<div class="signature-grid"><div>GIÁM THỊ 1<div class="sig-space"></div></div><div>GIÁM THỊ 2<div class="sig-space"></div></div><div>CHỦ TỊCH HỘI ĐỒNG<div class="sig-space"></div></div></div>';
        }
        echo '</section>';
    }
    if ($isPdfExport) {
        echo '<script>(function(){function fitText(sel,min){document.querySelectorAll(sel).forEach(function(el){var fs=parseFloat(window.getComputedStyle(el).fontSize)||11;while(el.scrollWidth>el.clientWidth&&fs>min){fs-=0.5;el.style.fontSize=fs+"px";}});}fitText(".name-cell",8);fitText(".class-cell",8);})();</script>';
        echo '<script>window.print();</script>';
    }
    echo '</body></html>';
    exit;
}

$baseQuery = [
    'exam_id' => $examId,
    'subject_id' => $subjectFilter,
    'search' => $search,
    'per_page' => $perPage,
];

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
<?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
<div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm"><div class="card-header bg-primary text-white d-flex justify-content-between align-items-center"><strong>Bước 6: In danh sách phòng thi</strong>
<div>
<?php if ($isExamLocked): ?>
<div class="d-flex flex-wrap gap-2">
    <a class="btn btn-light btn-sm" href="<?= BASE_URL ?>/modules/exams/print_rooms.php?<?= http_build_query(array_merge($baseQuery, ['export' => 'format1', 'file' => 'excel'])) ?>">Mẫu niêm yết (Excel)</a>
    <a class="btn btn-light btn-sm" href="<?= BASE_URL ?>/modules/exams/print_rooms.php?<?= http_build_query(array_merge($baseQuery, ['export' => 'format1', 'file' => 'pdf'])) ?>" target="_blank" rel="noopener">Mẫu niêm yết (PDF)</a>
    <a class="btn btn-light btn-sm" href="<?= BASE_URL ?>/modules/exams/print_rooms.php?<?= http_build_query(array_merge($baseQuery, ['export' => 'format2', 'file' => 'excel'])) ?>">Mẫu phiếu thu bài (Excel)</a>
    <a class="btn btn-light btn-sm" href="<?= BASE_URL ?>/modules/exams/print_rooms.php?<?= http_build_query(array_merge($baseQuery, ['export' => 'format2', 'file' => 'pdf'])) ?>" target="_blank" rel="noopener">Mẫu phiếu thu bài (PDF)</a>
    <?php if ($examMode === 2): ?><a class="btn btn-light btn-sm" href="<?= BASE_URL ?>/modules/exams/print_subject_list.php">DS theo môn</a><?php endif; ?>
</div>
<?php else: ?>
<span class="badge bg-warning text-dark">Phải khoá kỳ thi trước khi export danh sách</span>
<?php endif; ?>
</div></div>
<div class="card-body">
<?= exams_display_flash(); ?>
<?php if (!$isExamLocked): ?><div class="alert alert-warning">Phải khoá kỳ thi trước khi in danh sách</div><?php endif; ?>
<div class="mb-3 d-flex gap-2">
<?php if (!$isExamLocked): ?>
<form method="post" action="<?= BASE_URL ?>/modules/exams/print_rooms.php" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="lock_exam"><button class="btn btn-success btn-sm">Khoá kỳ thi</button></form>
<?php endif; ?>
<?php if ($role === 'admin' && $isExamLocked): ?><form method="post" action="<?= BASE_URL ?>/modules/exams/print_rooms.php" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="unlock_exam"><button class="btn btn-outline-danger btn-sm">Mở khoá kỳ thi</button></form><?php endif; ?>
</div>

<form method="get" action="<?= BASE_URL ?>/modules/exams/print_rooms.php" class="row g-2 mb-3">
    <div class="col-md-4">
        <label class="form-label">Lọc theo môn</label>
        <select class="form-select" name="subject_id">
            <option value="0">-- Tất cả môn --</option>
            <?php foreach ($subjectOptions as $opt): ?>
                <option value="<?= (int) $opt['id'] ?>" <?= $subjectFilter === (int) $opt['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $opt['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label">Tìm kiếm</label>
        <input class="form-control" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Tên phòng, SBD, họ tên, lớp">
    </div>
    <div class="col-md-2">
        <label class="form-label">Số phòng/trang</label>
        <select class="form-select" name="per_page"><?php foreach ($perPageOptions as $opt): ?><option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option><?php endforeach; ?></select>
    </div>
    <div class="col-md-2 d-flex gap-2 align-items-end">
        <button class="btn btn-primary" type="submit">Lọc</button>
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/modules/exams/print_rooms.php">Bỏ lọc</a>
    </div>
</form>

<?php if (empty($roomGroups)): ?><div class="alert alert-warning">Chưa có dữ liệu phòng thi phù hợp bộ lọc.</div><?php endif; ?>
<?php foreach ($roomGroups as $room): ?>
<div class="border rounded p-3 mb-3"><h5>Phòng: <?= htmlspecialchars($room['ten_phong'], ENT_QUOTES, 'UTF-8') ?> | Môn: <?= htmlspecialchars($room['ten_mon'], ENT_QUOTES, 'UTF-8') ?> | Khối: <?= htmlspecialchars($room['khoi'], ENT_QUOTES, 'UTF-8') ?></h5>
<table class="table table-sm table-bordered"><thead><tr><th>#</th><th>SBD</th><th>Họ tên</th><th>Lớp</th><th>Ngày sinh</th><?php if ($examMode === 2): ?><th>Môn thi</th><?php endif; ?></tr></thead><tbody>
<?php foreach($room['students'] as $i=>$st): ?><tr><td><?= $i+1 ?></td><td><?= htmlspecialchars($st['sbd'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($st['hoten'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($st['lop'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($st['ngaysinh'], ENT_QUOTES, 'UTF-8') ?></td><?php if ($examMode === 2): ?><td><?= htmlspecialchars((string)($st['mon_thi'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?></tr><?php endforeach; ?>
<?php if (empty($room['students'])): ?><tr><td colspan="<?= $examMode === 2 ? 6 : 5 ?>" class="text-center text-muted">(Phòng trống)</td></tr><?php endif; ?>
</tbody></table></div>
<?php endforeach; ?>

<?php if ($totalPages > 1): ?>
<?php $pageLink = static fn(int $target): string => BASE_URL . '/modules/exams/print_rooms.php?' . http_build_query(array_merge($baseQuery, ['page' => $target])); ?>
<nav><ul class="pagination pagination-sm">
<li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><?= $page <= 1 ? '<span class="page-link">Trước</span>' : '<a class="page-link" href="'.htmlspecialchars($pageLink($page-1), ENT_QUOTES, 'UTF-8').'">Trước</a>' ?></li>
<?php for ($p=max(1,$page-5); $p<=min($totalPages,$page+5); $p++): ?>
<li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($pageLink($p), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a></li>
<?php endfor; ?>
<li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><?= $page >= $totalPages ? '<span class="page-link">Sau</span>' : '<a class="page-link" href="'.htmlspecialchars($pageLink($page+1), ENT_QUOTES, 'UTF-8').'">Sau</a>' ?></li>
</ul></nav>
<?php endif; ?>
</div></div></div></div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
