<?php
/**
 * cron-keep-alive.php — FCM silent push 로 앱 process keep-alive.
 *
 * 목적 (앱팀 요청 2026-06-11):
 *   영맨 앱 process 가 OS deep sleep(Doze) 에 들어가면 첫 통화 RINGING 시
 *   CallScreeningService cold start 가 느려 통화 전 모달이 안 뜨는 케이스 발생.
 *   30분 주기 silent push(data only) 로 앱을 미리 깨워 cold start 회피.
 *
 * 호출 권한: X-Cron-Token: <RECORDING_WORKER_TOKEN> 헤더 (cron-process-jobs.php 와 동일 토큰 재사용).
 *
 * 흐름:
 *   1. user_fcm_tokens 전체 조회 (활동성 최적화: 최근 1시간 통화 있던 owner 는 이미 awake → skip)
 *   2. FCM HTTP v1 으로 data-only 메시지 발송 (notification 없음 = 사용자에게 시각/소리 표시 X)
 *      payload: { data: {type:"keep_alive", timestamp}, android: {priority:high, ttl:1800s} }
 *   3. INVALID_TOKEN 응답은 자동 정리 (stale 토큰 DELETE)
 *
 * GitHub Actions schedule (.github/workflows/keep-alive.yml) 매 30분 트리거.
 *
 * 부하: OAuth access_token 1회 발급 후 토큰별 HTTP v1 발송 (FCM v1 은 multicast 없음 → 토큰별).
 *       현재 규모(테스트~수십명)는 set_time_limit 안에서 충분. 대량(수천+) 시 ?limit 으로 분할.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

set_time_limit(240);
ignore_user_abort(true);

/* ========== .env 직접 파싱 (cron-process-jobs.php 와 동일 패턴) ========== */
function ka_load_env(string $key): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $f = __DIR__ . '/.env';
        if (is_file($f)) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos($line, '=') === false || $line[0] === '#') continue;
                [$k, $v] = explode('=', $line, 2);
                $cache[trim($k)] = trim($v);
            }
        }
    }
    return $cache[$key] ?? '';
}

function ka_jerror(string $msg, int $http = 500): void {
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 인증 — X-Cron-Token 헤더 (cron-process-jobs.php 와 같은 토큰) */
$expectedCronToken = ka_load_env('RECORDING_WORKER_TOKEN');
if ($expectedCronToken === '') ka_jerror('RECORDING_WORKER_TOKEN 미설정.', 503);
$providedToken = trim((string)($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
if (!hash_equals($expectedCronToken, $providedToken)) ka_jerror('Unauthorized', 401);

/* FCM 헬퍼 — service account 로드 / OAuth / 토큰별 발송 (PDO 불필요한 함수만 사용) */
$fcmHelper = __DIR__ . '/fcm_helpers.php';
if (!is_file($fcmHelper)) ka_jerror('fcm_helpers.php 없음.', 503);
require_once $fcmHelper;
if (!function_exists('fcm_get_access_token') || !function_exists('fcm_send_to_token')) {
    ka_jerror('fcm_helpers 함수 누락.', 503);
}

/* DB 연결 (db_config.php — cron-process-jobs.php 와 동일 후보 경로) */
$dbConfig = null;
foreach ([__DIR__ . '/db_config.php', dirname(__DIR__) . '/db_config.php'] as $p) {
    if (is_file($p)) { $dbConfig = require $p; break; }
}
if (!is_array($dbConfig)) ka_jerror('db_config.php 찾을 수 없음.', 503);

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbConfig['host'] ?? 'localhost',
        $dbConfig['port'] ?? '3306',
        $dbConfig['dbname'] ?? '');
    $pdo = new PDO($dsn, $dbConfig['user'] ?? '', $dbConfig['pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    ka_jerror('DB 연결 실패: ' . $e->getMessage(), 503);
}

/* FCM access token 1회 발급 (process 캐시 — 토큰별 재발급 안 함) */
$sa = function_exists('fcm_load_service_account') ? fcm_load_service_account() : null;
if (!$sa || empty($sa['project_id'])) ka_jerror('FCM service account 미설정.', 503);
$access = fcm_get_access_token();
if (!$access) ka_jerror('FCM access token 발급 실패.', 503);
$projectId = (string)$sa['project_id'];

/* 분할 처리 안전장치 (대량 시) — 기본 넉넉 */
$limit = (int)($_GET['limit'] ?? 2000);
if ($limit < 1) $limit = 2000;
if ($limit > 5000) $limit = 5000;

/* 활동성 최적화 — 최근 1시간 통화 있던 owner 는 이미 awake → skip.
   recording_jobs 테이블 없거나 컬럼 이슈 시 전체 발송으로 폴백. */
$tokens = [];
try {
    $sql = "SELECT t.id, t.token
            FROM user_fcm_tokens t
            WHERE NOT EXISTS (
                SELECT 1 FROM recording_jobs r
                WHERE r.owner_email = t.owner_email
                  AND r.recorded_at IS NOT NULL
                  AND r.recorded_at > (NOW() - INTERVAL 1 HOUR)
            )
            ORDER BY t.id ASC
            LIMIT " . $limit;
    $stmt = $pdo->query($sql);
    $tokens = $stmt->fetchAll();
} catch (Throwable $e) {
    // 폴백 — 활동성 최적화 없이 전체 발송 (recording_jobs 미존재 등).
    try {
        $stmt = $pdo->query("SELECT id, token FROM user_fcm_tokens ORDER BY id ASC LIMIT " . $limit);
        $tokens = $stmt->fetchAll();
    } catch (Throwable $e2) {
        ka_jerror('user_fcm_tokens 조회 실패: ' . $e2->getMessage(), 503);
    }
}

/* data-only silent push payload (notification 없음 → UI/소리 표시 X) */
$payload = [
    'data' => [
        'type'      => 'keep_alive',
        'timestamp' => (string)time(),
    ],
    'android' => [
        'priority' => 'high',   // Doze 우회
        'ttl'      => '1800s',  // 30분 — 다음 keep-alive 전까지만 유효
    ],
];

$sent = 0;
$failed = 0;
$invalid = [];
foreach ($tokens as $row) {
    $result = fcm_send_to_token($access, $projectId, (string)$row['token'], $payload);
    if ($result === 'OK') {
        $sent++;
    } elseif ($result === 'INVALID_TOKEN') {
        $invalid[] = (int)$row['id'];
        $failed++;
    } else {
        $failed++;
    }
}

/* stale 토큰 정리 */
$cleaned = 0;
if ($invalid) {
    try {
        $placeholders = implode(',', array_fill(0, count($invalid), '?'));
        $del = $pdo->prepare("DELETE FROM user_fcm_tokens WHERE id IN ($placeholders)");
        $del->execute($invalid);
        $cleaned = $del->rowCount();
    } catch (Throwable $e) {
        error_log('[keep-alive] stale token cleanup failed: ' . $e->getMessage());
    }
}

echo json_encode([
    'ok'      => true,
    'targets' => count($tokens),
    'sent'    => $sent,
    'failed'  => $failed,
    'cleaned' => $cleaned,
], JSON_UNESCAPED_UNICODE);
