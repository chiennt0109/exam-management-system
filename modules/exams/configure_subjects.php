<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';

$examId = exams_resolve_current_exam_from_request();
if ($examId <= 0) {
    exams_set_flash('warning', 'Chưa có kỳ thi mặc định. Vui lòng chọn kỳ thi mặc định trước khi thao tác.');
    header('Location: ' . BASE_URL . '/modules/exams/index.php');
    exit;
}

// Migrations for step 4 extension
$examCols = array_column($pdo->query('PRAGMA table_info(exams)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('exam_mode', $examCols, true)) {
    $pdo->exec('ALTER TABLE exams ADD COLUMN exam_mode INTEGER DEFAULT 1');
}

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

$pdo->exec('CREATE TABLE IF NOT EXISTS exam_subjects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exam_id INTEGER NOT NULL,
    subject_id INTEGER NOT NULL,
    sort_order INTEGER NOT NULL,
    UNIQUE(exam_id, subject_id)
)');
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_exam_subjects_order ON exam_subjects(exam_id, sort_order)');
$pdo->exec('CREATE TABLE IF NOT EXISTS exam_student_subjects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exam_id INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    subject_id INTEGER NOT NULL,
    UNIQUE(exam_id, student_id, subject_id)
)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_exam_student_subjects_exam_student ON exam_student_subjects(exam_id, student_id)');

$csrf = exams_get_csrf_token();
$subjects = $pdo->query('SELECT id, ma_mon, ten_mon FROM subjects ORDER BY ten_mon')->fetchAll(PDO::FETCH_ASSOC);
$examRowStmt = $pdo->prepare('SELECT id, ten_ky_thi, exam_mode FROM exams WHERE id = :id LIMIT 1');
$examRowStmt->execute([':id' => $examId]);
$examRow = $examRowStmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $examId, 'ten_ky_thi' => '', 'exam_mode' => 1];
$examMode = in_array((int)($examRow['exam_mode'] ?? 1), [1, 2], true) ? (int)$examRow['exam_mode'] : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        exams_set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: ' . BASE_URL . '/modules/exams/configure_subjects.php');
        exit;
    }

    exams_assert_exam_unlocked_for_write($pdo, $examId);

    if (exams_is_locked($pdo, $examId)) {
        exams_set_flash('error', 'Kỳ thi đã khoá phân phòng, không thể sửa cấu hình môn.');
        header('Location: ' . BASE_URL . '/modules/exams/configure_subjects.php');
        exit;
    }

    $baseReady = (int) $pdo->query('SELECT COUNT(*) FROM exam_students WHERE exam_id = ' . $examId . ' AND subject_id IS NULL')->fetchColumn();
    if ($baseReady <= 0) {
        exams_set_flash('warning', 'Cần hoàn thành bước gán học sinh trước.');
        header('Location: ' . BASE_URL . '/modules/exams/configure_subjects.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? 'add');

    try {
        if ($action === 'set_exam_mode') {
            $mode = (int) ($_POST['exam_mode'] ?? 1);
            if (!in_array($mode, [1, 2], true)) {
                throw new RuntimeException('Chế độ kỳ thi không hợp lệ.');
            }
            $pdo->prepare('UPDATE exams SET exam_mode = :mode WHERE id = :id')->execute([':mode' => $mode, ':id' => $examId]);
            exams_set_flash('success', 'Đã cập nhật chế độ kỳ thi.');
        } elseif ($action === 'add_matrix_subject') {
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            if ($subjectId <= 0) {
                throw new RuntimeException('Phải chọn môn học.');
            }
            $maxSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM exam_subjects WHERE exam_id = ' . $examId)->fetchColumn();
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO exam_subjects(exam_id, subject_id, sort_order) VALUES(:exam_id, :subject_id, :sort_order)');
            $stmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':sort_order' => $maxSort + 1]);
            exams_set_flash('success', 'Đã thêm môn vào ma trận.');
        } elseif ($action === 'save_matrix_subject_config') {
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            $componentCount = (int) ($_POST['component_count'] ?? 1);
            $tongDiem = (float) ($_POST['tong_diem'] ?? 10);
            $diemTuLuan = (float) ($_POST['diem_tu_luan'] ?? 10);
            $diemTracNghiem = (float) ($_POST['diem_trac_nghiem'] ?? 0);
            $diemNoi = (float) ($_POST['diem_noi'] ?? 0);

            if ($subjectId <= 0 || !in_array($componentCount, [1, 2, 3], true)) {
                throw new RuntimeException('Cấu hình thành phần điểm không hợp lệ.');
            }
            if ($tongDiem <= 0 || $tongDiem > 10) {
                throw new RuntimeException('Tổng điểm phải > 0 và <= 10.');
            }
            if ($diemTuLuan < 0 || $diemTuLuan > 10 || $diemTracNghiem < 0 || $diemTracNghiem > 10 || $diemNoi < 0 || $diemNoi > 10) {
                throw new RuntimeException('Điểm thành phần phải trong khoảng 0..10.');
            }

            $sum = $diemTuLuan + ($componentCount >= 2 ? $diemTracNghiem : 0) + ($componentCount >= 3 ? $diemNoi : 0);
            if ($sum > $tongDiem) {
                throw new RuntimeException('Tổng điểm thành phần vượt tổng điểm.');
            }

            $hinhThucThi = match ($componentCount) {
                1 => 'single_component',
                2, 3 => 'two_components',
                default => 'single_component',
            };

            $existing = $pdo->prepare('SELECT id FROM exam_subject_config WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi LIMIT 1');
            $existing->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => 'ALL']);
            $cfgId = (int) ($existing->fetchColumn() ?: 0);

            if ($cfgId > 0) {
                $pdo->prepare('UPDATE exam_subject_config SET hinh_thuc_thi = :hinh_thuc_thi, component_count = :component_count, scope_mode = :scope_mode, tong_diem = :tong_diem, diem_tu_luan = :diem_tu_luan, diem_trac_nghiem = :diem_trac_nghiem, diem_noi = :diem_noi WHERE id = :id AND exam_id = :exam_id')
                    ->execute([
                        ':hinh_thuc_thi' => $hinhThucThi,
                        ':component_count' => $componentCount,
                        ':scope_mode' => 'entire_grade',
                        ':tong_diem' => $tongDiem,
                        ':diem_tu_luan' => $diemTuLuan,
                        ':diem_trac_nghiem' => $componentCount >= 2 ? $diemTracNghiem : 0,
                        ':diem_noi' => $componentCount >= 3 ? $diemNoi : 0,
                        ':id' => $cfgId,
                        ':exam_id' => $examId,
                    ]);
            } else {
                $pdo->prepare('INSERT INTO exam_subject_config (exam_id, subject_id, khoi, hinh_thuc_thi, component_count, weight_1, weight_2, scope_mode, tong_diem, diem_tu_luan, diem_trac_nghiem, diem_noi) VALUES (:exam_id, :subject_id, :khoi, :hinh_thuc_thi, :component_count, NULL, NULL, :scope_mode, :tong_diem, :diem_tu_luan, :diem_trac_nghiem, :diem_noi)')
                    ->execute([
                        ':exam_id' => $examId,
                        ':subject_id' => $subjectId,
                        ':khoi' => 'ALL',
                        ':hinh_thuc_thi' => $hinhThucThi,
                        ':component_count' => $componentCount,
                        ':scope_mode' => 'entire_grade',
                        ':tong_diem' => $tongDiem,
                        ':diem_tu_luan' => $diemTuLuan,
                        ':diem_trac_nghiem' => $componentCount >= 2 ? $diemTracNghiem : 0,
                        ':diem_noi' => $componentCount >= 3 ? $diemNoi : 0,
                    ]);
            }
            exams_set_flash('success', 'Đã lưu cấu hình thành phần điểm cho môn.');
        } elseif ($action === 'remove_matrix_subject') {
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            if ($subjectId <= 0) {
                throw new RuntimeException('Môn học không hợp lệ.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM exam_subjects WHERE exam_id = :exam_id AND subject_id = :subject_id')
                ->execute([':exam_id' => $examId, ':subject_id' => $subjectId]);
            $pdo->prepare('DELETE FROM exam_student_subjects WHERE exam_id = :exam_id AND subject_id = :subject_id')
                ->execute([':exam_id' => $examId, ':subject_id' => $subjectId]);
            $pdo->commit();
            exams_set_flash('success', 'Đã xóa môn khỏi ma trận.');
        } elseif ($action === 'move_matrix_subject') {
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            $direction = (string) ($_POST['direction'] ?? 'up');
            if ($subjectId <= 0 || !in_array($direction, ['up', 'down'], true)) {
                throw new RuntimeException('Thao tác sắp xếp không hợp lệ.');
            }
            $rows = $pdo->prepare('SELECT subject_id, sort_order FROM exam_subjects WHERE exam_id = :exam_id ORDER BY sort_order ASC');
            $rows->execute([':exam_id' => $examId]);
            $list = $rows->fetchAll(PDO::FETCH_ASSOC);
            $idx = null;
            foreach ($list as $i => $r) {
                if ((int)$r['subject_id'] === $subjectId) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx !== null) {
                $swapIdx = $direction === 'up' ? $idx - 1 : $idx + 1;
                if (isset($list[$swapIdx])) {
                    $a = $list[$idx];
                    $b = $list[$swapIdx];
                    $tmpSort = -1;
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE exam_subjects SET sort_order = :sort WHERE exam_id = :exam_id AND subject_id = :subject_id')
                        ->execute([':sort' => $tmpSort, ':exam_id' => $examId, ':subject_id' => (int) $a['subject_id']]);
                    $pdo->prepare('UPDATE exam_subjects SET sort_order = :sort WHERE exam_id = :exam_id AND subject_id = :subject_id')
                        ->execute([':sort' => (int) $a['sort_order'], ':exam_id' => $examId, ':subject_id' => (int) $b['subject_id']]);
                    $pdo->prepare('UPDATE exam_subjects SET sort_order = :sort WHERE exam_id = :exam_id AND subject_id = :subject_id')
                        ->execute([':sort' => (int) $b['sort_order'], ':exam_id' => $examId, ':subject_id' => (int) $a['subject_id']]);
                    $pdo->commit();
                }
            }
            exams_set_flash('success', 'Đã cập nhật thứ tự môn.');
        } elseif ($action === 'delete') {
            $configId = (int) ($_POST['config_id'] ?? 0);
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM exam_subject_classes WHERE exam_config_id = :config_id')->execute([':config_id' => $configId]);
            $pdo->prepare('DELETE FROM exam_subject_config WHERE id = :id AND exam_id = :exam_id')->execute([':id' => $configId, ':exam_id' => $examId]);
            $pdo->commit();
            exams_set_flash('success', 'Đã xóa cấu hình môn.');
        } else {
            // Legacy mode 1 config
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
            $hinhThucThi = match ($componentCount) {1 => 'single_component', 2, 3 => 'two_components', default => ''};

            $classesJson = (string) ($_POST['selected_classes_json'] ?? '[]');
            $selectedClasses = json_decode($classesJson, true);
            $selectedClasses = is_array($selectedClasses) ? array_values(array_unique(array_map('strval', $selectedClasses))) : [];

            if ($khoi === '' || $subjectId <= 0 || !in_array($scopeMode, ['entire_grade', 'specific_classes'], true) || !in_array($componentCount, [1, 2, 3], true)) {
                throw new RuntimeException('Thông tin cấu hình không hợp lệ.');
            }
            if ($tongDiem <= 0 || $tongDiem > 10) {
                throw new RuntimeException('Tổng điểm phải > 0 và <= 10.');
            }
            $sum = $diemTuLuan + ($componentCount >= 2 ? $diemTracNghiem : 0) + ($componentCount >= 3 ? $diemNoi : 0);
            if ($sum <= 0 || $sum > $tongDiem) {
                throw new RuntimeException('Tổng thành phần điểm không hợp lệ.');
            }
            if ($scopeMode === 'specific_classes' && empty($selectedClasses)) {
                throw new RuntimeException('Phải chọn ít nhất 1 lớp cho phạm vi theo lớp.');
            }

            $pdo->beginTransaction();
            $existingStmt = $pdo->prepare('SELECT id, scope_mode FROM exam_subject_config WHERE exam_id = :exam_id AND subject_id = :subject_id AND khoi = :khoi ORDER BY id DESC');
            $existingStmt->execute([':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi]);
            $existingCfg = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($existingCfg) {
                $cfgId = (int) $existingCfg['id'];
                $pdo->prepare('UPDATE exam_subject_config
                    SET hinh_thuc_thi = :hinh_thuc_thi, component_count = :component_count, scope_mode = :scope_mode,
                        tong_diem = :tong_diem, diem_tu_luan = :diem_tu_luan, diem_trac_nghiem = :diem_trac_nghiem, diem_noi = :diem_noi
                    WHERE id = :id AND exam_id = :exam_id')
                    ->execute([
                        ':hinh_thuc_thi' => $hinhThucThi,
                        ':component_count' => $componentCount,
                        ':scope_mode' => $scopeMode,
                        ':tong_diem' => $tongDiem,
                        ':diem_tu_luan' => $diemTuLuan,
                        ':diem_trac_nghiem' => $componentCount >= 2 ? $diemTracNghiem : 0,
                        ':diem_noi' => $componentCount >= 3 ? $diemNoi : 0,
                        ':id' => $cfgId,
                        ':exam_id' => $examId,
                    ]);
            } else {
                $ins = $pdo->prepare('INSERT INTO exam_subject_config (
                    exam_id, subject_id, khoi, hinh_thuc_thi, component_count, weight_1, weight_2, scope_mode,
                    tong_diem, diem_tu_luan, diem_trac_nghiem, diem_noi
                ) VALUES (
                    :exam_id, :subject_id, :khoi, :hinh_thuc_thi, :component_count, :weight_1, :weight_2, :scope_mode,
                    :tong_diem, :diem_tu_luan, :diem_trac_nghiem, :diem_noi
                )');
                $ins->execute([
                    ':exam_id' => $examId,
                    ':subject_id' => $subjectId,
                    ':khoi' => $khoi,
                    ':hinh_thuc_thi' => $hinhThucThi,
                    ':component_count' => $componentCount,
                    ':weight_1' => null,
                    ':weight_2' => null,
                    ':scope_mode' => $scopeMode,
                    ':tong_diem' => $tongDiem,
                    ':diem_tu_luan' => $diemTuLuan,
                    ':diem_trac_nghiem' => $componentCount >= 2 ? $diemTracNghiem : 0,
                    ':diem_noi' => $componentCount >= 3 ? $diemNoi : 0,
                ]);
                $cfgId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM exam_subject_classes WHERE exam_config_id = :config_id')->execute([':config_id' => $cfgId]);
            if ($scopeMode === 'specific_classes') {
                $insClass = $pdo->prepare('INSERT INTO exam_subject_classes (exam_config_id, exam_id, subject_id, khoi, lop) VALUES (:exam_config_id, :exam_id, :subject_id, :khoi, :lop)');
                foreach ($selectedClasses as $lop) {
                    $insClass->execute([':exam_config_id' => $cfgId, ':exam_id' => $examId, ':subject_id' => $subjectId, ':khoi' => $khoi, ':lop' => $lop]);
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

    header('Location: ' . BASE_URL . '/modules/exams/configure_subjects.php');
    exit;
}

$grades = [];
$configRows = [];
$classesByGrade = [];
$gradeStmt = $pdo->prepare('SELECT DISTINCT khoi FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NULL AND khoi IS NOT NULL AND khoi <> "" ORDER BY khoi');
$gradeStmt->execute([':exam_id' => $examId]);
$grades = array_map(static fn($r): string => (string) $r['khoi'], $gradeStmt->fetchAll(PDO::FETCH_ASSOC));
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
    $cfg['class_list'] = implode(', ', $classMap[(int)$cfg['id']] ?? []);
}
unset($cfg);

$matrixSubjectStmt = $pdo->prepare('SELECT es.subject_id, es.sort_order, s.ma_mon, s.ten_mon
    FROM exam_subjects es
    INNER JOIN subjects s ON s.id = es.subject_id
    WHERE es.exam_id = :exam_id
    ORDER BY es.sort_order ASC, es.id ASC');
$matrixSubjectStmt->execute([':exam_id' => $examId]);
$matrixSubjects = $matrixSubjectStmt->fetchAll(PDO::FETCH_ASSOC);

$matrixScoreConfig = [];
$matrixCfgStmt = $pdo->prepare('SELECT subject_id, component_count, tong_diem, diem_tu_luan, diem_trac_nghiem, diem_noi FROM exam_subject_config WHERE exam_id = :exam_id AND khoi = :khoi');
$matrixCfgStmt->execute([':exam_id' => $examId, ':khoi' => 'ALL']);
foreach ($matrixCfgStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $sid = (int) ($r['subject_id'] ?? 0);
    if ($sid > 0) {
        $matrixScoreConfig[$sid] = [
            'component_count' => max(1, min(3, (int) ($r['component_count'] ?? 1))),
            'tong_diem' => (float) ($r['tong_diem'] ?? 10),
            'diem_tu_luan' => (float) ($r['diem_tu_luan'] ?? 10),
            'diem_trac_nghiem' => (float) ($r['diem_trac_nghiem'] ?? 0),
            'diem_noi' => (float) ($r['diem_noi'] ?? 0),
        ];
    }
}

$filterClass = trim((string) ($_GET['class'] ?? ''));
$filterSearch = trim((string) ($_GET['search'] ?? ''));
$perPageOptions = [26, 50, 100, 200, 500];
$perPage = (int) ($_GET['per_page'] ?? 26);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 26;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$baseStudentSql = ' FROM exam_students es
    INNER JOIN students st ON st.id = es.student_id
    WHERE es.exam_id = :exam_id AND es.subject_id IS NULL';
$params = [':exam_id' => $examId];
if ($filterClass !== '') {
    $baseStudentSql .= ' AND st.lop = :lop';
    $params[':lop'] = $filterClass;
}
if ($filterSearch !== '') {
    $baseStudentSql .= ' AND lower(st.hoten) LIKE :kw';
    $params[':kw'] = '%' . mb_strtolower($filterSearch) . '%';
}

$allStudentSql = 'SELECT st.id, st.hoten, st.ngaysinh, st.lop' . $baseStudentSql;
$allStudentStmt = $pdo->prepare($allStudentSql);
foreach ($params as $k => $v) {
    $allStudentStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$allStudentStmt->execute();
$allStudentsInExam = $allStudentStmt->fetchAll(PDO::FETCH_ASSOC);

$extractSortTokens = static function (string $fullName): array {
    $normalized = trim(mb_strtolower($fullName));
    if ($normalized === '') {
        return ['', ''];
    }

    $parts = preg_split('/\s+/u', $normalized) ?: [];
    $firstFromRight = (string) ($parts[count($parts) - 1] ?? '');

    return [$firstFromRight, $normalized];
};

usort($allStudentsInExam, static function (array $a, array $b) use ($extractSortTokens): int {
    [$firstA, $fullA] = $extractSortTokens((string) ($a['hoten'] ?? ''));
    [$firstB, $fullB] = $extractSortTokens((string) ($b['hoten'] ?? ''));

    if ($firstA !== $firstB) {
        return $firstA <=> $firstB;
    }
    if ($fullA !== $fullB) {
        return $fullA <=> $fullB;
    }

    return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
});

$totalStudents = count($allStudentsInExam);
$totalPages = max(1, (int) ceil($totalStudents / max(1, $perPage)));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$configureUrl = BASE_URL . '/modules/exams/configure_subjects.php';
$studentsInExam = array_slice($allStudentsInExam, $offset, $perPage);

$selectedStmt = $pdo->prepare('SELECT student_id, subject_id FROM exam_student_subjects WHERE exam_id = :exam_id');
$selectedStmt->execute([':exam_id' => $examId]);
$selectedRows = $selectedStmt->fetchAll(PDO::FETCH_ASSOC);
$selectedMap = [];
foreach ($selectedRows as $row) {
    $selectedMap[(int)$row['student_id']][(int)$row['subject_id']] = true;
}

$classOptionsStmt = $pdo->prepare('SELECT DISTINCT lop FROM students WHERE id IN (SELECT student_id FROM exam_students WHERE exam_id = :exam_id AND subject_id IS NULL) AND lop IS NOT NULL AND trim(lop) <> "" ORDER BY lop');
$classOptionsStmt->execute([':exam_id' => $examId]);
$classOptions = array_map(static fn($r): string => (string)$r['lop'], $classOptionsStmt->fetchAll(PDO::FETCH_ASSOC));

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

                <form method="post" class="row g-2 mb-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="set_exam_mode">
                    <div class="col-md-4">
                        <label class="form-label">Chế độ kỳ thi</label>
                        <select class="form-select" name="exam_mode">
                            <option value="1" <?= $examMode === 1 ? 'selected' : '' ?>>1 - Kiểm tra định kỳ</option>
                            <option value="2" <?= $examMode === 2 ? 'selected' : '' ?>>2 - Tốt nghiệp THPT</option>
                        </select>
                    </div>
                    <div class="col-md-3 align-self-end"><button class="btn btn-outline-primary" type="submit">Lưu chế độ</button></div>
                </form>

                <?php if ($examMode === 1): ?>
                    <form method="post" class="border rounded p-3 mb-3" id="cfgForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="selected_classes_json" id="selectedClassesJson" value="[]">
                        <div class="row g-2">
                            <div class="col-md-2"><label class="form-label">Khối *</label><select class="form-select" name="khoi" id="khoiSelect" required><option value="">-- Chọn --</option><?php foreach ($grades as $grade): ?><option value="<?= htmlspecialchars($grade, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($grade, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-4"><label class="form-label">Môn học *</label><select class="form-select" name="subject_id" required><option value="">-- Chọn môn --</option><?php foreach ($subjects as $s): ?><option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars((string)$s['ma_mon'].' - '.(string)$s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-3"><label class="form-label">Phạm vi</label><select class="form-select" name="scope_mode" id="scopeMode" required><option value="entire_grade">Toàn khối</option><option value="specific_classes">Theo lớp</option></select></div>
                            <div class="col-md-3"><label class="form-label">Số thành phần điểm</label><select class="form-select" name="component_count" id="componentCount"><option value="1">1</option><option value="2">2</option><option value="3">3</option></select></div>
                            <div class="col-md-3"><label class="form-label">Tổng điểm</label><input class="form-control" type="number" step="0.01" min="0" max="10" name="tong_diem" required></div>
                            <div class="col-md-3" id="fieldTuLuan"><label class="form-label">Điểm tự luận</label><input class="form-control" type="number" step="0.01" min="0" max="10" name="diem_tu_luan" id="diemTuLuan" required></div>
                            <div class="col-md-3" id="fieldTracNghiem"><label class="form-label">Điểm trắc nghiệm</label><input class="form-control" type="number" step="0.01" min="0" max="10" name="diem_trac_nghiem" id="diemTracNghiem"></div>
                            <div class="col-md-3" id="fieldNoi"><label class="form-label">Điểm nói</label><input class="form-control" type="number" step="0.01" min="0" max="10" name="diem_noi" id="diemNoi"></div>
                        </div>
                        <div id="classScopePanel" class="border rounded p-2 mt-3" style="display:none;">
                            <div class="row g-2">
                                <div class="col-md-5"><label class="form-label">Danh sách lớp trong khối</label><select multiple class="form-select" id="availableClasses" size="8"></select></div>
                                <div class="col-md-2 d-flex flex-column justify-content-center gap-2"><button type="button" class="btn btn-outline-primary" id="moveOneRight">&gt;</button><button type="button" class="btn btn-outline-primary" id="moveAllRight">&gt;&gt;</button><button type="button" class="btn btn-outline-secondary" id="moveOneLeft">&lt;</button><button type="button" class="btn btn-outline-secondary" id="moveAllLeft">&lt;&lt;</button></div>
                                <div class="col-md-5"><label class="form-label">Danh sách lớp đã chọn</label><select multiple class="form-select" id="selectedClasses" size="8"></select></div>
                            </div>
                        </div>
                        <button class="btn btn-success mt-3" type="submit">Lưu cấu hình</button>
                    </form>
                    <div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th>Khối</th><th>Môn</th><th>Scope</th><th>Số TP</th><th>Tổng</th><th>Tự luận</th><th>Trắc nghiệm</th><th>Nói</th><th>Lớp áp dụng</th><th></th></tr></thead><tbody><?php if (empty($configRows)): ?><tr><td colspan="10" class="text-center">Chưa có cấu hình.</td></tr><?php else: foreach ($configRows as $row): ?><tr><td><?= htmlspecialchars((string)$row['khoi'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)$row['ma_mon'].' - '.(string)$row['ten_mon'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)$row['scope_mode'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int)$row['component_count'] ?></td><td><?= htmlspecialchars((string)$row['tong_diem'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)$row['diem_tu_luan'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)$row['diem_trac_nghiem'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)$row['diem_noi'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)($row['class_list'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td><td><form method="post" onsubmit="return confirm('Xóa cấu hình này?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="config_id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-danger">Xóa</button></form></td></tr><?php endforeach; endif; ?></tbody></table></div>
                <?php else: ?>
                    <div class="border rounded p-3 mb-3">
                        <h6 class="mb-3">Danh sách môn trong ma trận</h6>
                        <form method="post" class="row g-2 mb-2">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="add_matrix_subject">
                            <div class="col-md-8">
                                <select class="form-select" name="subject_id" required>
                                    <option value="">-- Chọn môn để thêm --</option>
                                    <?php foreach ($subjects as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars((string) $s['ma_mon'] . ' - ' . (string) $s['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4"><button class="btn btn-primary" type="submit">Thêm môn</button></div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle">
                                <thead>
                                    <tr><th>STT</th><th>Môn</th><th>Cấu hình thành phần điểm</th><th width="220">Thao tác</th></tr>
                                </thead>
                                <tbody>
                                <?php if (empty($matrixSubjects)): ?>
                                    <tr><td colspan="4" class="text-center">Chưa có môn trong ma trận.</td></tr>
                                <?php else: foreach ($matrixSubjects as $i => $ms):
                                    $sid = (int) $ms['subject_id'];
                                    $cfg = $matrixScoreConfig[$sid] ?? ['component_count' => 1, 'tong_diem' => 10, 'diem_tu_luan' => 10, 'diem_trac_nghiem' => 0, 'diem_noi' => 0];
                                ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td><?= htmlspecialchars((string) $ms['ma_mon'] . ' - ' . (string) $ms['ten_mon'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <form method="post" class="row g-1 align-items-end">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="save_matrix_subject_config">
                                                <input type="hidden" name="subject_id" value="<?= $sid ?>">
                                                <div class="col-md-2"><label class="form-label mb-0 small">TP</label><select class="form-select form-select-sm" name="component_count"><option value="1" <?= (int)$cfg['component_count']===1?'selected':'' ?>>1</option><option value="2" <?= (int)$cfg['component_count']===2?'selected':'' ?>>2</option><option value="3" <?= (int)$cfg['component_count']===3?'selected':'' ?>>3</option></select></div>
                                                <div class="col-md-2"><label class="form-label mb-0 small">Tổng</label><input class="form-control form-control-sm" type="number" step="0.01" min="0" max="10" name="tong_diem" value="<?= htmlspecialchars((string)$cfg['tong_diem'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                                <div class="col-md-2"><label class="form-label mb-0 small">TL</label><input class="form-control form-control-sm" type="number" step="0.01" min="0" max="10" name="diem_tu_luan" value="<?= htmlspecialchars((string)$cfg['diem_tu_luan'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                                <div class="col-md-3"><label class="form-label mb-0 small">TN</label><input class="form-control form-control-sm" type="number" step="0.01" min="0" max="10" name="diem_trac_nghiem" value="<?= htmlspecialchars((string)$cfg['diem_trac_nghiem'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                                <div class="col-md-2"><label class="form-label mb-0 small">Nói</label><input class="form-control form-control-sm" type="number" step="0.01" min="0" max="10" name="diem_noi" value="<?= htmlspecialchars((string)$cfg['diem_noi'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                                <div class="col-md-1"><button class="btn btn-sm btn-success" type="submit">Lưu</button></div>
                                            </form>
                                        </td>
                                        <td class="d-flex gap-1">
                                            <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="move_matrix_subject"><input type="hidden" name="subject_id" value="<?= $sid ?>"><input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-outline-secondary">↑</button></form>
                                            <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="move_matrix_subject"><input type="hidden" name="subject_id" value="<?= $sid ?>"><input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-outline-secondary">↓</button></form>
                                            <form method="post" onsubmit="return confirm('Xóa môn khỏi ma trận?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="remove_matrix_subject"><input type="hidden" name="subject_id" value="<?= $sid ?>"><button class="btn btn-sm btn-outline-danger">Xóa</button></form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="border rounded p-3">
                        <h6 class="mb-3">Ma trận chọn môn theo học sinh</h6>
                        <form method="get" action="<?= $configureUrl ?>" class="row g-2 mb-3">
                            <input type="hidden" name="exam_id" value="<?= (int) $examId ?>">
                            <div class="col-md-3">
                                <select class="form-select" name="class">
                                    <option value="">-- Lọc theo lớp --</option>
                                    <?php foreach ($classOptions as $lop): ?>
                                        <option value="<?= htmlspecialchars($lop, ENT_QUOTES, 'UTF-8') ?>" <?= $filterClass === $lop ? 'selected' : '' ?>><?= htmlspecialchars($lop, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4"><input class="form-control" type="text" name="search" value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Tìm theo tên học sinh"></div>
                            <div class="col-md-2">
                                <select class="form-select" name="per_page">
                                    <?php foreach ($perPageOptions as $opt): ?>
                                        <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?> / trang</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex gap-2"><button class="btn btn-outline-primary" type="submit">Lọc</button><a class="btn btn-outline-secondary" href="<?= $configureUrl . "?" . http_build_query(['exam_id' => $examId]) ?>">Bỏ lọc</a></div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-bordered table-sm matrix">
                                <thead>
                                <tr>
                                    <th>STT</th>
                                    <th>Họ tên</th>
                                    <th>Ngày sinh</th>
                                    <th>Lớp</th>
                                    <?php foreach ($matrixSubjects as $sub): ?>
                                        <th>
                                            <div class="small mb-1"><?= htmlspecialchars((string) $sub['ten_mon'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <input type="checkbox" class="matrix-col-toggle" data-subject="<?= (int) $sub['subject_id'] ?>" title="Chọn/Bỏ chọn cả cột trong trang hiện tại">
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($studentsInExam)): ?>
                                    <tr><td colspan="<?= max(4, count($matrixSubjects) + 4) ?>" class="text-center">Không có học sinh.</td></tr>
                                <?php else: foreach ($studentsInExam as $idx => $stu): ?>
                                    <tr>
                                        <td><?= $offset + $idx + 1 ?></td>
                                        <td><?= htmlspecialchars((string) $stu['hoten'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?php $dob = (string) ($stu['ngaysinh'] ?? ''); $tsDob = strtotime($dob); echo htmlspecialchars($tsDob ? date('d/m/Y', $tsDob) : $dob, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?= htmlspecialchars((string) $stu['lop'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <?php foreach ($matrixSubjects as $sub): $sid = (int) $stu['id']; $subId = (int) $sub['subject_id']; $isChecked = !empty($selectedMap[$sid][$subId]); ?>
                                            <td class="text-center"><input type="checkbox" class="matrix-checkbox" data-student="<?= $sid ?>" data-subject="<?= $subId ?>" <?= $isChecked ? 'checked' : '' ?>></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <?php
                                $windowStart = max(1, $page - 10);
                                $windowEnd = min($totalPages, $page + 10);
                                $pageLink = static fn(int $target): string => $configureUrl . '?' . http_build_query([
                                    'exam_id' => $examId,
                                    'class' => $filterClass,
                                    'search' => $filterSearch,
                                    'per_page' => $perPage,
                                    'page' => $target,
                                ]);
                            ?>
                            <nav>
                                <ul class="pagination pagination-sm flex-wrap">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <?php if ($page <= 1): ?>
                                            <span class="page-link">Trang đầu</span>
                                        <?php else: ?>
                                            <a class="page-link" href="<?= $pageLink(1) ?>">Trang đầu</a>
                                        <?php endif; ?>
                                    </li>
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <?php if ($page <= 1): ?>
                                            <span class="page-link">Trang trước</span>
                                        <?php else: ?>
                                            <a class="page-link" href="<?= $pageLink($page - 1) ?>">Trang trước</a>
                                        <?php endif; ?>
                                    </li>

                                    <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= $pageLink($p) ?>"><?= $p ?></a>
                                        </li>
                                    <?php endfor; ?>

                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                        <?php if ($page >= $totalPages): ?>
                                            <span class="page-link">Trang sau</span>
                                        <?php else: ?>
                                            <a class="page-link" href="<?= $pageLink($page + 1) ?>">Trang sau</a>
                                        <?php endif; ?>
                                    </li>
                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                        <?php if ($page >= $totalPages): ?>
                                            <span class="page-link">Trang cuối</span>
                                        <?php else: ?>
                                            <a class="page-link" href="<?= $pageLink($totalPages) ?>">Trang cuối</a>
                                        <?php endif; ?>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>

                        <div class="small text-muted mt-2">Mỗi lần tick/bỏ tick sẽ lưu ngay qua AJAX. Chức năng chọn cả cột áp dụng trên trang hiện tại.</div>
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

function refreshAvailableByGrade(){ if(!khoiSelect||!availableClasses||!selectedClasses) return; const khoi=khoiSelect.value; const selected=Array.from(selectedClasses.options).map(o=>o.value); availableClasses.innerHTML=''; (classesByGrade[khoi]||[]).forEach(cls=>{ if(!selected.includes(cls)){ const op=document.createElement('option'); op.value=cls; op.textContent=cls; availableClasses.appendChild(op);} }); syncSelectedClassesJson(); }
function syncSelectedClassesJson(){ if(!selectedClassesJson||!selectedClasses) return; selectedClassesJson.value = JSON.stringify(Array.from(selectedClasses.options).map(o=>o.value)); }
function moveSelected(from,to){ if(!from||!to) return; Array.from(from.selectedOptions).forEach(opt=>{ const exists=Array.from(to.options).some(o=>o.value===opt.value); if(!exists){ const n=document.createElement('option'); n.value=opt.value; n.textContent=opt.textContent; to.appendChild(n);} opt.remove();}); syncSelectedClassesJson(); }
function moveAll(from,to){ if(!from) return; Array.from(from.options).forEach(opt=>opt.selected=true); moveSelected(from,to); }
function updateScopePanel(){ if(!scopeMode||!classScopePanel) return; classScopePanel.style.display = scopeMode.value === 'specific_classes' ? 'block' : 'none'; if (scopeMode.value !== 'specific_classes' && selectedClasses){ selectedClasses.innerHTML=''; syncSelectedClassesJson(); refreshAvailableByGrade(); }}
function updateComponentFields(){ if(!componentCount) return; const c=parseInt(componentCount.value||'1',10); const tn=document.getElementById('diemTracNghiem'); const no=document.getElementById('diemNoi'); const f2=document.getElementById('fieldTracNghiem'); const f3=document.getElementById('fieldNoi'); if(f2) f2.style.display=c>=2?'block':'none'; if(f3) f3.style.display=c>=3?'block':'none'; if(tn){ tn.required=c>=2; tn.disabled=c<2; if(c<2) tn.value=''; } if(no){ no.required=c>=3; no.disabled=c<3; if(c<3) no.value=''; }}

khoiSelect?.addEventListener('change', refreshAvailableByGrade);
scopeMode?.addEventListener('change', updateScopePanel);
componentCount?.addEventListener('change', updateComponentFields);
document.getElementById('moveOneRight')?.addEventListener('click', ()=>moveSelected(availableClasses, selectedClasses));
document.getElementById('moveAllRight')?.addEventListener('click', ()=>moveAll(availableClasses, selectedClasses));
document.getElementById('moveOneLeft')?.addEventListener('click', ()=>moveSelected(selectedClasses, availableClasses));
document.getElementById('moveAllLeft')?.addEventListener('click', ()=>moveAll(selectedClasses, availableClasses));
document.getElementById('cfgForm')?.addEventListener('submit', syncSelectedClassesJson);
updateComponentFields(); updateScopePanel(); refreshAvailableByGrade();

async function postMatrix(payload) {
    const res = await fetch('<?= BASE_URL ?>/modules/exams/save_exam_matrix.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body: payload.toString()
    });
    return res.json();
}

document.querySelectorAll('.matrix-checkbox').forEach(cb => {
    cb.addEventListener('change', async function () {
        const payload = new URLSearchParams();
        payload.set('csrf_token', <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>);
        payload.set('action', 'toggle');
        payload.set('exam_id', <?= (string) $examId ?>);
        payload.set('student_id', String(this.dataset.student || '0'));
        payload.set('subject_id', String(this.dataset.subject || '0'));
        payload.set('checked', this.checked ? '1' : '0');

        try {
            const data = await postMatrix(payload);
            if (!data || data.ok !== true) {
                this.checked = !this.checked;
                alert((data && data.error) ? data.error : 'Không lưu được dữ liệu.');
            }
        } catch (e) {
            this.checked = !this.checked;
            alert('Lỗi kết nối khi lưu ma trận môn.');
        }
    });
});

document.querySelectorAll('.matrix-col-toggle').forEach(colToggle => {
    colToggle.addEventListener('change', async function () {
        const subjectId = this.dataset.subject || '0';
        const checked = this.checked;
        const rowInputs = Array.from(document.querySelectorAll('.matrix-checkbox[data-subject="' + subjectId + '"]'));
        const studentIds = rowInputs.map(i => i.dataset.student || '0').filter(v => v !== '0');
        if (studentIds.length === 0) {
            return;
        }

        const payload = new URLSearchParams();
        payload.set('csrf_token', <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>);
        payload.set('action', 'toggle_column');
        payload.set('exam_id', <?= (string) $examId ?>);
        payload.set('subject_id', subjectId);
        payload.set('checked', checked ? '1' : '0');
        payload.set('student_ids', studentIds.join(','));

        try {
            const data = await postMatrix(payload);
            if (!data || data.ok !== true) {
                this.checked = !this.checked;
                alert((data && data.error) ? data.error : 'Không lưu được dữ liệu cột.');
                return;
            }
            rowInputs.forEach(i => { i.checked = checked; });
        } catch (e) {
            this.checked = !this.checked;
            alert('Lỗi kết nối khi lưu dữ liệu cột.');
        }
    });
});
</script>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
