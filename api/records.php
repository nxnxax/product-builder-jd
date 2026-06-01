<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 암호화 헬퍼 (AES-256-GCM) — youngman_encrypt / youngman_decrypt / *_json / *_enabled
// 헬퍼 파일이 아직 배포 안 됐을 수 있어 가드로 감싸고 stub 정의 (서비스 중단 방지).
$__cryptoFile = __DIR__ . '/crypto_helpers.php';
if (is_file($__cryptoFile)) {
    require_once $__cryptoFile;
}
if (!function_exists('youngman_encrypt')) {
    function youngman_encrypt($v) { return $v; }
    function youngman_decrypt($v) { return $v; }
    function youngman_encrypt_json($v) {
        if ($v === null) return null;
        return is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    function youngman_decrypt_json($v) {
        if ($v === null || $v === '') return null;
        if (!is_string($v)) return $v;
        $d = json_decode($v, true);
        return ($d === null && json_last_error() !== JSON_ERROR_NONE) ? $v : $d;
    }
    function youngman_crypto_enabled(): bool { return false; }
}

$configPath = __DIR__ . '/db_config.php';
if (!is_file($configPath)) {
    $configPath = dirname(__DIR__) . '/db_config.php';
}

if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB 설정 파일이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = require $configPath;
$authConfigPath = __DIR__ . '/supabase_config.php';
if (!is_file($authConfigPath)) {
    $authConfigPath = dirname(__DIR__) . '/supabase_config.php';
}
$auth = is_file($authConfigPath) ? require $authConfigPath : ['require_auth' => false];

// Fallback: hydrate auth config from .env or supabase_config.js if fields are missing.
// Lets the new-format token verification work without forcing operators to update
// supabase_config.php right away.
(function () use (&$auth) {
    // records.php may be deployed alongside supabase_config.js (webroot) or one level
    // below it (api/ subdir). Search both __DIR__ and its parent so hydration works
    // regardless of layout.
    $searchDirs = [__DIR__, dirname(__DIR__)];
    $sources = [];

    foreach ($searchDirs as $dir) {
        $envPath = $dir . '/.env';
        if (is_file($envPath)) {
            $envSource = [];
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $m)) {
                    $envSource[$m[1]] = trim($m[2], "\"' ");
                }
            }
            if (!empty($envSource)) $sources[] = $envSource;
        }

        $jsPath = $dir . '/supabase_config.js';
        if (is_file($jsPath)) {
            $jsSource = [];
            $contents = (string)file_get_contents($jsPath);
            if (preg_match('/SUPABASE_URL\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $m)) {
                $jsSource['SUPABASE_URL'] = $m[1];
            }
            if (preg_match('/SUPABASE_ANON_KEY\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $m)) {
                $jsSource['SUPABASE_ANON_KEY'] = $m[1];
            }
            if (!empty($jsSource)) $sources[] = $jsSource;
        }
    }

    foreach ($sources as $source) {
        if (empty($auth['supabase_url'])) {
            $rawUrl = (string)($source['VITE_SUPABASE_URL'] ?? $source['SUPABASE_URL'] ?? '');
            if ($rawUrl !== '') {
                $auth['supabase_url'] = preg_replace('#/(rest|auth)/v1/?.*$#', '', $rawUrl);
            }
        }
        if (empty($auth['anon_key'])) {
            $rawKey = (string)($source['VITE_SUPABASE_ANON_KEY'] ?? $source['SUPABASE_ANON_KEY'] ?? '');
            if ($rawKey !== '') $auth['anon_key'] = $rawKey;
        }
        if (empty($auth['service_key'])) {
            $rawSvc = (string)($source['SUPABASE_SERVICE_KEY'] ?? $source['SUPABASE_SERVICE_ROLE_KEY'] ?? '');
            if ($rawSvc !== '') $auth['service_key'] = $rawSvc;
        }
    }
})();

function respond($payload, $status = 200) {
    http_response_code($status);
    // 앱팀 2026-05-20 요청 — 실패 응답에 error_code 자동 매핑.
    // 호출처가 직접 error_code 지정했으면 우선. 키워드 휴리스틱은 fragile 하지만 backwards compat 우선.
    if (is_array($payload) && empty($payload['ok']) && $status >= 400 && !isset($payload['error_code'])) {
        $errMsg = (string)($payload['error'] ?? $payload['message'] ?? '');
        $code = 'UNKNOWN';
        if ($status === 401) {
            if (preg_match('/만료|expired|exp\b/iu', $errMsg)) $code = 'AUTH_EXPIRED';
            elseif (preg_match('/로그인이 필요|헤더|Bearer|no_token|토큰.*없/iu', $errMsg)) $code = 'AUTH_REQUIRED';
            else $code = 'AUTH_INVALID';
        } elseif ($status === 409) $code = 'JOB_DUPLICATE';
        elseif ($status >= 500) $code = 'RETRYABLE_SERVER_ERROR';
        elseif ($status === 404) $code = 'NOT_FOUND';
        elseif ($status === 403) $code = 'FORBIDDEN';
        elseif ($status === 400) $code = 'INVALID_REQUEST';
        $payload['error_code'] = $code;
        if (!isset($payload['http_status'])) $payload['http_status'] = $status;
        if (!isset($payload['message']) && isset($payload['error'])) $payload['message'] = $payload['error'];
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_body() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $json = json_decode($raw, true);
    if (is_array($json)) return $json;
    // form-encoded fallback (앱이 application/x-www-form-urlencoded 보낼 가능성).
    parse_str($raw, $form);
    if (is_array($form) && !empty($form)) return $form;
    // multipart fallback — PHP 가 $_POST 에 채움.
    if (!empty($_POST)) return $_POST;
    respond(['ok' => false, 'error' => 'JSON 형식이 올바르지 않습니다.'], 400);
}

function normalize_resource($value) {
    $resource = strtolower(trim((string)$value));
    if ($resource === 'customer') $resource = 'customers';
    if ($resource === 'employee') $resource = 'employees';
    $allowed = [
        'customers', 'employees',
        'auth-membership', 'auth-member', 'auth-availability',
        'auth-profile', 'account-delete', 'sms-credentials',
        'admin-members', 'admin-stats', 'admin-stats-range', 'admin-logs', 'admin-settings',
        'admin-bootstrap', 'admin-cleanup-orphans',
        'ledger-groups', 'ledger-records', 'ledger-records-bulk',
        'mobile-tokens',
        'customer-log',
        'app-fcm-token',
        'recording-job',
        'community-posts',
        'find-email', 'find-email-send-otp', 'find-email-verify-otp',
        'find-pwd-send-otp', 'find-pwd-verify-otp', 'find-pwd-reset',
        'signup-send-otp', 'signup-verify-otp',
    ];
    if (!in_array($resource, $allowed, true)) {
        respond(['ok' => false, 'error' => '지원하지 않는 리소스입니다.'], 400);
    }
    return $resource;
}

function clean($value) {
    $value = trim((string)($value ?? ''));
    return $value === '' ? null : $value;
}

function admin_email_allowlist() {
    return ['nxnxax@gmail.com'];
}

function is_admin_email($email) {
    $normalized = strtolower(trim((string)$email));
    if ($normalized === '') return false;
    return in_array($normalized, admin_email_allowlist(), true);
}

function is_valid_nickname($value) {
    $v = trim((string)$value);
    $len = mb_strlen($v, 'UTF-8');
    if ($len < 2 || $len > 20) return false;
    return (bool)preg_match('/^[A-Za-z0-9_\-가-힣]+$/u', $v);
}

function nickname_taken(PDO $pdo, $store, $nickname, $excludeEmail = null) {
    $columns = $store['columns'];
    $col = first_existing_column($columns, ['nickname', 'nick', 'display_name']);
    if (!$col) return false; // no column = nothing to dedupe on
    $emailCol = $store['email_column'];
    $sql = "SELECT 1 FROM " . quote_identifier($store['table'])
         . " WHERE LOWER(" . quote_identifier($col) . ") = :nick";
    $params = [':nick' => mb_strtolower(trim((string)$nickname), 'UTF-8')];
    if ($excludeEmail !== null && $excludeEmail !== '' && $emailCol) {
        $sql .= " AND LOWER(" . quote_identifier($emailCol) . ") <> :excl";
        $params[':excl'] = strtolower(trim((string)$excludeEmail));
    }
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}

function make_client_id() {
    return (string)round(microtime(true) * 1000) . bin2hex(random_bytes(3));
}

function quote_identifier($value) {
    return '`' . str_replace('`', '``', (string)$value) . '`';
}

function table_exists(PDO $pdo, $table) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
    ");
    $stmt->execute([':table_name' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function table_columns(PDO $pdo, $table) {
    $stmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
    ");
    $stmt->execute([':table_name' => $table]);
    return array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function ensure_members_table(PDO $pdo) {
    static $attempted = false;
    if ($attempted) return;
    $attempted = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `members` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `email` VARCHAR(255) NOT NULL,
            `nickname` VARCHAR(60) NULL DEFAULT NULL,
            `name` VARCHAR(120) NOT NULL DEFAULT '',
            `phone` VARCHAR(40) NOT NULL DEFAULT '',
            `provider` VARCHAR(40) NOT NULL DEFAULT 'email',
            `supabase_id` VARCHAR(64) NOT NULL DEFAULT '',
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `role` VARCHAR(20) NOT NULL DEFAULT 'member',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `last_login_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_email` (`email`),
            UNIQUE KEY `uniq_nickname` (`nickname`),
            KEY `idx_supabase_id` (`supabase_id`),
            KEY `idx_role` (`role`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        auth_log('members table ensured');

        // Ensure nickname column + unique index exist on pre-existing tables.
        $cols = table_columns($pdo, 'members');
        if (!in_array('nickname', $cols, true)) {
            try {
                $pdo->exec("ALTER TABLE `members` ADD COLUMN `nickname` VARCHAR(60) NULL DEFAULT NULL");
                auth_log('members.nickname column added');
            } catch (Throwable $e) {
                auth_log('members.nickname add failed', ['error' => $e->getMessage()]);
            }
            try {
                $pdo->exec("ALTER TABLE `members` ADD UNIQUE KEY `uniq_nickname` (`nickname`)");
            } catch (Throwable $e) {
                /* unique key may already exist or duplicates may block add — non-fatal */
            }
        }
    } catch (Throwable $e) {
        auth_log('members table create failed', ['error' => $e->getMessage()]);
    }
}

function find_member_store(PDO $pdo) {
    // Idempotent: creates members if missing, ensures nickname column on existing tables.
    ensure_members_table($pdo);

    foreach (['members', 'users'] as $table) {
        if (!table_exists($pdo, $table)) continue;

        $columns = table_columns($pdo, $table);
        foreach (['email', 'user_email', 'mb_email'] as $emailColumn) {
            if (in_array($emailColumn, $columns, true)) {
                return ['table' => $table, 'email_column' => $emailColumn, 'columns' => $columns];
            }
        }
    }

    return null;
}

function first_existing_column($columns, $candidates) {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) return $candidate;
    }
    return null;
}

function member_exists_by_email(PDO $pdo, $email) {
    $email = strtolower(trim((string)$email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $store = find_member_store($pdo);
    if (!$store) return null;

    $table = quote_identifier($store['table']);
    $emailColumn = quote_identifier($store['email_column']);
    $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE LOWER({$emailColumn}) = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    return (bool)$stmt->fetchColumn();
}

function create_member_from_google(PDO $pdo, $authUser, $data) {
    if (!$authUser) respond(['ok' => false, 'error' => 'Google 인증 세션이 필요합니다.'], 401);

    $email = strtolower(trim((string)($data['email'] ?? '')));
    $authEmail = strtolower(trim((string)($authUser['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['ok' => false, 'error' => '회원 이메일이 올바르지 않습니다.'], 400);
    }
    if ($authEmail === '' || $authEmail !== $email) {
        respond(['ok' => false, 'error' => 'Google 인증 이메일과 가입 이메일이 일치하지 않습니다.'], 403);
    }

    // 사장님 2026-05-25 — Google 가입자만 "가입 완료" 버튼 강제. 일반(이메일) 가입은 옛 흐름.
    // login-complete.html 의 OAuth 후 ensure POST 시 provider='google' 명시.
    // "가입 완료" 버튼 클릭 시 finalize=true + 이름/휴대폰/닉네임/약관/인증토큰 모두 POST → INSERT.
    $isFinalize = !empty($data['finalize']);
    $signupProviderEarly = strtolower(trim((string)($data['provider'] ?? '')));
    if ($signupProviderEarly === 'google' && !$isFinalize) {
        $store = find_member_store($pdo);
        if ($store && member_exists_by_email($pdo, $email) !== true) {
            respond([
                'ok' => true,
                'pending_signup' => true,
                'needsExtra' => true,
                'needsName' => true,
                'needsNickname' => true,
                'needsPhone' => true,
            ]);
        }
    }

    // fullName 우선순위: body → supabase user metadata → email local-part
    // (회원가입 모달은 fullName 명시 전달; 로그인 후 자동 보강은 metadata fallback 사용)
    $fullName = clean($data['fullName'] ?? $data['name'] ?? null);
    if (!$fullName) {
        $meta = is_array($authUser['user_metadata'] ?? null) ? $authUser['user_metadata'] : [];
        $fullName = clean($meta['full_name'] ?? $meta['name'] ?? null);
    }
    if (!$fullName) {
        // 마지막 fallback — email local-part (사용자가 나중에 본인 정보에서 수정 가능)
        $fullName = substr($email, 0, strpos($email, '@') ?: strlen($email));
    }

    $phone = clean($data['phone'] ?? null);
    if (!$phone) {
        $meta = is_array($authUser['user_metadata'] ?? null) ? $authUser['user_metadata'] : [];
        $phone = clean($meta['phone'] ?? null);
    }

    // 사장님 2026-05-25 — 일반(이메일) 회원가입 + Google "가입 완료" 시 휴대폰 인증 토큰 강제.
    // signup-verify-otp 가 발급한 'signup_verified' 행 (10분 유효, 1회 사용) 매칭.
    // finalize=true (Google "가입 완료" 버튼) 또는 provider='email' (일반 회원가입) 일 때 검증.
    $signupProvider = strtolower(trim((string)($data['provider'] ?? '')));
    if (($signupProvider === 'email' || $isFinalize) && $phone !== null && $phone !== '') {
        $verificationToken = trim((string)($data['phoneVerificationToken'] ?? $data['phone_verification_token'] ?? ''));
        if ($verificationToken === '') {
            respond(['ok'=>false, 'error'=>'휴대폰 인증이 필요합니다.', 'reason'=>'phone_verification_required'], 403);
        }
        $phoneDigits = preg_replace('/\D/', '', (string)$phone);
        ensure_auth_otp_table($pdo);
        $vStmt = $pdo->prepare("SELECT id, expires_at FROM auth_otp WHERE target = :t AND purpose = 'signup_verified' AND code = :c LIMIT 1");
        $vStmt->execute([':t' => $phoneDigits, ':c' => $verificationToken]);
        $vRow = $vStmt->fetch();
        if (!$vRow) {
            respond(['ok'=>false, 'error'=>'휴대폰 인증 토큰이 유효하지 않습니다. 인증을 다시 진행해주세요.', 'reason'=>'phone_token_invalid'], 403);
        }
        if (strtotime($vRow['expires_at']) < time()) {
            $pdo->prepare('DELETE FROM auth_otp WHERE id = ?')->execute([$vRow['id']]);
            respond(['ok'=>false, 'error'=>'휴대폰 인증이 만료되었습니다 (10분). 다시 진행해주세요.', 'reason'=>'phone_token_expired'], 410);
        }
        // 1회 사용 — 검증 통과 후 즉시 삭제.
        $pdo->prepare('DELETE FROM auth_otp WHERE id = ?')->execute([$vRow['id']]);
    }

    $nickname = clean($data['nickname'] ?? null);
    if ($nickname !== null && !is_valid_nickname($nickname)) {
        respond(['ok' => false, 'error' => '닉네임 형식이 올바르지 않습니다.'], 400);
    }
    // Google 가입 시에는 휴대폰 번호를 필수가 아니게 변경 (사용자 요청: 구글 시스템 그대로 사용)

    $store = find_member_store($pdo);
    if (!$store) respond(['ok' => false, 'error' => 'members 또는 users 회원 테이블을 찾을 수 없습니다.'], 500);

    // PII 암호문(enc:v1: + base64) 을 저장하려면 VARCHAR(64) 같은 좁은 컬럼은 부족.
    // SQLSTATE 22001 ("Data too long") 방지 위해 name/phone/nickname 을 VARCHAR(255) 로 자동 확장.
    ensure_member_pii_columns_wide($pdo, $store);

    // idempotent 모드 — ?ensure=1 또는 body.ensure=true 면 이미 존재해도 OK (skip + 200 반환)
    $ensure = !empty($_GET['ensure']) || !empty($data['ensure']);
    if (member_exists_by_email($pdo, $email) === true) {
        if ($ensure) {
            // 기존 row 의 nickname/phone 도 함께 반환 → client 가 "추가 입력 필요" 판단 가능
            $existingNick = null;
            $existingPhonePlain = '';
            try {
                $nickColExisting = first_existing_column($store['columns'], ['nickname', 'nick', 'display_name']);
                $phoneColExisting = first_existing_column($store['columns'], ['phone', 'mobile', 'tel', 'contact', 'user_phone', 'mb_hp']);
                $selectCols = [];
                if ($nickColExisting)  $selectCols[] = quote_identifier($nickColExisting) . ' AS nk';
                if ($phoneColExisting) $selectCols[] = quote_identifier($phoneColExisting) . ' AS ph';
                if (!empty($selectCols)) {
                    $emailColQ = quote_identifier($store['email_column']);
                    $tableQ    = quote_identifier($store['table']);
                    $stmt = $pdo->prepare("SELECT " . implode(', ', $selectCols) . " FROM {$tableQ} WHERE LOWER({$emailColQ}) = :e LIMIT 1");
                    $stmt->execute([':e' => $email]);
                    $r = $stmt->fetch();
                    if ($r) {
                        if ($nickColExisting)  $existingNick = (string)($r['nk'] ?? '');
                        if ($phoneColExisting) {
                            // 암호화 저장이므로 복호화 시도 (평문도 호환)
                            $existingPhonePlain = function_exists('youngman_decrypt')
                                ? (string)youngman_decrypt((string)($r['ph'] ?? ''))
                                : (string)($r['ph'] ?? '');
                        }
                    }
                }
            } catch (Throwable $e) {}
            $needsPhone = (preg_replace('/\D/', '', $existingPhonePlain) === '');
            respond([
                'ok' => true,
                'already' => true,
                'nickname' => $existingNick,
                'needsNickname' => ($existingNick === null || trim((string)$existingNick) === ''),
                'needsPhone' => $needsPhone,
                'message' => '이미 가입된 계정 — 기존 행 유지',
            ]);
        }
        respond(['ok' => false, 'error' => '이미 가입된 계정입니다.'], 409);
    }
    if ($nickname !== null && nickname_taken($pdo, $store, $nickname)) {
        respond(['ok' => false, 'error' => '이미 사용 중인 닉네임입니다.'], 409);
    }

    $columns = $store['columns'];
    $row = [
        $store['email_column'] => $email,
    ];

    $nameColumn = first_existing_column($columns, ['name', 'full_name', 'user_name', 'username', 'mb_name']);
    if ($nameColumn) $row[$nameColumn] = $fullName;

    $phoneColumn = first_existing_column($columns, ['phone', 'mobile', 'tel', 'contact', 'user_phone', 'mb_hp']);
    if ($phoneColumn) $row[$phoneColumn] = $phone;

    $nicknameColumn = first_existing_column($columns, ['nickname', 'nick', 'display_name']);
    if ($nicknameColumn && $nickname !== null) $row[$nicknameColumn] = $nickname;

    $providerColumn = first_existing_column($columns, ['provider', 'signup_method', 'oauth_provider']);
    if ($providerColumn) $row[$providerColumn] = 'google';

    $authIdColumn = first_existing_column($columns, ['supabase_id', 'auth_user_id', 'oauth_id']);
    if ($authIdColumn && !empty($authUser['sub'])) $row[$authIdColumn] = $authUser['sub'];

    $statusColumn = first_existing_column($columns, ['status', 'member_status']);
    if ($statusColumn) $row[$statusColumn] = 'active';

    $roleColumn = first_existing_column($columns, ['role', 'member_role', 'user_role', 'level']);
    if ($roleColumn) $row[$roleColumn] = is_admin_email($email) ? 'admin' : 'member';

    $now = date('Y-m-d H:i:s');
    $createdColumn = first_existing_column($columns, ['created_at', 'created', 'registered_at', 'reg_date']);
    if ($createdColumn) $row[$createdColumn] = $now;
    $updatedColumn = first_existing_column($columns, ['updated_at', 'modified_at']);
    if ($updatedColumn) $row[$updatedColumn] = $now;

    // Drop null values so DB defaults (e.g. '') apply on NOT NULL columns.
    $row = array_filter($row, function ($v) { return $v !== null; });

    // 개인정보 컬럼 AES-256-GCM 암호화 (회원가입 시 저장 전)
    foreach (['name', 'phone'] as $sensitiveCol) {
        if (isset($row[$sensitiveCol]) && is_string($row[$sensitiveCol]) && $row[$sensitiveCol] !== '') {
            $row[$sensitiveCol] = youngman_encrypt($row[$sensitiveCol]);
        }
    }

    $fieldSql = implode(', ', array_map('quote_identifier', array_keys($row)));
    $placeholderSql = implode(', ', array_map(function ($column) {
        return ':' . $column;
    }, array_keys($row)));
    $sql = "INSERT INTO " . quote_identifier($store['table']) . " ({$fieldSql}) VALUES ({$placeholderSql})";
    $params = [];
    foreach ($row as $column => $value) {
        $params[':' . $column] = $value;
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
        // DB 제약 위반 / 컬럼 누락 등의 실제 사유를 client 에 노출 → 진단 가능
        respond([
            'ok'    => false,
            'error' => 'members 행 생성 실패: ' . $e->getMessage(),
            'sql_state' => $e->getCode(),
            'columns'   => array_keys($row),
            'table'     => $store['table'],
        ], 500);
    }

    respond([
        'ok' => true,
        'created' => true,
        'role' => is_admin_email($email) ? 'admin' : 'member',
        'nickname' => $nickname,
        // 신규 INSERT 시 nickname/phone 비어있으면 client 가 추가 입력 받음
        'needsNickname' => ($nickname === null || trim((string)$nickname) === ''),
        'needsPhone' => (preg_replace('/\D/', '', (string)$phone) === ''),
    ]);
}

/**
 * Supabase admin — 이메일로 user id 조회. service_role 키 필요.
 * 응답: user.id (string) 또는 null.
 */
function supabase_admin_find_user_id(string $base, string $serviceKey, string $email): ?string {
    if ($base === '' || $serviceKey === '' || $email === '') return null;
    $url = $base . '/auth/v1/admin/users?email=' . urlencode($email);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $serviceKey,
            'apikey: ' . $serviceKey,
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $http >= 400) return null;
    $data = json_decode($body, true);
    if (!is_array($data)) return null;
    // 응답 형식: { users: [...] } 또는 { id: ... } 또는 단일 객체
    $users = $data['users'] ?? null;
    if (is_array($users) && !empty($users[0]['id'])) return (string)$users[0]['id'];
    if (!empty($data['id'])) return (string)$data['id'];
    return null;
}

/**
 * Supabase admin — user 영구 삭제. service_role 키 필요.
 * Play 정책 — 사용자 계정 삭제 시 auth.users 도 함께 제거.
 */
function supabase_admin_delete_user(string $base, string $serviceKey, string $userId): bool {
    if ($base === '' || $serviceKey === '' || $userId === '') return false;
    $url = $base . '/auth/v1/admin/users/' . urlencode($userId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $serviceKey,
            'apikey: ' . $serviceKey,
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $http >= 400) {
        error_log('[supabase admin delete_user] http=' . $http . ' body=' . substr((string)$body, 0, 300));
        return false;
    }
    return true;
}

/**
 * 사용자 업로드 폴더 재귀 삭제 (account-delete 용). user_dir_segment = upload.php 와 동일 hash.
 */
function delete_user_upload_dir(string $email): int {
    $seg = 'u_' . substr(hash('sha256', strtolower(trim($email))), 0, 16);
    $deleted = 0;
    foreach ([__DIR__, dirname(__DIR__)] as $base) {
        foreach (['uploads', 'uploads/recordings'] as $sub) {
            $dir = $base . '/' . $sub . '/' . $seg;
            if (!is_dir($dir)) continue;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                if ($f->isFile()) { @unlink($f->getPathname()); $deleted++; }
                elseif ($f->isDir()) { @rmdir($f->getPathname()); }
            }
            @rmdir($dir);
        }
    }
    return $deleted;
}

/**
 * Supabase admin — user 비밀번호 변경. service_role 키 필요.
 */
function supabase_admin_set_password(string $base, string $serviceKey, string $userId, string $newPassword): bool {
    if ($base === '' || $serviceKey === '' || $userId === '') return false;
    $url = $base . '/auth/v1/admin/users/' . urlencode($userId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode(['password' => $newPassword], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $serviceKey,
            'apikey: ' . $serviceKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $http >= 400) {
        error_log('[supabase admin set_password] http=' . $http . ' body=' . substr((string)$body, 0, 300));
        return false;
    }
    return true;
}

/**
 * 휴대폰 마스킹 — 010-1234-**** 형태 (auth_otp 응답에 사용).
 */
function mask_phone($phone) {
    $digits = preg_replace('/\D/', '', (string)$phone);
    if (strlen($digits) >= 8) {
        return substr($digits, 0, 3) . '-' . substr($digits, 3, 4) . '-' . str_repeat('*', max(0, strlen($digits) - 7));
    }
    return $digits;
}

/**
 * 인증번호 저장용 auth_otp 테이블 자동 생성.
 */
function ensure_auth_otp_table(PDO $pdo) {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_otp (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purpose VARCHAR(32) NOT NULL DEFAULT 'find_email',
            target VARCHAR(32) NOT NULL,
            code VARCHAR(64) NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            INDEX idx_otp_target_purpose (target, purpose),
            INDEX idx_otp_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // 사장님 2026-05-25 — 옛 row 의 code VARCHAR(8) 이면 lazy ALTER.
        // signup_verified / find_pwd_reset token 은 48 hex chars (random 24 bytes) 라 8자로는 부족.
        try {
            $pdo->exec("ALTER TABLE auth_otp MODIFY code VARCHAR(64) NOT NULL");
        } catch (Throwable $e) {
            // ALTER 실패해도 silent — 이미 충분히 큰 경우.
        }
        // GC — 만료된 OTP 정리 (request 당 가벼움)
        $pdo->exec("DELETE FROM auth_otp WHERE expires_at < NOW()");
    } catch (Throwable $e) {
        // silent — 다음 INSERT 시 에러 노출
    }
}

/**
 * 관리자(admin) 계정의 Solapi 자격증명을 사용해 OTP SMS 발송.
 * 일반 사용자 lookup 용으로 익명 호출되므로 sender 는 사이트 관리자 본인 계정.
 */
function send_otp_sms_via_admin(PDO $pdo, $purpose, $targetPhone) {
    // 관리자 이메일 후보 — admin_email_allowlist 의 첫 값
    $adminEmail = admin_email_allowlist()[0] ?? '';
    if ($adminEmail === '') return ['ok'=>false, 'error'=>'관리자 정보 없음', 'status'=>500];

    try {
        $stmt = $pdo->prepare("SELECT api_key_enc, api_secret_enc, sender_phone_enc, provider
                              FROM sms_credentials WHERE owner_email = :o LIMIT 1");
        $stmt->execute([':o' => strtolower($adminEmail)]);
        $cred = $stmt->fetch();
    } catch (Throwable $e) {
        return ['ok'=>false, 'error'=>'관리자 SMS 자격증명 조회 실패', 'status'=>500];
    }
    if (!$cred) return ['ok'=>false, 'error'=>'관리자 Solapi 미연동 — 사이트 운영자에게 문의해주세요.', 'status'=>503];

    $apiKey = function_exists('youngman_decrypt') ? youngman_decrypt((string)$cred['api_key_enc']) : (string)$cred['api_key_enc'];
    $apiSec = function_exists('youngman_decrypt') ? youngman_decrypt((string)$cred['api_secret_enc']) : (string)$cred['api_secret_enc'];
    $sender = preg_replace('/\D/', '', (string)(function_exists('youngman_decrypt') ? youngman_decrypt((string)$cred['sender_phone_enc']) : (string)$cred['sender_phone_enc']));
    if (!$apiKey || !$apiSec || !$sender) return ['ok'=>false, 'error'=>'관리자 SMS 자격증명 불완전', 'status'=>503];

    // 인증번호 생성
    try {
        $code = sprintf('%06d', random_int(0, 999999));
    } catch (Throwable $e) {
        $code = sprintf('%06d', mt_rand(0, 999999));
    }

    // 기존 OTP 삭제 후 새로 저장
    try {
        $pdo->prepare('DELETE FROM auth_otp WHERE target = ? AND purpose = ?')->execute([$targetPhone, $purpose]);
        $pdo->prepare("INSERT INTO auth_otp (purpose, target, code, expires_at)
                       VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))")
            ->execute([$purpose, $targetPhone, $code]);
    } catch (Throwable $e) {
        return ['ok'=>false, 'error'=>'인증번호 저장 실패: ' . $e->getMessage(), 'status'=>500];
    }

    // Solapi 발송
    require_once __DIR__ . '/sms/providers/SmsProvider.php';
    require_once __DIR__ . '/sms/providers/SolapiProvider.php';
    try {
        $provider = new SolapiProvider(['api_key' => $apiKey, 'api_secret' => $apiSec]);
        // 사장님 2026-05-25 — purpose 별 사용자 친화 메시지.
        $purposeLabel = (function ($p) {
            if ($p === 'signup')   return '회원가입';
            if ($p === 'find_pwd') return '비밀번호 찾기';
            return '아이디 찾기';
        })($purpose);
        $msg = "[영맨] {$purposeLabel} 인증번호: {$code} (5분 안에 입력해주세요)";
        $result = $provider->sendBulk([['to' => $targetPhone, 'text' => $msg]], $sender, []);
        if (!isset($result['success']) || $result['success'] < 1) {
            $reason = '';
            if (!empty($result['failed'][0]['error'])) $reason = ' (' . $result['failed'][0]['error'] . ')';
            return ['ok'=>false, 'error'=>'SMS 발송 실패' . $reason, 'status'=>502];
        }
        return ['ok'=>true];
    } catch (Throwable $e) {
        return ['ok'=>false, 'error'=>'Solapi 호출 오류: ' . $e->getMessage(), 'status'=>500];
    }
}

/**
 * members 테이블의 PII 암호화 대상 컬럼을 VARCHAR(255) 로 자동 확장.
 * AES-256-GCM 결과 'enc:v1:<base64IV>:<base64ct>:<base64tag>' 는 100~200 chars.
 * 좁은 VARCHAR(20~64) 컬럼이면 SQLSTATE 22001 (Data too long) 으로 INSERT 실패.
 *
 * idempotent — 이미 충분히 크면 skip. ALTER 실패해도 silent (사용자 INSERT 시 명확한 에러).
 */
function ensure_member_pii_columns_wide(PDO $pdo, $store) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $table = $store['table'];
    $targetCols = ['name', 'full_name', 'phone', 'mobile', 'tel', 'nickname', 'nick', 'display_name'];

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM " . quote_identifier($table))->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return;   // SHOW COLUMNS 실패 — silent
    }
    foreach ($cols as $col) {
        $name = strtolower((string)($col['Field'] ?? ''));
        $type = strtolower((string)($col['Type'] ?? ''));
        if (!in_array($name, $targetCols, true)) continue;
        // VARCHAR(N) 이고 N < 255 이면 확장. TEXT 종류는 충분히 크므로 skip.
        if (preg_match('/^varchar\((\d+)\)/i', $type, $m)) {
            $width = (int)$m[1];
            if ($width < 255) {
                try {
                    $sql = "ALTER TABLE " . quote_identifier($table) . " MODIFY " . quote_identifier($col['Field']) . " VARCHAR(255)";
                    $pdo->exec($sql);
                } catch (Throwable $e) {
                    // ALTER 실패해도 silent — 다음 INSERT 시 명확한 에러 발생.
                }
            }
        }
    }
}

function enforce_registered_member(PDO $pdo, $authUser) {
    if (!$authUser) return;

    $email = (string)($authUser['email'] ?? '');
    if ($email === '') return;

    $registered = member_exists_by_email($pdo, $email);
    if ($registered === null) return;
    if (!$registered) respond(['ok' => false, 'error' => '가입된 회원만 이용할 수 있습니다.'], 403);
}

function format_date($value) {
    if (!$value) return '';
    $ts = is_numeric($value) ? (int)$value : strtotime((string)$value);
    if (!$ts) return '';
    return date('Y. m. d.', $ts);
}

function member_row_from_store($store, $row) {
    $cols = $store['columns'];
    $emailCol = $store['email_column'];
    $nameCol = first_existing_column($cols, ['name', 'full_name', 'user_name', 'username', 'mb_name']);
    $nicknameCol = first_existing_column($cols, ['nickname', 'nick', 'display_name']);
    $phoneCol = first_existing_column($cols, ['phone', 'mobile', 'tel', 'contact', 'user_phone', 'mb_hp']);
    $providerCol = first_existing_column($cols, ['provider', 'signup_method', 'oauth_provider']);
    $statusCol = first_existing_column($cols, ['status', 'member_status']);
    $roleCol = first_existing_column($cols, ['role', 'member_role', 'user_role', 'level']);
    $createdCol = first_existing_column($cols, ['created_at', 'created', 'registered_at', 'reg_date']);
    $updatedCol = first_existing_column($cols, ['updated_at', 'modified_at']);
    $lastLoginCol = first_existing_column($cols, ['last_login_at', 'last_login', 'login_at', 'last_active_at']);

    $role = $roleCol ? (string)($row[$roleCol] ?? '') : '';
    $status = $statusCol ? (string)($row[$statusCol] ?? '') : 'active';

    // 개인정보 컬럼 복호화 — 평문도 호환 (lazy migration)
    $decName  = $nameCol  ? youngman_decrypt($row[$nameCol]  ?? '') : '';
    $decPhone = $phoneCol ? youngman_decrypt($row[$phoneCol] ?? '') : '';

    // 구독 결제 — 컬럼 있으면 응답에 포함 (admin 편집 모달 prefill 용).
    $planCol = first_existing_column($cols, ['plan']);
    $planStatusCol = first_existing_column($cols, ['plan_status']);
    $summaryUsedCol = first_existing_column($cols, ['free_summaries_used', 'summary_used']);
    $summaryLimitCol = first_existing_column($cols, ['summary_limit']);
    $summaryLimitMinutesCol = first_existing_column($cols, ['summary_limit_minutes']);
    $usageSecondsCol = first_existing_column($cols, ['usage_seconds_period']);
    $overageEnabledCol = first_existing_column($cols, ['overage_enabled']);
    $overageBalanceCol = first_existing_column($cols, ['overage_balance_seconds']);
    $overageTopUpCountCol = first_existing_column($cols, ['overage_top_up_count']);
    $overageLastTopUpAtCol = first_existing_column($cols, ['overage_last_top_up_at']);
    $periodEndCol = first_existing_column($cols, ['current_period_end']);

    return [
        'email' => $row[$emailCol] ?? '',
        'name' => $decName,
        'nickname' => $nicknameCol ? ($row[$nicknameCol] ?? '') : '',
        'phone' => $decPhone,
        'provider' => $providerCol ? ($row[$providerCol] ?? 'email') : 'email',
        'status' => $status === '' ? 'active' : strtolower($status),
        'role' => $role === '' ? 'member' : strtolower($role),
        'plan' => $planCol ? ((function($p){
            // 사장님 2026-05-26 — 옛 plan key 정규화 (sales/master/agency 신규 요금제).
            $p = (string)$p;
            if ($p === 'trialing') return 'free';
            if ($p === 'plus')     return 'sales';
            if ($p === 'premium')  return 'sales';
            if ($p === 'pro')      return 'master';
            return $p;
        })((string)($row[$planCol] ?? 'free'))) : 'free',
        'plan_status' => $planStatusCol ? ((function($s){ return $s === 'trialing' ? 'active' : $s; })((string)($row[$planStatusCol] ?? 'active'))) : 'active',
        // 레거시 회 단위
        'summary_used' => $summaryUsedCol ? (int)($row[$summaryUsedCol] ?? 0) : 0,
        'summary_limit' => $summaryLimitCol ? ($row[$summaryLimitCol] === null ? null : (int)$row[$summaryLimitCol]) : null,
        // Phase 2 분 단위
        'summary_limit_minutes' => $summaryLimitMinutesCol ? ($row[$summaryLimitMinutesCol] === null ? null : (int)$row[$summaryLimitMinutesCol]) : null,
        'usage_seconds_period' => $usageSecondsCol ? (int)($row[$usageSecondsCol] ?? 0) : 0,
        'overage_enabled' => $overageEnabledCol ? (int)($row[$overageEnabledCol] ?? 0) : 0,
        'overage_balance_seconds' => $overageBalanceCol ? (int)($row[$overageBalanceCol] ?? 0) : 0,
        'overage_top_up_count' => $overageTopUpCountCol ? (int)($row[$overageTopUpCountCol] ?? 0) : 0,
        'overage_last_top_up_at' => $overageLastTopUpAtCol ? format_date($row[$overageLastTopUpAtCol] ?? '') : '',
        'current_period_end' => $periodEndCol ? format_date($row[$periodEndCol] ?? '') : '',
        'createdAt' => $createdCol ? format_date($row[$createdCol] ?? '') : '',
        'updatedAt' => $updatedCol ? format_date($row[$updatedCol] ?? '') : '',
        'lastLoginAt' => $lastLoginCol ? format_date($row[$lastLoginCol] ?? '') : '',
    ];
}

function fetch_member_by_email(PDO $pdo, $email) {
    $email = strtolower(trim((string)$email));
    if ($email === '') return null;

    $store = find_member_store($pdo);
    if (!$store) return null;

    $table = quote_identifier($store['table']);
    $emailCol = quote_identifier($store['email_column']);
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE LOWER({$emailCol}) = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return ['store' => $store, 'row' => $row];
}

function is_admin_user($authUser, $memberRecord = null) {
    if (!$authUser) return false;

    $appMeta = (array)($authUser['app_metadata'] ?? []);
    $userMeta = (array)($authUser['user_metadata'] ?? []);
    $role = strtolower((string)($appMeta['role'] ?? $userMeta['role'] ?? ''));
    if (in_array($role, ['admin', 'owner', 'superadmin'], true)) return true;
    if (!empty($appMeta['is_admin']) || !empty($userMeta['is_admin'])) return true;

    if ($memberRecord) {
        $member = member_row_from_store($memberRecord['store'], $memberRecord['row']);
        $memberRole = strtolower((string)$member['role']);
        if (in_array($memberRole, ['admin', 'owner', 'superadmin'], true)) return true;
    }

    return false;
}

function enforce_admin(PDO $pdo, $authUser) {
    if (!$authUser) respond(['ok' => false, 'error' => '로그인이 필요합니다.'], 401);

    $email = (string)($authUser['email'] ?? '');
    $memberRecord = $email !== '' ? fetch_member_by_email($pdo, $email) : null;
    if (!is_admin_user($authUser, $memberRecord)) {
        respond(['ok' => false, 'error' => '관리자 권한이 필요합니다.'], 403);
    }
}

function ensure_activity_log_table(PDO $pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            actor_email VARCHAR(255) NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            detail VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_logs_created (created_at),
            INDEX idx_logs_actor (actor_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_site_settings_table(PDO $pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(64) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function record_activity(PDO $pdo, $email, $eventType, $detail = null) {
    if (!ensure_activity_log_table($pdo)) return;
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (actor_email, event_type, detail) VALUES (:email, :event, :detail)");
        $stmt->execute([
            ':email' => (string)$email,
            ':event' => substr((string)$eventType, 0, 64),
            ':detail' => $detail === null ? null : substr((string)$detail, 0, 500),
        ]);
    } catch (Throwable $e) {
        // Non-fatal — never block user actions on logging failure.
    }
}

/**
 * 보안: customers / employees 테이블에 owner_email 컬럼이 없으면 자동으로 추가한다.
 * 기존 행은 owner_email = NULL 로 남고, 일반 조회에서 자동으로 숨겨진다.
 * 한번 실행되면 정적 캐시에 기록해 같은 요청 내에서 반복 호출되지 않는다.
 */
function ensure_owner_column(PDO $pdo, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    try {
        $cols = table_columns($pdo, $table);
        if (!in_array('owner_email', $cols, true)) {
            $pdo->exec("ALTER TABLE " . quote_identifier($table)
                . " ADD COLUMN owner_email VARCHAR(255) NULL DEFAULT NULL,"
                . " ADD INDEX idx_{$table}_owner_email (owner_email)");
        }
        return $cache[$table] = true;
    } catch (Throwable $e) {
        // ALTER 실패 시 (권한/락) — 보안 우선 정책이라 false 로 false-close.
        // 호출부에서 false 면 모든 데이터 거부하도록 처리.
        error_log('[records] ensure_owner_column failed for ' . $table . ': ' . $e->getMessage());
        return $cache[$table] = false;
    }
}

/** 현재 사용자의 owner 식별자 (이메일 소문자). 빈 문자열이면 인증 실패로 간주. */
function current_owner_email($authUser): string {
    return strtolower((string)($authUser['email'] ?? ''));
}

/* ============================================================
   계약자 / 조직도 / 고객 관리대장 — 공통 ledger 시스템 (Phase 1)
   ============================================================ */

/**
 * 그룹과 레코드 테이블 자동 마이그레이션.
 * 한 요청 내에서 한 번만 실행 (정적 캐시).
 */
function ensure_ledger_tables(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ledger_groups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_email VARCHAR(255) NOT NULL,
                page_type VARCHAR(20) NOT NULL,
                name VARCHAR(120) NOT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                field_schema_json LONGTEXT NULL,
                settings_json LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_lg_owner_page (owner_email, page_type),
                INDEX idx_lg_owner_default (owner_email, is_default)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ledger_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                group_id INT NOT NULL,
                owner_email VARCHAR(255) NOT NULL,
                sort_no INT NOT NULL DEFAULT 0,
                data_json LONGTEXT NULL,
                client_idempotency_key VARCHAR(120) NULL DEFAULT NULL,
                source VARCHAR(40) NOT NULL DEFAULT 'web',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_lr_group_sort (group_id, sort_no),
                INDEX idx_lr_owner (owner_email),
                UNIQUE KEY uniq_lr_idempotency (owner_email, client_idempotency_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return $done = true;
    } catch (Throwable $e) {
        error_log('[records] ensure_ledger_tables failed: ' . $e->getMessage());
        return $done = false;
    }
}

/** 페이지 타입 검증. 허용된 값만 통과. */
function valid_ledger_page_type(string $v): bool {
    return in_array($v, ['contract', 'org', 'customer', 'custom'], true);
}

/** 그룹 행 → 응답 형태로 변환. JSON 컬럼은 AES-256-GCM 복호화 (평문도 호환). */
function ledger_group_row(array $row): array {
    return [
        'id'           => (int)$row['id'],
        'pageType'     => $row['page_type'],
        'name'         => $row['name'],
        'isDefault'    => (bool)$row['is_default'],
        // is_main — 앱팀 요청 (통화종료 모달 chip picker 기본 선택용).
        // 현재는 isDefault 의 snake_case alias. owner+page_type 내 1개만 true (PATCH 시 자동 분기).
        'is_main'      => (bool)$row['is_default'],
        'sortOrder'    => (int)$row['sort_order'],
        'fieldSchema'  => !empty($row['field_schema_json']) ? youngman_decrypt_json($row['field_schema_json']) : null,
        'settings'     => !empty($row['settings_json'])     ? youngman_decrypt_json($row['settings_json'])     : null,
        'createdAt'    => $row['created_at'] ?? null,
        'updatedAt'    => $row['updated_at'] ?? null,
    ];
}

/** 레코드 행 → 응답 형태. data_json 은 AES-256-GCM 복호화 (평문도 호환). */
function ledger_record_row(array $row): array {
    $data = new stdClass();
    if (!empty($row['data_json'])) {
        $decoded = youngman_decrypt_json($row['data_json']);
        $data = ($decoded === null) ? new stdClass() : $decoded;
    }
    return [
        'id'         => (int)$row['id'],
        'groupId'    => (int)$row['group_id'],
        'sortNo'     => (int)$row['sort_no'],
        'data'       => $data,
        'source'     => $row['source'] ?? 'web',
        'createdAt'  => $row['created_at'] ?? null,
        'updatedAt'  => $row['updated_at'] ?? null,
    ];
}

/* ============================================================
   customer_log — 통화 녹취 AI 요약 (앱 자동 입력 + 웹 수동 편집)
   ============================================================ */

/** 무료 플랜 요약 횟수 한도. CALL_RECORDING_BACKEND.md §1 확정값. */
function customer_log_free_quota(): int { return 5; }

/**
 * customer_log 테이블 자동 마이그레이션. 한 요청 내 한 번만 실행.
 * 모든 PII 컬럼은 AES-256-GCM 으로 암호화 저장 (enc:v1: prefix).
 */
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
        // 옵션 D — 양식 전송 시 ledger_records 와 1:1 link. 기존 테이블에 없으면 ADD.
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM customer_log")->fetchAll(PDO::FETCH_ASSOC);
            $hasLink = false;
            $hasRegion = false;
            foreach ($cols as $c) {
                $f = $c['Field'] ?? '';
                if ($f === 'linked_ledger_record_id') $hasLink = true;
                if ($f === 'region') $hasRegion = true;
            }
            if (!$hasLink) {
                $pdo->exec("ALTER TABLE customer_log
                    ADD COLUMN linked_ledger_record_id INT NULL DEFAULT NULL,
                    ADD INDEX idx_cl_linked (linked_ledger_record_id)");
            }
            // 사장님 2026-05-24 — 고객 거주지 자동 인식. AES-256-GCM 암호문 저장.
            if (!$hasRegion) {
                $pdo->exec("ALTER TABLE customer_log ADD COLUMN region VARCHAR(255) NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {
            error_log('[records] customer_log ALTER linked_ledger_record_id/region failed: ' . $e->getMessage());
        }
        return $done = true;
    } catch (Throwable $e) {
        error_log('[records] ensure_customer_log_table failed: ' . $e->getMessage());
        return $done = false;
    }
}

/**
 * members 테이블에 plan/free_summaries_used 컬럼이 없으면 추가.
 * 기존 members 행은 default 값 ('free', 0) 으로 채워짐.
 */
function ensure_members_plan_columns(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $cols = table_columns($pdo, 'members');
        if (!in_array('plan', $cols, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `plan` VARCHAR(16) NOT NULL DEFAULT 'free'");
        }
        if (!in_array('free_summaries_used', $cols, true)) {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `free_summaries_used` INT NOT NULL DEFAULT 0");
        }
        return $done = true;
    } catch (Throwable $e) {
        error_log('[records] ensure_members_plan_columns failed: ' . $e->getMessage());
        return $done = false;
    }
}

/**
 * phone_number → 블라인드 인덱스 키 (PII 평문 노출 없이 lookup 가능).
 * 마스터 키가 있으면 HMAC-SHA256, 없으면 plain SHA256 으로 폴백.
 */
function customer_phone_lookup_key(?string $phone): ?string {
    if ($phone === null) return null;
    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === '') return null;
    $key = function_exists('youngman_master_key') ? youngman_master_key() : null;
    return $key ? hash_hmac('sha256', $digits, $key) : hash('sha256', $digits);
}

/**
 * customer_log row → 응답 형태 변환. PII 컬럼 모두 복호화 (평문도 호환).
 * 앱은 평문만 받음.
 */
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
        'region'              => youngman_decrypt($row['region'] ?? null),
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

/**
 * 옵션 D — customer-log → ledger_records 전송용 기본 그룹의 field_schema.
 * 5필드 매핑 (앱팀 요청 — customers.html ledger UI 가 인식하는 key 명):
 *   managed     ← true (앱 측은 항상 관리중으로 시작 — 사용자가 웹 ledger 에서 수동 해제 가능)
 *   date        ← consult_at
 *   customer    ← customer_name
 *   phone       ← phone_number
 *   call_count  ← 자동 계산 (같은 group 내 정규화된 phone 매칭 카운트 + 1)
 *   content     ← summary + interest + inquiry (라벨로 구분, 줄바꿈)
 *   agent_memo  ← agent_memo (앱 SummaryReview 모달의 "담당자 메모" 입력값)
 *   memo        ← '' (비고 — 사용자가 웹 ledger 에서 직접 입력하는 자유 메모)
 * (budget_condition / next_action / transcript 는 매핑 미적용 — 추후 합의 따라 content 에 추가 가능)
 */
/**
 * 사장님 2026-05-24 — ledger group 의 field_schema 에서 "지역" 필드 key 찾기.
 * 1) strict match (label="지역" / key="region"/"지역")
 * 2) loose match (label 에 "지역" 포함, key 에 "region"/"area" 포함)
 * 3) 못 찾으면 null — caller 가 client-side DEFAULT_FIELDS 의 'region' fallback 사용.
 *
 * 사장님 그룹은 server-side default schema (region 미포함) 로 생성됐고 client-side
 * DEFAULT_FIELDS (region 포함) 가 UI 를 그리는 구조 → server schema 에는 region 정의 없음.
 */
function find_region_field_key(?array $fieldSchema): ?string {
    if (!is_array($fieldSchema)) return null;
    foreach ($fieldSchema as $f) {
        if (!is_array($f)) continue;
        $label = trim((string)($f['label'] ?? ''));
        $key = trim((string)($f['key'] ?? ''));
        if ($key === '') continue;
        // strict match
        if ($label === '지역' || $key === 'region' || $key === '지역') return $key;
        // loose match (옛 그룹 schema 변형 호환)
        if (mb_strpos($label, '지역') !== false) return $key;
        if (stripos($key, 'region') !== false || stripos($key, 'area') !== false) return $key;
    }
    return null;
}

/**
 * region 매핑용 데이터 key 결정.
 * - schema 에서 찾으면 그 key, 못 찾으면 'region' fallback.
 * - 'region' fallback 이 안전한 이유: customers.js DEFAULT_FIELDS 가 항상 region 컬럼을
 *   data['region'] 에서 읽음. 즉 schema 정의 없어도 UI 가 자동 표시.
 */
function resolve_region_data_key(?array $fieldSchema): string {
    return find_region_field_key($fieldSchema) ?? 'region';
}

function customer_log_default_group_field_schema(): array {
    return [
        ['key' => 'managed',    'label' => '관리',         'type' => 'manage_switch'],
        ['key' => 'date',       'label' => '날짜',         'type' => 'date'],
        ['key' => 'call_count', 'label' => '통화수',       'type' => 'call_count'],
        ['key' => 'customer',   'label' => '고객명',       'type' => 'text'],
        ['key' => 'phone',      'label' => '연락처',       'type' => 'text'],
        ['key' => 'region',     'label' => '지역',         'type' => 'text'],
        ['key' => 'content',    'label' => '상담 내용',    'type' => 'textarea'],
        ['key' => 'agent_memo', 'label' => '담당자 메모',  'type' => 'textarea'],
        ['key' => 'memo',       'label' => '비고',         'type' => 'text'],
    ];
}

/**
 * 같은 owner_email + 정규화 phone 의 unlinked customer_log 들을 batch 로 link 갱신.
 * send_to_group 호출 시 catch-up — 한 row 가 ledger 에 미러되면 같은 phone 의
 * 다른 미전송 row 들도 같은 ledger_record 로 자동 연결 (앱의 미전송 모달 정리용).
 * 본인 customer_log id 는 제외 (이미 별도 UPDATE 됨).
 * 반환: 새로 link 갱신된 row 수.
 */
function backfill_same_phone_links(PDO $pdo, string $ownerEmail, string $normalizedPhone, int $ledgerRecordId, string $currentCustomerLogId): int {
    if ($normalizedPhone === '' || $ledgerRecordId <= 0) return 0;
    try {
        $stmt = $pdo->prepare("SELECT id, phone_number FROM customer_log
            WHERE owner_email = :o AND linked_ledger_record_id IS NULL");
        $stmt->execute([':o' => $ownerEmail]);
        $matchIds = [];
        while ($r = $stmt->fetch()) {
            $p = youngman_decrypt($r['phone_number'] ?? '');
            $pn = preg_replace('/[^0-9]/', '', (string)$p);
            if ($pn !== '' && $pn === $normalizedPhone && (string)$r['id'] !== $currentCustomerLogId) {
                $matchIds[] = (string)$r['id'];
            }
        }
        if (empty($matchIds)) return 0;
        $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
        $upd = $pdo->prepare("UPDATE customer_log
            SET linked_ledger_record_id = ?
            WHERE id IN ($placeholders) AND owner_email = ? AND linked_ledger_record_id IS NULL");
        $upd->execute(array_merge([$ledgerRecordId], $matchIds, [$ownerEmail]));
        return (int)$upd->rowCount();
    } catch (Throwable $e) {
        error_log('[records] backfill_same_phone_links failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * 같은 group 내에서 정규화된 phone (숫자만) 이 일치하는 ledger_records 카운트 + 1.
 * data_json AES-256-GCM 복호화 후 phone 정규화 비교. 같은 사람과의 N번째 통화 표시용.
 * phone 빈 값이면 1 반환 (단독 row).
 */
function calculate_call_count(PDO $pdo, int $groupId, string $ownerEmail, string $phone): int {
    $normalized = preg_replace('/[^0-9]/', '', (string)$phone);
    if ($normalized === '') return 1;
    try {
        $stmt = $pdo->prepare('SELECT data_json FROM ledger_records WHERE group_id = :g AND owner_email = :o');
        $stmt->execute([':g' => $groupId, ':o' => $ownerEmail]);
        $count = 1;
        while ($row = $stmt->fetch()) {
            $d = !empty($row['data_json']) ? youngman_decrypt_json($row['data_json']) : null;
            if (is_array($d)) {
                $p = preg_replace('/[^0-9]/', '', (string)($d['phone'] ?? ''));
                if ($p !== '' && $p === $normalized) $count++;
            }
        }
        return $count;
    } catch (Throwable $e) {
        error_log('[records] calculate_call_count failed: ' . $e->getMessage());
        return 1;
    }
}

/**
 * 옵션 D — owner 의 customer page_type 그룹 중 default(또는 첫 번째) 반환.
 * 없으면 자동 생성 (제목 "그룹제목을 설정해주세요", is_default=1, 9필드 schema).
 */
function ensure_customer_log_default_group(PDO $pdo, string $owner): ?array {
    if (!ensure_ledger_tables($pdo)) return null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM ledger_groups
            WHERE owner_email = :o AND page_type = 'customer'
            ORDER BY is_default DESC, id ASC LIMIT 1");
        $stmt->execute([':o' => $owner]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Lazy 마이그레이션 — 옛 schema 자동 갱신.
            // 판별: field_schema_json 에 'call_count' key 가 없으면 모든 구버전 (옛 9 / 5 / 6 필드)
            // → 새 8필드 (managed/date/customer/phone/call_count/content/agent_memo/memo) 로 자동 갱신.
            $rawSchema = !empty($existing['field_schema_json']) ? youngman_decrypt_json($existing['field_schema_json']) : null;
            $needsMigration = false;
            if (is_array($rawSchema) && !empty($rawSchema[0])) {
                $existingKeys = array_map(fn($f) => is_array($f) ? ($f['key'] ?? '') : '', $rawSchema);
                if (!in_array('call_count', $existingKeys, true)) {
                    $needsMigration = true;
                }
            }
            if ($needsMigration) {
                $newSchema = customer_log_default_group_field_schema();
                $pdo->prepare('UPDATE ledger_groups SET field_schema_json = :fs WHERE id = :id AND owner_email = :o')
                    ->execute([':fs' => youngman_encrypt_json($newSchema), ':id' => (int)$existing['id'], ':o' => $owner]);
                $sel = $pdo->prepare('SELECT * FROM ledger_groups WHERE id = :id LIMIT 1');
                $sel->execute([':id' => (int)$existing['id']]);
                return $sel->fetch() ?: $existing;
            }
            return $existing;
        }

        // 새 그룹 — 5필드 schema.
        $schema = customer_log_default_group_field_schema();
        $ins = $pdo->prepare("INSERT INTO ledger_groups
            (owner_email, page_type, name, is_default, sort_order, field_schema_json, settings_json)
            VALUES (:o, 'customer', :n, 1, 0, :fs, NULL)");
        $ins->execute([
            ':o'  => $owner,
            ':n'  => '그룹제목을 설정해주세요',
            ':fs' => youngman_encrypt_json($schema),
        ]);
        $newId = (int)$pdo->lastInsertId();
        $sel = $pdo->prepare('SELECT * FROM ledger_groups WHERE id = :id LIMIT 1');
        $sel->execute([':id' => $newId]);
        return $sel->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('[records] ensure_customer_log_default_group failed: ' . $e->getMessage());
        return null;
    }
}

/* ============================================================
   Phase 2 — call-recording async + FCM 인프라
   ============================================================ */

/**
 * FCM 푸시 토큰 테이블. 앱이 받은 토큰을 owner_email 별로 저장.
 * 같은 토큰이 다른 owner 로 재등록되면 UNIQUE 충돌 → ON DUPLICATE KEY UPDATE 로 owner 갱신.
 */
function ensure_user_fcm_tokens_table(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_fcm_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_email VARCHAR(255) NOT NULL,
                token VARCHAR(512) NOT NULL,
                device_id VARCHAR(120) NULL DEFAULT NULL,
                platform VARCHAR(16) NOT NULL DEFAULT 'android',
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_uft_token (token),
                INDEX idx_uft_owner (owner_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return $done = true;
    } catch (Throwable $e) {
        error_log('[records] ensure_user_fcm_tokens_table failed: ' . $e->getMessage());
        return $done = false;
    }
}

/**
 * 비동기 통화녹취 처리 작업 큐. async mode 호출 시 즉시 job_id 응답 + 백그라운드 처리.
 * status: queued | processing | completed | failed
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
        // lazy ALTER — 기존 테이블 schema 동기화 (process-recording.php 와 동일).
        $cols = [];
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM recording_jobs")->fetchAll() as $c) $cols[] = $c['Field'];
        } catch (Throwable $e) {}
        $needAlter = [
            'audio_sha256'           => 'CHAR(64) NULL DEFAULT NULL',
            'duration_sec'           => 'INT NOT NULL DEFAULT 0',
            'customer_name_hint'     => 'VARCHAR(80) NULL DEFAULT NULL',
            'phone_number'           => 'VARCHAR(60) NULL DEFAULT NULL',
            'recorded_at'            => 'DATETIME NULL DEFAULT NULL',
            'retry_count'            => 'INT NOT NULL DEFAULT 0',
            'transcript_encrypted'   => 'LONGTEXT NULL DEFAULT NULL',
            'summary_json_encrypted' => 'LONGTEXT NULL DEFAULT NULL',
            'progress_pct'           => 'TINYINT NOT NULL DEFAULT 0',
            'group_id'               => 'VARCHAR(36) NULL DEFAULT NULL',
            'review_required'        => 'TINYINT(1) NOT NULL DEFAULT 0',
            'response_elapsed_ms'    => 'INT NULL DEFAULT NULL',
            // 사장님 2026-05-23 — "양식으로 전송" 자동 confirm. trigger_summarize 시 1 설정 → callback 이 ready_to_review 대신 자동 confirm.
            'auto_confirm'           => 'TINYINT(1) NOT NULL DEFAULT 0',
            // 사장님 2026-06-01 — 사용량 차감 멱등 키. "요약보기"/"양식전송" 첫 클릭 시 NOW() 채움.
            'usage_counted_at'       => 'DATETIME NULL DEFAULT NULL',
        ];
        foreach ($needAlter as $col => $def) {
            if (!empty($cols) && !in_array($col, $cols, true)) {
                try { $pdo->exec("ALTER TABLE recording_jobs ADD COLUMN `{$col}` {$def}"); }
                catch (Throwable $e) { error_log('[records] ALTER recording_jobs.' . $col . ': ' . $e->getMessage()); }
            }
        }
        // status 컬럼 길이 확장 (옛 16→20, ready_to_review/failed_retryable 등 수용)
        if (!empty($cols) && in_array('status', $cols, true)) {
            try { $pdo->exec("ALTER TABLE recording_jobs MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'queued'"); }
            catch (Throwable $e) {}
        }
        return $done = true;
    } catch (Throwable $e) {
        error_log('[records] ensure_recording_jobs_table failed: ' . $e->getMessage());
        return $done = false;
    }
}

/* ============================================================
   구독 결제 시스템 (PortOne + 토스페이먼츠)
   subscriptions / payments / usage_logs — lazy CREATE.
   ============================================================ */

/**
 * 구독 row — 한 사용자가 활성 구독 1개 (active/past_due/cancelled).
 * 결제 webhook 또는 cron 갱신 시 status / period 만 UPDATE.
 */
function ensure_subscriptions_table(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_email VARCHAR(255) NOT NULL,
                plan VARCHAR(16) NOT NULL DEFAULT 'free',
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                portone_customer_id VARCHAR(64) NULL DEFAULT NULL,
                portone_billing_key VARCHAR(128) NULL DEFAULT NULL,
                portone_subscription_id VARCHAR(64) NULL DEFAULT NULL,
                current_period_start DATETIME NULL DEFAULT NULL,
                current_period_end DATETIME NULL DEFAULT NULL,
                cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_sub_owner (owner_email),
                INDEX idx_sub_status (status),
                INDEX idx_sub_period_end (current_period_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return $done = true;
    } catch (Throwable $e) {
        error_log('[records] ensure_subscriptions_table failed: ' . $e->getMessage());
        return $done = false;
    }
}

/**
 * 결제 row — PortOne 결제 시도 1건. webhook 이벤트마다 INSERT.
 * raw_event_json 으로 webhook payload 원본 보존 (감사용).
 */
function ensure_payments_table(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_email VARCHAR(255) NOT NULL,
                portone_payment_id VARCHAR(64) NULL DEFAULT NULL,
                portone_transaction_id VARCHAR(64) NULL DEFAULT NULL,
                portone_subscription_id VARCHAR(64) NULL DEFAULT NULL,
                amount INT NOT NULL DEFAULT 0,
                currency VARCHAR(8) NOT NULL DEFAULT 'KRW',
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                paid_at DATETIME NULL DEFAULT NULL,
                raw_event_json LONGTEXT NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pay_owner (owner_email),
                INDEX idx_pay_payment_id (portone_payment_id),
                INDEX idx_pay_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return $done = true;
    } catch (Throwable $e) {
        error_log('[records] ensure_payments_table failed: ' . $e->getMessage());
        return $done = false;
    }
}

/**
 * 사용량 로그 — AI 요약 등 quota-차감 기능 사용 시마다 INSERT.
 * 월별 reset 후에도 원본 기록 유지 (감사 + 통계).
 */
function ensure_usage_logs_table(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS usage_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                owner_email VARCHAR(255) NOT NULL,
                feature VARCHAR(40) NOT NULL,
                amount INT NOT NULL DEFAULT 1,
                plan VARCHAR(16) NULL DEFAULT NULL,
                metadata_json TEXT NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_usage_owner (owner_email),
                INDEX idx_usage_feature (feature),
                INDEX idx_usage_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return $done = true;
    } catch (Throwable $e) {
        error_log('[records] ensure_usage_logs_table failed: ' . $e->getMessage());
        return $done = false;
    }
}

/** FCM 토큰 행 → 응답 형태. token 은 마스킹해서 반환 (앱 측 확인용). */
function user_fcm_token_row(array $row): array {
    $tok = (string)($row['token'] ?? '');
    $masked = strlen($tok) > 16 ? (substr($tok, 0, 8) . '...' . substr($tok, -4)) : $tok;
    return [
        'id'         => (int)$row['id'],
        'token_masked' => $masked,
        'device_id'  => $row['device_id'] ?? null,
        'platform'   => $row['platform'] ?? 'android',
        'last_seen_at' => $row['last_seen_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
    ];
}

/** 모바일 API 토큰 테이블 자동 마이그레이션. */
function ensure_mobile_tokens_table(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mobile_api_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_email VARCHAR(255) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                token_prefix VARCHAR(12) NOT NULL,
                label VARCHAR(120) NOT NULL DEFAULT '',
                last_used_at DATETIME NULL DEFAULT NULL,
                last_used_ip VARCHAR(45) NULL DEFAULT NULL,
                revoked_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_token_hash (token_hash),
                INDEX idx_mt_owner (owner_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return $done = true;
    } catch (Throwable $e) {
        error_log('[records] ensure_mobile_tokens_table failed: ' . $e->getMessage());
        return $done = false;
    }
}

function ensure_community_posts_table(PDO $pdo): bool {
    static $done = null;
    if ($done !== null) return $done;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS community_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(20) NOT NULL,
                title VARCHAR(200) NOT NULL,
                body MEDIUMTEXT,
                author_email VARCHAR(255) NOT NULL,
                author_name VARCHAR(120) NOT NULL DEFAULT '',
                view_count INT NOT NULL DEFAULT 0,
                pinned TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cp_cat (category, id),
                INDEX idx_cp_author (author_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return $done = true;
    } catch (Throwable $e) {
        error_log('[records] ensure_community_posts_table failed: ' . $e->getMessage());
        return $done = false;
    }
}

function valid_community_category(string $v): bool {
    return in_array($v, ['notice', 'free', 'qna'], true);
}

function community_post_row(array $row): array {
    return [
        'id'         => (int)$row['id'],
        'category'   => $row['category'],
        'title'      => $row['title'],
        'body'       => $row['body'],
        'authorName' => $row['author_name'] ?: '',
        'authorEmail'=> $row['author_email'],
        'viewCount'  => (int)$row['view_count'],
        'pinned'     => (bool)$row['pinned'],
        'createdAt'  => $row['created_at'],
        'updatedAt'  => $row['updated_at'],
    ];
}

/** 사용자가 해당 그룹의 소유자임을 확인. 아니면 즉시 403. */
function ensure_ledger_group_owner(PDO $pdo, int $groupId, string $owner): array {
    $stmt = $pdo->prepare('SELECT * FROM ledger_groups WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $groupId]);
    $row = $stmt->fetch();
    if (!$row) respond(['ok' => false, 'error' => '존재하지 않는 그룹입니다.'], 404);
    if (strtolower((string)$row['owner_email']) !== $owner) {
        respond(['ok' => false, 'error' => '해당 그룹에 접근 권한이 없습니다.'], 403);
    }
    return $row;
}

/** 본 요청의 사용자가 어드민/오너인지 판단. 기존 is_admin_user($authUser, $memberRecord) 활용. */
function current_user_is_admin(PDO $pdo, $authUser): bool {
    if (!$authUser) return false;
    $email = (string)($authUser['email'] ?? '');
    $memberRecord = $email !== '' ? fetch_member_by_email($pdo, $email) : null;
    return is_admin_user($authUser, $memberRecord);
}

function customer_row($row) {
    // PII (name/phone/notes) — AES-256-GCM 복호화. 평문도 호환 (lazy migration).
    return [
        'id' => $row['client_id'],
        'name' => youngman_decrypt($row['name'] ?? ''),
        'phone' => youngman_decrypt($row['phone'] ?? ''),
        'notes' => youngman_decrypt($row['notes'] ?? ''),
        'createdAt' => date('Y. m. d.', strtotime($row['created_at'])),
    ];
}

function employee_row($row) {
    // PII (name/contact/notes) — AES-256-GCM 복호화. title/start_date 는 비PII 로 평문 유지.
    return [
        'id' => $row['client_id'],
        'name' => youngman_decrypt($row['name'] ?? ''),
        'title' => $row['title'],
        'contact' => youngman_decrypt($row['contact'] ?? ''),
        'startDate' => $row['start_date'] ?? '',
        'notes' => youngman_decrypt($row['notes'] ?? ''),
        'createdAt' => date('Y. m. d.', strtotime($row['created_at'])),
    ];
}

function base64url_decode_strict($value) {
    $value = strtr((string)$value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding) $value .= str_repeat('=', 4 - $padding);
    $decoded = base64_decode($value, true);
    if ($decoded === false) respond(['ok' => false, 'error' => '인증 토큰 형식이 올바르지 않습니다.'], 401);
    return $decoded;
}

function read_authorization_header() {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach (['Authorization', 'authorization'] as $key) {
            if (!empty($headers[$key])) {
                $header = $headers[$key];
                break;
            }
        }
    }

    return (string)$header;
}

function auth_log($message, $context = []) {
    if (!empty($context)) {
        $message .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    error_log('[Auth] ' . $message);
}

function auth_token_diagnostics($header, $token = null, $extra = []) {
    $header = (string)$header;
    $token = $token === null ? '' : (string)$token;
    $dotCount = $token === '' ? 0 : substr_count($token, '.');
    auth_log('Authorization diagnostics', array_merge([
        'authorization_header_exists' => $header !== '',
        'bearer_prefix_exists' => (bool)preg_match('/^Bearer\s+/i', $header),
        'token_prefix_20' => $token === '' ? '' : substr($token, 0, 20),
        'jwt_format_dot_count_is_2' => $dotCount === 2,
        'dot_count' => $dotCount,
    ], $extra));
}

function decode_jwt_parts($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return [null, null, $parts];
    }

    [$headerPart, $payloadPart] = $parts;
    $headerJson = json_decode(base64url_decode_strict($headerPart), true);
    $payload = json_decode(base64url_decode_strict($payloadPart), true);
    return [$headerJson, $payload, $parts];
}

function auth_config_list($auth, $singleKey, $listKey) {
    $values = [];
    if (!empty($auth[$singleKey])) $values[] = (string)$auth[$singleKey];
    if (!empty($auth[$listKey]) && is_array($auth[$listKey])) {
        foreach ($auth[$listKey] as $value) {
            if ((string)$value !== '') $values[] = (string)$value;
        }
    }
    return array_values(array_unique($values));
}

function fetch_firebase_certs($auth) {
    $cachePath = (string)($auth['firebase_certs_cache'] ?? (sys_get_temp_dir() . '/jdhoon_firebase_certs.json'));
    if (is_file($cachePath)) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() && is_array($cached['certs'] ?? null)) {
            return $cached['certs'];
        }
    }

    $url = (string)($auth['firebase_cert_url'] ?? 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com');
    $response = @file_get_contents($url);
    if ($response === false) {
        auth_log('Firebase ID Token verification failed', ['reason' => 'public_cert_fetch_failed']);
        respond(['ok' => false, 'error' => 'Firebase 공개키를 가져오지 못했습니다.'], 401);
    }

    $certs = json_decode($response, true);
    if (!is_array($certs)) {
        auth_log('Firebase ID Token verification failed', ['reason' => 'public_cert_json_invalid']);
        respond(['ok' => false, 'error' => 'Firebase 공개키 응답이 올바르지 않습니다.'], 401);
    }

    $ttl = 3600;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/max-age=(\d+)/i', $line, $matches)) {
            $ttl = max(60, (int)$matches[1]);
            break;
        }
    }

    @file_put_contents($cachePath, json_encode([
        'expires_at' => time() + $ttl,
        'certs' => $certs,
    ], JSON_UNESCAPED_SLASHES));

    return $certs;
}

function verify_firebase_jwt($auth, $token, $headerJson, $payload, $parts) {
    if (($headerJson['alg'] ?? '') !== 'RS256') {
        return null;
    }

    // Only attempt Firebase verification when the token actually looks like a
    // Firebase ID token. Otherwise we would (incorrectly) reject Supabase RS256
    // tokens that share the same algorithm.
    $iss = (string)($payload['iss'] ?? '');
    if (strpos($iss, 'securetoken.google.com') === false) {
        return null;
    }

    $projectIds = auth_config_list($auth, 'firebase_project_id', 'firebase_project_ids');
    $tokenProjectId = (string)($payload['aud'] ?? '');
    if ($tokenProjectId !== '' && empty($projectIds)) {
        $projectIds[] = $tokenProjectId;
    }

    if (empty($projectIds)) {
        auth_log('Firebase ID Token verification failed', ['reason' => 'firebase_project_id_missing']);
        respond(['ok' => false, 'error' => 'Firebase 인증 설정이 없습니다.'], 500);
    }

    if (!in_array($tokenProjectId, $projectIds, true)) {
        auth_log('Firebase ID Token verification failed', ['reason' => 'audience_mismatch', 'aud' => $tokenProjectId]);
        respond(['ok' => false, 'error' => 'Firebase 인증 대상이 올바르지 않습니다.'], 401);
    }

    $expectedIssuer = 'https://securetoken.google.com/' . $tokenProjectId;
    if (($payload['iss'] ?? '') !== $expectedIssuer) {
        auth_log('Firebase ID Token verification failed', ['reason' => 'issuer_mismatch', 'iss' => $payload['iss'] ?? '']);
        respond(['ok' => false, 'error' => 'Firebase 인증 발급자가 올바르지 않습니다.'], 401);
    }

    if (($payload['exp'] ?? 0) < time()) {
        auth_log('Firebase ID Token verification failed', ['reason' => 'expired']);
        respond(['ok' => false, 'error' => '로그인 세션이 만료되었습니다.'], 401);
    }

    if (($payload['iat'] ?? 0) > time() + 300) {
        auth_log('Firebase ID Token verification failed', ['reason' => 'issued_in_future']);
        respond(['ok' => false, 'error' => 'Firebase 인증 발급 시간이 올바르지 않습니다.'], 401);
    }

    if (empty($payload['sub']) || !is_string($payload['sub'])) {
        auth_log('Firebase ID Token verification failed', ['reason' => 'subject_missing']);
        respond(['ok' => false, 'error' => 'Firebase 인증 사용자 정보가 없습니다.'], 401);
    }

    $kid = (string)($headerJson['kid'] ?? '');
    $certs = fetch_firebase_certs($auth);
    if ($kid === '' || empty($certs[$kid])) {
        auth_log('Firebase ID Token verification failed', ['reason' => 'kid_not_found', 'kid' => $kid]);
        respond(['ok' => false, 'error' => 'Firebase 인증 키를 찾을 수 없습니다.'], 401);
    }

    $publicKey = openssl_pkey_get_public($certs[$kid]);
    if (!$publicKey) {
        auth_log('Firebase ID Token verification failed', ['reason' => 'public_key_invalid', 'kid' => $kid]);
        respond(['ok' => false, 'error' => 'Firebase 공개키가 올바르지 않습니다.'], 401);
    }

    $signature = base64url_decode_strict($parts[2]);
    $verified = openssl_verify($parts[0] . '.' . $parts[1], $signature, $publicKey, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) {
        auth_log('Firebase ID Token verification failed', ['reason' => 'signature_invalid', 'kid' => $kid]);
        respond(['ok' => false, 'error' => 'Firebase ID Token 검증에 실패했습니다.'], 401);
    }

    auth_log('Firebase ID Token verification succeeded', [
        'uid' => $payload['sub'] ?? '',
        'email' => $payload['email'] ?? '',
        'aud' => $payload['aud'] ?? '',
    ]);

    return $payload;
}

function verify_supabase_jwt_payload($auth, $token, $headerJson, $payload, $parts) {
    if (($headerJson['alg'] ?? '') !== 'HS256') {
        return null;
    }

    $jwtSecret = (string)($auth['jwt_secret'] ?? '');
    if ($jwtSecret === '' || $jwtSecret === 'your-supabase-jwt-secret') {
        respond(['ok' => false, 'error' => 'Supabase 인증 설정이 없습니다.'], 500);
    }

    [$headerPart, $payloadPart, $signaturePart] = $parts;
    $expected = hash_hmac('sha256', $headerPart . '.' . $payloadPart, $jwtSecret, true);
    $actual = base64url_decode_strict($signaturePart);
    if (!hash_equals($expected, $actual)) {
        auth_log('Supabase JWT verification failed', ['reason' => 'signature_invalid']);
        respond(['ok' => false, 'error' => '인증 토큰 검증에 실패했습니다.'], 401);
    }

    if (($payload['exp'] ?? 0) < time()) {
        auth_log('Supabase JWT verification failed', ['reason' => 'expired']);
        respond(['ok' => false, 'error' => '로그인 세션이 만료되었습니다.'], 401);
    }

    $issuer = (string)($auth['issuer'] ?? '');
    if ($issuer !== '' && ($payload['iss'] ?? '') !== $issuer) {
        auth_log('Supabase JWT verification failed', ['reason' => 'issuer_mismatch', 'iss' => $payload['iss'] ?? '']);
        respond(['ok' => false, 'error' => '인증 발급자가 올바르지 않습니다.'], 401);
    }

    $audience = (string)($auth['audience'] ?? 'authenticated');
    $aud = $payload['aud'] ?? '';
    $audiences = is_array($aud) ? $aud : [$aud];
    if ($audience !== '' && !in_array($audience, $audiences, true)) {
        auth_log('Supabase JWT verification failed', ['reason' => 'audience_mismatch', 'aud' => $aud]);
        respond(['ok' => false, 'error' => '인증 대상이 올바르지 않습니다.'], 401);
    }

    auth_log('Supabase JWT verification succeeded', [
        'sub' => $payload['sub'] ?? '',
        'email' => $payload['email'] ?? '',
    ]);

    return $payload;
}

function derive_supabase_base_url($auth, $payload) {
    $candidates = [];
    if (!empty($auth['supabase_url'])) $candidates[] = (string)$auth['supabase_url'];
    if (!empty($auth['issuer'])) $candidates[] = (string)$auth['issuer'];
    if (is_array($payload) && !empty($payload['iss'])) $candidates[] = (string)$payload['iss'];

    foreach ($candidates as $candidate) {
        $url = trim($candidate);
        if ($url === '') continue;
        // Strip /auth/v1 suffix if present so we can append it consistently.
        $url = preg_replace('#/auth/v1/?$#', '', $url);
        $url = rtrim($url, '/');
        if ($url !== '' && preg_match('#^https?://#', $url)) {
            return $url;
        }
    }
    return '';
}

function verify_supabase_via_userinfo($auth, $token, $payload) {
    $base = derive_supabase_base_url($auth, $payload);
    if ($base === '') return null;

    $endpoint = $base . '/auth/v1/user';
    $apiKey = (string)($auth['anon_key'] ?? $auth['service_key'] ?? '');

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];
    if ($apiKey !== '') $headers[] = 'apikey: ' . $apiKey;

    if (!function_exists('curl_init')) {
        auth_log('Supabase userinfo verification failed', ['reason' => 'curl_unavailable']);
        return null;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        auth_log('Supabase userinfo verification failed', [
            'reason' => 'http_request_failed',
            'endpoint' => $endpoint,
            'curl_error' => $curlErr,
        ]);
        return null;
    }

    if ($status < 200 || $status >= 300) {
        auth_log('Supabase userinfo verification failed', [
            'reason' => 'non_2xx',
            'status' => $status,
            'body' => substr((string)$response, 0, 200),
        ]);
        return null;
    }

    $user = json_decode($response, true);
    if (!is_array($user) || empty($user['id'])) {
        auth_log('Supabase userinfo verification failed', ['reason' => 'invalid_payload']);
        return null;
    }

    auth_log('Supabase userinfo verification succeeded', [
        'sub' => $user['id'] ?? '',
        'email' => $user['email'] ?? '',
    ]);

    $appMeta = is_array($user['app_metadata'] ?? null) ? $user['app_metadata'] : [];
    $userMeta = is_array($user['user_metadata'] ?? null) ? $user['user_metadata'] : [];

    return [
        'sub' => $user['id'],
        'email' => $user['email'] ?? '',
        'phone' => $user['phone'] ?? '',
        'role' => $user['role'] ?? 'authenticated',
        'app_metadata' => $appMeta,
        'user_metadata' => $userMeta,
    ];
}

function verify_auth_token($auth) {
    if (empty($auth['require_auth'])) return null;

    $header = read_authorization_header();

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        auth_token_diagnostics($header);
        respond(['ok' => false, 'error' => '로그인이 필요합니다.'], 401);
    }

    $token = $matches[1];
    auth_token_diagnostics($header, $token);

    [$headerJson, $payload, $parts] = decode_jwt_parts($token);
    if (count($parts) !== 3) {
        auth_log('Token verification failed', ['reason' => 'not_jwt']);
        respond(['ok' => false, 'error' => '인증 토큰 형식이 올바르지 않습니다.'], 401);
    }

    if (!is_array($headerJson) || !is_array($payload)) {
        auth_log('Token verification failed', ['reason' => 'jwt_json_invalid']);
        respond(['ok' => false, 'error' => '인증 토큰 형식이 올바르지 않습니다.'], 401);
    }

    auth_log('JWT decoded', [
        'alg' => $headerJson['alg'] ?? '',
        'kid' => $headerJson['kid'] ?? '',
        'iss' => $payload['iss'] ?? '',
        'aud' => $payload['aud'] ?? '',
    ]);

    $firebasePayload = verify_firebase_jwt($auth, $token, $headerJson, $payload, $parts);
    if ($firebasePayload !== null) return $firebasePayload;

    $alg = (string)($headerJson['alg'] ?? '');
    $jwtSecret = (string)($auth['jwt_secret'] ?? '');
    $supabaseSecretConfigured = $jwtSecret !== '' && $jwtSecret !== 'your-supabase-jwt-secret';

    if ($alg === 'HS256' && $supabaseSecretConfigured) {
        $supabasePayload = verify_supabase_jwt_payload($auth, $token, $headerJson, $payload, $parts);
        if ($supabasePayload !== null) return $supabasePayload;
    }

    // Fallback: validate by asking Supabase directly.
    // Required for new-format publishable keys (sb_publishable_*) where access tokens
    // are signed asymmetrically (RS256/ES256) and we can't verify the signature locally.
    $userinfoPayload = verify_supabase_via_userinfo($auth, $token, $payload);
    if ($userinfoPayload !== null) return $userinfoPayload;

    auth_log('Token verification failed', ['reason' => 'unsupported_token', 'alg' => $alg]);
    respond(['ok' => false, 'error' => '지원하지 않는 인증 토큰입니다.'], 401);
}

try {
    // Public resources (no auth) — keep narrow.
    $publicResources = ['auth-availability', 'find-email', 'find-email-send-otp', 'find-email-verify-otp',
                        'find-pwd-send-otp', 'find-pwd-verify-otp', 'find-pwd-reset',
                        'signup-send-otp', 'signup-verify-otp'];
    // self-auth resources — global verify_auth_token 우회, handler 안에서 단순 /auth/v1/user 패턴 + spec §4 응답 shape.
    // upload.php / process-recording.php 와 동일한 인증 흐름으로 통일.
    $selfAuthResources = ['customer-log', 'app-fcm-token', 'recording-job'];
    $peekedResource = strtolower(trim((string)($_GET['resource'] ?? '')));
    $isSkipGlobalAuth = in_array($peekedResource, $publicResources, true)
                      || in_array($peekedResource, $selfAuthResources, true);
    $authUser = $isSkipGlobalAuth ? null : verify_auth_token($auth);

    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'] ?? 'localhost',
            (int)($db['port'] ?? 3306),
            $db['database'] ?? ''
        ),
        $db['user'] ?? '',
        $db['password'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $method = $_SERVER['REQUEST_METHOD'];
    $body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? read_json_body() : [];
    $resource = normalize_resource($_GET['resource'] ?? $body['resource'] ?? '');

    if ($resource === 'auth-membership') {
        if ($method !== 'GET') respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);

        $email = clean($_GET['email'] ?? null);
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['ok' => false, 'error' => '조회할 이메일이 올바르지 않습니다.'], 400);
        }
        if ($authUser && strtolower((string)($authUser['email'] ?? '')) !== strtolower($email)) {
            respond(['ok' => false, 'error' => '본인 이메일만 조회할 수 있습니다.'], 403);
        }

        $registered = member_exists_by_email($pdo, $email);
        respond([
            'ok' => true,
            'registered' => $registered === true,
            'source' => $registered === null ? 'not_configured' : 'database',
        ]);
    }

    if ($resource === 'auth-member') {
        if ($method !== 'POST') respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
        create_member_from_google($pdo, $authUser, $body);
    }

    // 사장님 2026-05-25 — 일반(이메일) 회원가입 휴대폰 인증.
    // ① signup-send-otp : 휴대폰 → 6자리 OTP SMS 발송 (auth_otp purpose='signup', 5분 유효, 1분 cooldown).
    // ② signup-verify-otp: phone+code 검증 → 'signup_verified' 토큰 발급 (10분 유효, 1회 사용).
    // ③ auth-member 분기에서 provider='email' + phone 입력 시 토큰 강제 (create_member_from_google 내부).
    if ($resource === 'signup-send-otp') {
        if ($method !== 'POST') respond(['ok'=>false, 'error'=>'지원하지 않는 요청 방식'], 405);
        $phone = preg_replace('/\D/', '', (string)($body['phone'] ?? ''));
        if (!preg_match('/^01[016789]\d{7,8}$/', $phone)) {
            respond(['ok'=>false, 'error'=>'올바른 휴대폰 번호를 입력해주세요 (010-...).'], 400);
        }

        ensure_auth_otp_table($pdo);

        // 1분 내 재발송 금지
        $check = $pdo->prepare("SELECT created_at FROM auth_otp WHERE target = :t AND purpose = 'signup' ORDER BY created_at DESC LIMIT 1");
        $check->execute([':t' => $phone]);
        $last = $check->fetch();
        if ($last && (time() - strtotime($last['created_at'])) < 60) {
            respond(['ok'=>false, 'error'=>'1분 후 재발송 가능합니다.'], 429);
        }

        // 이미 가입된 휴대폰이면 거절 — 사장님이 "다음 단계 못 넘어가도록" 요청한 의도와 일치.
        $store = find_member_store($pdo);
        if ($store) {
            $phoneCol = first_existing_column($store['columns'], ['phone', 'mobile', 'tel', 'contact', 'user_phone', 'mb_hp']);
            if ($phoneCol) {
                try {
                    $tableQ = quote_identifier($store['table']);
                    $phoneQ = quote_identifier($phoneCol);
                    $rows = $pdo->query("SELECT {$phoneQ} AS ph FROM {$tableQ}")->fetchAll();
                    foreach ($rows as $r) {
                        $rPh = function_exists('youngman_decrypt') ? youngman_decrypt((string)$r['ph']) : (string)$r['ph'];
                        if (preg_replace('/\D/', '', (string)$rPh) === $phone) {
                            respond(['ok'=>false, 'error'=>'이미 가입된 휴대폰 번호입니다.', 'reason'=>'phone_already_registered'], 409);
                        }
                    }
                } catch (Throwable $e) {}
            }
        }

        $sendResult = send_otp_sms_via_admin($pdo, 'signup', $phone);
        if (!$sendResult['ok']) {
            respond(['ok'=>false, 'error'=>$sendResult['error'] ?? 'SMS 발송 실패'], $sendResult['status'] ?? 500);
        }
        respond(['ok'=>true, 'sentTo'=>mask_phone($phone), 'expiresInSec'=>300]);
    }

    if ($resource === 'signup-verify-otp') {
        if ($method !== 'POST') respond(['ok'=>false, 'error'=>'지원하지 않는 요청 방식'], 405);
        $phone = preg_replace('/\D/', '', (string)($body['phone'] ?? ''));
        $code  = preg_replace('/\D/', '', (string)($body['code'] ?? ''));
        if (!$phone || !$code) respond(['ok'=>false, 'error'=>'휴대폰/인증번호를 입력해주세요.'], 400);

        ensure_auth_otp_table($pdo);
        $stmt = $pdo->prepare("SELECT id, code, attempts, expires_at FROM auth_otp WHERE target = :t AND purpose = 'signup' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':t' => $phone]);
        $otp = $stmt->fetch();
        if (!$otp) respond(['ok'=>false, 'error'=>'인증번호를 먼저 받아주세요.'], 400);
        if (strtotime($otp['expires_at']) < time()) {
            $pdo->prepare('DELETE FROM auth_otp WHERE id = ?')->execute([$otp['id']]);
            respond(['ok'=>false, 'error'=>'인증번호가 만료되었습니다. 다시 받아주세요.'], 410);
        }
        if ((int)$otp['attempts'] >= 5) respond(['ok'=>false, 'error'=>'시도 횟수를 초과했습니다.'], 429);
        if ($otp['code'] !== $code) {
            $pdo->prepare('UPDATE auth_otp SET attempts = attempts + 1 WHERE id = ?')->execute([$otp['id']]);
            respond(['ok'=>false, 'error'=>'인증번호가 일치하지 않습니다.'], 400);
        }

        // 검증 성공 — find-pwd 와 동일 패턴: 별도 행 발급 (signup_verified, 10분 유효, 1회 사용).
        try {
            $token = bin2hex(random_bytes(24));   // 48 hex chars
        } catch (Throwable $e) {
            $token = bin2hex(openssl_random_pseudo_bytes(24));
        }
        $pdo->prepare('DELETE FROM auth_otp WHERE id = ?')->execute([$otp['id']]);
        $pdo->prepare("INSERT INTO auth_otp (purpose, target, code, expires_at)
                       VALUES ('signup_verified', ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
            ->execute([$phone, $token]);
        respond(['ok'=>true, 'verificationToken'=>$token]);
    }

    if ($resource === 'find-pwd-send-otp') {
        // 비밀번호 찾기 — SMS 인증번호 발송 (purpose='find_pwd')
        if ($method !== 'POST') respond(['ok'=>false, 'error'=>'지원하지 않는 요청 방식'], 405);
        $phone = preg_replace('/\D/', '', (string)($body['phone'] ?? ''));
        if (strlen($phone) < 10) respond(['ok'=>false, 'error'=>'올바른 휴대폰 번호를 입력해주세요.'], 400);

        ensure_auth_otp_table($pdo);

        // 1분 내 재발송 금지
        $check = $pdo->prepare("SELECT created_at FROM auth_otp WHERE target = :t AND purpose = 'find_pwd' ORDER BY created_at DESC LIMIT 1");
        $check->execute([':t' => $phone]);
        $last = $check->fetch();
        if ($last && (time() - strtotime($last['created_at'])) < 60) {
            respond(['ok'=>false, 'error'=>'1분 후 재발송 가능합니다.'], 429);
        }

        $sendResult = send_otp_sms_via_admin($pdo, 'find_pwd', $phone);
        if (!$sendResult['ok']) {
            respond(['ok'=>false, 'error'=>$sendResult['error'] ?? 'SMS 발송 실패'], $sendResult['status'] ?? 500);
        }
        respond(['ok'=>true, 'sentTo'=>mask_phone($phone), 'expiresInSec'=>300]);
    }

    if ($resource === 'find-pwd-verify-otp') {
        // 비밀번호 찾기 — OTP 검증 + 사용자 정보 + provider 판별.
        // google 가입자면 별도 응답 → 클라이언트가 안내.
        // email 가입자면 reset_token (10분 만료) 발급 → find-pwd-reset 에서 사용.
        if ($method !== 'POST') respond(['ok'=>false, 'error'=>'지원하지 않는 요청 방식'], 405);
        $name  = clean($body['name'] ?? null);
        $phone = preg_replace('/\D/', '', (string)($body['phone'] ?? ''));
        $code  = preg_replace('/\D/', '', (string)($body['code'] ?? ''));
        if (!$name || !$phone || !$code) respond(['ok'=>false, 'error'=>'이름/휴대폰/인증번호를 모두 입력해주세요.'], 400);

        ensure_auth_otp_table($pdo);
        $stmt = $pdo->prepare("SELECT id, code, attempts, expires_at FROM auth_otp WHERE target = :t AND purpose = 'find_pwd' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':t' => $phone]);
        $otp = $stmt->fetch();
        if (!$otp) respond(['ok'=>false, 'error'=>'인증번호를 먼저 받아주세요.'], 400);
        if (strtotime($otp['expires_at']) < time()) {
            $pdo->prepare('DELETE FROM auth_otp WHERE id = ?')->execute([$otp['id']]);
            respond(['ok'=>false, 'error'=>'인증번호가 만료되었습니다. 다시 받아주세요.'], 410);
        }
        if ((int)$otp['attempts'] >= 5) respond(['ok'=>false, 'error'=>'시도 횟수를 초과했습니다.'], 429);
        if ($otp['code'] !== $code) {
            $pdo->prepare('UPDATE auth_otp SET attempts = attempts + 1 WHERE id = ?')->execute([$otp['id']]);
            respond(['ok'=>false, 'error'=>'인증번호가 일치하지 않습니다.'], 400);
        }

        // 이름+휴대폰 매칭 + provider 판별
        $store = find_member_store($pdo);
        if (!$store) respond(['ok'=>false, 'error'=>'회원 테이블을 찾을 수 없습니다.'], 500);
        $cols = $store['columns'];
        $nameCol = first_existing_column($cols, ['name', 'full_name', 'user_name', 'username', 'mb_name']);
        $phoneCol = first_existing_column($cols, ['phone', 'mobile', 'tel', 'contact', 'user_phone', 'mb_hp']);
        $emailCol = $store['email_column'];
        $providerCol = first_existing_column($cols, ['provider', 'signup_method', 'oauth_provider']);
        if (!$nameCol || !$phoneCol || !$emailCol) respond(['ok'=>false, 'error'=>'회원 테이블 구조 문제'], 500);

        $providerSel = $providerCol ? ", " . quote_identifier($providerCol) . " AS pv" : "";
        $rows = $pdo->query("SELECT " . quote_identifier($nameCol) . " AS nm, "
            . quote_identifier($phoneCol) . " AS ph, "
            . quote_identifier($emailCol) . " AS em" . $providerSel
            . " FROM " . quote_identifier($store['table']))->fetchAll();
        $matched = null;
        foreach ($rows as $r) {
            $rNm = function_exists('youngman_decrypt') ? youngman_decrypt((string)$r['nm']) : (string)$r['nm'];
            $rPh = function_exists('youngman_decrypt') ? youngman_decrypt((string)$r['ph']) : (string)$r['ph'];
            if (trim((string)$rNm) !== trim($name)) continue;
            if (preg_replace('/\D/', '', (string)$rPh) === $phone) {
                $matched = ['email' => (string)$r['em'], 'provider' => $providerCol ? strtolower((string)($r['pv'] ?? '')) : ''];
                break;
            }
        }
        if (!$matched) respond(['ok'=>false, 'error'=>'인증은 성공했으나 입력한 이름과 일치하는 계정이 없습니다.'], 404);

        // Google 가입자는 비밀번호 재설정 불가 — 안내 응답
        if (in_array($matched['provider'], ['google', 'oauth', 'oauth_google'], true)) {
            $pdo->prepare('DELETE FROM auth_otp WHERE id = ?')->execute([$otp['id']]);
            respond([
                'ok' => false,
                'reason' => 'oauth_provider',
                'provider' => $matched['provider'],
                'email' => $matched['email'],
                'error' => '구글 계정으로 가입하신 회원입니다. 구글 계정 보안 설정에서 비밀번호를 변경해주세요.',
            ], 200);
        }

        // 이메일 가입자 — reset_token 10분 만료 발급 (별도 OTP 행)
        try {
            $token = bin2hex(random_bytes(24));   // 48 hex chars
        } catch (Throwable $e) {
            $token = bin2hex(openssl_random_pseudo_bytes(24));
        }
        $pdo->prepare('DELETE FROM auth_otp WHERE id = ?')->execute([$otp['id']]);
        $pdo->prepare("INSERT INTO auth_otp (purpose, target, code, expires_at)
                       VALUES ('find_pwd_reset', ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
            ->execute([$phone, $token]);
        respond(['ok'=>true, 'resetToken'=>$token, 'email'=>$matched['email']]);
    }

    if ($resource === 'find-pwd-reset') {
        // 새 비밀번호 설정 — reset_token 검증 + supabase admin API 로 user 비밀번호 변경.
        if ($method !== 'POST') respond(['ok'=>false, 'error'=>'지원하지 않는 요청 방식'], 405);
        $phone       = preg_replace('/\D/', '', (string)($body['phone'] ?? ''));
        $resetToken  = clean($body['resetToken'] ?? null);
        $email       = strtolower(trim((string)($body['email'] ?? '')));
        $newPassword = (string)($body['newPassword'] ?? '');
        if (!$phone || !$resetToken || !$email || $newPassword === '') {
            respond(['ok'=>false, 'error'=>'필수 정보가 누락되었습니다.'], 400);
        }
        if (strlen($newPassword) < 6) respond(['ok'=>false, 'error'=>'비밀번호는 6자 이상이어야 합니다.'], 400);

        ensure_auth_otp_table($pdo);
        $stmt = $pdo->prepare("SELECT id, code, expires_at FROM auth_otp WHERE target = :t AND purpose = 'find_pwd_reset' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':t' => $phone]);
        $row = $stmt->fetch();
        if (!$row) respond(['ok'=>false, 'error'=>'인증 단계를 먼저 완료해주세요.'], 400);
        if (strtotime($row['expires_at']) < time()) {
            $pdo->prepare('DELETE FROM auth_otp WHERE id = ?')->execute([$row['id']]);
            respond(['ok'=>false, 'error'=>'재설정 토큰이 만료되었습니다. 처음부터 다시 진행해주세요.'], 410);
        }
        if ($row['code'] !== $resetToken) respond(['ok'=>false, 'error'=>'잘못된 재설정 토큰입니다.'], 403);

        $svcKey = (string)($auth['service_key'] ?? '');
        if ($svcKey === '') respond(['ok'=>false, 'error'=>'서버 설정 누락 — SUPABASE_SERVICE_KEY 미설정. 운영자에게 문의해주세요.'], 503);

        // supabase admin API — user id 먼저 찾기
        $base = rtrim((string)($auth['supabase_url'] ?? ''), '/');
        $base = preg_replace('#/rest/v1/?$#', '', $base);
        if ($base === '') respond(['ok'=>false, 'error'=>'서버 설정 누락 — SUPABASE_URL'], 503);

        $userId = supabase_admin_find_user_id($base, $svcKey, $email);
        if (!$userId) respond(['ok'=>false, 'error'=>'Supabase 사용자 조회 실패'], 502);

        $ok = supabase_admin_set_password($base, $svcKey, $userId, $newPassword);
        if (!$ok) respond(['ok'=>false, 'error'=>'비밀번호 변경 실패 (Supabase API)'], 502);

        // 토큰 소멸
        $pdo->prepare('DELETE FROM auth_otp WHERE id = ?')->execute([$row['id']]);
        respond(['ok'=>true]);
    }

    if ($resource === 'find-email-send-otp') {
        // 휴대폰 인증번호 발송 — 관리자(admin) sms_credentials 사용해서 Solapi 발송.
        // 인증번호는 auth_otp 테이블에 5분 만료로 저장.
        if ($method !== 'POST') respond(['ok'=>false, 'error'=>'지원하지 않는 요청 방식'], 405);
        $phone = preg_replace('/\D/', '', (string)($body['phone'] ?? ''));
        if (strlen($phone) < 10) respond(['ok'=>false, 'error'=>'올바른 휴대폰 번호를 입력해주세요.'], 400);

        ensure_auth_otp_table($pdo);

        // rate limit — 같은 phone 1분 내 재발송 금지
        $check = $pdo->prepare("SELECT created_at FROM auth_otp WHERE target = :t AND purpose = 'find_email' ORDER BY created_at DESC LIMIT 1");
        $check->execute([':t' => $phone]);
        $last = $check->fetch();
        if ($last && (time() - strtotime($last['created_at'])) < 60) {
            respond(['ok'=>false, 'error'=>'1분 후 재발송 가능합니다.'], 429);
        }

        $sendResult = send_otp_sms_via_admin($pdo, 'find_email', $phone);
        if (!$sendResult['ok']) {
            respond(['ok'=>false, 'error'=>$sendResult['error'] ?? 'SMS 발송 실패'], $sendResult['status'] ?? 500);
        }
        respond(['ok'=>true, 'sentTo'=>mask_phone($phone), 'expiresInSec'=>300]);
    }

    if ($resource === 'find-email-verify-otp') {
        // 인증번호 검증 + 이름 매칭 + 마스킹된 이메일 반환.
        if ($method !== 'POST') respond(['ok'=>false, 'error'=>'지원하지 않는 요청 방식'], 405);
        $name  = clean($body['name'] ?? null);
        $phone = preg_replace('/\D/', '', (string)($body['phone'] ?? ''));
        $code  = preg_replace('/\D/', '', (string)($body['code'] ?? ''));
        if (!$name || !$phone || !$code) respond(['ok'=>false, 'error'=>'이름/휴대폰/인증번호를 모두 입력해주세요.'], 400);

        ensure_auth_otp_table($pdo);

        // OTP 검증 — purpose=find_email, target=phone, 5분 미만, attempts<5
        $stmt = $pdo->prepare("SELECT id, code, attempts, expires_at FROM auth_otp WHERE target = :t AND purpose = 'find_email' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([':t' => $phone]);
        $otp = $stmt->fetch();
        if (!$otp) respond(['ok'=>false, 'error'=>'인증번호를 먼저 받아주세요.'], 400);
        if (strtotime($otp['expires_at']) < time()) {
            $pdo->prepare('DELETE FROM auth_otp WHERE id = ?')->execute([$otp['id']]);
            respond(['ok'=>false, 'error'=>'인증번호가 만료되었습니다. 다시 받아주세요.'], 410);
        }
        if ((int)$otp['attempts'] >= 5) {
            respond(['ok'=>false, 'error'=>'시도 횟수를 초과했습니다. 다시 받아주세요.'], 429);
        }
        if ($otp['code'] !== $code) {
            $pdo->prepare('UPDATE auth_otp SET attempts = attempts + 1 WHERE id = ?')->execute([$otp['id']]);
            respond(['ok'=>false, 'error'=>'인증번호가 일치하지 않습니다.'], 400);
        }

        // 이름 + 휴대폰 매칭 (find-email 과 동일 로직)
        $store = find_member_store($pdo);
        if (!$store) respond(['ok'=>false, 'error'=>'회원 테이블을 찾을 수 없습니다.'], 500);
        $cols = $store['columns'];
        $nameCol = first_existing_column($cols, ['name', 'full_name', 'user_name', 'username', 'mb_name']);
        $phoneCol = first_existing_column($cols, ['phone', 'mobile', 'tel', 'contact', 'user_phone', 'mb_hp']);
        $emailCol = $store['email_column'];
        if (!$nameCol || !$phoneCol || !$emailCol) respond(['ok'=>false, 'error'=>'회원 테이블 구조 문제'], 500);

        $rows = $pdo->query("SELECT " . quote_identifier($nameCol) . " AS nm, "
            . quote_identifier($phoneCol) . " AS ph, "
            . quote_identifier($emailCol) . " AS em FROM " . quote_identifier($store['table']))->fetchAll();
        $matched = null;
        foreach ($rows as $r) {
            $rNm = function_exists('youngman_decrypt') ? youngman_decrypt((string)$r['nm']) : (string)$r['nm'];
            $rPh = function_exists('youngman_decrypt') ? youngman_decrypt((string)$r['ph']) : (string)$r['ph'];
            if (trim((string)$rNm) !== trim($name)) continue;
            if (preg_replace('/\D/', '', (string)$rPh) === $phone) { $matched = (string)$r['em']; break; }
        }
        if (!$matched) respond(['ok'=>false, 'error'=>'인증은 성공했으나 입력한 이름과 일치하는 계정이 없습니다.'], 404);

        // OTP 소멸 (재사용 방지)
        $pdo->prepare('DELETE FROM auth_otp WHERE id = ?')->execute([$otp['id']]);

        // 마스킹
        $parts = explode('@', $matched, 2);
        $local = $parts[0] ?? '';
        $domain = $parts[1] ?? '';
        $maskedLocal = mb_substr($local, 0, 2) . str_repeat('*', max(0, mb_strlen($local) - 2));
        respond(['ok'=>true, 'email' => $maskedLocal . '@' . $domain]);
    }

    if ($resource === 'find-email') {
        // 이름 + 휴대폰 → 마스킹된 이메일 반환 (아이디 찾기).
        // 무차별 lookup 방지 위해 둘 다 정확히 일치해야 함.
        if ($method !== 'POST') respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
        $name  = clean($body['name'] ?? null);
        $phone = clean($body['phone'] ?? null);
        if (!$name || !$phone) {
            respond(['ok' => false, 'error' => '이름과 휴대폰 번호를 모두 입력해주세요.'], 400);
        }
        $phoneDigits = preg_replace('/\D/', '', $phone);
        if (strlen($phoneDigits) < 10) {
            respond(['ok' => false, 'error' => '올바른 휴대폰 번호 형식이 아닙니다.'], 400);
        }
        $store = find_member_store($pdo);
        if (!$store) respond(['ok' => false, 'error' => '회원 테이블을 찾을 수 없습니다.'], 500);
        $cols = $store['columns'];
        $nameCol = first_existing_column($cols, ['name', 'full_name', 'user_name', 'username', 'mb_name']);
        $phoneCol = first_existing_column($cols, ['phone', 'mobile', 'tel', 'contact', 'user_phone', 'mb_hp']);
        $emailCol = $store['email_column'];
        if (!$nameCol || !$phoneCol || !$emailCol) {
            respond(['ok' => false, 'error' => '이름/휴대폰 컬럼이 없는 회원 테이블입니다.'], 500);
        }
        // 암호화된 PII 와 매칭 위해 전체 fetch + PHP 단에서 decrypt 비교
        // (members 행 수가 적다는 가정 — 대규모면 별도 hash column 필요)
        try {
            $stmt = $pdo->query("SELECT " . quote_identifier($nameCol) . " AS nm, "
                              . quote_identifier($phoneCol) . " AS ph, "
                              . quote_identifier($emailCol) . " AS em FROM " . quote_identifier($store['table']));
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            respond(['ok' => false, 'error' => '회원 조회 실패: ' . $e->getMessage()], 500);
        }
        $matchedEmail = null;
        foreach ($rows as $r) {
            $rNm = function_exists('youngman_decrypt') ? youngman_decrypt((string)$r['nm']) : (string)$r['nm'];
            $rPh = function_exists('youngman_decrypt') ? youngman_decrypt((string)$r['ph']) : (string)$r['ph'];
            if (trim((string)$rNm) !== trim($name)) continue;
            $rPhDigits = preg_replace('/\D/', '', (string)$rPh);
            if ($rPhDigits === $phoneDigits) {
                $matchedEmail = (string)$r['em'];
                break;
            }
        }
        if (!$matchedEmail) {
            respond(['ok' => false, 'error' => '입력하신 이름/휴대폰으로 가입된 계정을 찾을 수 없습니다.'], 404);
        }
        // 마스킹 — local-part 의 첫 2자만 + 나머지는 ***
        $parts = explode('@', $matchedEmail, 2);
        $local = $parts[0] ?? '';
        $domain = $parts[1] ?? '';
        $maskedLocal = mb_substr($local, 0, 2) . str_repeat('*', max(0, mb_strlen($local) - 2));
        respond(['ok' => true, 'email' => $maskedLocal . '@' . $domain]);
    }

    if ($resource === 'auth-availability') {
        if ($method !== 'GET') respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);

        $email = clean($_GET['email'] ?? null);
        $nickname = clean($_GET['nickname'] ?? null);
        if ($email === null && $nickname === null) {
            respond(['ok' => false, 'error' => 'email 또는 nickname 중 하나는 필요합니다.'], 400);
        }

        $store = find_member_store($pdo);
        $result = ['ok' => true];

        if ($email !== null) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result['email_taken'] = false;
                $result['email_invalid'] = true;
            } else if ($store) {
                $result['email_taken'] = (member_exists_by_email($pdo, $email) === true);
            } else {
                $result['email_taken'] = false;
            }
        }

        if ($nickname !== null) {
            if (!is_valid_nickname($nickname)) {
                $result['nickname_taken'] = false;
                $result['nickname_invalid'] = true;
            } else if ($store) {
                $result['nickname_taken'] = nickname_taken($pdo, $store, $nickname);
            } else {
                $result['nickname_taken'] = false;
            }
        }

        respond($result);
    }

    if ($resource === 'admin-bootstrap') {
        if ($method !== 'POST') respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
        if (!$authUser) respond(['ok' => false, 'error' => '로그인이 필요합니다.'], 401);

        $email = strtolower((string)($authUser['email'] ?? ''));
        if (!is_admin_email($email)) {
            respond(['ok' => false, 'error' => '권한 없음.'], 403);
        }

        $store = find_member_store($pdo);
        if (!$store) respond(['ok' => false, 'error' => '회원 테이블을 찾을 수 없습니다.'], 500);

        $columns = $store['columns'];
        $roleColumn = first_existing_column($columns, ['role', 'member_role', 'user_role', 'level']);
        if (!$roleColumn) respond(['ok' => false, 'error' => 'role 컬럼이 없습니다.'], 500);

        $tableQ = quote_identifier($store['table']);
        $emailQ = quote_identifier($store['email_column']);
        $roleQ = quote_identifier($roleColumn);

        if (member_exists_by_email($pdo, $email) === true) {
            $stmt = $pdo->prepare("UPDATE {$tableQ} SET {$roleQ} = :role WHERE LOWER({$emailQ}) = :email");
            $stmt->execute([':role' => 'admin', ':email' => $email]);
            respond(['ok' => true, 'action' => 'updated', 'email' => $email, 'role' => 'admin']);
        }

        $row = [$store['email_column'] => $email, $roleColumn => 'admin'];
        $nameColumn = first_existing_column($columns, ['name', 'full_name', 'user_name', 'username', 'mb_name']);
        if ($nameColumn) {
            $row[$nameColumn] = (string)($authUser['user_metadata']['full_name']
                ?? $authUser['user_metadata']['name']
                ?? explode('@', $email)[0]);
        }
        $providerColumn = first_existing_column($columns, ['provider', 'signup_method', 'oauth_provider']);
        if ($providerColumn) {
            $row[$providerColumn] = (string)($authUser['app_metadata']['provider'] ?? 'email');
        }
        $authIdColumn = first_existing_column($columns, ['supabase_id', 'auth_user_id', 'oauth_id']);
        if ($authIdColumn && !empty($authUser['sub'])) $row[$authIdColumn] = $authUser['sub'];
        $statusColumn = first_existing_column($columns, ['status', 'member_status']);
        if ($statusColumn) $row[$statusColumn] = 'active';
        $now = date('Y-m-d H:i:s');
        $createdColumn = first_existing_column($columns, ['created_at', 'created', 'registered_at', 'reg_date']);
        if ($createdColumn) $row[$createdColumn] = $now;
        $updatedColumn = first_existing_column($columns, ['updated_at', 'modified_at']);
        if ($updatedColumn) $row[$updatedColumn] = $now;
        $row = array_filter($row, function ($v) { return $v !== null; });

        $fieldSql = implode(', ', array_map('quote_identifier', array_keys($row)));
        $placeholderSql = implode(', ', array_map(function ($c) { return ':' . $c; }, array_keys($row)));
        $stmt = $pdo->prepare("INSERT INTO {$tableQ} ({$fieldSql}) VALUES ({$placeholderSql})");
        $params = [];
        foreach ($row as $k => $v) $params[':' . $k] = $v;
        $stmt->execute($params);
        respond(['ok' => true, 'action' => 'created', 'email' => $email, 'role' => 'admin']);
    }

    if ($resource === 'auth-profile') {
        if (!$authUser) respond(['ok' => false, 'error' => '로그인이 필요합니다.'], 401);
        $email = strtolower((string)($authUser['email'] ?? ''));
        if ($email === '') respond(['ok' => false, 'error' => '인증 사용자 이메일을 확인할 수 없습니다.'], 400);

        $store = find_member_store($pdo);
        if (!$store) respond(['ok' => false, 'error' => '회원 테이블을 찾을 수 없습니다.'], 500);

        if ($method === 'GET') {
            $rec = fetch_member_by_email($pdo, $email);
            // 사장님 2026-05-25 — v60 client cache refresh 용. requires_subscription flag
            // 매 호출마다 DB latest 반영 (member row 의 plan + admin allowlist 기반).
            $isAdminUserPf = is_admin_email($email);
            if (!$rec) {
                respond(['ok' => true, 'profile' => [
                    'email' => $email,
                    'name' => $authUser['user_metadata']['full_name'] ?? $authUser['user_metadata']['name'] ?? '',
                    'phone' => '',
                    'provider' => $authUser['app_metadata']['provider'] ?? 'email',
                    'status' => 'active',
                    'role' => 'member',
                    'createdAt' => '',
                    'updatedAt' => '',
                    'lastLoginAt' => '',
                    'plan' => 'free',
                    'plan_status' => 'active',
                    'requires_subscription' => !$isAdminUserPf,  // member row 없으면 free 간주
                ]]);
            }
            $profile = member_row_from_store($rec['store'], $rec['row']);
            $profile['email'] = $email;
            // 사장님 2026-05-26 — 옛 plan key 호환 매핑 (member_row_from_store 가 이미 정규화하지만 fail-safe).
            $effectivePlanPf = strtolower((string)($profile['plan'] ?? 'free'));
            if ($effectivePlanPf === 'trialing') $effectivePlanPf = 'free';
            if ($effectivePlanPf === 'plus' || $effectivePlanPf === 'premium') $effectivePlanPf = 'sales';
            if ($effectivePlanPf === 'pro') $effectivePlanPf = 'master';
            $profile['requires_subscription'] = ($effectivePlanPf === 'free' && !$isAdminUserPf);
            respond(['ok' => true, 'profile' => $profile]);
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
            $cols = $store['columns'];
            $assignments = [];
            $params = [':email' => $email];

            $nameCol = first_existing_column($cols, ['name', 'full_name', 'user_name', 'username', 'mb_name']);
            if ($nameCol && isset($data['name'])) {
                $assignments[] = quote_identifier($nameCol) . ' = :name';
                $plain = clean($data['name']) ?? '';
                // 개인정보 — AES-256-GCM 암호화 후 저장
                $params[':name'] = ($plain !== '') ? youngman_encrypt($plain) : '';
            }

            $phoneCol = first_existing_column($cols, ['phone', 'mobile', 'tel', 'contact', 'user_phone', 'mb_hp']);
            if ($phoneCol && isset($data['phone'])) {
                $assignments[] = quote_identifier($phoneCol) . ' = :phone';
                $plainPhone = clean($data['phone']);
                $params[':phone'] = ($plainPhone !== null && $plainPhone !== '') ? youngman_encrypt($plainPhone) : $plainPhone;
            }

            // 닉네임 update — Google 가입 후 추가 입력 받은 nickname 또는 사용자 변경
            $nickCol = first_existing_column($cols, ['nickname', 'nick', 'display_name']);
            if ($nickCol && isset($data['nickname'])) {
                $nick = clean($data['nickname']);
                if ($nick !== null && !is_valid_nickname($nick)) {
                    respond(['ok' => false, 'error' => '닉네임은 2~20자, 한글/영문/숫자/_/- 만 가능합니다.'], 400);
                }
                if ($nick !== null && nickname_taken($pdo, $store, $nick, $email)) {
                    respond(['ok' => false, 'error' => '이미 사용 중인 닉네임입니다.'], 409);
                }
                $assignments[] = quote_identifier($nickCol) . ' = :nickname';
                $params[':nickname'] = $nick;
            }

            // 자동 충전 동의 토글 (overage_enabled) — 본인이 직접 ON/OFF 가능. PII 아님.
            $overageEnabledCol = first_existing_column($cols, ['overage_enabled']);
            if ($overageEnabledCol && array_key_exists('overage_enabled', $data)) {
                $assignments[] = quote_identifier($overageEnabledCol) . ' = :overage_enabled';
                $params[':overage_enabled'] = (int)(!!$data['overage_enabled']);
            }

            $updatedCol = first_existing_column($cols, ['updated_at', 'modified_at']);
            if ($updatedCol) {
                $assignments[] = quote_identifier($updatedCol) . ' = :updated_at';
                $params[':updated_at'] = date('Y-m-d H:i:s');
            }

            if (empty($assignments)) respond(['ok' => false, 'error' => '수정 가능한 필드가 없습니다.'], 400);

            $emailCol = quote_identifier($store['email_column']);
            $sql = 'UPDATE ' . quote_identifier($store['table']) . ' SET ' . implode(', ', $assignments)
                . " WHERE LOWER({$emailCol}) = :email";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            record_activity($pdo, $email, 'profile.update');
            respond(['ok' => true]);
        }

        respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
    }

    if ($resource === 'account-delete') {
        // 본인 계정 + 본인 데이터 일괄 삭제 (회원 탈퇴).
        // 인증된 사용자만 자기 자신을 삭제 가능. admin 우회 없음.
        if (!$authUser) respond(['ok' => false, 'error' => '로그인이 필요합니다.'], 401);
        if ($method !== 'POST' && $method !== 'DELETE') {
            respond(['ok' => false, 'error' => 'POST 또는 DELETE 만 허용됩니다.'], 405);
        }

        $email = strtolower((string)($authUser['email'] ?? ''));
        if ($email === '') respond(['ok' => false, 'error' => '인증 사용자 이메일을 확인할 수 없습니다.'], 400);

        // 안전망: 요청 body 에 confirm_email 이 있으면 토큰 이메일과 일치해야 함.
        $confirm = strtolower((string)($body['confirm_email'] ?? ''));
        if ($confirm !== '' && $confirm !== $email) {
            respond(['ok' => false, 'error' => '확인용 이메일이 일치하지 않습니다.'], 400);
        }

        $store = find_member_store($pdo);
        $emailCol = $store ? quote_identifier($store['email_column']) : null;
        $tableQ   = $store ? quote_identifier($store['table']) : null;

        // Play 정책 준수 — 사용자 데이터 완전 삭제 (2026-05-20 보강).
        // 추가 테이블: customer_log / recording_jobs / usage_logs / user_fcm_tokens / payments
        // 추가 자산: uploads/<user_seg>/ 파일 + Supabase auth.users row.
        $deleted = [
            'ledger_records' => 0, 'ledger_groups' => 0,
            'customers' => 0, 'employees' => 0,
            'mobile_api_tokens' => 0, 'member' => 0,
            'customer_log' => 0, 'recording_jobs' => 0,
            'usage_logs' => 0, 'user_fcm_tokens' => 0,
            'sms_credentials' => 0,
            'community_posts' => 0,
            'subscriptions' => 0,
            'payments' => 0,
            'audio_files' => 0,
            'supabase_user' => false,
        ];

        try {
            $pdo->beginTransaction();

            // 1) ledger_records — 사용자 행 모두
            try {
                $s = $pdo->prepare('DELETE FROM ledger_records WHERE owner_email = :o');
                $s->execute([':o' => $email]);
                $deleted['ledger_records'] = $s->rowCount();
            } catch (Throwable $e) { /* 테이블 미존재 등 무시 */ }

            // 2) ledger_groups
            try {
                $s = $pdo->prepare('DELETE FROM ledger_groups WHERE owner_email = :o');
                $s->execute([':o' => $email]);
                $deleted['ledger_groups'] = $s->rowCount();
            } catch (Throwable $e) {}

            // 3) legacy customers / employees (owner_email 컬럼이 있는 경우만)
            foreach (['customers', 'employees'] as $legacy) {
                try {
                    $cols = table_columns($pdo, $legacy);
                    if (in_array('owner_email', $cols, true)) {
                        $s = $pdo->prepare('DELETE FROM ' . quote_identifier($legacy) . ' WHERE owner_email = :o');
                        $s->execute([':o' => $email]);
                        $deleted[$legacy] = $s->rowCount();
                    }
                } catch (Throwable $e) {}
            }

            // 4) mobile_api_tokens
            try {
                $s = $pdo->prepare('DELETE FROM mobile_api_tokens WHERE owner_email = :o');
                $s->execute([':o' => $email]);
                $deleted['mobile_api_tokens'] = $s->rowCount();
            } catch (Throwable $e) {}

            // 5) customer_log — 통화 요약 결과 (PII 암호문)
            try {
                $s = $pdo->prepare('DELETE FROM customer_log WHERE owner_email = :o');
                $s->execute([':o' => $email]);
                $deleted['customer_log'] = $s->rowCount();
            } catch (Throwable $e) {}

            // 6) recording_jobs — 통화 처리 job (transcript/summary 암호문 포함)
            try {
                $s = $pdo->prepare('DELETE FROM recording_jobs WHERE owner_email = :o');
                $s->execute([':o' => $email]);
                $deleted['recording_jobs'] = $s->rowCount();
            } catch (Throwable $e) {}

            // 7) usage_logs — 분 단위 사용 기록
            try {
                $s = $pdo->prepare('DELETE FROM usage_logs WHERE owner_email = :o');
                $s->execute([':o' => $email]);
                $deleted['usage_logs'] = $s->rowCount();
            } catch (Throwable $e) {}

            // 8) user_fcm_tokens — FCM 토큰
            try {
                $s = $pdo->prepare('DELETE FROM user_fcm_tokens WHERE owner_email = :o');
                $s->execute([':o' => $email]);
                $deleted['user_fcm_tokens'] = $s->rowCount();
            } catch (Throwable $e) {}

            // 8-1) sms_credentials — Solapi/Aligo 자격증명 (민감 — 즉시 완전 삭제)
            try {
                $s = $pdo->prepare('DELETE FROM sms_credentials WHERE owner_email = :o');
                $s->execute([':o' => $email]);
                $deleted['sms_credentials'] = $s->rowCount();
            } catch (Throwable $e) {}

            // 8-2) community_posts — 작성자 익명화 (다른 사용자 열람 일관성 유지)
            try {
                $s = $pdo->prepare("UPDATE community_posts SET author_email = CONCAT('deleted_', SUBSTRING(MD5(:o), 1, 16)) WHERE author_email = :o");
                $s->execute([':o' => $email]);
                $deleted['community_posts'] = $s->rowCount();
            } catch (Throwable $e) {}

            // 8-3) subscriptions — 전자상거래법 보관 의무. owner_email 익명화.
            try {
                $s = $pdo->prepare("UPDATE subscriptions SET owner_email = CONCAT('deleted_', SUBSTRING(MD5(:o), 1, 16)) WHERE owner_email = :o");
                $s->execute([':o' => $email]);
                $deleted['subscriptions'] = $s->rowCount();
            } catch (Throwable $e) {}

            // 9) payments — 결제 내역.
            // 단, 전자상거래법상 결제 기록은 5년 보관 의무 → email 컬럼만 null 로 마스킹 (row 자체는 유지).
            // members 의 personal info 제거됨 + payments 의 owner_email 마스킹 으로 GDPR/Play 정책 + 법령 양립.
            try {
                $cols = table_columns($pdo, 'payments');
                if (in_array('owner_email', $cols, true)) {
                    $s = $pdo->prepare("UPDATE payments SET owner_email = CONCAT('deleted_', SUBSTRING(MD5(:o), 1, 16)) WHERE owner_email = :o");
                    $s->execute([':o' => $email]);
                    $deleted['payments'] = $s->rowCount();
                }
            } catch (Throwable $e) {}

            // 10) 마지막으로 member 행 — 이게 삭제되면 records.php 가 가입된 회원이 아니라고 판정.
            if ($store && $emailCol && $tableQ) {
                $s = $pdo->prepare("DELETE FROM {$tableQ} WHERE LOWER({$emailCol}) = :email");
                $s->execute([':email' => $email]);
                $deleted['member'] = $s->rowCount();
            }

            $pdo->commit();
        } catch (Throwable $e) {
            try { $pdo->rollBack(); } catch (Throwable $e2) {}
            respond(['ok' => false, 'error' => '탈퇴 처리 중 오류: ' . $e->getMessage()], 500);
        }

        // 11) 업로드 파일 삭제 (트랜잭션 밖 — 파일 IO 는 commit 후)
        try {
            $deleted['audio_files'] = delete_user_upload_dir($email);
        } catch (Throwable $e) {
            error_log('[account-delete] upload dir 삭제 실패: ' . $e->getMessage());
        }

        // 12) Supabase auth.users 삭제 (Play 정책 — 인증 row 도 함께)
        try {
            $svcKey = (string)($auth['service_key'] ?? '');
            $svcBase = (string)($auth['supabase_url'] ?? '');
            if ($svcKey !== '' && $svcBase !== '') {
                $uid = supabase_admin_find_user_id($svcBase, $svcKey, $email);
                if ($uid) {
                    $deleted['supabase_user'] = supabase_admin_delete_user($svcBase, $svcKey, $uid);
                }
            }
        } catch (Throwable $e) {
            error_log('[account-delete] supabase user 삭제 실패: ' . $e->getMessage());
        }

        // 활동 로그 — 트랜잭션 밖에서 (실패해도 탈퇴 자체엔 영향 없음).
        // owner_email 자리에 hash 사용 (탈퇴자 email 평문 보관 금지).
        $hashedEmail = 'deleted_' . substr(hash('sha256', $email), 0, 16);
        record_activity($pdo, $hashedEmail, 'account.delete', json_encode($deleted, JSON_UNESCAPED_UNICODE));

        respond(['ok' => true, 'deleted' => $deleted]);
    }

    if ($resource === 'sms-credentials') {
        // 회원별 SMS provider 자격증명 (Solapi/Aligo). PII 격리 — 본인만.
        // 영맨 사이트는 발송 중계만, 충전/결제는 사용자가 provider 사이트에서 직접.
        if (!$authUser) respond(['ok' => false, 'error' => '로그인이 필요합니다.'], 401);
        enforce_registered_member($pdo, $authUser);
        $email = strtolower((string)($authUser['email'] ?? ''));
        if ($email === '') respond(['ok' => false, 'error' => '인증 사용자 이메일을 확인할 수 없습니다.'], 400);

        // 자동 마이그레이션
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sms_credentials (
                owner_email VARCHAR(255) PRIMARY KEY,
                provider VARCHAR(20) NOT NULL DEFAULT 'solapi',
                api_key_enc TEXT NULL,
                api_secret_enc TEXT NULL,
                sender_phone_enc VARCHAR(255) NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            respond(['ok' => false, 'error' => 'SMS 자격증명 테이블 마이그레이션 실패'], 500);
        }

        if ($method === 'GET') {
            $stmt = $pdo->prepare('SELECT * FROM sms_credentials WHERE owner_email = :o LIMIT 1');
            $stmt->execute([':o' => $email]);
            $row = $stmt->fetch();
            if (!$row) {
                respond(['ok' => true, 'configured' => false, 'provider' => 'solapi']);
            }
            $apiKey = youngman_decrypt($row['api_key_enc']);
            $sender = youngman_decrypt($row['sender_phone_enc']);
            // api_key 는 앞뒤만 보여주고 가운데 마스킹. secret 은 절대 노출 X.
            $masked = '';
            if (is_string($apiKey) && strlen($apiKey) >= 8) {
                $masked = substr($apiKey, 0, 4) . '****' . substr($apiKey, -4);
            } elseif (is_string($apiKey) && $apiKey !== '') {
                $masked = str_repeat('*', strlen($apiKey));
            }
            respond([
                'ok'              => true,
                'configured'      => true,
                'provider'        => $row['provider'] ?? 'solapi',
                'apiKeyMasked'    => $masked,
                'senderPhone'     => is_string($sender) ? $sender : '',
                'updatedAt'       => $row['updated_at'] ?? null,
            ]);
        }

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
            $provider = strtolower(trim((string)($data['provider'] ?? 'solapi')));
            if (!in_array($provider, ['solapi', 'aligo'], true)) {
                respond(['ok' => false, 'error' => 'provider 는 solapi 또는 aligo 만 지원합니다.'], 400);
            }
            $apiKeyIn    = trim((string)($data['apiKey'] ?? ''));
            $apiSecretIn = trim((string)($data['apiSecret'] ?? ''));
            $senderIn    = preg_replace('/[^\d]/', '', (string)($data['senderPhone'] ?? ''));

            if ($senderIn !== '' && !preg_match('/^0\d{9,10}$/', $senderIn)) {
                respond(['ok' => false, 'error' => '발신번호는 숫자만, 0 으로 시작하는 9~10자리.'], 400);
            }

            // 기존 행 조회 — 빈 입력은 기존값 유지 (재입력 안 시킴)
            $cur = $pdo->prepare('SELECT api_key_enc, api_secret_enc, sender_phone_enc FROM sms_credentials WHERE owner_email = :o LIMIT 1');
            $cur->execute([':o' => $email]);
            $existing = $cur->fetch();

            $apiKeyEnc    = ($apiKeyIn    !== '') ? youngman_encrypt($apiKeyIn)    : ($existing['api_key_enc']    ?? null);
            $apiSecretEnc = ($apiSecretIn !== '') ? youngman_encrypt($apiSecretIn) : ($existing['api_secret_enc'] ?? null);
            $senderEnc    = ($senderIn    !== '') ? youngman_encrypt($senderIn)    : ($existing['sender_phone_enc'] ?? null);

            $upsert = $pdo->prepare("
                INSERT INTO sms_credentials (owner_email, provider, api_key_enc, api_secret_enc, sender_phone_enc)
                VALUES (:o, :p, :k, :s, :sp)
                ON DUPLICATE KEY UPDATE
                    provider = VALUES(provider),
                    api_key_enc = VALUES(api_key_enc),
                    api_secret_enc = VALUES(api_secret_enc),
                    sender_phone_enc = VALUES(sender_phone_enc)
            ");
            $upsert->execute([
                ':o' => $email, ':p' => $provider,
                ':k' => $apiKeyEnc, ':s' => $apiSecretEnc, ':sp' => $senderEnc,
            ]);
            record_activity($pdo, $email, 'sms.credentials.update', $provider);
            respond(['ok' => true]);
        }

        if ($method === 'DELETE') {
            $stmt = $pdo->prepare('DELETE FROM sms_credentials WHERE owner_email = :o');
            $stmt->execute([':o' => $email]);
            record_activity($pdo, $email, 'sms.credentials.delete');
            respond(['ok' => true]);
        }

        respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
    }

    if ($resource === 'admin-members') {
        enforce_admin($pdo, $authUser);

        $store = find_member_store($pdo);
        if (!$store) respond(['ok' => false, 'error' => '회원 테이블을 찾을 수 없습니다.'], 500);

        if ($method === 'GET') {
            $cols = $store['columns'];
            $createdCol = first_existing_column($cols, ['created_at', 'created', 'registered_at', 'reg_date']);
            $orderBy = $createdCol ? quote_identifier($createdCol) . ' DESC' : '1';
            $rows = $pdo->query('SELECT * FROM ' . quote_identifier($store['table']) . ' ORDER BY ' . $orderBy)->fetchAll();
            $items = array_map(function ($r) use ($store) { return member_row_from_store($store, $r); }, $rows);
            respond(['ok' => true, 'items' => $items]);
        }

        if ($method === 'PATCH' || $method === 'PUT') {
            $email = strtolower((string)clean($body['email'] ?? null));
            if ($email === '') respond(['ok' => false, 'error' => '대상 회원 이메일이 없습니다.'], 400);

            $cols = $store['columns'];
            $assignments = [];
            $params = [':email' => $email];

            $statusCol = first_existing_column($cols, ['status', 'member_status']);
            if ($statusCol && isset($body['status'])) {
                $status = strtolower(trim((string)$body['status']));
                if (!in_array($status, ['active', 'suspended', 'banned'], true)) {
                    respond(['ok' => false, 'error' => '허용되지 않는 상태값입니다.'], 400);
                }
                $assignments[] = quote_identifier($statusCol) . ' = :status';
                $params[':status'] = $status;
            }

            $roleCol = first_existing_column($cols, ['role', 'member_role', 'user_role']);
            if ($roleCol && isset($body['role'])) {
                $role = strtolower(trim((string)$body['role']));
                if (!in_array($role, ['member', 'admin'], true)) {
                    respond(['ok' => false, 'error' => '허용되지 않는 권한값입니다.'], 400);
                }
                $assignments[] = quote_identifier($roleCol) . ' = :role';
                $params[':role'] = $role;
            }

            // 구독 결제 — 관리자 수동 변경 (PortOne 결합 전 권한 로직 검증용).
            $planCol = first_existing_column($cols, ['plan']);
            $summaryLimitCol = first_existing_column($cols, ['summary_limit']);
            $planChanged = false;
            $newPlanVal = null;
            if ($planCol && isset($body['plan'])) {
                $planVal = strtolower(trim((string)$body['plan']));
                // 사장님 2026-05-26 — 신규 요금제 (sales/master/agency). 옛 plus/pro 폐지.
                if (!in_array($planVal, ['free', 'sales', 'master', 'agency'], true)) {
                    respond(['ok' => false, 'error' => '허용되지 않는 plan 값입니다.'], 400);
                }
                $assignments[] = quote_identifier($planCol) . ' = :plan';
                $params[':plan'] = $planVal;
                $planChanged = true;
                $newPlanVal = $planVal;
            }
            // plan 변경 시 summary_limit 도 자동 동기화 (회 단위 — deprecated 이나 호환).
            // sales=20 / master/agency=NULL(무제한) / free=0.
            if ($planChanged && $summaryLimitCol && !isset($body['summary_limit'])) {
                $autoLimit = null;
                switch ($newPlanVal) {
                    case 'agency':
                    case 'master':    $autoLimit = null; break;
                    case 'sales':     $autoLimit = 20;   break;
                    case 'free':      $autoLimit = 0;    break;
                }
                if ($autoLimit === null) {
                    $assignments[] = quote_identifier($summaryLimitCol) . ' = NULL';
                } else {
                    $assignments[] = quote_identifier($summaryLimitCol) . ' = :auto_limit';
                    $params[':auto_limit'] = $autoLimit;
                }
            }
            // plan 변경 시 summary_limit_minutes 도 자동 동기화 (분 단위 — 신규 요금제 기준).
            $summaryLimitMinutesCol = first_existing_column($cols, ['summary_limit_minutes']);
            if ($planChanged && $summaryLimitMinutesCol && !isset($body['summary_limit_minutes'])) {
                $autoLimitMin = 0;
                switch ($newPlanVal) {
                    case 'agency':    $autoLimitMin = 1500; break;
                    case 'master':    $autoLimitMin = 700;  break;
                    case 'sales':     $autoLimitMin = 300;  break;
                    case 'free':      $autoLimitMin = 0;    break;
                }
                $assignments[] = quote_identifier($summaryLimitMinutesCol) . ' = :auto_limit_min';
                $params[':auto_limit_min'] = $autoLimitMin;
            }
            // 명시적 summary_limit_minutes (admin 수동 부여)
            if ($summaryLimitMinutesCol && array_key_exists('summary_limit_minutes', $body)) {
                $slm = $body['summary_limit_minutes'];
                if ($slm === null || $slm === '') {
                    $assignments[] = quote_identifier($summaryLimitMinutesCol) . ' = NULL';
                } else {
                    $slmVal = (int)$slm;
                    if ($slmVal < 0) $slmVal = 0;
                    $assignments[] = quote_identifier($summaryLimitMinutesCol) . ' = :slm';
                    $params[':slm'] = $slmVal;
                }
            }
            // 관리자 수동 overage_balance_seconds 부여 (분 단위 입력 → 초 단위 저장)
            $overageBalanceCol = first_existing_column($cols, ['overage_balance_seconds']);
            if ($overageBalanceCol && array_key_exists('overage_balance_minutes', $body)) {
                $obm = (int)$body['overage_balance_minutes'];
                if ($obm < 0) $obm = 0;
                $assignments[] = quote_identifier($overageBalanceCol) . ' = :obs';
                $params[':obs'] = $obm * 60;
            }
            // usage_seconds_period 초기화 (admin 수동 — 분 단위 입력)
            $usageSecondsCol = first_existing_column($cols, ['usage_seconds_period']);
            if ($usageSecondsCol && array_key_exists('usage_minutes_period', $body)) {
                $ump = (int)$body['usage_minutes_period'];
                if ($ump < 0) $ump = 0;
                $assignments[] = quote_identifier($usageSecondsCol) . ' = :ums';
                $params[':ums'] = $ump * 60;
            }
            $planStatusCol = first_existing_column($cols, ['plan_status']);
            if ($planStatusCol && isset($body['plan_status'])) {
                $psVal = strtolower(trim((string)$body['plan_status']));
                // 사장님 2026-05-25 — trialing 폐지.
                if (!in_array($psVal, ['active', 'past_due', 'cancelled'], true)) {
                    respond(['ok' => false, 'error' => '허용되지 않는 plan_status 값입니다.'], 400);
                }
                $assignments[] = quote_identifier($planStatusCol) . ' = :plan_status';
                $params[':plan_status'] = $psVal;
            }
            // summary_used 는 옛 컬럼명 free_summaries_used 사용
            $summaryUsedCol = first_existing_column($cols, ['free_summaries_used', 'summary_used']);
            if ($summaryUsedCol && isset($body['summary_used'])) {
                $usedVal = (int)$body['summary_used'];
                if ($usedVal < 0) $usedVal = 0;
                $assignments[] = quote_identifier($summaryUsedCol) . ' = :summary_used';
                $params[':summary_used'] = $usedVal;
            }
            // 만료일 (current_period_end) — 관리자 수동 변경 (오프라인/현금 결제 또는 테스터 무료 체험 기간).
            $periodEndCol = first_existing_column($cols, ['current_period_end']);
            if ($periodEndCol && array_key_exists('current_period_end', $body)) {
                $peVal = $body['current_period_end'];
                if ($peVal === null || $peVal === '') {
                    $assignments[] = quote_identifier($periodEndCol) . ' = NULL';
                } else {
                    // 형식 검증 — YYYY-MM-DD 또는 YYYY-MM-DD HH:MM:SS
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', (string)$peVal)) {
                        respond(['ok' => false, 'error' => '만료일 형식 오류 (YYYY-MM-DD 필요).'], 400);
                    }
                    $assignments[] = quote_identifier($periodEndCol) . ' = :period_end';
                    $params[':period_end'] = (string)$peVal;
                }
            }

            if (empty($assignments)) respond(['ok' => false, 'error' => '수정할 필드가 없습니다.'], 400);

            $updatedCol = first_existing_column($cols, ['updated_at', 'modified_at']);
            if ($updatedCol) {
                $assignments[] = quote_identifier($updatedCol) . ' = :updated_at';
                $params[':updated_at'] = date('Y-m-d H:i:s');
            }

            $emailCol = quote_identifier($store['email_column']);
            $sql = 'UPDATE ' . quote_identifier($store['table']) . ' SET ' . implode(', ', $assignments)
                . " WHERE LOWER({$emailCol}) = :email";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            record_activity($pdo, (string)($authUser['email'] ?? ''), 'admin.member.update', $email);
            respond(['ok' => true]);
        }

        respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
    }

    if ($resource === 'admin-stats') {
        enforce_admin($pdo, $authUser);
        if ($method !== 'GET') respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);

        $stats = [
            'totalCustomers' => 0,
            'totalEmployees' => 0,
            'totalMembers' => 0,
            'newMembers7d' => 0,
            'recentSignups' => [],
            'memberTrend' => [],
        ];

        try { $stats['totalCustomers'] = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn(); } catch (Throwable $e) {}
        try { $stats['totalEmployees'] = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn(); } catch (Throwable $e) {}

        $store = find_member_store($pdo);
        if ($store) {
            $table = quote_identifier($store['table']);
            try { $stats['totalMembers'] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(); } catch (Throwable $e) {}
            $createdCol = first_existing_column($store['columns'], ['created_at', 'created', 'registered_at', 'reg_date']);
            if ($createdCol) {
                $createdQ = quote_identifier($createdCol);
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$createdQ} >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
                    $stmt->execute();
                    $stats['newMembers7d'] = (int)$stmt->fetchColumn();
                } catch (Throwable $e) {}

                try {
                    $emailCol = quote_identifier($store['email_column']);
                    $rows = $pdo->query("SELECT * FROM {$table} ORDER BY {$createdQ} DESC LIMIT 5")->fetchAll();
                    $stats['recentSignups'] = array_map(function ($r) use ($store) { return member_row_from_store($store, $r); }, $rows);
                } catch (Throwable $e) {}

                try {
                    $stmt = $pdo->query("
                        SELECT DATE({$createdQ}) AS d, COUNT(*) AS c
                        FROM {$table}
                        WHERE {$createdQ} >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                        GROUP BY DATE({$createdQ})
                        ORDER BY d ASC
                    ");
                    $byDay = [];
                    foreach ($stmt as $row) { $byDay[$row['d']] = (int)$row['c']; }
                    $trend = [];
                    for ($i = 13; $i >= 0; $i--) {
                        $day = date('Y-m-d', strtotime("-{$i} day"));
                        $trend[] = ['date' => $day, 'count' => $byDay[$day] ?? 0];
                    }
                    $stats['memberTrend'] = $trend;
                } catch (Throwable $e) {}
            }
        }

        respond(['ok' => true, 'stats' => $stats]);
    }

    /* 사장님 2026-05-24 — 관리자 통계 페이지 (admin.html) 데이터 endpoint.
     * date range 받아서 일별 breakdown 으로 5개 지표 반환:
     *   visitors / pageviews / newPayments / cancelledSubs / summaryViews / autoConfirms
     * + referrer host top 10 합계.
     * GET /api/records.php?resource=admin-stats-range&from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    if ($resource === 'admin-stats-range') {
        enforce_admin($pdo, $authUser);
        if ($method !== 'GET') respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);

        $from = trim((string)($_GET['from'] ?? ''));
        $to   = trim((string)($_GET['to']   ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-13 day'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
        if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }
        // 최대 365일 제한 (DoS 방지)
        $fromTs = strtotime($from . ' 00:00:00');
        $toTs   = strtotime($to   . ' 23:59:59');
        if (($toTs - $fromTs) > 366 * 86400) {
            respond(['ok' => false, 'error' => '최대 365일 범위까지만 조회 가능합니다.'], 400);
        }

        // 일별 빈 buckets 미리 생성
        $daily = [];
        for ($t = $fromTs; $t <= $toTs; $t += 86400) {
            $d = date('Y-m-d', $t);
            $daily[$d] = [
                'date' => $d,
                'visitors' => 0,
                'pageviews' => 0,
                'newSignups' => 0,
                'newPayments' => 0,
                'cancelledSubs' => 0,
                'summaryViews' => 0,
                'autoConfirms' => 0,
            ];
        }
        // 사장님 2026-05-24 — 회원별 행동 패턴 분석용 이벤트 + 집계.
        $events = [];     // 시간순 통합 활동 로그 (5종 + signup)
        $memberAgg = [];  // email => { signupAt, payments, cancels, summaryViews, autoConfirms, lastActivity }
        $bumpAgg = function (string $email, string $key, string $occurredAt) use (&$memberAgg) {
            $email = strtolower(trim($email));
            if ($email === '') return;
            if (!isset($memberAgg[$email])) {
                $memberAgg[$email] = [
                    'email' => $email,
                    'signupAt' => null,
                    'payments' => 0, 'cancels' => 0,
                    'summaryViews' => 0, 'autoConfirms' => 0,
                    'lastActivity' => '',
                ];
            }
            if (isset($memberAgg[$email][$key])) $memberAgg[$email][$key]++;
            if ($occurredAt > ($memberAgg[$email]['lastActivity'] ?? '')) {
                $memberAgg[$email]['lastActivity'] = $occurredAt;
            }
        };

        // ── 방문자 / pageview (page_views, is_bot=0) ──
        try {
            $stmt = $pdo->prepare("SELECT DATE(created_at) AS d,
                       COUNT(DISTINCT session_id) AS visitors,
                       COUNT(*) AS pageviews
                FROM page_views
                WHERE is_bot=0 AND created_at BETWEEN :a AND :b
                GROUP BY DATE(created_at)");
            $stmt->execute([':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']);
            foreach ($stmt as $r) {
                $d = $r['d'];
                if (isset($daily[$d])) {
                    $daily[$d]['visitors']  = (int)$r['visitors'];
                    $daily[$d]['pageviews'] = (int)$r['pageviews'];
                }
            }
        } catch (Throwable $e) { /* table 없을 수 있음 (첫 deploy) */ }

        // ── 유입경로 — referrer_host 상위 10 ──
        $referrers = [];
        try {
            $stmt = $pdo->prepare("SELECT
                    CASE
                        WHEN utm_source <> '' THEN utm_source
                        WHEN referrer_host <> '' THEN referrer_host
                        ELSE '(direct)'
                    END AS source,
                    COUNT(*) AS c
                FROM page_views
                WHERE is_bot=0 AND created_at BETWEEN :a AND :b
                GROUP BY source
                ORDER BY c DESC LIMIT 10");
            $stmt->execute([':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']);
            foreach ($stmt as $r) $referrers[] = ['source' => (string)$r['source'], 'count' => (int)$r['c']];
        } catch (Throwable $e) {}

        // ── 신규가입 (사장님 2026-05-24) — members 의 created_at per day + events ──
        $storeForStats = find_member_store($pdo);
        if ($storeForStats) {
            $createdColS = first_existing_column($storeForStats['columns'], ['created_at', 'created', 'registered_at', 'reg_date']);
            if ($createdColS) {
                $cq = quote_identifier($createdColS);
                $eq = quote_identifier($storeForStats['email_column']);
                $tbl = quote_identifier($storeForStats['table']);
                try {
                    $stmt = $pdo->prepare("SELECT {$cq} AS at, {$eq} AS email
                        FROM {$tbl}
                        WHERE {$cq} BETWEEN :a AND :b
                        ORDER BY {$cq} DESC");
                    $stmt->execute([':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']);
                    foreach ($stmt as $r) {
                        $at = (string)$r['at'];
                        $email = strtolower(trim((string)$r['email']));
                        $d = substr($at, 0, 10);
                        if (isset($daily[$d])) $daily[$d]['newSignups']++;
                        $events[] = ['occurred_at' => $at, 'email' => $email, 'type' => 'signup', 'detail' => ''];
                        if ($email !== '' && !isset($memberAgg[$email])) {
                            $memberAgg[$email] = [
                                'email' => $email,
                                'signupAt' => $at,
                                'payments' => 0, 'cancels' => 0,
                                'summaryViews' => 0, 'autoConfirms' => 0,
                                'lastActivity' => $at,
                            ];
                        } elseif ($email !== '') {
                            $memberAgg[$email]['signupAt'] = $at;
                            if ($at > $memberAgg[$email]['lastActivity']) $memberAgg[$email]['lastActivity'] = $at;
                        }
                    }
                } catch (Throwable $e) {}
            }
        }

        // ── 신규결제 — payments status='paid' (or completed) per day + events ──
        try {
            $stmt = $pdo->prepare("SELECT created_at AS at, owner_email AS email, amount
                FROM payments
                WHERE status IN ('paid','PAID','completed','success')
                  AND created_at BETWEEN :a AND :b
                ORDER BY created_at DESC");
            $stmt->execute([':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']);
            foreach ($stmt as $r) {
                $at = (string)$r['at'];
                $email = strtolower(trim((string)$r['email']));
                $d = substr($at, 0, 10);
                if (isset($daily[$d])) $daily[$d]['newPayments']++;
                $detail = isset($r['amount']) && $r['amount'] !== null ? ('₩' . number_format((int)$r['amount'])) : '';
                $events[] = ['occurred_at' => $at, 'email' => $email, 'type' => 'payment', 'detail' => $detail];
                $bumpAgg($email, 'payments', $at);
            }
        } catch (Throwable $e) {}

        // ── 구독취소 — subscriptions cancelled_at OR status='cancelled' + events ──
        // (스키마 변형 대비 — cancelled_at 컬럼 우선, 없으면 status 패턴)
        try {
            $hasCancelledAt = false;
            try {
                $check = $pdo->query("SHOW COLUMNS FROM subscriptions LIKE 'cancelled_at'");
                $hasCancelledAt = (bool)$check->fetch();
            } catch (Throwable $e) {}
            if ($hasCancelledAt) {
                $stmt = $pdo->prepare("SELECT cancelled_at AS at, owner_email AS email
                    FROM subscriptions
                    WHERE cancelled_at IS NOT NULL
                      AND cancelled_at BETWEEN :a AND :b
                    ORDER BY cancelled_at DESC");
            } else {
                $stmt = $pdo->prepare("SELECT updated_at AS at, owner_email AS email
                    FROM subscriptions
                    WHERE status IN ('cancelled','canceled','CANCELLED')
                      AND updated_at BETWEEN :a AND :b
                    ORDER BY updated_at DESC");
            }
            $stmt->execute([':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']);
            foreach ($stmt as $r) {
                $at = (string)$r['at'];
                $email = strtolower(trim((string)$r['email']));
                $d = substr($at, 0, 10);
                if (isset($daily[$d])) $daily[$d]['cancelledSubs']++;
                $events[] = ['occurred_at' => $at, 'email' => $email, 'type' => 'cancel', 'detail' => ''];
                $bumpAgg($email, 'cancels', $at);
            }
        } catch (Throwable $e) {}

        // ── 요약보기 (auto_confirm=0) + 양식으로 전송 (auto_confirm=1) + events ──
        // trigger_summarize 호출 시점 = recording_jobs.created_at 기준.
        try {
            $stmt = $pdo->prepare("SELECT created_at AS at, owner_email AS email, auto_confirm
                FROM recording_jobs
                WHERE created_at BETWEEN :a AND :b
                ORDER BY created_at DESC");
            $stmt->execute([':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']);
            foreach ($stmt as $r) {
                $at = (string)$r['at'];
                $email = strtolower(trim((string)$r['email']));
                $d = substr($at, 0, 10);
                $isAuto = !empty($r['auto_confirm']);
                if (isset($daily[$d])) {
                    if ($isAuto) $daily[$d]['autoConfirms']++;
                    else         $daily[$d]['summaryViews']++;
                }
                $events[] = [
                    'occurred_at' => $at,
                    'email' => $email,
                    'type' => $isAuto ? 'auto_confirm' : 'summary_view',
                    'detail' => '',
                ];
                $bumpAgg($email, $isAuto ? 'autoConfirms' : 'summaryViews', $at);
            }
        } catch (Throwable $e) {}

        // ── 사장님 2026-05-24 (확장) — recording_jobs 상태별 + STT 성공률 + latency + 통화길이 ──
        $jobsStats = [
            'byStatus' => [],
            'sttSuccessRate' => null,    // (completed + saved) / total
            'avgLatencySec' => null,     // 평균 처리 시간 (started_at ~ completed_at)
            'avgDurationSec' => null,    // 평균 통화 길이
            'totalJobs' => 0,
            'successJobs' => 0,
            'failedJobs' => 0,
        ];
        try {
            $stmt = $pdo->prepare("SELECT status, COUNT(*) AS c,
                       AVG(duration_sec) AS avg_dur,
                       AVG(CASE WHEN started_at IS NOT NULL AND completed_at IS NOT NULL
                                THEN TIMESTAMPDIFF(SECOND, started_at, completed_at) END) AS avg_lat
                FROM recording_jobs
                WHERE created_at BETWEEN :a AND :b
                GROUP BY status");
            $stmt->execute([':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']);
            $sumDur = 0; $sumDurN = 0; $sumLat = 0; $sumLatN = 0;
            foreach ($stmt as $r) {
                $s = (string)$r['status'];
                $c = (int)$r['c'];
                $jobsStats['byStatus'][$s] = $c;
                $jobsStats['totalJobs'] += $c;
                if (in_array($s, ['completed','saved'], true)) $jobsStats['successJobs'] += $c;
                if (in_array($s, ['failed_retryable','failed_permanent'], true)) $jobsStats['failedJobs'] += $c;
                if ($r['avg_dur'] !== null) { $sumDur += ((float)$r['avg_dur']) * $c; $sumDurN += $c; }
                if ($r['avg_lat'] !== null) { $sumLat += ((float)$r['avg_lat']) * $c; $sumLatN += $c; }
            }
            if ($sumDurN > 0) $jobsStats['avgDurationSec'] = round($sumDur / $sumDurN, 1);
            if ($sumLatN > 0) $jobsStats['avgLatencySec'] = round($sumLat / $sumLatN, 1);
            if ($jobsStats['totalJobs'] > 0) {
                $jobsStats['sttSuccessRate'] = round($jobsStats['successJobs'] / $jobsStats['totalJobs'] * 100, 1);
            }
        } catch (Throwable $e) {}

        // ── 회원별 요약 사용시간/사용률 ★사장님 중요 표시 (members 현재 상태) ──
        // usage_seconds_period (초) / summary_limit_minutes (분) 기준.
        $memberUsage = [];
        if ($storeForStats) {
            $eq = quote_identifier($storeForStats['email_column']);
            $tbl = quote_identifier($storeForStats['table']);
            $cols = $storeForStats['columns'];
            // 필요한 컬럼 존재 여부 확인
            $hasPlan      = in_array('plan', $cols, true);
            $hasPlanStat  = in_array('plan_status', $cols, true);
            $hasUsageSec  = in_array('usage_seconds_period', $cols, true);
            $hasLimitMin  = in_array('summary_limit_minutes', $cols, true);
            $hasOverEn    = in_array('overage_enabled', $cols, true);
            $hasOverBal   = in_array('overage_balance_seconds', $cols, true);
            $hasPeriodEnd = in_array('current_period_end', $cols, true);
            $hasCreated   = in_array('created_at', $cols, true);
            try {
                $select = [$eq . ' AS email'];
                if ($hasPlan)      $select[] = 'plan';
                if ($hasPlanStat)  $select[] = 'plan_status';
                if ($hasUsageSec)  $select[] = 'usage_seconds_period';
                if ($hasLimitMin)  $select[] = 'summary_limit_minutes';
                if ($hasOverEn)    $select[] = 'overage_enabled';
                if ($hasOverBal)   $select[] = 'overage_balance_seconds';
                if ($hasPeriodEnd) $select[] = 'current_period_end';
                if ($hasCreated)   $select[] = 'created_at';
                // 사용량 우선 + 가입 최근 순 정렬
                $orderBy = $hasUsageSec ? "usage_seconds_period DESC" : ($hasCreated ? "created_at DESC" : $eq);
                $sql = "SELECT " . implode(', ', $select) . " FROM {$tbl} ORDER BY {$orderBy} LIMIT 200";
                foreach ($pdo->query($sql) as $r) {
                    $usedSec = (int)($r['usage_seconds_period'] ?? 0);
                    $limitMin = (int)($r['summary_limit_minutes'] ?? 0);
                    $usedMin = $usedSec / 60;
                    $pct = ($limitMin > 0) ? round(($usedMin / $limitMin) * 100, 1) : 0;
                    $overBalSec = (int)($r['overage_balance_seconds'] ?? 0);
                    $memberUsage[] = [
                        'email'        => (string)$r['email'],
                        'plan'         => (string)($r['plan'] ?? ''),
                        'planStatus'   => (string)($r['plan_status'] ?? ''),
                        'usedMin'      => round($usedMin, 1),
                        'limitMin'     => $limitMin,
                        'usagePct'     => $pct,
                        'overageEnabled'=> !empty($r['overage_enabled']) ? 1 : 0,
                        'overageBalMin' => round($overBalSec / 60, 1),
                        'periodEnd'    => (string)($r['current_period_end'] ?? ''),
                        'createdAt'    => (string)($r['created_at'] ?? ''),
                    ];
                }
            } catch (Throwable $e) {}
        }

        // ── 플랜별 분포 ──
        // 사장님 2026-05-26 — 신규 요금제 (sales/master/agency). 옛 plus/pro/premium/trialing 은 정규화.
        $planDistribution = ['free'=>0,'sales'=>0,'master'=>0,'agency'=>0,'other'=>0];
        if ($storeForStats && in_array('plan', $storeForStats['columns'], true)) {
            $tbl = quote_identifier($storeForStats['table']);
            try {
                $stmt = $pdo->query("SELECT plan, COUNT(*) AS c FROM {$tbl} GROUP BY plan");
                foreach ($stmt as $r) {
                    $p = (string)$r['plan'];
                    $c = (int)$r['c'];
                    // 옛 plan key 정규화 (DB migration 잔재 호환)
                    if ($p === 'trialing') $p = 'free';
                    if ($p === 'plus' || $p === 'premium') $p = 'sales';
                    if ($p === 'pro') $p = 'master';
                    if (isset($planDistribution[$p])) $planDistribution[$p] += $c;
                    else $planDistribution['other'] += $c;
                }
            } catch (Throwable $e) {}
        }

        // ── 일별 매출 추이 ──
        $dailyRevenue = [];
        foreach ($daily as $d => $_) $dailyRevenue[$d] = ['date' => $d, 'revenue' => 0];
        try {
            $stmt = $pdo->prepare("SELECT DATE(created_at) AS d, SUM(amount) AS rev
                FROM payments
                WHERE status IN ('paid','PAID','completed','success')
                  AND created_at BETWEEN :a AND :b
                GROUP BY DATE(created_at)");
            $stmt->execute([':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']);
            foreach ($stmt as $r) {
                $d = (string)$r['d'];
                if (isset($dailyRevenue[$d])) $dailyRevenue[$d]['revenue'] = (int)$r['rev'];
            }
        } catch (Throwable $e) {}
        $totalRevenue = array_sum(array_column($dailyRevenue, 'revenue'));

        // ── MRR / ARPU ──
        // 사장님 2026-05-28 — VAT 별도 정책. price=청구액(VAT 포함) / price_display=공급가액(사장님 매출).
        // MRR 은 사장님 매출 기준 = price_display (VAT 제외) × active subscriptions.
        $planPrices = [
            'sales'   => ['price' => 26400, 'price_display' => 24000, 'vat_excluded' => true, 'minutes' => 300],
            'master'  => ['price' => 51700, 'price_display' => 47000, 'vat_excluded' => true, 'minutes' => 700],
            'agency'  => ['price' => 97900, 'price_display' => 89000, 'vat_excluded' => true, 'minutes' => 1500],
            // 옛 plan key fallback (DB migration 잔재 호환)
            'plus'    => ['price' => 26400, 'price_display' => 24000, 'vat_excluded' => true, 'minutes' => 300],
            'pro'     => ['price' => 51700, 'price_display' => 47000, 'vat_excluded' => true, 'minutes' => 700],
            'premium' => ['price' => 26400, 'price_display' => 24000, 'vat_excluded' => true, 'minutes' => 300],
        ];
        $mrr = 0;
        $activeSubsCount = 0;
        try {
            $stmt = $pdo->query("SELECT plan, COUNT(*) AS c FROM subscriptions WHERE status='active' GROUP BY plan");
            foreach ($stmt as $r) {
                $p = (string)$r['plan'];
                $c = (int)$r['c'];
                $activeSubsCount += $c;
                $mrr += (int)($planPrices[$p]['price_display'] ?? 0) * $c;
            }
        } catch (Throwable $e) {}
        $arpu = $activeSubsCount > 0 ? (int)round($mrr / $activeSubsCount) : 0;

        // ── MAU / DAU (page_views, 로그인된 owner_email 기준) ──
        $mau = 0; $dau = 0;
        try {
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT owner_email) FROM page_views
                WHERE is_bot=0 AND owner_email IS NOT NULL AND owner_email <> ''
                  AND created_at >= DATE_SUB(:n, INTERVAL 30 DAY)");
            $stmt->execute([':n' => $now]);
            $mau = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT owner_email) FROM page_views
                WHERE is_bot=0 AND owner_email IS NOT NULL AND owner_email <> ''
                  AND created_at >= DATE_SUB(:n, INTERVAL 1 DAY)");
            $stmt->execute([':n' => $now]);
            $dau = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {}

        // ── Funnel — 가입 → 첫통화 → 첫고객 → 첫결제 (기간 내 가입자 기준) ──
        $funnel = ['signups' => 0, 'firstCallers' => 0, 'firstSavers' => 0, 'firstPayers' => 0];
        if ($storeForStats) {
            $createdColF = first_existing_column($storeForStats['columns'], ['created_at', 'created', 'registered_at']);
            if ($createdColF) {
                $cq = quote_identifier($createdColF);
                $eq = quote_identifier($storeForStats['email_column']);
                $tbl = quote_identifier($storeForStats['table']);
                try {
                    // 기간 내 가입자 email 수집
                    $stmt = $pdo->prepare("SELECT LOWER({$eq}) AS email FROM {$tbl}
                        WHERE {$cq} BETWEEN :a AND :b");
                    $stmt->execute([':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']);
                    $signupEmails = [];
                    foreach ($stmt as $r) { $e = trim((string)$r['email']); if ($e !== '') $signupEmails[$e] = true; }
                    $funnel['signups'] = count($signupEmails);
                    if (!empty($signupEmails)) {
                        $placeholders = implode(',', array_fill(0, count($signupEmails), '?'));
                        $emails = array_keys($signupEmails);
                        // 첫 통화 — recording_jobs distinct owner_email
                        try {
                            $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT owner_email) FROM recording_jobs
                                WHERE LOWER(owner_email) IN ($placeholders)");
                            $stmt2->execute($emails);
                            $funnel['firstCallers'] = (int)$stmt2->fetchColumn();
                        } catch (Throwable $e) {}
                        // 첫 고객 저장 — customer_log distinct owner_email
                        try {
                            $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT owner_email) FROM customer_log
                                WHERE LOWER(owner_email) IN ($placeholders)");
                            $stmt2->execute($emails);
                            $funnel['firstSavers'] = (int)$stmt2->fetchColumn();
                        } catch (Throwable $e) {}
                        // 첫 결제 — payments paid distinct owner_email
                        try {
                            $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT owner_email) FROM payments
                                WHERE status IN ('paid','PAID','completed','success')
                                  AND LOWER(owner_email) IN ($placeholders)");
                            $stmt2->execute($emails);
                            $funnel['firstPayers'] = (int)$stmt2->fetchColumn();
                        } catch (Throwable $e) {}
                    }
                } catch (Throwable $e) {}
            }
        }

        // ── AI provider 사용량 (customer_log.ai_model 패턴 분류) ──
        $providerUsage = ['whisper'=>0,'clova'=>0,'claude'=>0,'gpt'=>0];
        try {
            $stmt = $pdo->prepare("SELECT ai_model FROM customer_log
                WHERE ai_generated_at BETWEEN :a AND :b
                  AND ai_model IS NOT NULL AND ai_model <> ''");
            $stmt->execute([':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']);
            foreach ($stmt as $r) {
                $m = strtolower((string)$r['ai_model']);
                if (strpos($m, 'whisper') !== false) $providerUsage['whisper']++;
                if (strpos($m, 'clova') !== false)   $providerUsage['clova']++;
                if (strpos($m, 'claude') !== false)  $providerUsage['claude']++;
                if (strpos($m, 'gpt') !== false)     $providerUsage['gpt']++;
            }
        } catch (Throwable $e) {}

        // ── 자동충전 통계 ──
        $autoChargeStats = ['enabledCount'=>0,'totalMembers'=>0,'avgBalanceMin'=>0,'enabledPct'=>0];
        if ($storeForStats) {
            $tbl = quote_identifier($storeForStats['table']);
            try {
                $row = $pdo->query("SELECT COUNT(*) AS total,
                           SUM(CASE WHEN overage_enabled=1 THEN 1 ELSE 0 END) AS enabled,
                           AVG(overage_balance_seconds) AS avg_bal
                    FROM {$tbl}")->fetch();
                if ($row) {
                    $autoChargeStats['totalMembers'] = (int)$row['total'];
                    $autoChargeStats['enabledCount'] = (int)$row['enabled'];
                    $autoChargeStats['avgBalanceMin'] = round(((float)$row['avg_bal']) / 60, 1);
                    if ($autoChargeStats['totalMembers'] > 0) {
                        $autoChargeStats['enabledPct'] = round($autoChargeStats['enabledCount'] / $autoChargeStats['totalMembers'] * 100, 1);
                    }
                }
            } catch (Throwable $e) {}
        }

        // 이벤트 시간순 정렬 + 최대 500건 (UI 부담 방지)
        usort($events, function ($a, $b) { return strcmp($b['occurred_at'], $a['occurred_at']); });
        if (count($events) > 500) $events = array_slice($events, 0, 500);

        // 회원별 집계 — 활동 합계 기준 정렬 + 상위 50
        $members = array_values($memberAgg);
        usort($members, function ($a, $b) {
            $ta = ($a['payments'] + $a['cancels'] + $a['summaryViews'] + $a['autoConfirms']);
            $tb = ($b['payments'] + $b['cancels'] + $b['summaryViews'] + $b['autoConfirms']);
            if ($ta !== $tb) return $tb <=> $ta;
            return strcmp($b['lastActivity'] ?? '', $a['lastActivity'] ?? '');
        });
        if (count($members) > 50) $members = array_slice($members, 0, 50);

        // 합계 (요약 카드용)
        $totals = ['visitors'=>0,'pageviews'=>0,'newSignups'=>0,'newPayments'=>0,'cancelledSubs'=>0,'summaryViews'=>0,'autoConfirms'=>0];
        foreach ($daily as $row) {
            foreach ($totals as $k => $_) $totals[$k] += $row[$k];
        }

        respond([
            'ok' => true,
            'range' => ['from' => $from, 'to' => $to],
            'totals' => $totals,
            'daily' => array_values($daily),
            'referrers' => $referrers,
            'events' => $events,
            'members' => $members,
            // 사장님 2026-05-24 — ChatGPT 추천 항목 9가지 확장
            'jobsStats' => $jobsStats,
            'memberUsage' => $memberUsage,
            'planDistribution' => $planDistribution,
            // 사장님 2026-05-28 — VAT 별도 정책. admin UI 가 price/price_display 둘 다 표시 가능.
            'planPrices' => $planPrices,
            'dailyRevenue' => array_values($dailyRevenue),
            'totalRevenue' => $totalRevenue,
            'mrr' => $mrr,
            'arpu' => $arpu,
            'activeSubsCount' => $activeSubsCount,
            'mauDau' => ['mau' => $mau, 'dau' => $dau],
            'funnel' => $funnel,
            'providerUsage' => $providerUsage,
            'autoChargeStats' => $autoChargeStats,
        ]);
    }

    if ($resource === 'admin-logs') {
        enforce_admin($pdo, $authUser);
        if ($method !== 'GET') respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);

        ensure_activity_log_table($pdo);
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        try {
            $stmt = $pdo->prepare('SELECT actor_email, event_type, detail, created_at FROM activity_logs ORDER BY id DESC LIMIT ' . $limit);
            $stmt->execute();
            $items = $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            $items = [];
        }
        respond(['ok' => true, 'items' => array_map(function ($r) {
            return [
                'actor' => $r['actor_email'] ?? '',
                'event' => $r['event_type'] ?? '',
                'detail' => $r['detail'] ?? '',
                'createdAt' => $r['created_at'] ?? '',
            ];
        }, $items)]);
    }

    if ($resource === 'admin-cleanup-orphans') {
        enforce_admin($pdo, $authUser);
        if ($method !== 'POST') respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);

        // 보안 마이그레이션 이전에 owner 없이 들어간 레거시 행을 일괄 삭제.
        // owner_email IS NULL OR '' 인 행이 대상.
        $deleted = ['customers' => 0, 'employees' => 0];
        foreach (['customers', 'employees'] as $tbl) {
            try {
                ensure_owner_column($pdo, $tbl);
                $stmt = $pdo->prepare("DELETE FROM " . quote_identifier($tbl)
                    . " WHERE owner_email IS NULL OR owner_email = ''");
                $stmt->execute();
                $deleted[$tbl] = $stmt->rowCount();
            } catch (Throwable $e) {
                respond(['ok' => false, 'error' => $tbl . ' 삭제 실패: ' . $e->getMessage()], 500);
            }
        }
        record_activity($pdo, (string)($authUser['email'] ?? ''), 'admin.cleanup.orphans',
            "customers={$deleted['customers']}, employees={$deleted['employees']}");
        respond(['ok' => true, 'deleted' => $deleted]);
    }

    if ($resource === 'admin-settings') {
        enforce_admin($pdo, $authUser);
        ensure_site_settings_table($pdo);

        if ($method === 'GET') {
            $items = [];
            try {
                $rows = $pdo->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
                foreach ($rows as $row) {
                    $items[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Throwable $e) {}
            respond(['ok' => true, 'settings' => $items]);
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];
            if (empty($settings)) respond(['ok' => false, 'error' => '저장할 설정이 없습니다.'], 400);

            $stmt = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (:k, :v)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($settings as $k => $v) {
                $key = substr((string)$k, 0, 64);
                $val = $v === null ? null : substr((string)$v, 0, 65000);
                $stmt->execute([':k' => $key, ':v' => $val]);
            }

            record_activity($pdo, (string)($authUser['email'] ?? ''), 'admin.settings.update');
            respond(['ok' => true]);
        }

        respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
    }

    /* ============================================================
       커뮤니티 게시판 — 공지사항 / 자유게시판 / 문의게시판
       ============================================================ */
    if ($resource === 'community-posts') {
        enforce_registered_member($pdo, $authUser);
        if (!ensure_community_posts_table($pdo)) {
            respond(['ok' => false, 'error' => '게시판 테이블 마이그레이션 실패'], 503);
        }
        $owner = current_owner_email($authUser);
        if ($owner === '') respond(['ok' => false, 'error' => '인증된 사용자 정보가 없습니다.'], 401);
        $isAdmin = current_user_is_admin($pdo, $authUser);

        if ($method === 'GET') {
            $idQ = $_GET['id'] ?? '';
            if ($idQ !== '') {
                $id = (int)$idQ;
                if ($id <= 0) respond(['ok' => false, 'error' => 'id 가 올바르지 않습니다.'], 400);
                $stmt = $pdo->prepare('SELECT * FROM community_posts WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();
                if (!$row) respond(['ok' => false, 'error' => '게시글을 찾을 수 없습니다.'], 404);
                // 조회수 +1 (작성자 본인 조회는 제외)
                if (strtolower($row['author_email']) !== $owner) {
                    $pdo->prepare('UPDATE community_posts SET view_count = view_count + 1 WHERE id = :id')->execute([':id' => $id]);
                    $row['view_count'] = ((int)$row['view_count']) + 1;
                }
                respond(['ok' => true, 'post' => community_post_row($row)]);
            }

            $cat = (string)($_GET['category'] ?? '');
            if ($cat === '' || !valid_community_category($cat)) {
                respond(['ok' => false, 'error' => 'category 가 올바르지 않습니다 (notice|free|qna).'], 400);
            }
            $page = max(1, (int)($_GET['page'] ?? 1));
            $size = min(100, max(5, (int)($_GET['size'] ?? 20)));
            $offset = ($page - 1) * $size;
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM community_posts WHERE category = :c');
            $countStmt->execute([':c' => $cat]);
            $total = (int)$countStmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT * FROM community_posts WHERE category = :c
                                   ORDER BY pinned DESC, id DESC
                                   LIMIT :lim OFFSET :off');
            $stmt->bindValue(':c', $cat, PDO::PARAM_STR);
            $stmt->bindValue(':lim', $size, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $items = array_map('community_post_row', $stmt->fetchAll());
            respond(['ok' => true, 'items' => $items, 'total' => $total, 'page' => $page, 'size' => $size]);
        }

        if ($method === 'POST') {
            $cat = trim((string)($body['category'] ?? ''));
            $title = trim((string)($body['title'] ?? ''));
            $bodyText = (string)($body['body'] ?? '');
            $authorName = trim((string)($body['authorName'] ?? ''));
            if (!valid_community_category($cat)) respond(['ok' => false, 'error' => 'category 가 올바르지 않습니다.'], 400);
            if ($title === '') respond(['ok' => false, 'error' => '제목을 입력해주세요.'], 400);
            if (mb_strlen($title) > 200) respond(['ok' => false, 'error' => '제목이 너무 깁니다 (200자 이내).'], 400);
            // 공지사항은 admin 만 작성 가능
            if ($cat === 'notice' && !$isAdmin) {
                respond(['ok' => false, 'error' => '공지사항은 관리자만 작성할 수 있습니다.'], 403);
            }
            $ins = $pdo->prepare('INSERT INTO community_posts (category, title, body, author_email, author_name)
                                  VALUES (:c, :t, :b, :e, :n)');
            $ins->execute([':c' => $cat, ':t' => $title, ':b' => $bodyText, ':e' => $owner, ':n' => $authorName]);
            respond(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        }

        if ($method === 'PATCH' || $method === 'PUT') {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) respond(['ok' => false, 'error' => 'id 가 필요합니다.'], 400);
            $stmt = $pdo->prepare('SELECT * FROM community_posts WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if (!$row) respond(['ok' => false, 'error' => '게시글을 찾을 수 없습니다.'], 404);
            // 작성자 본인 또는 admin 만 수정
            if (strtolower($row['author_email']) !== $owner && !$isAdmin) {
                respond(['ok' => false, 'error' => '수정 권한이 없습니다.'], 403);
            }
            $assignments = [];
            $params = [':id' => $id];
            if (array_key_exists('title', $body)) {
                $title = trim((string)$body['title']);
                if ($title === '') respond(['ok' => false, 'error' => '제목은 비울 수 없습니다.'], 400);
                if (mb_strlen($title) > 200) respond(['ok' => false, 'error' => '제목이 너무 깁니다.'], 400);
                $assignments[] = 'title = :title';
                $params[':title'] = $title;
            }
            if (array_key_exists('body', $body)) {
                $assignments[] = 'body = :body';
                $params[':body'] = (string)$body['body'];
            }
            if (array_key_exists('pinned', $body) && $isAdmin) {
                $assignments[] = 'pinned = :p';
                $params[':p'] = !empty($body['pinned']) ? 1 : 0;
            }
            if (!$assignments) respond(['ok' => false, 'error' => '수정할 필드가 없습니다.'], 400);
            $upd = $pdo->prepare('UPDATE community_posts SET ' . implode(', ', $assignments) . ' WHERE id = :id');
            $upd->execute($params);
            respond(['ok' => true]);
        }

        if ($method === 'DELETE') {
            $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) respond(['ok' => false, 'error' => 'id 가 필요합니다.'], 400);
            $stmt = $pdo->prepare('SELECT author_email FROM community_posts WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if (!$row) respond(['ok' => false, 'error' => '게시글을 찾을 수 없습니다.'], 404);
            if (strtolower($row['author_email']) !== $owner && !$isAdmin) {
                respond(['ok' => false, 'error' => '삭제 권한이 없습니다.'], 403);
            }
            $del = $pdo->prepare('DELETE FROM community_posts WHERE id = :id');
            $del->execute([':id' => $id]);
            respond(['ok' => true]);
        }

        respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
    }

    /* ============================================================
       Ledger 시스템 — 그룹/레코드 (계약자·조직도·고객 관리대장 공통)
       ============================================================ */
    if ($resource === 'ledger-groups' || $resource === 'ledger-records' || $resource === 'ledger-records-bulk' || $resource === 'mobile-tokens') {
        enforce_registered_member($pdo, $authUser);
        if ($resource !== 'mobile-tokens' && !ensure_ledger_tables($pdo)) {
            respond(['ok' => false, 'error' => 'ledger 마이그레이션 실패 — 잠시 후 다시 시도'], 503);
        }
        $owner = current_owner_email($authUser);
        if ($owner === '') respond(['ok' => false, 'error' => '인증된 사용자 정보가 없습니다.'], 401);

        if ($resource === 'ledger-groups') {
            if ($method === 'GET') {
                $pageType = (string)($_GET['page_type'] ?? '');
                if ($pageType !== '' && !valid_ledger_page_type($pageType)) {
                    respond(['ok' => false, 'error' => 'page_type 값이 올바르지 않습니다.'], 400);
                }
                $sql = 'SELECT * FROM ledger_groups WHERE owner_email = :o';
                $params = [':o' => $owner];
                if ($pageType !== '') { $sql .= ' AND page_type = :pt'; $params[':pt'] = $pageType; }
                $sql .= ' ORDER BY sort_order ASC, id ASC';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                respond(['ok' => true, 'items' => array_map('ledger_group_row', $stmt->fetchAll())]);
            }

            if ($method === 'POST') {
                $pageType = (string)($body['pageType'] ?? '');
                $name = trim((string)($body['name'] ?? ''));
                if (!valid_ledger_page_type($pageType)) respond(['ok' => false, 'error' => 'pageType 이 올바르지 않습니다.'], 400);
                if ($name === '') respond(['ok' => false, 'error' => '그룹 이름은 필수입니다.'], 400);

                $isDefault = !empty($body['isDefault']) ? 1 : 0;
                $sortOrder = (int)($body['sortOrder'] ?? 0);
                // 사용자 정의 양식 정의는 라벨/수식에 PII 가 섞일 수 있어 AES-256-GCM 으로 암호화 저장.
                $fieldSchema = isset($body['fieldSchema']) ? youngman_encrypt_json($body['fieldSchema']) : null;
                $settings    = isset($body['settings'])    ? youngman_encrypt_json($body['settings'])    : null;

                // is_default 가 켜진 그룹은 같은 owner+page_type 내에서 1개만 유지.
                if ($isDefault) {
                    $unset = $pdo->prepare('UPDATE ledger_groups SET is_default = 0 WHERE owner_email = :o AND page_type = :pt');
                    $unset->execute([':o' => $owner, ':pt' => $pageType]);
                }

                $stmt = $pdo->prepare('
                    INSERT INTO ledger_groups (owner_email, page_type, name, is_default, sort_order, field_schema_json, settings_json)
                    VALUES (:o, :pt, :name, :def, :so, :fs, :st)
                ');
                $stmt->execute([
                    ':o' => $owner, ':pt' => $pageType, ':name' => $name,
                    ':def' => $isDefault, ':so' => $sortOrder,
                    ':fs' => $fieldSchema, ':st' => $settings,
                ]);
                respond(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
            }

            if ($method === 'PATCH' || $method === 'PUT') {
                $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
                if (!$id) respond(['ok' => false, 'error' => 'id 가 필요합니다.'], 400);
                $existing = ensure_ledger_group_owner($pdo, $id, $owner);

                $assignments = [];
                $params = [':id' => $id];

                if (array_key_exists('name', $body)) {
                    $assignments[] = 'name = :name';
                    $params[':name'] = trim((string)$body['name']);
                }
                if (array_key_exists('sortOrder', $body)) {
                    $assignments[] = 'sort_order = :so';
                    $params[':so'] = (int)$body['sortOrder'];
                }
                if (array_key_exists('fieldSchema', $body)) {
                    $assignments[] = 'field_schema_json = :fs';
                    // 양식 정의도 AES-256-GCM 으로 암호화.
                    $params[':fs'] = youngman_encrypt_json($body['fieldSchema']);
                }
                if (array_key_exists('settings', $body)) {
                    $assignments[] = 'settings_json = :st';
                    $params[':st'] = youngman_encrypt_json($body['settings']);
                }
                if (array_key_exists('isDefault', $body)) {
                    $isDef = !empty($body['isDefault']) ? 1 : 0;
                    if ($isDef) {
                        // 같은 owner+page_type 내 다른 그룹 default 해제.
                        $unset = $pdo->prepare('UPDATE ledger_groups SET is_default = 0 WHERE owner_email = :o AND page_type = :pt AND id != :id');
                        $unset->execute([':o' => $owner, ':pt' => $existing['page_type'], ':id' => $id]);
                    }
                    $assignments[] = 'is_default = :def';
                    $params[':def'] = $isDef;
                }
                if (!$assignments) respond(['ok' => false, 'error' => '수정할 필드가 없습니다.'], 400);

                $stmt = $pdo->prepare('UPDATE ledger_groups SET ' . implode(', ', $assignments) . ' WHERE id = :id');
                $stmt->execute($params);
                respond(['ok' => true]);
            }

            if ($method === 'DELETE') {
                $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
                if (!$id) respond(['ok' => false, 'error' => 'id 가 필요합니다.'], 400);
                ensure_ledger_group_owner($pdo, $id, $owner);
                // 레코드도 함께 삭제 (FK CASCADE 가 없는 환경 대비 명시적 삭제).
                $pdo->prepare('DELETE FROM ledger_records WHERE group_id = :g AND owner_email = :o')
                    ->execute([':g' => $id, ':o' => $owner]);
                $pdo->prepare('DELETE FROM ledger_groups WHERE id = :id AND owner_email = :o')
                    ->execute([':id' => $id, ':o' => $owner]);
                respond(['ok' => true]);
            }

            respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
        }

        if ($resource === 'ledger-records') {
            if ($method === 'GET') {
                // group_id 단일 또는 group_ids=1,2,3 멀티 지원.
                $rawIds = (string)($_GET['group_ids'] ?? $_GET['group_id'] ?? '');
                $ids = array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', $rawIds)), function ($v) { return $v > 0; }));
                if (!$ids) respond(['ok' => false, 'error' => 'group_id 또는 group_ids 가 필요합니다.'], 400);

                // 모든 그룹이 현재 사용자 소유인지 확인.
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $check = $pdo->prepare('SELECT id, owner_email FROM ledger_groups WHERE id IN (' . $placeholders . ')');
                $check->execute($ids);
                $found = $check->fetchAll();
                if (count($found) !== count($ids)) {
                    respond(['ok' => false, 'error' => '존재하지 않는 그룹이 포함됨.'], 404);
                }
                foreach ($found as $g) {
                    if (strtolower((string)$g['owner_email']) !== $owner) {
                        respond(['ok' => false, 'error' => '권한 없는 그룹이 포함됨.'], 403);
                    }
                }

                $sql = 'SELECT * FROM ledger_records WHERE group_id IN (' . $placeholders . ') AND owner_email = ?
                        ORDER BY group_id ASC, sort_no ASC, id ASC';
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge($ids, [$owner]));
                respond(['ok' => true, 'items' => array_map('ledger_record_row', $stmt->fetchAll())]);
            }

            if ($method === 'POST') {
                $groupId = (int)($body['groupId'] ?? 0);
                if (!$groupId) respond(['ok' => false, 'error' => 'groupId 가 필요합니다.'], 400);
                ensure_ledger_group_owner($pdo, $groupId, $owner);

                $data = isset($body['data']) ? (array)$body['data'] : [];
                $idemKey = trim((string)($body['clientIdempotencyKey'] ?? ''));
                $source  = trim((string)($body['source'] ?? 'web'));
                if (strlen($source) > 40) $source = substr($source, 0, 40);

                // page_type='customer' 그룹의 새 row 자동 보강:
                //  - managed: 명시적으로 false 가 아니면 true (기본 관리중)
                //  - call_count: 비어있고 phone 있으면 같은 group + 동일 phone 카운트 + 1
                $pageType = '';
                try {
                    $pt = $pdo->prepare('SELECT page_type FROM ledger_groups WHERE id = :g LIMIT 1');
                    $pt->execute([':g' => $groupId]);
                    $pageType = (string)($pt->fetchColumn() ?: '');
                } catch (Throwable $e) {}
                if ($pageType === 'customer') {
                    if (!array_key_exists('managed', $data) || $data['managed'] === '' || $data['managed'] === null) {
                        $data['managed'] = true;
                    }
                    $existingCallCount = $data['call_count'] ?? '';
                    if ($existingCallCount === '' || $existingCallCount === null || (int)$existingCallCount < 1) {
                        $phoneRaw = (string)($data['phone'] ?? '');
                        if ($phoneRaw !== '') {
                            $data['call_count'] = calculate_call_count($pdo, $groupId, $owner, $phoneRaw);
                        }
                    }
                }

                // 멱등성: 같은 owner_email + idempotency_key 가 이미 있으면 그 row 반환.
                if ($idemKey !== '') {
                    $existing = $pdo->prepare('SELECT id FROM ledger_records WHERE owner_email = :o AND client_idempotency_key = :k LIMIT 1');
                    $existing->execute([':o' => $owner, ':k' => $idemKey]);
                    $hit = $existing->fetchColumn();
                    if ($hit) respond(['ok' => true, 'id' => (int)$hit, 'duplicate' => true]);
                }

                // sort_no = 그룹 내 max + 1 (자동 NO).
                $next = $pdo->prepare('SELECT IFNULL(MAX(sort_no), 0) + 1 FROM ledger_records WHERE group_id = :g');
                $next->execute([':g' => $groupId]);
                $sortNo = (int)$next->fetchColumn();

                $stmt = $pdo->prepare('
                    INSERT INTO ledger_records (group_id, owner_email, sort_no, data_json, client_idempotency_key, source)
                    VALUES (:g, :o, :sn, :d, :k, :s)
                ');
                $stmt->execute([
                    ':g' => $groupId, ':o' => $owner, ':sn' => $sortNo,
                    // 고객/계약/직원 등 모든 행 데이터를 AES-256-GCM 으로 암호화하여 저장
                    ':d' => youngman_encrypt_json($data),
                    ':k' => $idemKey !== '' ? $idemKey : null,
                    ':s' => $source,
                ]);
                respond(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'sortNo' => $sortNo]);
            }

            if ($method === 'PATCH' || $method === 'PUT') {
                $id = (int)($body['id'] ?? 0);
                if (!$id) respond(['ok' => false, 'error' => 'id 가 필요합니다.'], 400);
                $stmt = $pdo->prepare('SELECT * FROM ledger_records WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();
                if (!$row) respond(['ok' => false, 'error' => '존재하지 않는 레코드.'], 404);
                if (strtolower((string)$row['owner_email']) !== $owner) respond(['ok' => false, 'error' => '권한 없음.'], 403);

                $assignments = [];
                $params = [':id' => $id];
                if (array_key_exists('data', $body)) {
                    // 부분 머지 — 기존 data 복호화 후 새 data 와 합쳐 다시 암호화 저장.
                    $existingDecoded = !empty($row['data_json']) ? youngman_decrypt_json($row['data_json']) : [];
                    if (!is_array($existingDecoded)) $existingDecoded = [];
                    $merged = array_replace($existingDecoded, (array)$body['data']);
                    $assignments[] = 'data_json = :d';
                    $params[':d'] = youngman_encrypt_json($merged);
                }
                if (array_key_exists('sortNo', $body)) {
                    $assignments[] = 'sort_no = :sn';
                    $params[':sn'] = (int)$body['sortNo'];
                }
                if (!$assignments) respond(['ok' => false, 'error' => '수정할 필드가 없습니다.'], 400);
                $upd = $pdo->prepare('UPDATE ledger_records SET ' . implode(', ', $assignments) . ' WHERE id = :id AND owner_email = :o');
                $params[':o'] = $owner;
                $upd->execute($params);
                respond(['ok' => true]);
            }

            if ($method === 'DELETE') {
                $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
                if (!$id) respond(['ok' => false, 'error' => 'id 가 필요합니다.'], 400);
                $del = $pdo->prepare('DELETE FROM ledger_records WHERE id = :id AND owner_email = :o');
                $del->execute([':id' => $id, ':o' => $owner]);
                if ($del->rowCount() === 0) respond(['ok' => false, 'error' => '삭제할 권한이 없거나 존재하지 않음.'], 404);
                respond(['ok' => true]);
            }

            respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
        }

        if ($resource === 'mobile-tokens') {
            if (!ensure_mobile_tokens_table($pdo)) {
                respond(['ok' => false, 'error' => '토큰 테이블 마이그레이션 실패'], 503);
            }

            if ($method === 'GET') {
                $stmt = $pdo->prepare('SELECT id, token_prefix, label, last_used_at, last_used_ip, revoked_at, created_at
                                       FROM mobile_api_tokens WHERE owner_email = :o ORDER BY id DESC');
                $stmt->execute([':o' => $owner]);
                respond(['ok' => true, 'items' => array_map(function ($r) {
                    return [
                        'id'         => (int)$r['id'],
                        'prefix'     => $r['token_prefix'],
                        'label'      => $r['label'],
                        'lastUsedAt' => $r['last_used_at'],
                        'lastUsedIp' => $r['last_used_ip'],
                        'revokedAt'  => $r['revoked_at'],
                        'createdAt'  => $r['created_at'],
                    ];
                }, $stmt->fetchAll())]);
            }

            if ($method === 'POST') {
                $label = trim((string)($body['label'] ?? ''));
                if ($label === '') $label = '이름 없음';
                if (mb_strlen($label) > 120) $label = mb_substr($label, 0, 120);

                // 32 hex (16 bytes) plaintext, 'yman_' 접두.
                $plain = 'yman_' . bin2hex(random_bytes(16));
                $hash  = hash('sha256', $plain);
                $prefix = substr($plain, 0, 12);   // 'yman_a4f9b3c2'

                $ins = $pdo->prepare('INSERT INTO mobile_api_tokens (owner_email, token_hash, token_prefix, label)
                                      VALUES (:o, :h, :p, :l)');
                $ins->execute([':o' => $owner, ':h' => $hash, ':p' => $prefix, ':l' => $label]);
                $id = (int)$pdo->lastInsertId();

                respond(['ok' => true, 'id' => $id, 'token' => $plain, 'prefix' => $prefix, 'label' => $label]);
            }

            if ($method === 'DELETE') {
                $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
                if (!$id) respond(['ok' => false, 'error' => 'id 가 필요합니다.'], 400);
                $upd = $pdo->prepare('UPDATE mobile_api_tokens SET revoked_at = NOW()
                                      WHERE id = :id AND owner_email = :o AND revoked_at IS NULL');
                $upd->execute([':id' => $id, ':o' => $owner]);
                if ($upd->rowCount() === 0) respond(['ok' => false, 'error' => '존재하지 않거나 이미 폐기된 토큰입니다.'], 404);
                respond(['ok' => true]);
            }

            respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
        }

        if ($resource === 'ledger-records-bulk') {
            // 선택삭제: { ids: [1,2,3, ...] }
            if ($method !== 'POST' && $method !== 'DELETE') {
                respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
            }
            $ids = is_array($body['ids'] ?? null) ? array_values(array_filter(array_map('intval', $body['ids']), fn($v) => $v > 0)) : [];
            if (!$ids) respond(['ok' => false, 'error' => 'ids 배열이 필요합니다.'], 400);

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $del = $pdo->prepare('DELETE FROM ledger_records WHERE id IN (' . $placeholders . ') AND owner_email = ?');
            $del->execute(array_merge($ids, [$owner]));
            respond(['ok' => true, 'deleted' => $del->rowCount()]);
        }
    }

    /* ============================================================
       customer_log — 통화 녹취 AI 요약 (앱 자동 입력 + 웹 수동 편집)
       Spec: CALL_RECORDING_BACKEND.md §6.

       글로벌 verify_auth_token 우회 ($selfAuthResources 에 등록) + 여기서
       /auth/v1/user 단순 호출로 자체 인증 (upload.php / process-recording.php
       와 동일한 검증된 패턴). 응답 shape 도 spec §4 표준
       ({status:'ok',...} / {status:'error', code, message}) 으로 통일.
       ============================================================ */
    if ($resource === 'customer-log') {
        // ── 자체 인증 ──
        // 사장님 2026-05-20 — X-Worker-Token (RECORDING_WORKER_TOKEN) 인증 우회 분기 추가.
        // recording-callback.php / process-recording.php 가 internal HTTP 로 send_to_group 호출 시 사용.
        // X-Worker-Token + body.owner_email 일치하면 Supabase 검증 skip.
        $workerTok = trim((string)($_SERVER['HTTP_X_WORKER_TOKEN'] ?? ''));
        $expectedWorkerTok = (function() {
            foreach ([__DIR__, dirname(__DIR__)] as $dir) {
                $envP = $dir . '/.env';
                if (!is_file($envP)) continue;
                foreach (file($envP, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (preg_match('/^\s*(?:export\s+)?RECORDING_WORKER_TOKEN\s*=\s*(.*)$/i', $line, $m)) {
                        return trim($m[1], "\"' \t");
                    }
                }
            }
            return '';
        })();
        $clEmail = '';
        $isInternalWorker = false;
        if ($workerTok !== '' && $expectedWorkerTok !== '' && hash_equals($expectedWorkerTok, $workerTok)) {
            $emailIn = strtolower(trim((string)($body['owner_email'] ?? $_GET['owner_email'] ?? '')));
            if ($emailIn !== '' && filter_var($emailIn, FILTER_VALIDATE_EMAIL)) {
                $clEmail = $emailIn;
                $isInternalWorker = true;
            } else {
                respond(['status' => 'error', 'code' => 'unauthorized', 'message' => 'worker token 유효하지만 body.owner_email 누락.'], 401);
            }
        } else {
            // 기존 Supabase /auth/v1/user 검증 흐름.
            $clHdr = read_authorization_header();
            if (!preg_match('/^Bearer\s+(.+)$/i', $clHdr, $clM)) {
                respond(['status' => 'error', 'code' => 'unauthorized', 'message' => '로그인이 필요합니다.'], 401);
            }
            $clToken = trim($clM[1]);
            $clBase  = !empty($auth['supabase_url']) ? rtrim((string)$auth['supabase_url'], '/') : '';
            $clKey   = (string)($auth['anon_key'] ?? '');
            if ($clBase === '' || $clKey === '') {
                respond(['status' => 'error', 'code' => 'unauthorized', 'message' => '서버 인증 설정 누락 (supabase_url / anon_key).'], 500);
            }
            $clCh = curl_init($clBase . '/auth/v1/user');
            curl_setopt_array($clCh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $clToken, 'apikey: ' . $clKey],
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $clResp = curl_exec($clCh);
            $clStatus = (int)curl_getinfo($clCh, CURLINFO_HTTP_CODE);
            curl_close($clCh);
            if ($clStatus !== 200 || !$clResp) {
                respond(['status' => 'error', 'code' => 'unauthorized',
                    'message' => '토큰 검증 실패 (Supabase ' . $clStatus . '). 다시 로그인해주세요.'], 401);
            }
            $clData = json_decode((string)$clResp, true);
            $clEmail = strtolower(trim((string)($clData['email'] ?? '')));
            if ($clEmail === '') {
                respond(['status' => 'error', 'code' => 'unauthorized', 'message' => '토큰에서 이메일 추출 실패.'], 401);
            }
        }
        $authUser = ['email' => $clEmail, 'sub' => null];

        // ── 테이블/컬럼 보장 ──
        if (!ensure_customer_log_table($pdo)) {
            respond(['status' => 'error', 'code' => 'upstream_failed',
                'message' => 'customer_log 마이그레이션 실패 — 잠시 후 다시 시도'], 503);
        }
        ensure_members_plan_columns($pdo);
        $owner = $clEmail;

        $action = strtolower(trim((string)($body['action'] ?? $_GET['action'] ?? '')));

        // ─── LIST (페이지네이션) ───
        if ($action === 'customer_log_list') {
            $limit = (int)($body['limit'] ?? $_GET['limit'] ?? 50);
            if ($limit < 1) $limit = 50;
            if ($limit > 200) $limit = 200;
            $before = trim((string)($body['before'] ?? $_GET['before'] ?? ''));

            $sql = 'SELECT * FROM customer_log WHERE owner_email = :o';
            $params = [':o' => $owner];
            if ($before !== '') {
                $ts = @strtotime($before);
                if ($ts) {
                    $sql .= ' AND consult_at < :b';
                    $params[':b'] = date('Y-m-d H:i:s', $ts);
                }
            }
            $sql .= ' ORDER BY consult_at DESC, id DESC LIMIT ' . ($limit + 1);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $hasMore = count($rows) > $limit;
            if ($hasMore) array_pop($rows);
            $nextBefore = ($hasMore && !empty($rows)) ? (string)end($rows)['consult_at'] : null;
            reset($rows);

            respond([
                'status' => 'ok',
                'items' => array_map('customer_log_row', $rows),
                'next_before' => $nextBefore,
            ]);
        }

        // ─── GET (단일 row) ───
        if ($action === 'customer_log_get') {
            $id = trim((string)($body['id'] ?? $_GET['id'] ?? ''));
            if ($id === '') respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'id 가 필요합니다.'], 400);
            $stmt = $pdo->prepare('SELECT * FROM customer_log WHERE id = :id AND owner_email = :o LIMIT 1');
            $stmt->execute([':id' => $id, ':o' => $owner]);
            $row = $stmt->fetch();
            if (!$row) respond(['status' => 'error', 'code' => 'not_found', 'message' => '존재하지 않거나 권한이 없습니다.'], 404);
            respond(['status' => 'ok', 'customer_log' => customer_log_row($row)]);
        }

        // ─── UPDATE (부분 patch) ───
        if ($action === 'customer_log_update') {
            $id = trim((string)($body['id'] ?? ''));
            if ($id === '') respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'id 가 필요합니다.'], 400);
            $patch = isset($body['patch']) && is_array($body['patch']) ? $body['patch'] : [];
            if (!$patch) respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'patch 가 비어있습니다.'], 400);

            $owns = $pdo->prepare('SELECT 1 FROM customer_log WHERE id = :id AND owner_email = :o LIMIT 1');
            $owns->execute([':id' => $id, ':o' => $owner]);
            if (!$owns->fetchColumn()) respond(['status' => 'error', 'code' => 'not_found', 'message' => '존재하지 않거나 권한이 없습니다.'], 404);

            $piiFields = ['customer_name', 'phone_number', 'summary', 'interest', 'inquiry',
                          'budget_condition', 'next_action', 'agent_memo', 'transcript'];
            $plainFields = ['consult_at'];

            $assignments = [];
            $params = [':id' => $id, ':o' => $owner];
            foreach ($patch as $key => $value) {
                if (in_array($key, $piiFields, true)) {
                    $assignments[] = '`' . $key . '` = :' . $key;
                    $params[':' . $key] = ($value === null || $value === '') ? null : youngman_encrypt((string)$value);
                    if ($key === 'phone_number') {
                        $assignments[] = 'customer_phone_lookup = :phlookup';
                        $params[':phlookup'] = customer_phone_lookup_key(($value === null || $value === '') ? null : (string)$value);
                    }
                } elseif (in_array($key, $plainFields, true)) {
                    if ($key === 'consult_at') {
                        $ts = @strtotime((string)$value);
                        if (!$ts) continue;
                        $assignments[] = 'consult_at = :consult_at';
                        $params[':consult_at'] = date('Y-m-d H:i:s', $ts);
                    }
                }
            }
            if (!$assignments) respond(['status' => 'error', 'code' => 'invalid_request', 'message' => '수정 가능한 필드가 없습니다.'], 400);

            $upd = $pdo->prepare('UPDATE customer_log SET ' . implode(', ', $assignments)
                                 . ' WHERE id = :id AND owner_email = :o');
            $upd->execute($params);

            $sel = $pdo->prepare('SELECT * FROM customer_log WHERE id = :id AND owner_email = :o LIMIT 1');
            $sel->execute([':id' => $id, ':o' => $owner]);
            $row = $sel->fetch();
            respond(['status' => 'ok', 'customer_log' => $row ? customer_log_row($row) : null]);
        }

        // ─── DELETE ───
        if ($action === 'customer_log_delete') {
            $id = trim((string)($body['id'] ?? ''));
            if ($id === '') respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'id 가 필요합니다.'], 400);
            $del = $pdo->prepare('DELETE FROM customer_log WHERE id = :id AND owner_email = :o');
            $del->execute([':id' => $id, ':o' => $owner]);
            if ($del->rowCount() === 0) respond(['status' => 'error', 'code' => 'not_found', 'message' => '존재하지 않거나 권한이 없습니다.'], 404);
            respond(['status' => 'ok']);
        }

        // POST /records.php?resource=customer-log&action=customer_log_cancel
        // body: { id: customer_log_id }
        // 사장님 2026-05-22 — 통화 종료 모달 "취소" / 요약보기 "닫기" 폐기 정책.
        // 잔해 데이터 누적 방지 — recording_jobs / customer_log / ledger_records mirror /
        // audio 파일 모두 cascade 삭제. callback 이 나중에 도착해도 row 없으면 0 rows
        // affected 라 안전 (callback 은 UPDATE only).
        if ($action === 'customer_log_cancel') {
            $id = trim((string)($body['id'] ?? $body['customer_log_id'] ?? ''));
            if ($id === '') respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'customer_log_id 필요.'], 400);

            // recording_jobs 의 audio path 미리 조회 (DELETE 전, 파일 unlink 용)
            $audioPaths = [];
            try {
                $jobs = $pdo->prepare('SELECT storage_path FROM recording_jobs WHERE customer_log_id = :cl AND owner_email = :o');
                $jobs->execute([':cl' => $id, ':o' => $owner]);
                foreach ($jobs->fetchAll() as $jr) {
                    $p = (string)($jr['storage_path'] ?? '');
                    if ($p !== '') $audioPaths[] = $p;
                }
            } catch (Throwable $e) {}

            $deleted = ['customer_log' => 0, 'recording_jobs' => 0, 'ledger_records' => 0, 'audio_files' => 0];

            try {
                $pdo->beginTransaction();

                // ledger_records mirror 삭제 (send_to_group 으로 INSERT 됐을 row)
                try {
                    $delLR = $pdo->prepare('DELETE FROM ledger_records WHERE customer_log_id = :cl AND owner_email = :o');
                    $delLR->execute([':cl' => $id, ':o' => $owner]);
                    $deleted['ledger_records'] = $delLR->rowCount();
                } catch (Throwable $e) {}

                // recording_jobs 삭제 (callback 이 더 이상 UPDATE 할 row 없게)
                try {
                    $delRJ = $pdo->prepare('DELETE FROM recording_jobs WHERE customer_log_id = :cl AND owner_email = :o');
                    $delRJ->execute([':cl' => $id, ':o' => $owner]);
                    $deleted['recording_jobs'] = $delRJ->rowCount();
                } catch (Throwable $e) {}

                // customer_log 본체 삭제
                $delCL = $pdo->prepare('DELETE FROM customer_log WHERE id = :id AND owner_email = :o');
                $delCL->execute([':id' => $id, ':o' => $owner]);
                $deleted['customer_log'] = $delCL->rowCount();

                if ($deleted['customer_log'] === 0) {
                    $pdo->rollBack();
                    respond(['status' => 'error', 'code' => 'not_found', 'message' => '존재하지 않거나 권한이 없습니다.'], 404);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                try { $pdo->rollBack(); } catch (Throwable $e2) {}
                respond(['status' => 'error', 'code' => 'upstream_failed', 'message' => 'cancel 실패: ' . $e->getMessage()], 503);
            }

            // audio 파일 삭제 (트랜잭션 밖 — 실패해도 DB 는 이미 정리됨)
            foreach ($audioPaths as $ap) {
                $candidates = [
                    $ap,
                    __DIR__ . '/' . ltrim($ap, '/'),
                    dirname(__DIR__) . '/' . ltrim($ap, '/'),
                ];
                foreach ($candidates as $p) {
                    if (is_file($p)) {
                        @unlink($p);
                        $deleted['audio_files']++;
                        break;
                    }
                }
            }

            error_log('[customer_log_cancel] owner=' . $owner . ' cl=' . $id . ' deleted=' . json_encode($deleted));
            respond(['status' => 'ok', 'deleted' => $deleted]);
        }

        // ─── ADMIN_JOB_DIAG (사장님 2026-05-20 진단용 — admin only) ───
        // GET/POST: action=admin_job_diag, body/query.job_ids=콤마구분 또는 배열
        // 응답: 각 job_id 의 진단 컬럼 모두 (owner 무관 — admin 권한).
        if ($action === 'admin_job_diag') {
            if (!is_admin_email($owner)) {
                respond(['status' => 'error', 'code' => 'forbidden', 'message' => 'admin 전용 endpoint.'], 403);
            }
            ensure_recording_jobs_table($pdo);
            // job_ids 다양한 형식 fallback
            $raw = $body['job_ids'] ?? $_GET['job_ids'] ?? '';
            $ids = [];
            if (is_array($raw)) {
                foreach ($raw as $v) { $v = trim((string)$v); if ($v !== '') $ids[] = $v; }
            } else {
                foreach (explode(',', (string)$raw) as $v) { $v = trim($v); if ($v !== '') $ids[] = $v; }
            }
            if (!$ids) respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'job_ids 필요.'], 400);
            $ids = array_slice($ids, 0, 50);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            try {
                $sql = "SELECT id, owner_email, status, review_required, customer_log_id, storage_path,
                               client_request_id, audio_sha256, duration_sec, customer_name_hint, phone_number,
                               recorded_at, retry_count, error_message, fcm_sent_at, started_at, completed_at,
                               progress_pct, group_id, created_at, updated_at,
                               (status = 'ready_to_review') AS is_ready_to_review,
                               (fcm_sent_at IS NOT NULL) AS fcm_sent
                        FROM recording_jobs WHERE id IN (" . $placeholders . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($ids);
                $rows = $stmt->fetchAll();
            } catch (Throwable $e) {
                respond(['status' => 'error', 'code' => 'upstream_failed', 'message' => 'SELECT 실패: ' . $e->getMessage()], 503);
            }
            $found = [];
            foreach ($rows as $r) { $found[(string)$r['id']] = $r; }
            $result = [];
            foreach ($ids as $reqId) {
                $result[$reqId] = isset($found[$reqId]) ? $found[$reqId] : null;
            }
            respond(['status' => 'ok', 'items' => $result, 'requested_count' => count($ids), 'found_count' => count($rows)]);
        }

        // ─── TRANSCRIPTS_BY_PHONE (사장님 2026-05-20 — 회차별 대화내용 전문보기) ───
        // GET /records.php?resource=customer-log&action=transcripts_by_phone&phone=01012345678
        // 같은 phone 의 모든 customer_log row 의 transcript 복호화 반환 (회차별 매칭용).
        if ($action === 'transcripts_by_phone') {
            ensure_customer_log_table($pdo);
            $phoneIn = trim((string)($body['phone'] ?? $_GET['phone'] ?? ''));
            if ($phoneIn === '') respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'phone 필요.'], 400);
            $phoneLookup = customer_phone_lookup_key($phoneIn);
            if (!$phoneLookup) respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'phone 형식 오류.'], 400);
            // 사장님 2026-05-24 — 옛 recording-callback (rc_phone_lookup_auto) 호환.
            // 2026-05-23 이전 "양식으로 전송" 흐름의 customer_log 는 customer_phone_lookup 이
            // 마지막 8자리 숫자로 저장됨. HMAC 매칭 + 8자리 매칭 둘 다 가져와 합침.
            $digitsOnly = preg_replace('/\D/', '', $phoneIn);
            $legacyLookup = $digitsOnly !== '' ? substr($digitsOnly, -8) : '';
            try {
                $stmt = $pdo->prepare('SELECT id, consult_at, transcript, ai_model
                    FROM customer_log
                    WHERE owner_email = :o
                      AND (customer_phone_lookup = :pl OR customer_phone_lookup = :legacy)
                    ORDER BY consult_at ASC, id ASC LIMIT 200');
                $stmt->execute([':o' => $owner, ':pl' => $phoneLookup, ':legacy' => $legacyLookup]);
                $rows = $stmt->fetchAll();
            } catch (Throwable $e) {
                respond(['status' => 'error', 'code' => 'upstream_failed', 'message' => '조회 실패.'], 503);
            }
            $items = array_map(function($r) {
                $tr = '';
                if (!empty($r['transcript'])) {
                    $dec = youngman_decrypt($r['transcript']);
                    if (is_string($dec)) $tr = $dec;
                }
                return [
                    'id' => (string)$r['id'],
                    'consult_at' => (string)($r['consult_at'] ?? ''),
                    'transcript' => $tr,
                    'ai_model' => (string)($r['ai_model'] ?? ''),
                ];
            }, $rows ?: []);
            respond(['status' => 'ok', 'items' => $items, 'count' => count($items)]);
        }

        // ─── GET_TRANSCRIPT_BY_ID (사장님 2026-05-24 — 회차 ↔ transcript 자물쇠 결합) ───
        // GET /records.php?resource=customer-log&action=get_transcript_by_id&id=<customer_log_id>
        // customer_log.id 로 직접 조회 → timestamp 매칭 무관, 다른 회차 transcript 와 혼선 차단.
        if ($action === 'get_transcript_by_id') {
            ensure_customer_log_table($pdo);
            $clId = trim((string)($body['id'] ?? $_GET['id'] ?? ''));
            if ($clId === '') respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'id 필요.'], 400);
            try {
                $stmt = $pdo->prepare('SELECT id, consult_at, transcript, ai_model
                    FROM customer_log
                    WHERE owner_email = :o AND id = :id LIMIT 1');
                $stmt->execute([':o' => $owner, ':id' => $clId]);
                $row = $stmt->fetch();
            } catch (Throwable $e) {
                respond(['status' => 'error', 'code' => 'upstream_failed', 'message' => '조회 실패.'], 503);
            }
            if (!$row) respond(['status' => 'error', 'code' => 'not_found', 'message' => 'customer_log 없음.'], 404);
            $tr = '';
            if (!empty($row['transcript'])) {
                $dec = youngman_decrypt($row['transcript']);
                if (is_string($dec)) $tr = $dec;
            }
            respond([
                'status' => 'ok',
                'item' => [
                    'id'         => (string)$row['id'],
                    'consult_at' => (string)($row['consult_at'] ?? ''),
                    'transcript' => $tr,
                    'ai_model'   => (string)($row['ai_model'] ?? ''),
                ],
            ]);
        }

        // ─── LIST_UNREVIEWED (사장님 2026-05-23 — "미확인 요약" 페이지 부활) ───
        // GET /records.php?resource=customer-log&action=list_unreviewed
        // customer_log 로 confirm 안 됐고 폐기도 안 된 모든 recording_jobs.
        // status: audio_pending(STT 미실행) / queued|processing(STT 진행 중) /
        //         ready_to_review(STT 완료, 검토 대기) / failed_retryable|failed_permanent(실패)
        if ($action === 'list_unreviewed') {
            ensure_recording_jobs_table($pdo);
            $limit = (int)($body['limit'] ?? $_GET['limit'] ?? 50);
            if ($limit < 1) $limit = 50;
            if ($limit > 200) $limit = 200;
            try {
                $sql = "SELECT id, status, summary_json_encrypted, duration_sec, recorded_at, group_id, phone_number, customer_name_hint, auto_confirm, created_at
                    FROM recording_jobs
                    WHERE owner_email = :o
                      AND customer_log_id IS NULL
                      AND status IN ('audio_pending','queued','processing','ready_to_review','failed_retryable','failed_permanent')
                    ORDER BY COALESCE(recorded_at, created_at) DESC
                    LIMIT " . $limit;
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':o' => $owner]);
                $rows = $stmt->fetchAll();
            } catch (Throwable $e) {
                error_log('[records list_unreviewed] SELECT failed: ' . $e->getMessage());
                respond(['status' => 'ok', 'items' => [], 'count' => 0, '_warn' => 'schema_mismatch']);
            }
            $items = array_map(function($r) {
                // 이름: summary_json (STT 후) → customer_name_hint (lazy 단계) → '고객'
                $name = '고객';
                $sumPreview = '';
                if (!empty($r['summary_json_encrypted'])) {
                    $dec = youngman_decrypt($r['summary_json_encrypted']);
                    $arr = is_string($dec) ? json_decode($dec, true) : null;
                    if (is_array($arr)) {
                        if (!empty($arr['customer_name'])) $name = trim((string)$arr['customer_name']);
                        $sumPreview = (string)($arr['summary'] ?? '');
                        if (mb_strlen($sumPreview) > 180) $sumPreview = mb_substr($sumPreview, 0, 177) . '...';
                    }
                }
                if ($name === '고객' && !empty($r['customer_name_hint'])) {
                    $name = trim((string)$r['customer_name_hint']);
                }
                return [
                    'id' => (string)$r['id'],
                    'job_status' => (string)$r['status'],
                    'status' => (string)$r['status'],   // backward compat
                    'customer_name' => $name,
                    'summary_preview' => $sumPreview,
                    'duration_sec' => (int)($r['duration_sec'] ?? 0),
                    'recorded_at' => $r['recorded_at'] ?: $r['created_at'],
                    'phone_number' => $r['phone_number'] ?: null,
                    'group_id' => $r['group_id'] ?: null,
                    'auto_confirm' => (int)($r['auto_confirm'] ?? 0) === 1,
                ];
            }, $rows ?: []);
            respond(['status' => 'ok', 'items' => $items, 'count' => count($items)]);
        }

        // ─── TRIGGER_SUMMARIZE (사장님 2026-05-23 — lazy-STT 모드 STT 시작) ───
        // POST /records.php?resource=customer-log&action=trigger_summarize
        // body: { job_id }
        // 동작: status='audio_pending' → 'queued' UPDATE → Railway dispatch.
        //       이미 진행 중/완료면 idempotent 응답.
        if ($action === 'trigger_summarize') {
            $jobId = trim((string)($body['job_id'] ?? ''));
            if ($jobId === '') respond(['ok'=>false,'status'=>'error','processing'=>false,'code'=>'invalid_request','message'=>'job_id 필요.'], 400);
            try {
                $jStmt = $pdo->prepare('SELECT id, owner_email, status, storage_path, duration_sec, customer_name_hint, phone_number, recorded_at, group_id, customer_log_id, client_request_id
                    FROM recording_jobs WHERE id = :id AND owner_email = :o LIMIT 1');
                $jStmt->execute([':id' => $jobId, ':o' => $owner]);
                $jRow = $jStmt->fetch();
            } catch (Throwable $e) {
                respond(['ok'=>false,'status'=>'error','processing'=>false,'code'=>'upstream_failed','message'=>'조회 실패.'], 503);
            }
            if (!$jRow) respond(['ok'=>false,'status'=>'error','processing'=>false,'code'=>'not_found','message'=>'해당 job 없음 또는 권한 없음.'], 404);

            $curStatus = (string)$jRow['status'];
            // 사장님 2026-05-23 — 앱팀 v46 요청: 모든 응답에 ok + processing 필드 명시.
            // 이미 진행 중/완료 — idempotent 응답
            if (in_array($curStatus, ['queued', 'processing', 'ready_to_review'], true)) {
                respond([
                    'ok' => true,
                    'status' => 'ok',
                    'processing' => $curStatus !== 'ready_to_review',
                    'job_id' => $jobId,
                    'job_status' => $curStatus,
                    'already' => true,
                    'message' => ($curStatus === 'ready_to_review' ? 'STT 완료. 미확인 요약에서 확인 가능.' : 'STT 진행 중.'),
                ]);
            }
            // failed_retryable 은 재시도 허용. failed_permanent 는 차단.
            if ($curStatus === 'failed_permanent') {
                respond([
                    'ok' => false, 'status' => 'error', 'processing' => false,
                    'code' => 'failed_permanent', 'error_code' => 'STT_FAILED_PERMANENT',
                    'job_id' => $jobId, 'job_status' => $curStatus,
                    'message' => '영구 실패한 작업입니다. 폐기 후 새로 통화하세요.',
                ], 409);
            }
            if (!in_array($curStatus, ['audio_pending', 'failed_retryable'], true)) {
                respond([
                    'ok' => false, 'status' => 'error', 'processing' => false,
                    'code' => 'invalid_state', 'error_code' => 'STT_INVALID_STATE',
                    'job_id' => $jobId, 'job_status' => $curStatus,
                    'message' => 'audio_pending/failed_retryable 만 trigger_summarize 가능 (현재: ' . $curStatus . ').',
                ], 409);
            }

            // 사장님 2026-05-23 — "양식으로 전송" 누른 케이스. auto_confirm=1 → callback 자동 confirm.
            $autoConfirm = !empty($body['auto_confirm']) ? 1 : 0;

            // 1) status='queued' UPDATE + auto_confirm 설정
            try {
                $pdo->prepare("UPDATE recording_jobs SET status = 'queued', updated_at = NOW(), retry_count = 0, error_message = NULL, auto_confirm = :ac WHERE id = :id")
                    ->execute([':ac' => $autoConfirm, ':id' => $jobId]);
            } catch (Throwable $e) {
                respond(['ok'=>false,'status'=>'error','processing'=>false,'code'=>'upstream_failed','message'=>'UPDATE 실패: ' . $e->getMessage()], 503);
            }

            // 사장님 2026-06-01 — 사용량 차감 (요약보기/양식전송 첫 클릭 시점, 멱등)
            // Why: lazy-STT 흐름에서 통화 발생 시점이 아닌 "사용자 클릭 시점" 에 차감해야 한다는 룰
            // How: usage_counted_at IS NULL 일 때만 NOW() 채우고 members.usage_seconds_period 누적. retry 재호출 시 중복 차감 X.
            if (strtolower(trim((string)$owner)) !== 'nxnxax@gmail.com') {
                try {
                    $mk = $pdo->prepare("UPDATE recording_jobs SET usage_counted_at = NOW() WHERE id = :id AND usage_counted_at IS NULL");
                    $mk->execute([':id' => $jobId]);
                    if ($mk->rowCount() > 0) {
                        $dur = (int)($jRow['duration_sec'] ?? 0);
                        if ($dur > 0) {
                            $pdo->prepare('UPDATE members SET usage_seconds_period = COALESCE(usage_seconds_period,0) + :d WHERE LOWER(email) = LOWER(:e)')
                                ->execute([':d' => $dur, ':e' => $owner]);
                        }
                    }
                } catch (Throwable $e) {
                    error_log('[trigger_summarize] usage 누적 실패: ' . $e->getMessage());
                }
            }

            // 2) Railway dispatch (RAILWAY_WORKER_URL 있을 때만; 없으면 cron worker 가 5분 후 처리)
            // 사장님 2026-05-23 — .env parsing 정규식 + quote strip + 변수 init (이전 코드 $rwTok 미정의 버그 fix).
            $railwayUrl = '';
            $rwTok = '';
            $envDiag = ['env_file_found' => false, 'has_url' => false, 'has_token' => false];
            foreach ([__DIR__ . '/.env', dirname(__DIR__) . '/.env'] as $envPath) {
                if (!is_file($envPath)) continue;
                $envDiag['env_file_found'] = true;
                $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $ln) {
                    if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $ln, $m)) {
                        $k = $m[1];
                        $v = trim($m[2], "\"' \t\r\n");
                        if (strcasecmp($k, 'RAILWAY_WORKER_URL') === 0)        { $railwayUrl = $v; $envDiag['has_url'] = true; }
                        if (strcasecmp($k, 'RECORDING_WORKER_TOKEN') === 0)    { $rwTok = $v;       $envDiag['has_token'] = true; }
                    }
                }
                if ($railwayUrl !== '' && $rwTok !== '') break;
            }
            $dispatched = false;
            $dispatchError = null;
            if ($railwayUrl !== '' && $rwTok !== '') {
                try {
                    $expires = time() + 600;
                    $audioToken = hash_hmac('sha256', $jobId . '.' . $expires, $rwTok);
                    $audioUrl = 'https://youngman-biz.com/recording-audio.php?job_id=' . urlencode($jobId)
                              . '&token=' . urlencode($audioToken) . '&expires=' . $expires;
                    $payload = json_encode([
                        'job_id'             => $jobId,
                        'owner_email'        => $owner,
                        'audio_url'          => $audioUrl,
                        'duration_sec'       => (int)($jRow['duration_sec'] ?? 0),
                        'customer_name_hint' => (string)($jRow['customer_name_hint'] ?? ''),
                        'phone_number'       => (string)($jRow['phone_number'] ?? ''),
                        'recorded_at'        => (string)($jRow['recorded_at'] ?? ''),
                        'group_id'           => (string)($jRow['group_id'] ?? ''),
                        'storage_path'       => (string)($jRow['storage_path'] ?? ''),
                    ], JSON_UNESCAPED_UNICODE);
                    $ch = curl_init(rtrim($railwayUrl, '/') . '/process');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $payload,
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            'X-Worker-Token: ' . $rwTok,
                        ],
                        CURLOPT_TIMEOUT => 8,
                        CURLOPT_CONNECTTIMEOUT => 4,
                    ]);
                    $rwResp = curl_exec($ch);
                    $rwStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($rwStatus >= 200 && $rwStatus < 300) {
                        $dispatched = true;
                        $pdo->prepare("UPDATE recording_jobs SET status = 'processing', started_at = NOW(), updated_at = NOW() WHERE id = :id")
                            ->execute([':id' => $jobId]);
                    } else {
                        $dispatchError = 'http_' . $rwStatus;
                        $msg = 'Railway dispatch http=' . $rwStatus . ' resp=' . substr((string)$rwResp, 0, 200);
                        error_log('[trigger_summarize] ' . $msg);
                        try { $pdo->prepare("UPDATE recording_jobs SET error_message = :em WHERE id = :id")
                            ->execute([':em' => $msg, ':id' => $jobId]); } catch (Throwable $e) {}
                    }
                } catch (Throwable $e) {
                    $dispatchError = 'exception';
                    $msg = 'Railway dispatch 예외: ' . $e->getMessage();
                    error_log('[trigger_summarize] ' . $msg);
                    try { $pdo->prepare("UPDATE recording_jobs SET error_message = :em WHERE id = :id")
                        ->execute([':em' => $msg, ':id' => $jobId]); } catch (Throwable $e2) {}
                }
            } else {
                $dispatchError = !$envDiag['env_file_found'] ? 'env_file_missing'
                              : (!$envDiag['has_url'] ? 'RAILWAY_WORKER_URL_missing'
                              : (!$envDiag['has_token'] ? 'RECORDING_WORKER_TOKEN_missing' : 'unknown'));
                error_log('[trigger_summarize] dispatch skipped: ' . $dispatchError);
            }
            // 사장님 2026-05-24 — "양식으로 전송" placeholder 부활.
            // auto_confirm=1 이면 즉시 placeholder customer_log INSERT + ledger mirror →
            // native 가 모달 닫고 사용자가 고객관리대장 가면 "AI 요약중..." 카드 표시.
            // callback §7 분기 (이미 있음) 가 결과 도착 시 customer_log UPDATE + ledger refresh.
            // STT 실패 시 callback 의 fallback (customer_log DELETE + status='ready_to_review') 으로 미확인 요약 복원.
            $placeholderCustomerLogId = null;
            $placeholderPhone = trim((string)($jRow['phone_number'] ?? ''));
            if ($autoConfirm && empty($jRow['customer_log_id']) && $placeholderPhone !== '') {
                try {
                    $placeholderCustomerLogId = (function() {
                        $data = random_bytes(16);
                        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
                        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
                        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
                    })();
                    $phName = trim((string)($jRow['customer_name_hint'] ?? ''));
                    if ($phName === '') $phName = '(처리 중)';
                    $phConsultAt = (string)($jRow['recorded_at'] ?? date('Y-m-d H:i:s'));
                    $phCriRaw = (string)($jRow['client_request_id'] ?? $jobId);
                    $pdo->prepare("INSERT INTO customer_log (
                            id, owner_email, customer_phone_lookup,
                            customer_name, phone_number,
                            summary, interest, inquiry, budget_condition, next_action,
                            transcript, consult_at, audio_storage_path, audio_kept,
                            ai_model, ai_generated_at, source, client_request_id
                        ) VALUES (
                            :id, :o, :pl,
                            :nm, :ph,
                            :sum, NULL, NULL, NULL, NULL,
                            NULL, :ca, NULL, 0,
                            'pending', NOW(), 'app-processing', :cri
                        )")->execute([
                            ':id'  => $placeholderCustomerLogId,
                            ':o'   => $owner,
                            ':pl'  => customer_phone_lookup_key($placeholderPhone),
                            ':nm'  => youngman_encrypt($phName),
                            ':ph'  => youngman_encrypt($placeholderPhone),
                            ':sum' => youngman_encrypt('(AI 요약 처리 중...)'),
                            ':ca'  => $phConsultAt,
                            ':cri' => $phCriRaw,
                        ]);
                    $pdo->prepare("UPDATE recording_jobs SET customer_log_id = :cl, updated_at = NOW() WHERE id = :id")
                        ->execute([':cl' => $placeholderCustomerLogId, ':id' => $jobId]);

                    // ledger mirror — internal HTTP (worker token 우회 분기 사용).
                    // 실패해도 응답은 진행 (placeholder customer_log 는 살아있음; refresh callback 이 따로 갱신).
                    if ($rwTok !== '') {
                        try {
                            $mirrorUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'youngman-biz.com') . '/records.php?resource=customer-log';
                            $mirrorPayload = ['action' => 'customer_log_send_to_group', 'id' => $placeholderCustomerLogId, 'owner_email' => $owner];
                            $gidStr = trim((string)($jRow['group_id'] ?? ''));
                            if ($gidStr !== '') $mirrorPayload['group_id'] = (int)$gidStr;
                            $mCh = curl_init($mirrorUrl);
                            curl_setopt_array($mCh, [
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => json_encode($mirrorPayload, JSON_UNESCAPED_UNICODE),
                                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Worker-Token: ' . $rwTok],
                                CURLOPT_TIMEOUT => 8,
                                CURLOPT_CONNECTTIMEOUT => 4,
                            ]);
                            $mResp = curl_exec($mCh);
                            $mStat = (int)curl_getinfo($mCh, CURLINFO_HTTP_CODE);
                            curl_close($mCh);
                            error_log('[trigger_summarize placeholder] mirror http=' . $mStat . ' cl=' . $placeholderCustomerLogId);
                        } catch (Throwable $e) {
                            error_log('[trigger_summarize placeholder] mirror 예외: ' . $e->getMessage());
                        }
                    }
                } catch (Throwable $e) {
                    error_log('[trigger_summarize placeholder] INSERT 실패: ' . $e->getMessage());
                    $placeholderCustomerLogId = null;
                }
            }

            // 사장님 2026-05-23 — 앱팀 v46 요청: ok + processing 필드 명시.
            // dispatched=false (cron 대기) 여도 결국 STT 처리되므로 processing=true.
            respond([
                'ok' => true,
                'status' => 'ok',
                'processing' => true,
                'job_id' => $jobId,
                'job_status' => $dispatched ? 'processing' : 'queued',
                'dispatched' => $dispatched,
                'dispatch_error' => $dispatchError,
                'customer_log_id' => $placeholderCustomerLogId,
                'auto_confirm' => $autoConfirm === 1,
                'message' => $dispatched ? 'STT 시작됨.' : 'STT 대기열 등록됨 — cron 5분 내 자동 재시도.',
                '_diag' => $envDiag,
            ]);
        }

        // ─── DISCARD (사장님 2026-05-23 — 미확인 요약 폐기 / 모달 "취소") ───
        // POST /records.php?resource=customer-log&action=discard
        // body: { job_id }
        // 동작: customer_log 없이 audio_pending/ready_to_review job 폐기.
        //       (customer_log 가 이미 있는 경우는 customer_log_cancel 사용.)
        if ($action === 'discard') {
            $jobId = trim((string)($body['job_id'] ?? ''));
            if ($jobId === '') respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'job_id 필요.'], 400);
            try {
                $jStmt = $pdo->prepare('SELECT id, storage_path, customer_log_id FROM recording_jobs WHERE id = :id AND owner_email = :o LIMIT 1');
                $jStmt->execute([':id' => $jobId, ':o' => $owner]);
                $jRow = $jStmt->fetch();
            } catch (Throwable $e) {
                respond(['status' => 'error', 'code' => 'upstream_failed', 'message' => '조회 실패.'], 503);
            }
            if (!$jRow) respond(['status' => 'error', 'code' => 'not_found', 'message' => '해당 job 없음 또는 권한 없음.'], 404);

            // customer_log_id 있으면 customer_log_cancel 로 안내 (cascade 처리 다름)
            if (!empty($jRow['customer_log_id'])) {
                respond(['status' => 'error', 'code' => 'has_customer_log',
                    'message' => '이미 고객관리대장에 저장됨. customer_log_cancel 사용.',
                    'customer_log_id' => $jRow['customer_log_id']], 409);
            }

            $deleted = ['recording_jobs' => 0, 'audio_files' => 0];
            try {
                $del = $pdo->prepare('DELETE FROM recording_jobs WHERE id = :id AND owner_email = :o');
                $del->execute([':id' => $jobId, ':o' => $owner]);
                $deleted['recording_jobs'] = $del->rowCount();
            } catch (Throwable $e) {
                respond(['status' => 'error', 'code' => 'upstream_failed', 'message' => 'DELETE 실패: ' . $e->getMessage()], 503);
            }

            // audio 파일 삭제
            $ap = (string)($jRow['storage_path'] ?? '');
            if ($ap !== '') {
                foreach ([$ap, __DIR__ . '/' . ltrim($ap, '/'), dirname(__DIR__) . '/' . ltrim($ap, '/')] as $p) {
                    if (is_file($p)) { @unlink($p); $deleted['audio_files']++; break; }
                }
            }

            error_log('[discard] owner=' . $owner . ' job=' . $jobId . ' deleted=' . json_encode($deleted));
            respond(['status' => 'ok', 'job_id' => $jobId, 'deleted' => $deleted]);
        }

        // ─── PREVIEW (앱팀 2026-05-20 — ready_to_review job 의 summary_json 복호화 반환) ───
        // GET /records.php?resource=customer-log&action=preview&job_id=xxx
        // 사용자 검토 화면에서 호출. recording_jobs.review_required=1 인 job 만.
        if ($action === 'preview') {
            $jobId = trim((string)($body['job_id'] ?? $_GET['job_id'] ?? ''));
            if ($jobId === '') respond(['ok'=>false,'status'=>'error','processing'=>false,'code'=>'invalid_request','message'=>'job_id 필요.'], 400);
            try {
                $jStmt = $pdo->prepare('SELECT id, status, summary_json_encrypted, customer_log_id, duration_sec, recorded_at, group_id, phone_number, review_required
                    FROM recording_jobs WHERE id = :id AND owner_email = :o LIMIT 1');
                $jStmt->execute([':id' => $jobId, ':o' => $owner]);
                $jRow = $jStmt->fetch();
            } catch (Throwable $e) {
                respond(['ok'=>false,'status'=>'error','processing'=>false,'code'=>'upstream_failed','message'=>'조회 실패.'], 503);
            }
            if (!$jRow) respond(['ok'=>false,'status'=>'error','processing'=>false,'code'=>'not_found','message'=>'해당 job 없음 또는 권한 없음.'], 404);

            $summaryJson = null;
            if (!empty($jRow['summary_json_encrypted'])) {
                $dec = youngman_decrypt($jRow['summary_json_encrypted']);
                $arr = is_string($dec) ? json_decode($dec, true) : null;
                if (is_array($arr)) $summaryJson = $arr;
            }
            // 사장님 2026-05-23 — 앱팀 v49 요청: ok + processing 필드 명시.
            // processing=true → 앱이 polling 계속. processing=false → 결과 표시 (또는 폐기/완료).
            $curStatus = (string)$jRow['status'];
            $isProcessing = in_array($curStatus, ['audio_pending', 'queued', 'processing'], true);
            $isFailed = in_array($curStatus, ['failed_permanent'], true);
            respond([
                'ok' => !$isFailed,
                'status' => $isFailed ? 'error' : 'ok',
                'processing' => $isProcessing,
                'job_id' => (string)$jRow['id'],
                'job_status' => $curStatus,
                'review_required' => !empty($jRow['review_required']),
                'customer_log_id' => $jRow['customer_log_id'] ?: null,
                'duration_sec' => (int)($jRow['duration_sec'] ?? 0),
                'recorded_at' => $jRow['recorded_at'] ?: null,
                'group_id' => $jRow['group_id'] ?: null,
                'phone_number' => $jRow['phone_number'] ?: null,
                'summary' => $summaryJson,   // {customer_name, summary, interest, inquiry, budget_condition, next_action, transcript, ...}
            ]);
        }

        // ─── SUMMARY_STATUS (사장님 2026-05-23 — preview alias, ok/processing 만 필요한 경량 polling) ───
        // GET /records.php?resource=customer-log&action=summary_status&job_id=xxx
        // preview 의 summary 필드 없는 경량 버전. polling 부하 절감.
        if ($action === 'summary_status') {
            $jobId = trim((string)($body['job_id'] ?? $_GET['job_id'] ?? ''));
            if ($jobId === '') respond(['ok'=>false,'status'=>'error','processing'=>false,'code'=>'invalid_request','message'=>'job_id 필요.'], 400);
            try {
                $jStmt = $pdo->prepare('SELECT id, status, customer_log_id, progress_pct, error_message
                    FROM recording_jobs WHERE id = :id AND owner_email = :o LIMIT 1');
                $jStmt->execute([':id' => $jobId, ':o' => $owner]);
                $jRow = $jStmt->fetch();
            } catch (Throwable $e) {
                respond(['ok'=>false,'status'=>'error','processing'=>false,'code'=>'upstream_failed','message'=>'조회 실패.'], 503);
            }
            if (!$jRow) respond(['ok'=>false,'status'=>'error','processing'=>false,'code'=>'not_found','message'=>'해당 job 없음 또는 권한 없음.'], 404);
            $curStatus = (string)$jRow['status'];
            $isProcessing = in_array($curStatus, ['audio_pending', 'queued', 'processing'], true);
            $isFailed = in_array($curStatus, ['failed_permanent'], true);
            respond([
                'ok' => !$isFailed,
                'status' => $isFailed ? 'error' : 'ok',
                'processing' => $isProcessing,
                'job_id' => (string)$jRow['id'],
                'job_status' => $curStatus,
                'progress_pct' => (int)($jRow['progress_pct'] ?? 0),
                'customer_log_id' => $jRow['customer_log_id'] ?: null,
                'error_message' => $isFailed ? (string)($jRow['error_message'] ?? '') : null,
            ]);
        }

        // ─── CONFIRM (앱팀 2026-05-20 — ready_to_review → saved + customer_log INSERT) ───
        // POST /records.php?resource=customer-log action=confirm
        // body: { job_id, overrides?: {customer_name, summary, interest, inquiry, budget_condition, next_action, transcript, consult_at, phone_number, group_id} }
        if ($action === 'confirm') {
            $jobId = trim((string)($body['job_id'] ?? ''));
            if ($jobId === '') respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'job_id 필요.'], 400);
            $overrides = is_array($body['overrides'] ?? null) ? $body['overrides'] : [];
            try {
                $jStmt = $pdo->prepare('SELECT id, status, summary_json_encrypted, customer_log_id, duration_sec, recorded_at, group_id, phone_number, client_request_id
                    FROM recording_jobs WHERE id = :id AND owner_email = :o LIMIT 1');
                $jStmt->execute([':id' => $jobId, ':o' => $owner]);
                $jRow = $jStmt->fetch();
            } catch (Throwable $e) {
                respond(['status' => 'error', 'code' => 'upstream_failed', 'message' => '조회 실패.'], 503);
            }
            if (!$jRow) respond(['status' => 'error', 'code' => 'not_found', 'message' => '해당 job 없음 또는 권한 없음.'], 404);

            // 이미 confirm 된 경우 — 기존 customer_log 반환 (idempotent).
            if (!empty($jRow['customer_log_id']) && in_array((string)$jRow['status'], ['saved', 'completed'], true)) {
                $cl = $pdo->prepare('SELECT * FROM customer_log WHERE id = :id AND owner_email = :o LIMIT 1');
                $cl->execute([':id' => $jRow['customer_log_id'], ':o' => $owner]);
                $clRow = $cl->fetch();
                respond([
                    'status' => 'ok',
                    'error_code' => 'JOB_EXISTS',
                    'duplicate' => true,
                    'customer_log' => $clRow ? customer_log_row($clRow) : null,
                    'job_id' => $jobId,
                ]);
            }
            if ((string)$jRow['status'] !== 'ready_to_review') {
                respond(['status' => 'error', 'code' => 'invalid_state',
                    'message' => 'ready_to_review 상태의 job 만 confirm 가능 (현재: ' . $jRow['status'] . ').'], 409);
            }

            // summary_json_encrypted 복호화
            $summaryArr = [];
            if (!empty($jRow['summary_json_encrypted'])) {
                $dec = youngman_decrypt($jRow['summary_json_encrypted']);
                $tmp = is_string($dec) ? json_decode($dec, true) : null;
                if (is_array($tmp)) $summaryArr = $tmp;
            }
            // overrides 가 있으면 그쪽 우선
            $pick = function(string $key, $fallback = '') use ($overrides, $summaryArr) {
                if (array_key_exists($key, $overrides) && is_string($overrides[$key])) return trim($overrides[$key]);
                if (array_key_exists($key, $summaryArr) && is_string($summaryArr[$key])) return trim($summaryArr[$key]);
                return $fallback;
            };
            $customerName    = $pick('customer_name', '고객');
            $phoneNumber     = $pick('phone_number', (string)($jRow['phone_number'] ?? ''));
            $summary         = $pick('summary', '');
            $interest        = $pick('interest', '');
            $inquiry         = $pick('inquiry', '');
            $budgetCondition = $pick('budget_condition', '');
            $nextAction      = $pick('next_action', '');
            $region          = $pick('region', '');
            $transcript      = $pick('transcript', '');
            $consultAtIn     = $pick('consult_at', (string)($jRow['recorded_at'] ?? ''));
            $groupId         = $pick('group_id', (string)($jRow['group_id'] ?? ''));
            $aiModel         = is_string($summaryArr['ai_model'] ?? null) ? trim($summaryArr['ai_model']) : 'unknown';

            $ts = $consultAtIn !== '' ? @strtotime($consultAtIn) : false;
            $consultAt = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
            if ($summary === '') $summary = $transcript ?: '(요약 없음)';

            $rowId = (function() {
                $data = random_bytes(16);
                $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
                $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
                return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
            })();
            $phoneLookup = customer_phone_lookup_key($phoneNumber !== '' ? $phoneNumber : null);
            try {
                $ins = $pdo->prepare("INSERT INTO customer_log (
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
                        :am, NOW(), 'app-review', :cri
                    )");
                $ins->execute([
                    ':id'  => $rowId,
                    ':o'   => $owner,
                    ':pl'  => $phoneLookup,
                    ':nm'  => $customerName !== '' ? youngman_encrypt($customerName) : null,
                    ':ph'  => $phoneNumber  !== '' ? youngman_encrypt($phoneNumber)  : null,
                    ':sum' => youngman_encrypt($summary),
                    ':intr'=> $interest !== '' ? youngman_encrypt($interest) : null,
                    ':inq' => $inquiry  !== '' ? youngman_encrypt($inquiry)  : null,
                    ':bg'  => $budgetCondition !== '' ? youngman_encrypt($budgetCondition) : null,
                    ':nx'  => $nextAction !== '' ? youngman_encrypt($nextAction) : null,
                    ':rg'  => $region !== '' ? youngman_encrypt($region) : null,
                    ':tr'  => $transcript !== '' ? youngman_encrypt($transcript) : null,
                    ':ca'  => $consultAt,
                    ':asp' => null,
                    ':am'  => $aiModel,
                    ':cri' => (string)($jRow['client_request_id'] ?? $jobId),
                ]);
            } catch (Throwable $e) {
                if (strpos((string)$e->getMessage(), 'Duplicate') !== false) {
                    respond(['status' => 'error', 'error_code' => 'JOB_DUPLICATE',
                        'code' => 'duplicate_request', 'message' => '중복 요청.'], 409);
                }
                respond(['status' => 'error', 'code' => 'upstream_failed', 'message' => 'customer_log INSERT 실패: ' . $e->getMessage()], 500);
            }

            // recording_jobs status='saved' + customer_log_id 저장
            try {
                $pdo->prepare("UPDATE recording_jobs SET
                        status = 'saved', customer_log_id = :cl, updated_at = NOW()
                    WHERE id = :id AND owner_email = :o")
                    ->execute([':cl' => $rowId, ':id' => $jobId, ':o' => $owner]);
            } catch (Throwable $e) {
                error_log('[records] recording_jobs saved UPDATE 실패: ' . $e->getMessage());
            }

            // 사장님 2026-05-23 — confirm 시 자동 send_to_group mirror (고객관리대장 자동 전송).
            // group_id 없어도 send_to_group 가 default 그룹 자동 생성.
            $mirrorResult = null;
            try {
                // 사장님 2026-05-23 — .env 따옴표 strip robust parsing (rc_load_env 와 동일).
                // 옛 코드는 trim($v) 만 해서 ".." 따옴표 그대로 → records.php worker token
                // 검증 hash_equals mismatch → 401 → send_to_group 100% 실패.
                $envWorkerTok = '';
                foreach ([__DIR__, dirname(__DIR__)] as $envDir) {
                    $envFile = $envDir . '/.env';
                    if (!is_file($envFile)) continue;
                    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
                        if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $ln, $m)) {
                            if (strcasecmp($m[1], 'RECORDING_WORKER_TOKEN') === 0) {
                                $envWorkerTok = trim($m[2], "\"' \t\r\n");
                                break 2;
                            }
                        }
                    }
                }
                if ($envWorkerTok !== '') {
                    $sendUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'youngman-biz.com') . '/records.php?resource=customer-log';
                    $sendPayload = ['action' => 'customer_log_send_to_group', 'id' => $rowId, 'owner_email' => $owner];
                    if ($groupId !== '') $sendPayload['group_id'] = (int)$groupId;
                    $sCh = curl_init($sendUrl);
                    curl_setopt_array($sCh, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode($sendPayload, JSON_UNESCAPED_UNICODE),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Worker-Token: ' . $envWorkerTok],
                        CURLOPT_TIMEOUT => 15,
                        CURLOPT_CONNECTTIMEOUT => 5,
                    ]);
                    $sResp = curl_exec($sCh);
                    $sStat = (int)curl_getinfo($sCh, CURLINFO_HTTP_CODE);
                    curl_close($sCh);
                    if ($sResp !== false && $sStat >= 200 && $sStat < 300) {
                        $mirrorResult = json_decode((string)$sResp, true);
                    }
                    error_log('[confirm] send_to_group http=' . $sStat . ' cl=' . $rowId);
                }
            } catch (Throwable $e) {
                error_log('[confirm] send_to_group 자동 호출 실패: ' . $e->getMessage());
            }

            // 응답
            $cl = $pdo->prepare('SELECT * FROM customer_log WHERE id = :id AND owner_email = :o LIMIT 1');
            $cl->execute([':id' => $rowId, ':o' => $owner]);
            $clRow = $cl->fetch();
            $respOut = [
                'status' => 'ok',
                'job_id' => $jobId,
                'job_status' => 'saved',
                'customer_log' => $clRow ? customer_log_row($clRow) : null,
                'customer_log_id' => $rowId,
            ];
            if (is_array($mirrorResult)) {
                $respOut['ledger_record'] = $mirrorResult['ledger_record'] ?? null;
                $respOut['group'] = $mirrorResult['group'] ?? null;
            }
            respond($respOut);
        }

        // ─── SEND TO GROUP (옵션 D — 양식 전송) ───
        // customer_log row 1개를 사용자가 선택한 ledger_groups(page_type='customer') 의
        // ledger_records 에 mirror. group_id 누락/invalid 시 default 그룹 자동 생성.
        // override 파라미터로 사용자가 모달에서 편집한 필드 반영.
        if ($action === 'customer_log_send_to_group') {
            $cid = trim((string)($body['id'] ?? ''));
            if ($cid === '') respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'id 가 필요합니다.'], 400);

            // customer_log row 확인 (owner 격리).
            $clStmt = $pdo->prepare('SELECT * FROM customer_log WHERE id = :id AND owner_email = :o LIMIT 1');
            $clStmt->execute([':id' => $cid, ':o' => $owner]);
            $clRow = $clStmt->fetch();
            if (!$clRow) respond(['status' => 'error', 'code' => 'not_found', 'message' => 'customer_log 없거나 권한 없음.'], 404);

            if (!ensure_ledger_tables($pdo)) {
                respond(['status' => 'error', 'code' => 'upstream_failed', 'message' => 'ledger 마이그레이션 실패.'], 503);
            }

            // 사장님 2026-05-21 §7 — refresh=true 분기 (placeholder-first).
            // process-recording 의 placeholder mirror 후 background STT/LLM 완료 시점에 호출.
            // linked_ledger_record 의 latest section 만 새 customer_log 정보로 교체.
            // group 결정 skip — 기존 lr 의 group 유지.
            if (!empty($body['refresh']) && !empty($clRow['linked_ledger_record_id'])) {
                $exLrR = $pdo->prepare('SELECT * FROM ledger_records WHERE id = :id AND owner_email = :o LIMIT 1');
                $exLrR->execute([':id' => (int)$clRow['linked_ledger_record_id'], ':o' => $owner]);
                $exLrRowR = $exLrR->fetch();
                if (!$exLrRowR) {
                    respond(['status' => 'error', 'code' => 'not_found', 'message' => 'linked ledger_record 없음.'], 404);
                }
                $clNameR    = trim((string)(youngman_decrypt($clRow['customer_name'] ?? '') ?? ''));
                $clPhoneR   = trim((string)(youngman_decrypt($clRow['phone_number'] ?? '') ?? ''));
                $clSummaryR = trim((string)(youngman_decrypt($clRow['summary'] ?? '') ?? ''));
                $clIntrR    = trim((string)(youngman_decrypt($clRow['interest'] ?? '') ?? ''));
                $clInqR     = trim((string)(youngman_decrypt($clRow['inquiry'] ?? '') ?? ''));
                $clRegionR  = trim((string)(youngman_decrypt($clRow['region'] ?? '') ?? ''));

                $contentPartsR = [];
                if ($clSummaryR !== '') $contentPartsR[] = $clSummaryR;
                if ($clIntrR    !== '') $contentPartsR[] = '관심: ' . $clIntrR;
                if ($clInqR     !== '') $contentPartsR[] = '문의: ' . $clInqR;
                $newSectionContentR = implode("\n\n", $contentPartsR);
                $todayDateR = (string)($clRow['consult_at'] ?? '');

                $curDataR = !empty($exLrRowR['data_json']) ? youngman_decrypt_json($exLrRowR['data_json']) : [];
                if (!is_array($curDataR)) $curDataR = [];

                $callCountR = (int)($curDataR['call_count'] ?? 1);
                if ($callCountR < 1) $callCountR = 1;

                $existingContentR = (string)($curDataR['content'] ?? '');
                $newLatestSectionR = "📞 {$todayDateR} 통화 ({$callCountR}회차)\n\n" . $newSectionContentR;

                if (strpos($existingContentR, "\n\n\n") !== false) {
                    // 여러 회차 — latest 만 교체
                    $partsR = explode("\n\n\n", $existingContentR, 2);
                    $mergedContentR = $newLatestSectionR . "\n\n\n" . $partsR[1];
                } else {
                    // 단일 회차 (placeholder) — 통째 교체
                    $mergedContentR = $newLatestSectionR;
                }

                $curDataR['date']     = $todayDateR;
                $curDataR['customer'] = $clNameR !== '' ? $clNameR : (string)($curDataR['customer'] ?? '');
                $curDataR['phone']    = $clPhoneR !== '' ? $clPhoneR : (string)($curDataR['phone'] ?? '');
                $curDataR['content']  = $mergedContentR;
                if (!isset($curDataR['managed'])) $curDataR['managed'] = true;
                // 사장님 2026-05-24 — 회차별 customer_log_id 자물쇠 매핑.
                // refresh 는 latest 회차 == callCountR 의 placeholder cid 가 실제 값으로 갱신되는 시점.
                // 옛 row (round_log_ids 키 없음) 에도 이번 회차 mapping 을 backfill.
                $existingMapR = (isset($curDataR['round_log_ids']) && is_array($curDataR['round_log_ids']))
                    ? $curDataR['round_log_ids'] : [];
                $existingMapR[(string)$callCountR] = (string)$cid;
                $curDataR['round_log_ids'] = $existingMapR;
                // 사장님 2026-05-24 — refresh 시점에도 region 갱신 (placeholder → 실제 값).
                // schema 매칭 못 해도 'region' fallback 사용 (client DEFAULT_FIELDS 호환).
                try {
                    $groupSchemaArrR = null;
                    $gStmtR = $pdo->prepare("SELECT field_schema_json FROM ledger_groups WHERE id = :id LIMIT 1");
                    $gStmtR->execute([':id' => (int)$exLrRowR['group_id']]);
                    $gRowSchemaR = $gStmtR->fetch();
                    if ($gRowSchemaR && !empty($gRowSchemaR['field_schema_json'])) {
                        $groupSchemaArrR = youngman_decrypt_json($gRowSchemaR['field_schema_json']);
                    }
                    $regionFieldKeyR = resolve_region_data_key($groupSchemaArrR);
                    if ($clRegionR !== '') {
                        $curDataR[$regionFieldKeyR] = $clRegionR;
                    }
                } catch (Throwable $e) {
                    // schema fetch 실패해도 'region' key fallback 으로 적용.
                    if ($clRegionR !== '') $curDataR['region'] = $clRegionR;
                }

                $newDataEncR = youngman_encrypt(json_encode($curDataR, JSON_UNESCAPED_UNICODE));
                $pdo->prepare("UPDATE ledger_records SET data_json = :dj, updated_at = NOW() WHERE id = :id")
                    ->execute([':dj' => $newDataEncR, ':id' => $exLrRowR['id']]);
                error_log('[send_to_group §7 refresh] cl=' . $cid . ' lr=' . $exLrRowR['id']);

                respond([
                    'status' => 'ok',
                    'refreshed' => true,
                    'customer_log_id' => $cid,
                    'ledger_record_id' => (int)$exLrRowR['id'],
                    'group_id' => (int)$exLrRowR['group_id'],
                ]);
            }

            // 그룹 결정: body / query 양쪽 + camelCase fallback + multipart form ($_POST).
            // 사장님 2026-05-20 보고 — 앱이 보내는 키 형식 불확실 → 다양한 경로 탐색.
            $gRow = null;
            $gid = 0;
            $gidSource = 'none';
            $candidates = [
                ['body.group_id',   $body['group_id'] ?? null],
                ['body.groupId',    $body['groupId'] ?? null],
                ['body.groupID',    $body['groupID'] ?? null],
                ['body.gid',        $body['gid'] ?? null],
                ['body.ledger_group_id', $body['ledger_group_id'] ?? null],
                ['query.group_id',  $_GET['group_id'] ?? null],
                ['query.groupId',   $_GET['groupId'] ?? null],
                ['post.group_id',   $_POST['group_id'] ?? null],
                ['post.groupId',    $_POST['groupId'] ?? null],
            ];
            foreach ($candidates as [$src, $val]) {
                if ($val === null || $val === '') continue;
                $iv = (int)$val;
                if ($iv > 0) { $gid = $iv; $gidSource = $src; break; }
            }
            error_log('[send_to_group] owner=' . $owner . ' cid=' . $cid . ' gid=' . $gid . ' src=' . $gidSource . ' body_keys=' . implode(',', array_keys($body ?? [])));
            $resolvedGroupReason = '';
            if ($gid > 0) {
                $gStmt = $pdo->prepare("SELECT * FROM ledger_groups
                    WHERE id = :id AND owner_email = :o AND page_type = 'customer' LIMIT 1");
                $gStmt->execute([':id' => $gid, ':o' => $owner]);
                $gRow = $gStmt->fetch() ?: null;
                if (!$gRow) {
                    error_log('[send_to_group] gid ' . $gid . ' not found for owner ' . $owner);
                    $resolvedGroupReason = 'gid_not_found_or_wrong_page_type';
                } else {
                    $resolvedGroupReason = 'explicit_gid';
                }
            } else {
                $resolvedGroupReason = 'no_gid_in_payload';
            }
            if (!$gRow) {
                $gRow = ensure_customer_log_default_group($pdo, $owner);
                if (!$gRow) respond(['status' => 'error', 'code' => 'upstream_failed', 'message' => '기본 그룹 자동 생성 실패.'], 503);
                $resolvedGroupReason .= '+default_fallback';
            }
            // 디버그 정보 — 응답에도 포함. 앱이 무시해도 운영자 직접 호출 시 확인 가능.
            // 사장님 2026-05-22 — RN 측이 group_id="33" 보내는데 backend gid_received=0 보고.
            // body parsing race 진단을 위해 raw_body / $_POST / Content-Type / body_keys 노출.
            $_rawBody = @file_get_contents('php://input');
            $_sendDebug = [
                'gid_received'      => $gid,
                'gid_source'        => $gidSource,
                'group_resolved_to' => (int)$gRow['id'],
                'group_name'        => (string)$gRow['name'],
                'reason'            => $resolvedGroupReason,
                // body parsing 진단
                'body_keys'         => is_array($body) ? array_keys($body) : ['(not_array)'],
                'body_size'         => is_array($body) ? count($body) : 0,
                'raw_body_length'   => is_string($_rawBody) ? strlen($_rawBody) : 0,
                'raw_body_preview'  => is_string($_rawBody) ? substr($_rawBody, 0, 300) : '',
                'content_type'      => (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
                'post_keys'         => array_keys($_POST ?? []),
                'get_keys'          => array_keys($_GET ?? []),
                // body['group_id'] 의 실제 raw 값 (있으면 형식 확인)
                'body_group_id_raw' => isset($body['group_id']) ? var_export($body['group_id'], true) : '(unset)',
                'body_groupId_raw'  => isset($body['groupId'])  ? var_export($body['groupId'], true)  : '(unset)',
            ];

            // 사장님 2026-05-20 — idempotent 분기는 "같은 customer_log + 같은 group" 일 때만 적용.
            // 다른 그룹으로 추가 mirror 허용 (멀티-그룹 미러). 기존 linked 는 첫 group 만 가리킴.
            if (!empty($clRow['linked_ledger_record_id'])) {
                $exLr = $pdo->prepare('SELECT * FROM ledger_records WHERE id = :id AND owner_email = :o LIMIT 1');
                $exLr->execute([':id' => (int)$clRow['linked_ledger_record_id'], ':o' => $owner]);
                $exLrRow = $exLr->fetch();
                if ($exLrRow && (int)$exLrRow['group_id'] === (int)$gRow['id']) {
                    // 같은 그룹 — idempotent 응답.
                    respond([
                        'status' => 'ok',
                        'duplicate' => true,
                        'customer_log' => customer_log_row($clRow),
                        'ledger_record' => ledger_record_row($exLrRow),
                        'group' => ledger_group_row($gRow),
                        '_send_debug' => $_sendDebug,
                    ]);
                }
                // 다른 그룹 — fall-through 해서 새 ledger_record INSERT/MERGE 진행.
            }

            // data_json 구성: customer_log → ledger 8필드 매핑.
            //   managed    ← true (기본 관리중, 사용자가 웹 ledger 에서 수동 해제 가능)
            //   date       ← consult_at
            //   customer   ← customer_name
            //   phone      ← phone_number
            //   call_count ← 같은 group + 동일 phone 카운트 + 1 (자동)
            //   content    ← summary + interest + inquiry (라벨 + 줄바꿈)
            //   agent_memo ← agent_memo (앱 SummaryReview 모달의 "담당자 메모" 입력값)
            //   memo       ← '' (비고 — 사용자가 웹 ledger 에서 직접 입력)
            // (budget_condition / next_action 는 customer_log 컬럼에 보존되지만 content 매핑 미적용)
            $clName    = trim((string)(youngman_decrypt($clRow['customer_name'] ?? '') ?? ''));
            $clPhone   = trim((string)(youngman_decrypt($clRow['phone_number'] ?? '') ?? ''));
            $clSummary = trim((string)(youngman_decrypt($clRow['summary'] ?? '') ?? ''));
            $clIntr    = trim((string)(youngman_decrypt($clRow['interest'] ?? '') ?? ''));
            $clInq     = trim((string)(youngman_decrypt($clRow['inquiry'] ?? '') ?? ''));
            $clRegion  = trim((string)(youngman_decrypt($clRow['region'] ?? '') ?? ''));
            $clAgentMemo = trim((string)(youngman_decrypt($clRow['agent_memo'] ?? '') ?? ''));

            // 사장님 2026-05-24 — 그룹 schema 에서 "지역" 필드 key 찾기.
            // 못 찾으면 'region' fallback (client DEFAULT_FIELDS 가 항상 data.region 읽음).
            $regionFieldKey = 'region';
            try {
                $groupSchemaArr = !empty($gRow['field_schema_json']) ? youngman_decrypt_json($gRow['field_schema_json']) : null;
                $regionFieldKey = resolve_region_data_key($groupSchemaArr);
            } catch (Throwable $e) { /* schema 파싱 실패 시 'region' fallback 그대로 사용 */ }

            $contentParts = [];
            if ($clSummary !== '') $contentParts[] = $clSummary;
            if ($clIntr    !== '') $contentParts[] = '관심: ' . $clIntr;
            if ($clInq     !== '') $contentParts[] = '문의: ' . $clInq;
            $newSectionContent = implode("\n\n", $contentParts);
            $todayDate = (string)($clRow['consult_at'] ?? '');

            // ─── Phone 정규화 기반 기존 row 탐색 ───
            // 같은 group 내 같은 phone 가진 row 가 있으면 INSERT 대신 UPDATE merge.
            // 핵심: 하나의 phone = 하나의 row. 새 통화 정보는 기존 row 에 누적되어 시계열 관리.
            $normalizedPhone = preg_replace('/[^0-9]/', '', (string)$clPhone);
            $existingLrRow = null;
            if ($normalizedPhone !== '') {
                $findStmt = $pdo->prepare('SELECT * FROM ledger_records WHERE group_id = :g AND owner_email = :o ORDER BY sort_no ASC, id ASC');
                $findStmt->execute([':g' => (int)$gRow['id'], ':o' => $owner]);
                while ($r = $findStmt->fetch()) {
                    $d = !empty($r['data_json']) ? youngman_decrypt_json($r['data_json']) : null;
                    if (is_array($d)) {
                        $p = preg_replace('/[^0-9]/', '', (string)($d['phone'] ?? ''));
                        if ($p !== '' && $p === $normalizedPhone) {
                            $existingLrRow = $r;
                            break;
                        }
                    }
                }
            }

            if ($existingLrRow) {
                // ═══ MERGE 분기 — 기존 row 누적 갱신 ═══
                $existingData = !empty($existingLrRow['data_json']) ? youngman_decrypt_json($existingLrRow['data_json']) : [];
                if (!is_array($existingData)) $existingData = [];

                // call_count = 기존 +1.
                $prevCount = (int)($existingData['call_count'] ?? 0);
                if ($prevCount < 1) $prevCount = 1;
                $newCallCount = $prevCount + 1;

                // content append — 최신이 위쪽. 회차 마커는 간결한 텍스트만 (━ 구분선 제거 —
                // 모달 word-wrap 환경에서 깨져 보이는 문제 회피). 회차 사이 빈 줄 3개로 분리.
                $newSection = "📞 {$todayDate} 통화 ({$newCallCount}회차)\n\n" . $newSectionContent;
                $existingContent = (string)($existingData['content'] ?? '');
                $mergedContent = $existingContent !== ''
                    ? $newSection . "\n\n\n" . $existingContent
                    : $newSection;

                // agent_memo append — 최신 위, 빈 메모는 추가 안 함.
                $existingMemo = (string)($existingData['agent_memo'] ?? '');
                $mergedMemo = $existingMemo;
                if ($clAgentMemo !== '') {
                    $memoSection = "📝 {$todayDate}\n" . $clAgentMemo;
                    $mergedMemo = $existingMemo !== ''
                        ? $memoSection . "\n\n\n" . $existingMemo
                        : $memoSection;
                }

                $mergedData = $existingData;
                $mergedData['date']       = $todayDate;  // 최근 통화 날짜 갱신
                $mergedData['customer']   = $clName !== '' ? $clName : (string)($existingData['customer'] ?? '');
                $mergedData['phone']      = $clPhone !== '' ? $clPhone : (string)($existingData['phone'] ?? '');
                $mergedData['call_count'] = $newCallCount;
                $mergedData['content']    = $mergedContent;
                $mergedData['agent_memo'] = $mergedMemo;
                // 사장님 2026-05-24 — 회차별 customer_log_id 자물쇠 매핑 누적.
                $existingMap = (isset($existingData['round_log_ids']) && is_array($existingData['round_log_ids']))
                    ? $existingData['round_log_ids'] : [];
                $existingMap[(string)$newCallCount] = (string)$cid;
                $mergedData['round_log_ids'] = $existingMap;
                // 사장님 2026-05-24 — 지역 자동 매핑. LLM 이 추출했으면 갱신, 못 했으면 기존 값 유지.
                // regionFieldKey 는 schema 매칭 시 그 key, 못 찾으면 'region' fallback.
                if ($clRegion !== '') {
                    $mergedData[$regionFieldKey] = $clRegion;
                }
                // managed: 기존 그대로 유지 (사용자가 의도적으로 비관리 토글한 경우 보존).
                // 옛 schema (managed 키 자체 없음) 면 true 로 보정.
                if (!array_key_exists('managed', $mergedData)) $mergedData['managed'] = true;
                // memo (비고): 기존 그대로 — 사용자 직접 입력 자유 필드.

                // override 적용 (call_count 제외).
                $override = (isset($body['override']) && is_array($body['override'])) ? $body['override'] : [];
                foreach ($override as $k => $v) {
                    if ($k === 'call_count') continue;
                    if (array_key_exists($k, $mergedData)) {
                        $mergedData[$k] = (string)$v;
                    }
                }

                // 가장 최근 통화가 그룹 최상단에 오도록 sort_no 도 갱신 (자기 제외 MIN - 1).
                // ledger-records GET 은 sort_no ASC 정렬이라 가장 작은 값이 위쪽.
                $minStmt = $pdo->prepare('SELECT IFNULL(MIN(sort_no), 1) - 1 FROM ledger_records WHERE group_id = :g AND owner_email = :o AND id != :id');
                $minStmt->execute([':g' => (int)$gRow['id'], ':o' => $owner, ':id' => (int)$existingLrRow['id']]);
                $newTopSort = (int)$minStmt->fetchColumn();

                $pdo->prepare('UPDATE ledger_records SET data_json = :d, sort_no = :s WHERE id = :id AND owner_email = :o')
                    ->execute([':d' => youngman_encrypt_json($mergedData), ':s' => $newTopSort, ':id' => (int)$existingLrRow['id'], ':o' => $owner]);

                // customer_log link → 기존 ledger_record 가리키도록.
                $pdo->prepare('UPDATE customer_log SET linked_ledger_record_id = :lr WHERE id = :id AND owner_email = :o')
                    ->execute([':lr' => (int)$existingLrRow['id'], ':id' => $cid, ':o' => $owner]);

                // catch-up: 같은 phone 의 다른 unlinked customer_log 도 함께 link.
                $backfilled = backfill_same_phone_links($pdo, $owner, $normalizedPhone, (int)$existingLrRow['id'], (string)$cid);

                $clStmt2 = $pdo->prepare('SELECT * FROM customer_log WHERE id = :id LIMIT 1');
                $clStmt2->execute([':id' => $cid]);
                $clRow2 = $clStmt2->fetch();
                $lrStmt = $pdo->prepare('SELECT * FROM ledger_records WHERE id = :id LIMIT 1');
                $lrStmt->execute([':id' => (int)$existingLrRow['id']]);
                $lrRow = $lrStmt->fetch();

                respond([
                    'status' => 'ok',
                    'merged' => true,
                    'backfilled_count' => $backfilled,
                    'customer_log'  => $clRow2 ? customer_log_row($clRow2) : null,
                    'ledger_record' => $lrRow  ? ledger_record_row($lrRow) : null,
                    'group' => ledger_group_row($gRow),
                    '_send_debug' => $_sendDebug,
                ]);
            }

            // ═══ INSERT 분기 (신규 phone 또는 phone 없음) ═══
            $callCount = calculate_call_count($pdo, (int)$gRow['id'], $owner, $clPhone);

            // 1회차도 회차 헤더 포함 — 전문보기 버튼 매칭 일관성 (사장님 2026-05-20).
            $firstRound = max(1, $callCount);
            $firstSection = "📞 {$todayDate} 통화 ({$firstRound}회차)\n\n" . $newSectionContent;
            $firstMemo = '';
            if ($clAgentMemo !== '') {
                $firstMemo = "📝 {$todayDate}\n" . $clAgentMemo;
            }

            $data = [
                'managed'    => true,
                'date'       => $todayDate,
                'customer'   => $clName,
                'phone'      => $clPhone,
                'call_count' => $callCount,
                'content'    => $firstSection,
                'agent_memo' => $firstMemo,
                'memo'       => '',
                // 사장님 2026-05-24 — 회차별 customer_log_id 자물쇠 매핑.
                // 회차 카드 ↔ transcript 영구 결합 → timestamp 매칭 실패해도 혼선 차단.
                'round_log_ids' => [(string)$firstRound => (string)$cid],
            ];
            // 사장님 2026-05-24 — 지역 자동 매핑 (INSERT 분기). regionFieldKey 는 'region' fallback 포함.
            if ($clRegion !== '') {
                $data[$regionFieldKey] = $clRegion;
            }

            // override — 앱 측이 모달에서 편집한 값. key 는 8필드 사용.
            // call_count 는 자동 계산값이라 override 받아도 무시 (백엔드 truth 유지).
            $override = (isset($body['override']) && is_array($body['override'])) ? $body['override'] : [];
            foreach ($override as $k => $v) {
                if ($k === 'call_count') continue;
                if (array_key_exists($k, $data)) {
                    $data[$k] = (string)$v;
                }
            }

            // 가장 최근 통화가 그룹 최상단에 오도록 sort_no = MIN - 1 (다른 row 들 위쪽).
            // 빈 그룹이면 IFNULL 로 0 ((1) - 1). ledger-records GET 은 sort_no ASC 정렬.
            $nxt = $pdo->prepare('SELECT IFNULL(MIN(sort_no), 1) - 1 FROM ledger_records WHERE group_id = :g');
            $nxt->execute([':g' => (int)$gRow['id']]);
            $sortNo = (int)$nxt->fetchColumn();

            $ins = $pdo->prepare('INSERT INTO ledger_records
                (group_id, owner_email, sort_no, data_json, source)
                VALUES (:g, :o, :sn, :d, :s)');
            $ins->execute([
                ':g'  => (int)$gRow['id'],
                ':o'  => $owner,
                ':sn' => $sortNo,
                ':d'  => youngman_encrypt_json($data),
                ':s'  => 'app-call-summary',
            ]);
            $newLrId = (int)$pdo->lastInsertId();

            // customer_log 에 link 저장.
            $pdo->prepare('UPDATE customer_log SET linked_ledger_record_id = :lr WHERE id = :id AND owner_email = :o')
                ->execute([':lr' => $newLrId, ':id' => $cid, ':o' => $owner]);

            // catch-up: 같은 phone 의 다른 unlinked customer_log 도 함께 link.
            $backfilled = backfill_same_phone_links($pdo, $owner, $normalizedPhone, $newLrId, (string)$cid);

            // 최종 응답: 갱신된 customer_log + 새 ledger_record + 그룹.
            $clStmt2 = $pdo->prepare('SELECT * FROM customer_log WHERE id = :id LIMIT 1');
            $clStmt2->execute([':id' => $cid]);
            $clRow2 = $clStmt2->fetch();
            $lrStmt = $pdo->prepare('SELECT * FROM ledger_records WHERE id = :id LIMIT 1');
            $lrStmt->execute([':id' => $newLrId]);
            $lrRow = $lrStmt->fetch();

            respond([
                'status' => 'ok',
                'backfilled_count' => $backfilled,
                'customer_log' => $clRow2 ? customer_log_row($clRow2) : null,
                'ledger_record' => $lrRow ? ledger_record_row($lrRow) : null,
                'group' => ledger_group_row($gRow),
                '_send_debug' => $_sendDebug,
            ]);
        }

        respond(['status' => 'error', 'code' => 'invalid_request', 'message' => '지원하지 않는 action 입니다.'], 400);
    }

    /* ============================================================
       app-fcm-token — Phase 2: FCM 푸시 토큰 등록/해지/조회 (앱 측 사용)
       customer-log 와 같은 self-auth (verify_auth_token 우회) + spec §4 표준 응답.
       async mode 의 통화녹취 처리 완료 시 푸시 발송 대상 토큰 저장소.
       ============================================================ */
    if ($resource === 'app-fcm-token') {
        $fcmHdr = read_authorization_header();
        if (!preg_match('/^Bearer\s+(.+)$/i', $fcmHdr, $fcmM)) {
            respond(['status' => 'error', 'code' => 'unauthorized', 'message' => '로그인이 필요합니다.'], 401);
        }
        $fcmTokenBearer = trim($fcmM[1]);
        $fcmBase = !empty($auth['supabase_url']) ? rtrim((string)$auth['supabase_url'], '/') : '';
        $fcmKey  = (string)($auth['anon_key'] ?? '');
        if ($fcmBase === '' || $fcmKey === '') {
            respond(['status' => 'error', 'code' => 'unauthorized', 'message' => '서버 인증 설정 누락 (supabase_url / anon_key).'], 500);
        }
        $fcmCh = curl_init($fcmBase . '/auth/v1/user');
        curl_setopt_array($fcmCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $fcmTokenBearer, 'apikey: ' . $fcmKey],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $fcmAuthResp = curl_exec($fcmCh);
        $fcmAuthStatus = (int)curl_getinfo($fcmCh, CURLINFO_HTTP_CODE);
        curl_close($fcmCh);
        if ($fcmAuthStatus !== 200 || !$fcmAuthResp) {
            respond(['status' => 'error', 'code' => 'unauthorized',
                'message' => '토큰 검증 실패 (Supabase ' . $fcmAuthStatus . '). 다시 로그인해주세요.'], 401);
        }
        $fcmAuthData = json_decode((string)$fcmAuthResp, true);
        $fcmOwnerEmail = strtolower(trim((string)($fcmAuthData['email'] ?? '')));
        if ($fcmOwnerEmail === '') {
            respond(['status' => 'error', 'code' => 'unauthorized', 'message' => '토큰에서 이메일 추출 실패.'], 401);
        }

        if (!ensure_user_fcm_tokens_table($pdo)) {
            respond(['status' => 'error', 'code' => 'upstream_failed', 'message' => 'fcm_tokens 마이그레이션 실패.'], 503);
        }

        $owner = $fcmOwnerEmail;
        $action = strtolower(trim((string)($body['action'] ?? $_GET['action'] ?? '')));

        // ─── REGISTER (UPSERT) ───
        if ($action === 'register') {
            $tok = trim((string)($body['token'] ?? ''));
            if ($tok === '') respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'token 이 필요합니다.'], 400);
            if (strlen($tok) > 512) respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'token 길이 초과 (최대 512자).'], 400);
            $devId = trim((string)($body['device_id'] ?? ''));
            if (strlen($devId) > 120) $devId = substr($devId, 0, 120);
            $plat = strtolower(trim((string)($body['platform'] ?? 'android')));
            if (!in_array($plat, ['android', 'ios'], true)) $plat = 'android';

            // 같은 token 이 다른 owner 로 재등록 = 계정 전환 케이스. owner_email 갱신 + last_seen_at touch.
            $ins = $pdo->prepare("INSERT INTO user_fcm_tokens (owner_email, token, device_id, platform)
                VALUES (:o, :t, :d, :p)
                ON DUPLICATE KEY UPDATE
                    owner_email = VALUES(owner_email),
                    device_id = VALUES(device_id),
                    platform = VALUES(platform),
                    last_seen_at = CURRENT_TIMESTAMP");
            $ins->execute([
                ':o' => $owner,
                ':t' => $tok,
                ':d' => $devId !== '' ? $devId : null,
                ':p' => $plat,
            ]);
            $sel = $pdo->prepare('SELECT * FROM user_fcm_tokens WHERE token = :t LIMIT 1');
            $sel->execute([':t' => $tok]);
            $row = $sel->fetch();
            respond([
                'status' => 'ok',
                'fcm_token' => $row ? user_fcm_token_row($row) : null,
            ]);
        }

        // ─── UNREGISTER ───
        if ($action === 'unregister') {
            $tok = trim((string)($body['token'] ?? ''));
            if ($tok === '') respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'token 이 필요합니다.'], 400);
            $del = $pdo->prepare('DELETE FROM user_fcm_tokens WHERE token = :t AND owner_email = :o');
            $del->execute([':t' => $tok, ':o' => $owner]);
            respond(['status' => 'ok', 'deleted' => (int)$del->rowCount()]);
        }

        // ─── LIST (디버그 / 사용자 본인 토큰 조회) ───
        if ($action === 'list') {
            $stmt = $pdo->prepare('SELECT * FROM user_fcm_tokens WHERE owner_email = :o ORDER BY id DESC');
            $stmt->execute([':o' => $owner]);
            respond([
                'status' => 'ok',
                'items' => array_map('user_fcm_token_row', $stmt->fetchAll()),
            ]);
        }

        respond(['status' => 'error', 'code' => 'invalid_request', 'message' => '지원하지 않는 action 입니다.'], 400);
    }

    /* ============================================================
       recording-job — Phase 2 M2: async 작업 status 폴링 (M3 FCM 전 fallback).
       customer-log / app-fcm-token 와 같은 self-auth + spec §4 표준 응답.
       ============================================================ */
    if ($resource === 'recording-job') {
        $rjHdr = read_authorization_header();
        if (!preg_match('/^Bearer\s+(.+)$/i', $rjHdr, $rjM)) {
            respond(['status' => 'error', 'code' => 'unauthorized', 'message' => '로그인이 필요합니다.'], 401);
        }
        $rjToken = trim($rjM[1]);
        $rjBase = !empty($auth['supabase_url']) ? rtrim((string)$auth['supabase_url'], '/') : '';
        $rjKey  = (string)($auth['anon_key'] ?? '');
        if ($rjBase === '' || $rjKey === '') {
            respond(['status' => 'error', 'code' => 'unauthorized', 'message' => '서버 인증 설정 누락.'], 500);
        }
        $rjCh = curl_init($rjBase . '/auth/v1/user');
        curl_setopt_array($rjCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $rjToken, 'apikey: ' . $rjKey],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $rjResp = curl_exec($rjCh);
        $rjStatus = (int)curl_getinfo($rjCh, CURLINFO_HTTP_CODE);
        curl_close($rjCh);
        if ($rjStatus !== 200 || !$rjResp) {
            respond(['status' => 'error', 'code' => 'unauthorized', 'message' => '토큰 검증 실패.'], 401);
        }
        $rjData = json_decode((string)$rjResp, true);
        $rjOwner = strtolower(trim((string)($rjData['email'] ?? '')));
        if ($rjOwner === '') {
            respond(['status' => 'error', 'code' => 'unauthorized', 'message' => '토큰 이메일 추출 실패.'], 401);
        }

        if (!ensure_recording_jobs_table($pdo)) {
            respond(['status' => 'error', 'code' => 'upstream_failed', 'message' => 'recording_jobs 마이그레이션 실패.'], 503);
        }

        $owner = $rjOwner;
        $action = strtolower(trim((string)($body['action'] ?? $_GET['action'] ?? '')));

        // ─── GET (단일 job 폴링) ───
        if ($action === 'recording_job_get') {
            $jid = trim((string)($body['job_id'] ?? $_GET['job_id'] ?? ''));
            if ($jid === '') respond(['status' => 'error', 'code' => 'invalid_request', 'message' => 'job_id 가 필요합니다.'], 400);
            $stmt = $pdo->prepare('SELECT * FROM recording_jobs WHERE id = :id AND owner_email = :o LIMIT 1');
            $stmt->execute([':id' => $jid, ':o' => $owner]);
            $row = $stmt->fetch();
            if (!$row) respond(['status' => 'error', 'code' => 'not_found', 'message' => '존재하지 않거나 권한이 없습니다.'], 404);
            respond([
                'status' => 'ok',
                'job' => [
                    'id'                => (string)$row['id'],
                    'job_status'        => $row['status'],
                    'customer_log_id'   => $row['customer_log_id'] ?? null,
                    'storage_path'      => $row['storage_path'] ?? null,
                    'client_request_id' => $row['client_request_id'] ?? null,
                    'error_message'     => $row['error_message'] ?? null,
                    'fcm_sent_at'       => $row['fcm_sent_at'] ?? null,
                    'started_at'        => $row['started_at'] ?? null,
                    'completed_at'      => $row['completed_at'] ?? null,
                    'created_at'        => $row['created_at'] ?? null,
                ],
            ]);
        }

        // ─── LIST (사용자 본인 최근 jobs) ───
        if ($action === 'recording_job_list') {
            $limit = (int)($body['limit'] ?? $_GET['limit'] ?? 20);
            if ($limit < 1) $limit = 20;
            if ($limit > 100) $limit = 100;
            $stmt = $pdo->prepare('SELECT id, status, customer_log_id, error_message, started_at, completed_at, created_at
                                   FROM recording_jobs WHERE owner_email = :o
                                   ORDER BY created_at DESC LIMIT ' . $limit);
            $stmt->execute([':o' => $owner]);
            respond(['status' => 'ok', 'items' => $stmt->fetchAll()]);
        }

        respond(['status' => 'error', 'code' => 'invalid_request', 'message' => '지원하지 않는 action 입니다.'], 400);
    }

    enforce_registered_member($pdo, $authUser);

    // ===== 보안: 사용자별 데이터 격리 (PII 보호) =====
    // owner_email 컬럼이 없으면 자동 추가.
    $table = $resource === 'customers' ? 'customers' : 'employees';
    $migrationOk = ensure_owner_column($pdo, $table);
    if (!$migrationOk) {
        respond(['ok' => false, 'error' => '데이터 격리 마이그레이션이 적용되지 않았습니다. 잠시 후 다시 시도해주세요.'], 503);
    }

    $owner = current_owner_email($authUser);
    if ($owner === '') {
        // 이메일을 알 수 없는 인증은 PII 데이터 접근 불가.
        respond(['ok' => false, 'error' => '인증된 사용자 정보를 확인할 수 없습니다.'], 401);
    }

    // 정책: 고객/직원 PII 는 어떤 사용자도 (관리자 포함) 다른 사람의 데이터를
    // 일반 대시보드에서 볼 수 없음. 운영 통계는 admin-stats 엔드포인트가 별도로
    // 집계만 제공 (개인 식별 정보 노출 없이).
    if ($method === 'GET') {
        $sql  = "SELECT * FROM " . quote_identifier($table) . " WHERE owner_email = :owner ORDER BY id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':owner' => $owner]);
        $rows = $stmt->fetchAll();
        $mapper = $resource === 'customers' ? 'customer_row' : 'employee_row';
        respond(['ok' => true, 'items' => array_map($mapper, $rows)]);
    }

    if ($method === 'POST') {
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $clientId = clean($data['id'] ?? null) ?: make_client_id();

        if ($resource === 'customers') {
            $name = clean($data['name'] ?? null);
            if (!$name) respond(['ok' => false, 'error' => '고객 이름은 필수입니다.'], 400);

            // 신규 INSERT 는 owner_email 항상 설정.
            // ON DUPLICATE KEY UPDATE: client_id 가 다른 사용자 소유면 갱신 거부 (owner_email 일치 시에만 갱신).
            $stmt = $pdo->prepare("
                INSERT INTO customers (client_id, owner_email, name, phone, notes)
                VALUES (:client_id, :owner, :name, :phone, :notes)
                ON DUPLICATE KEY UPDATE
                    name  = IF(owner_email = VALUES(owner_email), VALUES(name),  name),
                    phone = IF(owner_email = VALUES(owner_email), VALUES(phone), phone),
                    notes = IF(owner_email = VALUES(owner_email), VALUES(notes), notes)
            ");
            $stmt->execute([
                ':client_id' => $clientId,
                ':owner'     => $owner,
                // PII — AES-256-GCM 암호화 후 저장.
                ':name'      => youngman_encrypt($name),
                ':phone'     => youngman_encrypt(clean($data['phone'] ?? null)),
                ':notes'     => youngman_encrypt(clean($data['notes'] ?? null)),
            ]);
        } else {
            $name = clean($data['name'] ?? null);
            $title = clean($data['title'] ?? null);
            if (!$name || !$title) respond(['ok' => false, 'error' => '직원 이름과 직함은 필수입니다.'], 400);

            $stmt = $pdo->prepare("
                INSERT INTO employees (client_id, owner_email, name, title, contact, start_date, notes)
                VALUES (:client_id, :owner, :name, :title, :contact, :start_date, :notes)
                ON DUPLICATE KEY UPDATE
                    name       = IF(owner_email = VALUES(owner_email), VALUES(name),       name),
                    title      = IF(owner_email = VALUES(owner_email), VALUES(title),      title),
                    contact    = IF(owner_email = VALUES(owner_email), VALUES(contact),    contact),
                    start_date = IF(owner_email = VALUES(owner_email), VALUES(start_date), start_date),
                    notes      = IF(owner_email = VALUES(owner_email), VALUES(notes),      notes)
            ");
            $stmt->execute([
                ':client_id'  => $clientId,
                ':owner'      => $owner,
                // PII — AES-256-GCM 암호화 (title/start_date 는 비PII 평문 유지).
                ':name'       => youngman_encrypt($name),
                ':title'      => $title,
                ':contact'    => youngman_encrypt(clean($data['contact'] ?? null)),
                ':start_date' => clean($data['startDate'] ?? null),
                ':notes'      => youngman_encrypt(clean($data['notes'] ?? null)),
            ]);
        }

        respond(['ok' => true, 'id' => $clientId]);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $id = clean($body['id'] ?? null);
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        if (!$id) respond(['ok' => false, 'error' => '수정할 ID가 없습니다.'], 400);

        if ($resource === 'customers') {
            $name = clean($data['name'] ?? null);
            if (!$name) respond(['ok' => false, 'error' => '고객 이름은 필수입니다.'], 400);

            $stmt = $pdo->prepare("UPDATE customers SET name = :name, phone = :phone, notes = :notes
                                    WHERE client_id = :id AND owner_email = :owner");
            $stmt->execute([
                ':id'    => $id,
                ':owner' => $owner,
                // PII — AES-256-GCM 암호화 후 저장.
                ':name'  => youngman_encrypt($name),
                ':phone' => youngman_encrypt(clean($data['phone'] ?? null)),
                ':notes' => youngman_encrypt(clean($data['notes'] ?? null)),
            ]);
        } else {
            $name = clean($data['name'] ?? null);
            $title = clean($data['title'] ?? null);
            if (!$name || !$title) respond(['ok' => false, 'error' => '직원 이름과 직함은 필수입니다.'], 400);

            $stmt = $pdo->prepare("UPDATE employees
                                    SET name = :name, title = :title, contact = :contact,
                                        start_date = :start_date, notes = :notes
                                    WHERE client_id = :id AND owner_email = :owner");
            $stmt->execute([
                ':id'         => $id,
                ':owner'      => $owner,
                // PII — AES-256-GCM 암호화 (title/start_date 는 비PII 평문 유지).
                ':name'       => youngman_encrypt($name),
                ':title'      => $title,
                ':contact'    => youngman_encrypt(clean($data['contact'] ?? null)),
                ':start_date' => clean($data['startDate'] ?? null),
                ':notes'      => youngman_encrypt(clean($data['notes'] ?? null)),
            ]);
        }
        if ($stmt->rowCount() === 0) {
            respond(['ok' => false, 'error' => '수정할 권한이 없거나 존재하지 않는 항목입니다.'], 404);
        }

        respond(['ok' => true]);
    }

    if ($method === 'DELETE') {
        $id = clean($body['id'] ?? null);
        if (!$id) respond(['ok' => false, 'error' => '삭제할 ID가 없습니다.'], 400);

        $stmt = $pdo->prepare("DELETE FROM " . quote_identifier($table)
            . " WHERE client_id = :id AND owner_email = :owner");
        $stmt->execute([':id' => $id, ':owner' => $owner]);
        if ($stmt->rowCount() === 0) {
            respond(['ok' => false, 'error' => '삭제할 권한이 없거나 존재하지 않는 항목입니다.'], 404);
        }
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
