<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';

$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$csrf = exams_get_csrf_token();
$role = (string) ($_SESSION['user']['role'] ?? '');
$examModeStmt = $pdo->prepare('SELECT exam_mode FROM exams WHERE id = :id LIMIT 1');
$examModeStmt->execute([':id' => $examId]);
$examMode = (int) ($examModeStmt->fetchColumn() ?: 1);
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

    $filename = 'danh_sach_phong_' . $export . '_exam_' . $examId . ($exportFile === 'excel' ? '.xls' : '.html');
    if ($exportFile === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    } else {
        header('Content-Type: text/html; charset=UTF-8');
    }
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Export phòng thi</title><style>body{font-family:"Times New Roman",serif;margin:24px}h3,h4,p{text-align:center;margin:2px 0}table{width:100%;border-collapse:collapse;margin-top:8px;margin-bottom:24px}th,td{border:1px solid #333;padding:4px;font-size:14px}th{text-align:center} .right{text-align:right}.center{text-align:center}</style></head><body>';

    foreach ($allGroups as $group) {
        if ($export === 'format1') {
            echo '<h3>TRƯỜNG THPT CHUYÊN TRẦN PHÚ</h3>';
            echo '<h4>DANH SÁCH NIÊM YẾT</h4>';
            echo '<p><strong>PHÒNG: ' . htmlspecialchars($group['ten_phong']) . '</strong> | Môn: ' . htmlspecialchars($group['ten_mon']) . '</p>';
            echo '<table><tr><th>STT</th><th>SBD</th><th>Họ và tên</th><th>Ngày sinh</th><th>Lớp</th><th>Ghi chú</th></tr>';
            foreach ($group['students'] as $i => $st) {
                echo '<tr><td class="center">' . ($i + 1) . '</td><td class="center">' . htmlspecialchars($st['sbd']) . '</td><td>' . htmlspecialchars($st['hoten']) . '</td><td class="center">' . htmlspecialchars($st['ngaysinh']) . '</td><td class="center">' . htmlspecialchars($st['lop']) . '</td><td></td></tr>';
            }
            echo '</table>';
            echo '<p class="right"><em>Hải Phòng, ngày ... tháng ... năm ...</em></p>';
            echo '<p class="right"><strong>CHỦ TỊCH HỘI ĐỒNG</strong></p>';
        } else {
            echo '<h3>TRƯỜNG THPT CHUYÊN TRẦN PHÚ</h3>';
            echo '<h4>PHIẾU THU BÀI</h4>';
            echo '<p><strong>PHÒNG: ' . htmlspecialchars($group['ten_phong']) . '</strong> | Môn: ' . htmlspecialchars($group['ten_mon']) . '</p>';
            echo '<table><tr><th>STT</th><th>SBD</th><th>Họ và tên</th><th>Ngày sinh</th><th>Lớp</th><th>Số tờ</th><th>Mã đề</th><th>Ghi chú / Ký tên</th></tr>';
            foreach ($group['students'] as $i => $st) {
                echo '<tr><td class="center">' . ($i + 1) . '</td><td class="center">' . htmlspecialchars($st['sbd']) . '</td><td>' . htmlspecialchars($st['hoten']) . '</td><td class="center">' . htmlspecialchars($st['ngaysinh']) . '</td><td class="center">' . htmlspecialchars($st['lop']) . '</td><td></td><td></td><td></td></tr>';
            }
            echo '</table>';
            echo '<p>Trong đó: ... học sinh tham dự; ... học sinh vắng.</p>';
            echo '<p class="right"><em>Hải Phòng, ngày ... tháng ... năm ...</em></p>';
            echo '<p><strong>GIÁM THỊ 1 &nbsp;&nbsp;&nbsp; GIÁM THỊ 2 &nbsp;&nbsp;&nbsp; CHỦ TỊCH HỘI ĐỒNG</strong></p>';
        }
        echo '<hr style="border:0;border-top:1px dashed #999;margin:24px 0">';
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
    <a class="btn btn-light btn-sm" href="?<?= http_build_query(array_merge($baseQuery, ['export' => 'format1', 'file' => 'excel'])) ?>">Mẫu niêm yết (Excel)</a>
    <a class="btn btn-light btn-sm" href="?<?= http_build_query(array_merge($baseQuery, ['export' => 'format1', 'file' => 'pdf'])) ?>">Mẫu niêm yết (PDF)</a>
    <a class="btn btn-light btn-sm" href="?<?= http_build_query(array_merge($baseQuery, ['export' => 'format2', 'file' => 'excel'])) ?>">Mẫu phiếu thu bài (Excel)</a>
    <a class="btn btn-light btn-sm" href="?<?= http_build_query(array_merge($baseQuery, ['export' => 'format2', 'file' => 'pdf'])) ?>">Mẫu phiếu thu bài (PDF)</a>
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
<form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="lock_exam"><button class="btn btn-success btn-sm">Khoá kỳ thi</button></form>
<?php endif; ?>
<?php if ($role === 'admin' && $isExamLocked): ?><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="unlock_exam"><button class="btn btn-outline-danger btn-sm">Mở khoá kỳ thi</button></form><?php endif; ?>
</div>

<form method="get" class="row g-2 mb-3">
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
