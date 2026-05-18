<?php
/**
 * PortOne V2 결제 검증 + 구독 활성화.
 *
 * 클라이언트 흐름:
 *   1. subscribe.html 에서 PortOne SDK 의 requestIssueBillingKeyAndPay()
 *      또는 requestPayment() 호출 → 성공 시 paymentId 받음
 *   2. 이 endpoint 에 POST: { paymentId, plan }
 *   3. 서버: PortOne API 로 검증 + 빌링키 저장 + members.plan 갱신
 *
 * Webhook 이 최종 truth — 이 endpoint 는 즉시 UX 응답용 (사용자가 결제 완료
 * 후 바로 plan 상태를 보고 다음 행동 가능하게).
 */

declare(strict_types=1);

require_once __DIR__ . '/../billing_helpers.php';
require_once __DIR__ . '/../crypto_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    portone_response(['status' => 'error', 'code' => 'method_not_allowed', 'message' => 'POST 만 허용.'], 405);
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$paymentId = trim((string)($body['paymentId'] ?? ''));
$planRequested = strtolower(trim((string)($body['plan'] ?? '')));

if ($paymentId === '') {
    portone_response(['status' => 'error', 'code' => 'invalid_request', 'message' => 'paymentId 누락.'], 400);
}
if (!in_array($planRequested, ['plus', 'pro'], true)) {
    portone_response(['status' => 'error', 'code' => 'invalid_request', 'message' => 'plan 은 plus 또는 pro 만 허용.'], 400);
}

// 인증 — Supabase Bearer 토큰
function get_bearer_token_billing(): string {
    $h = '';
    if (function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        foreach ($hdrs as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) { $h = (string)$v; break; } }
    }
    if ($h === '' && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        if (is_array($hdrs)) {
            foreach ($hdrs as $k => $v) { if (strcasecmp((string)$k, 'authorization') === 0) { $h = (string)$v; break; } }
        }
    }
    if ($h === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) $h = (string)$_SERVER['HTTP_AUTHORIZATION'];
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return '';
}

$token = get_bearer_token_billing();
if ($token === '') portone_response(['status' => 'error', 'code' => 'unauthorized', 'message' => '로그인이 필요합니다.'], 401);

$supabaseUrl = rtrim((string)(billing_load_env_value('VITE_SUPABASE_URL') ?: getenv('VITE_SUPABASE_URL') ?: ''), '/');
$anonKey = (string)(billing_load_env_value('VITE_SUPABASE_ANON_KEY') ?: getenv('VITE_SUPABASE_ANON_KEY') ?: '');
if ($supabaseUrl === '' || $anonKey === '') {
    portone_response(['status' => 'error', 'code' => 'config', 'message' => '서버 인증 설정 누락.'], 500);
}
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
if ($authStatus !== 200 || !$authResp) {
    portone_response(['status' => 'error', 'code' => 'unauthorized', 'message' => '토큰 검증 실패.'], 401);
}
$authData = json_decode((string)$authResp, true);
$ownerEmail = strtolower(trim((string)($authData['email'] ?? '')));
if ($ownerEmail === '') portone_response(['status' => 'error', 'code' => 'unauthorized', 'message' => '이메일 추출 실패.'], 401);

// PortOne API — 결제 검증
try {
    $resp = portone_api_call('GET', '/payments/' . urlencode($paymentId));
} catch (Throwable $e) {
    portone_response(['status' => 'error', 'code' => 'upstream_failed', 'message' => 'PortOne 호출 실패: ' . $e->getMessage()], 502);
}
if ($resp['status'] < 200 || $resp['status'] >= 300 || !is_array($resp['body'])) {
    portone_response(['status' => 'error', 'code' => 'upstream_failed', 'message' => 'PortOne 응답 오류 ' . $resp['status']], 502);
}
$payment = $resp['body'];

// 결제 상태 + 금액 검증
$paymentStatus = strtoupper((string)($payment['status'] ?? ''));
if ($paymentStatus !== 'PAID') {
    portone_response(['status' => 'error', 'code' => 'payment_not_paid', 'message' => '결제가 완료되지 않음: ' . $paymentStatus, 'payment_status' => $paymentStatus], 400);
}
$paidAmount = (int)($payment['amount']['total'] ?? 0);
$expectedAmount = portone_plan_amount($planRequested);
if ($paidAmount !== $expectedAmount) {
    portone_response(['status' => 'error', 'code' => 'amount_mismatch', 'message' => '결제 금액 불일치: ' . $paidAmount . ' vs ' . $expectedAmount], 400);
}

// 빌링키 — paymentMethod 또는 billingKey 필드에서 추출 (PortOne 응답 schema 따라).
$billingKey = (string)($payment['billingKey'] ?? $payment['method']['billingKey'] ?? '');
$customerId = (string)($payment['customer']['id'] ?? '');

// DB 갱신
require_once __DIR__ . '/../db_config.php';
try {
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    portone_response(['status' => 'error', 'code' => 'db', 'message' => 'DB 연결 실패.'], 500);
}

$now = date('Y-m-d H:i:s');
$periodStart = $now;
$periodEnd = date('Y-m-d H:i:s', strtotime('+30 days'));

try {
    // members 갱신 (plan/plan_status/billing key/period)
    $pdo->prepare("UPDATE members SET
            plan = :plan,
            plan_status = 'active',
            portone_customer_id = :pcid,
            portone_billing_key = :bkey,
            portone_subscription_id = NULL,
            current_period_start = :ps,
            current_period_end = :pe,
            cancel_at_period_end = 0,
            free_summaries_used = 0,
            last_usage_reset_at = :now
        WHERE email = :email")
        ->execute([
            ':plan' => $planRequested,
            ':pcid' => $customerId !== '' ? $customerId : null,
            ':bkey' => $billingKey !== '' ? $billingKey : null,
            ':ps' => $periodStart,
            ':pe' => $periodEnd,
            ':now' => $now,
            ':email' => $ownerEmail,
        ]);

    // subscriptions row (활성 구독 표시)
    $pdo->prepare("INSERT INTO subscriptions
            (owner_email, plan, status, portone_customer_id, portone_billing_key, current_period_start, current_period_end)
            VALUES (:o, :p, 'active', :pcid, :bkey, :ps, :pe)")
        ->execute([
            ':o' => $ownerEmail,
            ':p' => $planRequested,
            ':pcid' => $customerId !== '' ? $customerId : null,
            ':bkey' => $billingKey !== '' ? $billingKey : null,
            ':ps' => $periodStart,
            ':pe' => $periodEnd,
        ]);
    $subscriptionId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE members SET portone_subscription_id = :sid WHERE email = :e')
        ->execute([':sid' => $subscriptionId, ':e' => $ownerEmail]);

    // payments row (결제 1건 기록)
    $pdo->prepare("INSERT INTO payments
            (owner_email, portone_payment_id, portone_subscription_id, amount, currency, status, paid_at, raw_event_json)
            VALUES (:o, :pid, :sid, :amt, 'KRW', 'paid', :paid, :raw)")
        ->execute([
            ':o' => $ownerEmail,
            ':pid' => $paymentId,
            ':sid' => (string)$subscriptionId,
            ':amt' => $paidAmount,
            ':paid' => $now,
            ':raw' => json_encode($payment, JSON_UNESCAPED_UNICODE),
        ]);
} catch (Throwable $e) {
    error_log('[billing/verify-payment] DB 갱신 실패: ' . $e->getMessage());
    portone_response(['status' => 'error', 'code' => 'db', 'message' => 'DB 갱신 실패 (결제는 성공). 관리자 문의: ' . $e->getMessage()], 500);
}

portone_response([
    'status' => 'ok',
    'plan' => $planRequested,
    'plan_status' => 'active',
    'current_period_end' => $periodEnd,
]);
