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
    // 앱팀 2026-05-20 요청 — error_code 표준화: ok/error_code/message/http_status 추가.
    if (!isset($extra['error_code'])) {
        if ($http === 409) $extra['error_code'] = 'JOB_DUPLICATE';
        elseif ($http >= 500) $extra['error_code'] = 'RETRYABLE_SERVER_ERROR';
        elseif ($http === 401) $extra['error_code'] = 'AUTH_INVALID';
        else $extra['error_code'] = strtoupper($code);
    }
    $payload = array_merge([
        'ok' => false,
        'error' => $code,
        'error_code' => $extra['error_code'],
        'message' => $msg,
        'http_status' => $http,
    ], $extra);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* JWT exp 클레임 추출 — AUTH_EXPIRED 와 AUTH_INVALID 구분용. */
function js_jwt_exp(string $token): ?int {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
    if (!$payload) return null;
    $data = json_decode($payload, true);
    if (!is_array($data) || !isset($data['exp'])) return null;
    return (int)$data['exp'];
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

/* Supabase 토큰으로 owner_email 확인 — error_code 표준 적용 */
function js_owner_email_from_auth(): string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['HTTP_X_AUTHORIZATION'] ?? '');
    if (!preg_match('/Bearer\s+(.+)/i', (string)$hdr, $m)) {
        js_jerror('unauthenticated', 'Authorization Bearer 헤더 누락.', 401, ['error_code' => 'AUTH_REQUIRED']);
    }
    $token = trim($m[1]);
    if ($token === '') js_jerror('unauthenticated', '빈 토큰.', 401, ['error_code' => 'AUTH_REQUIRED']);

    $supabaseUrlRaw = js_load_env('VITE_SUPABASE_URL');
    if ($supabaseUrlRaw === '') js_jerror('upstream_failed', 'Supabase URL 미설정.', 503, ['error_code' => 'RETRYABLE_SERVER_ERROR']);
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
        $errorCode = 'AUTH_INVALID';
        if ($status === 401) {
            $exp = js_jwt_exp($token);
            if ($exp !== null && $exp < time()) $errorCode = 'AUTH_EXPIRED';
        } elseif ($status === 0 || $status >= 500) {
            $errorCode = 'RETRYABLE_SERVER_ERROR';
        }
        $http = $errorCode === 'RETRYABLE_SERVER_ERROR' ? 503 : 401;
        js_jerror('unauthenticated', '토큰 검증 실패 (Supabase ' . $status . ').', $http, ['error_code' => $errorCode]);
    }
    $data = json_decode((string)$resp, true);
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if ($email === '') js_jerror('unauthenticated', '사용자 이메일 확인 불가.', 401, ['error_code' => 'AUTH_INVALID']);
    return $email;
}

/* status → 사용자 친화 label + progress 보정 + retryable 분기 */
function js_status_label(string $status, int $progressPct): array {
    $map = [
        'queued'             => ['대기 중...', max(5, $progressPct)],
        'uploaded'           => ['업로드 완료, 처리 대기 중...', max(10, $progressPct)],
        'uploading'          => ['파일 업로드 중...', max(15, $progressPct)],
        'processing'         => ['처리 중...', max(20, $progressPct)],  // 레거시
        'stt_processing'     => ['음성 텍스트 변환 중...', max(30, $progressPct)],
        'llm_processing'     => ['AI 요약 중...', max(70, $progressPct)],
        'ready_to_review'    => ['검토 대기 — 결과 준비됨', 100],
        'saved'              => ['저장 완료', 100],
        'completed'          => ['완료', 100],  // saved 와 동의어 (legacy auto-mode)
        'failed'             => ['처리 실패', 100],  // 레거시
        'failed_retryable'   => ['일시 실패 — 자동 재시도 중', 50],
        'failed_permanent'   => ['처리 실패 — 영맨 고객센터 문의', 100],
    ];
    return $map[$status] ?? [$status, $progressPct];
}

/* status → retryable boolean (앱팀 outbox 분기용). null = 끝났거나 진행 중. */
function js_retryable(string $status): ?bool {
    if ($status === 'failed_retryable') return true;
    if ($status === 'failed_permanent') return false;
    return null;   // 진행 중이거나 완료된 케이스 — retryable 개념 N/A.
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
                                 retry_count, started_at, completed_at, error_message, updated_at, created_at,
                                 review_required
        FROM recording_jobs
        WHERE id = :id AND owner_email = :o LIMIT 1");
    $sel->execute([':id' => $jobId, ':o' => $ownerEmail]);
    $job = $sel->fetch();
} catch (Throwable $e) {
    js_jerror('upstream_failed', '조회 실패.', 503, ['error_code' => 'RETRYABLE_SERVER_ERROR']);
}

if (!$job) js_jerror('not_found', '해당 job 을 찾을 수 없습니다.', 404, ['error_code' => 'NOT_FOUND']);

$status = (string)$job['status'];
$progressPct = (int)($job['progress_pct'] ?? 0);
[$stepLabel, $progressPct] = js_status_label($status, $progressPct);
$retryable = js_retryable($status);

// failure 시 error_code 매핑 (앱팀 outbox 분기용)
$failureErrorCode = null;
if ($status === 'failed_retryable') $failureErrorCode = 'RETRYABLE_SERVER_ERROR';
elseif ($status === 'failed_permanent') $failureErrorCode = 'JOB_FAILED_PERMANENT';
elseif ($status === 'failed') $failureErrorCode = 'JOB_FAILED_PERMANENT';   // 레거시 매핑

// 결과 URL — saved/completed/ready_to_review 일 때 records.php 의 row 또는 job 자체.
$resultUrl = null;
if ($job['customer_log_id']) {
    $resultUrl = '/records.php?resource=customer-log&id=' . urlencode((string)$job['customer_log_id']);
} elseif ($status === 'ready_to_review') {
    // customer_log 아직 없음 — 검토 대기. 앱이 confirm 액션 호출 시 INSERT 됨.
    $resultUrl = '/records.php?resource=customer-log&action=preview&job_id=' . urlencode((string)$job['id']);
}

// audio_kept — 영맨은 처리 후 즉시 삭제 (24h 보존 모드 미구현). 추후 컬럼 추가 시 분기.
$audioKept = false;

echo json_encode([
    'ok' => true,
    'job' => [
        'id' => (string)$job['id'],
        'status' => $status,
        'step_label' => $stepLabel,
        'progress_pct' => $progressPct,
        'retryable' => $retryable,
        'audio_kept' => $audioKept,
        'result_url' => $resultUrl,
        'error_code' => $failureErrorCode,
        'customer_log_id' => $job['customer_log_id'] ?: null,
        'duration_sec' => (int)($job['duration_sec'] ?? 0),
        'retry_count' => (int)($job['retry_count'] ?? 0),
        'review_required' => !empty($job['review_required']),
        'started_at' => $job['started_at'] ?: null,
        'completed_at' => $job['completed_at'] ?: null,
        'error_message' => $job['error_message'] ?: null,
        'updated_at' => $job['updated_at'] ?: null,
        'created_at' => $job['created_at'] ?: null,
    ],
], JSON_UNESCAPED_UNICODE);
