<?php
// recovery_system/includes/restore_engine.php
// Finds what the live portal is missing and puts it back through the API.
//
// restore_scan() : compares live and backup (read-only, safe to call any time)
// restore_run()  : sends the missing rows back, parent tables first
//
// Insert-only. Rows that exist on the live portal are never overwritten. Rows that exist on
// both sides but differ (edited on live) are only reported, never "fixed" automatically.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/integrity.php';

// Parents before children: results and questions need their exam to exist first
const RESTORE_ORDER        = ['exams', 'questions', 'registered_students', 'student_results'];
const RESTORE_BATCH        = 200;   // rows per restore request (the live API accepts up to 500)
const RESTORE_PREVIEW_ROWS = 8;     // rows shown in the preview per table
const RESTORE_DIFF_ROWS    = 5;     // edited records shown in detail per table
const RESTORE_MAX_PAGES    = 100;   // safety cap when reading the live id list

// Columns shown in the preview (the answer key is never shown)
const RESTORE_PREVIEW_COLS = [
    'exams'               => ['id', 'title', 'duration_minutes'],
    'questions'           => ['id', 'exam_id', 'position', 'question_text'],
    'registered_students' => ['id', 'mat_number', 'full_name'],
    'student_results'     => ['id', 'exam_id', 'mat_number', 'score', 'total'],
];

// ---------------------------------------------------------------------------
// Reads every [id, hash] pair of one live table. Returns ['ok', 'error', 'map' => id => hash]
// ---------------------------------------------------------------------------
function restore_live_index(string $table): array
{
    $map   = [];
    $after = 0;

    for ($page = 0; $page < RESTORE_MAX_PAGES; $page++) {
        $r = api_ids($table, $after, 5000);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => (string) $r['error'], 'map' => []];
        }
        foreach (($r['data']['ids'] ?? []) as [$id, $hash]) {
            $map[(int) $id] = (string) $hash;
        }
        if (!($r['data']['has_more'] ?? false)) {
            return ['ok' => true, 'error' => null, 'map' => $map];
        }
        $after = (int) $r['data']['last_id'];
    }
    return ['ok' => false, 'error' => 'Too many records to scan.', 'map' => []];
}

// ---------------------------------------------------------------------------
// Compares live and backup.
// Returns ['ok', 'error', 'total_missing', 'total_changed',
//          'tables' => [table => ['mutable', 'live', 'backup', 'missing' => ids, 'changed' => ids]]]
// "changed" is only checked for tables that never change after insert
// (questions, student_results). Exams and students are edited legitimately.
// ---------------------------------------------------------------------------
function restore_scan(PDO $pdo): array
{
    $fail = fn(string $msg): array => [
        'ok' => false, 'error' => $msg, 'tables' => [], 'total_missing' => 0, 'total_changed' => 0,
    ];

    $h = api_health();
    if (!$h['ok']) {
        return $fail('The live portal is not reachable: ' . $h['error']);
    }

    $tables       = [];
    $totalMissing = 0;
    $totalChanged = 0;

    foreach (RESTORE_ORDER as $table) {
        $mutable = (bool) ($h['data']['tables'][$table]['mutable'] ?? false);

        $live = restore_live_index($table);
        if (!$live['ok']) {
            return $fail("Could not read the live $table: " . $live['error']);
        }

        $backup = $pdo->query("SELECT id, row_hash FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);

        $missing = [];
        $changed = [];
        foreach ($backup as $id => $hash) {
            $id = (int) $id;
            if (!isset($live['map'][$id])) {
                $missing[] = $id;
            } elseif (!$mutable && !hash_equals((string) $hash, $live['map'][$id])) {
                $changed[] = $id;
            }
        }

        $tables[$table] = [
            'mutable' => $mutable,
            'live'    => count($live['map']),
            'backup'  => count($backup),
            'missing' => $missing,
            'changed' => $changed,
        ];
        $totalMissing += count($missing);
        $totalChanged += count($changed);
    }

    return [
        'ok' => true, 'error' => null, 'tables' => $tables,
        'total_missing' => $totalMissing, 'total_changed' => $totalChanged,
    ];
}

// A few of the missing rows, straight from the backup, for the preview
function restore_preview_rows(PDO $pdo, string $table, array $ids): array
{
    $ids = array_slice($ids, 0, RESTORE_PREVIEW_ROWS);
    if (!$ids) {
        return [];
    }
    $colSql = '`' . implode('`, `', RESTORE_PREVIEW_COLS[$table]) . '`';
    $marks  = implode(', ', array_fill(0, count($ids), '?'));

    $st = $pdo->prepare("SELECT $colSql FROM `$table` WHERE id IN ($marks) ORDER BY id");
    $st->execute($ids);
    return $st->fetchAll();
}

// For records that exist on both sides but differ: shows backup value vs live value
function restore_diffs(PDO $pdo, string $table, array $ids): array
{
    $cols   = MIRROR_COLUMNS[$table];
    $colSql = '`' . implode('`, `', $cols) . '`';
    $out    = [];

    foreach (array_slice($ids, 0, RESTORE_DIFF_ROWS) as $id) {
        $st = $pdo->prepare("SELECT $colSql FROM `$table` WHERE id = ?");
        $st->execute([$id]);
        $backupRow = $st->fetch();

        $f       = api_fetch($table, $id - 1, 1);
        $liveRow = null;
        if ($f['ok'] && isset($f['data']['rows'][0]) && (int) $f['data']['rows'][0]['id'] === $id) {
            $liveRow = $f['data']['rows'][0];
        }

        $diff = [];
        if ($backupRow && $liveRow) {
            foreach ($cols as $c) {
                if ((string) ($backupRow[$c] ?? '') !== (string) ($liveRow[$c] ?? '')) {
                    $diff[$c] = [$backupRow[$c] ?? null, $liveRow[$c] ?? null];
                }
            }
        }
        $out[] = ['id' => $id, 'diff' => $diff, 'found' => $liveRow !== null];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Restores the missing rows of the chosen tables (parents first). The list of what is
// missing is recomputed here, so nothing from the browser decides which rows are sent.
// Returns ['ok', 'error', 'results' => [table => [sent, inserted, skipped, damaged, error]]]
// ---------------------------------------------------------------------------
function restore_run(PDO $pdo, array $tables, int $adminId, string $adminName): array
{
    $scan = restore_scan($pdo);
    if (!$scan['ok']) {
        return ['ok' => false, 'error' => $scan['error'], 'results' => []];
    }

    $results = [];
    $allOk   = true;

    foreach (RESTORE_ORDER as $table) {
        if (!in_array($table, $tables, true)) {
            continue;
        }
        $ids = $scan['tables'][$table]['missing'];
        if (!$ids) {
            continue;
        }

        $cols    = MIRROR_COLUMNS[$table];
        $colSql  = '`' . implode('`, `', array_merge($cols, ['row_hash'])) . '`';
        $sent = $inserted = $skipped = $damaged = 0;
        $error   = null;

        foreach (array_chunk($ids, RESTORE_BATCH) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $st    = $pdo->prepare("SELECT $colSql FROM `$table` WHERE id IN ($marks) ORDER BY id");
            $st->execute($chunk);

            $rows = [];
            foreach ($st->fetchAll() as $r) {
                // Never send a backup row that no longer matches its own fingerprint
                if (!backup_row_is_intact($table, $r)) {
                    $damaged++;
                    continue;
                }
                unset($r['row_hash']);
                $rows[] = $r;
            }
            if (!$rows) {
                continue;
            }

            $res = api_restore($table, $rows);
            if (!$res['ok']) {
                $error = (string) $res['error'];
                break;
            }
            $sent     += count($rows);
            $inserted += (int) ($res['data']['inserted'] ?? 0);
            $skipped  += (int) ($res['data']['skipped'] ?? 0);
        }

        $failed  = $error !== null || $damaged > 0;
        $message = $error !== null
            ? $error
            : ($damaged > 0 ? "$damaged backup record(s) failed their integrity check and were not restored." : null);

        $pdo->prepare(
            'INSERT INTO restore_log
                (restored_at, admin_id, admin_name, table_name, rows_sent, rows_inserted, rows_skipped, status, message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            date('Y-m-d H:i:s'), $adminId ?: null, $adminName, $table,
            $sent, $inserted, $skipped, $failed ? 'failed' : 'success',
            $message !== null ? mb_substr($message, 0, 500) : null,
        ]);

        $results[$table] = [
            'sent' => $sent, 'inserted' => $inserted, 'skipped' => $skipped,
            'damaged' => $damaged, 'error' => $error,
        ];

        if ($failed) {
            $allOk = false;
        }
        if ($error !== null) {
            break;   // later tables may depend on this one, so stop here
        }
    }

    return ['ok' => $allOk, 'error' => null, 'results' => $results];
}