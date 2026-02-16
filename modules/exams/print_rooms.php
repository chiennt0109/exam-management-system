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

$stmt = $pdo->prepare('SELECT r.id AS room_id, r.ten_phong, r.khoi, sub.ten_mon, es.student_id, es.sbd, st.hoten, st.lop, st.ngaysinh
    FROM rooms r
    INNER JOIN subjects sub ON sub.id = r.subject_id
    LEFT JOIN exam_students es ON es.room_id = r.id
    LEFT JOIN students st ON st.id = es.student_id
    WHERE r.exam_id = :exam_id
    ORDER BY sub.ten_mon, r.khoi, r.ten_phong, es.sbd');
$stmt->execute([':exam_id' => $examId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


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

$roomGroups = [];
foreach ($rows as $row) {
    $key = (string) $row['room_id'];
    if (!isset($roomGroups[$key])) {
        $roomGroups[$key] = ['ten_phong' => (string) $row['ten_phong'], 'khoi' => (string) $row['khoi'], 'ten_mon' => (string) $row['ten_mon'], 'students' => []];
    }
    if (!empty($row['hoten'])) {
        $dob = (string) ($row['ngaysinh'] ?? '');
        $ts = strtotime($dob);
        $studentId = (int) ($row['student_id'] ?? 0);
        $roomGroups[$key]['students'][] = ['sbd' => (string) $row['sbd'], 'hoten' => (string) $row['hoten'], 'lop' => (string) $row['lop'], 'ngaysinh' => $ts ? date('d/m/Y', $ts) : $dob, 'mon_thi' => (string) ($studentSubjectsMap[$studentId] ?? '')];
    }
}

$export = (string) ($_GET['export'] ?? '');
if (in_array($export, ['excel', 'pdf'], true)) {
    if (!$isExamLocked) {
        exams_set_flash('warning', 'Phải khoá kỳ thi trước khi in danh sách');
        header('Location: ' . BASE_URL . '/modules/exams/print_rooms.php');
        exit;
    }

    if ($export === 'excel') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="room_list_exam_' . $examId . '.csv"');
        $out = fopen('php://output', 'wb');
        fputcsv($out, $examMode === 2 ? ['Mon', 'Khoi', 'Phong', 'SBD', 'Ho ten', 'Lop', 'Ngay sinh', 'Mon thi'] : ['Mon', 'Khoi', 'Phong', 'SBD', 'Ho ten', 'Lop', 'Ngay sinh']);
        foreach ($roomGroups as $room) {
            foreach ($room['students'] as $st) {
                if ($examMode === 2) { fputcsv($out, [$room['ten_mon'], $room['khoi'], $room['ten_phong'], $st['sbd'], $st['hoten'], $st['lop'], $st['ngaysinh'], $st['mon_thi'] ?? '']); } else { fputcsv($out, [$room['ten_mon'], $room['khoi'], $room['ten_phong'], $st['sbd'], $st['hoten'], $st['lop'], $st['ngaysinh']]); }
            }
        }
        fclose($out);
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo '<h3>Danh sách phòng thi</h3>';
    foreach ($roomGroups as $room) {
        echo '<h4>Môn: ' . htmlspecialchars($room['ten_mon']) . ' | Phòng: ' . htmlspecialchars($room['ten_phong']) . '</h4>';
        echo '<table border="1" cellspacing="0" cellpadding="4"><tr><th>#</th><th>SBD</th><th>Họ tên</th><th>Lớp</th><th>Ngày sinh</th>' . ($examMode === 2 ? '<th>Môn thi</th>' : '') . '</tr>';
        foreach ($room['students'] as $i => $st) {
            echo '<tr><td>' . ($i + 1) . '</td><td>' . htmlspecialchars($st['sbd']) . '</td><td>' . htmlspecialchars($st['hoten']) . '</td><td>' . htmlspecialchars($st['lop']) . '</td><td>' . htmlspecialchars($st['ngaysinh']) . '</td>' . ($examMode === 2 ? '<td>' . htmlspecialchars((string) ($st['mon_thi'] ?? '')) . '</td>' : '') . '</tr>';
        }
        echo '</table><br>';
    }
    exit;
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
<?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
<div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm"><div class="card-header bg-primary text-white d-flex justify-content-between align-items-center"><strong>Bước 6: In danh sách phòng thi</strong>
<div>
<?php if ($isExamLocked): ?>
<a class="btn btn-light btn-sm" href="?export=excel">Export Excel</a>
<a class="btn btn-light btn-sm" href="?export=pdf" target="_blank">Export PDF</a>
<?php if ($examMode === 2): ?><a class="btn btn-light btn-sm" href="<?= BASE_URL ?>/modules/exams/print_subject_list.php">DS theo môn</a><?php endif; ?>
<button class="btn btn-light btn-sm" onclick="window.print()">In</button>
<?php else: ?>
<span class="badge bg-warning text-dark">Phải khoá kỳ thi trước khi in danh sách</span>
<?php endif; ?>
</div></div>
<div class="card-body">
<?= exams_display_flash(); ?>
<?php if (!$isExamLocked): ?><div class="alert alert-warning">Phải khoá kỳ thi trước khi in danh sách</div><?php endif; ?>
<div class="mb-2">
<form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="lock_exam"><button class="btn btn-success btn-sm" <?= $isExamLocked ? 'disabled' : '' ?>>Khoá kỳ thi</button></form>
<?php if ($role === 'admin'): ?><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="unlock_exam"><button class="btn btn-outline-danger btn-sm" <?= !$isExamLocked ? 'disabled' : '' ?>>Mở khoá kỳ thi</button></form><?php endif; ?>
</div>
<?php if (empty($roomGroups)): ?><div class="alert alert-warning">Chưa có dữ liệu phòng thi.</div><?php endif; ?>
<?php foreach ($roomGroups as $room): ?>
<div class="border rounded p-3 mb-3"><h5>Phòng: <?= htmlspecialchars($room['ten_phong'], ENT_QUOTES, 'UTF-8') ?> | Môn: <?= htmlspecialchars($room['ten_mon'], ENT_QUOTES, 'UTF-8') ?> | Khối: <?= htmlspecialchars($room['khoi'], ENT_QUOTES, 'UTF-8') ?></h5>
<table class="table table-sm table-bordered"><thead><tr><th>#</th><th>SBD</th><th>Họ tên</th><th>Lớp</th><th>Ngày sinh</th><?php if ($examMode === 2): ?><th>Môn thi</th><?php endif; ?></tr></thead><tbody>
<?php foreach($room['students'] as $i=>$st): ?><tr><td><?= $i+1 ?></td><td><?= htmlspecialchars($st['sbd'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($st['hoten'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($st['lop'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($st['ngaysinh'], ENT_QUOTES, 'UTF-8') ?></td><?php if ($examMode === 2): ?><td><?= htmlspecialchars((string)($st['mon_thi'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?></tr><?php endforeach; ?>
</tbody></table></div>
<?php endforeach; ?>
</div></div></div></div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
