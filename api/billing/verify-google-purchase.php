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
require_once __DIR__ . '/../fcm_helpers.php'; // 사장님 실시간 결제 알림

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

// 사장님 2026-05-29 — 앱팀 401 진단용 호출 파라미터 로깅 (raw token / packageName / productId / 사용자).
error_log(sprintf(
    '[verify-google-purchase] call params: pkg=%s productId=%s planKey=%s tokenLen=%d ownerEmail=%s',
    $packageName, $productId, $planKey, strlen($purchaseToken), $ownerEmail
));

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
    // 사장님 2026-05-29 — Google API errors 자세히 추출 + client 에 노출 (앱팀 진단).
    $err = is_array($resp) ? ($resp['error'] ?? []) : [];
    $errMsg    = is_array($err) ? (string)($err['message'] ?? '') : '';
    $errStatus = is_array($err) ? (string)($err['status'] ?? '') : '';
    $errFirst  = is_array($err) && is_array($err['errors'] ?? null) ? ($err['errors'][0] ?? []) : [];
    $errReason = is_array($errFirst) ? (string)($errFirst['reason'] ?? '') : '';
    $errDomain = is_array($errFirst) ? (string)($errFirst['domain'] ?? '') : '';
    error_log(sprintf(
        '[verify-google-purchase] http=%d status=%s reason=%s domain=%s msg=%s raw=%s',
        $httpCode, $errStatus, $errReason, $errDomain, $errMsg, substr((string)$result['raw'], 0, 800)
    ));
    portone_response([
        'ok' => false,
        'code' => 'google_api_error',
        'message' => 'Google 검증 실패 (' . $httpCode . '): ' . $errMsg,
        'http' => $httpCode,
        'google_status' => $errStatus,
        'google_reason' => $errReason,
        'google_domain' => $errDomain,
        'google_raw_excerpt' => substr((string)$result['raw'], 0, 600),
    ], 400);
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
    $newLimitMin = plan_default_summary_limit_minutes($planKey);  // 분 한도 (sales 300 / master 700 / agency 1500)
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
            summary_limit = :slim,
            summary_limit_minutes = :slm
        WHERE LOWER(email) = LOWER(:email)")
        ->execute([
            ':plan' => $planKey,
            ':ps' => $now,
            ':pe' => $periodEnd,
            ':now' => $now,
            ':slim' => $newLimit,
            ':slm' => $newLimitMin,
            ':email' => $ownerEmail,
        ]);

    // 사장님 2026-05-28 — VAT 별도 정책. 공급가액/세액/합계 분리 저장 (세금계산서 발행용).
    $supplyAmt = plan_supply_amount($planKey);
    $vatAmt    = plan_vat_amount($planKey);
    $totalAmt  = portone_plan_amount($planKey);
    // 구독 이력(subscriptions)은 best-effort — 별도 try 로 격리한다.
    // 2026-06-19: 이 INSERT 가 실패(예: 토큰 truncation 1406)하면 아래 payments(매출 단일기준) 기록까지
    // 통째로 날아가 "최초 결제건조차 안 남는" 사고가 났었다. 이력 실패가 매출 기록을 막지 않도록 분리.
    try {
        $pdo->prepare("INSERT INTO subscriptions
                (owner_email, plan, status, portone_customer_id, portone_billing_key, current_period_start, current_period_end,
                 supply_amount, vat_amount, total_amount)
                VALUES (:o, :p, 'active', :gp_cust, :gp_key, :ps, :pe, :sa, :va, :ta)")
            ->execute([
                ':o' => $ownerEmail,
                ':p' => $planKey,
                // portone_customer_id 는 VARCHAR(64) — Google purchase token(200~300자) 직접 넣으면 1406 truncation.
                // → customer_id 는 짧은 google 식별자, billing_key(VARCHAR128)엔 token 앞부분만 보관(이력 추적용).
                ':gp_cust' => 'gplay-' . substr(md5($purchaseToken), 0, 24),
                ':gp_key'  => substr($purchaseToken, 0, 120),
                ':ps' => $now,
                ':pe' => $periodEnd,
                ':sa' => $supplyAmt,
                ':va' => $vatAmt,
                ':ta' => $totalAmt,
            ]);
    } catch (Throwable $e) {
        error_log('[verify-google-purchase] subscriptions 이력 기록 실패(매출 기록은 계속 진행): ' . $e->getMessage());
    }

    // 동일 purchaseToken 의 결제 행 멱등 — 앱 재검증(앱 재실행 시 verify 재호출) 으로
    // 같은 구매가 중복 기록/중복 알림 되지 않게 한다. (갱신은 Google 이 새 token 발급 → 새 pid → 정상 기록)
    $payPid = 'gplay-' . substr(md5($purchaseToken), 0, 16);
    $exists = $pdo->prepare("SELECT 1 FROM payments WHERE portone_payment_id = :pid LIMIT 1");
    $exists->execute([':pid' => $payPid]);
    $isNewPayment = !$exists->fetchColumn();

    if ($isNewPayment) {
        $pdo->prepare("INSERT IGNORE INTO payments
                (owner_email, portone_payment_id, amount, currency, status, paid_at, raw_event_json,
                 supply_amount, vat_amount, total_amount)
                VALUES (:o, :pid, :amt, 'KRW', 'PAID', :paid, :raw, :sa, :va, :ta)")
            ->execute([
                ':o' => $ownerEmail,
                ':pid' => $payPid,
                ':amt' => $totalAmt,
                ':paid' => $now,
                ':raw' => substr(json_encode(['provider' => 'google_play', 'productId' => $productId, 'response' => $resp], JSON_UNESCAPED_UNICODE), 0, 4000),
                ':sa' => $supplyAmt,
                ':va' => $vatAmt,
                ':ta' => $totalAmt,
            ]);
    }
} catch (Throwable $e) {
    error_log('[verify-google-purchase] DB write 실패: ' . $e->getMessage());
    portone_response(['ok' => false, 'code' => 'db_write', 'message' => 'DB 갱신 실패: ' . $e->getMessage()], 500);
}

// 사장님 실시간 결제 알림 — 새 결제 행이 기록됐을 때만. 실패해도 결제 응답엔 영향 없음.
if (!empty($isNewPayment) && function_exists('notify_admin_new_payment')) {
    try { notify_admin_new_payment($pdo, 'subscription', $ownerEmail, (int)$totalAmt, (string)$planKey); }
    catch (Throwable $e) { error_log('[verify-google] admin notify: ' . $e->getMessage()); }
}

portone_response([
    'ok' => true,
    'plan' => $planKey,
    'plan_status' => 'active',
    'current_period_end' => $periodEnd,
    'auto_renewing' => $autoRenewing,
]);

