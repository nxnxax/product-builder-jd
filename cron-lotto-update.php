<?php
/**
 * cron-lotto-update.php — 매주 자동 로또 회차 갱신 (GitHub Actions schedule).
 *
 * smok95 미러에서 최신 all.json 을 받아 lotto_winning_numbers 에 upsert + lotto_numbers_all.json 갱신.
 * 수동 버튼 페이지(lotto_offline_json_import.php)는 그대로 유지 — 이 파일은 그 자동화 버전.
 *
 * 인증: ?token= 값이 .env LOTTO_CRON_TOKEN 과 일치해야 함.
 * 트리거: .github/workflows/lotto-update.yml (매주 일요일 01:00 KST).
 *
 * ⚠ import 로직(create_table/normalize_item/save_lotto/get_stats)은 lotto_offline_json_import.php 와
 *   동일하게 복제됨. smok95 JSON 형식이 바뀌면 양쪽을 같이 수정할 것.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);
date_default_timezone_set('Asia/Seoul');
require_once __DIR__ . '/lotto_db_config.php';

const MIRROR_URL = 'https://smok95.github.io/lotto/results/all.json';
$JSON_FILE = __DIR__ . '/lotto_numbers_all.json';

function lcu_jout($p, int $code = 200): void {
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ── 토큰 인증 ── */
$expected = (string)lotto_env('LOTTO_CRON_TOKEN', '');
$given    = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if ($expected === '') lcu_jout(['ok' => false, 'error' => 'LOTTO_CRON_TOKEN 미설정(.env)'], 500);
if (!hash_equals($expected, $given)) lcu_jout(['ok' => false, 'error' => 'unauthorized'], 401);

/* ── import 로직 (lotto_offline_json_import.php 복제) ── */
function create_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lotto_winning_numbers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            draw_no INT NOT NULL UNIQUE COMMENT '회차',
            draw_date DATE NULL COMMENT '추첨일',
            no1 TINYINT NOT NULL, no2 TINYINT NOT NULL, no3 TINYINT NOT NULL,
            no4 TINYINT NOT NULL, no5 TINYINT NOT NULL, no6 TINYINT NOT NULL,
            bonus_no TINYINT NOT NULL,
            first_winner_count INT DEFAULT 0,
            first_prize_amount BIGINT DEFAULT 0,
            total_sell_amount BIGINT DEFAULT 0,
            raw_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_draw_no (draw_no), INDEX idx_draw_date (draw_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function normalize_item(array $item): array {
    if (isset($item['draw_no'], $item['numbers']) && is_array($item['numbers'])) {
        $numbers = array_values($item['numbers']);
        if (count($numbers) < 6) throw new RuntimeException('numbers 배열 길이가 6 미만입니다.');
        $date = null;
        if (!empty($item['date'])) { $ts = strtotime((string)$item['date']); if ($ts) $date = date('Y-m-d', $ts); }
        $firstWinnerCount = 0; $firstPrizeAmount = 0;
        if (!empty($item['divisions']) && is_array($item['divisions'])) {
            $first = $item['divisions'][0] ?? null;
            if (!is_array($first) || empty($first)) $first = $item['divisions'][1] ?? null;
            if (is_array($first)) { $firstWinnerCount = (int)($first['winners'] ?? 0); $firstPrizeAmount = (int)($first['prize'] ?? 0); }
        }
        return [
            'draw_no' => (int)$item['draw_no'], 'draw_date' => $date,
            'no1' => (int)$numbers[0], 'no2' => (int)$numbers[1], 'no3' => (int)$numbers[2],
            'no4' => (int)$numbers[3], 'no5' => (int)$numbers[4], 'no6' => (int)$numbers[5],
            'bonus_no' => (int)($item['bonus_no'] ?? 0),
            'first_winner_count' => $firstWinnerCount, 'first_prize_amount' => $firstPrizeAmount,
            'total_sell_amount' => (int)($item['total_sales_amount'] ?? 0),
            'raw_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
    if (isset($item['drwNo'], $item['drwtNo1'])) {
        return [
            'draw_no' => (int)$item['drwNo'], 'draw_date' => $item['drwNoDate'] ?? null,
            'no1' => (int)$item['drwtNo1'], 'no2' => (int)$item['drwtNo2'], 'no3' => (int)$item['drwtNo3'],
            'no4' => (int)$item['drwtNo4'], 'no5' => (int)$item['drwtNo5'], 'no6' => (int)$item['drwtNo6'],
            'bonus_no' => (int)$item['bnusNo'],
            'first_winner_count' => (int)($item['firstPrzwnerCo'] ?? 0),
            'first_prize_amount' => (int)($item['firstWinamnt'] ?? 0),
            'total_sell_amount' => (int)($item['totSellamnt'] ?? 0),
            'raw_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
    throw new RuntimeException('지원하지 않는 JSON 항목 형식입니다.');
}

function save_lotto(PDO $pdo, array $row): void {
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("
            INSERT INTO lotto_winning_numbers
                (draw_no, draw_date, no1, no2, no3, no4, no5, no6, bonus_no,
                 first_winner_count, first_prize_amount, total_sell_amount, raw_json)
            VALUES
                (:draw_no, :draw_date, :no1, :no2, :no3, :no4, :no5, :no6, :bonus_no,
                 :first_winner_count, :first_prize_amount, :total_sell_amount, :raw_json)
            ON DUPLICATE KEY UPDATE
                draw_date = VALUES(draw_date),
                no1 = VALUES(no1), no2 = VALUES(no2), no3 = VALUES(no3),
                no4 = VALUES(no4), no5 = VALUES(no5), no6 = VALUES(no6),
                bonus_no = VALUES(bonus_no),
                first_winner_count = VALUES(first_winner_count),
                first_prize_amount = VALUES(first_prize_amount),
                total_sell_amount  = VALUES(total_sell_amount),
                raw_json = VALUES(raw_json),
                updated_at = NOW()
        ");
    }
    $stmt->execute([
        ':draw_no' => $row['draw_no'], ':draw_date' => $row['draw_date'],
        ':no1' => $row['no1'], ':no2' => $row['no2'], ':no3' => $row['no3'],
        ':no4' => $row['no4'], ':no5' => $row['no5'], ':no6' => $row['no6'],
        ':bonus_no' => $row['bonus_no'],
        ':first_winner_count' => $row['first_winner_count'],
        ':first_prize_amount' => $row['first_prize_amount'],
        ':total_sell_amount' => $row['total_sell_amount'],
        ':raw_json' => $row['raw_json'],
    ]);
}

function get_stats(PDO $pdo): array {
    $row = $pdo->query("SELECT COUNT(*) AS total_count, MIN(draw_no) AS min_draw,
        MAX(draw_no) AS max_draw, MAX(draw_date) AS latest_date FROM lotto_winning_numbers")->fetch();
    return $row ?: ['total_count' => 0, 'min_draw' => null, 'max_draw' => null, 'latest_date' => null];
}

function lcu_fetch(string $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'youngman-lotto-cron/1.0',
    ]);
    $r = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($r !== false && $code >= 200 && $code < 300) ? $r : false;
}

/* ── 실행 ── */
try {
    $pdo = lotto_db();
    create_table($pdo);

    // 1) 미러에서 최신 JSON 받기 (cafe24 → github.io). 실패 시 로컬 파일 폴백.
    $source = 'mirror';
    $json = lcu_fetch(MIRROR_URL);
    if ($json === false || trim((string)$json) === '') {
        $source = 'local_fallback';
        $json = is_file($JSON_FILE) ? (string)file_get_contents($JSON_FILE) : '';
    } else {
        // 미러 성공 → 로컬 JSON 파일도 갱신(관리자 버튼 페이지 상태표시 동기화).
        @file_put_contents($JSON_FILE, $json);
    }
    if (trim((string)$json) === '') lcu_jout(['ok' => false, 'error' => '미러·로컬 모두 데이터 없음'], 502);

    $data = json_decode($json, true);
    if (!is_array($data)) lcu_jout(['ok' => false, 'error' => 'JSON 파싱 실패'], 502);
    if (isset($data['results']) && is_array($data['results'])) $data = $data['results'];

    $before = get_stats($pdo);

    $pdo->beginTransaction();
    $saved = 0; $failed = 0; $errors = [];
    foreach ($data as $idx => $item) {
        try {
            if (!is_array($item)) throw new RuntimeException('항목이 객체가 아닙니다.');
            save_lotto($pdo, normalize_item($item));
            $saved++;
        } catch (Throwable $e) {
            $failed++;
            if (count($errors) < 5) $errors[] = ($idx + 1) . '번째: ' . $e->getMessage();
        }
    }
    $pdo->commit();

    $after = get_stats($pdo);
    lcu_jout([
        'ok' => true,
        'source' => $source,                       // mirror | local_fallback
        'saved' => $saved, 'failed' => $failed,
        'max_draw_before' => $before['max_draw'],
        'max_draw_after'  => $after['max_draw'],
        'total' => $after['total_count'],
        'latest_date' => $after['latest_date'],
        'errors' => $errors,
    ]);
} catch (Throwable $e) {
    lcu_jout(['ok' => false, 'error' => $e->getMessage()], 500);
}
