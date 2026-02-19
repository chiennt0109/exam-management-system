<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_once BASE_PATH . '/modules/exams/score_utils.php';
require_role(['admin', 'organizer', 'scorer']);

$csrf = exams_get_csrf_token();
$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$role = normalize_role((string) ($_SESSION['user']['role'] ?? ''));
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$isAdmin = $role === 'admin';
$draft = (array) ($_SESSION['score_import_draft'] ?? []);

if (($draft['exam_id'] ?? 0) !== $examId || empty($draft['rows']) || empty($draft['columns'])) {
    exams_set_flash('error', 'Không có dữ liệu import. Vui lòng tải file lại.');
    header('Location: ' . BASE_URL . '/modules/exams/import_scores.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !exams_verify_csrf($_POST['csrf_token'] ?? null)) {
    exams_set_flash('error', 'CSRF token không hợp lệ.');
    header('Location: ' . BASE_URL . '/modules/exams/import_scores.php');
    exit;
}

$importProfile = (string) ($draft['import_profile'] ?? 'assigned_scope');
$mode = (string) ($draft['mode'] ?? 'subject_room');
$subjectId = (int) ($draft['subject_id'] ?? 0);
$roomId = (int) ($draft['room_id'] ?? 0);
$scopeType = (string) ($draft['scope_type'] ?? 'khoi');
$scopeValue = (string) ($draft['scope_value'] ?? '');
$columns = (array) ($draft['columns'] ?? []);
$headers = (array) ($draft['headers'] ?? []);
$rows = (array) ($draft['rows'] ?? []);
$selectedSbd = (string) ($_POST['col_sbd'] ?? ($draft['col_sbd'] ?? ($columns[0] ?? '')));
if (!in_array($selectedSbd, $columns, true)) {
    $selectedSbd = $columns[0] ?? '';
}

$componentLabels = [
    'component_1' => 'Tự luận',
    'component_2' => 'Trắc nghiệm',
    'component_3' => 'Nói',
];

$subjectsStmt = $pdo->prepare('SELECT DISTINCT s.id, s.ma_mon, s.ten_mon
    FROM exam_students es
    INNER JOIN subjects s ON s.id = es.subject_id
    WHERE es.exam_id = :exam_id AND es.subject_id IS NOT NULL
    ORDER BY s.ten_mon');
$subjectsStmt->execute([':exam_id' => $examId]);
$subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);
$subjectMap = [];
foreach ($subjects as $s) {
    $sid = (int) ($s['id'] ?? 0);
    if ($sid > 0) {
        $subjectMap[$sid] = [
            'name' => (string) ($s['ten_mon'] ?? ('#' . $sid)),
            'code' => (string) ($s['ma_mon'] ?? ''),
        ];
    }
}

$componentCountBySubject = [];
$cfgStmt = $pdo->prepare('SELECT subject_id, MAX(component_count) AS component_count
    FROM exam_subject_config
    WHERE exam_id = :exam_id
    GROUP BY subject_id');
$cfgStmt->execute([':exam_id' => $examId]);
foreach ($cfgStmt->fetchAll(PDO::FETCH_ASSOC) as $cfg) {
    $sid = (int) ($cfg['subject_id'] ?? 0);
    $componentCountBySubject[$sid] = max(1, min(3, (int) ($cfg['component_count'] ?? 1)));
}

$targets = [];
if ($importProfile === 'all_exam') {
    if (!$isAdmin) {
        exams_set_flash('error', 'Chỉ admin mới dùng chế độ import toàn kỳ thi.');
        header('Location: ' . BASE_URL . '/modules/exams/import_scores.php');
        exit;
    }
    foreach ($subjectMap as $sid => $meta) {
        $count = $componentCountBySubject[$sid] ?? 1;
        $targets[] = ['key' => $sid . '|component_1', 'subject_id' => $sid, 'component' => 'component_1', 'label' => $meta['name'] . ' - Tự luận'];
        if ($count >= 2) $targets[] = ['key' => $sid . '|component_2', 'subject_id' => $sid, 'component' => 'component_2', 'label' => $meta['name'] . ' - Trắc nghiệm'];
        if ($count >= 3) $targets[] = ['key' => $sid . '|component_3', 'subject_id' => $sid, 'component' => 'component_3', 'label' => $meta['name'] . ' - Nói'];
    }
} else {
    if ($subjectId <= 0 || !isset($subjectMap[$subjectId])) {
        exams_set_flash('error', 'Không xác định được môn trong phạm vi import.');
        header('Location: ' . BASE_URL . '/modules/exams/import_scores.php');
        exit;
    }
    $count = $componentCountBySubject[$subjectId] ?? 1;
    $candidate = ['component_1'];
    if ($count >= 2) $candidate[] = 'component_2';
    if ($count >= 3) $candidate[] = 'component_3';

    if ($role === 'scorer') {
        $khoiValue = '';
        if ($mode === 'subject_room') {
            $khoiStmt = $pdo->prepare('SELECT khoi FROM rooms WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id LIMIT 1');
            $khoiStmt->execute([':id' => $roomId, ':exam_id' => $examId, ':subject_id' => $subjectId]);
            $khoiValue = (string) ($khoiStmt->fetchColumn() ?: '');
        } else {
            $khoiValue = $scopeType === 'khoi' ? $scopeValue : '';
        }
        $assignStmt = $pdo->prepare('SELECT DISTINCT component_name FROM score_assignments
            WHERE exam_id=:exam_id AND subject_id=:subject_id AND user_id=:user_id
              AND ((room_id IS NOT NULL AND room_id=:room_id) OR (room_id IS NULL AND khoi=:khoi))');
        $assignStmt->execute([':exam_id'=>$examId,':subject_id'=>$subjectId,':user_id'=>$userId,':room_id'=>$roomId,':khoi'=>$khoiValue]);
        $assigned = array_values(array_unique(array_map('strval', $assignStmt->fetchAll(PDO::FETCH_COLUMN))));
                $candidate = array_values(array_intersect($candidate, $assigned));
    }

    foreach ($candidate as $component) {
        $targets[] = [
            'key' => $subjectId . '|' . $component,
            'subject_id' => $subjectId,
            'component' => $component,
            'label' => $componentLabels[$component] ?? $component,
        ];
    }
}

if (empty($targets)) {
    exams_set_flash('error', 'Không có thành phần điểm được phân công để import trong phạm vi đã chọn.');
    header('Location: ' . BASE_URL . '/modules/exams/import_scores.php');
    exit;
}

$mapCols = (array) ($_POST['map_cols'] ?? ($draft['map_cols'] ?? []));
$availableCols = array_values($columns);
$colIdx = 1;
foreach ($targets as $target) {
    $key = $target['key'];
    $selected = (string) ($mapCols[$key] ?? ($availableCols[$colIdx] ?? ($availableCols[0] ?? '')));
    if (!in_array($selected, $availableCols, true)) {
        $selected = $availableCols[0] ?? '';
    }
    $mapCols[$key] = $selected;
    $colIdx++;
}
$draft['col_sbd'] = $selectedSbd;
$draft['map_cols'] = $mapCols;
$_SESSION['score_import_draft'] = $draft;

$baseStmt = $pdo->prepare('SELECT es.student_id, es.sbd, es.khoi, es.lop, st.hoten
    FROM exam_students es
    INNER JOIN students st ON st.id = es.student_id
    WHERE es.exam_id = :exam_id AND es.subject_id IS NULL');
$baseStmt->execute([':exam_id' => $examId]);
$baseBySbd = [];
foreach ($baseStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $sbd = trim((string) ($row['sbd'] ?? ''));
    if ($sbd !== '') {
        $baseBySbd[$sbd] = $row;
    }
}

$registered = [];
$regStmt = $pdo->prepare('SELECT student_id, subject_id FROM exam_student_subjects WHERE exam_id = :exam_id');
$regStmt->execute([':exam_id' => $examId]);
foreach ($regStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $registered[(int)($r['student_id'] ?? 0)][(int)($r['subject_id'] ?? 0)] = true;
}

$scopeMembers = [];
$roomMembersBySubject = [];
if ($importProfile === 'assigned_scope') {
    if ($mode === 'subject_grade') {
        $whereCol = $scopeType === 'lop' ? 'lop' : 'khoi';
        $scopeStmt = $pdo->prepare("SELECT student_id FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NULL AND $whereCol = :scope_value");
        $scopeStmt->execute([':exam_id' => $examId, ':scope_value' => $scopeValue]);
        $scopeMembers = array_fill_keys(array_map('intval', $scopeStmt->fetchAll(PDO::FETCH_COLUMN)), true);
    } else {
        $roomStmt = $pdo->prepare('SELECT student_id, subject_id FROM exam_students WHERE exam_id = :exam_id AND room_id = :room_id AND subject_id IS NOT NULL');
        $roomStmt->execute([':exam_id' => $examId, ':room_id' => $roomId]);
        foreach ($roomStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $roomMembersBySubject[(int)$r['subject_id']][(int)$r['student_id']] = true;
        }
    }
}

$maxBySubjectComponent = [];
foreach ($subjectMap as $sid => $_meta) {
    $cfg = $pdo->prepare('SELECT COALESCE(tong_diem,10) AS tong_diem, COALESCE(diem_tu_luan,10) AS diem_tu_luan, COALESCE(diem_trac_nghiem,0) AS diem_trac_nghiem, COALESCE(diem_noi,0) AS diem_noi FROM exam_subject_config WHERE exam_id = :exam_id AND subject_id = :subject_id ORDER BY id DESC LIMIT 1');
    $cfg->execute([':exam_id' => $examId, ':subject_id' => $sid]);
    $r = $cfg->fetch(PDO::FETCH_ASSOC) ?: [];
    $maxBySubjectComponent[$sid] = [
        'total' => (float) ($r['tong_diem'] ?? 10),
        'component_1' => (float) ($r['diem_tu_luan'] ?? 10),
        'component_2' => (float) ($r['diem_trac_nghiem'] ?? 0),
        'component_3' => (float) ($r['diem_noi'] ?? 0),
    ];
}

$preview = [];
$validRows = [];
$existingCount = 0;
$perPageOptions = [20, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 20);
if (!in_array($perPage, $perPageOptions, true)) { $perPage = 20; }
$page = max(1, (int) ($_GET['page'] ?? 1));

foreach ($rows as $idx => $row) {
    $sbd = trim((string) ($row[$selectedSbd] ?? ''));
    $name = '';
    $studentId = 0;
    $status = '✅ Hợp lệ';

    if ($sbd === '') {
        $status = '⚠ Thiếu SBD';
    } elseif (!isset($baseBySbd[$sbd])) {
        $status = '⚠ Không tìm thấy SBD';
    } else {
        $student = $baseBySbd[$sbd];
        $studentId = (int) ($student['student_id'] ?? 0);
        $name = (string) ($student['hoten'] ?? '');

        $scorePreview = [];
        foreach ($targets as $target) {
            $sid = (int) $target['subject_id'];
            $comp = (string) $target['component'];
            $raw = trim((string) ($row[$mapCols[$target['key']] ?? ''] ?? ''));
            $maxScore = (float) ($maxBySubjectComponent[$sid][$comp] ?? 10);
            $parsed = parseSmartScore($raw, $maxScore);

            if (!isset($registered[$studentId][$sid])) {
                $status = '⚠ Không đăng ký môn ' . ($subjectMap[$sid]['name'] ?? ('#'.$sid));
                continue;
            }
            if ($importProfile === 'assigned_scope') {
                if ($mode === 'subject_room' && empty($roomMembersBySubject[$sid][$studentId])) {
                    $status = '⚠ Không thuộc phòng import';
                    continue;
                }
                if ($mode === 'subject_grade' && !isset($scopeMembers[$studentId])) {
                    $status = '⚠ Không thuộc phạm vi khối/lớp';
                    continue;
                }
            }
            if ($raw !== '' && $parsed === null) {
                $status = '⚠ Điểm không hợp lệ ở ' . $target['label'];
                $scorePreview[$target['label']] = $raw . ' → lỗi';
                continue;
            }

            $scorePreview[$target['label']] = $raw === '' ? '' : ($raw . ' → ' . ($parsed === null ? '' : (string) $parsed));

            $validRows[] = [
                'student_id' => $studentId,
                'subject_id' => $sid,
                'component_name' => $comp,
                'parsed_score' => $parsed,
            ];
        }
    }

    $preview[] = [
        'line' => $idx + 2,
        'sbd' => $sbd,
        'name' => $name,
        'status' => $status,
        'score_preview' => $scorePreview ?? [],
    ];
}

$checkExisting = $pdo->prepare('SELECT 1 FROM exam_scores WHERE exam_id = :exam_id AND subject_id = :subject_id AND student_id = :student_id LIMIT 1');
foreach ($validRows as $v) {
    $checkExisting->execute([':exam_id'=>$examId,':subject_id'=>(int)$v['subject_id'],':student_id'=>(int)$v['student_id']]);
    if ($checkExisting->fetchColumn()) {
        $existingCount++;
    }
}

$totalRows = count($preview);
$totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;
$previewPageRows = array_slice($preview, $offset, $perPage);

$_SESSION['score_import_preview'] = [
    'exam_id' => $examId,
    'valid_rows' => $validRows,
    'import_profile' => $importProfile,
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
            <div class="alert alert-danger">⚠ Đây là bước quan trọng, mapping sai cột có thể làm sai toàn bộ dữ liệu điểm.</div>
            <form method="post" class="row g-3 mb-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="col-md-3"><label class="form-label">Cột SBD</label><select name="col_sbd" class="form-select"><?php foreach ($columns as $col): ?><option value="<?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedSbd === $col ? 'selected' : '' ?>><?= htmlspecialchars(colLabel($col, $headers), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <?php foreach ($targets as $t): ?>
                    <div class="col-md-3"><label class="form-label"><?= htmlspecialchars((string) $t['label'], ENT_QUOTES, 'UTF-8') ?></label><select class="form-select" name="map_cols[<?= htmlspecialchars((string) $t['key'], ENT_QUOTES, 'UTF-8') ?>]"><?php foreach ($columns as $col): ?><option value="<?= htmlspecialchars($col, ENT_QUOTES, 'UTF-8') ?>" <?= ($mapCols[$t['key']] ?? '') === $col ? 'selected' : '' ?>><?= htmlspecialchars(colLabel($col, $headers), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <?php endforeach; ?>
                <div class="col-12 d-grid"><button class="btn btn-outline-primary" type="submit">Cập nhật preview</button></div>
            </form>

            <?php if ($existingCount > 0): ?><div class="alert alert-warning">⚠ Có <?= $existingCount ?> bản ghi đã có điểm, hãy chọn chiến lược import cẩn thận.</div><?php endif; ?>

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead><tr><th>Dòng</th><th>SBD</th><th>Họ tên</th><?php foreach ($targets as $t): ?><th><?= htmlspecialchars((string) $t['label'], ENT_QUOTES, 'UTF-8') ?></th><?php endforeach; ?><th>Trạng thái</th></tr></thead>
                    <tbody><?php foreach ($previewPageRows as $r): ?><tr><td><?= (int) $r['line'] ?></td><td><?= htmlspecialchars((string) $r['sbd'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8') ?></td><?php foreach ($targets as $t): ?><td><?= htmlspecialchars((string) (($r['score_preview'][(string)$t['label']] ?? '')), ENT_QUOTES, 'UTF-8') ?></td><?php endforeach; ?><td><?= htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?></tbody>
                </table>
            </div>
            <?php if ($totalRows > 0): ?>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <div class="small text-muted">Hiển thị <?= count($previewPageRows) ?> / <?= $totalRows ?> dòng</div>
                    <div class="btn-group">
                        <?php $baseQuery = ['page'=>max(1,$page-1),'per_page'=>$perPage]; ?>
                        <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= http_build_query($baseQuery) ?>">Trước</a>
                        <?php $nextQuery = ['page'=>min($totalPages,$page+1),'per_page'=>$perPage]; ?>
                        <a class="btn btn-sm btn-outline-secondary <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= http_build_query($nextQuery) ?>">Sau</a>
                    </div>
                </div>
            <?php endif; ?>

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
