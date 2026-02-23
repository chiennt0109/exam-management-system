<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';

require_once BASE_PATH . '/core/auth.php';
require_login();
require_role(['admin']);
require_once BASE_PATH . '/core/db.php';

const USER_ALLOWED_ROLES = ['admin', 'organizer', 'scorer'];

function users_get_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function users_verify_csrf_token(?string $token): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '' || !is_string($token)) {
        return false;
    }

    return hash_equals($sessionToken, $token);
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function display_flash(): string
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    if (!is_array($flash) || !isset($flash['type'], $flash['message'])) {
        return '';
    }

    $type = (string) $flash['type'];
    $message = (string) $flash['message'];

    $map = [
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        'info' => 'alert-info',
    ];
    $class = $map[$type] ?? 'alert-info';

    return '<div class="alert ' . $class . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
}

function users_badge_class(string $role): string
{
    return match ($role) {
        'admin' => 'bg-danger',
        'organizer' => 'bg-primary',
        'scorer' => 'bg-success',
        default => 'bg-secondary',
    };
}

function users_has_created_at(PDO $pdo): bool
{
    $stmt = $pdo->query("PRAGMA table_info(users)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'created_at') {
            return true;
        }
    }

    return false;
}
