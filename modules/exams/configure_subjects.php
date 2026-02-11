<?php
declare(strict_types=1);

require_once __DIR__.'/_common.php';

$csrf = exams_get_csrf_token();
$exams = exams_get_all_exams($pdo);
$subjects = $pdo->query('SELECT id, ma_mon, ten_mon FROM subjects ORDER BY ten_mon')->fetchAll(PDO::FETCH_ASSOC);
$examId = max(0, (int) ($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        exams_set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: configure_subjects.php?exam_id=' . $examId);
        exit;
    }

    if ($examId <= 0) {
        exams_set_flash('error', 'Vui lòng chọn kỳ thi.');
        header('Location: configure_subjects.php');
        exit;
    }

    $baseReady = (int) $pdo->query('SELECT COUNT(*) FROM exam_students WHERE exam_id = ' . $examId . ' AND subject_id IS NULL')->fetchColumn();
    if ($baseReady <= 0) {
        exams_set_flash('warning', 'Cần hoàn thành bước gán học sinh trước.');
        header('Location: configure_subjects.php?exam_id=' . $examId);
        exit;
    }

    $action = (string) ($_POST['action'] ?? 'add');

    try {
        if ($action === 'delete') {
            $configId = (int) ($_POST['config_id'] ?? 0);
            $pdo->beginTransaction();
            $getCfg = $pdo->prepare('SELECT subject_id, khoi FROM exam_subject_config WHERE id = :id AND exam_id = :exam_id LIMIT 1');
            $getCfg->execute([':id' => $configId, ':exam_id' => $examId]);
            $cfg = $getCfg->fetch(PDO::FETCH_ASSOC);
            if ($cfg) {
                $delClass = $pdo->prepare('DELETE FROM exam_subject_classes WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi');
                $delClass->execute([
                    ':exam_id' => $examId,
                    ':subject_id' => (int) $cfg['subject_id'],
                    ':khoi' => (string) $cfg['khoi'],
                ]);
            }
            $delCfg = $pdo->prepare('DELETE FROM exam_subject_config WHERE id = :id AND exam_id = :exam_id');
            $delCfg->execute([':id' => $configId, ':exam_id' => $examId]);
            $pdo->commit();
            exams_set_flash('success', 'Đã xóa cấu hình môn.');
        } else {
            $khoi = trim((string) ($_POST['khoi'] ?? ''));
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            $hinhThucThi = trim((string) ($_POST['hinh_thuc_thi'] ?? 'single_component'));
            $weight1 = (float) ($_POST['weight_1'] ?? 1);
            $weight2 = (float) ($_POST['weight_2'] ?? 0);
            $scopeMode = trim((string) ($_POST['scope_mode'] ?? 'entire_grade'));
            $classesJson = (string) ($_POST['selected_classes_json'] ?? '[]');
            $selectedClasses = json_decode($classesJson, true);
            $selectedClasses = is_array($selectedClasses) ? array_values(array_unique(array_map('strval', $selectedClasses))) : [];

            if ($khoi === '') {
                throw new RuntimeException('Phải chọn khối.');
            }
            if ($subjectId <= 0) {
                throw new RuntimeException('Phải chọn môn học.');
            }
            if (!in_array($hinhThucThi, ['single_component', 'two_components'], true)) {
                throw new RuntimeException('Hình thức thi không hợp lệ.');
            }
            if (!in_array($scopeMode, ['entire_grade', 'specific_classes'], true)) {
                throw new RuntimeException('Phạm vi áp dụng không hợp lệ.');
            }

            $componentCount = $hinhThucThi === 'two_components' ? 2 : 1;
            if ($componentCount === 2) {
                if ($weight1 <= 0 || $weight2 <= 0) {
                    throw new RuntimeException('Môn 2 thành phần cần weight_1 và weight_2 > 0.');
                }
            } else {
                $weight1 = 1;
                $weight2 = 0;
            }

            $validClasses = getClassesByGrade($pdo, $examId, $khoi);
            if ($scopeMode === 'specific_classes') {
                if (empty($selectedClasses)) {
                    throw new RuntimeException('Chế độ lớp cụ thể cần chọn ít nhất 1 lớp.');
                }
                foreach ($selectedClasses as $className) {
                    if (!in_array($className, $validClasses, true)) {
                        throw new RuntimeException('Lớp ' . $className . ' không thuộc khối ' . $khoi . '.');
                    }
                }
            }

            $existsStmt = $pdo->prepare('SELECT id FROM exam_subject_config WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi LIMIT 1');
            $existsStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
            if ($existsStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Môn này đã được cấu hình cho khối đã chọn (không cho phép trùng).');
            }

            $pdo->beginTransaction();
            $insertCfg = $pdo->prepare('INSERT INTO exam_subject_config (exam_id, subject_id, khoi, hinh_thuc_thi, component_count, weight_1, weight_2, scope_mode)
                VALUES (:exam_id, :subject_id, :khoi, :hinh_thuc_thi, :component_count, :weight_1, :weight_2, :scope_mode)');
            $insertCfg->execute([
                ':exam_id' => $examId,
                ':subject_id' => $subjectId,
                ':khoi' => $khoi,
                ':hinh_thuc_thi' => $hinhThucThi,
                ':component_count' => $componentCount,
                ':weight_1' => $weight1,
                ':weight_2' => $weight2,
                ':scope_mode' => $scopeMode,
            ]);

            if ($scopeMode === 'specific_classes') {
                $insClass = $pdo->prepare('INSERT INTO exam_subject_classes (exam_id, subject_id, khoi, lop) VALUES (:exam_id, :subject_id, :khoi, :lop)');
                foreach ($selectedClasses as $className) {
                    $insClass->execute([
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

    header('Location: configure_subjects.php?exam_id=' . $examId);
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

    $cfgStmt = $pdo->prepare('SELECT c.id, c.khoi, c.subject_id, c.hinh_thuc_thi, c.component_count, c.weight_1, c.weight_2, c.scope_mode, s.ma_mon, s.ten_mon
        FROM exam_subject_config c
        INNER JOIN subjects s ON s.id = c.subject_id
        WHERE c.exam_id = :exam_id
        ORDER BY c.khoi, s.ten_mon');
    $cfgStmt->execute([':exam_id' => $examId]);
    $configRows = $cfgStmt->fetchAll(PDO::FETCH_ASSOC);

    $classListStmt = $pdo->prepare('SELECT exam_id, subject_id, khoi, lop FROM exam_subject_classes WHERE exam_id = :exam_id');
    $classListStmt->execute([':exam_id' => $examId]);
    $classRows = $classListStmt->fetchAll(PDO::FETCH_ASSOC);
    $classMap = [];
    foreach ($classRows as $row) {
        $key = (int) $row['subject_id'] . '|' . (string) $row['khoi'];
        $classMap[$key][] = (string) $row['lop'];
    }

    foreach ($configRows as &$cfg) {
        $key = (int) $cfg['subject_id'] . '|' . (string) $cfg['khoi'];
        $cfg['class_list'] = implode(', ', $classMap[$key] ?? []);
    }
    unset($cfg);
}

$wizard = $examId > 0 ? exams_wizard_steps($pdo, $examId) : [];

require_once __DIR__.'/../../layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Bước 4: Cấu hình môn theo khối (nâng cao)</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>

                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-6">
                        <select name="exam_id" class="form-select" required>
                            <option value="">-- Chọn kỳ thi --</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int) $exam['id'] ?>" <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>#<?= (int) $exam['id'] ?> - <?= htmlspecialchars((string)$exam['ten_ky_thi'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
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
                                <label class="form-label">Hình thức thi *</label>
                                <select class="form-select" name="hinh_thuc_thi" id="hinhThucThi">
                                    <option value="single_component">single_component</option>
                                    <option value="two_components">two_components</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Phạm vi áp dụng</label>
                                <select class="form-select" name="scope_mode" id="scopeMode">
                                    <option value="entire_grade">Toàn khối</option>
                                    <option value="specific_classes">Lớp cụ thể</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">weight_1</label>
                                <input class="form-control" type="number" step="0.01" min="0" name="weight_1" id="weight1" value="1">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">weight_2</label>
                                <input class="form-control" type="number" step="0.01" min="0" name="weight_2" id="weight2" value="0">
                            </div>
                        </div>

                        <div id="classScopePanel" class="mt-3" style="display:none;">
                            <h6>Chọn lớp áp dụng (Dual List)</h6>
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label">Danh sách lớp khả dụng</label>
                                    <select multiple class="form-select" id="availableClasses" size="8"></select>
                                </div>
                                <div class="col-md-2 d-flex flex-column justify-content-center gap-2">
                                    <button type="button" class="btn btn-outline-primary" id="addClassBtn">&gt;&gt;</button>
                                    <button type="button" class="btn btn-outline-secondary" id="removeClassBtn">&lt;&lt;</button>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Lớp đã chọn</label>
                                    <select multiple class="form-select" id="selectedClasses" size="8"></select>
                                </div>
                            </div>
                        </div>

                        <button class="btn btn-success mt-3" type="submit">Lưu cấu hình</button>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead><tr><th>Khối</th><th>Môn</th><th>Hình thức</th><th>Component</th><th>w1</th><th>w2</th><th>Scope</th><th>Lớp áp dụng</th><th></th></tr></thead>
                            <tbody>
                                <?php if (empty($configRows)): ?>
                                    <tr><td colspan="9" class="text-center">Chưa có cấu hình.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($configRows as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)$row['khoi'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$row['ma_mon'].' - '.(string)$row['ten_mon'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$row['hinh_thuc_thi'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= (int)$row['component_count'] ?></td>
                                            <td><?= htmlspecialchars((string)$row['weight_1'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$row['weight_2'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)$row['scope_mode'], ENT_QUOTES, 'UTF-8') ?></td>
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
const classScopePanel = document.getElementById('classScopePanel');
const availableClasses = document.getElementById('availableClasses');
const selectedClasses = document.getElementById('selectedClasses');
const addClassBtn = document.getElementById('addClassBtn');
const removeClassBtn = document.getElementById('removeClassBtn');
const selectedClassesJson = document.getElementById('selectedClassesJson');
const hinhThucThi = document.getElementById('hinhThucThi');
const weight1 = document.getElementById('weight1');
const weight2 = document.getElementById('weight2');

function refreshDualList() {
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

    selectedClassesJson.value = JSON.stringify(selected);
}

function moveSelected(from, to) {
    const options = Array.from(from.selectedOptions);
    options.forEach(opt => {
        const exists = Array.from(to.options).some(o => o.value === opt.value);
        if (!exists) {
            const newOpt = document.createElement('option');
            newOpt.value = opt.value;
            newOpt.textContent = opt.textContent;
            to.appendChild(newOpt);
        }
        opt.remove();
    });
    refreshDualList();
}

function toggleScopePanel() {
    classScopePanel.style.display = scopeMode.value === 'specific_classes' ? 'block' : 'none';
}

function toggleWeightState() {
    const twoComp = hinhThucThi.value === 'two_components';
    weight2.disabled = !twoComp;
    if (!twoComp) {
        weight2.value = '0';
        if (parseFloat(weight1.value || '0') <= 0) weight1.value = '1';
    }
}

khoiSelect?.addEventListener('change', refreshDualList);
scopeMode?.addEventListener('change', toggleScopePanel);
addClassBtn?.addEventListener('click', () => moveSelected(availableClasses, selectedClasses));
removeClassBtn?.addEventListener('click', () => moveSelected(selectedClasses, availableClasses));
hinhThucThi?.addEventListener('change', toggleWeightState);

document.getElementById('cfgForm')?.addEventListener('submit', function () {
    refreshDualList();
});

toggleScopePanel();
toggleWeightState();
refreshDualList();
</script>

<?php require_once __DIR__.'/../../layout/footer.php'; ?>
