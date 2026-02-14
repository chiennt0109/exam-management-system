<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_role(['admin', 'organizer']);

$csrf = exams_get_csrf_token();
$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$errors = [];
$lockState = exams_get_lock_state($pdo, $examId);
$isExamLocked = ((int) ($lockState['exam_locked'] ?? 0)) === 1;

$subjectCfgStmt = $pdo->prepare('SELECT c.subject_id, MAX(c.component_count) AS component_count, s.ten_mon
    FROM exam_subject_config c
    INNER JOIN subjects s ON s.id = c.subject_id
    WHERE c.exam_id = :exam_id
    GROUP BY c.subject_id, s.ten_mon
    ORDER BY s.ten_mon');
$subjectCfgStmt->execute([':exam_id' => $examId]);
$subjectCfgs = $subjectCfgStmt->fetchAll(PDO::FETCH_ASSOC);
$subjects = array_map(static fn(array $r): array => ['id' => (int)$r['subject_id'], 'ten_mon' => (string)$r['ten_mon']], $subjectCfgs);

$componentMap = [];
foreach ($subjectCfgs as $cfg) {
    $sid = (int) $cfg['subject_id'];
    $count = max(1, min(3, (int) ($cfg['component_count'] ?? 1)));
    $labels = ['component_1' => 'Tự luận'];
    if ($count >= 2) {
        $labels['component_2'] = 'Trắc nghiệm';
    }
    if ($count >= 3) {
        $labels['component_3'] = 'Nói';
    }
    $labels['total'] = 'Tổng';
    $componentMap[$sid] = $labels;
}

$allUsersStmt = $pdo->query("SELECT id, username, role FROM users WHERE active = 1 ORDER BY username");
$allUsers = $allUsersStmt ? $allUsersStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$scoreUsers = array_values(array_filter($allUsers, static function (array $user): bool {
    return normalize_role((string) ($user['role'] ?? '')) === 'scorer';
}));

$roomsStmt = $pdo->prepare('SELECT r.id, r.ten_phong, r.khoi, r.subject_id, s.ten_mon
    FROM rooms r
    INNER JOIN subjects s ON s.id = r.subject_id
    WHERE r.exam_id = :exam_id
    ORDER BY s.ten_mon, r.ten_phong');
$roomsStmt->execute([':exam_id' => $examId]);
$rooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }

    try {
        exams_assert_exam_locked_for_scoring($pdo, $examId);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    if (empty($errors)) {
        $action = (string) ($_POST['action'] ?? 'create');
        try {
            $pdo->beginTransaction();
            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $pdo->prepare('DELETE FROM score_assignments WHERE id = :id AND exam_id = :exam_id')->execute([':id' => $id, ':exam_id' => $examId]);
            } else {
                $subjectId = (int) ($_POST['subject_id'] ?? 0);
                $mode = (string) ($_POST['assign_mode'] ?? 'subject_grade');
                $componentNames = array_values(array_unique(array_map('strval', (array)($_POST['component_name'] ?? ['total']))));
                $khoi = trim((string) ($_POST['khoi'] ?? ''));
                $userId = (int) ($_POST['user_id'] ?? 0);

                if (!isset($componentMap[$subjectId])) {
                    throw new RuntimeException('Môn không thuộc kỳ thi hiện tại.');
                }
                $componentNames = array_values(array_filter($componentNames, static fn(string $v): bool => isset($componentMap[$subjectId][$v])));
                if (empty($componentNames)) {
                    throw new RuntimeException('Vui lòng chọn ít nhất 1 thành phần điểm hợp lệ.');
                }
                if ($userId <= 0) {
                    throw new RuntimeException('Người nhập điểm là bắt buộc.');
                }

                if ($mode === 'subject_grade') {
                    if ($khoi === '') {
                        throw new RuntimeException('Vui lòng nhập khối.');
                    }
                    $khoiCheck = $pdo->prepare('SELECT 1 FROM exam_students WHERE exam_id = :exam_id AND khoi = :khoi LIMIT 1');
                    $khoiCheck->execute([':exam_id' => $examId, ':khoi' => $khoi]);
                    if (!$khoiCheck->fetchColumn()) {
                        throw new RuntimeException('Khối ' . $khoi . ' không có trong kỳ thi.');
                    }
                    foreach ($componentNames as $componentName) {
                        $find = $pdo->prepare('SELECT id FROM score_assignments WHERE exam_id=:exam_id AND subject_id=:subject_id AND khoi=:khoi AND component_name=:component_name AND room_id IS NULL LIMIT 1');
                        $find->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi, ':component_name' => $componentName]);
                        $id = (int) ($find->fetchColumn() ?: 0);
                        if ($id > 0) {
                            $pdo->prepare('UPDATE score_assignments SET user_id = :user_id WHERE id = :id')->execute([':user_id' => $userId, ':id' => $id]);
                        } else {
                            $pdo->prepare('INSERT INTO score_assignments(exam_id, subject_id, khoi, room_id, user_id, component_name) VALUES(:exam_id,:subject_id,:khoi,NULL,:user_id,:component_name)')
                                ->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi, ':user_id' => $userId, ':component_name' => $componentName]);
                        }
                    }
                } else {
                    $roomIds = array_values(array_unique(array_map('intval', (array) ($_POST['room_ids'] ?? []))));
                    $roomIds = array_values(array_filter($roomIds, static fn(int $v): bool => $v > 0));
                    if (empty($roomIds)) {
                        throw new RuntimeException('Vui lòng chọn ít nhất 1 phòng.');
                    }

                    $roomCheck = $pdo->prepare('SELECT 1 FROM rooms WHERE id = :id AND exam_id = :exam_id AND subject_id = :subject_id LIMIT 1');
                    foreach ($componentNames as $componentName) {
                    $find = $pdo->prepare('SELECT id FROM score_assignments WHERE exam_id=:exam_id AND subject_id=:subject_id AND room_id=:room_id AND component_name=:component_name LIMIT 1');
                    foreach ($roomIds as $roomId) {
                        $roomCheck->execute([':id' => $roomId, ':exam_id' => $examId, ':subject_id' => $subjectId]);
                        if (!$roomCheck->fetchColumn()) {
                            throw new RuntimeException('Có phòng không thuộc môn đã chọn.');
                        }
                        $find->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':room_id' => $roomId, ':component_name' => $componentName]);
                        $id = (int) ($find->fetchColumn() ?: 0);
                        if ($id > 0) {
                            $pdo->prepare('UPDATE score_assignments SET user_id = :user_id WHERE id = :id')->execute([':user_id' => $userId, ':id' => $id]);
                        } else {
                            $pdo->prepare('INSERT INTO score_assignments(exam_id, subject_id, khoi, room_id, user_id, component_name) VALUES(:exam_id,:subject_id,NULL,:room_id,:user_id,:component_name)')
                                ->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':room_id' => $roomId, ':user_id' => $userId, ':component_name' => $componentName]);
                        }
                    }
                }
                }
            }
            $pdo->commit();
            exams_set_flash('success', 'Cập nhật phân công thành công.');
            header('Location: ' . BASE_URL . '/modules/exams/scoring_assignment.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

$assignStmt = $pdo->prepare('SELECT sa.*, u.username, sub.ten_mon, r.ten_phong
    FROM score_assignments sa
    INNER JOIN users u ON u.id = sa.user_id
    INNER JOIN subjects sub ON sub.id = sa.subject_id
    LEFT JOIN rooms r ON r.id = sa.room_id
    WHERE sa.exam_id = :exam_id
    ORDER BY sub.ten_mon, sa.component_name, sa.khoi, r.ten_phong');
$assignStmt->execute([':exam_id' => $examId]);
$assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

$unassignedBySubject = [];
foreach ($subjects as $sub) {
    $sid = (int)$sub['id'];
    $roomTotalStmt = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE exam_id = :exam_id AND subject_id = :sid');
    $roomTotalStmt->execute([':exam_id'=>$examId, ':sid'=>$sid]);
    $totalRooms = (int)$roomTotalStmt->fetchColumn();

    $assignedRoomStmt = $pdo->prepare('SELECT COUNT(DISTINCT room_id) FROM score_assignments WHERE exam_id = :exam_id AND subject_id = :sid AND room_id IS NOT NULL');
    $assignedRoomStmt->execute([':exam_id'=>$examId, ':sid'=>$sid]);
    $assignedRooms = (int)$assignedRoomStmt->fetchColumn();

    if ($totalRooms > $assignedRooms) {
        $unassignedBySubject[] = ['ten_mon' => $sub['ten_mon'], 'missing_rooms' => $totalRooms - $assignedRooms];
    }
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
<?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
<div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm mb-3"><div class="card-header bg-primary text-white"><strong>Phân công nhập điểm</strong></div><div class="card-body">
<?= exams_display_flash(); ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!$isExamLocked): ?><div class="alert alert-warning">Phải khoá kỳ thi trước khi phân công nhập điểm.</div><?php endif; ?>
<form method="post" class="row g-2" id="assignForm">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<div class="col-md-3"><label class="form-label">Môn</label><select name="subject_id" id="subjectSelect" class="form-select" required><?php foreach($subjects as $sub): ?><option value="<?= (int)$sub['id'] ?>"><?= htmlspecialchars((string)$sub['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Thành phần điểm</label><div id="componentBoxes" class="border rounded p-2 bg-light"></div></div>
<div class="col-md-3"><label class="form-label">Người nhập điểm</label><select name="user_id" class="form-select" required><?php foreach($scoreUsers as $u): ?><option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Chế độ</label><div>
<div class="form-check"><input checked class="form-check-input" type="radio" name="assign_mode" value="subject_grade" id="m1"><label class="form-check-label" for="m1">Theo môn + khối</label></div>
<div class="form-check"><input class="form-check-input" type="radio" name="assign_mode" value="subject_room" id="m2"><label class="form-check-label" for="m2">Theo phòng + môn</label></div>
</div></div>
<div class="col-md-3" id="scopeKhoi"><label class="form-label">Khối</label><input name="khoi" id="khoiInput" class="form-control" placeholder="VD: 12"></div>
<div class="col-12 d-none" id="scopeRoom">
<label class="form-label">Phòng + môn</label>
<div class="row g-2">
<div class="col-md-5"><div class="small text-muted">Danh sách phòng theo môn</div><select id="roomsAvailable" class="form-select" size="8" multiple></select></div>
<div class="col-md-2 d-flex flex-column justify-content-center gap-2"><button type="button" id="btnAddRooms" class="btn btn-outline-primary btn-sm">&gt;&gt;</button><button type="button" id="btnRemoveRooms" class="btn btn-outline-secondary btn-sm">&lt;&lt;</button></div>
<div class="col-md-5"><div class="small text-muted">Danh sách đã chọn</div><select name="room_ids[]" id="roomSelected" class="form-select" size="8" multiple></select></div>
</div>
</div>
<div class="col-12"><button class="btn btn-success" type="submit" <?= !$isExamLocked ? 'disabled' : '' ?>>Lưu phân công</button></div>
</form></div></div>

<?php if (!empty($unassignedBySubject)): ?><div class="alert alert-info"><strong>Môn/phòng chưa được phân công đủ:</strong><ul class="mb-0"><?php foreach($unassignedBySubject as $u): ?><li><?= htmlspecialchars((string)$u['ten_mon'], ENT_QUOTES, 'UTF-8') ?>: còn thiếu <?= (int)$u['missing_rooms'] ?> phòng</li><?php endforeach; ?></ul></div><?php endif; ?><div class="card shadow-sm"><div class="card-header">Danh sách phân công</div><div class="card-body"><table class="table table-sm table-bordered"><thead><tr><th>Môn</th><th>Thành phần</th><th>Phạm vi</th><th>Người nhập điểm</th><th></th></tr></thead><tbody><?php foreach($assignments as $a): ?><tr><td><?= htmlspecialchars((string)$a['ten_mon'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)($a['component_name'] ?? 'total'), ENT_QUOTES, 'UTF-8') ?></td><td><?= $a['room_id'] ? ('Phòng '.htmlspecialchars((string)$a['ten_phong'], ENT_QUOTES, 'UTF-8')) : ('Khối '.htmlspecialchars((string)$a['khoi'], ENT_QUOTES, 'UTF-8')) ?></td><td><?= htmlspecialchars((string)$a['username'], ENT_QUOTES, 'UTF-8') ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-outline-danger">Xoá</button></form></td></tr><?php endforeach; ?></tbody></table></div></div>
</div></div>
<script>
const roomData = <?= json_encode(array_map(static fn($r) => ['id' => (int)$r['id'], 'subject_id' => (int)$r['subject_id'], 'label' => (string)($r['ten_phong'].' (Khối '.$r['khoi'].')')], $rooms), JSON_UNESCAPED_UNICODE) ?>;
const componentMap = <?= json_encode($componentMap, JSON_UNESCAPED_UNICODE) ?>;
const modeRadios = document.querySelectorAll('input[name="assign_mode"]');
const scopeKhoi = document.getElementById('scopeKhoi');
const scopeRoom = document.getElementById('scopeRoom');
const khoiInput = document.getElementById('khoiInput');
const subjectSelect = document.getElementById('subjectSelect');
const componentBoxes = document.getElementById('componentBoxes');
const available = document.getElementById('roomsAvailable');
const selected = document.getElementById('roomSelected');
const addBtn = document.getElementById('btnAddRooms');
const removeBtn = document.getElementById('btnRemoveRooms');

function currentMode(){ return document.querySelector('input[name="assign_mode"]:checked')?.value || 'subject_grade'; }
function syncMode(){
  const isRoom = currentMode() === 'subject_room';
  scopeRoom.classList.toggle('d-none', !isRoom);
  scopeKhoi.classList.toggle('d-none', isRoom);
  khoiInput.required = !isRoom;
}
function refreshComponents(){
  const sid = subjectSelect.value;
  const cmp = componentMap[sid] || {'total':'Tổng'};
  componentBoxes.innerHTML = '';
  Object.entries(cmp).forEach(([k,v], idx)=>{
    const id = 'cmp_'+k;
    const div = document.createElement('div');
    div.className = 'form-check';
    div.innerHTML = `<input class="form-check-input" type="checkbox" name="component_name[]" value="${k}" id="${id}" ${idx===0?'checked':''}><label class="form-check-label" for="${id}">${v}</label>`;
    componentBoxes.appendChild(div);
  });
}
function renderAvailable(){
  const sid = Number(subjectSelect.value || 0);
  const selectedIds = new Set(Array.from(selected.options).map(o => Number(o.value)));
  available.innerHTML = '';
  roomData.filter(r => r.subject_id === sid && !selectedIds.has(r.id)).forEach(r => {
    const opt = document.createElement('option');
    opt.value = String(r.id); opt.textContent = r.label;
    available.appendChild(opt);
  });
}
function moveSelected(from, to){ Array.from(from.selectedOptions).forEach(opt => to.appendChild(opt)); }
function markSelectedForSubmit(){ Array.from(selected.options).forEach(o => o.selected = true); }

addBtn?.addEventListener('click', ()=>moveSelected(available, selected));
removeBtn?.addEventListener('click', ()=>moveSelected(selected, available));
available?.addEventListener('dblclick', ()=>moveSelected(available, selected));
selected?.addEventListener('dblclick', ()=>moveSelected(selected, available));
subjectSelect?.addEventListener('change', ()=>{ selected.innerHTML=''; refreshComponents(); renderAvailable(); });
modeRadios.forEach(r=>r.addEventListener('change', syncMode));
document.getElementById('assignForm')?.addEventListener('submit', markSelectedForSubmit);

syncMode();
refreshComponents();
renderAvailable();
</script>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
