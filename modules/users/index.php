<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';

require_once BASE_PATH . '/modules/users/_common.php';

$search = trim((string) ($_GET['q'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$csrf = users_get_csrf_token();

$filters = [];
$params = [];

if ($search !== '') {
    $filters[] = 'username LIKE :search';
    $params[':search'] = '%' . $search . '%';
}

if ($roleFilter !== '' && in_array($roleFilter, USER_ALLOWED_ROLES, true)) {
    $filters[] = 'role = :role';
    $params[':role'] = $roleFilter;
}

$whereSql = $filters ? (' WHERE ' . implode(' AND ', $filters)) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM users' . $whereSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$hasCreatedAt = users_has_created_at($pdo);
$selectCreated = $hasCreatedAt ? ', created_at' : '';

$sql = 'SELECT id, username, role, active' . $selectCreated . ' FROM users'
    . $whereSql
    . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $token = $_POST['csrf_token'] ?? null;

    if (!users_verify_csrf_token(is_string($token) ? $token : null)) {
        set_flash('error', 'CSRF token không hợp lệ.');
        header('Location: ' . BASE_URL . '/modules/users/index.php');
        exit;
    }

    if ($id <= 0) {
        set_flash('error', 'ID người dùng không hợp lệ.');
        header('Location: ' . BASE_URL . '/modules/users/index.php');
        exit;
    }

    try {
        if ($action === 'toggle_active') {
            $check = $pdo->prepare('SELECT id, active FROM users WHERE id = :id LIMIT 1');
            $check->execute([':id' => $id]);
            $user = $check->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                set_flash('error', 'Không tìm thấy người dùng.');
            } else {
                $newStatus = ((int) $user['active'] === 1) ? 0 : 1;
                $update = $pdo->prepare('UPDATE users SET active = :active WHERE id = :id');
                $update->execute([':active' => $newStatus, ':id' => $id]);
                set_flash('success', $newStatus === 1 ? 'Đã bật tài khoản.' : 'Đã vô hiệu hóa tài khoản.');
            }
        }
    } catch (PDOException $e) {
        set_flash('error', 'Có lỗi khi cập nhật trạng thái tài khoản.');
    }

    header('Location: ' . BASE_URL . '/modules/users/index.php?' . http_build_query([
        'q' => $search,
        'role' => $roleFilter,
        'page' => $page,
    ]));
    exit;
}

require_once BASE_PATH . '/layout/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="students-layout" style="display:flex;min-height:calc(100vh - 44px);">
    <?php require_once BASE_PATH . '/layout/sidebar.php'; ?>

    <div class="students-main" style="flex:1;padding:20px;min-width:0;">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <strong>Quản lý người dùng</strong>
                <a href="<?= BASE_URL ?>/modules/users/create.php" class="btn btn-light btn-sm">+ Thêm user</a>
            </div>
            <div class="card-body">
                <?= display_flash(); ?>

                <form method="get" class="row g-2 mb-3">
                    <div class="col-md-5">
                        <input type="text" class="form-control" name="q" placeholder="Tìm theo username" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="role">
                            <option value="">-- Tất cả vai trò --</option>
                            <?php foreach (USER_ALLOWED_ROLES as $role): ?>
                                <option value="<?= $role ?>" <?= $roleFilter === $role ? 'selected' : '' ?>><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Lọc</button>
                        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-outline-secondary">Làm mới</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-striped table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created at</th>
                                <th style="width:240px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr><td colspan="6" class="text-center">Không có dữ liệu.</td></tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?= (int) $user['id'] ?></td>
                                        <td><?= htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><span class="badge <?= users_badge_class((string) $user['role']) ?>"><?= htmlspecialchars((string) $user['role'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td>
                                            <?php if ((int) $user['active'] === 1): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Disabled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= $hasCreatedAt ? htmlspecialchars((string) ($user['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') : '<em>N/A</em>' ?>
                                        </td>
                                        <td>
                                            <a class="btn btn-sm btn-warning" href="<?= BASE_URL ?>/modules/users/edit.php?id=<?= (int) $user['id'] ?>">Edit</a>

                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                                <button type="submit" class="btn btn-sm <?= (int) $user['active'] === 1 ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                                                    <?= (int) $user['active'] === 1 ? 'Disable' : 'Enable' ?>
                                                </button>
                                            </form>

                                            <?php if ((string) ($user['role'] ?? '') !== 'admin'): ?>
                                                <a class="btn btn-sm btn-danger" href="<?= BASE_URL ?>/modules/users/delete.php?id=<?= (int) $user['id'] ?>">Delete</a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" disabled title="Không được phép xóa tài khoản admin">Delete</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav>
                        <ul class="pagination">
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(['q' => $search, 'role' => $roleFilter, 'page' => $p]) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once BASE_PATH . '/layout/footer.php'; ?>
