<?php
/**
 * POST /billing/topup-verify.php  — 일회성 충전권(Google Play consumable) 결제 검증 + 충전.
 * 앱팀 스펙 ■3 (2026-06-18).
 *
 * 요청(JSON): { purchase_token, product_id?, order_id? }
 * 인증: Authorization: Bearer <Supabase JWT> (billing_require_bearer_email).
 * 처리: purchase_token UNIQUE 멱등 → Google Play 검증(purchaseState==0) → topup_balance_minutes += 80 → consume.
 * 응답: { ok, balance_minutes, duplicate }
 */
declare(strict_types=1);

require_once __DIR__ . '/../billing_helpers.php';
require_once __DIR__ . '/google_play_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    portone_response(['ok' => false, 'code' => 'method_not_allowed'], 405);
}

$raw  = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body)) {
    portone_response(['ok' => false, 'code' => 'invalid_request', 'message' => 'JSON 형식 오류'], 400);
}

$purchaseToken = trim((string)($body['purchase_token'] ?? ''));
$productId     = trim((string)($body['product_id'] ?? '')) ?: topup_product_id();
$orderId       = trim((string)($body['order_id'] ?? ''));

if ($purchaseToken === '') {
    portone_response(['ok' => false, 'code' => 'invalid_request', 'message' => 'purchase_token 누락'], 400);
}
if ($productId !== topup_product_id()) {
    portone_response(['ok' => false, 'code' => 'product_mismatch', 'message' => 'product_id 가 충전권 상품과 불일치'], 400);
}

$ownerEmail = billing_require_bearer_email();

try {
    $pdo = billing_pdo();
} catch (Throwable $e) {
    portone_response(['ok' => false, 'code' => 'db_connect', 'message' => 'DB 연결 실패'], 500);
}
billing_ensure_tables($pdo);

// 1) 이미 처리된 token → 멱등 응답 (중복 차감 차단)
try {
    $st = $pdo->prepare("SELECT status FROM topup_purchases WHERE purchase_token=:t LIMIT 1");
    $st->execute([':t' => $purchaseToken]);
    $existing = $st->fetchColumn();
    if ($existing && in_array($existing, ['verified', 'consumed'], true)) {
        portone_response(['ok' => true, 'balance_minutes' => topup_get_balance($pdo, $ownerEmail), 'duplicate' => true]);
    }
} catch (Throwable $e) { /* 테이블 ensure 직후 — 계속 진행 */ }

// 2) Google Play Developer API 검증
$pkg = trim((string)billing_load_env_value('GOOGLE_PLAY_PACKAGE_NAME')) ?: 'com.youngmanapp';
$res = google_play_get_product($pkg, $productId, $purchaseToken);
$http = (int)$res['http'];
$info = $res['body'];
if ($http < 200 || $http >= 300 || !is_array($info)) {
    error_log('[topup-verify] google api ' . $http . ': ' . substr((string)$res['raw'], 0, 400));
    portone_response(['ok' => false, 'code' => 'google_api_error', 'message' => 'Google 검증 실패 (' . $http . ')', 'http' => $http], 400);
}
$purchaseState = isset($info['purchaseState']) ? (int)$info['purchaseState'] : -1;
if ($purchaseState !== 0) {
    portone_response(['ok' => false, 'code' => 'not_purchased', 'message' => '구매 완료 상태가 아닙니다 (purchaseState=' . $purchaseState . ')'], 400);
}
if ($orderId === '') $orderId = (string)($info['orderId'] ?? '');

// 3) 적립 (purchase_token 멱등)
$result = topup_credit($pdo, $ownerEmail, $purchaseToken, $orderId, $productId, topup_minutes_per_purchase());

// 4) consume (서버 ACK — 소비형은 앱 consumeAsync 와 병행. 실패해도 적립은 유효)
if (!$result['duplicate']) {
    try { google_play_acknowledge_product($pkg, $productId, $purchaseToken); } catch (Throwable $e) { /* 무시 */ }
    topup_mark_consumed($pdo, $purchaseToken);
}

portone_response([
    'ok' => true,
    'balance_minutes' => (int)$result['balance_minutes'],
    'duplicate' => (bool)$result['duplicate'],
]);
