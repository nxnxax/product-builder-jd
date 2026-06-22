<?php
/**
 * guide-video.php — import-guide.html 안내 영상 (관리자 전용 업로드 + 공개 조회).
 *
 *  GET  /guide-video.php            → { ok, exists, url, version }  (공개)
 *  POST /guide-video.php            → 관리자 토큰 + multipart(video) → 저장. (관리자 전용)
 *
 * 보안:
 *  - 업로드는 Supabase 토큰 검증 후 관리자 이메일(nxnxax@gmail.com)만 허용.
 *  - 저장 파일명은 고정(import-guide.mp4) → 사용자 입력 파일명 미사용 = path traversal 불가.
 *  - finfo 로 실제 MIME 검증(mp4/webm/mov 만). 80MB 상한. media/ 에 .htaccess 로 스크립트 실행 차단.
 *  - 1080×1920 세로 영상 권장 (import-guide.html 이 9:16 object-fit:cover 로 꽉 차게 표시).
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function gv_out(array $p, int $c = 200): void {
    http_response_code($c);
    echo json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$MEDIA_DIR  = __DIR__ . '/media';
$VIDEO_PATH = $MEDIA_DIR . '/import-guide.mp4';
$PUBLIC_URL = '/media/import-guide.mp4';            // 웹루트 기준 절대경로
$ADMIN_EMAILS = ['nxnxax@gmail.com'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ── GET: 현재 영상 정보 (공개) ── */
if ($method === 'GET') {
    $exists = is_file($VIDEO_PATH);
    $mt = $exists ? (int)@filemtime($VIDEO_PATH) : 0;
    gv_out([
        'ok'      => true,
        'exists'  => $exists,
        'url'     => $exists ? ($PUBLIC_URL . '?v=' . $mt) : null,
        'version' => $mt,
    ]);
}

if ($method !== 'POST') gv_out(['ok' => false, 'error' => 'method_not_allowed'], 405);

/* ── POST 상한 초과 감지 (php.ini post_max_size 초과 시 $_FILES/$_POST 비어버림) ── */
$contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLen > 0 && empty($_FILES) && empty($_POST)) {
    gv_out(['ok' => false, 'error' => 'too_large',
            'message' => '서버 업로드 한도를 초과했습니다. 영상을 더 짧게/작게(권장 50MB 이하) 만들어 다시 시도하세요.'], 413);
}

/* ── Supabase 토큰 검증 → email (upload.php 와 동일 패턴) ── */
function gv_load_supabase_auth(): array {
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
    }
    return $auth;
}

function gv_bearer_token(): string {
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach ((array)@apache_request_headers() as $k => $v) {
            if (strcasecmp((string)$k, 'authorization') === 0) { $h = (string)$v; break; }
        }
    }
    if ($h === '' && function_exists('getallheaders')) {
        foreach ((array)@getallheaders() as $k => $v) {
            if (strcasecmp((string)$k, 'authorization') === 0) { $h = (string)$v; break; }
        }
    }
    if ($h === '') $h = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return trim($h);
}

$token = gv_bearer_token();
if ($token === '') gv_out(['ok' => false, 'error' => 'unauthorized', 'message' => '로그인이 필요합니다.'], 401);

$auth = gv_load_supabase_auth();
$base = !empty($auth['supabase_url']) ? rtrim((string)$auth['supabase_url'], '/') : '';
$anon = (string)($auth['anon_key'] ?? '');
if ($base === '' || $anon === '') gv_out(['ok' => false, 'error' => 'config', 'message' => '서버 인증 설정 누락.'], 500);

$ch = curl_init($base . '/auth/v1/user');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'apikey: ' . $anon],
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$resp   = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($status !== 200 || !$resp) gv_out(['ok' => false, 'error' => 'unauthorized', 'message' => '토큰 검증 실패. 다시 로그인하세요.'], 401);
$u = json_decode((string)$resp, true);
$email = strtolower(trim((string)($u['email'] ?? '')));
if ($email === '') gv_out(['ok' => false, 'error' => 'unauthorized', 'message' => '토큰에서 이메일 추출 실패.'], 401);

if (!in_array($email, $ADMIN_EMAILS, true)) {
    gv_out(['ok' => false, 'error' => 'forbidden', 'message' => '관리자만 영상을 업로드할 수 있습니다.'], 403);
}

/* ── 파일 검증 ── */
if (empty($_FILES['video']) || ($_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $err = (int)($_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE);
    $msg = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        ? '영상 크기가 서버 한도를 초과했습니다. (권장 50MB 이하)'
        : '영상 파일이 없습니다.';
    gv_out(['ok' => false, 'error' => 'no_file', 'message' => $msg], 400);
}
$f = $_FILES['video'];
$maxBytes = 80 * 1024 * 1024;
if (($f['size'] ?? 0) <= 0 || $f['size'] > $maxBytes) {
    gv_out(['ok' => false, 'error' => 'too_large', 'message' => '영상은 80MB 이하만 업로드할 수 있습니다.'], 413);
}
$mime = '';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) { $mime = (string)finfo_file($fi, $f['tmp_name']); finfo_close($fi); }
}
if ($mime === '') $mime = (string)($f['type'] ?? '');
$allowed = ['video/mp4', 'video/webm', 'video/quicktime'];
if (!in_array($mime, $allowed, true)) {
    gv_out(['ok' => false, 'error' => 'bad_type', 'message' => 'mp4 / webm 영상만 업로드할 수 있습니다. (감지된 형식: ' . $mime . ')'], 415);
}

/* ── 저장 (고정 파일명, 원자적 교체) ── */
if (!is_dir($MEDIA_DIR)) {
    if (!@mkdir($MEDIA_DIR, 0755, true) && !is_dir($MEDIA_DIR)) {
        gv_out(['ok' => false, 'error' => 'mkdir_fail', 'message' => 'media 폴더 생성 실패.'], 500);
    }
}
$ht = $MEDIA_DIR . '/.htaccess';
if (!is_file($ht)) {
    @file_put_contents($ht,
        "Options -Indexes\n"
        . "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh|inc|env|html?)$\">\n"
        . "  Require all denied\n"
        . "</FilesMatch>\n");
}
$tmpDest = $VIDEO_PATH . '.tmp';
if (!@move_uploaded_file($f['tmp_name'], $tmpDest)) {
    gv_out(['ok' => false, 'error' => 'save_fail', 'message' => '저장 실패. 잠시 후 다시 시도하세요.'], 500);
}
if (!@rename($tmpDest, $VIDEO_PATH)) { @unlink($tmpDest); gv_out(['ok' => false, 'error' => 'save_fail', 'message' => '영상 교체 실패.'], 500); }
@chmod($VIDEO_PATH, 0644);

$v = (int)@filemtime($VIDEO_PATH);
gv_out(['ok' => true, 'url' => $PUBLIC_URL . '?v=' . $v, 'version' => $v, 'message' => '업로드 완료']);
