<?php
/**
 * GET  /billing/topup-settings.php  → { ok, auto_topup_enabled, topup_balance_minutes }
 * POST /billing/topup-settings.php  { auto_topup_enabled } → 저장 후 동일 응답.
 * 앱팀 스펙 ■5 (2026-06-18). 인증: Authorization: Bearer <Supabase JWT>.
 */
declare(strict_types=1);

require_once __DIR__ . '/../billing_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    portone_response(['ok' => false, 'code' => 'method_not_allowed'], 405);
}

$ownerEmail = billing_require_bearer_email();

try {
    $pdo = billing_pdo();
} catch (Throwable $e) {
    portone_response(['ok' => false, 'code' => 'db_connect', 'message' => 'DB 연결 실패'], 500);
}
billing_ensure_tables($pdo);

if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($body) || !array_key_exists('auto_topup_enabled', $body)) {
        portone_response(['ok' => false, 'code' => 'invalid_request', 'message' => 'auto_topup_enabled 누락'], 400);
    }
    $enabled = !empty($body['auto_topup_enabled']) ? 1 : 0;
    try {
        $pdo->prepare("UPDATE members SET auto_topup_enabled=:v WHERE LOWER(email)=LOWER(:e)")
            ->execute([':v' => $enabled, ':e' => $ownerEmail]);
    } catch (Throwable $e) {
        portone_response(['ok' => false, 'code' => 'db_error', 'message' => '저장 실패'], 500);
    }
}

try {
    $st = $pdo->prepare("SELECT COALESCE(auto_topup_enabled,0) AS en, COALESCE(topup_balance_minutes,0) AS bal
                         FROM members WHERE LOWER(email)=LOWER(:e) LIMIT 1");
    $st->execute([':e' => $ownerEmail]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['en' => 0, 'bal' => 0];
} catch (Throwable $e) {
    $row = ['en' => 0, 'bal' => 0];
}

portone_response([
    'ok' => true,
    'auto_topup_enabled' => (bool)(int)$row['en'],
    'topup_balance_minutes' => (int)$row['bal'],
]);
