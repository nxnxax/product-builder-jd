# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-28 세션 종료 — **VAT 별도 정책 전환 완료 (Phase 1)**. 청구액 26,400/51,700/97,900 (= 공급가액 + 10%) / 사용자 표시 = 공급가액 + "(VAT 별도)" 라벨 / MRR = 공급가액 기준 (사장님 매출). subscriptions/payments 에 supply/vat/total/tax_invoice_id 컬럼 lazy ALTER + members 에 business_* 5개. Phase 2 (세금계산서 외부 연동 — 팝빌/바로빌/KCP) 대기.*

---

## 1. 사이트 목적

**YOUNGMAN / 영맨** — 1인 사업자용 AI 영업 플랫폼.
- 슬로건: "단 한 건의 고객정보 누락 없이 관리"
- CRM(고객) / HRM(조직도·계약자) / 마케팅 / 로또 / 단체 SMS / **AI 통화 요약 → 미확인 요약 → 고객관리대장 전송 (자동 지역 인식)**
- 디자인: 한국 캘리그라피 + 인장(seal-red `#c8362c`), Apple/Linear 미니멀
- 라이브: https://youngman-biz.com (Cafe24 + Supabase + MariaDB + PHP)
- **결제**: Google Play Billing (안드로이드 앱 내 구독). PortOne+토스 코드는 keep (미래 부활용)
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
terms.html / privacy.html / refund.html / auto-billing.html  ← 4개 정책
subscribe.html / billing.html / tester.html
unreviewed.html  ← lazy-STT 카드 UI

[공통 JS]
auth-shared.js   — Supabase + 헤더/footer/bottom-nav + 인증 + placeholder masker
bridge.js        — RN WebView 브리지 (startSubscription 미구현 — 앱팀 작업)
ledger-shared.js — 관리대장 공통
customers.js     — 고객관리대장. region + placeholder + 5초 polling
admin.js         — 관리자 페이지 (sales/master/agency 라벨 + 통계 페이지네이션)

[PHP API — cafe24 webroot flat]
api/records.php            — CRUD + auth-profile + admin-members PATCH
                             + planDistribution / MRR / planPrices(객체 구조, VAT 별도)
                             + signup-send-otp / signup-verify-otp
                             + customer_log_send_to_group + transcripts_by_phone + trigger_summarize
api/process-recording.php  — 통화 audio. lazy-STT + plan migration
                             + build_plan_info (minutes_used/limit/remaining) + 옵션 A drop
api/recording-callback.php — Railway worker 결과 수신
api/cron-process-jobs.php  — 5분 cron
api/billing_helpers.php    — portone_plan_amount(=청구액, VAT 포함)
                             + ★ plan_supply_amount (공급가액)
                             + ★ plan_vat_amount (10%)
                             + billing_ensure_tables (subs/payments/members lazy ALTER)
api/billing/config.php     — plans 응답: price/price_display/vat_amount/vat_excluded/minutes/amount
api/billing/verify-payment.php       — (PortOne keep) sales/master/agency whitelist + supply/vat/total INSERT
api/billing/cancel-subscription.php  — (PortOne keep)
api/billing/cron-renew.php           — (PortOne keep) + usage_seconds_period=0 reset
api/billing/google_play_helpers.php  — JWT RS256 + OAuth2 + subscriptions.get
api/billing/verify-google-purchase.php — 영수증 1차 검증 + plan 활성화 + supply/vat/total INSERT
api/billing/rtdn.php       — Real-time Developer Notifications (9가지 type)

[Railway worker]
worker/main.py — Whisper + Claude Sonnet 4.6 + region 추출

[베타 APK 호스팅]
tester.html → /download/youngman-latest.apk (사장님 FTP 직접 업로드)
```

---

## 3. 현재 완성된 기능

### VAT 별도 정책 (2026-05-28 ★ 핵심 변경)
- 청구 금액 (VAT 포함, 결제 시 청구): sales 26,400 / master 51,700 / agency 97,900
- 공급가액 (사용자 표시 = 사장님 매출): sales 24,000 / master 47,000 / agency 89,000
- VAT (10%): sales 2,400 / master 4,700 / agency 8,900
- 사용자 노출: "₩24,000 / 월 (VAT 별도)" 형식. **VAT 금액(2,400)은 표시 X** — "VAT 별도" 라벨만.
- MRR 계산은 공급가액 기준 (사장님 실 매출, VAT 제외)
- DB 컬럼 lazy ALTER (subscriptions / payments): `supply_amount` / `vat_amount` / `total_amount` / `tax_invoice_id` / `tax_invoice_issued_at`
- members lazy ALTER: `business_number` / `business_name` / `business_ceo` / `business_email` / `business_auto_invoice` (Phase 2 사업자 정보 사전 등록)
- `/api/billing/config.php` plans 응답 스키마: `{ price, price_display, vat_amount, vat_excluded:true, minutes, amount, label }`

### 신규 요금제 (2026-05-26)
- **Free**: 0분 / 무료 (AI 요약은 유료 plan 전용)
- **Sales**: 300분 / ₩24,000 (VAT 별도, 청구 ₩26,400)
- **Master**: 700분 / ₩47,000 (VAT 별도, 청구 ₩51,700)
- **Agency**: 1,500분 / ₩89,000 (VAT 별도, 청구 ₩97,900)
- 옛 plus/pro/premium/trialing 자동 마이그레이션 (DB UPDATE, idempotent)
- 사용량 이월 금지 — 매월 결제일에 `usage_seconds_period=0` reset (3중 안전망)
- 자동 충전 (overage_top_up) UI 숨김 (`SHOW_OVERAGE_FEATURE=false`), 백엔드 keep

### subscribe.html 카드 spec
- 카드 4개: Free / Sales / Master / Agency (4-column grid)
- 메인: 금액 (₩24,000) / 월 (VAT 별도)
- 서브: AI요약 N분 / 월
- 부연: "(요약보기 · 양식으로 전송 버튼 클릭 시에만 차감 — 팝업 [취소] / 자동 종료 시 차감 X)"
- 결제 버튼 → `window.YoungmanBridge.startSubscription(planKey)`

### Google Play Billing 통합
- `verify-google-purchase.php`: 영수증 1차 검증, paymentState 1/2 만 활성화, emailAddress 매칭
- `rtdn.php`: Cloud Pub/Sub Push, `?token=` 1차 + Google 재검증 2차, 9가지 notificationType
- productId: `youngman_sales_monthly` / `youngman_master_monthly` / `youngman_agency_monthly`

### 정책 페이지 디자인 wrap (2026-05-26)
- privacy.html / terms.html / refund.html / auto-billing.html — 본문 100% 보존 + 사이트 header/footer wrap

### 관리자 통계 페이지네이션 (2026-05-26)
- 5개 표 15개씩: daily / referrers / members / events / memberUsage
- `renderStatsPaginated` helper + `_lastStatsPayload` 캐시 + click delegation

### 인증
- Supabase + Google OAuth + 6중 race guard + bridge.js heartbeat
- 7단계 auth header fallback (`/auth/v1/user` 폴백 — sb_publishable_)
- 휴대폰 SMS 인증 (회원가입 + Google 가입 보충)
- 이름 / 닉네임 분리 저장
- client_request_id 64자 초과 시 SHA-256 hash

### 환영 모달 / CRM / HRM / 통화 녹취 / 미확인 요약
- 기존 그대로 (자세한 spec 은 옛 커밋 메시지 + memory 참조)

---

## 4. 아직 미완성 (다음 세션 작업)

### ⏳ 1순위 — Phase 2 세금계산서 외부 연동 (★ 다음 세션 진행)
사장님 결정 필요 + 외부 서비스 계약 + DB UI 구현. 베타 테스트 기간(2026-05-29~06-12) 내 가능하면 좋음.
1. **외부 서비스 선정** — 팝빌 / 바로빌 / NHN KCP 중 사장님 결정
2. **사업자 정보 등록 폼** — 마이페이지 또는 회원가입 시 (`members.business_*` 컬럼 활용)
3. **결제 내역 페이지** — billing.html 에 "세금계산서 발행" 버튼 추가
4. **발행 API 연동** — 자동/수동 발행, PDF 다운로드, 이메일 전송
5. **payments.tax_invoice_id / tax_invoice_issued_at** 채우는 로직

### ⏳ 2순위 — 사장님 직접 작업 (출시 차단)
**A. Google Play Console 가격 갱신** (★ 2026-05-28 VAT 별도 전환):
- `youngman_sales_monthly` → 26,400
- `youngman_master_monthly` → 51,700
- `youngman_agency_monthly` → 97,900

**B. GitHub Secrets 등록** (3개 — Settings → Secrets and variables → Actions):
1. `GOOGLE_PLAY_PACKAGE_NAME` = `com.youngmanapp`
2. `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` = minified 한 줄
3. `RTDN_SHARED_TOKEN` = 임의 32자 (RTDN 작업 시점)

**C. Play Console**:
- API 액세스 → Service Account `youngman-billing-verifier@youngmanapp-e8a9d.iam.gserviceaccount.com` 연결 + "주문 및 구독 관리" 권한
- 정기구독 상품 3개 등록 (위 productId)

**D. RTDN (출시 후 OK)**:
- Cloud Pub/Sub 토픽 생성
- Push URL: `https://youngman-biz.com/billing/rtdn.php?token={RTDN_SHARED_TOKEN}`

**E. 보안 마무리**:
- `RECORDING_WORKER_TOKEN` rotate (Railway + cafe24 .env + GitHub Secrets, 따옴표 없이)
- cafe24 webroot 의 `admin_env_diag.php` FTP 직접 삭제

### ⏳ 3순위 — 앱팀(어센트라) 작업 대기
- RN 앱 가격 표시 26,400 / 51,700 / 97,900 동기화 (VAT 별도 정책)
- 사장님 admin + 무료 테스트 계정 회귀 테스트

### 4순위 — backlog (낮은 우선순위)
- AI 요약 두 모드 분기 (대화형 vs 보고서식)
- card-builder UX / forms 수식 inline help / profile/admin 디자인 일관성
- records.php dead code cleanup (700줄 — Phase 9)
- Lottie 비서 애니메이션
- 옛 통화 region backfill
- 자동 충전 (overage_top_up) 부활 — `SHOW_OVERAGE_FEATURE=true` 1줄

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
  `GOOGLE_PLAY_PACKAGE_NAME` / `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` / `RTDN_SHARED_TOKEN`
- "배포/올림" 키워드 → 자율 push→trigger→verify
- 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)`
- 새 페이지 추가 → `deploy.yml` 의 `cp` 추가
- CI 폴링: `until curl ... grep -q '"status": "completed"'; do sleep 15; done` 패턴

### APK 호스팅
- 사장님 FileZilla FTP 로 직접 업로드: `/download/youngman-latest.apk`

---

## 6. Cafe24/PHP 관련 주의사항

- 🚫 **SSH/SCP 절대 금지** — silent drop. FTP only.
- 🚫 **cafe24 cron 미지원** — GitHub Actions schedule.
- 🚫 **cafe24 빈 POST body → 5xx HTML** — 1바이트 이상 필수.
- 🚫 **cafe24 ffmpeg 미설치** — m4a transcode 는 Railway worker 강제.
- 🚫 **cafe24 PHP-FPM opcache** — modified time 무시 가능. `opcache_invalidate()` 필요할 때 있음.
- 🚫 **cafe24 .env 파일 lock** — FileZilla 열어둔 상태로 업로드 시 PHP 가 새 내용 못 읽음.
- 🚫 **cafe24 .env 는 매 deploy 마다 GitHub Secrets 로부터 재생성됨**.
- 🚫 **dhlottery 직접 호출 금지** (IP 차단).
- 🚫 **`git add -A` 금지** — PII 누설 위험. 명시적 파일만 add.
- 🔑 **.env 값에 따옴표 절대 금지**. 모든 .env parsing 은 `trim($v, "\"' \t\r\n")` 사용.
- 🔑 **JSON 시크릿은 minified 한 줄** (`GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` / `FIREBASE_SERVICE_ACCOUNT_JSON`).
- 🔑 **phone_lookup 함수 통일 필수** — `customer_phone_lookup_key` (HMAC-SHA256).
- 📁 Webroot flat layout. `api/billing/` → `deploy/billing/`
- 🔐 `YOUNGMAN_CRYPTO_KEY` 분실 = 복호화 영구 불가
- 📡 PHP 30초 timeout → process-recording `set_time_limit(300)` + Railway 위임
- 📡 records.php `/auth/v1/user` 폴백 — sb_publishable_ asymmetric JWT
- 📊 PII 컬럼 폭 — 암호문 100~200 chars, VARCHAR(255)+
- 📊 Whisper 25MB 제한 + iPhone/Galaxy m4a codec 변종 거부 → **mp3 통일**
- 🔑 **case-insensitive email match** — `WHERE LOWER(email) = LOWER(:e)`
- 🔑 **Cache-bust 필수** — JS module 변경 시 HTML import querystring (?v=) 도 같은 commit.
- 🔑 **Service Account JSON 키는 절대 git commit 금지** — GitHub Secrets only.

### Railway worker quirks
- 🚫 `railway.json` 의 `startCommand` 가 Dockerfile 모드에서 shell expansion 안 됨 — Dockerfile CMD `sh -c` wrap.
- 🚫 Failed deployment 가 누적되어도 옛 Active 가 traffic 받음 — dashboard 확인 습관.

---

## 7. 최근 수정한 파일 (2026-05-28 세션)

```
bb2003b  feat(billing): VAT 별도 정책 전환 — 청구 26,400/51,700/97,900 + 공급가액 분리 저장 ★ 핵심
3451dfa  ci(deploy): .env 에 GOOGLE_PLAY 3개 변수 secrets 주입 (2026-05-26)
ed1adc6  feat(policy): 4개 정책 페이지 디자인 wrap (2026-05-26)
3e55300  docs(policy): 4개 정책 페이지 앱팀(어센트라) standalone 흡수 (2026-05-26)
05072d8  feat(billing): Google Play Billing 통합 — verify-google-purchase + RTDN (2026-05-26)
dd49666  feat(admin): 통계 표 5종 페이지네이션 (2026-05-26)
abbf487  feat(plan): 신규 요금제 sales/master/agency 전환 (2026-05-26)
```

### bb2003b 상세 (2026-05-28)
9개 파일:
- `api/billing_helpers.php` — portone_plan_amount(청구액) + plan_supply_amount + plan_vat_amount + lazy ALTER 확장
- `api/billing/config.php` — plans 응답 스키마 확장
- `api/records.php:3568` — planPrices 객체화 + MRR 공급가액 기준 + admin stats 응답에 planPrices 노출
- `subscribe.html` — "(VAT 별도)" 라벨 + meta description + 안내문구
- `billing.html` — formatPrice "(VAT 별도)" 라벨
- `admin.html:523` — plan select option (₩24,000+VAT, 결제 ₩26,400)
- `api/process-recording.php:955` — 주석 갱신
- `api/billing/verify-google-purchase.php:145` — INSERT 시 supply/vat/total 분리
- `api/billing/verify-payment.php:136` — INSERT 시 supply/vat/total 분리

---

## 8. 절대 건드리면 안 되는 부분

### VAT 별도 정책 (2026-05-28) ★ 신규
- 🔒 `portone_plan_amount()` = 청구액 (VAT 포함) — Google Play / PortOne 실제 청구 금액과 일치 필수
- 🔒 `plan_supply_amount()` = 공급가액 (24,000/47,000/89,000) — 사용자 표시 + 세금계산서 공급가액 + MRR 기준
- 🔒 사용자 노출 영역에 **VAT 금액(2,400)은 절대 표시 X** — "(VAT 별도)" 라벨만
- 🔒 MRR 계산은 `price_display` (공급가액) 기준. price(청구액) 으로 곱하면 매출 부풀려짐.
- 🔒 subscriptions/payments INSERT 시 supply_amount/vat_amount/total_amount 3개 모두 채우기. 신규 결제 endpoint 추가 시 동일 패턴 필수.
- 🔒 `config.php` plans 응답 키 (price/price_display/vat_excluded/vat_amount/minutes/amount/label) — 앱팀(어센트라) 이 사용. 키 이름 변경 금지.

### 신규 요금제
- 🔒 plan key 4종: `free` / `sales` / `master` / `agency` (그 외 입력 거부)
- 🔒 옛 key 정규화 6+ 위치 (plus→sales, pro→master, premium→sales, trialing→free)
- 🔒 사용량 이월 금지 3중 안전망: verify-payment / cron-renew / process-recording lazy reset
- 🔒 `SHOW_OVERAGE_FEATURE` flag — subscribe.html 자동 충전 UI 숨김. 백엔드 코드는 keep.
- 🔒 PortOne 코드 keep — plan whitelist 만 sales/master/agency 갱신. 미래 재활성화 가능.

### Google Play Billing
- 🔒 productId 매핑: sales→youngman_sales_monthly / master→...master / agency→...agency
- 🔒 verify-google-purchase.php — `paymentState` 1(완료) / 2(무료체험) 만 활성화
- 🔒 verify-google-purchase.php — `emailAddress` 있을 때만 매칭
- 🔒 verify-google-purchase.php — 검증 성공 시 `subscriptions.portone_billing_key` 에 purchase token prefix 120자 저장
- 🔒 rtdn.php — `?token=` 1차 + Google subscriptions.get 2차 검증 양쪽 필수
- 🔒 rtdn.php — `notificationType=12 (REVOKED)` + `voidedPurchaseNotification` 즉시 free 강등
- 🔒 google_play_access_token() — JWT RS256 + sys_get_temp_dir 캐시 (1시간), 권한 0600

### 정책 페이지 / 관리자 통계 / 인증 / 환영 모달 / 회차 자물쇠 / lazy-STT
(2026-05-26 이전 변경. 옛 PROJECT_CONTEXT.md 참조 — 변경 없음)

### 일반
- 🔒 YOUNGMAN 브랜드 — `logo_main.png` + seal-red `#c8362c`
- 🔒 cron-process-jobs max_retry=2
- 🔒 audio_cleanup 7일 (audio_pending 제외)
- 🔒 ledger UX — 헤더 클릭 필터 / 행 추가 모달 / accordion
- 🔒 placeholder masker (auth-shared.js) — MutationObserver
- 🔒 OG/Twitter image = og-thumbnail.png, favicon/logo = logo_main.png
- 🔒 **사용자 노출 텍스트에 "모달" 단어 금지** — "팝업" 사용

---

## 9. 다음에 이어서 해야 할 작업

### 1순위 — Phase 2 세금계산서 외부 연동 (★ 클로드 작업)
1. 사장님과 외부 서비스 선택 결정 (팝빌 / 바로빌 / NHN KCP)
2. 선택 후 API 키 발급 안내 → GitHub Secrets 등록
3. `members.business_*` 5개 컬럼 활용 — 사업자 정보 등록 폼 (마이페이지 또는 신규 페이지)
4. billing.html 결제 내역 페이지에 "세금계산서 발행" 버튼 + 발행 후 PDF/이메일
5. `payments.tax_invoice_id` / `tax_invoice_issued_at` 채우는 endpoint 신설
6. 자동 발행 옵션 (`business_auto_invoice=1` 시 verify-google-purchase / verify-payment 성공 직후 자동 호출)

### 2순위 — 사장님 직접 작업 (출시 차단)
1. **Google Play Console 가격 갱신** ★ 2026-05-28 VAT 정책 동기화 (26,400/51,700/97,900)
2. **GitHub Secrets 3개 등록** (GOOGLE_PLAY_PACKAGE_NAME / GOOGLE_PLAY_SERVICE_ACCOUNT_JSON / RTDN_SHARED_TOKEN)
3. **Play Console** Service Account 권한 부여 + 상품 3개 등록
4. **앱팀(어센트라) 빌드 받기** — AAB 업로드 → 내부 테스트

### 3순위 — 출시 후 (RTDN)
1. Cloud Pub/Sub 토픽 생성
2. Play Console → 실시간 알림 → 토픽 ID
3. Push URL: `https://youngman-biz.com/billing/rtdn.php?token={RTDN_SHARED_TOKEN}`

### 4순위 — 회귀 테스트 (앱팀 빌드 받은 후)
1. 사장님 admin (nxnxax@gmail.com) + 테스트 계정 (nxnxqx@dddm.com)
2. Sales/Master/Agency 구독 → BillingClient → verify-google-purchase → 26,400 등 청구 확인 → plan 활성화
3. 무료 사용자 통화 → audio drop + 모달 분기
4. 한도 초과 → plan_required 응답
5. RTDN: 해지 / 갱신 / 환불 동기화

### 5순위 — 보안 마무리 (사장님 직접)
1. `RECORDING_WORKER_TOKEN` rotate (Railway + cafe24 .env + GitHub Secrets)
2. cafe24 webroot `admin_env_diag.php` FTP 직접 삭제

### 6순위 — backlog
- AI 요약 두 모드 분기 (대화형 vs 보고서식)
- card-builder UX / forms 수식 inline help / profile/admin 디자인 일관성
- records.php dead code cleanup (Phase 9)
- Lottie 비서 애니메이션
- 옛 통화 region backfill (Claude API 비용 발생)
- 자동 충전 부활 (`SHOW_OVERAGE_FEATURE=true`)

---

## 자가 진단 채널

- `sessionStorage.erp.ensureError` — members 보강 실패
- `sessionStorage.erp.memberEnsured = '1'` — 보강 성공
- `localStorage['yman_pending_welcome']` — 환영 모달 트리거
- 콘솔 prefix: `[auth submit]` / `[google oauth]` / `[bridge]` / `[process-recording]` / `[trigger_summarize]` / `[recording-callback]` / `[send_to_group]` / `[discard]` / `[confirm]` / `[fcm]` / `[build_plan_info]` / `[verify-google-purchase]` / `[rtdn]`
- 브리지: `window.YoungmanBridge.isInApp()` / `.refreshSession()` / `.sendHeartbeat()` / `.setUnreviewedCount(n)` / `.startSubscription(planKey)` (앱팀 구현 대기)
- Railway log: Railway dashboard → Deployments → ACTIVE → Logs
- Google Play API 호출 실패 시 `/tmp/youngman_gplay_token.cache` 삭제 후 재시도

### 진단 SQL (사장님 phpMyAdmin)
```sql
-- 최근 가입자 + plan 상태
SELECT email, plan, plan_status, summary_limit_minutes,
       usage_seconds_period, last_usage_reset_at
FROM members ORDER BY id DESC LIMIT 10;

-- Google Play 구독 이력 + VAT 분리 (2026-05-28)
SELECT id, owner_email, plan, status, portone_billing_key AS gp_token_prefix,
       supply_amount, vat_amount, total_amount,
       tax_invoice_id, tax_invoice_issued_at,
       current_period_start, current_period_end, created_at
FROM subscriptions ORDER BY id DESC LIMIT 10;

-- payments 이력 + VAT 분리 (gplay-* = Google Play)
SELECT id, owner_email, portone_payment_id,
       supply_amount, vat_amount, total_amount, amount,
       status, paid_at
FROM payments ORDER BY id DESC LIMIT 10;

-- 사업자 회원 (세금계산서 발행자 사전 등록)
SELECT email, business_number, business_name, business_ceo,
       business_email, business_auto_invoice
FROM members WHERE business_number IS NOT NULL ORDER BY id DESC;

-- 최근 통화 흐름
SELECT id, owner_email, status, customer_log_id, auto_confirm, duration_sec,
       LEFT(error_message, 200) AS err,
       TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec
FROM recording_jobs ORDER BY created_at DESC LIMIT 10;
```

### 2026-05-28 세션 결과 요약
- VAT 별도 정책 Phase 1 완료 + 라이브 verify OK
- Phase 2 (세금계산서 외부 연동) 사장님 결정 항목 다수 — 다음 세션 시작 시 첫 작업
- 사장님 직접 작업: Google Play Console 가격 갱신 (26,400/51,700/97,900)

---

## 환경

- GitHub push 자율 (PAT `~/.git-credentials`)
- Railway 자동 재배포
- **사장님 호칭: 사장님**. "쉬세요" 절대 금지. "PoC" 대신 "테스트" 사용.
- **일반인 용어 우선** — "race condition" / "dedup" / "mirror" / "모달" 대신 "두 번 처리" / "겹침" / "복사" / "팝업".
- **토큰 최소 우선** — 사장님이 명시. 짧은 답변 + 최소 작업.

## 메모리 참조 (`~/.claude/projects/-home-user-jdhoon/memory/`)

- `MEMORY.md` — 인덱스
- `project_new_pricing_2026_05_26.md` — sales/master/agency + carry-over 금지
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
- `feedback_paste_formatting.md` — 외부 채팅 paste 메시지 코드블록
- `project_app_bridge.md` — RN WebView + startSubscription 브리지
- `project_pii_crypto.md` — AES-256-GCM 라이브
- `project_ledger_system.md` — page_type 기반
- `project_whisper_claude_quality.md` — Sonnet 4.6 production
- `project_railway_deploy_quirks.md` — Dockerfile + startCommand $PORT
- `deploy_cafe24.md` — FTP only + .env 매 deploy 재생성
