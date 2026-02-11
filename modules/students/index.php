<?php
require_once __DIR__.'/../../core/auth.php';
require_login();
require_role(['admin']);
require_once __DIR__.'/../../core/db.php';

$keyword = trim($_GET['q'] ?? '');

$sql = 'SELECT id, sbd, hoten, ngaysinh, lop, truong FROM students';
$params = [];

if ($keyword !== '') {
    $sql .= ' WHERE hoten LIKE :keyword OR sbd LIKE :keyword';
    $params[':keyword'] = '%' . $keyword . '%';
}

$sql .= ' ORDER BY id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__.'/../../layout/header.php';
?>

<div class="container">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>

    <div class="content">
        <div class="d-flex justify-content-between align-items-center mb-3" style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
            <h2 style="margin:0;">Quản lý học sinh</h2>
            <a href="create.php" class="btn btn-success" style="display:inline-block;padding:8px 12px;background:#28a745;color:#fff;text-decoration:none;border-radius:4px;">+ Thêm học sinh</a>
        </div>

        <form method="get" class="row g-2 mb-3" style="display:flex;gap:10px;align-items:center;margin:10px 0 16px;">
            <input
                type="text"
                name="q"
                class="form-control"
                style="flex:1;min-width:220px;padding:8px;"
                placeholder="Tìm theo SBD hoặc họ tên..."
                value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>"
            >
            <button type="submit" class="btn btn-primary" style="padding:8px 12px;background:#007bff;color:#fff;border:none;border-radius:4px;">Tìm kiếm</button>
            <a href="index.php" class="btn btn-secondary" style="padding:8px 12px;background:#6c757d;color:#fff;text-decoration:none;border-radius:4px;">Làm mới</a>
        </form>

        <div class="table-responsive">
            <table class="table table-bordered table-striped" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#007bff;color:#fff;">
                        <th style="border:1px solid #ddd;padding:8px;">ID</th>
                        <th style="border:1px solid #ddd;padding:8px;">SBD</th>
                        <th style="border:1px solid #ddd;padding:8px;">Họ tên</th>
                        <th style="border:1px solid #ddd;padding:8px;">Ngày sinh</th>
                        <th style="border:1px solid #ddd;padding:8px;">Lớp</th>
                        <th style="border:1px solid #ddd;padding:8px;">Trường</th>
                        <th style="border:1px solid #ddd;padding:8px;min-width:140px;">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="7" style="border:1px solid #ddd;padding:8px;text-align:center;">Không có dữ liệu học sinh.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td style="border:1px solid #ddd;padding:8px;"><?= (int) $student['id'] ?></td>
                            <td style="border:1px solid #ddd;padding:8px;"><?= htmlspecialchars($student['sbd'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="border:1px solid #ddd;padding:8px;"><?= htmlspecialchars($student['hoten'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="border:1px solid #ddd;padding:8px;"><?= htmlspecialchars($student['ngaysinh'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="border:1px solid #ddd;padding:8px;"><?= htmlspecialchars($student['lop'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="border:1px solid #ddd;padding:8px;"><?= htmlspecialchars($student['truong'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="border:1px solid #ddd;padding:8px;">
                                <a href="edit.php?id=<?= (int) $student['id'] ?>" class="btn btn-sm btn-warning" style="display:inline-block;padding:5px 8px;background:#ffc107;color:#212529;text-decoration:none;border-radius:4px;">Sửa</a>
                                <a href="delete.php?id=<?= (int) $student['id'] ?>" class="btn btn-sm btn-danger" style="display:inline-block;padding:5px 8px;background:#dc3545;color:#fff;text-decoration:none;border-radius:4px;">Xóa</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__.'/../../layout/footer.php'; ?>
