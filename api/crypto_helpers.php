<?php
/**
 * crypto_helpers.php — 사용자 데이터 암호화/복호화
 *
 * 보안 정책:
 *  - AES-256-GCM 대칭 암호화 (인증 태그 포함으로 변조 감지)
 *  - 마스터 키는 .env 의 YOUNGMAN_CRYPTO_KEY (GitHub Secret 으로 관리)
 *  - 각 평문에 대해 랜덤 IV (12바이트, GCM 권장 길이)
 *  - DB 저장 형식: "enc:v1:<base64(IV ‖ TAG ‖ CIPHERTEXT)>"
 *  - 'enc:v1:' 접두사 없는 값은 평문으로 간주 → lazy migration 안전망
 *  - 키 미설정 시 암호화 비활성 (개발 환경) — 로깅
 */

/** 마스터 키 로드 — 한 번만 계산 후 캐시 */
function youngman_master_key(): ?string {
    static $cached = null;
    if ($cached !== null) return $cached === '' ? null : $cached;

    $raw = getenv('YOUNGMAN_CRYPTO_KEY') ?: '';

    // .env 폴백: 배포 레이아웃에 따라 __DIR__ 또는 그 부모에 있을 수 있음.
    // cafe24: webroot/.env + webroot/crypto_helpers.php → __DIR__/.env
    // 로컬 dev: api/crypto_helpers.php + 루트/.env → dirname(__DIR__)/.env
    if ($raw === '') {
        $candidates = [__DIR__ . '/.env', dirname(__DIR__) . '/.env'];
        foreach ($candidates as $envPath) {
            if (!file_exists($envPath)) continue;
            $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) continue;
            foreach ($lines as $line) {
                // 양쪽 따옴표/공백/export 접두사도 허용 (records.php 의 .env 파서와 동일).
                if (preg_match('/^\s*(?:export\s+)?YOUNGMAN_CRYPTO_KEY\s*=\s*(.*)$/i', $line, $m)) {
                    $raw = trim($m[1], "\"' ");
                    break 2;
                }
            }
        }
    }

    if ($raw === '') {
        $cached = '';
        return null;
    }

    // 키 형식: hex(64자) / base64 / 그 외 임의 문자열 → SHA-256 derive 로 32바이트 정규화
    $key = null;
    if (strlen($raw) === 64 && ctype_xdigit($raw)) {
        $key = @hex2bin($raw);
    }
    if ($key === null || $key === false) {
        $b64 = @base64_decode($raw, true);
        if ($b64 !== false && strlen($b64) === 32) $key = $b64;
    }
    if ($key === null || $key === false) {
        $key = hash('sha256', $raw, true);
    }
    $cached = $key;
    return $key;
}

/** 평문 문자열을 AES-256-GCM 암호화. 키 없으면 평문 그대로 반환 */
function youngman_encrypt(?string $plaintext): ?string {
    if ($plaintext === null || $plaintext === '') return $plaintext;
    $key = youngman_master_key();
    if (!$key) return $plaintext;

    $iv = openssl_random_pseudo_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false || strlen($tag) !== 16) {
        return $plaintext;   // 암호화 실패 — 안전망: 평문 보존 (절대 데이터 손실 X)
    }
    return 'enc:v1:' . base64_encode($iv . $tag . $ct);
}

/** 암호화된 값 복호화. 'enc:v1:' 접두사 없으면 평문으로 간주 → 그대로 반환 (lazy migration) */
function youngman_decrypt($value) {
    if (!is_string($value) || strpos($value, 'enc:v1:') !== 0) return $value;
    $key = youngman_master_key();
    if (!$key) return $value;

    $payload = base64_decode(substr($value, 7), true);
    if ($payload === false || strlen($payload) < 28) return $value;

    $iv  = substr($payload, 0,  12);
    $tag = substr($payload, 12, 16);
    $ct  = substr($payload, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return ($pt === false) ? $value : $pt;
}

/** JSON 데이터를 통째 암호화 (객체/배열 입력 → 암호화된 문자열) */
function youngman_encrypt_json($data): ?string {
    if ($data === null) return null;
    $json = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return null;
    return youngman_encrypt($json);
}

/** 암호화된 JSON 복호화 (문자열 → 객체/배열). 평문 JSON 도 호환 */
function youngman_decrypt_json($value) {
    if ($value === null || $value === '') return null;
    $decrypted = youngman_decrypt($value);
    if (!is_string($decrypted)) return $decrypted;
    $decoded = json_decode($decrypted, true);
    return ($decoded === null && json_last_error() !== JSON_ERROR_NONE)
        ? $decrypted   // JSON 아니면 원본 문자열 반환
        : $decoded;
}

/** 암호화 활성 여부 확인 (디버그/상태 표시용) */
function youngman_crypto_enabled(): bool {
    return youngman_master_key() !== null;
}
