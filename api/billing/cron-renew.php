<?php
/**
 * 정기결제 자동 갱신 cron.
 *
 * GitHub Actions schedule (매일 KST 03:00) 또는 외부 cron 이 호출.
 * 인증: Authorization: Bearer {AUDIO_CLEANUP_TOKEN} 같은 패턴으로 token 검증.
 *       (별도 토큰 발급도 가능하지만 audio_cleanup 처럼 동일 시크릿 재사용 — 보안 충분)
 *
 * 흐름:
 *   1. members 중 다음 조건 row 검색:
 *      - plan IN (sales, master, agency)
 *      - plan_status = 'active'
 *      - current_period_end <= NOW()
 *      - portone_billing_key IS NOT NULL
 *      - cancel_at_period_end = 0  (해지 신청 안 한 사용자만)
 *   2. 각 row 에 대해 PortOne 빌링키 결제 API 호출
 *   3. 성공 → period 연장 (+30일) + free_summaries_used=0 reset
 *      실패 → past_due 로 전환 (재시도는 다음 cron 실행에서)
 *   4. cancel_at_period_end=1 인 row 는 plan='free' 로 강등
 *   5. 결과 JSON 응답 (성공/실패/skip 카운트)
 *
 * Idempotency: 같은 사용자에게 같은 날 두 번 결제 시도 안 함 (paymentId 에 날짜 포함).
 */

declare(strict_types=1);

require_once __DIR__ . '/../billing_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    portone_response(['status' => 'error', 'code' => 'method_not_allowed'], 405);
}

// 토큰 검증 — audio_cleanup 과 동일한 시크릿 재사용. timing-safe 비교.
$expectedToken = billing_load_env_value('AUDIO_CLEANUP_TOKEN') ?: (getenv('AUDIO_CLEANUP_TOKEN') ?: '');
if ($expectedToken === '') {
    portone_response(['status' => 'error', 'code' => 'config', 'message' => 'cron token 미설정'], 503);
}
$providedToken = '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.+)/i', (string)$auth, $m)) $providedToken = trim($m[1]);
if ($providedToken === '') $providedToken = (string)($_GET['token'] ?? '');
if (!hash_equals($expectedToken, $providedToken)) {
    portone_response(['status' => 'error', 'code' => 'unauthorized'], 401);
}

// 옵션 — dry_run 이면 실제 결제 안 함, 대상 row 만 조회.
$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === 'true';

try {
    $pdo = billing_pdo();
} catch (Throwable $e) {
    portone_response(['status' => 'error', 'code' => 'db', 'message' => 'DB 연결 실패: ' . $e->getMessage()], 500);
}

$now = date('Y-m-d H:i:s');
$counts = ['target' => 0, 'paid' => 0, 'failed' => 0, 'downgraded' => 0, 'skipped' => 0];
$details = [];

// 1) cancel_at_period_end=1 + 기간 만료 → free 강등.
try {
    $expiredCancelled = $pdo->prepare("SELECT email FROM members
        WHERE cancel_at_period_end = 1
          AND current_period_end IS NOT NULL
          AND current_period_end <= NOW()
          AND plan IN ('sales', 'master', 'agency')");
    $expiredCancelled->execute();
    while ($r = $expiredCancelled->fetch()) {
        if ($dryRun) { $counts['downgraded']++; continue; }
        $pdo->prepare("UPDATE members SET
                plan = 'free',
                plan_status = 'cancelled',
                portone_billing_key = NULL,
                cancel_at_period_end = 0
            WHERE email = :e")->execute([':e' => $r['email']]);
        $pdo->prepare("UPDATE subscriptions SET status = 'cancelled', updated_at = NOW()
            WHERE owner_email = :e AND status = 'active'")->execute([':e' => $r['email']]);
        $counts['downgraded']++;
        $details[] = ['email' => $r['email'], 'action' => 'downgraded_to_free'];
    }
} catch (Throwable $e) {
    error_log('[cron-renew] expired-cancelled query failed: ' . $e->getMessage());
}

// 2) 결제 갱신 대상 — current_period_end 도달 + 빌링키 보유 + 미해지.
try {
    $stmt = $pdo->prepare("SELECT email, plan, portone_billing_key, portone_customer_id
        FROM members
        WHERE plan IN ('sales', 'master', 'agency')
          AND plan_status IN ('active', 'past_due')
          AND current_period_end IS NOT NULL
          AND current_period_end <= NOW()
          AND portone_billing_key IS NOT NULL
          AND cancel_at_period_end = 0");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $counts['target'] = count($rows);
} catch (Throwable $e) {
    portone_response(['status' => 'error', 'code' => 'db', 'message' => '대상 조회 실패: ' . $e->getMessage()], 500);
}

foreach ($rows as $row) {
    $email = (string)$row['email'];
    $plan = strtolower((string)$row['plan']);
    $billingKey = (string)$row['portone_billing_key'];
    $amount = portone_plan_amount($plan);
    if ($amount === 0 || $billingKey === '') {
        $counts['skipped']++;
        continue;
    }

    if ($dryRun) {
        $counts['paid']++; // 가상으로 카운트
        $details[] = ['email' => $email, 'action' => 'dry_run_would_pay', 'amount' => $amount];
        continue;
    }

    // paymentId 에 날짜 포함 → 같은 날 재시도 시 자동 idempotent (PortOne 가 중복 거부)
    $paymentId = 'renew-' . date('Ymd') . '-' . substr(md5($email), 0, 10);
    $orderName = portone_plan_label($plan);

    try {
        $resp = portone_api_call('POST', '/payments/' . urlencode($paymentId) . '/billing-key', [
            'billingKey' => $billingKey,
            'orderName' => $orderName,
            'amount' => ['total' => $amount],
            'currency' => 'KRW',
            'customer' => ['id' => $row['portone_customer_id'] ?? $email, 'email' => $email],
        ]);
    } catch (Throwable $e) {
        error_log('[cron-renew] portone call failed (' . $email . '): ' . $e->getMessage());
        $counts['failed']++;
        $details[] = ['email' => $email, 'action' => 'api_error', 'error' => $e->getMessage()];
        continue;
    }

    $ok = ($resp['status'] >= 200 && $resp['status'] < 300);
    $paymentStatus = is_array($resp['body']) ? portone_extract_status($resp['body']) : '';

    if ($ok && $paymentStatus === 'PAID') {
        $newPeriodEnd = date('Y-m-d H:i:s', strtotime('+30 days'));
        try {
            $newLimit = plan_default_summary_limit($plan);
            $newLimitMin = plan_default_summary_limit_minutes($plan);  // 분 한도
            // 사장님 2026-05-26 — 사용량 이월 금지: 매월 갱신 결제 시 usage_seconds_period 도 0 으로 reset.
            // 옛 잔여 분은 삭제되고 새 결제 분만 사용 가능.
            $pdo->prepare("UPDATE members SET
                    plan_status = 'active',
                    current_period_start = :ps,
                    current_period_end = :pe,
                    free_summaries_used = 0,
                    usage_seconds_period = 0,
                    last_usage_warning_pct = 0,
                    last_usage_reset_at = :now,
                    summary_limit = :slim,
                    summary_limit_minutes = :slm
                WHERE email = :e")
                ->execute([':ps' => $now, ':pe' => $newPeriodEnd, ':now' => $now, ':slim' => $newLimit, ':slm' => $newLimitMin, ':e' => $email]);
            $pdo->prepare("UPDATE subscriptions SET
                    status = 'active',
                    current_period_start = :ps,
                    current_period_end = :pe,
                    updated_at = NOW()
                WHERE owner_email = :e AND status IN ('active', 'past_due')")
                ->execute([':ps' => $now, ':pe' => $newPeriodEnd, ':e' => $email]);
            $pdo->prepare("INSERT IGNORE INTO payments
                    (owner_email, portone_payment_id, amount, currency, status, paid_at, raw_event_json)
                    VALUES (:o, :pid, :amt, 'KRW', 'paid', :paid, :raw)")
                ->execute([
                    ':o' => $email,
                    ':pid' => $paymentId,
                    ':amt' => $amount,
                    ':paid' => $now,
                    ':raw' => json_encode($resp['body'], JSON_UNESCAPED_UNICODE),
                ]);
            $counts['paid']++;
            $details[] = ['email' => $email, 'action' => 'paid', 'amount' => $amount];
        } catch (Throwable $e) {
            error_log('[cron-renew] DB update failed after payment (' . $email . '): ' . $e->getMessage());
            $counts['failed']++;
            $details[] = ['email' => $email, 'action' => 'paid_but_db_failed', 'error' => $e->getMessage()];
        }
    } else {
        // 결제 실패 — past_due 로. cron 이 매일 돌면서 7일 정도 재시도.
        $errorMsg = (string)($resp['body']['message'] ?? $resp['body']['type'] ?? 'unknown');
        try {
            $pdo->prepare("UPDATE members SET plan_status = 'past_due' WHERE email = :e")->execute([':e' => $email]);
            $pdo->prepare("INSERT IGNORE INTO payments
                    (owner_email, portone_payment_id, amount, currency, status, raw_event_json)
                    VALUES (:o, :pid, :amt, 'KRW', 'failed', :raw)")
                ->execute([
                    ':o' => $email,
                    ':pid' => $paymentId,
                    ':amt' => $amount,
                    ':raw' => json_encode($resp['body'] ?? $resp, JSON_UNESCAPED_UNICODE),
                ]);
        } catch (Throwable $e) {
            error_log('[cron-renew] DB write (past_due) failed: ' . $e->getMessage());
        }
        $counts['failed']++;
        $details[] = ['email' => $email, 'action' => 'payment_failed', 'http_status' => $resp['status'], 'error' => $errorMsg];
    }
}

portone_response([
    'status' => 'ok',
    'dry_run' => $dryRun,
    'now' => $now,
    'counts' => $counts,
    'details' => $details,
]);
