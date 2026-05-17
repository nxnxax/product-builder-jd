<?php
/**
 * fcm_helpers.php — Firebase Cloud Messaging HTTP v1 발송.
 *
 * Phase 2 M3: process-recording.php async 모드 완료 시 user_fcm_tokens 의
 * owner 토큰들로 푸시 발송. service account JSON 으로 OAuth 2.0 access_token
 * 발급 + FCM HTTP v1 endpoint POST.
 *
 * 외부 라이브러리(firebase/php-jwt) 미사용 — RS256 JWT 는 openssl_sign 직접.
 *
 * 필요 secrets (cafe24 .env):
 *   FIREBASE_SERVICE_ACCOUNT_JSON = {"type":"service_account","project_id":"...",
 *                                    "private_key":"-----BEGIN PRIVATE KEY-----...",
 *                                    "client_email":"firebase-adminsdk-...@..."}
 *
 * 호출자:
 *   send_fcm_to_user(PDO $pdo, string $ownerEmail, array $message): array
 *     - message: ['title' => '...', 'body' => '...', 'data' => [...key/value...]]
 *     - return: ['sent' => int, 'failed' => int, 'invalid_tokens' => [...]]
 */
declare(strict_types=1);

if (!function_exists('fcm_load_service_account')) {

/** service account JSON 로드. .env 의 FIREBASE_SERVICE_ACCOUNT_JSON 우선, 파일 폴백 없음. */
function fcm_load_service_account(): ?array {
    static $cached = null;
    if ($cached !== null) return $cached === [] ? null : $cached;

    $raw = '';
    foreach ([__DIR__, dirname(__DIR__)] as $dir) {
        $envPath = $dir . '/.env';
        if (!is_file($envPath)) continue;
        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) continue;
        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:export\s+)?FIREBASE_SERVICE_ACCOUNT_JSON\s*=\s*(.*)$/i', $line, $m)) {
                $raw = trim($m[1], "\"' ");
                break 2;
            }
        }
    }
    if ($raw === '') { $cached = []; return null; }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['private_key']) || empty($data['client_email']) || empty($data['project_id'])) {
        error_log('[fcm] service account JSON 형식 오류 (private_key/client_email/project_id 누락)');
        $cached = [];
        return null;
    }

    // GitHub Secret 에서 multi-line value 가 \n literal 로 들어올 수 있음.
    if (strpos($data['private_key'], "\\n") !== false && strpos($data['private_key'], "\n") === false) {
        $data['private_key'] = str_replace("\\n", "\n", $data['private_key']);
    }
    $cached = $data;
    return $data;
}

/** base64url (RFC 4648 §5) — JWT 표준. */
function fcm_b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * Google OAuth 2.0 access_token (FCM scope) 발급.
 * RS256 으로 self-signed JWT 만들고 oauth2.googleapis.com/token 에 교환.
 * 발급된 토큰은 메모리 캐시 (process 내, expires_in 만료 5분 전까지 재사용).
 */
function fcm_get_access_token(): ?string {
    static $cache = ['token' => null, 'exp' => 0];
    if ($cache['token'] !== null && $cache['exp'] > time() + 300) {
        return $cache['token'];
    }

    $sa = fcm_load_service_account();
    if (!$sa) return null;

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claim = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $signingInput = fcm_b64url(json_encode($header, JSON_UNESCAPED_SLASHES))
                  . '.' . fcm_b64url(json_encode($claim, JSON_UNESCAPED_SLASHES));

    $key = @openssl_pkey_get_private($sa['private_key']);
    if (!$key) {
        error_log('[fcm] openssl_pkey_get_private failed (private_key 파싱 실패)');
        return null;
    }
    $signature = '';
    $okSign = @openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
    if (function_exists('openssl_free_key')) @openssl_free_key($key);
    if (!$okSign) {
        error_log('[fcm] openssl_sign failed');
        return null;
    }
    $jwt = $signingInput . '.' . fcm_b64url($signature);

    // OAuth 교환.
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $status < 200 || $status >= 300) {
        error_log('[fcm] OAuth token exchange failed (' . $status . '): ' . substr((string)$resp, 0, 300) . ' ' . $err);
        return null;
    }
    $data = json_decode((string)$resp, true);
    if (!is_array($data) || empty($data['access_token'])) {
        error_log('[fcm] OAuth response invalid: ' . substr((string)$resp, 0, 300));
        return null;
    }

    $cache['token'] = (string)$data['access_token'];
    $cache['exp']   = $now + (int)($data['expires_in'] ?? 3600);
    return $cache['token'];
}

/**
 * 단일 token 에 FCM HTTP v1 메시지 발송.
 * return: 200~299 OK / 'INVALID_TOKEN' (UNREGISTERED/INVALID_ARGUMENT) / 'FAILED'
 */
function fcm_send_to_token(string $accessToken, string $projectId, string $token, array $messagePayload): string {
    $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';
    $body = json_encode([
        'message' => array_merge(['token' => $token], $messagePayload),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=utf-8',
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 200 && $status < 300) return 'OK';

    // 404 UNREGISTERED / 400 INVALID_ARGUMENT 등은 토큰이 무효 → 호출자가 정리.
    $data = is_string($resp) ? json_decode($resp, true) : null;
    $errCode = '';
    if (is_array($data)) {
        $errCode = (string)($data['error']['details'][0]['errorCode']
                          ?? $data['error']['status']
                          ?? $data['error']['message']
                          ?? '');
    }
    if ($status === 404
        || $status === 400 && stripos($errCode, 'INVALID') !== false
        || stripos($errCode, 'UNREGISTERED') !== false) {
        return 'INVALID_TOKEN';
    }
    error_log('[fcm] send fail (' . $status . '): ' . substr((string)$resp, 0, 300));
    return 'FAILED';
}

/**
 * user_fcm_tokens 의 owner_email 토큰 모두에게 발송.
 * INVALID_TOKEN 응답은 자동으로 DELETE (stale 토큰 정리).
 */
function send_fcm_to_user(PDO $pdo, string $ownerEmail, array $message): array {
    $sa = fcm_load_service_account();
    if (!$sa) {
        return ['sent' => 0, 'failed' => 0, 'invalid_tokens' => [], 'reason' => 'service_account_missing'];
    }
    $access = fcm_get_access_token();
    if (!$access) {
        return ['sent' => 0, 'failed' => 0, 'invalid_tokens' => [], 'reason' => 'oauth_failed'];
    }

    $stmt = $pdo->prepare('SELECT id, token FROM user_fcm_tokens WHERE owner_email = :o');
    $stmt->execute([':o' => strtolower($ownerEmail)]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['sent' => 0, 'failed' => 0, 'invalid_tokens' => [], 'reason' => 'no_tokens'];

    // 표준 메시지 payload — notification (시스템 표시) + data (앱 라우팅용).
    $payload = [];
    if (!empty($message['title']) || !empty($message['body'])) {
        $payload['notification'] = [
            'title' => (string)($message['title'] ?? ''),
            'body'  => (string)($message['body'] ?? ''),
        ];
    }
    if (!empty($message['data']) && is_array($message['data'])) {
        $stringified = [];
        foreach ($message['data'] as $k => $v) {
            $stringified[(string)$k] = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        $payload['data'] = $stringified;
    }
    // Android 채널 ID + iOS APNS — 향후 앱 측 요구사항 따라 확장.
    $payload['android'] = ['priority' => 'high'];

    $sent = 0;
    $failed = 0;
    $invalid = [];
    foreach ($rows as $row) {
        $result = fcm_send_to_token($access, (string)$sa['project_id'], (string)$row['token'], $payload);
        if ($result === 'OK') {
            $sent++;
        } elseif ($result === 'INVALID_TOKEN') {
            $invalid[] = (int)$row['id'];
            $failed++;
        } else {
            $failed++;
        }
    }

    // stale 토큰 정리.
    if ($invalid) {
        try {
            $placeholders = implode(',', array_fill(0, count($invalid), '?'));
            $del = $pdo->prepare("DELETE FROM user_fcm_tokens WHERE id IN ($placeholders)");
            $del->execute($invalid);
        } catch (Throwable $e) {
            error_log('[fcm] stale token cleanup failed: ' . $e->getMessage());
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'invalid_tokens' => $invalid];
}

} // end if function_exists guard
