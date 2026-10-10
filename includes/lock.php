<?php
// recovery_system/includes/lock.php
// Lock files so two runs of the same job never overlap.
// The OS releases a flock() if the script dies, so a crash can never leave a stuck lock.

function acquire_lock(string $name)
{
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $fh = @fopen($dir . '/' . preg_replace('/[^a-z0-9_]/i', '', $name) . '.lock', 'c');
    if ($fh === false) {
        return null;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {   // someone else holds it
        fclose($fh);
        return null;
    }
    return $fh;   // keep this variable alive until the job finishes
}

function release_lock($fh): void
{
    if (is_resource($fh)) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}