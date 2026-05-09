<?php
/**
 * lotto_db_config.php
 * lotto_db_import.php 와 lotto-recommend.php 가 공유하는 MySQL 연결 모듈.
 *
 * 자격증명 우선순위
 *  1) 환경변수 (LOTTO_DB_HOST / LOTTO_DB_NAME / LOTTO_DB_USER / LOTTO_DB_PASS)
 *  2) 같은 디렉터리 또는 상위 디렉터리의 .env 파일 (배포 워크플로우가 GitHub Secrets 로부터 채움)
 *  3) (개발용) 아래 fallback 상수
 *
 * 카페24 운영 시 권장 흐름
 *   - GitHub Secrets 에 LOTTO_DB_HOST / LOTTO_DB_NAME / LOTTO_DB_USER / LOTTO_DB_PASS 저장
 *   - .github/workflows/deploy.yml 이 자동으로 서버 .env 에 주입
 *   - 이 파일은 자격증명을 담지 않으므로 git/공개 저장소에 안전하게 포함됨
 */

declare(strict_types=1);

if (!function_exists('lotto_env')) {
    function lotto_env(string $key, string $default = ''): string {
        $v = getenv($key);
        if ($v !== false && $v !== '') return (string)$v;

        static $cached = null;
        if ($cached === null) {
            $cached = [];
            foreach ([__DIR__ . '/.env', dirname(__DIR__) . '/.env'] as $p) {
                if (!is_file($p)) continue;
                foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $m)) {
                        $cached[strtoupper($m[1])] = trim($m[2], "\"' \t\r\n");
                    }
                }
            }
        }
        return $cached[strtoupper($key)] ?? $default;
    }
}

if (!defined('LOTTO_DB_HOST')) {
    define('LOTTO_DB_HOST',    lotto_env('LOTTO_DB_HOST', 'localhost'));
    define('LOTTO_DB_NAME',    lotto_env('LOTTO_DB_NAME', ''));
    define('LOTTO_DB_USER',    lotto_env('LOTTO_DB_USER', ''));
    define('LOTTO_DB_PASS',    lotto_env('LOTTO_DB_PASS', ''));
    define('LOTTO_DB_CHARSET', lotto_env('LOTTO_DB_CHARSET', 'utf8mb4'));
}

if (!function_exists('lotto_db')) {
    function lotto_db(): PDO {
        if (LOTTO_DB_NAME === '' || LOTTO_DB_USER === '') {
            throw new RuntimeException(
                'DB 자격증명이 비어있습니다. GitHub Secrets 에 LOTTO_DB_HOST / LOTTO_DB_NAME / LOTTO_DB_USER / LOTTO_DB_PASS 를 설정한 뒤 재배포하세요.'
            );
        }
        $dsn = 'mysql:host=' . LOTTO_DB_HOST
             . ';dbname=' . LOTTO_DB_NAME
             . ';charset=' . LOTTO_DB_CHARSET;

        return new PDO($dsn, LOTTO_DB_USER, LOTTO_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
    }
}
