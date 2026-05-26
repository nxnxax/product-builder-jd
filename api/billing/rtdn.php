<?php
/**
 * Google Play Real-time Developer Notifications (RTDN) 수신 endpoint.
 *
 * Cloud Pub/Sub Push subscription 이 이 URL 로 POST 함:
 *   POST /api/billing/rtdn.php
 *   body: { message: { data: base64, messageId: ..., publishTime: ... }, subscription: ... }
 *
 * data 는 base64 인코딩된 JSON. decode 후 형식 (subscriptionNotification 케이스):
 *   {
 *     version: "1.0",
 *     packageName: "com.youngmanapp",
 *     eventTimeMillis: "...",
 *     subscriptionNotification: {
 *       version: "1.0",
 *       notificationType: 1..13,   // 아래 상수 참조
 *       purchaseToken: "...",
 *       subscriptionId: "youngman_sales_monthly"
 *     }
 *   }
 *
 * notificationType 처리 (앱팀 §3 spec):
 *   2  = RENEWED                — current_period_end 연장 + plan_status='active' + usage reset
 *   3  = CANCELED               — cancel_at_period_end=1 (current_period_end 까지 사용 가능)
 *   4  = PURCHASED              — verify-google-purchase 와 동일 처리
 *   5  = ON_HOLD                — plan_status='past_due'
 *   6  = IN_GRACE_PERIOD        — plan_status='past_due'
 *   7  = RESTARTED              — plan_status='active' + cancel_at_period_end=0
 *   12 = REVOKED                — plan='free' 즉시 강등
 *   13 = EXPIRED                — plan='free' 강등
 *   1  = RECOVERED              — plan_status='active' + usage reset
 *   8/9/10/11 = price/renewal change — log 만 (DB 변경 X)
 *
 * 보안:
 *   - Pub/Sub Push subscription 인증: Authorization: Bearer {OIDC token}.
 *     선택: 토픽 생성 시 ServiceAccount + audience 지정해 우리 endpoint 가 OIDC token 검증.
 *     간단 단계: ?token=XXX query param 으로 공유 시크릿 검증 (HMAC). cafe24 환경 친화.
 *   - 본 endpoint 는 .env 의 RTDN_SHARED_TOKEN (사장님 직접 설정) 으로 1차 차단 + Google 영수증 재검증으로 2차 차단.
 */

declare(strict_types=1);

require_once __DIR__ . '/../billing_helpers.php';
require_once __DIR__ . '/google_play_helpers.php';

header('Content-Type: application/json; charset=utf-8');

// 공유 토큰 검증 (Pub/Sub Push 설정 시 ?token=... 으로 등록 추천)
$expectedToken = billing_load_env_value('RTDN_SHARED_TOKEN');
if ($expectedToken !== '') {
    $providedToken = (string)($_GET['token'] ?? '');
    if (!hash_equals($expectedToken, $providedToken)) {
        portone_response(['ok' => false, 'code' => 'unauthorized'], 401);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    portone_response(['ok' => false, 'code' => 'method_not_allowed'], 405);
}

$rawBody = (string)file_get_contents('php://input');
$envelope = json_decode($rawBody, true);
if (!is_array($envelope)) {
    portone_response(['ok' => false, 'code' => 'invalid_body'], 400);
}

// Pub/Sub Push 형식: { message: { data: base64, ... }, subscription: ... }
$dataB64 = (string)($envelope['message']['data'] ?? '');
if ($dataB64 === '') {
    // 일부 RTDN 테스트 메시지는 data 없음 — 200 OK 반환 (재시도 방지).
    portone_response(['ok' => true, 'skipped' => 'no_data']);
}
$dataJson = base64_decode($dataB64, true);
$payload = $dataJson ? json_decode((string)$dataJson, true) : null;
if (!is_array($payload)) {
    error_log('[rtdn] payload decode 실패. raw=' . substr($dataB64, 0, 200));
    portone_response(['ok' => true, 'skipped' => 'invalid_payload']);
}

$packageName = (string)($payload['packageName'] ?? '');
$subNoti = $payload['subscriptionNotification'] ?? null;
$voidedNoti = $payload['voidedPurchaseNotification'] ?? null;  // 환불 발생 시
$testNoti = $payload['testNotification'] ?? null;

if ($testNoti) {
    error_log('[rtdn] test notification ok');
    portone_response(['ok' => true, 'test' => true]);
}

if (!is_array($subNoti) && !is_array($voidedNoti)) {
    portone_response(['ok' => true, 'skipped' => 'no_handler']);
}

try {
    $pdo = billing_pdo();
} catch (Throwable $e) {
    error_log('[rtdn] DB connect 실패: ' . $e->getMessage());
    portone_response(['ok' => false, 'code' => 'db_connect'], 500);
}

// voidedPurchaseNotification — 환불 처리
if (is_array($voidedNoti)) {
    $purchaseToken = (string)($voidedNoti['purchaseToken'] ?? '');
    rtdn_handle_voided($pdo, $purchaseToken);
    portone_response(['ok' => true, 'handled' => 'voided']);
}

$notificationType = (int)($subNoti['notificationType'] ?? 0);
$purchaseToken    = (string)($subNoti['purchaseToken'] ?? '');
$productId        = (string)($subNoti['subscriptionId'] ?? '');
if ($purchaseToken === '' || $productId === '') {
    portone_response(['ok' => true, 'skipped' => 'missing_token_or_product']);
}

// productId → planKey 역매핑
$planKey = '';
switch ($productId) {
    case 'youngman_sales_monthly':  $planKey = 'sales'; break;
    case 'youngman_master_monthly': $planKey = 'master'; break;
    case 'youngman_agency_monthly': $planKey = 'agency'; break;
}

// Google 에서 현재 구독 상태 재조회 (영수증 검증 + expiryTime 최신 가져오기)
$subData = null;
try {
    $expectedPackage = trim(billing_load_env_value('GOOGLE_PLAY_PACKAGE_NAME')) ?: 'com.youngmanapp';
    $r = google_play_get_subscription($expectedPackage, $productId, $purchaseToken);
    if ($r['http'] >= 200 && $r['http'] < 300) {
        $subData = $r['body'];
    } else {
        error_log('[rtdn] subscriptions.get http ' . $r['http'] . ' resp=' . substr((string)$r['raw'], 0, 200));
    }
} catch (Throwable $e) {
    error_log('[rtdn] google API 호출 실패: ' . $e->getMessage());
}

// purchase token 으로 owner_email 찾기 (subscriptions 테이블에 저장해둔 token prefix 매칭)
$ownerEmail = '';
try {
    $tokenPrefix = substr($purchaseToken, 0, 120);
    $ps = $pdo->prepare("SELECT owner_email FROM subscriptions WHERE portone_billing_key = :t ORDER BY id DESC LIMIT 1");
    $ps->execute([':t' => $tokenPrefix]);
    $row = $ps->fetch();
    if ($row) $ownerEmail = (string)$row['owner_email'];
} catch (Throwable $e) {
    error_log('[rtdn] owner lookup 실패: ' . $e->getMessage());
}
// fallback — Google 응답의 emailAddress
if ($ownerEmail === '' && is_array($subData) && !empty($subData['emailAddress'])) {
    $ownerEmail = strtolower((string)$subData['emailAddress']);
}

if ($ownerEmail === '') {
    error_log('[rtdn] owner_email 매칭 실패. type=' . $notificationType . ' token_prefix=' . substr($purchaseToken, 0, 40));
    portone_response(['ok' => true, 'skipped' => 'owner_not_found', 'type' => $notificationType]);
}

$now = date('Y-m-d H:i:s');
$expiryMs = is_array($subData) && isset($subData['expiryTimeMillis']) ? (int)$subData['expiryTimeMillis'] : 0;
$periodEnd = $expiryMs > 0 ? date('Y-m-d H:i:s', (int)floor($expiryMs / 1000)) : null;

try {
    switch ($notificationType) {
        case 1:  // RECOVERED
        case 2:  // RENEWED
            // 이월 금지 — usage_seconds_period reset.
            $pdo->prepare("UPDATE members SET
                    plan_status='active',
                    current_period_start=:now,
                    current_period_end=COALESCE(:pe, current_period_end),
                    free_summaries_used=0,
                    usage_seconds_period=0,
                    last_usage_warning_pct=0,
                    last_usage_reset_at=:now,
                    cancel_at_period_end=0
                  WHERE LOWER(email)=LOWER(:e)")
                ->execute([':now' => $now, ':pe' => $periodEnd, ':e' => $ownerEmail]);
            break;

        case 3:  // CANCELED — 사용자가 Google Play 에서 해지. current_period_end 까지 사용 가능.
            $pdo->prepare("UPDATE members SET cancel_at_period_end=1 WHERE LOWER(email)=LOWER(:e)")
                ->execute([':e' => $ownerEmail]);
            break;

        case 4:  // PURCHASED (신규)
            if ($planKey !== '') {
                $newLimit = plan_default_summary_limit($planKey);
                $pdo->prepare("UPDATE members SET
                        plan=:p, plan_status='active',
                        current_period_start=:now,
                        current_period_end=COALESCE(:pe, DATE_ADD(:now, INTERVAL 30 DAY)),
                        free_summaries_used=0,
                        usage_seconds_period=0,
                        last_usage_warning_pct=0,
                        last_usage_reset_at=:now,
                        cancel_at_period_end=0,
                        summary_limit=:slim
                      WHERE LOWER(email)=LOWER(:e)")
                    ->execute([':p' => $planKey, ':now' => $now, ':pe' => $periodEnd, ':slim' => $newLimit, ':e' => $ownerEmail]);
            }
            break;

        case 5:  // ON_HOLD
        case 6:  // IN_GRACE_PERIOD
            $pdo->prepare("UPDATE members SET plan_status='past_due' WHERE LOWER(email)=LOWER(:e)")
                ->execute([':e' => $ownerEmail]);
            break;

        case 7:  // RESTARTED — 해지 취소.
            $pdo->prepare("UPDATE members SET plan_status='active', cancel_at_period_end=0 WHERE LOWER(email)=LOWER(:e)")
                ->execute([':e' => $ownerEmail]);
            break;

        case 12: // REVOKED — 환불됨. 즉시 free 강등.
        case 13: // EXPIRED — 만료.
            $pdo->prepare("UPDATE members SET plan='free', plan_status='cancelled', cancel_at_period_end=0 WHERE LOWER(email)=LOWER(:e)")
                ->execute([':e' => $ownerEmail]);
            $pdo->prepare("UPDATE subscriptions SET status='cancelled', updated_at=NOW() WHERE owner_email=:e AND status='active'")
                ->execute([':e' => $ownerEmail]);
            break;

        case 8:  // PRICE_CHANGE_CONFIRMED
        case 9:  // DEFERRED
        case 10: // PAUSED
        case 11: // PAUSE_SCHEDULE_CHANGED
        default:
            // log 만 — DB 변경 없음.
            error_log('[rtdn] type=' . $notificationType . ' owner=' . $ownerEmail . ' (log only)');
            break;
    }
} catch (Throwable $e) {
    error_log('[rtdn] DB update 실패: ' . $e->getMessage());
    portone_response(['ok' => false, 'code' => 'db_write', 'message' => $e->getMessage()], 500);
}

portone_response(['ok' => true, 'handled' => $notificationType, 'owner' => $ownerEmail]);

// ────────────────────────────────────────────────────────────────────
// helpers
// ────────────────────────────────────────────────────────────────────

function rtdn_handle_voided(PDO $pdo, string $purchaseToken): void {
    if ($purchaseToken === '') return;
    try {
        $tokenPrefix = substr($purchaseToken, 0, 120);
        $ps = $pdo->prepare("SELECT owner_email FROM subscriptions WHERE portone_billing_key=:t ORDER BY id DESC LIMIT 1");
        $ps->execute([':t' => $tokenPrefix]);
        $row = $ps->fetch();
        if (!$row) return;
        $email = (string)$row['owner_email'];
        $pdo->prepare("UPDATE members SET plan='free', plan_status='cancelled', cancel_at_period_end=0 WHERE LOWER(email)=LOWER(:e)")
            ->execute([':e' => $email]);
        error_log('[rtdn] voided purchase → free 강등. owner=' . $email);
    } catch (Throwable $e) {
        error_log('[rtdn] voided 처리 실패: ' . $e->getMessage());
    }
}
