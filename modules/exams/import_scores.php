<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_role(['admin', 'organizer', 'scorer']);

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

$hasPhpSpreadsheet = class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class);

$csrf = exams_get_csrf_token();
$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$errors = [];
$role = normalize_role((string) ($_SESSION['user']['role'] ?? ''));
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$isAdmin = $role === 'admin';

$roomsStmt = $pdo->prepare('SELECT r.id, r.ten_phong, r.khoi, s.ten_mon, r.subject_id
    FROM rooms r
    INNER JOIN subjects s ON s.id = r.subject_id
    WHERE r.exam_id = :exam_id
    ORDER BY s.ten_mon, r.ten_phong');
$roomsStmt->execute([':exam_id' => $examId]);
$rooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);

$subjectsStmt = $pdo->prepare('SELECT DISTINCT s.id, s.ma_mon, s.ten_mon
    FROM exam_students es
    INNER JOIN subjects s ON s.id = es.subject_id
    WHERE es.exam_id = :exam_id AND es.subject_id IS NOT NULL
    ORDER BY s.ten_mon');
$subjectsStmt->execute([':exam_id' => $examId]);
$subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);

$khoiOptions = $pdo->prepare('SELECT DISTINCT khoi FROM exam_students WHERE exam_id = :exam_id AND khoi IS NOT NULL AND khoi <> "" ORDER BY khoi');
$khoiOptions->execute([':exam_id' => $examId]);
$khois = $khoiOptions->fetchAll(PDO::FETCH_COLUMN);

$lopOptions = $pdo->prepare('SELECT DISTINCT lop FROM exam_students WHERE exam_id = :exam_id AND lop IS NOT NULL AND lop <> "" ORDER BY lop');
$lopOptions->execute([':exam_id' => $examId]);
$lops = $lopOptions->fetchAll(PDO::FETCH_COLUMN);

$importProfile = (string) ($_POST['import_profile'] ?? 'assigned_scope');
if (!in_array($importProfile, ['assigned_scope', 'all_exam'], true)) {
    $importProfile = 'assigned_scope';
}
if (!$isAdmin && $importProfile === 'all_exam') {
    $importProfile = 'assigned_scope';
}

$mode = (string) ($_POST['mode'] ?? 'subject_room');
if (!in_array($mode, ['subject_grade', 'subject_room'], true)) {
    $mode = 'subject_room';
}
$subjectId = max(0, (int) ($_POST['subject_id'] ?? 0));
$roomId = max(0, (int) ($_POST['room_id'] ?? 0));
$scopeType = (string) ($_POST['scope_type'] ?? 'khoi');
if (!in_array($scopeType, ['khoi', 'lop'], true)) {
    $scopeType = 'khoi';
}
$scopeValue = trim((string) ($_POST['scope_value'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }

    if ($importProfile === 'assigned_scope') {
        if ($subjectId <= 0) {
            $errors[] = 'Vui lòng chọn môn thi.';
        }
        if ($mode === 'subject_room' && $roomId <= 0) {
            $errors[] = 'Vui lòng chọn phòng thi.';
        }
        if ($mode === 'subject_grade' && $scopeValue === '') {
            $errors[] = 'Vui lòng chọn khối/lớp.';
        }
    }

    $columns = [];
    $headers = [];
    $rows = [];

    $headersJson = (string) ($_POST['parsed_headers_json'] ?? '');
    $rowsJson = (string) ($_POST['parsed_rows_json'] ?? '');

    if ($headersJson !== '' && $rowsJson !== '') {
        $decodedHeaders = json_decode($headersJson, true);
        $decodedRows = json_decode($rowsJson, true);
        if (is_array($decodedHeaders) && is_array($decodedRows) && !empty($decodedHeaders)) {
            $headers = $decodedHeaders;
            $rows = $decodedRows;
            $columns = array_keys($headers);
        }
    }

    if (empty($columns) && empty($errors)) {
        if (empty($_FILES['excelfile']['tmp_name']) || !is_uploaded_file($_FILES['excelfile']['tmp_name'])) {
            $errors[] = 'Vui lòng chọn tệp Excel hợp lệ.';
        } elseif ($hasPhpSpreadsheet) {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load((string) $_FILES['excelfile']['tmp_name']);
                $sheet = $spreadsheet->getActiveSheet();
                $highestColumn = $sheet->getHighestDataColumn();
                $highestRow = (int) $sheet->getHighestDataRow();
                $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

                for ($i = 1; $i <= $maxCol; $i++) {
                    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
                    $columns[] = $col;
                    $cell = $sheet->getCell($col . '1')->getCalculatedValue();
                    $headers[$col] = is_scalar($cell) ? trim((string) $cell) : '';
                }

                for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
                    $row = [];
                    foreach ($columns as $column) {
                        $value = $sheet->getCell($column . $rowIndex)->getCalculatedValue();
                        $row[$column] = is_scalar($value) ? trim((string) $value) : '';
                    }
                    $rows[] = $row;
                }
            } catch (Throwable $e) {
                $errors[] = 'Không thể đọc file Excel: ' . $e->getMessage();
            }
        } else {
            $errors[] = 'Thiếu thư viện PHPSpreadsheet trên server.';
        }
    }

    if (empty($errors)) {
        $_SESSION['score_import_draft'] = [
            'exam_id' => $examId,
            'import_profile' => $importProfile,
            'mode' => $mode,
            'subject_id' => $subjectId,
            'room_id' => $roomId,
            'scope_type' => $scopeType,
            'scope_value' => $scopeValue,
            'columns' => $columns,
            'headers' => $headers,
            'rows' => $rows,
            'col_sbd' => $columns[0] ?? '',
            'map_cols' => [],
        ];

        header('Location: ' . BASE_URL . '/modules/exams/preview_import.php');
        exit;
    }
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Import điểm thi - Bước 1: Duyệt file</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>
                <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <div class="alert alert-danger">⚠ Thao tác import có thể ghi đè hoặc xóa dữ liệu điểm. Vui lòng kiểm tra kỹ trước khi xác nhận lưu ở bước cuối.</div>

                <form method="POST" enctype="multipart/form-data" class="row g-3" id="scoreImportForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="parsed_headers_json" id="parsedHeadersJson">
                    <input type="hidden" name="parsed_rows_json" id="parsedRowsJson">
                    <input type="hidden" name="import" value="1">

                    <div class="col-md-4">
                        <label class="form-label" for="import_profile">Chế độ import</label>
                        <select id="import_profile" name="import_profile" class="form-select" onchange="toggleImportProfile(this.value)">
                            <option value="assigned_scope" <?= $importProfile === 'assigned_scope' ? 'selected' : '' ?>>Chế độ 1: Theo phạm vi phân công</option>
                            <?php if ($isAdmin): ?><option value="all_exam" <?= $importProfile === 'all_exam' ? 'selected' : '' ?>>Chế độ 2: Admin import toàn kỳ thi</option><?php endif; ?>
                        </select>
                    </div>

                    <div class="col-md-4 profile-assigned">
                        <label class="form-label" for="mode">Phạm vi</label>
                        <select id="mode" name="mode" class="form-select" onchange="toggleMode(this.value)">
                            <option value="subject_grade" <?= $mode === 'subject_grade' ? 'selected' : '' ?>>Môn + Khối/Lớp</option>
                            <option value="subject_room" <?= $mode === 'subject_room' ? 'selected' : '' ?>>Môn + Phòng</option>
                        </select>
                    </div>

                    <div class="col-md-4 profile-assigned">
                        <label class="form-label" for="subject_id">Môn thi</label>
                        <select id="subject_id" name="subject_id" class="form-select">
                            <option value="0">-- Chọn môn --</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= (int) $subject['id'] ?>" <?= $subjectId === (int) $subject['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) (($subject['ma_mon'] ?? '') . ' - ' . $subject['ten_mon']), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4 mode-room profile-assigned">
                        <label class="form-label" for="room_id">Phòng thi</label>
                        <select id="room_id" name="room_id" class="form-select">
                            <option value="0">-- Chọn phòng --</option>
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?= (int) $room['id'] ?>" <?= $roomId === (int) $room['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $room['ten_mon'] . ' | ' . $room['ten_phong'] . ' | Khối ' . $room['khoi'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2 mode-grade profile-assigned">
                        <label class="form-label" for="scope_type">Theo</label>
                        <select id="scope_type" name="scope_type" class="form-select">
                            <option value="khoi" <?= $scopeType === 'khoi' ? 'selected' : '' ?>>Khối</option>
                            <option value="lop" <?= $scopeType === 'lop' ? 'selected' : '' ?>>Lớp</option>
                        </select>
                    </div>
                    <div class="col-md-2 mode-grade profile-assigned">
                        <label class="form-label" for="scope_value">Giá trị</label>
                        <select id="scope_value" name="scope_value" class="form-select">
                            <option value="">-- Chọn --</option>
                            <?php foreach ($khois as $khoi): ?>
                                <option class="opt-khoi" value="<?= htmlspecialchars((string) $khoi, ENT_QUOTES, 'UTF-8') ?>" <?= $scopeType === 'khoi' && $scopeValue === (string) $khoi ? 'selected' : '' ?>><?= htmlspecialchars((string) $khoi, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                            <?php foreach ($lops as $lop): ?>
                                <option class="opt-lop" value="<?= htmlspecialchars((string) $lop, ENT_QUOTES, 'UTF-8') ?>" <?= $scopeType === 'lop' && $scopeValue === (string) $lop ? 'selected' : '' ?>><?= htmlspecialchars((string) $lop, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label" for="excelfile">Tệp Excel</label>
                        <input type="file" name="excelfile" id="excelfile" class="form-control" accept=".xlsx,.xls" required>
                        <div class="form-text" id="parseStatus">Chưa đọc file.</div>
                    </div>
                    <div class="col-md-4 d-grid align-items-end">
                        <button type="submit" name="import" id="import-btn" class="btn btn-primary mt-4">Duyệt file</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
function toggleImportProfile(profile){
  document.querySelectorAll('.profile-assigned').forEach(el => el.style.display = profile === 'assigned_scope' ? '' : 'none');
}
function toggleMode(mode) {
    document.querySelectorAll('.mode-room').forEach(el => el.style.display = mode === 'subject_room' ? '' : 'none');
    document.querySelectorAll('.mode-grade').forEach(el => el.style.display = mode === 'subject_grade' ? '' : 'none');
}
function syncScopeValue() {
    const scopeType = document.getElementById('scope_type')?.value || 'khoi';
    const select = document.getElementById('scope_value');
    if (!select) return;
    Array.from(select.options).forEach(opt => {
        if (opt.value === '') { opt.hidden = false; return; }
        opt.hidden = scopeType === 'khoi' ? !opt.classList.contains('opt-khoi') : !opt.classList.contains('opt-lop');
    });
    if (select.selectedOptions[0] && select.selectedOptions[0].hidden) {
        select.value = '';
    }
}

document.getElementById('scoreImportForm')?.addEventListener('submit', function (e) {
    const form = e.currentTarget;
    const input = document.getElementById('excelfile');
    const file = input?.files?.[0];
    if (!file) { return; }

    if (typeof XLSX === 'undefined') {
        // fallback: let server parse file
        return;
    }

    e.preventDefault();
    const submitBtn = document.getElementById('import-btn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Đang đọc file...';
    }

    const reader = new FileReader();
    reader.onload = (evt) => {
        try {
            const data = new Uint8Array(evt.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const sheetName = workbook.SheetNames[0];
            const rows = XLSX.utils.sheet_to_json(workbook.Sheets[sheetName], { header: 1, defval: '' });
            if (!rows.length) {
                alert('File Excel trống.');
                return;
            }

            const headersArray = rows[0].map((v, idx) => {
                const txt = String(v || '').trim();
                return txt !== '' ? txt : `Cột ${idx + 1}`;
            });
            const columns = headersArray.map((_, idx) => XLSX.utils.encode_col(idx));
            const headers = {};
            columns.forEach((col, idx) => { headers[col] = headersArray[idx]; });
            const dataRows = rows.slice(1).map((r) => {
                const row = {};
                columns.forEach((col, idx) => { row[col] = String(r[idx] ?? '').trim(); });
                return row;
            });

            document.getElementById('parsedHeadersJson').value = JSON.stringify(headers);
            document.getElementById('parsedRowsJson').value = JSON.stringify(dataRows);
            document.getElementById('parseStatus').textContent = `Đã đọc ${dataRows.length} dòng dữ liệu.`;

            if (window.confirm('Xác nhận chuyển sang bước mapping và preview dữ liệu import?')) {
                form.submit();
            }
        } catch (err) {
            alert('Không đọc được file Excel. Vui lòng kiểm tra định dạng file.');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Duyệt file';
            }
        }
    };

    reader.onerror = () => {
        alert('Không thể đọc tệp đã chọn.');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Duyệt file';
        }
    };

    reader.readAsArrayBuffer(file);
});

document.getElementById('scope_type')?.addEventListener('change', syncScopeValue);
toggleImportProfile('<?= $importProfile ?>');
toggleMode('<?= $mode ?>');
syncScopeValue();
</script>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
