<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$subs=$pdo->query('SELECT id, ten_mon FROM subjects ORDER BY ten_mon')->fetchAll(PDO::FETCH_ASSOC);
$s1=max(0,(int)($_GET['s1']??($subs[0]['id']??0)));$s2=max(0,(int)($_GET['s2']??($subs[1]['id']??0)));$s3=max(0,(int)($_GET['s3']??($subs[2]['id']??0)));
$list=[];
if($s1&&$s2&&$s3){$sql='SELECT st.hoten, st.lop, s1.score as d1, s2.score as d2, s3.score as d3, (s1.score+s2.score+s3.score) as tong FROM students st INNER JOIN exam_scores s1 ON s1.student_id=st.id AND s1.exam_id=:e AND s1.subject_id=:s1 INNER JOIN exam_scores s2 ON s2.student_id=st.id AND s2.exam_id=:e AND s2.subject_id=:s2 INNER JOIN exam_scores s3 ON s3.student_id=st.id AND s3.exam_id=:e AND s3.subject_id=:s3 ORDER BY tong DESC';$st=$pdo->prepare($sql);$st->execute([':e'=>$examId,':s1'=>$s1,':s2'=>$s2,':s3'=>$s3]);$list=$st->fetchAll(PDO::FETCH_ASSOC);} 
$name=[];foreach($subs as $s)$name[(int)$s['id']]=(string)$s['ten_mon'];
require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);"><?php require_once BASE_PATH . '/layout/sidebar.php'; ?><div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm"><div class="card-header bg-primary text-white"><strong>Thống kê theo tổ hợp 3 môn</strong></div><div class="card-body">
<form method="get" class="row g-2 mb-3"><?php foreach ([1,2,3] as $idx): $v=${'s'.$idx}; ?><div class="col-md-3"><select class="form-select" name="s<?= $idx ?>"><?php foreach($subs as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $v===(int)$s['id']?'selected':'' ?>><?= htmlspecialchars((string)$s['ten_mon'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?></select></div><?php endforeach; ?><div class="col-md-2"><button class="btn btn-primary w-100">Xem</button></div></form>
<table class="table table-bordered table-sm"><thead><tr><th>#</th><th>Họ tên</th><th>Lớp</th><th><?= htmlspecialchars($name[$s1]??'M1',ENT_QUOTES,'UTF-8') ?></th><th><?= htmlspecialchars($name[$s2]??'M2',ENT_QUOTES,'UTF-8') ?></th><th><?= htmlspecialchars($name[$s3]??'M3',ENT_QUOTES,'UTF-8') ?></th><th>Tổng</th></tr></thead><tbody>
<?php foreach($list as $i=>$r): ?><tr><td><?= $i+1 ?></td><td><?= htmlspecialchars((string)$r['hoten'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)$r['lop'],ENT_QUOTES,'UTF-8') ?></td><td><?= number_format((float)$r['d1'],2) ?></td><td><?= number_format((float)$r['d2'],2) ?></td><td><?= number_format((float)$r['d3'],2) ?></td><td><strong><?= number_format((float)$r['tong'],2) ?></strong></td></tr><?php endforeach; ?>
<?php if(empty($list)): ?><tr><td colspan="7" class="text-center">Không có dữ liệu đủ 3 môn.</td></tr><?php endif; ?>
</tbody></table>
</div></div></div></div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
