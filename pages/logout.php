<?php
// recovery_system/pages/logout.php
// POST + CSRF token only, so other sites can't log the admin out.

require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? null)) {
    admin_logout();
}

header('Location: ' . APP_BASE . '/pages/login.php');
exit;