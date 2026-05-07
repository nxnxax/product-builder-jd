<?php
header('Content-Type: application/json; charset=utf-8');

// --- Naver API Configuration ---
$CONFIG = [
    'API_KEY' => "01000000003722b9ac34402bfaf57ad299324c5aa7ee19f045097597a7b3d5b4aa13773684",
    'SECRET_KEY' => "AQAAAABPpWpWLqcB3Ptzm2v2AUEqulSZh0ZqII5+OO+usyRiTQ==",
    'CUSTOMER_ID' => "436324",
    'NAVER_CLIENT_ID' => "rC3G5rYjmcPecMZidCAP",
    'NAVER_CLIENT_SECRET' => "DDofjHvdTw",
    'BASE_URL' => "https://api.searchad.naver.com",
    'URI' => "/keywordstool",
    'METHOD' => "GET"
];

// --- Input Handling ---
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);
$keywordsText = $input['keywords'] ?? '';

if (!$keywordsText) {
    echo json_encode(['error' => '키워드를 입력해주세요.']);
    exit;
}

$keywords = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $keywordsText))));
if (empty($keywords)) {
    echo json_encode(['error' => '유효한 키워드가 없습니다.']);
    exit;
}

// --- Logic Functions ---
function make_signature($timestamp, $method, $uri, $secret_key) {
    return base64_encode(hash_hmac("sha256", $timestamp.'.'.$method.'.'.$uri, $secret_key, true));
}

function normalize_keyword($value) {
    return strtoupper(preg_replace('/\s+/u', '', trim((string)$value)));
}

function to_number($value) {
    if ($value === "< 10") return 0;
    return is_numeric($value) ? intval($value) : 0;
}

function comp_level($comp) {
    $c = strtoupper(trim((string)$comp));
    if ($c === 'HIGH' || $c === '높음') return '높음';
    if ($c === 'MEDIUM' || $c === '중간') return '중간';
    if ($c === 'LOW' || $c === '낮음') return '낮음';
    return $comp ?: '정보없음';
}

function comp_score($comp) {
    $c = comp_level($comp);
    return $c === '낮음' ? 25 : ($c === '중간' ? 55 : ($c === '높음' ? 85 : 45));
}

function pct($n) { return max(0, min(100, round($n))); }

function get_keywords($keyword, $order, $cfg) {
    $timestamp = round(microtime(true) * 1000);
    $signature = make_signature($timestamp, $cfg['METHOD'], $cfg['URI'], $cfg['SECRET_KEY']);
    $clean = normalize_keyword($keyword);
    
    $url = $cfg['BASE_URL'].$cfg['URI']."?".http_build_query(["hintKeywords" => $clean, "showDetail" => 1]);
    $headers = [
        "X-Timestamp: $timestamp",
        "X-API-KEY: ".$cfg['API_KEY'],
        "X-Customer: ".$cfg['CUSTOMER_ID'],
        "X-Signature: $signature"
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($res === false || $http !== 200) {
        return [[
            "inputKeyword" => $keyword, "type" => "입력키워드", "keyword" => $keyword,
            "pcSearch" => "오류", "mobileSearch" => "오류", "totalSearch" => 0,
            "competition" => "", "status" => "API 오류 $http", "order" => $order, "group" => 0
        ]];
    }
    
    $json = json_decode($res, true);
    $list = $json["keywordList"] ?? [];
    $results = [];
    $inputFound = false;

    foreach ($list as $item) {
        $rel = $item["relKeyword"] ?? "";
        $isExact = normalize_keyword($rel) === $clean;
        $pc = to_number($item["monthlyPcQcCnt"] ?? 0);
        $mo = to_number($item["monthlyMobileQcCnt"] ?? 0);
        
        $row = [
            "inputKeyword" => $keyword,
            "type" => $isExact ? "입력키워드" : "연관키워드",
            "keyword" => $isExact ? $keyword : $rel,
            "pcSearch" => $item["monthlyPcQcCnt"] ?? 0,
            "mobileSearch" => $item["monthlyMobileQcCnt"] ?? 0,
            "totalSearch" => $pc + $mo,
            "competition" => comp_level($item["compIdx"] ?? ""),
            "status" => "성공",
            "order" => $order,
            "group" => $isExact ? 0 : 1
        ];
        
        if ($isExact) { array_unshift($results, $row); $inputFound = true; }
        else { $results[] = $row; }
    }
    
    if (!$inputFound) {
        array_unshift($results, [
            "inputKeyword" => $keyword, "type" => "입력키워드", "keyword" => $keyword,
            "pcSearch" => 0, "mobileSearch" => 0, "totalSearch" => 0,
            "competition" => "데이터 없음", "status" => "성공", "order" => $order, "group" => 0
        ]);
    }
    return $results;
}

// --- Execution ---
$allResults = [];
foreach ($keywords as $i => $kw) {
    $kwRes = get_keywords($kw, $i, $CONFIG);
    $allResults = array_merge($allResults, $kwRes);
}

// Sorting
usort($allResults, function($a, $b) {
    if ($a['order'] !== $b['order']) return $a['order'] <=> $b['order'];
    if ($a['group'] !== $b['group']) return $a['group'] <=> $b['group'];
    return $b['totalSearch'] <=> $a['totalSearch'];
});

$mainRows = array_values(array_filter($allResults, fn($r) => $r['type'] === '입력키워드'));
$totalVolume = array_sum(array_column($allResults, 'totalSearch'));

$analyses = [];
foreach ($mainRows as $main) {
    $volume = $main['totalSearch'];
    $pc = to_number($main['pcSearch']);
    $mob = to_number($main['mobileSearch']);
    $mobileShare = ($pc + $mob) > 0 ? ($mob / ($pc + $mob)) * 100 : 60;
    $comp = comp_score($main['competition']);
    
    $search = pct(52 + min(28, $volume / 500) - max(0, ($comp - 50) * 0.35));
    $blog = pct(45 + ($comp >= 70 ? 8 : 0));
    $cafe = pct(42 + ($mobileShare >= 65 ? 10 : 4));
    $seo = pct(36 - ($comp >= 75 ? 6 : 0));

    $channels = [
        ['name' => '네이버 검색광고', 'score' => $search, 'desc' => '즉시 노출과 전화 전환 테스트에 유리', 'action' => '정확 키워드 위주 소액 테스트'],
        ['name' => '블로그/VIEW 콘텐츠', 'score' => $blog, 'desc' => '비교·후기·정보 탐색 고객 확보에 유리', 'action' => '방문혜택 및 입지 콘텐츠 제작'],
        ['name' => '네이버 카페 마케팅', 'score' => $cafe, 'desc' => '지역 커뮤니티 반응 유도에 유리', 'action' => '관심층 많은 지역 카페 중심 운영'],
        ['name' => '웹사이트 SEO/랜딩페이지', 'score' => $seo, 'desc' => '장기적으로 광고비를 낮추는 기반', 'action' => '전용 랜딩페이지와 상담 CTA 강화']
    ];
    usort($channels, fn($a, $b) => $b['score'] <=> $a['score']);
    
    $analyses[$main['inputKeyword']] = [
        'volumeGrade' => $volume >= 10000 ? "높음" : ($volume >= 2000 ? "중간" : "낮음"),
        'mobileShare' => round($mobileShare),
        'competition' => $main['competition'],
        'channels' => $channels,
        'best' => $channels[0],
        'reason' => '검색량과 경쟁도를 종합 분석한 결과입니다.',
        'budget' => '점수가 높은 채널 위주로 예산을 분배하세요.',
        'contentSupply' => [
            'blog' => ['label' => '블로그', 'daily' => null, 'total' => null, 'level' => '참고'],
            'cafe' => ['label' => '카페', 'daily' => null, 'total' => null, 'level' => '참고'],
            'news' => ['label' => '뉴스', 'daily' => null, 'total' => null, 'level' => '참고'],
            'web' => ['label' => '웹문서', 'daily' => null, 'total' => null, 'level' => '참고']
        ]
    ];
}

echo json_encode([
    'results' => $allResults,
    'mainRows' => $mainRows,
    'totalVolume' => $totalVolume,
    'analyses' => $analyses
]);
