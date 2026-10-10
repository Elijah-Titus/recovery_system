<?php
// recovery_system/config/config.php
// Everything the recovery system knows about the live portal is in this file.
$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$appBase = preg_replace('~/(?:pages|actions|cron)$~', '', $scriptDirectory);
define('APP_BASE', rtrim($appBase ?? $scriptDirectory, '/'));   // '' when installed at the domain root
date_default_timezone_set('UTC');   // change to your zone (e.g. 'Africa/Lagos') for local times

// ---- Live portal API ----
define('LIVE_API_URL',        'http://localhost/school_portal/api.php');
define('LIVE_API_KEY_READ',   'dd07439c6b3833f1bf1c5a4c7aadfba023cb72d7e2d23367c391dfe132de23c8');    // health, fetch, ids
define('LIVE_API_KEY_WRITE',  'e5890a5c7b04880e984a975960dd07367a78ee2fb8497ffaf4e2cb8a312037bf');   // restore only
define('LIVE_API_TIMEOUT',    15);      // seconds before a call counts as failed
define('LIVE_API_VERIFY_SSL', true);    // keep true in production (only matters for https)

// ---- Backup database (separate from the live one) ----
define('DB_HOST',    'localhost');
define('DB_NAME',    'recovery_backup');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// ---- Monitoring and schedule settings ----
define('SYNC_BATCH_SIZE',      500);    // rows per fetch request
define('FULL_REFRESH_MINUTES', 60);     // how often exams + registered_students are fully re-copied
define('SYNC_STALE_MINUTES',   10);     // dashboard turns red if the last good sync is older than this
define('HEALTH_STALE_MINUTES', 5);      // ...or if no health check has run for this long
define('HEALTH_FAILS_ALERT',   3);      // consecutive failed checks before "portal is DOWN"
define('ARCHIVE_KEEP_DAYS',    14);     // daily snapshot dumps older than this are deleted