<?php
/**
 * install-ping.php — 영맨앱 설치 1회 핑 (광고 설치 추적용).
 *
 *  POST /install-ping.php
 *  body: { device_id(필수), android_id?, install_referrer?(base64), user_agent? }
 *  resp: { ok:true, duplicate:false }   // 같은 device_id 2회 → duplicate:true
 *
 * 원칙(앱팀 spec):
 *  - 인증 불필요(첫 실행=미가입). 실패해도 앱 영향 0 → 항상 HTTP 200.
 *  - device_id UNIQUE 로 silent dedup (최초 핑 = 설치일로 고정, 재핑 무시).
 *  - 시간대 = Asia/Seoul(KST) 고정. (서버 기본 tz 미설정 환경 대비 명시)
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function ip_out(array $p): void { http_response_code(200); echo json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { ip_out(['ok' => true, 'skipped' => 'method']); }

/* DB 연결 */
$dbConfig = null;
foreach ([__DIR__ . '/db_config.php', dirname(__DIR__) . '/db_config.php'] as $p) {
    if (is_file($p)) { $dbConfig = require $p; break; }
}
if (!is_array($dbConfig)) ip_out(['ok' => true, 'skipped' => 'no_db']);
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbConfig['host'] ?? 'localhost', $dbConfig['port'] ?? '3306', $dbConfig['database'] ?? ''),
        $dbConfig['user'] ?? '', $dbConfig['password'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    try { $pdo->exec("SET time_zone = '+09:00'"); } catch (Throwable $e) {}   // KST 세션 (NOW/CURRENT_TIMESTAMP)
} catch (Throwable $e) { ip_out(['ok' => true, 'skipped' => 'db']); }

/* 테이블 lazy migration */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS install_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(64) NOT NULL,
        android_id VARCHAR(64) NULL,
        install_referrer TEXT NULL,
        user_agent VARCHAR(255) NULL,
        pinged_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_device (device_id),
        INDEX idx_pinged (pinged_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { ip_out(['ok' => true, 'skipped' => 'migrate']); }

/* body 파싱 */
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

$deviceId = substr(trim((string)($body['device_id'] ?? '')), 0, 64);
$deviceId = preg_replace('/[^A-Za-z0-9_\-]/', '', $deviceId);   // uuid/식별자 외 문자 제거
if ($deviceId === '') ip_out(['ok' => true, 'duplicate' => false, 'skipped' => 'no_device_id']);

$androidId = substr(trim((string)($body['android_id'] ?? '')), 0, 64);
$androidId = preg_replace('/[^A-Za-z0-9_\-]/', '', $androidId) ?: null;

$refRaw = (string)($body['install_referrer'] ?? '');
$installReferrer = null;
if ($refRaw !== '') {
    $dec = base64_decode($refRaw, true);          // base64 면 디코드, 아니면 raw 보존
    $installReferrer = substr(($dec !== false && $dec !== '') ? $dec : $refRaw, 0, 1000);
}
$ua = substr(trim((string)($body['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''))), 0, 255) ?: null;
$now = date('Y-m-d H:i:s');   // KST

/* INSERT IGNORE → 최초 1건만(설치일 고정). rowCount: 1=신규, 0=중복 */
$duplicate = false;
try {
    $st = $pdo->prepare("INSERT IGNORE INTO install_log
        (device_id, android_id, install_referrer, user_agent, pinged_at)
        VALUES (:d, :a, :r, :u, :t)");
    $st->execute([':d' => $deviceId, ':a' => $androidId, ':r' => $installReferrer, ':u' => $ua, ':t' => $now]);
    $duplicate = ($st->rowCount() === 0);
} catch (Throwable $e) {
    error_log('[install-ping] insert: ' . $e->getMessage());
    ip_out(['ok' => true, 'duplicate' => false, 'skipped' => 'write']);
}

ip_out(['ok' => true, 'duplicate' => $duplicate]);
