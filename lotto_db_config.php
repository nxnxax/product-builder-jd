<?php
/**
 * lotto_db_config.php
 * lotto_db_import.php 와 api/lotto-recommend.php 가 공유하는 DB 설정.
 *
 * 사용법
 *  1) 카페24 MySQL 정보를 아래에 채워넣고 FTP 로 서버에 업로드
 *  2) lotto_db_import.php 를 한번 열어 DB 테이블 생성 / 전체 회차 적재
 *  3) 이후엔 매주 lotto_db_import.php 의 "최신 회차만 업데이트" 버튼만 눌러주면 됨
 */

declare(strict_types=1);

if (!defined('LOTTO_DB_HOST')) {
    define('LOTTO_DB_HOST',    'localhost');
    define('LOTTO_DB_NAME',    '여기에_DB명');
    define('LOTTO_DB_USER',    '여기에_DB아이디');
    define('LOTTO_DB_PASS',    '여기에_DB비밀번호');
    define('LOTTO_DB_CHARSET', 'utf8mb4');
}

function lotto_db(): PDO {
    $dsn = 'mysql:host=' . LOTTO_DB_HOST
         . ';dbname=' . LOTTO_DB_NAME
         . ';charset=' . LOTTO_DB_CHARSET;

    return new PDO($dsn, LOTTO_DB_USER, LOTTO_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
}
