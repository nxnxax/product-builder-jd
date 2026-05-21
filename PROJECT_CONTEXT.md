# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-22 (KST 새벽 세션 종료) — **🚨 비상 진행 중: Whisper 400 Invalid file 무한 재발. Phase 18 fix 후도 동일 에러. cafe24 opcache 또는 코드 경로 의심. ChatGPT 진단 paste 메시지 작성 완료.***

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
auth-shared.js  — Supabase + 헤더/footer/bottom-nav + 인증
bridge.js       — RN WebView 브리지 (heartbeat 포함)
ledger-shared.js — 관리대장 공통

[PHP API — cafe24 webroot flat]
api/records.php           — 모든 CRUD + customer_log_send_to_group + refresh=true
api/process-recording.php — 통화 audio 업로드. §7 placeholder-first + cafe24 자체 STT fallback (현재 Whisper 400 무한 재발 ★)
api/recording-callback.php — Railway worker 결과 수신 + customer_log_id 있으면 UPDATE only
api/cron-process-jobs.php  — 5분 cron + processing stuck 10분 watchdog
api/job-status.php         — 앱 polling (7단계 auth fallback)
api/recording-audio.php    — HMAC signed audio URL (10분)
api/audio_cleanup.php      — 7일 cron cleanup
api/upload.php             — multipart audio 수신
api/billing_helpers.php / billing/* — 결제

[Railway worker]
worker/main.py  — Whisper + Claude + transcode_to_mp3

[베타 APK 호스팅]
tester.html → /download/youngman-latest.apk (사장님 FTP 직접 업로드)
.htaccess 에 .apk MIME + Content-Disposition

[운영 문서]
migrations/2026-05-21_cleanup_unreviewed_system.sql (사장님 실행 안 함)
```

---

## 3. 현재 완성된 기능

### 인증
- Supabase + Google OAuth + 6중 race guard + bridge.js heartbeat
- 7단계 auth header fallback
- error_code 표준 6종: AUTH_EXPIRED / AUTH_INVALID / AUTH_REQUIRED / JOB_DUPLICATE / JOB_EXISTS / RETRYABLE_SERVER_ERROR
- logout.html top-level `return` SyntaxError fix (commit 7507787)

### CRM / HRM
- 조직도/계약자/고객 관리대장 + AES-256-GCM 암호화
- 양식 빌더 (Phase 1~3, 8타입)
- 회차별 content 분할 + "대화내용 전문보기" 버튼
- 단체 SMS + 잔액 카드

### 통화 녹취 — §7 placeholder-first 흐름 (현재 ★ 깨진 상태)
```
통화 종료 → /process-recording.php
  → recording_jobs INSERT (status='queued')
  → placeholder customer_log INSERT (ai_model='pending', source='app-placeholder', summary='AI 분석 중')
  → recording_jobs.customer_log_id 즉시 UPDATE
  → auto send_to_group mirror (placeholder content)
  → 1~2초 응답 (customer_log placeholder 포함)
  → fastcgi_finish_request
  → background: Railway dispatch 또는 cafe24 자체 STT
  → callback 또는 직접 customer_log UPDATE
  → ledger refresh (latest section 만 교체, call_count 보존)
```

### Phase 8 미확인요약 시스템 폐기 (어제 결정)
- unreviewed.html 삭제 + 하단 nav 메뉴 제거
- records.php 의 list_unreviewed / trigger_summarize / confirm / discard / summary_status / preview deprecated 가드 (Phase 11 으로 dead code 처리)
- review_required 분기 무력화 (process-recording + recording-callback)

### 베타 APK 호스팅 (2026-05-22 ship)
- tester.html — Apple/Linear 미니멀 다운로드 안내
- /download/youngman-latest.apk (사장님 FTP 직접 업로드)
- .htaccess 에 application/vnd.android.package-archive MIME + Content-Disposition
- 향후 업데이트: 사장님이 같은 경로에 덮어쓰기 (URL 그대로 유지)

### 메인 hero CTA 임시 변경 (결제사 승인 전)
- "AI 통화 요약 + 고객관리 원터치 전송 서비스 신청" → "테스트기간 무료이벤트 다운로드 바로가기"
- href: subscribe.html → tester.html
- JS updateFlagshipCta() 의 임시 override 블록 (commit ca94374)
- 결제사 승인 후 원복 — 임시 블록 제거하면 plan 별 분기 자동 복원

---

## 4. 🚨 아직 미완성 + 비상 (다음 세션 최우선)

### ★★★ 비상 1 — Whisper 400 Invalid file 무한 재발 (진단 중)

**증상**: 사장님 통화 시 customer_log placeholder 정상 생성. 그러나 background STT 자체가 항상 실패. recording_jobs 모든 row 가 `failed_retryable` + error_message: "502: Whisper 400: {error:{message:Invalid fil..."

**적용 시도된 fix**:
- Phase 17 (commit 3bad4b3): Railway dispatch 결과를 error_message 에 기록 강화 → cafe24 자체 STT fallback catch 가 덮어쓰기로 효과 없음
- Phase 18 (commit 140d056): cafe24 자체 STT 의 `$sttProviderRequested = 'clova'` 강제 → 라이브 배포 OK 인데도 같은 Whisper 400 에러 (사장님 새 통화 5-22 04:03 검증)

**의심 원인 (확정 안 됨)**:
- A. cafe24 PHP-FPM opcode cache 가 옛 코드 캐싱 (Phase 18 변경 무효)
- B. 다른 코드 경로가 Whisper 호출 (grep 으로는 Line 1187 한 곳만)
- C. Railway worker (worker/main.py) 의 Whisper 호출 결과가 callback 통해 같은 message format

**다음 세션 첫 작업**:
1. ChatGPT 진단 paste 메시지 (사장님이 ChatGPT 에 paste 후 답변 받음) 결과 확인
2. cafe24 opcache 강제 reset 방법 시도 (파일 timestamp 변경 또는 .htaccess opcache_reset)
3. Whisper 호출 분기 통째 제거 시도 (`if (false)` 또는 Line 1187 분기 삭제)
4. 또는 Railway dispatch 자체 fix (Railway 정상 작동하면 Whisper + transcode 정상)

### 비상 2 — GitHub Actions cron 7시간 간격으로 안 돔
- process-jobs.yml schedule `*/5 * * * *` 인데 실제 run 간격이 7-12시간
- 결과: stuck row 자동 retry 안 됨
- 사장님 수동 트리거 권한 필요 (PAT 403)
- 다음 세션: GitHub repo Actions 설정 확인 + workflow_dispatch 수동 호출

### 비상 3 — Railway dispatch 실패 원인 미확정
- cafe24 자체 STT fallback 진입 = Railway dispatch 가 실패한 것은 확실
- 정확한 원인은 Phase 17 진단 메시지가 덮어쓰임 + opcache 의심으로 미확정
- GitHub Secrets RAILWAY_WORKER_URL / RECORDING_WORKER_TOKEN 확인 필요 (사장님 GitHub Settings)

### 기존 backlog (낮은 우선순위)
- 앱팀 §8 polling endpoint 의뢰 (job-status.php 응답 minimal version)
- records.php deprecated endpoint Sunset/Deprecation HTTP header
- 401/403 _auth_debug 필드 표준화 (redact 적용)
- records.php dead code 700줄 cleanup (미확인요약 폐기 잔재)
- schema 정리 (review_required / recording_review_mode 컬럼)
- callback 순서 보장 검토 (N→N-1 역전 현상)
- AI 요약 두 모드 분기 (대화형 vs 보고서식)
- PortOne Webhook URL 등록 + 정식 토스 키 발급 후 라이브 결제 검증
- card-builder UX / forms 수식 inline help / profile/admin 디자인 일관성

---

## 5. 배포 방식

- GitHub Actions → FTP (cafe24, SamKirkland/FTP-Deploy-Action)
- 클로드 직접 push 가능 (PAT `~/.git-credentials`, 90일 만료 Aug 17 2026)
- Railway: GitHub 연동, `worker/` 폴더 root, main push 자동 재배포
- 시크릿 (필수):
  `CAFE24_FTP_PASSWORD` / `YOUNGMAN_CRYPTO_KEY` / `SUPABASE_SERVICE_KEY`
  `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` / `NCP_CLOVA_*`
  `STT_PROVIDER=whisper` / `LLM_PROVIDER=anthropic`
  `RAILWAY_WORKER_URL` / `RECORDING_WORKER_TOKEN` (★ 사장님 확인 필요)
  `PORTONE_*` / `FIREBASE_SERVICE_ACCOUNT_JSON` / `AUDIO_CLEANUP_TOKEN`
- "배포/올림" 키워드 → 자율 push→trigger→verify
- 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)`
- 새 페이지 추가 → `deploy.yml` 의 `test -f` + `cp` 둘 다

### APK 호스팅 (2026-05-22)
- 사장님 FileZilla FTP 로 직접 업로드: `/download/youngman-latest.apk`
- 앱팀 새 빌드 시 같은 경로 덮어쓰기 (앱 내 URL 변경 X)

---

## 6. Cafe24/PHP 관련 주의사항

- 🚫 **SSH/SCP 절대 금지** — silent drop. FTP only.
- 🚫 **cafe24 cron 미지원** — GitHub Actions schedule (★ 현재 7시간 간격 깨짐).
- 🚫 **cafe24 빈 POST body → 5xx HTML** — 1바이트 이상 필수.
- 🚫 **cafe24 ffmpeg 미설치** — m4a transcode 는 **Railway worker 강제**.
- 🚫 **cafe24 PHP opcache** — modified time 무시 가능성. 새 코드 반영 안 될 수 있음 (★ 진단 중).
- 🚫 **dhlottery 직접 호출 금지** (IP 차단).
- 🚫 **`git add -A` 금지** — PII 누설 위험.
- 📁 Webroot flat layout. `api/sms/` → `deploy/sms/providers/` / `api/billing/` → `deploy/billing/`
- 🔐 `YOUNGMAN_CRYPTO_KEY` 분실 = 복호화 영구 불가
- 📡 PHP 30초 timeout → process-recording set_time_limit(300) + Railway 위임
- 📡 records.php `/auth/v1/user` 폴백 — sb_publishable_ asymmetric JWT
- 📡 db_config.php — `return [host, port, database, user, password]`
- 📊 PII 컬럼 폭 — 암호문 100~200 chars, VARCHAR(255)+
- 📊 Whisper 25MB 제한 + iPhone/Galaxy m4a codec 변종 거부 → **mp3 통일 변환 (Railway worker)**
- 📊 cafe24 자체 STT 은 Clova 만 사용 (Phase 18 결정) — Whisper 가 cafe24 환경에서 m4a 거부

---

## 7. 최근 수정한 파일 (commit 흐름)

```
# 2026-05-22 — Whisper 400 비상 진행 중
140d056 fix(stt): cafe24 자체 STT fallback Whisper → Clova 강제 ★ (적용됐는데 효과 없음)
3bad4b3 fix(stt §diag): Railway dispatch 실패 원인 DB 기록 강화
08cd6bd revert(stt): §7 placeholder-first + cron watchdog 재적용 (사장님 결정)
81602b1 revert(stt): §7 + cron watchdog 롤백 (사장님 분노 후 즉시 롤백)
4211e92 fix(cron §8): processing stuck watchdog 10분
57ecc6a fix(stt §7): placeholder-first 전환 — sync 응답 시간 7~60s → 1~2s

# 2026-05-22 — APK 호스팅 + 메인 CTA
ca94374 feat(beta): 메인 hero CTA 임시 → 베타 다운로드 (결제사 승인 전)
d17a798 feat(beta-apk): Android 베타 APK 다운로드 호스팅
7507787 hotfix(auth): logout.html module top-level 'return' SyntaxError → if/else 통합

# 2026-05-21 후반 (어제 세션 종료 commit)
0faba13 docs(context): 2026-05-21 세션 종료 갱신
d978738 fix(stt): review_required 분기 제거 — 미확인요약 폐기 결정 정합
37c3261 fix(stt): native group_id 미명시 fallback — first customer group 자동
bf65d09 revert(stt): STT 3개 파일 5-20 19:34 UTC (46e01c6) 시점 복원
bbf7607 chore(stt): 미확인요약 시스템 완전 폐기 — Phase 8 (a-e)
```

---

## 8. 절대 건드리면 안 되는 부분

### 인증
- 🔒 6중 race guard 풀스택
- 🔒 7단계 auth header fallback
- 🔒 `window.supabase` 글로벌 + `_runRefreshOnce` cooldown 25s + timeout 12s
- 🔒 records.php `/auth/v1/user` 폴백
- 🔒 PII owner_email 격리

### 통화 녹취 흐름 (2026-05-22 비상 진행 중)
- 🔒 §7 placeholder-first 응답 구조 (1~2초) — native v15 polling 의존
- 🔒 customer_log placeholder INSERT 시 source='app-placeholder', ai_model='pending'
- 🔒 customer_log.client_request_id 24h UNIQUE (dedup)
- 🔒 recording_jobs.audio_sha256 영구 dedup
- 🔒 review_required 분기 제거 상태 유지 (미확인요약 폐기)
- 🔒 Phase 18 의 `$sttProviderRequested = 'clova'` 강제 — Whisper 거부 회피 (★ 라이브 효력 미확정)
- 🔒 fastcgi_finish_request + ignore_user_abort + set_time_limit(300) — background 처리 보장 (cafe24 PHP-FPM 환경 의존)

### 결제
- 🔒 plan_default_summary_limit_minutes — Free=30/Plus=300/Pro=1000
- 🔒 overage_top_up — 5000원/71분/70원per분
- 🔒 PortOne V2 + 토스 — subscribe.html 의 `requestIssueBillingKey`

### 일반
- 🔒 YOUNGMAN 브랜드 — `logo_main.png` + seal-red `#c8362c`
- 🔒 cron-process-jobs max_retry=2
- 🔒 audio_cleanup 7일
- 🔒 ledger UX — 헤더 클릭 필터 / 행 추가 모달 / accordion
- 🔒 메뉴 신규양식 슬롯 (미확인요약 메뉴는 폐기 상태 유지)
- 🔒 메인 hero CTA 임시 (베타 다운로드) — 결제사 승인 후 원복 예정
- 🔒 APK 경로 `/download/youngman-latest.apk` (사장님 FTP 직접 업로드)

---

## 9. 다음 세션 우선 작업

### 1순위 — Whisper 400 무한 재발 비상 해결
1. **ChatGPT 진단 결과 확인** — 사장님이 paste 받은 답변 공유받기
2. **cafe24 opcache 무력화 시도**:
   - process-recording.php 에 임의 변경 (주석 한 줄) → push → timestamp 갱신 → opcache reset 유도
   - 또는 `.htaccess` 에 `php_flag opcache.enable Off` (cafe24 설정 허용 여부)
   - 또는 opcache_reset() 호출하는 admin endpoint 추가
3. **Whisper 호출 분기 통째 제거**:
   - process-recording.php Line 1187 `if ($sttProvider === 'whisper') {` → `if (false) {`
   - 또는 Whisper 분기 전체 삭제 (Line 1187-1300)
4. **Railway dispatch 정상 작동 복구**:
   - 사장님 GitHub Settings → Secrets 의 RAILWAY_WORKER_URL / RECORDING_WORKER_TOKEN 등록 여부 확인
   - Railway dashboard 의 worker 살아있는지 확인 (사장님 작업)
   - 영맨이 라이브 admin endpoint 추가하여 진단 가능하게

### 2순위 — GitHub Actions cron 정상화
1. https://github.com/nxnxax/product-builder-jd/actions/workflows/process-jobs.yml 확인
2. cron schedule 활성화 + 마지막 실행 시각 확인
3. inactive 면 manual trigger (사장님 작업 — Run workflow 버튼)
4. 영맨 PAT 가 workflow trigger 권한 없음 (403). 사장님 직접 트리거 필요

### 3순위 — 사장님 직접 확인 사항
1. cafe24 phpMyAdmin 다음 SQL 정기 확인 (디버깅용):
   ```
   SELECT id, status, retry_count, customer_log_id, 
          LEFT(error_message, 200) AS err_preview,
          created_at, updated_at 
   FROM recording_jobs 
   WHERE owner_email = 'nxnxax@gmail.com' 
   ORDER BY created_at DESC LIMIT 3;
   ```
2. GitHub Settings → Secrets 의 RAILWAY_WORKER_URL 등록 여부 캡처
3. Railway dashboard 로그 확인 (worker 살아있는지)

### 4순위 — 잔존 cleanup
- 앱팀 §8 polling endpoint 의뢰 답변 처리
- records.php dead code 700줄 cleanup
- schema 정리 (review_required / recording_review_mode 컬럼)
- ChatGPT 권장 §7 callback timing / FCM 흐름 검증

---

## 자가 진단 채널

- `sessionStorage.erp.ensureError` — members 보강 실패
- `sessionStorage.erp.memberEnsured = '1'` — 보강 성공
- 콘솔 prefix: `[auth submit]` / `[google oauth]` / `[bridge]` / `[process-recording]` / `[process-recording §7]` / `[process-recording §diag]` / `[recording-callback]` / `[records list_unreviewed]` / `[send_to_group]` / `[fcm]`
- 브리지: `window.YoungmanBridge.isInApp()` / `.refreshSession()` / `.sendHeartbeat()`
- Railway log: Railway dashboard → Deployments → Logs
- recording_jobs row 진단: admin_job_diag endpoint (admin only)

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
- `feedback_no_working_flow_break.md` — 작동 검증된 흐름은 사장님 동의 없이 변경 금지
- `feedback_terminology_test.md` — "PoC" → "테스트", "ship/deploy" → "배포/올림"
- `feedback_pii_isolation.md` — PII owner_email 강제
- `feedback_readability_first.md` — 60대+ 가독성 우선
- `feedback_ledger_ux.md` — 헤더 필터 / 행 추가 모달
- `project_app_bridge.md` — RN WebView 앱
- `project_pii_crypto.md` — AES-256-GCM 라이브
- `project_ledger_system.md` — page_type 기반
- `project_whisper_claude_quality.md` — Sonnet 4.6 production
- `deploy_cafe24.md` — FTP only
