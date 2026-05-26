# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-26 세션 종료 — **요금제 전면 개편 (sales/master/agency) + Google Play Billing 통합 (verify-google-purchase + RTDN) + 정책 페이지 디자인 wrap + 통계 페이지네이션** 완료. 출시 차단 작업 거의 끝남. 사장님 직접 작업 = GitHub Secrets 등록 (3개) + Play Console Service Account 권한 부여 + 상품 등록 + RTDN Pub/Sub 설정 (후순위).*

---

## 1. 사이트 목적

**YOUNGMAN / 영맨** — 1인 사업자용 AI 영업 플랫폼.
- 슬로건: "단 한 건의 고객정보 누락 없이 관리"
- CRM(고객) / HRM(조직도·계약자) / 마케팅 / 로또 / 단체 SMS / **AI 통화 요약 → 미확인 요약 → 고객관리대장 전송 (자동 지역 인식)**
- 디자인: 한국 캘리그라피 + 인장(seal-red `#c8362c`), Apple/Linear 미니멀
- 라이브: https://youngman-biz.com (Cafe24 + Supabase + MariaDB + PHP)
- **결제**: Google Play Billing (안드로이드 앱 내 구독). PortOne+토스 코드는 keep 상태 (미래 부활용)
- 앱: RN Android WebView + bridge.js (어센트라 v83+ Google Play Billing 통합)
- 고객층: 보험/자동차/중고차/일반 자영업 다양 — AI 업종 무관 범용
- 사업자: 어센트라(Ascentra) 사업자등록번호 393-39-01518 / 대표 장동훈 (nxnxax@gmail.com)

---

## 2. 주요 파일 구조

```
[프론트]
index.html / login-complete.html / logout.html / profile.html / admin.html
org.html / contracts.html / customers.html / forms.html / board.html
card-builder.html / lotto2233.html / Marketing.html / kapp_premium.php
terms.html / privacy.html / refund.html / auto-billing.html  ← 4개 정책 (2026-05-26 wrap)
subscribe.html / billing.html / tester.html
unreviewed.html  ← lazy-STT 카드 UI

[공통 JS]
auth-shared.js   — Supabase + 헤더/footer/bottom-nav + 인증 + placeholder masker
                   + 회원가입 모달 휴대폰 OTP + 환영 모달
                   + mountAppHeader / mountAppFooter (정책 페이지도 사용)
bridge.js        — RN WebView 브리지 (heartbeat 30s, startSubscription 미구현 — 앱팀 작업)
ledger-shared.js — 관리대장 공통
customers.js     — 고객관리대장. region + placeholder + 5초 polling
admin.js         — 관리자 페이지 (sales/master/agency 라벨 + 통계 5표 15개씩 페이지네이션)

[PHP API — cafe24 webroot flat]
api/records.php            — CRUD + auth-profile (plan 정규화) + admin-members PATCH (sales/master/agency whitelist)
                             + planDistribution / MRR planPrices 신규 키
                             + signup-send-otp / signup-verify-otp
                             + customer_log_send_to_group + transcripts_by_phone
                             + trigger_summarize
api/process-recording.php  — 통화 audio. lazy-STT
                             + plan migration (plus→sales, pro→master, premium→sales, trialing→free)
                             + build_plan_info 응답에 minutes_used / minutes_limit / minutes_remaining (앱팀 §5)
                             + 사용량 이월 금지 lazy reset (last_usage_reset_at 30일 경과 시)
                             + 옵션 A (무료 사용자 audio drop) 유지
api/recording-callback.php — Railway worker 결과 수신
api/cron-process-jobs.php  — 5분 cron
api/billing_helpers.php    — portone_plan_amount / label / limit / migration (신규 plan key)
api/billing/config.php     — plans: sales/master/agency
api/billing/verify-payment.php       — (PortOne keep) sales/master/agency whitelist + usage reset
api/billing/cancel-subscription.php  — (PortOne keep) sales/master/agency whitelist
api/billing/cron-renew.php           — (PortOne keep) usage_seconds_period=0 reset 추가
api/billing/google_play_helpers.php  — ★ 신규: JWT RS256 + OAuth2 access token + subscriptions.get
api/billing/verify-google-purchase.php — ★ 신규: 앱 영수증 1차 검증 + plan 활성화 + 이월 금지
api/billing/rtdn.php       — ★ 신규: Real-time Developer Notifications (9가지 type 처리)

[Railway worker]
worker/main.py — Whisper + Claude Sonnet 4.6 + region 추출

[Asset]
og-thumbnail.png / logo_main.png

[베타 APK 호스팅]
tester.html → /download/youngman-latest.apk (사장님 FTP 직접 업로드)
```

---

## 3. 현재 완성된 기능

### 신규 요금제 (2026-05-26 핵심 변경)
- **Free**: 0분 / 무료 (AI 요약은 유료 plan 전용)
- **Sales**: 300분 / ₩24,000
- **Master**: 700분 / ₩47,000
- **Agency**: 1,500분 / ₩89,000
- 옛 plus/pro/premium/trialing 자동 마이그레이션 (DB UPDATE, idempotent)
- 사용량 이월 금지 — 매월 결제일에 `usage_seconds_period=0` reset (3중 안전망: verify-payment / cron-renew / process-recording lazy)
- 자동 충전 (overage_top_up) 기능은 백엔드 keep + UI 숨김 (`SHOW_OVERAGE_FEATURE=false` flag — 부활 시 true 만)

### subscribe.html 카드 구조 (2026-05-26 spec)
- 카드 4개: Free / Sales / Master / Agency (4-column grid, 1100px 이하 2-column)
- **메인**: 금액 / 월
- **서브**: AI요약 N분 / 월 (회색 — `--fg-tertiary`, font-weight 400)
- **부연**: "(요약보기 · 양식으로 전송 버튼 클릭 시에만 차감 — 팝업 [취소] / 자동 종료 시 차감 X)"
- features 5개 공통: 고객관리대장 / 문자발송 / 마케팅+AI사주+로또 / 동일 전화번호 통화 자동 누적 / 담당자 메모 동기화
- **분당 단가 배지 표시 금지** (사장님 지적 — 사업수지 계산용은 사용자 노출 X)
- 결제 버튼 → `window.YoungmanBridge.startSubscription(planKey)` 호출 (앱팀 BillingClient trigger). 웹 fallback alert: "안드로이드 앱에서 결제해주세요"

### Google Play Billing 통합 (2026-05-26 신규 endpoint 3개)
- **`api/billing/google_play_helpers.php`**: Service Account JSON → JWT(RS256) signing → OAuth2 access token (sys_get_temp_dir 캐시, 1시간) + `google_play_get_subscription(packageName, productId, purchaseToken)`
- **`api/billing/verify-google-purchase.php`**: POST endpoint, JWT bearer 인증
  - Body: `{ purchaseToken, productId, planKey, packageName }`
  - paymentState 1(완료) 또는 2(무료체험) 만 활성화
  - emailAddress 매칭 (Google 응답에 있을 때만)
  - members UPDATE + subscriptions/payments INSERT (이력)
  - usage_seconds_period=0 / last_usage_warning_pct=0 reset (이월 금지)
- **`api/billing/rtdn.php`**: Cloud Pub/Sub Push subscription 수신
  - `?token=XXX` shared token 1차 차단 + Google 영수증 재검증 2차 차단
  - 9가지 notificationType 처리: RENEWED / CANCELED / PURCHASED / ON_HOLD / IN_GRACE_PERIOD / RESTARTED / REVOKED / EXPIRED / RECOVERED + voidedPurchaseNotification (환불)
- **productId 매핑** (`google_play_product_id`):
  - sales → `youngman_sales_monthly`
  - master → `youngman_master_monthly`
  - agency → `youngman_agency_monthly`

### process-recording.php 응답 보강 (앱팀 §5)
```json
"plan": {
  "plan": "sales",
  "requires_subscription": false,
  "minutes_used": 47,
  "minutes_limit": 300,
  "minutes_remaining": 253
}
```

### 정책 페이지 디자인 wrap (2026-05-26)
- privacy.html / terms.html / refund.html / auto-billing.html
- 본문 텍스트 100% 보존 (Play 검수 통과 본문 그대로)
- 사이트 header (mountAppHeader) + pretendard webfont + style.css 통합
- inline `<style>` 의 `body` 룰을 `.policy-shell` 로 옮김 (사이트 header 폭 영향 차단)
- 본문 안의 div.footer (사업자 정보) 보존 + 사이트 footer 그 아래 mount

### 관리자 통계 페이지네이션 (2026-05-26)
- 5개 표 15개씩 페이지: daily / referrers / members / events / memberUsage
- 공통 helper: `renderStatsPaginated(tbody, items, colspan, renderRow, emptyMsg, pageKey)`
- 표 우측 하단에 [이전] [N/총 · 총 N건] [다음] 자동 노출 (≤15건 시 숨김)
- click delegation + `_lastStatsPayload` 캐시 후 rerender 패턴

### 인증 (옛 흐름 그대로)
- Supabase + Google OAuth + 6중 race guard + bridge.js heartbeat
- 7단계 auth header fallback (`/auth/v1/user` 폴백 — sb_publishable_)
- 휴대폰 SMS 인증 (회원가입 시 + Google 가입 보충 폼)
- 이름 / 닉네임 분리 저장
- client_request_id 64자 초과 시 SHA-256 hash

### 환영 모달 (회원가입 후 첫 로그인 1회)
- localStorage `yman_pending_welcome` 기반
- DOMContentLoaded 자동 표시 (login-complete / logout skip)

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
- 카드 layout / 줄바꿈 / 2줄 버튼 / 5초 polling / 낙관적 UI / 체크박스 / 날짜 구분선

---

## 4. 아직 미완성 (다음 세션 작업)

### ⏳ 1순위 — 사장님 직접 작업 (출시 차단)
**A. GitHub Secrets 등록** (3개 — Settings → Secrets and variables → Actions):
1. `GOOGLE_PLAY_PACKAGE_NAME` = `com.youngmanapp`
2. `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` = minified 한 줄 (사장님이 직접 paste — `nxnxax@gmail.com` 본인 키)
3. `RTDN_SHARED_TOKEN` = (출시 후 RTDN 작업 시점에 임의 32자)

**B. Play Console**:
- 설정 → API 액세스 → Service Account `youngman-billing-verifier@youngmanapp-e8a9d.iam.gserviceaccount.com` 연결 + **"주문 및 구독 관리"** 권한
- 정기구독 상품 3개 등록: `youngman_sales_monthly` / `youngman_master_monthly` / `youngman_agency_monthly`

**C. RTDN (출시 후 1~2주 OK)**:
- Cloud Pub/Sub 토픽 생성
- Play Console → 수익 창출 → 실시간 알림 → 토픽 ID 등록
- Push subscription URL: `https://youngman-biz.com/billing/rtdn.php?token={RTDN_SHARED_TOKEN}`

**D. 기존 보안 마무리** (옛):
- `RECORDING_WORKER_TOKEN` rotate (3곳 — Railway + cafe24 .env + GitHub Secrets, 따옴표 없이)
- cafe24 webroot 의 `admin_env_diag.php` FTP 직접 삭제

### ⏳ 2순위 — 앱팀 (어센트라) 작업 대기
- 어센트라 v83+ Google Play Billing 통합 완료 보고됨. Play Console 신원 확인 후 AAB 업로드 + 내부 테스트 시작.
- 사장님 admin 계정 + 무료 테스트 계정 회귀 테스트:
  1. 무료 사용자 통화 → "Plus 구독부터" 모달 (옵션 A)
  2. 구독 결제 → BillingClient → server verify → plan 활성화
  3. RTDN 갱신/해지/환불 동기화 (RTDN 활성 후)
  4. 한도 초과 시 응답 `code: 'plan_required'` 처리

### 기존 backlog (낮은 우선순위)
- AI 요약 두 모드 분기 (대화형 vs 보고서식)
- card-builder UX / forms 수식 inline help / profile/admin 디자인 일관성
- records.php dead code cleanup (700줄 — Phase 9)
- Lottie 비서 애니메이션 (사장님이 lottiefiles.com 에서 선택 후)
- 옛 통화 region backfill (사장님 결정 필요)
- 자동 충전 (overage_top_up) 부활 시점 결정 — `SHOW_OVERAGE_FEATURE=true` 1줄 변경

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
  **`GOOGLE_PLAY_PACKAGE_NAME` / `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` / `RTDN_SHARED_TOKEN`** ★ 신규 (2026-05-26)
- "배포/올림" 키워드 → 자율 push→trigger→verify
- 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)`
- 새 페이지 추가 → `deploy.yml` 의 `cp` 추가

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
- 🔑 **JSON 시크릿은 minified 한 줄** — `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` / `FIREBASE_SERVICE_ACCOUNT_JSON`. .env parser 가 첫 라인만 잡음.
- 🔑 **phone_lookup 함수 통일 필수** — `customer_phone_lookup_key` (HMAC-SHA256).
- 📁 Webroot flat layout. `api/sms/` → `deploy/sms/providers/` / `api/billing/` → `deploy/billing/`
- 🔐 `YOUNGMAN_CRYPTO_KEY` 분실 = 복호화 영구 불가
- 📡 PHP 30초 timeout → process-recording `set_time_limit(300)` + Railway 위임
- 📡 records.php `/auth/v1/user` 폴백 — sb_publishable_ asymmetric JWT
- 📡 db_config.php — `return [host, port, database, user, password]`
- 📊 PII 컬럼 폭 — 암호문 100~200 chars, VARCHAR(255)+
- 📊 Whisper 25MB 제한 + iPhone/Galaxy m4a codec 변종 거부 → **mp3 통일 변환**
- 📊 Authorization 헤더 fallback 7단계 (records.php read_authorization_header)
- 🔑 **client_request_id 64자 초과 시 SHA-256 hash 자동 대체**
- 🔑 **case-insensitive email match** — `WHERE LOWER(email) = LOWER(:e)`
- 🔑 **auth_otp.code VARCHAR(64)** — 48 hex token 저장 위해 lazy ALTER
- 🔑 **Cache-bust 필수** — JS module 변경 시 HTML import querystring 도 같은 commit 에서 갱신.
- 🔑 **Service Account JSON 키는 절대 git commit 금지** — GitHub Secrets only.

### Railway worker quirks
- 🚫 `railway.json` 의 `startCommand` 가 Dockerfile 모드에서 shell expansion 안 됨 — Dockerfile CMD `sh -c` wrap.
- 🚫 Failed deployment 가 누적되어도 옛 Active 가 traffic 받음 — dashboard 확인 습관.

---

## 7. 최근 수정한 파일 (2026-05-26 세션)

```
3451dfa  ci(deploy): .env 에 GOOGLE_PLAY 3개 변수 secrets 주입
ed1adc6  feat(policy): 4개 정책 페이지 디자인 wrap — 사이트 header/footer/CSS 통합
3e55300  docs(policy): 4개 정책 페이지 — 앱팀(어센트라) standalone 버전 흡수
05072d8  feat(billing): Google Play Billing 통합 — verify-google-purchase + RTDN + minutes 응답 ★ 큰 변경
dd49666  feat(admin): 통계 표 5종 15개씩 페이지네이션
4c46632  fix(subscribe): AI요약 분 표시 회색으로
75dfea6  fix(subscribe): AI요약 분 표시 굵기 제거
853232d  fix(subscribe): 분당 단가 배지 제거 — 사업수지 계산용 노출 금지
e2d1c37  feat(subscribe): 카드 보강 — features 통일 / AI요약 prefix / 자동충전 숨김 / 가운데 정렬 / 모달→팝업
47a34eb  feat(plan): 요금 카드 레이아웃 보강 + 사용량 이월 금지 (carry-over fix)
abbf487  feat(plan): 신규 요금제 sales/master/agency 전환 — 옛 plus/pro 폐지 ★ 핵심 변경
```

---

## 8. 절대 건드리면 안 되는 부분

### 신규 요금제 (2026-05-26)
- 🔒 plan key 4종: `free` / `sales` / `master` / `agency` (그 외 입력 거부)
- 🔒 옛 key 정규화 6+ 위치: records.php auth-profile, process-recording build_plan_info / plan check, admin.js, subscribe.html, billing.html, index.html (plus→sales, pro→master, premium→sales, trialing→free)
- 🔒 사용량 이월 금지 3중 안전망: verify-payment / cron-renew / process-recording lazy reset. 신규 결제 endpoint 추가 시 동일 reset 패턴 필수 (usage_seconds_period=0 + last_usage_warning_pct=0)
- 🔒 `SHOW_OVERAGE_FEATURE` flag — subscribe.html 의 자동 충전 UI 숨김. 백엔드 `charge_overage_top_up` / `overage_balance_seconds` 컬럼은 keep.
- 🔒 PortOne 코드 keep — verify-payment.php / cancel-subscription.php / cron-renew.php. plan whitelist 만 sales/master/agency 로 갱신됨. 미래 재활성화 가능.

### Google Play Billing (2026-05-26)
- 🔒 productId 매핑 (`google_play_product_id`): sales→youngman_sales_monthly / master→...master / agency→...agency. 앱팀이 Play Console 등록 ID 와 정확히 일치해야 함.
- 🔒 verify-google-purchase.php — `paymentState` 1(완료) 또는 2(무료체험) 만 활성화. 그 외는 400.
- 🔒 verify-google-purchase.php — `emailAddress` 있을 때만 매칭. 옛 영수증/sandbox 호환.
- 🔒 verify-google-purchase.php — 검증 성공 시 `subscriptions.portone_billing_key` 컬럼에 purchase token prefix 120자 저장 (RTDN owner_email lookup 키)
- 🔒 rtdn.php — `?token=` 1차 차단 + Google subscriptions.get 재검증 2차 차단. 토큰만 검증하면 위변조 위험.
- 🔒 rtdn.php — `notificationType=12 (REVOKED)` 와 `voidedPurchaseNotification` 은 즉시 free 강등.
- 🔒 google_play_access_token() — JWT RS256 + sys_get_temp_dir 캐시 (1시간). cache file 권한 0600.

### 정책 페이지 (2026-05-26)
- 🔒 본문 텍스트 100% 보존 (Play 검수 통과 본문). 디자인 wrap 만 가능.
- 🔒 `.policy-shell` wrapper + inline `<style>` 의 `body` 룰은 절대 다시 `body` 로 옮기지 말 것 (사이트 header 폭 깨짐).
- 🔒 본문 안의 `div.footer` (사업자 정보) 와 site footer (mountAppFooter) 는 별개 — 둘 다 보존.

### 관리자 통계 (2026-05-26)
- 🔒 `renderStatsPaginated` helper — `_statsPage[pageKey]` 상태 + `_lastStatsPayload` 캐시. events filter 변경 시 `_statsPage.events=1` reset.

### 인증
- 🔒 6중 race guard 풀스택
- 🔒 7단계 auth header fallback
- 🔒 `window.supabase` 글로벌 + `_runRefreshOnce` cooldown 25s + timeout 12s
- 🔒 records.php `/auth/v1/user` 폴백
- 🔒 records.php worker token 우회 분기 (X-Worker-Token + body.owner_email)
- 🔒 PII owner_email 격리
- 🔒 admin_email_allowlist = `['nxnxax@gmail.com']`

### 회원가입 휴대폰 인증
- 🔒 records.php signup-send-otp / signup-verify-otp endpoint (auth_otp 재사용)
- 🔒 publicResources allowlist 에 signup-* 포함
- 🔒 auth_otp.code VARCHAR(64)
- 🔒 create_member_from_google 의 token 검증 분기 — provider='email' 또는 finalize=true 시 강제

### 환영 모달
- 🔒 openWelcomeModal — markWelcomed 즉시 localStorage.removeItem + 비동기 updateUser
- 🔒 maybeShowWelcomeModal — localStorage 만 트리거
- 🔒 표시 직전 즉시 localStorage.removeItem
- 🔒 DOMContentLoaded 자동 호출 (login-complete / logout skip)

### 회차 ↔ transcript 자물쇠 (2026-05-24)
- 🔒 records.php send_to_group 3분기 모두 `data_json.round_log_ids[회차]=cid`
- 🔒 get_transcript_by_id endpoint
- 🔒 customers.js 회차 카드 `data-customer-log-id` attribute

### lazy-STT 모드
- 🔒 process-recording.php — status='audio_pending', placeholder/mirror/dispatch 안 함
- 🔒 trigger_summarize — auto_confirm 분기 + placeholder INSERT (auto_confirm=1) + ledger mirror
- 🔒 recording-callback.php §7 분기 UPDATE + ledger refresh
- 🔒 cron-process-jobs.php — audio_pending 자동 처리 제외 (lazy)
- 🔒 audio_cleanup.php — audio_pending / failed_retryable storage_path 영구 보존
- 🔒 list_unreviewed query — customer_log_id IS NULL + status IN (...)

### 일반
- 🔒 YOUNGMAN 브랜드 — `logo_main.png` + seal-red `#c8362c`
- 🔒 cron-process-jobs max_retry=2
- 🔒 audio_cleanup 7일 (audio_pending 제외)
- 🔒 ledger UX — 헤더 클릭 필터 / 행 추가 모달 / accordion
- 🔒 placeholder masker (auth-shared.js setupPlaceholderMasker) — MutationObserver
- 🔒 OG/Twitter image = og-thumbnail.png, favicon/logo = logo_main.png
- 🔒 **사용자 노출 텍스트에 "모달" 단어 금지** — "팝업" 사용 (60대+ 사용자가 모르는 단어). 코드 변수명/코멘트는 OK.

---

## 9. 다음에 이어서 해야 할 작업

### 1순위 — 사장님 직접 작업 (출시 차단)
1. **GitHub Secrets 3개 등록**:
   - `GOOGLE_PLAY_PACKAGE_NAME` = `com.youngmanapp`
   - `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` = minified 한 줄 (이미 직전 메시지에 제공됨)
   - `RTDN_SHARED_TOKEN` = (RTDN 작업 시점에 임의 32자)
2. **Play Console**: Service Account `youngman-billing-verifier@youngmanapp-e8a9d.iam.gserviceaccount.com` 연결 + "주문 및 구독 관리" 권한 + 상품 3개 등록
3. **앱팀(어센트라) 빌드 받기** — 신원 확인 후 AAB 업로드 → 내부 테스트

### 2순위 — 출시 후 (RTDN)
1. Cloud Pub/Sub 토픽 생성
2. Play Console → 실시간 알림 → 토픽 ID
3. Push subscription URL: `https://youngman-biz.com/billing/rtdn.php?token={RTDN_SHARED_TOKEN}`
4. RTDN_SHARED_TOKEN GitHub Secrets 추가 + 1회 deploy trigger

### 3순위 — 회귀 테스트 (앱팀 빌드 받은 후)
1. 사장님 admin (nxnxax@gmail.com) + 테스트 계정 (nxnxqx@dddm.com) 두 가지
2. Sales/Master/Agency 구독 → BillingClient → verify-google-purchase → plan 활성화
3. 무료 사용자 통화 → audio drop + 모달 분기
4. 한도 초과 → plan_required 응답
5. RTDN: 해지 / 갱신 / 환불 동기화

### 4순위 — 보안 마무리 (사장님 직접)
1. `RECORDING_WORKER_TOKEN` rotate (Railway + cafe24 .env + GitHub Secrets)
2. cafe24 webroot `admin_env_diag.php` FTP 직접 삭제

### 5순위 — backlog
- AI 요약 두 모드 분기 (대화형 vs 보고서식)
- card-builder UX / forms 수식 inline help / profile/admin 디자인 일관성
- records.php dead code cleanup (700줄 — Phase 9)
- Lottie 비서 애니메이션
- 옛 통화 region backfill (Claude API 비용 발생)
- 자동 충전 부활 시점 (`SHOW_OVERAGE_FEATURE=true`)

---

## 자가 진단 채널

- `sessionStorage.erp.ensureError` — members 보강 실패
- `sessionStorage.erp.memberEnsured = '1'` — 보강 성공
- `localStorage['yman_pending_welcome']` — 환영 모달 트리거
- 콘솔 prefix: `[auth submit]` / `[google oauth]` / `[bridge]` / `[process-recording]` / `[trigger_summarize]` / `[recording-callback]` / `[send_to_group]` / `[discard]` / `[confirm]` / `[fcm]` / `[build_plan_info]` / `[verify-google-purchase]` / `[rtdn]`
- 브리지: `window.YoungmanBridge.isInApp()` / `.refreshSession()` / `.sendHeartbeat()` / `.setUnreviewedCount(n)` / `.startSubscription(planKey)` ★ 신규 (앱팀 구현 대기)
- Railway log: Railway dashboard → Deployments → 가장 위 ACTIVE → Logs
- Google Play API 호출 실패 시 `/tmp/youngman_gplay_token.cache` 삭제 후 재시도

### 진단 SQL (사장님 phpMyAdmin)
```sql
-- 최근 가입자 + 신규 plan 상태
SELECT email, plan, plan_status, summary_limit, summary_limit_minutes,
       usage_seconds_period, last_usage_reset_at
FROM members ORDER BY id DESC LIMIT 10;

-- Google Play 구독 이력
SELECT id, owner_email, plan, status, portone_billing_key AS gp_token_prefix,
       current_period_start, current_period_end, created_at
FROM subscriptions ORDER BY id DESC LIMIT 10;

-- payments 이력 (gplay-* = Google Play 결제)
SELECT id, owner_email, portone_payment_id, amount, status, paid_at
FROM payments ORDER BY id DESC LIMIT 10;

-- 최근 통화 흐름
SELECT id, owner_email, status, customer_log_id, auto_confirm, duration_sec,
       LEFT(error_message, 200) AS err,
       TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec
FROM recording_jobs ORDER BY created_at DESC LIMIT 10;
```

### 2026-05-26 세션 결과 요약
- 사장님 admin = `nxnxax@gmail.com` (정답)
- 신규 테스트 계정 = `nxnxqx@dddm.com` (사장님이 admin UI 로 plan='master' 변경 가능)
- 어센트라 Service Account = `youngman-billing-verifier@youngmanapp-e8a9d.iam.gserviceaccount.com`
- Google Play 패키지 = `com.youngmanapp`
- 출시 차단 작업 끝 — 사장님 GitHub Secrets 등록 + Play Console 설정만 남음

---

## 환경

- GitHub push 자율 (PAT `~/.git-credentials`)
- Railway 자동 재배포
- **사장님 호칭: 사장님**. "쉬세요" 절대 금지. "PoC" 대신 "테스트" 사용.
- **일반인 용어 우선** — "race condition" / "dedup" / "mirror" / "모달" 대신 "두 번 처리" / "겹침" / "복사" / "팝업".
- **토큰 최소 우선** — 사장님이 명시. 짧은 답변 + 최소 작업.

## 메모리 참조 (`~/.claude/projects/-home-user-jdhoon/memory/`)

- `MEMORY.md` — 인덱스
- `project_new_pricing_2026_05_26.md` — sales/master/agency + carry-over 금지 + Google Play 빌링 + SHOW_OVERAGE_FEATURE flag ★ 신규
- `feedback_auth_flow_lessons.md` — 인증 root cause
- `feedback_claude_prefill.md` — Sonnet 4.x prefill 금지
- `feedback_css_edit_sanity.md` — 큰 Edit 후 brace balance
- `feedback_deploy_autonomy.md` — 배포 자율
- `feedback_no_proceed_prompts.md` — "proceed?" 묻지 말 것
- `feedback_no_rest_suggestions.md` — 휴식 권유 절대 금지
- `feedback_no_working_flow_break.md` — 작동 검증된 흐름 변경 시 단계별 검증
- `feedback_terminology_test.md` — "PoC" → "테스트", "ship/deploy" → "배포/올림"
- `feedback_pii_isolation.md` — PII owner_email 강제 + git add -A 금지
- `feedback_readability_first.md` — 60대+ 가독성 우선
- `feedback_ledger_ux.md` — 헤더 필터 / 행 추가 모달
- `feedback_paste_formatting.md` — 외부 채팅 paste 메시지는 코드블록 감싸기
- `project_app_bridge.md` — RN WebView 앱 + startSubscription 브리지 신설 예정
- `project_pii_crypto.md` — AES-256-GCM 라이브
- `project_ledger_system.md` — page_type 기반
- `project_whisper_claude_quality.md` — Sonnet 4.6 production
- `project_railway_deploy_quirks.md` — Dockerfile + startCommand $PORT / Failed deploy 누적 / .env GitHub Secrets 동기화
- `deploy_cafe24.md` — FTP only + .env 매 deploy 재생성
