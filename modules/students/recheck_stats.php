<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/core/auth.php';
require_login();
require_role(['admin', 'organizer']);
require_once BASE_PATH . '/core/db.php';

$pdo->exec('CREATE TABLE IF NOT EXISTS student_recheck_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exam_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    subject_id INTEGER NOT NULL,
    room_id INTEGER,
    component_1 REAL,
    component_2 REAL,
    component_3 REAL,
    note TEXT,
    status TEXT DEFAULT "pending",
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(exam_id, student_id, subject_id)
)');

$examId = (int) ($_GET['exam_id'] ?? 0);
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$roomId = (int) ($_GET['room_id'] ?? 0);
$export = (string) ($_GET['export'] ?? '');

$exams = $pdo->query('SELECT id, ten_ky_thi FROM exams ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
if ($examId <= 0 && !empty($exams)) {
    $examId = (int) ($exams[0]['id'] ?? 0);
}

$subjects = [];
$rooms = [];
if ($examId > 0) {
    $sStmt = $pdo->prepare('SELECT DISTINCT s.id, s.ten_mon
        FROM student_recheck_requests rr
        INNER JOIN subjects s ON s.id = rr.subject_id
        WHERE rr.exam_id = :exam_id
        ORDER BY s.ten_mon');
    $sStmt->execute([':exam_id' => $examId]);
    $subjects = $sStmt->fetchAll(PDO::FETCH_ASSOC);

    $rStmt = $pdo->prepare('SELECT DISTINCT r.id, r.ten_phong
        FROM student_recheck_requests rr
        LEFT JOIN rooms r ON r.id = rr.room_id
        WHERE rr.exam_id = :exam_id AND rr.room_id IS NOT NULL
        ORDER BY r.ten_phong');
    $rStmt->execute([':exam_id' => $examId]);
    $rooms = $rStmt->fetchAll(PDO::FETCH_ASSOC);
}

$where = ' WHERE rr.exam_id = :exam_id';
$params = [':exam_id' => $examId];
if ($subjectId > 0) {
    $where .= ' AND rr.subject_id = :subject_id';
    $params[':subject_id'] = $subjectId;
}
if ($roomId > 0) {
    $where .= ' AND rr.room_id = :room_id';
    $params[':room_id'] = $roomId;
}

$sql = 'SELECT rr.*, st.sbd, st.hoten, st.lop, sub.ten_mon, rm.ten_phong,
        COALESCE(sc.total_score, sc.diem) AS total_score, sc.component_1 AS score_component_1, sc.component_2 AS score_component_2, sc.component_3 AS score_component_3,
        COALESCE(cfg.component_count, CASE WHEN rr.component_3 IS NOT NULL THEN 3 WHEN rr.component_2 IS NOT NULL THEN 2 ELSE 1 END) AS component_count
    FROM student_recheck_requests rr
    INNER JOIN students st ON st.id = rr.student_id
    INNER JOIN subjects sub ON sub.id = rr.subject_id
    LEFT JOIN rooms rm ON rm.id = rr.room_id
    LEFT JOIN scores sc ON sc.exam_id = rr.exam_id AND sc.student_id = rr.student_id AND sc.subject_id = rr.subject_id
    LEFT JOIN exam_subject_config cfg ON cfg.exam_id = rr.exam_id AND cfg.subject_id = rr.subject_id' . $where . '
    ORDER BY sub.ten_mon, rm.ten_phong, st.sbd';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$groups = [1 => [], 2 => [], 3 => []];
foreach ($rows as $row) {
    $count = max(1, min(3, (int) ($row['component_count'] ?? 1)));
    $groups[$count][] = $row;
}

if (in_array($export, ['excel', 'pdf'], true)) {
    $filename = 'thong_ke_phuc_tra_exam_' . $examId . ($export === 'excel' ? '.xls' : '.html');
    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: inline; filename="' . $filename . '"');
    }

    echo '<!doctype html><html><head><meta charset="UTF-8"><title>Thống kê phúc tra</title>';
    echo '<style>body{font-family:"Times New Roman",serif;font-size:14px} table{width:100%;border-collapse:collapse;margin:10px 0}th,td{border:1px solid #333;padding:6px}h2{margin-top:20px}</style>';
    echo '</head><body>';
    echo '<h1>THỐNG KÊ HỌC SINH PHÚC TRA</h1>';

    foreach ([1,2,3] as $c) {
        if (empty($groups[$c])) {
            continue;
        }
        echo '<h2>Danh sách thành phần điểm: ' . $c . '</h2>';
        echo '<table><thead><tr><th>STT</th><th>SBD</th><th>Họ tên</th><th>Lớp</th><th>Môn</th><th>Phòng</th><th>Điểm TP1</th>';
        if ($c >= 2) {
            echo '<th>Điểm TP2</th>';
        }
        if ($c >= 3) {
            echo '<th>Điểm TP3</th>';
        }
        echo '<th>Tổng điểm</th><th>Phúc tra TP1</th>';
        if ($c >= 2) {
            echo '<th>Phúc tra TP2</th>';
        }
        if ($c >= 3) {
            echo '<th>Phúc tra TP3</th>';
        }
        echo '<th>Ghi chú</th></tr></thead><tbody>';

        $i = 1;
        foreach ($groups[$c] as $r) {
            echo '<tr>';
            echo '<td>' . $i++ . '</td>';
            echo '<td>' . htmlspecialchars((string) ($r['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($r['hoten'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($r['lop'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($r['ten_mon'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($r['ten_phong'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . number_format((float) ($r['score_component_1'] ?? 0), 2) . '</td>';
            if ($c >= 2) { echo '<td>' . ((isset($r['score_component_2']) && $r['score_component_2'] !== null) ? number_format((float) $r['score_component_2'], 2) : '-') . '</td>'; }
            if ($c >= 3) { echo '<td>' . ((isset($r['score_component_3']) && $r['score_component_3'] !== null) ? number_format((float) $r['score_component_3'], 2) : '-') . '</td>'; }
            echo '<td>' . number_format((float) ($r['total_score'] ?? 0), 2) . '</td>';
            echo '<td>' . ((isset($r['component_1']) && $r['component_1'] !== null) ? number_format((float) $r['component_1'], 2) : '-') . '</td>';
            if ($c >= 2) { echo '<td>' . ((isset($r['component_2']) && $r['component_2'] !== null) ? number_format((float) $r['component_2'], 2) : '-') . '</td>'; }
            if ($c >= 3) { echo '<td>' . ((isset($r['component_3']) && $r['component_3'] !== null) ? number_format((float) $r['component_3'], 2) : '-') . '</td>'; }
            echo '<td>' . htmlspecialchars((string) ($r['note'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '</body></html>';
    exit;
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div class="students-layout" style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div class="students-main" style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Thống kê học sinh phúc tra</strong></div>
            <div class="card-body">
                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-3">
                        <select class="form-select" name="exam_id" required>
                            <?php foreach ($exams as $ex): ?>
                                <option value="<?= (int) $ex['id'] ?>" <?= $examId === (int) $ex['id'] ? 'selected' : '' ?>>#<?= (int) $ex['id'] ?> - <?= htmlspecialchars((string) $ex['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="subject_id">
                            <option value="0">-- Tất cả môn --</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $subjectId === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="room_id">
                            <option value="0">-- Tất cả phòng --</option>
                            <?php foreach ($rooms as $r): ?>
                                <option value="<?= (int) $r['id'] ?>" <?= $roomId === (int) $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) ($r['ten_phong'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Lọc</button>
                        <a class="btn btn-outline-success" href="?<?= htmlspecialchars(http_build_query(['exam_id' => $examId, 'subject_id' => $subjectId, 'room_id' => $roomId, 'export' => 'excel']), ENT_QUOTES, 'UTF-8') ?>">Xuất Excel</a>
                        <a class="btn btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query(['exam_id' => $examId, 'subject_id' => $subjectId, 'room_id' => $roomId, 'export' => 'pdf']), ENT_QUOTES, 'UTF-8') ?>">Xuất PDF</a>
                    </div>
                </form>

                <?php foreach ([1, 2, 3] as $c): ?>
                    <?php if (empty($groups[$c])) { continue; } ?>
                    <h5 class="mt-4">Danh sách thành phần điểm: <?= $c ?></h5>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle">
                            <thead class="table-light"><tr>
                                <th>STT</th><th>SBD</th><th>Họ tên</th><th>Lớp</th><th>Môn</th><th>Phòng</th><th>Điểm TP1</th>
                                <?php if ($c >= 2): ?><th>Điểm TP2</th><?php endif; ?>
                                <?php if ($c >= 3): ?><th>Điểm TP3</th><?php endif; ?>
                                <th>Tổng điểm</th><th>Phúc tra TP1</th>
                                <?php if ($c >= 2): ?><th>Phúc tra TP2</th><?php endif; ?>
                                <?php if ($c >= 3): ?><th>Phúc tra TP3</th><?php endif; ?>
                                <th>Ghi chú</th>
                            </tr></thead>
                            <tbody>
                            <?php $stt = 1; foreach ($groups[$c] as $r): ?>
                                <tr>
                                    <td><?= $stt++ ?></td>
                                    <td><?= htmlspecialchars((string) ($r['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['hoten'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['lop'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['ten_mon'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['ten_phong'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= number_format((float) ($r['score_component_1'] ?? 0), 2) ?></td>
                                    <?php if ($c >= 2): ?><td><?= isset($r['score_component_2']) && $r['score_component_2'] !== null ? number_format((float) $r['score_component_2'], 2) : '-' ?></td><?php endif; ?>
                                    <?php if ($c >= 3): ?><td><?= isset($r['score_component_3']) && $r['score_component_3'] !== null ? number_format((float) $r['score_component_3'], 2) : '-' ?></td><?php endif; ?>
                                    <td><?= number_format((float) ($r['total_score'] ?? 0), 2) ?></td>
                                    <td><?= isset($r['component_1']) && $r['component_1'] !== null ? number_format((float) $r['component_1'], 2) : '-' ?></td>
                                    <?php if ($c >= 2): ?><td><?= isset($r['component_2']) && $r['component_2'] !== null ? number_format((float) $r['component_2'], 2) : '-' ?></td><?php endif; ?>
                                    <?php if ($c >= 3): ?><td><?= isset($r['component_3']) && $r['component_3'] !== null ? number_format((float) $r['component_3'], 2) : '-' ?></td><?php endif; ?>
                                    <td><?= htmlspecialchars((string) ($r['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($groups[$c])): ?><tr><td colspan="14" class="text-center">Không có dữ liệu.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?><div class="alert alert-info">Chưa có đăng ký phúc tra.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
