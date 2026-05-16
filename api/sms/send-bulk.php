<?php
/**
 * send-bulk.php — 선택한 고객들에게 단체 SMS 발송.
 *
 * 정책:
 *  - 인증된 사용자 본인의 고객 데이터만 조회 (owner_email 강제)
 *  - 클라이언트는 customer_id 만 전송 — 전화번호 직접 전송 금지
 *  - 서버에서 ledger_records.data_json 복호화 후 phone 추출
 *  - 010xxxxxxxx 정규화 + 중복 제거 + 마케팅 수신 동의 필터
 *  - .env 키 없으면 dry-run (실제 발송 X, 로그에는 status='dry_run')
 *  - sms_logs 에는 phone_masked + message_hash 만 저장 (원문 보존 X)
 *
 * 요청 (POST JSON):
 *   {
 *     "customer_ids": [1, 2, 3, ...],
 *     "message":      "...",
 *     "skip_consent_check": false        // (선택) true 면 동의 필드 무시 — confirm 후 사용자 명시 동의
 *   }
 *
 * 응답:
 *   {
 *     "ok": true,
 *     "dryRun": bool,
 *     "provider": "solapi",
 *     "totalSelected":     n,           // 사용자가 선택한 customer 수
 *     "afterConsent":      n,           // 마케팅 동의 필터 적용 후 수
 *     "uniquePhones":      n,           // 중복 제거 후 발송 대상 수
 *     "skipped":           [{customer_id, reason}, ...],
 *     "success":           n,
 *     "failed":            [{phone, error}, ...]
 *   }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST 만 허용됩니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// crypto helpers (records.php 와 동일 로직 — webroot/.env 의 YOUNGMAN_CRYPTO_KEY 사용)
$cryptoFile = __DIR__ . '/../crypto_helpers.php';
if (!is_file($cryptoFile)) $cryptoFile = dirname(__DIR__, 2) . '/crypto_helpers.php';
if (is_file($cryptoFile)) require_once $cryptoFile;
if (!function_exists('youngman_decrypt_json')) {
    // Fallback stub — crypto_helpers 미배포 시에도 동작
    function youngman_decrypt_json($v) {
        if ($v === null || $v === '') return null;
        if (!is_string($v)) return $v;
        $d = json_decode($v, true);
        return ($d === null && json_last_error() !== JSON_ERROR_NONE) ? $v : $d;
    }
}

require_once __DIR__ . '/providers/SmsProvider.php';
require_once __DIR__ . '/providers/SolapiProvider.php';
require_once __DIR__ . '/providers/AligoProvider.php';

/* ===== DB 연결 (records.php 와 동일 패턴) ===== */
$cfg = __DIR__ . '/../db_config.php';
if (!is_file($cfg)) $cfg = dirname(__DIR__, 2) . '/db_config.php';
if (!is_file($cfg)) jout(['ok' => false, 'error' => 'DB 설정 파일이 없습니다.'], 500);
$db = require $cfg;
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'] ?? 'localhost', (int)($db['port'] ?? 3306), $db['database'] ?? ''),
        $db['user'] ?? '', $db['password'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    jout(['ok' => false, 'error' => 'DB 연결 실패'], 500);
}

/* ===== Supabase 토큰 검증 — records.php 의 패턴 재사용 (간소화) ===== */
$authHeader = '';
foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
    if (!empty($_SERVER[$k])) { $authHeader = $_SERVER[$k]; break; }
}
if (!$authHeader && function_exists('getallheaders')) {
    foreach (getallheaders() as $h => $v) {
        if (strcasecmp($h, 'Authorization') === 0) { $authHeader = $v; break; }
    }
}
if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    jout(['ok' => false, 'error' => '로그인이 필요합니다.'], 401);
}
$accessToken = trim($m[1]);
$ownerEmail = verify_supabase_token($accessToken);
if (!$ownerEmail) {
    jout(['ok' => false, 'error' => '인증 실패 — 다시 로그인 해주세요.'], 401);
}
$ownerEmail = strtolower($ownerEmail);

/* ===== 요청 본문 파싱 ===== */
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) jout(['ok' => false, 'error' => '요청 본문이 JSON 이어야 합니다.'], 400);

$customerIds = $body['customer_ids'] ?? [];
$message     = trim((string)($body['message'] ?? ''));
$skipConsent = !empty($body['skip_consent_check']);
$imageBase64 = isset($body['image_base64']) ? trim((string)$body['image_base64']) : '';
$imageName   = isset($body['image_name'])   ? trim((string)$body['image_name'])   : 'attachment.jpg';

if (!is_array($customerIds) || empty($customerIds)) {
    jout(['ok' => false, 'error' => '대상 고객을 선택해주세요.'], 400);
}
// 텍스트 비어 있어도 이미지가 있으면 OK (MMS 사진만 발송)
if ($message === '' && $imageBase64 === '') {
    jout(['ok' => false, 'error' => '문자 내용 또는 이미지를 입력해주세요.'], 400);
}
if (mb_strlen($message) > 2000) {
    jout(['ok' => false, 'error' => '문자 내용이 너무 깁니다 (2000자 이하).'], 400);
}
// 이미지 base64 사이즈 제한 (raw 200KB → base64 ~272KB)
if ($imageBase64 !== '' && strlen($imageBase64) > 300 * 1024) {
    jout(['ok' => false, 'error' => '이미지가 너무 큽니다 (200KB 이하만 가능).'], 400);
}

// id 정수만, 최대 1000명 제한
$customerIds = array_values(array_unique(array_filter(array_map('intval', $customerIds), function ($v) { return $v > 0; })));
if (count($customerIds) > 1000) {
    jout(['ok' => false, 'error' => '한 번에 1000명까지만 발송 가능합니다.'], 400);
}

/* ===== ledger_records 조회 — owner_email 강제 ===== */
ensure_sms_logs_table($pdo);

$placeholders = implode(',', array_fill(0, count($customerIds), '?'));
$sql = "SELECT lr.id, lr.group_id, lr.data_json, lg.settings_json
        FROM ledger_records lr
        JOIN ledger_groups lg ON lg.id = lr.group_id
        WHERE lr.owner_email = ? AND lr.id IN ($placeholders)";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$ownerEmail], $customerIds));
$rows = $stmt->fetchAll();

/* ===== phone 추출 + 정규화 + 동의 필터 + dedupe ===== */
$skipped = [];
$phoneToCustomerId = [];   // 010xxxxxxxx => 첫 customer_id
$afterConsent = 0;

foreach ($rows as $r) {
    $cid  = (int)$r['id'];
    $data = youngman_decrypt_json($r['data_json']);
    if (!is_array($data)) {
        $skipped[] = ['customer_id' => $cid, 'reason' => '고객 데이터 복호화 실패'];
        continue;
    }
    $settings = $r['settings_json'] ? (youngman_decrypt_json($r['settings_json']) ?: []) : [];
    $customFields = (is_array($settings) && isset($settings['customFields']) && is_array($settings['customFields']))
        ? $settings['customFields'] : [];

    // 마케팅 수신 동의 필드 자동 감지 (label 에 '동의'|'수신'|'마케팅'|'sms'|'opt' 포함된 toggle/switch)
    $consentOk = true;
    if (!$skipConsent) {
        $consentField = null;
        foreach ($customFields as $f) {
            $t = $f['type'] ?? '';
            $l = mb_strtolower($f['label'] ?? '', 'UTF-8');
            if (($t === 'toggle' || $t === 'switch')
                && (strpos($l, '동의') !== false || strpos($l, '수신') !== false
                    || strpos($l, '마케팅') !== false || strpos($l, 'sms') !== false
                    || strpos($l, 'opt') !== false)) {
                $consentField = $f; break;
            }
        }
        if ($consentField) {
            $consentOk = !empty($data[$consentField['key']]);
        }
    }
    if (!$consentOk) {
        $skipped[] = ['customer_id' => $cid, 'reason' => '마케팅 수신 동의 안 함'];
        continue;
    }
    $afterConsent++;

    // phone 추출 — type='tel' 인 첫 필드 또는 일반 key (phone/tel/mobile)
    $phoneRaw = '';
    foreach ($customFields as $f) {
        if (($f['type'] ?? '') === 'tel' && !empty($data[$f['key']])) {
            $phoneRaw = (string)$data[$f['key']]; break;
        }
    }
    if ($phoneRaw === '') {
        foreach (['phone', 'tel', 'mobile', 'hp', 'cell'] as $k) {
            if (!empty($data[$k])) { $phoneRaw = (string)$data[$k]; break; }
        }
    }
    if ($phoneRaw === '') {
        $skipped[] = ['customer_id' => $cid, 'reason' => '전화번호 없음'];
        continue;
    }

    $normalized = normalize_kr_phone($phoneRaw);
    if (!$normalized) {
        $skipped[] = ['customer_id' => $cid, 'reason' => '전화번호 형식 오류'];
        continue;
    }
    // dedupe: 같은 번호는 첫 customer_id 만 등록 (이후는 skip)
    if (isset($phoneToCustomerId[$normalized])) {
        $skipped[] = ['customer_id' => $cid, 'reason' => '중복 번호 (이미 발송 예정)'];
        continue;
    }
    $phoneToCustomerId[$normalized] = $cid;
}

$messagesToSend = [];
foreach ($phoneToCustomerId as $phone => $cid) {
    $messagesToSend[] = ['to' => $phone, 'text' => $message];
}

if (empty($messagesToSend)) {
    jout([
        'ok'            => true,
        'dryRun'        => true,
        'provider'      => 'none',
        'totalSelected' => count($customerIds),
        'afterConsent'  => $afterConsent,
        'uniquePhones'  => 0,
        'skipped'       => $skipped,
        'success'       => 0,
        'failed'        => [],
    ]);
}

/* ===== Provider 결정 — 회원별 sms_credentials 에서 조회 ===== */
$credStmt = $pdo->prepare('SELECT provider, api_key_enc, api_secret_enc, sender_phone_enc
                          FROM sms_credentials WHERE owner_email = :o LIMIT 1');
try { $credStmt->execute([':o' => $ownerEmail]); }
catch (Throwable $e) {
    jout([
        'ok'     => false,
        'error'  => '문자 자격증명 테이블이 아직 준비되지 않았습니다. 잠시 후 다시 시도해주세요.',
        'reason' => 'sms_credentials_missing_table',
    ], 503);
}
$cred = $credStmt->fetch();

if (!$cred) {
    jout([
        'ok'     => false,
        'error'  => 'Solapi 계정이 연동되지 않았습니다. 내 정보 → 문자 설정에서 API Key / Secret / 발신번호를 등록해 주세요.',
        'reason' => 'sms_not_configured',
        'action' => 'open_settings',
    ], 412);
}

$providerName = strtolower((string)($cred['provider'] ?? 'solapi'));
$apiKey       = youngman_decrypt($cred['api_key_enc']    ?? '');
$apiSecret    = youngman_decrypt($cred['api_secret_enc'] ?? '');
$senderFrom   = preg_replace('/[^\d]/', '', (string)youngman_decrypt($cred['sender_phone_enc'] ?? ''));

if (!is_string($apiKey) || $apiKey === '' || !is_string($apiSecret) || $apiSecret === '') {
    jout([
        'ok'     => false,
        'error'  => 'Solapi API Key / Secret 이 등록되지 않았습니다. 내 정보 → 문자 설정에서 등록해 주세요.',
        'reason' => 'sms_credentials_empty',
        'action' => 'open_settings',
    ], 412);
}
if ($senderFrom === '') {
    jout([
        'ok'     => false,
        'error'  => '발신번호가 등록되지 않았습니다. 내 정보 → 문자 설정에서 등록해 주세요.',
        'reason' => 'sms_sender_missing',
        'action' => 'open_settings',
    ], 412);
}

if ($providerName === 'aligo') {
    $provider = new AligoProvider([
        'api_key' => $apiKey,
        'user_id' => $apiSecret,   // Aligo 는 secret 자리에 user_id 사용 (사용자 등록 시 안내)
    ]);
} else {
    $provider = new SolapiProvider([
        'api_key'    => $apiKey,
        'api_secret' => $apiSecret,
    ]);
}

// 명시적 dry-run 토글 (요청 body 의 dry_run=true) — 테스트용
$dryRun = !empty($body['dry_run']);

// 이미지 첨부: Solapi 만 storage upload 지원. Aligo 는 무시 (SMS/LMS 만 가능).
$imageId = null;
if ($imageBase64 !== '' && $providerName !== 'aligo' && !$dryRun) {
    if (method_exists($provider, 'uploadImage')) {
        $imageId = $provider->uploadImage($imageBase64, $imageName);
        if (!$imageId) {
            jout([
                'ok'    => false,
                'error' => '이미지 업로드 실패 — Solapi storage 에 파일을 올리지 못했습니다. 다시 시도하거나 다른 이미지를 사용해 주세요.',
                'reason' => 'image_upload_failed',
            ], 502);
        }
    }
}
$sendOpts = ['dryRun' => $dryRun];
if ($imageId) $sendOpts['imageId'] = $imageId;

$result = $provider->sendBulk($messagesToSend, $senderFrom, $sendOpts);

// 실패 사유 사용자 친화 메시지로 매핑
$result['failed'] = array_map(function ($f) {
    return ['to' => $f['to'], 'error' => map_provider_error((string)($f['error'] ?? ''))];
}, $result['failed'] ?? []);

/* ===== sms_logs 기록 (원문 저장 X — phone_masked + message hash 만) ===== */
$msgHash = substr(hash('sha256', $message), 0, 16);
$msgLen  = mb_strlen($message, 'UTF-8');
$logSql  = "INSERT INTO sms_logs (owner_email, customer_id, phone_masked, message_hash, message_len, provider, status, error_message)
            VALUES (:owner, :cid, :masked, :hash, :len, :prov, :status, :err)";
$logStmt = $pdo->prepare($logSql);

$failedToMap = [];
foreach (($result['failed'] ?? []) as $f) {
    $failedToMap[$f['to']] = $f['error'] ?? '실패';
}

$now = date('Y-m-d H:i:s');
foreach ($phoneToCustomerId as $phone => $cid) {
    $isFailed = isset($failedToMap[$phone]);
    try {
        $logStmt->execute([
            ':owner'  => $ownerEmail,
            ':cid'    => $cid,
            ':masked' => mask_phone($phone),
            ':hash'   => $msgHash,
            ':len'    => $msgLen,
            ':prov'   => $result['provider'] ?? $providerName,
            ':status' => $result['dryRun'] ? 'dry_run' : ($isFailed ? 'failed' : 'success'),
            ':err'    => $isFailed ? substr($failedToMap[$phone], 0, 500) : null,
        ]);
    } catch (Throwable $e) {
        // 로그 실패는 발송 결과에 영향 안 줌
    }
}

/* ===== 응답 — phone 은 마스킹해서 반환 ===== */
$failedMasked = array_map(function ($f) {
    return ['phone' => mask_phone($f['to']), 'error' => $f['error']];
}, $result['failed'] ?? []);

jout([
    'ok'            => true,
    'dryRun'        => $result['dryRun'] ?? false,
    'provider'      => $result['provider'] ?? $providerName,
    'totalSelected' => count($customerIds),
    'afterConsent'  => $afterConsent,
    'uniquePhones'  => count($messagesToSend),
    'skipped'       => $skipped,
    'success'       => $result['success'] ?? 0,
    'failed'        => $failedMasked,
]);

/* ===================== 헬퍼들 ===================== */

function jout($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Supabase access token 검증 — /auth/v1/user 폴백 (records.php 와 동일 방식). */
function verify_supabase_token(string $token): ?string {
    // .env 의 VITE_SUPABASE_URL 사용
    $env = load_env_file();
    $url = $env['VITE_SUPABASE_URL'] ?? '';
    $url = rtrim((string)$url, '/');
    // URL 정리: /rest/v1/ 또는 / 로 끝날 수 있음 — base 만 추출
    $url = preg_replace('#/rest/v1/?$#', '', $url);
    $url = preg_replace('#/auth/v1/?$#', '', $url);
    if ($url === '') return null;
    $anon = $env['VITE_SUPABASE_ANON_KEY'] ?? '';
    $ch = curl_init($url . '/auth/v1/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'apikey: ' . $anon,
        ],
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || $body === false) return null;
    $data = json_decode($body, true);
    if (!is_array($data)) return null;
    $email = $data['email'] ?? null;
    return is_string($email) && $email !== '' ? $email : null;
}

/** .env 파일 로드 — webroot/.env 또는 그 부모. records.php 와 동일 규칙. */
function load_env_file(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $candidates = [
        __DIR__ . '/../.env',
        __DIR__ . '/../../.env',
        dirname(__DIR__, 2) . '/.env',
    ];
    $out = [];
    foreach ($candidates as $p) {
        if (!is_file($p)) continue;
        $lines = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) continue;
        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $m)) {
                $out[$m[1]] = trim($m[2], "\"' ");
            }
        }
        break;   // 첫 번째 발견된 .env 만
    }
    return $cached = $out;
}

/** 010-1234-5678 / +82 10 1234 5678 / 01012345678 → 01012345678 (국내 휴대폰만). */
function normalize_kr_phone(string $raw): ?string {
    $d = preg_replace('/[^\d]/', '', $raw);
    if ($d === '') return null;
    // +82 또는 82 로 시작하는 국제 표기
    if (strpos($d, '82') === 0 && strlen($d) >= 11) {
        $d = '0' . substr($d, 2);
    }
    // 휴대폰: 010 / 011 / 016 / 017 / 018 / 019, 길이 10~11
    if (preg_match('/^01[016789]\d{7,8}$/', $d)) return $d;
    return null;
}

/** 010-****-1234 식 마스킹. */
function mask_phone(string $phone): string {
    if (strlen($phone) < 7) return preg_replace('/\d/', '*', $phone);
    $head = substr($phone, 0, 3);
    $tail = substr($phone, -4);
    return $head . '-****-' . $tail;
}

/** Solapi/Aligo 의 raw 에러 코드/문구를 사용자 친화 메시지로 변환. */
function map_provider_error(string $raw): string {
    $r = $raw;
    $lower = strtolower($r);
    // Solapi 잔액 부족 / 충전 필요
    if (strpos($r, '4032') !== false || strpos($lower, 'balance') !== false || strpos($r, '잔액') !== false) {
        return '잔액 부족 — Solapi 사이트에서 충전 후 다시 시도해주세요.';
    }
    // 발신번호 미등록
    if (strpos($r, '4022') !== false || strpos($r, '4039') !== false
        || strpos($lower, 'sender') !== false || strpos($r, '발신') !== false) {
        return '발신번호가 Solapi 에 등록되지 않았습니다. Solapi 사이트에서 발신번호를 사전 등록해 주세요.';
    }
    // 인증 실패
    if (strpos($r, '4031') !== false || strpos($r, '4012') !== false || strpos($r, '4001') !== false
        || strpos($lower, 'unauth') !== false || strpos($lower, 'forbidden') !== false || strpos($lower, 'invalid api') !== false) {
        return 'Solapi 인증 실패 — API Key / Secret 을 다시 확인해 주세요.';
    }
    // 번호 형식 오류
    if (strpos($r, '4025') !== false || strpos($lower, 'phone') !== false || strpos($r, '번호') !== false) {
        return '수신 번호 형식 오류 (010-XXXX-XXXX 형식만 가능).';
    }
    // 한도 초과
    if (strpos($lower, 'limit') !== false || strpos($lower, 'quota') !== false || strpos($r, '한도') !== false) {
        return '일/월 발송 한도 초과 — Solapi 설정을 확인해 주세요.';
    }
    return $r !== '' ? $r : '발송 실패 (사유 미상)';
}

/** sms_logs 테이블 자동 마이그레이션 (첫 호출 시 1회). */
function ensure_sms_logs_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sms_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_email VARCHAR(255) NOT NULL,
            customer_id INT NULL,
            phone_masked VARCHAR(40) NOT NULL,
            message_hash VARCHAR(64) NOT NULL,
            message_len INT NOT NULL,
            provider VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            error_message VARCHAR(500) NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sms_owner (owner_email),
            INDEX idx_sms_sent (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    } catch (Throwable $e) {
        // 마이그 실패해도 발송 자체는 진행 (로그만 skip)
    }
}
