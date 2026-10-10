<?php
// recovery_system/pages/recovery.php
// Shows what is missing from the live portal, previews it, and restores it after confirmation.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restore_engine.php';

require_admin();
set_time_limit(120);

$pdo  = db();
$scan = restore_scan($pdo);

$labels = [
    'exams'               => 'Exams',
    'questions'           => 'Questions',
    'registered_students' => 'Registered students',
    'student_results'     => 'Student results',
];

$history = $pdo->query(
    'SELECT restored_at, admin_name, table_name, rows_sent, rows_inserted, rows_skipped, status, message
       FROM restore_log ORDER BY id DESC LIMIT 10'
)->fetchAll();

// Short, safe text for table cells
$cell = fn($v): string => $v === null ? '-' : e(mb_strimwidth((string) $v, 0, 60, '…'));

$pageTitle = 'Recovery';
$active    = 'recovery';
require __DIR__ . '/../includes/layout/header.php';
?>

<h1 class="page-title">Recovery</h1>
<p class="muted lead">Compares the live portal with the backup and puts back any records that are missing. Existing records are never overwritten.</p>

<?php if ($flash = flash_get()): ?>
    <div class="flash flash-<?= e($flash['type']) ?>" role="status"><?= e($flash['text']) ?></div>
<?php endif; ?>

<?php if (!$scan['ok']): ?>

    <div class="alert alert-critical" role="alert"><?= e($scan['error']) ?></div>
    <p class="muted">Recovery needs the live portal to be reachable, because records are restored through its API. Once it is back online, reload this page.</p>

<?php else: ?>

    <section class="card card-wide">
        <div class="card-head">
            <h2>Live portal vs backup</h2>
            <span class="muted small">Scanned just now</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Table</th><th>Live portal</th><th>Backup</th><th>Missing from live</th><th>Edited on live</th></tr>
                </thead>
                <tbody>
                <?php foreach (RESTORE_ORDER as $t): $s = $scan['tables'][$t]; ?>
                    <tr>
                        <td><?= e($labels[$t]) ?></td>
                        <td><?= (int) $s['live'] ?></td>
                        <td><?= (int) $s['backup'] ?></td>
                        <td>
                            <?php if ($s['missing']): ?>
                                <span class="badge badge-bad"><?= count($s['missing']) ?></span>
                            <?php else: ?>
                                <span class="badge badge-good">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['mutable']): ?>
                                <span class="muted small">not tracked</span>
                            <?php elseif ($s['changed']): ?>
                                <span class="badge badge-warn"><?= count($s['changed']) ?></span>
                            <?php else: ?>
                                <span class="badge badge-good">0</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($scan['total_missing'] === 0 && $scan['total_changed'] === 0): ?>
        <div class="flash flash-success">Nothing to restore. The live portal has every record the backup holds.</div>
    <?php endif; ?>

    <?php if ($scan['total_missing'] > 0): ?>
        <form method="post" action="<?= e(APP_BASE) ?>/actions/restore.php" class="card card-wide">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <h2>Restore missing records</h2>
            <p class="muted small">Preview of what will be added to the live portal. Tables are restored in this order: exams, questions, students, results.</p>

            <?php foreach (RESTORE_ORDER as $t):
                $missing = $scan['tables'][$t]['missing'];
                if (!$missing) { continue; }
                $rows = restore_preview_rows($pdo, $t, $missing);
                $cols = RESTORE_PREVIEW_COLS[$t];
            ?>
                <div class="restore-block">
                    <label class="check">
                        <input type="checkbox" name="tables[]" value="<?= e($t) ?>" checked>
                        <span><span class="check-title"><?= e($labels[$t]) ?></span>: <?= count($missing) ?> missing</span>
                    </label>

                    <div class="table-wrap">
                        <table class="table table-compact">
                            <thead>
                                <tr><?php foreach ($cols as $c): ?><th><?= e(str_replace('_', ' ', $c)) ?></th><?php endforeach; ?></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr><?php foreach ($cols as $c): ?><td><?= $cell($r[$c] ?? null) ?></td><?php endforeach; ?></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($missing) > RESTORE_PREVIEW_ROWS): ?>
                        <p class="muted small">&hellip;and <?= count($missing) - RESTORE_PREVIEW_ROWS ?> more.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="confirm-box">
                <label class="check">
                    <input type="checkbox" name="confirm" value="1" required>
                    <span>I understand this adds the missing records to the live portal. Records that already exist there are not changed.</span>
                </label>
            </div>

            <p class="cta"><button type="submit" class="btn btn-primary">Restore selected records</button></p>
        </form>
    <?php endif; ?>

    <?php if ($scan['total_changed'] > 0): ?>
        <section class="card card-wide">
            <div class="card-head">
                <h2>Edited on the live portal</h2>
                <span class="muted small">Not restorable automatically</span>
            </div>
            <div class="alert alert-warning">
                These records exist on both sides but no longer match the backup, which keeps the original. Restore never overwrites existing records, so check each one and correct it on the live portal if it was changed by mistake.
            </div>

            <?php foreach (RESTORE_ORDER as $t):
                $changed = $scan['tables'][$t]['changed'];
                if (!$changed) { continue; }
            ?>
                <div class="restore-block">
                    <p><span class="check-title"><?= e($labels[$t]) ?></span>: <?= count($changed) ?> edited</p>
                    <ul class="diff">
                    <?php foreach (restore_diffs($pdo, $t, $changed) as $d): ?>
                        <li>
                            Record #<?= (int) $d['id'] ?>
                            <?php if (!$d['found']): ?>
                                <span class="muted">(could not read the live copy)</span>
                            <?php else: ?>
                                <?php foreach ($d['diff'] as $col => [$was, $now]): ?>
                                    <span class="mono"><?= e($col) ?>: backup <strong><?= $cell($was) ?></strong> &rarr; live <strong><?= $cell($now) ?></strong></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                    <?php if (count($changed) > RESTORE_DIFF_ROWS): ?>
                        <p class="muted small">&hellip;and <?= count($changed) - RESTORE_DIFF_ROWS ?> more.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

<?php endif; ?>

<section class="card card-wide">
    <h2>Restore history</h2>
    <?php if (!$history): ?>
        <p class="muted">No restores yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-compact">
                <thead><tr><th>When</th><th>By</th><th>Table</th><th>Sent</th><th>Restored</th><th>Already there</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr title="<?= e($h['message']) ?>">
                        <td><?= e(fmt_dt($h['restored_at'])) ?></td>
                        <td><?= e($h['admin_name']) ?></td>
                        <td><?= e(str_replace('_', ' ', $h['table_name'])) ?></td>
                        <td><?= (int) $h['rows_sent'] ?></td>
                        <td><?= (int) $h['rows_inserted'] ?></td>
                        <td><?= (int) $h['rows_skipped'] ?></td>
                        <td><span class="badge badge-<?= $h['status'] === 'success' ? 'good' : 'bad' ?>"><?= e($h['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>