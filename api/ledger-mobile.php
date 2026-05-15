<?php
/**
 * api/ledger-mobile.php  — Phase 5: 모바일 앱용 ledger 입력 엔드포인트
 *
 * 인증: Authorization: Bearer yman_<32hex>  (records.php 의 mobile-tokens 로 발급)
 * 카페24 평면 배포 시 /ledger-mobile.php 로 노출.
 *
 * 요청 (POST JSON):
 *  {
 *    "page_type": "customer" | "contract" | "org",
 *    "group_id": 12,                           // (group_name 과 둘 중 하나)
 *    "group_name": "브래인시티비스타동원",       // 이름으로 자동 매칭/생성
 *    "data": { ... 필드 값 ... },
 *    "client_idempotency_key": "ocr-2026-05-09-abc123",
 *    "source": "mobile-ocr" | "mobile-call" | ...
 *  }
 *
 * 응답:
 *  { ok: true, record_id, sort_no, group_id, duplicate, group_auto_created }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function jout(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ===== 암호화 헬퍼 (records.php 와 동일 키, 동일 포맷) ===== */
require_once __DIR__ . '/crypto_helpers.php';

/* ===== DB ===== */
$cfg = __DIR__ . '/db_config.php';
if (!is_file($cfg)) $cfg = dirname(__DIR__) . '/db_config.php';
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
    jout(['ok' => false, 'error' => 'DB 연결 실패: ' . $e->getMessage()], 500);
}

/* ===== 토큰 검증 ===== */
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$auth) {
    // Apache/cafe24 에 따라 HTTP_AUTHORIZATION 이 누락되는 경우가 있음. getallheaders fallback.
    if (function_exists('getallheaders')) {
        $hdrs = getallheaders() ?: [];
        foreach ($hdrs as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = (string)$v; break; }
        }
    }
}
if (!preg_match('/^Bearer\s+(yman_[a-f0-9]{32})$/', trim($auth), $m)) {
    jout(['ok' => false, 'error' => '유효한 모바일 API 토큰이 필요합니다.'], 401);
}
$plain = $m[1];
$hash  = hash('sha256', $plain);

try {
    $stmt = $pdo->prepare('SELECT id, owner_email FROM mobile_api_tokens
                            WHERE token_hash = :h AND revoked_at IS NULL LIMIT 1');
    $stmt->execute([':h' => $hash]);
    $tok = $stmt->fetch();
} catch (Throwable $e) {
    jout(['ok' => false, 'error' => 'DB 오류'], 500);
}
if (!$tok) jout(['ok' => false, 'error' => '토큰이 유효하지 않거나 폐기됨'], 401);

$owner   = strtolower((string)$tok['owner_email']);
$tokenId = (int)$tok['id'];

// last_used 업데이트 (실패해도 무시).
try {
    $upd = $pdo->prepare('UPDATE mobile_api_tokens SET last_used_at = NOW(), last_used_ip = :ip WHERE id = :id');
    $upd->execute([':ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), ':id' => $tokenId]);
} catch (Throwable $e) { /* */ }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jout(['ok' => false, 'error' => 'POST only'], 405);
}

/* ===== 요청 파싱 ===== */
$raw  = (string)file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) jout(['ok' => false, 'error' => 'JSON 형식 오류'], 400);

$pageType = strtolower(trim((string)($body['page_type'] ?? '')));
$validPages = ['contract', 'org', 'customer'];
if (!in_array($pageType, $validPages, true)) {
    jout(['ok' => false, 'error' => 'page_type 은 contract / org / customer 중 하나여야 합니다.'], 400);
}

$groupId   = (int)($body['group_id'] ?? 0);
$groupName = trim((string)($body['group_name'] ?? ''));
$data      = is_array($body['data'] ?? null) ? $body['data'] : [];
$idemKey   = trim((string)($body['client_idempotency_key'] ?? ''));
$source    = trim((string)($body['source'] ?? 'mobile'));
if ($source === '' || strlen($source) > 40) $source = 'mobile';

if (!$groupId && $groupName === '') {
    jout(['ok' => false, 'error' => 'group_id 또는 group_name 이 필요합니다.'], 400);
}

/* ===== 그룹 조회 (없으면 자동 생성) ===== */
$groupAutoCreated = false;
if ($groupId) {
    $g = $pdo->prepare('SELECT id, owner_email FROM ledger_groups WHERE id = :id LIMIT 1');
    $g->execute([':id' => $groupId]);
    $row = $g->fetch();
    if (!$row) jout(['ok' => false, 'error' => '존재하지 않는 그룹 id'], 404);
    if (strtolower((string)$row['owner_email']) !== $owner) jout(['ok' => false, 'error' => '그룹 접근 권한 없음'], 403);
} else {
    $g = $pdo->prepare('SELECT id FROM ledger_groups WHERE owner_email = :o AND page_type = :pt AND name = :n LIMIT 1');
    $g->execute([':o' => $owner, ':pt' => $pageType, ':n' => $groupName]);
    $groupId = (int)$g->fetchColumn();
    if (!$groupId) {
        // 자동 생성. 기본 field schema 는 서버에서 모르므로 빈 객체. 웹 UI 가
        // 첫 접속 시 보여줄 기본 필드를 채워 넣음 (org/customer/contracts.js 참고).
        $ins = $pdo->prepare('INSERT INTO ledger_groups (owner_email, page_type, name, field_schema_json, settings_json)
                              VALUES (:o, :pt, :n, NULL, NULL)');
        $ins->execute([':o' => $owner, ':pt' => $pageType, ':n' => $groupName]);
        $groupId = (int)$pdo->lastInsertId();
        $groupAutoCreated = true;
    }
}

/* ===== 멱등성 처리 ===== */
if ($idemKey !== '') {
    $chk = $pdo->prepare('SELECT id, sort_no FROM ledger_records
                          WHERE owner_email = :o AND client_idempotency_key = :k LIMIT 1');
    $chk->execute([':o' => $owner, ':k' => $idemKey]);
    $existing = $chk->fetch();
    if ($existing) {
        jout([
            'ok'        => true,
            'record_id' => (int)$existing['id'],
            'sort_no'   => (int)$existing['sort_no'],
            'group_id'  => $groupId,
            'duplicate' => true,
            'group_auto_created' => $groupAutoCreated,
        ]);
    }
}

/* ===== 레코드 생성 ===== */
try {
    $next = $pdo->prepare('SELECT IFNULL(MAX(sort_no), 0) + 1 FROM ledger_records WHERE group_id = :g');
    $next->execute([':g' => $groupId]);
    $sortNo = (int)$next->fetchColumn();

    $ins = $pdo->prepare('
        INSERT INTO ledger_records (group_id, owner_email, sort_no, data_json, client_idempotency_key, source)
        VALUES (:g, :o, :sn, :d, :k, :s)
    ');
    $ins->execute([
        ':g'  => $groupId,
        ':o'  => $owner,
        ':sn' => $sortNo,
        // AES-256-GCM 암호화 — records.php 와 동일 포맷, 동일 키.
        ':d'  => youngman_encrypt_json($data),
        ':k'  => $idemKey !== '' ? $idemKey : null,
        ':s'  => $source,
    ]);
    $recordId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    jout(['ok' => false, 'error' => '저장 실패: ' . $e->getMessage()], 500);
}

jout([
    'ok'                  => true,
    'record_id'           => $recordId,
    'sort_no'             => $sortNo,
    'group_id'            => $groupId,
    'duplicate'           => false,
    'group_auto_created'  => $groupAutoCreated,
]);
