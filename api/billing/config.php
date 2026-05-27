<?php
/**
 * PortOne JS SDK 가 사용하는 publishable config 응답.
 *
 * 노출 OK 값 (frontend 에 박혀도 무방한 값):
 *   - storeId         (가맹점 ID, store-xxxxxxxx)
 *   - channelKey      (토스페이먼츠 채널 키)
 *   - plans           (각 plan 의 price + label)
 *
 * 절대 노출 금지:
 *   - PORTONE_API_SECRET, PORTONE_WEBHOOK_SECRET
 */

declare(strict_types=1);

require_once __DIR__ . '/../billing_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    portone_response(['status' => 'error', 'code' => 'method_not_allowed'], 405);
}

try {
    $storeId = portone_env('PORTONE_STORE_ID');
    $channelKey = portone_env('PORTONE_CHANNEL_KEY_TOSS');
} catch (Throwable $e) {
    portone_response(['status' => 'error', 'code' => 'config_missing', 'message' => $e->getMessage()], 503);
}

// 사장님 2026-05-28 — VAT 별도 정책. 앱팀(어센트라) 요청 §5 스키마 통일.
//   price         : 실제 결제 청구 금액 (VAT 포함, 26,400 등)
//   price_display : 사용자 카드 표시 금액 (공급가액, 24,000 등)
//   vat_excluded  : VAT 별도 플래그 (사용자 표시 시 "(VAT 별도)" 라벨)
//   minutes       : 월 AI 요약 한도 (분)
//   amount        : 옛 클라이언트 호환 (= price = 결제 청구액)
$planKeys = ['sales', 'master', 'agency'];
$plans = [];
foreach ($planKeys as $k) {
    $plans[$k] = [
        'label'         => portone_plan_label($k),
        'price'         => portone_plan_amount($k),
        'price_display' => plan_supply_amount($k),
        'vat_amount'    => plan_vat_amount($k),
        'vat_excluded'  => true,
        'minutes'       => plan_default_summary_limit_minutes($k),
        'amount'        => portone_plan_amount($k),  // 옛 호환
    ];
}

portone_response([
    'status' => 'ok',
    'storeId' => $storeId,
    'channelKey' => $channelKey,
    'plans' => $plans,
    'currency' => 'KRW',
]);
