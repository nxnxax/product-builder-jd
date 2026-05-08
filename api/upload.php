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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uploadsDir = __DIR__ . '/uploads';

$allowedImage = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
$allowedVideo = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
$allowed = $allowedImage + $allowedVideo;
$listExts = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'webm', 'mov'];

$action = strtolower(trim((string)($_GET['action'] ?? '')));

if ($method === 'OPTIONS') jout(['ok' => true]);

// === DELETE ===
if ($method === 'DELETE' || $action === 'delete') {
    $name = (string)($_GET['name'] ?? $_POST['name'] ?? '');
    $name = basename($name); // strip any path components
    if ($name === '' || $name === '.' || $name === '..' || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
        jout(['ok' => false, 'error' => '파일명이 올바르지 않습니다.'], 400);
    }
    if ($name[0] === '.') {
        jout(['ok' => false, 'error' => '숨김 파일은 삭제할 수 없습니다.'], 400);
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $listExts, true)) {
        jout(['ok' => false, 'error' => '허용되지 않는 파일 종류입니다.'], 400);
    }
    $path = $uploadsDir . '/' . $name;
    $real = @realpath($path);
    $rootReal = @realpath($uploadsDir);
    if (!$real || !$rootReal || strpos($real, $rootReal . DIRECTORY_SEPARATOR) !== 0) {
        jout(['ok' => false, 'error' => '파일을 찾을 수 없습니다.'], 404);
    }
    if (!@unlink($real)) {
        jout(['ok' => false, 'error' => '삭제 실패 (권한 또는 파일 잠김 가능).'], 500);
    }
    jout(['ok' => true, 'deleted' => $name]);
}

// === LIST ===
if ($method === 'GET') {
    $files = [];
    if (is_dir($uploadsDir)) {
        $entries = @scandir($uploadsDir) ?: [];
        foreach ($entries as $f) {
            if ($f === '.' || $f === '..' || $f[0] === '.') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext, $listExts, true)) continue;
            $p = $uploadsDir . '/' . $f;
            if (!is_file($p)) continue;
            $isVideo = in_array($ext, ['mp4', 'webm', 'mov'], true);
            $files[] = [
                'name' => $f,
                'mtime' => filemtime($p),
                'size' => filesize($p),
                'kind' => $isVideo ? 'video' : 'image',
            ];
        }
        usort($files, function($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    }
    jout(['ok' => true, 'files' => array_slice($files, 0, 60)]);
}

if ($method !== 'POST') jout(['ok' => false, 'error' => 'method not allowed'], 405);

// === UPLOAD ===
// Accept either "file" (new) or "image" (legacy) field for backward compat.
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
if (!isset($allowed[$mime])) {
    jout(['ok' => false, 'error' => '지원하지 않는 형식입니다 (' . ($mime ?: 'unknown') . '). PNG/JPG/GIF/WebP/MP4/WebM/MOV만 가능.'], 400);
}

$isVideo = isset($allowedVideo[$mime]);
$maxBytes = $isVideo ? 30 * 1024 * 1024 : 10 * 1024 * 1024;
if ((int)$file['size'] > $maxBytes) {
    jout(['ok' => false, 'error' => '파일이 너무 큽니다. ' . ($isVideo ? '동영상은 최대 30MB' : '이미지는 최대 10MB')], 400);
}

$ext = $allowed[$mime];

if (!is_dir($uploadsDir)) {
    if (!@mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
        jout(['ok' => false, 'error' => '업로드 폴더 생성 실패'], 500);
    }
}

$ht = $uploadsDir . '/.htaccess';
if (!is_file($ht)) {
    $rules = "Options -Indexes\n"
           . "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh|inc|env)$\">\n"
           . "  Require all denied\n"
           . "</FilesMatch>\n"
           . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
           . "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n"
           . "<IfModule mod_php8.c>\n  php_flag engine off\n</IfModule>\n";
    @file_put_contents($ht, $rules);
}

try {
    $rand = bin2hex(random_bytes(5));
} catch (Throwable $e) {
    $rand = substr(sha1(uniqid('', true)), 0, 10);
}
$basename = date('Ymd-His') . '-' . $rand . '.' . $ext;
$dest = $uploadsDir . '/' . $basename;

if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    jout(['ok' => false, 'error' => '파일 저장 실패'], 500);
}
@chmod($dest, 0644);

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'youngman-biz.com';
$publicUrl = $proto . '://' . $host . '/uploads/' . $basename;

jout([
    'ok' => true,
    'url' => $publicUrl,
    'name' => $basename,
    'size' => (int)$file['size'],
    'mime' => $mime,
    'kind' => $isVideo ? 'video' : 'image',
]);
