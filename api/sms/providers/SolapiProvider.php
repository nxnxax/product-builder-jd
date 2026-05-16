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
        $http = null;
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

        // Solapi 응답 분석.
        // 가능한 source (우선순위 순):
        //   1) failedMessageList — 실패한 to 와 사유 명시
        //   2) messageList — 각 메시지의 statusCode (구조: 객체 또는 배열)
        //   3) groupInfo.count — registeredSuccess/registeredFailed 집계
        // 매칭은 마지막 11자리(한국 핸드폰) 기준 — Solapi 응답이 '+82...' 로 올 수 있어
        // 단순 문자열 비교는 실패. digits-only + tail-11 으로 normalize.

        $tailKey = function ($v) {
            $d = preg_replace('/\D/', '', (string)$v);
            return $d === '' ? '' : substr($d, -11);
        };

        // raw response 가 null/문자열이면 markAllFailed — 새 로직 진입 전 안전 가드
        if (!is_array($rawResponse)) {
            return $this->markAllFailed($messages, '응답을 파싱할 수 없습니다 (HTTP ' . ($http ?? '?') . ')');
        }

        // 1) failedMessageList 의 to 추출
        $failedSet = [];
        $fml = $rawResponse['failedMessageList'] ?? null;
        if (is_array($fml)) {
            foreach ($fml as $fm) {
                if (!is_array($fm)) continue;
                $k = $tailKey($fm['to'] ?? '');
                if ($k === '') continue;
                $reason = $fm['statusMessage'] ?? $fm['statusCode'] ?? '발송 실패';
                $failedSet[$k] = (string)$reason;
            }
        }

        // 2) messageList — 객체/배열 둘 다 지원
        $statusByTo = [];
        $ml = $rawResponse['messageList'] ?? null;
        if (is_array($ml)) {
            foreach ($ml as $msg) {
                if (!is_array($msg)) continue;
                $k = $tailKey($msg['to'] ?? '');
                if ($k === '') continue;
                $statusByTo[$k] = (string)($msg['statusCode'] ?? '');
            }
        }

        // 3) groupInfo.count
        $groupInfo = $rawResponse['groupInfo'] ?? null;
        $counts = is_array($groupInfo) ? ($groupInfo['count'] ?? []) : [];
        $registeredFailed  = isset($counts['registeredFailed'])  ? (int)$counts['registeredFailed']  : null;
        $registeredSuccess = isset($counts['registeredSuccess']) ? (int)$counts['registeredSuccess'] : null;

        $success = 0;
        $failed  = [];
        foreach ($messages as $m) {
            $k = $tailKey($m['to']);

            // 명시 실패 — failedMessageList
            if (isset($failedSet[$k])) {
                $failed[] = ['to' => $m['to'], 'error' => $failedSet[$k]];
                continue;
            }

            $sc = $statusByTo[$k] ?? null;
            // Solapi: '2xxx' = 성공 (등록 완료), '4xxx' = 실패, '3xxx' = 처리 중.
            if ($sc !== null && $sc !== '' && substr($sc, 0, 1) === '2') {
                $success++;
                continue;
            }

            // statusCode 못 찾았으면 groupInfo 의 집계로 판단.
            // Solapi 가 모든 메시지를 성공 등록했고 (registeredFailed=0) 우리 to 가
            // failedMessageList 에도 없으면 success 로 인정.
            if ($registeredFailed === 0 && $registeredSuccess !== null && $registeredSuccess > 0) {
                $success++;
                continue;
            }

            // statusCode 있는데 '2' 시작 아님 → 그 코드 그대로 실패.
            if ($sc !== null && $sc !== '') {
                $failed[] = ['to' => $m['to'], 'error' => '코드 ' . $sc];
                continue;
            }

            // 정말 정보 없음
            $failed[] = ['to' => $m['to'], 'error' => '응답에서 상태 누락'];
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
     * Solapi 계정 잔액 조회.
     * 응답: ['balance' => float|null, 'point' => float|null] 또는 null (실패).
     */
    public function getBalance(): ?array
    {
        if (!$this->isConfigured()) return null;
        $endpoint = 'https://api.solapi.com/cash/v1/balance';
        try {
            $auth = $this->buildAuthHeader();
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $auth,
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($body) || $body === '' || $http >= 400) return null;
            $resp = json_decode($body, true);
            if (!is_array($resp)) return null;
            return [
                'balance' => isset($resp['balance']) ? (float)$resp['balance'] : null,
                'point'   => isset($resp['point'])   ? (float)$resp['point']   : null,
            ];
        } catch (Throwable $e) {
            return null;
        }
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
