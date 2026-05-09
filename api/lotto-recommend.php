<?php
/**
 * api/lotto-recommend.php
 *
 * lotto_winning_numbers DB 의 1등 당첨 패턴을 분석해서 이번 회차 추천번호를 만들어준다.
 *
 * 요청 (POST JSON):
 *  {
 *    "mode": "pattern" | "combined",
 *    "games": 5,                     // 1~10 사이
 *    "saju": {                       // mode=combined 일 때만 의미 있음
 *       "lucky":  "water" | "wood" | "fire" | "earth" | "metal",
 *       "yongshin": "...",
 *       "seed": "이름+생년월일+추첨일 합친 임의 문자열"
 *    }
 *  }
 *
 * 응답:
 *  {
 *    "ok": true,
 *    "based_on": { "draws": 1234, "latest_draw_no": 2233, "latest_date": "2026-05-03" },
 *    "games": [
 *      { "numbers": [3,11,18,24,33,41], "score": 92.4,
 *        "explain": { "frequency_rank": 0.81, "saju_bias": 0.34, "balance": 0.91 } },
 *      ...
 *    ]
 *  }
 *
 * 추천 원칙
 *  - 가중 빈도(최근 회차 가중치 ↑) + 출현 간격(오래 안나온 번호 약간 보정)
 *  - mode=combined 면 사용자 사주 보완 오행에 해당하는 번호 가중치 추가
 *  - 한쪽으로 몰리는 조합 (예: 1~6, 39~44) 방지: 5개 구간 중 최소 4개 구간 커버,
 *    연속된 번호 4개 이상 금지, 합 100~180, 홀수 2~4개
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../lotto_db_config.php';

function jout(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') jout(['ok' => false, 'error' => 'POST only'], 405);

$raw  = (string)file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) jout(['ok' => false, 'error' => 'JSON 형식 오류'], 400);

$mode  = (string)($body['mode']  ?? 'pattern');
$games = max(1, min(10, (int)($body['games'] ?? 5)));
$saju  = is_array($body['saju'] ?? null) ? $body['saju'] : [];
$seedStr = (string)($saju['seed'] ?? ($mode . '|' . microtime()));

// 오행 → 번호 매핑 (사주 보완 오행이 가장 큰 보정을 받음)
$elementNumbers = [
    'water' => [1,6,11,16,21,26,31,36,41],
    'wood'  => [3,8,13,18,23,28,33,38,43],
    'fire'  => [2,7,12,17,22,27,32,37,42],
    'metal' => [4,9,14,19,24,29,34,39,44],
    'earth' => [5,10,15,20,25,30,35,40,45],
];

// ---------- 1. DB 로드 ----------
try {
    $pdo = lotto_db();
    $rows = $pdo->query("
        SELECT draw_no, draw_date, no1, no2, no3, no4, no5, no6, bonus_no
          FROM lotto_winning_numbers
         ORDER BY draw_no ASC
    ")->fetchAll();
} catch (Throwable $e) {
    jout(['ok' => false, 'error' => 'DB 연결 실패. lotto_db_config.php 확인 필요. 상세: ' . $e->getMessage()], 500);
}

if (count($rows) < 50) {
    jout(['ok' => false, 'error' => 'DB 에 학습할 회차 데이터가 부족합니다. lotto_db_import.php 에서 전체 가져오기를 먼저 실행하세요. (현재 ' . count($rows) . '회차)'], 503);
}

$totalDraws  = count($rows);
$latestDraw  = (int)$rows[$totalDraws - 1]['draw_no'];
$latestDate  = (string)$rows[$totalDraws - 1]['draw_date'];

// ---------- 2. 가중 빈도 + 갭 분석 ----------
// 가중치: 최근 회차일수록 큼. 지수가중 (가장 최신=1.0, 가장 오래된≈0.30)
$freq = array_fill(1, 45, 0.0);     // 가중 빈도
$rawFreq = array_fill(1, 45, 0);    // 그냥 출현 횟수
$lastSeen = array_fill(1, 45, -1);  // 마지막 출현 회차 인덱스 (없으면 -1)

foreach ($rows as $idx => $r) {
    $w = 0.30 + 0.70 * ($idx / max(1, $totalDraws - 1));   // 0.30 → 1.00
    foreach (['no1','no2','no3','no4','no5','no6'] as $col) {
        $n = (int)$r[$col];
        $freq[$n]    += $w;
        $rawFreq[$n] += 1;
        $lastSeen[$n] = $idx;
    }
}

// gap = 마지막 출현 후 몇 회차 지났나 (오래 안 나오면 클수록)
$gap = [];
for ($n = 1; $n <= 45; $n++) {
    $gap[$n] = ($lastSeen[$n] < 0) ? $totalDraws : ($totalDraws - 1 - $lastSeen[$n]);
}

// ---------- 3. 페어 동시출현 ----------
$pair = [];
for ($a = 1; $a <= 45; $a++) {
    $pair[$a] = array_fill(1, 45, 0);
}
foreach ($rows as $r) {
    $ns = [(int)$r['no1'],(int)$r['no2'],(int)$r['no3'],(int)$r['no4'],(int)$r['no5'],(int)$r['no6']];
    foreach ($ns as $a) {
        foreach ($ns as $b) {
            if ($a !== $b) $pair[$a][$b]++;
        }
    }
}

// ---------- 4. 사주 보너스 ----------
// (a) 사용자 보완 오행에 해당하는 번호: 큰 가산
// (b) 과거 1등 추첨일의 일주 오행이 사용자 보완 오행과 같은 회차들 → "내 사주에 운이 좋았던 회차" → 그 회차 6번호에 추가 가산
$sajuBoost = array_fill(1, 45, 0.0);
$luckyEl = (string)($saju['lucky'] ?? '');
$elementSummary = [
    'lucky_element' => $luckyEl,
    'lucky_pool'    => $luckyEl !== '' ? ($elementNumbers[$luckyEl] ?? []) : [],
    'aligned_draws' => 0,
];

if ($mode === 'combined' && $luckyEl !== '' && isset($elementNumbers[$luckyEl])) {
    foreach ($elementNumbers[$luckyEl] as $n) {
        $sajuBoost[$n] += 8.0;   // 보완 오행 번호 직접 가산
    }

    // 일주 오행 매핑
    $stems    = ['갑','을','병','정','무','기','경','신','임','계'];
    $branches = ['자','축','인','묘','진','사','오','미','신','유','술','해'];
    $stemEl   = ['갑'=>'wood','을'=>'wood','병'=>'fire','정'=>'fire','무'=>'earth','기'=>'earth',
                 '경'=>'metal','신'=>'metal','임'=>'water','계'=>'water'];

    $aligned = 0;
    $base = strtotime('1900-01-31');
    foreach ($rows as $idx => $r) {
        $d = $r['draw_date'] ?? '';
        if (!$d) continue;
        $ts = strtotime($d);
        if (!$ts) continue;
        $diff = (int)floor(($ts - $base) / 86400);
        if ($diff < 0) continue;
        $stem = $stems[(($diff % 10) + 10) % 10];
        if (($stemEl[$stem] ?? '') !== $luckyEl) continue;

        $aligned++;
        // 그 회차의 번호들에 가산. 가장 최근 매칭 회차일수록 더 큰 가산.
        $w = 0.4 + 0.6 * ($idx / max(1, $totalDraws - 1));
        foreach (['no1','no2','no3','no4','no5','no6'] as $col) {
            $n = (int)$r[$col];
            $sajuBoost[$n] += 1.6 * $w;
        }
    }
    $elementSummary['aligned_draws'] = $aligned;
}

// ---------- 5. 최종 점수 ----------
$avgFreq = array_sum($freq) / 45;
$score = [];
for ($n = 1; $n <= 45; $n++) {
    // base: 가중 빈도(편차) + 갭 보정(오래 안 나온 번호에 작은 가산)
    $s  = ($freq[$n] - $avgFreq) * 1.0;
    $s += min(10, $gap[$n]) * 0.25;          // gap 10회 이상은 더 안 받음 (편향 방지)
    $s += $sajuBoost[$n];
    $score[$n] = $s;
}

// 점수를 0보다 큰 가중치로 변환
$minS = min($score);
$weights = [];
for ($n = 1; $n <= 45; $n++) {
    $weights[$n] = ($score[$n] - $minS) + 1.0;   // 항상 양수
}

// ---------- 6. 시드 기반 결정적 셔플 ----------
$seedNum = abs(crc32($seedStr . '|' . $mode . '|' . $latestDraw));
mt_srand($seedNum);

// ---------- 7. 조합 생성 ----------
// 한쪽 몰림 방지 제약:
//  - 5개 구간 [1-9],[10-18],[19-27],[28-36],[37-45] 중 최소 4개에서 1개 이상
//  - 연속된 정수 4개 이상 금지 (예: 1,2,3,4 동시 등장 금지)
//  - 합 100~180
//  - 홀수 2~4개
//  - 한 구간에서 4개 이상 뽑지 않기

function band(int $n): int {
    if ($n <= 9)  return 0;
    if ($n <= 18) return 1;
    if ($n <= 27) return 2;
    if ($n <= 36) return 3;
    return 4;
}

function has_long_run(array $nums, int $maxRun = 3): bool {
    sort($nums);
    $run = 1;
    for ($i = 1; $i < count($nums); $i++) {
        if ($nums[$i] === $nums[$i-1] + 1) {
            $run++;
            if ($run > $maxRun) return true;
        } else {
            $run = 1;
        }
    }
    return false;
}

function passes_balance(array $nums): bool {
    if (count($nums) !== 6) return false;
    $sum = array_sum($nums);
    if ($sum < 100 || $sum > 180) return false;

    $odd = 0;
    $bandCount = [0,0,0,0,0];
    foreach ($nums as $n) {
        if ($n % 2 === 1) $odd++;
        $bandCount[band($n)]++;
    }
    if ($odd < 2 || $odd > 4) return false;
    if (max($bandCount) >= 4) return false;
    $coveredBands = count(array_filter($bandCount, fn($c) => $c > 0));
    if ($coveredBands < 4) return false;
    if (has_long_run($nums, 3)) return false;
    return true;
}

function weighted_pick(array $weights, array $exclude): int {
    $sum = 0.0;
    $pool = [];
    foreach ($weights as $n => $w) {
        if (in_array($n, $exclude, true)) continue;
        $pool[$n] = $w;
        $sum += $w;
    }
    if ($sum <= 0) {
        $keys = array_keys($pool);
        return $keys[mt_rand(0, count($keys) - 1)];
    }
    $r = mt_rand() / mt_getrandmax() * $sum;
    $acc = 0.0;
    foreach ($pool as $n => $w) {
        $acc += $w;
        if ($r <= $acc) return $n;
    }
    return array_key_last($pool);
}

function build_one_set(array $weights): array {
    // 4구간 커버를 적극 만들기 위해 구간별로 한번씩 우선 뽑고 나머지를 채운다
    for ($attempt = 0; $attempt < 400; $attempt++) {
        $picked = [];
        $bandsOrder = [0,1,2,3,4];
        shuffle($bandsOrder);
        // 5개 구간 중 4개 구간 우선 보장
        $primary = array_slice($bandsOrder, 0, 4);
        foreach ($primary as $b) {
            $bw = [];
            foreach ($weights as $n => $w) {
                if (band((int)$n) === $b && !in_array($n, $picked, true)) $bw[$n] = $w;
            }
            if (!$bw) continue;
            $picked[] = weighted_pick($bw, $picked);
        }
        // 남은 2자리는 전체 가중치에서 뽑되 동일 구간 4개 누적 방지
        while (count($picked) < 6) {
            $candidate = weighted_pick($weights, $picked);
            $tentative = array_merge($picked, [$candidate]);
            $bandCount = [0,0,0,0,0];
            foreach ($tentative as $n) $bandCount[band($n)]++;
            if (max($bandCount) >= 4) {
                continue;   // 같은 구간 4개 누적이면 다시
            }
            $picked[] = $candidate;
        }
        sort($picked);
        if (passes_balance($picked)) return $picked;
    }
    // 실패 시 균형 조건 일부 완화한 fallback
    $picked = [];
    while (count($picked) < 6) {
        $picked[] = weighted_pick($weights, $picked);
    }
    sort($picked);
    return $picked;
}

// ---------- 8. games 개 생성 (서로 다른 조합 보장) ----------
$results = [];
$seenKeys = [];
$tries = 0;
while (count($results) < $games && $tries < 80) {
    $tries++;
    $set = build_one_set($weights);
    $key = implode('-', $set);
    if (isset($seenKeys[$key])) continue;
    $seenKeys[$key] = true;

    // 점수 산출 (UI 표시용)
    $rawSum = 0.0;
    foreach ($set as $n) $rawSum += $weights[$n];
    $maxPossible = 0.0;
    arsort($weights);
    $top6 = array_slice($weights, 0, 6, true);
    foreach ($top6 as $w) $maxPossible += $w;
    ksort($weights);
    $score100 = $maxPossible > 0 ? min(99.5, 80 + ($rawSum / $maxPossible) * 18) : 88.0;

    // 보조 설명: 사주 가산이 얼마나 들어갔는지
    $sajuShare = 0.0;
    foreach ($set as $n) $sajuShare += $sajuBoost[$n];

    $results[] = [
        'numbers' => $set,
        'score'   => round($score100, 1),
        'sum'     => array_sum($set),
        'odd'     => count(array_filter($set, fn($n) => $n % 2 === 1)),
        'bands'   => array_count_values(array_map('band', $set)),
        'saju_bonus_share' => round($sajuShare, 2),
    ];
}

// ---------- 9. 응답 ----------
$response = [
    'ok'       => true,
    'mode'     => $mode,
    'based_on' => [
        'draws'           => $totalDraws,
        'latest_draw_no'  => $latestDraw,
        'latest_date'     => $latestDate,
    ],
    'top_numbers' => array_slice(
        array_keys((function ($w) { arsort($w); return $w; })($weights)),
        0, 12
    ),
    'saju'  => $elementSummary,
    'games' => $results,
];

jout($response);
