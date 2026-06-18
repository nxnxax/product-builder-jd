<?php
/**
 * 임시 테스트 셋업 — 고정 테스트계정만 "한도 1분 남김 + 자동충전 ON" 으로 세팅.
 * 충전(topup_needed) 흐름 테스트용. 다른 계정 불가, 실사용자 무관.
 * 호출: GET /billing/topup-testsetup.php?confirm=youngman-topup-testsetup-2026
 */
declare(strict_types=1);
require_once __DIR__ . '/../billing_helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_GET['confirm'] ?? '') !== 'youngman-topup-testsetup-2026') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$EMAIL = 'nxnxqx@gmail.com';  // 고정 — 이 테스트 계정만 조작

try {
    $pdo = billing_pdo();
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'db: ' . $e->getMessage()]);
    exit;
}
billing_ensure_tables($pdo);

$st = $pdo->prepare("SELECT plan, COALESCE(summary_limit_minutes,0) AS lim FROM members WHERE LOWER(email)=LOWER(:e) LIMIT 1");
$st->execute([':e' => $EMAIL]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'member_not_found', 'email' => $EMAIL]);
    exit;
}

$limitMin = (int)$row['lim'];
$sets = [];
$params = [':e' => $EMAIL];

// 유료 한도 없으면 sales 300 으로 테스트 셋업 (free 면 AI 요약 자체가 막히므로).
if ($limitMin <= 0) {
    $limitMin = 300;
    $sets[] = "plan = 'sales'";
    $sets[] = "plan_status = 'active'";
    $sets[] = "summary_limit_minutes = 300";
    $sets[] = "summary_limit = 20";
}

// 1분 남기기: 사용량 = (한도 - 1) 분
$usageSec = max(0, ($limitMin - 1) * 60);
$sets[] = "usage_seconds_period = :u";   $params[':u'] = $usageSec;
$sets[] = "free_summaries_used = 0";
$sets[] = "auto_topup_enabled = 1";       // 신규 충전 흐름 동의
$sets[] = "last_usage_warning_pct = 0";
$sets[] = "last_usage_reset_at = NOW()";  // lazy reset 이 usage 0 으로 안 지우게

$pdo->prepare("UPDATE members SET " . implode(', ', $sets) . " WHERE LOWER(email)=LOWER(:e)")->execute($params);

$st2 = $pdo->prepare("SELECT plan, plan_status, summary_limit_minutes, usage_seconds_period,
                             auto_topup_enabled, COALESCE(topup_balance_minutes,0) AS bal
                      FROM members WHERE LOWER(email)=LOWER(:e) LIMIT 1");
$st2->execute([':e' => $EMAIL]);
$r = $st2->fetch(PDO::FETCH_ASSOC);
$usedMin = (int)round(((int)$r['usage_seconds_period']) / 60);

echo json_encode([
    'ok' => true,
    'email' => $EMAIL,
    'limit_min' => (int)$r['summary_limit_minutes'],
    'used_min' => $usedMin,
    'left_min' => (int)$r['summary_limit_minutes'] - $usedMin,
    'auto_topup_enabled' => (int)$r['auto_topup_enabled'],
    'topup_balance_minutes' => (int)$r['bal'],
    'state' => $r,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
