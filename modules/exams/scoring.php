<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_role(['admin', 'organizer', 'scorer']);

$csrf = exams_get_csrf_token();
$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$role = (string) ($_SESSION['user']['role'] ?? '');
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$errors = [];
$examModeStmt = $pdo->prepare('SELECT exam_mode FROM exams WHERE id = :id LIMIT 1');
$examModeStmt->execute([':id' => $examId]);
$examMode = (int) ($examModeStmt->fetchColumn() ?: 1);
if (!in_array($examMode, [1, 2], true)) { $examMode = 1; }
$pdo->exec('CREATE TABLE IF NOT EXISTS exam_scores (id INTEGER PRIMARY KEY AUTOINCREMENT, exam_id INTEGER NOT NULL, student_id INTEGER NOT NULL, subject_id INTEGER NOT NULL, score REAL, updated_at TEXT, UNIQUE(exam_id, student_id, subject_id))');

if ($examMode === 2) {
    $seed = $pdo->prepare('INSERT INTO scores(exam_id, student_id, subject_id, diem, scorer_id, updated_at)
        SELECT es.exam_id, es.student_id, es.subject_id, NULL, NULL, NULL
        FROM exam_students es
        INNER JOIN exam_student_subjects ess ON ess.exam_id = es.exam_id AND ess.student_id = es.student_id AND ess.subject_id = es.subject_id
        WHERE es.exam_id = :exam_id AND es.subject_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM scores sc WHERE sc.exam_id = es.exam_id AND sc.student_id = es.student_id AND sc.subject_id = es.subject_id)');
    $seed->execute([':exam_id' => $examId]);
} else {
    $seed = $pdo->prepare('INSERT INTO scores(exam_id, student_id, subject_id, diem, scorer_id, updated_at)
        SELECT es.exam_id, es.student_id, es.subject_id, NULL, NULL, NULL
        FROM exam_students es
        WHERE es.exam_id = :exam_id AND es.subject_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM scores sc WHERE sc.exam_id = es.exam_id AND sc.student_id = es.student_id AND sc.subject_id = es.subject_id)');
    $seed->execute([':exam_id' => $examId]);
}

$subjectsStmt = $pdo->prepare('SELECT DISTINCT s.id, s.ten_mon
    FROM exam_students es
    INNER JOIN subjects s ON s.id = es.subject_id
    WHERE es.exam_id = :exam_id AND es.subject_id IS NOT NULL
    ORDER BY s.ten_mon');
$subjectsStmt->execute([':exam_id' => $examId]);
$subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);

$subjectId = max(0, (int) ($_GET['subject_id'] ?? $_POST['subject_id'] ?? ($subjects[0]['id'] ?? 0)));
$roomsStmt = $pdo->prepare('SELECT id, ten_phong, khoi FROM rooms WHERE exam_id = :exam_id AND subject_id = :subject_id ORDER BY ten_phong');
$roomsStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId]);
$rooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);
$roomId = max(0, (int) ($_GET['room_id'] ?? $_POST['room_id'] ?? ($rooms[0]['id'] ?? 0)));

$roomKhoi = '';
foreach ($rooms as $r) {
    if ((int) $r['id'] === $roomId) {
        $roomKhoi = (string) $r['khoi'];
        break;
    }
}

$cfgStmt = $pdo->prepare('SELECT component_count, tong_diem, diem_tu_luan, diem_trac_nghiem, diem_noi
    FROM exam_subject_config
    WHERE exam_id = :exam_id AND subject_id = :subject_id
    ORDER BY id DESC LIMIT 1');
$cfgStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId]);
$cfg = $cfgStmt->fetch(PDO::FETCH_ASSOC) ?: ['component_count' => 1, 'tong_diem' => 10, 'diem_tu_luan' => 10, 'diem_trac_nghiem' => 0, 'diem_noi' => 0];
$componentCount = max(1, min(3, (int) ($cfg['component_count'] ?? 1)));
$max1 = (float) ($cfg['diem_tu_luan'] ?? 10);
$max2 = (float) ($cfg['diem_trac_nghiem'] ?? 0);
$max3 = (float) ($cfg['diem_noi'] ?? 0);
$totalMax = (float) ($cfg['tong_diem'] ?? 10);

$componentLabels = ['component_1' => 'Tự luận'];
if ($componentCount >= 2) {
    $componentLabels['component_2'] = 'Trắc nghiệm';
}
if ($componentCount >= 3) {
    $componentLabels['component_3'] = 'Nói';
}

$allowedComponents = array_keys($componentLabels);
if ($role === 'scorer') {
    $aStmt = $pdo->prepare('SELECT component_name
        FROM score_assignments
        WHERE exam_id = :exam_id AND subject_id = :subject_id AND user_id = :user_id
          AND ((room_id IS NOT NULL AND room_id = :room_id) OR (room_id IS NULL AND khoi = :khoi))');
    $aStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':user_id' => $userId, ':room_id' => $roomId, ':khoi' => $roomKhoi]);
    $as = $aStmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($as)) {
        $allowedComponents = [];
    } elseif (in_array('total', $as, true)) {
        $allowedComponents = array_keys($componentLabels);
    } else {
        $allowedComponents = array_values(array_intersect(array_keys($componentLabels), $as));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }
    if (!exams_is_exam_locked($pdo, $examId)) {
        $errors[] = 'Kỳ thi chưa khoá toàn bộ, chưa thể nhập điểm.';
    }
    if ($roomId <= 0 || $subjectId <= 0) {
        $errors[] = 'Vui lòng chọn môn và phòng thi.';
    }

    $rowsPayload = $_POST['rows'] ?? [];
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $sel = $pdo->prepare('SELECT component_1, component_2, component_3 FROM scores WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id LIMIT 1');
            $up = $pdo->prepare('UPDATE scores SET component_1=:c1, component_2=:c2, component_3=:c3, total_score=:total, diem=:total, scorer_id=:scorer, updated_at=:updated WHERE id=:id AND exam_id=:exam_id AND subject_id=:subject_id');
            foreach ((array) $rowsPayload as $scoreIdRaw => $vals) {
                $scoreId = (int) $scoreIdRaw;
                $sel->execute([':id' => $scoreId, ':exam_id' => $examId, ':subject_id' => $subjectId]);
                $old = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$old) {
                    continue;
                }

                $c1 = (float) ($old['component_1'] ?? 0);
                $c2 = (float) ($old['component_2'] ?? 0);
                $c3 = (float) ($old['component_3'] ?? 0);
                if (in_array('component_1', $allowedComponents, true)) { $c1 = (float) ($vals['c1'] ?? 0); }
                if (in_array('component_2', $allowedComponents, true)) { $c2 = (float) ($vals['c2'] ?? 0); }
                if (in_array('component_3', $allowedComponents, true)) { $c3 = (float) ($vals['c3'] ?? 0); }

                if ($c1 > $max1 || ($componentCount >= 2 && $c2 > $max2) || ($componentCount >= 3 && $c3 > $max3)) {
                    throw new RuntimeException('Điểm thành phần vượt quá mức tối đa.');
                }
                $sum = $c1 + ($componentCount >= 2 ? $c2 : 0) + ($componentCount >= 3 ? $c3 : 0);
                if ($sum > $totalMax) {
                    throw new RuntimeException('Tổng điểm vượt quá tổng điểm môn.');
                }

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
                $pdo->prepare('INSERT INTO exam_scores (exam_id, student_id, subject_id, score, updated_at)
                    SELECT exam_id, student_id, subject_id, :score, :updated FROM scores WHERE id = :id
                    ON CONFLICT(exam_id, student_id, subject_id) DO UPDATE SET score = excluded.score, updated_at = excluded.updated_at')
                    ->execute([':score' => $sum, ':updated' => date('c'), ':id' => $scoreId]);
            }
            $pdo->commit();
            exams_set_flash('success', 'Đã lưu điểm.');
            header('Location: ' . BASE_URL . '/modules/exams/scoring.php?' . http_build_query(['subject_id' => $subjectId, 'room_id' => $roomId]));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

$listStmt = $pdo->prepare('SELECT sc.id, es.sbd, st.hoten, st.ngaysinh, st.lop, sc.component_1, sc.component_2, sc.component_3, sc.total_score
    FROM scores sc
    INNER JOIN exam_students es ON es.exam_id = sc.exam_id AND es.student_id = sc.student_id AND es.subject_id = sc.subject_id
    INNER JOIN students st ON st.id = sc.student_id
    WHERE sc.exam_id = :exam_id AND sc.subject_id = :subject_id AND es.room_id = :room_id
    ORDER BY es.sbd, st.hoten');
$listStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':room_id' => $roomId]);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

function fmtDob(?string $dob): string { if (!$dob) return ''; $t = strtotime($dob); return $t ? date('d/m/Y', $t) : $dob; }

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
<?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
<div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm"><div class="card-header bg-primary text-white"><strong>Nhập điểm theo phòng thi</strong></div><div class="card-body">
<?= exams_display_flash(); ?>
<?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="get" class="row g-2 mb-3">
<div class="col-md-4"><label class="form-label">Môn</label><select class="form-select" name="subject_id" onchange="this.form.submit()"><?php foreach($subjects as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $subjectId === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Phòng thi</label><select class="form-select" name="room_id" onchange="this.form.submit()"><?php foreach($rooms as $r): ?><option value="<?= (int)$r['id'] ?>" <?= $roomId === (int)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$r['ten_phong'].' - Khối '.$r['khoi'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
</form>

<form method="post" id="scoreForm">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="subject_id" value="<?= (int)$subjectId ?>">
<input type="hidden" name="room_id" value="<?= (int)$roomId ?>">
<table class="table table-sm table-bordered align-middle">
<thead>
<tr>
<th>STT</th><th>SBD</th><th>Họ tên</th><th>Ngày sinh</th><th>Lớp</th>
<?php foreach ($componentLabels as $key => $label): ?>
<th>
<div><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
<?php if (in_array($key, $allowedComponents, true)): ?><button type="button" class="btn btn-link btn-sm p-0 fill-max" data-col="<?= $key ?>" data-max="<?= $key === 'component_1' ? $max1 : ($key === 'component_2' ? $max2 : $max3) ?>">Điền tối đa</button><?php endif; ?>
</th>
<?php endforeach; ?>
<th>Tổng</th>
</tr>
</thead>
<tbody>
<?php foreach($rows as $idx => $r): ?>
<tr>
<td><?= $idx + 1 ?></td>
<td><?= htmlspecialchars((string)$r['sbd'], ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars((string)$r['hoten'], ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars(fmtDob((string)$r['ngaysinh']), ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars((string)$r['lop'], ENT_QUOTES, 'UTF-8') ?></td>
<?php foreach ($componentLabels as $key => $label):
    $name = $key === 'component_1' ? 'c1' : ($key === 'component_2' ? 'c2' : 'c3');
    $val = $r[$key] ?? '';
    $editable = in_array($key, $allowedComponents, true);
    $max = $key === 'component_1' ? $max1 : ($key === 'component_2' ? $max2 : $max3);
?>
<td><input class="form-control form-control-sm score-input" data-col="<?= $key ?>" data-max="<?= $max ?>" name="rows[<?= (int)$r['id'] ?>][<?= $name ?>]" value="<?= htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') ?>" <?= $editable ? '' : 'readonly' ?>></td>
<?php endforeach; ?>
<td><?= htmlspecialchars((string)($r['total_score'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<div class="mt-2"><button class="btn btn-success" type="submit" <?= empty($allowedComponents) ? 'disabled' : '' ?>>Lưu điểm</button></div>
</form>
</div></div></div></div>
<script>
const inputs = Array.from(document.querySelectorAll('.score-input:not([readonly])'));
function normalize(v){ const raw = String(v||'').replace(/[^0-9]/g,''); if(raw.length < 2) return v; const n = Number(raw)/100; return Number.isFinite(n) ? n.toFixed(2).replace(/\.00$/,'.0').replace(/(\.\d)0$/,'$1') : v; }
function nextInput(cur){ const idx = inputs.indexOf(cur); if(idx>=0 && idx < inputs.length-1) inputs[idx+1].focus(); }
inputs.forEach(inp=>{
  inp.addEventListener('blur',()=>{ const nv = normalize(inp.value); if(nv!==inp.value) inp.value = nv; });
  inp.addEventListener('input',()=>{ const max=parseFloat(inp.dataset.max||'0'); const v=parseFloat(inp.value||''); if(!Number.isNaN(v) && v===max) nextInput(inp); });
});
document.querySelectorAll('.fill-max').forEach(btn=>btn.addEventListener('click',()=>{
  const col=btn.dataset.col, max=btn.dataset.max;
  document.querySelectorAll('.score-input[data-col="'+col+'"]:not([readonly])').forEach(i=>{ if(String(i.value).trim()==='') i.value=max; });
}));
</script>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
