<?php
// recovery_system/includes/sync_engine.php
// Copies data from the live portal (through the API) into the backup database.
//
// Immutable tables (questions, student_results): insert-only, driven by an id cursor.
//   An existing backup row is NEVER overwritten, so if someone edits a score on the
//   live side, the backup keeps the original and the integrity check can flag it.
// Mutable tables (exams, registered_students): new rows are picked up every run, and
//   everything is fully re-copied (upserted) every FULL_REFRESH_MINUTES.
//
// The caller must hold the 'sync' lock (see cron/sync_cli.php).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/integrity.php';

const SYNC_MAX_BATCHES = 200;   // safety cap per table per run (200 x batch size rows)

function sync_ts(): string
{
    return date('Y-m-d H:i:s');
}

// Returns ['status' => success|partial|failed, 'rows' => int, 'message' => string,
//          'errors' => string[], 'notes' => string[]]
function sync_run(string $source = 'cron', bool $forceFull = false): array
{
    $pdo    = db();
    $source = $source === 'manual' ? 'manual' : 'cron';

    // Because the caller holds the lock, any run still marked "running" was cut off by a crash
    $pdo->prepare(
        "UPDATE sync_log SET status = 'failed', finished_at = ?, message = 'Interrupted before it finished.'
          WHERE status = 'running'"
    )->execute([sync_ts()]);

    $pdo->prepare("INSERT INTO sync_log (started_at, source, status) VALUES (?, ?, 'running')")
        ->execute([sync_ts(), $source]);
    $logId = (int) $pdo->lastInsertId();

    $total    = 0;
    $okTables = 0;
    $errors   = [];
    $notes    = [];

    // One health call first: fails fast if the portal is down, and tells us
    // which tables are mutable (the live API is the single source of truth for that)
    $h = api_health();
    if (!$h['ok']) {
        $errors[] = 'Live portal check failed: ' . $h['error'];
    } else {
        foreach (array_keys(MIRROR_COLUMNS) as $table) {
            $mutable = (bool) ($h['data']['tables'][$table]['mutable'] ?? false);
            try {
                $r = sync_table($pdo, $table, $mutable, $forceFull);
                $total += $r['changed'];
                $okTables++;
                $notes[] = $table . ' ' . $r['changed']
                    . ($r['full'] ? ' (full refresh)' : '')
                    . ($r['more'] ? ' (more waiting, continues next run)' : '');
            } catch (Throwable $e) {
                $errors[] = $table . ': ' . $e->getMessage();
            }
        }
    }

    $status  = !$errors ? 'success' : ($okTables > 0 ? 'partial' : 'failed');
    $message = mb_substr(implode('; ', array_merge($errors, $notes)), 0, 500);

    $pdo->prepare('UPDATE sync_log SET finished_at = ?, status = ?, rows_copied = ?, message = ? WHERE id = ?')
        ->execute([sync_ts(), $status, $total, $message, $logId]);

    return ['status' => $status, 'rows' => $total, 'message' => $message, 'errors' => $errors, 'notes' => $notes];
}

// Copies one table. Each batch is saved in a transaction together with the cursor,
// so a crash mid-way loses nothing and repeats nothing.
// Returns ['changed' => rows inserted or updated, 'full' => bool, 'more' => bool]
function sync_table(PDO $pdo, string $table, bool $mutable, bool $forceFull): array
{
    $cols = MIRROR_COLUMNS[$table];

    $st = $pdo->prepare('SELECT last_id, last_full_refresh FROM sync_state WHERE table_name = ?');
    $st->execute([$table]);
    $state = $st->fetch();
    if (!$state) {
        throw new RuntimeException('no sync_state row for this table');
    }
    $cursor = (int) $state['last_id'];

    $full = false;
    if ($mutable) {
        $last = $state['last_full_refresh'];
        $full = $forceFull || $last === null || strtotime($last) < time() - FULL_REFRESH_MINUTES * 60;
    }
    $after = $full ? 0 : $cursor;

    // INSERT ... ON DUPLICATE KEY UPDATE:
    //  - immutable: "id = id" changes nothing, so an existing row is left exactly as it is
    //  - mutable: updates the data columns, and only counts as changed if something differs
    $insertCols = array_merge($cols, ['row_hash', 'synced_at']);
    $colSql     = '`' . implode('`, `', $insertCols) . '`';
    $marks      = implode(', ', array_fill(0, count($insertCols), '?'));

    if ($mutable) {
        $sets = [];
        foreach ($insertCols as $c) {
            if ($c !== 'id' && $c !== 'synced_at') {
                $sets[] = "`$c` = VALUES(`$c`)";
            }
        }
        $dup = implode(', ', $sets);
    } else {
        $dup = '`id` = `id`';
    }

    $ins = $pdo->prepare("INSERT INTO `$table` ($colSql) VALUES ($marks) ON DUPLICATE KEY UPDATE $dup");
    $upd = $pdo->prepare('UPDATE sync_state SET last_id = ?, updated_at = ? WHERE table_name = ?');

    $changed = 0;
    $batches = 0;
    $more    = false;

    while (true) {
        $f = api_fetch($table, $after, SYNC_BATCH_SIZE);
        if (!$f['ok']) {
            throw new RuntimeException((string) $f['error']);
        }

        $rows = $f['data']['rows'] ?? [];
        if (!$rows) {
            break;
        }

        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                $hash = $r['_hash'] ?? '';
                unset($r['_hash']);

                // The row must still match the fingerprint the live portal gave it.
                // If not, it was damaged in transit and nothing from this batch is saved.
                if (!is_string($hash) || !hash_equals($hash, row_hash($r, $cols))) {
                    throw new RuntimeException('row ' . ($r['id'] ?? '?') . ' failed its integrity check');
                }

                $values = [];
                foreach ($cols as $c) {
                    $values[] = $r[$c] ?? null;
                }
                $values[] = $hash;
                $values[] = sync_ts();

                $ins->execute($values);
                if ($ins->rowCount() > 0) {
                    $changed++;
                }
            }

            $after  = (int) $f['data']['last_id'];
            $cursor = max($cursor, $after);   // a full refresh never moves the cursor backwards
            $upd->execute([$cursor, sync_ts(), $table]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if (!($f['data']['has_more'] ?? false)) {
            break;
        }
        if (++$batches >= SYNC_MAX_BATCHES) {
            $more = true;
            break;
        }
    }

    if ($full && !$more) {
        $pdo->prepare('UPDATE sync_state SET last_full_refresh = ? WHERE table_name = ?')
            ->execute([sync_ts(), $table]);
    }

    return ['changed' => $changed, 'full' => $full, 'more' => $more];
}