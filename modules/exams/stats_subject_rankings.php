<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$subjectId = max(0, (int)($_GET['subject_id'] ?? 0));
$subStmt = $pdo->prepare('SELECT id, ten_mon FROM subjects ORDER BY ten_mon');$subStmt->execute();$subs=$subStmt->fetchAll(PDO::FETCH_ASSOC);
if ($subjectId<=0 && !empty($subs)) $subjectId=(int)$subs[0]['id'];
$list=[];
if ($subjectId>0){$st=$pdo->prepare('SELECT st.hoten, st.lop, es.score FROM exam_scores es INNER JOIN students st ON st.id=es.student_id WHERE es.exam_id=:e AND es.subject_id=:s ORDER BY es.score DESC, st.hoten');$st->execute([':e'=>$examId,':s'=>$subjectId]);$list=$st->fetchAll(PDO::FETCH_ASSOC);} 
require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);"><?php require_once BASE_PATH . '/layout/sidebar.php'; ?><div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm"><div class="card-header bg-primary text-white"><strong>Bảng điểm xếp hạng theo môn</strong></div><div class="card-body">
<form method="get" class="row g-2 mb-3"><div class="col-md-4"><select class="form-select" name="subject_id"><?php foreach($subs as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $subjectId===(int)$s['id']?'selected':'' ?>><?= htmlspecialchars((string)$s['ten_mon'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-primary w-100">Xem</button></div></form>
<table class="table table-bordered table-sm"><thead><tr><th>Hạng</th><th>Họ tên</th><th>Lớp</th><th>Điểm</th></tr></thead><tbody>
<?php foreach($list as $i=>$r): ?><tr><td><?= $i+1 ?></td><td><?= htmlspecialchars((string)$r['hoten'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)$r['lop'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars(number_format((float)$r['score'],2),ENT_QUOTES,'UTF-8') ?></td></tr><?php endforeach; ?>
<?php if (empty($list)): ?><tr><td colspan="4" class="text-center">Không có dữ liệu.</td></tr><?php endif; ?>
</tbody></table>
</div></div></div></div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
