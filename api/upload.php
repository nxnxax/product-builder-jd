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
$allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
$listExts = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

if ($method === 'OPTIONS') jout(['ok' => true]);

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
            $files[] = [
                'name' => $f,
                'mtime' => filemtime($p),
                'size' => filesize($p),
            ];
        }
        usort($files, function($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    }
    jout(['ok' => true, 'files' => array_slice($files, 0, 30)]);
}

if ($method !== 'POST') jout(['ok' => false, 'error' => 'method not allowed'], 405);

if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
    jout(['ok' => false, 'error' => '"image" 필드에 파일이 없습니다.'], 400);
}
$file = $_FILES['image'];
if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    jout(['ok' => false, 'error' => '업로드 에러 (코드: ' . (int)$file['error'] . ')'], 400);
}

$maxBytes = 10 * 1024 * 1024;
if ((int)$file['size'] > $maxBytes) {
    jout(['ok' => false, 'error' => '파일이 너무 큽니다. 최대 10MB.'], 400);
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
    jout(['ok' => false, 'error' => '지원하지 않는 형식입니다 (' . ($mime ?: 'unknown') . '). PNG/JPG/GIF/WebP만 가능.'], 400);
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
]);
