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

portone_response([
    'status' => 'ok',
    'storeId' => $storeId,
    'channelKey' => $channelKey,
    'plans' => [
        'sales' => [
            'label' => portone_plan_label('sales'),
            'amount' => portone_plan_amount('sales'),
        ],
        'master' => [
            'label' => portone_plan_label('master'),
            'amount' => portone_plan_amount('master'),
        ],
        'agency' => [
            'label' => portone_plan_label('agency'),
            'amount' => portone_plan_amount('agency'),
        ],
    ],
    'currency' => 'KRW',
]);
