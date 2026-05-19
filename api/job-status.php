<?php
/**
 * job-status.php — recording_jobs polling endpoint.
 *
 * 앱이 1~2초 간격으로 호출 → job 의 status / progress_pct / 결과 반환.
 * Authorization: Bearer <Supabase access_token> 필수.
 * 본인 owner_email 의 job 만 조회 가능 (PII 격리).
 *
 * 사용 예 (앱):
 *   GET /job-status.php?job_id=rec_xxxxx
 *
 * 응답:
 * {
 *   "ok": true,
 *   "job": {
 *     "id": "...",
 *     "status": "stt_processing",
 *     "step_label": "음성 텍스트 변환 중",
 *     "progress_pct": 30,
 *     "customer_log_id": null (completed 시 채워짐),
 *     "duration_sec": 360,
 *     "retry_count": 0,
 *     "started_at": "2026-05-20T01:47:07+09:00",
 *     "completed_at": null,
 *     "error_message": null,
 *     "updated_at": "..."
 *   }
 * }
 *
 * Phase 2 속도 개선 (2026-05-20 ChatGPT 권장): "2~3분 무반응 → 1~2초 안에 처리중 표시" 핵심.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// process-recording.php 의 헬퍼 (load_env_value / require_auth_email / load_supabase_auth 등) 재사용 위해 함수 정의만 로드.
// 하지만 require_once 하면 진입 코드까지 실행되므로 필요한 함수만 inline 복제.

function js_jerror(string $code, string $msg, int $http = 400, array $extra = []): void {
    http_response_code($http);
    echo json_encode(array_merge(['ok' => false, 'error' => $code, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/* .env 직접 파싱 — cafe24 PHP 가 자동 로드 안 함 */
function js_load_env(string $key): string {
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

/* Supabase 토큰으로 owner_email 확인 */
function js_owner_email_from_auth(): string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['HTTP_X_AUTHORIZATION'] ?? '');
    if (!preg_match('/Bearer\s+(.+)/i', (string)$hdr, $m)) {
        js_jerror('unauthenticated', 'Authorization Bearer 헤더 누락.', 401);
    }
    $token = trim($m[1]);
    if ($token === '') js_jerror('unauthenticated', '빈 토큰.', 401);

    $supabaseUrlRaw = js_load_env('VITE_SUPABASE_URL');
    if ($supabaseUrlRaw === '') js_jerror('upstream_failed', 'Supabase URL 미설정.', 503);
    $rootUrl = preg_replace('#/(rest|auth)/v1/?.*$#', '', rtrim($supabaseUrlRaw, '/'));

    $ch = curl_init($rootUrl . '/auth/v1/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'apikey: ' . js_load_env('VITE_SUPABASE_ANON_KEY'),
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $status < 200 || $status >= 300) {
        js_jerror('unauthenticated', '토큰 검증 실패 (Supabase ' . $status . ').', 401);
    }
    $data = json_decode((string)$resp, true);
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if ($email === '') js_jerror('unauthenticated', '사용자 이메일 확인 불가.', 401);
    return $email;
}

/* status → 사용자 친화 label + progress 보정 */
function js_status_label(string $status, int $progressPct): array {
    $map = [
        'queued'             => ['대기 중...', max(5, $progressPct)],
        'uploading'          => ['파일 업로드 중...', max(15, $progressPct)],
        'processing'         => ['처리 중...', max(20, $progressPct)],  // 레거시
        'stt_processing'    => ['음성 텍스트 변환 중...', max(30, $progressPct)],
        'llm_processing'    => ['AI 요약 중...', max(70, $progressPct)],
        'completed'          => ['완료', 100],
        'failed'             => ['처리 실패', 100],  // 레거시
        'failed_retryable'  => ['일시 실패 — 자동 재시도 중', 50],
        'failed_permanent'  => ['처리 실패 — 영맨 고객센터 문의', 100],
    ];
    return $map[$status] ?? [$status, $progressPct];
}

/* ========== 진입 ========== */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') js_jerror('method_not_allowed', 'GET only', 405);

$jobId = trim((string)($_GET['job_id'] ?? ''));
if ($jobId === '') js_jerror('invalid_request', 'job_id 누락.', 400);
if (strlen($jobId) > 64) js_jerror('invalid_request', 'job_id 너무 김.', 400);

$ownerEmail = js_owner_email_from_auth();

/* DB 연결 */
$dbConfigCandidates = [__DIR__ . '/db_config.php', dirname(__DIR__) . '/db_config.php'];
$dbConfig = null;
foreach ($dbConfigCandidates as $p) {
    if (is_file($p)) { $dbConfig = require $p; break; }
}
if (!is_array($dbConfig)) js_jerror('upstream_failed', 'DB 설정 파일 없음.', 503);

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
    js_jerror('upstream_failed', 'DB 연결 실패: ' . $e->getMessage(), 503);
}

/* job 조회 — owner_email 격리 (PII 안전) */
try {
    $sel = $pdo->prepare("SELECT id, status, progress_pct, customer_log_id, duration_sec,
                                 retry_count, started_at, completed_at, error_message, updated_at, created_at
        FROM recording_jobs
        WHERE id = :id AND owner_email = :o LIMIT 1");
    $sel->execute([':id' => $jobId, ':o' => $ownerEmail]);
    $job = $sel->fetch();
} catch (Throwable $e) {
    js_jerror('upstream_failed', '조회 실패.', 503);
}

if (!$job) js_jerror('not_found', '해당 job 을 찾을 수 없습니다.', 404);

$status = (string)$job['status'];
$progressPct = (int)($job['progress_pct'] ?? 0);
[$stepLabel, $progressPct] = js_status_label($status, $progressPct);

echo json_encode([
    'ok' => true,
    'job' => [
        'id' => (string)$job['id'],
        'status' => $status,
        'step_label' => $stepLabel,
        'progress_pct' => $progressPct,
        'customer_log_id' => $job['customer_log_id'] ?: null,
        'duration_sec' => (int)($job['duration_sec'] ?? 0),
        'retry_count' => (int)($job['retry_count'] ?? 0),
        'started_at' => $job['started_at'] ?: null,
        'completed_at' => $job['completed_at'] ?: null,
        'error_message' => $job['error_message'] ?: null,
        'updated_at' => $job['updated_at'] ?: null,
        'created_at' => $job['created_at'] ?: null,
    ],
], JSON_UNESCAPED_UNICODE);
