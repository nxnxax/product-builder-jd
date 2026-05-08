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
                if (strcasecmp($m[1], $key) === 0) {
                    return trim($m[2], "\"' \t\r\n");
                }
            }
        }
    }
    return '';
}

function openai_chat(string $apiKey, array $body, int $timeout = 60): array {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['ok' => false, 'status' => 0, 'error' => 'curl: ' . $err];
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => json_decode((string)$resp, true), 'raw' => (string)$resp];
}

function ocr_business_card(string $apiKey, string $imageBase64, string $mime): array {
    $resp = openai_chat($apiKey, [
        'model' => 'gpt-4o',
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' =>
                    "이 이미지가 명함이라면 다음 필드를 JSON으로 추출: " .
                    "name(이름), title(직책), company(회사명), email, phone, mobile, address, website, " .
                    "tagline(슬로건), industry(부동산/건설/마케팅/광고/테크/법무/의료/F&B/뷰티/교육/금융/일반 중 하나), " .
                    "language('ko' 또는 'en'). " .
                    "값을 못 찾은 필드는 빈 문자열. 명함이 아니면 {\"error\":\"not_a_business_card\"}."],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $imageBase64]],
            ],
        ]],
        'response_format' => ['type' => 'json_object'],
        'max_tokens' => 700,
        'temperature' => 0.1,
    ], 45);

    if (!$resp['ok']) return ['ok' => false, 'error' => $resp['body']['error']['message'] ?? ($resp['error'] ?? 'OpenAI 호출 실패')];
    $content = $resp['body']['choices'][0]['message']['content'] ?? '';
    $parsed = json_decode((string)$content, true);
    if (!is_array($parsed)) return ['ok' => false, 'error' => '응답 파싱 실패'];
    if (!empty($parsed['error']) && $parsed['error'] === 'not_a_business_card') {
        return ['ok' => false, 'error' => '명함으로 보이지 않는 이미지입니다.'];
    }
    return ['ok' => true, 'fields' => $parsed];
}

function fetch_site_meta(string $url): ?array {
    if (!preg_match('#^https?://#i', $url)) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CardBuilderBot/1.0)',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 400) return null;
    $html = (string)$body;
    $title = ''; $description = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) $description = trim($m[1]);
    return ['title' => mb_substr($title, 0, 200), 'description' => mb_substr($description, 0, 400)];
}

function pick_recraft_size(array $dims): array {
    $aspect = $dims['width'] / max(1, $dims['height']);
    // Recraft v3 supported sizes (per docs).
    $sizes = [
        [1024, 1024], [1365, 1024], [1024, 1365], [1536, 1024], [1024, 1536],
        [1820, 1024], [1024, 1820], [1024, 2048], [2048, 1024], [1434, 1024],
        [1024, 1434], [1024, 1280], [1280, 1024], [1024, 1707], [1707, 1024],
    ];
    $best = $sizes[0];
    $bestDelta = abs(($best[0] / $best[1]) - $aspect);
    foreach ($sizes as $s) {
        $d = abs(($s[0] / $s[1]) - $aspect);
        if ($d < $bestDelta) { $best = $s; $bestDelta = $d; }
    }
    return ['size' => $best[0] . 'x' . $best[1], 'width' => $best[0], 'height' => $best[1]];
}

function gpt_design_director(string $apiKey, ?array $cardFields, ?array $siteMeta, string $tone): array {
    $context = [];
    if ($cardFields) {
        $clean = [];
        foreach (['name','title','company','email','phone','mobile','address','tagline','industry','language'] as $k) {
            $v = trim((string)($cardFields[$k] ?? ''));
            if ($v !== '') $clean[$k] = $v;
        }
        if ($clean) $context[] = "OCR fields: " . json_encode($clean, JSON_UNESCAPED_UNICODE);
    }
    if ($siteMeta) {
        $hint = $siteMeta['description'] ?? $siteMeta['title'] ?? '';
        if ($hint !== '') $context[] = "Site context: " . mb_substr($hint, 0, 240);
    }
    if ($tone !== '') $context[] = "User tone brief: " . $tone;

    $sys = <<<SYS
You are an art director planning a single-side business card BEFORE handing off to an image generator. Read the brief and emit a STRICT JSON design plan. No prose outside JSON.

CRITICAL HIERARCHY RULES — these dictate what becomes biggest/most prominent:
1. If the card is for SALES / 영업 / 마케팅 / 광고 / real-estate sales (분양) — the HERO is the BRAND/PRODUCT/PROPERTY title (e.g., apartment brand like "브레인시티 비스타동원"), NOT the legal company name. Phone number is the SECOND most prominent element because the buyer needs to call.
2. If the card is for an executive/professional (lawyer, doctor, exec) — HERO is the person's name. Company name secondary.
3. If the card is for a creative/designer — HERO is name OR portfolio brand. Compact contact.

Identify which case applies from the OCR fields. The "company" field on a sales card might actually contain the BRAND/PRODUCT title (apartment brand) rather than the legal entity. Use the tone brief and tagline to decide.

OUTPUT JSON SHAPE (all strings; arrays where noted):
{
  "hero_text": "<the single largest piece of text — verbatim from input>",
  "hero_role": "brand_title | person_name | product_name | studio_name",
  "secondary_text": "<the second-largest text element>",
  "secondary_role": "person_name | company_name | tagline | role_title",
  "emphasized_phone": true | false,
  "phone_value": "<phone verbatim or empty>",
  "tertiary_lines": ["<remaining contact lines in display order>"],
  "layout_pattern": "left_hero_right_contact | top_hero_bottom_grid | centered_hero_bottom_strip | full_bleed_color_left",
  "color_palette": {"bg":"#hex","fg":"#hex","accent":"#hex"},
  "decorative_device": "<one short phrase, e.g. 'thin burgundy horizontal line under hero', 'small monogram top-left from initials', 'thick vertical accent bar on left edge'>",
  "background_treatment": "solid | subtle_gradient | half_color_block",
  "design_note": "<1 sentence summary of mood: e.g., '럭셔리 부동산 분양, 차분한 위계, 매거진 톤'>"
}

PALETTE GUIDANCE (override only with brand-color from OCR if present):
- 부동산/분양/건설: bg #f7f3ec (warm cream), fg #1a1a1a (charcoal), accent #7a0026 (deep burgundy)
- 마케팅/광고/영업(default): bg #fafafa, fg #0a0a0a, accent #e63946 (signal red)
- 테크: bg #ffffff, fg #0a0a0a, accent #0066ff
- 법무/금융: bg #f4f1ea, fg #0a1a2e (deep navy), accent #b08a4a (warm gold)
- F&B/호스피탈리티: bg #f4f1ea, fg #1a1a1a, accent #1f4d3a (forest)
- 뷰티/패션: bg #ffffff, fg #1a1a1a, accent #2c2c2c
SYS;

    $resp = openai_chat($apiKey, [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => implode("\n", $context)],
        ],
        'response_format' => ['type' => 'json_object'],
        'max_tokens' => 700,
        'temperature' => 0.4,
    ], 30);

    if (!$resp['ok']) return ['ok' => false, 'error' => $resp['body']['error']['message'] ?? 'director call failed'];
    $plan = json_decode((string)($resp['body']['choices'][0]['message']['content'] ?? ''), true);
    if (!is_array($plan)) return ['ok' => false, 'error' => 'plan parse failed'];
    return ['ok' => true, 'plan' => $plan];
}

function build_recraft_prompt(?array $cardFields, ?array $siteMeta, string $tone, ?array $plan = null): string {
    $hero      = trim((string)($plan['hero_text']        ?? ''));
    $heroRole  = trim((string)($plan['hero_role']        ?? ''));
    $secondary = trim((string)($plan['secondary_text']   ?? ''));
    $phone     = trim((string)($plan['phone_value']      ?? ''));
    $tertiary  = is_array($plan['tertiary_lines'] ?? null) ? array_filter(array_map('strval', $plan['tertiary_lines'])) : [];
    $emphPhone = !empty($plan['emphasized_phone']);
    $layout    = trim((string)($plan['layout_pattern']   ?? 'left_hero_right_contact'));
    $palette   = is_array($plan['color_palette'] ?? null) ? $plan['color_palette'] : [];
    $bg        = trim((string)($palette['bg']            ?? '#fafafa'));
    $fg        = trim((string)($palette['fg']            ?? '#0a0a0a'));
    $accent    = trim((string)($palette['accent']        ?? '#7a0026'));
    $deco      = trim((string)($plan['decorative_device'] ?? 'thin accent line under the hero text'));
    $bgTreat   = trim((string)($plan['background_treatment'] ?? 'solid'));
    $designNote = trim((string)($plan['design_note']     ?? ''));

    // If plan is missing, fall back to extracting from raw OCR fields.
    if ($hero === '') {
        $hero = trim((string)($cardFields['company'] ?? $cardFields['name'] ?? ''));
        $heroRole = 'brand_title';
        $secondary = trim((string)($cardFields['name'] ?? ''));
        $phone = trim((string)($cardFields['phone'] ?? $cardFields['mobile'] ?? ''));
        $tertiary = array_filter([
            (string)($cardFields['email']   ?? ''),
            (string)($cardFields['address'] ?? ''),
        ]);
    }

    $hierarchyLines = [];
    if ($hero      !== '') $hierarchyLines[] = "1. HERO (largest text on the card, extra-bold display weight): \"$hero\"";
    if ($secondary !== '') $hierarchyLines[] = "2. SECONDARY (medium weight, ~40% of hero size): \"$secondary\"";
    if ($emphPhone && $phone !== '') {
        $hierarchyLines[] = "3. PHONE NUMBER (treated as a CALL TO ACTION — bold, prominent, ~50–60% of hero size): \"$phone\"";
    } elseif ($phone !== '') {
        $tertiary[] = $phone;
    }
    foreach ($tertiary as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        $idx = count($hierarchyLines) + 1;
        $hierarchyLines[] = "{$idx}. SUPPORTING (small, low-emphasis, ~18–22% of hero size): \"$line\"";
    }

    $textBlock = "EXACT TEXT TO RENDER (use these verbatim — do NOT change spelling, do NOT romanize Korean, do NOT translate, do NOT add any words):\n" .
                 implode("\n", $hierarchyLines) . "\n";

    $colorBlock = "Color palette: background {$bg}, foreground text {$fg}, accent {$accent}. " .
                  "Background treatment: {$bgTreat}. ";
    $decoBlock = "Decorative device: {$deco}. Use the accent color sparingly only for this device or for emphasis. ";

    $layoutPhrases = [
        'left_hero_right_contact'   => 'Layout: left half holds the hero text. Right column holds person/contact info aligned right or stacked vertically.',
        'top_hero_bottom_grid'      => 'Layout: hero text spans the top third. Bottom holds a clean two-column grid: left = person/role, right = contact details.',
        'centered_hero_bottom_strip'=> 'Layout: hero centered in upper-middle. Bottom strip holds contact details in a single horizontal row.',
        'full_bleed_color_left'     => 'Layout: left 35–40% is a full-bleed accent color block holding the hero in white. Right area on the background color holds person/contact info.',
    ];
    $layoutPhrase = $layoutPhrases[$layout] ?? $layoutPhrases['left_hero_right_contact'];

    $userTone = $tone !== '' ? "User context: $tone. " : '';
    $designNoteBlock = $designNote !== '' ? "Mood: $designNote. " : '';

    $prompt =
        "Flat vector graphic design. Print-ready business card artwork (NOT a photo, NOT a mockup, NOT a card sitting on a surface). " .
        "The image IS the card — full bleed, edge-to-edge. No drop shadow. No perspective. No paper texture. No studio environment. No hand. No isometric. " .
        "Treat this as an Adobe Illustrator artboard exported as a single flat asset. " .
        $colorBlock . $decoBlock . $designNoteBlock . $userTone .
        $layoutPhrase . " " .
        "Use a Korean-aware modern sans typeface (Pretendard / Noto Sans KR style). Generous margins (≥7% from each edge). " .
        $textBlock .
        "Render every line above with PERFECT spelling. Hangul characters must be drawn correctly. " .
        "No clip art. No emoji. No fake QR codes. No stock icons. No invented logos. " .
        "Single-side front only. Strong typographic hierarchy — the HERO is unmistakably the largest, the rest follow the order above.";

    return mb_substr($prompt, 0, 1500);
}

function recraft_generate(string $apiKey, string $prompt, string $size, string $style = 'realistic_image'): array {
    $ch = curl_init('https://external.api.recraft.ai/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'prompt' => $prompt,
            'style' => $style,
            'size' => $size,
            'model' => 'recraftv3',
            'response_format' => 'url',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['ok' => false, 'error' => 'curl: ' . $err, 'status' => 0];
    $data = json_decode((string)$resp, true);

    if ($status < 200 || $status >= 300) {
        $msg = is_array($data) ? ($data['error']['message'] ?? $data['message'] ?? json_encode($data)) : substr((string)$resp, 0, 300);
        return ['ok' => false, 'error' => 'Recraft ' . $status . ': ' . $msg, 'status' => $status, 'body' => $data];
    }

    $url = is_array($data) ? ($data['data'][0]['url'] ?? $data['data'][0]['image']['url'] ?? '') : '';
    if ($url === '') return ['ok' => false, 'error' => 'No image URL in Recraft response', 'body' => $data];
    return ['ok' => true, 'url' => $url, 'credits' => $data['credits'] ?? null, 'body' => $data];
}

function save_image_from_url(string $url, string $extension = 'png'): ?string {
    $dir = __DIR__ . '/uploads/cards';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);
    $bytes = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($bytes === false || $status < 200 || $status >= 300) return null;

    try { $rand = bin2hex(random_bytes(5)); } catch (Throwable $e) { $rand = substr(sha1(uniqid('', true)), 0, 10); }
    // Detect actual extension from URL or default to png.
    if (preg_match('/\.(png|jpg|jpeg|webp|svg)(\?|$)/i', $url, $m)) {
        $extension = strtolower($m[1]);
    }
    $name = date('Ymd-His') . '-' . $rand . '.' . $extension;
    $path = $dir . '/' . $name;
    if (@file_put_contents($path, $bytes) === false) return null;
    @chmod($path, 0644);
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'youngman-biz.com';
    return $proto . '://' . $host . '/uploads/cards/' . $name;
}

// === Main ===
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && (($_GET['test'] ?? '') === 'connectivity')) {
    $hosts = ['api.openai.com', 'external.api.recraft.ai', 'example.com'];
    $results = [];
    foreach ($hosts as $h) {
        $ip = @gethostbyname($h);
        $resolved = $ip !== $h && $ip !== false;
        $results[] = ['host' => $h, 'resolved_ip' => $resolved ? $ip : null];
    }
    jout([
        'ok' => true,
        'hosts' => $results,
        'env' => [
            'OPENAI_API_KEY_present'  => load_env_value('OPENAI_API_KEY')  !== '',
            'RECRAFT_API_KEY_present' => load_env_value('RECRAFT_API_KEY') !== '',
            'php_version' => PHP_VERSION,
        ],
    ]);
}

if ($method !== 'POST') jout(['ok' => false, 'error' => 'POST only'], 405);

$openaiKey  = load_env_value('OPENAI_API_KEY');
$recraftKey = load_env_value('RECRAFT_API_KEY');
if ($recraftKey === '') jout(['ok' => false, 'error' => 'RECRAFT_API_KEY가 서버 .env에 설정되지 않았습니다.'], 500);

$siteUrl = trim((string)($_POST['siteUrl'] ?? ''));
$tone = trim((string)($_POST['tone'] ?? ''));
$hasImage = !empty($_FILES['image']) && is_array($_FILES['image']) && (int)($_FILES['image']['error'] ?? 1) === UPLOAD_ERR_OK;

if (!$hasImage && $siteUrl === '' && $tone === '') {
    jout(['ok' => false, 'error' => '명함 이미지 / 사이트 주소 / 톤 설명 중 하나는 필요합니다.'], 400);
}

$cardFields = null;
$ocrError = null;
$inputDims = ['width' => 1820, 'height' => 1024]; // landscape default
if ($hasImage) {
    $tmp = $_FILES['image']['tmp_name'];
    if ((int)$_FILES['image']['size'] > 8 * 1024 * 1024) jout(['ok' => false, 'error' => '이미지가 너무 큽니다. 최대 8MB.'], 400);
    $mime = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: 'image/jpeg') : 'image/jpeg';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        jout(['ok' => false, 'error' => '지원되지 않는 이미지 형식: ' . $mime], 400);
    }
    $info = @getimagesize($tmp);
    if ($info && (int)$info[0] > 0 && (int)$info[1] > 0) {
        $inputDims = ['width' => (int)$info[0], 'height' => (int)$info[1]];
    }
    if ($openaiKey !== '') {
        $b64 = base64_encode((string)file_get_contents($tmp));
        $ocr = ocr_business_card($openaiKey, $b64, $mime);
        if ($ocr['ok']) $cardFields = $ocr['fields']; else $ocrError = $ocr['error'] ?? 'OCR 실패';
    } else {
        $ocrError = 'OPENAI_API_KEY 미설정 — OCR 건너뜀';
    }
}

$siteMeta = null;
if ($siteUrl !== '') {
    if (!preg_match('#^https?://#i', $siteUrl)) jout(['ok' => false, 'error' => '사이트 주소는 http:// 또는 https:// 로 시작해야 합니다.'], 400);
    $siteMeta = fetch_site_meta($siteUrl);
}

if (!$cardFields) $cardFields = [];

// Step 1: GPT-4o Director plans the hierarchy (hero, secondary, phone CTA, layout, palette).
$directorPlan = null;
$directorError = null;
if ($openaiKey !== '') {
    $director = gpt_design_director($openaiKey, $cardFields, $siteMeta, $tone);
    if ($director['ok']) {
        $directorPlan = $director['plan'];
    } else {
        $directorError = $director['error'];
    }
}

$sizePick = pick_recraft_size($inputDims);
$prompt = build_recraft_prompt($cardFields, $siteMeta, $tone, $directorPlan);

// Step 2: Recraft executes. vector_illustration is the flattest output — no
// shadows, no environment, no card-on-desk mockups. realistic_image and
// digital_illustration both leak photo cues despite anti-photo prompting.
$style = 'vector_illustration';
if (mb_stripos($tone, '리얼') !== false || mb_stripos($tone, 'photo') !== false) {
    $style = 'realistic_image';
} elseif (mb_stripos($tone, '디지털') !== false || mb_stripos($tone, 'digital') !== false) {
    $style = 'digital_illustration';
}

$gen = recraft_generate($recraftKey, $prompt, $sizePick['size'], $style);
if (!$gen['ok']) {
    jout([
        'ok' => false,
        'error' => $gen['error'],
        'fields' => $cardFields,
        'ocr_error' => $ocrError,
        'prompt_preview' => mb_substr($prompt, 0, 300),
    ], 502);
}

$savedUrl = save_image_from_url($gen['url']) ?: $gen['url'];

jout([
    'ok' => true,
    'fields' => $cardFields,
    'siteMeta' => $siteMeta,
    'imageUrl' => $savedUrl,
    'recraftUrl' => $gen['url'],
    'credits' => $gen['credits'],
    'size' => $sizePick['size'],
    'style' => $style,
    'directorPlan' => $directorPlan,
    'note' => $ocrError ? ('OCR: ' . $ocrError) : ($directorError ? ('Director: ' . $directorError) : null),
]);
