<?php
/**
 * Google Play Developer API 공통 helpers.
 * verify-google-purchase.php (영수증 1차 검증) + rtdn.php (실시간 알림) 양쪽이 require.
 */

declare(strict_types=1);

require_once __DIR__ . '/../billing_helpers.php';

if (!function_exists('google_play_product_id')) {
    /**
     * planKey → Google Play productId 매핑 (앱팀 §7 spec).
     */
    function google_play_product_id(string $planKey): string {
        switch (strtolower($planKey)) {
            case 'sales':   return 'youngman_sales_monthly';
            case 'master':  return 'youngman_master_monthly';
            case 'agency':  return 'youngman_agency_monthly';
            default:        return '';
        }
    }
}

if (!function_exists('google_play_access_token')) {
    /**
     * Service Account JSON 키로 OAuth2 access token 발급 (1시간 캐시).
     * - JWT 생성 (RS256) → https://oauth2.googleapis.com/token 호출.
     */
    function google_play_access_token(): string {
        $cacheFile = sys_get_temp_dir() . '/youngman_gplay_token.cache';
        if (is_file($cacheFile)) {
            $cached = @json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['token'], $cached['expires_at']) && (int)$cached['expires_at'] > time() + 60) {
                return (string)$cached['token'];
            }
        }

        $rawJson = billing_load_env_value('GOOGLE_PLAY_SERVICE_ACCOUNT_JSON');
        if ($rawJson === '') {
            throw new RuntimeException('GOOGLE_PLAY_SERVICE_ACCOUNT_JSON 미설정');
        }
        // 값이 file path 인 경우 — 읽어서 사용.
        if (is_file($rawJson)) {
            $rawJson = (string)file_get_contents($rawJson);
        }
        $sa = json_decode($rawJson, true);
        if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) {
            throw new RuntimeException('Service Account JSON 형식 오류 (private_key / client_email 누락)');
        }

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $now = time();
        $claim = [
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/androidpublisher',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ];
        $b64u = function ($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };
        $jwtUnsigned = $b64u(json_encode($header)) . '.' . $b64u(json_encode($claim));
        $signature = '';
        if (!openssl_sign($jwtUnsigned, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('JWT signing 실패');
        }
        $jwt = $jwtUnsigned . '.' . $b64u($signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code < 200 || $code >= 300) {
            throw new RuntimeException('oauth2/token 응답 ' . $code . ': ' . substr((string)$raw, 0, 200));
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('access_token 응답 누락');
        }
        $token = (string)$data['access_token'];
        $expiresIn = (int)($data['expires_in'] ?? 3600);
        @file_put_contents($cacheFile, json_encode(['token' => $token, 'expires_at' => time() + $expiresIn]), LOCK_EX);
        @chmod($cacheFile, 0600);
        return $token;
    }
}

if (!function_exists('google_play_get_subscription')) {
    /**
     * purchases.subscriptions.get — 영수증 검증 + 만료 시각 / payment state 조회.
     * 반환: ['http' => int, 'body' => array|null, 'raw' => string]
     */
    function google_play_get_subscription(string $packageName, string $productId, string $purchaseToken): array {
        $accessToken = google_play_access_token();
        $url = sprintf('https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/subscriptions/%s/tokens/%s',
            rawurlencode($packageName), rawurlencode($productId), rawurlencode($purchaseToken));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $body = ($raw !== false) ? json_decode((string)$raw, true) : null;
        return ['http' => $code, 'body' => is_array($body) ? $body : null, 'raw' => (string)$raw];
    }
}
