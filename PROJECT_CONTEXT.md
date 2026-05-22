# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-22 (KST 오후 세션 종료) — ✅ **Whisper 400 비상 완전 종료** + 인증 race 2종 fix + UX 개선 + cancel endpoint + 진단 인프라.*

---

## 1. 사이트 목적

**YOUNGMAN / 영맨** — 1인 사업자용 AI 영업 플랫폼.
- 슬로건: "단 한 건의 고객정보 누락 없이 관리"
- CRM(고객) / HRM(조직도·계약자) / 마케팅 / 로또 / 단체 SMS / **AI 통화 요약 → CRM 자동 전송**
- 디자인: 한국 캘리그라피 + 인장(seal-red `#c8362c`), Apple/Linear 미니멀
- 라이브: https://youngman-biz.com (Cafe24 + Supabase + MariaDB + PHP)
- 결제: PortOne V2 + 토스페이먼츠 정기결제
- 앱: RN Android WebView + bridge.js
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

[공통 JS]
auth-shared.js  — Supabase + 헤더/footer/bottom-nav + 인증 + placeholder masker
bridge.js       — RN WebView 브리지 (heartbeat 포함)
ledger-shared.js — 관리대장 공통

[PHP API — cafe24 webroot flat]
api/records.php           — 모든 CRUD + customer_log_send_to_group + customer_log_cancel + refresh=true
api/process-recording.php — 통화 audio 업로드. §7 placeholder-first + Railway dispatch
api/recording-callback.php — Railway worker 결과 수신 + customer_log UPDATE only
api/cron-process-jobs.php  — 5분 cron + processing stuck 10분 watchdog
api/job-status.php         — 앱 polling (7단계 auth fallback)
api/recording-audio.php    — HMAC signed audio URL (10분)
api/audio_cleanup.php      — 7일 cron cleanup
api/upload.php             — multipart audio 수신
api/billing_helpers.php / billing/* — 결제

[Railway worker]
worker/Dockerfile  — python:3.11-slim + ffmpeg + uvicorn (sh -c CMD)
worker/main.py     — Whisper + Claude + transcode_to_mp3 호출 (line 565)
worker/railway.json — DOCKERFILE builder, startCommand 제거됨

[Asset]
og-thumbnail.png — OG/Twitter 카드 이미지 (1.7MB)
logo_main.png    — favicon + JSON-LD logo

[베타 APK 호스팅]
tester.html → /download/youngman-latest.apk (사장님 FTP 직접 업로드)
.htaccess 에 .apk MIME + Content-Disposition
```

---

## 3. 현재 완성된 기능

### 인증
- Supabase + Google OAuth + 6중 race guard + bridge.js heartbeat
- 7단계 auth header fallback
- **Google 로그인 race fix** (commit 73b7f20) — 모달 표시 시 supabaseClient 미준비면 버튼 disabled + "인증 준비 중…" 텍스트. init 완료 시 자동 enabled.
- **OAuth 후 헤더 깜빡임 fix** (commit 02c3270) — mountAppHeader 에 localStorage sb-*-auth-token 직접 파싱 fallback. anon 깜빡임 0초.

### CRM / HRM
- 조직도/계약자/고객 관리대장 + AES-256-GCM 암호화
- 양식 빌더 (Phase 1~3, 8타입)
- 회차별 content 분할 + "대화내용 전문보기" 버튼
- 단체 SMS + 잔액 카드

### 통화 녹취 — §7 placeholder-first 흐름 (✅ 풀스택 정상)
```
통화 종료 → /process-recording.php
  → recording_jobs INSERT (status='queued')
  → placeholder customer_log INSERT (ai_model='pending', source='app-placeholder')
  → recording_jobs.customer_log_id 즉시 UPDATE
  → auto send_to_group mirror (placeholder content)
  → 0.2~1초 응답 (customer_log placeholder 포함) ★ 측정 완료 223ms
  → fastcgi_finish_request
  → background: Railway dispatch (Dockerfile + Whisper + Claude)
  → callback → customer_log UPDATE (실제 요약)
  → ledger refresh
```

### UX 개선
- **Placeholder masker** (commit 5145cb5) — auth-shared.js 의 setupPlaceholderMasker(). MutationObserver 로 ledger cell 의 "AI 분석 중" / "처리중..." text 를 빨간 dot 3개 pulse 애니메이션으로 자동 가림. inline style 주입 self-contained.

### 통화 취소/요약 폐기 (commit 671177e)
- POST `/records.php?resource=customer-log&action=customer_log_cancel`
- body: `{ id: customer_log_id }`
- Cascade 삭제: customer_log + recording_jobs + ledger_records mirror + audio file
- callback 안전 (UPDATE only 라 row 없으면 0 rows affected)

### OG 썸네일 (commit 0830540)
- worker/Thumbnail.png → og-thumbnail.png (repo root)
- 8개 HTML (index/Marketing/auto-billing/subscribe/lotto2233/refund/privacy/terms) 의 og:image/twitter:image 일괄 교체
- favicon + JSON-LD logo 는 logo_main.png 유지

### 베타 APK 호스팅
- tester.html — Apple/Linear 미니멀 다운로드 안내
- /download/youngman-latest.apk (사장님 FTP 직접 업로드)
- 메인 hero CTA 임시 변경: subscribe.html → tester.html (결제사 승인 전)

### Railway Worker (2026-05-22 PM 부활)
- Active commit: 70961f4 (Dockerfile + railway.json startCommand 제거)
- worker/main.py:565 의 transcode_to_mp3() 호출 살아남 — m4a → mp3 → Whisper 정상
- HTTP /health 정상 (token_set/openai_set/anthropic_set 다 true)

### 진단 인프라 (오늘 추가)
- `_send_debug` 응답 확장 (commit 6bca688) — body_keys, raw_body, content_type, body_group_id_raw 등
- `response_elapsed_ms` 컬럼 + 응답 필드 (commit 2435dae, 80aa038) — §7 응답 시간 SQL 가시화

---

## 4. 아직 미완성 (다음 세션 작업)

### ⏳ RN 측 race 3종 (앱팀 작업, 영맨 진단 완료)

**(A) 요약보기 5초+ 무반응** — 영맨 §7 응답 **223ms 측정 확정**. 100% RN 측 race.
가설:
- 모달 표시가 응답 받기 전 발화 (낙관적 UI)
- "요약보기" 버튼이 customer_log_id 대기 condition
- "요약보기" 클릭 시 customer_log_get 별도 fetch
Fix: 모달 표시를 응답 받은 후로 deferred + 영맨 응답의 `customer_log` 필드 즉시 활용 (별도 fetch X).

**(B) group_id 전달 race** — RN 측이 `group_id="33"` 보낸다는데 backend `gid_received=0`. 영맨이 `_send_debug` 강화 push 완료. 다음 통화 시 RN 측 응답 캡처해서 paste 받아야 정확한 원인 분기 (body parsing 실패 vs nested wrapping vs 다른 key).

**(C) 첫 통화 모달 안 뜸** — 두 번째 통화는 정상. 앱 측 통화 종료 detection 또는 응답 처리 race. SQL 로 첫 통화 시 recording_jobs INSERT 됐는지 확인하면 분기 가능.

### ⏳ 모달 UX — 여자비서 Lottie 애니메이션 (앱팀 작업)
- 사장님이 [lottiefiles.com](https://lottiefiles.com/free-animations/secretary) 에서 마음에 드는 무료 secretary writing 애니메이션 선택 → JSON 다운로드 → 영맨에게 전달
- 영맨이 cafe24 호스팅 + 앱팀에 lottie-react-native 통합 가이드 작성
- 현재 모달의 동그라미 spinner = RN native UI (영맨 repo 에 텍스트 없음 확인)

### ⚠️ 보안 마무리 (사장님 작업 필수)
1. **RECORDING_WORKER_TOKEN rotate** — 진단 중 screenshot 노출 + hash prefix 노출됨
   - Railway Variables → 새 token 생성 (랜덤 64+자)
   - cafe24 .env 동일 새 값 (FileZilla 파일 닫고 업로드 — lock 시 PHP 못 읽음)
   - GitHub Secrets 도 동기화 (deploy 시 .env 재생성됨)
2. **cafe24 webroot 의 admin_env_diag.php FTP 직접 삭제** — git 에서는 제거됐지만 (commit 5d0d0bd) deploy mirror 아닐 수 있음

### 진단 컬럼 cleanup (선택)
- recording_jobs.response_elapsed_ms — 다음 통화 모니터링용 유지 또는 제거
- recording_jobs.error_message 의 옛 진단 메시지

### 기존 backlog (낮은 우선순위)
- 앱팀 §8 polling endpoint 의뢰 답변 처리
- records.php deprecated endpoint Sunset/Deprecation HTTP header
- 401/403 _auth_debug 필드 표준화
- records.php dead code 700줄 cleanup (미확인요약 폐기 잔재)
- schema 정리 (review_required / recording_review_mode)
- AI 요약 두 모드 분기 (대화형 vs 보고서식)
- PortOne Webhook URL 등록 + 정식 토스 키 발급 후 라이브 결제 검증
- card-builder UX / forms 수식 inline help / profile/admin 디자인 일관성
- GitHub Actions cron 7시간 간격 (비상 2번 — process-jobs.yml schedule)

---

## 5. 배포 방식

- GitHub Actions → FTP (cafe24, SamKirkland/FTP-Deploy-Action)
- 클로드 직접 push 가능 (PAT `~/.git-credentials`, 90일 만료 Aug 17 2026)
- Railway: GitHub 연동, `worker/` 폴더 root, main push 자동 재배포
- 시크릿 (필수):
  `CAFE24_FTP_PASSWORD` / `YOUNGMAN_CRYPTO_KEY` / `SUPABASE_SERVICE_KEY`
  `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` / `NCP_CLOVA_*`
  `STT_PROVIDER=whisper` / `LLM_PROVIDER=anthropic`
  `RAILWAY_WORKER_URL` / `RECORDING_WORKER_TOKEN` ★ rotate 필요
  `PORTONE_*` / `FIREBASE_SERVICE_ACCOUNT_JSON` / `AUDIO_CLEANUP_TOKEN`
- "배포/올림" 키워드 → 자율 push→trigger→verify
- 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)`
- 새 페이지 추가 → `deploy.yml` 의 `test -f` + `cp` 둘 다

### Railway 배포 (2026-05-22 PM 정상화)
- worker/Dockerfile 사용 (nixpacks 미사용)
- railway.json `startCommand` 제거 — Dockerfile CMD 의 `sh -c "uvicorn ... --port ${PORT:-8080}"` 사용
- Active deployment 확인 습관: dashboard → Deployments 탭 → ACTIVE 표시 + commit hash 확인

### APK 호스팅
- 사장님 FileZilla FTP 로 직접 업로드: `/download/youngman-latest.apk`
- 앱팀 새 빌드 시 같은 경로 덮어쓰기 (앱 내 URL 변경 X)

---

## 6. Cafe24/PHP 관련 주의사항

- 🚫 **SSH/SCP 절대 금지** — silent drop. FTP only.
- 🚫 **cafe24 cron 미지원** — GitHub Actions schedule.
- 🚫 **cafe24 빈 POST body → 5xx HTML** — 1바이트 이상 필수.
- 🚫 **cafe24 ffmpeg 미설치** — m4a transcode 는 Railway worker 강제.
- 🚫 **cafe24 PHP-FPM opcache** — modified time 무시 가능성. `opcache_invalidate()` 또는 reset 필요할 때 있음.
- 🚫 **cafe24 .env 파일 lock** — FileZilla 등에서 열어둔 상태로 업로드 시 PHP 가 새 내용 못 읽음. 파일 닫고 재업로드 필수.
- 🚫 **cafe24 .env 는 매 deploy 마다 GitHub Secrets 로부터 재생성됨** — FTP 직접 수정값은 다음 push 시 덮어쓰여짐. GitHub Secrets 와 동기화 필수.
- 🚫 **dhlottery 직접 호출 금지** (IP 차단).
- 🚫 **`git add -A` 금지** — PII 누설 위험.
- 📁 Webroot flat layout. `api/sms/` → `deploy/sms/providers/` / `api/billing/` → `deploy/billing/`
- 🔐 `YOUNGMAN_CRYPTO_KEY` 분실 = 복호화 영구 불가
- 📡 PHP 30초 timeout → process-recording set_time_limit(300) + Railway 위임
- 📡 records.php `/auth/v1/user` 폴백 — sb_publishable_ asymmetric JWT
- 📡 db_config.php — `return [host, port, database, user, password]`
- 📊 PII 컬럼 폭 — 암호문 100~200 chars, VARCHAR(255)+
- 📊 Whisper 25MB 제한 + iPhone/Galaxy m4a codec 변종 거부 → **mp3 통일 변환 (Railway worker main.py:565 transcode_to_mp3)**
- 📊 Authorization 헤더 fallback 7단계 (records.php read_authorization_header) — HTTP_AUTHORIZATION / REDIRECT_HTTP_AUTHORIZATION / getallheaders[Authorization|authorization] / apache_request_headers

### Railway worker quirks (2026-05-22 학습)
- 🚫 **`railway.json` 의 `startCommand` 가 Dockerfile 모드에서 shell expansion 안 됨** — `$PORT` literal 로 전달되어 uvicorn fail. Dockerfile CMD 의 `sh -c` wrap 사용.
- 🚫 **Failed deployment 가 누적되어도 옛 Active 가 계속 traffic 받음** — dashboard 확인 습관.

---

## 7. 최근 수정한 파일 (commit 흐름)

```
# 2026-05-22 PM — UX 개선 + 진단 인프라
2435dae diag(call): recording_jobs.response_elapsed_ms 컬럼 — SQL 가시화
80aa038 diag(call): process-recording §7 응답에 server_elapsed_ms 노출
0830540 feat(seo): OG/Twitter 카드 이미지 → og-thumbnail.png 교체
500617b chore(ui): 고객관리대장 "모바일 앱 연동 준비 완료" 안내 제거
6bca688 diag(send_to_group): _send_debug 에 body parsing 진단 필드 추가
671177e feat(call): customer_log_cancel — 통화 취소/요약 폐기 cascade
5145cb5 feat(ux): 통화 녹취 placeholder text 로딩 dots 로 가림
02c3270 fix(auth): OAuth 직후 헤더 anon → 로그인 깜빡임 제거
73b7f20 fix(auth): supabase init race — 모달 표시 시 버튼 비활성화

# 2026-05-22 PM — Whisper 400 비상 최종 fix
5d0d0bd chore(cleanup): admin_env_diag 제거 + PROJECT_CONTEXT 갱신
70961f4 fix(railway): startCommand 제거 — Dockerfile CMD 사용 ★ FINAL FIX
e7c3a21 diag(env): opcache_reset + Railway outbound HTTP 테스트 분기 추가
38aaf0c diag(env): Authorization 헤더 7단계 fallback + source 노출
630ed4e diag(env): 401 응답에 token hash 비교 정보 추가
19167ca diag: cafe24 .env 읽기 진단 endpoint 추가 (검증 후 제거됨)
ee2c7bb fix(railway): nixpacks pip 미설치 — Dockerfile 전환

# 2026-05-22 AM — 비상 진행 중 (해결됨)
140d056 fix(stt): cafe24 자체 STT fallback Whisper → Clova 강제
3bad4b3 fix(stt §diag): Railway dispatch 실패 원인 DB 기록 강화
08cd6bd revert(stt): §7 placeholder-first + cron watchdog 재적용
57ecc6a fix(stt §7): placeholder-first 전환 — sync 응답 시간 1~2s
```

---

## 8. 절대 건드리면 안 되는 부분

### 인증
- 🔒 6중 race guard 풀스택
- 🔒 7단계 auth header fallback
- 🔒 `window.supabase` 글로벌 + `_runRefreshOnce` cooldown 25s + timeout 12s
- 🔒 records.php `/auth/v1/user` 폴백
- 🔒 PII owner_email 격리
- 🔒 mountAppHeader 의 localStorage sb-*-auth-token 직접 파싱 fallback (commit 02c3270)
- 🔒 로그인 모달 표시 시 supabase init 미준비 버튼 disabled 로직 (commit 73b7f20)

### 통화 녹취
- 🔒 §7 placeholder-first 응답 구조 (0.2~1초) — RN polling 의존
- 🔒 customer_log placeholder INSERT 시 source='app-placeholder', ai_model='pending'
- 🔒 customer_log.client_request_id 24h UNIQUE (dedup)
- 🔒 recording_jobs.audio_sha256 영구 dedup
- 🔒 fastcgi_finish_request + ignore_user_abort + set_time_limit(300)
- 🔒 worker/main.py:565 transcode_to_mp3 호출 — Whisper 400 회피
- 🔒 worker/Dockerfile 의 sh -c CMD — railway startCommand 안 씀
- 🔒 worker/railway.json — startCommand 없음, builder=DOCKERFILE

### 결제
- 🔒 plan_default_summary_limit_minutes — Free=30/Plus=300/Pro=1000
- 🔒 overage_top_up — 5000원/71분/70원per분
- 🔒 PortOne V2 + 토스 — subscribe.html 의 `requestIssueBillingKey`

### 일반
- 🔒 YOUNGMAN 브랜드 — `logo_main.png` + seal-red `#c8362c`
- 🔒 cron-process-jobs max_retry=2
- 🔒 audio_cleanup 7일
- 🔒 ledger UX — 헤더 클릭 필터 / 행 추가 모달 / accordion
- 🔒 placeholder masker (auth-shared.js setupPlaceholderMasker) — MutationObserver 패턴
- 🔒 메인 hero CTA 임시 (베타 다운로드) — 결제사 승인 후 원복 예정
- 🔒 OG/Twitter image = og-thumbnail.png, favicon/logo = logo_main.png (역할 분리)

---

## 9. 다음에 이어서 해야 할 작업

### 1순위 — RN 측 race 진단 + 앱팀 협업
1. **요약보기 5초+ 무반응** — 영맨 측 223ms 정상. 사장님이 [확정] 메시지 (세션 마지막에 paste 한 내용) 앱팀에 전달 + RN 측이 응답 받기 전 모달 표시 fix 또는 customer_log_get 별도 fetch 제거.
2. **group_id 전달 race** — RN 측이 다음 통화 시 send_to_group 응답의 `_send_debug` 캡처 + paste. 영맨이 받으면 body parsing 실패 vs nested wrapping vs key 이름 mismatch 분기 즉시.
3. **첫 통화 모달 안 뜸** — SQL 로 recording_jobs INSERT 됐는지 확인 (사장님 또는 영맨 측에서 시각 매칭).

### 2순위 — 보안 마무리 (사장님 작업)
1. `RECORDING_WORKER_TOKEN` rotate (Railway Variables + cafe24 .env + GitHub Secrets 3곳)
2. cafe24 webroot 의 `admin_env_diag.php` FTP 직접 삭제 (git 제거됐지만 cafe24 mirror 안 됐을 수 있음)

### 3순위 — 모달 UX 여자비서 애니메이션
1. 사장님이 lottiefiles.com 에서 secretary writing 무료 애니메이션 선택 → JSON 다운로드 → 영맨 전달
2. 영맨: cafe24 `/secretary-loading.json` 호스팅 + 앱팀에 lottie-react-native 통합 가이드 작성

### 4순위 — 진단 컬럼 cleanup
- recording_jobs.response_elapsed_ms — 모니터링 필요 없으면 ALTER TABLE DROP
- send_to_group `_send_debug` 응답 필드 — 진단 끝나면 제거 또는 admin-only

### 사장님 직접 확인 항목
- 카카오톡에 https://youngman-biz.com 공유 시 새 og-thumbnail.png 미리보기 표시 확인
- 페이스북 디버거 [developers.facebook.com/tools/debug](https://developers.facebook.com/tools/debug) 로 강제 갱신 가능
- 새 통화 시 §7 흐름 (placeholder 0.2초 → 12초 후 실제 요약) 정상 작동 확인

---

## 자가 진단 채널

- `sessionStorage.erp.ensureError` — members 보강 실패
- `sessionStorage.erp.memberEnsured = '1'` — 보강 성공
- 콘솔 prefix: `[auth submit]` / `[google oauth]` / `[bridge]` / `[process-recording]` / `[process-recording §7]` / `[process-recording §7 timing]` / `[process-recording §diag]` / `[recording-callback]` / `[send_to_group]` / `[customer_log_cancel]` / `[fcm]`
- 브리지: `window.YoungmanBridge.isInApp()` / `.refreshSession()` / `.sendHeartbeat()`
- Railway log: Railway dashboard → Deployments → 가장 위 ACTIVE → Logs
- recording_jobs row 진단: admin_job_diag endpoint (admin only) + response_elapsed_ms 컬럼

### 진단 SQL (사장님 phpMyAdmin)
```sql
-- 최근 통화 §7 응답 시간 + 전체 처리 시간
SELECT id, status, response_elapsed_ms,
       TIMESTAMPDIFF(MICROSECOND, created_at, updated_at)/1000 AS total_ms,
       LEFT(error_message, 200) AS err,
       created_at, updated_at
FROM recording_jobs WHERE owner_email='nxnxax@gmail.com'
ORDER BY created_at DESC LIMIT 3;
```

---

## 환경

- GitHub push 자율 (PAT `~/.git-credentials`)
- Railway 자동 재배포
- **사장님 호칭: 사장님**. "쉬세요" 절대 금지. "PoC" 대신 "테스트" 사용.

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
