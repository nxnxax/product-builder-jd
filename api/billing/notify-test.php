<?php
/**
 * 임시 테스트 — 관리자(admin) "실시간 결제 알림 팝업" 확인용.
 * 실제 결제/payments 기록 없이 notify_admin_new_payment(FCM) 만 발사한다.
 *   → 계정 전환 불필요(관리자 nxnxax 로 로그인된 폰에 그대로 푸시), 매출 데이터 오염 없음.
 *
 * 호출: GET /billing/notify-test.php?confirm=youngman-notify-test-2026
 *        [&kind=subscription|topup|overage|renewal & amount=14900 & plan=Sales & buyer=test@x.com]
 *
 * 응답의 fcm.sent > 0 = 관리자 폰으로 푸시 발송됨. fcm.reason=no_tokens = 관리자 폰에 FCM 토큰 미등록
 *   (그 폰에서 nxnxax 로 앱 로그인 한 번 하면 토큰 등록됨).
 *
 * 출시 전 비활성(stub)화 할 것.
 */
declare(strict_types=1);
require_once __DIR__ . '/../billing_helpers.php';
require_once __DIR__ . '/../fcm_helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_GET['confirm'] ?? '') !== 'youngman-notify-test-2026') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$kind   = in_array(($_GET['kind'] ?? ''), ['subscription', 'topup', 'overage', 'renewal'], true) ? (string)$_GET['kind'] : 'subscription';
$amount = max(0, (int)($_GET['amount'] ?? 14900));
$plan   = (string)($_GET['plan'] ?? 'Sales');
$buyer  = (string)($_GET['buyer'] ?? 'test-buyer@example.com');

try {
    $pdo = billing_pdo();
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'db: ' . $e->getMessage()]);
    exit;
}

if (!function_exists('notify_admin_new_payment')) {
    echo json_encode(['ok' => false, 'error' => 'notify_admin_new_payment 함수 없음 (fcm_helpers 확인 필요)']);
    exit;
}

$res = notify_admin_new_payment($pdo, $kind, $buyer, $amount, $plan);
echo json_encode([
    'ok'     => true,
    'note'   => '관리자(nxnxax) 폰으로 결제 알림 푸시 발송 테스트. fcm.sent>0 = 발송됨, no_tokens = 토큰 미등록.',
    'kind'   => $kind,
    'amount' => $amount,
    'plan'   => $plan,
    'buyer'  => $buyer,
    'fcm'    => $res,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
