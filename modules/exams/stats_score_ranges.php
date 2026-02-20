<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');

$subjects = $pdo->prepare('SELECT id, ten_mon FROM subjects ORDER BY ten_mon');
$subjects->execute();
$subjectMap = [];
foreach ($subjects->fetchAll(PDO::FETCH_ASSOC) as $s) $subjectMap[(int)$s['id']] = (string)$s['ten_mon'];

$stmt = $pdo->prepare('SELECT subject_id, score FROM exam_scores WHERE exam_id = :eid AND score IS NOT NULL');
$stmt->execute([':eid'=>$examId]);
$bins = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $sid = (int)$r['subject_id']; $sc=(float)$r['score'];
    $bins[$sid] ??= ['total'=>0,'r'=>array_fill(0,11,0)];
    $bins[$sid]['total']++;
    if ($sc <= 1) $bins[$sid]['r'][0]++; elseif ($sc <= 2) $bins[$sid]['r'][1]++; elseif ($sc <= 3) $bins[$sid]['r'][2]++; elseif ($sc <= 4) $bins[$sid]['r'][3]++; elseif ($sc < 5) $bins[$sid]['r'][4]++; elseif ($sc < 6) $bins[$sid]['r'][5]++; elseif ($sc < 7) $bins[$sid]['r'][6]++; elseif ($sc < 8) $bins[$sid]['r'][7]++; elseif ($sc < 9) $bins[$sid]['r'][8]++; elseif ($sc < 10) $bins[$sid]['r'][9]++; else $bins[$sid]['r'][10]++;
}
$pc = static fn(int $c,int $t): string => $t>0?number_format($c*100/$t,1).'%':'0.0%';
require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);"><?php require_once BASE_PATH . '/layout/sidebar.php'; ?><div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm"><div class="card-header bg-primary text-white"><strong>Thống kê tỷ lệ điểm theo môn</strong></div><div class="card-body table-responsive">
<table class="table table-bordered table-sm"><thead><tr><th>Môn</th><th>Tổng</th><th>0-1</th><th>1-2</th><th>2-3</th><th>3-4</th><th>4-5</th><th>5-6</th><th>6-7</th><th>7-8</th><th>8-9</th><th>9-10</th><th>=10</th></tr></thead><tbody>
<?php foreach ($bins as $sid=>$row): ?><tr><td><?= htmlspecialchars($subjectMap[$sid] ?? ('Môn '.$sid), ENT_QUOTES,'UTF-8') ?></td><td><?= $row['total'] ?></td><?php foreach($row['r'] as $i=>$c): ?><td><?= $pc($c,$row['total']) ?> (<?= $c ?>)</td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if (empty($bins)): ?><tr><td colspan="13" class="text-center">Chưa có dữ liệu điểm.</td></tr><?php endif; ?>
</tbody></table>
</div></div></div></div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
