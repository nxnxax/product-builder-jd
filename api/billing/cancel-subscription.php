<?php
/**
 * 구독 해지 — cancel_at_period_end 로 처리 (즉시 환불 X, 다음 결제일까지 사용 가능).
 * PortOne 빌링키는 즉시 삭제 (자동결제 차단).
 */

declare(strict_types=1);

require_once __DIR__ . '/../billing_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    portone_response(['status' => 'error', 'code' => 'method_not_allowed'], 405);
}

$ownerEmail = billing_require_bearer_email();

try {
    $pdo = billing_pdo();
} catch (Throwable $e) {
    portone_response(['status' => 'error', 'code' => 'db_connect', 'message' => 'DB 연결 실패: ' . $e->getMessage()], 500);
}

$m = $pdo->prepare('SELECT plan, plan_status, portone_billing_key, current_period_end FROM members WHERE email = :e LIMIT 1');
$m->execute([':e' => $ownerEmail]);
$member = $m->fetch();
if (!$member) portone_response(['status' => 'error', 'code' => 'not_found'], 404);

$currentPlan = strtolower((string)($member['plan'] ?? 'free'));
$currentStatus = strtolower((string)($member['plan_status'] ?? 'active'));
if (!in_array($currentPlan, ['plus', 'pro'], true)) {
    portone_response(['status' => 'error', 'code' => 'no_active_subscription', 'message' => '활성 유료 구독이 없습니다.'], 400);
}

$billingKey = (string)($member['portone_billing_key'] ?? '');
if ($billingKey !== '') {
    try {
        portone_api_call('DELETE', '/billing-keys/' . urlencode($billingKey));
    } catch (Throwable $e) {
        error_log('[billing/cancel] 빌링키 삭제 실패 (cron 차단으로 충분): ' . $e->getMessage());
    }
}

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
    portone_response(['status' => 'error', 'code' => 'db_write', 'message' => 'DB 갱신 실패: ' . $e->getMessage()], 500);
}

portone_response([
    'status' => 'ok',
    'cancel_at_period_end' => true,
    'plan' => $currentPlan,
    'plan_status' => $currentStatus,
    'access_until' => (string)($member['current_period_end'] ?? ''),
]);
