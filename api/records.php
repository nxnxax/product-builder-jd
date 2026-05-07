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
    if (!in_array($resource, ['customers', 'employees'], true)) {
        respond(['ok' => false, 'error' => '지원하지 않는 리소스입니다.'], 400);
    }
    return $resource;
}

function clean($value) {
    $value = trim((string)($value ?? ''));
    return $value === '' ? null : $value;
}

function make_client_id() {
    return (string)round(microtime(true) * 1000) . bin2hex(random_bytes(3));
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

function verify_supabase_jwt($auth) {
    if (empty($auth['require_auth'])) return null;

    $jwtSecret = (string)($auth['jwt_secret'] ?? '');
    if ($jwtSecret === '' || $jwtSecret === 'your-supabase-jwt-secret') {
        respond(['ok' => false, 'error' => 'Supabase 인증 설정이 없습니다.'], 500);
    }

    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        respond(['ok' => false, 'error' => '로그인이 필요합니다.'], 401);
    }

    $token = $matches[1];
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        respond(['ok' => false, 'error' => '인증 토큰 형식이 올바르지 않습니다.'], 401);
    }

    [$headerPart, $payloadPart, $signaturePart] = $parts;
    $headerJson = json_decode(base64url_decode_strict($headerPart), true);
    $payload = json_decode(base64url_decode_strict($payloadPart), true);

    if (!is_array($headerJson) || !is_array($payload) || ($headerJson['alg'] ?? '') !== 'HS256') {
        respond(['ok' => false, 'error' => '지원하지 않는 인증 토큰입니다.'], 401);
    }

    $expected = hash_hmac('sha256', $headerPart . '.' . $payloadPart, $jwtSecret, true);
    $actual = base64url_decode_strict($signaturePart);
    if (!hash_equals($expected, $actual)) {
        respond(['ok' => false, 'error' => '인증 토큰 검증에 실패했습니다.'], 401);
    }

    if (($payload['exp'] ?? 0) < time()) {
        respond(['ok' => false, 'error' => '로그인 세션이 만료되었습니다.'], 401);
    }

    $issuer = (string)($auth['issuer'] ?? '');
    if ($issuer !== '' && ($payload['iss'] ?? '') !== $issuer) {
        respond(['ok' => false, 'error' => '인증 발급자가 올바르지 않습니다.'], 401);
    }

    $audience = (string)($auth['audience'] ?? 'authenticated');
    $aud = $payload['aud'] ?? '';
    $audiences = is_array($aud) ? $aud : [$aud];
    if ($audience !== '' && !in_array($audience, $audiences, true)) {
        respond(['ok' => false, 'error' => '인증 대상이 올바르지 않습니다.'], 401);
    }

    return $payload;
}

try {
    $authUser = verify_supabase_jwt($auth);

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

    if ($method === 'GET') {
        if ($resource === 'customers') {
            $rows = $pdo->query("SELECT * FROM customers ORDER BY id DESC")->fetchAll();
            respond(['ok' => true, 'items' => array_map('customer_row', $rows)]);
        }

        $rows = $pdo->query("SELECT * FROM employees ORDER BY id DESC")->fetchAll();
        respond(['ok' => true, 'items' => array_map('employee_row', $rows)]);
    }

    if ($method === 'POST') {
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $clientId = clean($data['id'] ?? null) ?: make_client_id();

        if ($resource === 'customers') {
            $name = clean($data['name'] ?? null);
            if (!$name) respond(['ok' => false, 'error' => '고객 이름은 필수입니다.'], 400);

            $stmt = $pdo->prepare("
                INSERT INTO customers (client_id, name, phone, notes)
                VALUES (:client_id, :name, :phone, :notes)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    phone = VALUES(phone),
                    notes = VALUES(notes)
            ");
            $stmt->execute([
                ':client_id' => $clientId,
                ':name' => $name,
                ':phone' => clean($data['phone'] ?? null),
                ':notes' => clean($data['notes'] ?? null),
            ]);
        } else {
            $name = clean($data['name'] ?? null);
            $title = clean($data['title'] ?? null);
            if (!$name || !$title) respond(['ok' => false, 'error' => '직원 이름과 직함은 필수입니다.'], 400);

            $stmt = $pdo->prepare("
                INSERT INTO employees (client_id, name, title, contact, start_date, notes)
                VALUES (:client_id, :name, :title, :contact, :start_date, :notes)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    title = VALUES(title),
                    contact = VALUES(contact),
                    start_date = VALUES(start_date),
                    notes = VALUES(notes)
            ");
            $stmt->execute([
                ':client_id' => $clientId,
                ':name' => $name,
                ':title' => $title,
                ':contact' => clean($data['contact'] ?? null),
                ':start_date' => clean($data['startDate'] ?? null),
                ':notes' => clean($data['notes'] ?? null),
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

            $stmt = $pdo->prepare("UPDATE customers SET name = :name, phone = :phone, notes = :notes WHERE client_id = :id");
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':phone' => clean($data['phone'] ?? null),
                ':notes' => clean($data['notes'] ?? null),
            ]);
        } else {
            $name = clean($data['name'] ?? null);
            $title = clean($data['title'] ?? null);
            if (!$name || !$title) respond(['ok' => false, 'error' => '직원 이름과 직함은 필수입니다.'], 400);

            $stmt = $pdo->prepare("
                UPDATE employees
                SET name = :name, title = :title, contact = :contact, start_date = :start_date, notes = :notes
                WHERE client_id = :id
            ");
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':title' => $title,
                ':contact' => clean($data['contact'] ?? null),
                ':start_date' => clean($data['startDate'] ?? null),
                ':notes' => clean($data['notes'] ?? null),
            ]);
        }

        respond(['ok' => true]);
    }

    if ($method === 'DELETE') {
        $id = clean($body['id'] ?? null);
        if (!$id) respond(['ok' => false, 'error' => '삭제할 ID가 없습니다.'], 400);

        $table = $resource === 'customers' ? 'customers' : 'employees';
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE client_id = :id");
        $stmt->execute([':id' => $id]);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => '지원하지 않는 요청 방식입니다.'], 405);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
