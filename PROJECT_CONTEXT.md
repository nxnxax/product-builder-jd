# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-25 세션 종료 — **회원가입 휴대폰 SMS 인증 + 5회 무료체험 폐지 + 환영 모달 + v60/v73 client 대응 server 작업** 완료. 사장님 보고한 무한로딩 / 양식 전송 실패는 **server 측 정상 (SQL 진단 완료) — root cause = 옛 native v49 가 새 server 흐름 미대응**. 앱팀 fix 대기.*

---

## 1. 사이트 목적

**YOUNGMAN / 영맨** — 1인 사업자용 AI 영업 플랫폼.
- 슬로건: "단 한 건의 고객정보 누락 없이 관리"
- CRM(고객) / HRM(조직도·계약자) / 마케팅 / 로또 / 단체 SMS / **AI 통화 요약 → 미확인 요약 → 고객관리대장 전송 (자동 지역 인식)**
- 디자인: 한국 캘리그라피 + 인장(seal-red `#c8362c`), Apple/Linear 미니멀
- 라이브: https://youngman-biz.com (Cafe24 + Supabase + MariaDB + PHP)
- 결제: PortOne V2 + 토스페이먼츠 정기결제
- 앱: RN Android WebView + bridge.js (v49 active. v60/v73 빌드 대기)
- 고객층: 보험/자동차/중고차/일반 자영업 다양 — AI 업종 무관 범용

---

## 2. 주요 파일 구조

```
[프론트]
index.html / login-complete.html / logout.html / profile.html / admin.html
org.html / contracts.html / customers.html / forms.html / board.html
card-builder.html / lotto2233.html / Marketing.html / kapp_premium.php
terms.html / privacy.html / refund.html / auto-billing.html
subscribe.html / billing.html / tester.html
unreviewed.html  ← lazy-STT 카드 UI

[공통 JS]
auth-shared.js   — Supabase + 헤더/footer/bottom-nav + 인증 + placeholder masker
                   + 회원가입 모달에 휴대폰 OTP 인증 UI (2026-05-25)
                   + 환영 모달 (openWelcomeModal + DOMContentLoaded 자동 트리거)
bridge.js        — RN WebView 브리지 (heartbeat 30s)
ledger-shared.js — 관리대장 공통
customers.js     — 고객관리대장. region + placeholder + 5초 polling
admin.js         — 관리자 페이지 (trialing 폐지 매핑 적용)

[PHP API — cafe24 webroot flat]
api/records.php            — CRUD + customer_log_send_to_group + transcripts_by_phone
                             + trigger_summarize (placeholder INSERT + ledger mirror)
                             + signup-send-otp / signup-verify-otp (2026-05-25 신규)
                             + auth-profile (requires_subscription flag, 2026-05-25)
                             + create_member_from_google (finalize 분기, 2026-05-25)
api/process-recording.php  — 통화 audio. lazy-STT (status='audio_pending')
                             + client_request_id 64자 초과 시 SHA-256 hash (2026-05-25)
                             + 옵션 A: 무료 사용자 audio drop + row skip (2026-05-25)
                             + customer_name phone_number lookup (v73+ 대응, 2026-05-25)
                             + build_plan_info_for_response helper (case-insensitive email)
api/recording-callback.php — Railway worker 결과 수신
api/cron-process-jobs.php  — 5분 cron
api/billing_helpers.php    — trialing 폐지 마이그레이션 (UPDATE plan='free' WHERE plan='trialing')
api/billing/cancel-subscription.php — default plan_status 'trialing' → 'active'

[Railway worker]
worker/main.py — Whisper + Claude Sonnet 4.6 + region 추출

[Asset]
og-thumbnail.png / logo_main.png

[베타 APK 호스팅]
tester.html → /download/youngman-latest.apk (사장님 FTP 직접 업로드)
```

---

## 3. 현재 완성된 기능

### 인증 (2026-05-25 대규모 강화)
- Supabase + Google OAuth + 6중 race guard + bridge.js heartbeat
- 7단계 auth header fallback (`/auth/v1/user` 폴백 — sb_publishable_)
- **휴대폰 SMS 인증 시스템 (신규)**:
  - 일반(이메일) 회원가입 시 휴대폰 인증 강제
  - Google 가입 보충 폼에도 동일 패턴
  - signup-send-otp / signup-verify-otp endpoint (auth_otp 테이블 재사용)
  - 6자리 OTP 5분 유효 + 1분 cooldown + 5회 시도 제한
  - 검증 성공 시 'signup_verified' 토큰 (48 hex, 10분 유효, 1회 사용)
  - records.php `create_member_from_google` 가 phone_verification_token 강제 검증
  - SMS 발송 = 사장님 admin sms_credentials 재사용 (Solapi)
  - auth_otp.code 컬럼 VARCHAR(8) → VARCHAR(64) lazy ALTER
- **Google 가입 보충 폼 전면 개편 (login-complete.html)**:
  - 자동 ensure POST 가 신규 가입자에게는 pending_signup 응답만 (INSERT 안 함)
  - "가입 완료" 버튼 누를 때만 finalize=true → INSERT
  - form 순서: 이름 / 휴대폰(인증) / 닉네임 / 약관
  - 버튼 줄바꿈 layout (column 방향) — input + button 폭 초과 회피
- **이름 / 닉네임 분리 저장** (members.name + members.nickname 별도)
- client_request_id 64자 초과 시 SHA-256 hash 자동 대체 (ARS 한글 파일명 대비)

### 5회 무료체험(trialing) 시스템 완전 폐지 (2026-05-25)
- 신규 가입자 default plan='free' (옛 'trialing' 폐지)
- 옛 trialing 가입자 → 'free' 자동 마이그레이션 (lazy UPDATE)
- 프론트: subscribe.html banner 삭제, billing.html / admin.html / admin.js label 정리
- 백엔드: plan_default_summary_limit.trialing → 0, ALTER plan DEFAULT 'free', plan_status DEFAULT 'active'
- 코드의 'trialing' 분기는 옛 row 호환 위해 free 와 동일 매핑으로 유지

### 환영 모달 (회원가입 후 첫 로그인 1회만)
- auth-shared.js `openWelcomeModal()` + `maybeShowWelcomeModal()`
- localStorage 'yman_pending_welcome' 기반 트리거 (서버 commit race 회피)
- 회원가입 완료 시 (signUp + ensure 성공 / Google finalize 성공) 직후 setItem
- DOMContentLoaded 자동 호출 → bootApp 안 쓰는 페이지 (index.html 등) 도 자동 표시
- transition 페이지 (login-complete / logout) 는 skip
- 모달 표시 직전 즉시 localStorage.removeItem → "한 번만" 강력 보장
- 본문:
  - "영맨 가입을 축하드립니다!"
  - "플랜을 업그레이드하여 AI 통화 요약 서비스를 시작하세요!"
  - "CRM 양식 등 기타 사이트 기능은 무료입니다."
  - "AI 통화 요약 플랜을 이용하시면 더욱 완성된 서비스를 누리실 수 있습니다."
- 버튼: "요금제 보기" → subscribe.html

### v60 client 명세 대응 — server 옵션 A (2026-05-25)
앱팀 v60 client 명세에 따라 server 측 작업:
- **process-recording.php 응답 plan 객체에 requires_subscription flag**
  - plan='free' && !isAdminUser → true
  - plan='plus'/'pro' or admin → false (절대 true 안 됨)
- **옵션 A**: requires_subscription=true 면 audio drop + recording_jobs INSERT skip
  - storage_path unlink (best-effort, 절대/상대 경로 모두 시도)
  - 응답: `{ status:'ok', job_id:null, job_status:'subscription_required', plan:{...} }`
  - 사장님 정책 (무료 사용자 미확인 요약 노출 X) 충족
- **auth-profile GET 응답에 requires_subscription flag** (client cache refresh 용)
- build_plan_info_for_response 의 SELECT 가 LOWER(email) match (옛 mixed-case 호환)
- 진단 log 추가 (member row not found 시 / SELECT 실패 시)

### v73 client 대응 — customer_name phone_number lookup (2026-05-25)
앱팀 v73 client (Play Store 정책 — READ_CONTACTS 권한 제거) 대응:
- process-recording.php 에서 customer_name_hint='' 이면 phone_number 로 customer_log lookup
- 우선순위: body.customer_name_hint (legacy) → customer_log 옛 customer_name → null (AI 자유 추출)
- placeholder 값 ('고객', '(처리 중)', '(처리중)') skip 후 다음 row (LIMIT 5)
- customer_phone_lookup_key (HMAC-SHA256) 사용 — client phone format 무관 normalize

### CRM / HRM
- 조직도/계약자/고객 관리대장 + AES-256-GCM 암호화
- 양식 빌더 (Phase 1~3, 8타입)
- 회차별 content 분할 + 회차 ↔ transcript 자물쇠 결합 (round_log_ids)
- 단체 SMS + 잔액 카드
- 고객 거주지 자동 인식 (Claude transcript 분석)

### 통화 녹취 — lazy-STT 모드
- 통화 종료 → process-recording (audio_pending INSERT, lazy)
- "요약보기" (auto_confirm=0) → trigger_summarize → Railway → callback → ready_to_review
- "양식으로 전송" (auto_confirm=1) → 즉시 placeholder customer_log + ledger mirror
- 안전망: STT partial fail → ready_to_review fallback, COALESCE NULLIF 보호, phone_lookup HMAC 통일

### 미확인 요약 UI (unreviewed.html)
- 카드 layout (좌측 info / 우측 버튼 2개 stack)
- 전화번호 / 통화시간 줄바꿈 분리
- "✓ 요약완료" 버튼 2줄
- 5초 polling (queued/processing 카드 있을 때만)
- 낙관적 UI (confirm/discard 즉시 DOM 제거)
- 체크박스 + 전체선택 + 인라인 일괄 동작
- 날짜 구분선 (오늘/어제/N월 N일)

### 결제 / 기타
- PortOne V2 + 토스 정기결제
- plan_default_summary_limit_minutes — Free=30/Plus=300/Pro=1000
- overage_top_up — 5000원/71분/70원per분
- 사용량 차감: usage_seconds_period 초 단위 누적 (정확)

---

## 4. 아직 미완성 (다음 세션 작업)

### ⏳ 1순위 — 앱팀 v60/v73 빌드 대기 (사장님 → 앱팀 의뢰 완료)
사장님이 앱팀에 명세 전달 완료. 앱팀 빌드 받으면 사장님 + 무료 테스트 계정으로 회귀 테스트 후 production.

**v60 client 명세 (앱팀 작업)**:
1. process-recording 응답의 `plan.requires_subscription === true` 보면 첫 모달 skip + "Plus 구독부터" 모달
2. `false` 또는 undefined → 기존 첫 모달 (안전망)
3. PlanCache stale 시 응답으로 정정 (swap 없음)
4. 무료 verdict 시 trigger_summarize 호출 X + outbox dismissed 마감

**v73 client 명세 (앱팀 작업)**:
1. Play Store 정책 — READ_CONTACTS / SYSTEM_ALERT_WINDOW / CAMERA / READ_MEDIA_* 권한 제거
2. customer_name_hint 항상 null 전송 (server 가 phone lookup 으로 옛 이름 복원)

**현재 사장님 client (옛 native v49) 가 처리 미흡한 부분**:
1. **양식 전송 실패** — 앱이 trigger_summarize(auto_confirm=1) 호출 자체를 안 함 → server recording_jobs 에 row 0건
2. **요약보기 무한로딩** — 앱이 호출은 했고 server 정상 완료 (status='saved'). callback 후 FCM 알림 못 받음 또는 polling 안 함
3. **1분 강제 메인 이동** — 웹 측에 1분 timer 없음. RN WebView 의 inactivity / heartbeat 못 받을 시 reload 의심
4. **옵션 A response (job_id=null) 처리 미흡** — v60 명세대로 빌드 받아야

### ⏳ 2순위 — 사장님 정책 검토 (앱팀 빌드 받기 전 임시 결정 필요)
- **옵션 A 유지 vs 일시 비활성화**:
  - 유지 (현재) — 무료 사용자 audio drop + 미확인 요약 노출 X. 단 옛 native UX 일부 깨짐.
  - 비활성화 — 무료 사용자 audio_pending row 노출 (사장님 정책 위반). 옛 native UX 정상.
  - 사장님은 유지 + v60 빌드 대기 선택 (default).

### ⚠️ 보안 마무리 (사장님 작업)
1. RECORDING_WORKER_TOKEN rotate (3곳 동기화 — Railway + cafe24 .env + GitHub Secrets, **따옴표 없이**)
2. cafe24 webroot 의 admin_env_diag.php FTP 직접 삭제

### 기존 backlog (낮은 우선순위)
- AI 요약 두 모드 분기 (대화형 vs 보고서식)
- PortOne Webhook URL 등록 + 정식 토스 키 발급 후 라이브 결제 검증
- card-builder UX / forms 수식 inline help / profile/admin 디자인 일관성
- records.php dead code cleanup (700줄 — Phase 9)
- Lottie 비서 애니메이션 (사장님이 lottiefiles.com 에서 선택 후)
- 옛 통화 region backfill (사장님 결정 필요)

---

## 5. 배포 방식

- GitHub Actions → FTP (cafe24, SamKirkland/FTP-Deploy-Action)
- 클로드 직접 push 가능 (PAT `~/.git-credentials`, 90일 만료 Aug 17 2026)
- Railway: GitHub 연동, `worker/` 폴더 root, main push 자동 재배포
- 시크릿 (필수):
  `CAFE24_FTP_PASSWORD` / `YOUNGMAN_CRYPTO_KEY` / `SUPABASE_SERVICE_KEY`
  `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` / `NCP_CLOVA_*`
  `STT_PROVIDER=whisper` / `LLM_PROVIDER=anthropic`
  `RAILWAY_WORKER_URL` / `RECORDING_WORKER_TOKEN` ★ 따옴표 없이
  `PORTONE_*` / `FIREBASE_SERVICE_ACCOUNT_JSON` / `AUDIO_CLEANUP_TOKEN`
- "배포/올림" 키워드 → 자율 push→trigger→verify
- 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)`
- 새 페이지 추가 → `deploy.yml` 의 `test -f` + `cp` 둘 다

### APK 호스팅
- 사장님 FileZilla FTP 로 직접 업로드: `/download/youngman-latest.apk`
- 앱팀 새 빌드 시 같은 경로 덮어쓰기

---

## 6. Cafe24/PHP 관련 주의사항

- 🚫 **SSH/SCP 절대 금지** — silent drop. FTP only.
- 🚫 **cafe24 cron 미지원** — GitHub Actions schedule.
- 🚫 **cafe24 빈 POST body → 5xx HTML** — 1바이트 이상 필수.
- 🚫 **cafe24 ffmpeg 미설치** — m4a transcode 는 Railway worker 강제.
- 🚫 **cafe24 PHP-FPM opcache** — modified time 무시 가능. `opcache_invalidate()` 필요할 때 있음.
- 🚫 **cafe24 .env 파일 lock** — FileZilla 열어둔 상태로 업로드 시 PHP 가 새 내용 못 읽음.
- 🚫 **cafe24 .env 는 매 deploy 마다 GitHub Secrets 로부터 재생성됨** — FTP 직접 수정값은 다음 push 시 덮어쓰여짐.
- 🚫 **dhlottery 직접 호출 금지** (IP 차단).
- 🚫 **`git add -A` 금지** — PII 누설 위험.
- 🔑 **.env 값에 따옴표 절대 금지** — 일부 PHP 함수가 strip 안 함. 모든 .env parsing 은 `trim($v, "\"' \t\r\n")` 사용.
- 🔑 **phone_lookup 함수 통일 필수** — `customer_phone_lookup_key` (HMAC-SHA256). callback + records.php + process-recording 모두 동일.
- 📁 Webroot flat layout. `api/sms/` → `deploy/sms/providers/` / `api/billing/` → `deploy/billing/`
- 🔐 `YOUNGMAN_CRYPTO_KEY` 분실 = 복호화 영구 불가
- 📡 PHP 30초 timeout → process-recording `set_time_limit(300)` + Railway 위임
- 📡 records.php `/auth/v1/user` 폴백 — sb_publishable_ asymmetric JWT
- 📡 db_config.php — `return [host, port, database, user, password]`
- 📊 PII 컬럼 폭 — 암호문 100~200 chars, VARCHAR(255)+
- 📊 Whisper 25MB 제한 + iPhone/Galaxy m4a codec 변종 거부 → **mp3 통일 변환**
- 📊 Authorization 헤더 fallback 7단계 (records.php read_authorization_header)
- 🔑 **client_request_id 64자 초과 시 SHA-256 hash 자동 대체** (2026-05-25) — ARS 통화 한글 파일명 호환.
- 🔑 **case-insensitive email match** (2026-05-25) — `WHERE LOWER(email) = LOWER(:e)`. 옛 mixed-case 가입자 호환.
- 🔑 **auth_otp.code VARCHAR(64)** (2026-05-25) — 48 hex token 저장 위해 lazy ALTER.
- 🔑 **Cache-bust 필수** — JS module 변경 시 HTML import querystring 도 같은 commit 에서 갱신. 옛 캐시 = 새 코드 효과 0.

### Railway worker quirks
- 🚫 `railway.json` 의 `startCommand` 가 Dockerfile 모드에서 shell expansion 안 됨 — Dockerfile CMD `sh -c` wrap.
- 🚫 Failed deployment 가 누적되어도 옛 Active 가 traffic 받음 — dashboard 확인 습관.

---

## 7. 최근 수정한 파일 (2026-05-25 세션)

```
# 2026-05-25 세션 — 회원가입 휴대폰 인증 + 5회무료 폐지 + 환영모달 + v60/v73 대응
db8259e feat(call): customer_name phone_number 기반 server lookup (v73+ 대응) ★ 신규
109fdab fix(plan): build_plan_info case-insensitive email match + 진단 log
22ed93f feat(plan): v60 client 옵션 A — 무료 사용자 audio drop + auth-profile requires_subscription ★ 큰 변경
a6166a9 feat(plan): process-recording 응답에 requires_subscription flag 추가
1498072 fix(welcome): 환영 모달 1분 후 재표시 버그 — 한 번만 강력 보장
724abe9 fix(welcome): bootApp 안 쓰는 페이지에서도 환영 모달 자동 표시
ca91131 fix(welcome): 환영 모달 트리거 localStorage 기반으로 변경
ef668d9 fix(signup): finalize 분기를 Google 가입자에게만 적용 ★ 핵심 FIX
e3949d9 feat(plan): 5회 무료체험(trialing) 시스템 폐지 + 첫 로그인 환영 모달 ★ 큰 변경
027ab9d fix(signup): Google 가입 모달 — input+button 폭 초과 / 버튼 줄바꿈
1f20f40 fix(signup): Google 가입 모달 layout — label/input 한 줄 + 가운데 정렬
2944583 feat(auth): Google 가입 흐름에 이름 + 휴대폰 인증 + 가입 완료 버튼 추가 ★ 신규
958503d fix(auth): auth_otp.code 컬럼 VARCHAR(8) → VARCHAR(64) lazy 확장
bf889ee fix(auth): signup OTP endpoint anonymous 허용 추가
7605d65 feat(auth): 일반(이메일) 회원가입에 휴대폰 SMS 인증 추가 ★ 신규
3688f98 fix(call): client_request_id 64자 초과 시 SHA-256 hash 자동 대체
```

---

## 8. 절대 건드리면 안 되는 부분

### 인증
- 🔒 6중 race guard 풀스택
- 🔒 7단계 auth header fallback
- 🔒 `window.supabase` 글로벌 + `_runRefreshOnce` cooldown 25s + timeout 12s
- 🔒 records.php `/auth/v1/user` 폴백
- 🔒 records.php worker token 우회 분기 (X-Worker-Token + body.owner_email)
- 🔒 PII owner_email 격리
- 🔒 admin_email_allowlist = `['nxnxax@gmail.com']` — 사장님 실제 admin 계정 (members 의 nxnxqx 와 다름; nxnxqx 는 옛 시점에 가입된 사용자)

### 회원가입 휴대폰 인증 (2026-05-25)
- 🔒 records.php signup-send-otp / signup-verify-otp endpoint (auth_otp 재사용)
- 🔒 publicResources allowlist 에 signup-* 포함 (anonymous 호출 가능)
- 🔒 auth_otp.code VARCHAR(64) — 48 hex token 저장
- 🔒 send_otp_sms_via_admin → purpose='signup' 메시지 "[영맨] 회원가입 인증번호"
- 🔒 create_member_from_google 의 token 검증 분기 — provider='email' 또는 finalize=true 시 강제
- 🔒 auth-shared.js 모달 + login-complete.html form 의 OTP UI + verificationToken state

### Google 가입 finalize 흐름 (2026-05-25)
- 🔒 create_member_from_google 의 finalize 분기 — provider='google' && !finalize 일 때만 pending_signup
- 🔒 일반(email) 가입은 옛 흐름 그대로 (finalize 없이도 INSERT)
- 🔒 login-complete.html 의 1차 ensure POST 에 provider: isGoogle ? 'google' : 'email' 명시
- 🔒 finalize=true 호출만 member row INSERT (Google 사용자는 "가입 완료" 버튼 누르기 전 INSERT X)

### 5회 무료체험(trialing) 폐지 (2026-05-25)
- 🔒 신규 가입자 default plan='free', plan_status='active'
- 🔒 옛 trialing 가입자 자동 마이그레이션 (billing_helpers.php / process-recording.php 양쪽)
- 🔒 코드의 'trialing' 분기는 옛 row 호환 위해 free 와 동일 매핑 (삭제 X)

### 환영 모달
- 🔒 openWelcomeModal — markWelcomed 즉시 localStorage.removeItem + 비동기 updateUser
- 🔒 maybeShowWelcomeModal — localStorage 만 트리거 (user_metadata fallback 제거)
- 🔒 표시 직전 즉시 localStorage.removeItem ("한 번만" 강력 보장)
- 🔒 DOMContentLoaded 자동 호출 — transition 페이지 (login-complete / logout) skip
- 🔒 signUp + login-complete finalize 시 localStorage.setItem 'yman_pending_welcome'='1'

### v60 client 옵션 A (2026-05-25)
- 🔒 process-recording.php — requires_subscription=true 면 audio drop + INSERT skip + 응답만
- 🔒 build_plan_info_for_response — LOWER(email) match + 진단 log
- 🔒 응답 plan 객체 매 호출 DB SELECT (cache stale 정정 보장)
- 🔒 auth-profile GET 에도 requires_subscription flag

### v73 client customer_name lookup (2026-05-25)
- 🔒 process-recording.php customer_name_hint='' 이면 customer_log phone lookup (HMAC)
- 🔒 placeholder 값 ('고객', '(처리 중)', '(처리중)') skip
- 🔒 customer_phone_lookup_key — \D 제거 + HMAC-SHA256 (client format 무관)

### 회차 ↔ transcript 자물쇠 (2026-05-24)
- 🔒 records.php send_to_group 3분기 모두 `data_json.round_log_ids[회차]=cid`
- 🔒 get_transcript_by_id endpoint
- 🔒 customers.js 회차 카드 `data-customer-log-id` attribute
- 🔒 `_findTranscriptByTimestamp` — 1분 cap 제거, best row (옛 데이터 호환)

### lazy-STT 모드 (2026-05-23~24)
- 🔒 process-recording.php — status='audio_pending', placeholder/mirror/dispatch 안 함
- 🔒 trigger_summarize — auto_confirm 분기 + placeholder INSERT (auto_confirm=1) + ledger mirror
- 🔒 recording-callback.php §7 분기 (customer_log_id 있음) UPDATE + ledger refresh
- 🔒 callback UPDATE COALESCE NULLIF 보호 (region 포함)
- 🔒 cron-process-jobs.php — audio_pending 자동 처리 제외 (lazy)
- 🔒 audio_cleanup.php — audio_pending / failed_retryable storage_path 영구 보존
- 🔒 list_unreviewed query — customer_log_id IS NULL + status IN (...)

### 미확인 요약 UI (unreviewed.html)
- 🔒 카드 layout / 줄바꿈 / 2줄 버튼 / 5초 polling / 낙관적 UI / 체크박스 / 날짜 구분선

### 결제
- 🔒 plan_default_summary_limit_minutes — Free=30/Plus=300/Pro=1000
- 🔒 사용량 차감 = usage_seconds_period 초 단위 누적 (정확)
- 🔒 overage_top_up — 5000원/71분/70원per분
- 🔒 PortOne V2 + 토스 — subscribe.html `requestIssueBillingKey`

### 일반
- 🔒 YOUNGMAN 브랜드 — `logo_main.png` + seal-red `#c8362c`
- 🔒 cron-process-jobs max_retry=2
- 🔒 audio_cleanup 7일 (audio_pending 제외)
- 🔒 ledger UX — 헤더 클릭 필터 / 행 추가 모달 / accordion
- 🔒 placeholder masker (auth-shared.js setupPlaceholderMasker) — MutationObserver
- 🔒 OG/Twitter image = og-thumbnail.png, favicon/logo = logo_main.png

---

## 9. 다음에 이어서 해야 할 작업

### 1순위 — 앱팀 v60/v73 빌드 대기 (사장님 측 의뢰 완료)
사장님이 앱팀에 명세 전부 전달함. 빌드 받으면 사장님 admin 계정 + 무료 테스트 계정 (예: nxnxqx@dddm.com) 두 가지로 회귀 테스트:
1. Plus 구독자 통화 종료 → 기존 첫 모달 (취소/요약보기/양식에 전송)
2. 무료 사용자 통화 종료 → 즉시 "Plus 구독부터" 모달 (첫 모달 X)
3. "양식에 전송" 클릭 후 trigger_summarize(auto_confirm=1) 실제 호출
4. "요약보기" 후 callback → client 화면 자동 갱신 (FCM 또는 polling)
5. admin plan 변경 → 사용자 다음 통화에서 즉시 반영

### 2순위 — 사장님 직접 작업
1. RECORDING_WORKER_TOKEN rotate (3곳 동기화, **따옴표 없이**)
2. cafe24 webroot admin_env_diag.php FTP 직접 삭제
3. 사장님 admin 계정 (nxnxax@gmail.com) members row 없음 — 가입 필요? 또는 admin allowlist 만으로 충분?

### 3순위 — 사장님 결정 시
- 옛 통화 region backfill (Claude API 비용 발생)
- 앱팀 v60/v73 빌드 받기 전 옵션 A 임시 비활성화 여부

### 4순위 — 미해결 backlog
- AI 요약 두 모드 분기 / PortOne 라이브 검증 / card-builder UX / records.php dead code cleanup / Lottie 비서 애니메이션

---

## 자가 진단 채널

- `sessionStorage.erp.ensureError` — members 보강 실패
- `sessionStorage.erp.memberEnsured = '1'` — 보강 성공
- `localStorage['yman_pending_welcome']` — 환영 모달 트리거 (회원가입 직후 '1')
- 콘솔 prefix: `[auth submit]` / `[google oauth]` / `[bridge]` / `[process-recording]` / `[trigger_summarize]` / `[trigger_summarize placeholder]` / `[recording-callback]` / `[send_to_group]` / `[discard]` / `[confirm]` / `[fcm]` / `[build_plan_info]` (신규)
- 브리지: `window.YoungmanBridge.isInApp()` / `.refreshSession()` / `.sendHeartbeat()` / `.setUnreviewedCount(n)`
- Railway log: Railway dashboard → Deployments → 가장 위 ACTIVE → Logs

### 진단 SQL (사장님 phpMyAdmin)
```sql
-- 최근 가입자 + plan 상태 (사장님 + 테스트 사용자)
SELECT email, plan, plan_status, summary_limit, summary_limit_minutes
FROM members ORDER BY id DESC LIMIT 10;

-- 최근 통화 흐름 (모든 사용자)
SELECT id, owner_email, status, customer_log_id, auto_confirm, duration_sec,
       LEFT(error_message, 200) AS err,
       TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec,
       LEFT(client_request_id, 40) AS cri
FROM recording_jobs ORDER BY created_at DESC LIMIT 10;

-- customer_log 상태 (양식전송 vs 요약보기 vs placeholder 분포)
SELECT id, owner_email, source, ai_model,
       LENGTH(summary) AS sum_len, LENGTH(transcript) AS tr_len,
       linked_ledger_record_id AS lr_id
FROM customer_log ORDER BY ai_generated_at DESC LIMIT 10;

-- 휴대폰 인증 OTP (auth_otp)
SELECT purpose, target, LEFT(code, 10) AS code_prefix, attempts,
       TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec
FROM auth_otp ORDER BY created_at DESC LIMIT 10;

-- ready_to_review / queued / processing / failed 분포
SELECT id, owner_email, status, auto_confirm,
       TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec,
       LEFT(error_message, 200) AS err
FROM recording_jobs
WHERE status IN ('ready_to_review', 'queued', 'processing', 'failed_retryable', 'failed_permanent')
ORDER BY created_at DESC LIMIT 10;
```

### 2026-05-25 진단 결과 요약 (다음 세션 인계)
- 사장님 admin = `nxnxax@gmail.com` (정답)
- 신규 테스트 계정 = `nxnxqx@dddm.com` (사장님이 admin UI 로 plan='pro' 변경한 계정)
- 그 계정 통화 2건 모두 server 측 정상 (status='saved', customer_log INSERT 완료)
- 사장님이 보고한 "무한로딩 / 양식 전송 실패" = server 측 정상, **옛 native v49 가 새 server 흐름 처리 미흡**
- 옛 failed_retryable 8건은 모두 옛 사장님 admin 계정 (nxnxax) 의 1~3일 전 통화 (Whisper 400 / Claude 400) — 옛 이슈, 현재 무관

---

## 환경

- GitHub push 자율 (PAT `~/.git-credentials`)
- Railway 자동 재배포
- **사장님 호칭: 사장님**. "쉬세요" 절대 금지. "PoC" 대신 "테스트" 사용.
- **일반인 용어 우선** — 개발자 용어 (race condition, dedup, mirror 등) 대신 "두 번 처리", "겹침", "복사" 같은 표현.

## 메모리 참조 (`~/.claude/projects/-home-user-jdhoon/memory/`)

- `MEMORY.md` — 인덱스
- `feedback_auth_flow_lessons.md` — 인증 root cause
- `feedback_claude_prefill.md` — Sonnet 4.x prefill 금지
- `feedback_css_edit_sanity.md` — 큰 Edit 후 brace balance
- `feedback_deploy_autonomy.md` — 배포 자율
- `feedback_no_proceed_prompts.md` — "proceed?" 묻지 말 것
- `feedback_no_rest_suggestions.md` — 휴식 권유 절대 금지
- `feedback_no_working_flow_break.md` — 작동 검증된 흐름 외부 의뢰 시 단계별 검증
- `feedback_terminology_test.md` — "PoC" → "테스트", "ship/deploy" → "배포/올림"
- `feedback_pii_isolation.md` — PII owner_email 강제 + git add -A 금지
- `feedback_readability_first.md` — 60대+ 가독성 우선
- `feedback_ledger_ux.md` — 헤더 필터 / 행 추가 모달
- `feedback_paste_formatting.md` — 외부 채팅 paste 메시지는 코드블록 감싸기
- `project_app_bridge.md` — RN WebView 앱
- `project_pii_crypto.md` — AES-256-GCM 라이브
- `project_ledger_system.md` — page_type 기반
- `project_whisper_claude_quality.md` — Sonnet 4.6 production
- `project_railway_deploy_quirks.md` — Dockerfile + startCommand $PORT / Failed deploy 누적 / .env GitHub Secrets 동기화
- `deploy_cafe24.md` — FTP only + .env 매 deploy 재생성
