# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-17 (세션 인계용 요약본)*

## 1. 사이트 목적

**YOUNGMAN / 영맨** — 1인 사업자용 AI 영업 플랫폼.
- CRM(고객) / HRM(조직도·계약자) / 마케팅 도구 / 로또 분석 / 단체 SMS 통합
- 한국 캘리그라피 + 인장(seal-red #c8362c), Apple/Linear 미니멀 톤
- 라이브: https://youngman-biz.com (Cafe24 호스팅 + Supabase Auth + MariaDB + PHP API)
- 인증: Supabase Cloud + JWT (sb_publishable_ 키, **PHP session 안 씀**)
- 최근 추가: **RN Android WebView 앱 브리지** (web ↔ app 연동)

## 2. 주요 파일 구조

```
[프론트 페이지]
index.html              — 새 랜딩 (hero + 6 features + End CTA, toss/niceid 톤)
                          + 헤더 슬롯 메뉴 (slot1=조직도, slot2=계약자, caret hover dropdown)
login-complete.html     — 단일 로그인 transition + Google 신규 가입자 닉네임/휴대폰 모달
logout.html             — 단일 로그아웃 cleanup (signOut scope:global)
profile.html / .js      — 내 정보 5탭 (계정/기본/보안/모바일API/문자설정/계정관리)
                          + 모바일 API 토큰 발급 UI (라벨/평문 1회 표시/폐기)
admin.html / .js        — 관리자 4탭 (대시보드/회원/설정/로그)
org.html / .js          — 조직도 (그룹별 팀 + 수수료, 팀 위계 정산 규칙)
contracts.html / .js    — 계약자 관리대장 (linkedOrgGroupId 로 조직도 연동 + 자동 수수료)
customers.html / .js    — 고객 관리대장 + 단체 SMS 발송 모달
forms.html / .js        — 사용자 정의 양식 빌더 + 사용 모드 (accordion-card)
                          빌더: 텍스트/숫자/날짜/체크/드롭다운/첨부/수식/페이지간 ref
                          수식: 엑셀 스타일 평문 + 조건문/집계/날짜/문자열 함수 라이브러리
                          + SETTING 함수 + 캐스케이드 picker + 다른 양식 ref 점표기
                          + __navSlot 메타로 slot1/slot2 에 양식 자동 노출
board.html / .js        — 게시판 (가독성 polish 완료)
card-builder.html       — 명함 (미완)
lotto2233.html          — 로또 (warm 톤 + dosa 인장 sigil)
Marketing.html, kapp_premium.php — iframe 외부 도구 (kapp 은 사이트 헤더/footer 통합)
terms.html, privacy.html — NICE 휴대폰 본인확인 심사 대응 약관/처리방침

[공통 JS]
auth-shared.js          — Supabase + 헤더/footer + 인증 전체 + Google in-app 분기
                          + bridge.js import → onAuthStateChange 자동 notifyLogin/Logout
bridge.js               — RN Android WebView 앱 브리지 (window.YoungmanBridge / postMessage)
ledger-shared.js        — 관리대장 공통 모달/필터/카드 상태
main.js                 — 사용 안 함 (백업만 유지)

[PHP API — cafe24 webroot 에 flat 배포]
api/records.php         — 모든 CRUD (customers/employees/ledger-*/admin-*/auth-*/find-*/sms-*/mobile-tokens/customer-log)
api/process-recording.php — 통화 녹취 → CLOVA STT → gpt-4o-mini 요약 → customer_log insert
api/sms/send-bulk.php   — 단체 SMS + image_base64
api/sms/balance.php     — Solapi 잔액 조회
api/sms/providers/      — Solapi / Aligo
api/crypto_helpers.php  — AES-256-GCM (enc:v1: prefix)
api/ledger-mobile.php   — 모바일 앱 Bearer 토큰
api/upload.php          — 파일 업로드 (kind=recording 분기 시 audio 전용 디렉터리)

[디자인 / 자산]
style.css               — 디자인 토큰 + sms-phone UI + signup-input-row 등
logo_main.png           — 브랜드 로고
BRIDGE_API.md           — 앱 ↔ 웹 메시지 스펙
CALL_RECORDING_BACKEND.md — 통화 녹취 → AI 요약 백엔드 spec (앱팀 contract)
```

## 3. 현재 완성된 기능

- ✅ **RN Android WebView 앱 브리지** (bridge.js 단일 모듈, 모든 페이지 자동 노출)
  - SIGNED_IN → `auth.login` / SIGNED_OUT → `auth.logout` / 외부 링크 → `nav.openExternal`
  - 기본 `onBack` 핸들러 (모달/드로어 닫고 true)
- ✅ **앱 안 Google 로그인** — native SDK + `signInWithIdToken` + nonce hash 전달 (백엔드 검증 X)
- ✅ Supabase Auth (이메일 + Google OAuth) — 회원가입/로그인/로그아웃/비번 변경
- ✅ **인증 일원화** — logout.html / login-complete.html 단일 transition
- ✅ **고아 user 자동 복구** — 모든 페이지 boot 시 `ensureMemberRowOnce`
- ✅ **로그인 유지 체크박스** (해제 시 pagehide/beforeunload 자동 sb-* 삭제)
- ✅ **아이디 찾기 / 비밀번호 찾기 (SMS 인증)** — 관리자 Solapi 키 사용
- ✅ **이메일/닉네임 중복확인** — 회원가입 form
- ✅ **Google 신규 가입자 모달** — 닉네임/휴대폰/약관 수집
- ✅ **모바일 백그라운드 후 토큰 만료 → 자동 refresh + 401 retry**
- ✅ **헤더 닉네임 표시 즉시 보장** (currentSession 즉시 도출 + metadata 우선)
- ✅ **Solapi 미연동 시 안내 모달** (60대+ 가독성)
- ✅ **검색 input 한글 IME 조합 깨짐 fix** (filterDOMRowsBySearch, input 재생성 안 함)
- ✅ **조직도/계약자/고객 관리대장 통합** (page_type 기반)
- ✅ **카드 expanded 상태 re-render 후 복원** (_expandedRowIds + MutationObserver)
- ✅ **PII 사용자별 격리 + AES-256-GCM 암호화 + 컬럼 폭 자동 확장**
- ✅ **단체 SMS 발송** (Solapi/Aligo, 폰 미리보기, 사진 첨부, drag&drop)
- ✅ **Solapi 충전 잔액 카드** (모달 내)
- ✅ 모바일 하단 네비게이션 (4탭, glass 톤) + bfcache pageshow.persisted reload
- ✅ **사용자 정의 양식 빌더 시스템 (forms Phase 1~3)** — 텍스트/숫자/날짜/체크/드롭다운/첨부/수식/페이지간 ref 8타입, 필드 편집/순서/필수 toggle, 엑셀 다운/가져오기, 헤더 필터, 일괄 삭제, 수식 UI 빌더, SETTING 함수
- ✅ **forms 수식 함수 라이브러리** — 엑셀 스타일 평문 + 조건문/집계/날짜/문자열/ref점표기 + 캐스케이드 picker
- ✅ **양식 슬롯 시스템** — slot1(조직도)/slot2(계약자) + dropdown caret hover, 사용자 정의 양식이 슬롯/하단 nav 자동 노출, __navSlot 메타 저장
- ✅ **모바일 카드 별도 DOM 렌더** — 가로스크롤 완전 제거 + 카드 펼치기/접기 + 라벨 표시 + 큰 글자
- ✅ **모바일 API 토큰 발급 UI** (profile) — 라벨 입력/발급/평문 1회 표시(복사)/목록/폐기, sha256 hash 저장
- ✅ **회원 탈퇴** — account-delete (트랜잭션 일괄 삭제)
- ✅ **NICE 휴대폰 본인확인 심사 대응** — 약관/개인정보처리방침/footer/동의항목 (terms.html, privacy.html, footer 다크 톤)
- ✅ **kapp_premium.php (N키워드 분석) 사이트 통합** — 헤더/footer/모바일 nav 일관 적용
- ✅ **board/upload/lotto 가독성 polish** — 60대+ 시력 저하 사용자 대응 일관 적용
- ✅ **로그인/회원가입 모달 가독성 polish** + 길어진 모달 닫기 버튼 viewport 짤림 fix
- ✅ **앱 잠금화면 후 자동 로그아웃 회귀 차단** — 가장 최근 fix (수동 로그아웃 전까지 영구 유지)
- ✅ 모바일 모달 footer 가 하단 nav 에 가려지던 fix
- ✅ **통화 녹취 → AI 요약 → CRM 기록 Phase 1** (앱 자동 입력 + 웹 수동 편집)
  - 앱이 통화녹음 m4a 업로드 → process-recording.php → CLOVA Speech STT(ko) → gpt-4o-mini 요약(JSON) → customer_log row insert (PII AES-256-GCM)
  - customer_name 결정 7단계 룰 (이름 → 호칭+나이 → 호칭만 → "고객" 폴백, 추측 금지)
  - customer_name_hint body 필드 (앱 측 폰 contacts lookup 결과 우선 적용)
  - free quota 5건 / 오디오 즉시 unlink / idempotency 24h (client_request_id)
  - records.php customer-log resource: list/get/update/delete + 자체 인증 + spec §4 표준 응답

## 4. 아직 미완성인 기능

- ⏳ **card-builder UX** — Recraft overlay primary path + AI/템플릿 토글 UI
- ⏳ **profile/admin 디자인 polish 일관성 감사** — 새 랜딩 톤과 통일
- ⏳ **forms 수식 inline help** — 함수 카탈로그 모달
- ⏳ **PII 평문 → 암호문 일괄 backfill 스크립트** (현재 lazy migration)
- ⏳ **로또 자동 갱신** — dhlottery IP 차단으로 JSON 미러 cron 자동화 필요
- ⏳ **Supabase Email Template 한글화** (Dashboard 수동)
- ⏳ **SMS_USER_GUIDE.txt 처리** — 현재 미커밋 untracked. 커밋 또는 .gitignore 결정 필요
- ⏳ **RN Android 앱 측 검증 마무리** — logcat 으로 auth.login / onGoogleSignInResult 확인
- ⏳ **call-recording Phase 2** — async mode (`?mode=async`) + FCM dispatcher + `user_fcm_tokens` 테이블 + 24h 미정리 오디오 cron 정리
- ⏳ **NCP Secret Key 재발급** — e2e 1차 검증 중 채팅 평문 노출, 운영자 NCP 콘솔에서 재발급 후 GitHub Secret 갱신

## 5. 배포 방식

- **GitHub Actions → FTP (port 21)** via `SamKirkland/FTP-Deploy-Action`
- 워크플로우: `.github/workflows/deploy.yml`
- 시크릿 (필수): `CAFE24_FTP_PASSWORD`, `YOUNGMAN_CRYPTO_KEY`, `SUPABASE_SERVICE_KEY`, `NCP_CLOVA_INVOKE_URL`, `NCP_CLOVA_SECRET`
- "배포/올려" 키워드 → push → trigger → verify 자동 (per-step 확인 묻지 말 것)
- 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)`
- 캐시 버스트: bridge.js 는 `v=20260517-bridge-v1`, auth-shared imports 는 `v=20260516-nickname-header`
- **신규 페이지 추가 시** deploy.yml 의 Prepare deploy cp 줄 + Validate test -f 둘 다 추가 (누락 = 라이브 404)

## 6. Cafe24/PHP 관련 주의사항

- 🚫 **SSH/SCP 절대 금지** — silent drop. FTP only. `server-dir: ./`
- 📁 **Webroot flat layout** — `api/records.php` → 배포 후 `/records.php`. `__DIR__` 기준
- 📁 **`api/sms/` 디렉토리는 deploy 시 `deploy/sms/providers/` 로 cp**
- 🔐 **YOUNGMAN_CRYPTO_KEY 분실 = 복호화 영구 불가** — GitHub Secret 백업 필수
- 🔐 **SUPABASE_SERVICE_KEY** — 비밀번호 재설정용. cafe24 서버 PHP 에서만 사용, 절대 노출 금지
- 🔐 **사용자별 Solapi 키** — `sms_credentials` 테이블 AES-256-GCM. 영맨은 결제 관여 X
- 🔐 **관리자 Solapi 키** — admin_email_allowlist[0] 익명 OTP 발송용
- 📡 **deploy/.env 매번 어셈블** — FTP 로 직접 넣은 키는 다음 deploy 에 덮어쓰임
- 📡 **records.php `/auth/v1/user` 폴백** — sb_publishable 키 로그인용. 제거 시 깨짐
- 📡 **dhlottery 직접 호출 금지** — cafe24 IP 차단 영구. JSON 미러만
- 📡 **PHP timeout 30초** — send-bulk.php 는 `set_time_limit(120)` 명시
- 📊 **PII 컬럼 폭** — 암호문 100~200 chars. 새 PII 컬럼은 최소 VARCHAR(255)

## 7. 최근 수정한 파일 (커밋 흐름)

```
d8d3e78 fix(auth): 앱(WebView) 잠금화면 후 자동 로그아웃 회귀 차단
bbf3c54 fix(mobile): 모달 footer 가 하단 고정 nav 에 가려지던 문제
b4f090c docs(PROJECT_CONTEXT): 2026-05-17 — RN WebView 앱 브리지 + Google in-app 로그인 반영
7dba625 feat(auth): in-app Google 로그인 — native SDK 경유 + signInWithIdToken
b4d9323 feat(bridge): window.YoungmanBridge 에 web→app 헬퍼 노출
f681435 feat(bridge): RN Android WebView 앱 브리지 v1
1df7e2c style(mobile-nav): 하단 고정 nav 배경 → 물방울/유리 톤
8d4864b fix(search): 검색 input DOM 재생성 안 함 — 모바일 한글 IME 조합 깨짐 근본 fix
8eae73f fix(mobile): 카드 toolbar — 검색바 + 행 추가 버튼 한 줄 정렬
```

**미커밋:** `SMS_USER_GUIDE.txt` (untracked, 5/15 추가)

## 8. 절대 건드리면 안 되는 부분

- 🔒 **PII owner_email 격리** — 모든 SELECT/UPDATE/DELETE 강제. admin 우회 없음
- 🔒 **`git add -A` 금지** — 작업 폴더의 .xlsx/.csv/.pdf PII 새어나갈 위험. 명시 add 만
- 🔒 **YOUNGMAN 브랜드** — logo_main.png + seal-red(#c8362c)
- 🔒 **SSH/SCP 배포 시도 금지**
- 🔒 **서버 설정 파일 repo 커밋 금지** — supabase_config.js/php, db_config.php, .env
- 🔒 **records.php `/auth/v1/user` 폴백 유지** — 마지막 보루
- 🔒 **dhlottery 직접 호출 부활 금지**
- 🔒 **인증 일원화 구조** — logout.html / login-complete.html 단일. 분산 핸들러 다시 만들지 말 것
- 🔒 **ledger UX 패턴** — 헤더 클릭 필터 / 행 추가 모달 / accordion 그룹. 인라인 거부됨
- 🔒 **카드 expanded 상태 보존** — _expandedRowIds + MutationObserver
- 🔒 **`<a href="logout.html">` native navigation** — JS click handler 의존 회귀 금지
- 🔒 **module top-level `return` 금지** — if/else 구조로 작성
- 🔒 **ensureMemberRowOnce** — 모든 페이지 boot 시 자동. 제거 시 고아 user 403 회귀
- 🔒 **PII 컬럼 폭 자동 확장** — ensure_member_pii_columns_wide. 제거 시 22001 회귀
- 🔒 **OAuth click handler 동기 흐름** — `await initSupabase` 추가 금지
- 🔒 **prompt: 'select_account'** — Google OAuth 옵션
- 🔒 **signOut({ scope: 'global' })** — logout.html. local 회귀 금지
- 🔒 **SMS 회원별 자격증명** — 영맨은 발송 중계만
- 🔒 **sms_logs 원문 저장 금지** — phone_masked + message_hash 만
- 🔒 **검색 input 재생성 금지** — filterDOMRowsBySearch hide/show 만
- 🔒 **apiRequest 토큰 refresh + 401 retry** — 제거 시 모바일 백그라운드 후 회귀
- 🔒 **mountAppHeader 의 currentSession 즉시 도출** — 제거 시 헤더 stale 회귀
- 🔒 **apiRequest 호출 형식** — `apiRequest('xxx', { query: 'k=v' })`. URL 직접 X
- 🔒 **bridge.js 메시지 타입 이름** — `auth.login` / `auth.logout` / `auth.googleSignIn.request` / `onGoogleSignInResult` / `nav.openExternal`. 변경 시 RN 앱 동시 수정 필수
- 🔒 **`window.YoungmanBridge` 전역 이름** — RN 앱이 가정. rename 금지
- 🔒 **Google 로그인: signInWithIdToken 직접 호출** — 백엔드 검증 엔드포인트 만들지 말 것
- 🔒 **Google 로그인 nonce 처리** — raw 는 웹만, hash 만 앱에 전달
- 🔒 **deploy.yml 의 bridge.js cp** — 누락 시 라이브 404 → 전체 페이지 깨짐
- 🔒 **앱 잠금화면 후 자동 로그아웃 차단 로직** — 수동 로그아웃 전까지 영구 유지. 회귀 금지
- 🔒 **forms 사용 모드 UI = accordion-card** — 조직도/계약자/고객 양식폼 형태. 다른 UI 로 회귀 금지
- 🔒 **양식 슬롯 caret hover 만 활성화** — label hover 는 dropdown 안 열림. 사용자 명시 정책
- 🔒 **새 entry HTML 의 inline script** — `mountAppHeader()` 만 호출 금지. `initSupabase()` 또는 `bootApp()` 동반 호출 필수 (currentSession null → 슬롯 양식 안 보임)
- 🔒 **모바일 카드 별도 DOM 렌더** — 가로스크롤 부활 금지. 카드 펼치기/접기 + 라벨 표시 패턴 유지
- 🔒 **NICE 본인확인 심사 약관/처리방침** — terms.html / privacy.html / footer 동의항목. 제거 시 본인확인 통과 영향

## 9. 다음에 이어서 해야 할 작업

1. **SMS_USER_GUIDE.txt 처리** — 커밋할지 / .gitignore 할지 사용자 확인
2. **card-builder 정리** — Recraft overlay primary + AI/템플릿 토글 UI
3. **PII 평문 → 암호문 backfill** — ledger_records / members 일괄 암호화 스크립트
4. **forms 수식 inline help** — 함수/path 카탈로그 모달
5. **profile/admin 디자인 일관성 감사** — 새 랜딩 톤과 맞추기
6. **Supabase Email Template 한글화** — Dashboard 수동 수정 안내
7. **로또 자동 갱신** — JSON 미러 cron 자동화
8. **메인 페이지 추가 다듬기** — 사용자 추가 피드백 대기
9. **RN Android 앱 측 검증 마무리** — logcat 으로 `auth.login` / `onGoogleSignInResult` 도착 확인
10. **Marketing.html 브리지 포함 검토** — 앱 진입 가능하면 bridge.js include

---

## 자가 진단 채널 (디버깅용)

- `sessionStorage.erp.ensureError` : members 보강 실패 시 JSON
- `sessionStorage.erp.memberEnsured = '1'` : 보강 성공
- `sessionStorage.erp.endSessionOnClose = '1'` : 로그인 유지 해제 상태
- 콘솔 prefix: `[auth submit]` / `[signIn]` / `[signUp]` / `[google oauth]` / `[google native]` / `[ensure member auto]` / `[sms balance]` / `[bridge]`
- 브리지 디버깅: `window.YoungmanBridge.isInApp()` / `.getAppInfo()` / `.getFcmToken()` / `.version`

## 메모리 참조 (~/.claude/projects/-home-user-jdhoon/memory/)

- `feedback_auth_flow_lessons.md` — 인증 13가지 root cause + 단일 페이지 일원화
- `feedback_css_edit_sanity.md` — 큰 Edit 후 brace balance 검증 필수
- `feedback_deploy_autonomy.md` — "배포/올려" 키워드 → 전체 chain 자동
- `feedback_no_proceed_prompts.md` — "Do you want to proceed?" 묻지 말 것
- `feedback_pii_isolation.md` — PII owner_email 강제, git add -A 금지
- `feedback_readability_first.md` — 60대+ 가독성 우선, 작은 글자 금지
- `feedback_ledger_ux.md` — 헤더 클릭 필터 / 행 추가 모달 / accordion 그룹
- `project_app_bridge.md` — RN Android WebView 앱 연동 인프라
- `project_pii_crypto.md` — AES-256-GCM 라이브 작동 중
- `project_ledger_system.md` — page_type 기반 그룹/레코드
- `project_youngman_redesign.md` — 브랜드 리디자인 5라운드 hero 완료
- `project_nav_slots.md` — "신규양식신청 메뉴 1/2" = slot1(조직도)/slot2(계약자), caret hover dropdown
- `project_mobile_bottom_nav.md` — ≤860px 4탭 하단 고정 nav, safe-area-inset
- `deploy_cafe24.md` — FTP only, webroot flat layout
