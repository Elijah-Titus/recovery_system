<?php
// recovery_system/cron/health_check.php
// Scheduled health check. Run from the command line only.
//   php cron/health_check.php
// Exit code: 0 = portal up, 1 = portal down, 2 = the monitor itself failed.

if (PHP_SAPI !== 'cli') { exit('Run this from the command line.'); }

require_once __DIR__ . '/../includes/health_engine.php';

try {
    $r = health_run();
    $s = monitor_snapshot(db());
} catch (Throwable $e) {
    echo date('c') . ' MONITOR ERROR: ' . $e->getMessage() . "\n";
    exit(2);
}

if ($r['is_up']) {
    $parts = [];
    foreach ($r['counts'] as $t => $c) {
        $parts[] = "$t $c";
    }
    echo date('c') . ' UP   ' . $r['ms'] . ' ms  (' . implode(', ', $parts) . ")\n";
} else {
    echo date('c') . ' DOWN  ' . $r['error'] . "\n";
}

echo 'Overall: ' . strtoupper($s['overall']) . "\n";
foreach ($s['alerts'] as $a) {
    echo '  [' . $a['level'] . '] ' . $a['message'] . "\n";
}

exit($r['is_up'] ? 0 : 1);