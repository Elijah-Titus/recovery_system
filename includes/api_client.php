<?php
// recovery_system/includes/api_client.php
// Every call to the live portal goes through api_call(). Nothing else in this
// project talks to the live system.

require_once __DIR__ . '/../config/config.php';

// Returns: ['ok' => bool, 'status' => int (0 = no connection), 'ms' => int,
//           'data' => ?array, 'error' => ?string]
// It never throws on network trouble, so a monitoring job can record "portal down"
// and carry on instead of crashing.
function api_call(string $action, array $query = [], ?array $body = null): array
{
    $result = ['ok' => false, 'status' => 0, 'ms' => 0, 'data' => null, 'error' => null];

    // Only "restore" ever uses the write key. Everything else uses the read key.
    $key = ($action === 'restore') ? LIVE_API_KEY_WRITE : LIVE_API_KEY_READ;

    if ($key === '' || str_starts_with($key, 'paste-the')) {
        $result['error'] = 'API key is not set in config.php.';
        return $result;
    }
    if (!function_exists('curl_init')) {
        $result['error'] = 'The PHP cURL extension is not enabled.';
        return $result;
    }

    $url     = LIVE_API_URL . '?' . http_build_query(array_merge(['action' => $action], $query));
    $headers = ['X-API-KEY: ' . $key, 'Accept: application/json'];

    $ch   = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => LIVE_API_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => min(5, LIVE_API_TIMEOUT),
        CURLOPT_FOLLOWLOCATION => false,   // a redirect could carry the key somewhere else
        CURLOPT_SSL_VERIFYPEER => LIVE_API_VERIFY_SSL,
        CURLOPT_SSL_VERIFYHOST => LIVE_API_VERIFY_SSL ? 2 : 0,
    ];

    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            curl_close($ch);
            $result['error'] = 'Could not encode the request as JSON.';
            return $result;
        }
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $json;
        $headers[]                = 'Content-Type: application/json';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $t0  = microtime(true);
    $raw = curl_exec($ch);
    $result['ms'] = (int) round((microtime(true) - $t0) * 1000);

    if ($raw === false) {
        $result['error'] = 'Could not reach the live portal: ' . curl_error($ch);
        curl_close($ch);
        return $result;
    }
    $result['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $result['error'] = 'The live portal sent a response that is not valid JSON (HTTP ' . $result['status'] . ').';
        return $result;
    }

    $result['data'] = $data;
    if ($result['status'] === 200 && ($data['ok'] ?? false) === true) {
        $result['ok'] = true;
        return $result;
    }

    $result['error'] = (string) ($data['error'] ?? ('HTTP ' . $result['status']));
    return $result;
}

// ---- One small function per API action ----

function api_health(): array
{
    return api_call('health');
}

function api_fetch(string $table, int $afterId = 0, int $limit = SYNC_BATCH_SIZE): array
{
    return api_call('fetch', ['table' => $table, 'after_id' => $afterId, 'limit' => $limit]);
}

function api_ids(string $table, int $afterId = 0, int $limit = 5000): array
{
    return api_call('ids', ['table' => $table, 'after_id' => $afterId, 'limit' => $limit]);
}

function api_restore(string $table, array $rows): array
{
    return api_call('restore', [], ['table' => $table, 'rows' => $rows]);
}