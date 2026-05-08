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

function build_recraft_prompt(?array $cardFields, ?array $siteMeta, string $tone): string {
    $name = trim((string)($cardFields['name'] ?? ''));
    $title = trim((string)($cardFields['title'] ?? ''));
    $company = trim((string)($cardFields['company'] ?? ''));
    $phone = trim((string)($cardFields['phone'] ?? $cardFields['mobile'] ?? ''));
    $email = trim((string)($cardFields['email'] ?? ''));
    $address = trim((string)($cardFields['address'] ?? ''));
    $tagline = trim((string)($cardFields['tagline'] ?? ''));
    $industry = strtolower(trim((string)($cardFields['industry'] ?? '')));

    $stylePresets = [
        '부동산'   => 'premium real estate developer business card, sophisticated charcoal + burgundy + cream palette, modern Swiss editorial layout, hairline divider, generous whitespace, brand feeling of 현대건설 자이 푸르지오 디에이치, ample padding, no clutter',
        'real estate' => 'premium real estate developer business card, sophisticated charcoal + burgundy + cream palette, modern Swiss editorial layout, hairline divider, generous whitespace, brand feeling of premium Korean apartment brands, ample padding, no clutter',
        '건설'     => 'premium construction company business card, navy + cream + charcoal palette, geometric grid, hairline accents, minimal, executive feel',
        '마케팅'   => 'bold modern marketing agency business card, signal red or hot orange accent, oversized hero typography, asymmetric editorial grid, magazine cover energy',
        '광고'     => 'bold modern advertising agency business card, signal red or hot orange accent, oversized hero typography, asymmetric editorial grid, magazine cover energy',
        '테크'     => 'modern tech startup business card, electric blue or signal accent on near-white background, geometric sans typography, asymmetric layout, clean and forward-looking',
        '법무'     => 'sophisticated law firm business card, deep navy + warm cream palette, refined neo-grotesk typography, classic monogram, conservative hierarchy',
        '의료'     => 'modern medical professional business card, soft blue + white + grey palette, clean clinical aesthetic, restrained',
        'f&b'     => 'warm hospitality business card, terracotta or forest green + cream palette, elegant display serif, letterpress feel',
        '뷰티'     => 'ultra-minimal beauty industry business card, oversized thin display serif, single elegant color block, lots of whitespace',
        '교육'     => 'modern education business card, navy + warm cream palette, refined sans, hierarchy-grid, trustworthy',
        '금융'     => 'sophisticated finance business card, deep navy + gold accent palette, refined neo-grotesk, monogram, executive feel',
    ];
    $stylePhrase = '';
    foreach ($stylePresets as $key => $phrase) {
        if ($key !== '' && (mb_stripos($industry, $key) !== false || mb_stripos((string)$company, $key) !== false || mb_stripos((string)$tone, $key) !== false)) {
            $stylePhrase = $phrase;
            break;
        }
    }
    if ($stylePhrase === '') {
        $stylePhrase = 'premium luxury business card, sophisticated charcoal + cream palette, modern minimal Swiss editorial layout, ample whitespace, hairline divider, refined typography';
    }

    $userTone = $tone !== '' ? "User design brief: $tone. " : '';

    // Korean text in Recraft prompts: keep verbatim with strict instruction to render exactly.
    $textInstructions = [];
    if ($name) $textInstructions[] = "name '$name'";
    if ($title) $textInstructions[] = "title '$title'";
    if ($company) $textInstructions[] = "company '$company'";
    if ($phone) $textInstructions[] = "phone '$phone'";
    if ($email) $textInstructions[] = "email '$email'";
    if ($address) $textInstructions[] = "address '$address'";
    if ($tagline) $textInstructions[] = "tagline '$tagline'";

    $textPart = $textInstructions
        ? "Print exactly these texts on the card, perfectly legible, do not change spelling, do not romanize Korean, render Hangul accurately: " . implode('; ', $textInstructions) . ". "
        : '';

    $prompt =
        "FLAT 2D GRAPHIC DESIGN of a business card, NOT a photo, NOT a 3D mockup, NOT a card on a desk. " .
        "The output IS the card itself — edge-to-edge, full bleed. " .
        "No drop shadows, no perspective, no paper texture, no studio background, no holding hand, no isometric view. " .
        "Treat this like a print-ready Adobe Illustrator artboard. " .
        $stylePhrase . ". " .
        $userTone .
        $textPart .
        "Layout: clear typographic hierarchy with the person's name as the largest element OR an oversized company name as hero, contact details smaller. " .
        "Use a thin geometric accent line or color block as decorative device. " .
        "Use Korean-aware sans typeface like Pretendard for Korean text. " .
        "No clip art, no emoji, no fake QR codes, no stock icons, no logos you did not invent. " .
        "Generous margins around all edges (at least 6%). " .
        "Asymmetric or grid-anchored compositions strongly preferred. " .
        "Single-side card front only. The entire image area is the card surface, filling the frame edge to edge.";

    // Cap length — Recraft has prompt length limits.
    return mb_substr($prompt, 0, 1000);
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

$sizePick = pick_recraft_size($inputDims);
$prompt = build_recraft_prompt($cardFields, $siteMeta, $tone);

// Default style: digital_illustration produces flat graphic-design output
// (the actual card as a flat artwork) instead of a photo of a card.
// realistic_image makes Recraft compose a studio shot of a card on a desk —
// not what we want. vector_illustration is even flatter but text rendering
// can be weaker. digital_illustration is the sweet spot for premium card
// graphics with legible Korean text.
$style = 'digital_illustration';
if (mb_stripos($tone, '벡터') !== false || mb_stripos($tone, 'vector') !== false || mb_stripos($tone, '일러스트') !== false) {
    $style = 'vector_illustration';
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
    'note' => $ocrError ? ('OCR: ' . $ocrError) : null,
]);
