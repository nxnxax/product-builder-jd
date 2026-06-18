<?php
/** 비활성화됨 — 충전 로직 자체 테스트는 2026-06-18 검증 완료 후 폐쇄. */
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'gone']);
