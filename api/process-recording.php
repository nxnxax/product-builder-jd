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

// 서버 에러 진단 로거 (사장님 2026-06-08) — 통화 업로드 실패 = 무한로딩 원인 추적.
// 파일 미배포 시에도 핵심 흐름 안 깨지게 is_file 가드.
$__elog = __DIR__ . '/error_logger.php';
if (is_file($__elog)) require_once $__elog;
if (function_exists('ym_register_fatal_logger')) ym_register_fatal_logger('fatal.process_recording');

/* ========== 응답/입력 헬퍼 ========== */
function jout(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function jerror(string $code, string $message, int $status, array $extra = []): void {
    // 앱팀 2026-05-20 요청 — error_code 표준화: ok/error_code/message/http_status 추가.
    // 기존 status/code 도 backwards compat 으로 유지.
    if (!isset($extra['error_code'])) {
        // 기본 매핑 (호출처가 명시 안 했을 때).
        if ($status === 409) $extra['error_code'] = 'JOB_DUPLICATE';
        elseif ($status >= 500) $extra['error_code'] = 'RETRYABLE_SERVER_ERROR';
        elseif ($status === 401) $extra['error_code'] = 'AUTH_INVALID';
        else $extra['error_code'] = strtoupper($code);
    }
    // 서버 장애(5xx)만 진단 기록 — 4xx(중복/인증/플랜)는 정상 흐름이라 제외.
    if ($status >= 500) {
        $pdo = $GLOBALS['__ym_pdo'] ?? null;
        if ($pdo instanceof PDO && function_exists('log_server_error')) {
            log_server_error($pdo, 'process_rec.fail', $message, 'code=' . $code . ' http=' . $status);
        }
    }
    $payload = array_merge([
        'ok'          => false,
        'status'      => 'error',
        'code'        => $code,
        'error_code'  => $extra['error_code'],
        'message'     => $message,
        'http_status' => $status,
    ], $extra);
    jout($payload, $status);
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

/* JWT exp 클레임 추출 — AUTH_EXPIRED 와 AUTH_INVALID 구분용 (앱팀 2026-05-20 요청). */
function jwt_exp_seconds(string $token): ?int {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
    if (!$payload) return null;
    $data = json_decode($payload, true);
    if (!is_array($data) || !isset($data['exp'])) return null;
    return (int)$data['exp'];
}

function require_auth_email(): string {
    $token = get_bearer_token();
    if (!$token) jerror('unauthorized', '로그인이 필요합니다. 헤더 Authorization Bearer 없음.', 401, [
        'error_code' => 'AUTH_REQUIRED',
        'debug' => ['stage' => 'no_bearer'],
    ]);
    $auth = load_supabase_auth();
    if (empty($auth['supabase_url']) || empty($auth['anon_key'])) {
        jerror('unauthorized', '서버 인증 설정 누락 (.env 의 Supabase URL/Anon Key).', 500, [
            'error_code' => 'RETRYABLE_SERVER_ERROR',
            'debug' => ['stage' => 'env_missing', 'url_set' => !empty($auth['supabase_url']), 'key_set' => !empty($auth['anon_key'])],
        ]);
    }
    $diag = null;
    $email = fetch_user_email_via_supabase($token, $auth, $diag);
    if (!$email) {
        $st = $diag['auth_status'] ?? 0;
        // AUTH_EXPIRED vs AUTH_INVALID 구분 — JWT exp 가 과거면 refresh 가능, 아니면 재로그인 필요.
        $errorCode = 'AUTH_INVALID';
        if ($st === 401) {
            $exp = jwt_exp_seconds($token);
            if ($exp !== null && $exp < time()) $errorCode = 'AUTH_EXPIRED';
        } elseif ($st === 0 || $st >= 500) {
            $errorCode = 'RETRYABLE_SERVER_ERROR';   // Supabase 자체 장애
        }
        $hint = $errorCode === 'AUTH_EXPIRED' ? '토큰이 만료되었습니다. refresh 후 재시도하세요.'
              : ($errorCode === 'RETRYABLE_SERVER_ERROR' ? 'Supabase 호출 일시 실패. 잠시 후 재시도하세요.'
              : '세션이 무효합니다. 다시 로그인이 필요합니다.');
        $httpCode = $errorCode === 'RETRYABLE_SERVER_ERROR' ? 503 : 401;
        jerror('unauthorized', $hint, $httpCode, [
            'error_code' => $errorCode,
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
 * 사장님 2026-05-25 — native A 모달 분기용 plan 정보 빌더.
 * requires_subscription = free + non-admin → native 가 첫 모달 (취소/요약보기/양식에 전송) 대신
 * "구독부터 사용 가능해요" 모달 즉시 표시 분기 트리거.
 * 2026-05-26 — 신규 요금제 (sales/master/agency) 전환. 옛 plan key 도 정규화.
 */
function build_plan_info_for_response(PDO $pdo, string $ownerEmail, bool $isAdminUser): array {
    $plan = 'free';
    $freeUsed = 0;
    $usageSec = 0;
    $limitMinDb = null;
    $rowFound = false;
    try {
        // 사장님 2026-05-25 — case-insensitive email match. 옛 가입자의 mixed-case email 호환.
        // 사장님 2026-05-26 (앱팀 §5) — minutes_used / limit / remaining 추가 응답.
        $ps = $pdo->prepare('SELECT plan, free_summaries_used, usage_seconds_period, summary_limit_minutes FROM members WHERE LOWER(email) = LOWER(:e) LIMIT 1');
        $ps->execute([':e' => $ownerEmail]);
        $row = $ps->fetch();
        if ($row) {
            $rowFound = true;
            $plan = (string)($row['plan'] ?? 'free');
            if ($plan === 'trialing') $plan = 'free';
            if ($plan === 'plus')     $plan = 'sales';
            if ($plan === 'premium')  $plan = 'sales';
            if ($plan === 'pro')      $plan = 'master';
            $freeUsed = (int)($row['free_summaries_used'] ?? 0);
            $usageSec = (int)($row['usage_seconds_period'] ?? 0);
            $limitMinDb = isset($row['summary_limit_minutes']) ? (int)$row['summary_limit_minutes'] : null;
        }
    } catch (Throwable $e) {
        error_log('[build_plan_info] SELECT fail owner=' . $ownerEmail . ' err=' . $e->getMessage());
    }
    $requiresSubscription = ($plan === 'free' && !$isAdminUser);
    if (!$rowFound) {
        error_log('[build_plan_info] member row not found owner=' . $ownerEmail . ' isAdmin=' . ($isAdminUser ? '1' : '0'));
    }
    // 분 단위 한도 — DB 값 우선, 없으면 plan default.
    $limitMin = $limitMinDb;
    if ($limitMin === null) {
        require_once __DIR__ . '/billing_helpers.php';
        $limitMin = plan_default_summary_limit_minutes($plan);
    }
    $usedMin = (int)round($usageSec / 60);
    $remainingMin = ($limitMin === null) ? null : max(0, $limitMin - $usedMin);
    return [
        'plan' => $plan,
        'free_summaries_used' => $freeUsed,
        'free_quota' => customer_log_free_quota(),
        'requires_subscription' => $requiresSubscription,
        'minutes_used' => $usedMin,
        'minutes_limit' => $limitMin,         // null = 무제한 (admin override 등)
        'minutes_remaining' => $remainingMin, // null = 무제한
    ];
}

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
            // 사장님 2026-05-25 — 5회 무료체험 폐지: default 'free'. AI 통화 요약은 유료 플랜.
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `plan` VARCHAR(16) NOT NULL DEFAULT 'free'");
        }
        if (!in_array('free_summaries_used', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `free_summaries_used` INT NOT NULL DEFAULT 0");
        }
        // 신규 컬럼 (구독 결제 시스템 — PortOne + 토스페이먼츠)
        // 사장님 2026-05-25 — 5회 무료체험 폐지: default 'active' (free 도 'active').
        if (!in_array('plan_status', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `plan_status` VARCHAR(16) NOT NULL DEFAULT 'active'");
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
        // summary_limit: NULL = 무제한 (pro), 0 = 차단 (free), 20 = plus. 2026-05-25 trialing 폐지.
        if (!in_array('summary_limit', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `summary_limit` INT NULL DEFAULT 0");
        }
        // last_usage_reset_at: 매월 결제 갱신 시 free_summaries_used=0 으로 reset 한 시점
        if (!in_array('last_usage_reset_at', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `last_usage_reset_at` DATETIME NULL DEFAULT NULL");
        }
        // 사장님 2026-05-26 — 신규 요금제 (sales/master/agency) 로 전환.
        // 옛 plan key (plus/pro/premium/trialing) 자동 마이그레이션. idempotent.
        try {
            $pdo->exec("UPDATE `members` SET `plan` = 'sales'  WHERE `plan` = 'plus'");
            $pdo->exec("UPDATE `members` SET `plan` = 'master' WHERE `plan` = 'pro'");
            $pdo->exec("UPDATE `members` SET `plan` = 'sales'  WHERE `plan` = 'premium'");
            $pdo->exec("UPDATE `members` SET `plan` = 'free'   WHERE `plan` = 'trialing'");
            $pdo->exec("UPDATE `members` SET `plan_status` = 'active' WHERE `plan_status` = 'trialing'");
        } catch (Throwable $e) {
            error_log('[process-recording] plan migration: ' . $e->getMessage());
        }
        return $done = true;
    } catch (Throwable $e) {
        error_log('[process-recording] ensure_members_plan_columns failed: ' . $e->getMessage());
        return $done = false;
    }
}

/**
 * plan 별 월 사용 한도 결정 (회 단위 — 레거시. 신규 흐름은 분 단위만 사용).
 * - 사용자의 summary_limit 컬럼이 명시되어 있으면 그 값 (관리자 수동 override 가능).
 * - 그렇지 않으면 plan 별 기본값:
 *     agency / master → null (무제한)
 *     sales           → 20
 *     free            → 0  (차단)
 * 사장님 2026-05-26 — sales/master/agency 신규 요금제. 옛 plus/pro/premium/trialing 호환.
 */
function resolve_summary_limit(?string $plan, $columnValue): ?int {
    // 컬럼 명시값 우선 (관리자가 admin 에서 직접 변경한 경우)
    if ($columnValue !== null && $columnValue !== '') {
        $n = (int)$columnValue;
        if ($n < 0) return null;   // 음수 = 무제한 (admin 안전망)
        return $n;
    }
    switch (strtolower((string)$plan)) {
        case 'agency':
        case 'master':
        case 'pro':       return null;  // 무제한 (옛 pro = master)
        case 'sales':
        case 'plus':
        case 'premium':   return 20;    // 옛 plus/premium = sales
        case 'trialing':                // 옛 가입자 호환 — free 와 동일.
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
                status VARCHAR(20) NOT NULL DEFAULT 'queued',
                storage_path VARCHAR(512) NULL DEFAULT NULL,
                client_request_id VARCHAR(64) NULL DEFAULT NULL,
                audio_sha256 CHAR(64) NULL DEFAULT NULL,
                duration_sec INT NOT NULL DEFAULT 0,
                customer_name_hint VARCHAR(80) NULL DEFAULT NULL,
                phone_number VARCHAR(60) NULL DEFAULT NULL,
                recorded_at DATETIME NULL DEFAULT NULL,
                retry_count INT NOT NULL DEFAULT 0,
                error_message TEXT NULL DEFAULT NULL,
                fcm_sent_at DATETIME NULL DEFAULT NULL,
                started_at DATETIME NULL DEFAULT NULL,
                completed_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rj_owner_status (owner_email, status),
                INDEX idx_rj_status_created (status, created_at),
                INDEX idx_rj_sha256 (owner_email, audio_sha256),
                UNIQUE KEY uniq_rj_idempotency (owner_email, client_request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Path B lazy ALTER — 기존 테이블에 신규 컬럼 추가
        $cols = [];
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM recording_jobs")->fetchAll() as $c) $cols[] = $c['Field'];
        } catch (Throwable $e) {}
        $needAlter = [
            'audio_sha256'         => 'CHAR(64) NULL DEFAULT NULL',
            'duration_sec'         => 'INT NOT NULL DEFAULT 0',
            'customer_name_hint'   => 'VARCHAR(80) NULL DEFAULT NULL',
            'phone_number'         => 'VARCHAR(60) NULL DEFAULT NULL',
            'recorded_at'          => 'DATETIME NULL DEFAULT NULL',
            'retry_count'          => 'INT NOT NULL DEFAULT 0',
            // 속도 개선 (2026-05-20 ChatGPT 권장): 중간 결과 저장 + 진행률 표시
            'transcript_encrypted' => 'LONGTEXT NULL DEFAULT NULL',
            'summary_json_encrypted' => 'LONGTEXT NULL DEFAULT NULL',
            'progress_pct'         => 'TINYINT NOT NULL DEFAULT 0',
            // 앱팀 옵션 b — 그룹 자동 전송용 컨테이너 (앱이 보내면 FCM payload 에 포함됨)
            'group_id'             => 'VARCHAR(36) NULL DEFAULT NULL',
            // 앱팀 2026-05-20 요청 — review_required = 1 이면 사용자 검토 후 saved 로 전환 (customer_log 자동 INSERT 안 함)
            'review_required'      => 'TINYINT(1) NOT NULL DEFAULT 0',
            // 사장님 2026-05-22 — §7 placeholder 응답 시간 진단용 (요약보기 race 분석)
            'response_elapsed_ms'  => 'INT NULL DEFAULT NULL',
            // 사장님 2026-05-23 — "양식으로 전송" 자동 confirm. trigger_summarize 시 1 설정 → callback 이 ready_to_review 대신 customer_log INSERT + send_to_group 자동 실행.
            'auto_confirm'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        ];
        foreach ($needAlter as $col => $def) {
            if (!empty($cols) && !in_array($col, $cols, true)) {
                try { $pdo->exec("ALTER TABLE recording_jobs ADD COLUMN `{$col}` {$def}"); }
                catch (Throwable $e) { error_log('[process-recording] ALTER ' . $col . ': ' . $e->getMessage()); }
            }
        }
        // status 컬럼 길이 16→20 으로 확장 (failed_retryable / failed_permanent / ready_to_review 등)
        if (!empty($cols) && in_array('status', $cols, true)) {
            try { $pdo->exec("ALTER TABLE recording_jobs MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'queued'"); }
            catch (Throwable $e) {}
        }
        // audio_sha256 인덱스 추가 (이미 있으면 무시)
        try { $pdo->exec("CREATE INDEX idx_rj_sha256 ON recording_jobs (owner_email, audio_sha256)"); }
        catch (Throwable $e) {}
        return $done = true;
    } catch (Throwable $e) {
        error_log('[process-recording] ensure_recording_jobs_table failed: ' . $e->getMessage());
        return $done = false;
    }
}

/**
 * placeholder-first 즉시 응답 — fastcgi_finish_request 로 client 연결 종료 후 백그라운드 계속.
 * 사장님 2026-05-21 §7 — sync + async 통합. customer_log placeholder 응답에 포함.
 */
function respond_async_queued(string $jobId, ?array $customerLogRow = null, $mirrorResult = null, ?array $planInfo = null): void {
    http_response_code(200);   // 사장님 §7 — 202 대신 200 (sync 호환)
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    // 사장님 2026-05-22 — 요약보기 5초 무반응 진단. server_elapsed_ms 로 영맨 측
    // 처리시간 노출하여 RN 측 race vs 영맨 측 느림 분기.
    $_serverElapsedMs = isset($_SERVER['REQUEST_TIME_FLOAT'])
        ? (int)round((microtime(true) - (float)$_SERVER['REQUEST_TIME_FLOAT']) * 1000)
        : null;
    // 사장님 2026-05-23 — lazy-STT 모드. job_status='audio_pending', customer_log 미생성.
    // 사용자 액션 (trigger_summarize) 시점에 Railway dispatch.
    $resp = [
        'status' => 'ok',
        'ok' => true,
        'job_id' => $jobId,
        'job_status' => 'audio_pending',
        'mode'   => 'audio_pending',
        'placeholder' => false,
        'server_elapsed_ms' => $_serverElapsedMs,
    ];
    if ($customerLogRow !== null) {
        $resp['customer_log'] = customer_log_row($customerLogRow);
    }
    if (is_array($mirrorResult)) {
        $resp['ledger_record'] = $mirrorResult['ledger_record'] ?? null;
        $resp['group'] = $mirrorResult['group'] ?? null;
    }
    if (is_array($planInfo)) {
        $resp['plan'] = $planInfo;
    }
    error_log('[process-recording §7 timing] job=' . $jobId . ' server_elapsed_ms=' . ($_serverElapsedMs ?? 'null'));
    echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    ignore_user_abort(true);
    @set_time_limit(300);   // 5분 — 백그라운드 처리 한도
}

/**
 * recording_jobs status + progress 업데이트 (속도 개선, 2026-05-20).
 * 앱이 /job-status.php polling 으로 진행률 표시. 실패 시 무시 (조용히).
 */
function update_job_status(?PDO $pdo, ?string $jobId, string $status, int $progressPct, ?string $errorMessage = null): void {
    if (!$pdo || !$jobId) return;
    try {
        $sql = "UPDATE recording_jobs SET status = :st, progress_pct = :pp, updated_at = NOW()";
        $params = [':st' => $status, ':pp' => $progressPct, ':id' => $jobId];
        if ($errorMessage !== null) {
            $sql .= ", error_message = :em";
            $params[':em'] = substr($errorMessage, 0, 1000);
        }
        $sql .= " WHERE id = :id";
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $e) { /* 무시 — 진행률은 best-effort */ }
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

/* ========== 인증 & PDO ==========
 * Path B (2026-05-20): cron worker 가 X-Internal-Worker-Token 헤더로 internal 호출하면
 * user access_token 검증 skip + body 의 _internal_owner_email 사용. AI 작업 lifecycle 을
 * 사용자 토큰 lifecycle 과 분리하기 위함. */
$internalWorkerToken = trim((string)($_SERVER['HTTP_X_INTERNAL_WORKER_TOKEN'] ?? ''));
$isInternalWorker = false;
if ($internalWorkerToken !== '') {
    $expectedWorker = load_env_value('RECORDING_WORKER_TOKEN');
    if ($expectedWorker !== '' && hash_equals($expectedWorker, $internalWorkerToken)) {
        $isInternalWorker = true;
    } else {
        jerror('unauthenticated', 'Invalid worker token.', 401);
    }
}

if ($isInternalWorker) {
    // body 에서 owner_email 받음. 사전에 body 파싱이 필요한데, 아래에서 다시 파싱하므로 미리 한 번 더.
    $rawBodyEarly = file_get_contents('php://input');
    $bodyEarly = json_decode((string)$rawBodyEarly, true);
    $internalOwner = trim((string)($bodyEarly['_internal_owner_email'] ?? ''));
    if ($internalOwner === '') jerror('invalid_request', 'internal owner_email 누락.', 400);
    $ownerEmail = strtolower($internalOwner);
} else {
    $ownerEmail = require_auth_email();
}

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
$GLOBALS['__ym_pdo'] = $pdo;  // fatal handler / jerror 진단 기록용

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
$groupIdHint  = trim((string)($body['group_id'] ?? ''));  // 앱이 보내면 recording_jobs 에 저장 + FCM payload 에 포함
// 앱이 폰 contacts lookup 결과로 매칭한 이름. 있으면 LLM 출력보다 우선 적용 (룰 §1).
$customerNameHint = trim((string)($body['customer_name_hint'] ?? ''));
if (mb_strlen($customerNameHint) > 80) $customerNameHint = mb_substr($customerNameHint, 0, 80);

// 앱팀 2026-05-26 v73+ — Play Store 정책 준수로 READ_CONTACTS 권한 제거.
// client 는 customer_name_hint 항상 null 전송 → server 측에서 phone_number 로 customer_log lookup.
// 우선순위:
//   1. customer_name_hint (legacy client 호환 — v73 미만)
//   2. 같은 owner_email + 같은 phone_number 의 옛 customer_log.customer_name (가장 최근)
//   3. null (AI 가 transcript 에서 자유 추출)
// "고객" / "(처리 중)" 같은 placeholder 는 제외.
if ($customerNameHint === '' && $phoneNumber !== '') {
    try {
        $lookupKeyHint = customer_phone_lookup_key($phoneNumber);
        if ($lookupKeyHint !== null) {
            $hintStmt = $pdo->prepare("
                SELECT customer_name
                FROM customer_log
                WHERE owner_email = :o
                  AND customer_phone_lookup = :pl
                  AND customer_name IS NOT NULL
                  AND customer_name != ''
                ORDER BY ai_generated_at DESC, created_at DESC
                LIMIT 5
            ");
            $hintStmt->execute([':o' => $ownerEmail, ':pl' => $lookupKeyHint]);
            $skipNames = ['고객', '(처리 중)', '(처리중)', ''];
            while ($r = $hintStmt->fetch()) {
                $decoded = trim((string)(youngman_decrypt($r['customer_name'] ?? '') ?? ''));
                if ($decoded !== '' && !in_array($decoded, $skipNames, true)) {
                    $customerNameHint = $decoded;
                    if (mb_strlen($customerNameHint) > 80) $customerNameHint = mb_substr($customerNameHint, 0, 80);
                    error_log('[process-recording] name resolved from history — owner=' . $ownerEmail
                            . ' phone_hash=' . substr($lookupKeyHint, 0, 8)
                            . ' name=' . substr($decoded, 0, 20));
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[process-recording] customer_name history lookup failed: ' . $e->getMessage());
    }
}

// 사장님 2026-05-21 §7 — placeholder-first 통합. sync 모드 폐기.
// 모든 호출이 placeholder customer_log INSERT + 즉시 응답 + background STT/LLM + UPDATE.
// cafe24 gateway 30s timeout 504 사례 해결 + 사용자 체감 응답 시간 7~60s → 1~2s.
// body.mode='async' 호환 — 응답에 customer_log placeholder 포함하여 native sync 가정과도 호환.
$asyncMode = true;
$_originalModeRequested = strtolower(trim((string)($body['mode'] ?? '')));   // 진단용 — sync/async/누락 어느 것이었는지 로그

if ($storagePath === '') jerror('invalid_audio', 'storage_path 누락.', 400);
if ($clientReqId === '') jerror('invalid_audio', 'client_request_id 누락.', 400);
if (strlen($clientReqId) > 64) {
    // 사장님 2026-05-25 — ARS 자동안내 통화 등 native 가 긴 ID (파일명 기반 한글 UTF-8) 보내는 케이스.
    // SHA-256 hash (정확히 64 hex) 로 자동 대체. 같은 입력 = 같은 hash → idempotency 보장.
    $clientReqId = hash('sha256', $clientReqId);
}

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
    $isAdminUserDup = is_admin_email_for_recording($ownerEmail);
    jout([
        'ok' => true,
        'duplicate' => true,
        'error_code' => 'JOB_EXISTS',
        'status' => 'saved',
        'message' => '이미 처리 완료된 작업입니다.',
        'customer_log' => customer_log_row($existing),
        'plan' => build_plan_info_for_response($pdo, $ownerEmail, $isAdminUserDup),
    ]);
}

/* ========== 사장님 2026-05-25 — v60 client 옵션 A ==========
 * 무료 사용자 (plan='free' + non-admin) request 받으면:
 *   1. audio 파일 drop (storage_path unlink — best-effort)
 *   2. recording_jobs INSERT skip (audio_pending row 생성 안 함)
 *   3. 응답에 plan.requires_subscription=true 만
 * → 사장님 미확인 요약 페이지에 무료 사용자 row 노출 안 됨 (사장님 정책 충족)
 * client v60 명세: trigger_summarize 호출 X + outbox dismissed 마감.
 *
 * 매 호출마다 build_plan_info_for_response 가 DB SELECT → 결제 즉시 반영 보장.
 */
$isAdminUserEarly = is_admin_email_for_recording($ownerEmail);
$planInfoEarly = build_plan_info_for_response($pdo, $ownerEmail, $isAdminUserEarly);
if (!empty($planInfoEarly['requires_subscription'])) {
    // audio 파일 drop — best-effort. storage_path 가 webroot 기준 상대 또는 절대.
    try {
        $sp = trim((string)$storagePath);
        if ($sp !== '') {
            $candidates = [$sp];
            if ($sp[0] !== '/') {
                // webroot 기준 상대 경로일 수도 — script dir 기준 시도.
                $candidates[] = __DIR__ . '/' . $sp;
                $candidates[] = dirname(__DIR__) . '/' . $sp;
            }
            foreach ($candidates as $p) {
                if (is_file($p)) { @unlink($p); break; }
            }
        }
    } catch (Throwable $e) {}
    error_log('[process-recording] free user audio dropped — owner=' . $ownerEmail
            . ' cri=' . substr($clientReqId, 0, 40)
            . ' plan=' . ($planInfoEarly['plan'] ?? '?'));
    jout([
        'status' => 'ok',
        'ok' => true,
        'job_id' => null,
        'job_status' => 'subscription_required',
        'mode' => 'subscription_required',
        'message' => 'AI 통화 요약은 Plus 또는 Pro 구독부터 사용 가능합니다.',
        'plan' => $planInfoEarly,
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
        $jStat = (string)$existingJob['status'];
        // 처리 완료 (saved/ready_to_review/completed) 면 JOB_EXISTS, 아직 처리 중이면 JOB_DUPLICATE.
        $existsErr = in_array($jStat, ['completed', 'saved', 'ready_to_review'], true) ? 'JOB_EXISTS' : 'JOB_DUPLICATE';
        $msg = in_array($jStat, ['completed', 'saved'], true) ? '이미 처리 완료된 작업입니다.'
             : ($jStat === 'ready_to_review' ? '검토 대기 중인 작업입니다.'
             : ($jStat === 'audio_pending' ? '음성파일 저장됨 — 미확인 요약에서 검토 가능합니다.'
             : '이미 처리 중인 작업입니다.'));
        jout([
            'ok' => true,
            'duplicate' => true,
            'error_code' => $existsErr,
            'job_id' => (string)$existingJob['id'],
            'status' => $jStat,          // 앱팀 2차 요청 — 실제 job_status 통일.
            'job_status' => $jStat,      // backwards compat.
            'message' => $msg,
            'customer_log_id' => $existingJob['customer_log_id'] ?? null,
        ], 200);
    }
    // Path B audio_sha256 idempotency — 같은 파일(content hash) 은 영구 dedup (앱팀 2026-05-20 요청).
    // client_request_id 가 달라도 파일이 같으면 차단. 앱 outbox 재시도 안전망.
    // client_request_id 24h 제한은 유지 — 같은 ID 재사용 케이스에 한해서.
    $absForHash = __DIR__ . '/' . $storagePath;
    $realForHash = @realpath($absForHash);
    $audioSha256 = null;
    if ($realForHash && is_file($realForHash)) {
        try { $audioSha256 = hash_file('sha256', $realForHash) ?: null; } catch (Throwable $e) { $audioSha256 = null; }
    }
    if ($audioSha256) {
        $shaIdem = $pdo->prepare("SELECT * FROM recording_jobs
            WHERE owner_email = :o AND audio_sha256 = :h
            ORDER BY created_at DESC LIMIT 1");
        $shaIdem->execute([':o' => $ownerEmail, ':h' => $audioSha256]);
        $shaJob = $shaIdem->fetch();
        if ($shaJob) {
            $jStat = (string)$shaJob['status'];
            $existsErr = in_array($jStat, ['completed', 'saved', 'ready_to_review'], true) ? 'JOB_EXISTS' : 'JOB_DUPLICATE';
            $msg = in_array($jStat, ['completed', 'saved'], true) ? '이미 처리 완료된 작업입니다.'
                 : ($jStat === 'ready_to_review' ? '검토 대기 중인 작업입니다.'
             : ($jStat === 'audio_pending' ? '음성파일 저장됨 — 미확인 요약에서 검토 가능합니다.'
             : '이미 처리 중인 작업입니다.'));
            jout([
                'ok' => true,
                'duplicate' => true,
                'duplicate_reason' => 'audio_sha256',
                'error_code' => $existsErr,
                'job_id' => (string)$shaJob['id'],
                'status' => $jStat,
                'job_status' => $jStat,
                'message' => $msg,
                'customer_log_id' => $shaJob['customer_log_id'] ?? null,
            ], 200);
        }
    }
    // 사장님 2026-05-23 — lazy-STT 모드 부활. 통화 종료 시 audio 만 보존 (status='audio_pending').
    // STT/LLM 은 사용자가 모달 "요약보기" 또는 미확인요약 페이지에서 trigger_summarize 호출 시점에만 진행.
    // → customer_log placeholder INSERT 안 함 / send_to_group mirror 안 함 / Railway dispatch 안 함.
    $reviewRequiredInt = 1;

    // 새 job 생성. 사용자 token 검증은 이미 끝났으므로 cron worker 가 server secret 으로 처리할 수 있음.
    $asyncJobId = uuid_v4();
    $insJob = $pdo->prepare("INSERT INTO recording_jobs
        (id, owner_email, status, storage_path, client_request_id,
         audio_sha256, duration_sec, customer_name_hint, phone_number, recorded_at, group_id, review_required)
        VALUES (:id, :o, 'audio_pending', :sp, :k,
                :sha, :dur, :hint, :ph, :ra, :gid, :rr)");
    $insJob->execute([
        ':id'  => $asyncJobId,
        ':o'   => $ownerEmail,
        ':sp'  => $storagePath,
        ':k'   => $clientReqId,
        ':sha' => $audioSha256,
        ':dur' => (int)$durationSec,
        ':hint' => $customerNameHint !== '' ? $customerNameHint : null,
        ':ph'  => $phoneNumber !== '' ? $phoneNumber : null,
        ':ra'  => $consultAt !== '' ? $consultAt : null,
        ':gid' => $groupIdHint !== '' ? $groupIdHint : null,
        ':rr'  => $reviewRequiredInt,
    ]);

    // 사장님 2026-05-23 — lazy-STT 모드. placeholder customer_log INSERT / ledger mirror 안 함.
    // 응답에 null 로 내려 native 앱은 audio_pending 상태로 모달 표시 후 사용자 액션 대기.
    $placeholderClRow = null;
    $placeholderMirrorResult = null;

    // 즉시 응답 — client 연결 종료. 이후 코드는 백그라운드.
    // 사장님 §7 — customer_log placeholder + ledger mirror 응답에 포함 (native sync 가정 호환).
    // 사장님 2026-05-22 — 응답 직전 elapsed_ms 를 recording_jobs 에 기록 (요약보기 race 진단)
    try {
        $_elapsedBefore = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (int)round((microtime(true) - (float)$_SERVER['REQUEST_TIME_FLOAT']) * 1000)
            : null;
        if ($_elapsedBefore !== null) {
            $pdo->prepare("UPDATE recording_jobs SET response_elapsed_ms = :e WHERE id = :id")
                ->execute([':e' => $_elapsedBefore, ':id' => $asyncJobId]);
        }
    } catch (Throwable $e) {}
    // 사장님 2026-05-25 — async 응답에 plan 정보 포함 (native A 모달 분기용).
    $isAdminUserAsync = is_admin_email_for_recording($ownerEmail);
    $planInfoAsync = build_plan_info_for_response($pdo, $ownerEmail, $isAdminUserAsync);
    respond_async_queued($asyncJobId, $placeholderClRow, $placeholderMirrorResult, $planInfoAsync);

    // 사장님 2026-06-01 — 자동 dispatch 제거. STT 처리는 사용자 mark_usage 호출 시점에만 시작.
    // Why: 사장님 룰 = 사용자 클릭 안 하면 STT 처리 X (껍데기만 미확인 요약에 저장). 자동 dispatch 시
    //      비클릭 통화도 STT 처리되어 미확인 요약에 요약된 카드 표시되던 문제 fix.
    // 흐름: audio_pending 으로만 저장. records.php mark_usage 가 호출되면 status='queued' + Railway dispatch.
    exit;
}

/* ========== Plan check ==========
 * 구독 plan 별 quota (사장님 2026-05-28 — VAT 별도 정책):
 *   agency  → 월 1,500분 (₩89,000 + VAT = 결제 ₩97,900)
 *   master  → 월   700분 (₩47,000 + VAT = 결제 ₩51,700)
 *   sales   → 월   300분 (₩24,000 + VAT = 결제 ₩26,400)
 *   free    → 0분 (차단) — AI 요약은 유료 plan 전용.
 * + plan_status 검사 — past_due / cancelled 면 차단 (active 만 통과).
 * + admin allowlist 는 모든 검사 우회.
 */
$plan = 'free';
$planStatus = 'active';
$freeUsed = 0;
$summaryLimitColumn = null;
$summaryLimitMinutes = null;   // 분 한도 (NULL 이면 회 단위 레거시 흐름 사용)
$usageSecondsPeriod = 0;       // 이번달 누적 초
$overageEnabled = 0;
$overageBalanceSeconds = 0;
// 사장님 2026-05-26 — 사용량 이월 금지 안전망.
// cron-renew 가 잡지 못하는 사용자 (free / admin 수동 plan / 결제 미연동) 도
// last_usage_reset_at 기준 30일 경과 시 usage_seconds_period 자동 0 reset.
// 정상 결제 흐름은 verify-payment / cron-renew 가 reset — 이건 보조 안전망.
try {
    $pdo->prepare("UPDATE members SET last_usage_reset_at = NOW()
                   WHERE email = :e AND last_usage_reset_at IS NULL")
        ->execute([':e' => $ownerEmail]);
    $pdo->prepare("UPDATE members SET
                       usage_seconds_period = 0,
                       free_summaries_used = 0,
                       last_usage_warning_pct = 0,
                       last_usage_reset_at = NOW()
                   WHERE email = :e
                     AND last_usage_reset_at IS NOT NULL
                     AND last_usage_reset_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")
        ->execute([':e' => $ownerEmail]);
} catch (Throwable $e) {
    error_log('[process-recording] lazy usage reset: ' . $e->getMessage());
}

try {
    $ps = $pdo->prepare('SELECT plan, plan_status, free_summaries_used, summary_limit,
                                summary_limit_minutes, usage_seconds_period,
                                overage_enabled, overage_balance_seconds
                         FROM members WHERE email = :e LIMIT 1');
    $ps->execute([':e' => $ownerEmail]);
    $row = $ps->fetch();
    if ($row) {
        $plan = (string)($row['plan'] ?? 'free');
        // 옛 plan key 호환 — 정상 흐름은 ensure_members_plan_columns 가 이미 마이그레이션함.
        if ($plan === 'trialing') $plan = 'free';
        if ($plan === 'plus')     $plan = 'sales';
        if ($plan === 'premium')  $plan = 'sales';
        if ($plan === 'pro')      $plan = 'master';
        $planStatus = (string)($row['plan_status'] ?? 'active');
        if ($planStatus === 'trialing') $planStatus = 'active';
        $freeUsed = (int)($row['free_summaries_used'] ?? 0);
        $summaryLimitColumn = $row['summary_limit'] ?? null;
        $summaryLimitMinutes = isset($row['summary_limit_minutes']) ? (int)$row['summary_limit_minutes'] : null;
        $usageSecondsPeriod = (int)($row['usage_seconds_period'] ?? 0);
        $overageEnabled = (int)($row['overage_enabled'] ?? 0);
        $overageBalanceSeconds = (int)($row['overage_balance_seconds'] ?? 0);
    }
} catch (Throwable $e) {
    // 컬럼이 아직 없으면 free 로 간주 (안전).
    $plan = 'free';
    $planStatus = 'active';
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
                    jerror('plan_required', 'AI 통화 요약은 유료 플랜에서 사용 가능합니다. Sales / Master / Agency 구독을 시작해 주세요.', 403);
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
                jerror('plan_required', '무료 플랜은 통화 AI 요약을 사용할 수 없습니다. Sales / Master / Agency 구독을 시작해 주세요.', 403);
            } else {
                jerror('plan_required', '이번 달 한도를 모두 사용했습니다. 다음 결제일까지 기다리거나 상위 플랜으로 업그레이드해 주세요.', 403);
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
 * 속도 개선 (2026-05-20): status='stt_processing' / progress=30% UPDATE — 앱 polling 진행률 표시. */
update_job_status($pdo, $asyncJobId, 'stt_processing', 30);

/* STT_PROVIDER 환경변수로 토글:
 *   - 'clova' (기본): Naver CLOVA Speech LSR — 화자분리 포함, 회당 ~180원
 *   - 'whisper': OpenAI Whisper API — 화자분리 없음, 회당 ~50원 (-72%)
 *
 * Whisper 사용 시 화자 라벨이 없으므로 LLM system prompt 가 평문도 처리 가능해야 함
 * (아래 ====== STT_PROVIDER 평문 케이스 ====== 블록 참조).
 */
$apiKey = load_env_value('OPENAI_API_KEY');
if ($apiKey === '') jerror('upstream_failed', 'OPENAI_API_KEY 미설정.', 500);

// 사장님 2026-05-22 — cafe24 자체 STT 흐름은 Railway dispatch 실패 시 fallback 안전망.
// cafe24 에 ffmpeg 미설치 → Whisper 가 m4a (Apple AAC 변종) 거부 ("502: Whisper 400 Invalid file").
// 안전망에서는 STT_PROVIDER='whisper' 무시하고 Clova 강제 — Clova 가 m4a 직접 수용.
// 정상 흐름 (Railway) 은 Whisper + transcode_to_mp3 으로 사장님 결정 "Whisper 유지" 충족.
$sttProviderRequested = 'clova';
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
 * 속도 개선 (2026-05-20): status='llm_processing' / progress=70% + transcript 임시 저장 — 앱 polling 진행률. */
if ($asyncJobId && $transcript !== '') {
    try {
        $pdo->prepare("UPDATE recording_jobs SET
                transcript_encrypted = :tr,
                status = 'llm_processing',
                progress_pct = 70,
                updated_at = NOW()
            WHERE id = :id")
            ->execute([':tr' => youngman_encrypt($transcript), ':id' => $asyncJobId]);
    } catch (Throwable $e) { /* 무시 */ }
}

/* LLM_PROVIDER 환경변수로 토글:
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

**화자별 줄바꿈**: 화자가 바뀌는 시점마다 빈 줄(`\\n\\n`)로 단락 구분.
- 한 명이 연속으로 말한 부분은 같은 단락
- 화자가 바뀌면 새 단락 (빈 줄 한 개)
- "나:" / "고객:" 같은 접두어는 사용 X (자연스러운 서술 안에서 누가 한 행동인지 명시)

예시:
지난번 발송 자료 검토 결과 평수가 작다는 피드백 받음. 사모님과 상의 후 35평 이상으로 조건 재설정 요청.

9억대 후보 두세 건 정리해 내일 오전 카톡 발송 약속함. 통화 분위기 우호적이고 다음 단계로 자연스럽게 진행됨.

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

**summary 마지막 줄에 'AI 의견:' 한 줄 필수 — 해당 고객 대응 추천 (영업 전문가 시각)**:
  - 마지막 단락 뒤 빈 줄 한 개 후 "AI 의견: ..." 형식으로 추가
  - **아래 '영업 전문가 지식' 섹션의 framework 활용해서 전문가급 대응 전략 추천**
  - 포함할 요소:
    · [고객 유형] 5분류 중 어디 해당 (근거 transcript 단서)
    · [BANT 진단] 4축 중 강한/약한 부분
    · [추천 접근] 다음 통화 전략 — 어느 framework / 어느 심리 원칙 활용, 어느 자료 준비, 어느 톤
    · [피해야 할 것] 압박 시점/방식 / 무시할 거절 종류 등
  - 2~3문장 가능. 단정적 추측 금지 (transcript 단서 기반 추론).
  - 업종 무관 (부동산/보험/자동차/일반 자영업 등 모두 적용 가능한 범용 framework 사용).

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
- **출력 형식 (절대 규칙)**:
  · 정확히 JSON 객체 1개만 출력
  · markdown code block (```json) 금지
  · JSON 외 어떤 텍스트도 포함 금지 (앞뒤 설명, 주석, "다음은 결과입니다" 같은 도입문 모두 금지)
  · 출력은 '{' 로 시작해서 '}' 로 끝나야 함
  · JSON 안의 string value 에 줄바꿈은 \\n 으로 표시 (raw newline 금지)

==== 영업 전문가 지식 (AI 의견 작성용 framework — 업종 무관 범용) ====

영맨 사용자는 부동산뿐 아니라 보험설계사, 자동차/중고차 딜러, 일반 자영업자 등
모든 영업 직군. 아래 framework 는 업종 무관 범용 — transcript 안에서 단서 보고 활용.

[1] 핵심 영업 framework

  · SPIN Selling (Neil Rackham) — needs 도출 4단계
    S(Situation): 현재 상황 파악
    P(Problem): 문제 인식
    I(Implication): 문제의 파급 / 비용
    N(Need-payoff): 해결책의 가치
    → transcript 에서 고객이 어느 단계까지 와있는지 진단.

  · BANT 평가 — 영업 우선순위
    B(Budget) 예산 명시도 / A(Authority) 의사결정 권한 / N(Need) 진짜 필요 / T(Timeline) 시기
    → 4개 충족 = 핫리드, 1~2개 = 일반, 0개 = 정보 수집 단계.

  · Sandler Pain Funnel — 표면 통증 → 본질 통증
    "왜?" 5번 반복으로 진짜 문제 도출. (예: 비싸다 → 왜? → 가족 반대 → 왜? → 시아버지 우려 → ...)

  · Challenger Sale — Teach/Tailor/Take Control
    고객이 모르는 인사이트 제공 → 고객 니즈에 맞춤 → 영업 페이스 주도.

  · Solution Selling — 제품이 아니라 솔루션 판매
    "이 차/보험/물건" 이 아니라 "이 고민을 해결하는 도구" 로 frame.

[2] 영업 심리학 핵심 (Cialdini + Kahneman)

  · Reciprocity 보답 의무 — 무료 자료/정보 먼저 제공 → 응답 의무감
  · Commitment & Consistency — 작은 동의부터 ("자료 받아보시겠어요?" YES → 큰 동의 쉬워짐)
  · Social Proof 사회적 증거 — 다른 고객 사례 인용 ("이번에 OO 고객도...")
  · Authority 전문성 — 시장 데이터 / 정책 변화 / 전문 용어 적절히 사용
  · Liking 호감 — 공감 / 유머 / 공통점 (자녀, 관심사, 지역, 학교)
  · Scarcity 희소성 — 한정 / 마감 / 다른 고객 검토 중

  · Loss Aversion (손실 회피, Kahneman) — 같은 가치라도 손실 frame 이 2배 강함
    "이득" → "놓치면 손실" 로 재구성 (예: "이번 달 놓치면 다음 인상 시점")

  · Anchoring 앵커링 — 첫 숫자가 기준점
    높은 옵션 먼저 보여주고 → 합리적 옵션 제안 (decoy 효과)

  · Endowment Effect 소유 효과 — 시승/시연/체험 시키면 소유감 → 결정 쉬워짐
    (예: 자동차 시승 / 보험 시뮬레이션 / 매물 답방 / 샘플 사용)

  · Foot-in-the-door — 작은 요청 OK → 큰 요청 OK 확률 ↑
  · Door-in-the-face — 큰 요청 거절 → 작은 요청 OK 확률 ↑

[3] 고객 유형 5분류 (업종 무관)

  · 가족/주변 의사결정 의존형
    배우자/부모/동업자 의향 우선. "상의해보겠다" 호언.
    → 의사결정자 같이 만나기 권유. 의사결정자 우려 자료 사전 준비.

  · 가격 민감형
    가격 1순위. 첫 질문이 가격. 할인/할부/비교 강조.
    → 가치 재정의 (TCO 총 비용, 사후관리, 차별점). 가격 압박 X.

  · 빠른 결정형
    구체적 질문 + 즉시 행동. 시간 압박 효과.
    → 희소성 + 명확한 next step + 즉시 견적/계약.

  · 정보 수집형 (비교 단계)
    "다른 곳도 봐야겠다" / "비교 자료 보내달라". 결정 멀음.
    → 차별점 강조 + 비교 우위 자료 + 전문성 노출. Follow-up 길게.

  · 망설임형 (위험 회피)
    "고민해볼게요" 반복. 침묵 많음. 결정 두려움.
    → 작은 commitment 부터 (자료 받기 / 짧은 방문 / 무료 체험).
    Loss Aversion frame 효과적 ("지금 안 잡으면 ...").

[4] 거절 처리 (Objection Handling) — LAARC

  Listen 경청 → Acknowledge 공감 → Ask 질문 → Respond 대응 → Confirm 확인

  · 가격 거절 → 가치 재정의 (TCO, 사후관리, 차별점, 평당/월당 단가)
  · 시간 거절 → 손실 비용 강조 (인상 임박, 학기 시작, 정책 변경)
  · 권한 거절 → 의사결정자 접근 권유 (동반 미팅, 자료 패키지)
  · 비교 거절 → 차별점 강조 (전문성, 사후관리, 보장, 신뢰)
  · 신뢰 거절 → Social Proof + Authority (실적, 자격, 후기)

[5] Closing 시그널 감지

  · 동의 시그널: 구체적 질문 (납기/입주/계약/등기/할부) / 가족 의향 묻기 / 다음 일정 묻기
  · 망설임 시그널: 침묵 / "고민해볼게요" / "다른 곳도 봐야" / 가격 재질문
  · 거절 시그널: 통화 짧게 끝내려 함 / 무관심 응답 / 비교 매물/상품 언급

  Closing 기법:
  · Trial close (의향 떠보기): "이 정도면 적합하실 것 같으세요?"
  · Alternative close (양자택일): "A 와 B 중 어느 게 끌리세요?"
  · Assumptive close: 이미 결정된 듯 진행 ("그럼 다음 주 화요일에 ...")
  · Summary close: 합의된 사항 요약 후 결정 유도
  · Urgency close: 희소성 / 시간 압박 ("이번 주 안에 ...")

[6] Follow-up 타이밍 룰

  · 24h: 첫 자료 발송 + 짧은 메시지
  · 3d: 자료 검토 후 의향 확인 통화
  · 7d: 신규 정보 / 시장 변화 / 관련 뉴스 공유 (관계 유지)
  · 30d: 장기 follow-up (재컨택 시점)
  · Trigger event: 고객 상황 변화 (전세 만기 / 차량 교체 시기 / 보험 만료 / 사업 확장 등)

[7] 업종별 특수성 (해당 transcript 분야 추론 후 적용)

  · 부동산 — 가족 의사결정 빈도 높음 / 학군 / 이사 시기 / 자금 흐름 / 호칭 (사장님/사모님)
  · 자동차/중고차 — 시승 활용 (Endowment) / 옵션 패키지 (Anchoring) / 할부 / 보증
  · 보험 — 가족 보장 frame / 납입 부담 vs 보장 가치 / 약관 복잡성 / 설계사 vs 본사
  · 기타 자영업/B2B — 견적 비교 / 사후 관리 / 거래 지속성 / 의사결정 사이클 길음

[AI 의견 작성 가이드 — 종합 적용]

위 framework 들을 transcript 단서에 맞춰 자연스럽게 활용. "SPIN 의 S 단계", "BANT" 같은
용어를 직접 명시하지 말고 (사용자가 영업 용어 모를 수 있음) 한국어 자연 표현으로:

  ❌ "SPIN 의 Implication 단계 미진입"
  ✅ "고객이 문제 인식은 했지만 그 파급 효과는 아직 체감 못 함"

  ❌ "BANT 의 Authority 약함"
  ✅ "본인 외 가족 의향 우선이라 의사결정 권한 약함"

예시 (자동차 영업):
  AI 의견: 시승 후 망설임형. 가격보다 옵션 선택을 고민 중. 다음 통화 전 옵션별 비교표
  + 비슷한 옵션으로 구매한 다른 고객 후기 준비. 시간 압박 피하고 옵션 가치를 정량화
  (월 사용시간 / 가족 만족도 / 사후관리 비용) 해서 설명이 효과적.

예시 (보험 영업):
  AI 의견: 가족 의사결정 의존형 (배우자 언급). BANT 의 Budget 명확하나 Authority 약함.
  배우자 동반 상담 권유 + 보장 시뮬레이션 (자녀 학자금 / 노후 / 의료비) 자료 준비.
  납입 부담 frame 보다 "보장 가치 손실 회피" frame 강조가 효과적.

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
                // 주의: Claude Sonnet 4.x 는 assistant prefill 미지원 (3.x 만 지원).
                // → prefill 제거. JSON 안정성은 system prompt 의 출력 규칙 + 3단 fallback parsing 으로 처리.
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
    // fallback 1: markdown code block 안의 JSON 추출 (```json ... ```)
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/i', $llmText, $m)) {
        $parsed = json_decode($m[1], true);
    }
}
if (!is_array($parsed)) {
    // fallback 2: 응답 안의 첫 { 부터 매칭되는 } 까지 brace counting 으로 추출
    $start = strpos($llmText, '{');
    if ($start !== false) {
        $depth = 0;
        $end = -1;
        for ($i = $start; $i < strlen($llmText); $i++) {
            if ($llmText[$i] === '{') $depth++;
            elseif ($llmText[$i] === '}') {
                $depth--;
                if ($depth === 0) { $end = $i; break; }
            }
        }
        if ($end > $start) {
            $jsonCandidate = substr($llmText, $start, $end - $start + 1);
            $parsed = json_decode($jsonCandidate, true);
        }
    }
}
if (!is_array($parsed)) {
    /* fallback 3: 앱팀 2026-05-20 2차 요청 — LLM 에 repair 1회 요청. */
    $repairSys = "다음 텍스트를 지정된 JSON schema 에 맞게 유효한 JSON 으로만 변환하세요. 설명 없이 JSON 만 반환하세요.\n"
               . "schema: {\"customer_name\":string, \"summary\":string, \"interest\":string, \"inquiry\":string, \"budget_condition\":string, \"next_action\":string}";
    $repairText = '';
    if ($llmProvider === 'anthropic') {
        $rch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($rch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $llmModel,
                'max_tokens' => 1500,
                'temperature' => 0,
                'system' => $repairSys,
                'messages' => [['role' => 'user', 'content' => $llmText]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $anthropicKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $rResp = curl_exec($rch);
        $rStatus = (int)curl_getinfo($rch, CURLINFO_HTTP_CODE);
        curl_close($rch);
        if ($rResp !== false && $rStatus >= 200 && $rStatus < 300) {
            $rData = json_decode((string)$rResp, true);
            $repairText = (string)($rData['content'][0]['text'] ?? '');
        }
    } else {
        $rch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($rch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $llmModel,
                'messages' => [
                    ['role' => 'system', 'content' => $repairSys],
                    ['role' => 'user', 'content' => $llmText],
                ],
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $rResp = curl_exec($rch);
        $rStatus = (int)curl_getinfo($rch, CURLINFO_HTTP_CODE);
        curl_close($rch);
        if ($rResp !== false && $rStatus >= 200 && $rStatus < 300) {
            $rData = json_decode((string)$rResp, true);
            $repairText = (string)($rData['choices'][0]['message']['content'] ?? '');
        }
    }
    /* repair 응답에 같은 3단 파싱 적용 */
    if ($repairText !== '') {
        $rParsed = json_decode($repairText, true);
        if (!is_array($rParsed) && preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/i', $repairText, $rm)) {
            $rParsed = json_decode($rm[1], true);
        }
        if (!is_array($rParsed)) {
            $rs = strpos($repairText, '{');
            if ($rs !== false) {
                $rd = 0; $re = -1;
                for ($ri = $rs; $ri < strlen($repairText); $ri++) {
                    if ($repairText[$ri] === '{') $rd++;
                    elseif ($repairText[$ri] === '}') { $rd--; if ($rd === 0) { $re = $ri; break; } }
                }
                if ($re > $rs) $rParsed = json_decode(substr($repairText, $rs, $re - $rs + 1), true);
            }
        }
        if (is_array($rParsed)) {
            error_log('[process-recording] LLM JSON repair 성공.');
            $parsed = $rParsed;
        }
    }
}
if (!is_array($parsed)) {
    /* 최종 실패 — recording_jobs failed_retryable + 200 응답 (앱팀 2026-05-20 2차 요청).
     * 502 던지면 앱이 일반 실패 처리 → 무한 재시도/모달 반복 원인. */
    error_log('[process-recording] LLM JSON 파싱 + repair 모두 실패. text 일부: ' . substr($llmText, 0, 500));
    if ($asyncMode && $asyncJobId) {
        // recording_jobs failed_retryable + 오디오 유지 (cron worker 재시도 위해 storage_path 유지).
        try {
            $pdo->prepare("UPDATE recording_jobs SET
                    status = 'failed_retryable',
                    error_message = :em,
                    updated_at = NOW()
                WHERE id = :id")
                ->execute([
                    ':em' => 'LLM_JSON_PARSE_FAILED',
                    ':id' => $asyncJobId,
                ]);
        } catch (Throwable $e) {}
        exit;   // async 모드 — client 응답은 이미 'queued' 로 갔음. polling 으로 status 받음.
    }
    // sync 모드 — 200 + retryable 응답.
    jout([
        'ok' => false,
        'error_code' => 'RETRYABLE_SERVER_ERROR',
        'status' => 'failed_retryable',
        'retryable' => true,
        'message' => 'LLM JSON 파싱 실패 — 자동 재시도 대기.',
        'last_error' => 'LLM_JSON_PARSE_FAILED',
        'http_status' => 200,
    ], 200);
}

$llmName    = isset($parsed['customer_name'])    ? trim((string)$parsed['customer_name'])    : '';
// 룰 §1: 앱이 전달한 contacts hint 가 있으면 LLM 출력보다 우선.
if ($customerNameHint !== '') $llmName = $customerNameHint;
$llmSummary = isset($parsed['summary'])          ? trim((string)$parsed['summary'])          : '';
$llmIntr    = isset($parsed['interest'])         ? trim((string)$parsed['interest'])         : '';
$llmInq     = isset($parsed['inquiry'])          ? trim((string)$parsed['inquiry'])          : '';
$llmBudg    = isset($parsed['budget_condition']) ? trim((string)$parsed['budget_condition']) : '';
$llmNext    = isset($parsed['next_action'])      ? trim((string)$parsed['next_action'])      : '';

if ($llmSummary === '') $llmSummary = $transcript;   // 최후 폴백 — 빈 summary 는 NOT NULL 위반.

/* ========== review_mode 분기 (사장님 2026-05-21 미확인요약 폐기 결정으로 무력화) ==========
 * 기존 review_required=1 흐름은 customer_log INSERT 안 하고 ready_to_review 로
 * 두는 흐름. 사장님이 미확인요약 시스템 자체를 폐기하면서 이 분기 무력화.
 * review_required 값 무관하게 항상 customer_log INSERT 진행 (아래 흐름).
 * 데이터 누락 0 + sync 응답에 customer_log 포함 보장 + async callback INSERT 보장. */
$reviewRequiredFinal = false;

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
    // 사장님 2026-05-21 §7 — placeholder INSERT (앞쪽 코드 블록) 와 client_request_id 충돌이 정상 흐름.
    // 응답은 이미 fastcgi_finish_request 후라 jout/jerror 출력 안 됨. background UPDATE 로 처리.
    if (strpos((string)$e->getMessage(), 'Duplicate') !== false) {
        $dupQ = $pdo->prepare('SELECT id FROM customer_log WHERE owner_email = :o AND client_request_id = :k LIMIT 1');
        $dupQ->execute([':o' => $ownerEmail, ':k' => $clientReqId]);
        $dupRowQ = $dupQ->fetch();
        if ($dupRowQ && !empty($dupRowQ['id'])) {
            $rowId = (string)$dupRowQ['id'];   // placeholder ID 로 변경 (이하 흐름에서 사용)
            try {
                $updClP = $pdo->prepare("UPDATE customer_log SET
                        customer_name = :nm,
                        phone_number = COALESCE(:ph, phone_number),
                        summary = :sum,
                        interest = COALESCE(:intr, interest),
                        inquiry = COALESCE(:inq, inquiry),
                        budget_condition = COALESCE(:bg, budget_condition),
                        next_action = COALESCE(:nx, next_action),
                        transcript = :tr,
                        ai_model = :am,
                        ai_generated_at = NOW(),
                        source = 'app-auto'
                    WHERE id = :id AND owner_email = :o");
                $updClP->execute([
                    ':nm'  => $llmName !== '' ? youngman_encrypt($llmName) : null,
                    ':ph'  => $phoneNumber !== '' ? youngman_encrypt($phoneNumber) : null,
                    ':sum' => youngman_encrypt($llmSummary),
                    ':intr'=> $llmIntr !== '' ? youngman_encrypt($llmIntr) : null,
                    ':inq' => $llmInq !== '' ? youngman_encrypt($llmInq) : null,
                    ':bg'  => $llmBudg !== '' ? youngman_encrypt($llmBudg) : null,
                    ':nx'  => $llmNext !== '' ? youngman_encrypt($llmNext) : null,
                    ':tr'  => youngman_encrypt($transcript),
                    ':am'  => $sttModelName . '+' . $llmModel,
                    ':id'  => $rowId,
                    ':o'   => $ownerEmail,
                ]);
                error_log('[process-recording §7] placeholder customer_log UPDATE: cl=' . $rowId);
            } catch (Throwable $eUpd) {
                error_log('[process-recording §7] placeholder UPDATE 실패: ' . $eUpd->getMessage());
            }
        }
        // background 계속 — auto send_to_group refresh 로 진행
    } else {
        error_log('[process-recording] insert failed: ' . $e->getMessage());
        // fastcgi_finish_request 후라 jerror 출력 안 됨. exit 로 background 종료.
        exit;
    }
}

/* ========== 자동 send_to_group mirror (사장님 2026-05-20) ==========
 * 앱이 통화 후 모달의 "양식에 전송" AutoSubmit 으로 group_id 보내면 자동 mirror.
 * group_id 빈 값이면 default 그룹 자동 (기존 흐름과 동일).
 * X-Worker-Token 헤더 + body.owner_email 로 records.php 의 send_to_group 액션 우회 인증. */
try {
    $sendUrl = 'https://youngman-biz.com/records.php?resource=customer-log';
    $sendPayload = [
        'action'      => 'customer_log_send_to_group',
        'id'          => $rowId,
        'owner_email' => $ownerEmail,
        'refresh'     => true,   // 사장님 §7 — placeholder mirror 가 이미 ledger_record 생성. 여기서 latest section UPDATE.
    ];
    if ($groupIdHint !== '') $sendPayload['group_id'] = (int)$groupIdHint;
    $workerTok = load_env_value('RECORDING_WORKER_TOKEN');
    if ($workerTok !== '') {
        $sCh = curl_init($sendUrl);
        curl_setopt_array($sCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($sendPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Worker-Token: ' . $workerTok,
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $sResp = curl_exec($sCh);
        $sStat = (int)curl_getinfo($sCh, CURLINFO_HTTP_CODE);
        curl_close($sCh);
        error_log('[process-recording] auto send_to_group cl=' . $rowId . ' gid=' . $groupIdHint . ' http=' . $sStat);
    } else {
        error_log('[process-recording] auto send_to_group skip — RECORDING_WORKER_TOKEN 미설정');
    }
} catch (Throwable $e) {
    error_log('[process-recording] auto send_to_group 실패: ' . $e->getMessage());
}

/* ========== 사용량 카운트 ==========
 *   - admin allowlist: 카운트 안 함
 *   - master / agency: 회 단위 카운트 안 함 (무제한 — 분 단위만 차감)
 *   - 그 외 (free / sales): summary 처리 성공 후 +1
 *   - usage_logs 에도 row 추가 (감사 / 통계)
 */
$planLowerForCount = strtolower($plan);
// 회 단위 카운트 (free_summaries_used) — admin/master/agency/pro 제외 (free/sales 만)
if (!$isAdminUser && $planLowerForCount !== 'master' && $planLowerForCount !== 'agency' && $planLowerForCount !== 'pro') {
    try {
        $pdo->prepare('UPDATE members SET free_summaries_used = free_summaries_used + 1 WHERE email = :e')
            ->execute([':e' => $ownerEmail]);
        $freeUsed += 1;
    } catch (Throwable $e) {}
}

// 분 단위 차감 + usage_logs — admin 포함 모두 누적 (사장님 본인 테스트 통계 반영)
// Why: 1) master/agency 가 회 단위 skip 조건에 묶여 분 단위까지 누락되던 버그 fix
//      2) admin (사장님) 본인 테스트도 통계에 반영. 한도 차단은 별도 (line 1024) 에서 admin skip 유지
{
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

            // FCM 사용량 임박/초과 알림 — 80% / 90% / 100% 도달 시 (중복 발송 방지 last_usage_warning_pct)
            $usedMinFinal = (int)round($usageAfter / 60);
            $usagePct = $summaryLimitMinutes > 0 ? (int)floor(($usedMinFinal / $summaryLimitMinutes) * 100) : 0;
            $threshold = 0;
            if ($usagePct >= 100)      $threshold = 100;
            elseif ($usagePct >= 90)   $threshold = 90;
            elseif ($usagePct >= 80)   $threshold = 80;
            if ($threshold > 0) {
                try {
                    require_once __DIR__ . '/fcm_helpers.php';
                    if (function_exists('send_usage_warning_fcm')) {
                        // current_period_end 조회
                        $peStmt = $pdo->prepare('SELECT current_period_end FROM members WHERE email = :e LIMIT 1');
                        $peStmt->execute([':e' => $ownerEmail]);
                        $peRow = $peStmt->fetch();
                        $periodEnd = $peRow['current_period_end'] ?? null;
                        send_usage_warning_fcm($pdo, $ownerEmail, $threshold, $usedMinFinal, (int)$summaryLimitMinutes, $periodEnd);
                    }
                } catch (Throwable $e) {
                    error_log('[process-recording] usage warning FCM 실패: ' . $e->getMessage());
                }
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
    // 속도 개선 (2026-05-20): summary_json 도 임시 저장 (앱 polling 에서 즉시 받기) + progress 100%.
    try {
        $summaryJsonPayload = json_encode([
            'customer_name'    => $llmName,
            'summary'          => $llmSummary,
            'interest'         => $llmIntr,
            'inquiry'          => $llmInq,
            'budget_condition' => $llmBudg,
            'next_action'      => $llmNext,
        ], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("UPDATE recording_jobs
            SET status = 'completed',
                progress_pct = 100,
                completed_at = NOW(),
                customer_log_id = :cl,
                summary_json_encrypted = :sj,
                updated_at = NOW()
            WHERE id = :id")
            ->execute([
                ':cl' => $rowId,
                ':sj' => $summaryJsonPayload !== false ? youngman_encrypt($summaryJsonPayload) : null,
                ':id' => $asyncJobId,
            ]);
    } catch (Throwable $e) {
        error_log('[process-recording] recording_jobs completed update failed: ' . $e->getMessage());
    }

    // M3: FCM 푸시 발송 — user_fcm_tokens 의 owner 토큰들에게.
    // 실패는 무시 (recording_jobs 는 이미 completed, 폴링 fallback 으로 앱이 결과 받을 수 있음).
    try {
        $custName = (string)(youngman_decrypt($savedRow['customer_name'] ?? '') ?: '고객');
        $sumPreview = (string)(youngman_decrypt($savedRow['summary'] ?? '') ?: '');
        if (mb_strlen($sumPreview) > 60) $sumPreview = mb_substr($sumPreview, 0, 57) . '...';
        // data-only (앱팀 2026-06-11 옵션 A) — notification 제거.
        $fcmResult = send_fcm_to_user($pdo, $ownerEmail, [
            'data'  => [
                'type'            => 'call_summary_ready',
                'job_id'          => (string)$asyncJobId,
                'customer_log_id' => (string)$rowId,
                'consult_at'      => (string)($savedRow['consult_at'] ?? ''),
                // 앱팀 옵션 b — 앱이 보낸 group_id 다시 emit. 앱이 sendCustomerLogToGroup 호출 시 사용.
                'group_id'        => (string)($groupIdHint ?? ''),
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
        // 사장님 2026-05-25 — native A 모달 분기용 flag.
        'requires_subscription' => ($plan === 'free' && !$isAdminUser),
    ],
]);
