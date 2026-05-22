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

$result = [
    'php_version' => PHP_VERSION,
    'opcache_enabled' => function_exists('opcache_get_status')
        ? (@opcache_get_status(false)['opcache_enabled'] ?? false)
        : 'unavailable',
    'env_files' => $envFiles,
    'keys' => [],
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
