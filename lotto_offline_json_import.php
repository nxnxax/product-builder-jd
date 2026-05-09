<?php
/**
 * lotto_offline_json_import.php
 *
 * 카페24 서버 IP 가 동행복권에 차단된 환경을 위한 오프라인 import 도구.
 * 서버는 dhlottery.co.kr 을 호출하지 않는다. 대신 같은 폴더의
 * lotto_numbers_all.json 을 읽어 lotto_winning_numbers 테이블에 적재.
 *
 * DB 자격증명은 lotto_db_config.php 에서 (.env / GitHub Secrets) 로 주입.
 *
 * 사용 흐름
 *  1) 새로운 회차가 추가된 lotto_numbers_all.json 을 FTP 로 같은 폴더에 업로드
 *     (또는 GitHub Actions 가 자동 배포)
 *  2) 브라우저로 https://youngman-biz.com/lotto_offline_json_import.php
 *  3) "DB 테이블 생성" → "JSON 파일 DB 저장" 한번씩 클릭
 *
 * JSON 데이터 출처 (참고)
 *  https://smok95.github.io/lotto/results/all.json
 */

declare(strict_types=1);

set_time_limit(300);
date_default_timezone_set('Asia/Seoul');

require_once __DIR__ . '/lotto_db_config.php';

$JSON_FILE = __DIR__ . '/lotto_numbers_all.json';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function create_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lotto_winning_numbers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            draw_no INT NOT NULL UNIQUE COMMENT '회차',
            draw_date DATE NULL COMMENT '추첨일',
            no1 TINYINT NOT NULL,
            no2 TINYINT NOT NULL,
            no3 TINYINT NOT NULL,
            no4 TINYINT NOT NULL,
            no5 TINYINT NOT NULL,
            no6 TINYINT NOT NULL,
            bonus_no TINYINT NOT NULL,
            first_winner_count INT DEFAULT 0 COMMENT '1등 당첨자 수',
            first_prize_amount BIGINT DEFAULT 0 COMMENT '1등 당첨금',
            total_sell_amount BIGINT DEFAULT 0 COMMENT '총 판매금액',
            raw_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_draw_no (draw_no),
            INDEX idx_draw_date (draw_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * 두 가지 JSON 형식을 지원:
 *  (a) smok95 형식: {draw_no, numbers:[6], bonus_no, date, divisions:[], total_sales_amount}
 *  (b) 동행복권 원본: {drwNo, drwtNo1..6, bnusNo, drwNoDate, firstPrzwnerCo, firstWinamnt, totSellamnt}
 */
function normalize_item(array $item): array {
    if (isset($item['draw_no'], $item['numbers']) && is_array($item['numbers'])) {
        $numbers = array_values($item['numbers']);
        if (count($numbers) < 6) {
            throw new RuntimeException('numbers 배열 길이가 6 미만입니다.');
        }

        $date = null;
        if (!empty($item['date'])) {
            $ts = strtotime((string)$item['date']);
            if ($ts) $date = date('Y-m-d', $ts);
        }

        // smok95 형식의 divisions 는 1등이 [0] 인 경우와 [1] 인 경우가 섞여있음.
        // 1회차는 1등 당첨자 0명이라 [0] 가 빈 객체이고 [1] 부터 시작. 안전하게
        // winners 가 가장 큰 prize 인 항목을 찾거나, [0] 이 비어있으면 [1] 사용.
        $firstWinnerCount = 0;
        $firstPrizeAmount = 0;
        if (!empty($item['divisions']) && is_array($item['divisions'])) {
            $first = $item['divisions'][0] ?? null;
            if (!is_array($first) || empty($first)) {
                $first = $item['divisions'][1] ?? null;
            }
            if (is_array($first)) {
                $firstWinnerCount = (int)($first['winners'] ?? 0);
                $firstPrizeAmount = (int)($first['prize']   ?? 0);
            }
        }

        return [
            'draw_no'            => (int)$item['draw_no'],
            'draw_date'          => $date,
            'no1'                => (int)$numbers[0],
            'no2'                => (int)$numbers[1],
            'no3'                => (int)$numbers[2],
            'no4'                => (int)$numbers[3],
            'no5'                => (int)$numbers[4],
            'no6'                => (int)$numbers[5],
            'bonus_no'           => (int)($item['bonus_no'] ?? 0),
            'first_winner_count' => $firstWinnerCount,
            'first_prize_amount' => $firstPrizeAmount,
            'total_sell_amount'  => (int)($item['total_sales_amount'] ?? 0),
            'raw_json'           => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    if (isset($item['drwNo'], $item['drwtNo1'])) {
        return [
            'draw_no'            => (int)$item['drwNo'],
            'draw_date'          => $item['drwNoDate'] ?? null,
            'no1'                => (int)$item['drwtNo1'],
            'no2'                => (int)$item['drwtNo2'],
            'no3'                => (int)$item['drwtNo3'],
            'no4'                => (int)$item['drwtNo4'],
            'no5'                => (int)$item['drwtNo5'],
            'no6'                => (int)$item['drwtNo6'],
            'bonus_no'           => (int)$item['bnusNo'],
            'first_winner_count' => (int)($item['firstPrzwnerCo'] ?? 0),
            'first_prize_amount' => (int)($item['firstWinamnt']   ?? 0),
            'total_sell_amount'  => (int)($item['totSellamnt']    ?? 0),
            'raw_json'           => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
        ':draw_no'            => $row['draw_no'],
        ':draw_date'          => $row['draw_date'],
        ':no1'                => $row['no1'],
        ':no2'                => $row['no2'],
        ':no3'                => $row['no3'],
        ':no4'                => $row['no4'],
        ':no5'                => $row['no5'],
        ':no6'                => $row['no6'],
        ':bonus_no'           => $row['bonus_no'],
        ':first_winner_count' => $row['first_winner_count'],
        ':first_prize_amount' => $row['first_prize_amount'],
        ':total_sell_amount'  => $row['total_sell_amount'],
        ':raw_json'           => $row['raw_json'],
    ]);
}

function get_stats(PDO $pdo): array {
    $row = $pdo->query("
        SELECT COUNT(*) AS total_count,
               MIN(draw_no)    AS min_draw,
               MAX(draw_no)    AS max_draw,
               MAX(draw_date)  AS latest_date
          FROM lotto_winning_numbers
    ")->fetch();
    return $row ?: ['total_count' => 0, 'min_draw' => null, 'max_draw' => null, 'latest_date' => null];
}

function import_json(PDO $pdo, string $file): array {
    if (!is_file($file)) throw new RuntimeException('JSON 파일이 없습니다: ' . basename($file));

    $json = file_get_contents($file);
    if ($json === false || trim($json) === '') {
        throw new RuntimeException('JSON 파일을 읽을 수 없거나 비어있습니다.');
    }
    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException('JSON 파싱 실패.');
    if (isset($data['results']) && is_array($data['results'])) $data = $data['results'];

    $pdo->beginTransaction();
    try {
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
        return ['saved' => $saved, 'failed' => $failed, 'errors' => $errors];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* =========================
   요청 처리
========================= */
$result_message = '';
$error_message  = '';
$stats          = null;

try {
    $pdo = lotto_db();
    create_table($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'import_json') {
            $r = import_json($pdo, $JSON_FILE);
            $result_message = "DB 저장 완료: 저장/갱신 {$r['saved']}건, 실패 {$r['failed']}건";
            if ($r['errors']) $result_message .= "\n일부 오류:\n" . implode("\n", $r['errors']);
        } elseif ($action === 'create_table') {
            $result_message = 'DB 테이블 생성 완료';
        }
    }

    $stats = get_stats($pdo);
} catch (Throwable $e) {
    $error_message = $e->getMessage();
}

$file_exists = is_file($JSON_FILE);
$file_size   = $file_exists ? (int)filesize($JSON_FILE) : 0;
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>로또 오프라인 JSON DB 저장 · YOUNGMAN</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Pretendard,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#fbf7ef;color:#0e0d0c}
.wrap{max-width:880px;margin:0 auto;padding:40px 20px 80px}
.card{background:#fff;border:1px solid rgba(20,14,8,.09);border-radius:16px;padding:28px}
.badge{display:inline-flex;padding:5px 10px;border-radius:999px;background:rgba(200,54,44,.08);color:#c8362c;font-size:11px;font-weight:700;letter-spacing:.04em}
h1{margin:14px 0 6px;font-size:26px;letter-spacing:-.02em}
.desc{color:#4f4943;line-height:1.7;font-size:14px}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:22px 0}
.stat{border:1px solid rgba(20,14,8,.09);border-radius:10px;padding:13px;background:#fbf7ef}
.stat span{display:block;color:#8a847e;font-size:11px;margin-bottom:5px}
.stat strong{display:block;font-size:18px}
.actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:18px}
button{border:0;border-radius:8px;padding:12px;font-weight:600;cursor:pointer;font-size:13.5px;font-family:inherit}
.primary{background:#0e0d0c;color:#fff} .gray{background:#f4efe7;color:#0e0d0c}
.msg,.err,.warn{white-space:pre-line;margin-top:14px;padding:13px 14px;border-radius:10px;line-height:1.55;font-size:13px}
.msg{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.warn{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.code{margin-top:18px;padding:14px 16px;border-radius:10px;background:#fbf7ef;border:1px solid rgba(20,14,8,.09);font-size:12.5px;line-height:1.7;color:#4f4943;font-family:ui-monospace,Menlo,monospace}
.ok{color:#15803d;font-weight:700}
.bad{color:#b91c1c;font-weight:700}
@media(max-width:680px){.grid,.actions{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="badge">LOTTO OFFLINE IMPORTER</div>
        <h1>로또 1등 당첨번호 오프라인 적재</h1>
        <p class="desc">
            카페24 서버가 동행복권에 직접 호출하지 않습니다. 같은 폴더의
            <b>lotto_numbers_all.json</b> 파일을 읽어 <code>lotto_winning_numbers</code> 테이블에 저장합니다.
            JSON 은 GitHub Actions 가 매주 함께 배포하므로 신규 회차 추가 후 이 페이지의 버튼만 한번 누르면 됩니다.
        </p>

        <?php if ($stats): ?>
        <div class="grid">
            <div class="stat"><span>저장된 회차 수</span><strong><?= number_format((int)$stats['total_count']) ?>건</strong></div>
            <div class="stat"><span>시작 회차</span><strong><?= h($stats['min_draw'] ?: '-') ?></strong></div>
            <div class="stat"><span>최신 회차</span><strong><?= h($stats['max_draw'] ?: '-') ?></strong></div>
            <div class="stat"><span>최신 추첨일</span><strong><?= h($stats['latest_date'] ?: '-') ?></strong></div>
        </div>
        <?php endif; ?>

        <div class="warn">
            JSON 파일 상태:
            <?php if ($file_exists): ?>
                <span class="ok">존재함</span> · 크기 <?= number_format($file_size) ?> bytes
            <?php else: ?>
                <span class="bad">없음</span> — lotto_numbers_all.json 파일을 같은 폴더에 업로드하세요.
            <?php endif; ?>
        </div>

        <form method="post" class="actions">
            <button class="gray"    name="action" value="create_table" type="submit">DB 테이블 생성</button>
            <button class="primary" name="action" value="import_json"  type="submit">JSON 파일 DB 저장</button>
        </form>

        <?php if ($result_message): ?><div class="msg"><?= h($result_message) ?></div><?php endif; ?>
        <?php if ($error_message): ?><div class="err"><?= h($error_message) ?></div><?php endif; ?>

        <div class="code">
주간 갱신 흐름
  1) 매주 토요일 추첨 후 lotto_numbers_all.json 을 새 회차 포함 버전으로 갱신
     (smok95.github.io 갱신을 따라가거나 직접 jq 로 한 줄 추가)
  2) 카페24에 lotto_numbers_all.json 다시 업로드 (또는 git push → 자동 배포)
  3) 이 페이지에서 "JSON 파일 DB 저장" 한번 클릭

분석 / 추천:
  lotto2233.html 는 lotto_winning_numbers 테이블만 사용 — 동행복권 직접 호출 없음.
        </div>
    </div>
</div>
</body>
</html>
