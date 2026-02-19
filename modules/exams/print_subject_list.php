<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';

$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$examModeStmt = $pdo->prepare('SELECT exam_mode FROM exams WHERE id = :id LIMIT 1');
$examModeStmt->execute([':id' => $examId]);
$examMode = (int) ($examModeStmt->fetchColumn() ?: 1);
if ($examMode !== 2) {
    exams_set_flash('warning', 'Chức năng danh sách theo môn chỉ áp dụng cho mode 2.');
    header('Location: ' . BASE_URL . '/modules/exams/print_rooms.php');
    exit;
}

$subjectStmt = $pdo->prepare('SELECT es.subject_id, s.ten_mon
    FROM exam_subjects es
    INNER JOIN subjects s ON s.id = es.subject_id
    WHERE es.exam_id = :exam_id
    ORDER BY es.sort_order ASC, s.ten_mon');
$subjectStmt->execute([':exam_id' => $examId]);
$subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);

$subjectId = max(0, (int) ($_GET['subject_id'] ?? ($subjects[0]['subject_id'] ?? 0)));
$list = [];
if ($subjectId > 0) {
    $listStmt = $pdo->prepare('SELECT st.hoten, st.lop, st.ngaysinh
        FROM exam_student_subjects ess
        INNER JOIN students st ON st.id = ess.student_id
        WHERE ess.exam_id = :exam_id AND ess.subject_id = :subject_id
        ORDER BY st.lop, st.hoten');
    $listStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId]);
    $list = $listStmt->fetchAll(PDO::FETCH_ASSOC);
}

if (($_GET['export'] ?? '') === '1') {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h3>Danh sách thí sinh theo môn</h3>';
    echo '<table border="1" cellspacing="0" cellpadding="4"><tr><th>STT</th><th>Họ tên</th><th>Lớp</th><th>Ngày sinh</th></tr>';
    foreach ($list as $i => $row) {
        $dob = (string) ($row['ngaysinh'] ?? '');
        $t = strtotime($dob);
        $dobFmt = $t ? date('d/m/Y', $t) : $dob;
        echo '<tr><td>' . ($i + 1) . '</td><td>' . htmlspecialchars((string) $row['hoten']) . '</td><td>' . htmlspecialchars((string) $row['lop']) . '</td><td>' . htmlspecialchars($dobFmt) . '</td></tr>';
    }
    echo '</table>';
    exit;
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
<?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
<div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm"><div class="card-header bg-primary text-white"><strong>Danh sách theo môn (mode 2)</strong></div><div class="card-body">
<form method="get" class="row g-2 mb-3">
<div class="col-md-6"><label class="form-label">Môn học</label><select class="form-select" name="subject_id" onchange="this.form.submit()">
<?php foreach ($subjects as $s): ?>
<option value="<?= (int) $s['subject_id'] ?>" <?= $subjectId === (int) $s['subject_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
</select></div>
<div class="col-md-3 align-self-end"><button class="btn btn-outline-primary" type="submit">Tải</button></div>
<div class="col-md-3 align-self-end"><a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/modules/exams/print_subject_list.php?<?= http_build_query(['subject_id' => $subjectId, 'export' => 1]) ?>" target="_blank">In</a></div>
</form>
<table class="table table-sm table-bordered"><thead><tr><th>STT</th><th>Họ tên</th><th>Lớp</th><th>Ngày sinh</th></tr></thead><tbody>
<?php if (empty($list)): ?><tr><td colspan="4" class="text-center">Không có dữ liệu.</td></tr><?php else: foreach($list as $i => $row): $dob=(string)($row['ngaysinh']??''); $t=strtotime($dob); ?>
<tr><td><?= $i+1 ?></td><td><?= htmlspecialchars((string)$row['hoten'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)$row['lop'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($t?date('d/m/Y',$t):$dob, ENT_QUOTES, 'UTF-8') ?></td></tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div></div></div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
