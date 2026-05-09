<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
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
    }
})();

function respond($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_body() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $json = json_decode($raw, true);
    if (!is_array($json)) respond(['ok' => false, 'error' => 'JSON 형식이 올바르지 않습니다.'], 400);
    return $json;
}

function normalize_resource($value) {
    $resource = strtolower(trim((string)$value));
    if ($resource === 'customer') $resource = 'customers';
    if ($resource === 'employee') $resource = 'employees';
    $allowed = [
        'customers', 'employees',
        'auth-membership', 'auth-member', 'auth-availability',
        'auth-profile',
        'admin-members', 'admin-stats', 'admin-logs', 'admin-settings',
        'admin-bootstrap',
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

function nickname_taken(PDO $pdo, $store, $nickname) {
    $columns = $store['columns'];
    $col = first_existing_column($columns, ['nickname', 'nick', 'display_name']);
    if (!$col) return false; // no column = nothing to dedupe on
    $stmt = $pdo->prepare(
        "SELECT 1 FROM " . quote_identifier($store['table']) .
        " WHERE LOWER(" . quote_identifier($col) . ") = :nick LIMIT 1"
    );
    $stmt->execute([':nick' => mb_strtolower(trim((string)$nickname), 'UTF-8')]);
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

    $fullName = clean($data['fullName'] ?? $data['name'] ?? null);
    $phone = clean($data['phone'] ?? null);
    $nickname = clean($data['nickname'] ?? null);
    if (!$fullName) respond(['ok' => false, 'error' => '가입자 이름은 필수입니다.'], 400);
    if ($nickname !== null && !is_valid_nickname($nickname)) {
        respond(['ok' => false, 'error' => '닉네임 형식이 올바르지 않습니다.'], 400);
    }
    // Google 가입 시에는 휴대폰 번호를 필수가 아니게 변경 (사용자 요청: 구글 시스템 그대로 사용)

    $store = find_member_store($pdo);
    if (!$store) respond(['ok' => false, 'error' => 'members 또는 users 회원 테이블을 찾을 수 없습니다.'], 500);
    if (member_exists_by_email($pdo, $email) === true) {
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

    $fieldSql = implode(', ', array_map('quote_identifier', array_keys($row)));
    $placeholderSql = implode(', ', array_map(function ($column) {
        return ':' . $column;
    }, array_keys($row)));
    $stmt = $pdo->prepare("INSERT INTO " . quote_identifier($store['table']) . " ({$fieldSql}) VALUES ({$placeholderSql})");
    $params = [];
    foreach ($row as $column => $value) {
        $params[':' . $column] = $value;
    }
    $stmt->execute($params);

    respond([
        'ok' => true,
        'created' => true,
        'role' => is_admin_email($email) ? 'admin' : 'member',
    ]);
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

    return [
        'email' => $row[$emailCol] ?? '',
        'name' => $nameCol ? ($row[$nameCol] ?? '') : '',
        'nickname' => $nicknameCol ? ($row[$nicknameCol] ?? '') : '',
        'phone' => $phoneCol ? ($row[$phoneCol] ?? '') : '',
        'provider' => $providerCol ? ($row[$providerCol] ?? 'email') : 'email',
        'status' => $status === '' ? 'active' : strtolower($status),
        'role' => $role === '' ? 'member' : strtolower($role),
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

/** 본 요청의 사용자가 어드민/오너인지 판단. 기존 is_admin_user($authUser, $memberRecord) 활용. */
function current_user_is_admin(PDO $pdo, $authUser): bool {
    if (!$authUser) return false;
    $email = (string)($authUser['email'] ?? '');
    $memberRecord = $email !== '' ? fetch_member_by_email($pdo, $email) : null;
    return is_admin_user($authUser, $memberRecord);
}

function customer_row($row) {
    return [
        'id' => $row['client_id'],
        'name' => $row['name'],
        'phone' => $row['phone'] ?? '',
        'notes' => $row['notes'] ?? '',
        'createdAt' => date('Y. m. d.', strtotime($row['created_at'])),
    ];
}

function employee_row($row) {
    return [
        'id' => $row['client_id'],
        'name' => $row['name'],
        'title' => $row['title'],
        'contact' => $row['contact'] ?? '',
        'startDate' => $row['start_date'] ?? '',
        'notes' => $row['notes'] ?? '',
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
    $publicResources = ['auth-availability'];
    $peekedResource = strtolower(trim((string)($_GET['resource'] ?? '')));
    $authUser = in_array($peekedResource, $publicResources, true) ? null : verify_auth_token($auth);

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
                ]]);
            }
            $profile = member_row_from_store($rec['store'], $rec['row']);
            $profile['email'] = $email;
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
                $params[':name'] = clean($data['name']) ?? '';
            }

            $phoneCol = first_existing_column($cols, ['phone', 'mobile', 'tel', 'contact', 'user_phone', 'mb_hp']);
            if ($phoneCol && isset($data['phone'])) {
                $assignments[] = quote_identifier($phoneCol) . ' = :phone';
                $params[':phone'] = clean($data['phone']);
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
                ':name'      => $name,
                ':phone'     => clean($data['phone'] ?? null),
                ':notes'     => clean($data['notes'] ?? null),
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
                ':name'       => $name,
                ':title'      => $title,
                ':contact'    => clean($data['contact'] ?? null),
                ':start_date' => clean($data['startDate'] ?? null),
                ':notes'      => clean($data['notes'] ?? null),
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
                ':name'  => $name,
                ':phone' => clean($data['phone'] ?? null),
                ':notes' => clean($data['notes'] ?? null),
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
                ':name'       => $name,
                ':title'      => $title,
                ':contact'    => clean($data['contact'] ?? null),
                ':start_date' => clean($data['startDate'] ?? null),
                ':notes'      => clean($data['notes'] ?? null),
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
