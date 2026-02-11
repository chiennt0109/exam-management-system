<?php
require_once __DIR__.'/../../core/auth.php';
require_login();
require_role(['admin']);
require_once __DIR__.'/../../core/db.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rowsJson = $_POST['rows_json'] ?? '';
    $rows = json_decode($rowsJson, true);

    if (!is_array($rows) || empty($rows)) {
        $errors[] = 'Không có dữ liệu hợp lệ để import.';
    } else {
        $insertStmt = $pdo->prepare('INSERT INTO students (sbd, hoten, ngaysinh, lop, truong) VALUES (:sbd, :hoten, :ngaysinh, :lop, :truong)');
        $inserted = 0;

        foreach ($rows as $row) {
            $sbd = trim((string) ($row['sbd'] ?? ''));
            $hoten = trim((string) ($row['hoten'] ?? ''));
            $ngaysinh = trim((string) ($row['ngaysinh'] ?? ''));
            $lop = trim((string) ($row['lop'] ?? ''));
            $truong = trim((string) ($row['truong'] ?? ''));

            if ($sbd === '' || $hoten === '') {
                continue;
            }

            if ($ngaysinh !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ngaysinh)) {
                $ngaysinh = '';
            }

            $insertStmt->execute([
                ':sbd' => $sbd,
                ':hoten' => $hoten,
                ':ngaysinh' => $ngaysinh,
                ':lop' => $lop,
                ':truong' => $truong
            ]);
            $inserted++;
        }

        header('Location: index.php?msg=' . ($inserted > 0 ? 'created' : 'none_inserted'));
        exit;
    }
}

require_once __DIR__.'/../../layout/header.php';
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
    .import-window {
        background: #fff;
        border: 1px solid #dbe3ec;
        border-radius: 16px;
        box-shadow: 0 12px 28px rgba(44,62,80,.15);
        overflow: hidden;
    }
    .import-titlebar {
        background: linear-gradient(135deg, #0ea5e9, #0284c7);
        color: #fff;
        padding: 12px 16px;
        display:flex;
        justify-content:space-between;
    }
    .import-body { padding: 18px; background: #f4f8fc; }
    .notice { padding:10px; border-radius:8px; margin-bottom:12px; background:#fee2e2; color:#991b1b; }
    .card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px; margin-bottom:12px; }
    .btn {
        display:inline-flex; align-items:center; justify-content:center;
        border:none; border-radius:8px; padding:10px 12px; color:#fff; text-decoration:none; cursor:pointer;
    }
    .btn-primary { background:#2563eb; }
    .btn-success { background:#16a34a; }
    .btn-secondary { background:#64748b; }
    table { width:100%; border-collapse:collapse; background:#fff; }
    th, td { border:1px solid #e5e7eb; padding:8px; }
    th { background:#eff6ff; color:#1d4ed8; }
</style>

<div class="students-layout">
    <?php require_once __DIR__.'/../../layout/sidebar.php'; ?>

    <div class="students-main">
        <div class="import-window">
            <div class="import-titlebar">
                <strong>Import học sinh từ Excel</strong>
                <span>📥</span>
            </div>
            <div class="import-body">
                <?php if (!empty($errors)): ?>
                    <div class="notice">
                        <ul style="margin:0; padding-left:18px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <p style="margin-top:0;"><strong>Định dạng cột gợi ý trong Excel:</strong> <code>sbd</code>, <code>hoten</code>, <code>ngaysinh</code>, <code>lop</code>, <code>truong</code>.</p>
                    <input type="file" id="excelFile" accept=".xlsx,.xls" style="margin-bottom:8px;">
                    <button type="button" class="btn btn-primary" onclick="loadExcel()">📂 Tải file</button>
                </div>

                <form method="post" id="importForm">
                    <input type="hidden" name="rows_json" id="rowsJson">
                    <div class="card">
                        <h4 style="margin-top:0;">Xem trước dữ liệu</h4>
                        <div style="overflow:auto; max-height:380px;">
                            <table id="previewTable">
                                <thead>
                                    <tr>
                                        <th>SBD</th><th>Họ tên</th><th>Ngày sinh</th><th>Lớp</th><th>Trường</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="5" style="text-align:center;">Chưa có dữ liệu.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div style="display:flex; gap:8px;">
                        <button type="submit" class="btn btn-success" onclick="return beforeSubmit()">✅ Import vào cơ sở dữ liệu</button>
                        <a href="index.php" class="btn btn-secondary">↩ Quay lại danh sách</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
    let normalizedRows = [];

    function normalizeDate(value) {
        if (!value) return '';
        if (typeof value === 'number') {
            const d = XLSX.SSF.parse_date_code(value);
            if (!d) return '';
            const mm = String(d.m).padStart(2, '0');
            const dd = String(d.d).padStart(2, '0');
            return `${d.y}-${mm}-${dd}`;
        }
        const text = String(value).trim();
        const m1 = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (m1) return text;
        const m2 = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (m2) return `${m2[3]}-${String(m2[2]).padStart(2, '0')}-${String(m2[1]).padStart(2, '0')}`;
        return '';
    }

    function mapHeaders(row) {
        return row.map(v => String(v || '').trim().toLowerCase());
    }

    function getIndex(headers, aliases) {
        for (let i = 0; i < headers.length; i++) {
            if (aliases.includes(headers[i])) return i;
        }
        return -1;
    }

    function loadExcel() {
        const fileInput = document.getElementById('excelFile');
        const file = fileInput.files[0];
        if (!file) {
            alert('Vui lòng chọn file Excel.');
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const sheetName = workbook.SheetNames[0];
            const rows = XLSX.utils.sheet_to_json(workbook.Sheets[sheetName], { header: 1, defval: '' });

            if (!rows.length) {
                alert('File Excel trống.');
                return;
            }

            const headers = mapHeaders(rows[0]);
            const idxSbd = getIndex(headers, ['sbd', 'mahs', 'mã hs', 'mã học sinh']);
            const idxHoten = getIndex(headers, ['hoten', 'họ tên', 'ho ten', 'fullname']);
            const idxNgaySinh = getIndex(headers, ['ngaysinh', 'ngày sinh', 'dob']);
            const idxLop = getIndex(headers, ['lop', 'lớp', 'malop', 'mã lớp']);
            const idxTruong = getIndex(headers, ['truong', 'trường', 'school']);

            if (idxSbd < 0 || idxHoten < 0) {
                alert('Không tìm thấy cột bắt buộc: sbd và hoten.');
                return;
            }

            normalizedRows = rows.slice(1).map(function(r) {
                return {
                    sbd: String(r[idxSbd] || '').trim(),
                    hoten: String(r[idxHoten] || '').trim(),
                    ngaysinh: idxNgaySinh >= 0 ? normalizeDate(r[idxNgaySinh]) : '',
                    lop: idxLop >= 0 ? String(r[idxLop] || '').trim() : '',
                    truong: idxTruong >= 0 ? String(r[idxTruong] || '').trim() : ''
                };
            }).filter(function(r) {
                return r.sbd !== '' && r.hoten !== '';
            });

            renderPreview();
        };

        reader.readAsArrayBuffer(file);
    }

    function renderPreview() {
        const tbody = document.querySelector('#previewTable tbody');
        tbody.innerHTML = '';

        if (!normalizedRows.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Không có dòng hợp lệ.</td></tr>';
            return;
        }

        normalizedRows.slice(0, 300).forEach(function(row) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${escapeHtml(row.sbd)}</td><td>${escapeHtml(row.hoten)}</td><td>${escapeHtml(row.ngaysinh)}</td><td>${escapeHtml(row.lop)}</td><td>${escapeHtml(row.truong)}</td>`;
            tbody.appendChild(tr);
        });
    }

    function beforeSubmit() {
        if (!normalizedRows.length) {
            alert('Chưa có dữ liệu để import.');
            return false;
        }
        document.getElementById('rowsJson').value = JSON.stringify(normalizedRows);
        return confirm(`Xác nhận import ${normalizedRows.length} học sinh vào cơ sở dữ liệu?`);
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
</script>

<?php require_once __DIR__.'/../../layout/footer.php'; ?>
