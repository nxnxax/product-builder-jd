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
    // 사주는 추론(thinking) 모델 금지 — Qwen3.x reasoning 계열은 content 비고 reasoning 만 채움.
    // 일반 instruct 모델 사용(바로 답 생성, 빠름, 창작형에 적합).
    $llmModel = 'Qwen/Qwen2.5-72B-Instruct-Turbo';
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
1. (가장 중요) 풀이를 시작할 때, 특히 '전반적인 운세' 앞부분에서, 그 사람의 타고난 기질·성격을 사주에서 읽어 '맞아, 내가 딱 그런 면이 있지!' 하고 무릎을 치게 짚어줘라. 사주를 '성격'으로 먼저 번역해 알아맞혀야 손님이 비로소 믿는다.
   · 예) 신강+조열 → "타고난 힘이 세고 기운이 뜨거운 분이라, 추진력은 좋은데 성격이 급하고, 한번 마음먹으면 본인 뜻대로 밀어붙이는 기질입니다. 그래서 사주가 물의 기운(여유·늦춤)으로 식혀주라 말하는 겁니다."
   · 신강/신약은 '자기 주관·고집의 세기', 조후(조열/한습)는 '성격이 급한지 느긋한지·뜨거운지 차가운지', 격국은 '일을 대하는 태도'로 번역해 풀어라.
2. 사주 용어를 빼지 말고 쓰되, 던지고 끝내지 마라. 용어(용신·기운·대운·합충 등)를 말한 뒤에는 반드시 그게 현실에서 무슨 뜻인지, 무엇을 하면 이롭고 무엇을 하면 해가 되는지를 쉬운 말로 바로 풀어줘라. [용어 → 현실 해석 → 행동]이 한 흐름으로 이어지게.
   · 예) "용신이 물의 기운이라, 마음이 급할 때 한 박자 쉬어가면 일이 풀리고, 욕심내 밀어붙이면 도리어 막힙니다."
   · 용어만 적고 해석을 안 붙이면 실패다. 손님은 사주를 모른다.
3. 단락마다 사주 요소를 하나씩 기계적으로 나열하지 마라. 사주 전체(타고난 기질·지금의 큰 흐름·올해와 오늘의 기운·사람 사이의 인연과 충돌)를 머릿속에서 '종합'해, 하나의 자연스러운 이야기로 엮어라. 좋은 운과 조심할 운을 같이 섞고, 각각에 맞는 행동을 붙여라.
4. 신비롭게, 미래의 모습을 그려줘라. "앞으로 몇 해 뒤 ~한 모습이 보입니다", "올해 안에 ~할 일이 따릅니다"처럼 시기와 장면을 구체적으로. 단, 죽음·중병 같은 단정 예언은 금지하고 "~를 조심하세요"라는 경고로 바꿔라.
5. 풀이로만 끝내지 마라. 손님이 가장 궁금한 건 '그래서 나 이제 뭘 어떻게 해야 하는데?'다. 항목마다 직접적이고 분명한 행동 지침을 줘라. 거의 명령에 가깝게.

[근거 규칙 — 절대 지어내지 마라]
- 모든 풀이는 위에 '받은 사주 데이터'에서만 끌어내라. 데이터에 없는 구체 사실(정확한 시각, 임의의 방향·장소·숫자·날짜)을 근거 없이 지어내면 안 된다.
- 방향·계절·색을 말하려면 '반드시' 용신/보완 오행에서 아래 표준 매핑으로만 유도하라:
  목(木)=동쪽·봄·초록 / 화(火)=남쪽·여름·붉은색 / 금(金)=서쪽·가을·흰색 / 수(水)=북쪽·겨울·검정·파랑 / 토(土)=중앙·환절기·노랑.
  (예: 용신이 물(水)이면 → 북쪽·겨울·물가·파란색이 이로움. 이때만 "북쪽"이라 말할 수 있다.)
- 사주에 시(時) 정보가 없으니 '아침/오후/저녁' 같은 하루 중 시각은 함부로 단정하지 마라. 시기는 데이터의 대운·세운·월운 흐름에서 '며칠 뒤·올해 안·내년 봄'처럼 끌어내라.

[행동 지침 — 구체적으로]
- "차분히 하세요", "균형을 유지하세요", "긍정적으로 생각하세요" 같은 두루뭉실하고 추상적인 말 금지.
- 특히 '덕목을 갖춰라' 류의 빈 말 금지: "여유를 가지면/융통성을 갖추면/인내하면/마음의 평정을 유지하면 좋다" 처럼 추상 덕목어(여유·융통성·균형·긍정·인내·감사·평정·유연함·마음가짐 등)를 '갖추라·유지하라'로 끝내지 마라. 그 단어를 쓰려면 반드시 '구체적으로 어떻게 하는 것인지'를 행동으로 풀어줘라.
  · (X) "여유와 융통성을 갖추면 더 큰 성취를 이룬다" → (O) "하루에 약속을 몰아 잡지 말고 일정 사이에 빈 시간을 남기세요. 또 계획이 틀어지면 고집부리지 말고 미리 정해둔 차선책으로 바로 갈아타세요."
- 누가 읽어도 당장 따라 할 수 있는 '행동·시간·사람·돈·장소' 단위의 구체 행동으로 적어라. 예)
  · (X) "돈 관리에 신경 쓰세요" → (O) "큰 계약은 이번 달을 넘겨 다음 달에 도장 찍으시고, 매달 월급의 일부를 먼저 떼어 저축하세요."
  · (X) "사람을 조심하세요" → (O) "이번 주엔 오랜 친구의 돈 부탁을 거절하세요. 빌려주면 사람도 돈도 잃습니다."
  · (X) "건강을 챙기세요" → (O) "기름진 술자리를 이번 여름엔 절반으로 줄이고, 일주일에 세 번 30분씩 걸으세요."
- 자가검열: 문장을 쓴 뒤 '이걸 읽은 손님이 당장 무엇을 할지 바로 떠올릴 수 있는가?'를 스스로 확인하라. 안 떠오르면 그 문장은 버리고 구체적인 행동으로 다시 써라. 마무리 문장도 예외 없다.

[단점·약점 — 솔직하고 자세히]
- 좋은 말만 늘어놓지 마라. 그 사람의 기질에서 비롯되는 단점·약점·실수하기 쉬운 지점을, 장점 못지않게(혹은 그 이상) 솔직하고 '자세히' 짚어줘라. 손님은 자기 약점을 알아맞혀 줄 때 가장 크게 공감하고 믿는다.
- 두루뭉실하게 한 줄로 넘기지 말고 '어떤 상황에서 어떤 실수를 저지르기 쉬운지'까지 구체적으로 그려줘라.
  · 예) "급한 성격 탓에 충분히 알아보지도 않고 계약서에 도장부터 찍었다가 손해 보기 쉽습니다. 특히 큰돈 걸린 일은 주변이 말려도 본인 고집대로 밀어붙이다 탈이 나니, 이런 분은 결정 전에 꼭 한 사람에게 반대 의견을 들어야 합니다."
- 항목마다 '이 사람이 조심해야 할 약점'을 최소 하나씩 넣어라. 단, 겁주거나 저주하지 말고 '미리 알면 피할 수 있다'는 따뜻한 경고로 풀어라.

[항목] — 아래 4개를 제목 그대로 한 줄에 적고, 각 3~5문장. 위 방식으로 종합해 풀어라. 각 항목엔 좋은 점과 '조심할 약점'을 함께 담아라. 항목 사이는 빈 줄로 구분.

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
        'max_tokens' => 2000,
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
if ($reading === '') {
    if (($_GET['dbg'] ?? '') === '1') {
        jout(['ok' => false, 'error' => '응답 비어있음', 'model' => $llmModel, 'status' => $status,
              'raw' => substr((string)$resp, 0, 1500)], 502);
    }
    jout(['ok' => false, 'error' => '응답 비어있음'], 502);
}

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
