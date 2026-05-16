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

        $imageId = $options['imageId'] ?? null;   // 사전 업로드된 이미지 ID (MMS 첨부)
        $payload = [
            'messages' => array_map(function ($m) use ($from, $imageId) {
                $row = [
                    'to'   => $m['to'],
                    'from' => $from,
                    'text' => $m['text'],
                ];
                if ($imageId) {
                    $row['imageId'] = $imageId;
                    $row['type']    = 'MMS';
                }
                // 이미지 없으면 Solapi 가 90byte 기준으로 SMS/LMS 자동 판정.
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

    /**
     * Solapi storage 에 이미지 업로드 → fileId 반환.
     * 문서: https://developers.solapi.com/references/storage/uploadFile
     * @param string $base64  base64 인코딩된 raw 이미지 (data: prefix 없이)
     * @param string $name    원본 파일명 (옵션)
     * @return string|null    성공 시 fileId, 실패 시 null
     */
    public function uploadImage(string $base64, string $name = 'attachment.jpg'): ?string
    {
        if (!$this->isConfigured()) return null;
        if ($base64 === '') return null;

        $endpoint = 'https://api.solapi.com/storage/v1/files';
        $payload  = json_encode([
            'file' => $base64,
            'type' => 'MMS',
            'name' => $name,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $auth = $this->buildAuthHeader();
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: ' . $auth,
                ],
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($body) || $body === '') return null;
            $resp = json_decode($body, true);
            if (!is_array($resp)) return null;
            // 응답: { fileId, type, name, ... }  실패: { errorCode, errorMessage }
            if (!empty($resp['fileId']) && $http < 400) {
                return (string)$resp['fileId'];
            }
            return null;
        } catch (Throwable $e) {
            return null;
        }
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
