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

// 사장님 2026-06-18 — 출시기념 할인가 (VAT 포함 최종가). 앱팀(어센트라) 스키마.
//   price          : 실제 결제 청구 금액 (VAT 포함 최종가, 14,900 등)
//   price_display  : 사용자 카드 표시 금액 (= 청구액, VAT 포함 최종가)
//   price_original : 정가 (할인 전, 줄긋기 strikethrough 표시용 — 결제엔 미사용)
//   supply_amount  : 공급가액 (VAT 제외, 세금계산서용)
//   vat_amount     : 부가세
//   vat_excluded   : false (이제 표시가가 VAT 포함 최종가)
//   minutes        : 월 AI 요약 한도 (분)
//   amount         : 옛 클라이언트 호환 (= price = 결제 청구액)
$planKeys = ['sales', 'master', 'agency'];
$plans = [];
foreach ($planKeys as $k) {
    $plans[$k] = [
        'label'          => portone_plan_label($k),
        'price'          => portone_plan_amount($k),
        'price_display'  => portone_plan_amount($k),
        'price_original' => plan_list_price($k),
        'supply_amount'  => plan_supply_amount($k),
        'vat_amount'     => plan_vat_amount($k),
        'vat_excluded'   => false,
        'minutes'        => plan_default_summary_limit_minutes($k),
        'amount'         => portone_plan_amount($k),  // 옛 호환
    ];
}

portone_response([
    'status' => 'ok',
    'storeId' => $storeId,
    'channelKey' => $channelKey,
    'plans' => $plans,
    'currency' => 'KRW',
]);
