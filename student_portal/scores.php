<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
student_require_login();

$student = student_portal_student();
$exam = student_portal_get_exam($pdo, $student['exam_id']);
$canViewScores = $exam ? student_portal_can_view_scores($exam) : false;
$csrf = student_portal_csrf_token();

$subjectStmt = $pdo->prepare('SELECT DISTINCT s.id AS subject_id, s.ten_mon
    FROM exam_subjects es
    INNER JOIN subjects s ON s.id = es.subject_id
    INNER JOIN student_exam_subjects ses ON ses.exam_id = es.exam_id AND ses.subject_id = es.subject_id
    WHERE es.exam_id = :exam_id AND ses.student_id = :student_id
    ORDER BY es.sort_order ASC, s.ten_mon ASC');
$subjectStmt->execute([':exam_id' => $student['exam_id'], ':student_id' => $student['id']]);
$subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($subjects)) {
    $fallbackStmt = $pdo->prepare('SELECT DISTINCT s.id AS subject_id, s.ten_mon
        FROM exam_students es
        INNER JOIN subjects s ON s.id = es.subject_id
        WHERE es.exam_id = :exam_id AND es.student_id = :student_id AND es.subject_id IS NOT NULL
        ORDER BY s.ten_mon ASC');
    $fallbackStmt->execute([':exam_id' => $student['exam_id'], ':student_id' => $student['id']]);
    $subjects = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
}

$subjectMap = [];
foreach ($subjects as $row) {
    $sid = (int) ($row['subject_id'] ?? 0);
    if ($sid > 0) {
        $subjectMap[$sid] = (string) ($row['ten_mon'] ?? '');
    }
}
$subjectIds = array_keys($subjectMap);

$scoreRows = [];
$configRows = [];
$roomBySubject = [];
$existingRequests = [];

if (!empty($subjectIds)) {
    $ph = implode(',', array_fill(0, count($subjectIds), '?'));

    $scoreStmt = $pdo->prepare('SELECT subject_id, total_score, diem, component_1, component_2, component_3
        FROM scores WHERE exam_id = ? AND student_id = ? AND subject_id IN (' . $ph . ')');
    $scoreStmt->execute(array_merge([$student['exam_id'], $student['id']], $subjectIds));
    foreach ($scoreStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $scoreRows[(int) $r['subject_id']] = $r;
    }

    $cfgStmt = $pdo->prepare('SELECT subject_id, component_count
        FROM exam_subject_config WHERE exam_id = ? AND subject_id IN (' . $ph . ')');
    $cfgStmt->execute(array_merge([$student['exam_id']], $subjectIds));
    foreach ($cfgStmt->fetchAll(PDO::FETCH_ASSOC) as $cfg) {
        $sid = (int) ($cfg['subject_id'] ?? 0);
        if (!isset($configRows[$sid])) {
            $configRows[$sid] = $cfg;
        }
    }

    $roomStmt = $pdo->prepare('SELECT subject_id, room_id FROM exam_students WHERE exam_id = ? AND student_id = ? AND subject_id IN (' . $ph . ')');
    $roomStmt->execute(array_merge([$student['exam_id'], $student['id']], $subjectIds));
    foreach ($roomStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $roomBySubject[(int) $r['subject_id']] = (int) ($r['room_id'] ?? 0);
    }

    $rqStmt = $pdo->prepare('SELECT subject_id, component_1, component_2, component_3
        FROM student_recheck_requests WHERE exam_id = ? AND student_id = ? AND subject_id IN (' . $ph . ')');
    $rqStmt->execute(array_merge([$student['exam_id'], $student['id']], $subjectIds));
    foreach ($rqStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existingRequests[(int) ($row['subject_id'] ?? 0)] = $row;
    }
}

$componentLabels = [1 => 'Tự luận', 2 => 'Trắc nghiệm', 3 => 'Nói'];
$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!student_portal_verify_csrf(is_string($token) ? $token : null)) {
        $error = 'Phiên làm việc hết hạn, vui lòng thử lại.';
    } else {
        try {
            $pdo->beginTransaction();
            foreach ($subjectMap as $subjectId => $_name) {
                $cc = max(1, min(3, (int) (($configRows[$subjectId]['component_count'] ?? 1))));
                $vals = [1 => null, 2 => null, 3 => null];
                $selectedAny = false;
                for ($i = 1; $i <= $cc; $i++) {
                    if (isset($_POST['select'][$i][$subjectId])) {
                        $vals[$i] = 1.0;
                        $selectedAny = true;
                    }
                }

                if (!$selectedAny) {
                    $pdo->prepare('DELETE FROM student_recheck_requests WHERE exam_id = :exam_id AND student_id = :student_id AND subject_id = :subject_id')
                        ->execute([':exam_id' => $student['exam_id'], ':student_id' => $student['id'], ':subject_id' => $subjectId]);
                    continue;
                }

                $roomId = (int) ($roomBySubject[$subjectId] ?? 0);
                $pdo->prepare('INSERT INTO student_recheck_requests(exam_id, student_id, subject_id, room_id, component_1, component_2, component_3, status, created_at, updated_at)
                    VALUES(:exam_id,:student_id,:subject_id,:room_id,:c1,:c2,:c3,"pending",datetime("now"),datetime("now"))
                    ON CONFLICT(exam_id, student_id, subject_id) DO UPDATE SET
                        room_id=excluded.room_id, component_1=excluded.component_1, component_2=excluded.component_2, component_3=excluded.component_3,
                        updated_at=datetime("now")')
                    ->execute([
                        ':exam_id' => $student['exam_id'], ':student_id' => $student['id'], ':subject_id' => $subjectId, ':room_id' => $roomId > 0 ? $roomId : null,
                        ':c1' => $vals[1], ':c2' => $vals[2], ':c3' => $vals[3],
                    ]);
            }
            $pdo->commit();
            $success = 'Đã lưu đăng ký phúc tra.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }

        $existingRequests = [];
        if (!empty($subjectIds)) {
            $ph = implode(',', array_fill(0, count($subjectIds), '?'));
            $rqStmt = $pdo->prepare('SELECT subject_id, component_1, component_2, component_3
                FROM student_recheck_requests WHERE exam_id = ? AND student_id = ? AND subject_id IN (' . $ph . ')');
            $rqStmt->execute(array_merge([$student['exam_id'], $student['id']], $subjectIds));
            foreach ($rqStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existingRequests[(int) ($row['subject_id'] ?? 0)] = $row;
            }
        }
    }
}

$scoreDisplay = static function(array $row, string $key): string {
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
        return '-';
    }
    return number_format((float) $row[$key], 2);
};

$total = 0.0;
$avg = 0.0;
$classify = '';
if ($canViewScores && !empty($scoreRows)) {
    $vals = [];
    foreach ($scoreRows as $r) {
        if (array_key_exists('total_score', $r) && $r['total_score'] !== null && $r['total_score'] !== '') {
            $vals[] = (float) $r['total_score'];
        } elseif (array_key_exists('diem', $r) && $r['diem'] !== null && $r['diem'] !== '') {
            $vals[] = (float) $r['diem'];
        }
    }
    if (!empty($vals)) {
        $total = array_sum($vals);
        $avg = $total / count($vals);
        $classify = $avg >= 8.0 ? 'Giỏi' : ($avg >= 6.5 ? 'Khá' : ($avg >= 5.0 ? 'Trung bình' : 'Yếu'));
    }
}

student_portal_render_header('Xem điểm và phúc tra');
?>
<main class="portal-main">
    <?php student_portal_render_student_info($pdo); ?>

    <section class="card">
        <h1><i class="fa-solid fa-chart-column"></i> Kết quả thi &amp; phúc tra</h1>
        <?php if ($error !== ''): ?><div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($success !== ''): ?><div class="alert-info"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <?php if ($canViewScores): ?>
            <h3 class="form-section-title">Điểm đã công bố</h3>
            <table class="portal-table">
                <thead><tr><th>Môn</th><th>Tổng</th><th>Tự luận</th><th>Trắc nghiệm</th><th>Nói</th></tr></thead>
                <tbody>
                <?php foreach ($subjectMap as $sid => $name): $sc = $scoreRows[$sid] ?? []; ?>
                    <tr>
                        <td><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $scoreDisplay($sc, 'total_score') !== '-' ? $scoreDisplay($sc, 'total_score') : $scoreDisplay($sc, 'diem') ?></td>
                        <td><?= $scoreDisplay($sc, 'component_1') ?></td>
                        <td><?= $scoreDisplay($sc, 'component_2') ?></td>
                        <td><?= $scoreDisplay($sc, 'component_3') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($subjectMap)): ?><tr><td colspan="5">Chưa có môn thi.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <div class="summary">
                <p><strong>Tổng điểm:</strong> <?= number_format($total, 2) ?></p>
                <p><strong>Trung bình:</strong> <?= number_format($avg, 2) ?></p>
                <p><strong>Xếp loại:</strong> <?= htmlspecialchars($classify, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php endif; ?>

        <h3 class="form-section-title">Đăng ký phúc tra (ma trận môn × thành phần)</h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <div class="table-responsive">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Môn</th>
                            <th><?= htmlspecialchars($componentLabels[1], ENT_QUOTES, 'UTF-8') ?></th>
                            <th><?= htmlspecialchars($componentLabels[2], ENT_QUOTES, 'UTF-8') ?></th>
                            <th><?= htmlspecialchars($componentLabels[3], ENT_QUOTES, 'UTF-8') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjectMap as $sid => $name): $cc = max(1, min(3, (int) ($configRows[$sid]['component_count'] ?? 1))); $req = $existingRequests[$sid] ?? []; ?>
                            <tr>
                                <td><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></td>
                                <?php for ($c = 1; $c <= 3; $c++): ?>
                                    <td>
                                        <?php if ($c <= $cc): ?>
                                            <input type="checkbox" name="select[<?= $c ?>][<?= (int) $sid ?>]" value="1" <?= isset($req['component_' . $c]) && $req['component_' . $c] !== null ? 'checked' : '' ?>>
                                        <?php else: ?>-
                                        <?php endif; ?>
                                    </td>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (empty($subjectMap)): ?><div class="alert-info">Không có môn trong phạm vi đăng ký phúc tra.</div><?php endif; ?>
            <div class="actions"><button type="submit" class="btn primary">Lưu đăng ký phúc tra</button></div>
        </form>

        <p><a href="<?= BASE_URL ?>/student_portal/dashboard.php">← Quay lại dashboard</a></p>
    </section>
</main>
<?php student_portal_render_footer(); ?>
