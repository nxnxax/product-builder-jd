<?php
require_once __DIR__ . '/SmsProvider.php';

/**
 * AligoProvider — Aligo SMS API (smartsms.aligo.in).
 * 문서: https://smartsms.aligo.in/admin/api/spec.html
 *
 * 필요 .env:
 *   ALIGO_API_KEY
 *   ALIGO_USER_ID
 *
 * 인증: form-data 로 key / user_id / sender / receiver / msg 등 전송.
 * 단체 발송은 receiver 를 콤마 구분 (최대 1000명).
 */
class AligoProvider extends SmsProvider
{
    public function name(): string { return 'aligo'; }

    public function isConfigured(): bool
    {
        return !empty($this->config['api_key'])
            && !empty($this->config['user_id']);
    }

    public function sendBulk(array $messages, string $from, array $options = []): array
    {
        if (!empty($options['dryRun']) || !$this->isConfigured()) {
            return $this->makeDryRunResponse($messages);
        }

        // Aligo 는 같은 본문에 한해 단체 발송. 본문이 다 같다고 가정.
        // 본문이 다른 케이스는 청크별로 분할 호출 (여기선 단일 본문 가정).
        $text = $messages[0]['text'] ?? '';
        $tos  = array_values(array_unique(array_column($messages, 'to')));
        if ($text === '' || empty($tos)) {
            return $this->markAllFailed($messages, '메시지 또는 수신자 누락');
        }

        $endpoint = strlen($text) > 90
            ? 'https://apis.aligo.in/send/'   // LMS/MMS 자동 처리됨
            : 'https://apis.aligo.in/send/';

        $payload = [
            'key'      => $this->config['api_key'],
            'user_id'  => $this->config['user_id'],
            'sender'   => $from,
            'receiver' => implode(',', $tos),
            'msg'      => $text,
            // 90 byte 초과 시 자동 LMS — title 옵션 가능 (현재 미사용)
        ];

        $rawResponse = null;
        try {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($payload),
                CURLOPT_TIMEOUT => 25,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                return $this->markAllFailed($messages, 'curl 실패: ' . $err);
            }
            $rawResponse = json_decode($body, true);
        } catch (Throwable $e) {
            return $this->markAllFailed($messages, $e->getMessage());
        }

        // Aligo: result_code = 1 이면 접수 성공. 그 외엔 실패.
        $resultCode = isset($rawResponse['result_code']) ? (int)$rawResponse['result_code'] : -1;
        if ($resultCode !== 1) {
            $reason = $rawResponse['message'] ?? ('결과코드 ' . $resultCode);
            return $this->markAllFailed($messages, $reason);
        }

        // 개별 실패는 Aligo 가 별도로 알려주지 않아 일괄 성공 처리.
        return [
            'provider'    => 'aligo',
            'success'     => count($messages),
            'failed'      => [],
            'dryRun'      => false,
            'rawResponse' => $rawResponse,
        ];
    }

    private function markAllFailed(array $messages, string $reason): array
    {
        return [
            'provider'    => 'aligo',
            'success'     => 0,
            'failed'      => array_map(function ($m) use ($reason) {
                return ['to' => $m['to'], 'error' => $reason];
            }, $messages),
            'dryRun'      => false,
            'rawResponse' => null,
        ];
    }
}
