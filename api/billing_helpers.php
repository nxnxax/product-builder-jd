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

if (!function_exists('portone_env')) {
    function portone_env(string $key): string {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $env = $_ENV[$key] ?? null;
            if ($env !== null && $env !== '') return (string)$env;
            throw new RuntimeException("PortOne 환경변수 누락: {$key}");
        }
        return $v;
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
