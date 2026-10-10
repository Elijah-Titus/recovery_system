<?php
// recovery_system/includes/layout/header.php
// Expects: $pageTitle (string), $active ('overview' | 'recovery'), optional $refreshSeconds (int)
$adminName = $_SESSION['admin_name'] ?? 'Admin';
$base      = APP_BASE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | Recovery System</title>
    <?php if (!empty($refreshSeconds)): ?>
        <meta http-equiv="refresh" content="<?= (int) $refreshSeconds ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e($base) ?>/assets/css/style.css">
</head>
<body class="app">

<header class="topbar">
    <div class="topbar-inner">
        <a href="<?= e($base) ?>/index.php" class="brand"><span class="brand-mark">RS</span> Recovery System</a>

        <nav class="nav">
            <a href="<?= e($base) ?>/index.php" class="<?= ($active ?? '') === 'overview' ? 'is-active' : '' ?>">Overview</a>
            <a href="<?= e($base) ?>/pages/recovery.php" class="<?= ($active ?? '') === 'recovery' ? 'is-active' : '' ?>">Recovery</a>
        </nav>

        <div class="topbar-user">
            <span class="user-name"><?= e($adminName) ?></span>
            <form method="post" action="<?= e($base) ?>/pages/logout.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <button type="submit" class="btn btn-outline btn-sm">Logout</button>
            </form>
        </div>
    </div>
</header>

<main class="page">