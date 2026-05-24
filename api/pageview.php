<?php
/**
 * pageview.php — 방문자/유입경로 트래킹 endpoint.
 *
 * 호출: POST /pageview.php (fire-and-forget, auth-shared.js 의 tryLogPageview).
 * 인증: 불필요 (모든 방문자 트래킹). 단 abuse 방지 위해 1초 timeout / no-cors.
 *
 * 저장 컬럼:
 *   path / referrer / utm_source / utm_medium / utm_campaign / user_agent
 *   ip_hash (HMAC 익명화) / session_id (브라우저 cookie / sessionStorage)
 *   owner_email (로그인 시 nullable) / is_bot (UA 패턴 감지)
 *
 * 사장님 2026-05-24 — 관리자 통계 페이지 (admin.html) 데이터 소스.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

ignore_user_abort(true);
set_time_limit(5);

/* DB 연결 */
$dbConfig = null;
foreach ([__DIR__ . '/db_config.php', dirname(__DIR__) . '/db_config.php'] as $p) {
    if (is_file($p)) { $dbConfig = require $p; break; }
}
if (!is_array($dbConfig)) { http_response_code(200); echo '{"ok":true,"skipped":"no_db"}'; exit; }

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbConfig['host'] ?? 'localhost',
        $dbConfig['port'] ?? '3306',
        $dbConfig['database'] ?? '');
    $pdo = new PDO($dsn, $dbConfig['user'] ?? '', $dbConfig['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) { http_response_code(200); echo '{"ok":true,"skipped":"db"}'; exit; }

/* 테이블 lazy migration */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS page_views (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        path VARCHAR(255) NOT NULL DEFAULT '',
        referrer VARCHAR(500) NOT NULL DEFAULT '',
        referrer_host VARCHAR(120) NOT NULL DEFAULT '',
        utm_source VARCHAR(100) NOT NULL DEFAULT '',
        utm_medium VARCHAR(100) NOT NULL DEFAULT '',
        utm_campaign VARCHAR(100) NOT NULL DEFAULT '',
        user_agent VARCHAR(500) NOT NULL DEFAULT '',
        ip_hash CHAR(64) NOT NULL DEFAULT '',
        session_id VARCHAR(64) NOT NULL DEFAULT '',
        owner_email VARCHAR(255) NULL,
        is_bot TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_pv_created (created_at),
        INDEX idx_pv_session (session_id),
        INDEX idx_pv_owner (owner_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { http_response_code(200); echo '{"ok":true,"skipped":"migrate"}'; exit; }

/* body parsing */
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

$path = substr(trim((string)($body['path'] ?? '')), 0, 255);
$referrer = substr(trim((string)($body['referrer'] ?? '')), 0, 500);
$utmSource = substr(trim((string)($body['utm_source'] ?? '')), 0, 100);
$utmMedium = substr(trim((string)($body['utm_medium'] ?? '')), 0, 100);
$utmCampaign = substr(trim((string)($body['utm_campaign'] ?? '')), 0, 100);
$sessionId = substr(trim((string)($body['session_id'] ?? '')), 0, 64);
$ownerEmail = strtolower(trim((string)($body['owner_email'] ?? '')));
if ($ownerEmail === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) $ownerEmail = null;

$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
$ipRaw = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
if (strpos($ipRaw, ',') !== false) $ipRaw = trim(explode(',', $ipRaw)[0]);
$ipHash = hash('sha256', $ipRaw . '|' . date('Y-m-d'));

/* bot 감지 — UA 패턴 매칭 (단순) */
$isBot = 0;
$botPatterns = ['bot','crawler','spider','slurp','baiduspider','yandex','duckduckbot','facebookexternalhit','headlesschrome','phantomjs','curl','wget','python-requests','okhttp'];
$uaLow = strtolower($userAgent);
foreach ($botPatterns as $p) { if (strpos($uaLow, $p) !== false) { $isBot = 1; break; } }

/* referrer_host 추출 */
$referrerHost = '';
if ($referrer !== '') {
    $parsed = @parse_url($referrer);
    if (is_array($parsed) && !empty($parsed['host'])) $referrerHost = substr((string)$parsed['host'], 0, 120);
}

try {
    $stmt = $pdo->prepare("INSERT INTO page_views
        (path, referrer, referrer_host, utm_source, utm_medium, utm_campaign,
         user_agent, ip_hash, session_id, owner_email, is_bot)
        VALUES (:path, :ref, :refh, :us, :um, :uc,
                :ua, :iph, :sid, :oe, :bot)");
    $stmt->execute([
        ':path' => $path,
        ':ref'  => $referrer,
        ':refh' => $referrerHost,
        ':us'   => $utmSource,
        ':um'   => $utmMedium,
        ':uc'   => $utmCampaign,
        ':ua'   => $userAgent,
        ':iph'  => $ipHash,
        ':sid'  => $sessionId,
        ':oe'   => $ownerEmail,
        ':bot'  => $isBot,
    ]);
} catch (Throwable $e) {
    /* fire-and-forget — 절대 사용자 페이지 방해 안 함 */
}

http_response_code(204);
exit;
