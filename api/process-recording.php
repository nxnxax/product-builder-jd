<?php
/**
 * process-recording.php — 통화 녹취 → Whisper STT → LLM 요약 → customer_log insert.
 *
 * Spec: CALL_RECORDING_BACKEND.md §5.
 *
 * 흐름:
 *   1. JWT 검증 → owner_email
 *   2. body 검증 (storage_path / client_request_id / recorded_at 등)
 *   3. Idempotency: 같은 (owner_email, client_request_id) 24h 내 → 기존 row 반환
 *   4. Plan check: plan='free' 이고 free_summaries_used >= FREE_QUOTA → 403 plan_required
 *   5. storage_path ownership 검증 (uploads/recordings/<userSeg>/...)
 *   6. Whisper STT (한국어)
 *   7. LLM 요약 (gpt-4o-mini, JSON 응답 강제)
 *   8. customer_log insert (모든 PII 컬럼 AES-256-GCM 암호화)
 *   9. members.free_summaries_used += 1 (plan=free 일 때만)
 *  10. 오디오 파일 unlink (audio_kept=false)
 *  11. row 재조회 → PII 복호화 → 응답
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Whisper + LLM 호출 합산 시간이 cafe24 기본 30s 를 넘을 수 있음.
@set_time_limit(240);   // ffmpeg transcode + Whisper + LLM 합산 여유.

/* ========== 응답/입력 헬퍼 ========== */
function jout(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function jerror(string $code, string $message, int $status, array $extra = []): void {
    jout(array_merge(['status' => 'error', 'code' => $code, 'message' => $message], $extra), $status);
}
function load_env_value(string $key): string {
    foreach ([__DIR__, dirname(__DIR__)] as $dir) {
        $path = $dir . '/.env';
        if (!is_file($path)) continue;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $m)) {
                if (strcasecmp($m[1], $key) === 0) return trim($m[2], "\"' \t\r\n");
            }
        }
    }
    return '';
}

/* ========== 암호화 헬퍼 — crypto_helpers.php 가 없으면 stub. ========== */
$__cryptoFile = __DIR__ . '/crypto_helpers.php';
if (is_file($__cryptoFile)) require_once $__cryptoFile;
if (!function_exists('youngman_encrypt')) {
    function youngman_encrypt($v) { return $v; }
    function youngman_decrypt($v) { return $v; }
    function youngman_master_key(): ?string { return null; }
}

/* ========== FCM 발송 헬퍼 — fcm_helpers.php 가 없으면 stub (async hook 무력화). ========== */
$__fcmFile = __DIR__ . '/fcm_helpers.php';
if (is_file($__fcmFile)) require_once $__fcmFile;
if (!function_exists('send_fcm_to_user')) {
    function send_fcm_to_user(PDO $pdo, string $ownerEmail, array $message): array {
        return ['sent' => 0, 'failed' => 0, 'invalid_tokens' => [], 'reason' => 'helper_missing'];
    }
}

/* ========== Supabase 인증 (upload.php 와 동일한 패턴) ========== */
function load_supabase_auth(): array {
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
                    if (empty($auth['supabase_url']) && ($k === 'SUPABASE_URL' || $k === 'VITE_SUPABASE_URL')) {
                        $auth['supabase_url'] = preg_replace('#/(rest|auth)/v1/?.*$#', '', $v);
                    }
                    if (empty($auth['anon_key']) && ($k === 'SUPABASE_ANON_KEY' || $k === 'VITE_SUPABASE_ANON_KEY')) {
                        $auth['anon_key'] = $v;
                    }
                }
            }
        }
        $jsPath = $dir . '/supabase_config.js';
        if (is_file($jsPath)) {
            $contents = (string)file_get_contents($jsPath);
            if (empty($auth['supabase_url']) && preg_match('/SUPABASE_URL\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $m)) {
                $auth['supabase_url'] = preg_replace('#/(rest|auth)/v1/?.*$#', '', $m[1]);
            }
            if (empty($auth['anon_key']) && preg_match('/SUPABASE_ANON_KEY\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $m)) {
                $auth['anon_key'] = $m[1];
            }
        }
    }
    return $auth;
}

function get_bearer_token(): string {
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('apache_request_headers')) {
        $req = @apache_request_headers();
        if (is_array($req)) {
            foreach ($req as $k => $v) {
                if (strcasecmp((string)$k, 'authorization') === 0) { $h = (string)$v; break; }
            }
        }
    }
    if ($h === '' && function_exists('getallheaders')) {
        $req = @getallheaders();
        if (is_array($req)) {
            foreach ($req as $k => $v) {
                if (strcasecmp((string)$k, 'authorization') === 0) { $h = (string)$v; break; }
            }
        }
    }
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return '';
}

function fetch_user_email_via_supabase(string $token, array $auth, ?array &$diagOut = null): string {
    $url = rtrim((string)($auth['supabase_url'] ?? ''), '/');
    $key = (string)($auth['anon_key'] ?? '');
    if ($diagOut !== null) {
        $diagOut = [
            'url_set'    => $url !== '',
            'key_set'    => $key !== '',
            'token_len'  => strlen($token),
            'auth_status' => 0,
        ];
    }
    if (!$url || !$key || !$token) return '';
    $ch = curl_init($url . '/auth/v1/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'apikey: ' . $key],
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($diagOut !== null) $diagOut['auth_status'] = $status;
    if ($status !== 200 || !$resp) return '';
    $data = json_decode((string)$resp, true);
    return strtolower(trim((string)($data['email'] ?? '')));
}

function require_auth_email(): string {
    $token = get_bearer_token();
    if (!$token) jerror('unauthorized', '로그인이 필요합니다. 헤더 Authorization Bearer 없음.', 401, [
        'debug' => ['stage' => 'no_bearer'],
    ]);
    $auth = load_supabase_auth();
    if (empty($auth['supabase_url']) || empty($auth['anon_key'])) {
        jerror('unauthorized', '서버 인증 설정 누락 (.env 의 Supabase URL/Anon Key).', 500, [
            'debug' => ['stage' => 'env_missing', 'url_set' => !empty($auth['supabase_url']), 'key_set' => !empty($auth['anon_key'])],
        ]);
    }
    $diag = null;
    $email = fetch_user_email_via_supabase($token, $auth, $diag);
    if (!$email) {
        $st = $diag['auth_status'] ?? 0;
        $hint = $st === 401 ? '세션이 만료되었습니다. 앱에서 다시 로그인하거나 새로고침 후 시도해주세요.'
              : ($st === 404 ? 'Supabase URL 설정 오류 (path 잘못).'
              : ($st === 0   ? 'Supabase 호출 네트워크 실패.'
              : 'Supabase 응답 ' . $st . '.'));
        jerror('unauthorized', '토큰 검증 실패. ' . $hint, 401, [
            'debug' => array_merge(['stage' => 'supabase_call'], (array)$diag),
        ]);
    }
    return $email;
}

/* ========== 디렉터리 격리 (upload.php 와 동일 함수명) ========== */
function user_dir_segment(string $email): string {
    return 'u_' . substr(hash('sha256', strtolower(trim($email))), 0, 16);
}

/* ========== customer_log 헬퍼 (records.php 와 동일 정의 — DRY 보다 standalone 우선) ========== */
function customer_log_free_quota(): int { return 5; }

/**
 * admin allowlist — 운영자 계정은 free quota 우회.
 * records.php 의 admin_email_allowlist() 와 같은 패턴.
 * 추후 admin 추가 시 양쪽 모두 갱신.
 */
function is_admin_email_for_recording(string $email): bool {
    return in_array(strtolower(trim($email)), ['nxnxax@gmail.com'], true);
}

function ensure_customer_log_table(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS customer_log (
                id CHAR(36) NOT NULL PRIMARY KEY,
                owner_email VARCHAR(255) NOT NULL,
                customer_phone_lookup CHAR(64) NULL DEFAULT NULL,
                customer_name VARCHAR(255) NULL DEFAULT NULL,
                phone_number VARCHAR(255) NULL DEFAULT NULL,
                summary TEXT NULL DEFAULT NULL,
                interest TEXT NULL DEFAULT NULL,
                inquiry TEXT NULL DEFAULT NULL,
                budget_condition TEXT NULL DEFAULT NULL,
                next_action TEXT NULL DEFAULT NULL,
                agent_memo TEXT NULL DEFAULT NULL,
                transcript LONGTEXT NULL DEFAULT NULL,
                consult_at DATETIME NOT NULL,
                audio_storage_path VARCHAR(512) NULL DEFAULT NULL,
                audio_kept TINYINT(1) NOT NULL DEFAULT 0,
                ai_model VARCHAR(64) NULL DEFAULT NULL,
                ai_generated_at DATETIME NULL DEFAULT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'app-auto',
                client_request_id VARCHAR(64) NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cl_owner_consult (owner_email, consult_at),
                INDEX idx_cl_owner_phone (owner_email, customer_phone_lookup),
                UNIQUE KEY uniq_cl_idempotency (owner_email, client_request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // 옵션 D — records.php 와 동기화.
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM customer_log")->fetchAll(PDO::FETCH_ASSOC);
            $hasLink = false;
            foreach ($cols as $c) { if (($c['Field'] ?? '') === 'linked_ledger_record_id') { $hasLink = true; break; } }
            if (!$hasLink) {
                $pdo->exec("ALTER TABLE customer_log
                    ADD COLUMN linked_ledger_record_id INT NULL DEFAULT NULL,
                    ADD INDEX idx_cl_linked (linked_ledger_record_id)");
            }
        } catch (Throwable $e) {
            error_log('[process-recording] customer_log ALTER linked_ledger_record_id failed: ' . $e->getMessage());
        }
        return $done = true;
    } catch (Throwable $e) {
        error_log('[process-recording] ensure_customer_log_table failed: ' . $e->getMessage());
        return $done = false;
    }
}

function ensure_members_plan_columns(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `members`");
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $existing[] = $col['Field'];
        }
        // 옛 컬럼 (Phase 1)
        if (!in_array('plan', $existing, true)) {
            // 새 default: 'trialing' — 신규 가입자 5회 무료 체험. 기존 row 는 'free' 유지.
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `plan` VARCHAR(16) NOT NULL DEFAULT 'trialing'");
        }
        if (!in_array('free_summaries_used', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `free_summaries_used` INT NOT NULL DEFAULT 0");
        }
        // 신규 컬럼 (구독 결제 시스템 — PortOne + 토스페이먼츠)
        // trialing(5회) → active(plus 20회 / pro 무제한) → past_due → cancelled
        if (!in_array('plan_status', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `plan_status` VARCHAR(16) NOT NULL DEFAULT 'trialing'");
        }
        if (!in_array('portone_customer_id', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `portone_customer_id` VARCHAR(64) NULL DEFAULT NULL");
        }
        // 빌링키 — 정기결제용. PortOne BillingKey API 로 발급 (카드 정보 토큰화).
        if (!in_array('portone_billing_key', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `portone_billing_key` VARCHAR(128) NULL DEFAULT NULL");
        }
        // 현재 active subscription id (subscriptions 테이블 ref)
        if (!in_array('portone_subscription_id', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `portone_subscription_id` VARCHAR(64) NULL DEFAULT NULL");
        }
        if (!in_array('current_period_start', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `current_period_start` DATETIME NULL DEFAULT NULL");
        }
        if (!in_array('current_period_end', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `current_period_end` DATETIME NULL DEFAULT NULL");
        }
        if (!in_array('cancel_at_period_end', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `cancel_at_period_end` TINYINT(1) NOT NULL DEFAULT 0");
        }
        // summary_limit: NULL = 무제한 (pro), 0 = 차단 (free), 5 = trialing, 20 = plus
        if (!in_array('summary_limit', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `summary_limit` INT NULL DEFAULT 5");
        }
        // last_usage_reset_at: 매월 결제 갱신 시 free_summaries_used=0 으로 reset 한 시점
        if (!in_array('last_usage_reset_at', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `last_usage_reset_at` DATETIME NULL DEFAULT NULL");
        }
        // Phase 1 lazy 마이그레이션 — 옛 plan='premium' (Phase 1 단순 구조) → 'plus' 로 정규화.
        // idempotent: 두 번째 호출부터는 영향 받는 row 0개.
        try {
            $pdo->exec("UPDATE `members` SET `plan` = 'plus' WHERE `plan` = 'premium'");
        } catch (Throwable $e) {
            error_log('[process-recording] premium → plus migration: ' . $e->getMessage());
        }
        return $done = true;
    } catch (Throwable $e) {
        error_log('[process-recording] ensure_members_plan_columns failed: ' . $e->getMessage());
        return $done = false;
    }
}

/**
 * plan 별 월 사용 한도 결정.
 * - 사용자의 summary_limit 컬럼이 명시되어 있으면 그 값 (관리자 수동 override 가능).
 * - 그렇지 않으면 plan 별 기본값:
 *     pro      → null (무제한)
 *     plus     → 20
 *     trialing → 5  (신규 가입 5회 무료 체험)
 *     free     → 0  (차단)
 */
function resolve_summary_limit(?string $plan, $columnValue): ?int {
    // 컬럼 명시값 우선 (관리자가 admin 에서 직접 변경한 경우)
    if ($columnValue !== null && $columnValue !== '') {
        $n = (int)$columnValue;
        if ($n < 0) return null;   // 음수 = 무제한 (admin 안전망)
        return $n;
    }
    switch (strtolower((string)$plan)) {
        case 'pro':       return null;
        case 'plus':
        case 'premium':   return 20;  // 옛 Phase 1 plan='premium' 안전망 — Plus 와 동일 권한
        case 'trialing':  return 5;
        case 'free':
        default:          return 0;
    }
}

/**
 * Phase 2 M2: 비동기 작업 큐 테이블. records.php 의 ensure_recording_jobs_table 과 schema 동기화.
 */
function ensure_recording_jobs_table(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS recording_jobs (
                id CHAR(36) NOT NULL PRIMARY KEY,
                owner_email VARCHAR(255) NOT NULL,
                customer_log_id CHAR(36) NULL DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'queued',
                storage_path VARCHAR(512) NULL DEFAULT NULL,
                client_request_id VARCHAR(64) NULL DEFAULT NULL,
                error_message TEXT NULL DEFAULT NULL,
                fcm_sent_at DATETIME NULL DEFAULT NULL,
                started_at DATETIME NULL DEFAULT NULL,
                completed_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rj_owner_status (owner_email, status),
                INDEX idx_rj_status_created (status, created_at),
                UNIQUE KEY uniq_rj_idempotency (owner_email, client_request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return $done = true;
    } catch (Throwable $e) {
        error_log('[process-recording] ensure_recording_jobs_table failed: ' . $e->getMessage());
        return $done = false;
    }
}

/**
 * async mode 즉시 응답 — fastcgi_finish_request 로 client 연결 종료 후 백그라운드 계속.
 */
function respond_async_queued(string $jobId): void {
    http_response_code(202);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'status' => 'queued',
        'job_id' => $jobId,
        'mode'   => 'async',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    ignore_user_abort(true);
    @set_time_limit(300);   // 5분 — 백그라운드 처리 한도
}

function customer_phone_lookup_key(?string $phone): ?string {
    if ($phone === null) return null;
    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === '') return null;
    $key = function_exists('youngman_master_key') ? youngman_master_key() : null;
    return $key ? hash_hmac('sha256', $digits, $key) : hash('sha256', $digits);
}

function customer_log_row(array $row): array {
    return [
        'id'                  => (string)$row['id'],
        'owner_email'         => $row['owner_email'] ?? null,
        'customer_name'       => youngman_decrypt($row['customer_name'] ?? null),
        'phone_number'        => youngman_decrypt($row['phone_number'] ?? null),
        'consult_at'          => $row['consult_at'] ?? null,
        'summary'             => youngman_decrypt($row['summary'] ?? null),
        'interest'            => youngman_decrypt($row['interest'] ?? null),
        'inquiry'             => youngman_decrypt($row['inquiry'] ?? null),
        'budget_condition'    => youngman_decrypt($row['budget_condition'] ?? null),
        'next_action'         => youngman_decrypt($row['next_action'] ?? null),
        'agent_memo'          => youngman_decrypt($row['agent_memo'] ?? null),
        'audio_storage_path'  => $row['audio_storage_path'] ?? null,
        'audio_kept'          => !empty($row['audio_kept']),
        'transcript'          => youngman_decrypt($row['transcript'] ?? null),
        'ai_model'            => $row['ai_model'] ?? null,
        'ai_generated_at'     => $row['ai_generated_at'] ?? null,
        'source'              => $row['source'] ?? 'app-auto',
        'client_request_id'   => $row['client_request_id'] ?? null,
        'linked_ledger_record_id' => isset($row['linked_ledger_record_id']) && $row['linked_ledger_record_id'] !== null
                                    ? (int)$row['linked_ledger_record_id'] : null,
        'created_at'          => $row['created_at'] ?? null,
        'updated_at'          => $row['updated_at'] ?? null,
    ];
}

function uuid_v4(): string {
    try {
        $u = random_bytes(16);
        $u[6] = chr((ord($u[6]) & 0x0f) | 0x40);
        $u[8] = chr((ord($u[8]) & 0x3f) | 0x80);
        $hx = bin2hex($u);
        return substr($hx, 0, 8) . '-' . substr($hx, 8, 4) . '-' . substr($hx, 12, 4)
             . '-' . substr($hx, 16, 4) . '-' . substr($hx, 20, 12);
    } catch (Throwable $e) {
        return substr(sha1(uniqid('cl_', true)), 0, 36);
    }
}

/* ========== HTTP method gate ========== */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') jerror('method_not_allowed', 'POST only', 405);

/* ========== 인증 & PDO ========== */
$ownerEmail = require_auth_email();

$dbConfigPath = __DIR__ . '/db_config.php';
if (!is_file($dbConfigPath)) $dbConfigPath = dirname(__DIR__) . '/db_config.php';
if (!is_file($dbConfigPath)) jerror('upstream_failed', 'DB 설정 파일 없음.', 500);
$db = require $dbConfigPath;

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'] ?? 'localhost',
            (int)($db['port'] ?? 3306),
            $db['database'] ?? ''),
        $db['user'] ?? '',
        $db['password'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    error_log('[process-recording] PDO connect failed: ' . $e->getMessage());
    jerror('upstream_failed', 'DB 연결 실패.', 500);
}

if (!ensure_customer_log_table($pdo)) jerror('upstream_failed', 'customer_log 마이그레이션 실패.', 503);
ensure_members_plan_columns($pdo);   // best-effort — plan 컬럼 없어도 'free' 디폴트로 처리.

/* ========== 요청 body 파싱 ========== */
$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body)) jerror('invalid_audio', 'JSON body 형식 오류.', 400);

$storagePath  = trim((string)($body['storage_path'] ?? ''));
$clientReqId  = trim((string)($body['client_request_id'] ?? ''));
$recordedAt   = trim((string)($body['recorded_at'] ?? ''));
$phoneNumber  = trim((string)($body['phone_number'] ?? ''));
$durationSec  = (int)($body['duration_sec'] ?? 0);
$origFilename = trim((string)($body['original_filename'] ?? ''));
// 앱이 폰 contacts lookup 결과로 매칭한 이름. 있으면 LLM 출력보다 우선 적용 (룰 §1).
$customerNameHint = trim((string)($body['customer_name_hint'] ?? ''));
if (mb_strlen($customerNameHint) > 80) $customerNameHint = mb_substr($customerNameHint, 0, 80);

// Phase 2 M2: async mode 옵션. body.mode = 'async' 이면 즉시 job_id 응답 + 백그라운드 처리.
// 기본 'sync' — 완료까지 응답 hold (기존 흐름 유지).
$asyncMode = (strtolower(trim((string)($body['mode'] ?? ''))) === 'async');

if ($storagePath === '') jerror('invalid_audio', 'storage_path 누락.', 400);
if ($clientReqId === '') jerror('invalid_audio', 'client_request_id 누락.', 400);
if (strlen($clientReqId) > 64) jerror('invalid_audio', 'client_request_id 너무 김.', 400);

$consultAt = '';
if ($recordedAt !== '') {
    $ts = @strtotime($recordedAt);
    if ($ts) $consultAt = date('Y-m-d H:i:s', $ts);
}
if ($consultAt === '') $consultAt = date('Y-m-d H:i:s');

/* ========== Idempotency (24h) ========== */
$idemStmt = $pdo->prepare("
    SELECT * FROM customer_log
    WHERE owner_email = :o AND client_request_id = :k
      AND created_at >= (NOW() - INTERVAL 24 HOUR)
    LIMIT 1
");
$idemStmt->execute([':o' => $ownerEmail, ':k' => $clientReqId]);
$existing = $idemStmt->fetch();
if ($existing) {
    // 이미 처리됨 — 200 + 같은 row.
    $planRow = null;
    try {
        $ps = $pdo->prepare('SELECT plan, free_summaries_used FROM members WHERE email = :e LIMIT 1');
        $ps->execute([':e' => $ownerEmail]);
        $planRow = $ps->fetch();
    } catch (Throwable $e) { /* plan 컬럼 없음 — 무시 */ }
    jout([
        'status' => 'ok',
        'customer_log' => customer_log_row($existing),
        'plan' => [
            'plan' => $planRow['plan'] ?? 'free',
            'free_summaries_used' => (int)($planRow['free_summaries_used'] ?? 0),
            'free_quota' => customer_log_free_quota(),
        ],
        'duplicate' => true,
    ]);
}

/* ========== Phase 2 M2 — async 분기 ==========
 * customer_log idempotency check 까지 통과 (이미 처리된 결과는 없음).
 * async 모드면 recording_jobs row 생성 + 즉시 응답 + 백그라운드 처리 시작.
 * sync 모드는 아래 흐름 그대로.
 */
$asyncJobId = null;
if ($asyncMode) {
    if (!ensure_recording_jobs_table($pdo)) {
        jerror('upstream_failed', 'recording_jobs 마이그레이션 실패.', 503);
    }
    // recording_jobs idempotency — 같은 client_request_id 의 job 이 있으면 그 job_id 반환.
    $jobIdem = $pdo->prepare("SELECT * FROM recording_jobs
        WHERE owner_email = :o AND client_request_id = :k
          AND created_at >= (NOW() - INTERVAL 24 HOUR) LIMIT 1");
    $jobIdem->execute([':o' => $ownerEmail, ':k' => $clientReqId]);
    $existingJob = $jobIdem->fetch();
    if ($existingJob) {
        jout([
            'status' => 'queued',
            'job_id' => (string)$existingJob['id'],
            'duplicate' => true,
            'job_status' => $existingJob['status'],
            'customer_log_id' => $existingJob['customer_log_id'] ?? null,
        ], 202);
    }
    // 새 job 생성.
    $asyncJobId = uuid_v4();
    $insJob = $pdo->prepare("INSERT INTO recording_jobs
        (id, owner_email, status, storage_path, client_request_id)
        VALUES (:id, :o, 'queued', :sp, :k)");
    $insJob->execute([
        ':id' => $asyncJobId,
        ':o'  => $ownerEmail,
        ':sp' => $storagePath,
        ':k'  => $clientReqId,
    ]);

    // 즉시 응답 — client 연결 종료. 이후 코드는 백그라운드.
    respond_async_queued($asyncJobId);

    // 정상 완료 안 되면 (PHP fatal, jerror exit 등) shutdown 시 'failed' 마크.
    register_shutdown_function(function() use ($pdo, $asyncJobId) {
        try {
            $check = $pdo->prepare("SELECT status FROM recording_jobs WHERE id = :id LIMIT 1");
            $check->execute([':id' => $asyncJobId]);
            $current = (string)$check->fetchColumn();
            if ($current === 'queued' || $current === 'processing') {
                $pdo->prepare("UPDATE recording_jobs
                    SET status = 'failed', completed_at = NOW(),
                        error_message = COALESCE(error_message, 'unexpected shutdown')
                    WHERE id = :id")
                    ->execute([':id' => $asyncJobId]);
            }
        } catch (Throwable $e) { /* shutdown 중 — log 만 */ error_log('[process-recording] shutdown failsafe: ' . $e->getMessage()); }
    });

    // status: processing 으로 업데이트 (백그라운드 시작).
    try {
        $pdo->prepare("UPDATE recording_jobs SET status = 'processing', started_at = NOW() WHERE id = :id")
            ->execute([':id' => $asyncJobId]);
    } catch (Throwable $e) { /* 무시 */ }
}

/* ========== Plan check ==========
 * 구독 plan 별 quota:
 *   pro      → 무제한 (summary_limit = NULL)
 *   plus     → 월 20회
 *   trialing → 5회 (신규 가입자 체험)
 *   free     → 0 (차단)
 * + plan_status 검사 — past_due / cancelled 면 차단 (active / trialing 만 통과).
 * + admin allowlist 는 모든 검사 우회.
 */
$plan = 'trialing';
$planStatus = 'trialing';
$freeUsed = 0;
$summaryLimitColumn = null;
$summaryLimitMinutes = null;   // 분 한도 (NULL 이면 회 단위 레거시 흐름 사용)
$usageSecondsPeriod = 0;       // 이번달 누적 초
$overageEnabled = 0;
$overageBalanceSeconds = 0;
try {
    $ps = $pdo->prepare('SELECT plan, plan_status, free_summaries_used, summary_limit,
                                summary_limit_minutes, usage_seconds_period,
                                overage_enabled, overage_balance_seconds
                         FROM members WHERE email = :e LIMIT 1');
    $ps->execute([':e' => $ownerEmail]);
    $row = $ps->fetch();
    if ($row) {
        $plan = (string)($row['plan'] ?? 'trialing');
        $planStatus = (string)($row['plan_status'] ?? 'trialing');
        $freeUsed = (int)($row['free_summaries_used'] ?? 0);
        $summaryLimitColumn = $row['summary_limit'] ?? null;
        $summaryLimitMinutes = isset($row['summary_limit_minutes']) ? (int)$row['summary_limit_minutes'] : null;
        $usageSecondsPeriod = (int)($row['usage_seconds_period'] ?? 0);
        $overageEnabled = (int)($row['overage_enabled'] ?? 0);
        $overageBalanceSeconds = (int)($row['overage_balance_seconds'] ?? 0);
    }
} catch (Throwable $e) {
    // 컬럼이 아직 없으면 trialing 으로 간주 (안전).
    $plan = 'trialing';
    $planStatus = 'trialing';
    $freeUsed = 0;
}
$isAdminUser = is_admin_email_for_recording($ownerEmail);

if (!$isAdminUser) {
    // plan_status 검사 — past_due / cancelled 는 즉시 차단.
    $statusLc = strtolower($planStatus);
    if ($statusLc === 'past_due') {
        jerror('plan_required', '결제 정보를 확인해 주세요. 결제가 처리되지 않아 일시 정지되었습니다.', 403);
    }
    if ($statusLc === 'cancelled') {
        jerror('plan_required', '구독이 해지되어 통화 AI 요약을 사용할 수 없습니다.', 403);
    }

    /* Phase 2 분 단위 한도 검사 (summary_limit_minutes 채워진 경우 우선).
     * 앱이 보낸 duration_sec 으로 이번 통화 길이를 사전에 알 수 있음.
     * 한도 + 충전 잔액으로 충당 가능한지 미리 검증. 부족 시 자동 충전 트리거. */
    if ($summaryLimitMinutes !== null && $summaryLimitMinutes > 0) {
        $limitSec = $summaryLimitMinutes * 60;
        $needSec = max(60, (int)$durationSec);  // 최소 1분 가정 (앱이 0 보낼 때 안전망)
        $afterUsage = $usageSecondsPeriod + $needSec;
        $shortageSec = max(0, $afterUsage - $limitSec);  // 한도 초과량 (초)
        $balanceShort = $shortageSec > $overageBalanceSeconds ? ($shortageSec - $overageBalanceSeconds) : 0;

        if ($balanceShort > 0) {
            // 한도 + 충전 잔액으로 부족 → 자동 충전 시도
            if ($overageEnabled === 1) {
                $topUp = function_exists('charge_overage_top_up') ? charge_overage_top_up($pdo, $ownerEmail) : ['ok' => false, 'reason' => 'helper_missing'];
                if ($topUp['ok']) {
                    $overageBalanceSeconds = (int)($topUp['new_balance_seconds'] ?? ($overageBalanceSeconds + (int)($topUp['added_seconds'] ?? 0)));
                    error_log('[process-recording] overage auto top-up success: owner=' . $ownerEmail . ', added=' . ($topUp['added_seconds'] ?? 0) . 's, new_balance=' . $overageBalanceSeconds . 's');
                    // 충전 후에도 부족하면 (5,000원으론 모자란 매우 긴 통화) — 일단 통과시키되 차감 시 음수 허용
                    // 다음 통화 시점에 다시 충전 트리거됨
                } else {
                    error_log('[process-recording] overage auto top-up failed: owner=' . $ownerEmail . ', reason=' . ($topUp['reason'] ?? '?'));
                    jerror('plan_required',
                        '이번 달 ' . $summaryLimitMinutes . '분 한도를 모두 사용하셨고 자동 충전 결제도 실패했습니다. ' .
                        '구독 관리 페이지에서 결제 수단을 확인해 주세요.',
                        402);
                }
            } else {
                // overage_enabled = 0 (자동 충전 미동의) → 한도 초과 거부
                if ($plan === 'free') {
                    jerror('plan_required', '무료 체험 ' . $summaryLimitMinutes . '분을 모두 사용했습니다. Plus 또는 Pro 구독을 시작해 주세요.', 403);
                } elseif ($plan === 'trialing') {
                    jerror('plan_required', '신규 가입 체험 ' . $summaryLimitMinutes . '분을 모두 사용했습니다. Plus 또는 Pro 구독을 시작해 주세요.', 403);
                } else {
                    jerror('plan_required',
                        '이번 달 ' . $summaryLimitMinutes . '분 한도를 모두 사용했습니다. ' .
                        '자동 충전을 켜시거나 다음 결제일을 기다려 주세요.',
                        403);
                }
            }
        }
    } else {
        /* 레거시 회 단위 흐름 (분 한도 컬럼이 아직 ALTER 안 된 환경 또는 Phase 1 잔재) */
        $effectiveLimit = resolve_summary_limit($plan, $summaryLimitColumn);
        if ($effectiveLimit !== null && $freeUsed >= $effectiveLimit) {
            if ($plan === 'free') {
                jerror('plan_required', '무료 플랜은 통화 AI 요약을 사용할 수 없습니다. Plus 또는 Pro 구독이 필요합니다.', 403);
            } elseif ($plan === 'trialing') {
                jerror('plan_required', '신규 가입 무료 체험을 모두 사용했습니다. Plus 또는 Pro 구독을 시작해 주세요.', 403);
            } else {
                jerror('plan_required', '이번 달 한도를 모두 사용했습니다. 다음 결제일까지 기다리거나 Pro 로 업그레이드해 주세요.', 403);
            }
        }
    }
}

/* ========== storage_path 검증 (owner 격리 + 경로 traversal 차단) ========== */
if (strpos($storagePath, '..') !== false || strpos($storagePath, "\0") !== false) {
    jerror('invalid_audio', 'storage_path 형식 오류.', 422);
}
$expectedPrefix = 'uploads/recordings/' . user_dir_segment($ownerEmail) . '/';
if (strpos($storagePath, $expectedPrefix) !== 0) {
    jerror('invalid_audio', '파일 소유권이 일치하지 않습니다.', 422);
}
$absPath = __DIR__ . '/' . $storagePath;
$realPath = @realpath($absPath);
if (!$realPath || !is_file($realPath)) {
    jerror('invalid_audio', '오디오 파일을 찾을 수 없습니다.', 422);
}
$uploadsReal = @realpath(__DIR__ . '/uploads');
if (!$uploadsReal || strpos($realPath, $uploadsReal . DIRECTORY_SEPARATOR) !== 0) {
    jerror('invalid_audio', '오디오 경로가 uploads 외부.', 422);
}

/* ========== STT provider 분기 ==========
 * STT_PROVIDER 환경변수로 토글:
 *   - 'clova' (기본): Naver CLOVA Speech LSR — 화자분리 포함, 회당 ~180원
 *   - 'whisper': OpenAI Whisper API — 화자분리 없음, 회당 ~50원 (-72%)
 *
 * Whisper 사용 시 화자 라벨이 없으므로 LLM system prompt 가 평문도 처리 가능해야 함
 * (아래 ====== STT_PROVIDER 평문 케이스 ====== 블록 참조).
 */
$apiKey = load_env_value('OPENAI_API_KEY');
if ($apiKey === '') jerror('upstream_failed', 'OPENAI_API_KEY 미설정.', 500);

$sttProviderRequested = strtolower(trim((string)load_env_value('STT_PROVIDER'))) ?: 'clova';
/* 확장자 판별 — 앱팀 회신: Content-Type 헤더는 항상 'audio/mp4' 하드코딩이라 신뢰 불가.
 *                       original_filename 의 확장자가 가장 신뢰할 수 있음. fallback: 서버 저장 파일명. */
$origExt = $origFilename !== '' ? strtolower(pathinfo($origFilename, PATHINFO_EXTENSION)) : '';
$srcExt = $origExt !== '' ? $origExt : strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

/* STT auto-fallback (옵션 c): 2단 사전 안전망.
 *   1) 확장자 화이트리스트 — Whisper 가 명시한 지원 포맷 외 자동 CLOVA
 *   2) 파일 사이즈 — Whisper API 의 25MB 제한 초과 시 자동 CLOVA (CLOVA 는 100MB 까지 OK)
 * NCP 설정이 없으면 그대로 whisper 호출 → 런타임 fallback 또는 친절한 jerror. */
$whisperSupportedExts = ['flac', 'm4a', 'mp3', 'mp4', 'mpeg', 'mpga', 'oga', 'ogg', 'wav', 'webm'];
$whisperMaxBytes = 25 * 1024 * 1024;  // OpenAI Whisper API 공식 제한
$sttProvider = $sttProviderRequested;
$sttFallbackReason = '';
$fileSize = @filesize($realPath);
if ($sttProvider === 'whisper') {
    $reasonCand = '';
    if (!in_array($srcExt, $whisperSupportedExts, true)) {
        $reasonCand = 'whisper_unsupported_format_' . ($srcExt !== '' ? $srcExt : 'unknown');
    } elseif ($fileSize !== false && $fileSize > $whisperMaxBytes) {
        $reasonCand = 'whisper_file_too_large_' . $fileSize;
    }
    if ($reasonCand !== '') {
        $clovaInvokeUrlCheck = load_env_value('NCP_CLOVA_INVOKE_URL');
        $clovaSecretCheck    = load_env_value('NCP_CLOVA_SECRET');
        if ($clovaInvokeUrlCheck !== '' && $clovaSecretCheck !== '') {
            $sttProvider = 'clova';
            $sttFallbackReason = $reasonCand;
            error_log('[process-recording] STT pre-fallback: whisper → clova (reason=' . $reasonCand . ', ext=' . $srcExt . ', filename=' . $origFilename . ', size=' . ($fileSize !== false ? $fileSize : '?') . ', owner=' . $ownerEmail . ')');
        }
        // NCP 설정 없으면 그대로 whisper → Whisper 호출 후 런타임 fallback 시도 또는 friendly jerror.
    }
}
$sttMimeMap = [
    'm4a' => 'audio/mp4',   'mp4' => 'audio/mp4',  'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',   'webm'=> 'audio/webm', 'ogg' => 'audio/ogg',
    'flac'=> 'audio/flac',  '3gp' => 'audio/3gpp', '3gpp'=> 'audio/3gpp',
    'aac' => 'audio/aac',   'amr' => 'audio/amr',  'opus'=> 'audio/ogg',
];
$sttMime = $sttMimeMap[$srcExt] ?? 'audio/mp4';
$sttPostname = 'audio.' . ($srcExt !== '' ? $srcExt : 'm4a');
$transcript = '';
$sttModelName = '';
$durationSeconds = 0;  // 통화 길이 (초) — 분 기반 사용량 추적 (Phase 1: 기록만, Phase 2: 차감)

if ($sttProvider === 'whisper') {
    /* ----- OpenAI Whisper API -----
     * POST https://api.openai.com/v1/audio/transcriptions
     *   - multipart: file + model='whisper-1' + language='ko' + prompt(한국어 컨텍스트 힌트)
     *   - 화자분리 없음 — transcript 가 평문 한 덩어리로 옴
     *   - 단가: $0.006/min (회당 ~50원)
     * 3gpp/AMR(삼성 T전화) 는 Whisper 미지원 가능성 — 그 경우 jerror 로 명시.
     */
    /* 화이트리스트 외 포맷 차단 — 위 자동 fallback 이 NCP 미설정 등의 이유로 적용 안 됐을 때만 도달.
     * 사용자에게 친절한 메시지 노출 (앱이 그대로 Alert 표시함). */
    if (!in_array($srcExt, $whisperSupportedExts, true)) {
        jerror('upstream_failed', '녹음 파일 형식을 인식할 수 없습니다. 영맨 고객센터에 문의해 주세요.', 415);
    }
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file'            => new CURLFile($realPath, $sttMime, $sttPostname),
            'model'           => 'whisper-1',
            'language'        => 'ko',
            'response_format' => 'verbose_json',
            'temperature'     => '0',
            // 한국어 영업/부동산 통화 컨텍스트 힌트 — Whisper 도메인 적응에 큰 효과
            'prompt'          => '한국어 부동산/영업 통화입니다. 사장님, 사모님, 평수, 매물, 자료, 견적, 자료 발송, 재컨택 같은 용어가 자주 등장합니다.',
        ],
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $sttResp = curl_exec($ch);
    $sttStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $sttErr = curl_error($ch);
    curl_close($ch);
    if ($sttResp === false) jerror('upstream_failed', 'Whisper 호출 실패: ' . $sttErr, 502);
    $sttData = json_decode((string)$sttResp, true);

    /* 런타임 fallback — Whisper 가 화이트리스트 ext (예: m4a) 라도 codec/container 변종으로 4xx 거부하는 케이스.
     * NCP 설정이 있고 사전 fallback 이 적용 안 됐을 때만 한 번 CLOVA 재시도. */
    if ($sttStatus >= 400 && $sttStatus < 500 && $sttFallbackReason === '') {
        $clovaInvokeUrlR = load_env_value('NCP_CLOVA_INVOKE_URL');
        $clovaSecretR    = load_env_value('NCP_CLOVA_SECRET');
        if ($clovaInvokeUrlR !== '' && $clovaSecretR !== '') {
            $whisperErrMsg = is_array($sttData) ? ($sttData['error']['message'] ?? json_encode($sttData)) : substr((string)$sttResp, 0, 200);
            $sttFallbackReason = 'whisper_runtime_' . $sttStatus;
            error_log('[process-recording] STT runtime fallback: whisper ' . $sttStatus . ' → clova (ext=' . $srcExt . ', filename=' . $origFilename . ', owner=' . $ownerEmail . ', whisper_err=' . $whisperErrMsg . ')');

            $clovaParamsR = json_encode([
                'language'      => 'ko-KR',
                'completion'    => 'sync',
                'fullText'      => true,
                'wordAlignment' => false,
                'diarization'   => ['enable' => true, 'speakerCountMin' => 2, 'speakerCountMax' => 2],
                'resultToObs'   => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $ch2 = curl_init(rtrim($clovaInvokeUrlR, '/') . '/recognizer/upload');
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [
                    'media'  => new CURLFile($realPath, $sttMime, $sttPostname),
                    'params' => $clovaParamsR,
                ],
                CURLOPT_HTTPHEADER => ['X-CLOVASPEECH-API-KEY: ' . $clovaSecretR],
                CURLOPT_TIMEOUT => 180,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $sttResp = curl_exec($ch2);
            $sttStatus = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            $sttErr = curl_error($ch2);
            curl_close($ch2);
            if ($sttResp === false) jerror('upstream_failed', 'Clova fallback 호출 실패: ' . $sttErr, 502);
            $sttData = json_decode((string)$sttResp, true);
            if ($sttStatus < 200 || $sttStatus >= 300) {
                $msg = is_array($sttData) ? ($sttData['message'] ?? json_encode($sttData)) : substr((string)$sttResp, 0, 300);
                jerror('upstream_failed', 'Clova fallback ' . $sttStatus . ': ' . $msg, 502);
            }
            $transcript = trim((string)($sttData['text'] ?? ''));
            if ($transcript === '' && !empty($sttData['segments']) && is_array($sttData['segments'])) {
                $parts = [];
                foreach ($sttData['segments'] as $seg) {
                    $segText = trim((string)($seg['text'] ?? ''));
                    if ($segText === '') continue;
                    $speakerLabel = $seg['speaker']['label'] ?? ($seg['speaker'] ?? null);
                    $parts[] = ($speakerLabel !== null ? '[화자' . $speakerLabel . '] ' : '') . $segText;
                }
                $transcript = implode("\n", $parts);
            }
            if ($transcript === '') jerror('upstream_failed', 'Clova fallback STT 결과가 비어있습니다.', 502);
            $sttModelName = 'naver-clova-speech';
            // duration: 앱이 보낸 duration_sec 우선, 없으면 CLOVA segments
            if ($durationSec > 0) {
                $durationSeconds = $durationSec;
            } elseif (!empty($sttData['segments']) && is_array($sttData['segments'])) {
                $lastSeg = end($sttData['segments']);
                if (isset($lastSeg['end'])) {
                    $durationSeconds = (int)round(((int)$lastSeg['end']) / 1000);
                }
            }
        } else {
            // NCP 설정 없음 → 친절한 메시지 노출
            jerror('upstream_failed', '녹음 파일 형식을 인식할 수 없습니다. 영맨 고객센터에 문의해 주세요.', 415);
        }
    } elseif ($sttStatus < 200 || $sttStatus >= 300) {
        $msg = is_array($sttData) ? ($sttData['error']['message'] ?? json_encode($sttData)) : substr((string)$sttResp, 0, 300);
        jerror('upstream_failed', 'Whisper ' . $sttStatus . ': ' . $msg, 502);
    } else {
        // Whisper 성공
        $transcript = trim((string)($sttData['text'] ?? ''));
        if ($transcript === '') jerror('upstream_failed', 'Whisper STT 결과가 비어있습니다.', 502);
        $sttModelName = 'openai-whisper-1';
        // 앱이 보낸 duration_sec (MediaStore Audio.Media.DURATION) 우선. 0 이면 Whisper response 의 duration 폴백.
        $durationSeconds = $durationSec > 0 ? $durationSec : (int)round((float)($sttData['duration'] ?? 0));
    }
} else {
    /* ----- Naver CLOVA Speech (Long Sentence Recognition) -----
     * 3gpp/AMR (Samsung T전화 등) / m4a/mp4 등 다양한 컨테이너 네이티브 지원이라
     * ffmpeg transcode 단계 불필요. cafe24 .env 의 NCP_CLOVA_INVOKE_URL + NCP_CLOVA_SECRET 사용.
     *
     * API: POST {INVOKE_URL}/recognizer/upload
     *   - 헤더: X-CLOVASPEECH-API-KEY
     *   - multipart: media (audio file) + params (JSON: language/completion/fullText/diarization)
     *   - completion="sync" → 결과 받을 때까지 응답 hold
     *   - diarization → 화자 분리 (영업/고객 2명 가정), LLM 이 customer_name 추출 유리
     */
    $clovaInvokeUrl = load_env_value('NCP_CLOVA_INVOKE_URL');
    $clovaSecret    = load_env_value('NCP_CLOVA_SECRET');
    if ($clovaInvokeUrl === '' || $clovaSecret === '') {
        jerror('upstream_failed', 'NCP Clova 설정 누락 (NCP_CLOVA_INVOKE_URL / NCP_CLOVA_SECRET).', 500);
    }

    $clovaParams = json_encode([
        'language'    => 'ko-KR',
        'completion'  => 'sync',
        'fullText'    => true,
        'wordAlignment' => false,
        'diarization' => ['enable' => true, 'speakerCountMin' => 2, 'speakerCountMax' => 2],
        'resultToObs' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init(rtrim($clovaInvokeUrl, '/') . '/recognizer/upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'media'  => new CURLFile($realPath, $sttMime, $sttPostname),
            'params' => $clovaParams,
        ],
        CURLOPT_HTTPHEADER => ['X-CLOVASPEECH-API-KEY: ' . $clovaSecret],
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $sttResp = curl_exec($ch);
    $sttStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $sttErr = curl_error($ch);
    curl_close($ch);

    if ($sttResp === false) jerror('upstream_failed', 'Clova 호출 실패: ' . $sttErr, 502);
    $sttData = json_decode((string)$sttResp, true);

    if ($sttStatus < 200 || $sttStatus >= 300) {
        $msg = is_array($sttData) ? ($sttData['message'] ?? json_encode($sttData)) : substr((string)$sttResp, 0, 300);
        jerror('upstream_failed', 'Clova ' . $sttStatus . ': ' . $msg, 502);
    }

    // transcript 추출: fullText=true 면 $sttData['text'] 에 합쳐진 결과 옴.
    // fallback: segments[].text 합치기 (화자 정보 포함 시 LLM 이 더 잘 추출).
    $transcript = trim((string)($sttData['text'] ?? ''));
    if ($transcript === '' && !empty($sttData['segments']) && is_array($sttData['segments'])) {
        $parts = [];
        foreach ($sttData['segments'] as $seg) {
            $segText = trim((string)($seg['text'] ?? ''));
            if ($segText === '') continue;
            $speakerLabel = $seg['speaker']['label'] ?? ($seg['speaker'] ?? null);
            $parts[] = ($speakerLabel !== null ? '[화자' . $speakerLabel . '] ' : '') . $segText;
        }
        $transcript = implode("\n", $parts);
    }
    if ($transcript === '') jerror('upstream_failed', 'Clova STT 결과가 비어있습니다.', 502);
    $sttModelName = 'naver-clova-speech';
    // duration: 앱이 보낸 duration_sec 우선. 없으면 CLOVA segments 의 마지막 end (ms) → 초 변환.
    if ($durationSec > 0) {
        $durationSeconds = $durationSec;
    } elseif (!empty($sttData['segments']) && is_array($sttData['segments'])) {
        $lastSeg = end($sttData['segments']);
        if (isset($lastSeg['end'])) {
            $durationSeconds = (int)round(((int)$lastSeg['end']) / 1000);
        }
    }
}

/* ========== LLM provider 분기 ==========
 * LLM_PROVIDER 환경변수로 토글:
 *   - 'openai' (기본): gpt-4o-mini — 회당 ~1원, 메모 수준 품질
 *   - 'anthropic': Claude Sonnet 4.6 + prompt caching — 회당 ~7~15원, 보고서 수준 품질
 *     (caching hit 시 회당 7원, miss 시 15~21원. 5분 통화 평균 시 분당 3~5원)
 */
$llmProvider = strtolower(trim((string)load_env_value('LLM_PROVIDER'))) ?: 'openai';

if ($llmProvider === 'anthropic') {
    $llmModel = 'claude-sonnet-4-6';
    $anthropicKey = load_env_value('ANTHROPIC_API_KEY');
    if ($anthropicKey === '') jerror('upstream_failed', 'ANTHROPIC_API_KEY 미설정.', 500);
} else {
    $llmModel = 'gpt-4o-mini';
}
$sys = <<<SYS
당신은 한국어 부동산/세일즈 통화 내용을 요약해 CRM에 기록하는 보조AI입니다.

입력: 통화 STT 전사
  - CLOVA(Naver) 결과: 화자별 segment 가 [화자1]/[화자2] 로 표시될 수 있음.
  - Whisper(OpenAI) 결과: 화자 라벨 없는 평문 한 덩어리.
  두 경우 모두 처리. 화자 라벨이 없으면 발화 흐름과 호칭/맥락으로 영업측·고객을 추론.

출력: 다음 JSON 스키마. 키 이름은 정확히 일치. 누락 시 빈 문자열이나 null.

{
  "customer_name": string,
  "summary": string,
  "interest": string | null,
  "inquiry": string | null,
  "budget_condition": string | null,
  "next_action": string | null
}

==== customer_name 결정 규칙 (7단계) ====

transcript 에 실제 나타난 단서만 사용. 임의로 추측/추정 금지.

(주: 1번 우선순위 — 외부 customer_name_hint 가 제공되면 백엔드 코드가 LLM
 출력을 덮어쓰니, LLM 은 아래 2~7번만 적용해서 출력하면 됨.)

[우선순위]
2. transcript 에 고객 본인 이름 또는 영업측이 부른 고객 이름 추출 가능
   → "{이름}님" 형식. (예: "김상우님")
   - 영업측 본인 이름이나 통화와 무관한 제3자 이름은 절대 사용 금지.

3. 이름 미추출 + 고객을 가리키는 "사장님" 호칭 + transcript 에 나이/연령대
   명시 (예: "올해 마흔", "쉰", "오십대") → "{연령대}대 남성" (예: "40대 남성")

4. 이름 미추출 + 고객을 가리키는 "사모님" 호칭 + transcript 에 나이/연령대
   명시 → "{연령대}대 여성" (예: "60대 여성")

5. 이름 미추출 + "사장님" 호칭만 등장, 나이 언급 없음 → "남성"

6. 이름 미추출 + "사모님" 호칭만 등장, 나이 언급 없음 → "여성"

7. 위 2~6번 모두 미해당 → "고객"

[절대 금지]
- 음성 timbre, 어휘 수준, 말투 등으로 성별/연령 추정 금지.
  transcript 에 명시되지 않은 정보는 절대 추론하지 말 것.
- "사장님"/"사모님" 이 영업측 발화에만 등장하고 그 대상이 고객인지 불명확하면
  fallback 적용 금지. 화자 분리 결과(있을 때) 또는 발화 흐름(없을 때) 으로
  호칭의 지칭 대상이 고객일 때만 적용.
- 화자 라벨이 없는 평문 transcript 에서는 호칭/대답 패턴으로 추론하되,
  영업측이 본인을 "사장님"이라 부르는 경우는 거의 없으므로 호칭 대상은 대체로 고객.
- 정보 부족 시 절대 추측 말고 "고객" 으로 반환.

[테스트 케이스]
- "사장님 안녕하세요. 자료 검토하셨어요?" "네, 봤어요." → "남성"
- "사모님, 60대시면 이 상품이 잘 맞으세요." "그래요?" → "60대 여성"
- "안녕하세요 김상우 사장님" "네 반갑습니다" → "김상우님"
- "안녕하세요 마흔살 사장님" "네 반갑습니다" → "40대 남성"
- "여보세요" "네 안녕하세요" → "고객"

==== summary 작성 규칙 — 최우선 ====

통화의 흐름과 맥락을 **빠짐없이** 자연스러운 한국어로 풀어 적되,
**말투는 보고서식 명사형/축약형 종결** 사용:
  - "~했음", "~임", "~함" 같은 축약형 종결
  - 또는 명사구로 끝맺음 ("관심 필요", "재컨택 권장", "자료 발송 약속")
  - "~습니다", "~입니다", "~네요", "~요" 같은 정중 종결은 절대 금지

PPT / bullet / 마커 사용 안 함 — 단락 형태의 서술문 (간결한 메모 톤).

다음 정보는 통화에 있었다면 **반드시** 모두 포함 (단정 가능한 사실만):
  ① 통화 개시 맥락 — 인사, 도입 화제, 누가 먼저 꺼낸 주제
  ② 다뤄진 모든 주제 — 가격, 일정, 매물, 자료, 조건, 위치, 면적 등
  ③ 고객의 모든 반응 — 관심, 망설임, 거절, 동의, 감정 표현
  ④ 영업측이 약속하거나 제안한 모든 내용
  ⑤ 구체적 숫자 — 가격, 평수, 시기, 인원, 비율 등
  ⑥ 합의된 다음 단계, 보류된 사항, 미정 사항
  ⑦ 통화 종료 맥락 — 어떤 합의/분위기로 마쳤는지

**분량 제한 없음.** 통화가 길면 길게, 짧으면 짧게.
한 줄도 핵심을 압축하느라 맥락을 빠뜨리지 말 것.
짧은 통화면 5문장, 긴 통화면 15문장 이상도 가능.
요약은 "압축" 이 아니라 "재서술" — 정보 손실 절대 금지.

==== 그 외 필드 ====

summary 와 별개로 각 필드 채움. summary 와 중복돼도 OK — 각 필드가 단독으로
의미 있어야 함. 톤은 summary 와 동일하게 "~했음/~임/~함" 또는 명사형 종결.

- interest: 고객이 관심을 보인 모든 항목. 다수면 쉼표로 나열.
- inquiry: 고객이 던진 모든 질문/문의. 다수면 쉼표로 나열.
- budget_condition: 통화 중 언급된 모든 예산/조건/요구사항.
- next_action: 영업측이 해야 할 follow-up 단계 (자료 발송 / 재컨택 / 견적 등).

각 필드는 통화에 명시된 내용이 없으면 null. 추측 금지.

==== 그 외 규칙 ====

- 단정적이지 않은 사실은 추측하지 말 것 (interest/inquiry/budget_condition/next_action 도 동일).
- 개인정보(주민번호, 카드번호 등)는 마스킹.
- JSON 외 다른 텍스트 출력 금지.

==== ★ 중요: 입력 단독성 (다른 통화와 절대 혼선 금지) ====

- 이번 transcript 에 명시된 사실만 출력. 그 외 정보(과거 통화 내용,
  같은 전화번호로 이전에 무엇을 다뤘는지, 일반 상식, 모델 자체의 추측)
  는 일체 사용 금지.
- 같은 고객과 여러 번 통화한 이력이 있더라도, 이번 요약은 **오직 이번
  transcript 만** 보고 작성. 옛 통화의 주제(예: 세탁기/가전/매물 등)가
  이번 transcript 에 등장하지 않으면 절대 언급하지 말 것.
- transcript 가 짧거나 정보가 부족하면 그 만큼 짧게 — 빈 칸을 메우려고
  옛 정보나 일반론으로 채우지 말 것.
SYS;

if ($llmProvider === 'anthropic') {
    /* ----- Anthropic Claude Messages API + prompt caching -----
     * system 메시지를 cache_control=ephemeral 로 표시 → 5분 TTL 캐시.
     * 같은 system prompt 가 5분 안에 재호출되면 input 단가 90% 절감.
     * 5분 안에 다음 통화가 안 들어와도 정상 호출 (cache miss → 정가). */
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $llmModel,
            'max_tokens' => 2048,
            'temperature' => 0.3,
            'system' => [
                [
                    'type' => 'text',
                    'text' => $sys,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $transcript],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $anthropicKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $llmResp = curl_exec($ch);
    $llmStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $llmErr = curl_error($ch);
    curl_close($ch);
    if ($llmResp === false) jerror('upstream_failed', 'Claude 호출 실패: ' . $llmErr, 502);
    $llmData = json_decode((string)$llmResp, true);
    if ($llmStatus < 200 || $llmStatus >= 300) {
        $msg = is_array($llmData) ? ($llmData['error']['message'] ?? json_encode($llmData)) : substr((string)$llmResp, 0, 300);
        jerror('upstream_failed', 'Claude ' . $llmStatus . ': ' . $msg, 502);
    }
    $llmText = (string)($llmData['content'][0]['text'] ?? '');
} else {
    /* ----- OpenAI Chat Completions ----- */
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $llmModel,
            'messages' => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => $transcript],
            ],
            'temperature' => 0.3,
            'response_format' => ['type' => 'json_object'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $llmResp = curl_exec($ch);
    $llmStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $llmErr = curl_error($ch);
    curl_close($ch);
    if ($llmResp === false) jerror('upstream_failed', 'LLM 호출 실패: ' . $llmErr, 502);
    $llmData = json_decode((string)$llmResp, true);
    if ($llmStatus < 200 || $llmStatus >= 300) {
        $msg = is_array($llmData) ? ($llmData['error']['message'] ?? json_encode($llmData)) : substr((string)$llmResp, 0, 300);
        jerror('upstream_failed', 'LLM ' . $llmStatus . ': ' . $msg, 502);
    }
    $llmText = (string)($llmData['choices'][0]['message']['content'] ?? '');
}
$parsed = json_decode($llmText, true);
if (!is_array($parsed)) {
    // 한 번 더 시도 — 평문 안에 JSON 블록이 섞인 경우 추출.
    if (preg_match('/\{[\s\S]*\}/', $llmText, $m)) {
        $parsed = json_decode($m[0], true);
    }
}
if (!is_array($parsed)) jerror('upstream_failed', 'LLM JSON 파싱 실패.', 502);

$llmName    = isset($parsed['customer_name'])    ? trim((string)$parsed['customer_name'])    : '';
// 룰 §1: 앱이 전달한 contacts hint 가 있으면 LLM 출력보다 우선.
if ($customerNameHint !== '') $llmName = $customerNameHint;
$llmSummary = isset($parsed['summary'])          ? trim((string)$parsed['summary'])          : '';
$llmIntr    = isset($parsed['interest'])         ? trim((string)$parsed['interest'])         : '';
$llmInq     = isset($parsed['inquiry'])          ? trim((string)$parsed['inquiry'])          : '';
$llmBudg    = isset($parsed['budget_condition']) ? trim((string)$parsed['budget_condition']) : '';
$llmNext    = isset($parsed['next_action'])      ? trim((string)$parsed['next_action'])      : '';

if ($llmSummary === '') $llmSummary = $transcript;   // 최후 폴백 — 빈 summary 는 NOT NULL 위반.

/* ========== Insert customer_log ========== */
$rowId = uuid_v4();
$phoneLookup = customer_phone_lookup_key($phoneNumber !== '' ? $phoneNumber : null);

try {
    $ins = $pdo->prepare("
        INSERT INTO customer_log (
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
            :am, NOW(), 'app-auto', :cri
        )
    ");
    $ins->execute([
        ':id'  => $rowId,
        ':o'   => $ownerEmail,
        ':pl'  => $phoneLookup,
        ':nm'  => $llmName    !== '' ? youngman_encrypt($llmName)    : null,
        ':ph'  => $phoneNumber !== '' ? youngman_encrypt($phoneNumber) : null,
        ':sum' => youngman_encrypt($llmSummary),
        ':intr'=> $llmIntr !== '' ? youngman_encrypt($llmIntr) : null,
        ':inq' => $llmInq  !== '' ? youngman_encrypt($llmInq)  : null,
        ':bg'  => $llmBudg !== '' ? youngman_encrypt($llmBudg) : null,
        ':nx'  => $llmNext !== '' ? youngman_encrypt($llmNext) : null,
        ':tr'  => youngman_encrypt($transcript),
        ':ca'  => $consultAt,
        ':asp' => $storagePath,
        ':am'  => $sttModelName . '+' . $llmModel,
        ':cri' => $clientReqId,
    ]);
} catch (Throwable $e) {
    // UNIQUE (owner_email, client_request_id) 충돌이 동시 요청에서 발생할 수 있음 — 24h 외 충돌은 409.
    if (strpos((string)$e->getMessage(), 'Duplicate') !== false) {
        jerror('duplicate_request', '중복 요청. 잠시 후 다시 시도해주세요.', 409);
    }
    error_log('[process-recording] insert failed: ' . $e->getMessage());
    jerror('upstream_failed', 'DB 저장 실패.', 500);
}

/* ========== 사용량 카운트 ==========
 *   - admin allowlist: 카운트 안 함
 *   - pro: 카운트 안 함 (무제한)
 *   - 그 외 (free / trialing / plus): summary 처리 성공 후 +1
 *   - usage_logs 에도 row 추가 (감사 / 통계)
 */
if (!$isAdminUser && strtolower($plan) !== 'pro') {
    // 레거시 회 단위 카운트 (분 단위 시스템 안정화 전까지 병행 운영)
    try {
        $pdo->prepare('UPDATE members SET free_summaries_used = free_summaries_used + 1 WHERE email = :e')
            ->execute([':e' => $ownerEmail]);
        $freeUsed += 1;
    } catch (Throwable $e) {}

    // Phase 2 분 단위 차감: usage_seconds_period 누적 + 한도 초과량은 overage_balance_seconds 에서 차감.
    if ($durationSeconds > 0) {
        try {
            $pdo->prepare('UPDATE members SET usage_seconds_period = COALESCE(usage_seconds_period,0) + :d WHERE email = :e')
                ->execute([':d' => $durationSeconds, ':e' => $ownerEmail]);
        } catch (Throwable $e) {}
        // 한도 초과량을 overage_balance_seconds 에서 차감 (분 한도가 있는 환경에서만)
        if ($summaryLimitMinutes !== null && $summaryLimitMinutes > 0) {
            $limitSecFinal = $summaryLimitMinutes * 60;
            $usageAfter = $usageSecondsPeriod + $durationSeconds;  // 사후 누적
            $overSec = max(0, $usageAfter - $limitSecFinal);
            $prevOverSec = max(0, $usageSecondsPeriod - $limitSecFinal);
            $deltaOverSec = $overSec - $prevOverSec;  // 이번 통화 분 중 한도 초과분
            if ($deltaOverSec > 0) {
                try {
                    $pdo->prepare('UPDATE members SET overage_balance_seconds = GREATEST(0, COALESCE(overage_balance_seconds,0) - :d) WHERE email = :e')
                        ->execute([':d' => $deltaOverSec, ':e' => $ownerEmail]);
                    error_log('[process-recording] overage balance decrement: owner=' . $ownerEmail . ', delta=' . $deltaOverSec . 's');
                } catch (Throwable $e) {}
            }
        }
    }
    // usage_logs 기록 (best-effort)
    try {
        // 같은 lazy CREATE 트릭 — usage_logs 없으면 records.php 의 ensure 가 트리거되어 있어야.
        $pdo->prepare('INSERT INTO usage_logs (owner_email, feature, amount, plan, metadata_json) VALUES (:o, :f, 1, :p, :m)')
            ->execute([
                ':o' => $ownerEmail,
                ':f' => 'call_summary',
                ':p' => $plan,
                ':m' => json_encode([
                    'customer_log_id'   => $rowId,
                    'duration_seconds'  => $durationSeconds,
                    'stt_model'         => $sttModelName,
                    'llm_model'         => $llmModel,
                ], JSON_UNESCAPED_UNICODE),
            ]);
    } catch (Throwable $e) {
        // 테이블 없으면 무시 — 다음 deploy 후 정상.
    }
}

/* ========== 오디오 파일 즉시 삭제 (audio_kept = false) ========== */
@unlink($realPath);
if ($convertedPath !== null && is_file($convertedPath)) @unlink($convertedPath);
// 디렉터리도 비어있으면 정리 — best-effort, 실패 무시.
@rmdir(dirname($realPath));

/* ========== 응답 (sync) / 완료 표시 (async) ========== */
$fetch = $pdo->prepare('SELECT * FROM customer_log WHERE id = :id LIMIT 1');
$fetch->execute([':id' => $rowId]);
$savedRow = $fetch->fetch();
if (!$savedRow) {
    // async 모드: 응답 이미 갔으니 jerror 출력은 무시되지만 exit → shutdown_function 이 failed 마크.
    jerror('upstream_failed', 'insert 후 조회 실패.', 500);
}

if ($asyncMode) {
    // async 모드 — client 응답은 이미 'queued' 로 갔음. 백그라운드 작업 완료 표시.
    try {
        $pdo->prepare("UPDATE recording_jobs
            SET status = 'completed', completed_at = NOW(), customer_log_id = :cl
            WHERE id = :id")
            ->execute([':cl' => $rowId, ':id' => $asyncJobId]);
    } catch (Throwable $e) {
        error_log('[process-recording] recording_jobs completed update failed: ' . $e->getMessage());
    }

    // M3: FCM 푸시 발송 — user_fcm_tokens 의 owner 토큰들에게.
    // 실패는 무시 (recording_jobs 는 이미 completed, 폴링 fallback 으로 앱이 결과 받을 수 있음).
    try {
        $custName = (string)(youngman_decrypt($savedRow['customer_name'] ?? '') ?: '고객');
        $sumPreview = (string)(youngman_decrypt($savedRow['summary'] ?? '') ?: '');
        if (mb_strlen($sumPreview) > 60) $sumPreview = mb_substr($sumPreview, 0, 57) . '...';
        $fcmResult = send_fcm_to_user($pdo, $ownerEmail, [
            'title' => '통화 요약 완료 — ' . $custName,
            'body'  => $sumPreview !== '' ? $sumPreview : '새 통화 요약이 저장되었습니다.',
            'data'  => [
                'type'            => 'call_summary_ready',
                'job_id'          => (string)$asyncJobId,
                'customer_log_id' => (string)$rowId,
                'consult_at'      => (string)($savedRow['consult_at'] ?? ''),
            ],
        ]);
        if (!empty($fcmResult['sent'])) {
            $pdo->prepare("UPDATE recording_jobs SET fcm_sent_at = NOW() WHERE id = :id")
                ->execute([':id' => $asyncJobId]);
        }
        error_log('[process-recording] FCM dispatch ' . json_encode($fcmResult, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        error_log('[process-recording] FCM dispatch threw: ' . $e->getMessage());
    }

    exit;
}

// sync 모드 — 정상 응답.
jout([
    'status' => 'ok',
    'customer_log' => customer_log_row($savedRow),
    'plan' => [
        'plan' => $plan,
        'free_summaries_used' => $freeUsed,
        'free_quota' => customer_log_free_quota(),
    ],
]);
