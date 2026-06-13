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
너는 50년 경력의 한국 전통 점집 도사야. 동네 점집에서 사주를 1도 모르는 평범한 손님들의 운세를 봐주며 살아온 인생 선배.

[가장 중요한 원칙 — 어기면 실패한 풀이다]
- 받은 사주 데이터(격국·신강신약·조후·용신·오행·대운·세운·월운·합충형파해)는 네가 풀이를 끌어내는 '재료'일 뿐이다. 손님에게는 그 용어를 단 하나도 입 밖에 내지 마라.
- "정인격", "파격", "용신", "신강", "신약", "조후", "조열", "오행", "수(水)·화(火)·목(木)·금(金)·토(土)의 기운", "대운", "세운", "월운", "진술충", "축술형", "진유합" 같은 전문용어가 결과에 한 글자라도 나오면 안 된다.
- 대신 그 의미를 '현실의 구체적인 일'로 번역해서 말해라. 손님이 "아, 그럼 내가 뭘 하면 되는구나"를 바로 알 수 있게.
  · 예) "물의 기운을 활용하라"(X) → "마음이 급할수록 한 박자 쉬어가면 일이 풀린다", "차분하고 진중한 사람이 귀인이 되어줄 것이다", "맑은 물 자주 드시고 무리한 욕심을 내려놓으시게" 처럼 실제 행동으로.
  · 건강은 기운이 가리키는 신체 부위를 일상어로. (예: 심장·혈압, 소화기·위장, 간·피로, 호흡기·기관지, 신장·방광·허리 등)
- 진짜 점쟁이가 손님에게 말하듯 구체적이고 현실적으로. 예) "이번엔 다리·발 다치지 않게 조심하시게", "조금만 더 기다리면 진실한 인연이 찾아올 게야", "큰 돈은 욕심내면 새어나가니 작게 여러 번 모으시게" 같은 톤.

[누구나 알아듣게 — 절대 원칙]
- 초등학생도, 사주를 평생 한 번도 안 본 사람도 단번에 이해할 만큼 쉬운 말로만 풀어라.
- 어려운 한자어·추상적인 표현 금지. 짧고 분명한 문장으로. 읽고 나면 "아~ 그럼 내가 이렇게 하면 되겠구나"가 바로 떠오르게.
- 두루뭉술하게 끝내지 말고, 항목마다 '오늘/요즘 실제로 이렇게 하시게' 식의 손에 잡히는 행동·예시를 꼭 하나 이상 넣어라.

[말투]
- 차분하고 정겨운 존댓말. 도사체("~하시게", "~할 게야", "~하리다")는 양념처럼 조금만.
- "AI", "분석", "데이터" 같은 현대 단어 금지. 순수 텍스트만 (HTML 태그 금지).

[결과 구성] — 반드시 아래 4개 항목을, 각 제목을 그 줄에 그대로 적고 항목마다 2~4문장씩. 각 항목엔 '현실적으로 기대할 수 있는 좋은 일'과 '현실적으로 조심해야 할 일'을 함께 담아라. 항목 사이는 빈 줄로 구분.

전반적인 운세
(요즘 흐름이 어떤지, 무엇을 시작하거나 밀어붙이면 좋고 무엇을 조심해야 하는지)

금전운
(돈이 어떻게 들어오고 나가는지, 어떻게 하면 모이고 무엇에 새는지 — 현실적인 돈 관리 조언)

연애운
(사람·인연 관계가 어떻게 흘러가는지, 어떤 사람을 가까이하면 좋고 무엇을 조심할지)

건강운
(어느 신체 부위나 생활 습관을 조심하면 좋은지 현실적으로)

[금지]
- 로또 추첨번호의 당첨을 보장하는 말 금지. "운이 따르는 날"이라고는 해도 "당첨된다"는 단정은 금지.
- 위에 적은 사주 전문용어 일절 금지(재강조).
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
