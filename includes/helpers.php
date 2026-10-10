<?php
// recovery_system/includes/helpers.php

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function fmt_dt(?string $ts): string
{
    return $ts ? date('d M Y, H:i:s', strtotime($ts)) : '-';
}

function ago(?string $ts): string
{
    if (!$ts) {
        return 'never';
    }
    $s = max(0, time() - strtotime($ts));
    if ($s < 60)    { return 'just now'; }
    if ($s < 3600)  { return floor($s / 60) . ' min ago'; }
    if ($s < 86400) { return floor($s / 3600) . ' h ago'; }
    return floor($s / 86400) . ' d ago';
}