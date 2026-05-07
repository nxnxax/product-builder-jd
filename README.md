# Cloudflare Pages 서브페이지용 Marketing.html

기존 `index.html`은 삭제하거나 덮어쓰지 마세요.

## 올바른 구조

```text
index.html                  ← 기존 메인화면 유지
Marketing.html              ← 마케팅 분석 서브페이지
functions/
  api/
    analyze.js              ← API 서버리스 함수
package.json
```

## 기존 index.html 메뉴에 추가

```html
<a href="/Marketing.html">Marketing · 마케팅 분석</a>
```

## 중요

아래 구조가 있으면 삭제하세요.

```text
api/
  analyze/
    index.html
```

`/api/analyze`는 일반 페이지가 아니라 Cloudflare Pages Function이어야 합니다.

## 환경변수

Cloudflare Pages > Settings > Variables and Secrets 에 추가:

- NAVER_SEARCHAD_API_KEY
- NAVER_SEARCHAD_SECRET_KEY
- NAVER_SEARCHAD_CUSTOMER_ID
- NAVER_CLIENT_ID
- NAVER_CLIENT_SECRET

저장 후 Deployments > Retry deployment 하세요.
