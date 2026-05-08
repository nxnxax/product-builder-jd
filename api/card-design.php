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

function ocr_business_card(string $apiKey, string $imageBase64, string $mime): array {
    $resp = openai_chat($apiKey, [
        'model' => 'gpt-4o',
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' =>
                    "이 이미지가 명함이라면 다음 필드를 JSON으로 추출해줘: " .
                    "name(이름), title(직책/직함), company(회사명), email, phone, mobile, address, website, " .
                    "tagline(슬로건/한 줄 소개), industry(추정 업종 — '부동산','건설','마케팅','광고','테크','법무','의료','F&B','뷰티','교육','금융','일반' 중 하나), " .
                    "language('ko' 또는 'en' 등). " .
                    "값을 못 찾은 필드는 빈 문자열. 명함이 아니면 {\"error\":\"not_a_business_card\"}."],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $imageBase64]],
            ],
        ]],
        'response_format' => ['type' => 'json_object'],
        'max_tokens' => 700,
        'temperature' => 0.1,
    ], 45);

    if (!$resp['ok']) {
        return ['ok' => false, 'error' => $resp['body']['error']['message'] ?? ($resp['error'] ?? 'OpenAI 호출 실패')];
    }
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
    $title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $description = '';
    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) $description = trim($m[1]);
    elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/i', $html, $m)) $description = trim($m[1]);
    $themeColor = '';
    if (preg_match('/<meta[^>]+name=["\']theme-color["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) $themeColor = trim($m[1]);

    return ['title' => mb_substr($title, 0, 200), 'description' => mb_substr($description, 0, 400), 'theme_color' => $themeColor];
}

function template_palette_decision(string $apiKey, ?array $cardFields, ?array $siteMeta, string $tone): array {
    $availableTemplates = [
        'luxury_01'           => '럭셔리/프리미엄 (부동산, 건설, 호텔, 고급 서비스). 좌우 분할, 차콜+버건디+크림, 코너 마크.',
        'black_gold'          => '하이엔드 임원/전문직 (법무, 금융, 컨설팅). 다크 배경 + 골드/크림 텍스트, 모노그램.',
        'modern_corporate'    => '테크/SaaS/현대적 기업. 좌측 액센트 바, 깔끔한 그리드, 라벨링된 메타데이터.',
        'minimal_01'          => '미니멀/디자이너/예술 (스튜디오, 사진가, 작가). 흰 배경, 큰 여백, 얇은 디바이더.',
        'editorial_marketing' => '광고/마케팅/영업/엔터테인먼트. 오버사이즈 이름, 시그널 컬러 액센트 블록, 매거진 톤.',
    ];

    $palettes = [
        ['name' => 'real_estate_premium', 'primary' => '#7a0026', 'fg' => '#1a1a1a', 'neutral' => '#f8f6f1', 'secondary' => '#5a5a5a', 'mood' => '럭셔리 부동산 (버건디+크림+차콜)'],
        ['name' => 'construction_navy',   'primary' => '#0a2940', 'fg' => '#1a1a1a', 'neutral' => '#fafafa', 'secondary' => '#5a5a5a', 'mood' => '건설/엔지니어링 (네이비+화이트)'],
        ['name' => 'black_warm_gold',     'primary' => '#c9a567', 'fg' => '#f5f0e6', 'neutral' => '#0f0f0f', 'secondary' => '#a89d87', 'mood' => '하이엔드 임원 (블랙+웜골드)'],
        ['name' => 'editorial_red',       'primary' => '#e63946', 'fg' => '#0a0a0a', 'neutral' => '#fafafa', 'secondary' => '#666',    'mood' => '광고/마케팅 (시그널 레드)'],
        ['name' => 'tech_electric',       'primary' => '#0066ff', 'fg' => '#0a0a0a', 'neutral' => '#ffffff', 'secondary' => '#525252', 'mood' => '테크/SaaS (일렉트릭 블루)'],
        ['name' => 'forest_cream',        'primary' => '#1f4d3a', 'fg' => '#1a1a1a', 'neutral' => '#f4f1ea', 'secondary' => '#666',    'mood' => 'F&B/호스피탈리티 (포레스트+크림)'],
        ['name' => 'soft_charcoal',       'primary' => '#2c2c2c', 'fg' => '#1a1a1a', 'neutral' => '#f8f8f8', 'secondary' => '#777',    'mood' => '미니멀 (소프트 차콜)'],
    ];

    $brief = [];
    if ($cardFields) {
        $b = [];
        foreach (['name','title','company','industry','tagline'] as $k) {
            $v = trim((string)($cardFields[$k] ?? ''));
            if ($v !== '') $b[$k] = $v;
        }
        if ($b) $brief[] = "OCR fields: " . json_encode($b, JSON_UNESCAPED_UNICODE);
    }
    if ($siteMeta) {
        $hint = $siteMeta['description'] ?: $siteMeta['title'];
        if ($hint !== '') $brief[] = "Site context: " . mb_substr($hint, 0, 240);
    }
    if ($tone !== '') $brief[] = "User tone brief: " . $tone;
    if (!$brief) $brief[] = "No info — pick a clean default (minimal_01 with soft_charcoal).";

    $sys =
        "You are a brand designer routing a business card to one of {N} pre-built templates and one of {M} curated palettes. " .
        "Read the brief, decide the template_id and palette_name. Optionally suggest tweaks. " .
        "Available templates:\n";
    foreach ($availableTemplates as $id => $desc) $sys .= "- $id: $desc\n";
    $sys .= "\nAvailable palettes (use exact name):\n";
    foreach ($palettes as $p) $sys .= "- {$p['name']}: {$p['mood']} — primary {$p['primary']}, neutral {$p['neutral']}\n";
    $sys .= "\nReturn STRICT JSON: { \"template_id\": \"...\", \"palette_name\": \"...\", \"primary_override\": \"#hex or empty\", \"reasoning\": \"1 sentence\" }. " .
        "Choose template_id ONLY from the above list. palette_name ONLY from the above list. primary_override is empty unless the brief explicitly demands a different brand color. " .
        "Match korean realestate/construction businesses to luxury_01 + real_estate_premium UNLESS the brief says otherwise. Match marketing/advertising businesses to editorial_marketing + editorial_red.";

    $resp = openai_chat($apiKey, [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => implode("\n", $brief)],
        ],
        'response_format' => ['type' => 'json_object'],
        'max_tokens' => 300,
        'temperature' => 0.4,
    ], 30);

    $defaultPalette = $palettes[6]; // soft_charcoal as ultimate fallback
    if (!$resp['ok']) {
        return [
            'template_id' => 'minimal_01',
            'palette' => $defaultPalette,
            'reasoning' => 'fallback — OpenAI 호출 실패: ' . ($resp['body']['error']['message'] ?? 'unknown'),
        ];
    }
    $decision = json_decode((string)($resp['body']['choices'][0]['message']['content'] ?? ''), true);
    if (!is_array($decision)) {
        return ['template_id' => 'minimal_01', 'palette' => $defaultPalette, 'reasoning' => 'fallback — JSON parse failed'];
    }

    $tplId = (string)($decision['template_id'] ?? '');
    if (!isset($availableTemplates[$tplId])) $tplId = 'minimal_01';

    $palette = $defaultPalette;
    foreach ($palettes as $p) {
        if ($p['name'] === ($decision['palette_name'] ?? '')) { $palette = $p; break; }
    }

    $override = trim((string)($decision['primary_override'] ?? ''));
    if (preg_match('/^#[0-9a-f]{6}$/i', $override)) {
        $palette = array_merge($palette, ['primary' => strtolower($override)]);
    }

    return [
        'template_id' => $tplId,
        'palette'     => $palette,
        'reasoning'   => (string)($decision['reasoning'] ?? ''),
    ];
}

function build_monogram(?array $fields): string {
    if (!$fields) return 'JD';
    $candidate = trim((string)($fields['company'] ?? '')) ?: trim((string)($fields['name'] ?? ''));
    if ($candidate === '') return 'JD';
    // Use first letter of each word, max 2 chars. For Korean fall back to first char.
    $clean = preg_replace('/[^A-Za-z가-힣\s]/u', '', $candidate);
    $parts = preg_split('/\s+/', trim((string)$clean));
    $letters = '';
    foreach ($parts as $p) {
        if ($p === '') continue;
        $letters .= mb_substr($p, 0, 1, 'UTF-8');
        if (mb_strlen($letters, 'UTF-8') >= 2) break;
    }
    if ($letters === '') $letters = mb_substr($candidate, 0, 2, 'UTF-8');
    return mb_strtoupper($letters, 'UTF-8');
}

function render_template(string $templateId, array $fields, array $palette, array $dims): array {
    $path = dirname(__DIR__) . '/templates/cards/' . $templateId . '.html';
    if (!is_file($path)) {
        $path = __DIR__ . '/../templates/cards/' . $templateId . '.html';
        if (!is_file($path)) {
            $path = __DIR__ . '/templates/cards/' . $templateId . '.html';
        }
    }
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'Template file not found: ' . $templateId];
    }
    $html = (string)file_get_contents($path);

    $repl = [
        '{{width}}'     => (string)$dims['width'],
        '{{height}}'    => (string)$dims['height'],
        '{{primary}}'   => $palette['primary'],
        '{{fg}}'        => $palette['fg'],
        '{{neutral}}'   => $palette['neutral'],
        '{{secondary}}' => $palette['secondary'],
        '{{name}}'      => htmlspecialchars((string)($fields['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{title}}'     => htmlspecialchars((string)($fields['title'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{company}}'   => htmlspecialchars((string)($fields['company'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{phone}}'     => htmlspecialchars((string)($fields['phone'] ?? $fields['mobile'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{email}}'     => htmlspecialchars((string)($fields['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{address}}'   => htmlspecialchars((string)($fields['address'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{tagline}}'   => htmlspecialchars((string)($fields['tagline'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{monogram}}'  => htmlspecialchars(build_monogram($fields), ENT_QUOTES, 'UTF-8'),
    ];

    // Conditional empty-class flags so the template can hide blanks via CSS class.
    foreach (['name','title','company','phone','email','address','tagline'] as $k) {
        $val = trim((string)($fields[$k] ?? ($k === 'phone' ? ($fields['mobile'] ?? '') : '')));
        $repl["{{{$k}_empty}}"] = $val === '' ? 'empty' : '';
    }

    $rendered = strtr($html, $repl);
    return ['ok' => true, 'html' => $rendered];
}

function save_html(string $html): ?string {
    $dir = __DIR__ . '/uploads/cards';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    }
    try { $rand = bin2hex(random_bytes(5)); } catch (Throwable $e) { $rand = substr(sha1(uniqid('', true)), 0, 10); }
    $name = date('Ymd-His') . '-' . $rand . '.html';
    $path = $dir . '/' . $name;
    if (@file_put_contents($path, $html) === false) return null;
    @chmod($path, 0644);
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'youngman-biz.com';
    return $proto . '://' . $host . '/uploads/cards/' . $name;
}

// === Main ===
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && (($_GET['test'] ?? '') === 'connectivity')) {
    $hosts = ['api.openai.com', 'example.com'];
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
            'OPENAI_API_KEY_present' => load_env_value('OPENAI_API_KEY') !== '',
            'php_version' => PHP_VERSION,
        ],
        'templates' => array_values(array_map(function ($p) { return basename($p, '.html'); }, glob(dirname(__DIR__) . '/templates/cards/*.html') ?: [])),
    ]);
}

if ($method !== 'POST') jout(['ok' => false, 'error' => 'POST only'], 405);

$apiKey = load_env_value('OPENAI_API_KEY');
if ($apiKey === '') jout(['ok' => false, 'error' => 'OPENAI_API_KEY가 서버 .env에 설정되지 않았습니다.'], 500);

$siteUrl = trim((string)($_POST['siteUrl'] ?? ''));
$tone = trim((string)($_POST['tone'] ?? ''));
$hasImage = !empty($_FILES['image']) && is_array($_FILES['image']) && (int)($_FILES['image']['error'] ?? 1) === UPLOAD_ERR_OK;

if (!$hasImage && $siteUrl === '' && $tone === '') {
    jout(['ok' => false, 'error' => '명함 이미지 / 사이트 주소 / 톤 설명 중 하나는 필요합니다.'], 400);
}

$cardFields = null;
$ocrError = null;
$inputDims = ['width' => 1050, 'height' => 600];
if ($hasImage) {
    $tmp = $_FILES['image']['tmp_name'];
    if ((int)$_FILES['image']['size'] > 8 * 1024 * 1024) jout(['ok' => false, 'error' => '이미지가 너무 큽니다. 최대 8MB.'], 400);
    $mime = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: 'image/jpeg') : 'image/jpeg';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        jout(['ok' => false, 'error' => '지원되지 않는 이미지 형식: ' . $mime], 400);
    }
    $info = @getimagesize($tmp);
    if ($info && (int)$info[0] > 0 && (int)$info[1] > 0) {
        $iw = (int)$info[0]; $ih = (int)$info[1];
        $maxSide = max($iw, $ih);
        if ($maxSide > 2000) { $s = 2000 / $maxSide; $iw = (int)round($iw * $s); $ih = (int)round($ih * $s); }
        $inputDims = ['width' => $iw, 'height' => $ih];
    }
    $b64 = base64_encode((string)file_get_contents($tmp));
    $ocr = ocr_business_card($apiKey, $b64, $mime);
    if ($ocr['ok']) $cardFields = $ocr['fields']; else $ocrError = $ocr['error'] ?? 'OCR 실패';
}

$siteMeta = null;
if ($siteUrl !== '') {
    if (!preg_match('#^https?://#i', $siteUrl)) jout(['ok' => false, 'error' => '사이트 주소는 http:// 또는 https:// 로 시작해야 합니다.'], 400);
    $siteMeta = fetch_site_meta($siteUrl);
}

// Merge user-provided tone into fields so it can flow into decision context
if (!$cardFields) $cardFields = [];
if (!empty($tone) && empty($cardFields['tagline'])) $cardFields['tagline'] = '';

$decision = template_palette_decision($apiKey, $cardFields, $siteMeta, $tone);
$render = render_template($decision['template_id'], $cardFields, $decision['palette'], $inputDims);
if (!$render['ok']) {
    jout(['ok' => false, 'error' => $render['error'], 'decision' => $decision], 500);
}

$savedUrl = save_html($render['html']);
if ($savedUrl === null) jout(['ok' => false, 'error' => '결과 저장 실패'], 500);

jout([
    'ok' => true,
    'fields' => $cardFields,
    'siteMeta' => $siteMeta,
    'htmlUrl' => $savedUrl,
    'decision' => $decision,
    'note' => $ocrError ? ('OCR: ' . $ocrError) : null,
]);
