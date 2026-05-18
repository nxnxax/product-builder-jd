<?php
/**
 * PortOne V2 빌링키 결제 + 구독 활성화.
 *
 * 클라이언트 흐름:
 *   1. subscribe.html 에서 PortOne SDK 의 requestIssueBillingKey() 호출
 *      → 빌링키만 발급 (토스페이먼츠는 IssueBillingKeyAndPay 미지원)
 *   2. 이 endpoint 에 POST: { billingKey, issueId, plan }
 *   3. 서버: PortOne API 로 첫 결제 호출 + DB 갱신
 */

declare(strict_types=1);

require_once __DIR__ . '/../billing_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    portone_response(['status' => 'error', 'code' => 'method_not_allowed', 'message' => 'POST 만 허용.'], 405);
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$billingKey = trim((string)($body['billingKey'] ?? ''));
$issueId = trim((string)($body['issueId'] ?? ''));
$planRequested = strtolower(trim((string)($body['plan'] ?? '')));

if ($billingKey === '') {
    portone_response(['status' => 'error', 'code' => 'invalid_request', 'message' => 'billingKey 누락.'], 400);
}
if (!in_array($planRequested, ['plus', 'pro'], true)) {
    portone_response(['status' => 'error', 'code' => 'invalid_request', 'message' => 'plan 은 plus 또는 pro 만 허용.'], 400);
}

// 인증 — Supabase Bearer (헬퍼 사용; 실패 시 자동 401 응답 + exit)
$ownerEmail = billing_require_bearer_email();

// PortOne API — 빌링키로 첫 결제 실행
$amount = portone_plan_amount($planRequested);
$orderName = portone_plan_label($planRequested);
$paymentId = 'first-' . date('Ymd') . '-' . substr(md5($ownerEmail . '|' . $billingKey), 0, 12);

try {
    $resp = portone_api_call('POST', '/payments/' . urlencode($paymentId) . '/billing-key', [
        'billingKey' => $billingKey,
        'orderName' => $orderName,
        'amount' => ['total' => $amount],
        'currency' => 'KRW',
        'customer' => ['id' => $ownerEmail, 'email' => $ownerEmail],
    ]);
} catch (Throwable $e) {
    portone_response(['status' => 'error', 'code' => 'upstream_failed', 'message' => 'PortOne 호출 실패: ' . $e->getMessage(), 'payment_id' => $paymentId], 502);
}
if ($resp['status'] < 200 || $resp['status'] >= 300 || !is_array($resp['body'])) {
    $msg = is_array($resp['body']) ? ($resp['body']['message'] ?? $resp['body']['type'] ?? '') : '';
    portone_response(['status' => 'error', 'code' => 'upstream_failed', 'message' => 'PortOne 결제 호출 실패 (' . $resp['status'] . '): ' . $msg, 'payment_id' => $paymentId, 'debug' => ['response_body' => $resp['body']]], 502);
}
$payment = $resp['body'];

// 결제 상태 — schema 가 nested 일 수 있어 여러 위치에서 시도. 비동기면 GET 으로 재확인.
$paymentStatus = portone_extract_status($payment);
if ($paymentStatus !== 'PAID') {
    sleep(1);
    try {
        $getResp = portone_api_call('GET', '/payments/' . urlencode($paymentId));
        if ($getResp['status'] >= 200 && $getResp['status'] < 300 && is_array($getResp['body'])) {
            $payment = $getResp['body'];
            $paymentStatus = portone_extract_status($payment);
        }
    } catch (Throwable $e) { /* 무시 */ }
}

if ($paymentStatus !== 'PAID') {
    portone_response([
        'status' => 'error',
        'code' => 'payment_not_paid',
        'message' => '결제가 완료되지 않음: ' . ($paymentStatus !== '' ? $paymentStatus : '(status 필드 없음)'),
        'payment_status' => $paymentStatus,
        'payment_id' => $paymentId,
        'debug' => [
            'response_keys' => array_keys($payment),
            'response_sample' => array_slice($payment, 0, 10, true),
        ],
    ], 400);
}

$paidAmount = portone_extract_amount($payment);
if ($paidAmount !== $amount) {
    portone_response(['status' => 'error', 'code' => 'amount_mismatch', 'message' => '결제 금액 불일치: ' . $paidAmount . ' vs ' . $amount, 'payment_id' => $paymentId], 400);
}

$customerId = (string)($payment['customer']['id'] ?? $payment['payment']['customer']['id'] ?? $issueId);

// DB 갱신
try {
    $pdo = billing_pdo();
} catch (Throwable $e) {
    portone_response(['status' => 'error', 'code' => 'db_connect', 'message' => 'DB 연결 실패: ' . $e->getMessage(), 'payment_id' => $paymentId], 500);
}

$now = date('Y-m-d H:i:s');
$periodStart = $now;
$periodEnd = date('Y-m-d H:i:s', strtotime('+30 days'));

try {
    // plan 별 default summary_limit 도 동기화 (plus 20 / pro NULL / 그 외 5)
    $newLimit = plan_default_summary_limit($planRequested);
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
            last_usage_reset_at = :now,
            summary_limit = :slim
        WHERE email = :email")
        ->execute([
            ':plan' => $planRequested,
            ':pcid' => $customerId !== '' ? $customerId : null,
            ':bkey' => $billingKey,
            ':ps' => $periodStart,
            ':pe' => $periodEnd,
            ':now' => $now,
            ':slim' => $newLimit,
            ':email' => $ownerEmail,
        ]);

    $pdo->prepare("INSERT INTO subscriptions
            (owner_email, plan, status, portone_customer_id, portone_billing_key, current_period_start, current_period_end)
            VALUES (:o, :p, 'active', :pcid, :bkey, :ps, :pe)")
        ->execute([
            ':o' => $ownerEmail,
            ':p' => $planRequested,
            ':pcid' => $customerId !== '' ? $customerId : null,
            ':bkey' => $billingKey,
            ':ps' => $periodStart,
            ':pe' => $periodEnd,
        ]);
    $subscriptionId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE members SET portone_subscription_id = :sid WHERE email = :e')
        ->execute([':sid' => $subscriptionId, ':e' => $ownerEmail]);

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
    error_log('[billing/verify-payment] DB write 실패: ' . $e->getMessage());
    portone_response(['status' => 'error', 'code' => 'db_write', 'message' => 'DB 갱신 실패 (결제는 성공): ' . $e->getMessage(), 'payment_id' => $paymentId], 500);
}

portone_response([
    'status' => 'ok',
    'plan' => $planRequested,
    'plan_status' => 'active',
    'current_period_end' => $periodEnd,
    'payment_id' => $paymentId,
]);
