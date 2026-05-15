<?php
require_once __DIR__ . '/SmsProvider.php';

/**
 * SolapiProvider — Solapi (coolsms) v4 REST API.
 * 문서: https://developers.solapi.com/references/messages
 *
 * 필요 .env:
 *   SOLAPI_API_KEY
 *   SOLAPI_API_SECRET
 *
 * 인증: Authorization: HMAC-SHA256 apiKey=...,date=...,salt=...,signature=...
 * date: ISO 8601 (예: 2026-05-16T12:34:56Z)
 * signature: HMAC-SHA256(secret, date + salt)
 */
class SolapiProvider extends SmsProvider
{
    public function name(): string { return 'solapi'; }

    public function isConfigured(): bool
    {
        return !empty($this->config['api_key'])
            && !empty($this->config['api_secret']);
    }

    public function sendBulk(array $messages, string $from, array $options = []): array
    {
        if (!empty($options['dryRun']) || !$this->isConfigured()) {
            return $this->makeDryRunResponse($messages);
        }

        $payload = [
            'messages' => array_map(function ($m) use ($from) {
                $row = [
                    'to'   => $m['to'],
                    'from' => $from,
                    'text' => $m['text'],
                ];
                // 90 byte 초과 시 자동 LMS 로 전환 (Solapi 가 type 추론)
                return $row;
            }, $messages),
        ];

        $endpoint = 'https://api.solapi.com/messages/v4/send-many/detail';
        $rawResponse = null;
        try {
            $auth = $this->buildAuthHeader();
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: ' . $auth,
                ],
                CURLOPT_TIMEOUT => 25,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                return $this->markAllFailed($messages, 'curl 실패: ' . $err);
            }
            $rawResponse = json_decode($body, true);
        } catch (Throwable $e) {
            return $this->markAllFailed($messages, $e->getMessage());
        }

        // Solapi 응답: { groupInfo: {...}, messageList: { 메시지ID: { statusCode, statusMessage, to, ... } } }
        $statusByTo = [];
        if (is_array($rawResponse) && isset($rawResponse['messageList']) && is_array($rawResponse['messageList'])) {
            foreach ($rawResponse['messageList'] as $msg) {
                if (!is_array($msg)) continue;
                $to = $msg['to'] ?? '';
                $sc = $msg['statusCode'] ?? '';
                if ($to === '') continue;
                $statusByTo[$to] = $sc;
            }
        }

        $success = 0;
        $failed  = [];
        foreach ($messages as $m) {
            $sc = $statusByTo[$m['to']] ?? null;
            // Solapi: 성공 코드 '2000'/'4000' 류로 시작하는 처리완료/대기. 실패는 4xxx 외 명시.
            // 보수적으로 statusCode 가 '2' 로 시작하면 성공으로.
            if ($sc !== null && substr((string)$sc, 0, 1) === '2') {
                $success++;
            } else {
                $failed[] = [
                    'to'    => $m['to'],
                    'error' => $sc ? ('코드 ' . $sc) : '응답에서 상태 누락',
                ];
            }
        }

        return [
            'provider'    => 'solapi',
            'success'     => $success,
            'failed'      => $failed,
            'dryRun'      => false,
            'rawResponse' => $rawResponse,
        ];
    }

    /** Solapi HMAC 인증 헤더 생성. */
    private function buildAuthHeader(): string
    {
        $apiKey = $this->config['api_key'];
        $secret = $this->config['api_secret'];
        $date   = gmdate('Y-m-d\TH:i:s\Z');
        $salt   = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $date . $salt, $secret);
        return sprintf(
            'HMAC-SHA256 apiKey=%s, date=%s, salt=%s, signature=%s',
            $apiKey, $date, $salt, $signature
        );
    }

    private function markAllFailed(array $messages, string $reason): array
    {
        return [
            'provider'    => 'solapi',
            'success'     => 0,
            'failed'      => array_map(function ($m) use ($reason) {
                return ['to' => $m['to'], 'error' => $reason];
            }, $messages),
            'dryRun'      => false,
            'rawResponse' => null,
        ];
    }
}
