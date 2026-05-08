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
    if ($resp === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'curl: ' . $err];
    }
    $decoded = json_decode((string)$resp, true);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $decoded, 'raw' => (string)$resp];
}

function research_design_brief(string $apiKey, ?array $cardFields, ?array $siteMeta, string $tone): string {
    $companyName = trim((string)($cardFields['company'] ?? ''));
    $title = trim((string)($cardFields['title'] ?? ''));
    $industryHint = $title ?: ($siteMeta['description'] ?? '') ?: 'advertising / marketing sales';
    $effectiveTone = $tone !== '' ? $tone : '광고영업이미지로 마케팅 명함 멋지게 만들어줘 (slick advertising/marketing-sales business card, bold and confident, ad-poster energy)';

    $query =
        "I'm designing a business card for: " . ($companyName ? "company \"$companyName\", " : '') .
        "industry/role: $industryHint. Tone brief: $effectiveTone.\n\n" .
        "Search the web for the top 2025 premium business card design references — specifically for marketing/advertising/sales professionals. " .
        "Look at award-winning portfolios (Behance, Awwwards, It's Nice That, Dieline) and current trends in editorial design. " .
        "Then synthesize a concrete DESIGN DNA in 4–6 sentences: " .
        "(a) Layout pattern (e.g. 'oversized name bleeding off the right edge with a thin horizontal accent line at top-third'), " .
        "(b) Exact color palette (3 hex codes max, name them), " .
        "(c) Typography hierarchy (which element is the hero, weight contrast), " .
        "(d) One distinctive graphic device (e.g. 'thick diagonal slash at bottom-left', 'oversized number 2025 as watermark', 'asterisk monogram'). " .
        "Be specific and opinionated — no generic advice. Output only the design DNA prose, no preamble, no URLs.";

    $resp = openai_chat($apiKey, [
        'model' => 'gpt-4o-search-preview',
        'web_search_options' => new \stdClass(),
        'messages' => [['role' => 'user', 'content' => $query]],
        'max_tokens' => 800,
    ], 45);

    if (!$resp['ok']) {
        // Soft-fail: return empty string and let SVG step proceed without research notes.
        return '';
    }
    $brief = (string)($resp['body']['choices'][0]['message']['content'] ?? '');
    return trim($brief);
}

function generate_card_svg(string $apiKey, ?array $cardFields, ?array $siteMeta, string $tone, array $dims = ['width' => 1050, 'height' => 600], string $researchBrief = ''): array {
    $w = max(300, min(2400, (int)$dims['width']));
    $h = max(200, min(2400, (int)$dims['height']));
    $context = [];
    if ($cardFields) {
        $clean = [];
        foreach (['name','title','company','email','phone','mobile','address','website','tagline'] as $k) {
            $v = trim((string)($cardFields[$k] ?? ''));
            if ($v !== '') $clean[$k] = $v;
        }
        if (!empty($cardFields['colors']) && is_array($cardFields['colors'])) {
            $clean['brand_colors'] = array_slice(array_filter(array_map('strval', $cardFields['colors'])), 0, 3);
        }
        if (!empty($cardFields['language'])) $clean['language'] = $cardFields['language'];
        if ($clean) $context[] = "OCR'd fields from the original card: " . json_encode($clean, JSON_UNESCAPED_UNICODE);
    }
    if ($siteMeta) {
        $hint = $siteMeta['og_description'] ?: $siteMeta['description'];
        if ($hint !== '') $context[] = "Brand context from website: " . mb_substr($hint, 0, 240);
        if (!empty($siteMeta['theme_color'])) $context[] = "Brand color hint: " . $siteMeta['theme_color'];
    }
    if ($tone !== '') {
        $context[] = "Design tone requested by user: $tone";
    } else {
        $context[] = "Default brief from the operator: 광고영업이미지로 마케팅 명함 멋지게 만들어줘 (design a slick MARKETING / ADVERTISING-SALES business card — bold, confident, attention-grabbing, energetic). This is for someone who sells visibility for a living; the card itself must look like an ad worth keeping.";
    }
    if (!$cardFields && !$siteMeta) $context[] = "No extracted info available — invent nothing; instead create a tasteful placeholder layout that demonstrates the design system, using the literal label 'Sample' if absolutely necessary.";

    if ($researchBrief !== '') {
        $context[] = "REAL-WORLD DESIGN DNA (synthesized from current 2025 references via web search) — follow this closely:\n" . $researchBrief;
    }

    $sys = "You are the lead brand designer at a top-tier studio (think Pentagram, Mucca, COLLINS). Your task: design a luxurious, distinctive business card SVG that captures the company's brand identity.\n" .
        "\n" .
        "PROCESS — execute internally before writing SVG:\n" .
        "1. Infer the company's brand essence from name + industry + tagline. What kind of business is this? (Tech startup? Law firm? Cafe? Designer studio? Real estate? Medical?). What are its likely values, customer demographic, and emotional tone?\n" .
        "2. Choose a sophisticated visual identity that matches:\n" .
        "   - Marketing / Advertising sales (DEFAULT for this product) → bold confident typography (display weight or heavy italic), saturated accent color (electric blue, hot orange, magenta, signal yellow) on dark background OR high-contrast white, oversized name or oversized initials, a strong graphic element like a thick diagonal stripe / bold arrow / large quoted tagline / magazine-cover treatment. Energetic. Looks like an Ogilvy or Wieden+Kennedy poster scaled to card size.\n" .
        "   - Tech/SaaS → bold geometric sans, single saturated accent on near-black or off-white, asymmetric composition.\n" .
        "   - Law/Finance → conservative serif or refined neo-grotesk, deep navy/charcoal + cream, classic centered or hierarchy-grid layout, monogram.\n" .
        "   - F&B/Hospitality → warm cream/terracotta/forest, elegant display serif, generous letterpress feel.\n" .
        "   - Beauty/Fashion → ultra-minimal, oversized thin display serif, single elegant photo-like color block or gradient, lots of whitespace.\n" .
        "   - Studio/Creative → expressive type, unconventional grid, maybe one bold rotated element.\n" .
        "   - Korean SMEs → premium sans-serif Korean type pairing (Pretendard) with restrained accents, subtle gradient possible.\n" .
        "3. Pick the layout that elevates the identity (NOT a generic centered card with everything in the middle). Acceptable patterns:\n" .
        "   a. Big monogram top-left + tiny info column bottom-right.\n" .
        "   b. Full-bleed gradient/color half + clean info on the other half.\n" .
        "   c. Oversized company name as the design hero, contact details a thin column.\n" .
        "   d. Tall vertical accent bar with company name climbing it.\n" .
        "   e. Thick sans display name with tiny labelled metadata (Title · Email · Phone) in a baseline grid below.\n" .
        "4. Use a SOPHISTICATED color pair. Avoid pure black on white unless the brand demands it. Examples: charcoal #1f2937 + warm cream #f7f3ec; deep emerald #064e3b + ivory; midnight navy #0f172a + warm gold #d4a87b; soft sand #ecdfca + espresso #3a2515. Brand colors from OCR override this if present.\n" .
        "\n" .
        "HARD CONSTRAINTS:\n" .
        "- viewBox=\"0 0 {$w} {$h}\" with width=\"{$w}\" height=\"{$h}\". Match this aspect ratio precisely.\n" .
        "- Pure SVG only. No external fonts, no <image>, no scripts, no foreignObject. Font stack: font-family=\"-apple-system, 'SF Pro Display', 'SF Pro Text', 'Pretendard', 'Apple SD Gothic Neo', 'Helvetica Neue', sans-serif\".\n" .
        "- Print every supplied field EXACTLY as given. No spelling changes. No Romanization of Korean. No invented info. No placeholders like 'Your name'.\n" .
        "- All text must fit inside the card with healthy padding (≥ 5% from each edge). Never let text touch or overflow the edge.\n" .
        "- Hierarchy is mandatory: ONE element should be the visual hero — typically the name OR the company name OR a monogram — significantly larger/bolder than everything else. The eye must know where to land first.\n" .
        "- Decorative elements must be pure geometry (rect, line, circle, path with simple commands). NO clip-art, NO emoji, NO fake QR codes, NO stock icons, NO faux Lorem.\n" .
        "- Use generous whitespace. Crowded cards are forbidden.\n" .
        "- For Korean text, use natural CJK tracking (letter-spacing 0 or slightly negative). Don't space-out individual Korean characters.\n" .
        "- DO NOT produce a generic, centered, plain layout. If the only safe move you can think of is centered black text on white, you have failed; pick a more committed layout.\n" .
        "\n" .
        "OUTPUT: ONLY the raw SVG, starting with <svg ...> and ending with </svg>. No markdown fences. No commentary. No <!-- comments --> outside the svg.";

    $user = implode("\n", $context);

    $resp = openai_chat($apiKey, [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ],
        'max_tokens' => 4000,
        'temperature' => 0.85,
    ], 60);

    if (!$resp['ok']) {
        $msg = $resp['body']['error']['message'] ?? ($resp['error'] ?? 'OpenAI 호출 실패');
        return ['ok' => false, 'error' => $msg, 'status' => $resp['status']];
    }

    $content = (string)($resp['body']['choices'][0]['message']['content'] ?? '');
    // Strip optional ``` fences if the model still wraps.
    $content = preg_replace('/^\s*```(?:svg|xml)?\s*/i', '', $content);
    $content = preg_replace('/\s*```\s*$/', '', $content);
    $content = trim((string)$content);

    if (stripos($content, '<svg') === false) {
        return ['ok' => false, 'error' => 'SVG 응답이 비어있습니다.', 'preview' => mb_substr($content, 0, 200)];
    }
    // Trim anything before <svg
    $svgStart = stripos($content, '<svg');
    $svgEnd = strripos($content, '</svg>');
    if ($svgEnd === false) {
        return ['ok' => false, 'error' => 'SVG 끝 태그가 없습니다.', 'preview' => mb_substr($content, 0, 200)];
    }
    $svg = substr($content, $svgStart, $svgEnd - $svgStart + 6);

    return ['ok' => true, 'svg' => $svg];
}

function ocr_business_card(string $apiKey, string $imageBase64, string $mime): array {
    $resp = openai_chat($apiKey, [
        'model' => 'gpt-4o',
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' =>
                    "이 이미지가 명함이라면 다음 필드를 JSON으로 추출해줘: " .
                    "name(이름), title(직책/직함), company(회사명), email, phone, mobile, address, website, " .
                    "tagline(슬로건/한 줄 소개), other(기타 줄들 배열). " .
                    "값을 못 찾은 필드는 빈 문자열 또는 빈 배열. " .
                    "추가로 colors(이미지에서 추출한 주요 색상 hex 코드 1~3개 배열), " .
                    "language('ko' 또는 'en' 등)도 함께 반환. " .
                    "명함이 아니면 {\"error\":\"not_a_business_card\"}."],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $imageBase64]],
            ],
        ]],
        'response_format' => ['type' => 'json_object'],
        'max_tokens' => 800,
        'temperature' => 0.1,
    ], 45);

    if (!$resp['ok']) {
        return ['ok' => false, 'status' => $resp['status'], 'error' => $resp['body']['error']['message'] ?? ($resp['error'] ?? 'OpenAI 호출 실패')];
    }
    $content = $resp['body']['choices'][0]['message']['content'] ?? '';
    $parsed = json_decode((string)$content, true);
    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => '응답을 파싱하지 못했습니다.'];
    }
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
    $title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $description = '';
    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) $description = trim($m[1]);
    elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/i', $html, $m)) $description = trim($m[1]);
    $ogTitle = '';
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) $ogTitle = trim($m[1]);
    $ogDescription = '';
    if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) $ogDescription = trim($m[1]);
    $themeColor = '';
    if (preg_match('/<meta[^>]+name=["\']theme-color["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) $themeColor = trim($m[1]);

    return [
        'title' => mb_substr($title, 0, 200),
        'description' => mb_substr($description, 0, 400),
        'og_title' => mb_substr($ogTitle, 0, 200),
        'og_description' => mb_substr($ogDescription, 0, 400),
        'theme_color' => $themeColor,
    ];
}

function save_svg_string(string $svg): ?string {
    $dir = __DIR__ . '/uploads/cards';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    }
    try {
        $rand = bin2hex(random_bytes(5));
    } catch (Throwable $e) {
        $rand = substr(sha1(uniqid('', true)), 0, 10);
    }
    $name = date('Ymd-His') . '-' . $rand . '.svg';
    $path = $dir . '/' . $name;
    if (@file_put_contents($path, $svg) === false) return null;
    @chmod($path, 0644);
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'youngman-biz.com';
    return $proto . '://' . $host . '/uploads/cards/' . $name;
}

function build_dalle_prompt(?array $cardFields, ?array $siteMeta, string $tone): string {
    $parts = [];
    $parts[] = "Design an elegant, premium-quality business card front. Photorealistic studio mockup on a clean neutral background, soft shadows, sharp typography. Composition is exactly a horizontal business card (3.5:2 aspect ratio).";

    if ($cardFields) {
        $name = trim((string)($cardFields['name'] ?? ''));
        $title = trim((string)($cardFields['title'] ?? ''));
        $company = trim((string)($cardFields['company'] ?? ''));
        $email = trim((string)($cardFields['email'] ?? ''));
        $phone = trim((string)($cardFields['phone'] ?? $cardFields['mobile'] ?? ''));
        $tagline = trim((string)($cardFields['tagline'] ?? ''));

        $details = [];
        if ($name !== '') $details[] = "name: $name";
        if ($title !== '') $details[] = "title: $title";
        if ($company !== '') $details[] = "company: $company";
        if ($email !== '') $details[] = "email: $email";
        if ($phone !== '') $details[] = "phone: $phone";
        if ($tagline !== '') $details[] = "tagline: $tagline";
        if ($details) $parts[] = "Print these on the card exactly as written, do not change spelling: " . implode(' | ', $details) . ".";

        if (!empty($cardFields['colors']) && is_array($cardFields['colors'])) {
            $colors = array_filter(array_map('strval', $cardFields['colors']));
            if ($colors) $parts[] = "Brand colors to use sparingly as accents: " . implode(', ', array_slice($colors, 0, 3)) . ".";
        }
    }

    if ($siteMeta) {
        $tagline = $siteMeta['og_description'] ?: $siteMeta['description'];
        if ($tagline !== '') $parts[] = "Reference brand context (do not print this verbatim): \"" . mb_substr($tagline, 0, 200) . "\".";
        if (!empty($siteMeta['theme_color'])) $parts[] = "Brand accent color hint: " . $siteMeta['theme_color'] . ".";
    }

    if ($tone !== '') {
        $parts[] = "Design tone: $tone.";
    } else {
        $parts[] = "Design tone: minimal, modern, premium — Apple/Linear-quality typography.";
    }

    $parts[] = "Use only Latin and Korean characters as appropriate for the names. Ensure all printed text is fully legible and correctly spelled. Return only the business card image, centered, no extra UI or annotations.";

    return implode(' ', $parts);
}

function save_image_from_url(string $url): ?string {
    $dir = __DIR__ . '/uploads/cards';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);
    $bytes = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($bytes === false || $status < 200 || $status >= 300) return null;

    try {
        $rand = bin2hex(random_bytes(5));
    } catch (Throwable $e) {
        $rand = substr(sha1(uniqid('', true)), 0, 10);
    }
    $name = date('Ymd-His') . '-' . $rand . '.png';
    $path = $dir . '/' . $name;
    if (@file_put_contents($path, $bytes) === false) return null;
    @chmod($path, 0644);

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'youngman-biz.com';
    return $proto . '://' . $host . '/uploads/cards/' . $name;
}

// === Main ===
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Connectivity diagnostic — visit /card-design.php?test=connectivity to check
// what hosts the cafe24 PHP environment can resolve and reach.
if ($method === 'GET' && (($_GET['test'] ?? '') === 'connectivity')) {
    $hosts = [
        'api.openai.com',
        'api.anthropic.com',
        'generativelanguage.googleapis.com',
        'xktjucyijpkopkyvxovh.supabase.co',
        'example.com',
    ];
    $results = [];
    foreach ($hosts as $h) {
        $ip = @gethostbyname($h);
        $resolved = $ip !== $h && $ip !== false;
        $reach = null;
        if ($resolved) {
            $ch = curl_init('https://' . $h . '/');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_USERAGENT => 'connectivity-test/1.0',
            ]);
            curl_exec($ch);
            $reach = [
                'http_code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'connect_time' => (float)curl_getinfo($ch, CURLINFO_CONNECT_TIME),
                'curl_error' => curl_error($ch) ?: null,
            ];
            curl_close($ch);
        }
        $results[] = [
            'host' => $h,
            'resolved_ip' => $resolved ? $ip : null,
            'reach' => $reach,
        ];
    }
    $envHasKey = load_env_value('OPENAI_API_KEY') !== '';
    jout([
        'ok' => true,
        'hosts' => $results,
        'env' => [
            'OPENAI_API_KEY_present' => $envHasKey,
            'php_version' => PHP_VERSION,
            'allow_url_fopen' => (bool)ini_get('allow_url_fopen'),
            'curl_loaded' => function_exists('curl_init'),
        ],
    ]);
}

if ($method !== 'POST') jout(['ok' => false, 'error' => 'POST only'], 405);

$apiKey = load_env_value('OPENAI_API_KEY');
if ($apiKey === '') jout(['ok' => false, 'error' => 'OPENAI_API_KEY가 서버 .env에 설정되지 않았습니다.'], 500);

$siteUrl = trim((string)($_POST['siteUrl'] ?? ''));
$tone = trim((string)($_POST['tone'] ?? ''));
$hasImage = !empty($_FILES['image']) && is_array($_FILES['image']) && (int)($_FILES['image']['error'] ?? 1) === UPLOAD_ERR_OK;

if (!$hasImage && $siteUrl === '') {
    jout(['ok' => false, 'error' => '명함 이미지 또는 사이트 주소가 필요합니다.'], 400);
}

$cardFields = null;
$ocrError = null;
$inputDims = ['width' => 1050, 'height' => 600]; // default landscape 3.5:2
if ($hasImage) {
    $tmp = $_FILES['image']['tmp_name'];
    $size = (int)$_FILES['image']['size'];
    if ($size > 8 * 1024 * 1024) {
        jout(['ok' => false, 'error' => '이미지가 너무 큽니다. 최대 8MB.'], 400);
    }
    $mime = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: 'image/jpeg') : 'image/jpeg';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        jout(['ok' => false, 'error' => '지원되지 않는 이미지 형식: ' . $mime], 400);
    }
    $info = @getimagesize($tmp);
    if ($info && (int)$info[0] > 0 && (int)$info[1] > 0) {
        // Cap longest side at 2000 to keep SVG numbers reasonable, preserve aspect ratio.
        $iw = (int)$info[0];
        $ih = (int)$info[1];
        $maxSide = max($iw, $ih);
        if ($maxSide > 2000) {
            $scale = 2000 / $maxSide;
            $iw = (int)round($iw * $scale);
            $ih = (int)round($ih * $scale);
        }
        $inputDims = ['width' => $iw, 'height' => $ih];
    }
    $b64 = base64_encode((string)file_get_contents($tmp));
    $ocr = ocr_business_card($apiKey, $b64, $mime);
    if ($ocr['ok']) {
        $cardFields = $ocr['fields'];
    } else {
        $ocrError = $ocr['error'] ?? 'OCR 실패';
    }
}

$siteMeta = null;
if ($siteUrl !== '') {
    if (!preg_match('#^https?://#i', $siteUrl)) {
        jout(['ok' => false, 'error' => '사이트 주소는 http:// 또는 https:// 로 시작해야 합니다.'], 400);
    }
    $siteMeta = fetch_site_meta($siteUrl);
}

// Step 1: Web-search-augmented research call to extract a current design DNA brief.
$researchBrief = research_design_brief($apiKey, $cardFields, $siteMeta, $tone);

// Step 2: Generate the SVG card using OCR data + research brief.
$gen = generate_card_svg($apiKey, $cardFields, $siteMeta, $tone, $inputDims, $researchBrief);

if (!$gen['ok']) {
    jout([
        'ok' => false,
        'error' => $gen['error'] ?? 'SVG 생성 실패',
        'fields' => $cardFields,
        'ocr_error' => $ocrError,
        'site_meta' => $siteMeta,
    ], 502);
}

$savedUrl = save_svg_string($gen['svg']);
if ($savedUrl === null) {
    jout(['ok' => false, 'error' => 'SVG 저장 실패', 'fields' => $cardFields], 500);
}

jout([
    'ok' => true,
    'fields' => $cardFields,
    'siteMeta' => $siteMeta,
    'imageUrl' => $savedUrl,
    'researchBrief' => $researchBrief !== '' ? $researchBrief : null,
    'note' => $ocrError ? ('OCR: ' . $ocrError) : null,
]);
