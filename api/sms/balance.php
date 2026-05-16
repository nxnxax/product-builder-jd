<?php
/**
 * balance.php — 사용자 본인의 Solapi 계정 잔액 조회.
 *
 * GET (Bearer 인증 필요)
 * 응답: { ok: true, balance: float|null, point: float|null, provider: 'solapi' }
 *      또는 { ok: false, error: '...', reason: '...' }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

@set_time_limit(15);

set_exception_handler(function ($e) {
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

// crypto helpers
$cryptoFile = __DIR__ . '/../crypto_helpers.php';
if (!is_file($cryptoFile)) $cryptoFile = dirname(__DIR__, 2) . '/crypto_helpers.php';
if (is_file($cryptoFile)) require_once $cryptoFile;
if (!function_exists('youngman_decrypt')) {
    function youngman_decrypt($v) { return $v; }
}

require_once __DIR__ . '/providers/SmsProvider.php';
require_once __DIR__ . '/providers/SolapiProvider.php';
require_once __DIR__ . '/providers/AligoProvider.php';

$cfg = __DIR__ . '/../db_config.php';
if (!is_file($cfg)) $cfg = dirname(__DIR__, 2) . '/db_config.php';
if (!is_file($cfg)) { http_response_code(500); echo json_encode(['ok'=>false, 'error'=>'DB 설정 파일이 없습니다.'], JSON_UNESCAPED_UNICODE); exit; }
$db = require $cfg;
$pdo = $db;

// Bearer auth
$authHeader = '';
foreach (function_exists('getallheaders') ? getallheaders() : [] as $k => $v) {
    if (strcasecmp($k, 'Authorization') === 0) { $authHeader = $v; break; }
}
if ($authHeader === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    http_response_code(401);
    echo json_encode(['ok'=>false, 'error'=>'로그인이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$accessToken = trim($m[1]);

// supabase token verify — records.php 의 verify_supabase_token 와 동일 로직.
$ownerEmail = supabase_verify_token($accessToken);
if (!$ownerEmail) {
    http_response_code(401);
    echo json_encode(['ok'=>false, 'error'=>'인증 실패 — 다시 로그인 해주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$ownerEmail = strtolower($ownerEmail);

// sms_credentials 조회
try {
    $stmt = $pdo->prepare('SELECT provider, api_key_enc, api_secret_enc FROM sms_credentials WHERE owner_email = :o LIMIT 1');
    $stmt->execute([':o' => $ownerEmail]);
    $cred = $stmt->fetch();
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>'문자 설정 테이블 미준비'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!$cred) {
    echo json_encode(['ok'=>false, 'error'=>'Solapi 미연동', 'reason'=>'not_configured'], JSON_UNESCAPED_UNICODE);
    exit;
}

$providerName = strtolower((string)($cred['provider'] ?? 'solapi'));
$apiKey       = youngman_decrypt($cred['api_key_enc']    ?? '');
$apiSecret    = youngman_decrypt($cred['api_secret_enc'] ?? '');

if ($providerName !== 'solapi') {
    echo json_encode(['ok'=>true, 'balance'=>null, 'point'=>null, 'provider'=>$providerName, 'note'=>'잔액 조회는 Solapi 만 지원'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($apiKey === '' || $apiSecret === '') {
    echo json_encode(['ok'=>false, 'error'=>'API 키 미등록', 'reason'=>'credentials_empty'], JSON_UNESCAPED_UNICODE);
    exit;
}

$provider = new SolapiProvider(['api_key'=>$apiKey, 'api_secret'=>$apiSecret]);
$bal = $provider->getBalance();

if (is_array($bal) && isset($bal['error'])) {
    echo json_encode([
        'ok'    => false,
        'error' => $bal['error'],
        'http'  => $bal['http'] ?? null,
        'reason'=> 'solapi_api_error',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'       => true,
    'provider' => 'solapi',
    'balance'  => $bal['balance'] ?? null,
    'point'    => $bal['point']   ?? null,
], JSON_UNESCAPED_UNICODE);

/* ===================== 헬퍼 ===================== */

function supabase_verify_token(string $token): ?string {
    $env = load_env_for_balance();
    $url = rtrim((string)($env['VITE_SUPABASE_URL'] ?? ''), '/');
    $url = preg_replace('#/rest/v1/?$#', '', $url);
    $url = preg_replace('#/auth/v1/?$#', '', $url);
    if ($url === '') return null;
    $anon = $env['VITE_SUPABASE_ANON_KEY'] ?? '';
    $ch = curl_init($url . '/auth/v1/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'apikey: ' . $anon],
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !is_string($body)) return null;
    $data = json_decode($body, true);
    return is_array($data) ? ($data['email'] ?? null) : null;
}

function load_env_for_balance(): array {
    $out = [];
    foreach ([__DIR__ . '/../.env', dirname(__DIR__, 2) . '/.env'] as $p) {
        if (!is_file($p)) continue;
        $lines = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) continue;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $k = trim(substr($line, 0, $eq));
            $v = trim(substr($line, $eq + 1));
            if ($k !== '') $out[$k] = $v;
        }
    }
    return $out;
}
