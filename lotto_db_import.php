<?php
/**
 * lotto_db_import.php
 * 동행복권 1회차 ~ 최신 회차의 1등 당첨번호를 MySQL DB 에 저장하는 도구.
 *
 * 사용 흐름
 *  1) lotto_db_config.php 에 DB 정보를 한번만 입력
 *  2) 이 파일을 브라우저로 열어 "DB 테이블 생성" → "전체 당첨번호 가져오기" 한번 실행
 *  3) 매주 토요일 추첨 직후 "최신 회차만 업데이트" 버튼 한번 눌러주면 끝
 *     (또는 cron 으로 ?action=update_latest&token=... 호출)
 */

declare(strict_types=1);

set_time_limit(300);
date_default_timezone_set('Asia/Seoul');

require_once __DIR__ . '/lotto_db_config.php';

/* =========================
   기본 함수
========================= */
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function create_table(PDO $pdo): void {
    $sql = "
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);
}

function fetch_lotto(int $draw_no): ?array {
    $url = "https://www.dhlottery.co.kr/common.do?method=getLottoNumber&drwNo=" . $draw_no;

    if (!function_exists('curl_init')) return null;

    // 동행복권은 봇 의심 시 메인 HTML 페이지를 돌려보냄. 실 브라우저처럼 보이도록
    // Accept / Referer / Accept-Language / Sec-Fetch-* 헤더를 채운다.
    $cookieJar = sys_get_temp_dir() . '/dhlottery_jar_' . md5(__FILE__);
    $headers = [
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Accept-Language: ko-KR,ko;q=0.9,en;q=0.8',
        'Referer: https://dhlottery.co.kr/gameResult.do?method=byWin',
        'Origin: https://dhlottery.co.kr',
        'X-Requested-With: XMLHttpRequest',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-site',
    ];
    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';

    $do_call = function (string $u) use ($cookieJar, $headers, $ua) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIEJAR      => $cookieJar,
            CURLOPT_COOKIEFILE     => $cookieJar,
            CURLOPT_ENCODING       => '',  // gzip/deflate 자동 처리
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$body, $status];
    };

    // 첫 호출이 HTML 이면 메인 페이지에 한번 들러 세션 쿠키를 받고 재시도.
    [$body, $status] = $do_call($url);

    if ($status >= 200 && $status < 300 && is_string($body) && $body !== ''
        && stripos(ltrim($body), '<') !== 0) {
        $data = json_decode($body, true);
        if (is_array($data) && ($data['returnValue'] ?? '') === 'success') return $data;
    }

    // HTML 응답이면 한번 메인 페이지를 다녀와서 쿠키 워밍업
    $do_call('https://dhlottery.co.kr/');
    [$body, $status] = $do_call($url);

    if ($status < 200 || $status >= 300 || !is_string($body) || $body === '') return null;
    if (stripos(ltrim($body), '<') === 0) return null;

    $data = json_decode($body, true);
    if (!is_array($data)) return null;
    if (($data['returnValue'] ?? '') !== 'success') return null;
    return $data;
}

/**
 * 진단용: 외부 동행복권 API 가 실제로 접속 가능한지 확인.
 * UI 의 "연결 진단" 버튼이 호출.
 */
function diagnose_fetch(): array {
    $out = [
        'php_version'      => PHP_VERSION,
        'curl_available'   => function_exists('curl_init'),
        'allow_url_fopen'  => (string)ini_get('allow_url_fopen'),
        'openssl_loaded'   => extension_loaded('openssl'),
        'sample_call'      => null,
    ];
    if (!$out['curl_available']) return $out;

    $sample = fetch_lotto(1);
    $out['sample_call'] = [
        'success'   => $sample !== null,
        'parsed'    => $sample !== null ? [
            'drwNo'      => $sample['drwNo'] ?? null,
            'drwNoDate'  => $sample['drwNoDate'] ?? null,
            'numbers'    => [
                $sample['drwtNo1'] ?? null, $sample['drwtNo2'] ?? null, $sample['drwtNo3'] ?? null,
                $sample['drwtNo4'] ?? null, $sample['drwtNo5'] ?? null, $sample['drwtNo6'] ?? null,
            ],
        ] : null,
    ];
    return $out;
}

function find_latest_draw_no(): int {
    $low = 1;
    $high = 3000;
    while ($low <= $high) {
        $mid = intdiv($low + $high, 2);
        $data = fetch_lotto($mid);
        if ($data) $low = $mid + 1;
        else       $high = $mid - 1;
        usleep(80000);
    }
    return $high;
}

function save_lotto(PDO $pdo, array $data): void {
    $sql = "
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
        total_sell_amount = VALUES(total_sell_amount),
        raw_json = VALUES(raw_json),
        updated_at = NOW()
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':draw_no'            => (int)$data['drwNo'],
        ':draw_date'          => $data['drwNoDate'] ?? null,
        ':no1'                => (int)$data['drwtNo1'],
        ':no2'                => (int)$data['drwtNo2'],
        ':no3'                => (int)$data['drwtNo3'],
        ':no4'                => (int)$data['drwtNo4'],
        ':no5'                => (int)$data['drwtNo5'],
        ':no6'                => (int)$data['drwtNo6'],
        ':bonus_no'           => (int)$data['bnusNo'],
        ':first_winner_count' => (int)($data['firstPrzwnerCo'] ?? 0),
        ':first_prize_amount' => (int)($data['firstWinamnt']   ?? 0),
        ':total_sell_amount'  => (int)($data['totSellamnt']    ?? 0),
        ':raw_json'           => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function get_stats(PDO $pdo): array {
    $row = $pdo->query("
        SELECT COUNT(*) AS total_count,
               MIN(draw_no) AS min_draw,
               MAX(draw_no) AS max_draw,
               MAX(draw_date) AS latest_date
          FROM lotto_winning_numbers
    ")->fetch();
    return $row ?: ['total_count' => 0, 'min_draw' => null, 'max_draw' => null, 'latest_date' => null];
}

function update_latest(PDO $pdo): array {
    $latest  = find_latest_draw_no();
    $current = (int)$pdo->query("SELECT IFNULL(MAX(draw_no), 0) FROM lotto_winning_numbers")->fetchColumn();

    if ($latest < 1) {
        throw new RuntimeException('동행복권 API 에 접속할 수 없습니다. "연결 진단" 버튼으로 cURL/SSL 상태를 확인하세요.');
    }
    if ($latest <= $current) {
        return ['saved' => 0, 'latest' => $latest, 'current' => $current, 'message' => "이미 최신 상태입니다. 현재 DB 최신회차: {$current}회차"];
    }
    $saved = 0;
    for ($i = $current + 1; $i <= $latest; $i++) {
        $data = fetch_lotto($i);
        if ($data) { save_lotto($pdo, $data); $saved++; }
        usleep(90000);
    }
    return ['saved' => $saved, 'latest' => $latest, 'current' => $current, 'message' => "최신 업데이트 완료: {$saved}건 추가 저장 (최신 {$latest}회차)"];
}

/* =========================
   요청 처리
========================= */
$result_message = '';
$error_message  = '';
$stats          = null;

// 매주 자동 업데이트용 cron 엔드포인트:  ?action=update_latest&token=<env LOTTO_CRON_TOKEN>
$cronToken = getenv('LOTTO_CRON_TOKEN') ?: '';
$queryAction = $_GET['action'] ?? '';
if ($queryAction === 'update_latest' && $cronToken !== '' && hash_equals($cronToken, (string)($_GET['token'] ?? ''))) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = lotto_db();
        create_table($pdo);
        echo json_encode(['ok' => true] + update_latest($pdo), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$diag = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = lotto_db();
        create_table($pdo);

        $action = $_POST['action'] ?? '';

        if ($action === 'diagnose') {
            $diag = diagnose_fetch();
            $result_message = '연결 진단 완료 — 아래 결과 박스 참고';
        } elseif ($action === 'install_all') {
            $latest = find_latest_draw_no();
            if ($latest < 1) throw new Exception('최신 회차를 찾지 못했습니다.');
            $saved = 0; $failed = 0;
            for ($i = 1; $i <= $latest; $i++) {
                $data = fetch_lotto($i);
                if ($data) { save_lotto($pdo, $data); $saved++; } else { $failed++; }
                usleep(90000);
            }
            if ($latest < 1) throw new Exception('최신 회차를 찾지 못했습니다.');
            $result_message = "완료: 1회차부터 {$latest}회차까지 처리. 저장/갱신 {$saved}건, 실패 {$failed}건";
        } elseif ($action === 'update_latest') {
            $info = update_latest($pdo);
            $result_message = $info['message'];
        } elseif ($action === 'create_table') {
            $result_message = 'DB 테이블 생성 완료';
        }

        $stats = get_stats($pdo);
    } catch (Throwable $e) {
        $error_message = $e->getMessage();
    }
} else {
    try {
        $pdo = lotto_db();
        create_table($pdo);
        $stats = get_stats($pdo);
    } catch (Throwable $e) {
        $error_message = 'DB 연결 실패. lotto_db_config.php 의 DB 정보를 먼저 채워주세요. 상세: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>로또 1등 당첨번호 DB · YOUNGMAN</title>
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
.stat span{display:block;color:#8a847e;font-size:11px;margin-bottom:5px;letter-spacing:.02em}
.stat strong{display:block;font-size:18px;letter-spacing:-.01em}
.actions{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:18px}
button{border:0;border-radius:8px;padding:12px;font-weight:600;cursor:pointer;font-size:13.5px;font-family:inherit;letter-spacing:-.005em}
.primary{background:#0e0d0c;color:#fff}
.green{background:#c8362c;color:#fff}
.gray{background:#f4efe7;color:#0e0d0c}
.msg{margin-top:16px;padding:13px 14px;border-radius:10px;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;line-height:1.5;font-size:13.5px}
.err{margin-top:16px;padding:13px 14px;border-radius:10px;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;line-height:1.5;font-size:13.5px}
.warning{margin-top:16px;padding:12px 14px;border-radius:10px;background:#fffbeb;color:#92400e;border:1px solid #fde68a;line-height:1.55;font-size:12.5px}
.flow{margin-top:18px;padding:14px 16px;border-radius:10px;background:#fbf7ef;border:1px solid rgba(20,14,8,.09);font-size:13px;line-height:1.7;color:#4f4943}
.flow b{color:#0e0d0c}
@media(max-width:680px){.grid,.actions{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="badge">LOTTO DB IMPORTER</div>
        <h1>로또 1등 당첨번호 DB</h1>
        <p class="desc">동행복권 회차별 1등 당첨번호를 MySQL DB 에 저장합니다. 매주 토요일 추첨 직후 최신 회차만 업데이트하면 충분합니다. 저장된 데이터는 <code>lotto2233.html</code> 의 <b>패턴 분석</b> 추천 기능에서 사용합니다.</p>

        <?php if ($stats): ?>
        <div class="grid">
            <div class="stat"><span>저장된 회차 수</span><strong><?= number_format((int)$stats['total_count']) ?>건</strong></div>
            <div class="stat"><span>시작 회차</span><strong><?= h($stats['min_draw'] ?: '-') ?></strong></div>
            <div class="stat"><span>최신 회차</span><strong><?= h($stats['max_draw'] ?: '-') ?></strong></div>
            <div class="stat"><span>최신 추첨일</span><strong><?= h($stats['latest_date'] ?: '-') ?></strong></div>
        </div>
        <?php endif; ?>

        <form method="post" class="actions">
            <button class="gray"    name="action" value="create_table"  type="submit">DB 테이블 생성</button>
            <button class="primary" name="action" value="install_all"   type="submit">전체 당첨번호 가져오기</button>
            <button class="green"   name="action" value="update_latest" type="submit">최신 회차만 업데이트</button>
        </form>
        <form method="post" style="margin-top:8px;">
            <button class="gray" name="action" value="diagnose" type="submit" style="width:100%;">🔌 연결 진단 (외부 API 접속 가능 여부)</button>
        </form>

        <?php if ($result_message): ?><div class="msg"><?= h($result_message) ?></div><?php endif; ?>
        <?php if ($error_message): ?><div class="err"><?= h($error_message) ?></div><?php endif; ?>

        <?php if ($diag): ?>
        <div class="flow" style="font-family:ui-monospace,Menlo,monospace;white-space:pre-wrap;font-size:12px;">PHP            <?= h($diag['php_version']) ?>
cURL           <?= $diag['curl_available'] ? 'OK' : '없음' ?>
allow_url_fopen <?= h($diag['allow_url_fopen']) ?>
openssl        <?= $diag['openssl_loaded'] ? '로드됨' : '없음' ?>

<?php if ($diag['sample_call']): ?>샘플 호출 (1회차):
  성공 여부 : <?= $diag['sample_call']['success'] ? '✓ JSON 정상 수신' : '✗ JSON 미수신 (HTML 또는 에러)' ?>
<?php if ($diag['sample_call']['parsed']): ?>  drwNo    : <?= h($diag['sample_call']['parsed']['drwNo']) ?>
  drwNoDate: <?= h($diag['sample_call']['parsed']['drwNoDate']) ?>
  numbers  : <?= h(implode(', ', array_filter($diag['sample_call']['parsed']['numbers']))) ?>
<?php endif; ?>
<?php endif; ?></div>
        <?php endif; ?>

        <div class="flow">
            <b>주간 갱신 흐름</b><br>
            ① 매주 토요일 21:00 추첨이 끝나면 → ② 이 페이지 열고 <b>"최신 회차만 업데이트"</b> 한번 클릭 → ③ DB 에 새 회차 1등 번호가 기록됨<br><br>
            <b>cron 자동화</b> (선택): 환경변수 <code>LOTTO_CRON_TOKEN</code> 을 지정하면<br>
            <code>GET ?action=update_latest&amp;token=&lt;토큰&gt;</code> 로 호출 가능합니다.
        </div>

        <div class="warning">
            보안 주의: 이 파일은 관리자 도구입니다. DB 저장이 끝났다면 .htaccess 등으로 외부 접근을 막거나 파일명을 바꿔 두세요.
        </div>
    </div>
</div>
</body>
</html>
