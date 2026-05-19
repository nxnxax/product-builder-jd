<?php
/**
 * cron-process-jobs.php — recording_jobs queued/failed_retryable 작업을 background 처리.
 *
 * 호출 권한: X-Cron-Token: <RECORDING_WORKER_TOKEN> 헤더로 인증.
 * 사용자 access_token 불필요 — server secret 기반.
 *
 * 흐름:
 *   1. queued 또는 failed_retryable (retry < 3) 작업을 LIMIT N SELECT
 *   2. 각 job 마다 internal HTTP 로 process-recording.php?internal=1 호출
 *      (X-Internal-Worker-Token 헤더로 인증, user access_token 불필요)
 *   3. 응답 status 에 따라 jobs 상태 갱신
 *
 * Phase 2 Path B (ChatGPT 권장 아키텍처 — 2026-05-20):
 * AI 작업 lifecycle 을 사용자 access_token lifecycle 과 완전 분리.
 * 사용자가 앱 종료해도, 토큰 만료돼도, refresh 실패해도 job 은 반드시 끝남.
 *
 * GitHub Actions schedule (.github/workflows/process-jobs.yml) 매 5분 트리거.
 * 한 번에 LIMIT 5 job 처리. 각 job 평균 35초 → 5건 ~3분 (PHP set_time_limit 240초 안에서).
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

set_time_limit(240);
ignore_user_abort(true);

/* ========== .env 직접 파싱 (PHP 가 .env 자동 로드 안 함) ========== */
$envPath = __DIR__ . '/.env';
function load_env_local(string $key): string {
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

function cron_jerror(string $msg, int $http = 500): void {
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 인증 — X-Cron-Token 헤더 */
$expectedCronToken = load_env_local('RECORDING_WORKER_TOKEN');
if ($expectedCronToken === '') cron_jerror('RECORDING_WORKER_TOKEN 미설정.', 503);
$providedToken = trim((string)($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
if (!hash_equals($expectedCronToken, $providedToken)) cron_jerror('Unauthorized', 401);

/* DB 연결 */
$dbConfigCandidates = [
    __DIR__ . '/db_config.php',
    dirname(__DIR__) . '/db_config.php',
];
$dbConfig = null;
foreach ($dbConfigCandidates as $p) {
    if (is_file($p)) { $dbConfig = require $p; break; }
}
if (!is_array($dbConfig)) cron_jerror('db_config.php 찾을 수 없음.', 503);

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbConfig['host'] ?? 'localhost',
        $dbConfig['port'] ?? '3306',
        $dbConfig['database'] ?? '');
    $pdo = new PDO($dsn, $dbConfig['user'] ?? '', $dbConfig['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    cron_jerror('DB 연결 실패: ' . $e->getMessage(), 503);
}

/* 처리할 jobs SELECT — queued 우선 + failed_retryable (retry<3) 도 함께 */
$limit = (int)($_GET['limit'] ?? 5);
if ($limit < 1) $limit = 1;
if ($limit > 20) $limit = 20;

$jobs = [];
try {
    $sel = $pdo->prepare("SELECT id, owner_email, storage_path, client_request_id,
                                 audio_sha256, duration_sec, customer_name_hint, phone_number,
                                 recorded_at, retry_count, status
        FROM recording_jobs
        WHERE (status = 'queued')
           OR (status = 'failed_retryable' AND retry_count < 3 AND updated_at < (NOW() - INTERVAL 1 MINUTE))
        ORDER BY created_at ASC
        LIMIT :n");
    $sel->bindValue(':n', $limit, PDO::PARAM_INT);
    $sel->execute();
    $jobs = $sel->fetchAll();
} catch (Throwable $e) {
    cron_jerror('jobs SELECT 실패: ' . $e->getMessage(), 503);
}

/* internal HTTP 호출 endpoint URL — 같은 cafe24 host */
$host = $_SERVER['HTTP_HOST'] ?? 'youngman-biz.com';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';  // 항상 https 강제
$internalUrl = $scheme . '://' . $host . '/process-recording.php?internal=1';

$processed = 0;
$failed = 0;
$skipped = 0;
$details = [];

foreach ($jobs as $job) {
    $jobId = (string)$job['id'];
    $ownerEmail = (string)$job['owner_email'];
    $retryCount = (int)($job['retry_count'] ?? 0);

    /* status='processing' 잠금 (다른 worker race 방지) */
    $lock = $pdo->prepare("UPDATE recording_jobs
        SET status = 'processing', started_at = NOW(), updated_at = NOW()
        WHERE id = :id AND status IN ('queued', 'failed_retryable')");
    $lock->execute([':id' => $jobId]);
    if ($lock->rowCount() === 0) {
        $skipped++;
        $details[] = ['job_id' => $jobId, 'action' => 'skipped_already_processing'];
        continue;
    }

    /* internal HTTP 호출 — sync 모드 (응답까지 대기). 4분 timeout. */
    $payload = [
        'storage_path' => $job['storage_path'],
        'client_request_id' => $job['client_request_id'] ?: $jobId,
        'duration_sec' => (int)($job['duration_sec'] ?? 0),
        'customer_name_hint' => (string)($job['customer_name_hint'] ?? ''),
        'phone_number' => (string)($job['phone_number'] ?? ''),
        'recorded_at' => (string)($job['recorded_at'] ?? ''),
        'mode' => 'sync',  // cron worker 안에서는 sync 처리 (이미 background 환경)
        '_internal_job_id' => $jobId,
        '_internal_owner_email' => $ownerEmail,
    ];

    $ch = curl_init($internalUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Internal-Worker-Token: ' . $expectedCronToken,
        ],
        CURLOPT_TIMEOUT => 230,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $httpStatus < 200 || $httpStatus >= 300) {
        /* 실패 처리 — retry_count++ */
        $newRetry = $retryCount + 1;
        $newStatus = $newRetry < 3 ? 'failed_retryable' : 'failed_permanent';
        $errMsg = $curlErr !== '' ? $curlErr : substr((string)$resp, 0, 800);
        try {
            $pdo->prepare("UPDATE recording_jobs SET
                    status = :st, retry_count = :rc, error_message = :em, updated_at = NOW()
                WHERE id = :id")
                ->execute([':st' => $newStatus, ':rc' => $newRetry, ':em' => $errMsg, ':id' => $jobId]);
        } catch (Throwable $e) {}
        $failed++;
        $details[] = [
            'job_id' => $jobId,
            'action' => $newStatus,
            'http' => $httpStatus,
            'retry_count' => $newRetry,
            'error' => substr($errMsg, 0, 200),
        ];
        error_log('[cron-process-jobs] job failed ' . $jobId . ' http=' . $httpStatus . ' err=' . $errMsg);
        continue;
    }

    /* 성공 — process-recording 가 customer_log + recording_jobs.completed 까지 처리 */
    $processed++;
    $details[] = ['job_id' => $jobId, 'action' => 'completed'];
}

echo json_encode([
    'ok' => true,
    'processed' => $processed,
    'failed' => $failed,
    'skipped' => $skipped,
    'total' => count($jobs),
    'details' => $details,
], JSON_UNESCAPED_UNICODE);
