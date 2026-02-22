<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';


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
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_recheck_exam_subject_room ON student_recheck_requests(exam_id, subject_id, room_id)');

$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$roomId = (int) ($_GET['room_id'] ?? 0);
$export = (string) ($_GET['export'] ?? '');

$examStmt = $pdo->prepare('SELECT ten_ky_thi FROM exams WHERE id = :id LIMIT 1');
$examStmt->execute([':id' => $examId]);
$examName = (string) ($examStmt->fetchColumn() ?: 'Kỳ thi hiện tại');

$subjectsStmt = $pdo->prepare('SELECT DISTINCT s.id, s.ten_mon
    FROM student_recheck_requests rr
    INNER JOIN subjects s ON s.id = rr.subject_id
    WHERE rr.exam_id = :exam_id
    ORDER BY s.ten_mon');
$subjectsStmt->execute([':exam_id' => $examId]);
$subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);

$roomsStmt = $pdo->prepare('SELECT DISTINCT r.id, r.ten_phong
    FROM student_recheck_requests rr
    LEFT JOIN rooms r ON r.id = rr.room_id
    WHERE rr.exam_id = :exam_id AND rr.room_id IS NOT NULL
    ORDER BY r.ten_phong');
$roomsStmt->execute([':exam_id' => $examId]);
$rooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);

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

$sql = 'SELECT rr.*, st.sbd, st.hoten, st.lop, sub.ten_mon, COALESCE(rm.ten_phong, "") AS ten_phong,
        COALESCE(sc.total_score, sc.diem) AS total_score,
        sc.component_1 AS score_component_1, sc.component_2 AS score_component_2, sc.component_3 AS score_component_3,
        COALESCE(cfg.component_count, CASE WHEN rr.component_3 IS NOT NULL THEN 3 WHEN rr.component_2 IS NOT NULL THEN 2 ELSE 1 END) AS component_count
    FROM student_recheck_requests rr
    INNER JOIN students st ON st.id = rr.student_id
    INNER JOIN subjects sub ON sub.id = rr.subject_id
    LEFT JOIN rooms rm ON rm.id = rr.room_id
    LEFT JOIN scores sc ON sc.exam_id = rr.exam_id AND sc.student_id = rr.student_id AND sc.subject_id = rr.subject_id
    LEFT JOIN exam_subject_config cfg ON cfg.exam_id = rr.exam_id AND cfg.subject_id = rr.subject_id' . $where . '
    ORDER BY sub.ten_mon ASC, rm.ten_phong ASC, st.sbd ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$groups = [1 => [], 2 => [], 3 => []];
foreach ($rows as $row) {
    $count = max(1, min(3, (int) ($row['component_count'] ?? 1)));
    $groups[$count][] = $row;
}

$renderHtml = static function() use ($groups, $examName, $examId): string {
    ob_start();
    ?>
    <!doctype html>
    <html lang="vi"><head><meta charset="UTF-8"><title>Thống kê phúc tra</title>
    <style>
        body{font-family:"Times New Roman",serif;font-size:14px}
        h1,h2{text-align:center}
        .meta{text-align:center;margin-bottom:10px}
        table{width:100%;border-collapse:collapse;margin:12px 0}
        th,td{border:1px solid #222;padding:6px}
        th{background:#f2f2f2}
    </style></head><body>
    <h1>DANH SÁCH HỌC SINH PHÚC TRA</h1>
    <div class="meta">Kỳ thi #<?= (int) $examId ?> - <?= htmlspecialchars($examName, ENT_QUOTES, 'UTF-8') ?></div>
    <?php foreach ([1,2,3] as $c): if (empty($groups[$c])) { continue; } ?>
        <h2>Danh sách thành phần điểm: <?= $c ?></h2>
        <table><thead><tr>
            <th>STT</th><th>SBD</th><th>Họ tên</th><th>Lớp</th><th>Môn</th><th>Phòng</th>
            <th>Điểm TP1</th><?php if ($c>=2): ?><th>Điểm TP2</th><?php endif; ?><?php if ($c>=3): ?><th>Điểm TP3</th><?php endif; ?>
            <th>Tổng điểm</th>
            <th>Phúc tra TP1</th><?php if ($c>=2): ?><th>Phúc tra TP2</th><?php endif; ?><?php if ($c>=3): ?><th>Phúc tra TP3</th><?php endif; ?>
            <th>Ghi chú</th>
        </tr></thead><tbody>
        <?php $i=1; foreach ($groups[$c] as $r): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars((string) ($r['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($r['hoten'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($r['lop'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($r['ten_mon'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($r['ten_phong'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= number_format((float) ($r['score_component_1'] ?? 0),2) ?></td>
                <?php if ($c>=2): ?><td><?= isset($r['score_component_2']) && $r['score_component_2'] !== null ? number_format((float)$r['score_component_2'],2) : '-' ?></td><?php endif; ?>
                <?php if ($c>=3): ?><td><?= isset($r['score_component_3']) && $r['score_component_3'] !== null ? number_format((float)$r['score_component_3'],2) : '-' ?></td><?php endif; ?>
                <td><?= number_format((float) ($r['total_score'] ?? 0),2) ?></td>
                <td><?= isset($r['component_1']) && $r['component_1'] !== null ? number_format((float)$r['component_1'],2) : '-' ?></td>
                <?php if ($c>=2): ?><td><?= isset($r['component_2']) && $r['component_2'] !== null ? number_format((float)$r['component_2'],2) : '-' ?></td><?php endif; ?>
                <?php if ($c>=3): ?><td><?= isset($r['component_3']) && $r['component_3'] !== null ? number_format((float)$r['component_3'],2) : '-' ?></td><?php endif; ?>
                <td><?= htmlspecialchars((string) ($r['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endforeach; ?>
    </body></html>
    <?php
    return (string) ob_get_clean();
};

if ($export === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="thong_ke_phuc_tra_exam_' . $examId . '.xls"');
    echo $renderHtml();
    exit;
}
if ($export === 'pdf') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline; filename="thong_ke_phuc_tra_exam_' . $examId . '.html"');
    echo $renderHtml();
    exit;
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-info text-dark"><strong>Thống kê phúc tra</strong></div>
            <div class="card-body">
                <div class="alert alert-secondary">Kỳ thi mặc định: <strong>#<?= $examId ?> - <?= htmlspecialchars($examName, ENT_QUOTES, 'UTF-8') ?></strong></div>
                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-4">
                        <select class="form-select" name="subject_id">
                            <option value="0">-- Tất cả môn --</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= $subjectId === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select class="form-select" name="room_id">
                            <option value="0">-- Tất cả phòng --</option>
                            <?php foreach ($rooms as $r): ?>
                                <option value="<?= (int) $r['id'] ?>" <?= $roomId === (int) $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $r['ten_phong'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Lọc</button>
                        <a class="btn btn-outline-success" href="?<?= htmlspecialchars(http_build_query(['subject_id' => $subjectId, 'room_id' => $roomId, 'export' => 'excel']), ENT_QUOTES, 'UTF-8') ?>">Xuất Excel</a>
                        <a class="btn btn-outline-secondary" href="?<?= htmlspecialchars(http_build_query(['subject_id' => $subjectId, 'room_id' => $roomId, 'export' => 'pdf']), ENT_QUOTES, 'UTF-8') ?>">Xuất PDF</a>
                    </div>
                </form>

                <?php foreach ([1,2,3] as $c): if (empty($groups[$c])) { continue; } ?>
                    <h5 class="mt-3">Danh sách thành phần điểm: <?= $c ?></h5>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle">
                            <thead class="table-light"><tr>
                                <th>STT</th><th>SBD</th><th>Họ tên</th><th>Lớp</th><th>Môn</th><th>Phòng</th>
                                <th>Điểm TP1</th><?php if ($c>=2): ?><th>Điểm TP2</th><?php endif; ?><?php if ($c>=3): ?><th>Điểm TP3</th><?php endif; ?>
                                <th>Tổng điểm</th>
                                <th>Phúc tra TP1</th><?php if ($c>=2): ?><th>Phúc tra TP2</th><?php endif; ?><?php if ($c>=3): ?><th>Phúc tra TP3</th><?php endif; ?>
                                <th>Ghi chú</th>
                            </tr></thead><tbody>
                            <?php $i=1; foreach ($groups[$c] as $r): ?>
                                <tr>
                                    <td><?= $i++ ?></td>
                                    <td><?= htmlspecialchars((string) ($r['sbd'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['hoten'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['lop'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['ten_mon'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($r['ten_phong'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= number_format((float) ($r['score_component_1'] ?? 0), 2) ?></td>
                                    <?php if ($c>=2): ?><td><?= isset($r['score_component_2']) && $r['score_component_2'] !== null ? number_format((float)$r['score_component_2'],2) : '-' ?></td><?php endif; ?>
                                    <?php if ($c>=3): ?><td><?= isset($r['score_component_3']) && $r['score_component_3'] !== null ? number_format((float)$r['score_component_3'],2) : '-' ?></td><?php endif; ?>
                                    <td><?= number_format((float) ($r['total_score'] ?? 0), 2) ?></td>
                                    <td><?= isset($r['component_1']) && $r['component_1'] !== null ? number_format((float)$r['component_1'],2) : '-' ?></td>
                                    <?php if ($c>=2): ?><td><?= isset($r['component_2']) && $r['component_2'] !== null ? number_format((float)$r['component_2'],2) : '-' ?></td><?php endif; ?>
                                    <?php if ($c>=3): ?><td><?= isset($r['component_3']) && $r['component_3'] !== null ? number_format((float)$r['component_3'],2) : '-' ?></td><?php endif; ?>
                                    <td><?= htmlspecialchars((string) ($r['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody></table>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?><div class="alert alert-info">Chưa có đăng ký phúc tra cho kỳ thi mặc định.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
