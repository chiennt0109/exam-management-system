<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_role(['admin', 'exam_manager', 'score_entry']);

$csrf = exams_get_csrf_token();
$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$role = (string) ($_SESSION['user']['role'] ?? '');
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$errors = [];


// Ensure score rows exist for all assigned exam students by subject
$seed = $pdo->prepare('INSERT INTO scores(exam_id, student_id, subject_id, diem, scorer_id, updated_at)
    SELECT es.exam_id, es.student_id, es.subject_id, NULL, NULL, NULL
    FROM exam_students es
    WHERE es.exam_id = :exam_id AND es.subject_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM scores sc WHERE sc.exam_id = es.exam_id AND sc.student_id = es.student_id AND sc.subject_id = es.subject_id)');
$seed->execute([':exam_id' => $examId]);

if (!exams_is_exam_locked($pdo, $examId)) {
    exams_set_flash('warning', 'Phải khoá kỳ thi trước khi nhập điểm.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }
    if (!exams_is_exam_locked($pdo, $examId)) {
        $errors[] = 'Kỳ thi chưa khoá toàn bộ, chưa thể nhập điểm.';
    }

    $scoreId = (int) ($_POST['score_id'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $componentCount = max(1, min(3, (int) ($_POST['component_count'] ?? 1)));
    $totalMax = (float) ($_POST['total_max'] ?? 10);
    $max1 = (float) ($_POST['max1'] ?? 10);
    $max2 = (float) ($_POST['max2'] ?? 0);
    $max3 = (float) ($_POST['max3'] ?? 0);
    $c1 = (float) ($_POST['component_1'] ?? 0);
    $c2 = (float) ($_POST['component_2'] ?? 0);
    $c3 = (float) ($_POST['component_3'] ?? 0);

    if ($c1 > $max1 || ($componentCount >= 2 && $c2 > $max2) || ($componentCount >= 3 && $c3 > $max3)) {
        $errors[] = 'Điểm thành phần vượt quá điểm tối đa cho phép.';
    }
    $sum = $c1 + ($componentCount >= 2 ? $c2 : 0) + ($componentCount >= 3 ? $c3 : 0);
    if ($sum > $totalMax) {
        $errors[] = 'Tổng điểm vượt quá tổng điểm môn.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $up = $pdo->prepare('UPDATE scores SET component_1 = :c1, component_2 = :c2, component_3 = :c3, total_score = :total, diem = :total, scorer_id = :scorer, updated_at = :updated WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id');
            $up->execute([
                ':c1' => $c1,
                ':c2' => $componentCount >= 2 ? $c2 : null,
                ':c3' => $componentCount >= 3 ? $c3 : null,
                ':total' => $sum,
                ':scorer' => $userId,
                ':updated' => date('c'),
                ':id' => $scoreId,
                ':exam_id' => $examId,
                ':subject_id' => $subjectId,
            ]);
            $pdo->commit();
            exams_set_flash('success', 'Đã lưu điểm.');
            header('Location: ' . BASE_URL . '/modules/exams/scoring.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Không thể lưu điểm.';
        }
    }
}

$scopeWhere = '';
$params = [':exam_id' => $examId];
if ($role === 'score_entry') {
    $scopeWhere = ' AND EXISTS (SELECT 1 FROM score_assignments sa WHERE sa.exam_id = sc.exam_id AND sa.subject_id = sc.subject_id AND sa.user_id = :user_id)';
    $params[':user_id'] = $userId;
}

$sql = 'SELECT sc.id, sc.subject_id, sc.student_id, st.hoten, st.lop, st.ngaysinh, sub.ten_mon,
               cfg.component_count, cfg.tong_diem, cfg.diem_tu_luan, cfg.diem_trac_nghiem, cfg.diem_noi,
               sc.component_1, sc.component_2, sc.component_3, sc.total_score
        FROM scores sc
        INNER JOIN students st ON st.id = sc.student_id
        INNER JOIN subjects sub ON sub.id = sc.subject_id
        LEFT JOIN exam_students es ON es.exam_id = sc.exam_id AND es.student_id = sc.student_id AND es.subject_id = sc.subject_id
        LEFT JOIN exam_subject_config cfg ON cfg.exam_id = sc.exam_id AND cfg.subject_id = sc.subject_id AND cfg.khoi = es.khoi
        WHERE sc.exam_id = :exam_id' . $scopeWhere . '
        ORDER BY sub.ten_mon, st.lop, st.hoten';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function fmtDob(?string $dob): string { if (!$dob) return ''; $t = strtotime($dob); return $t ? date('d/m/Y',$t) : $dob; }

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
<?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
<div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm"><div class="card-header bg-primary text-white"><strong>Nhập điểm</strong></div><div class="card-body">
<?= exams_display_flash(); ?>
<?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<table class="table table-sm table-bordered align-middle"><thead><tr><th>Môn</th><th>Học sinh</th><th>Lớp</th><th>Ngày sinh</th><th>TP1</th><th>TP2</th><th>TP3</th><th>Tổng</th><th>Lưu</th></tr></thead><tbody>
<?php foreach($rows as $r): $cc=max(1,(int)($r['component_count']??1)); $m1=(float)($r['diem_tu_luan']??10); $m2=(float)($r['diem_trac_nghiem']??0); $m3=(float)($r['diem_noi']??0); $tm=(float)($r['tong_diem']??10); ?>
<tr><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="score_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="subject_id" value="<?= (int)$r['subject_id'] ?>"><input type="hidden" name="component_count" value="<?= $cc ?>"><input type="hidden" name="max1" value="<?= $m1 ?>"><input type="hidden" name="max2" value="<?= $m2 ?>"><input type="hidden" name="max3" value="<?= $m3 ?>"><input type="hidden" name="total_max" value="<?= $tm ?>">
<td><?= htmlspecialchars((string)$r['ten_mon'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)$r['hoten'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)$r['lop'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(fmtDob((string)$r['ngaysinh']), ENT_QUOTES, 'UTF-8') ?></td>
<td><button type="button" class="btn btn-link btn-sm p-0" data-fill-column="c1" data-max="<?= $m1 ?>">Điền tối đa</button><input class="form-control form-control-sm score-input" data-col="c1" data-max="<?= $m1 ?>" name="component_1" value="<?= htmlspecialchars((string)($r['component_1'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
<td><?php if($cc>=2): ?><button type="button" class="btn btn-link btn-sm p-0" data-fill-column="c2" data-max="<?= $m2 ?>">Điền tối đa</button><input class="form-control form-control-sm score-input" data-col="c2" data-max="<?= $m2 ?>" name="component_2" value="<?= htmlspecialchars((string)($r['component_2'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?></td>
<td><?php if($cc>=3): ?><button type="button" class="btn btn-link btn-sm p-0" data-fill-column="c3" data-max="<?= $m3 ?>">Điền tối đa</button><input class="form-control form-control-sm score-input" data-col="c3" data-max="<?= $m3 ?>" name="component_3" value="<?= htmlspecialchars((string)($r['component_3'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?></td>
<td><?= htmlspecialchars((string)($r['total_score'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><td><button class="btn btn-sm btn-success">Lưu</button></td></form></tr>
<?php endforeach; ?>
</tbody></table>
</div></div></div></div>
<script src="<?= BASE_URL ?>/modules/exams/assets/score_input.js"></script>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
