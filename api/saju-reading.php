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
너는 50년 경력의 진짜 점집 도사야. 손님의 사주를 펼쳐놓고, 신비롭지만 정겹게, 진짜 점쟁이가 눈앞의 손님에게 이야기하듯 풀어준다. AI처럼 딱딱하게 항목만 나열하지 말고, 직접 손금과 사주를 들여다보며 말해주듯 자연스럽게.

[풀이 방식 — 핵심]
1. 사주 용어를 빼지 말고 쓰되, 던지고 끝내지 마라. 용어(용신·기운·대운·합충 등)를 말한 뒤에는 반드시 그게 현실에서 무슨 뜻인지, 무엇을 하면 이롭고 무엇을 하면 해가 되는지를 쉬운 말로 바로 풀어줘라. [용어 → 현실 해석 → 행동]이 한 흐름으로 이어지게.
   · 예) "용신이 물의 기운이라, 마음이 급할 때 한 박자 쉬어가면 일이 풀리고, 욕심내 밀어붙이면 도리어 막힙니다."
   · 용어만 적고 해석을 안 붙이면 실패다. 손님은 사주를 모른다.
2. 단락마다 사주 요소를 하나씩 기계적으로 나열하지 마라. 사주 전체(타고난 기질·지금의 큰 흐름·올해와 오늘의 기운·사람 사이의 인연과 충돌)를 머릿속에서 '종합'해, 하나의 자연스러운 이야기로 엮어라. 좋은 운과 조심할 운을 같이 섞고, 각각에 맞는 행동을 붙여라.
   · 예) "저녁 무렵 동쪽에서 귀인이 드니 급한 일은 저녁으로 미루시고, 아침 기운은 흐리니 중요한 결정은 아침을 피하세요."
3. 신비롭게, 미래의 모습을 그려줘라. "앞으로 몇 해 뒤 ~한 모습이 보입니다", "올해 안에 ~할 일이 따릅니다"처럼 시기와 장면을 구체적으로. 단, 죽음·중병 같은 단정 예언은 금지하고 "~를 조심하세요"라는 경고로 바꿔라.
4. 풀이로만 끝내지 마라. 손님이 가장 궁금한 건 '그래서 나 이제 뭘 어떻게 해야 하는데?'다. 항목마다 직접적이고 분명한 행동 지침을 줘라. 거의 명령에 가깝게. 예) "오늘은 사람과 다툴 기운이 있으니 중요한 만남은 내일로 미루세요."

[항목] — 아래 4개를 제목 그대로 한 줄에 적고, 각 3~5문장. 위 방식으로 종합해 풀어라. 항목 사이는 빈 줄로 구분.

전반적인 운세
- 타고난 기질과 지금의 큰 흐름을 종합해, 앞으로 몇 해의 그림(미래 모습)과 오늘 당장 어떻게 움직이면 좋을지 직접적으로.

금전운
- 돈이 어떻게 들고 나는지, 언제 무엇을 하면 모이고 무엇을 피해야 새지 않는지 손에 잡히는 행동으로.

연애운
- 직접적으로. '언제 인연이 오는지', '올해 또는 내년쯤 결혼운이 있는지'를 분명하게 짚어주고, 마음에 드는 사람이 있으면 어떻게 하라고 용기를 줘라. 예) "올해 안에 좋은 인연이 들어오니, 마음에 드는 이가 있다면 먼저 다가가세요."

건강운
- 어느 신체 부위와 어느 시기를 조심해야 하는지, 무슨 습관을 들이면 좋은지 구체적으로. (기운이 가리키는 부위를 일상어로: 심장·혈압, 소화기·위장, 간·피로, 호흡기, 신장·방광·허리 등)

[말투] 정겹고 신비로운 존댓말, 점쟁이체("~보입니다", "~할 운이 따릅니다", "~하세요"). 'AI·분석·데이터' 단어 금지. 순수 텍스트만(HTML 금지).
[금지] 로또 당첨 보장 금지("운이 따른다"는 OK, "당첨된다"는 단정 X). 죽음·중병 단정 예언 금지(조심 권고로).
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
        'max_tokens' => 1600,
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
