# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-20 (Phase 2 분 단위 과금 + 자동 충전 + 인증 race condition fix + 앱팀 5가지 점검 반영 풀스택 ship)*

## 1. 사이트 목적

**YOUNGMAN / 영맨** — 1인 사업자용 AI 영업 플랫폼.
- CRM(고객) / HRM(조직도·계약자) / 마케팅 도구 / 로또 분석 / 단체 SMS 통합
- 한국 캘리그라피 + 인장(seal-red #c8362c), Apple/Linear 미니멀 톤
- 라이브: https://youngman-biz.com (Cafe24 호스팅 + Supabase Auth + MariaDB + PHP API)
- 인증: Supabase Cloud + JWT (sb_publishable_ 키, **PHP session 안 씀**)
- 최근 추가: RN Android WebView 앱 + 통화 녹취 → AI 요약 (Whisper+Claude) → CRM + **분 단위 과금 + 자동 충전** + PortOne V2 정기결제

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
subscribe.html / billing.html          — 구독 결제 UI (분 단위)

[공통 JS]
auth-shared.js          — Supabase + 헤더/footer + 인증 + bridge.js import
                          + _refreshInflight dedup + window.YoungmanBridge.refreshSession() hook
bridge.js               — RN Android WebView 앱 브리지 (window.YoungmanBridge)
ledger-shared.js        — 관리대장 공통

[PHP API — cafe24 webroot 평면 배포]
api/records.php         — 모든 CRUD (customers/employees/ledger-*/admin-*/auth-*/
                          find-*/sms-*/mobile-tokens/customer-log/app-fcm-token/recording-job)
                          + auth-profile 에 분 단위 + overage_* 필드 반환
                          + overage_enabled 본인 토글 PATCH
                          + admin-members PATCH 에 summary_limit_minutes / overage_balance_minutes 입력
api/process-recording.php — 통화 녹취 → STT (Whisper/CLOVA 3단 fallback) → Claude Sonnet 4.6 요약
                          → customer_log + 분 단위 차감 + 자동 충전 트리거
api/fcm_helpers.php     — FCM HTTP v1
api/audio_cleanup.php   — 24h audio cron cleanup
api/sms/...             — SMS 발송
api/crypto_helpers.php  — AES-256-GCM (enc:v1: prefix)
api/ledger-mobile.php   — 모바일 앱 Bearer 토큰
api/upload.php          — 파일 업로드
api/billing_helpers.php — PortOne V2 헬퍼 + plan_default_summary_limit_minutes()
                          + overage_top_up_seconds()/amount_won()/per_minute_won()
                          + charge_overage_top_up() — 자동 충전 결제
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
.github/workflows/deploy.yml                  — FTP 배포 + .env 어셈블 (STT_PROVIDER, LLM_PROVIDER 포함)
.github/workflows/audio-cleanup-schedule.yml  — 매일 KST 04:00 audio cleanup
.github/workflows/billing-renew.yml           — 매일 KST 03:00 빌링키 자동 결제 cron
```

## 3. 현재 완성된 기능

- ✅ Supabase Auth (이메일 + Google OAuth, 회원가입/로그인/로그아웃/비번 변경)
- ✅ 인증 일원화 (logout.html / login-complete.html 단일 transition)
- ✅ **인증 race condition fix (2026-05-20)** — `_refreshInflight` 전역 dedup, 임계점 60→300초, 5곳 핸들러 (ensureFreshAccessToken / apiRequest 401 retry / SIGNED_OUT / onAppResume / visibilitychange) 공유
- ✅ **앱팀 5가지 인증 점검 반영 (2026-05-20)** — `storage: localStorage` 명시 / `window.YoungmanBridge.refreshSession()` 글로벌 hook / `window.supabase` 노출 / TOKEN_REFRESHED 시 _bridgeLogin 자동
- ✅ 고아 user 자동 복구 (ensureMemberRowOnce)
- ✅ 로그인 유지 체크박스 (pagehide/beforeunload 자동 sb-* 삭제)
- ✅ 아이디/비밀번호 찾기 (SMS 인증)
- ✅ RN Android 앱 브리지 (bridge.js, isInApp 2단계 신호)
- ✅ 앱 안 Google 로그인 (native SDK + signInWithIdToken + nonce hash)
- ✅ 카카오톡 등 in-app browser Google 로그인 안내
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
- ✅ **헤더 user-menu + 모바일 drawer 에 "구독 관리" 링크 (2026-05-19)**

### ✅ 통화 녹취 → AI 요약 → CRM (Phase 1+2 라이브)

- **STT provider toggle (`STT_PROVIDER` env)**: `clova` (CLOVA Speech LSR, 화자분리, 회당 ~180원) / `whisper` (OpenAI Whisper, ~50원)
  - **3단 fallback (whisper 활성화 시)**:
    1. 사전 (확장자): 화이트리스트(`flac/m4a/mp3/mp4/mpeg/mpga/oga/ogg/wav/webm`) 외 → CLOVA
    2. 사전 (사이즈): Whisper 25MB 제한 초과 → CLOVA (긴 통화 안전)
    3. 런타임 (4xx): Whisper 가 codec 변종으로 거부 → CLOVA 재시도
  - 확장자 판별: `original_filename` 우선 (앱이 Content-Type 항상 'audio/mp4' 하드코딩이라 신뢰 불가)
  - duration: 앱 `duration_sec` 우선 (MediaStore Audio.Media.DURATION), STT response 폴백
- **LLM provider toggle (`LLM_PROVIDER` env)**: `openai` (gpt-4o-mini, 메모 수준, 회당 ~1원) / `anthropic` (Claude Sonnet 4.6 + prompt caching, **보고서 수준**, 회당 ~7~21원)
  - prompt caching: system 메시지 `cache_control: ephemeral` → 5분 TTL hit 시 input 90% 절감
- **사장님 명시 만족 — 2026-05-20**: Whisper + Claude Sonnet 4.6 조합 "성능 진짜 대박" production 확정. **gpt-4o-mini 회귀 비추, Opus 4.7 도 오버킬.**
- **Phase 2 분 단위 과금 (2026-05-20 풀스택 ship)**:
  - members 컬럼 (lazy ALTER): `summary_limit_minutes` / `usage_seconds_period` / `overage_enabled` / `overage_balance_seconds` / `overage_top_up_count` / `overage_last_top_up_at`
  - `plan_default_summary_limit_minutes()`: Free=30 / Plus=300 / Pro=1000 / trialing=30
  - process-recording.php 흐름:
    1. 사전 체크: 한도+잔액 부족 시 자동 충전 트리거 (overage_enabled=1 일 때만)
    2. 미동의 + 한도 초과 → 403 plan_required
    3. 처리 후 차감: usage_seconds_period += duration / 한도 초과분은 overage_balance_seconds 에서 차감
  - 레거시 회 단위 흐름 병행 운영 (분 컬럼 없는 환경 폴백)
- **자동 충전 (`charge_overage_top_up()`)**: ₩5,000 / 71분 (4,286초) = 분당 70원. PortOne billingKey 로 임의 시점 결제. payments 테이블에 PAID row 저장.
- **billing.html**: 사용량 분 단위 표시 + 자동 충전 토글 (PATCH `/records.php?resource=auth-profile` body: `{overage_enabled}`) + 잔액 표시
- **subscribe.html**: 새 가격 features (Free 30분 / Plus 300분 / Pro 1,000분 + 자동 충전 안내)
- **auto-billing.html**: 제6조 자동 충전 약관 (해지 가능 명시)
- **18분 통화 PoC 성공 (2026-05-20)** — Whisper+Claude e2e 흐름 검증됨
- **옵션 D 라운드 4 완성 (기존)**: 8필드 매핑 + phone merge + backfill catch-up + 카드 expanded 보존

### ✅ 구독 결제 시스템 — PortOne V2 + 토스페이먼츠 (2026-05-19 풀스택)

- **요금제 (2026-05-20 분 단위 전환)**: Free(0/30분) / Plus(₩19,000 / 300분) / Pro(₩39,000 / 1,000분)
- **결제 흐름** (2단계 — 토스는 IssueBillingKeyAndPay 미지원):
  1. 클라이언트: PortOne SDK `requestIssueBillingKey({ billingKeyMethod:'CARD', ... })` → 카드 등록 창 → billingKey
  2. 서버: `/billing/verify-payment.php` POST → PortOne API `POST /payments/{id}/billing-key` → status=PAID
- **모바일**: REDIRECTION 모드 자동 적용 (토스 페이지 전체 화면 이동 + 복귀)
- **정기결제 cron**: 매일 KST 03:00 → `/billing/cron-renew.php`. 실패 시 past_due. cancel_at_period_end=1 + 만료 시 free 강등
- **Webhook**: PortOne 콘솔 → `/billing/webhook-portone.php` (Standard Webhooks signature 검증)
- **해지**: cancel_at_period_end=1 + PortOne 빌링키 DELETE. 기간 만료 시 cron 이 free 로 강등
- **summary_limit 자동 동기화**: plan 변경 시 verify-payment / cron-renew / admin PATCH 모두 자동 (회 + 분 둘 다)
- **premium → plus alias**: 'premium' 값을 'plus' 로 자동 마이그레이션 + UI alias
- **admin > 회원 관리**: 회원 표에 플랜/사용량/다음 결제일 컬럼. 편집 모달에 구독 플랜 / 구독 상태 / 사용량 / 만료일 입력 + 빠른 버튼
- **정책 페이지**: refund.html (환불정책) + auto-billing.html (자동결제 + 자동 충전). 사업자정보 (어센트라 / 393-39-01518)

### ✅ 메인 페이지 flagship CTA (2026-05-19)

- "AI 통화 요약 + 고객관리 원터치 전송 서비스 신청" — 매출 최우선 CTA
- 위치: PC = "전화만 하세요" 직후 / 모바일 = 스마트폰 SVG 와 eyebrow 사이 정중앙
- 디자인: 황금색 메탈릭 + shimmer + 흰 텍스트
- plan 별 분기: 비로그인/free/trialing → subscribe / plus/premium → upgrade text / pro → 숨김
- **모바일 nowrap 반응형 fix (2026-05-19)**: clamp() 로 폭에 맞춰 글자/패딩 자동 스케일

## 4. 아직 미완성인 기능

- ⏳ **26분+ 통화 PoC 재시도** — 인증 race condition fix (commit 1aea481) 검증 필요. 사장님 재로그인 후 통화 1건 시도하면 결판.
- ⏳ **admin.html UI follow-up** — 회원 편집 모달에 분 단위 입력 + 수동 충전 부여 UI (백엔드는 records.php 에 이미 ALTER 됨)
- ⏳ **FCM payload 협의** — Phase 2 분 사용량 임박/초과 알림: `usage_warning { type, threshold(80/90/100), used_min, limit_min, period_end }` / `overage_charged { type, amount, added_min, new_balance_min }`. 앱팀 협의 후 영맨 서버에서 발송 구현.
- ⏳ **기존 회원 마이그레이션 검증** — lazy ALTER 의 plan 별 default 가 잘 적용됐는지 production DB 확인 필요
- ⏳ **PortOne 콘솔 Webhook URL 등록** — `https://youngman-biz.com/billing/webhook-portone.php` (사용자 직접)
- ⏳ **정식 토스 키 발급 후 라이브 결제 검증** — 테스트 키 환경에서 `NOT_SUPPORTED_CARD_TYPE` 으로 e2e 결제 검증 미완
- ⏳ **GitHub Actions M4 audio-cleanup workflow_dispatch dry_run** — 사용자 트리거 미완
- ⏳ **AI 요약 두 모드 분기** — 대화형(legacy)/요약정리형(PPT). profile.html 라디오 + members.ai_summary_mode + prompt 분기. PPT prompt 는 `37fca8b` 에 보존
- ⏳ **card-builder UX** — Recraft overlay primary + AI/템플릿 토글
- ⏳ **PII 평문 → 암호문 일괄 backfill 스크립트** (lazy 외 일괄)
- ⏳ **forms 수식 inline help** — 함수/path 카탈로그 모달
- ⏳ **profile/admin 디자인 일관성 감사**
- ⏳ **Supabase Email Template 한글화** (Dashboard 수동)
- ⏳ **Marketing.html 브리지 포함 검토**

## 5. 배포 방식

- **GitHub Actions → FTP** via `SamKirkland/FTP-Deploy-Action`
- 주 워크플로우: `.github/workflows/deploy.yml`
- 보조: `audio-cleanup-schedule.yml` (매일 04:00) / `billing-renew.yml` (매일 03:00)
- **클로드가 직접 push 가능 (2026-05-20 부터)** — 사장님이 fine-grained PAT 발급 → `~/.git-credentials` 저장 완료. credential.helper=store
- 시크릿 (필수):
  - `CAFE24_FTP_PASSWORD` / `YOUNGMAN_CRYPTO_KEY` / `SUPABASE_SERVICE_KEY`
  - `NCP_CLOVA_INVOKE_URL` / `NCP_CLOVA_SECRET`
  - `FIREBASE_SERVICE_ACCOUNT_JSON` / `AUDIO_CLEANUP_TOKEN`
  - `PORTONE_STORE_ID` / `PORTONE_API_SECRET` / `PORTONE_WEBHOOK_SECRET` / `PORTONE_CHANNEL_KEY_TOSS`
  - **`STT_PROVIDER=whisper` / `LLM_PROVIDER=anthropic` / `ANTHROPIC_API_KEY`** (2026-05-19~20 등록)
- "배포/올려" 키워드 → 클로드가 push→trigger→verify 자율 진행 ([[feedback_deploy_autonomy]])
- 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)`
- **신규 페이지 추가 시** deploy.yml 의 `Prepare cp` + `Validate test -f / php -l` 둘 다 추가
- **Secret 변경 시** 빈 commit push 로 재배포

## 6. Cafe24/PHP 관련 주의사항

- 🚫 **SSH/SCP 절대 금지** — silent drop. FTP only.
- 🚫 **cafe24 cron 미지원** — GitHub Actions schedule 로 대체
- 🚫 **cafe24 빈 POST body → 5xx HTML** — multipart/JSON body 1바이트 이상 필수
- 🚫 **cafe24 ffmpeg 미설치** — 통화 녹음 transcode 불가 → Clova / Whisper 가 네이티브 처리
- 🚫 **dhlottery 직접 호출 금지** — cafe24 IP 차단 영구. JSON 미러만
- 📁 **Webroot flat layout** — `api/records.php` → 배포 후 `/records.php`. `__DIR__` 기준
- 📁 **`api/sms/` → `deploy/sms/providers/` cp**
- 📁 **`api/billing/` → `deploy/billing/` cp** (mkdir -p)
- 🔐 **YOUNGMAN_CRYPTO_KEY 분실 = 복호화 영구 불가** — GitHub Secret 백업 필수
- 🔐 **PORTONE_API_SECRET / WEBHOOK_SECRET / ANTHROPIC_API_KEY** — 채팅 노출 금지
- 🔐 **NCP_CLOVA_SECRET / FIREBASE_SERVICE_ACCOUNT_JSON / AUDIO_CLEANUP_TOKEN** — 채팅 노출 금지
- 📡 **deploy/.env 매번 어셈블** — FTP 로 직접 넣은 키는 다음 deploy 에 덮어쓰임
- 📡 **PHP 가 .env 자동 로드 안 함** — `billing_load_env_value()` 또는 `load_env_value()` 로 파일 직접 파싱
- 📡 **VITE_SUPABASE_URL** 가 `https://xxx.supabase.co/rest/v1/` 형태 → 정규식으로 root 추출 후 `/auth/v1/user` 호출
- 📡 **db_config.php** — `return [host=>..., port=>..., database=>..., user=>..., password=>...]` array 반환 패턴
- 📡 **records.php `/auth/v1/user` 폴백 유지**
- 📡 **PHP timeout 30초** — send-bulk.php `set_time_limit(120)`, process-recording.php `set_time_limit(240)`
- 📊 **PII 컬럼 폭** — 암호문 100~200 chars. 새 PII 컬럼은 최소 VARCHAR(255)
- 📊 **OpenAI Whisper API 25MB 제한** — 사전 체크로 CLOVA fallback
- 📊 **Whisper 미지원 ext**: aac, opus, 3gp, 3gpp, amr — 화이트리스트로 자동 fallback

## 7. 최근 수정한 파일 (커밋 흐름)

```
1aea481 feat(billing+auth): Phase 2 분 단위 과금 + 자동 충전 + 앱팀 5가지 인증 점검 반영
6ad8169 fix(auth): refresh_token rotation race condition + 임계점 강화
28db191 fix(stt): Whisper 25MB 파일 사이즈 사전 fallback (긴 통화 대응)
ac7de25 fix(stt): Whisper 4xx 런타임 fallback (m4a 변종 codec 거부 케이스)
daa8f29 fix(stt): Whisper 지원 포맷 화이트리스트 fallback (aac/opus 등)
e5b9276 feat(stt): 앱팀 회신 반영 — 자동 fallback + duration_sec + original_filename
a890d00 chore: STT_PROVIDER=whisper + LLM_PROVIDER=anthropic 활성화 재배포
de27a7b feat(stt/llm): Whisper+Claude toggle + 분 기반 사용량 누적 (Phase 1)
8105820 ui(header): 구독 관리 링크 추가 — PC user-menu + 모바일 drawer
4b4726f ui(index): 모바일 flagship CTA — 좁은 폭에서 nowrap + clamp 스케일
2cce2d4 docs(PROJECT_CONTEXT): 2026-05-19 갱신
878c04e fix(subscribe): 모바일 결제창 — REDIRECTION 모드 + 복귀 흐름
e68a38f fix(index): Plus 사용자 클릭 시 결제 페이지로 (billing.html → subscribe.html)
```

**미커밋:** `SMS_USER_GUIDE.txt` (untracked, 무시 가능)

## 8. 절대 건드리면 안 되는 부분

- 🔒 **PII owner_email 격리** — 모든 SELECT/UPDATE/DELETE 강제. admin 우회 없음 (단 `is_admin_email_for_recording` quota 우회는 예외)
- 🔒 **`git add -A` 금지** — PII 새어나갈 위험. 명시 add 만
- 🔒 **YOUNGMAN 브랜드** — logo_main.png + seal-red(#c8362c)
- 🔒 **SSH/SCP 배포 시도 금지**
- 🔒 **서버 설정 파일 repo 커밋 금지** — supabase_config.js/php, db_config.php, .env
- 🔒 **records.php `/auth/v1/user` 폴백 유지**
- 🔒 **dhlottery 직접 호출 부활 금지**
- 🔒 **인증 일원화 구조** — logout.html / login-complete.html 단일
- 🔒 **`_refreshInflight` 전역 dedup** — 5곳 핸들러 공유, race condition 방지. 임계점 300초 (5분).
- 🔒 **`window.YoungmanBridge.refreshSession()` + `window.supabase` 글로벌 노출** — RN 자동 복구 경로
- 🔒 **`storage: window.localStorage` 명시** — WebView default 누락 방지
- 🔒 **TOKEN_REFRESHED 시 `_bridgeLogin` 자동 호출** — RN 토큰 동기화
- 🔒 **ledger UX 패턴** — 헤더 클릭 필터 / 행 추가 모달 / accordion 그룹
- 🔒 **카드 expanded 상태 보존** — _expandedRowIds + MutationObserver
- 🔒 **`<a href="logout.html">` native navigation**
- 🔒 **module top-level `return` 금지**
- 🔒 **ensureMemberRowOnce / PII 컬럼 폭 자동 확장**
- 🔒 **OAuth click handler 동기 흐름** — `await initSupabase` 추가 금지
- 🔒 **prompt: 'select_account' / signOut scope: 'global'**
- 🔒 **SMS 회원별 자격증명 / sms_logs 원문 저장 금지**
- 🔒 **검색 input 재생성 금지** — filterDOMRowsBySearch hide/show 만
- 🔒 **apiRequest 토큰 refresh + 401 retry (dedup 거침)**
- 🔒 **mountAppHeader currentSession 즉시 도출**
- 🔒 **bridge.js 메시지 타입 / window.YoungmanBridge 전역 이름 / isInApp 2단계 신호 (ReactNativeWebView + UA 'YoungmanApp')**
- 🔒 **Google 로그인 signInWithIdToken 직접 / nonce raw 웹 / hash 앱 전달 / in-app browser 분기**
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
- 🔒 **STT 3단 fallback 순서** — (1) ext 화이트리스트 (2) 25MB 사이즈 (3) 런타임 4xx — 모두 NCP 설정 있을 때만 동작
- 🔒 **Whisper params** — `model=whisper-1`, `language=ko`, `response_format=verbose_json`, `temperature=0`, `prompt`=한국어 영업 컨텍스트
- 🔒 **Claude API 호출 시 prompt caching** — system 메시지 `cache_control: ephemeral`
- 🔒 **`original_filename` 확장자 판별 우선** (Content-Type 헤더는 항상 audio/mp4 하드코딩)
- 🔒 **`duration_sec` 앱 값 우선** (Whisper/CLOVA response 의 duration 은 폴백)
- 🔒 **Clova Speech params** (STT_PROVIDER=clova 시) — `language=ko-KR`, `completion=sync`, `fullText=true`, `diarization` 2명
- 🔒 **ai_model 컬럼 동적** — `{sttModelName}+{llmModel}`
- 🔒 **billing_pdo()** — db_config.php candidate 4단계 검색 + billing_ensure_tables() 자동 호출
- 🔒 **portone_extract_status / portone_extract_amount** — schema 변동 안전망 (4 nested 위치 탐색)
- 🔒 **billing_require_bearer_email()** — Supabase URL 정규화 + auth_status 진단 응답
- 🔒 **subscribe.html PortOne 호출** — 토스 미지원이라 `requestIssueBillingKey` + 모바일 `windowType.mobile: 'REDIRECTION'` + `redirectUrl`
- 🔒 **handleBillingReturn()** — `?billing_return=1` 감지 + localStorage 의 pending plan/issueId 복원
- 🔒 **plan_default_summary_limit_minutes()** — Free=30 / Plus=300 / Pro=1000 / trialing=30
- 🔒 **overage_top_up_seconds=4286 / overage_top_up_amount_won=5000 / overage_per_minute_won=70** — 자동 충전 단가 (Phase 2 결정)
- 🔒 **charge_overage_top_up()** — sanity check (overage_enabled=1 + billingKey 존재 + plan_status active/trialing) 우회 금지
- 🔒 **process-recording.php Phase 2 흐름** — 사전 한도+잔액 체크 → 부족 시 overage_enabled=1 일 때만 자동 충전 / 후 차감 시 GREATEST(0, balance - delta) 음수 방지
- 🔒 **premium → plus 자동 마이그레이션** — `ensure_members_plan_columns` 끝 UPDATE 한 줄
- 🔒 **flagship CTA plan 분기** — pro 면 display:none

## 9. 다음에 이어서 해야 할 작업

### 1순위 — Phase 2 검증
1. **사장님 26분+ 통화 PoC** (재로그인 후) — 인증 race condition fix 가 진짜 효과 있는지 검증. 결과 분기:
   - ✅ 성공 → Phase 2 정상 작동. FCM payload 협의 시작.
   - ❌ 인증 에러 재발 → 시나리오 C (네트워크) 또는 Supabase 측 문제. Console 설정 점검 (이미 OK 확인됨 — 10초 reuse interval / never timeout).
2. **분 단위 차감 정확도 검증** — 통화 후 DB 의 `usage_seconds_period` 가 실제 통화 길이와 일치하는지
3. **자동 충전 흐름 시뮬레이션** — admin 으로 사장님 계정 `summary_limit_minutes=1` 설정 후 짧은 통화 → 자동 충전 트리거 확인

### 2순위 — UI / 협의
4. **admin.html UI follow-up** — 회원 편집 모달에 분 단위 입력 + 수동 충전 부여 (백엔드는 records.php 에 이미 ALTER 됨)
5. **FCM payload 협의** — `usage_warning` / `overage_charged` payload 스펙 앱팀 협의 후 영맨 서버 발송 코드 추가

### 기존 backlog
6. PortOne 콘솔 Webhook URL 등록 (`https://youngman-biz.com/billing/webhook-portone.php`)
7. 정식 토스 키 도착 시 라이브 결제 검증
8. **AI 요약 두 모드 분기** — [[project_ai_summary_modes]]. profile.html 라디오 + members.ai_summary_mode + prompt 분기. PPT prompt 는 `37fca8b` 에 보존
9. card-builder UX (Recraft overlay + AI/템플릿 토글)
10. PII 평문 → 암호문 backfill 스크립트
11. forms 수식 inline help
12. profile/admin 디자인 일관성 감사
13. Supabase Email Template 한글화
14. 로또 자동 갱신 (JSON 미러 cron)
15. Marketing.html 브리지 포함 검토

---

## 자가 진단 채널 (디버깅용)

- `sessionStorage.erp.ensureError` : members 보강 실패 시 JSON
- `sessionStorage.erp.memberEnsured = '1'` : 보강 성공
- `sessionStorage.erp.endSessionOnClose = '1'` : 로그인 유지 해제
- 콘솔 prefix: `[auth submit]` / `[signIn]` / `[google oauth]` / `[ensure member auto]` / `[sms balance]` / `[bridge]` / `[process-recording]` / `[fcm]` / `[records]` / `[subscribe]` / `[billing/verify-payment]` / `[webhook-portone]` / `[cron-renew]` / `[charge_overage_top_up]`
- 브리지 디버깅: `window.YoungmanBridge.isInApp()` / `.getAppInfo()` / `.getFcmToken()` / `.version` / `.refreshSession()`
- 결제 진단: `/billing/config.php` GET → 200 + storeId 응답이면 .env 정상. `verify-payment` 응답의 `debug` 객체로 단계별 실패 위치 파악.

## 환경 한계 (이 클로드 워크스페이스)

- **GitHub push 자율 가능 (2026-05-20 부터)** — fine-grained PAT 가 `~/.git-credentials` 에 저장됨. 90일 만료 (Aug 17 2026). 만료 시 사장님이 재발급 + 다시 입력 필요.
- 토큰 권한: nxnxax/product-builder-jd repo 의 Contents Read/Write + Workflows Read/Write + Metadata Read
- push 후 GitHub Actions deploy.yml 이 FTP 업로드까지 자동 — curl 검증도 가능
- 사용자 호칭: **사장님** (1인 사업자, 비개발자 친화 톤)

## 메모리 참조 (~/.claude/projects/-home-user-jdhoon/memory/)

- `feedback_auth_flow_lessons.md` — 인증 root cause + 단일 페이지 일원화
- `feedback_css_edit_sanity.md` — 큰 Edit 후 brace balance 검증
- `feedback_deploy_autonomy.md` — "배포/올려" 키워드 → push 까지 자율 (이제 클로드가 직접 push 가능)
- `feedback_no_proceed_prompts.md` — "Do you want to proceed?" 묻지 말 것
- `feedback_pii_isolation.md` — PII owner_email 강제, git add -A 금지
- `feedback_readability_first.md` — 60대+ 가독성 우선
- `feedback_ledger_ux.md` — 헤더 클릭 필터 / 행 추가 모달 / accordion
- `feedback_paste_formatting.md` — 외부 채팅 붙여넣기 메시지 코드블록 wrap
- `pending_call_recording_status.md` — call-recording 라운드 4 인계
- `project_app_bridge.md` — RN Android WebView 앱 연동
- `project_pii_crypto.md` — AES-256-GCM 라이브
- `project_ledger_system.md` — page_type 기반 그룹/레코드
- `project_youngman_redesign.md` — 브랜드 리디자인
- `project_nav_slots.md` — slot1/slot2 caret hover dropdown
- `project_mobile_bottom_nav.md` — 4탭 하단 nav
- `project_ai_summary_modes.md` — 대화형/PPT 두 모드 분기 예정
- `project_whisper_claude_quality.md` — **2026-05-20 사장님 "성능 진짜 대박" 명시. Whisper+Sonnet 4.6 production 확정. gpt-4o-mini 회귀 비추, Opus 4.7 도 오버킬**
- `deploy_cafe24.md` — FTP only, webroot flat layout
