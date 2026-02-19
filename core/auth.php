<?php
require_once __DIR__ . '/../bootstrap.php';
session_start();
require_once BASE_PATH . '/core/db.php';

function isWriteRequest(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function normalize_role(?string $role): string {
    $normalized = strtolower(trim((string) $role));

    return match ($normalized) {
        'score_entry', 'score-input', 'score_input', 'nhapdiem', 'nhap_diem' => 'scorer',
        'exam_manager', 'quanlythi', 'exam-manager' => 'organizer',
        default => $normalized,
    };
}

function current_user_role(): string {
    $sessionRole = $_SESSION['role'] ?? null;
    if (is_string($sessionRole) && trim($sessionRole) !== '') {
        return normalize_role($sessionRole);
    }

    $userRole = $_SESSION['user']['role'] ?? null;
    return normalize_role(is_string($userRole) ? $userRole : '');
}

function resolve_default_exam_id(PDO $pdo): int {
    try {
        $examCols = array_column($pdo->query('PRAGMA table_info(exams)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('is_default', $examCols, true)) {
            // Cột này được tạo bởi init_db/migration. Tránh thay đổi schema trong luồng đăng nhập.
            return 0;
        }

        $whereActive = in_array('deleted_at', $examCols, true)
            ? 'WHERE (deleted_at IS NULL OR trim(deleted_at) = "")'
            : 'WHERE 1=1';

        $defaultStmt = $pdo->query('SELECT id FROM exams ' . $whereActive . ' AND is_default = 1 ORDER BY id DESC LIMIT 1');
        $defaultExamId = (int) ($defaultStmt->fetchColumn() ?: 0);
        if ($defaultExamId > 0) {
            return $defaultExamId;
        }

        $countStmt = $pdo->query('SELECT COUNT(*) FROM exams ' . $whereActive);
        $activeCount = (int) ($countStmt->fetchColumn() ?: 0);
        if ($activeCount === 1) {
            $onlyStmt = $pdo->query('SELECT id FROM exams ' . $whereActive . ' ORDER BY id DESC LIMIT 1');
            $onlyId = (int) ($onlyStmt->fetchColumn() ?: 0);
            if ($onlyId > 0) {
                $pdo->beginTransaction();
                $pdo->exec('UPDATE exams SET is_default = 0');
                $up = $pdo->prepare('UPDATE exams SET is_default = 1 WHERE id = :id');
                $up->execute([':id' => $onlyId]);
                $pdo->commit();
                return $onlyId;
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    return 0;
}

function is_maintenance_mode(): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => 'maintenance_mode']);
        return ((string) $stmt->fetchColumn()) === '1';
    } catch (Throwable $e) {
        return false;
    }
}

/* ========= LOGIN ========= */
function login($username, $password) {
    global $pdo;

    $username = trim((string) $username);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE lower(trim(username)) = lower(trim(?)) AND active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return false;

    $isValid = false;
    if (is_string($user['password']) && password_verify($password, $user['password'])) {
        $isValid = true;
    } else {
        $salt = 'exam_system_salt';
        $hash = hash('sha256', $password . $salt);
        $isValid = ($hash === $user['password']);
        if (!$isValid) {
            $isValid = hash('sha256', $password) === (string) $user['password'];
        }
        if (!$isValid) {
            $isValid = (string) $user['password'] === (string) $password;
        }
    }

    if (!$isValid) {
        return false;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $role = normalize_role((string) ($user['role'] ?? ''));
    $_SESSION['user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'role' => $role,
    ];
    // Keep lightweight mirror for legacy code/sidebar compatibility
    $_SESSION['role'] = $role;

    // Only show maintenance notice to non-admins, but do not block login/dashboard read access.
    if (is_maintenance_mode() && $role !== 'admin') {
        $_SESSION['maintenance_notice'] = 'Kỳ thi đang được mở bởi quản trị viên. Vui lòng chờ.';
    }

    // Kỳ thi mặc định là cấu hình toàn cục theo DB, không lưu theo session.

    return true;
}

/* ========= LOGOUT ========= */
function logout() {
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

/* ========= MIDDLEWARE ========= */
function require_login() {
    global $pdo;

    if (!isset($_SESSION['user'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    if (!isset($_SESSION['current_exam_id']) || (int) $_SESSION['current_exam_id'] <= 0) {
        $defaultExamId = resolve_default_exam_id($pdo);
        if ($defaultExamId > 0) {
            $_SESSION['current_exam_id'] = $defaultExamId;
        }
    }
}

function require_role(array $roles) {
    require_login();
    $role = current_user_role();
    $allowed = array_map(static fn($r): string => normalize_role((string) $r), $roles);
    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        echo '⛔ Bạn không có quyền truy cập chức năng này';
        exit;
    }
}
