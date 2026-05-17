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
@set_time_limit(180);

/* ========== 응답/입력 헬퍼 ========== */
function jout(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function jerror(string $code, string $message, int $status): void {
    jout(['status' => 'error', 'code' => $code, 'message' => $message], $status);
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

function fetch_user_email_via_supabase(string $token, array $auth): string {
    $url = rtrim((string)($auth['supabase_url'] ?? ''), '/');
    $key = (string)($auth['anon_key'] ?? '');
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
    if ($status !== 200 || !$resp) return '';
    $data = json_decode((string)$resp, true);
    return strtolower(trim((string)($data['email'] ?? '')));
}

function require_auth_email(): string {
    $token = get_bearer_token();
    if (!$token) jerror('unauthorized', '로그인이 필요합니다.', 401);
    $auth = load_supabase_auth();
    if (empty($auth['supabase_url']) || empty($auth['anon_key'])) {
        jerror('unauthorized', '서버 인증 설정 누락.', 500);
    }
    $email = fetch_user_email_via_supabase($token, $auth);
    if (!$email) jerror('unauthorized', '토큰 검증 실패. 다시 로그인해주세요.', 401);
    return $email;
}

/* ========== 디렉터리 격리 (upload.php 와 동일 함수명) ========== */
function user_dir_segment(string $email): string {
    return 'u_' . substr(hash('sha256', strtolower(trim($email))), 0, 16);
}

/* ========== customer_log 헬퍼 (records.php 와 동일 정의 — DRY 보다 standalone 우선) ========== */
function customer_log_free_quota(): int { return 5; }

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
        if (!in_array('plan', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `plan` VARCHAR(16) NOT NULL DEFAULT 'free'");
        }
        if (!in_array('free_summaries_used', $existing, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `free_summaries_used` INT NOT NULL DEFAULT 0");
        }
        return $done = true;
    } catch (Throwable $e) {
        error_log('[process-recording] ensure_members_plan_columns failed: ' . $e->getMessage());
        return $done = false;
    }
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

/* ========== Plan check ========== */
$plan = 'free';
$freeUsed = 0;
try {
    $ps = $pdo->prepare('SELECT plan, free_summaries_used FROM members WHERE email = :e LIMIT 1');
    $ps->execute([':e' => $ownerEmail]);
    $row = $ps->fetch();
    if ($row) {
        $plan = (string)($row['plan'] ?? 'free');
        $freeUsed = (int)($row['free_summaries_used'] ?? 0);
    }
} catch (Throwable $e) {
    // plan 컬럼이 아직 없으면 free 로 간주.
    $plan = 'free';
    $freeUsed = 0;
}
if ($plan === 'free' && $freeUsed >= customer_log_free_quota()) {
    jerror('plan_required', '무료 체험 횟수가 끝났습니다. Premium 가입이 필요합니다.', 403);
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

/* ========== Whisper STT ========== */
$apiKey = load_env_value('OPENAI_API_KEY');
if ($apiKey === '') jerror('upstream_failed', 'OPENAI_API_KEY 미설정.', 500);

// Whisper 는 multipart filename 확장자 또는 Content-Type 으로 포맷을 판단.
// 우리 파일명은 UUID (예: 9f0e8d7c-1234-...-m4a) — 하이픈이 많아 일부 Whisper 파서가 확장자 인식 실패 → 400.
// 또한 mime_content_type 이 'audio/x-m4a' 같은 변형 mime 을 반환하면 Whisper 가 거부.
// 해결: 디스크 확장자에서 정규화된 mime + 명시적 'audio.<ext>' postname 으로 전송.
$srcExt = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$whisperMimeMap = [
    'm4a'  => 'audio/mp4',
    'mp4'  => 'audio/mp4',
    'mp3'  => 'audio/mpeg',
    'mpga' => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'webm' => 'audio/webm',
    'ogg'  => 'audio/ogg',
    'oga'  => 'audio/ogg',
    'opus' => 'audio/ogg',
    'flac' => 'audio/flac',
    '3gp'  => 'audio/mp4',
    '3gpp' => 'audio/mp4',
    'aac'  => 'audio/mp4',
    'amr'  => 'audio/mp4',
];
$whisperExt  = isset($whisperMimeMap[$srcExt]) ? $srcExt : 'm4a';
$whisperMime = $whisperMimeMap[$whisperExt];
$whisperPostname = 'audio.' . $whisperExt;

$ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'model' => 'whisper-1',
        'language' => 'ko',
        'file' => new CURLFile($realPath, $whisperMime, $whisperPostname),
    ],
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
    CURLOPT_TIMEOUT => 120,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$sttResp = curl_exec($ch);
$sttStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$sttErr = curl_error($ch);
curl_close($ch);

if ($sttResp === false) jerror('upstream_failed', 'Whisper 호출 실패: ' . $sttErr, 502);
$sttData = json_decode((string)$sttResp, true);
if ($sttStatus < 200 || $sttStatus >= 300) {
    $msg = is_array($sttData) ? ($sttData['error']['message'] ?? json_encode($sttData)) : substr((string)$sttResp, 0, 300);
    jerror('upstream_failed', 'Whisper ' . $sttStatus . ': ' . $msg, 502);
}
$transcript = trim((string)($sttData['text'] ?? ''));
if ($transcript === '') jerror('upstream_failed', 'STT 결과가 비어있습니다.', 502);

/* ========== LLM 요약 (gpt-4o-mini, JSON 응답) ========== */
$llmModel = 'gpt-4o-mini';
$sys = <<<SYS
당신은 한국어 부동산/세일즈 통화 내용을 요약해 CRM에 기록하는 보조AI입니다.

입력: 통화 STT 전사
출력: 다음 JSON 스키마. 키 이름은 정확히 일치. 누락 시 빈 문자열이나 null.

{
  "customer_name": string | null,
  "summary": string,
  "interest": string | null,
  "inquiry": string | null,
  "budget_condition": string | null,
  "next_action": string | null
}

규칙:
- 단정적이지 않은 사실은 추측하지 말 것.
- 개인정보(주민번호, 카드번호 등)는 마스킹.
- 통화 상대가 본인을 지칭한 호칭만 customer_name으로.
- 영업측 발화는 customer_name으로 쓰지 말 것.
- summary 는 3-5문장.
- JSON 외 다른 텍스트 출력 금지.
SYS;

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
$parsed = json_decode($llmText, true);
if (!is_array($parsed)) {
    // 한 번 더 시도 — 평문 안에 JSON 블록이 섞인 경우 추출.
    if (preg_match('/\{[\s\S]*\}/', $llmText, $m)) {
        $parsed = json_decode($m[0], true);
    }
}
if (!is_array($parsed)) jerror('upstream_failed', 'LLM JSON 파싱 실패.', 502);

$llmName    = isset($parsed['customer_name'])    ? trim((string)$parsed['customer_name'])    : '';
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
        ':am'  => $llmModel,
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

/* ========== free_summaries_used 증가 (plan=free 일 때만) ========== */
if ($plan === 'free') {
    try {
        $pdo->prepare('UPDATE members SET free_summaries_used = free_summaries_used + 1 WHERE email = :e')
            ->execute([':e' => $ownerEmail]);
        $freeUsed += 1;
    } catch (Throwable $e) {
        // plan 컬럼이 아직 없으면 무시 (다음 호출 시 ensure 후 정상 동작).
    }
}

/* ========== 오디오 파일 즉시 삭제 (audio_kept = false) ========== */
@unlink($realPath);
// 디렉터리도 비어있으면 정리 — best-effort, 실패 무시.
@rmdir(dirname($realPath));

/* ========== 응답 ========== */
$fetch = $pdo->prepare('SELECT * FROM customer_log WHERE id = :id LIMIT 1');
$fetch->execute([':id' => $rowId]);
$savedRow = $fetch->fetch();
if (!$savedRow) jerror('upstream_failed', 'insert 후 조회 실패.', 500);

jout([
    'status' => 'ok',
    'customer_log' => customer_log_row($savedRow),
    'plan' => [
        'plan' => $plan,
        'free_summaries_used' => $freeUsed,
        'free_quota' => customer_log_free_quota(),
    ],
]);
