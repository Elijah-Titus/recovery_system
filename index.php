<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/health_engine.php';

require_admin();

$snapshot = monitor_snapshot(db());

$pageTitle = 'Overview';
$active    = 'overview';
require __DIR__ . '/includes/layout/header.php';

$overallLabels = [
    'ok'       => 'All systems operational',
    'warning'  => 'Attention needed',
    'critical' => 'Critical issues detected',
];
$overall = $snapshot['overall'];
$bannerClass = in_array($overall, ['ok', 'warning', 'critical'], true) ? $overall : 'warning';

$portalLabels = [
    'up'       => 'Online',
    'degraded' => 'Degraded',
    'down'     => 'Down',
    'unknown'  => 'Unknown',
];
$portalBadge = [
    'up'       => 'good',
    'degraded' => 'warn',
    'down'     => 'bad',
    'unknown'  => 'muted',
];
$portalState = $snapshot['portal']['state'];

$tables = [
    'exams'               => 'Exams',
    'questions'           => 'Questions',
    'registered_students' => 'Registered students',
    'student_results'     => 'Student results',
];
?>

<section class="banner banner-<?= e($bannerClass) ?>">
    <div>
        <h1><?= e($overallLabels[$overall] ?? 'System status unavailable') ?></h1>
        <p>Live portal and backup status at a glance.</p>
    </div>
    <a class="btn btn-light" href="<?= e(APP_BASE) ?>/pages/recovery.php">Open recovery</a>
</section>

<?php if ($snapshot['alerts']): ?>
    <div class="alerts" aria-label="System alerts">
        <?php foreach ($snapshot['alerts'] as $alert): ?>
            <div class="alert alert-<?= $alert['level'] === 'critical' ? 'critical' : 'warning' ?>" role="status">
                <?= e($alert['message']) ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="flash flash-success" role="status">No active alerts.</div>
<?php endif; ?>

<div class="grid">
    <section class="card">
        <h2>Live portal</h2>
        <p class="big"><span class="badge badge-<?= e($portalBadge[$portalState] ?? 'muted') ?>"><?= e($portalLabels[$portalState] ?? 'Unknown') ?></span></p>
        <dl class="kv">
            <dt>Last check</dt>
            <dd><?= e(fmt_dt($snapshot['portal']['last_check'])) ?></dd>
            <dt>Response time</dt>
            <dd><?= $snapshot['portal']['response_ms'] !== null ? (int) $snapshot['portal']['response_ms'] . ' ms' : '-' ?></dd>
        </dl>
    </section>

    <section class="card">
        <h2>Backup sync</h2>
        <p class="big"><span class="badge badge-<?= $snapshot['sync']['last_status'] === 'success' ? 'good' : ($snapshot['sync']['last_status'] === 'failed' ? 'bad' : 'muted') ?>">
            <?= e($snapshot['sync']['last_status'] ?? 'Not run') ?>
        </span></p>
        <dl class="kv">
            <dt>Last successful sync</dt>
            <dd><?= e(fmt_dt($snapshot['sync']['last_success'])) ?></dd>
            <dt>Last sync attempt</dt>
            <dd><?= e(fmt_dt($snapshot['sync']['last_finished'])) ?></dd>
        </dl>
    </section>

    <section class="card">
        <h2>Latest health check</h2>
        <p class="big"><?= $snapshot['portal']['check_age_min'] !== null ? (int) $snapshot['portal']['check_age_min'] . ' min ago' : 'No data' ?></p>
        <p class="muted small">Health checks and syncs run on the configured schedule.</p>
    </section>
</div>

<section class="card card-wide">
    <div class="card-head">
        <h2>Live portal and backup records</h2>
        <a class="btn btn-primary btn-sm" href="<?= e(APP_BASE) ?>/pages/recovery.php">Review recovery</a>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Table</th><th>Live portal</th><th>Backup</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($tables as $table => $label):
                $liveCount = $snapshot['live_counts'][$table] ?? null;
                $backupCount = $snapshot['backup_counts'][$table] ?? 0;
                $missing = $liveCount !== null && $liveCount < $backupCount;
            ?>
                <tr>
                    <td><?= e($label) ?></td>
                    <td><?= $liveCount !== null ? (int) $liveCount : '-' ?></td>
                    <td><?= (int) $backupCount ?></td>
                    <td>
                        <?php if ($liveCount === null): ?>
                            <span class="badge badge-muted">No health data</span>
                        <?php elseif ($missing): ?>
                            <span class="badge badge-bad">Backup has more</span>
                        <?php else: ?>
                            <span class="badge badge-good">In sync</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>