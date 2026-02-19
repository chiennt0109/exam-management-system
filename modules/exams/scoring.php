<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_once BASE_PATH . '/modules/exams/score_utils.php';
require_role(['admin', 'scorer']);

$csrf = exams_get_csrf_token();
$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$role = (string) ($_SESSION['user']['role'] ?? '');
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$errors = [];

$examModeStmt = $pdo->prepare('SELECT exam_mode FROM exams WHERE id = :id LIMIT 1');
$examModeStmt->execute([':id' => $examId]);
$examMode = (int) ($examModeStmt->fetchColumn() ?: 1);
if (!in_array($examMode, [1, 2], true)) {
    $examMode = 1;
}

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

$allRoomsStmt = $pdo->prepare('SELECT id, ten_phong, khoi, subject_id FROM rooms WHERE exam_id = :exam_id ORDER BY ten_phong');
$allRoomsStmt->execute([':exam_id' => $examId]);
$allRooms = $allRoomsStmt->fetchAll(PDO::FETCH_ASSOC);

$allowedRoomIdsBySubject = [];
if ($role === 'scorer') {
    $assignmentStmt = $pdo->prepare('SELECT subject_id, khoi, room_id
        FROM score_assignments
        WHERE exam_id = :exam_id AND user_id = :user_id');
    $assignmentStmt->execute([':exam_id' => $examId, ':user_id' => $userId]);
    $assignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assignments as $assignment) {
        $sid = (int) ($assignment['subject_id'] ?? 0);
        $rid = (int) ($assignment['room_id'] ?? 0);
        $khoi = trim((string) ($assignment['khoi'] ?? ''));
        if ($sid <= 0) {
            continue;
        }
        $allowedRoomIdsBySubject[$sid] = $allowedRoomIdsBySubject[$sid] ?? [];

        if ($rid > 0) {
            $allowedRoomIdsBySubject[$sid][$rid] = true;
            continue;
        }

        if ($khoi === '') {
            continue;
        }

        foreach ($allRooms as $room) {
            if ((int) ($room['subject_id'] ?? 0) === $sid && (string) ($room['khoi'] ?? '') === $khoi) {
                $allowedRoomIdsBySubject[$sid][(int) ($room['id'] ?? 0)] = true;
            }
        }
    }

    $subjects = array_values(array_filter($subjects, static function (array $sub) use ($allowedRoomIdsBySubject): bool {
        $sid = (int) ($sub['id'] ?? 0);
        return $sid > 0 && !empty($allowedRoomIdsBySubject[$sid]);
    }));
}

$subjectId = max(0, (int) ($_GET['subject_id'] ?? ($subjects[0]['id'] ?? 0)));
if ($role === 'scorer' && ($subjectId <= 0 || empty($allowedRoomIdsBySubject[$subjectId] ?? []))) {
    $subjectId = (int) ($subjects[0]['id'] ?? 0);
}

$rooms = array_values(array_filter($allRooms, static function (array $room) use ($subjectId, $role, $allowedRoomIdsBySubject): bool {
    $rid = (int) ($room['id'] ?? 0);
    $sid = (int) ($room['subject_id'] ?? 0);
    if ($sid !== $subjectId) {
        return false;
    }
    if ($role !== 'scorer') {
        return true;
    }
    return !empty($allowedRoomIdsBySubject[$sid][$rid]);
}));
$roomId = max(0, (int) ($_GET['room_id'] ?? ($rooms[0]['id'] ?? 0)));
if (!in_array($roomId, array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $rooms), true)) {
    $roomId = (int) ($rooms[0]['id'] ?? 0);
}

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
    } elseif (!in_array('total', $as, true)) {
        $allowedComponents = array_values(array_intersect(array_keys($componentLabels), $as));
    }
}

$displayComponentLabels = $componentLabels;
if ($role === 'scorer') {
    $displayComponentLabels = [];
    foreach ($componentLabels as $key => $label) {
        if (in_array($key, $allowedComponents, true)) {
            $displayComponentLabels[$key] = $label;
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

function fmtDob(?string $dob): string
{
    if (!$dob) {
        return '';
    }
    $t = strtotime($dob);
    return $t ? date('d/m/Y', $t) : $dob;
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
<?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
<div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm"><div class="card-header bg-primary text-white d-flex justify-content-between align-items-center"><strong>Nhập điểm theo phòng thi</strong><a class="btn btn-light btn-sm" href="<?= BASE_URL ?>/modules/exams/import_scores.php">Import Excel</a></div><div class="card-body">
<?= exams_display_flash(); ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($role === 'scorer' && empty($subjects)): ?><div class="alert alert-warning">Bạn chưa được phân công phạm vi nhập điểm.</div><?php endif; ?>
<?php if ($role === 'scorer' && !empty($subjects) && empty($displayComponentLabels)): ?><div class="alert alert-warning">Bạn chưa được phân công thành phần điểm cho phạm vi đang chọn.</div><?php endif; ?>
<form method="get" class="row g-2 mb-3" id="scoringFilterForm">
<div class="col-md-4"><label class="form-label">Môn</label><select class="form-select" name="subject_id" id="subjectFilterSelect"><?php foreach ($subjects as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $subjectId === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Phòng thi</label><select class="form-select" name="room_id" id="roomFilterSelect"><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $roomId === (int) $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $r['ten_phong'] . ' - Khối ' . $r['khoi'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
</form>

<form method="post" id="scoreForm" action="<?= BASE_URL ?>/modules/exams/save_score.php">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="subject_id" value="<?= (int) $subjectId ?>">
<input type="hidden" name="room_id" value="<?= (int) $roomId ?>">
<table class="table table-sm table-bordered align-middle">
<thead>
<tr>
<th>STT</th><th>SBD</th><th>Họ tên</th><th>Ngày sinh</th><th>Lớp</th>
<?php foreach ($displayComponentLabels as $key => $label): ?>
<th>
<div><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
<?php if (in_array($key, $allowedComponents, true)): ?><button type="button" class="btn btn-link btn-sm p-0 fill-max" data-fill-column="<?= $key ?>" data-max="<?= $key === 'component_1' ? $max1 : ($key === 'component_2' ? $max2 : $max3) ?>">Điền tối đa</button><?php endif; ?>
</th>
<?php endforeach; ?>
<th>Tổng</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $idx => $r): ?>
<tr>
<td><?= $idx + 1 ?></td>
<td><?= htmlspecialchars((string) $r['sbd'], ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars((string) $r['hoten'], ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars(fmtDob((string) $r['ngaysinh']), ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars((string) $r['lop'], ENT_QUOTES, 'UTF-8') ?></td>
<?php foreach ($displayComponentLabels as $key => $label):
    $name = $key === 'component_1' ? 'c1' : ($key === 'component_2' ? 'c2' : 'c3');
    $editable = in_array($key, $allowedComponents, true);
    $max = $key === 'component_1' ? $max1 : ($key === 'component_2' ? $max2 : $max3);
    $rawValue = $r[$key];
    $val = $rawValue === null ? '' : score_value_to_string((float) $rawValue);
?>
<td><input class="form-control form-control-sm score-input" data-col="<?= $key ?>" data-max="<?= $max ?>" name="rows[<?= (int) $r['id'] ?>][<?= $name ?>]" value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= $editable ? '' : 'readonly' ?>></td>
<?php endforeach; ?>
<td><?= htmlspecialchars($r['total_score'] === null ? '' : score_value_to_string((float) $r['total_score']), ENT_QUOTES, 'UTF-8') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<div class="mt-2"><button class="btn btn-success" type="submit" <?= (empty($displayComponentLabels) || $roomId <= 0) ? 'disabled' : '' ?>>Lưu điểm</button></div>
</form>
</div></div></div></div>

<script>
const roomOptionsBySubject = <?= json_encode(array_reduce($allRooms, static function (array $carry, array $room) use ($role, $allowedRoomIdsBySubject): array {
    $sid = (int) ($room['subject_id'] ?? 0);
    $rid = (int) ($room['id'] ?? 0);
    if ($sid <= 0 || $rid <= 0) {
        return $carry;
    }
    if ($role === 'scorer' && empty($allowedRoomIdsBySubject[$sid][$rid])) {
        return $carry;
    }
    $carry[$sid] = $carry[$sid] ?? [];
    $carry[$sid][] = [
        'id' => $rid,
        'label' => (string) (($room['ten_phong'] ?? '') . ' - Khối ' . ($room['khoi'] ?? '')),
    ];
    return $carry;
}, []), JSON_UNESCAPED_UNICODE) ?>;

const scoringFilterForm = document.getElementById('scoringFilterForm');
const subjectFilterSelect = document.getElementById('subjectFilterSelect');
const roomFilterSelect = document.getElementById('roomFilterSelect');

function refillRoomOptions(subjectId){
  if (!roomFilterSelect) {
    return;
  }
  const currentRoom = String(roomFilterSelect.value || '');
  const items = roomOptionsBySubject[String(subjectId)] || roomOptionsBySubject[Number(subjectId)] || [];
  roomFilterSelect.innerHTML = '';
  items.forEach((item, idx) => {
    const opt = document.createElement('option');
    opt.value = String(item.id);
    opt.textContent = String(item.label || '');
    if (String(item.id) === currentRoom || (currentRoom === '' && idx === 0)) {
      opt.selected = true;
    }
    roomFilterSelect.appendChild(opt);
  });
}

subjectFilterSelect?.addEventListener('change', () => {
  refillRoomOptions(subjectFilterSelect.value || '0');
  scoringFilterForm?.submit();
});

roomFilterSelect?.addEventListener('change', () => {
  scoringFilterForm?.submit();
});
</script>

<script src="<?= BASE_URL ?>/modules/exams/assets/score_input.js"></script>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
