<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jout(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function load_env_value(string $key): string {
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') jout(['ok' => false, 'error' => 'POST only'], 405);

// LLM 선택: Together(Qwen) 우선, 없으면 gpt-4o 폴백
$togetherKey = load_env_value('TOGETHER_API_KEY');
if ($togetherKey !== '') {
    $apiKey   = $togetherKey;
    $llmUrl   = 'https://api.together.xyz/v1/chat/completions';
    $llmModel = 'Qwen/Qwen3.5-397B-A17B';
    $llmLabel = 'Together';
} else {
    $apiKey   = load_env_value('OPENAI_API_KEY');
    $llmUrl   = 'https://api.openai.com/v1/chat/completions';
    $llmModel = 'gpt-4o';
    $llmLabel = 'OpenAI';
}
if ($apiKey === '') jout(['ok' => false, 'error' => 'TOGETHER_API_KEY/OPENAI_API_KEY 미설정'], 500);

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body)) jout(['ok' => false, 'error' => 'JSON 형식 오류'], 400);

// Sanitize + extract fields
$name      = trim((string)($body['name']       ?? ''));
$gender    = trim((string)($body['gender']     ?? ''));
$birthLabel = trim((string)($body['birthLabel'] ?? ''));
$drawDate  = trim((string)($body['drawDate']   ?? ''));
$ge        = trim((string)($body['ge']         ?? ''));    // 격국
$geStatus  = trim((string)($body['geStatus']   ?? ''));    // 격국 상태
$str       = trim((string)($body['str']        ?? ''));    // 신강/신약
$cl        = trim((string)($body['cl']         ?? ''));    // 조후
$ys        = trim((string)($body['ys']         ?? ''));    // 용신
$lucky     = trim((string)($body['lucky']      ?? ''));    // 보완 오행
$dw        = trim((string)($body['daewoon']    ?? ''));
$sw        = trim((string)($body['sewoon']     ?? ''));
$ww        = trim((string)($body['wolwoon']    ?? ''));
$rel       = trim((string)($body['relations']  ?? ''));

if ($name === '') jout(['ok' => false, 'error' => '이름이 필요합니다.'], 400);

// Build the saju summary as the user message
$summary = "이름: {$name}";
if ($gender !== '')     $summary .= "\n성별: {$gender}";
if ($birthLabel !== '') $summary .= "\n출생: {$birthLabel}";
if ($drawDate !== '')   $summary .= "\n추첨일: {$drawDate}";
if ($ge !== '')         $summary .= "\n격국: {$ge}" . ($geStatus !== '' ? " ({$geStatus})" : '');
if ($str !== '')        $summary .= "\n신강·신약: {$str}";
if ($cl !== '')         $summary .= "\n조후: {$cl}";
if ($ys !== '')         $summary .= "\n용신: {$ys}";
if ($lucky !== '')      $summary .= "\n오늘 보완 오행: {$lucky}";
if ($dw !== '')         $summary .= "\n대운: {$dw}";
if ($sw !== '')         $summary .= "\n세운: {$sw}";
if ($ww !== '')         $summary .= "\n월운: {$ww}";
if ($rel !== '')        $summary .= "\n합·충·형·파·해 관계: {$rel}";

$sys = <<<SYS
너는 50년 경력의 한국 전통 점집 도사야. 동네 어귀의 작은 점집에서 사람들의 사주를 봐주며 살아온 인생 선배. 말투는:
- 친근하지만 신비로운 분위기
- 어조는 차분하고 단단한 한국어 (반말 아님 — 정중한 존댓말)
- 가끔 옛스러운 표현 한두 개 ("~인 게야", "~한 까닭이오", "흠..." 같은 추임새는 자제하고 대신 자연스러운 도사체)
- 절대 "AI" "분석" "데이터" 같은 현대적 단어 쓰지 마
- "사주가 말해주기를", "오행의 흐름이", "기운이 모이는 자리가" 같은 점집체 어휘 사용
- 결과는 4~6 문단, 각 문단 2~4 문장
- 문단 사이는 빈 줄(\\n\\n)로 구분
- HTML 태그 절대 쓰지 마 (순수 텍스트만)

내용 구성 (이 순서로):
1. 사주 전체 흐름 한 문단 (격국·신강신약·조후를 자연스럽게 풀어서)
2. 대운·세운·월운이 만나는 흐름 (지금 시기가 어떤지)
3. 오늘 추첨일과의 궁합 + 보완 오행/용신 의미
4. 금전운 (현실적이되 운명적인 톤으로)
5. 연애·인간관계 운 (있으면)
6. 마무리 — 오늘 가져야 할 마음가짐 한 문단

추첨번호의 적중을 보장하는 발언은 금지. "운이 모이는 자리"라고는 해도 "당첨된다"는 단정은 금지.
SYS;

// Call LLM (Together/Qwen 우선, gpt-4o 폴백 — OpenAI 호환 REST)
$ch = curl_init($llmUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'model' => $llmModel,
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $summary],
        ],
        'max_tokens' => 1200,
        'temperature' => 0.85,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$resp = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($resp === false) jout(['ok' => false, 'error' => $llmLabel . ' 호출 실패: ' . $err], 502);
$data = json_decode((string)$resp, true);
if ($status < 200 || $status >= 300) {
    $msg = is_array($data) ? ($data['error']['message'] ?? json_encode($data)) : substr((string)$resp, 0, 300);
    jout(['ok' => false, 'error' => $llmLabel . ' ' . $status . ': ' . $msg], 502);
}

$reading = (string)($data['choices'][0]['message']['content'] ?? '');
$reading = trim($reading);
if ($reading === '') jout(['ok' => false, 'error' => '응답 비어있음'], 502);

// 관리자 활동로그 기록 — 어떤 AI가 사주를 풀었는지(qwen/gpt-4o). 실패해도 응답엔 영향 없음.
try {
    $cfgPath = __DIR__ . '/db_config.php';
    if (!is_file($cfgPath)) $cfgPath = dirname(__DIR__) . '/db_config.php';
    if (is_file($cfgPath)) {
        $db = require $cfgPath;
        $logPdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $db['host'] ?? 'localhost', (int)($db['port'] ?? 3306), $db['database'] ?? ''),
            $db['user'] ?? '', $db['password'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $actor = strtolower(trim((string)($body['email'] ?? ''))) ?: '(비회원)';
        $token = ($llmLabel === 'Together') ? 'qwen' : 'gpt-4o';
        $st = $logPdo->prepare("INSERT INTO activity_logs (actor_email, event_type, detail) VALUES (?, 'saju_reading', ?)");
        $st->execute([$actor, $token]);
    }
} catch (Throwable $e) { /* 로그 실패는 무시 */ }

jout([
    'ok' => true,
    'reading' => $reading,
]);
