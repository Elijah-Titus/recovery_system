<?php
// recovery_system/cron/sync_cli.php
// Scheduled sync. Run from the command line only.
//   php cron/sync_cli.php          normal run
//   php cron/sync_cli.php --full   also force a full refresh of the mutable tables

if (PHP_SAPI !== 'cli') { exit('Run this from the command line.'); }

require_once __DIR__ . '/../includes/lock.php';
require_once __DIR__ . '/../includes/sync_engine.php';

$lock = acquire_lock('sync');
if ($lock === null) {
    echo date('c') . " Another sync is still running; skipping this run.\n";
    exit(0);
}

try {
    $result = sync_run('cron', in_array('--full', $argv ?? [], true));
} catch (Throwable $e) {
    echo date('c') . ' ERROR: ' . $e->getMessage() . "\n";
    release_lock($lock);
    exit(2);
}

echo date('c') . ' ' . strtoupper($result['status']) . ' - ' . $result['rows'] . " row(s) copied\n";
foreach ($result['notes'] as $n)  { echo "  $n\n"; }
foreach ($result['errors'] as $e) { echo "  ERROR: $e\n"; }

release_lock($lock);
exit($result['status'] === 'success' ? 0 : 1);