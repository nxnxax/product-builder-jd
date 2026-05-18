<?php
/**
 * 구독 해지 — cancel_at_period_end 로 처리 (즉시 환불 X, 다음 결제일까지 사용 가능).
 *
 * 흐름:
 *   1. 사용자가 /billing 의 "구독 해지" 클릭
 *   2. 이 endpoint POST (인증 필요)
 *   3. members.cancel_at_period_end = 1 + subscriptions.status 그대로 'active'
 *   4. cron-renew 가 current_period_end 도달 시 자동 결제 시도 안 함 →
 *      plan_status='cancelled' + plan='free' 로 강등
 *
 * PortOne 빌링키도 즉시 삭제 (다음 결제 차단). 사용자가 재구독 시 다시 발급.
 */

declare(strict_types=1);

require_once __DIR__ . '/../billing_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    portone_response(['status' => 'error', 'code' => 'method_not_allowed'], 405);
}

// 인증
function get_bearer_token_cancel(): string {
    $h = '';
    if (function_exists('getallheaders')) {
        $hdrs = getallheaders();
        if (is_array($hdrs)) {
            foreach ($hdrs as $k => $v) { if (strcasecmp((string)$k, 'authorization') === 0) { $h = (string)$v; break; } }
        }
    }
    if ($h === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) $h = (string)$_SERVER['HTTP_AUTHORIZATION'];
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return '';
}

$token = get_bearer_token_cancel();
if ($token === '') portone_response(['status' => 'error', 'code' => 'unauthorized'], 401);

$supabaseUrl = rtrim((string)(billing_load_env_value('VITE_SUPABASE_URL') ?: getenv('VITE_SUPABASE_URL') ?: ''), '/');
$anonKey = (string)(billing_load_env_value('VITE_SUPABASE_ANON_KEY') ?: getenv('VITE_SUPABASE_ANON_KEY') ?: '');
$ch = curl_init($supabaseUrl . '/auth/v1/user');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'apikey: ' . $anonKey],
    CURLOPT_TIMEOUT => 8,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$authResp = curl_exec($ch);
$authStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($authStatus !== 200 || !$authResp) portone_response(['status' => 'error', 'code' => 'unauthorized'], 401);
$authData = json_decode((string)$authResp, true);
$ownerEmail = strtolower(trim((string)($authData['email'] ?? '')));
if ($ownerEmail === '') portone_response(['status' => 'error', 'code' => 'unauthorized'], 401);

// DB
require_once __DIR__ . '/../db_config.php';
try {
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    portone_response(['status' => 'error', 'code' => 'db'], 500);
}

// 현재 구독 조회
$m = $pdo->prepare('SELECT plan, plan_status, portone_billing_key, current_period_end FROM members WHERE email = :e LIMIT 1');
$m->execute([':e' => $ownerEmail]);
$member = $m->fetch();
if (!$member) portone_response(['status' => 'error', 'code' => 'not_found'], 404);

$currentPlan = strtolower((string)($member['plan'] ?? 'free'));
$currentStatus = strtolower((string)($member['plan_status'] ?? 'trialing'));
if (!in_array($currentPlan, ['plus', 'pro'], true)) {
    portone_response(['status' => 'error', 'code' => 'no_active_subscription', 'message' => '활성 유료 구독이 없습니다.'], 400);
}

// 빌링키 삭제 (PortOne API) — 다음 자동결제 차단.
$billingKey = (string)($member['portone_billing_key'] ?? '');
if ($billingKey !== '') {
    try {
        portone_api_call('DELETE', '/billing-keys/' . urlencode($billingKey));
    } catch (Throwable $e) {
        // 실패해도 진행 — DB 측 cancel_at_period_end 만으로도 cron 이 결제 안 함.
        error_log('[billing/cancel] 빌링키 삭제 실패 (cron 차단으로 충분): ' . $e->getMessage());
    }
}

// DB 업데이트 — cancel_at_period_end=1. plan/plan_status 는 그대로 유지
// (사용자가 결제 기간까지는 사용 가능).
try {
    $pdo->prepare("UPDATE members SET
            cancel_at_period_end = 1,
            portone_billing_key = NULL
        WHERE email = :e")
        ->execute([':e' => $ownerEmail]);

    $pdo->prepare("UPDATE subscriptions SET
            cancel_at_period_end = 1,
            portone_billing_key = NULL,
            updated_at = NOW()
        WHERE owner_email = :e AND status = 'active'")
        ->execute([':e' => $ownerEmail]);
} catch (Throwable $e) {
    portone_response(['status' => 'error', 'code' => 'db', 'message' => 'DB 갱신 실패: ' . $e->getMessage()], 500);
}

portone_response([
    'status' => 'ok',
    'cancel_at_period_end' => true,
    'plan' => $currentPlan,
    'plan_status' => $currentStatus,
    'access_until' => (string)($member['current_period_end'] ?? ''),
]);
