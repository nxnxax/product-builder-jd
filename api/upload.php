<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function jout(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ========== Supabase 인증 (records.php 와 동일한 hydrate 로직) ========== */
function load_supabase_auth(): array {
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
        $jsPath = $dir . '/supabase_config.js';
        if (is_file($jsPath)) {
            $contents = (string)file_get_contents($jsPath);
            if (empty($auth['supabase_url']) && preg_match('/SUPABASE_URL\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $m)) {
                $auth['supabase_url'] = preg_replace('#/(rest|auth)/v1/?.*$#', '', $m[1]);
            }
            if (empty($auth['anon_key']) && preg_match('/SUPABASE_ANON_KEY\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $m)) {
                $auth['anon_key'] = $m[1];
            }
        }
    }
    return $auth;
}

function get_bearer_token(): string {
    // 1. 표준 $_SERVER (가장 흔한 경로).
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    // 2. Apache 직접 헤더 — cafe24 같은 일부 환경에서 PHP $_SERVER 에 누락될 때 fallback.
    if ($h === '' && function_exists('apache_request_headers')) {
        $req = @apache_request_headers();
        if (is_array($req)) {
            foreach ($req as $k => $v) {
                if (strcasecmp((string)$k, 'authorization') === 0) { $h = (string)$v; break; }
            }
        }
    }
    if ($h === '' && function_exists('getallheaders')) {
        $req = @getallheaders();
        if (is_array($req)) {
            foreach ($req as $k => $v) {
                if (strcasecmp((string)$k, 'authorization') === 0) { $h = (string)$v; break; }
            }
        }
    }
    // 3. multipart 요청 시 X-Auth-Token 백업 헤더 (클라이언트가 보조로 보냄).
    if ($h === '') {
        $alt = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
        if ($alt !== '') return trim($alt);
    }
    // 4. 마지막 fallback: 토큰을 POST/GET 으로 보내는 경우 (multipart 우회).
    if ($h === '') {
        $alt = (string)($_POST['_token'] ?? $_GET['_token'] ?? '');
        if ($alt !== '') return trim($alt);
    }
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return '';
}

function fetch_user_email_via_supabase(string $token, array $auth): string {
    $url = rtrim((string)($auth['supabase_url'] ?? ''), '/');
    $key = (string)($auth['anon_key'] ?? '');
    if (!$url || !$key || !$token) return '';
    $ch = curl_init($url . '/auth/v1/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'apikey: ' . $key,
        ],
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200 || !$resp) return '';
    $data = json_decode((string)$resp, true);
    return strtolower(trim((string)($data['email'] ?? '')));
}

function require_auth_email(): string {
    $token = get_bearer_token();
    if (!$token) {
        jout([
            'ok' => false,
            'error' => '로그인이 필요합니다. (Authorization 헤더가 도달하지 않았어요. 페이지 새로고침 후 다시 시도해주세요.)',
            'debug' => 'no_token',
        ], 401);
    }
    $auth = load_supabase_auth();
    if (empty($auth['supabase_url']) || empty($auth['anon_key'])) {
        jout(['ok' => false, 'error' => '서버 인증 설정이 누락됐습니다. 운영자에게 알려주세요.', 'debug' => 'missing_config'], 500);
    }
    $email = fetch_user_email_via_supabase($token, $auth);
    if (!$email) {
        jout([
            'ok' => false,
            'error' => '토큰 검증 실패. 잠시 후 다시 시도하거나 다시 로그인해주세요.',
            'debug' => 'token_rejected',
        ], 401);
    }
    return $email;
}

/* ========== 사용자별 디렉토리 격리 ========== */
function user_dir_segment(string $email): string {
    return 'u_' . substr(hash('sha256', strtolower(trim($email))), 0, 16);
}

function ensure_user_dir(string $rootDir, string $segment): string {
    $dir = $rootDir . '/' . $segment;
    if (!is_dir($rootDir)) {
        if (!@mkdir($rootDir, 0755, true) && !is_dir($rootDir)) {
            jout(['ok' => false, 'error' => '업로드 폴더 생성 실패'], 500);
        }
    }
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            jout(['ok' => false, 'error' => '사용자 폴더 생성 실패'], 500);
        }
    }
    // 디렉토리 인덱싱 차단 + 실행 가능 파일 차단 (uploads root 의 .htaccess 가 상속되지만 방어).
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht,
            "Options -Indexes\n"
            . "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh|inc|env)$\">\n"
            . "  Require all denied\n"
            . "</FilesMatch>\n"
        );
    }
    return $dir;
}

/* ========== 메인 ========== */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uploadsDir = __DIR__ . '/uploads';

$allowedImage = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
$allowedVideo = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
$allowedDoc   = [
    'application/pdf' => 'pdf',
    'application/zip' => 'zip',
    'application/x-zip-compressed' => 'zip',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'text/plain' => 'txt',
    'text/csv' => 'csv',
    'application/octet-stream' => null,  // 일반 바이너리는 확장자로 따로 검사
];
$allowed = $allowedImage + $allowedVideo + $allowedDoc;
$listExts = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'webm', 'mov',
             'pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv'];

$action = strtolower(trim((string)($_GET['action'] ?? '')));

if ($method === 'OPTIONS') jout(['ok' => true]);

$email = require_auth_email();
$userSeg = user_dir_segment($email);
$userDir = ensure_user_dir($uploadsDir, $userSeg);

/* === DELETE === */
if ($method === 'DELETE' || $action === 'delete') {
    $name = (string)($_GET['name'] ?? $_POST['name'] ?? '');
    $name = basename($name);
    if ($name === '' || $name === '.' || $name === '..' || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
        jout(['ok' => false, 'error' => '파일명이 올바르지 않습니다.'], 400);
    }
    if ($name[0] === '.') jout(['ok' => false, 'error' => '숨김 파일은 삭제할 수 없습니다.'], 400);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $listExts, true)) jout(['ok' => false, 'error' => '허용되지 않는 파일 종류입니다.'], 400);

    $path = $userDir . '/' . $name;
    $real = @realpath($path);
    $rootReal = @realpath($userDir);
    if (!$real || !$rootReal || strpos($real, $rootReal . DIRECTORY_SEPARATOR) !== 0) {
        jout(['ok' => false, 'error' => '파일을 찾을 수 없습니다.'], 404);
    }
    if (!@unlink($real)) jout(['ok' => false, 'error' => '삭제 실패'], 500);
    jout(['ok' => true, 'deleted' => $name]);
}

/* === LIST (자기 디렉토리만) === */
if ($method === 'GET') {
    $files = [];
    if (is_dir($userDir)) {
        $entries = @scandir($userDir) ?: [];
        foreach ($entries as $f) {
            if ($f === '.' || $f === '..' || $f[0] === '.') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext, $listExts, true)) continue;
            $p = $userDir . '/' . $f;
            if (!is_file($p)) continue;
            $isVideo = in_array($ext, ['mp4', 'webm', 'mov'], true);
            $isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
            $kind = $isVideo ? 'video' : ($isImage ? 'image' : 'doc');
            $files[] = [
                'name' => $f,
                'mtime' => filemtime($p),
                'size' => filesize($p),
                'kind' => $kind,
                'ext' => $ext,
                'url' => '/uploads/' . rawurlencode($userSeg) . '/' . rawurlencode($f),
            ];
        }
        usort($files, function($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    }
    jout(['ok' => true, 'files' => array_slice($files, 0, 100), 'userSeg' => $userSeg]);
}

if ($method !== 'POST') jout(['ok' => false, 'error' => 'method not allowed'], 405);

/* === UPLOAD === */
$file = null;
if (!empty($_FILES['file']) && is_array($_FILES['file'])) $file = $_FILES['file'];
elseif (!empty($_FILES['image']) && is_array($_FILES['image'])) $file = $_FILES['image'];

if (!$file) jout(['ok' => false, 'error' => '"file" 필드에 파일이 없습니다.'], 400);
if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    jout(['ok' => false, 'error' => '업로드 에러 (코드: ' . (int)$file['error'] . ')'], 400);
}

$mime = '';
if (function_exists('mime_content_type')) {
    $detected = @mime_content_type($file['tmp_name']);
    if (is_string($detected)) $mime = strtolower($detected);
}
if ($mime === '' && function_exists('finfo_open')) {
    $fi = @finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
        $detected = @finfo_file($fi, $file['tmp_name']);
        if (is_string($detected)) $mime = strtolower($detected);
        @finfo_close($fi);
    }
}

// 클라이언트 파일명에서 확장자 fallback (octet-stream 케이스 대응).
$origExt = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));

$ext = null;
if (isset($allowed[$mime])) {
    $ext = $allowed[$mime];
}
if ($ext === null && $origExt && in_array($origExt, $listExts, true)) {
    $ext = $origExt;   // octet-stream 인 경우 확장자로 결정
}
if (!$ext) {
    jout(['ok' => false, 'error' => '지원하지 않는 형식입니다 (' . ($mime ?: 'unknown') . ').'], 400);
}

$isVideo = isset($allowedVideo[$mime]) || in_array($ext, ['mp4', 'webm', 'mov'], true);
$isImage = isset($allowedImage[$mime]) || in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
$isDoc   = !$isVideo && !$isImage;

$maxBytes = $isVideo ? 100 * 1024 * 1024 : ($isImage ? 20 * 1024 * 1024 : 50 * 1024 * 1024);
if ((int)$file['size'] > $maxBytes) {
    jout(['ok' => false, 'error' => '파일이 너무 큽니다. (이미지 ≤ 20MB · 동영상 ≤ 100MB · 일반 파일 ≤ 50MB)'], 400);
}

// uploads root 에 .htaccess 보강 (없으면 생성).
$rootHt = $uploadsDir . '/.htaccess';
if (!is_file($rootHt)) {
    @file_put_contents($rootHt,
        "Options -Indexes\n"
        . "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh|inc|env)$\">\n"
        . "  Require all denied\n"
        . "</FilesMatch>\n"
        . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
        . "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n"
        . "<IfModule mod_php8.c>\n  php_flag engine off\n</IfModule>\n"
    );
}

try {
    $rand = bin2hex(random_bytes(5));
} catch (Throwable $e) {
    $rand = substr(sha1(uniqid('', true)), 0, 10);
}
$basename = date('Ymd-His') . '-' . $rand . '.' . $ext;
$dest = $userDir . '/' . $basename;

if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    jout(['ok' => false, 'error' => '파일 저장 실패'], 500);
}
@chmod($dest, 0644);

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'youngman-biz.com';
$publicUrl = $proto . '://' . $host . '/uploads/' . rawurlencode($userSeg) . '/' . rawurlencode($basename);

jout([
    'ok' => true,
    'url' => $publicUrl,
    'name' => $basename,
    'size' => (int)$file['size'],
    'mime' => $mime,
    'ext' => $ext,
    'kind' => $isVideo ? 'video' : ($isImage ? 'image' : 'doc'),
]);
