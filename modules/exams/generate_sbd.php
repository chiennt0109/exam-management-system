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

$tab = (string) ($_GET['tab'] ?? 'unassigned');
if (!in_array($tab, ['unassigned', 'assigned'], true)) {
    $tab = 'unassigned';
}

$qUnassignedName = trim((string) ($_GET['q_unassigned_name'] ?? ''));
$qUnassignedClass = trim((string) ($_GET['q_unassigned_class'] ?? ''));
$qAssignedName = trim((string) ($_GET['q_assigned_name'] ?? ''));
$qAssignedClass = trim((string) ($_GET['q_assigned_class'] ?? ''));
$pageUnassigned = max(1, (int) ($_GET['page_unassigned'] ?? 1));
$pageAssigned = max(1, (int) ($_GET['page_assigned'] ?? 1));
$perPage = 20;

function sbdSortNameKey(string $fullName): string
{
    $name = trim($fullName);
    if ($name === '') {
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

function buildGenerateSbdUrl(array $params): string
{
    return BASE_URL . '/modules/exams/generate_sbd.php?' . http_build_query($params);
}

function fmtDob(?string $dob): string
{
    if (!$dob) {
        return '';
    }
    $ts = strtotime($dob);
    return $ts ? date('d/m/Y', $ts) : $dob;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        exams_set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: ' . buildGenerateSbdUrl(['exam_id' => $examId, 'tab' => $tab]));
        exit;
    }

    exams_assert_exam_unlocked_for_write($pdo, $examId);

    $action = (string) ($_POST['action'] ?? '');

    $redirectParams = [
        'exam_id' => $examId,
        'tab' => (string) ($_POST['tab'] ?? $tab),
        'q_unassigned_name' => $qUnassignedName,
        'q_unassigned_class' => $qUnassignedClass,
        'q_assigned_name' => $qAssignedName,
        'q_assigned_class' => $qAssignedClass,
        'page_unassigned' => $pageUnassigned,
        'page_assigned' => $pageAssigned,
    ];

    if ($action === 'regen_missing_auto') {
        try {
            $duplicateRows = checkDuplicateSBD($pdo, $examId);
            if (!empty($duplicateRows)) {
                exams_set_flash('error', 'Phát hiện trùng SBD. Vui lòng xử lý trước khi tự đánh số.');
                header('Location: ' . buildGenerateSbdUrl($redirectParams));
                exit;
            }

            $rowsStmt = $pdo->prepare('SELECT es.id, es.khoi, s.hoten
                FROM exam_students es
                INNER JOIN students s ON s.id = es.student_id
                WHERE es.exam_id = :exam_id AND es.subject_id IS NULL AND (es.sbd IS NULL OR trim(es.sbd) = "")');
            $rowsStmt->execute([':exam_id' => $examId]);
            $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

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
                exams_set_flash('warning', 'Không có học sinh nào chưa có SBD để tự đánh số.');
                header('Location: ' . buildGenerateSbdUrl($redirectParams));
                exit;
            }

            $pdo->beginTransaction();
            $updateBase = $pdo->prepare('UPDATE exam_students SET sbd = :sbd WHERE id = :id');
            $syncSubjectOne = $pdo->prepare('UPDATE exam_students SET sbd = :sbd WHERE exam_id = :exam_id AND student_id = :student_id AND subject_id IS NOT NULL');
            $generated = 0;
            foreach ($rows as $row) {
                $sbd = generateNextSBD($pdo, $examId);
                $updateBase->execute([':sbd' => $sbd, ':id' => (int) $row['id']]);
                $generated++;

                $studentIdStmt = $pdo->prepare('SELECT student_id FROM exam_students WHERE id = :id LIMIT 1');
                $studentIdStmt->execute([':id' => (int) $row['id']]);
                $studentId = (int) ($studentIdStmt->fetchColumn() ?: 0);
                if ($studentId > 0) {
                    $syncSubjectOne->execute([':sbd' => $sbd, ':exam_id' => $examId, ':student_id' => $studentId]);
                }
            }
            $pdo->commit();
            exams_set_flash('success', 'Đã tự đánh số SBD cho ' . $generated . ' học sinh chưa có SBD.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            exams_set_flash('error', 'Không thể tự đánh số SBD.');
        }

        header('Location: ' . buildGenerateSbdUrl($redirectParams));
        exit;
    }

    if ($action === 'assign_manual_sbd') {
        $selectedStudentIds = array_values(array_unique(array_map('intval', (array) ($_POST['selected_unassigned'] ?? []))));
        $manualInput = (array) ($_POST['manual_sbd'] ?? []);

        if (empty($selectedStudentIds)) {
            exams_set_flash('warning', 'Vui lòng chọn học sinh chưa có SBD để đánh số.');
            header('Location: ' . buildGenerateSbdUrl($redirectParams));
            exit;
        }

        $assignments = [];
        foreach ($selectedStudentIds as $sid) {
            $sbd = trim((string) ($manualInput[(string) $sid] ?? ''));
            if ($sbd === '') {
                continue;
            }
            if (!preg_match('/^\d+$/', $sbd)) {
                exams_set_flash('error', 'SBD nhập tay chỉ được chứa chữ số.');
                header('Location: ' . buildGenerateSbdUrl($redirectParams));
                exit;
            }
            $assignments[$sid] = $sbd;
        }

        if (empty($assignments)) {
            exams_set_flash('warning', 'Chưa nhập SBD cho các học sinh đã chọn.');
            header('Location: ' . buildGenerateSbdUrl($redirectParams));
            exit;
        }

        if (count($assignments) !== count(array_unique(array_values($assignments)))) {
            exams_set_flash('error', 'Danh sách SBD nhập tay bị trùng trong cùng lần gán.');
            header('Location: ' . buildGenerateSbdUrl($redirectParams));
            exit;
        }

        try {
            $pdo->beginTransaction();

            $dupCheck = $pdo->prepare('SELECT es.student_id
                FROM exam_students es
                WHERE es.exam_id = :exam_id AND es.subject_id IS NULL AND es.sbd = :sbd AND es.student_id <> :student_id
                LIMIT 1');
            $updateBase = $pdo->prepare('UPDATE exam_students SET sbd = :sbd WHERE exam_id = :exam_id AND subject_id IS NULL AND student_id = :student_id');
            $syncSubject = $pdo->prepare('UPDATE exam_students SET sbd = :sbd WHERE exam_id = :exam_id AND subject_id IS NOT NULL AND student_id = :student_id');

            $updated = 0;
            foreach ($assignments as $studentId => $sbd) {
                $dupCheck->execute([':exam_id' => $examId, ':sbd' => $sbd, ':student_id' => $studentId]);
                if ($dupCheck->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException('SBD ' . $sbd . ' đã tồn tại trong kỳ thi.');
                }

                $updateBase->execute([':sbd' => $sbd, ':exam_id' => $examId, ':student_id' => $studentId]);
                if ($updateBase->rowCount() > 0) {
                    $syncSubject->execute([':sbd' => $sbd, ':exam_id' => $examId, ':student_id' => $studentId]);
                    $updated++;
                }
            }

            $pdo->commit();
            exams_set_flash('success', 'Đã đánh số báo danh thủ công cho ' . $updated . ' học sinh.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            exams_set_flash('error', $e->getMessage());
        }

        header('Location: ' . buildGenerateSbdUrl($redirectParams));
        exit;
    }
}

$baseParams = [':exam_id' => $examId];
$unassignedWhere = ' WHERE es.exam_id = :exam_id AND es.subject_id IS NULL AND (es.sbd IS NULL OR trim(es.sbd) = "")';
if ($qUnassignedName !== '') {
    $unassignedWhere .= ' AND s.hoten LIKE :uq_name';
    $baseParams[':uq_name'] = '%' . $qUnassignedName . '%';
}
if ($qUnassignedClass !== '') {
    $unassignedWhere .= ' AND es.lop LIKE :uq_class';
    $baseParams[':uq_class'] = '%' . $qUnassignedClass . '%';
}

$assignedParams = [':exam_id' => $examId];
$assignedWhere = ' WHERE es.exam_id = :exam_id AND es.subject_id IS NULL AND es.sbd IS NOT NULL AND trim(es.sbd) <> ""';
if ($qAssignedName !== '') {
    $assignedWhere .= ' AND s.hoten LIKE :aq_name';
    $assignedParams[':aq_name'] = '%' . $qAssignedName . '%';
}
if ($qAssignedClass !== '') {
    $assignedWhere .= ' AND es.lop LIKE :aq_class';
    $assignedParams[':aq_class'] = '%' . $qAssignedClass . '%';
}

$countUnassigned = $pdo->prepare('SELECT COUNT(*) FROM exam_students es INNER JOIN students s ON s.id = es.student_id' . $unassignedWhere);
$countUnassigned->execute($baseParams);
$totalUnassigned = (int) $countUnassigned->fetchColumn();
$totalPagesUnassigned = max(1, (int) ceil($totalUnassigned / $perPage));
if ($pageUnassigned > $totalPagesUnassigned) {
    $pageUnassigned = $totalPagesUnassigned;
}

$countAssigned = $pdo->prepare('SELECT COUNT(*) FROM exam_students es INNER JOIN students s ON s.id = es.student_id' . $assignedWhere);
$countAssigned->execute($assignedParams);
$totalAssigned = (int) $countAssigned->fetchColumn();
$totalPagesAssigned = max(1, (int) ceil($totalAssigned / $perPage));
if ($pageAssigned > $totalPagesAssigned) {
    $pageAssigned = $totalPagesAssigned;
}

$offsetUnassigned = ($pageUnassigned - 1) * $perPage;
$listUnassigned = $pdo->prepare('SELECT es.student_id, es.khoi, es.lop, es.sbd, s.hoten, s.ngaysinh
    FROM exam_students es
    INNER JOIN students s ON s.id = es.student_id' . $unassignedWhere . '
    ORDER BY es.lop, s.hoten
    LIMIT :limit OFFSET :offset');
foreach ($baseParams as $k => $v) {
    $listUnassigned->bindValue($k, $v);
}
$listUnassigned->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listUnassigned->bindValue(':offset', $offsetUnassigned, PDO::PARAM_INT);
$listUnassigned->execute();
$unassignedRows = $listUnassigned->fetchAll(PDO::FETCH_ASSOC);

$offsetAssigned = ($pageAssigned - 1) * $perPage;
$listAssigned = $pdo->prepare('SELECT es.student_id, es.khoi, es.lop, es.sbd, s.hoten, s.ngaysinh
    FROM exam_students es
    INNER JOIN students s ON s.id = es.student_id' . $assignedWhere . '
    ORDER BY CAST(es.sbd AS INTEGER), s.hoten
    LIMIT :limit OFFSET :offset');
foreach ($assignedParams as $k => $v) {
    $listAssigned->bindValue($k, $v);
}
$listAssigned->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listAssigned->bindValue(':offset', $offsetAssigned, PDO::PARAM_INT);
$listAssigned->execute();
$assignedRows = $listAssigned->fetchAll(PDO::FETCH_ASSOC);

$baseQuery = [
    'exam_id' => $examId,
    'tab' => $tab,
    'q_unassigned_name' => $qUnassignedName,
    'q_unassigned_class' => $qUnassignedClass,
    'q_assigned_name' => $qAssignedName,
    'q_assigned_class' => $qAssignedClass,
    'page_unassigned' => $pageUnassigned,
    'page_assigned' => $pageAssigned,
];

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Sinh số báo danh</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>

                <form method="get" class="row g-2 mb-3" action="<?= BASE_URL ?>/modules/exams/generate_sbd.php">
                    <div class="col-md-6">
                        <?php if ($fixedExamContext): ?>
                            <input type="hidden" name="exam_id" value="<?= $examId ?>">
                            <div class="form-control bg-light">#<?= $examId ?> - Kỳ thi hiện tại</div>
                        <?php else: ?>
                            <select name="exam_id" class="form-select" required>
                                <option value="">-- Chọn kỳ thi --</option>
                                <?php foreach ($exams as $exam): ?>
                                    <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>#<?= (int) $exam['id'] ?> - <?= htmlspecialchars((string) $exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3"><button class="btn btn-primary" type="submit">Tải dữ liệu</button></div>
                </form>

                <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                    <a class="btn btn-outline-warning btn-sm" href="<?= BASE_URL ?>/modules/exams/check_duplicates.php?exam_id=<?= $examId ?>">🔎 Kiểm tra SBD trùng</a>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/modules/exams/export_duplicates.php?exam_id=<?= $examId ?>">📤 Xuất CSV lỗi SBD</a>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="regen_missing_auto">
                        <input type="hidden" name="tab" value="unassigned">
                        <button class="btn btn-success btn-sm" type="submit">⚙️ Tự đánh số cho học sinh chưa có SBD</button>
                    </form>
                </div>

                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item"><a class="nav-link <?= $tab === 'unassigned' ? 'active' : '' ?>" href="<?= buildGenerateSbdUrl(array_merge($baseQuery, ['tab' => 'unassigned'])) ?>">Chưa có SBD (<?= $totalUnassigned ?>)</a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab === 'assigned' ? 'active' : '' ?>" href="<?= buildGenerateSbdUrl(array_merge($baseQuery, ['tab' => 'assigned'])) ?>">Đã có SBD (<?= $totalAssigned ?>)</a></li>
                </ul>

                <?php if ($tab === 'unassigned'): ?>
                    <form method="get" class="row g-2 mb-3" action="<?= BASE_URL ?>/modules/exams/generate_sbd.php">
                        <input type="hidden" name="exam_id" value="<?= $examId ?>">
                        <input type="hidden" name="tab" value="unassigned">
                        <input type="hidden" name="page_assigned" value="<?= $pageAssigned ?>">
                        <div class="col-md-4"><input class="form-control" name="q_unassigned_name" value="<?= htmlspecialchars($qUnassignedName, ENT_QUOTES, 'UTF-8') ?>" placeholder="Tìm theo tên"></div>
                        <div class="col-md-3"><input class="form-control" name="q_unassigned_class" value="<?= htmlspecialchars($qUnassignedClass, ENT_QUOTES, 'UTF-8') ?>" placeholder="Lọc theo lớp"></div>
                        <div class="col-md-2"><button class="btn btn-outline-primary" type="submit">Lọc</button></div>
                    </form>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="assign_manual_sbd">
                        <input type="hidden" name="tab" value="unassigned">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle">
                                <thead><tr><th style="width:40px"><input type="checkbox" id="checkAllUnassigned"></th><th>STT</th><th>Họ tên</th><th>Ngày sinh</th><th>Lớp</th><th>Khối</th><th>SBD nhập tay</th></tr></thead>
                                <tbody>
                                <?php if (empty($unassignedRows)): ?>
                                    <tr><td colspan="7" class="text-center">Không có học sinh chưa được đánh số báo danh.</td></tr>
                                <?php else: foreach ($unassignedRows as $idx => $row): ?>
                                    <tr>
                                        <td><input class="unassigned-check" type="checkbox" name="selected_unassigned[]" value="<?= (int) $row['student_id'] ?>"></td>
                                        <td><?= ($pageUnassigned - 1) * $perPage + $idx + 1 ?></td>
                                        <td><?= htmlspecialchars((string) $row['hoten'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars(fmtDob((string) $row['ngaysinh']), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $row['lop'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $row['khoi'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><input class="form-control form-control-sm" name="manual_sbd[<?= (int) $row['student_id'] ?>]" placeholder="Nhập SBD"></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit" <?= empty($unassignedRows) ? 'disabled' : '' ?>>💾 Lưu SBD đã nhập cho học sinh đã chọn</button>
                    </form>

                    <?php if ($totalPagesUnassigned > 1):
                        $start = max(1, $pageUnassigned - 10);
                        $end = min($totalPagesUnassigned, $pageUnassigned + 10);
                        $pageLink = static fn(int $target): string => buildGenerateSbdUrl(array_merge($baseQuery, ['tab' => 'unassigned', 'page_unassigned' => $target]));
                    ?>
                        <nav class="mt-3"><ul class="pagination pagination-sm flex-wrap">
                            <li class="page-item <?= $pageUnassigned <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $pageUnassigned <= 1 ? '#' : $pageLink(1) ?>">Trang đầu</a></li>
                            <li class="page-item <?= $pageUnassigned <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $pageUnassigned <= 1 ? '#' : $pageLink($pageUnassigned - 1) ?>">Trang trước</a></li>
                            <?php for ($i = $start; $i <= $end; $i++): ?><li class="page-item <?= $i === $pageUnassigned ? 'active' : '' ?>"><a class="page-link" href="<?= $pageLink($i) ?>"><?= $i ?></a></li><?php endfor; ?>
                            <li class="page-item <?= $pageUnassigned >= $totalPagesUnassigned ? 'disabled' : '' ?>"><a class="page-link" href="<?= $pageUnassigned >= $totalPagesUnassigned ? '#' : $pageLink($pageUnassigned + 1) ?>">Trang sau</a></li>
                            <li class="page-item <?= $pageUnassigned >= $totalPagesUnassigned ? 'disabled' : '' ?>"><a class="page-link" href="<?= $pageUnassigned >= $totalPagesUnassigned ? '#' : $pageLink($totalPagesUnassigned) ?>">Trang cuối</a></li>
                        </ul></nav>
                    <?php endif; ?>
                <?php else: ?>
                    <form method="get" class="row g-2 mb-3" action="<?= BASE_URL ?>/modules/exams/generate_sbd.php">
                        <input type="hidden" name="exam_id" value="<?= $examId ?>">
                        <input type="hidden" name="tab" value="assigned">
                        <input type="hidden" name="page_unassigned" value="<?= $pageUnassigned ?>">
                        <div class="col-md-4"><input class="form-control" name="q_assigned_name" value="<?= htmlspecialchars($qAssignedName, ENT_QUOTES, 'UTF-8') ?>" placeholder="Tìm theo tên"></div>
                        <div class="col-md-3"><input class="form-control" name="q_assigned_class" value="<?= htmlspecialchars($qAssignedClass, ENT_QUOTES, 'UTF-8') ?>" placeholder="Lọc theo lớp"></div>
                        <div class="col-md-2"><button class="btn btn-outline-primary" type="submit">Lọc</button></div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead><tr><th>STT</th><th>Họ tên</th><th>Ngày sinh</th><th>Lớp</th><th>SBD</th></tr></thead>
                            <tbody>
                            <?php if (empty($assignedRows)): ?>
                                <tr><td colspan="5" class="text-center">Chưa có học sinh đã đánh số báo danh.</td></tr>
                            <?php else: foreach ($assignedRows as $idx => $row): ?>
                                <tr>
                                    <td><?= ($pageAssigned - 1) * $perPage + $idx + 1 ?></td>
                                    <td><?= htmlspecialchars((string) $row['hoten'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(fmtDob((string) $row['ngaysinh']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) $row['lop'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) $row['sbd'], ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPagesAssigned > 1):
                        $start = max(1, $pageAssigned - 10);
                        $end = min($totalPagesAssigned, $pageAssigned + 10);
                        $pageLink = static fn(int $target): string => buildGenerateSbdUrl(array_merge($baseQuery, ['tab' => 'assigned', 'page_assigned' => $target]));
                    ?>
                        <nav class="mt-3"><ul class="pagination pagination-sm flex-wrap">
                            <li class="page-item <?= $pageAssigned <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $pageAssigned <= 1 ? '#' : $pageLink(1) ?>">Trang đầu</a></li>
                            <li class="page-item <?= $pageAssigned <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $pageAssigned <= 1 ? '#' : $pageLink($pageAssigned - 1) ?>">Trang trước</a></li>
                            <?php for ($i = $start; $i <= $end; $i++): ?><li class="page-item <?= $i === $pageAssigned ? 'active' : '' ?>"><a class="page-link" href="<?= $pageLink($i) ?>"><?= $i ?></a></li><?php endfor; ?>
                            <li class="page-item <?= $pageAssigned >= $totalPagesAssigned ? 'disabled' : '' ?>"><a class="page-link" href="<?= $pageAssigned >= $totalPagesAssigned ? '#' : $pageLink($pageAssigned + 1) ?>">Trang sau</a></li>
                            <li class="page-item <?= $pageAssigned >= $totalPagesAssigned ? 'disabled' : '' ?>"><a class="page-link" href="<?= $pageAssigned >= $totalPagesAssigned ? '#' : $pageLink($totalPagesAssigned) ?>">Trang cuối</a></li>
                        </ul></nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
const checkAllUnassigned = document.getElementById('checkAllUnassigned');
if (checkAllUnassigned) {
    checkAllUnassigned.addEventListener('change', () => {
        document.querySelectorAll('.unassigned-check').forEach((cb) => {
            cb.checked = checkAllUnassigned.checked;
        });
    });
}
</script>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
