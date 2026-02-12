<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';

require_once BASE_PATH . '/modules/exams/_common.php';

$exams = exams_get_all_exams($pdo);
$examId = max(0, (int) ($_GET['exam_id'] ?? 0));
$roomGroups = [];

if ($examId > 0) {
    $stmt = $pdo->prepare('SELECT r.id AS room_id, r.ten_phong, r.khoi, sub.ten_mon, es.sbd, st.hoten, st.lop
        FROM rooms r
        INNER JOIN subjects sub ON sub.id = r.subject_id
        LEFT JOIN exam_students es ON es.room_id = r.id
        LEFT JOIN students st ON st.id = es.student_id
        WHERE r.exam_id = :exam_id
        ORDER BY sub.ten_mon, r.khoi, r.ten_phong, es.sbd');
    $stmt->execute([':exam_id' => $examId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $key = (string) $row['room_id'];
        if (!isset($roomGroups[$key])) {
            $roomGroups[$key] = [
                'ten_phong' => (string) $row['ten_phong'],
                'khoi' => (string) $row['khoi'],
                'ten_mon' => (string) $row['ten_mon'],
                'students' => [],
            ];
        }

        if (!empty($row['hoten'])) {
            $roomGroups[$key]['students'][] = [
                'sbd' => (string) $row['sbd'],
                'hoten' => (string) $row['hoten'],
                'lop' => (string) $row['lop'],
            ];
        }
    }
}

$wizard = $examId > 0 ? exams_wizard_steps($pdo, $examId) : [];

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <strong>Bước 6: In danh sách phòng thi</strong>
                <button class="btn btn-light btn-sm" onclick="window.print()">In</button>
            </div>
            <div class="card-body">
                <?= exams_display_flash(); ?>

                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-6">
                        <select name="exam_id" class="form-select" required>
                            <option value="">-- Chọn kỳ thi --</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int)$exam['id'] ?>" <?= $examId === (int)$exam['id'] ? 'selected' : '' ?>>#<?= (int)$exam['id'] ?> - <?= htmlspecialchars((string)$exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3"><button class="btn btn-primary" type="submit">Xem danh sách</button></div>
                </form>

                <?php if ($examId > 0): ?>
                    <div class="mb-3">
                        <?php foreach ($wizard as $index => $step): ?>
                            <span class="badge <?= $step['done'] ? 'bg-success' : 'bg-secondary' ?> me-1">B<?= $index ?>: <?= htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>

                    <?php if (empty($roomGroups)): ?>
                        <div class="alert alert-warning">Chưa có dữ liệu phòng thi.</div>
                    <?php else: ?>
                        <?php foreach ($roomGroups as $room): ?>
                            <div class="border rounded p-3 mb-3">
                                <h5 class="mb-2">Phòng: <?= htmlspecialchars($room['ten_phong'], ENT_QUOTES, 'UTF-8') ?> | Môn: <?= htmlspecialchars($room['ten_mon'], ENT_QUOTES, 'UTF-8') ?> | Khối: <?= htmlspecialchars($room['khoi'], ENT_QUOTES, 'UTF-8') ?></h5>
                                <table class="table table-sm table-bordered mb-0">
                                    <thead><tr><th>#</th><th>SBD</th><th>Họ tên</th><th>Lớp</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($room['students'] as $idx => $student): ?>
                                            <tr>
                                                <td><?= $idx + 1 ?></td>
                                                <td><?= htmlspecialchars($student['sbd'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($student['hoten'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($student['lop'], ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
