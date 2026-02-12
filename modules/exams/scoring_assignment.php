<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_role(['admin', 'exam_manager', 'organizer']);

$csrf = exams_get_csrf_token();
$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }

    try {
        exams_assert_exam_unlocked_for_write($pdo, $examId);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    if (empty($errors)) {
        $action = (string) ($_POST['action'] ?? 'create');
        try {
            $pdo->beginTransaction();
            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('DELETE FROM score_assignments WHERE id = :id AND exam_id = :exam_id');
                $stmt->execute([':id' => $id, ':exam_id' => $examId]);
            } else {
                $subjectId = (int) ($_POST['subject_id'] ?? 0);
                $mode = (string) ($_POST['assign_mode'] ?? 'subject_grade');
                $khoi = trim((string) ($_POST['khoi'] ?? ''));
                $roomId = (int) ($_POST['room_id'] ?? 0);
                $userId = (int) ($_POST['user_id'] ?? 0);

                if ($subjectId <= 0 || $userId <= 0) {
                    throw new RuntimeException('Môn chấm và người nhập điểm là bắt buộc.');
                }

                if ($mode === 'subject_grade') {
                    if ($khoi === '') {
                        throw new RuntimeException('Vui lòng chọn khối khi phân công theo môn + khối.');
                    }
                    $exists = $pdo->prepare('SELECT 1 FROM score_assignments WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi AND room_id IS NULL LIMIT 1');
                    $exists->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
                    if ($exists->fetchColumn()) {
                        throw new RuntimeException('Phân công theo môn + khối đã tồn tại.');
                    }
                    $ins = $pdo->prepare('INSERT INTO score_assignments(exam_id, subject_id, khoi, room_id, user_id) VALUES(:exam_id,:subject_id,:khoi,NULL,:user_id)');
                    $ins->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi, ':user_id' => $userId]);
                } else {
                    if ($roomId <= 0) {
                        throw new RuntimeException('Vui lòng chọn phòng khi phân công theo phòng + môn.');
                    }
                    $exists = $pdo->prepare('SELECT 1 FROM score_assignments WHERE exam_id = :exam_id AND subject_id = :subject_id AND room_id = :room_id LIMIT 1');
                    $exists->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':room_id' => $roomId]);
                    if ($exists->fetchColumn()) {
                        throw new RuntimeException('Phân công theo phòng + môn đã tồn tại.');
                    }
                    $ins = $pdo->prepare('INSERT INTO score_assignments(exam_id, subject_id, khoi, room_id, user_id) VALUES(:exam_id,:subject_id,NULL,:room_id,:user_id)');
                    $ins->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':room_id' => $roomId, ':user_id' => $userId]);
                }
            }
            $pdo->commit();
            exams_set_flash('success', 'Cập nhật phân công chấm thành công.');
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

$subjects = $pdo->query('SELECT id, ten_mon FROM subjects ORDER BY ten_mon')->fetchAll(PDO::FETCH_ASSOC);
$scoreUsersStmt = $pdo->query("SELECT id, username FROM users WHERE active = 1 AND role = 'score_entry' ORDER BY username");
$scoreUsers = $scoreUsersStmt ? $scoreUsersStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$rooms = $pdo->prepare('SELECT r.id, r.ten_phong, r.khoi, s.ten_mon, r.subject_id FROM rooms r INNER JOIN subjects s ON s.id = r.subject_id WHERE r.exam_id = :exam_id ORDER BY s.ten_mon, r.ten_phong');
$rooms->execute([':exam_id' => $examId]);
$rooms = $rooms->fetchAll(PDO::FETCH_ASSOC);

$assignStmt = $pdo->prepare('SELECT sa.*, u.username, sub.ten_mon, r.ten_phong FROM score_assignments sa INNER JOIN users u ON u.id = sa.user_id INNER JOIN subjects sub ON sub.id = sa.subject_id LEFT JOIN rooms r ON r.id = sa.room_id WHERE sa.exam_id = :exam_id ORDER BY sub.ten_mon, sa.khoi, r.ten_phong');
$assignStmt->execute([':exam_id' => $examId]);
$assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
<?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
<div style="flex:1;padding:20px;min-width:0;">
<div class="card shadow-sm mb-3"><div class="card-header bg-primary text-white"><strong>Phân công nhập điểm</strong></div><div class="card-body">
<?= exams_display_flash(); ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" class="row g-2">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<div class="col-md-3"><label class="form-label">Môn</label><select name="subject_id" class="form-select" required><?php foreach($subjects as $sub): ?><option value="<?= (int)$sub['id'] ?>"><?= htmlspecialchars((string)$sub['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Người nhập điểm</label><select name="user_id" class="form-select" required><?php foreach($scoreUsers as $u): ?><option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Chế độ</label><div>
<div class="form-check"><input checked class="form-check-input" type="radio" name="assign_mode" value="subject_grade" id="m1"><label class="form-check-label" for="m1">Theo môn + khối</label></div>
<div class="form-check"><input class="form-check-input" type="radio" name="assign_mode" value="subject_room" id="m2"><label class="form-check-label" for="m2">Theo phòng + môn</label></div>
</div></div>
<div class="col-md-3" id="scopeKhoi"><label class="form-label">Khối</label><input name="khoi" class="form-control" placeholder="VD: 12"></div>
<div class="col-md-6 d-none" id="scopeRoom"><label class="form-label">Phòng</label><select name="room_id" class="form-select"><option value="">-- Chọn phòng --</option><?php foreach($rooms as $r): ?><option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars((string)$r['ten_mon'].' - '.$r['ten_phong'].' (Khối '.$r['khoi'].')', ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
<div class="col-12"><button class="btn btn-success" type="submit">Lưu phân công</button></div>
</form></div></div>

<div class="card shadow-sm"><div class="card-header">Danh sách phân công</div><div class="card-body"><table class="table table-sm table-bordered"><thead><tr><th>Môn</th><th>Phạm vi</th><th>Người nhập điểm</th><th></th></tr></thead><tbody><?php foreach($assignments as $a): ?><tr><td><?= htmlspecialchars((string)$a['ten_mon'], ENT_QUOTES, 'UTF-8') ?></td><td><?= $a['room_id'] ? ('Phòng '.htmlspecialchars((string)$a['ten_phong'], ENT_QUOTES, 'UTF-8')) : ('Khối '.htmlspecialchars((string)$a['khoi'], ENT_QUOTES, 'UTF-8')) ?></td><td><?= htmlspecialchars((string)$a['username'], ENT_QUOTES, 'UTF-8') ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-outline-danger">Xoá</button></form></td></tr><?php endforeach; ?></tbody></table></div></div>
</div></div>
<script>
const modeRadios = document.querySelectorAll('input[name="assign_mode"]');
const khoi = document.getElementById('scopeKhoi');
const room = document.getElementById('scopeRoom');
function syncMode(){
  const mode = document.querySelector('input[name="assign_mode"]:checked').value;
  if(mode === 'subject_room'){room.classList.remove('d-none');khoi.classList.add('d-none');}
  else {room.classList.add('d-none');khoi.classList.remove('d-none');}
}
modeRadios.forEach(r=>r.addEventListener('change',syncMode)); syncMode();
</script>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
