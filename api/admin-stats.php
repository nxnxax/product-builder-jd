<?php
/**
 * admin-stats.php — 일별 통계 (installs / signups / payments). 관리자 전용.
 *
 *  GET /admin-stats.php?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
 *  header: Authorization: Bearer <admin token>
 *
 * 원칙(앱팀 spec — 오류 0 필수):
 *  - 시간대 = Asia/Seoul(KST) 자정 기준 일별. (서버 tz 미설정 환경 대비 명시 고정)
 *  - admin = nxnxax@gmail.com 화이트리스트(코드). 그 외 403.
 *  - 데이터 소스: install_log / members(가입) / payments(첫결제·status=paid) / subscriptions(활성).
 *  - 정합성 투명성: summary 외 raw + 메트릭별 에러 노출(한 쿼리 실패가 전체를 막지 않음).
 *
 * 주의: 이 사이트의 회원 테이블은 'members'(스펙의 users 아님). 첫결제=owner별 MIN(paid_at).
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function as_out(array $p, int $c = 200): void { http_response_code($c); echo json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') as_out(['ok' => false, 'error' => 'method_not_allowed'], 405);

/* ── admin 토큰 검증 ── */
function as_load_supabase_auth(): array {
    $cfgPath = __DIR__ . '/supabase_config.php';
    if (!is_file($cfgPath)) $cfgPath = dirname(__DIR__) . '/supabase_config.php';
    $auth = is_file($cfgPath) ? require $cfgPath : [];
    if (!is_array($auth)) $auth = [];
    foreach ([__DIR__, dirname(__DIR__)] as $dir) {
        $envPath = $dir . '/.env';
        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $m)) {
                    $k = strtoupper($m[1]); $v = trim($m[2], "\"' ");
                    if (empty($auth['supabase_url']) && ($k === 'SUPABASE_URL' || $k === 'VITE_SUPABASE_URL')) $auth['supabase_url'] = preg_replace('#/(rest|auth)/v1/?.*$#', '', $v);
                    if (empty($auth['anon_key']) && ($k === 'SUPABASE_ANON_KEY' || $k === 'VITE_SUPABASE_ANON_KEY')) $auth['anon_key'] = $v;
                }
            }
        }
    }
    return $auth;
}
function as_bearer(): string {
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach ((array)@apache_request_headers() as $k => $v) { if (strcasecmp((string)$k, 'authorization') === 0) { $h = (string)$v; break; } }
    }
    if ($h === '' && function_exists('getallheaders')) {
        foreach ((array)@getallheaders() as $k => $v) { if (strcasecmp((string)$k, 'authorization') === 0) { $h = (string)$v; break; } }
    }
    if ($h === '') $h = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return trim($h);
}

$ADMIN_EMAILS = ['nxnxax@gmail.com'];
$token = as_bearer();
if ($token === '') as_out(['ok' => false, 'error' => 'unauthorized', 'message' => '로그인이 필요합니다.'], 401);
$auth = as_load_supabase_auth();
$base = !empty($auth['supabase_url']) ? rtrim((string)$auth['supabase_url'], '/') : '';
$anon = (string)($auth['anon_key'] ?? '');
if ($base === '' || $anon === '') as_out(['ok' => false, 'error' => 'config', 'message' => '서버 인증 설정 누락.'], 500);
$ch = curl_init($base . '/auth/v1/user');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'apikey: ' . $anon], CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5]);
$resp = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($status !== 200 || !$resp) as_out(['ok' => false, 'error' => 'unauthorized', 'message' => '토큰 검증 실패. 다시 로그인하세요.'], 401);
$u = json_decode((string)$resp, true);
$email = strtolower(trim((string)($u['email'] ?? '')));
if ($email === '' || !in_array($email, $ADMIN_EMAILS, true)) as_out(['ok' => false, 'error' => 'forbidden', 'message' => '관리자만 접근할 수 있습니다.'], 403);

/* ── DB ── */
$dbConfig = null;
foreach ([__DIR__ . '/db_config.php', dirname(__DIR__) . '/db_config.php'] as $p) { if (is_file($p)) { $dbConfig = require $p; break; } }
if (!is_array($dbConfig)) as_out(['ok' => false, 'error' => 'db', 'message' => 'DB 설정 없음.'], 500);
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbConfig['host'] ?? 'localhost', $dbConfig['port'] ?? '3306', $dbConfig['database'] ?? ''),
        $dbConfig['user'] ?? '', $dbConfig['password'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    try { $pdo->exec("SET time_zone = '+09:00'"); } catch (Throwable $e) {}   // KST 세션
} catch (Throwable $e) { as_out(['ok' => false, 'error' => 'db', 'message' => 'DB 연결 실패.'], 500); }

/* ── 기간 파라미터 (KST, 자정 기준) ── */
$today = date('Y-m-d');
$validDate = function ($s) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s) && checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4)); };
$end   = $validDate($_GET['end_date'] ?? '')   ? (string)$_GET['end_date']   : $today;
$start = $validDate($_GET['start_date'] ?? '') ? (string)$_GET['start_date'] : date('Y-m-d', strtotime($end . ' -29 day'));
if (strtotime($start) > strtotime($end)) { $tmp = $start; $start = $end; $end = $tmp; }
if (strtotime($end) > strtotime($start . ' +366 day')) $end = date('Y-m-d', strtotime($start . ' +366 day'));  // span 상한
$rangeA = $start . ' 00:00:00';
$rangeB = $end . ' 23:59:59';

/* ── 일별 골격 ── */
$daily = [];
for ($cur = strtotime($start), $endTs = strtotime($end); $cur <= $endTs; $cur = strtotime('+1 day', $cur)) {
    $d = date('Y-m-d', $cur);
    $daily[$d] = ['date' => $d, 'installs' => 0, 'signups' => 0, 'payments_new' => 0, 'payments_active_total' => 0];
}
$errors = [];

/* installs — install_log */
try {
    $st = $pdo->prepare("SELECT DATE(pinged_at) d, COUNT(*) c FROM install_log WHERE pinged_at BETWEEN :a AND :b GROUP BY DATE(pinged_at)");
    $st->execute([':a' => $rangeA, ':b' => $rangeB]);
    foreach ($st as $r) { $d = (string)$r['d']; if (isset($daily[$d])) $daily[$d]['installs'] = (int)$r['c']; }
} catch (Throwable $e) { $errors['installs'] = $e->getMessage(); }   // 테이블 아직 없을 수 있음(첫 핑 전)

/* signups — members.created_at */
try {
    $st = $pdo->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM members WHERE created_at BETWEEN :a AND :b GROUP BY DATE(created_at)");
    $st->execute([':a' => $rangeA, ':b' => $rangeB]);
    foreach ($st as $r) { $d = (string)$r['d']; if (isset($daily[$d])) $daily[$d]['signups'] = (int)$r['c']; }
} catch (Throwable $e) { $errors['signups'] = $e->getMessage(); }

/* payments_new — owner별 첫 결제(MIN paid_at), status=paid */
try {
    $st = $pdo->prepare("SELECT DATE(fp) d, COUNT(*) c FROM (
            SELECT owner_email, MIN(paid_at) fp FROM payments
            WHERE LOWER(status) = 'paid' AND paid_at IS NOT NULL
            GROUP BY owner_email
        ) t WHERE fp BETWEEN :a AND :b GROUP BY DATE(fp)");
    $st->execute([':a' => $rangeA, ':b' => $rangeB]);
    foreach ($st as $r) { $d = (string)$r['d']; if (isset($daily[$d])) $daily[$d]['payments_new'] = (int)$r['c']; }
} catch (Throwable $e) { $errors['payments_new'] = $e->getMessage(); }

/* payments_active_total — 유료 구독 활성 구간(owner distinct) per day + current_active */
$currentActive = 0;
try {
    $st = $pdo->query("SELECT owner_email, current_period_start s, current_period_end e
                       FROM subscriptions
                       WHERE plan IN ('sales','master','agency') AND current_period_start IS NOT NULL");
    $subs = $st->fetchAll();
    $activeByDay = [];   // d => set(owner)
    $todayOwners = [];
    foreach ($subs as $sub) {
        $owner = strtolower((string)$sub['owner_email']);
        if ($owner === '') continue;
        $sD = substr((string)$sub['s'], 0, 10);
        $eD = !empty($sub['e']) ? substr((string)$sub['e'], 0, 10) : '9999-12-31';
        foreach ($daily as $d => $_) { if ($d >= $sD && $d <= $eD) $activeByDay[$d][$owner] = true; }
        if ($today >= $sD && $today <= $eD) $todayOwners[$owner] = true;
    }
    foreach ($daily as $d => &$row) { $row['payments_active_total'] = isset($activeByDay[$d]) ? count($activeByDay[$d]) : 0; }
    unset($row);
    $currentActive = count($todayOwners);
} catch (Throwable $e) { $errors['payments_active_total'] = $e->getMessage(); }

/* ── 응답 ── */
$dailyArr = array_values($daily);
$summary = [
    'total_installs'      => array_sum(array_column($dailyArr, 'installs')),
    'total_signups'       => array_sum(array_column($dailyArr, 'signups')),
    'total_payments_new'  => array_sum(array_column($dailyArr, 'payments_new')),
    'current_active'      => $currentActive,
];
as_out([
    'ok'      => true,
    'tz'      => 'Asia/Seoul',
    'range'   => ['start_date' => $start, 'end_date' => $end],
    'daily'   => $dailyArr,
    'summary' => $summary,
    'raw'     => [
        'note' => 'installs=install_log distinct device, signups=members.created_at, payments_new=owner별 첫 paid 결제일, payments_active_total/current_active=유료구독 활성구간 distinct owner. 모두 KST 자정 기준.',
        'errors' => $errors,   // 비어있으면 정상. 메트릭별 쿼리 실패 시 사유 표시.
    ],
]);
