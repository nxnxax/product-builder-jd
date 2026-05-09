<?php
/**
 * lotto_db_import.php  —  DEPRECATED
 *
 * 이 페이지는 더 이상 사용하지 않습니다. 카페24 서버 IP 가 동행복권에
 * 차단되어 있어 직접 API 호출 방식이 동작하지 않습니다.
 *
 * 새 도구: lotto_offline_json_import.php
 *   같은 폴더의 lotto_numbers_all.json 을 읽어 DB 에 적재합니다.
 *   동행복권에 절대 직접 호출하지 않습니다.
 */

declare(strict_types=1);
header('Cache-Control: no-store');
header('Location: /lotto_offline_json_import.php', true, 301);
?>
<!DOCTYPE html>
<html lang="ko"><head><meta charset="UTF-8"><title>이동됨 · YOUNGMAN</title></head>
<body style="font-family:Pretendard,-apple-system,sans-serif;padding:40px;color:#0e0d0c;line-height:1.7;">
<h2 style="margin:0 0 8px;">이 페이지는 폐기되었습니다</h2>
<p style="color:#4f4943;">
  카페24 서버 IP 가 동행복권에 차단되어 직접 API 호출은 동작하지 않습니다.<br>
  대신 오프라인 JSON 기반 적재 도구를 사용하세요:
</p>
<p><a href="/lotto_offline_json_import.php" style="color:#c8362c;font-weight:600;">→ lotto_offline_json_import.php 로 이동</a></p>
</body></html>
