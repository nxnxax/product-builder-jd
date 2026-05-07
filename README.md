# Cloudflare Pages용 네이버 키워드 마케팅 분석기

PHP 없이 Cloudflare Pages에서 구동되는 버전입니다.

## 구조
- `index.html` : 화면
- `functions/api/analyze.js` : 네이버 검색광고 API / 네이버 Search Open API 처리
- `package.json` : Wrangler 실행용

## Cloudflare Pages 환경변수

Cloudflare Pages 프로젝트의 Settings > Environment variables 에 등록하세요.

필수:
- `NAVER_SEARCHAD_API_KEY`
- `NAVER_SEARCHAD_SECRET_KEY`
- `NAVER_SEARCHAD_CUSTOMER_ID`

선택이지만 권장:
- `NAVER_CLIENT_ID`
- `NAVER_CLIENT_SECRET`

API 키는 절대 `index.html`에 넣지 마세요.

## 배포
GitHub에 이 폴더 전체를 올리고 Cloudflare Pages에 연결하세요.

Build command: 비워도 됩니다.
Build output directory: `/` 또는 비워도 됩니다.
