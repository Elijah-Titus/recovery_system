<?php
// recovery_system/includes/auth.php
// Admin session handling, CSRF helpers and one-time flash messages.

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/helpers.php';

const ADMIN_IDLE_SECONDS = 1800;   // logged out after 30 minutes without activity

if (session_status() === PHP_SESSION_NONE) {
    session_name('RECOVERY_ADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function admin_is_logged_in(): bool
{
    if (empty($_SESSION['admin_id'])) {
        return false;
    }
    if (time() - (int) ($_SESSION['last_activity'] ?? 0) > ADMIN_IDLE_SECONDS) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

// Call at the top of every protected page.
function require_admin(): void
{
    if (!admin_is_logged_in()) {
        header('Location: ' . APP_BASE . '/pages/login.php');
        exit;
    }
}

function admin_login(array $admin): void
{
    session_regenerate_id(true);   // prevents session fixation
    $_SESSION['admin_id']      = (int) $admin['id'];
    $_SESSION['admin_name']    = $admin['full_name'];
    $_SESSION['last_activity'] = time();
}

function admin_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool
{
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

function flash_set(string $type, string $text): void
{
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

function flash_get(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}