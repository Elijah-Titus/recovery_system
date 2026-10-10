<?php
// recovery_system/pages/login.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (admin_is_logged_in()) {
    header('Location: ' . APP_BASE . '/index.php');
    exit;
}

const MAX_ATTEMPTS = 5;
const LOCK_SECONDS = 300;

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $attempts  = $_SESSION['login_attempts'] ?? 0;
    $lockUntil = $_SESSION['login_lock_until'] ?? 0;

    if ($lockUntil > time()) {
        $error = 'Too many failed attempts. Please try again in a few minutes.';
    } elseif (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Enter your email and password.';
    } else {
        $stmt = db()->prepare('SELECT id, full_name, password_hash FROM admins WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            unset($_SESSION['login_attempts'], $_SESSION['login_lock_until']);
            admin_login($admin);
            header('Location: ' . APP_BASE . '/index.php');
            exit;
        }

        $attempts++;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= MAX_ATTEMPTS) {
            $_SESSION['login_lock_until'] = time() + LOCK_SECONDS;
            $_SESSION['login_attempts']   = 0;
        }
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Recovery System</title>
    <link rel="stylesheet" href="<?= e(APP_BASE) ?>/assets/css/style.css">
</head>
<body class="auth-body">
    <div class="auth-card">
        <div class="auth-logo">RS</div>
        <h1>Recovery System</h1>
        <p class="muted">Administrator login</p>

        <?php if ($error !== ''): ?>
            <div class="flash flash-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(APP_BASE) ?>/pages/login.php" class="form" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>
    </div>
</body>
</html>