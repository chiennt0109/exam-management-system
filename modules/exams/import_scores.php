<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_once BASE_PATH . '/modules/exams/score_utils.php';
require_role(['admin', 'organizer', 'scorer']);

$autoloadCandidates = [
    BASE_PATH . '/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];
foreach ($autoloadCandidates as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

$canUseSpreadsheet = class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class);

$csrf = exams_get_csrf_token();
$examId = exams_require_current_exam_or_redirect('/modules/exams/index.php');
$errors = [];
$success = null;

$roomsStmt = $pdo->prepare('SELECT r.id, r.ten_phong, r.khoi, s.ten_mon, r.subject_id
    FROM rooms r
    INNER JOIN subjects s ON s.id = r.subject_id
    WHERE r.exam_id = :exam_id
    ORDER BY s.ten_mon, r.ten_phong');
$roomsStmt->execute([':exam_id' => $examId]);
$rooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);

$subjectsStmt = $pdo->prepare('SELECT DISTINCT s.id, s.ten_mon
    FROM exam_student_subjects ess
    INNER JOIN subjects s ON s.id = ess.subject_id
    WHERE ess.exam_id = :exam_id
    ORDER BY s.ten_mon');
$subjectsStmt->execute([':exam_id' => $examId]);
$subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);

if (!isset($_SESSION['score_import_state']) || !is_array($_SESSION['score_import_state'])) {
    $_SESSION['score_import_state'] = [];
}
$state = (array) $_SESSION['score_import_state'];

$mode = (string) ($_POST['mode'] ?? ($state['mode'] ?? 'room'));
$mode = in_array($mode, ['room', 'subject'], true) ? $mode : 'room';
$roomId = max(0, (int) ($_POST['room_id'] ?? ($state['room_id'] ?? 0)));
$subjectId = max(0, (int) ($_POST['subject_id'] ?? ($state['subject_id'] ?? 0)));
$selectedSbdColumn = (string) ($_POST['sbd_column'] ?? ($state['sbd_column'] ?? ''));
$selectedScoreColumn = (string) ($_POST['score_column'] ?? ($state['score_column'] ?? ''));

/**
 * @return array<int, string>
 */
function excel_column_letters(string $highestColumn): array
{
    $maxColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
    $columns = [];
    for ($index = 1; $index <= $maxColIndex; $index++) {
        $columns[] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }

    return $columns;
}

/**
 * @param array<string, string> $headers
 */
function column_label(string $column, array $headers): string
{
    $header = trim((string) ($headers[$column] ?? ''));
    return $header === '' ? $column : ($column . ' - ' . $header);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }

    $action = (string) ($_POST['action'] ?? '');

    if (!$canUseSpreadsheet) {
        $errors[] = 'Không tìm thấy PHPSpreadsheet. Vui lòng cài đặt vendor/autoload.php.';
    }

    if (empty($errors) && $action === 'upload_file') {
        if (empty($_FILES['excel_file']['tmp_name']) || !is_uploaded_file($_FILES['excel_file']['tmp_name'])) {
            $errors[] = 'Vui lòng chọn tệp Excel hợp lệ.';
        } else {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load((string) $_FILES['excel_file']['tmp_name']);
                $sheet = $spreadsheet->getActiveSheet();
                $highestColumn = $sheet->getHighestDataColumn();
                $highestRow = (int) $sheet->getHighestDataRow();

                $columns = excel_column_letters($highestColumn);
                $headers = [];
                foreach ($columns as $column) {
                    $headerCell = $sheet->getCell($column . '1')->getCalculatedValue();
                    $headers[$column] = is_scalar($headerCell) ? trim((string) $headerCell) : '';
                }

                $rows = [];
                for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
                    $row = [];
                    foreach ($columns as $column) {
                        $cell = $sheet->getCell($column . $rowIndex)->getCalculatedValue();
                        $row[$column] = is_scalar($cell) ? trim((string) $cell) : '';
                    }
                    $rows[] = $row;
                }

                $selectedSbdColumn = $columns[0] ?? '';
                $selectedScoreColumn = $columns[1] ?? ($columns[0] ?? '');

                $_SESSION['score_import_state'] = [
                    'mode' => $mode,
                    'room_id' => $roomId,
                    'subject_id' => $subjectId,
                    'headers' => $headers,
                    'rows' => $rows,
                    'columns' => $columns,
                    'sbd_column' => $selectedSbdColumn,
                    'score_column' => $selectedScoreColumn,
                ];
                $state = $_SESSION['score_import_state'];
            } catch (Throwable $e) {
                $errors[] = 'Không thể đọc tệp Excel: ' . $e->getMessage();
            }
        }
    }

    if (empty($errors) && $action === 'process_import') {
        $state = (array) ($_SESSION['score_import_state'] ?? []);
        $columns = (array) ($state['columns'] ?? []);
        $headers = (array) ($state['headers'] ?? []);
        $rows = (array) ($state['rows'] ?? []);

        if (empty($columns)) {
            $errors[] = 'Chưa có dữ liệu file. Hãy tải file Excel trước.';
        }

        if (!in_array($selectedSbdColumn, $columns, true) || !in_array($selectedScoreColumn, $columns, true)) {
            $errors[] = 'Cột SBD hoặc cột điểm không hợp lệ.';
        }

        $targetSubjectId = 0;
        $eligibleBySbd = [];

        if (empty($errors) && $mode === 'room') {
            $roomStmt = $pdo->prepare('SELECT id, subject_id FROM rooms WHERE exam_id = :exam_id AND id = :room_id LIMIT 1');
            $roomStmt->execute([':exam_id' => $examId, ':room_id' => $roomId]);
            $roomRow = $roomStmt->fetch(PDO::FETCH_ASSOC);
            if (!$roomRow) {
                $errors[] = 'Phòng được chọn không hợp lệ.';
            } else {
                $targetSubjectId = (int) $roomRow['subject_id'];

                $eligibleStmt = $pdo->prepare('SELECT sbd, student_id
                    FROM exam_students
                    WHERE exam_id = :exam_id AND subject_id = :subject_id AND room_id = :room_id');
                $eligibleStmt->execute([
                    ':exam_id' => $examId,
                    ':subject_id' => $targetSubjectId,
                    ':room_id' => $roomId,
                ]);
                foreach ($eligibleStmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
                    $eligibleBySbd[(string) $student['sbd']] = (int) $student['student_id'];
                }
            }
        }

        if (empty($errors) && $mode === 'subject') {
            $targetSubjectId = $subjectId;
            if ($targetSubjectId <= 0) {
                $errors[] = 'Môn được chọn không hợp lệ.';
            } else {
                $eligibleStmt = $pdo->prepare('SELECT base.sbd, ess.student_id
                    FROM exam_student_subjects ess
                    INNER JOIN exam_students base
                      ON base.exam_id = ess.exam_id
                     AND base.student_id = ess.student_id
                     AND base.subject_id IS NULL
                    WHERE ess.exam_id = :exam_id AND ess.subject_id = :subject_id');
                $eligibleStmt->execute([':exam_id' => $examId, ':subject_id' => $targetSubjectId]);
                foreach ($eligibleStmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
                    $eligibleBySbd[(string) $student['sbd']] = (int) $student['student_id'];
                }
            }
        }

        if (empty($errors)) {
            $maxStmt = $pdo->prepare('SELECT COALESCE(tong_diem, 10) FROM exam_subject_config WHERE exam_id = :exam_id AND subject_id = :subject_id ORDER BY id DESC LIMIT 1');
            $maxStmt->execute([':exam_id' => $examId, ':subject_id' => $targetSubjectId]);
            $maxScore = (float) ($maxStmt->fetchColumn() ?: 10);

            $upsertScore = $pdo->prepare('INSERT INTO exam_scores (exam_id, student_id, subject_id, score, updated_at)
                VALUES (:exam_id, :student_id, :subject_id, :score, :updated_at)
                ON CONFLICT(exam_id, student_id, subject_id)
                DO UPDATE SET score = excluded.score, updated_at = excluded.updated_at');
            $deleteScore = $pdo->prepare('DELETE FROM exam_scores
                WHERE exam_id = :exam_id AND student_id = :student_id AND subject_id = :subject_id');

            $updated = 0;
            $deleted = 0;
            $skipped = 0;

            $pdo->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $sbd = trim((string) ($row[$selectedSbdColumn] ?? ''));
                    if ($sbd === '' || !isset($eligibleBySbd[$sbd])) {
                        $skipped++;
                        continue;
                    }

                    $rawScore = (string) ($row[$selectedScoreColumn] ?? '');
                    $parsedScore = parseSmartScore($rawScore, $maxScore);
                    $studentId = (int) $eligibleBySbd[$sbd];

                    if ($parsedScore === null) {
                        $deleteScore->execute([
                            ':exam_id' => $examId,
                            ':student_id' => $studentId,
                            ':subject_id' => $targetSubjectId,
                        ]);
                        $deleted++;
                        continue;
                    }

                    $upsertScore->execute([
                        ':exam_id' => $examId,
                        ':student_id' => $studentId,
                        ':subject_id' => $targetSubjectId,
                        ':score' => $parsedScore,
                        ':updated_at' => date('c'),
                    ]);
                    $updated++;
                }

                $pdo->commit();
                $success = sprintf('Import hoàn tất. Cập nhật: %d, Xóa do rỗng/không hợp lệ: %d, Bỏ qua: %d.', $updated, $deleted, $skipped);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Lỗi import: ' . $e->getMessage();
            }
        }

        $_SESSION['score_import_state']['mode'] = $mode;
        $_SESSION['score_import_state']['room_id'] = $roomId;
        $_SESSION['score_import_state']['subject_id'] = $subjectId;
        $_SESSION['score_import_state']['sbd_column'] = $selectedSbdColumn;
        $_SESSION['score_import_state']['score_column'] = $selectedScoreColumn;
        $state = (array) $_SESSION['score_import_state'];
    }
}

$columns = (array) ($state['columns'] ?? []);
$headers = (array) ($state['headers'] ?? []);
$previewRows = array_slice((array) ($state['rows'] ?? []), 0, 8);

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>
    <div style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><strong>Import điểm từ Excel</strong></div>
            <div class="card-body">
                <?= exams_display_flash(); ?>
                <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="row g-3 mb-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="upload_file">

                    <div class="col-md-3">
                        <label class="form-label">Mode import</label>
                        <select name="mode" id="modeSelect" class="form-select" onchange="toggleMode(this.value)">
                            <option value="room" <?= $mode === 'room' ? 'selected' : '' ?>>Import theo phòng</option>
                            <option value="subject" <?= $mode === 'subject' ? 'selected' : '' ?>>Import theo môn</option>
                        </select>
                    </div>

                    <div class="col-md-5 mode-room">
                        <label class="form-label">Chọn phòng</label>
                        <select name="room_id" class="form-select">
                            <option value="0">-- Chọn phòng --</option>
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?= (int) $room['id'] ?>" <?= $roomId === (int) $room['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $room['ten_mon'] . ' | ' . $room['ten_phong'] . ' | Khối ' . $room['khoi'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-5 mode-subject">
                        <label class="form-label">Chọn môn</label>
                        <select name="subject_id" class="form-select">
                            <option value="0">-- Chọn môn --</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= (int) $subject['id'] ?>" <?= $subjectId === (int) $subject['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $subject['ten_mon'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Tệp Excel</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls,.csv">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100" <?= $canUseSpreadsheet ? '' : 'disabled' ?>>Tải file & đọc cột</button>
                    </div>
                </form>

                <?php if (!empty($columns)): ?>
                    <form method="post" class="row g-3 mb-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="process_import">
                        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="room_id" value="<?= (int) $roomId ?>">
                        <input type="hidden" name="subject_id" value="<?= (int) $subjectId ?>">

                        <div class="col-md-4">
                            <label class="form-label">Cột SBD</label>
                            <select name="sbd_column" class="form-select">
                                <?php foreach ($columns as $column): ?>
                                    <option value="<?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedSbdColumn === $column ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(column_label($column, $headers), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Cột điểm</label>
                            <select name="score_column" class="form-select">
                                <?php foreach ($columns as $column): ?>
                                    <option value="<?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedScoreColumn === $column ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(column_label($column, $headers), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-success w-100">Process Import</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead>
                                <tr>
                                    <?php foreach ($columns as $column): ?>
                                        <th><?= htmlspecialchars(column_label($column, $headers), ENT_QUOTES, 'UTF-8') ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewRows as $previewRow): ?>
                                    <tr>
                                        <?php foreach ($columns as $column): ?>
                                            <td><?= htmlspecialchars((string) ($previewRow[$column] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
function toggleMode(mode) {
    document.querySelectorAll('.mode-room').forEach((el) => {
        el.style.display = mode === 'room' ? '' : 'none';
    });
    document.querySelectorAll('.mode-subject').forEach((el) => {
        el.style.display = mode === 'subject' ? '' : 'none';
    });
}
toggleMode('<?= $mode ?>');
</script>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
