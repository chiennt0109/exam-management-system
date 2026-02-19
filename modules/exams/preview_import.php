<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_once BASE_PATH . '/modules/exams/score_utils.php';
require_role(['admin', 'organizer', 'scorer']);

$csrf = exams_get_csrf_token();
$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$role = (string) ($_SESSION['user']['role'] ?? '');
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$draft = (array) ($_SESSION['score_import_draft'] ?? []);

if (($draft['exam_id'] ?? 0) !== $examId || empty($draft['rows']) || empty($draft['columns'])) {
    exams_set_flash('error', 'Không có dữ liệu import. Vui lòng tải file lại.');
    header('Location: ' . BASE_URL . '/modules/exams/import_scores.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !exams_verify_csrf($_POST['csrf_token'] ?? null)) {
    exams_set_flash('error', 'CSRF token không hợp lệ.');
    header('Location: ' . BASE_URL . '/modules/exams/import_scores.php');
    exit;
}

$mode = (string) ($draft['mode'] ?? 'subject_room');
$subjectId = (int) ($draft['subject_id'] ?? 0);
$roomId = (int) ($draft['room_id'] ?? 0);
$scopeType = (string) ($draft['scope_type'] ?? 'khoi');
$scopeValue = (string) ($draft['scope_value'] ?? '');
$targetComponent = (string) ($draft['target_component'] ?? 'total');
$columns = (array) ($draft['columns'] ?? []);
$headers = (array) ($draft['headers'] ?? []);
$rows = (array) ($draft['rows'] ?? []);

$selectedSbd = (string) ($_POST['col_sbd'] ?? ($draft['col_sbd'] ?? ($columns[0] ?? '')));
$selectedScore = (string) ($_POST['col_score'] ?? ($draft['col_score'] ?? ($columns[1] ?? ($columns[0] ?? ''))));

if (!in_array($selectedSbd, $columns, true)) {
    $selectedSbd = $columns[0] ?? '';
}
if (!in_array($selectedScore, $columns, true)) {
    $selectedScore = $columns[1] ?? ($columns[0] ?? '');
}

$draft['col_sbd'] = $selectedSbd;
$draft['col_score'] = $selectedScore;
$_SESSION['score_import_draft'] = $draft;

$subjectNameStmt = $pdo->prepare('SELECT ten_mon FROM subjects WHERE id = :id LIMIT 1');
$subjectNameStmt->execute([':id' => $subjectId]);
$subjectName = (string) ($subjectNameStmt->fetchColumn() ?: ('#' . $subjectId));

$maxStmt = $pdo->prepare('SELECT COALESCE(tong_diem, 10) FROM exam_subject_config WHERE exam_id = :exam_id AND subject_id = :subject_id ORDER BY id DESC LIMIT 1');
$maxStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId]);
$maxScore = (float) ($maxStmt->fetchColumn() ?: 10);

$baseStmt = $pdo->prepare('SELECT es.student_id, es.sbd, es.khoi, es.lop, st.hoten
    FROM exam_students es
    INNER JOIN students st ON st.id = es.student_id
    WHERE es.exam_id = :exam_id AND es.subject_id IS NULL');
$baseStmt->execute([':exam_id' => $examId]);
$baseRows = $baseStmt->fetchAll(PDO::FETCH_ASSOC);
$baseBySbd = [];
foreach ($baseRows as $row) {
    $sbd = trim((string) ($row['sbd'] ?? ''));
    if ($sbd !== '') {
        $baseBySbd[$sbd] = $row;
    }
}

$regStmt = $pdo->prepare('SELECT student_id FROM exam_student_subjects WHERE exam_id = :exam_id AND subject_id = :subject_id');
$regStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId]);
$registered = array_fill_keys(array_map('intval', $regStmt->fetchAll(PDO::FETCH_COLUMN)), true);

$roomMembers = [];
if ($mode === 'subject_room') {
    $roomStmt = $pdo->prepare('SELECT student_id FROM exam_students WHERE exam_id = :exam_id AND subject_id = :subject_id AND room_id = :room_id');
    $roomStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':room_id' => $roomId]);
    $roomMembers = array_fill_keys(array_map('intval', $roomStmt->fetchAll(PDO::FETCH_COLUMN)), true);
}

$scopeMembers = [];
if ($mode === 'subject_grade') {
    $whereCol = $scopeType === 'lop' ? 'lop' : 'khoi';
    $scopeStmt = $pdo->prepare("SELECT student_id FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NULL AND $whereCol = :scope_value");
    $scopeStmt->execute([':exam_id' => $examId, ':scope_value' => $scopeValue]);
    $scopeMembers = array_fill_keys(array_map('intval', $scopeStmt->fetchAll(PDO::FETCH_COLUMN)), true);
}

$preview = [];
$validRows = [];
$candidateIds = [];

foreach ($rows as $idx => $row) {
    $sbd = trim((string) ($row[$selectedSbd] ?? ''));
    $rawScore = trim((string) ($row[$selectedScore] ?? ''));
    $parsed = parseSmartScore($rawScore, $maxScore);

    $status = '✅ Hợp lệ';
    $statusCode = 'valid';
    $name = '';
    $studentId = 0;

    if ($sbd === '') {
        $status = '⚠ Thiếu SBD';
        $statusCode = 'missing_sbd';
    } elseif (!isset($baseBySbd[$sbd])) {
        $status = '⚠ Không tìm thấy SBD';
        $statusCode = 'missing_student';
    } else {
        $student = $baseBySbd[$sbd];
        $studentId = (int) $student['student_id'];
        $name = (string) $student['hoten'];

        if (!isset($registered[$studentId])) {
            $status = '⚠ Không đăng ký môn';
            $statusCode = 'not_registered';
        } elseif ($mode === 'subject_room' && !isset($roomMembers[$studentId])) {
            $status = '⚠ Không thuộc phòng';
            $statusCode = 'not_in_room';
        } elseif ($mode === 'subject_grade' && !isset($scopeMembers[$studentId])) {
            $status = '⚠ Không thuộc khối/lớp';
            $statusCode = 'not_in_scope';
        } else {
            if (normalize_role($role) === 'scorer') {
                $assignStmt = $pdo->prepare('SELECT 1 FROM score_assignments WHERE exam_id = :exam_id AND subject_id = :subject_id AND user_id = :user_id AND component_name = :component_name AND ((room_id IS NOT NULL AND room_id = :room_id) OR (room_id IS NULL AND khoi = :khoi)) LIMIT 1');
                $assignStmt->execute([
                    ':exam_id' => $examId,
                    ':subject_id' => $subjectId,
                    ':user_id' => $userId,
                    ':component_name' => $targetComponent,
                    ':room_id' => $roomId,
                    ':khoi' => (string) ($student['khoi'] ?? ''),
                ]);
                if (!$assignStmt->fetchColumn()) {
                    $status = '⚠ Không được phân công thành phần điểm này';
                    $statusCode = 'not_assigned_component';
                }
            }

            if ($statusCode === 'valid' && $rawScore !== '' && $parsed === null) {
                $status = '⚠ Điểm vượt max/không hợp lệ';
                $statusCode = 'invalid_score';
            }
        }
    }

    if ($statusCode === 'valid') {
        $validRows[] = [
            'student_id' => $studentId,
            'sbd' => $sbd,
            'name' => $name,
            'raw_score' => $rawScore,
            'parsed_score' => $parsed,
        ];
        $candidateIds[] = $studentId;
    }

    $preview[] = [
        'line' => $idx + 2,
        'sbd' => $sbd,
        'name' => $name,
        'raw_score' => $rawScore,
        'parsed_score' => $parsed,
        'status' => $status,
        'status_code' => $statusCode,
    ];
}

$existingByStudent = [];
$existingCount = 0;
if ($candidateIds) {
    $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
    $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
    $sql = "SELECT student_id, score FROM exam_scores WHERE exam_id = ? AND subject_id = ? AND student_id IN ($placeholders)";
    $params = array_merge([$examId, $subjectId], $candidateIds);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sid = (int) $row['student_id'];
        $existingByStudent[$sid] = $row['score'];
    }
}

foreach ($preview as &$p) {
    if (($p['status_code'] ?? '') === 'valid' && isset($baseBySbd[$p['sbd']])) {
        $sid = (int) $baseBySbd[$p['sbd']]['student_id'];
        if (array_key_exists($sid, $existingByStudent)) {
            $p['status'] = '⚠ Đã có điểm';
            $existingCount++;
        }
    }
}
unset($p);

$_SESSION['score_import_preview'] = [
    'exam_id' => $examId,
    'mode' => $mode,
    'subject_id' => $subjectId,
    'room_id' => $roomId,
    'scope_type' => $scopeType,
    'scope_value' => $scopeValue,
    'col_sbd' => $selectedSbd,
    'col_score' => $selectedScore,
    'max_score' => $maxScore,
    'target_component' => $targetComponent,
    'valid_rows' => $validRows,
    'existing_ids' => array_map('intval', array_keys($existingByStudent)),
];

function colLabel(string $col, array $headers): string
{
    $h = trim((string) ($headers[$col] ?? ''));
    return $h === '' ? $col : ($col . ' - ' . $h);
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm mb-3"><div class="card-header bg-primary text-white"><strong>Import điểm thi - Bước 2/3: Mapping + Preview</strong></div><div class="card-body">
            <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <form method="post" class="row g-3 mb-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="col-md-4"><label class="form-label">Cột SBD</label><select name="col_sbd" class="form-select"><?php foreach ($columns as $col): ?><option value="<?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedSbd === $col ? 'selected' : '' ?>><?= htmlspecialchars(colLabel($col, $headers), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4"><label class="form-label">Cột Điểm</label><select name="col_score" class="form-select"><?php foreach ($columns as $col): ?><option value="<?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedScore === $col ? 'selected' : '' ?>><?= htmlspecialchars(colLabel($col, $headers), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4 d-grid align-items-end"><button class="btn btn-outline-primary mt-4" type="submit">Cập nhật preview</button></div>
            </form>

            <div class="alert alert-secondary mb-2">Môn: <strong><?= htmlspecialchars($subjectName, ENT_QUOTES, 'UTF-8') ?></strong> | Thành phần import: <strong><?= htmlspecialchars((string) $targetComponent, ENT_QUOTES, 'UTF-8') ?></strong> | Max điểm: <strong><?= htmlspecialchars((string) $maxScore, ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div class="alert alert-danger">⚠ Hành động import có thể ghi đè hoặc xóa dữ liệu điểm hiện có. Hãy xác nhận kỹ trước khi lưu.</div>
            <?php if ($existingCount > 0): ?>
                <div class="alert alert-warning">⚠ Đã có dữ liệu điểm cho <?= $existingCount ?> thí sinh.</div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead><tr><th>Dòng</th><th>SBD</th><th>Họ tên</th><th>Điểm gốc</th><th>Điểm parse</th><th>Trạng thái</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview as $r): ?>
                        <tr>
                            <td><?= (int) $r['line'] ?></td>
                            <td><?= htmlspecialchars((string) $r['sbd'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $r['raw_score'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($r['parsed_score'] === null ? 'NULL' : (string) $r['parsed_score'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" action="<?= BASE_URL ?>/modules/exams/process_import.php" class="d-flex gap-2 mt-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <button class="btn btn-danger" type="submit" name="strategy" value="overwrite" onclick="return confirm('Xác nhận GHI ĐÈ dữ liệu điểm hiện có?')">Ghi đè</button>
                <button class="btn btn-warning" type="submit" name="strategy" value="skip_existing" onclick="return confirm('Xác nhận import và BỎ QUA các bản ghi đã có?')">Bỏ qua bản ghi đã có</button>
                <button class="btn btn-secondary" type="submit" name="strategy" value="cancel">Hủy</button>
            </form>
        </div></div>
    </div>
</div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
