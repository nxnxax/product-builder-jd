<?php
/**
 * audio_cleanup.php — 24h 미정리 audio 파일 cron cleanup.
 *
 * Phase 2 M4. process-recording.php 가 정상 종료 시 audio 즉시 unlink 하지만,
 * fail/timeout/fatal 케이스에서 disk 에 잔존하는 audio 파일을 정기 정리.
 *
 * 호출:
 *   GET /audio_cleanup.php?token=<AUDIO_CLEANUP_TOKEN>
 *   GET /audio_cleanup.php (Authorization: Bearer <AUDIO_CLEANUP_TOKEN>)
 *   옵션:
 *     ?dry_run=1            — 삭제 안 하고 listing 만 반환
 *     ?max_age_hours=24     — 이 시간 이상 된 파일만 (default 24)
 *     ?max_files=1000       — 한 호출에서 최대 처리 파일 수 (default 1000)
 *
 * 인증: AUDIO_CLEANUP_TOKEN 비교 (cafe24 cron 이 비밀 token 으로 호출).
 *
 * 보존 규칙:
 *   - customer_log.audio_kept = 1 인 row 의 audio_storage_path 와 매칭되면 skip
 *   - mtime 이 cutoff (now - max_age_hours) 보다 최근이면 skip
 *
 * cafe24 cron 등록 예시 (운영자 작업):
 *   매일 새벽 4시: 0 4 * * *
 *   명령: curl -sk "https://youngman-biz.com/audio_cleanup.php?token=<TOKEN>"
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

@set_time_limit(300);

function jout(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function load_env_value(string $key): string {
    foreach ([__DIR__, dirname(__DIR__)] as $dir) {
        $path = $dir . '/.env';
        if (!is_file($path)) continue;
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) continue;
        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $m)) {
                if (strcasecmp($m[1], $key) === 0) return trim($m[2], "\"' \t\r\n");
            }
        }
    }
    return '';
}

/* ========== 인증 ========== */
$expectedToken = load_env_value('AUDIO_CLEANUP_TOKEN');
if ($expectedToken === '') {
    jout(['ok' => false, 'error' => 'AUDIO_CLEANUP_TOKEN 미설정 — GitHub Secret 등록 필요.'], 500);
}

$providedToken = '';
$authHdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($authHdr === '' && function_exists('apache_request_headers')) {
    $req = @apache_request_headers();
    if (is_array($req)) {
        foreach ($req as $k => $v) {
            if (strcasecmp((string)$k, 'authorization') === 0) { $authHdr = (string)$v; break; }
        }
    }
}
if (preg_match('/^Bearer\s+(.+)$/i', $authHdr, $m)) $providedToken = trim($m[1]);
if ($providedToken === '') $providedToken = (string)($_GET['token'] ?? $_POST['token'] ?? '');

if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    jout(['ok' => false, 'error' => 'unauthorized'], 401);
}

/* ========== 옵션 ========== */
$dryRun = !empty($_GET['dry_run']);
$maxAgeHours = (int)($_GET['max_age_hours'] ?? 24);
if ($maxAgeHours < 1) $maxAgeHours = 24;
if ($maxAgeHours > 24 * 30) $maxAgeHours = 24 * 30;   // 30일 한도

$maxFiles = (int)($_GET['max_files'] ?? 1000);
if ($maxFiles < 1) $maxFiles = 1000;
if ($maxFiles > 10000) $maxFiles = 10000;

/* ========== DB 연결 (audio_kept 보존 마크 fetch 용) ========== */
$dbConfigPath = __DIR__ . '/db_config.php';
if (!is_file($dbConfigPath)) $dbConfigPath = dirname(__DIR__) . '/db_config.php';
if (!is_file($dbConfigPath)) {
    jout(['ok' => false, 'error' => 'db_config 파일 없음'], 500);
}
$db = require $dbConfigPath;

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'] ?? 'localhost',
            (int)($db['port'] ?? 3306),
            $db['database'] ?? ''),
        $db['user'] ?? '',
        $db['password'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    jout(['ok' => false, 'error' => 'DB 연결 실패: ' . $e->getMessage()], 500);
}

/* ========== 보존된 audio_storage_path 캐시 ========== */
$keptSet = [];
try {
    $keptStmt = $pdo->query("SELECT audio_storage_path FROM customer_log
        WHERE audio_kept = 1 AND audio_storage_path IS NOT NULL AND audio_storage_path != ''");
    foreach ($keptStmt as $r) {
        $p = (string)($r['audio_storage_path'] ?? '');
        if ($p !== '') $keptSet[$p] = true;
    }
} catch (Throwable $e) {
    // customer_log 테이블 없음 — 모든 파일 cleanup 대상.
}

/* ========== uploads/recordings/ 스캔 ========== */
$root = __DIR__ . '/uploads/recordings';
if (!is_dir($root)) {
    jout([
        'ok' => true,
        'scanned' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => [],
        'reason' => 'no_root_dir',
        'root' => $root,
    ]);
}

$cutoff = time() - ($maxAgeHours * 3600);
$audioExts = ['m4a', 'mp4', 'mp3', 'wav', 'ogg', 'opus', 'aac', '3gp', '3gpp', 'amr', 'flac', 'webm', 'mpga', 'oga'];

$scanned = 0;
$deleted = 0;
$skipped = 0;
$errors = [];
$sampleDeleted = [];   // 처음 10개만 응답에 포함 (디버그)

$rootRealPath = (string)@realpath($root);
$webrootReal = (string)@realpath(__DIR__);

try {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
} catch (Throwable $e) {
    jout(['ok' => false, 'error' => '디렉터리 walk 실패: ' . $e->getMessage()], 500);
}

foreach ($iter as $fileInfo) {
    if ($scanned >= $maxFiles) break;
    if (!$fileInfo->isFile()) continue;

    $ext = strtolower($fileInfo->getExtension());
    if (!in_array($ext, $audioExts, true)) continue;

    $scanned++;
    $abs = (string)$fileInfo->getRealPath();
    // path traversal 방어 — webroot 안쪽 확인.
    if ($webrootReal === '' || strpos($abs, $webrootReal . DIRECTORY_SEPARATOR) !== 0) {
        $errors[] = ['path' => $abs, 'reason' => 'outside_webroot'];
        continue;
    }
    $rel = substr($abs, strlen($webrootReal) + 1);
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);

    // 보존 마크
    if (isset($keptSet[$rel])) {
        $skipped++;
        continue;
    }
    // mtime 체크
    if ($fileInfo->getMTime() > $cutoff) {
        $skipped++;
        continue;
    }

    if ($dryRun) {
        $deleted++;
        if (count($sampleDeleted) < 10) $sampleDeleted[] = $rel;
    } else {
        if (@unlink($abs)) {
            $deleted++;
            if (count($sampleDeleted) < 10) $sampleDeleted[] = $rel;
        } else {
            $errors[] = ['path' => $rel, 'reason' => 'unlink_failed'];
        }
    }
}

/* ========== 빈 디렉터리 best-effort 정리 (live mode 만) ========== */
$emptyDirsRemoved = 0;
if (!$dryRun) {
    // 가장 깊은 디렉터리부터 위로 — yyyy-mm-dd → u_<hash>.
    try {
        $dirIter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($dirIter as $entry) {
            if (!$entry->isDir()) continue;
            $dirAbs = (string)$entry->getRealPath();
            $contents = @scandir($dirAbs) ?: [];
            $real = array_values(array_filter($contents, fn($c) => $c !== '.' && $c !== '..' && $c !== '.htaccess'));
            if ($real === []) {
                // .htaccess 있어도 단독이면 같이 제거.
                $ht = $dirAbs . '/.htaccess';
                if (is_file($ht)) @unlink($ht);
                if (@rmdir($dirAbs)) $emptyDirsRemoved++;
            }
        }
    } catch (Throwable $e) {
        // 정리 실패는 무시.
    }
}

jout([
    'ok' => true,
    'mode' => $dryRun ? 'dry_run' : 'live',
    'scanned' => $scanned,
    'deleted' => $deleted,
    'skipped' => $skipped,
    'empty_dirs_removed' => $emptyDirsRemoved,
    'errors' => $errors,
    'sample_deleted' => $sampleDeleted,
    'max_age_hours' => $maxAgeHours,
    'max_files' => $maxFiles,
    'kept_in_db' => count($keptSet),
    'started_at' => date('c'),
    'completed_at' => date('c'),
]);
