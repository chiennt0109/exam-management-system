<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/modules/exams/_common.php';
require_once BASE_PATH . '/modules/exams/score_utils.php';
require_role(['admin', 'organizer', 'scorer']);

$autoloadPaths = [
    BASE_PATH . '/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];
foreach ($autoloadPaths as $autoloadPath) {
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

if (!isset($_SESSION['score_import'])) {
    $_SESSION['score_import'] = [];
}
$importData = (array) $_SESSION['score_import'];

$mode = (string) ($_POST['mode'] ?? $_GET['mode'] ?? ($importData['mode'] ?? 'room'));
$mode = in_array($mode, ['room', 'subject'], true) ? $mode : 'room';
$roomId = max(0, (int) ($_POST['room_id'] ?? $_GET['room_id'] ?? ($importData['room_id'] ?? 0)));
$subjectId = max(0, (int) ($_POST['subject_id'] ?? $_GET['subject_id'] ?? ($importData['subject_id'] ?? 0)));
$selectedSbdCol = (string) ($_POST['sbd_col'] ?? ($importData['sbd_col'] ?? 'A'));
$selectedScoreCol = (string) ($_POST['score_col'] ?? ($importData['score_col'] ?? 'B'));

function get_excel_columns(string $highestColumn): array
{
    $columns = [];
    $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
    for ($i = 1; $i <= $last; $i++) {
        $columns[] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    }
    return $columns;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!exams_verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'CSRF token không hợp lệ.';
    }

    $action = (string) ($_POST['action'] ?? '');

    if (!$canUseSpreadsheet) {
        $errors[] = 'Thiếu thư viện PHPSpreadsheet. Vui lòng cài đặt trước khi import.';
    }

    if (empty($errors) && $action === 'load_file') {
        if (empty($_FILES['excel_file']['tmp_name']) || !is_uploaded_file($_FILES['excel_file']['tmp_name'])) {
            $errors[] = 'Vui lòng chọn tệp Excel.';
        } else {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['excel_file']['tmp_name']);
                $sheet = $spreadsheet->getActiveSheet();
                $highestColumn = $sheet->getHighestDataColumn();
                $highestRow = $sheet->getHighestDataRow();
                $rows = [];

                for ($row = 1; $row <= $highestRow; $row++) {
                    $rowData = [];
                    foreach (get_excel_columns($highestColumn) as $col) {
                        $cellValue = $sheet->getCell($col . $row)->getCalculatedValue();
                        $rowData[$col] = is_scalar($cellValue) ? trim((string) $cellValue) : '';
                    }
                    $rows[] = $rowData;
                }

                $_SESSION['score_import'] = [
                    'rows' => $rows,
                    'columns' => get_excel_columns($highestColumn),
                    'mode' => $mode,
                    'room_id' => $roomId,
                    'subject_id' => $subjectId,
                    'sbd_col' => $selectedSbdCol,
                    'score_col' => $selectedScoreCol,
                ];
                $importData = $_SESSION['score_import'];
            } catch (Throwable $e) {
                $errors[] = 'Không thể đọc tệp Excel: ' . $e->getMessage();
            }
        }
    }

    if (empty($errors) && $action === 'do_import') {
        $importData = (array) ($_SESSION['score_import'] ?? []);
        $rows = (array) ($importData['rows'] ?? []);
        $columns = (array) ($importData['columns'] ?? []);

        if (empty($rows) || empty($columns)) {
            $errors[] = 'Không có dữ liệu import. Vui lòng tải file trước.';
        }

        if (!in_array($selectedSbdCol, $columns, true) || !in_array($selectedScoreCol, $columns, true)) {
            $errors[] = 'Cột SBD hoặc cột điểm không hợp lệ.';
        }

        $targetSubjectId = 0;
        $eligibleBySbd = [];
        if (empty($errors) && $mode === 'room') {
            $roomStmt = $pdo->prepare('SELECT id, subject_id FROM rooms WHERE id = :id AND exam_id = :exam_id LIMIT 1');
            $roomStmt->execute([':id' => $roomId, ':exam_id' => $examId]);
            $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
            if (!$room) {
                $errors[] = 'Phòng thi không hợp lệ.';
            } else {
                $targetSubjectId = (int) $room['subject_id'];
                $eligibleStmt = $pdo->prepare('SELECT es.sbd, es.student_id
                    FROM exam_students es
                    WHERE es.exam_id = :exam_id AND es.subject_id = :subject_id AND es.room_id = :room_id');
                $eligibleStmt->execute([
                    ':exam_id' => $examId,
                    ':subject_id' => $targetSubjectId,
                    ':room_id' => $roomId,
                ]);
                foreach ($eligibleStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
                    $eligibleBySbd[(string) $it['sbd']] = (int) $it['student_id'];
                }
            }
        }

        if (empty($errors) && $mode === 'subject') {
            $targetSubjectId = $subjectId;
            if ($targetSubjectId <= 0) {
                $errors[] = 'Môn thi không hợp lệ.';
            } else {
                $eligibleStmt = $pdo->prepare('SELECT base.sbd, ess.student_id
                    FROM exam_student_subjects ess
                    INNER JOIN exam_students base
                        ON base.exam_id = ess.exam_id AND base.student_id = ess.student_id AND base.subject_id IS NULL
                    WHERE ess.exam_id = :exam_id AND ess.subject_id = :subject_id');
                $eligibleStmt->execute([
                    ':exam_id' => $examId,
                    ':subject_id' => $targetSubjectId,
                ]);
                foreach ($eligibleStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
                    $eligibleBySbd[(string) $it['sbd']] = (int) $it['student_id'];
                }
            }
        }

        if (empty($errors)) {
            $maxStmt = $pdo->prepare('SELECT tong_diem FROM exam_subject_config WHERE exam_id = :exam_id AND subject_id = :subject_id ORDER BY id DESC LIMIT 1');
            $maxStmt->execute([':exam_id' => $examId, ':subject_id' => $targetSubjectId]);
            $maxScore = (float) ($maxStmt->fetchColumn() ?: 10);

            $upsert = $pdo->prepare('INSERT INTO exam_scores (exam_id, student_id, subject_id, score, updated_at)
                VALUES (:exam_id, :student_id, :subject_id, :score, :updated_at)
                ON CONFLICT(exam_id, student_id, subject_id) DO UPDATE SET
                    score = excluded.score,
                    updated_at = excluded.updated_at');

            $imported = 0;
            $skipped = 0;

            $pdo->beginTransaction();
            try {
                foreach ($rows as $idx => $row) {
                    if ($idx === 0) {
                        continue;
                    }
                    $sbd = trim((string) ($row[$selectedSbdCol] ?? ''));
                    if ($sbd === '') {
                        $skipped++;
                        continue;
                    }

                    if (!isset($eligibleBySbd[$sbd])) {
                        $skipped++;
                        continue;
                    }

                    $rawScore = (string) ($row[$selectedScoreCol] ?? '');
                    $parsed = parseSmartScore($rawScore, $maxScore);
                    if ($parsed === null) {
                        $skipped++;
                        continue;
                    }

                    $upsert->execute([
                        ':exam_id' => $examId,
                        ':student_id' => $eligibleBySbd[$sbd],
                        ':subject_id' => $targetSubjectId,
                        ':score' => $parsed,
                        ':updated_at' => date('c'),
                    ]);
                    $imported++;
                }
                $pdo->commit();
                $success = sprintf('Import hoàn tất: %d bản ghi cập nhật, %d dòng bỏ qua.', $imported, $skipped);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $e->getMessage();
            }
        }

        $_SESSION['score_import']['mode'] = $mode;
        $_SESSION['score_import']['room_id'] = $roomId;
        $_SESSION['score_import']['subject_id'] = $subjectId;
        $_SESSION['score_import']['sbd_col'] = $selectedSbdCol;
        $_SESSION['score_import']['score_col'] = $selectedScoreCol;
        $importData = $_SESSION['score_import'];
    }
}

$columns = (array) ($importData['columns'] ?? []);
$previewRows = array_slice((array) ($importData['rows'] ?? []), 0, 6);

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
            <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div><?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="row g-3 mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="load_file">
                <div class="col-md-4">
                    <label class="form-label">Mode import</label>
                    <select name="mode" class="form-select" onchange="toggleTarget(this.value)">
                        <option value="room" <?= $mode === 'room' ? 'selected' : '' ?>>Import theo phòng</option>
                        <option value="subject" <?= $mode === 'subject' ? 'selected' : '' ?>>Import theo môn</option>
                    </select>
                </div>
                <div class="col-md-4 target-room">
                    <label class="form-label">Phòng thi</label>
                    <select name="room_id" class="form-select">
                        <option value="0">-- Chọn phòng --</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= (int) $room['id'] ?>" <?= $roomId === (int) $room['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $room['ten_mon'] . ' | ' . $room['ten_phong'] . ' | Khối ' . $room['khoi'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 target-subject">
                    <label class="form-label">Môn thi</label>
                    <select name="subject_id" class="form-select">
                        <option value="0">-- Chọn môn --</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?= (int) $subject['id'] ?>" <?= $subjectId === (int) $subject['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $subject['ten_mon'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Tệp Excel</label>
                    <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls,.csv">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100" <?= $canUseSpreadsheet ? '' : 'disabled' ?>>Tải file</button>
                </div>
            </form>

            <?php if (!empty($columns)): ?>
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="do_import">
                <input type="hidden" name="mode" value="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="room_id" value="<?= (int) $roomId ?>">
                <input type="hidden" name="subject_id" value="<?= (int) $subjectId ?>">

                <div class="col-md-4">
                    <label class="form-label">Cột SBD</label>
                    <select class="form-select" name="sbd_col">
                        <?php foreach ($columns as $column): ?>
                            <option value="<?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedSbdCol === $column ? 'selected' : '' ?>><?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cột điểm</label>
                    <select class="form-select" name="score_col">
                        <?php foreach ($columns as $column): ?>
                            <option value="<?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedScoreCol === $column ? 'selected' : '' ?>><?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100">Import điểm</button>
                </div>
            </form>

            <div class="table-responsive mt-3">
                <table class="table table-sm table-bordered">
                    <thead>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th><?= htmlspecialchars($column, ENT_QUOTES, 'UTF-8') ?></th>
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
function toggleTarget(mode) {
    document.querySelectorAll('.target-room').forEach((el) => {
        el.style.display = mode === 'room' ? '' : 'none';
    });
    document.querySelectorAll('.target-subject').forEach((el) => {
        el.style.display = mode === 'subject' ? '' : 'none';
    });
}
toggleTarget('<?= $mode ?>');
</script>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
