# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-19 (PortOne + 토스페이먼츠 구독 결제 시스템 풀스택 ship + flagship CTA + admin 강화)*

## 1. 사이트 목적

**YOUNGMAN / 영맨** — 1인 사업자용 AI 영업 플랫폼.
- CRM(고객) / HRM(조직도·계약자) / 마케팅 도구 / 로또 분석 / 단체 SMS 통합
- 한국 캘리그라피 + 인장(seal-red #c8362c), Apple/Linear 미니멀 톤
- 라이브: https://youngman-biz.com (Cafe24 호스팅 + Supabase Auth + MariaDB + PHP API)
- 인증: Supabase Cloud + JWT (sb_publishable_ 키, **PHP session 안 씀**)
- 최근 추가: RN Android WebView 앱 브리지 + 통화 녹취 → AI 요약 → CRM + **구독 결제 (PortOne V2 + 토스페이먼츠)**

## 2. 주요 파일 구조

```
[프론트 페이지]
index.html / login-complete.html / logout.html
profile.html(.js) / admin.html(.js)
org.html(.js) / contracts.html(.js) / customers.html(.js)
forms.html(.js) / board.html(.js) / card-builder.html / lotto2233.html
Marketing.html / kapp_premium.php
terms.html / privacy.html
refund.html / auto-billing.html       — 정책 (PortOne 심사 통과 필수)
subscribe.html / billing.html          — 구독 결제 UI

[공통 JS]
auth-shared.js          — Supabase + 헤더/footer + 인증 + bridge.js import
bridge.js               — RN Android WebView 앱 브리지 (window.YoungmanBridge)
ledger-shared.js        — 관리대장 공통

[PHP API — cafe24 webroot 평면 배포]
api/records.php         — 모든 CRUD (customers/employees/ledger-*/admin-*/auth-*/
                          find-*/sms-*/mobile-tokens/customer-log/app-fcm-token/recording-job)
api/process-recording.php — 통화 녹취 → CLOVA STT → gpt-4o-mini 요약 → customer_log
api/fcm_helpers.php     — FCM HTTP v1
api/audio_cleanup.php   — 24h audio cron cleanup
api/sms/...             — SMS 발송
api/crypto_helpers.php  — AES-256-GCM (enc:v1: prefix)
api/ledger-mobile.php   — 모바일 앱 Bearer 토큰
api/upload.php          — 파일 업로드
api/billing_helpers.php — PortOne V2 헬퍼 (billing_pdo / portone_api_call /
                          billing_require_bearer_email / portone_extract_status 등)
api/billing/verify-payment.php       — 빌링키로 첫 결제 + DB 갱신
api/billing/webhook-portone.php      — Webhook 수신 (Standard Webhooks 검증)
api/billing/cancel-subscription.php  — 해지 (cancel_at_period_end=1)
api/billing/cron-renew.php           — 매일 KST 03:00 정기결제 cron
api/billing/config.php               — storeId/channelKey publishable

[디자인 / 자산 / 문서]
style.css / logo_main.png
BRIDGE_API.md             — 앱 ↔ 웹 메시지 스펙
CALL_RECORDING_BACKEND.md — 통화 녹취 → AI 요약 백엔드 spec

[CI]
.github/workflows/deploy.yml                  — FTP 배포 + .env 어셈블
.github/workflows/audio-cleanup-schedule.yml  — 매일 KST 04:00 audio cleanup
.github/workflows/billing-renew.yml           — 매일 KST 03:00 빌링키 자동결제 cron
```

## 3. 현재 완성된 기능

- ✅ Supabase Auth (이메일 + Google OAuth, 회원가입/로그인/로그아웃/비번 변경)
- ✅ 인증 일원화 (logout.html / login-complete.html 단일 transition)
- ✅ 고아 user 자동 복구 (ensureMemberRowOnce)
- ✅ 로그인 유지 체크박스 (pagehide/beforeunload 자동 sb-* 삭제)
- ✅ 아이디/비밀번호 찾기 (SMS 인증)
- ✅ RN Android 앱 브리지 (bridge.js, isInApp 2단계 신호: ReactNativeWebView + UA 'YoungmanApp')
- ✅ 앱 안 Google 로그인 (native SDK + signInWithIdToken + nonce hash)
- ✅ 카카오톡 등 in-app browser Google 로그인 안내 (Android Chrome intent / iOS 안내)
- ✅ 조직도/계약자/고객 관리대장 통합 (page_type 기반)
- ✅ PII 사용자별 격리 + AES-256-GCM 암호화
- ✅ 단체 SMS 발송 (Solapi/Aligo) + 잔액 카드
- ✅ 모바일 하단 네비게이션 (4탭) + bfcache reload
- ✅ 사용자 정의 양식 빌더 (forms Phase 1~3, 8타입)
- ✅ forms 수식 함수 라이브러리 + 캐스케이드 picker
- ✅ 양식 슬롯 시스템 (slot1=조직도/slot2=계약자, caret hover dropdown)
- ✅ 모바일 카드 별도 DOM 렌더
- ✅ 모바일 API 토큰 발급 UI (profile)
- ✅ 회원 탈퇴 + NICE 휴대폰 본인확인 약관

### ✅ 통화 녹취 → AI 요약 → CRM (Phase 1+2 라이브)

- **STT provider toggle (2026-05-19 추가)**: `STT_PROVIDER` 환경변수로 분기
  - `clova` (기본): Naver CLOVA Speech LSR — 화자분리 포함, 회당 **~180원**
  - `whisper`: OpenAI Whisper API — 화자분리 없음, 회당 **~50원** (-72%)
  - **자동 fallback (옵션 c, 앱팀 합의)**: STT_PROVIDER=whisper 인데 업로드 파일 확장자가 `3gpp/3gp/amr` 이면 자동으로 CLOVA 호출. 앱 변경 없이 모든 디바이스 호환. NCP 설정 없으면 415.
  - **확장자 판별**: `original_filename` 우선 (앱이 multipart 의 Content-Type 을 'audio/mp4' 로 하드코딩하므로 헤더 신뢰 불가). fallback: 서버 저장 파일명.
  - **duration 추출**: 앱이 보낸 `duration_sec` (MediaStore Audio.Media.DURATION) 우선. 0 이면 STT response 의 duration 폴백.
  - `ai_model` 컬럼은 동적 생성 (`{stt}+{llm}` 패턴)
- **LLM provider toggle (2026-05-19 추가)**: `LLM_PROVIDER` 환경변수로 분기
  - `openai` (기본): gpt-4o-mini — 회당 **~1원**, 메모 수준 품질
  - `anthropic`: Claude Sonnet 4.6 + prompt caching — 회당 **~7~21원**, **보고서 수준** (한국어 뉘앙스/감정/next_action 정교도 우위)
  - prompt caching: system 메시지 `cache_control: ephemeral` → 5분 TTL, hit 시 input 90% 절감
- **분 기반 사용량 추적 (Phase 1 — 2026-05-19, 백엔드 only)**:
  - `members.summary_limit_minutes` (분 한도), `members.usage_seconds_period` (이번달 누적 초) 컬럼 추가 (lazy ALTER)
  - `members.overage_enabled` / `overage_balance_seconds` / `overage_top_up_count` / `overage_last_top_up_at` 자동 충전 컬럼 추가
  - `plan_default_summary_limit_minutes()`: Free=30, Plus=300, Pro=1,000, trialing=30
  - `overage_top_up_seconds()`: 4,286초 (= 71.43분, ₩5,000 / 분당 70원)
  - process-recording.php 가 통화 길이(초) 추출 후 `usage_seconds_period` 누적. 차감/한도 체크는 **Phase 2 에서 활성화** (현재는 회 단위 병행 운영)
- **Phase 1 (sync)**: 앱 m4a → Clova STT(ko, 화자분리 2명) → gpt-4o-mini JSON → customer_log + AES-256-GCM
- **Phase 2 M1~M4**: app-fcm-token / async mode + recording-job 폴링 / FCM HTTP v1 / 24h audio cleanup
- **옵션 D (라운드 4 완성)**: customer_log_send_to_group — **8필드 매핑** (managed/date/call_count/customer/phone/content/agent_memo/memo). 자동 default 그룹 생성 + lazy 마이그레이션 + Idempotency
- **phone merge**: 같은 group + 정규화된 phone 일치하는 기존 row 가 있으면 INSERT 대신 UPDATE 누적. 최신 통화 = 최상단 (sort_no = MIN - 1)
- **backfill catch-up**: send_to_group 시 같은 phone 의 모든 unlinked customer_log 도 같이 link
- **고객관리대장 UI 통일**: 모든 텍스트 셀 2줄 clamp + 가운데 + click 상세 모달, 가로 스크롤 제거, 날짜 6자리(YY.MM.DD), 모바일 카드 접힘 시 "강동원 (3)번 통화함" 자연어 태그
- **AI 요약 톤/구조** 옛 대화형(`~습니다`) 으로 롤백 — PPT 톤(`37fca8b`)은 git 보존, 두 모드 분기 작업 시 복원 예정
- 카드 expanded 상태 보존 (_expandedRowIds + MutationObserver)

### ✅ 구독 결제 시스템 — PortOne V2 + 토스페이먼츠 (2026-05-19 풀스택)

- **요금제**: Free(0) / Plus(₩19,000) / Pro(₩39,000). 신규 가입자 trialing 5회 무료체험.
- **사용량 카운트**: "통화녹음 → AI 요약 → 고객관리대장 저장" 전체 1회 (process-recording 시점 +1).
- **결제 흐름** (2단계 분리 — 토스는 IssueBillingKeyAndPay 미지원):
  1. 클라이언트: PortOne SDK `requestIssueBillingKey({ billingKeyMethod:'CARD', ... })` → 카드 등록 창 → billingKey 받음
  2. 서버: `/billing/verify-payment.php` POST → PortOne API `POST /payments/{id}/billing-key` 호출 → status=PAID 검증 → DB 갱신
- **모바일**: REDIRECTION 모드 자동 적용 (IFRAME 이 화면 폭 벗어남 → 토스 페이지 전체 화면 이동 + 복귀)
- **정기결제 cron**: `.github/workflows/billing-renew.yml` 매일 KST 03:00 → `/billing/cron-renew.php` → 만료된 row PortOne 빌링키 결제 호출. 실패 시 past_due. cancel_at_period_end=1 + 만료 시 free 강등.
- **Webhook**: PortOne 콘솔 → `/billing/webhook-portone.php` (Standard Webhooks signature 검증)
- **해지**: cancel_at_period_end=1 + PortOne 빌링키 DELETE. 기간 만료 시 cron 이 free 로 강등.
- **DB 마이그레이션** (lazy ALTER):
  - members: plan / plan_status / portone_customer_id / portone_billing_key / portone_subscription_id / current_period_start / current_period_end / cancel_at_period_end / summary_limit / last_usage_reset_at
  - subscriptions / payments / usage_logs 테이블 (billing_pdo() 호출 시 자동 ensure)
- **summary_limit 자동 동기화**: plan 변경 시 verify-payment / cron-renew / admin PATCH 모두 자동 (plus=20, pro=NULL, trialing=5, free=0). 옛 trialing default 5 잔재 일괄 마이그레이션.
- **premium → plus alias**: Phase 1 'premium' 값을 'plus' 로 자동 마이그레이션 + UI alias.
- **admin > 회원 관리 강화**: 회원 표에 플랜/사용량/다음 결제일 컬럼. 편집 모달에 구독 플랜 / 구독 상태 / 사용량 / **만료일 입력 + 빠른 버튼**(+7/14/30/90일/+1년/지우기) — 현금/오프라인 결제 + 테스터 무료기간 수동 부여.
- **정책 페이지**: refund.html (환불정책) + auto-billing.html (자동결제 안내). 사업자정보 (어센트라 / 393-39-01518) 는 footer + terms/privacy 에 기존 등록.

### ✅ 메인 페이지 flagship CTA (2026-05-19)

- "AI 통화 요약 + 고객관리 원터치 전송 서비스 신청" — **매출 최우선 CTA**
- 위치: PC = "전화만 하세요" 직후 / 모바일 = 스마트폰 SVG 와 eyebrow 사이 정중앙
- 디자인: 황금색 메탈릭 (#b8870c → #d4a017 → #b8870c) + 검정 좁은 그림자 + radius 12px + shimmer 빛 sweep (3.6초 간격) + 흰 텍스트 + drop shadow
- plan 별 분기:
  - 비로그인/free/trialing → 신청 텍스트 → `/subscribe.html`
  - plus/premium → "AI 요약 무제한을 원하신다면 Upgrade to Pro" → `/subscribe.html`
  - pro → 숨김

## 4. 아직 미완성인 기능

- ⏳ **PortOne 콘솔 Webhook URL 등록** — `https://youngman-biz.com/billing/webhook-portone.php` (사용자 직접)
- ⏳ **정식 토스 키 발급 후 라이브 결제 검증** — 테스트 키 환경에서 `NOT_SUPPORTED_CARD_TYPE` 으로 e2e 결제 검증 미완 (정식 키 도착 후 30분이면 됨)
- ⏳ **통화 요약 HTTP 401** — process-recording 의 진단 정보 (`debug.stage` / `auth_status`) 추가됨, 사용자 새 통화 시도 후 회신 대기. 가장 가능성: 앱 토큰 만료 → `window.YoungmanBridge.refreshSession()` 추가 필요할 수 있음 (앱팀 결정 대기)
- ⏳ **GitHub Actions M4 audio-cleanup workflow_dispatch dry_run** — 사용자 트리거 미완
- ⏳ **AI 요약 두 모드 분기** — 대화형(legacy) / 요약정리형(PPT). [[project_ai_summary_modes]]. profile.html 라디오 + members.ai_summary_mode + prompt 분기. PPT prompt 는 `37fca8b` 에 보존
- ⏳ **card-builder UX** — Recraft overlay primary + AI/템플릿 토글
- ⏳ **PII 평문 → 암호문 일괄 backfill 스크립트** (lazy 외 일괄)
- ⏳ **forms 수식 inline help** — 함수/path 카탈로그 모달
- ⏳ **profile/admin 디자인 일관성 감사**
- ⏳ **Supabase Email Template 한글화** (Dashboard 수동)
- ⏳ **Marketing.html 브리지 포함 검토**

## 5. 배포 방식

- **GitHub Actions → FTP** via `SamKirkland/FTP-Deploy-Action`
- 주 워크플로우: `.github/workflows/deploy.yml`
- 보조 워크플로우:
  - `audio-cleanup-schedule.yml` (매일 KST 04:00)
  - `billing-renew.yml` (매일 KST 03:00 — 빌링키 자동 결제)
- 시크릿 (필수):
  - `CAFE24_FTP_PASSWORD` / `YOUNGMAN_CRYPTO_KEY` / `SUPABASE_SERVICE_KEY`
  - `NCP_CLOVA_INVOKE_URL` / `NCP_CLOVA_SECRET`
  - `FIREBASE_SERVICE_ACCOUNT_JSON` / `AUDIO_CLEANUP_TOKEN`
  - **`PORTONE_STORE_ID` / `PORTONE_API_SECRET` / `PORTONE_WEBHOOK_SECRET` / `PORTONE_CHANNEL_KEY_TOSS`** (2026-05-19 등록 완료)
  - `STT_PROVIDER` (선택, 미설정 시 'clova') — 'whisper' 로 설정 시 OpenAI Whisper 사용
  - `LLM_PROVIDER` (선택, 미설정 시 'openai') — 'anthropic' 으로 설정 시 Claude Sonnet 4.6 + prompt caching 사용
- "배포/올려" 키워드 → push 안내 (사용자 직접 push 후 Actions 자동) — 이 환경에는 GitHub push 자격 없음
- 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)`
- **신규 페이지 추가 시** deploy.yml 의 `Prepare cp` + `Validate test -f / php -l` 둘 다 추가
- **Secret 변경 시** 빈 commit push 로 재배포: `git commit --allow-empty -m "..."`

## 6. Cafe24/PHP 관련 주의사항

- 🚫 **SSH/SCP 절대 금지** — silent drop. FTP only.
- 🚫 **cafe24 cron 미지원** — GitHub Actions schedule 로 대체 (audio_cleanup / billing-renew)
- 🚫 **cafe24 빈 POST body → 5xx HTML** — multipart/JSON body 1바이트 이상 필수
- 🚫 **cafe24 ffmpeg 미설치** — 통화 녹음 transcode 불가 → Clova Speech 가 3gpp/AMR 네이티브 처리
- 🚫 **dhlottery 직접 호출 금지** — cafe24 IP 차단 영구. JSON 미러만
- 📁 **Webroot flat layout** — `api/records.php` → 배포 후 `/records.php`. `__DIR__` 기준
- 📁 **`api/sms/` → `deploy/sms/providers/` cp**
- 📁 **`api/billing/` → `deploy/billing/` cp** (mkdir -p)
- 🔐 **YOUNGMAN_CRYPTO_KEY 분실 = 복호화 영구 불가** — GitHub Secret 백업 필수
- 🔐 **PORTONE_API_SECRET / WEBHOOK_SECRET** — 채팅 노출 금지. 노출 시 PortOne 콘솔에서 즉시 재발급 + Secret 갱신
- 🔐 **NCP_CLOVA_SECRET / FIREBASE_SERVICE_ACCOUNT_JSON / AUDIO_CLEANUP_TOKEN** — 채팅 노출 금지
- 📡 **deploy/.env 매번 어셈블** — FTP 로 직접 넣은 키는 다음 deploy 에 덮어쓰임
- 📡 **PHP 가 .env 자동 로드 안 함** — `billing_load_env_value()` 또는 `load_env_value()` 로 파일 직접 파싱
- 📡 **VITE_SUPABASE_URL** 가 `https://xxx.supabase.co/rest/v1/` 형태 → `preg_replace('#/(rest|auth)/v1/?.*$#', '', $url)` 로 root 추출 후 `/auth/v1/user` 호출
- 📡 **db_config.php** — `return [host=>..., port=>..., database=>..., user=>..., password=>...]` array 반환 패턴. `$DB_HOST` 같은 변수 set 안 함
- 📡 **records.php `/auth/v1/user` 폴백 유지**
- 📡 **PHP timeout 30초** — send-bulk.php `set_time_limit(120)`, process-recording.php `set_time_limit(240)`
- 📊 **PII 컬럼 폭** — 암호문 100~200 chars. 새 PII 컬럼은 최소 VARCHAR(255)

## 7. 최근 수정한 파일 (커밋 흐름)

```
878c04e fix(subscribe): 모바일 결제창 — REDIRECTION 모드 + 복귀 흐름
e68a38f fix(index): Plus 사용자 클릭 시 결제 페이지로 (billing.html → subscribe.html)
e2d23f7 ui(index): flagship CTA 텍스트 부각 (font-weight 700 + 그림자 강화)
a16f094 ui(index): flagship CTA — 황금색 메탈릭 + shimmer + 영문 + 아이콘 제거
13944dd feat: hero flagship CTA(plan 분기) + admin 구독 컬럼 + 로또 갱신 카드
0698c37 feat(index): hero 'AI 통화 요약 + 원터치 전송 서비스 신청' CTA
aabff13 fix(billing): summary_limit 동기화 + 옛 데이터 마이그레이션
71803f2 fix(billing): billing_pdo 호출 시 테이블 자동 생성
4998ba9 fix(billing): 일괄 점검 — DB / Supabase URL / 응답 schema / 인증 통일
d661938 fix(billing): PortOne 응답 schema 다층 탐색 + 비동기 PAID 대기
477884d fix(billing): 결제 토큰 만료 진단 + 세션 사전 검사
e4036f6 fix(billing): Supabase URL '/rest/v1/' 잔재 제거
3def88c fix(call-recording): process-recording 401 진단 정보 추가
06ce9fb fix(billing): .env 직접 파싱 — cafe24 PHP 자동 로드 X
54ae0c3 fix(billing): 빌링키 + 결제 2단계 분리 (토스페이먼츠 호환)
e5593a1 fix(billing): billingKeyMethod → billingKeyAndPayMethod
5b0812f feat(billing): 정기결제 cron + GitHub Actions schedule
e0efe39 feat(billing): 결제 버튼 + 해지 버튼 활성화 + config.php
77528ae feat(billing): PortOne V2 endpoint 3종 + deploy.yml
7c0e8d1 feat(billing): /billing 구독 관리 페이지
66587fd feat(billing): /subscribe 요금제 비교 페이지
50df9e0 feat(billing): 환불정책 + 자동결제 안내 페이지
6dbaf1f feat(billing): DB 스키마 + admin plan 편집 + quota 분기
170088e feat(admin): 회원 편집 모달 만료일 입력 + 빠른 버튼
39fc556 fix(billing): premium → plus 자동 마이그레이션
```

**미커밋:** `SMS_USER_GUIDE.txt` (untracked)

## 8. 절대 건드리면 안 되는 부분

- 🔒 **PII owner_email 격리** — 모든 SELECT/UPDATE/DELETE 강제. admin 우회 없음 (단 `is_admin_email_for_recording` quota 우회는 예외)
- 🔒 **`git add -A` 금지** — PII 새어나갈 위험. 명시 add 만
- 🔒 **YOUNGMAN 브랜드** — logo_main.png + seal-red(#c8362c)
- 🔒 **SSH/SCP 배포 시도 금지**
- 🔒 **서버 설정 파일 repo 커밋 금지** — supabase_config.js/php, db_config.php, .env
- 🔒 **records.php `/auth/v1/user` 폴백 유지**
- 🔒 **dhlottery 직접 호출 부활 금지**
- 🔒 **인증 일원화 구조** — logout.html / login-complete.html 단일
- 🔒 **ledger UX 패턴** — 헤더 클릭 필터 / 행 추가 모달 / accordion 그룹
- 🔒 **카드 expanded 상태 보존** — _expandedRowIds + MutationObserver
- 🔒 **`<a href="logout.html">` native navigation**
- 🔒 **module top-level `return` 금지**
- 🔒 **ensureMemberRowOnce / PII 컬럼 폭 자동 확장**
- 🔒 **OAuth click handler 동기 흐름** — `await initSupabase` 추가 금지
- 🔒 **prompt: 'select_account' / signOut scope: 'global'**
- 🔒 **SMS 회원별 자격증명 / sms_logs 원문 저장 금지**
- 🔒 **검색 input 재생성 금지** — filterDOMRowsBySearch hide/show 만
- 🔒 **apiRequest 토큰 refresh + 401 retry**
- 🔒 **mountAppHeader currentSession 즉시 도출**
- 🔒 **bridge.js 메시지 타입 / window.YoungmanBridge 전역 이름 / isInApp 2단계 신호 (ReactNativeWebView + UA 'YoungmanApp')**
- 🔒 **Google 로그인 signInWithIdToken 직접 / nonce raw 웹 / hash 앱 전달 / in-app browser 분기 (카카오톡 등 Android Chrome intent)**
- 🔒 **deploy.yml 의 bridge.js cp**
- 🔒 **forms 사용 모드 UI = accordion-card**
- 🔒 **새 entry HTML inline script** — `initSupabase()` 또는 `bootApp()` 동반 필수
- 🔒 **모바일 카드 별도 DOM 렌더**
- 🔒 **records.php $selfAuthResources** — `['customer-log', 'app-fcm-token', 'recording-job']`
- 🔒 **customer_log_send_to_group 8필드 매핑** + lazy 마이그레이션 (call_count key 없으면 갱신)
- 🔒 **calculate_call_count / backfill_same_phone_links** — 정규화 phone 매칭, 본인 제외
- 🔒 **phone merge sort_no 정책** — MIN-1 로 최상단 이동
- 🔒 **process-recording.php async** — fastcgi_finish_request + ignore_user_abort + register_shutdown_function 셋 다 유지
- 🔒 **fcm_helpers.php RS256 self-signed JWT** — openssl_sign 직접
- 🔒 **user_fcm_tokens UNIQUE token** — UPSERT 동작
- 🔒 **audio_cleanup.php hash_equals + audio_kept=1 보존**
- 🔒 **is_admin_email_for_recording allowlist** — `nxnxax@gmail.com`
- 🔒 **Clova Speech params** (STT_PROVIDER=clova 시) — `language=ko-KR`, `completion=sync`, `fullText=true`, `diarization` 2명
- 🔒 **Whisper params** (STT_PROVIDER=whisper 시) — `model=whisper-1`, `language=ko`, `response_format=verbose_json`, `temperature=0`, `prompt`=한국어 영업 컨텍스트 힌트
- 🔒 **ai_model 컬럼** — `{sttModelName}+{llmModel}` 동적 (`naver-clova-speech+gpt-4o-mini` 또는 `openai-whisper-1+gpt-4o-mini`)
- 🔒 **system prompt 화자 라벨 분기** — Clova 는 `[화자1]/[화자2]` 라벨 포함, Whisper 는 평문. LLM 이 둘 다 처리 가능해야 함 (prompt 명시)
- 🔒 **billing_pdo()** — db_config.php candidate 4단계 검색 + billing_ensure_tables() 자동 호출
- 🔒 **portone_extract_status / portone_extract_amount** — schema 변동 안전망 (4 nested 위치 탐색)
- 🔒 **billing_require_bearer_email()** — Supabase URL 정규화 + auth_status 진단 응답
- 🔒 **subscribe.html PortOne 호출** — 토스 미지원이라 `requestIssueBillingKey` (And Pay 아님) + 모바일 `windowType.mobile: 'REDIRECTION'` + `redirectUrl`
- 🔒 **handleBillingReturn()** — `?billing_return=1` 감지 + localStorage 의 pending plan/issueId 복원
- 🔒 **summary_limit plan 별 default** (`plan_default_summary_limit`) — plus=20 / pro=null / trialing=5 / free=0
- 🔒 **premium → plus 자동 마이그레이션** — `ensure_members_plan_columns` 끝 UPDATE 한 줄
- 🔒 **flagship CTA plan 분기** — pro 면 display:none

## 9. 다음에 이어서 해야 할 작업

### 결제 시스템 마무리 (1순위)

1. **PortOne 콘솔에 Webhook URL 등록** — `https://youngman-biz.com/billing/webhook-portone.php`. 이벤트: Transaction.Paid / Failed / Cancelled / PartialCancelled. 사용자 직접.
2. **정식 토스 키 도착 시 라이브 검증**:
   - PortOne 콘솔 → 채널 라이브 전환 + 라이브 토스 키 입력
   - GitHub Secrets 의 `PORTONE_*` 4개 라이브 키로 갱신
   - 빈 commit push 로 재배포
   - 본인 카드로 1회 결제 e2e 검증 (₩19,000 또는 ₩39,000)
3. **통화 요약 HTTP 401 회신 대기** — `debug.stage` / `auth_status` 받아서 진단. 가장 가능성 = 앱 토큰 만료 → bridge.refreshSession() 추가 검토.

### STT 원가 절감 PoC — Whisper 검증 (2026-05-19 추가)

- **목표**: STT 원가 회당 180원 → 50원 (-72%) 또는 추가 30~50% 절감 (자체 호스팅 시)
- **방법**: GitHub Secret 에 `STT_PROVIDER=whisper` 등록 후 빈 commit push → 재배포 → 실 영업 통화로 품질 비교
- **검증 포인트**:
  - 한국어 영업/부동산 통화 transcript 품질 (NCP CLOVA 대비 정확도)
  - 7단계 customer_name 추출 로직이 화자 라벨 없이도 작동하는지 (LLM 추론 의존)
  - 3gpp/AMR 디바이스 (삼성 T전화) 사용자 비율 — 차단되면 영향 큼
- **롤백**: `STT_PROVIDER=clova` 로 재변경 + 재배포 (즉시)

### 기존 backlog

4. **GitHub Actions M4 audio-cleanup workflow_dispatch dry_run** — 사용자가 [audio-cleanup-schedule.yml](https://github.com/nxnxax/product-builder-jd/actions/workflows/audio-cleanup-schedule.yml) 트리거
5. **AI 요약 두 모드 분기** — [[project_ai_summary_modes]]. profile.html 라디오 + members.ai_summary_mode + prompt 분기. PPT prompt 는 `37fca8b` 에 보존
6. **SMS_USER_GUIDE.txt 처리** — 커밋 / .gitignore / 그대로
7. **card-builder UX** — Recraft overlay primary + AI/템플릿 토글
8. **PII 평문 → 암호문 backfill 스크립트** (lazy 외 일괄)
9. **forms 수식 inline help** — 함수/path 카탈로그 모달
10. **profile/admin 디자인 일관성 감사**
11. **Supabase Email Template 한글화** (Dashboard 수동)
12. **로또 자동 갱신** — JSON 미러 cron
13. **Marketing.html 브리지 포함 검토**

---

## 자가 진단 채널 (디버깅용)

- `sessionStorage.erp.ensureError` : members 보강 실패 시 JSON
- `sessionStorage.erp.memberEnsured = '1'` : 보강 성공
- `sessionStorage.erp.endSessionOnClose = '1'` : 로그인 유지 해제
- 콘솔 prefix: `[auth submit]` / `[signIn]` / `[signUp]` / `[google oauth]` / `[google native]` / `[ensure member auto]` / `[sms balance]` / `[bridge]` / `[process-recording]` / `[fcm]` / `[records]` / `[subscribe]` / `[billing/verify-payment]` / `[webhook-portone]` / `[cron-renew]`
- 브리지 디버깅: `window.YoungmanBridge.isInApp()` / `.getAppInfo()` / `.getFcmToken()` / `.version`
- 결제 진단: `/billing/config.php` GET → 200 + storeId 응답이면 .env 정상. `verify-payment` 응답의 `debug` 객체로 단계별 실패 위치 즉시 파악.

## 환경 한계 (이 클로드 워크스페이스)

- GitHub credentials / SSH key / gh CLI / FTP password 모두 **없음**
- `git push origin main` → `fatal: could not read Username` 즉시 실패
- 실제 push 는 **사용자가 터미널에서 직접 실행** — 메모리에 [[feedback_deploy_autonomy]]
- push 만 되면 GitHub Actions deploy.yml 이 FTP 업로드까지 자동 — 그 후 라이브 `curl` 검증은 다시 클로드 가능

## 메모리 참조 (~/.claude/projects/-home-user-jdhoon/memory/)

- `feedback_auth_flow_lessons.md` — 인증 root cause + 단일 페이지 일원화
- `feedback_css_edit_sanity.md` — 큰 Edit 후 brace balance 검증
- `feedback_deploy_autonomy.md` — "배포/올려" 키워드 → push 까지 안내 (이 환경 push 자격 없음)
- `feedback_no_proceed_prompts.md` — "Do you want to proceed?" 묻지 말 것
- `feedback_pii_isolation.md` — PII owner_email 강제, git add -A 금지
- `feedback_readability_first.md` — 60대+ 가독성 우선
- `feedback_ledger_ux.md` — 헤더 클릭 필터 / 행 추가 모달 / accordion
- `feedback_paste_formatting.md` — 외부 채팅 붙여넣기 메시지 코드블록 wrap
- `pending_call_recording_status.md` — call-recording 라운드 4 인계 + 대기 항목
- `project_app_bridge.md` — RN Android WebView 앱 연동
- `project_pii_crypto.md` — AES-256-GCM 라이브
- `project_ledger_system.md` — page_type 기반 그룹/레코드
- `project_youngman_redesign.md` — 브랜드 리디자인
- `project_nav_slots.md` — slot1/slot2 caret hover dropdown
- `project_mobile_bottom_nav.md` — 4탭 하단 nav
- `project_ai_summary_modes.md` — 대화형/PPT 두 모드 분기 예정 (PPT prompt `37fca8b` 보존)
- `deploy_cafe24.md` — FTP only, webroot flat layout
