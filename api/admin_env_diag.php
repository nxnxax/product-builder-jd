<?php
declare(strict_types=1);

/* admin_env_diag.php — PHP 가 실제로 .env 에서 읽는 값 진단
   - Authorization: Bearer <RECORDING_WORKER_TOKEN> 검증
   - .env 에 token 자체가 없으면 503 반환 (= .env 가 PHP 까지 안 닿는 증거)
   - 보안 키는 마스킹 (앞4 + 끝4 + 길이), URL 류는 노출
*/

header('Content-Type: application/json; charset=utf-8');

function load_env_value(string $key): string {
    foreach ([__DIR__, dirname(__DIR__)] as $dir) {
        $path = $dir . '/.env';
        if (!is_file($path)) continue;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $m)) {
                if (strcasecmp($m[1], $key) === 0) return trim($m[2], "\"' \t\r\n");
            }
        }
    }
    return '';
}

function find_env_path(string $key): string {
    foreach ([__DIR__, dirname(__DIR__)] as $dir) {
        $path = $dir . '/.env';
        if (!is_file($path)) continue;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $m)) {
                if (strcasecmp($m[1], $key) === 0) return $path;
            }
        }
    }
    return '';
}

function mask_value(string $v): string {
    if ($v === '') return '(empty)';
    $len = strlen($v);
    if ($len <= 8) return str_repeat('*', $len) . " (len:$len)";
    return substr($v, 0, 4) . '...' . substr($v, -4) . " (len:$len)";
}

$envFiles = [];
foreach ([__DIR__, dirname(__DIR__)] as $dir) {
    $path = $dir . '/.env';
    $envFiles[] = [
        'path' => $path,
        'exists' => is_file($path),
        'readable' => is_readable($path),
        'size' => is_file($path) ? filesize($path) : 0,
        'mtime' => is_file($path) ? date('Y-m-d H:i:s', filemtime($path)) : null,
    ];
}

$expectedToken = load_env_value('RECORDING_WORKER_TOKEN');

if ($expectedToken === '') {
    http_response_code(503);
    echo json_encode([
        'error' => 'RECORDING_WORKER_TOKEN .env 미설정 또는 PHP 가 .env 읽지 못함',
        'hint' => 'env_files_status 보고 어느 경로의 .env 가 존재/읽기가능한지 확인',
        'env_files' => $envFiles,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$authSource = '';
$hdr = '';

if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'];
    $authSource = 'HTTP_AUTHORIZATION';
} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    $authSource = 'REDIRECT_HTTP_AUTHORIZATION';
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach (['Authorization', 'authorization'] as $key) {
        if (!empty($headers[$key])) {
            $hdr = $headers[$key];
            $authSource = 'getallheaders[' . $key . ']';
            break;
        }
    }
}
if (!$hdr && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    foreach (['Authorization', 'authorization'] as $key) {
        if (!empty($headers[$key])) {
            $hdr = $headers[$key];
            $authSource = 'apache_request_headers[' . $key . ']';
            break;
        }
    }
}

$incoming = (stripos($hdr, 'Bearer ') === 0) ? trim(substr($hdr, 7)) : '';

$serverKeysSeen = [];
foreach ($_SERVER as $k => $_) {
    if (stripos($k, 'AUTH') !== false || stripos($k, 'HTTP_') === 0) {
        $serverKeysSeen[] = $k;
    }
}
sort($serverKeysSeen);

if ($incoming === '' || !hash_equals($expectedToken, $incoming)) {
    http_response_code(401);
    echo json_encode([
        'error' => 'unauthorized',
        'hint' => 'Authorization: Bearer <RECORDING_WORKER_TOKEN> 헤더 필요',
        'auth_source' => $authSource ?: '(none — header not received by PHP)',
        'raw_header_len' => strlen((string)$hdr),
        'server_keys_seen' => $serverKeysSeen,
        'token_compare' => [
            'expected_len' => strlen($expectedToken),
            'expected_sha1_prefix' => substr(sha1($expectedToken), 0, 12),
            'expected_first4' => substr($expectedToken, 0, 4),
            'expected_last4' => substr($expectedToken, -4),
            'incoming_len' => strlen($incoming),
            'incoming_sha1_prefix' => $incoming === '' ? '(empty)' : substr(sha1($incoming), 0, 12),
            'incoming_first4' => substr($incoming, 0, 4),
            'incoming_last4' => substr($incoming, -4),
            'match' => false,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$keys = [
    'RAILWAY_WORKER_URL', 'RECORDING_WORKER_TOKEN',
    'ANTHROPIC_API_KEY', 'OPENAI_API_KEY',
    'NCP_CLOVA_SECRET', 'NCP_CLOVA_INVOKE_URL',
    'CAFE24_BASE_URL', 'STT_PROVIDER', 'LLM_PROVIDER',
    'SUPABASE_URL', 'SUPABASE_ANON_KEY', 'SUPABASE_SERVICE_KEY',
    'YOUNGMAN_CRYPTO_KEY',
];

$exposeAsIs = ['RAILWAY_WORKER_URL', 'CAFE24_BASE_URL', 'STT_PROVIDER', 'LLM_PROVIDER', 'SUPABASE_URL', 'NCP_CLOVA_INVOKE_URL'];

$action = $_GET['action'] ?? '';

if ($action === 'opcache_reset') {
    $resetResult = ['action' => 'opcache_reset'];
    if (function_exists('opcache_reset')) {
        $resetResult['opcache_reset'] = @opcache_reset() ? 'success' : 'failed';
    } else {
        $resetResult['opcache_reset'] = 'function disabled';
    }
    $targetFiles = [__DIR__ . '/process-recording.php', __DIR__ . '/recording-callback.php'];
    foreach ($targetFiles as $f) {
        if (function_exists('opcache_invalidate') && is_file($f)) {
            $resetResult['invalidate'][$f] = @opcache_invalidate($f, true) ? 'success' : 'failed';
        }
    }
    echo json_encode($resetResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'test_railway') {
    $url = load_env_value('RAILWAY_WORKER_URL');
    $token = load_env_value('RECORDING_WORKER_TOKEN');
    $start = microtime(true);
    $ch = curl_init(rtrim($url, '/') . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    ]);
    $resp = curl_exec($ch);
    $info = [
        'http_status' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'curl_errno' => curl_errno($ch),
        'curl_error' => curl_error($ch),
        'elapsed_ms' => round((microtime(true) - $start) * 1000, 2),
        'response_preview' => substr((string)$resp, 0, 500),
    ];
    curl_close($ch);
    echo json_encode([
        'action' => 'test_railway',
        'url' => rtrim($url, '/') . '/',
        'result' => $info,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$processRecordingPath = __DIR__ . '/process-recording.php';

$result = [
    'php_version' => PHP_VERSION,
    'opcache_enabled' => function_exists('opcache_get_status')
        ? (@opcache_get_status(false)['opcache_enabled'] ?? false)
        : 'unavailable',
    'process_recording_php' => [
        'path' => $processRecordingPath,
        'exists' => is_file($processRecordingPath),
        'mtime' => is_file($processRecordingPath) ? date('Y-m-d H:i:s', filemtime($processRecordingPath)) : null,
        'size' => is_file($processRecordingPath) ? filesize($processRecordingPath) : 0,
    ],
    'env_files' => $envFiles,
    'keys' => [],
    'available_actions' => [
        '?action=opcache_reset' => 'opcache 강제 reset + process-recording / recording-callback invalidate',
        '?action=test_railway' => 'cafe24 → Railway outbound HTTP 테스트 (Bearer token 으로 /health 호출)',
    ],
];

foreach ($keys as $key) {
    $v = load_env_value($key);
    $result['keys'][$key] = [
        'found' => $v !== '',
        'found_in' => find_env_path($key),
        'value' => in_array($key, $exposeAsIs, true) ? $v : mask_value($v),
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
