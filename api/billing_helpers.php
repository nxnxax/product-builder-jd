<?php
/**
 * PortOne V2 결제 시스템 헬퍼.
 *
 * 환경변수 (cafe24 .env / GitHub Secrets 어셈블):
 *   PORTONE_STORE_ID         — store-xxxxxxxx 형태 (가맹점 ID)
 *   PORTONE_API_SECRET       — V2 API Secret (Authorization 헤더용)
 *   PORTONE_WEBHOOK_SECRET   — Webhook signature 검증용
 *   PORTONE_CHANNEL_KEY_TOSS — 토스페이먼츠 채널 키
 *
 * API base: https://api.portone.io
 * Webhook spec: Standard Webhooks (svix 호환) — webhook-id / webhook-timestamp / webhook-signature 헤더.
 *
 * 환경변수 누락 시 모든 함수는 throw — endpoint 가 503 으로 응답해야 함.
 */

if (!function_exists('billing_load_env_value')) {
    /**
     * cafe24 의 PHP 는 .env 자동 로드 X. process-recording.php 의 load_env_value 패턴
     * 그대로 — webroot 의 .env 파일을 직접 파싱해서 값 반환.
     */
    function billing_load_env_value(string $key): string {
        foreach ([__DIR__, dirname(__DIR__)] as $dir) {
            $path = $dir . '/.env';
            if (!is_file($path)) continue;
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $m)) {
                    if (strcasecmp($m[1], $key) === 0) return trim($m[2], "\"' \t\r\n");
                }
            }
        }
        return '';
    }
}

if (!function_exists('portone_env')) {
    function portone_env(string $key): string {
        // 1. getenv (Apache 환경변수 또는 process env)
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        // 2. $_ENV superglobal
        $env = $_ENV[$key] ?? null;
        if ($env !== null && $env !== '') return (string)$env;
        // 3. webroot 의 .env 파일 직접 파싱 (cafe24 PHP 환경)
        $fromFile = billing_load_env_value($key);
        if ($fromFile !== '') return $fromFile;
        throw new RuntimeException("PortOne 환경변수 누락: {$key}");
    }
}

if (!function_exists('portone_api_call')) {
    /**
     * PortOne V2 API 호출 — Authorization: PortOne {API_SECRET}.
     * 반환: ['status' => int, 'body' => array | null, 'raw' => string].
     */
    function portone_api_call(string $method, string $path, ?array $body = null): array {
        $secret = portone_env('PORTONE_API_SECRET');
        $url = 'https://api.portone.io' . $path;
        $ch = curl_init($url);
        $headers = [
            'Authorization: PortOne ' . $secret,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];
        if ($body !== null && $method !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('PortOne API 호출 실패: ' . $err);
        }
        $decoded = json_decode((string)$raw, true);
        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null, 'raw' => (string)$raw];
    }
}

if (!function_exists('portone_verify_webhook')) {
    /**
     * Standard Webhooks signature 검증 (PortOne V2 webhook).
     * 헤더: webhook-id / webhook-timestamp / webhook-signature.
     * signature 포맷: "v1,{base64_hmac_sha256}". 여러 signature 가 공백으로 구분될 수 있음.
     * 비교 문자열: "{webhook_id}.{webhook_timestamp}.{body}".
     */
    function portone_verify_webhook(string $body, array $headers): bool {
        $secret = portone_env('PORTONE_WEBHOOK_SECRET');
        // PortOne 의 webhook secret 은 base64 인코딩 — "whsec_xxx" prefix 제거 후 디코딩.
        $secretClean = preg_replace('/^whsec_/', '', $secret);
        $secretBytes = base64_decode($secretClean, true);
        if ($secretBytes === false) {
            // base64 가 아니면 raw bytes 로 그대로.
            $secretBytes = $secret;
        }
        $lower = array_change_key_case($headers, CASE_LOWER);
        $id = $lower['webhook-id'] ?? '';
        $ts = $lower['webhook-timestamp'] ?? '';
        $sig = $lower['webhook-signature'] ?? '';
        if ($id === '' || $ts === '' || $sig === '') return false;
        // replay 방지 — 5분 이내 timestamp 만 허용.
        $tsInt = (int)$ts;
        if ($tsInt === 0 || abs(time() - $tsInt) > 300) return false;
        $signed = $id . '.' . $ts . '.' . $body;
        $expected = base64_encode(hash_hmac('sha256', $signed, $secretBytes, true));
        // 헤더의 signature 는 "v1,xxx" 또는 "v1,xxx v1,yyy" 형태.
        $sigList = preg_split('/\s+/', trim($sig));
        foreach ($sigList as $candidate) {
            $parts = explode(',', $candidate, 2);
            if (count($parts) !== 2) continue;
            if (hash_equals($expected, $parts[1])) return true;
        }
        return false;
    }
}

if (!function_exists('portone_plan_amount')) {
    /** 우리 plan 코드 → 결제 금액 (KRW, VAT 포함). */
    function portone_plan_amount(string $plan): int {
        switch (strtolower($plan)) {
            case 'plus': return 19000;
            case 'pro':  return 39000;
            default:     return 0;
        }
    }
}

if (!function_exists('portone_plan_label')) {
    function portone_plan_label(string $plan): string {
        switch (strtolower($plan)) {
            case 'plus': return 'YOUNGMAN Plus 월간 구독';
            case 'pro':  return 'YOUNGMAN Pro 월간 구독';
            default:     return 'YOUNGMAN 구독';
        }
    }
}

if (!function_exists('portone_response')) {
    function portone_response(array $data, int $http = 200): void {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($http);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('billing_pdo')) {
    /**
     * cafe24 db_config.php 표준 — `return [host=>..., port=>..., database=>...,
     * user=>..., password=>...]` array 반환 패턴. process-recording / records 와 동일.
     */
    function billing_pdo(): PDO {
        $candidates = [
            __DIR__ . '/db_config.php',          // api/billing 의 부모 (api/)
            dirname(__DIR__) . '/db_config.php', // webroot
        ];
        // __DIR__ 이 billing/ 서브디렉토리이면 한 단계 더.
        $candidates[] = dirname(__DIR__, 1) . '/db_config.php';
        $candidates[] = dirname(__DIR__, 2) . '/db_config.php';
        $dbConfigPath = null;
        foreach ($candidates as $p) {
            if (is_file($p)) { $dbConfigPath = $p; break; }
        }
        if ($dbConfigPath === null) {
            throw new RuntimeException('db_config.php 위치를 찾을 수 없음.');
        }
        $db = require $dbConfigPath;
        if (!is_array($db)) {
            throw new RuntimeException('db_config.php 가 array 반환 안 함.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'] ?? 'localhost',
            (int)($db['port'] ?? 3306),
            $db['database'] ?? '');
        return new PDO($dsn, $db['user'] ?? '', $db['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}

if (!function_exists('billing_supabase_url')) {
    /**
     * .env 의 VITE_SUPABASE_URL 이 `https://xxx.supabase.co/rest/v1/` 형태 — root 만 추출.
     */
    function billing_supabase_url(): string {
        $raw = billing_load_env_value('VITE_SUPABASE_URL') ?: (getenv('VITE_SUPABASE_URL') ?: '');
        return rtrim((string)preg_replace('#/(rest|auth)/v1/?.*$#', '', $raw), '/');
    }
}

if (!function_exists('billing_anon_key')) {
    function billing_anon_key(): string {
        return (string)(billing_load_env_value('VITE_SUPABASE_ANON_KEY') ?: (getenv('VITE_SUPABASE_ANON_KEY') ?: ''));
    }
}

if (!function_exists('billing_require_bearer_email')) {
    /**
     * Authorization Bearer 추출 + Supabase /auth/v1/user 검증 + email 반환.
     * 실패 시 portone_response 로 401 응답 (debug 동봉).
     */
    function billing_require_bearer_email(): string {
        // 헤더에서 Bearer 추출
        $h = '';
        if (function_exists('getallheaders')) {
            $hdrs = getallheaders();
            if (is_array($hdrs)) {
                foreach ($hdrs as $k => $v) { if (strcasecmp((string)$k, 'authorization') === 0) { $h = (string)$v; break; } }
            }
        }
        if ($h === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) $h = (string)$_SERVER['HTTP_AUTHORIZATION'];
        $token = '';
        if (preg_match('/Bearer\s+(.+)/i', $h, $m)) $token = trim($m[1]);
        if ($token === '') {
            portone_response(['status' => 'error', 'code' => 'unauthorized', 'message' => '로그인 필요 (Bearer 없음).', 'debug' => ['stage' => 'no_bearer']], 401);
        }
        $supabaseUrl = billing_supabase_url();
        $anonKey = billing_anon_key();
        if ($supabaseUrl === '' || $anonKey === '') {
            portone_response(['status' => 'error', 'code' => 'config', 'message' => '서버 인증 설정 누락 (.env Supabase URL/Key).', 'debug' => ['url_set' => $supabaseUrl !== '', 'key_set' => $anonKey !== '']], 500);
        }
        $ch = curl_init($supabaseUrl . '/auth/v1/user');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'apikey: ' . $anonKey],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200 || !$resp) {
            $hint = $status === 401 ? '세션 만료 — 페이지 새로고침/재로그인 후 다시 시도.' :
                    ($status === 404 ? 'Supabase URL 경로 오류.' :
                    ($status === 0 ? 'Supabase 네트워크 실패.' : 'Supabase 응답 ' . $status));
            portone_response(['status' => 'error', 'code' => 'unauthorized', 'message' => '토큰 검증 실패. ' . $hint, 'debug' => ['stage' => 'supabase_call', 'auth_status' => $status, 'token_len' => strlen($token)]], 401);
        }
        $data = json_decode((string)$resp, true);
        $email = strtolower(trim((string)($data['email'] ?? '')));
        if ($email === '') {
            portone_response(['status' => 'error', 'code' => 'unauthorized', 'message' => '이메일 추출 실패.'], 401);
        }
        return $email;
    }
}

if (!function_exists('portone_extract_status')) {
    /** PortOne V2 응답에서 결제 상태를 여러 nested 위치에서 시도해 추출. */
    function portone_extract_status(array $payment): string {
        return strtoupper((string)(
            $payment['status']
            ?? $payment['payment']['status']
            ?? $payment['transaction']['status']
            ?? $payment['data']['status']
            ?? ''
        ));
    }
}

if (!function_exists('portone_extract_amount')) {
    function portone_extract_amount(array $payment): int {
        return (int)(
            $payment['amount']['total']
            ?? $payment['amount']
            ?? $payment['payment']['amount']['total']
            ?? $payment['data']['amount']['total']
            ?? 0
        );
    }
}
