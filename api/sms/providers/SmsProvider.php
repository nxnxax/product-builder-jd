<?php
/**
 * SmsProvider — 단체 문자 발송 provider 추상 클래스.
 *
 * 모든 provider 는 sendBulk(messages, from, options) 를 구현.
 *   messages: [['to' => '01012345678', 'text' => '...'], ...]
 *   from:     발신 번호 (010 ...)
 *   options:  ['dryRun' => bool, 'subject' => string|null] 등
 *
 * 표준 반환:
 *   [
 *     'provider'    => 'solapi',
 *     'success'     => int,
 *     'failed'      => [ ['to' => '01000000000', 'error' => 'reason'], ... ],
 *     'dryRun'      => bool,
 *     'rawResponse' => mixed,    // 디버그용 (로그에는 hash 만 저장)
 *   ]
 */

abstract class SmsProvider
{
    /** @var array provider 설정 (env 에서 주입) */
    protected $config = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    abstract public function name(): string;

    /**
     * @param array $messages [{to, text}]
     * @param string $from
     * @param array $options ['dryRun' => bool]
     * @return array 표준 반환
     */
    abstract public function sendBulk(array $messages, string $from, array $options = []): array;

    /** 설정 유효성 — 키 누락이면 false (dry-run 모드로 fallback 권장). */
    abstract public function isConfigured(): bool;

    /** 표준 dry-run 응답 — 실제 API 호출 안 함. */
    protected function makeDryRunResponse(array $messages): array
    {
        return [
            'provider'    => $this->name(),
            'success'     => count($messages),
            'failed'      => [],
            'dryRun'      => true,
            'rawResponse' => null,
        ];
    }
}
