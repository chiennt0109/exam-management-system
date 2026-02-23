<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';

$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$examModeStmt = $pdo->prepare('SELECT exam_mode FROM exams WHERE id = :id LIMIT 1');
$examModeStmt->execute([':id' => $examId]);
$examMode = (int) ($examModeStmt->fetchColumn() ?: 1);
if ($examMode !== 2) {
    exams_set_flash('warning', 'Thống kê theo môn chỉ áp dụng cho mode 2.');
    header('Location: ' . BASE_URL . '/modules/exams/scoring.php');
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
$stats = ['total' => 0, 'avg_score' => null, 'passed' => 0];
if ($subjectId > 0) {
    $stmt = $pdo->prepare('SELECT
        COUNT(*) as total,
        AVG(score) as avg_score,
        SUM(CASE WHEN score >= 5 THEN 1 ELSE 0 END) as passed
        FROM exam_scores
        WHERE exam_id = :exam_id AND subject_id = :subject_id');
    $stmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats;

    $registeredStmt = $pdo->prepare('SELECT COUNT(*) FROM exam_student_subjects WHERE exam_id = :exam_id AND subject_id = :subject_id');
    $registeredStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId]);
    $stats['registered'] = (int) ($registeredStmt->fetchColumn() ?: 0);
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
<?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
<div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm"><div class="card-header bg-primary text-white"><strong>Thống kê theo môn (mode 2)</strong></div><div class="card-body">
<form method="get" class="row g-2 mb-3">
<div class="col-md-6"><label class="form-label">Môn học</label><select class="form-select" name="subject_id" onchange="this.form.submit()"><?php foreach ($subjects as $s): ?><option value="<?= (int)$s['subject_id'] ?>" <?= $subjectId === (int)$s['subject_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
<div class="col-md-3 align-self-end"><button class="btn btn-outline-primary" type="submit">Xem</button></div>
</form>
<div class="row g-3">
<div class="col-md-4"><div class="border rounded p-3"><div class="text-muted">Số thí sinh đăng ký</div><div class="h4 mb-0"><?= (int)($stats['registered'] ?? 0) ?></div></div></div>
<div class="col-md-4"><div class="border rounded p-3"><div class="text-muted">Điểm trung bình</div><div class="h4 mb-0"><?= $stats['avg_score'] !== null ? number_format((float)$stats['avg_score'], 2) : 'N/A' ?></div></div></div>
<div class="col-md-4"><div class="border rounded p-3"><div class="text-muted">Số đạt >= 5</div><div class="h4 mb-0"><?= (int)($stats['passed'] ?? 0) ?></div></div></div>
</div>
</div></div></div></div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
