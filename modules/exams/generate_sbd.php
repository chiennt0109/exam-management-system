<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';

require_once BASE_PATH . '/modules/exams/_common.php';

$csrf = exams_get_csrf_token();
$exams = exams_get_all_exams($pdo);
$examId = exams_resolve_current_exam_from_request();
if ($examId <= 0) {
    exams_set_flash('warning', 'Vui lòng chọn kỳ thi hiện tại trước khi thao tác.');
    header('Location: ' . BASE_URL . '/modules/exams/index.php');
    exit;
}
$fixedExamContext = getCurrentExamId() > 0;

function sbdSortNameKey(string $fullName): string
{
    $name = trim($fullName);
    if ($name == '') {
        return '';
    }

    $parts = preg_split('/\s+/u', $name) ?: [];
    $last = (string) end($parts);
    $lastLower = function_exists('mb_strtolower') ? mb_strtolower($last, 'UTF-8') : strtolower($last);
    $fullLower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);

    return $lastLower . '|' . $fullLower;
}

function sbdGradeGroupKey(string $khoi): string
{
    $raw = trim($khoi);
    return function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        exams_set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: ' . BASE_URL . '/modules/exams/generate_sbd.php?exam_id=' . $examId);
        exit;
    }

    exams_assert_exam_unlocked_for_write($pdo, $examId);

    if ($examId <= 0) {
        exams_set_flash('error', 'Vui lòng chọn kỳ thi.');
        header('Location: ' . BASE_URL . '/modules/exams/generate_sbd.php');
        exit;
    }

    try {
        $duplicateRows = checkDuplicateSBD($pdo, $examId);
        if (!empty($duplicateRows)) {
            exams_set_flash('error', 'Phát hiện trùng SBD. Vui lòng xử lý trước khi sinh SBD mới.');
            header('Location: ' . BASE_URL . '/modules/exams/generate_sbd.php?exam_id=' . $examId);
            exit;
        }

        $baseStudentsStmt = $pdo->prepare('SELECT es.id, es.khoi, s.hoten FROM exam_students es INNER JOIN students s ON s.id = es.student_id WHERE es.exam_id = :exam_id AND es.subject_id IS NULL');
        $baseStudentsStmt->execute([':exam_id' => $examId]);
        $rows = $baseStudentsStmt->fetchAll(PDO::FETCH_ASSOC);
        usort($rows, static function (array $a, array $b): int {
            $ga = sbdGradeGroupKey((string) ($a['khoi'] ?? ''));
            $gb = sbdGradeGroupKey((string) ($b['khoi'] ?? ''));

            $cmpGrade = $ga <=> $gb;
            if ($cmpGrade !== 0) {
                return $cmpGrade;
            }

            $ka = sbdSortNameKey((string) ($a['hoten'] ?? ''));
            $kb = sbdSortNameKey((string) ($b['hoten'] ?? ''));
            if ($ka !== $kb) {
                return $ka <=> $kb;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        if (empty($rows)) {
            exams_set_flash('warning', 'Chưa có học sinh gán cho kỳ thi.');
            header('Location: ' . BASE_URL . '/modules/exams/generate_sbd.php?exam_id=' . $examId);
            exit;
        }

        $pdo->beginTransaction();
        $updateBase = $pdo->prepare('UPDATE exam_students SET sbd = :sbd WHERE id = :id AND sbd IS NULL');
        $generated = 0;
        foreach ($rows as $row) {
            $sbd = generateNextSBD($pdo, $examId);
            $updateBase->execute([':sbd' => $sbd, ':id' => (int) $row['id']]);
            if ($updateBase->rowCount() > 0) {
                $generated++;
            }
        }

        $syncSubjectRows = $pdo->prepare('UPDATE exam_students
            SET sbd = (
                SELECT b.sbd FROM exam_students b
                WHERE b.exam_id = exam_students.exam_id
                  AND b.student_id = exam_students.student_id
                  AND b.subject_id IS NULL
                LIMIT 1
            )
            WHERE exam_id = :exam_id
              AND subject_id IS NOT NULL
              AND sbd IS NULL');
        $syncSubjectRows->execute([':exam_id' => $examId]);

        $pdo->commit();
        exams_set_flash('success', 'Đã sinh SBD mới cho ' . $generated . ' học sinh chưa có SBD.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        exams_set_flash('error', 'Không thể sinh SBD.');
    }

    header('Location: ' . BASE_URL . '/modules/exams/generate_sbd.php?exam_id=' . $examId);
    exit;
}

$students = [];
if ($examId > 0) {
    $stmt = $pdo->prepare('SELECT es.id, es.student_id, es.khoi, es.lop, es.sbd, s.hoten
        FROM exam_students es
        INNER JOIN students s ON s.id = es.student_id
        WHERE es.exam_id = :exam_id AND es.subject_id IS NULL
        ORDER BY es.sbd IS NULL, es.sbd, s.hoten');
    $stmt->execute([':exam_id' => $examId]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$wizard = $examId > 0 ? exams_wizard_steps($pdo, $examId) : [];

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Bước 3: Sinh SBD</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>

                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-6">
                        <?php if ($fixedExamContext): ?><input type="hidden" name="exam_id" value="<?= $examId ?>"><div class="form-control bg-light">#<?= $examId ?> - Kỳ thi hiện tại</div><?php else: ?><select name="exam_id" class="form-select" required>
                            <option value="">-- Chọn kỳ thi --</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>
                                    #<?= (int) $exam['id'] ?> - <?= htmlspecialchars((string) $exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select><?php endif; ?>
                    </div>
                    <div class="col-md-3"><button class="btn btn-primary" type="submit">Tải dữ liệu</button></div>
                </form>

                <?php if ($examId > 0): ?>
                    <div class="mb-3">
                        <?php foreach ($wizard as $index => $step): ?>
                            <span class="badge <?= $step['done'] ? 'bg-success' : 'bg-secondary' ?> me-1">B<?= $index ?>: <?= htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>


                    <div class="mb-3 d-flex gap-2">
                        <a class="btn btn-outline-warning btn-sm" href="check_duplicates.php?exam_id=<?= $examId ?>">Kiểm tra SBD trùng</a>
                        <a class="btn btn-outline-secondary btn-sm" href="export_duplicates.php?exam_id=<?= $examId ?>">Xuất CSV lỗi SBD</a>
                    </div>

                    <form method="post" class="mb-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="exam_id" value="<?= $examId ?>">
                        <button class="btn btn-success" type="submit">Sinh lại SBD cho toàn bộ học sinh kỳ thi</button>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead><tr><th>Họ tên</th><th>Lớp</th><th>Khối</th><th>SBD</th></tr></thead>
                            <tbody>
                                <?php if (empty($students)): ?>
                                    <tr><td colspan="4" class="text-center">Chưa có học sinh.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($students as $s): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) $s['hoten'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) $s['lop'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) $s['khoi'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) $s['sbd'], ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
