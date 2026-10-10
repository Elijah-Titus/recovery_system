<?php
// recovery_system/includes/health_engine.php
// health_run()       : one check of the live portal, saved to health_log
// monitor_snapshot() : reads the logs and answers "is everything okay?" (used by the dashboard too)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/integrity.php';

const HEALTH_KEEP_DAYS = 30;   // older health_log rows are deleted

// ---------------------------------------------------------------------------
// One health check
// Returns ['is_up' => bool, 'status' => ?int, 'ms' => int, 'error' => ?string, 'counts' => ?array]
// ---------------------------------------------------------------------------
function health_run(): array
{
    $pdo = db();
    $r   = api_health();

    // "Up" means the portal answered AND its own database is reachable
    $isUp = $r['ok'] && (($r['data']['db'] ?? false) === true);

    $counts = null;
    if ($isUp) {
        $counts = [];
        foreach (($r['data']['tables'] ?? []) as $name => $t) {
            $counts[$name] = (int) ($t['count'] ?? 0);
        }
    }
    $error = $isUp ? null : mb_substr((string) ($r['error'] ?? 'Unknown problem'), 0, 255);

    $pdo->prepare(
        'INSERT INTO health_log (checked_at, is_up, http_status, response_ms, counts_json, error)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        date('Y-m-d H:i:s'),
        $isUp ? 1 : 0,
        $r['status'] > 0 ? $r['status'] : null,
        $r['ms'],
        $counts !== null ? json_encode($counts) : null,
        $error,
    ]);

    // Housekeeping: drop old rows (indexed, so this is cheap)
    $pdo->prepare('DELETE FROM health_log WHERE checked_at < ?')
        ->execute([date('Y-m-d H:i:s', time() - HEALTH_KEEP_DAYS * 86400)]);

    return ['is_up' => $isUp, 'status' => $r['status'], 'ms' => $r['ms'], 'error' => $error, 'counts' => $counts];
}

// ---------------------------------------------------------------------------
// Overall picture. Returns:
//   overall      'ok' | 'warning' | 'critical'
//   alerts       [['level' => 'warning'|'critical', 'message' => string], ...]
//   portal       state 'up'|'degraded'|'down'|'unknown', fails, last_check, last_ok, response_ms, error, check_age_min
//   sync         last_status, last_finished, last_success, age_min, stale
//   live_counts  from the latest successful health check (null if none)
//   backup_counts
// ---------------------------------------------------------------------------
function monitor_snapshot(PDO $pdo): array
{
    $alerts  = [];
    $overall = 'ok';
    $raise = function (string $level, string $message) use (&$alerts, &$overall): void {
        $alerts[] = ['level' => $level, 'message' => $message];
        if ($level === 'critical') {
            $overall = 'critical';
        } elseif ($overall === 'ok') {
            $overall = 'warning';
        }
    };

    // ---------- Live portal ----------
    $recent = $pdo->query(
        'SELECT checked_at, is_up, http_status, response_ms, counts_json, error
           FROM health_log ORDER BY id DESC LIMIT 100'
    )->fetchAll();
    $last = $recent[0] ?? null;

    // Consecutive failures counted back from the newest check, and the two newest successful checks
    $fails   = 0;
    $leading = true;
    $upRows  = [];
    foreach ($recent as $row) {
        if ((int) $row['is_up'] === 1) {
            $leading = false;
            $upRows[] = $row;
            if (count($upRows) >= 2) {
                break;
            }
        } elseif ($leading) {
            $fails++;
        }
    }
    $liveCounts = isset($upRows[0]) ? json_decode((string) $upRows[0]['counts_json'], true) : null;
    $prevCounts = isset($upRows[1]) ? json_decode((string) $upRows[1]['counts_json'], true) : null;

    $state    = 'unknown';
    $checkAge = null;

    if ($last === null) {
        $raise('warning', 'No health checks have been recorded yet. Make sure cron/health_check.php is scheduled.');
    } else {
        $checkAge = (int) floor((time() - strtotime($last['checked_at'])) / 60);

        if ($checkAge > HEALTH_STALE_MINUTES) {
            $raise('warning', "The last health check was $checkAge minute(s) ago. Health checks may have stopped running.");
        } elseif ($fails >= HEALTH_FAILS_ALERT) {
            $state = 'down';
            $raise('critical', "The live portal is DOWN ($fails failed checks in a row). Last error: " . ($last['error'] ?? 'unknown'));
        } elseif ($fails > 0) {
            $state = 'degraded';
            $raise('warning', "The last $fails health check(s) failed: " . ($last['error'] ?? 'unknown'));
        } else {
            $state = 'up';
        }
    }

    // ---------- Backup counts ----------
    $backupCounts = [];
    foreach (array_keys(MIRROR_COLUMNS) as $t) {   // names come from a constant, never from input
        $backupCounts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    }

    // ---------- Data-loss detection ----------
    if (is_array($liveCounts) && in_array($state, ['up', 'degraded'], true)) {
        foreach ($backupCounts as $t => $inBackup) {
            $live = (int) ($liveCounts[$t] ?? 0);
            if ($live < $inBackup) {
                $raise('critical', ($inBackup - $live) . " record(s) in $t exist in the backup but not on the live portal. Open Recovery to restore them.");
            }
        }
        if (is_array($prevCounts)) {
            foreach ($liveCounts as $t => $c) {
                $before = (int) ($prevCounts[$t] ?? 0);
                if ((int) $c < $before) {
                    $raise('critical', "$t on the live portal dropped from $before to $c between two health checks. Records may have been deleted.");
                }
            }
        }
    }

    // ---------- Sync ----------
    $lastSync = $pdo->query(
        'SELECT started_at, finished_at, status, rows_copied, message FROM sync_log ORDER BY id DESC LIMIT 1'
    )->fetch();
    $lastSuccess = $pdo->query(
        "SELECT finished_at FROM sync_log WHERE status = 'success' ORDER BY id DESC LIMIT 1"
    )->fetchColumn();

    $syncAge   = $lastSuccess ? (int) floor((time() - strtotime($lastSuccess)) / 60) : null;
    $syncStale = $syncAge === null || $syncAge > SYNC_STALE_MINUTES;

    if (!$lastSync) {
        $raise('warning', 'No sync has run yet.');
    } else {
        if ($syncStale) {
            $raise('warning', $syncAge === null
                ? 'No sync has completed successfully yet.'
                : "The last successful sync was $syncAge minute(s) ago.");
        }
        if (in_array($lastSync['status'], ['failed', 'partial'], true)) {
            $raise('warning', 'The latest sync ' . $lastSync['status'] . ': ' . ($lastSync['message'] ?? ''));
        }
    }

    return [
        'overall' => $overall,
        'alerts'  => $alerts,
        'portal'  => [
            'state'         => $state,
            'fails'         => $fails,
            'last_check'    => $last['checked_at'] ?? null,
            'last_ok'       => $upRows[0]['checked_at'] ?? null,
            'response_ms'   => isset($last['response_ms']) ? (int) $last['response_ms'] : null,
            'error'         => $last['error'] ?? null,
            'check_age_min' => $checkAge,
        ],
        'sync' => [
            'last_status'   => $lastSync['status'] ?? null,
            'last_finished' => $lastSync['finished_at'] ?? null,
            'last_success'  => $lastSuccess ?: null,
            'age_min'       => $syncAge,
            'stale'         => $syncStale,
        ],
        'live_counts'   => is_array($liveCounts) ? $liveCounts : null,
        'backup_counts' => $backupCounts,
    ];
}