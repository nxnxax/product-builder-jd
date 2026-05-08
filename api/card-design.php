<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jout(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') jout(['ok' => false, 'error' => 'POST only'], 405);

$siteUrl = trim((string)($_POST['siteUrl'] ?? ''));
$tone = trim((string)($_POST['tone'] ?? ''));
$hasImage = !empty($_FILES['image']) && (int)($_FILES['image']['error'] ?? 1) === UPLOAD_ERR_OK;

if (!$hasImage && $siteUrl === '') {
    jout(['ok' => false, 'error' => '이미지 또는 사이트 주소가 필요합니다.'], 400);
}

// TODO: integrate real AI pipeline. Until that's wired up, return a stub
// so the UI flow can be tested end-to-end.
jout([
    'ok' => true,
    'fields' => [
        '이미지' => $hasImage ? ($_FILES['image']['name'] ?? '(첨부됨)') : '(없음)',
        '사이트' => $siteUrl ?: '(없음)',
        '톤' => $tone ?: '(자동)',
    ],
    'note' => 'AI 파이프라인이 아직 연결되지 않았습니다. 입력은 정상적으로 수신되었으며, 백엔드 키 등록 후 실제 디자인이 생성됩니다.',
]);
