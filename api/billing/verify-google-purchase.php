<?php
/**
 * Google Play Billing — 구독 영수증 검증 + plan 활성화.
 *
 * 앱팀 (어센트라 v83+) 이 BillingClient 결제 완료 후 호출:
 *   POST /api/billing/verify-google-purchase.php
 *   Headers: Authorization: Bearer {Supabase JWT}
 *   Body: { purchaseToken, productId, planKey, packageName }
 *
 * 처리:
 *   1. JWT 로 Supabase /auth/v1/user 검증 (billing_require_bearer_email)
 *   2. Service Account key 로 OAuth2 access token 발급 (1시간 캐시)
 *   3. purchases.subscriptions.get(packageName, productId, purchaseToken) 호출
 *   4. 본인 사용자 매칭 (obfuscatedAccountId 또는 emailAddress) + paymentState=1 또는 2 확인
 *   5. members UPDATE (plan, plan_status='active', current_period_end=expiryTimeMillis 파싱)
 *      + usage_seconds_period=0, free_summaries_used=0, last_usage_warning_pct=0 reset (이월 금지)
 *      + subscriptions INSERT (이력)
 *      + payments INSERT (이력 — Google 영수증 raw 저장)
 *   6. 응답: { ok: true, plan: '...' }
 *
 * 환경변수 (.env):
 *   GOOGLE_PLAY_PACKAGE_NAME           — 예: com.youngmanapp
 *   GOOGLE_PLAY_SERVICE_ACCOUNT_JSON   — Service Account JSON (전체 내용 한 줄 또는 파일경로)
 *
 * Google Play Console → 설정 → API 액세스 → Service Account "주문 및 구독 관리" 권한 부여 필수.
 */

declare(strict_types=1);

require_once __DIR__ . '/../billing_helpers.php';
require_once __DIR__ . '/google_play_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    portone_response(['status' => 'error', 'code' => 'method_not_allowed'], 405);
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$purchaseToken = trim((string)($body['purchaseToken'] ?? ''));
$productId     = trim((string)($body['productId'] ?? ''));
$planKey       = strtolower(trim((string)($body['planKey'] ?? '')));
$packageName   = trim((string)($body['packageName'] ?? ''));

if ($purchaseToken === '' || $productId === '' || $planKey === '') {
    portone_response(['ok' => false, 'code' => 'invalid_request', 'message' => 'purchaseToken / productId / planKey 누락'], 400);
}
if (!in_array($planKey, ['sales', 'master', 'agency'], true)) {
    portone_response(['ok' => false, 'code' => 'invalid_plan', 'message' => 'planKey 는 sales / master / agency 만 허용'], 400);
}

// 사용자 인증
$ownerEmail = billing_require_bearer_email();

// 환경변수
$expectedPackage = trim(billing_load_env_value('GOOGLE_PLAY_PACKAGE_NAME')) ?: 'com.youngmanapp';
if ($packageName !== '' && $packageName !== $expectedPackage) {
    portone_response(['ok' => false, 'code' => 'package_mismatch', 'message' => 'packageName 불일치'], 400);
}
$packageName = $expectedPackage;

// productId ↔ planKey 매핑 검증 (위변조 방지)
$expectedProductId = google_play_product_id($planKey);
if ($expectedProductId !== '' && $productId !== $expectedProductId) {
    portone_response(['ok' => false, 'code' => 'product_mismatch', 'message' => 'productId 가 planKey 와 불일치'], 400);
}

// Google Play API 호출 (helper 가 access token 발급까지 처리)
try {
    $result = google_play_get_subscription($packageName, $productId, $purchaseToken);
} catch (Throwable $e) {
    error_log('[verify-google-purchase] google API 호출 실패: ' . $e->getMessage());
    portone_response(['ok' => false, 'code' => 'google_auth_failed', 'message' => 'Google API 호출 실패: ' . $e->getMessage()], 502);
}
$httpCode = $result['http'];
$resp = $result['body'];
if ($httpCode < 200 || $httpCode >= 300 || !is_array($resp)) {
    $msg = is_array($resp) ? ($resp['error']['message'] ?? '') : '';
    error_log('[verify-google-purchase] http ' . $httpCode . ' resp=' . substr((string)$result['raw'], 0, 300));
    portone_response(['ok' => false, 'code' => 'google_api_error', 'message' => 'Google 검증 실패 (' . $httpCode . '): ' . $msg, 'http' => $httpCode], 400);
}

/**
 * Google Play purchases.subscriptions.get 응답 필드 (일부):
 *   - paymentState: 0=결제 대기 / 1=결제 완료 / 2=무료 체험 / 3=구독 보류
 *   - acknowledgementState: 0=미확인 / 1=확인됨
 *   - expiryTimeMillis: 구독 만료 시각 (ms)
 *   - autoRenewing: 자동 갱신 여부
 *   - cancelReason: 0=사용자 해지 / 1=시스템 / ... (있을 때만)
 *   - obfuscatedExternalAccountId / emailAddress: 본인 매칭용 (옵션)
 */
$paymentState = isset($resp['paymentState']) ? (int)$resp['paymentState'] : -1;
$expiryMs     = isset($resp['expiryTimeMillis']) ? (int)$resp['expiryTimeMillis'] : 0;
$autoRenewing = !empty($resp['autoRenewing']);
$linkedEmail  = strtolower((string)($resp['emailAddress'] ?? ''));
$linkedExtId  = strtolower((string)($resp['obfuscatedExternalAccountId'] ?? ''));

// 결제 상태 검증 — 1(완료) 또는 2(무료 체험) 만 활성화.
if (!in_array($paymentState, [1, 2], true)) {
    portone_response(['ok' => false, 'code' => 'payment_pending', 'message' => '결제가 완료되지 않은 상태입니다 (paymentState=' . $paymentState . ')', 'payment_state' => $paymentState], 400);
}

// 본인 매칭 (있을 때만, 옵션) — 앱이 obfuscatedAccountId 로 ownerEmail hash 보내면 검증.
if ($linkedEmail !== '' && $linkedEmail !== strtolower($ownerEmail)) {
    error_log('[verify-google-purchase] email mismatch: linked=' . $linkedEmail . ' bearer=' . $ownerEmail);
    portone_response(['ok' => false, 'code' => 'account_mismatch', 'message' => '구독 계정과 로그인 계정이 불일치합니다.'], 403);
}

// 만료 시각 파싱
$periodEnd = $expiryMs > 0 ? date('Y-m-d H:i:s', (int)floor($expiryMs / 1000)) : date('Y-m-d H:i:s', strtotime('+30 days'));
$now = date('Y-m-d H:i:s');

// DB 갱신
try {
    $pdo = billing_pdo();
} catch (Throwable $e) {
    portone_response(['ok' => false, 'code' => 'db_connect', 'message' => 'DB 연결 실패: ' . $e->getMessage()], 500);
}

try {
    // 사용량 이월 금지 — 신규 결제 시 usage_seconds_period 도 0 으로 reset (사장님 2026-05-26 정책).
    $newLimit = plan_default_summary_limit($planKey);
    $pdo->prepare("UPDATE members SET
            plan = :plan,
            plan_status = 'active',
            current_period_start = :ps,
            current_period_end = :pe,
            cancel_at_period_end = 0,
            free_summaries_used = 0,
            usage_seconds_period = 0,
            last_usage_warning_pct = 0,
            last_usage_reset_at = :now,
            summary_limit = :slim
        WHERE LOWER(email) = LOWER(:email)")
        ->execute([
            ':plan' => $planKey,
            ':ps' => $now,
            ':pe' => $periodEnd,
            ':now' => $now,
            ':slim' => $newLimit,
            ':email' => $ownerEmail,
        ]);

    // 사장님 2026-05-28 — VAT 별도 정책. 공급가액/세액/합계 분리 저장 (세금계산서 발행용).
    $supplyAmt = plan_supply_amount($planKey);
    $vatAmt    = plan_vat_amount($planKey);
    $totalAmt  = portone_plan_amount($planKey);
    $pdo->prepare("INSERT INTO subscriptions
            (owner_email, plan, status, portone_customer_id, portone_billing_key, current_period_start, current_period_end,
             supply_amount, vat_amount, total_amount)
            VALUES (:o, :p, 'active', :gp_token, :gp_token, :ps, :pe, :sa, :va, :ta)")
        ->execute([
            ':o' => $ownerEmail,
            ':p' => $planKey,
            ':gp_token' => substr($purchaseToken, 0, 120),  // Google purchase token 일부 (이력 추적)
            ':ps' => $now,
            ':pe' => $periodEnd,
            ':sa' => $supplyAmt,
            ':va' => $vatAmt,
            ':ta' => $totalAmt,
        ]);

    $pdo->prepare("INSERT INTO payments
            (owner_email, portone_payment_id, amount, currency, status, paid_at, raw_event_json,
             supply_amount, vat_amount, total_amount)
            VALUES (:o, :pid, :amt, 'KRW', 'PAID', :paid, :raw, :sa, :va, :ta)")
        ->execute([
            ':o' => $ownerEmail,
            ':pid' => 'gplay-' . substr(md5($purchaseToken), 0, 16),
            ':amt' => $totalAmt,
            ':paid' => $now,
            ':raw' => substr(json_encode(['provider' => 'google_play', 'productId' => $productId, 'response' => $resp], JSON_UNESCAPED_UNICODE), 0, 4000),
            ':sa' => $supplyAmt,
            ':va' => $vatAmt,
            ':ta' => $totalAmt,
        ]);
} catch (Throwable $e) {
    error_log('[verify-google-purchase] DB write 실패: ' . $e->getMessage());
    portone_response(['ok' => false, 'code' => 'db_write', 'message' => 'DB 갱신 실패: ' . $e->getMessage()], 500);
}

portone_response([
    'ok' => true,
    'plan' => $planKey,
    'plan_status' => 'active',
    'current_period_end' => $periodEnd,
    'auto_renewing' => $autoRenewing,
]);

