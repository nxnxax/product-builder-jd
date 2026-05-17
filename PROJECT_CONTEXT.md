# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-17*

## 1. 사이트 목적

**YOUNGMAN / 영맨** 브랜드의 1인 사업자용 AI 영업 플랫폼.
- CRM(고객) / HRM(조직도·계약자) / 마케팅 도구 / 로또 분석 / 단체 SMS 통합
- 한국 캘리그라피 + 인장(seal-red #c8362c) 브랜드, Apple/Linear 미니멀 톤
- 라이브: https://youngman-biz.com (cafe24 호스팅 + Supabase Auth + MariaDB)
- 인증: Supabase Cloud + JWT (sb_publishable_ 키, **PHP session 안 씀**)
- 메인 페이지: toss/niceid 스타일 7단락 zig-zag (1단락=AI 고객관리 hero)

## 2. 주요 파일 구조

```
index.html                     — 새 랜딩 (hero + 6 features + End CTA, 홀수단락 floating bg 아이콘)
index_backup_20260516.html     — 옛 디자인 백업 (deploy 안 됨)
main.js / main_backup_*.js     — 사용 안 함 (백업만 유지)

auth-shared.js                 — Supabase + 헤더/footer + 인증 전체 관리
                                 currentSession, initSupabase, getSession, performLogout
                                 navigateAfterAuth → login-complete.html
                                 ensureMemberRowOnce — 모든 페이지 boot 시 자동 members 보강
                                 translateAuthError (영문 → 한국어 친화)
                                 openSharedLoginModal / openFindIdModal / openFindPasswordModal
                                 bindBackdropClose (pointerdown/up — 드래그 close 차단)
                                 + bridge.js import → onAuthStateChange 자동 notifyLogin/Logout
                                 + Google 로그인 isInApp 분기 — native SDK + signInWithIdToken

bridge.js                      — RN Android WebView 앱 브리지 (window.YoungmanBridge / postMessage 채널)
                                 web→app: auth.login / auth.logout / nav.openExternal / nav.share /
                                          auth.googleSignIn.request / app.fcm.request / app.info.request /
                                          app.statusBar / app.haptic
                                 app→web: onReady / onAppInfo / onFcmToken / onPushOpen / onAppResume /
                                          onBack(boolean) / onGoogleSignInResult
                                 브라우저(앱 외부)에서 모든 호출 no-op — fallback 자동 보장
BRIDGE_API.md                  — 앱 ↔ 웹 메시지 스펙 + RN 측 onMessage / injectJavaScript 스니펫

logout.html                    — 단일 로그아웃 cleanup 페이지 (signOut scope:global + KEEP yman_nav_/userEmail.last)
login-complete.html            — 단일 로그인 transition + 신규 Google 가입자 닉네임/약관 form

profile.html / profile.js      — 내 정보 5탭 (계정/기본/보안/모바일API/문자설정/계정관리)
admin.html / admin.js          — 관리자 4탭 (대시보드/회원/설정/로그)

org.html / org.js              — 조직도 (그룹별 팀 + 수수료)
contracts.html / contracts.js  — 계약자 관리대장 (수수료 정산)
customers.html / customers.js  — 고객 관리대장
                                 + 단체 SMS 모달 (좌측 수신자 패널 / 폰 미리보기 / 충전잔액카드 /
                                   LMS·MMS 큰 배지 / 사진 첨부 drag&drop / 수신자 X 즉시 제거 / 로딩 overlay)
forms.html / forms.js          — 사용자 정의 양식
ledger-shared.js               — 공통 모달/필터/cell click/엑셀 헬퍼
                                 + 카드 expanded 상태 _expandedRowIds Set + MutationObserver 자동 복원
                                 + bfcache pageshow.persisted reload
                                 + endSessionOnClose listener (로그인 유지 체크해제 처리)

api/records.php                — 모든 CRUD (customers/employees/ledger-*/admin-*/auth-*/
                                  find-email[-send-otp][-verify-otp]/find-pwd-send-otp/verify-otp/reset/
                                  sms-credentials/mobile-tokens/account-delete)
                                 + ensure_member_pii_columns_wide(이름/휴대폰/닉네임 컬럼 자동 VARCHAR(255))
                                 + send_otp_sms_via_admin (관리자 Solapi)
                                 + supabase_admin_find_user_id / supabase_admin_set_password (service_role)
                                 + auth-member ensure 모드 + needsNickname 응답
                                 + auth-profile PUT nickname 지원
api/sms/send-bulk.php          — 단체 SMS (텍스트 + image_base64) + set_time_limit(120) + fatal handler
api/sms/balance.php            — Solapi 충전 잔액 조회 (PDO new 명시)
api/sms/providers/             — Solapi (uploadImage / getBalance / sendBulk) / Aligo
api/crypto_helpers.php         — AES-256-GCM (enc:v1: prefix)
api/ledger-mobile.php          — 모바일 앱 Bearer 토큰
api/upload.php                 — 파일 업로드

lotto2233.html / lotto_*       — 로또
board.html / board.js          — 게시판
card-builder.html              — 명함 (미완)
Marketing.html / kapp_premium.php — iframe 외부 도구

style.css                      — 디자인 토큰 + sms-phone 폰 UI + sms-balance-card + signup-input-row +
                                 .signup-extra (google 가입 모달) + .rp-remove (수신자 제거 버튼) +
                                 .check-dup-btn (중복확인) + .shared-auth-fields[hidden]{display:none}
                                 + sms-attach-empty/preview [hidden] fix
logo_main.png                  — 브랜드 로고
SMS_USER_GUIDE.txt             — 사용자 설명서
```

## 3. 현재 완성된 기능

- ✅ **RN Android WebView 앱 브리지** — bridge.js 단일 모듈 (auth-shared 가 import 하므로 모든 페이지 자동 노출)
  - 양방향 채널: web→app `window.ReactNativeWebView.postMessage(JSON.stringify(...))`, app→web `window.YoungmanBridge.<handler>(...)`
  - 자동 송신: SIGNED_IN → `auth.login { accessToken, userId, email, expiresAt, ... }`, SIGNED_OUT → `auth.logout`, 외부 도메인/_blank 링크 클릭 → `nav.openExternal`
  - logout.html 진입 시 navigation race 대비 명시적 `notifyLogout` 추가 호출
  - 기본 `onBack` 핸들러 — 흔한 모달/드로어/시트 셀렉터 자동 닫고 true, 없으면 false (앱이 라우터 pop)
  - **앱 안 Google 로그인** — accounts.google.com 의 disallowed_useragent 회피. raw nonce 생성 → SHA-256 hash 만 앱 전달 (`auth.googleSignIn.request { nonce }`) → 앱이 GoogleSignin SDK 호출 → idToken 반환 (`onGoogleSignInResult { idToken }`) → 웹이 `supabase.auth.signInWithIdToken({ provider:'google', token, nonce:rawNonce })` 직접 호출. **백엔드 검증 엔드포인트(`/api/auth/google`) 만들지 않음** — Supabase 가 직접 검증
- ✅ Supabase Auth (이메일 + Google OAuth) + 회원가입 / 로그인 / 로그아웃 / 비밀번호 변경 (admin API)
- ✅ **인증 일원화** — logout.html / login-complete.html 단일 transition 페이지
- ✅ **고아 user 자동 복구** — 모든 페이지 boot 시 ensureMemberRowOnce 호출 → members 자동 보강
- ✅ **로그인 유지 체크박스** (체크 해제 시 pagehide/beforeunload 자동 sb-* 삭제)
- ✅ **아이디 찾기 (SMS 인증)** — 관리자 Solapi 자격증명으로 OTP 발송 + 마스킹 이메일
- ✅ **비밀번호 찾기 (SMS 인증 + 새 비번 설정)** — supabase admin API 직접 변경, Google 가입자는 안내만
- ✅ **이메일/닉네임 중복확인** — 회원가입 form (한 줄 layout)
- ✅ **Google 신규 가입자 닉네임/휴대폰/약관 모달** — login-complete.html 의 needsNickname/needsPhone 기반
  - isGoogle 판별: app_metadata.provider OR providers[] OR identities[] (broaden)
  - needsExtra = isGoogle && (needsNickname || needsPhone) — 둘 중 하나만 비어도 모달
  - 기존 nickname 있으면 pre-fill + 중복확인 skip (수정 시 재검증)
- ✅ **모바일 백그라운드 후 토큰 만료 → 자동 refresh + 401 retry** (apiRequest)
  - 만료 60초 이내면 refreshSession() 선제 호출
  - 401 응답 시 한 번 더 refresh + 동일 요청 재시도
- ✅ **헤더 닉네임 표시 즉시 보장** — mountAppHeader 가 cachedName 빈 경우 currentSession.user 에서 즉시 도출, refreshAppHeader 는 apiRequest 전에 metadata 기반 이름 우선 cache
- ✅ **Solapi 미등록 시 친절한 안내 모달** (customers → 문자발송 클릭)
  - 단계 4개(가입→API발급→복사→붙여넣기) ol 목록 + solapi.com / console.solapi.com/credentials 링크
  - 큰 글자(14.5~15px), 요금 충전 안내 박스 포함
- ✅ **검색 input 한글 IME 조합 깨짐 fix** (customers/contracts/org/forms)
  - 근본 원인: renderRecords() 가 input 요소 매번 재생성 → IME composer 바인딩 끊김
  - 해결: filterDOMRowsBySearch() — 기존 DOM 행 textContent.includes(q) 로 hide/show 만, input 요소 보존
- ✅ **헤더 표시 이름 닉네임 우선** — getDisplayName candidates 순서 변경
- ✅ 영문 에러 한국어 친화 메시지 (translateAuthError 단일 헬퍼)
- ✅ 모달 드래그 시 강제 종료 fix (pointerdown/up 둘 다 backdrop 일 때만 close)
- ✅ 조직도/계약자/고객 관리대장 통합 (page_type 기반 ledger 시스템)
- ✅ **카드 expanded 상태 re-render 후 자동 복원** (_expandedRowIds + MutationObserver)
- ✅ **셀 토글 클릭 시 카드 안 접힘** (data-cell-* selector + stopPropagation)
- ✅ PII 사용자별 격리 (owner_email 강제) + AES-256-GCM 암호화 + PII 컬럼 폭 자동 확장
- ✅ 회원 탈퇴 (account-delete, 트랜잭션 일괄 삭제)
- ✅ 모바일 API 토큰 발급/관리
- ✅ **단체 SMS 발송** — 회원별 Solapi/Aligo, 폰 UI 미리보기, 좌측 수신자 패널 + X 제거,
  큰 SMS/LMS/MMS 배지, 사진 첨부 (drag&drop + 200KB + 1500x1440 검증), 발송 결과 robust 매칭
- ✅ **Solapi 충전 잔액 카드** (모달 내 폰 미리보기 바로 위, 컴팩트)
- ✅ 새 메인 랜딩 (toss/niceid 톤) + 모바일 hero compact + floating bg 아이콘
- ✅ 모바일 하단 네비게이션 + bfcache pageshow.persisted reload
- ✅ records.php INSERT try/catch + sessionStorage.erp.ensureError 자가 진단 채널

## 4. 아직 미완성인 기능

- ⏳ **card-builder UX** — Recraft overlay + 토글 UI 미구현
- ⏳ **profile/admin 디자인 polish 일관성 감사**
- ⏳ **forms 수식 inline help** — 함수 카탈로그/예시 모달
- ⏳ **로또 자동 갱신** — dhlottery IP 차단으로 수동 JSON, cron 토큰 코드만
- ⏳ **PII 평문 → 암호문 일괄 backfill 스크립트** — 현재 lazy migration
- ⏳ **Supabase Email Template 한글화** (사용자가 Dashboard 에서 직접 수정 — 코드 X)

## 5. 배포 방식

- **GitHub Actions → FTP (port 21)** via `SamKirkland/FTP-Deploy-Action`
- 워크플로우: `.github/workflows/deploy.yml`
- 시크릿 (필수): `CAFE24_FTP_PASSWORD`, `YOUNGMAN_CRYPTO_KEY` (PII 암호화), **`SUPABASE_SERVICE_KEY` (비밀번호 재설정)**
- "배포/올려" 키워드 → push → trigger → verify 자동, per-step 확인 묻지 말 것
- 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)`
- 캐시 버스트 최신: `v=20260516-nickname-header` (auth-shared import 들), bridge.js 는 `v=20260517-bridge-v1`
- **신규 페이지 추가 시** deploy.yml 의 Prepare deploy 의 cp 줄 + Validate test -f 둘 다 추가 (누락 시 라이브 404)

## 6. Cafe24/PHP 관련 주의사항

- 🚫 **SSH/SCP 절대 사용 금지** — silent drop. FTP only. `server-dir: ./`
- 📁 **Webroot flat layout** — `api/records.php` → deploy 후 `/records.php`. `__DIR__` 기준
- 📁 **`api/sms/` 디렉토리 유지** — `deploy/sms/providers/*` 로 cp
- 🔐 **YOUNGMAN_CRYPTO_KEY 분실 = 복호화 영구 불가** — GitHub Secret 백업 필수
- 🔐 **사용자별 Solapi 키** — `sms_credentials` 테이블 AES-256-GCM. 영맨은 결제/충전 일절 관여 X
- 🔐 **관리자 Solapi 키** (admin_email_allowlist[0]) — 익명 OTP 발송용. 사용자 본인 부담 X
- 🔐 **SUPABASE_SERVICE_KEY** — 비밀번호 재설정용. service_role 키 노출 절대 금지. cafe24 서버 PHP 에서만 사용
- 📡 **deploy/.env 매번 어셈블** — 사용자가 FTP 로 직접 넣은 키는 다음 deploy 에 덮어쓰임
- 📡 **records.php `/auth/v1/user` 폴백** — sb_publishable 키 로그인용. 제거 시 깨짐
- 📡 **dhlottery 직접 호출 금지** — cafe24 IP 차단. JSON 미러만
- 📡 **PHP timeout 30초** — send-bulk.php 는 set_time_limit(120) 명시 (Solapi curl 25초 + 처리 = 30초 근접 시 502)
- 📊 **DB 컬럼 폭** — PII 암호화 'enc:v1:...' 는 100~200 chars. 새 PII 컬럼 도입 시 최소 VARCHAR(255)

## 7. 최근 수정한 파일 (커밋 흐름)

```
7dba625 feat(auth): in-app Google 로그인 — native SDK 경유 + signInWithIdToken
b4d9323 feat(bridge): window.YoungmanBridge 에 web→app 헬퍼 노출 (DevTools 검증용)
f681435 feat(bridge): RN Android WebView 앱 브리지 v1 — 인증/FCM/외부링크/뒤로가기 기본세트
1df7e2c style(mobile-nav): 하단 고정 nav 배경 — 칙칙한 회색 → 물방울/유리 톤 (glass)
8d4864b fix(search): 검색 input DOM 재생성 안 함 — 모바일 한글 IME 조합 깨짐 근본 fix
8eae73f fix(mobile): 카드 toolbar — 검색바 + 행 추가 버튼 한 줄 정렬
5a8ad9d fix(mobile): 카드 toolbar — 두 줄 분리 시도 (이후 8eae73f 가 한 줄로 회귀)
10d380a fix(ledger/forms): 검색 한글 IME — compositionstart/end + debounce 1차 시도 (불충분)
e1f6a28 feat(sms): Solapi 안내 모달에 요금 차감/충전 안내 추가
4b8df2a feat(sms): Solapi 미연동 시 친절한 안내 모달 (60대+ 가독성 우선, 단계별 ol)
922e287 fix(auth): Google 가입 모달 트리거 강화 — needsNickname || needsPhone + isGoogle broaden
4f3cbab fix(auth): 헤더 닉네임 표시 즉시 보장 — 빈 표시 회귀 차단
b73bfff fix(auth): 모바일 백그라운드 후 access token 만료 시 자동 refresh + 401 retry
ba8669c docs: PROJECT_CONTEXT — Google 가입 모달에 휴대폰 수집 반영
5e713db feat(auth): Google 신규 가입자 모달에 휴대폰 번호 입력 슬롯 추가
0fd2816 feat(auth): 헤더 우측 상단 사용자 이름을 닉네임 우선으로 표시
b99ec62 fix(auth): 구글 가입 후 닉네임 모달 — needsNickname 기반 판별
a2385b8 hotfix(auth): login-complete.html module top-level 'return' SyntaxError fix
9afb505 fix(ledger): 카드 expanded re-render 후 보존 (_expandedRowIds + MutationObserver)
0cf1f14 feat(auth): Google 신규 가입자 닉네임/약관 모달 + auth-profile PUT nickname
fdae27a feat(auth): 모달 드래그 close fix + 이메일/닉네임 중복확인
256684b feat(auth): 비밀번호 찾기 SMS 인증 + 새 비번 + Google 사용자 안내
... (이전: SMS UI 일원화, 인증 흐름 일원화, 모바일 hero, 단체문자 발송 등)
```

### 2026-05-17 작업 요약 (RN Android WebView 앱 브리지 도입)

- **bridge.js 신규 — 일반적인 WebView 앱 기본세트**
  - 단일 ES module (~200 lines). 브라우저(앱 외부)에서 모든 호출 no-op fallback 보장
  - `window.ReactNativeWebView.postMessage(JSON.stringify({type, payload, ts}))` 단일 송신 채널
  - `window.YoungmanBridge.<handler>(...)` 수신 — 앱이 `injectJavaScript` 로 호출
  - 외부 도메인 / `target="_blank"` 링크 클릭 자동 가로채기 → `nav.openExternal` 송신
  - 기본 `onBack` 핸들러 — 모달/드로어/시트 닫고 true, 없으면 false (앱이 pop 처리)

- **auth-shared.js — 인증 hook + Google 로그인 in-app 분기**
  - `onAuthStateChange` SIGNED_IN/INITIAL_SESSION/TOKEN_REFRESHED → `notifyLogin(session)`, SIGNED_OUT → `notifyLogout()`
  - 모든 페이지가 auth-shared 통해 자동 로드되므로 HTML 별 추가 작업 없음
  - **Google 로그인 isInApp 분기** — WebView 안에서는 accounts.google.com 의 `disallowed_useragent` 차단 회피. 흐름:
    1. 웹이 raw nonce 생성 (`crypto.randomUUID` 두 번 concat) → SHA-256 hash 계산
    2. hash 만 `auth.googleSignIn.request { nonce: hash }` 로 앱 전송 (raw 는 웹만 보유)
    3. 앱이 `GoogleSignin.signIn({ nonce: hash })` 호출 → idToken 안에 hash 가 포함됨
    4. 앱이 `window.YoungmanBridge.handle('onGoogleSignInResult', { idToken })` inject
    5. 웹이 `supabase.auth.signInWithIdToken({ provider:'google', token: idToken, nonce: rawNonce })` 호출
    6. Supabase 가 raw nonce 를 hash 해서 token 안의 hash 와 비교 → 검증 통과 → 세션 set
    7. 성공 시 login-complete.html 로 이동 → 기존 consent/ensure/redirect 흐름 그대로 재사용
  - 브라우저(앱 외부)는 기존 signInWithOAuth redirect 흐름 그대로 — isInApp() 한 줄 분기

- **logout.html — navigation race 대비**
  - SIGNED_OUT 이벤트가 location.replace 와 race 해서 앱에 못 닿는 케이스 차단
  - signOut 직전에 `import('./bridge.js').then(b => b.notifyLogout())` 명시 호출

- **BRIDGE_API.md — RN 측 구현 스펙 + 코드 스니펫**
  - 메시지 타입 표, app→web 핸들러 4종 + onGoogleSignInResult, RN 측 onMessage switch 풀스니펫
  - GoogleSignin.configure webClientId 는 Supabase Dashboard Auth Providers Google "Authorized Client IDs" 와 일치해야 함

- **deploy.yml — bridge.js 배포 추가**
  - Prepare deploy folder 의 cp 줄에 `bridge.js` 누락 시 라이브 404 발생 → 명시 추가

- **검증 시나리오 (앱팀 logcat 으로 확인 가능)**
  1. 로그인 → `auth.login { accessToken, userId, email, expiresAt }` 도착
  2. DevTools 콘솔에서 `window.YoungmanBridge.requestFcmToken()` → 앱이 `onFcmToken(token)` 으로 응답
  3. profile.html 의 solapi.com 링크 클릭 → `nav.openExternal { url }` 도착
  4. 앱에서 Google 로그인 버튼 클릭 → `auth.googleSignIn.request { nonce }` → 앱 SDK → `onGoogleSignInResult { idToken }` → `auth.login` 끊김 없이 연결

### 2026-05-21 작업 요약

- **인증/가입 — Google 모달 보강**
  - login-complete.html: 닉네임 row 아래 휴대폰 input 슬롯 추가, auth-profile PUT 에 phone 동시 전송, user_metadata 동기화
  - 모달 트리거 조건 broaden: `isGoogle = provider OR providers[] OR identities[].google`
  - needsExtra = `isGoogle && (needsNickname || needsPhone)` — 휴대폰만 없어도 모달 강제 표시
  - 기존 nickname 있을 때 pre-fill + 중복확인 skip
  - 서버(records.php) auth-member ensure 응답에 needsPhone 필드 추가 (existing member 도 phone 복호화 후 판정)

- **모바일 토큰 만료 자동 복구**
  - 증상: 모바일 백그라운드 탭에서 1시간+ 후 forms/관리대장 진입 시 "지원하지 않는 인증 토큰입니다." 에러
  - 원인: setInterval 기반 supabase auto-refresh 가 mobile background 에서 멈춤
  - 수정 (auth-shared.js apiRequest): expires_at-60초 검사 후 선제 refreshSession + 401 응답 시 한 번 더 refresh + retry

- **헤더 닉네임 "보였다 안 보였다" 증상**
  - 원인: bootApp 의 첫 mountAppHeader 가 initSupabase 완료 전 동기 실행 → cachedName=='', currentSession=null → 빈 span 렌더, refreshAppHeader apiRequest 완료까지 빈 표시
  - 수정: mountAppHeader 가 currentSession.user 있으면 getDisplayName(null, user) 즉시 도출 + cache, refreshAppHeader 가 apiRequest 전에 metadata 기반 이름 우선 cache & 재렌더

- **Solapi 미연동 안내 모달**
  - customers → 문자발송 클릭 시 confirm() 대체 → 큼직한 안내 모달
  - 단계 4개 ol: ①solapi.com 가입 ②console.solapi.com/credentials API 발급 ③API KEY/SECRET 복사(SECRET 조회버튼 안내) ④내 정보>문자설정 붙여넣기
  - 요금 충전 안내 박스 (주황 배경, "사용자 Solapi 계정 잔액 차감, 충전 필요")
  - 60대+ 사용자 가독성: 14.5~15px, color contrast 강조, 클릭 가능 링크

- **검색 input 한글 IME 조합 깨짐 (모바일)**
  - 1차 시도: compositionstart/end + debounce (10d380a) — 일부 모바일 키보드가 jamo 별 compositionend 발화하는 케이스 미해결
  - 근본 fix (8d4864b): filterDOMRowsBySearch() — renderRecords() 호출 대신 기존 DOM 행에 textContent.includes(q) 매칭, 안 맞으면 display:none. input 요소 자체는 절대 재생성 안 됨 → IME composer 바인딩 유지
  - 적용: customers/contracts/org/forms.js

- **모바일 카드 toolbar 레이아웃**
  - + 행 추가 버튼이 검색바에 눌려 찌그러지던 증상
  - 1차: 줄바꿈 분리 (5a8ad9d) → 사용자 한 줄 요청 → 8eae73f: flex-wrap:nowrap + 버튼 flex:0 0 auto + white-space:nowrap + min-height:40px

- **모바일 하단 고정 nav 글래스 톤**
  - 기존 짙은 회색 베이지(rgba(214,208,199,0.96)) → 흰색 반투명 그라데이션 + blur(26px) saturate(180%) + 윗면 inset highlight
  - active 아이템 inset highlight + 약한 빨강 그림자로 살짝 떠있는 효과

## 8. 절대 건드리면 안 되는 부분

- 🔒 **PII owner_email 격리** — 모든 SELECT/UPDATE/DELETE 에 `WHERE owner_email = :current_user` 강제. admin 우회 없음
- 🔒 **`git add -A` 금지** — 작업 폴더의 .xlsx/.csv/.pdf 등 PII 새어나갈 수 있음. 명시 add 만
- 🔒 **YOUNGMAN 브랜드** — logo_main.png + seal-red(#c8362c)
- 🔒 **SSH/SCP 배포 시도 금지** — 작동 안 함
- 🔒 **서버 설정 파일 repo 커밋 금지** — supabase_config.js/php, db_config.php, .env 는 FTP/GitHub Secret 만
- 🔒 **records.php `/auth/v1/user` 폴백** 유지 — 마지막 보루
- 🔒 **dhlottery 직접 호출 부활 금지** — IP 차단 영구
- 🔒 **인증 일원화 구조** — 로그인/로그아웃은 logout.html / login-complete.html 단일 흐름. 분산된 핸들러 다시 만들지 말 것
- 🔒 **ledger UX 패턴** — 헤더 클릭 필터 / 행 추가 모달 / accordion 그룹. 인라인 거부됨
- 🔒 **카드 expanded 상태 보존** — _expandedRowIds + MutationObserver. 토글 시 자동 접힘 회귀 금지
- 🔒 **`<a href="logout.html">` native navigation** — JS click handler 의존으로 회귀 금지 (모바일 user gesture 손실 위험)
- 🔒 **module top-level `return` 금지** — `<script type="module">` 안에서 if/else 구조로 작성
- 🔒 **ensureMemberRowOnce** — 모든 페이지 boot 시 자동 호출. 제거 시 고아 user 403 회귀
- 🔒 **PII 컬럼 폭 자동 확장** — ensure_member_pii_columns_wide. 제거 시 22001 회귀
- 🔒 **OAuth click handler 동기 흐름** — `await initSupabase` 추가 금지 (모바일 navigation 차단)
- 🔒 **prompt: 'select_account'** — Google OAuth 옵션. 제거 시 계정 선택 화면 skip
- 🔒 **signOut({ scope: 'global' })** — logout.html. local 로 회귀 금지 (다른 디바이스 살아남음)
- 🔒 **forms accordion-card UI 통일** — 사용자 명시
- 🔒 **SMS 회원별 자격증명** — 영맨은 발송 중계만 (단 관리자 키는 OTP 발송용 별도)
- 🔒 **sms_logs 원문 저장 금지** — phone_masked + message_hash 만
- 🔒 **헤더 anon 결정**에 cachedName **OR** currentSession 둘 다 — 로그인 직후 헤더 stale 회귀 금지
- 🔒 **검색 input 재생성 금지** — renderRecords() 호출 대신 filterDOMRowsBySearch() 로 hide/show. input 요소 재생성하면 모바일 한글 IME 즉시 깨짐
- 🔒 **apiRequest 토큰 refresh + 401 retry** — 제거 시 모바일 백그라운드 후 "지원하지 않는 인증 토큰" 회귀
- 🔒 **mountAppHeader 의 currentSession 즉시 도출** — 제거 시 헤더 닉네임 "보였다 안 보였다" 회귀
- 🔒 **apiRequest 호출 형식** — `apiRequest('xxx', { query: 'k=v' })`. URL 직접 X (인코딩 깨짐)
- 🔒 **normalize_resource 화이트리스트** — 신규 resource 추가 시 $allowed 배열에도 추가 (안 그러면 400)
- 🔒 **bridge.js 메시지 타입 이름** — `auth.login` / `auth.logout` / `auth.googleSignIn.request` / `onGoogleSignInResult` / `nav.openExternal` 등. 변경 시 RN 앱쪽 onMessage / injectJavaScript 동시 수정 필수 (단방향 회귀 = 앱 깨짐)
- 🔒 **`window.YoungmanBridge` 전역 이름** — RN 앱의 injectJavaScript 가 이 이름 가정. rename / namespace 분리 금지
- 🔒 **Google 로그인 in-app 분기: signInWithIdToken 직접 호출** — 백엔드 검증 엔드포인트(`/api/auth/google` 류) 만들지 말 것. Supabase v2 가 idToken + nonce 직접 검증. 백엔드 만들면 service_role 노출 표면적 늘고 동기화 부담만 증가
- 🔒 **Google 로그인 nonce 처리** — raw nonce 는 웹만 보유, hash 만 앱에 전달. 앱이 raw 알게 되면 replay 보호 무력화. 흐름 변경 금지
- 🔒 **deploy.yml 의 bridge.js cp** — 누락 시 라이브 404 → auth-shared.js import 실패 → 전체 페이지 깨짐

## 9. 다음에 이어서 해야 할 작업

1. **card-builder 정리** — Recraft overlay primary path + AI/템플릿 토글 UI
2. **PII 평문 → 암호문 backfill** — ledger_records / members 전체 일괄 암호화 스크립트
3. **forms 수식 inline help** — 함수/path 카탈로그 모달
4. **profile/admin 디자인 일관성 감사** — 새 랜딩 톤과 맞추기
5. **Supabase Email Template 한글화** — Dashboard → Authentication → Email Templates 직접 수정 안내
6. **로또 자동 갱신** — JSON 미러 cron 자동화 (현재 수동 + 토큰 코드만)
7. **메인 페이지 추가 다듬기** — 사용자 추가 피드백 가능성
8. **RN Android 앱 측 검증 마무리** (Codex 작업) — logcat 으로 `auth.login` / `onGoogleSignInResult` 도착 확인. `GoogleSignin.configure({ webClientId })` 는 Supabase Dashboard 의 Authorized Client IDs 와 정확히 일치해야 검증 통과. 토큰 영구 저장은 SecureStore (AsyncStorage 평문 X), FCM `onTokenRefresh` 구독 필요
9. **Marketing.html 브리지 포함 검토** — 현재 auth-shared 미사용 (마케팅 공개 페이지). 앱에서 진입할 일 없으면 그대로 OK, 진입 가능하면 bridge.js 직접 include

---

## 자가 진단 채널 (디버깅용)

- `sessionStorage.erp.ensureError` : members 보강 실패 시 status/error/columns/table/sqlState JSON 저장
- `sessionStorage.erp.memberEnsured = '1'` : 보강 성공 (session 당 1회)
- `sessionStorage.erp.endSessionOnClose = '1'` : 로그인 유지 체크 해제 상태
- 콘솔 prefix: `[auth submit]` / `[signIn]` / `[signUp]` / `[google oauth]` / `[google native]` / `[ensure member auto]` / `[members POST]` / `[sms balance]` / `[bridge]`
- 브리지 디버깅 (DevTools 콘솔): `window.YoungmanBridge.isInApp()` / `.getAppInfo()` / `.getFcmToken()` / `.postToApp('debug.ping', {})` / `.version`

## 메모리 참조

- `feedback_auth_flow_lessons.md` — 인증 13가지 원인 + 단일 페이지 일원화 + 자동 ensure + 컬럼 폭 확장
- `feedback_css_edit_sanity.md` — 큰 Edit 후 brace balance + 중복 selector + module top-level return 금지
- `feedback_deploy_autonomy.md` — "배포/올려" 키워드 트리거
- `feedback_no_proceed_prompts.md` — "Do you want to proceed?" 묻지 말 것
- `project_app_bridge.md` — RN Android WebView 앱 연동 인프라 + Google 로그인 in-app 분기 (signInWithIdToken)
- 기타 PII / ledger UX / 모바일 nav / 슬롯 nav 등
