<?php
/**
 * PortOne V2 Webhook 수신 endpoint.
 *
 * PortOne 콘솔 → Webhook 설정에서 이 URL 등록:
 *   https://youngman-biz.com/billing/webhook-portone.php
 *
 * Standard Webhooks spec — webhook-id / webhook-timestamp / webhook-signature 헤더.
 * Body: { type: "Transaction.Paid" | "Transaction.Failed" | "Transaction.Cancelled" | ...,
 *         data: { paymentId, transactionId, billingKey, ... } }
 *
 * 모든 webhook 은 raw body 를 payments 테이블에 저장 (감사/재처리용).
 * 검증 실패 시 401, 검증 성공 시 항상 200 (PortOne 재시도 차단).
 */

declare(strict_types=1);

require_once __DIR__ . '/../billing_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    portone_response(['status' => 'error', 'code' => 'method_not_allowed'], 405);
}

$rawBody = (string)file_get_contents('php://input');
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
// Apache / Nginx 의 HTTP_ 변환된 header 도 보조로
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $hname = strtolower(str_replace('_', '-', substr($k, 5)));
        if (!isset($headers[$hname])) $headers[$hname] = $v;
    }
}

try {
    $valid = portone_verify_webhook($rawBody, $headers);
} catch (Throwable $e) {
    error_log('[webhook-portone] verify exception: ' . $e->getMessage());
    portone_response(['status' => 'error', 'code' => 'config'], 500);
}
if (!$valid) {
    portone_response(['status' => 'error', 'code' => 'signature_invalid'], 401);
}

$event = json_decode($rawBody, true);
if (!is_array($event)) {
    portone_response(['status' => 'ok', 'note' => 'body parse failed but signature OK']);
}

$type = (string)($event['type'] ?? '');
$data = is_array($event['data'] ?? null) ? $event['data'] : [];
$paymentId = (string)($data['paymentId'] ?? $data['id'] ?? '');

// DB
require_once __DIR__ . '/../db_config.php';
try {
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    error_log('[webhook-portone] DB connect fail: ' . $e->getMessage());
    portone_response(['status' => 'ok', 'note' => 'db unavailable — event logged']);
}

// 결제 정보 추가 조회 — webhook payload 가 minimal 한 경우 PortOne API 로 보강.
$fullPayment = $data;
if ($paymentId !== '' && empty($data['customer']) && in_array($type, ['Transaction.Paid', 'Transaction.PartialCancelled', 'Transaction.Cancelled', 'Transaction.Failed'], true)) {
    try {
        $resp = portone_api_call('GET', '/payments/' . urlencode($paymentId));
        if ($resp['status'] >= 200 && $resp['status'] < 300 && is_array($resp['body'])) {
            $fullPayment = array_merge($data, $resp['body']);
        }
    } catch (Throwable $e) {
        error_log('[webhook-portone] payment lookup failed: ' . $e->getMessage());
    }
}

$ownerEmail = strtolower((string)($fullPayment['customer']['email'] ?? ''));
$amount = (int)($fullPayment['amount']['total'] ?? 0);
$now = date('Y-m-d H:i:s');

try {
    // payments row 항상 INSERT (감사용)
    $pdo->prepare("INSERT INTO payments
            (owner_email, portone_payment_id, amount, currency, status, paid_at, raw_event_json)
            VALUES (:o, :pid, :amt, 'KRW', :st, :paid, :raw)")
        ->execute([
            ':o' => $ownerEmail,
            ':pid' => $paymentId,
            ':amt' => $amount,
            ':st' => $type,
            ':paid' => $now,
            ':raw' => json_encode($event, JSON_UNESCAPED_UNICODE),
        ]);
} catch (Throwable $e) {
    error_log('[webhook-portone] payments INSERT failed: ' . $e->getMessage());
}

// 이벤트 별 처리
if ($ownerEmail !== '') {
    try {
        switch ($type) {
            case 'Transaction.Paid':
                // 결제 성공 — 정기결제 갱신 케이스. members.current_period 연장 + summary_used reset.
                $billingKey = (string)($fullPayment['billingKey'] ?? $fullPayment['method']['billingKey'] ?? '');
                $newPeriodEnd = date('Y-m-d H:i:s', strtotime('+30 days'));
                $pdo->prepare("UPDATE members SET
                        plan_status = 'active',
                        current_period_start = :ps,
                        current_period_end = :pe,
                        free_summaries_used = 0,
                        last_usage_reset_at = :now,
                        portone_billing_key = COALESCE(:bk, portone_billing_key)
                    WHERE email = :e")
                    ->execute([
                        ':ps' => $now,
                        ':pe' => $newPeriodEnd,
                        ':now' => $now,
                        ':bk' => $billingKey !== '' ? $billingKey : null,
                        ':e' => $ownerEmail,
                    ]);
                break;

            case 'Transaction.Failed':
                // 결제 실패 — past_due. cron 재시도 또는 사용자 결제 수단 갱신 후 정상화.
                $pdo->prepare("UPDATE members SET plan_status = 'past_due' WHERE email = :e")
                    ->execute([':e' => $ownerEmail]);
                break;

            case 'Transaction.Cancelled':
            case 'Transaction.PartialCancelled':
                // 환불 등 — subscription 별도 cancel 흐름 (취소 endpoint 가 처리). 여기는 로그만.
                break;

            default:
                // 알 수 없는 이벤트 — 로그만.
                error_log('[webhook-portone] unknown event type: ' . $type);
        }
    } catch (Throwable $e) {
        error_log('[webhook-portone] event handler failed: ' . $e->getMessage());
    }
}

portone_response(['status' => 'ok']);
