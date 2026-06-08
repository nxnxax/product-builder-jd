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

// 서버 에러 진단 로거 (사장님 2026-06-08) — callback 실패는 무한로딩/양식전송 실패와 직결.
// 파일이 아직 배포 안 됐어도 핵심 흐름은 절대 안 깨지게 is_file 가드.
$__elog = __DIR__ . '/error_logger.php';
if (is_file($__elog)) require_once $__elog;
if (function_exists('ym_register_fatal_logger')) ym_register_fatal_logger('fatal.callback');

function rc_jerror(string $msg, int $http = 500): void {
    // 모든 callback 실패를 error_logs 에 기록 (정상 callback 은 이 함수를 안 탐).
    $pdo = $GLOBALS['__ym_pdo'] ?? null;
    if ($pdo instanceof PDO && function_exists('log_server_error')) {
        log_server_error($pdo, 'callback.fail', $msg, 'http=' . $http
            . ' job=' . ($GLOBALS['__ym_job_id'] ?? '?'));
    }
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/* .env 직접 파싱
 * 사장님 2026-05-23 — 따옴표 strip 추가. records.php 의 worker token 검증과
 * 동일 trim 패턴 사용해야 hash_equals 통과. 이전 버그: .env 에 ".." 따옴표
 * 있으면 callback 측은 quote 포함, records.php 측은 quote 제거 → mismatch
 * → send_to_group internal HTTP 401 → auto_confirm 흐름 항상 실패.
 *
 * 정규식 + quote/space trim 으로 robust parsing. webroot/.env + parent/.env 둘 다 탐색. */
function rc_load_env(string $key): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ([__DIR__, dirname(__DIR__)] as $dir) {
            $f = $dir . '/.env';
            if (!is_file($f)) continue;
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $m)) {
                    $k = $m[1];
                    $v = trim($m[2], "\"' \t\r\n");
                    if (!isset($cache[$k])) $cache[$k] = $v;
                }
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
$GLOBALS['__ym_job_id'] = $jobId;

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
$GLOBALS['__ym_pdo'] = $pdo;  // fatal handler / rc_jerror 진단 기록용

require_once __DIR__ . '/crypto_helpers.php';

/* job 검증 — owner_email 일치 확인 (스푸핑 방지) */
try {
    $sel = $pdo->prepare("SELECT id, owner_email, customer_log_id, retry_count, group_id, recorded_at, phone_number, review_required, duration_sec, client_request_id, auto_confirm
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

/* 사장님 2026-05-21 §7 — placeholder-first 안전망.
 * process-recording 이 placeholder customer_log INSERT 한 경우 callback 은 UPDATE only.
 * (Railway worker / cron retry 경유 시 안전. cafe24 자체 STT 는 같은 PHP process 가 직접 UPDATE.) */
if (!empty($jobRow['customer_log_id'])) {
    $customerNameCb    = trim((string)($body['customer_name'] ?? ''));
    $summaryCb         = trim((string)($body['summary'] ?? ''));
    $interestCb        = trim((string)($body['interest'] ?? ''));
    $inquiryCb         = trim((string)($body['inquiry'] ?? ''));
    $budgetConditionCb = trim((string)($body['budget_condition'] ?? ''));
    $nextActionCb      = trim((string)($body['next_action'] ?? ''));
    $regionCb          = trim((string)($body['region'] ?? ''));
    $transcriptCb      = trim((string)($body['transcript'] ?? ''));
    $sttModelCb        = trim((string)($body['stt_model'] ?? 'unknown'));
    $llmModelCb        = trim((string)($body['llm_model'] ?? 'unknown'));
    // 대체 provider 가 실패해 검증된 provider 로 넘어갔으면 ai_model 에 '*' 표기 (관리자 진단용).
    $fbCb              = (!empty($body['stt_fallback_used']) || !empty($body['llm_fallback_used'])) ? '*' : '';
    $phoneNumberCb     = trim((string)($body['phone_number'] ?? ($jobRow['phone_number'] ?? '')));

    if ($summaryCb === '') $summaryCb = $transcriptCb ?: '(요약 없음)';
    if ($customerNameCb === '') $customerNameCb = '고객';

    // 사장님 2026-05-23 — UPDATE 분기 모든 컬럼 COALESCE 보호.
    // 두 번째 callback (cron retry 등) 가 빈 transcript / summary 로 덮어쓰는 buggy 케이스 방지.
    // 첫 번째 callback 의 정상 데이터가 보존됨.
    try {
        $pdo->prepare("UPDATE customer_log SET
                customer_name = COALESCE(NULLIF(:nm, ''), customer_name),
                phone_number = COALESCE(NULLIF(:ph, ''), phone_number),
                summary = COALESCE(NULLIF(:sum, ''), summary),
                interest = COALESCE(NULLIF(:intr, ''), interest),
                inquiry = COALESCE(NULLIF(:inq, ''), inquiry),
                budget_condition = COALESCE(NULLIF(:bg, ''), budget_condition),
                next_action = COALESCE(NULLIF(:nx, ''), next_action),
                region = COALESCE(NULLIF(:rg, ''), region),
                transcript = COALESCE(NULLIF(:tr, ''), transcript),
                ai_model = :am,
                ai_generated_at = NOW(),
                source = 'app-auto-completed'
            WHERE id = :id AND owner_email = :o")
            ->execute([
                ':nm'  => $customerNameCb !== '' ? youngman_encrypt($customerNameCb) : '',
                ':ph'  => $phoneNumberCb !== '' ? youngman_encrypt($phoneNumberCb) : '',
                ':sum' => $summaryCb !== '' ? youngman_encrypt($summaryCb) : '',
                ':intr'=> $interestCb !== '' ? youngman_encrypt($interestCb) : '',
                ':inq' => $inquiryCb !== '' ? youngman_encrypt($inquiryCb) : '',
                ':bg'  => $budgetConditionCb !== '' ? youngman_encrypt($budgetConditionCb) : '',
                ':nx'  => $nextActionCb !== '' ? youngman_encrypt($nextActionCb) : '',
                ':rg'  => $regionCb !== '' ? youngman_encrypt($regionCb) : '',
                ':tr'  => $transcriptCb !== '' ? youngman_encrypt($transcriptCb) : '',
                ':am'  => $sttModelCb . '+' . $llmModelCb . $fbCb,
                ':id'  => $jobRow['customer_log_id'],
                ':o'   => $ownerEmail,
            ]);
    } catch (Throwable $e) {
        rc_jerror('customer_log UPDATE 실패: ' . $e->getMessage(), 502);
    }

    /* recording_jobs UPDATE — completed */
    try {
        $pdo->prepare("UPDATE recording_jobs SET
                status = 'completed', progress_pct = 100, completed_at = NOW(), updated_at = NOW()
            WHERE id = :id")
            ->execute([':id' => $jobId]);
    } catch (Throwable $e) {
        error_log('[recording-callback §7] recording_jobs UPDATE 실패: ' . $e->getMessage());
    }

    /* ledger_records refresh — internal HTTP to customer_log_send_to_group with refresh=true */
    try {
        $refUrl = rtrim((string)rc_load_env('CAFE24_BASE_URL') ?: 'https://youngman-biz.com', '/')
                . '/records.php?resource=customer-log';
        $refPayload = [
            'action'      => 'customer_log_send_to_group',
            'id'          => $jobRow['customer_log_id'],
            'owner_email' => $ownerEmail,
            'refresh'     => true,
        ];
        $rCh = curl_init($refUrl);
        curl_setopt_array($rCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($refPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Worker-Token: ' . $expected,
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_exec($rCh);
        $rStat = (int)curl_getinfo($rCh, CURLINFO_HTTP_CODE);
        curl_close($rCh);
        error_log('[recording-callback §7] ledger refresh cl=' . $jobRow['customer_log_id'] . ' http=' . $rStat);
    } catch (Throwable $e) {
        error_log('[recording-callback §7] ledger refresh 실패: ' . $e->getMessage());
    }

    /* FCM call_summary_ready */
    try {
        require_once __DIR__ . '/fcm_helpers.php';
        $sumPreview = $summaryCb;
        if (mb_strlen($sumPreview) > 60) $sumPreview = mb_substr($sumPreview, 0, 57) . '...';
        send_fcm_to_user($pdo, $ownerEmail, [
            'title' => '통화 요약 완료 — ' . $customerNameCb,
            'body'  => $sumPreview,
            'data'  => [
                'type'            => 'call_summary_ready',
                'job_id'          => $jobId,
                'customer_log_id' => $jobRow['customer_log_id'],
            ],
        ]);
    } catch (Throwable $e) {
        error_log('[recording-callback §7] FCM 발송 실패: ' . $e->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'job_id' => $jobId,
        'status' => 'completed',
        'customer_log_id' => $jobRow['customer_log_id'],
        'mode' => 'update_only',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* 성공 케이스 — customer_log INSERT (backward compat: customer_log_id 없는 경우) */
$customerName    = trim((string)($body['customer_name'] ?? ''));
$summary         = trim((string)($body['summary'] ?? ''));
$interest        = trim((string)($body['interest'] ?? ''));
$inquiry         = trim((string)($body['inquiry'] ?? ''));
$budgetCondition = trim((string)($body['budget_condition'] ?? ''));
$nextAction      = trim((string)($body['next_action'] ?? ''));
$region          = trim((string)($body['region'] ?? ''));
$transcript      = trim((string)($body['transcript'] ?? ''));
$sttModel        = trim((string)($body['stt_model'] ?? 'unknown'));
$llmModel        = trim((string)($body['llm_model'] ?? 'unknown'));
$fb              = (!empty($body['stt_fallback_used']) || !empty($body['llm_fallback_used'])) ? '*' : '';
$groupId         = trim((string)($body['group_id'] ?? ($jobRow['group_id'] ?? '')));
$phoneNumber     = trim((string)($body['phone_number'] ?? ($jobRow['phone_number'] ?? '')));
$consultAt       = (string)($jobRow['recorded_at'] ?? date('Y-m-d H:i:s'));

if ($summary === '') $summary = $transcript ?: '(요약 없음)';
if ($customerName === '') $customerName = '고객';

/* 사장님 2026-05-23 — lazy-STT 모드. callback 도착 시 summary_json_encrypted 저장.
 * auto_confirm=1 이면 (사용자가 "양식으로 전송" 누름) customer_log INSERT + send_to_group 자동 실행.
 * auto_confirm=0 이면 status='ready_to_review' 만 (사용자가 미확인 요약 페이지에서 confirm 수동). */

$summaryJsonObj = [
    'customer_name'    => $customerName,
    'summary'          => $summary,
    'interest'         => $interest,
    'inquiry'          => $inquiry,
    'budget_condition' => $budgetCondition,
    'next_action'      => $nextAction,
    'region'           => $region,
    'transcript'       => $transcript,
    'ai_model'         => $sttModel . '+' . $llmModel,
];
$summaryJsonEnc = youngman_encrypt(json_encode($summaryJsonObj, JSON_UNESCAPED_UNICODE));

$autoConfirm = !empty($jobRow['auto_confirm']);
$customerLogId = null;
$finalStatus = 'ready_to_review';

// 사장님 2026-05-23 — STT 결과가 비어있거나 너무 짧으면 auto_confirm 진행 안 함.
// audio_duration 대비 transcript 가 비정상적으로 짧으면 STT 부분 실패로 간주.
// 미확인 요약에 ready_to_review 로 남김 → 사장님이 검토 후 결정.
$durationSec = (int)($jobRow['duration_sec'] ?? 0);
$transcriptLen = mb_strlen($transcript);
$sttPartialFail = false;
if ($autoConfirm) {
    if ($transcriptLen === 0) {
        $sttPartialFail = true;
        $sttFailReason = 'STT 결과 비어있음 (transcript empty)';
    } elseif ($durationSec >= 20 && $transcriptLen < 10) {
        // 20초 이상 통화인데 transcript 10자 미만 → STT 거의 실패
        $sttPartialFail = true;
        $sttFailReason = sprintf('STT 결과 너무 짧음 (duration=%ds, transcript=%d chars)', $durationSec, $transcriptLen);
    }
    if ($sttPartialFail) {
        // 사장님 2026-06-01 — auto_confirm 강제 유지 (사용자 양식전송 의도 우선).
        // STT 결과가 비어있어도 customer_log INSERT 진행 → 고객관리대장에 빈 카드 표시 (사용자 수정 가능).
        // 옛 fallback (auto_confirm=false → ready_to_review) 폐기 — 양식전송이 미확인 요약으로 빠지던 원인.
        error_log('[recording-callback auto_confirm] STT partial fail 감지 (auto_confirm 강제 유지): ' . $sttFailReason);
        try {
            $pdo->prepare("UPDATE recording_jobs SET error_message = CONCAT(IFNULL(error_message, ''), ' [STT partial fail: ', :reason, ']') WHERE id = :id")
                ->execute([':reason' => $sttFailReason, ':id' => $jobId]);
        } catch (Throwable $e) {}
    }
}

if ($autoConfirm) {
    /* 자동 confirm — customer_log INSERT + recording_jobs UPDATE + send_to_group mirror */
    require_once __DIR__ . '/crypto_helpers.php';
    function rc_uuid_v4_auto(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    // 사장님 2026-05-24 — records.php 의 customer_phone_lookup_key 와 동일 로직.
    // 이전엔 마지막 8자리 숫자만 저장 → records.php 의 transcripts_by_phone 가
    // HMAC-SHA256 hex 로 WHERE 매칭하니까 0 row → "전문보기" 가 항상 "저장되어 있지 않습니다".
    // ("양식으로 전송" 흐름에서만 발생 — confirm 흐름은 records.php 가 INSERT 라 정상.)
    function rc_phone_lookup_auto(string $phone): ?string {
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '') return null;
        $key = function_exists('youngman_master_key') ? youngman_master_key() : null;
        return $key ? hash_hmac('sha256', $digits, $key) : hash('sha256', $digits);
    }
    $customerLogId = rc_uuid_v4_auto();
    $phoneLookup = $phoneNumber !== '' ? rc_phone_lookup_auto($phoneNumber) : null;
    $consultAt = (string)($jobRow['recorded_at'] ?? date('Y-m-d H:i:s'));
    $clientReqId = (string)($jobRow['client_request_id'] ?? $jobId);

    try {
        $pdo->prepare("INSERT INTO customer_log (
                id, owner_email, customer_phone_lookup,
                customer_name, phone_number,
                summary, interest, inquiry, budget_condition, next_action,
                region, transcript, consult_at, audio_storage_path, audio_kept,
                ai_model, ai_generated_at, source, client_request_id
            ) VALUES (
                :id, :o, :pl,
                :nm, :ph,
                :sum, :intr, :inq, :bg, :nx,
                :rg, :tr, :ca, :asp, 0,
                :am, NOW(), 'app-auto-confirm', :cri
            )")->execute([
                ':id'  => $customerLogId,
                ':o'   => $ownerEmail,
                ':pl'  => $phoneLookup,
                ':nm'  => $customerName !== '' ? youngman_encrypt($customerName) : null,
                ':ph'  => $phoneNumber !== '' ? youngman_encrypt($phoneNumber) : null,
                ':sum' => youngman_encrypt($summary),
                ':intr'=> $interest !== '' ? youngman_encrypt($interest) : null,
                ':inq' => $inquiry !== '' ? youngman_encrypt($inquiry) : null,
                ':bg'  => $budgetCondition !== '' ? youngman_encrypt($budgetCondition) : null,
                ':nx'  => $nextAction !== '' ? youngman_encrypt($nextAction) : null,
                ':rg'  => $region !== '' ? youngman_encrypt($region) : null,
                ':tr'  => youngman_encrypt($transcript),
                ':ca'  => $consultAt,
                ':asp' => null,
                ':am'  => $sttModel . '+' . $llmModel . $fb,
                ':cri' => $clientReqId,
            ]);
        $finalStatus = 'saved';
    } catch (Throwable $e) {
        // INSERT 실패 시 ready_to_review 로 fallback — 사용자가 미확인 요약에서 수동 confirm 가능.
        error_log('[recording-callback auto_confirm] customer_log INSERT 실패: ' . $e->getMessage());
        $customerLogId = null;
        $finalStatus = 'ready_to_review';
    }
}

try {
    $pdo->prepare("UPDATE recording_jobs SET
            status = :st, progress_pct = 100, completed_at = NOW(),
            summary_json_encrypted = :sj,
            customer_log_id = COALESCE(:cl, customer_log_id),
            phone_number = COALESCE(NULLIF(:ph, ''), phone_number),
            updated_at = NOW()
        WHERE id = :id")
        ->execute([
            ':st' => $finalStatus, ':sj' => $summaryJsonEnc,
            ':cl' => $customerLogId, ':ph' => $phoneNumber, ':id' => $jobId,
        ]);
} catch (Throwable $e) {
    rc_jerror('recording_jobs UPDATE 실패: ' . $e->getMessage(), 502);
}

/* auto_confirm=1 + customer_log INSERT 성공 → send_to_group mirror
 * 사장님 2026-05-23 — send_to_group 실패 시 사용자가 인지 못 하는 silent failure 방지.
 * customer_log INSERT 됐는데 mirror 안 됐으면 → 자동으로 미확인 요약 복원
 * (customer_log DELETE + recording_jobs status='ready_to_review' fallback). */
$mirrorFailed = false;
$mirrorDiag = null;
if ($autoConfirm && $customerLogId) {
    try {
        $sendUrl = rtrim((string)rc_load_env('CAFE24_BASE_URL') ?: 'https://youngman-biz.com', '/')
                 . '/records.php?resource=customer-log';
        $sendPayload = ['action' => 'customer_log_send_to_group', 'id' => $customerLogId, 'owner_email' => $ownerEmail];
        if ($groupId !== '') $sendPayload['group_id'] = (int)$groupId;
        $sCh = curl_init($sendUrl);
        curl_setopt_array($sCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($sendPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Worker-Token: ' . $expected],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $sResp = curl_exec($sCh);
        $sStat = (int)curl_getinfo($sCh, CURLINFO_HTTP_CODE);
        curl_close($sCh);
        // 응답 검증: HTTP 200 + JSON status='ok' 필수.
        $mirrorOk = ($sStat >= 200 && $sStat < 300);
        if ($mirrorOk && $sResp !== false) {
            $sData = json_decode((string)$sResp, true);
            if (!is_array($sData) || (($sData['status'] ?? '') !== 'ok' && empty($sData['merged']))) {
                $mirrorOk = false;
            }
        }
        $mirrorDiag = sprintf('http=%d resp_len=%d body=%s', $sStat,
            (is_string($sResp) ? strlen($sResp) : 0),
            substr((string)$sResp, 0, 150));
        if (!$mirrorOk) {
            $mirrorFailed = true;
            error_log('[recording-callback auto_confirm] send_to_group 실패: ' . $mirrorDiag);
        } else {
            error_log('[recording-callback auto_confirm] send_to_group OK: ' . $mirrorDiag);
        }
    } catch (Throwable $e) {
        $mirrorFailed = true;
        $mirrorDiag = 'exception: ' . $e->getMessage();
        error_log('[recording-callback auto_confirm] send_to_group 예외: ' . $e->getMessage());
    }

    // 사장님 2026-05-23 fallback — mirror 실패 시 미확인 요약 복원.
    if ($mirrorFailed) {
        try {
            $pdo->prepare("DELETE FROM customer_log WHERE id = :id AND owner_email = :o")
                ->execute([':id' => $customerLogId, ':o' => $ownerEmail]);
        } catch (Throwable $e) {
            error_log('[recording-callback auto_confirm fallback] customer_log DELETE 실패: ' . $e->getMessage());
        }
        try {
            $pdo->prepare("UPDATE recording_jobs SET status = 'ready_to_review', customer_log_id = NULL,
                    error_message = CONCAT(IFNULL(error_message, ''), ' [auto_confirm mirror 실패: ', :diag, ']'),
                    updated_at = NOW() WHERE id = :id")
                ->execute([':diag' => substr((string)$mirrorDiag, 0, 200), ':id' => $jobId]);
        } catch (Throwable $e) {
            error_log('[recording-callback auto_confirm fallback] recording_jobs UPDATE 실패: ' . $e->getMessage());
        }
        $customerLogId = null;
        $finalStatus = 'ready_to_review';
    }
}

/* FCM — auto_confirm 분기 + mirror 실패 시 안내 변경 */
try {
    require_once __DIR__ . '/fcm_helpers.php';
    $sumPreview = $summary;
    if (mb_strlen($sumPreview) > 60) $sumPreview = mb_substr($sumPreview, 0, 57) . '...';
    if ($autoConfirm && !$mirrorFailed) {
        $fcmTitle = '고객관리대장 저장 완료 — ' . $customerName;
    } elseif ($autoConfirm && $mirrorFailed) {
        $fcmTitle = '저장 실패 — 미확인 요약에 보관 (' . $customerName . ')';
    } else {
        $fcmTitle = '통화 요약 완료 — ' . $customerName;
    }
    send_fcm_to_user($pdo, $ownerEmail, [
        'title' => $fcmTitle,
        'body'  => $sumPreview,
        'data'  => [
            'type'            => 'call_summary_ready',
            'job_id'          => $jobId,
            'job_status'      => $finalStatus,
            'customer_log_id' => $customerLogId,
            'group_id'        => $groupId,
            'auto_confirmed'  => ($autoConfirm && !$mirrorFailed) ? '1' : '0',
            'mirror_failed'   => $mirrorFailed ? '1' : '0',
        ],
    ]);
} catch (Throwable $e) {
    error_log('[recording-callback] FCM 발송 실패: ' . $e->getMessage());
}

echo json_encode([
    'ok' => true,
    'job_id' => $jobId,
    'status' => $finalStatus,
    'customer_log_id' => $customerLogId,
    'auto_confirmed' => $autoConfirm && !$mirrorFailed,
    'mirror_failed' => $mirrorFailed,
    'mirror_diag' => $mirrorDiag,
    'mode' => 'lazy_stt',
], JSON_UNESCAPED_UNICODE);
