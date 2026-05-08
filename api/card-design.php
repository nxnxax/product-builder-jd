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
                    "이 이미지가 명함이라면 모든 텍스트를 추출해서 JSON으로 반환해줘. 필드:\n" .
                    "- brand_title: 로고 영역에 있는 가장 시각적으로 강조된 텍스트 (분양 명함이라면 아파트 브랜드명, 광고 명함이라면 캠페인/제품명 등). 가장 크고 굵게 박힌 메인 타이틀이 여기 들어감. 보통 로고 위치(상단/좌상단/중앙 상단)에 있음.\n" .
                    "- name: 명함 주인의 이름\n" .
                    "- title: 직책 (팀장/대표/이사 등)\n" .
                    "- company: 법적 회사명 (작게 박힌 상호 — brand_title과 다를 수 있음. 예: brand_title='브레인시티 비스타동원'일 때 company='동원개발')\n" .
                    "- email\n" .
                    "- phone (대표번호)\n" .
                    "- mobile (휴대폰)\n" .
                    "- address\n" .
                    "- website\n" .
                    "- tagline (슬로건/한 줄 소개)\n" .
                    "- industry (부동산/건설/마케팅/광고/테크/법무/의료/F&B/뷰티/교육/금융/일반)\n" .
                    "- language ('ko' 또는 'en')\n" .
                    "값을 못 찾은 필드는 빈 문자열. 명함이 아니면 {\"error\":\"not_a_business_card\"}."],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $imageBase64]],
            ],
        ]],
        'response_format' => ['type' => 'json_object'],
        'max_tokens' => 800,
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

function template_palette_decision(string $apiKey, ?array $cardFields, ?array $siteMeta, string $tone): array {
    $availableTemplates = [
        'luxury_01'           => '럭셔리/프리미엄 (부동산, 건설, 호텔, 고급 서비스). 좌우 분할, 차콜+버건디+크림.',
        'black_gold'          => '하이엔드 임원/전문직 (법무, 금융, 컨설팅). 다크 배경 + 골드/크림, 모노그램.',
        'modern_corporate'    => '테크/SaaS/현대적 기업. 좌측 액센트 바, 깔끔한 그리드.',
        'minimal_01'          => '미니멀/디자이너/예술 (스튜디오, 사진가). 흰 배경, 큰 여백.',
        'editorial_marketing' => '광고/마케팅/영업/엔터테인먼트. 오버사이즈 이름, 시그널 컬러.',
    ];

    $palettes = [
        ['name' => 'real_estate_premium', 'primary' => '#7a0026', 'fg' => '#1a1a1a', 'neutral' => '#f8f6f1', 'secondary' => '#5a5a5a', 'mood' => '럭셔리 부동산'],
        ['name' => 'construction_navy',   'primary' => '#0a2940', 'fg' => '#1a1a1a', 'neutral' => '#fafafa', 'secondary' => '#5a5a5a', 'mood' => '건설/엔지니어링'],
        ['name' => 'black_warm_gold',     'primary' => '#c9a567', 'fg' => '#f5f0e6', 'neutral' => '#0f0f0f', 'secondary' => '#a89d87', 'mood' => '하이엔드 임원'],
        ['name' => 'editorial_red',       'primary' => '#e63946', 'fg' => '#0a0a0a', 'neutral' => '#fafafa', 'secondary' => '#666',    'mood' => '광고/마케팅'],
        ['name' => 'tech_electric',       'primary' => '#0066ff', 'fg' => '#0a0a0a', 'neutral' => '#ffffff', 'secondary' => '#525252', 'mood' => '테크/SaaS'],
        ['name' => 'forest_cream',        'primary' => '#1f4d3a', 'fg' => '#1a1a1a', 'neutral' => '#f4f1ea', 'secondary' => '#666',    'mood' => 'F&B/호스피탈리티'],
        ['name' => 'soft_charcoal',       'primary' => '#2c2c2c', 'fg' => '#1a1a1a', 'neutral' => '#f8f8f8', 'secondary' => '#777',    'mood' => '미니멀'],
    ];

    $brief = [];
    if ($cardFields) {
        $b = [];
        foreach (['brand_title','name','title','company','industry','tagline'] as $k) {
            $v = trim((string)($cardFields[$k] ?? ''));
            if ($v !== '') $b[$k] = $v;
        }
        if ($b) $brief[] = "OCR fields: " . json_encode($b, JSON_UNESCAPED_UNICODE);
    }
    if ($siteMeta) {
        $hint = $siteMeta['description'] ?: $siteMeta['title'];
        if ($hint !== '') $brief[] = "Site context: " . mb_substr($hint, 0, 240);
    }
    if ($tone !== '') $brief[] = "User tone: " . $tone;
    if (!$brief) $brief[] = "No info — pick minimal_01 + soft_charcoal.";

    $sys =
        "You are a brand designer routing a business card to one pre-built HTML template + curated palette. " .
        "Read the brief, decide the IDs.\n\n" .
        "ABSOLUTE HIERARCHY RULE: if `brand_title` is present in OCR, it IS the visual hero of the card and goes into the 'company' slot of the chosen template (which renders biggest). The legal 'company' name goes into 'tagline' (smaller). Person 'name' stays as 'name'.\n" .
        "Templates:\n";
    foreach ($availableTemplates as $id => $desc) $sys .= "- $id: $desc\n";
    $sys .= "\nPalettes (use exact name):\n";
    foreach ($palettes as $p) $sys .= "- {$p['name']}: {$p['mood']} — primary {$p['primary']}, neutral {$p['neutral']}\n";
    $sys .= "\nReturn STRICT JSON: { \"template_id\":\"...\", \"palette_name\":\"...\", \"primary_override\":\"#hex or empty\", \"hero_text\":\"<verbatim text that should be largest>\", \"sub_text\":\"<verbatim text that should be second>\", \"tertiary_text\":\"<verbatim small text e.g. legal company>\", \"reasoning\":\"1 sentence in Korean\" }.\n" .
        "Korean real-estate-sales (분양) → luxury_01 + real_estate_premium. brand_title is hero, person 'name 직책' is sub, legal company is tertiary.\n" .
        "Marketing/광고 → editorial_marketing + editorial_red.";

    $resp = openai_chat($apiKey, [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => implode("\n", $brief)],
        ],
        'response_format' => ['type' => 'json_object'],
        'max_tokens' => 600,
        'temperature' => 0.4,
    ], 30);

    $defaultPalette = $palettes[6];
    if (!$resp['ok']) {
        return [
            'template_id' => 'minimal_01',
            'palette' => $defaultPalette,
            'reasoning' => 'fallback — OpenAI 호출 실패: ' . ($resp['body']['error']['message'] ?? 'unknown'),
            'hero_text' => '', 'sub_text' => '', 'tertiary_text' => '',
        ];
    }
    $decision = json_decode((string)($resp['body']['choices'][0]['message']['content'] ?? ''), true);
    if (!is_array($decision)) {
        return ['template_id' => 'minimal_01', 'palette' => $defaultPalette, 'reasoning' => 'fallback — JSON parse failed', 'hero_text' => '', 'sub_text' => '', 'tertiary_text' => ''];
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
        'template_id'    => $tplId,
        'palette'        => $palette,
        'reasoning'      => (string)($decision['reasoning'] ?? ''),
        'hero_text'      => (string)($decision['hero_text'] ?? ''),
        'sub_text'       => (string)($decision['sub_text'] ?? ''),
        'tertiary_text'  => (string)($decision['tertiary_text'] ?? ''),
    ];
}

function pick_ideogram_aspect(array $dims): string {
    $aspect = $dims['width'] / max(1, $dims['height']);
    $options = [
        ['ASPECT_1_1',  1.0],
        ['ASPECT_4_3',  4/3],   ['ASPECT_3_4',  3/4],
        ['ASPECT_3_2',  3/2],   ['ASPECT_2_3',  2/3],
        ['ASPECT_16_10', 16/10], ['ASPECT_10_16', 10/16],
        ['ASPECT_16_9', 16/9],  ['ASPECT_9_16', 9/16],
        ['ASPECT_3_1',  3.0],   ['ASPECT_1_3',  1/3],
    ];
    $best = $options[0];
    $bestDelta = abs($best[1] - $aspect);
    foreach ($options as $o) {
        $d = abs($o[1] - $aspect);
        if ($d < $bestDelta) { $best = $o; $bestDelta = $d; }
    }
    return $best[0];
}

function build_ideogram_card_prompt(?array $cardFields, ?array $siteMeta, string $tone, ?array $plan = null): string {
    $hero      = trim((string)($plan['hero_text']        ?? ''));
    $secondary = trim((string)($plan['secondary_text']   ?? ''));
    $phone     = trim((string)($plan['phone_value']      ?? ''));
    $tertiary  = is_array($plan['tertiary_lines'] ?? null) ? array_filter(array_map('strval', $plan['tertiary_lines'])) : [];
    $emphPhone = !empty($plan['emphasized_phone']);
    $palette   = is_array($plan['color_palette'] ?? null) ? $plan['color_palette'] : [];
    $bg        = trim((string)($palette['bg']     ?? '#fafafa'));
    $fg        = trim((string)($palette['fg']     ?? '#0a0a0a'));
    $accent    = trim((string)($palette['accent'] ?? '#7a0026'));
    $deco      = trim((string)($plan['decorative_device']    ?? 'thin accent line under the hero'));
    $designNote = trim((string)($plan['design_note']         ?? ''));

    if ($hero === '') {
        $hero      = trim((string)($cardFields['brand_title'] ?? $cardFields['company'] ?? $cardFields['name'] ?? ''));
        $secondary = trim((string)($cardFields['name'] ?? ''));
        $phone     = trim((string)($cardFields['phone'] ?? $cardFields['mobile'] ?? ''));
        $tertiary  = array_filter([
            (string)($cardFields['title']   ?? ''),
            (string)($cardFields['email']   ?? ''),
            (string)($cardFields['address'] ?? ''),
            (string)($cardFields['company'] ?? ''),
        ]);
    }

    $industry = strtolower((string)($cardFields['industry'] ?? '') . ' ' . $tone);
    $industryHint = 'premium luxury';
    if (preg_match('/부동산|분양|건설|아파트|real.?estate|construction/u', $industry)) {
        $industryHint = 'premium Korean real estate / apartment-brand business card, 현대건설 자이 푸르지오 mood';
    } elseif (preg_match('/마케팅|광고|영업|marketing|advert/u', $industry)) {
        $industryHint = 'bold modern marketing/advertising business card, magazine-cover energy';
    } elseif (preg_match('/테크|tech|saas|it/u', $industry)) {
        $industryHint = 'modern tech startup business card, geometric, forward-looking';
    }

    $textBlock = '';
    if ($hero !== '')      $textBlock .= "Largest text (hero, bold display weight): \"$hero\". ";
    if ($secondary !== '') $textBlock .= "Secondary text: \"$secondary\". ";
    if ($emphPhone && $phone !== '') $textBlock .= "Phone number as call-to-action (bold): \"$phone\". ";
    elseif ($phone !== '') $tertiary[] = $phone;
    foreach ($tertiary as $line) {
        $line = trim((string)$line);
        if ($line !== '') $textBlock .= "Small line: \"$line\". ";
    }

    // Ideogram works best with concise, structured prompts. Tight, declarative.
    // Korean text strategy: lead with "graphic design business card with Korean text",
    // then quote each Korean string literally so the tokenizer treats them as text-to-render.
    $parts = [];
    $parts[] = 'Graphic design of a business card with Korean text';
    $parts[] = 'flat 2D vector style';
    $parts[] = $industryHint;
    $parts[] = "background color {$bg}";
    $parts[] = "primary text color {$fg}";
    $parts[] = "accent color {$accent}";
    $parts[] = $deco;
    if ($designNote !== '') $parts[] = mb_substr($designNote, 0, 80, 'UTF-8');

    // Hierarchy lines as Ideogram-friendly text instructions.
    if ($hero !== '')      $parts[] = "large bold headline reading \"{$hero}\"";
    if ($secondary !== '') $parts[] = "medium subline reading \"{$secondary}\"";
    if ($emphPhone && $phone !== '') $parts[] = "prominent bold phone number reading \"{$phone}\"";
    elseif ($phone !== '') $tertiary[] = $phone;
    foreach ($tertiary as $line) {
        $line = trim((string)$line);
        if ($line !== '') $parts[] = "small caption reading \"{$line}\"";
    }

    $parts[] = 'asymmetric editorial layout with clear hierarchy';
    $parts[] = 'generous whitespace';
    $parts[] = 'no photo no mockup no card on a desk no perspective no shadow no environment';
    $parts[] = 'edge-to-edge composition';
    $parts[] = 'single-side card design only';
    $parts[] = 'no clip art no emoji no stock icons no fake QR codes';

    $prompt = implode(', ', $parts);
    return mb_substr($prompt, 0, 1500, 'UTF-8');
}

function ideogram_generate(string $apiKey, string $prompt, string $aspect, string $styleType = 'DESIGN'): array {
    $ch = curl_init('https://api.ideogram.ai/generate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'image_request' => [
                'prompt' => $prompt,
                'aspect_ratio' => $aspect,
                'model' => 'V_2',
                'style_type' => $styleType,
                'magic_prompt_option' => 'AUTO',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'Api-Key: ' . $apiKey,
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
        $msg = is_array($data) ? ($data['error'] ?? $data['detail'] ?? json_encode($data)) : substr((string)$resp, 0, 400);
        return ['ok' => false, 'error' => 'Ideogram ' . $status . ': ' . $msg, 'status' => $status, 'body' => $data];
    }
    $first = is_array($data) && !empty($data['data'][0]) ? $data['data'][0] : null;
    $url = is_array($first) ? ($first['url'] ?? '') : '';
    if ($url === '') return ['ok' => false, 'error' => 'No image URL in Ideogram response', 'body' => $data];
    return [
        'ok' => true,
        'url' => $url,
        'resolution' => $first['resolution'] ?? null,
        'is_image_safe' => $first['is_image_safe'] ?? null,
        'seed' => $first['seed'] ?? null,
        'used_prompt' => $first['prompt'] ?? null,
    ];
}

function save_remote_image(string $url): ?string {
    $dir = __DIR__ . '/uploads/cards';
    if (!is_dir($dir) && (!@mkdir($dir, 0755, true) && !is_dir($dir))) return null;
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

    $ext = 'png';
    if (preg_match('/\.(png|jpg|jpeg|webp|svg)(\?|$)/i', $url, $m)) $ext = strtolower($m[1]);
    try { $rand = bin2hex(random_bytes(5)); } catch (Throwable $e) { $rand = substr(sha1(uniqid('', true)), 0, 10); }
    $name = 'bg-' . date('Ymd-His') . '-' . $rand . '.' . $ext;
    $path = $dir . '/' . $name;
    if (@file_put_contents($path, $bytes) === false) return null;
    @chmod($path, 0644);
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'youngman-biz.com';
    return $proto . '://' . $host . '/uploads/cards/' . $name;
}

function build_monogram(string $candidate): string {
    $candidate = trim($candidate);
    if ($candidate === '') return 'JD';
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

function render_template(string $templateId, array $renderFields, array $palette, array $dims, string $bgImageUrl = ''): array {
    $candidates = [
        dirname(__DIR__) . '/templates/cards/' . $templateId . '.html',
        __DIR__ . '/templates/cards/' . $templateId . '.html',
    ];
    $path = null;
    foreach ($candidates as $c) if (is_file($c)) { $path = $c; break; }
    if ($path === null) return ['ok' => false, 'error' => 'Template file not found: ' . $templateId];

    $html = (string)file_get_contents($path);
    $repl = [
        '{{width}}'        => (string)$dims['width'],
        '{{height}}'       => (string)$dims['height'],
        '{{primary}}'      => $palette['primary'],
        '{{fg}}'           => $palette['fg'],
        '{{neutral}}'      => $palette['neutral'],
        '{{secondary}}'    => $palette['secondary'],
        '{{bg_image_url}}' => $bgImageUrl,
    ];
    foreach (['name','title','company','phone','email','address','tagline','monogram'] as $k) {
        $val = trim((string)($renderFields[$k] ?? ''));
        $repl["{{{$k}}}"] = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
        $repl["{{{$k}_empty}}"] = $val === '' ? 'empty' : '';
    }
    return ['ok' => true, 'html' => strtr($html, $repl)];
}

function save_html(string $html): ?string {
    $dir = __DIR__ . '/uploads/cards';
    if (!is_dir($dir) && (!@mkdir($dir, 0755, true) && !is_dir($dir))) return null;
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
    jout([
        'ok' => true,
        'env' => [
            'OPENAI_API_KEY_present'   => load_env_value('OPENAI_API_KEY')   !== '',
            'RECRAFT_API_KEY_present'  => load_env_value('RECRAFT_API_KEY')  !== '',
            'IDEOGRAM_API_KEY_present' => load_env_value('IDEOGRAM_API_KEY') !== '',
            'php_version' => PHP_VERSION,
        ],
        'templates' => array_values(array_map(function ($p) { return basename($p, '.html'); }, glob(dirname(__DIR__) . '/templates/cards/*.html') ?: [])),
    ]);
}

if ($method !== 'POST') jout(['ok' => false, 'error' => 'POST only'], 405);

$openaiKey = load_env_value('OPENAI_API_KEY');
if ($openaiKey === '') jout(['ok' => false, 'error' => 'OPENAI_API_KEY가 서버 .env에 설정되지 않았습니다.'], 500);

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
        // Use the original image dimensions exactly — no scaling/cap.
        $inputDims = ['width' => (int)$info[0], 'height' => (int)$info[1]];
    }
    $b64 = base64_encode((string)file_get_contents($tmp));
    $ocr = ocr_business_card($openaiKey, $b64, $mime);
    if ($ocr['ok']) $cardFields = $ocr['fields']; else $ocrError = $ocr['error'] ?? 'OCR 실패';
}

$siteMeta = null;
if ($siteUrl !== '') {
    if (!preg_match('#^https?://#i', $siteUrl)) jout(['ok' => false, 'error' => '사이트 주소는 http:// 또는 https:// 로 시작해야 합니다.'], 400);
    $siteMeta = fetch_site_meta($siteUrl);
}

if (!$cardFields) $cardFields = [];

// Director decides palette + hero/sub/tertiary/etc. (used by both Ideogram and template fallback)
$decision = template_palette_decision($openaiKey, $cardFields, $siteMeta, $tone);

// Build the director plan in the shape build_ideogram_card_prompt expects.
$brandTitle = trim((string)($cardFields['brand_title'] ?? ''));
$personName = trim((string)($cardFields['name']        ?? ''));
$personTitle = trim((string)($cardFields['title']      ?? ''));
$legalCompany = trim((string)($cardFields['company']   ?? ''));
$phone = trim((string)($cardFields['phone'] ?? $cardFields['mobile'] ?? ''));
$email = trim((string)($cardFields['email'] ?? ''));
$address = trim((string)($cardFields['address'] ?? ''));
$ocrTagline = trim((string)($cardFields['tagline'] ?? ''));

$heroResolved = $decision['hero_text'] !== '' ? $decision['hero_text']
              : ($brandTitle !== '' ? $brandTitle : ($legalCompany !== '' ? $legalCompany : $personName));
$subResolved = $decision['sub_text'] !== '' ? $decision['sub_text']
              : trim($personName . ($personTitle !== '' ? ' ' . $personTitle : ''));
$tertiary = array_filter([
    $decision['tertiary_text'] !== '' ? $decision['tertiary_text'] : '',
    $brandTitle !== '' && $legalCompany !== '' ? $legalCompany : '',
    $email,
    $address,
]);

$ideogramPlan = [
    'hero_text'         => $heroResolved,
    'secondary_text'    => $subResolved,
    'phone_value'       => $phone,
    'emphasized_phone'  => $phone !== '',
    'tertiary_lines'    => array_values($tertiary),
    'color_palette'     => [
        'bg'     => $decision['palette']['neutral'],
        'fg'     => $decision['palette']['fg'],
        'accent' => $decision['palette']['primary'],
    ],
    'decorative_device' => 'thin accent line near the hero, single brand color used sparingly',
    'design_note'       => $decision['reasoning'] ?? '',
];

// Try Ideogram first.
$ideogramKey = load_env_value('IDEOGRAM_API_KEY');
$ideogramMeta = ['attempted' => false, 'used' => false, 'error' => null];
$ideogramImageUrl = '';
if ($ideogramKey !== '') {
    $ideogramMeta['attempted'] = true;
    $aspect = pick_ideogram_aspect($inputDims);
    $cardPrompt = build_ideogram_card_prompt($cardFields, $siteMeta, $tone, $ideogramPlan);
    $gen = ideogram_generate($ideogramKey, $cardPrompt, $aspect, 'DESIGN');
    if ($gen['ok']) {
        $saved = save_remote_image($gen['url']);
        if ($saved !== null) {
            $ideogramImageUrl = $saved;
            $ideogramMeta['used'] = true;
            $ideogramMeta['resolution'] = $gen['resolution'] ?? null;
            $ideogramMeta['seed'] = $gen['seed'] ?? null;
            $ideogramMeta['used_prompt'] = $gen['used_prompt'] ?? null;
        } else {
            $ideogramMeta['error'] = '이미지 저장 실패';
        }
    } else {
        $ideogramMeta['error'] = $gen['error'] ?? 'Ideogram 호출 실패';
    }
}

// If Ideogram succeeded, return the image directly. Otherwise fall back to
// template render so user always gets *something*.
if ($ideogramImageUrl !== '') {
    jout([
        'ok' => true,
        'fields' => $cardFields,
        'siteMeta' => $siteMeta,
        'imageUrl' => $ideogramImageUrl,
        'ideogram' => $ideogramMeta,
        'decision' => $decision,
        'plan' => $ideogramPlan,
        'note' => $ocrError ? ('OCR: ' . $ocrError) : null,
    ]);
}

// === Template fallback ===
$heroForTemplate = $heroResolved;
$smallCompanyForTemplate = $brandTitle !== '' && $legalCompany !== '' ? $legalCompany : '';

$renderFields = [
    'name'     => $personName,
    'title'    => $personTitle,
    'company'  => $heroForTemplate,
    'phone'    => $phone,
    'email'    => $email,
    'address'  => $address,
    'tagline'  => $smallCompanyForTemplate !== '' ? $smallCompanyForTemplate : $ocrTagline,
    'monogram' => build_monogram($heroForTemplate ?: $personName),
];

$render = render_template($decision['template_id'], $renderFields, $decision['palette'], $inputDims, '');
if (!$render['ok']) jout(['ok' => false, 'error' => $render['error'], 'decision' => $decision], 500);

$savedUrl = save_html($render['html']);
if ($savedUrl === null) jout(['ok' => false, 'error' => '결과 저장 실패'], 500);

jout([
    'ok' => true,
    'fields' => $cardFields,
    'siteMeta' => $siteMeta,
    'htmlUrl' => $savedUrl,
    'decision' => array_merge($decision, ['actual_template' => $decision['template_id']]),
    'ideogram' => $ideogramMeta,
    'renderFields' => $renderFields,
    'note' => $ocrError ? ('OCR: ' . $ocrError) : null,
]);
