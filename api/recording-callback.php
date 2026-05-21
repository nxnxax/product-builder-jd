<?php
/**
 * recording-callback.php — Railway worker 의 처리 결과 수신 endpoint.
 *
 * 호출자: Railway worker (worker/main.py)
 * 인증: X-Worker-Token: <RECORDING_WORKER_TOKEN>
 *
 * 흐름:
 *   1. token 검증 (cron-process-jobs 와 동일 secret)
 *   2. body 의 transcript / summary 등 받기
 *   3. customer_log INSERT (recording_jobs 의 owner_email 기준)
 *   4. recording_jobs status='completed' UPDATE
 *   5. FCM call_summary_ready 발송
 *   6. (group_id 있으면 앱이 FCM 받은 후 sendCustomerLogToGroup 호출 — 영맨은 매핑 안 함)
 *
 * Railway worker 가 실패 시: status='failed_retryable' 또는 'failed_permanent' 로 전달
 *   → recording_jobs.error_message 저장. cron worker (Path B) 가 다시 시도.
 *
 * Path B (cron-process-jobs.php) 와 같은 패턴 — 차이는 cron 은 cafe24 자체 처리,
 * 이건 외부 Railway 가 처리한 결과 받기.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

set_time_limit(60);
ignore_user_abort(true);

function rc_jerror(string $msg, int $http = 500): void {
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/* .env 직접 파싱 */
function rc_load_env(string $key): string {
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') rc_jerror('POST only', 405);

/* 인증 */
$expected = rc_load_env('RECORDING_WORKER_TOKEN');
if ($expected === '') rc_jerror('RECORDING_WORKER_TOKEN 미설정.', 503);
$provided = trim((string)($_SERVER['HTTP_X_WORKER_TOKEN'] ?? ''));
if (!hash_equals($expected, $provided)) rc_jerror('Unauthorized', 401);

/* body 파싱 */
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) rc_jerror('Invalid JSON body.', 400);

$jobId = trim((string)($body['job_id'] ?? ''));
$ownerEmail = strtolower(trim((string)($body['owner_email'] ?? '')));
if ($jobId === '' || $ownerEmail === '') rc_jerror('job_id / owner_email 누락.', 400);

$statusReq = (string)($body['status'] ?? 'completed');
$errorMessage = (string)($body['error_message'] ?? '');

/* DB 연결 + crypto helper */
$dbConfigCandidates = [__DIR__ . '/db_config.php', dirname(__DIR__) . '/db_config.php'];
$dbConfig = null;
foreach ($dbConfigCandidates as $p) {
    if (is_file($p)) { $dbConfig = require $p; break; }
}
if (!is_array($dbConfig)) rc_jerror('db_config.php 찾을 수 없음.', 503);

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
    rc_jerror('DB 연결 실패: ' . $e->getMessage(), 503);
}

require_once __DIR__ . '/crypto_helpers.php';

/* job 검증 — owner_email 일치 확인 (스푸핑 방지) */
try {
    $sel = $pdo->prepare("SELECT id, owner_email, customer_log_id, retry_count, group_id, recorded_at, phone_number, review_required, duration_sec
        FROM recording_jobs WHERE id = :id LIMIT 1");
    $sel->execute([':id' => $jobId]);
    $jobRow = $sel->fetch();
} catch (Throwable $e) {
    rc_jerror('job 조회 실패: ' . $e->getMessage(), 503);
}
if (!$jobRow) rc_jerror('job not found.', 404);
if (strtolower((string)$jobRow['owner_email']) !== $ownerEmail) {
    rc_jerror('owner_email mismatch.', 403);
}

/* 실패 케이스 처리 */
if ($statusReq !== 'completed') {
    $newRetry = (int)($jobRow['retry_count'] ?? 0) + 1;
    // 앱팀 2026-05-20 2차 요청 — max_retry = 2.
    $newStatus = ($statusReq === 'failed_permanent' || $newRetry >= 2)
        ? 'failed_permanent' : 'failed_retryable';
    try {
        $pdo->prepare("UPDATE recording_jobs SET
                status = :st, retry_count = :rc, error_message = :em, updated_at = NOW()
            WHERE id = :id")
            ->execute([':st' => $newStatus, ':rc' => $newRetry, ':em' => substr($errorMessage, 0, 1000), ':id' => $jobId]);
    } catch (Throwable $e) {}
    echo json_encode(['ok' => true, 'status' => $newStatus, 'retry_count' => $newRetry], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 성공 케이스 — customer_log INSERT */
$customerName    = trim((string)($body['customer_name'] ?? ''));
$summary         = trim((string)($body['summary'] ?? ''));
$interest        = trim((string)($body['interest'] ?? ''));
$inquiry         = trim((string)($body['inquiry'] ?? ''));
$budgetCondition = trim((string)($body['budget_condition'] ?? ''));
$nextAction      = trim((string)($body['next_action'] ?? ''));
$transcript      = trim((string)($body['transcript'] ?? ''));
$sttModel        = trim((string)($body['stt_model'] ?? 'unknown'));
$llmModel        = trim((string)($body['llm_model'] ?? 'unknown'));
$groupId         = trim((string)($body['group_id'] ?? ($jobRow['group_id'] ?? '')));
$phoneNumber     = trim((string)($body['phone_number'] ?? ($jobRow['phone_number'] ?? '')));
$consultAt       = (string)($jobRow['recorded_at'] ?? date('Y-m-d H:i:s'));

if ($summary === '') $summary = $transcript ?: '(요약 없음)';
if ($customerName === '') $customerName = '고객';

/* 사장님 2026-05-21 — 미확인요약 시스템 폐기로 review_required 분기 제거.
 * review_required 값 무관하게 항상 customer_log INSERT + ledger mirror 진행 (아래 흐름).
 * 데이터 누락 0. */

/* customer_log row ID 생성 */
function rc_uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
$customerLogId = rc_uuid_v4();

/* customer_phone_lookup 계산 (records.php 와 동일 규칙) */
function rc_phone_lookup(string $phone): ?string {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    if (!$clean) return null;
    return substr($clean, -8);  // 뒤 8자리 (지역번호 제외)
}
$phoneLookup = $phoneNumber !== '' ? rc_phone_lookup($phoneNumber) : null;

try {
    $ins = $pdo->prepare("INSERT INTO customer_log (
            id, owner_email, customer_phone_lookup,
            customer_name, phone_number,
            summary, interest, inquiry, budget_condition, next_action,
            transcript, consult_at, audio_storage_path, audio_kept,
            ai_model, ai_generated_at, source, client_request_id
        ) VALUES (
            :id, :o, :pl,
            :nm, :ph,
            :sum, :intr, :inq, :bg, :nx,
            :tr, :ca, :asp, 0,
            :am, NOW(), 'railway-worker', :cri
        )");
    $ins->execute([
        ':id'  => $customerLogId,
        ':o'   => $ownerEmail,
        ':pl'  => $phoneLookup,
        ':nm'  => $customerName !== '' ? youngman_encrypt($customerName) : null,
        ':ph'  => $phoneNumber !== '' ? youngman_encrypt($phoneNumber) : null,
        ':sum' => youngman_encrypt($summary),
        ':intr'=> $interest !== '' ? youngman_encrypt($interest) : null,
        ':inq' => $inquiry  !== '' ? youngman_encrypt($inquiry)  : null,
        ':bg'  => $budgetCondition !== '' ? youngman_encrypt($budgetCondition) : null,
        ':nx'  => $nextAction !== '' ? youngman_encrypt($nextAction) : null,
        ':tr'  => youngman_encrypt($transcript),
        ':ca'  => $consultAt,
        ':asp' => null,  // Railway 가 audio 다운로드 후 처리. 영맨 storage_path 는 cron worker 가 cleanup.
        ':am'  => $sttModel . '+' . $llmModel,
        ':cri' => $jobId,
    ]);
} catch (Throwable $e) {
    rc_jerror('customer_log INSERT 실패: ' . $e->getMessage(), 502);
}

/* recording_jobs 업데이트 — completed */
try {
    $pdo->prepare("UPDATE recording_jobs SET
            status = 'completed', progress_pct = 100, completed_at = NOW(),
            customer_log_id = :cl, updated_at = NOW()
        WHERE id = :id")
        ->execute([':cl' => $customerLogId, ':id' => $jobId]);
} catch (Throwable $e) {
    error_log('[recording-callback] recording_jobs UPDATE 실패: ' . $e->getMessage());
}

/* 자동 send_to_group mirror (사장님 2026-05-20) — 통화 후 모달의 "양식에 전송" AutoSubmit 경로.
 * 앱이 process-recording.php request body 에 group_id 보내면 → recording_jobs 저장 → Railway 처리 →
 * 여기서 customer_log INSERT 후 자동으로 records.php?action=send_to_group 호출하여 ledger_records 에 mirror.
 * group_id 명시 안 됐어도 default 그룹으로 자동 mirror (사장님 기존 default 그룹 흐름 보존). */
try {
    $sendUrl = rtrim((string)rc_load_env('CAFE24_BASE_URL') ?: 'https://youngman-biz.com', '/')
             . '/records.php?resource=customer-log';
    $sendPayload = [
        'action'      => 'customer_log_send_to_group',
        'id'          => $customerLogId,
        'owner_email' => $ownerEmail,
    ];
    if ($groupId !== '') $sendPayload['group_id'] = (int)$groupId;
    $sCh = curl_init($sendUrl);
    curl_setopt_array($sCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($sendPayload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Worker-Token: ' . $expected,
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $sResp = curl_exec($sCh);
    $sStat = (int)curl_getinfo($sCh, CURLINFO_HTTP_CODE);
    curl_close($sCh);
    error_log('[recording-callback] auto send_to_group job=' . $jobId . ' gid=' . $groupId . ' http=' . $sStat);
    if ($sResp !== false && $sStat >= 200 && $sStat < 300) {
        $sData = json_decode((string)$sResp, true);
        if (is_array($sData) && isset($sData['_send_debug'])) {
            error_log('[recording-callback] _send_debug: ' . json_encode($sData['_send_debug'], JSON_UNESCAPED_UNICODE));
        }
    }
} catch (Throwable $e) {
    error_log('[recording-callback] auto send_to_group 실패: ' . $e->getMessage());
}

/* FCM call_summary_ready 발송 */
try {
    require_once __DIR__ . '/fcm_helpers.php';
    $sumPreview = $summary;
    if (mb_strlen($sumPreview) > 60) $sumPreview = mb_substr($sumPreview, 0, 57) . '...';
    send_fcm_to_user($pdo, $ownerEmail, [
        'title' => '통화 요약 완료 — ' . $customerName,
        'body'  => $sumPreview,
        'data'  => [
            'type'            => 'call_summary_ready',
            'job_id'          => $jobId,
            'customer_log_id' => $customerLogId,
            'consult_at'      => $consultAt,
            'group_id'        => $groupId,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[recording-callback] FCM 발송 실패: ' . $e->getMessage());
}

echo json_encode([
    'ok' => true,
    'job_id' => $jobId,
    'customer_log_id' => $customerLogId,
    'status' => 'completed',
], JSON_UNESCAPED_UNICODE);
