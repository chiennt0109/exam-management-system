<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';

require_once BASE_PATH . '/modules/exams/_common.php';

// Lightweight migration for new score-structure fields (only if missing)
$existingCols = array_column($pdo->query('PRAGMA table_info(exam_subject_config)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$addColumns = [
    'tong_diem' => 'REAL',
    'diem_tu_luan' => 'REAL',
    'diem_trac_nghiem' => 'REAL',
    'diem_noi' => 'REAL',
];
foreach ($addColumns as $col => $type) {
    if (!in_array($col, $existingCols, true)) {
        $pdo->exec('ALTER TABLE exam_subject_config ADD COLUMN ' . $col . ' ' . $type);
    }
}

$csrf = exams_get_csrf_token();
$exams = exams_get_all_exams($pdo);
$subjects = $pdo->query('SELECT id, ma_mon, ten_mon FROM subjects ORDER BY ten_mon')->fetchAll(PDO::FETCH_ASSOC);
$examId = exams_resolve_current_exam_from_request();
if ($examId <= 0) {
    exams_set_flash('warning', 'Vui lòng chọn kỳ thi hiện tại trước khi thao tác.');
    header('Location: ' . BASE_URL . '/modules/exams/index.php');
    exit;
}
$fixedExamContext = getCurrentExamId() > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        exams_set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: ' . BASE_URL . '/modules/exams/configure_subjects.php?exam_id=' . $examId);
        exit;
    }

    exams_assert_exam_unlocked_for_write($pdo, $examId);

    if ($examId <= 0) {
        exams_set_flash('error', 'Vui lòng chọn kỳ thi.');
        header('Location: ' . BASE_URL . '/modules/exams/configure_subjects.php');
        exit;
    }
    if (exams_is_locked($pdo, $examId)) {
        exams_set_flash('error', 'Kỳ thi đã khoá phân phòng, không thể sửa cấu hình môn.');
        header('Location: ' . BASE_URL . '/modules/exams/configure_subjects.php');
        exit;
    }

    $baseReady = (int) $pdo->query('SELECT COUNT(*) FROM exam_students WHERE exam_id = ' . $examId . ' AND subject_id IS NULL')->fetchColumn();
    if ($baseReady <= 0) {
        exams_set_flash('warning', 'Cần hoàn thành bước gán học sinh trước.');
        header('Location: ' . BASE_URL . '/modules/exams/configure_subjects.php?exam_id=' . $examId);
        exit;
    }

    $action = (string) ($_POST['action'] ?? 'add');

    try {
        if ($action === 'delete') {
            $configId = (int) ($_POST['config_id'] ?? 0);
            $pdo->beginTransaction();
            $delClass = $pdo->prepare('DELETE FROM exam_subject_classes WHERE exam_config_id = :config_id');
            $delClass->execute([':config_id' => $configId]);
            $delCfg = $pdo->prepare('DELETE FROM exam_subject_config WHERE id = :id AND exam_id = :exam_id');
            $delCfg->execute([':id' => $configId, ':exam_id' => $examId]);
            $pdo->commit();
            exams_set_flash('success', 'Đã xóa cấu hình môn.');
        } else {
            $khoi = trim((string) ($_POST['khoi'] ?? ''));
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            $scopeMode = trim((string) ($_POST['scope_mode'] ?? ''));
            $componentCount = (int) ($_POST['component_count'] ?? 1);

            $tongDiemRaw = trim((string) ($_POST['tong_diem'] ?? ''));
            $diemTuLuanRaw = trim((string) ($_POST['diem_tu_luan'] ?? ''));
            $diemTracNghiemRaw = trim((string) ($_POST['diem_trac_nghiem'] ?? ''));
            $diemNoiRaw = trim((string) ($_POST['diem_noi'] ?? ''));

            $tongDiem = $tongDiemRaw === '' ? 0.0 : (float) $tongDiemRaw;
            $diemTuLuan = $diemTuLuanRaw === '' ? 0.0 : (float) $diemTuLuanRaw;
            $diemTracNghiem = $diemTracNghiemRaw === '' ? 0.0 : (float) $diemTracNghiemRaw;
            $diemNoi = $diemNoiRaw === '' ? 0.0 : (float) $diemNoiRaw;

            $hinhThucThi = match ($componentCount) {
                1 => 'single_component',
                2, 3 => 'two_components',
                default => '',
            };

            $classesJson = (string) ($_POST['selected_classes_json'] ?? '[]');
            $selectedClasses = json_decode($classesJson, true);
            $selectedClasses = is_array($selectedClasses) ? array_values(array_unique(array_map('strval', $selectedClasses))) : [];

            if ($khoi === '') {
                throw new RuntimeException('Phải chọn khối.');
            }
            if ($subjectId <= 0) {
                throw new RuntimeException('Phải chọn môn học.');
            }
            if (!in_array($scopeMode, ['entire_grade', 'specific_classes'], true)) {
                throw new RuntimeException('Phải chọn phạm vi áp dụng.');
            }
            if (!in_array($componentCount, [1, 2, 3], true)) {
                throw new RuntimeException('Số thành phần điểm không hợp lệ.');
            }

            // Score validation: all must be <= 10 and > 0 where used
            if ($tongDiem <= 0 || $tongDiem > 10) {
                throw new RuntimeException('Tổng điểm phải > 0 và <= 10.');
            }

            $sum = 0.0;
            if ($componentCount === 1) {
                if ($diemTuLuanRaw === '') {
                    throw new RuntimeException('Vui lòng nhập đủ 1 thành phần điểm đã chọn.');
                }
                if ($diemTuLuan <= 0 || $diemTuLuan > 10) {
                    throw new RuntimeException('Điểm thành phần phải > 0 và <= 10.');
                }
                $diemTracNghiem = 0;
                $diemNoi = 0;
                $sum = $diemTuLuan;
            } elseif ($componentCount === 2) {
                if ($diemTuLuanRaw === '' || $diemTracNghiemRaw === '') {
                    throw new RuntimeException('Vui lòng nhập đủ 2 thành phần điểm đã chọn.');
                }
                if ($diemTuLuan <= 0 || $diemTuLuan > 10 || $diemTracNghiem <= 0 || $diemTracNghiem > 10) {
                    throw new RuntimeException('Điểm Tự luận/Trắc nghiệm phải > 0 và <= 10.');
                }
                $diemNoi = 0;
                $sum = $diemTuLuan + $diemTracNghiem;
            } else {
                if ($diemTuLuanRaw === '' || $diemTracNghiemRaw === '' || $diemNoiRaw === '') {
                    throw new RuntimeException('Vui lòng nhập đủ 3 thành phần điểm đã chọn.');
                }
                if (
                    $diemTuLuan <= 0 || $diemTuLuan > 10 ||
                    $diemTracNghiem <= 0 || $diemTracNghiem > 10 ||
                    $diemNoi <= 0 || $diemNoi > 10
                ) {
                    throw new RuntimeException('Điểm Tự luận/Trắc nghiệm/Nói phải > 0 và <= 10.');
                }
                $sum = $diemTuLuan + $diemTracNghiem + $diemNoi;
            }

            if (abs($sum - $tongDiem) > 0.0001) {
                throw new RuntimeException('Tổng điểm phải bằng tổng các thành phần chi tiết.');
            }

            $validClasses = getClassesByGrade($pdo, $examId, $khoi);
            if ($scopeMode === 'specific_classes') {
                if (empty($selectedClasses)) {
                    throw new RuntimeException('Phạm vi lớp cụ thể phải chọn ít nhất 1 lớp.');
                }
                foreach ($selectedClasses as $className) {
                    if (!in_array($className, $validClasses, true)) {
                        throw new RuntimeException('Lớp ' . $className . ' không thuộc khối ' . $khoi . '.');
                    }
                }
            }

            // Duplicate rule: only reject when new config is identical 100% to an existing record
            $existingStmt = $pdo->prepare('SELECT id, scope_mode FROM exam_subject_config WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi ORDER BY id DESC');
            $existingStmt->execute([
                ':exam_id' => $examId,
                ':subject_id' => $subjectId,
                ':khoi' => $khoi,
            ]);
            $existingRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);

            $newClassesForCompare = $selectedClasses;
            sort($newClassesForCompare);
            $newScopeSignature = $scopeMode === 'specific_classes'
                ? 'specific_classes|' . implode(',', $newClassesForCompare)
                : 'entire_grade';

            foreach ($existingRows as $existingCfg) {
                $existingScopeMode = (string) ($existingCfg['scope_mode'] ?? 'entire_grade');
                $oldScopeSignature = 'entire_grade';

                if ($existingScopeMode === 'specific_classes') {
                    $oldClassStmt = $pdo->prepare('SELECT lop FROM exam_subject_classes WHERE exam_config_id = :config_id ORDER BY lop');
                    $oldClassStmt->execute([':config_id' => (int) ($existingCfg['id'] ?? 0)]);
                    $oldClasses = array_map(static fn(array $r): string => (string) $r['lop'], $oldClassStmt->fetchAll(PDO::FETCH_ASSOC));
                    $oldScopeSignature = 'specific_classes|' . implode(',', $oldClasses);
                }

                if ($oldScopeSignature === $newScopeSignature) {
                    throw new RuntimeException('Cấu hình đã tồn tại 100% cho kỳ thi + môn + khối + phạm vi.');
                }
            }

            $pdo->beginTransaction();
            $insertCfg = $pdo->prepare('INSERT INTO exam_subject_config (
                    exam_id, subject_id, khoi, hinh_thuc_thi, component_count, weight_1, weight_2, scope_mode,
                    tong_diem, diem_tu_luan, diem_trac_nghiem, diem_noi
                ) VALUES (
                    :exam_id, :subject_id, :khoi, :hinh_thuc_thi, :component_count, :weight_1, :weight_2, :scope_mode,
                    :tong_diem, :diem_tu_luan, :diem_trac_nghiem, :diem_noi
                )');
            $insertCfg->execute([
                ':exam_id' => $examId,
                ':subject_id' => $subjectId,
                ':khoi' => $khoi,
                ':hinh_thuc_thi' => $hinhThucThi,
                ':component_count' => $componentCount,
                ':weight_1' => $componentCount >= 1 ? $diemTuLuan : null,
                ':weight_2' => $componentCount >= 2 ? $diemTracNghiem : null,
                ':scope_mode' => $scopeMode,
                ':tong_diem' => $tongDiem,
                ':diem_tu_luan' => $componentCount >= 1 ? $diemTuLuan : null,
                ':diem_trac_nghiem' => $componentCount >= 2 ? $diemTracNghiem : null,
                ':diem_noi' => $componentCount >= 3 ? $diemNoi : null,
            ]);
            $newConfigId = (int) $pdo->lastInsertId();

            if ($scopeMode === 'specific_classes') {
                $insClass = $pdo->prepare('INSERT INTO exam_subject_classes (exam_config_id, exam_id, subject_id, khoi, lop) VALUES (:exam_config_id, :exam_id, :subject_id, :khoi, :lop)');
                foreach ($selectedClasses as $className) {
                    $insClass->execute([
                        ':exam_config_id' => $newConfigId,
                        ':exam_id' => $examId,
                        ':subject_id' => $subjectId,
                        ':khoi' => $khoi,
                        ':lop' => $className,
                    ]);
                }
            }

            $pdo->commit();
            exams_set_flash('success', 'Đã lưu cấu hình môn học theo khối.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        exams_set_flash('error', $e->getMessage());
    }

    header('Location: ' . BASE_URL . '/modules/exams/configure_subjects.php?exam_id=' . $examId);
    exit;
}

$grades = [];
$configRows = [];
$classesByGrade = [];
if ($examId > 0) {
    $gradeStmt = $pdo->prepare('SELECT DISTINCT khoi FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NULL AND khoi IS NOT NULL AND khoi <> "" ORDER BY khoi');
    $gradeStmt->execute([':exam_id' => $examId]);
    $grades = array_map(fn($r) => (string) $r['khoi'], $gradeStmt->fetchAll(PDO::FETCH_ASSOC));

    foreach ($grades as $g) {
        $classesByGrade[$g] = getClassesByGrade($pdo, $examId, $g);
    }

    $cfgStmt = $pdo->prepare('SELECT c.id, c.khoi, c.subject_id, c.scope_mode, c.component_count, c.tong_diem, c.diem_tu_luan, c.diem_trac_nghiem, c.diem_noi, s.ma_mon, s.ten_mon
        FROM exam_subject_config c
        INNER JOIN subjects s ON s.id = c.subject_id
        WHERE c.exam_id = :exam_id
        ORDER BY c.khoi, s.ten_mon');
    $cfgStmt->execute([':exam_id' => $examId]);
    $configRows = $cfgStmt->fetchAll(PDO::FETCH_ASSOC);

    $classListStmt = $pdo->prepare('SELECT exam_config_id, lop FROM exam_subject_classes WHERE exam_id = :exam_id');
    $classListStmt->execute([':exam_id' => $examId]);
    $classRows = $classListStmt->fetchAll(PDO::FETCH_ASSOC);
    $classMap = [];
    foreach ($classRows as $row) {
        $key = (int) ($row['exam_config_id'] ?? 0);
        $classMap[$key][] = (string) $row['lop'];
    }

    foreach ($configRows as &$cfg) {
        $key = (int) $cfg['id'];
        $cfg['class_list'] = implode(', ', $classMap[$key] ?? []);
    }
    unset($cfg);
}

$wizard = $examId > 0 ? exams_wizard_steps($pdo, $examId) : [];

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Bước 4: Cấu hình môn theo khối</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>

                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-6">
                        <?php if ($fixedExamContext): ?><input type="hidden" name="exam_id" value="<?= $examId ?>"><div class="form-control bg-light">#<?= $examId ?> - Kỳ thi hiện tại</div><?php else: ?><select name="exam_id" class="form-select" required>
                            <option value="">-- Chọn kỳ thi --</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>#<?= (int) $exam['id'] ?> - <?= htmlspecialchars((string)$exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></option>
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

                    <form method="post" class="border rounded p-3 mb-3" id="cfgForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="exam_id" value="<?= $examId ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="selected_classes_json" id="selectedClassesJson" value="[]">

                        <div class="row g-2">
                            <div class="col-md-2">
                                <label class="form-label">Khối *</label>
                                <select class="form-select" name="khoi" id="khoiSelect" required>
                                    <option value="">-- Chọn --</option>
                                    <?php foreach ($grades as $grade): ?>
                                        <option value="<?= htmlspecialchars($grade, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($grade, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Môn học *</label>
                                <select class="form-select" name="subject_id" required>
                                    <option value="">-- Chọn môn --</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?= (int) $subject['id'] ?>"><?= htmlspecialchars((string)$subject['ma_mon'].' - '.(string)$subject['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Số thành phần điểm</label>
                                <select class="form-select" name="component_count" id="componentCount">
                                    <option value="1">1 thành phần điểm</option>
                                    <option value="2">2 thành phần điểm</option>
                                    <option value="3">3 thành phần điểm</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Phạm vi áp dụng *</label>
                                <select class="form-select" name="scope_mode" id="scopeMode" required>
                                    <option value="">-- Chọn phạm vi --</option>
                                    <option value="entire_grade">Toàn khối</option>
                                    <option value="specific_classes">Lớp cụ thể</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Tổng điểm tối đa</label>
                                <input class="form-control" type="number" step="0.01" min="0.01" max="10" name="tong_diem" id="tongDiem" required>
                            </div>
                            <div class="col-md-3" id="fieldTuLuan">
                                <label class="form-label">Tự luận</label>
                                <input class="form-control" type="number" step="0.01" min="0.01" max="10" name="diem_tu_luan" id="diemTuLuan" required>
                            </div>
                            <div class="col-md-3" id="fieldTracNghiem" style="display:none;">
                                <label class="form-label">Trắc nghiệm</label>
                                <input class="form-control" type="number" step="0.01" min="0.01" max="10" name="diem_trac_nghiem" id="diemTracNghiem" value="">
                            </div>
                            <div class="col-md-3" id="fieldNoi" style="display:none;">
                                <label class="form-label">Nói</label>
                                <input class="form-control" type="number" step="0.01" min="0.01" max="10" name="diem_noi" id="diemNoi" value="">
                            </div>
                        </div>

                        <div id="classScopePanel" class="mt-3" style="display:none;">
                            <h6>Chọn lớp áp dụng (Dual Window)</h6>
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label">Danh sách lớp khả dụng trong khối</label>
                                    <select multiple class="form-select" id="availableClasses" size="8"></select>
                                </div>
                                <div class="col-md-2 d-flex flex-column justify-content-center gap-2">
                                    <button type="button" class="btn btn-outline-primary" id="moveOneRight">&gt;</button>
                                    <button type="button" class="btn btn-outline-primary" id="moveAllRight">&gt;&gt;</button>
                                    <button type="button" class="btn btn-outline-secondary" id="moveOneLeft">&lt;</button>
                                    <button type="button" class="btn btn-outline-secondary" id="moveAllLeft">&lt;&lt;</button>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Danh sách lớp đã chọn</label>
                                    <select multiple class="form-select" id="selectedClasses" size="8"></select>
                                </div>
                            </div>
                        </div>

                        <button class="btn btn-success mt-3" type="submit">Lưu cấu hình</button>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead><tr><th>Khối</th><th>Môn</th><th>Scope</th><th>Số TP</th><th>Tổng</th><th>Tự luận</th><th>Trắc nghiệm</th><th>Nói</th><th>Lớp áp dụng</th><th></th></tr></thead>
                            <tbody>
                                <?php if (empty($configRows)): ?>
                                    <tr><td colspan="10" class="text-center">Chưa có cấu hình.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($configRows as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)$row['khoi'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$row['ma_mon'].' - '.(string)$row['ten_mon'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$row['scope_mode'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= (int)$row['component_count'] ?></td>
                                            <td><?= htmlspecialchars((string)$row['tong_diem'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$row['diem_tu_luan'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$row['diem_trac_nghiem'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$row['diem_noi'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)($row['class_list'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <form method="post" onsubmit="return confirm('Xóa cấu hình này?')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="exam_id" value="<?= $examId ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="config_id" value="<?= (int)$row['id'] ?>">
                                                    <button class="btn btn-sm btn-danger">Xóa</button>
                                                </form>
                                            </td>
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

<script>
const classesByGrade = <?= json_encode($classesByGrade, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const khoiSelect = document.getElementById('khoiSelect');
const scopeMode = document.getElementById('scopeMode');
const componentCount = document.getElementById('componentCount');
const classScopePanel = document.getElementById('classScopePanel');
const availableClasses = document.getElementById('availableClasses');
const selectedClasses = document.getElementById('selectedClasses');
const selectedClassesJson = document.getElementById('selectedClassesJson');

function refreshAvailableByGrade() {
    const khoi = khoiSelect.value;
    const selected = Array.from(selectedClasses.options).map(o => o.value);
    availableClasses.innerHTML = '';
    (classesByGrade[khoi] || []).forEach(cls => {
        if (!selected.includes(cls)) {
            const op = document.createElement('option');
            op.value = cls;
            op.textContent = cls;
            availableClasses.appendChild(op);
        }
    });
    syncSelectedClassesJson();
}

function syncSelectedClassesJson() {
    const selected = Array.from(selectedClasses.options).map(o => o.value);
    selectedClassesJson.value = JSON.stringify(selected);
}

function moveSelected(from, to) {
    const opts = Array.from(from.selectedOptions);
    opts.forEach(opt => {
        const exists = Array.from(to.options).some(o => o.value === opt.value);
        if (!exists) {
            const n = document.createElement('option');
            n.value = opt.value;
            n.textContent = opt.textContent;
            to.appendChild(n);
        }
        opt.remove();
    });
    syncSelectedClassesJson();
}

function moveAll(from, to) {
    Array.from(from.options).forEach(opt => opt.selected = true);
    moveSelected(from, to);
}

function updateScopePanel() {
    classScopePanel.style.display = scopeMode.value === 'specific_classes' ? 'block' : 'none';
    if (scopeMode.value !== 'specific_classes') {
        selectedClasses.innerHTML = '';
        syncSelectedClassesJson();
        refreshAvailableByGrade();
    }
}

function updateComponentFields() {
    const count = parseInt(componentCount.value, 10);
    const diemTracNghiem = document.getElementById('diemTracNghiem');
    const diemNoi = document.getElementById('diemNoi');

    document.getElementById('fieldTuLuan').style.display = 'block';
    document.getElementById('fieldTracNghiem').style.display = count >= 2 ? 'block' : 'none';
    document.getElementById('fieldNoi').style.display = count >= 3 ? 'block' : 'none';

    diemTracNghiem.required = count >= 2;
    diemNoi.required = count >= 3;

    // Disable unused score fields so browser does not validate hidden inputs.
    diemTracNghiem.disabled = count < 2;
    diemNoi.disabled = count < 3;

    if (count < 2) {
        diemTracNghiem.value = '';
    }
    if (count < 3) {
        diemNoi.value = '';
    }
}

khoiSelect?.addEventListener('change', refreshAvailableByGrade);
scopeMode?.addEventListener('change', updateScopePanel);
componentCount?.addEventListener('change', updateComponentFields);

document.getElementById('moveOneRight')?.addEventListener('click', () => moveSelected(availableClasses, selectedClasses));
document.getElementById('moveAllRight')?.addEventListener('click', () => moveAll(availableClasses, selectedClasses));
document.getElementById('moveOneLeft')?.addEventListener('click', () => moveSelected(selectedClasses, availableClasses));
document.getElementById('moveAllLeft')?.addEventListener('click', () => moveAll(selectedClasses, availableClasses));

document.getElementById('cfgForm')?.addEventListener('submit', function () {
    syncSelectedClassesJson();
});

updateComponentFields();
updateScopePanel();
refreshAvailableByGrade();
</script>

<?php require_once BASE_PATH . '/layout/footer.php'; ?>
