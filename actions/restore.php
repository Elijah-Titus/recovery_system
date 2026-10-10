<?php
// recovery_system/actions/restore.php
// Receives the confirmed restore from pages/recovery.php. Admin only, POST only, CSRF-protected.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lock.php';
require_once __DIR__ . '/../includes/restore_engine.php';
require_once __DIR__ . '/../includes/health_engine.php';

require_admin();

$back = APP_BASE . '/pages/recovery.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? null)) {
    header('Location: ' . $back);
    exit;
}

if (empty($_POST['confirm'])) {
    flash_set('warning', 'Tick the confirmation box before restoring.');
    header('Location: ' . $back);
    exit;
}

// Only table names from the fixed list are accepted
$requested = $_POST['tables'] ?? [];
$requested = is_array($requested) ? array_map('strval', $requested) : [];
$tables    = array_values(array_intersect(RESTORE_ORDER, $requested));

if (!$tables) {
    flash_set('warning', 'Select at least one table to restore.');
    header('Location: ' . $back);
    exit;
}

set_time_limit(300);
ignore_user_abort(true);

$lock = acquire_lock('restore');
if ($lock === null) {
    flash_set('warning', 'A restore is already running. Wait for it to finish.');
    header('Location: ' . $back);
    exit;
}

try {
    $pdo = db();
    $run = restore_run($pdo, $tables, (int) ($_SESSION['admin_id'] ?? 0), (string) ($_SESSION['admin_name'] ?? 'Admin'));

    if ($run['error'] !== null) {
        flash_set('error', 'Restore did not start: ' . $run['error']);
    } else {
        $parts = [];
        foreach ($run['results'] as $t => $r) {
            $p = str_replace('_', ' ', $t) . ': ' . $r['inserted'] . ' restored';
            if ($r['skipped'] > 0) { $p .= ', ' . $r['skipped'] . ' already existed'; }
            if ($r['damaged'] > 0) { $p .= ', ' . $r['damaged'] . ' skipped (damaged backup copy)'; }
            if ($r['error'] !== null) { $p .= '. FAILED: ' . $r['error']; }
            $parts[] = $p;
        }

        // Check what is still missing among the tables that were selected
        $after     = restore_scan($pdo);
        $remaining = 0;
        if ($after['ok']) {
            foreach ($tables as $t) {
                $remaining += count($after['tables'][$t]['missing']);
            }
        }

        $text = $parts ? implode('. ', $parts) . '.' : 'Nothing needed restoring.';
        if ($after['ok']) {
            $text .= $remaining === 0 ? ' Nothing is missing any more.' : " $remaining record(s) are still missing.";
        }
        flash_set($run['ok'] && $remaining === 0 ? 'success' : 'warning', $text);
    }

    // Record a fresh health check straight away, so the dashboard reflects the restore
    try { health_run(); } catch (Throwable $e) { /* the scheduled check will catch up */ }
} catch (Throwable $e) {
    error_log('Restore: ' . $e->getMessage());
    flash_set('error', 'The restore could not be completed. Check the logs.');
}

release_lock($lock);
header('Location: ' . $back);
exit;