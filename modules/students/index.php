<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once BASE_PATH . '/core/auth.php';
require_login();
require_role(['admin']);
require_once BASE_PATH . '/core/db.php';

$keyword = trim($_GET['q'] ?? '');
$flash = $_GET['msg'] ?? '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'bulk_delete_selected') {
        $ids = $_POST['student_ids'] ?? [];
        $ids = array_values(array_filter(array_map('intval', (array) $ids), function ($id) {
            return $id > 0;
        }));

        if (empty($ids)) {
            $errors[] = 'Vui lòng chọn ít nhất 1 học sinh để xóa.';
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $deleteStmt = $pdo->prepare("DELETE FROM students WHERE id IN ($placeholders)");
            $deleteStmt->execute($ids);

            header('Location: ' . BASE_URL . '/modules/students/index.php?msg=deleted_selected');
            exit;
        }
    }

    if ($action === 'bulk_delete_filtered') {
        $keywordPost = trim($_POST['keyword'] ?? '');

        if ($keywordPost === '') {
            $errors[] = 'Điều kiện lọc trống, không thể xóa theo điều kiện.';
        } else {
            $deleteStmt = $pdo->prepare('DELETE FROM students WHERE hoten LIKE :keyword OR sbd LIKE :keyword');
            $deleteStmt->execute([':keyword' => '%' . $keywordPost . '%']);

            header('Location: ' . BASE_URL . '/modules/students/index.php?msg=deleted_filtered');
            exit;
        }
    }
}

$sql = 'SELECT id, sbd, hoten, ngaysinh, lop, truong FROM students';
$params = [];

if ($keyword !== '') {
    $sql .= ' WHERE hoten LIKE :keyword OR sbd LIKE :keyword';
    $params[':keyword'] = '%' . $keyword . '%';
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$getStudentNameKey = static function(string $fullName): string {
    $name = trim(preg_replace('/\s+/', ' ', $fullName) ?? '');
    if ($name == '') {
        return '';
    }
    $parts = explode(' ', $name);
    return mb_strtolower((string) end($parts), 'UTF-8');
};

usort($students, static function(array $a, array $b) use ($getStudentNameKey): int {
    $classCmp = strcmp(trim((string) ($a['lop'] ?? '')), trim((string) ($b['lop'] ?? '')));
    if ($classCmp !== 0) {
        return $classCmp;
    }

    $nameKeyCmp = strcmp(
        $getStudentNameKey((string) ($a['hoten'] ?? '')),
        $getStudentNameKey((string) ($b['hoten'] ?? ''))
    );
    if ($nameKeyCmp !== 0) {
        return $nameKeyCmp;
    }

    return strcmp((string) ($a['hoten'] ?? ''), (string) ($b['hoten'] ?? ''));
});

$totalStudents = count($students);
$perPage = 50;
$totalPages = max(1, (int) ceil($totalStudents / $perPage));
$page = max(1, (int) ($_GET['page'] ?? 1));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$students = array_slice($students, $offset, $perPage);

$groupedStudents = [];
foreach ($students as $st) {
    $className = trim((string) ($st['lop'] ?? ''));
    if ($className === '') {
        $className = 'Chưa phân lớp';
    }
    if (!isset($groupedStudents[$className])) {
        $groupedStudents[$className] = [];
    }
    $groupedStudents[$className][] = $st;
}

require_once BASE_PATH . '/layout/header.php';
?>

<style>

    .students-layout {
        display: flex;
        align-items: stretch;
        width: 100%;
        min-height: calc(100vh - 44px);
    }
    .students-layout > .sidebar {
        flex: 0 0 220px;
        width: 220px;
        min-width: 220px;
    }
    .students-main {
        flex: 1 1 auto;
        min-width: 0;
        padding: 20px;
    }
    .students-window {
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 12px 28px rgba(44, 62, 80, 0.15);
        border: 1px solid #dbe3ec;
        overflow: hidden;
    }
    .window-titlebar {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: #fff;
        padding: 12px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .window-controls {
        display: flex;
        gap: 8px;
    }
    .window-dot {
        width: 12px;
        height: 12px;
        border-radius: 999px;
        display: inline-block;
        background: rgba(255,255,255,0.75);
    }
    .window-body {
        padding: 18px;
        background: #f4f8fc;
    }
    .tool-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
        gap: 10px;
        margin-bottom: 14px;
    }
    .tool-icon {
        background: #fff;
        border: 1px solid #dbe3ec;
        border-radius: 12px;
        padding: 10px;
        text-decoration: none;
        color: #1f2937;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 84px;
    }
    .tool-icon:hover { box-shadow: 0 8px 20px rgba(37,99,235,.14); }
    .tool-icon .icon { font-size: 24px; line-height: 1; margin-bottom: 8px; }
    .tool-icon .label { font-size: 13px; font-weight: 600; text-align: center; }
    .students-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        border-radius: 10px;
        overflow: hidden;
    }
    .students-table th,
    .students-table td {
        border: 1px solid #e5e7eb;
        padding: 10px;
        vertical-align: middle;
    }
    .students-table th {
        background: #eff6ff;
        color: #1d4ed8;
    }
    .table-action { display: inline-flex; gap: 6px; }
    .btn-icon {
        display: inline-flex;
        width: 32px;
        height: 32px;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        color: #fff;
        text-decoration: none;
        border: none;
        cursor: pointer;
    }
    .btn-edit { background: #f59e0b; }
    .btn-delete { background: #ef4444; }
    .search-row { display:flex; gap:8px; margin: 10px 0 16px; }
    .search-row input[type='text'] { flex: 1; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; }
    .search-row button, .search-row a {
        padding: 10px 12px;
        border: none;
        border-radius: 8px;
        text-decoration: none;
        color: #fff;
        background: #2563eb;
    }
    .search-row a { background: #64748b; }
    .notice {
        padding: 10px;
        border-radius: 8px;
        margin-bottom: 12px;
    }
    .notice.success { background: #dcfce7; color: #166534; }
    .notice.error { background: #fee2e2; color: #991b1b; }
</style>

<div class="students-layout">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>

    <div class="students-main">
        <div class="students-window">
            <div class="window-titlebar">
                <strong>Quản lý học sinh</strong>
                <div class="window-controls">
                    <span class="window-dot"></span><span class="window-dot"></span><span class="window-dot"></span>
                </div>
            </div>

            <div class="window-body">
                <?php if ($flash === 'deleted_selected'): ?>
                    <div class="notice success">Đã xóa các học sinh được chọn.</div>
                <?php elseif ($flash === 'deleted_filtered'): ?>
                    <div class="notice success">Đã xóa học sinh theo điều kiện lọc.</div>
                <?php elseif ($flash === 'created'): ?>
                    <div class="notice success">Đã thêm học sinh mới.</div>
                <?php elseif ($flash === 'updated'): ?>
                    <div class="notice success">Đã cập nhật thông tin học sinh.</div>
                <?php elseif ($flash === 'deleted_one'): ?>
                    <div class="notice success">Đã xóa học sinh.</div>
                <?php elseif ($flash === 'none_inserted'): ?>
                    <div class="notice error">Không có dòng hợp lệ được import (yêu cầu tối thiểu: SBD + Họ tên).</div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="notice error">
                        <ul style="margin:0; padding-left: 18px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="tool-grid">
                    <a class="tool-icon" href="<?= BASE_URL ?>/modules/students/create.php" title="Thêm học sinh">
                        <span class="icon">➕</span>
                        <span class="label">Thêm học sinh</span>
                    </a>
                    <a class="tool-icon" href="<?= BASE_URL ?>/modules/students/import.php" title="Import Excel">
                        <span class="icon">📥</span>
                        <span class="label">Import Excel</span>
                    </a>
                    <button type="submit" form="bulkForm" name="action" value="bulk_delete_selected" class="tool-icon" style="cursor:pointer;" onclick="return confirm('Bạn có chắc muốn xóa các học sinh đã chọn?');" title="Xóa đã chọn">
                        <span class="icon">🗑️</span>
                        <span class="label">Xóa đã chọn</span>
                    </button>
                    <form method="post" onsubmit="return confirm('Bạn có chắc muốn xóa toàn bộ học sinh theo điều kiện lọc hiện tại?');" style="margin:0;">
                        <input type="hidden" name="action" value="bulk_delete_filtered">
                        <input type="hidden" name="keyword" value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="tool-icon" style="width:100%;cursor:pointer;" title="Xóa theo lọc">
                            <span class="icon">🧹</span>
                            <span class="label">Xóa theo lọc</span>
                        </button>
                    </form>
                </div>

                <form method="get" class="search-row">
                    <input type="text" name="q" value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>" placeholder="Lọc theo SBD hoặc họ tên...">
                    <button type="submit">Tìm</button>
                    <a href="<?= BASE_URL ?>/modules/students/index.php">Làm mới</a>
                </form>

                <form method="post" id="bulkForm">
                    <input type="hidden" name="action" value="bulk_delete_selected">

                    <div style="margin-bottom:8px;">
                        <label><input type="checkbox" id="checkAll"> Chọn tất cả</label>
                    </div>

                    <table class="students-table">
                        <thead>
                            <tr>
                                <th style="width:40px; text-align:center;"></th>
                                <th>ID</th>
                                <th>SBD</th>
                                <th>Họ tên</th>
                                <th>Ngày sinh</th>
                                <th>Lớp</th>
                                <th>Trường</th>
                                <th style="width:100px;">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="8" style="text-align:center;">Không có dữ liệu học sinh.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($groupedStudents as $className => $classStudents): ?>
                                    <tr>
                                        <td colspan="8" style="background:#eaf2ff;font-weight:700;color:#1e3a8a;">Lớp: <?= htmlspecialchars($className, ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                    <?php foreach ($classStudents as $student): ?>
                                        <tr>
                                            <td style="text-align:center;">
                                                <input type="checkbox" class="student-check" name="student_ids[]" value="<?= (int) $student['id'] ?>">
                                            </td>
                                            <td><?= (int) $student['id'] ?></td>
                                            <td><?= htmlspecialchars($student['sbd'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($student['hoten'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($student['ngaysinh'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($student['lop'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($student['truong'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <span class="table-action">
                                                    <a class="btn-icon btn-edit" href="<?= BASE_URL ?>/modules/students/edit.php?id=<?= (int) $student['id'] ?>" title="Sửa">✏️</a>
                                                    <a class="btn-icon btn-delete" href="<?= BASE_URL ?>/modules/students/delete.php?id=<?= (int) $student['id'] ?>" title="Xóa">🗑</a>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ($totalPages > 1): ?>
                        <?php
                            $buildPageUrl = static function(int $targetPage) use ($keyword): string {
                                return BASE_URL . '/modules/students/index.php?' . http_build_query([
                                    'q' => $keyword,
                                    'page' => $targetPage,
                                ]);
                            };
                        ?>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px;">
                            <?php if ($page > 1): ?>
                                <a class="btn-icon" style="width:auto;padding:0 10px;background:#2563eb;" href="<?= htmlspecialchars($buildPageUrl(1), ENT_QUOTES, 'UTF-8') ?>">Trang đầu</a>
                                <a class="btn-icon" style="width:auto;padding:0 10px;background:#2563eb;" href="<?= htmlspecialchars($buildPageUrl($page - 1), ENT_QUOTES, 'UTF-8') ?>">Trang trước</a>
                            <?php endif; ?>

                            <?php $startPage = max(1, $page - 2); $endPage = min($totalPages, $page + 2); ?>
                            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                <a class="btn-icon" style="width:auto;padding:0 10px;<?= $p === $page ? 'background:#1d4ed8;' : 'background:#64748b;' ?>" href="<?= htmlspecialchars($buildPageUrl($p), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a class="btn-icon" style="width:auto;padding:0 10px;background:#2563eb;" href="<?= htmlspecialchars($buildPageUrl($page + 1), ENT_QUOTES, 'UTF-8') ?>">Trang sau</a>
                                <a class="btn-icon" style="width:auto;padding:0 10px;background:#2563eb;" href="<?= htmlspecialchars($buildPageUrl($totalPages), ENT_QUOTES, 'UTF-8') ?>">Trang cuối</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    const checkAll = document.getElementById('checkAll');
    const checks = document.querySelectorAll('.student-check');

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            checks.forEach(function (item) {
                item.checked = checkAll.checked;
            });
        });
    }
</script>

<?php require_once BASE_PATH . '/layout/footer.php'; ?>
