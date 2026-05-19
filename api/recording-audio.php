<?php
/**
 * recording-audio.php — Railway worker 가 cafe24 의 audio 파일 다운로드하는 endpoint.
 *
 * 호출: GET /recording-audio.php?job_id=xxx&token=YYY&expires=TS
 *
 * token = HMAC-SHA256(job_id + '.' + expires_ts, RECORDING_WORKER_TOKEN)
 *   - cafe24 의 process-recording.php 가 Railway 호출 시 생성
 *   - 만료 시각 (expires) 10분 안에만 유효
 *   - hash_equals 로 비교 (timing attack 방지)
 *
 * 응답: audio 파일 binary stream (Content-Type 은 확장자 기준)
 */

declare(strict_types=1);

function ra_jerror(string $msg, int $http = 400): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function ra_load_env(string $key): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $f = __DIR__ . '/.env';
        if (is_file($f)) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos($line, '=') === false || $line[0] === '#') continue;
                [$k, $v] = explode('=', $line, 2);
                $cache[trim($k)] = trim($v);
            }
        }
    }
    return $cache[$key] ?? '';
}

$jobId   = trim((string)($_GET['job_id'] ?? ''));
$token   = trim((string)($_GET['token'] ?? ''));
$expires = (int)($_GET['expires'] ?? 0);
if ($jobId === '' || $token === '' || $expires <= 0) {
    ra_jerror('필수 파라미터 누락.', 400);
}
if (time() > $expires) ra_jerror('signed URL 만료.', 410);

$secret = ra_load_env('RECORDING_WORKER_TOKEN');
if ($secret === '') ra_jerror('RECORDING_WORKER_TOKEN 미설정.', 503);

$expectedToken = hash_hmac('sha256', $jobId . '.' . $expires, $secret);
if (!hash_equals($expectedToken, $token)) ra_jerror('Invalid signature.', 403);

/* DB 연결 후 storage_path 조회 */
$dbConfigCandidates = [__DIR__ . '/db_config.php', dirname(__DIR__) . '/db_config.php'];
$dbConfig = null;
foreach ($dbConfigCandidates as $p) {
    if (is_file($p)) { $dbConfig = require $p; break; }
}
if (!is_array($dbConfig)) ra_jerror('db_config.php 없음.', 503);

try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbConfig['host'] ?? 'localhost',
        $dbConfig['port'] ?? '3306',
        $dbConfig['database'] ?? '');
    $pdo = new PDO($dsn, $dbConfig['user'] ?? '', $dbConfig['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    ra_jerror('DB 연결 실패.', 503);
}

try {
    $ps = $pdo->prepare("SELECT storage_path, owner_email FROM recording_jobs WHERE id = :id LIMIT 1");
    $ps->execute([':id' => $jobId]);
    $row = $ps->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    ra_jerror('조회 실패.', 503);
}
if (!$row) ra_jerror('job not found.', 404);

$storagePath = (string)$row['storage_path'];
if ($storagePath === '') ra_jerror('storage_path 없음.', 404);

/* storage_path 안전성 검증 (경로 traversal 차단) */
if (strpos($storagePath, '..') !== false || strpos($storagePath, "\0") !== false) {
    ra_jerror('storage_path 형식 오류.', 422);
}

$absPath = __DIR__ . '/' . $storagePath;
$realPath = @realpath($absPath);
if (!$realPath || !is_file($realPath)) ra_jerror('audio 파일 없음.', 404);

$uploadsReal = @realpath(__DIR__ . '/uploads');
if (!$uploadsReal || strpos($realPath, $uploadsReal . DIRECTORY_SEPARATOR) !== 0) {
    ra_jerror('audio 경로가 uploads 외부.', 422);
}

/* 파일 stream */
$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mimeMap = [
    'm4a' => 'audio/mp4',   'mp4' => 'audio/mp4',  'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',   'webm'=> 'audio/webm', 'ogg' => 'audio/ogg',
    'flac'=> 'audio/flac',  '3gp' => 'audio/3gpp', '3gpp'=> 'audio/3gpp',
    'aac' => 'audio/aac',   'amr' => 'audio/amr',  'opus'=> 'audio/ogg',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: no-store');

readfile($realPath);
exit;
