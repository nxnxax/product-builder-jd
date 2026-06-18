<?php
/**
 * 임시 자체 테스트 — 충전권 서버 로직(메세지/적립/멱등/차감 우선순위) 검증.
 * 고정 테스트 이메일만 조작 + 종료 시 정리. 실사용자 무관. 검증 후 파일 제거 예정.
 * 호출: GET /billing/topup-selftest.php?confirm=youngman-topup-selftest-2026
 */
declare(strict_types=1);
require_once __DIR__ . '/../billing_helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_GET['confirm'] ?? '') !== 'youngman-topup-selftest-2026') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$TEST = '__topup_selftest__@youngman.internal';
$steps = [];
$add = function (string $name, bool $pass, $detail) use (&$steps) {
    $steps[] = ['step' => $name, 'pass' => $pass, 'detail' => $detail];
};

try {
    $pdo = billing_pdo();
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'db: ' . $e->getMessage()]);
    exit;
}
billing_ensure_tables($pdo);

// ── 정리(이전 잔재) + 테스트 회원 생성 ──
try {
    $pdo->prepare("DELETE FROM topup_purchases WHERE owner_email=:e")->execute([':e' => $TEST]);
    $pdo->prepare("DELETE FROM members WHERE email=:e")->execute([':e' => $TEST]);
    $pdo->prepare("INSERT IGNORE INTO members (email) VALUES (:e)")->execute([':e' => $TEST]);
    $pdo->prepare("UPDATE members SET topup_balance_minutes=0, auto_topup_enabled=0 WHERE email=:e")->execute([':e' => $TEST]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'setup: ' . $e->getMessage()]);
    exit;
}

// ── 1. 차감 판정 로직 (순수 함수) ──
$ev1 = topup_evaluate_quota(700, 700, 5, 0, true);   // 한도소진 + 잔액0 + 동의 → topup_required
$add('① 한도소진+잔액0+동의 → 추가결제 안내(topup_required)', $ev1['decision'] === 'topup_required', $ev1);

$ev4 = topup_evaluate_quota(700, 700, 5, 0, false);  // 미동의 → blocked
$add('② 한도소진+미동의 → 차단(blocked)', $ev4['decision'] === 'blocked', $ev4);

$ev5 = topup_evaluate_quota(700, 100, 5, 0, true);   // 한도 남음 → limit
$add('③ 정기 한도 남음 → 정기한도 차감(limit)', $ev5['decision'] === 'allow' && $ev5['from'] === 'limit', $ev5);

// ── 2. 충전 적립 (5,000원 = 80분) ──
$cr1 = topup_credit($pdo, $TEST, 'selftest-token-AAA', 'GPA.TEST-AAA', topup_product_id(), topup_minutes_per_purchase());
$add('④ 충전 적립 → 잔액 80분', $cr1['credited'] === 80 && $cr1['balance_minutes'] === 80 && !$cr1['duplicate'], $cr1);

// ── 3. 멱등성 (같은 token 재호출 → 중복 적립 차단) ──
$cr2 = topup_credit($pdo, $TEST, 'selftest-token-AAA', 'GPA.TEST-AAA', topup_product_id(), topup_minutes_per_purchase());
$add('⑤ 같은 purchase_token 재검증 → 멱등(잔액 80 유지)', $cr2['duplicate'] === true && $cr2['balance_minutes'] === 80, $cr2);

// ── 4. 잔액 충분 → 충전분에서 차감 결정 ──
$bal = topup_get_balance($pdo, $TEST);
$ev2 = topup_evaluate_quota(700, 700, 5, $bal, true);
$add('⑥ 잔액 80분 → 5분 통화는 충전분에서 차감', $ev2['decision'] === 'allow' && $ev2['from'] === 'topup' && $ev2['consume_topup_minutes'] === 5, $ev2);

// ── 5. 실제 차감 반영 (80 → 75) ──
$pdo->prepare("UPDATE members SET topup_balance_minutes = topup_balance_minutes - 5 WHERE email=:e")->execute([':e' => $TEST]);
$balAfter = topup_get_balance($pdo, $TEST);
$add('⑦ 충전분 5분 차감 (80 → 75)', $balAfter === 75, ['balance_minutes' => $balAfter]);

// ── 6. 잔액 부족(75 < 80분 통화) + 동의 → 재충전 안내 ──
$ev3 = topup_evaluate_quota(700, 700, 80, $balAfter, true);
$add('⑧ 잔액 부족 → 추가결제 안내(topup_required)', $ev3['decision'] === 'topup_required', $ev3);

// ── 정리 ──
try {
    $pdo->prepare("DELETE FROM topup_purchases WHERE owner_email=:e")->execute([':e' => $TEST]);
    $pdo->prepare("DELETE FROM members WHERE email=:e")->execute([':e' => $TEST]);
} catch (Throwable $e) { /* 무시 */ }

$allPass = true;
foreach ($steps as $s) { if (!$s['pass']) $allPass = false; }
echo json_encode([
    'ok' => $allPass,
    'all_pass' => $allPass,
    'sample_message' => $ev1['message'] ?? null,
    'steps' => $steps,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
