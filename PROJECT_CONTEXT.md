# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-21 (KST 16:30 세션 종료) — **STT On-Demand + 미확인요약 시스템 완전 폐기. STT 자동 처리 복원. 모달 양식전송/요약보기 두 흐름 모두 사장님 PoC 정상.***

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
subscribe.html / billing.html / unreviewed.html (미확인 요약)

[공통 JS]
auth-shared.js  — Supabase + 헤더/footer/bottom-nav + 인증 + setUnreviewedCount badge
bridge.js       — RN WebView 브리지 (heartbeat 포함)
ledger-shared.js — 관리대장 공통 (deepSearchMatch 포함)

[PHP API — cafe24 webroot flat]
api/records.php           — 모든 CRUD + customer-log resource (trigger_summarize / confirm /
                             discard / summary_status / list_unreviewed / transcripts_by_phone /
                             admin_job_diag / send_to_group)
api/process-recording.php — 통화 audio 업로드. STT/LLM 자동 X (defer_summarize=true default).
api/recording-callback.php — Railway worker 결과 수신 (review_required 분기)
api/cron-process-jobs.php  — 매 5분 cron. failed_retryable retry (max=2)
api/job-status.php         — 앱 polling (7단계 auth fallback)
api/recording-audio.php    — HMAC signed audio URL (10분)
api/audio_cleanup.php      — 7일 cron cleanup (사장님 결정)
api/upload.php             — multipart audio 수신
api/billing_helpers.php / billing/* — 결제

[Railway worker]
worker/main.py  — Whisper + Claude + transcode_to_mp3 (m4a → mp3 통일)

[운영 문서]
PLAY_DATA_SAFETY.md — Play Console Data Safety raw 답안집
SEO: robots.txt, sitemap.xml
```

---

## 3. 현재 완성된 기능

### 인증
- Supabase + Google OAuth + 6중 race guard + bridge.js heartbeat
- `_runRefreshOnce` Promise.race + 12s timeout
- 7단계 auth header fallback (records / upload / process-recording / job-status)
- error_code 표준 6종: AUTH_EXPIRED / AUTH_INVALID / AUTH_REQUIRED / JOB_DUPLICATE / JOB_EXISTS / RETRYABLE_SERVER_ERROR

### CRM / HRM
- 조직도/계약자/고객 관리대장 (page_type) + AES-256-GCM 암호화
- 양식 빌더 (Phase 1~3, 8타입)
- 검색 강화: `deepSearchMatch` (object/array inner value 재귀 + NFC 정규화)
- 회차별 content 분할 + "대화내용 전문보기" 버튼 (customers 상세 모달)
- 단체 SMS + 잔액 카드

### 통화 녹취 — STT 자동 처리 흐름 (2026-05-21 최종 회귀)
**핵심 정책 — 사장님 결정 (KST 오후 비상 후)**: STT On-Demand + 미확인요약 시스템 완전 폐기.
통화 종료 시 자동 STT + customer_log INSERT + ledger mirror.

```
통화 종료 → /process-recording.php (sync 기본 mode)
   → recording_jobs INSERT (status='queued')
   → Railway worker /process 호출 (m4a → mp3 → Whisper → Claude)
   → 영맨 응답 hold (sync) 또는 callback (async)
   → customer_log INSERT (영맨이 wait 후 일괄 INSERT)
   → auto send_to_group mirror → ledger_records row 자동 생성
sync 응답: { ok, customer_log: {...}, plan: {...} }
async 응답: { status: 'queued', job_id, mode: 'async' } + callback 후 FCM
```

### 영맨 측 정합성 보장 (앱팀과 협의 §B-1~B-9)
- **sync (mode 누락 또는 'sync')**: STT/LLM 완료 후 customer_log row 포함 응답
- **async (mode='async')**: 즉시 202 응답 + callback 시점 customer_log INSERT + FCM
- **dedup**: client_request_id 24h (customer_log) + audio_sha256 영구 (async)
- **idempotency**: customer_log_send_to_group 의 (customer_log_id + group_id) 키
- **group_id fallback** (commit 37c3261): native 가 안 보내면 first customer group 자동
- **review_required 제거** (commit d978738): 미확인요약 폐기 결정 정합 — 항상 INSERT

### 폐기된 endpoint / 시스템 (사용 안 함)
- **unreviewed.html** (deleted 2026-05-21)
- **하단 nav "미확인 요약" 메뉴** (auth-shared.js 에서 신규양식 슬롯으로 복원)
- **list_unreviewed / trigger_summarize / confirm / discard / summary_status / preview**
  → 코드 자체는 records.php 에 잔존 (46e01c6 시점 복원). deprecated 가드 Phase 11c 후속
- **bridge.setUnreviewedCount API** (badge 없음)
- **recording_review_mode** 컬럼 (사용 안 함, schema 그대로)
- **recording_jobs.review_required** 컬럼 (사용 안 함, schema 그대로)

### 유지 endpoint
- **admin_job_diag** — admin only 진단 (사장님 콘솔에서 직접 호출)
- **customer_log_send_to_group** — auto mirror + worker token 우회 + 9개 키 fallback
- **transcripts_by_phone** — 회차별 전문 조회

### Play Console 대비 (2026-05-20 ship)
- account-delete 완전 — members + customer_log + recording_jobs + audio + Supabase auth.users
- privacy.html / terms.html 통화녹음/AI/Plus(19000)/Pro(39000)/자동결제 명시
- PLAY_DATA_SAFETY.md (raw 답안집)

### SEO (2026-05-20 ship)
- robots.txt + sitemap.xml
- index.html / Marketing.html / subscribe.html / 정책 페이지 — OG/Twitter/canonical/JSON-LD
- 로그인 후 페이지 — `noindex, nofollow`

### Footer (2026-05-21)
- 6줄 라벨 (회사명 / 대표 / 사업자등록번호 / 대표번호 1800-5743 / 주소 / 이메일)
- `<dl><dt><dd>` grid 110px×1fr — 모바일에서도 값 정렬 일관
- 영맨 로고 — `clip-path: inset(0 0 6px 0)` 으로 하단 흰 가로줄 노이즈 제거

---

## 4. 다음 세션 인계 (2026-05-21 KST 16:30 세션 종료 시점)

### ✅ 비상 안정화 완료
- 통화 종료 → 모달 → **"양식으로 전송" 직격** 흐름 사장님 PoC 정상 (commit d978738 이후)
- 통화 종료 → 모달 → **"요약보기" → 양식 전송** 흐름 정상
- 앱팀 R2 빌드 ship (race fix + state reset by client_request_id + Overlay single-owner lock)
- 영맨 review_required 분기 무력화 — 데이터 누락 0 보장

### 다음 세션 후속 작업 (낮은 우선순위, 비상 X)

**[C-2] 401/403 응답 표준화 + _auth_debug 필드 (redact 적용)**
- records.php 의 인증 미들웨어 검토 + error_code 표준 6종 보장
- 진단용 _auth_debug 필드 추가 (sensitive 정보 redact)
- 앱팀 환영. 비상 종료 후 진행.

**[C-3] deprecated endpoint Sunset/Deprecation HTTP header**
- list_unreviewed / trigger_summarize / discard / summary_status / preview / confirm
- Sunset: Wed, 01 Jul 2026 00:00:00 GMT
- 앱팀 다음 빌드에 cleanup 진행 예정

**[C-4] group_id fallback 강화**
- ledger_groups page_type='customer' row 없을 때 ensure_customer_log_default_group() 호출
- 앱팀이 이미 group_id 명시 전송하므로 fallback 의존 0. 안전망 강화.

**[§3 callback 순서] N번째 통화에 (N-1) row 먼저 INSERT 현상 진단** (낮은 우선)
- 증상: 사장님이 "한 박자 밀린다" 체감. 최종적으로 N, N-1 모두 들어옴 (데이터 손실 0)
- 추정: native HeadlessJsTask 직렬 처리 + outbox retry timing
- 영맨 확인 항목: recording_jobs 의 created_at 시간순 callback 순서 자연 보장 여부
  + audio_sha256/client_request_id 다른 두 row 의 Railway worker 동시성

**[Phase 9 후속] records.php dead code 약 700줄 cleanup**
- list_unreviewed/trigger_summarize/discard/summary_status/preview/confirm 분기 코드
- 46e01c6 복원 후 가드 사라진 상태. 사용자 영향은 없지만 코드 가독성 위함.

### 잔여 schema 정리 (후속)
- recording_jobs.review_required 컬럼 — 사용 안 함
- recording_jobs.audio_pending status — 사용 안 함
- members.recording_review_mode 컬럼 — 사용 안 함
- DB row cleanup migration SQL — migrations/2026-05-21_cleanup_unreviewed_system.sql 작성 완료 (실행 안 함)

### 기존 backlog (낮은 우선순위)
- admin recording_jobs 통계 대시보드
- Marketing.html "Whisper + Claude + 영업 framework" 자랑 콘텐츠
- AI 요약 두 모드 분기 (대화형 / 보고서식) — profile 라디오
- PortOne Webhook URL 등록 (사장님 직접)
- 정식 토스 키 발급 후 라이브 결제 검증
- card-builder UX
- PII backfill 스크립트
- forms 수식 inline help
- profile/admin 디자인 일관성 감사
- Supabase Email Template 한글화
- Phase 3 자체 Whisper 호스팅 (Modal)
- FCM 분기 (call_recorded_pending_review vs call_summary_ready)
- 사장님 계정 `recording_review_mode` 설정 UI (현재 default 'auto')

---

## 5. 배포 방식

- GitHub Actions → FTP (cafe24, SamKirkland/FTP-Deploy-Action)
- 클로드 직접 push 가능 (PAT `~/.git-credentials`, 90일 만료 Aug 17 2026)
- Railway: GitHub 연동, `worker/` 폴더 root, main push 자동 재배포
- 시크릿 (필수):
  `CAFE24_FTP_PASSWORD` / `YOUNGMAN_CRYPTO_KEY` / `SUPABASE_SERVICE_KEY`
  `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` / `NCP_CLOVA_*`
  `STT_PROVIDER=whisper` / `LLM_PROVIDER=anthropic`
  `RAILWAY_WORKER_URL` / `RECORDING_WORKER_TOKEN`
  `PORTONE_*` / `FIREBASE_SERVICE_ACCOUNT_JSON` / `AUDIO_CLEANUP_TOKEN`
- "배포/올려" 키워드 → 자율 push→trigger→verify
- 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)`
- 새 페이지 추가 → `deploy.yml` 의 `test -f` + `cp` 둘 다

---

## 6. Cafe24/PHP 관련 주의사항

- 🚫 **SSH/SCP 절대 금지** — silent drop. FTP only.
- 🚫 **cafe24 cron 미지원** — GitHub Actions schedule.
- 🚫 **cafe24 빈 POST body → 5xx HTML** — 1바이트 이상 필수.
- 🚫 **cafe24 ffmpeg 미설치** — m4a transcode 는 **Railway worker 강제**.
- 🚫 **dhlottery 직접 호출 금지** (IP 차단).
- 🚫 **`git add -A` 금지** — PII 누설 위험.
- 📁 Webroot flat layout. `api/sms/` → `deploy/sms/providers/` / `api/billing/` → `deploy/billing/`
- 🔐 `YOUNGMAN_CRYPTO_KEY` 분실 = 복호화 영구 불가
- 📡 PHP 30초 timeout → process-recording set_time_limit(300) + Railway 위임
- 📡 records.php `/auth/v1/user` 폴백 — sb_publishable_ asymmetric JWT
- 📡 db_config.php — `return [host, port, database, user, password]`
- 📊 PII 컬럼 폭 — 암호문 100~200 chars, VARCHAR(255)+
- 📊 Whisper 25MB 제한 + iPhone/Galaxy m4a codec 변종 거부 → **mp3 통일 변환 (Railway worker)**

---

## 7. 최근 수정한 파일 (commit 흐름)

```
# 2026-05-21 후반 — STT 자동 처리 회귀 + 미확인요약 완전 폐기 + 비상 안정화
d978738 fix(stt): review_required 분기 제거 — 미확인요약 폐기 결정 정합
37c3261 fix(stt): native group_id 미명시 fallback — first customer group 자동
bf65d09 revert(stt): STT 3개 파일 5-20 19:34 UTC (46e01c6) 시점 복원
bbf7607 chore(stt): 미확인요약 시스템 완전 폐기 — Phase 8 (a-e)
e352c1c fix(stt): 미확인요약 시스템 폐기 + STT 자동 처리 복원
f2a3dec fix(stt-architecture): 근본 구조 전환 — INSERT/AI 분리 (ChatGPT 권장)
57ece74 feat(diag): list_unreviewed status 확장 + error_message/retry_count 노출
8f4899d fix(stt-process-recording): native group_id 미명시 시 first customer group 자동 + 즉시 STT
e03ff21 fix(stt-confirm): native group_id 미명시 케이스 대응 — first customer group default
fa5b4e8 fix(stt-on-demand): "양식으로 전송" 데이터 누락 비상 — callback 단일책임 구조

# 2026-05-20 후반 — STT On-Demand 도입 (5-21 비상 후 폐기)
4256cbd feat(stt): summary_status polling endpoint + 응답 일관성 (native NaN/null 방지)
967bcf3 fix(footer): 영맨 로고 하단 가로줄 노이즈 제거 (clip-path inset)
a14cc2c fix(footer): 영맨 로고 복원 — height 30px 노이즈 회피
1738992 fix(confirm): customer_log INSERT 후 자동 send_to_group + '전송중' 메시지
90b42f9 fix(stt-on-demand): Railway worker 직접 호출 — cafe24 자체 STT 우회
28574d9 fix(stt): 양식전송/요약보기 fail + footer 정렬
1c76eb5 fix(footer): 모바일 잔재 미디어쿼리 제거 (display:block override 충돌)
b30593c fix(footer): 모바일 정렬 깨짐 — 라벨/값 가로 flex + 라벨 width 고정
4552879 feat(footer + nav): 푸터 6줄 + 대표번호 + 미확인요약 빨간 badge
4098fca feat(nav): 미확인 요약 메뉴 → native deep link
65580b7 feat(unreviewed): discard action
779fadf feat(stt-on-demand) Phase 2: UI + audio 7일 + privacy 갱신
893881c feat(stt-on-demand): STT 자동 실행 제거 — 사용자 액션 시에만
46e01c6 fix(job-status): 401 인증 불일치 — 7단계 fallback
89a669b feat(admin): admin_job_diag endpoint
2198a95 feat(pending-review): group_id 누락 / pending_review=true → ready_to_review
f9674f9 fix(send_to_group): body parsing + group_id 9개 키 fallback + _send_debug
59f87e4 fix(autosubmit): group_id 자동 mirror 흐름 완성
ab7db7e fix(send_to_group): 다른 그룹 선택 시 데이터 안 들어가는 문제
ebd3a67 fix(unreviewed): list_unreviewed 503 — schema lazy ALTER
28f5133 fix(unreviewed): 상단 헤더 + 하단 nav 누락
89a7bf4 feat(nav): 미확인 요약 메뉴 — 신규양식 슬롯 2개 → 1개
fe314f7 feat(seo): SEO 최적화 — meta/og/twitter + sitemap.xml + robots.txt + JSON-LD
3ab5b17 feat(play-policy): Play Console 등록 대비 — account-delete e2e + privacy/terms + Data Safety
915f3bd feat(crm): 회차별 대화내용 전문보기 버튼 — customers 상세 모달
15d3f24 feat(search): 양식폼/관리대장 검색 — 전체 항목 재귀 매칭 강화
53f798a feat(recording): 앱팀 2차 긴급 요청 — 무한 재시도/모달 반복 차단
26e24af feat(app-bridge): 앱팀 1순위 5종 풀스택
```

**미커밋**: `SMS_USER_GUIDE.txt` (untracked, 무시)

---

## 8. 절대 건드리면 안 되는 부분

### 인증
- 🔒 6중 race guard 풀스택 — 하나라도 빠지면 토큰 invalidate 재발
- 🔒 7단계 auth header fallback (records / upload / process-recording / job-status)
- 🔒 `window.supabase` 글로벌 + `_runRefreshOnce` cooldown 25s + timeout 12s
- 🔒 records.php `/auth/v1/user` 폴백
- 🔒 PII owner_email 격리 — 모든 SELECT/UPDATE/DELETE 강제

### STT On-Demand 흐름 (2026-05-21)
- 🔒 **process-recording.php default = audio_pending** — STT 자동 X
- 🔒 **defer_summarize=true** default. `false` 명시 = cron retry / internal worker 만
- 🔒 **records.php trigger_summarize / confirm** → Railway worker /process 직접 호출 (cafe24 자체 STT 우회). cafe24 ffmpeg 미설치라 m4a Whisper 400 거부.
- 🔒 **recording-callback.php review_required 분기** — review_required=1 면 ready_to_review + summary_json_encrypted 저장 (customer_log INSERT X). 0 면 자동 INSERT + send_to_group
- 🔒 **summary_status endpoint** — 빠른 row 조회만. long-running 금지.
- 🔒 **응답 detail 필드 일관성** — 모든 분기에 customer_name / summary / duration_sec / recorded_at / phone_number 포함 (native NaN 방지)
- 🔒 **audio_pending status** — cron 안 잡음 (WHERE = queued/failed_retryable)
- 🔒 **Railway worker transcode_to_mp3** — m4a → mp3 통일. 갤럭시/iPhone codec 변종 대응
- 🔒 **JSON 파싱 4단 fallback** — 직접 / markdown / brace counting / repair
- 🔒 **Claude Sonnet 4.x prefill 금지** — system prompt + fallback parsing 으로만

### 결제
- 🔒 plan_default_summary_limit_minutes — Free=30/Plus=300/Pro=1000
- 🔒 overage_top_up — 5000원/71분/70원per분
- 🔒 PortOne V2 + 토스 — subscribe.html 의 `requestIssueBillingKey`

### 일반
- 🔒 YOUNGMAN 브랜드 — `logo_main.png` + seal-red `#c8362c`
- 🔒 cron-process-jobs max_retry=2
- 🔒 audio_cleanup 7일 (사장님 결정)
- 🔒 ledger UX — 헤더 클릭 필터 / 행 추가 모달 / accordion
- 🔒 카드 expanded 상태 보존 — `_expandedRowIds` + MutationObserver
- 🔒 records.php selfAuthResources — `['customer-log', 'app-fcm-token', 'recording-job']`
- 🔒 forms 사용 모드 = accordion-card

---

## 9. 다음에 이어서 해야 할 작업

### 1순위 (사장님 PoC 막힘 — 자고 일어나서 이어서)
1. **사장님 admin_job_diag 호출 받기** — 최근 PoC job_id 들의 status / error_message 확인
   ```js
   // 사장님 콘솔에서 paste
   (async () => {
     const sess = (await window.supabase.auth.getSession()).data.session;
     const t = sess?.access_token;
     const r = await fetch('/records.php?resource=customer-log&action=admin_job_diag', {
       method: 'POST',
       headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + t },
       body: JSON.stringify({ job_ids: ['<최근 job_id 들>'] })
     });
     console.log(JSON.stringify(await r.json(), null, 2));
   })();
   ```
2. **Railway worker dashboard log 확인** — 사장님이 Railway 대시보드에서 mp3 transcode 작동 여부 (transcode_to_mp3 성공 로그 / Whisper 처리 시간 / callback 응답)
3. **summary_status 라이브 검증** — `curl -sk "https://youngman-biz.com/records.php?resource=customer-log&action=summary_status&job_id=xxx"` (인증 필요하지만 401 OK)
4. **앱팀 협의** — native crash 원인. 영맨 응답은 항상 200 JSON 이라 native 측 fetch/parsing 문제. 이미 영맨 전달 문서 ship (위 commit 4256cbd 응답 spec).
5. **confirm 의 자동 send_to_group 검증** — 사장님 양식 전송 후 평택 그룹에 실제 row 들어가는지 확인.

### 2순위 (앱팀 협의 후)
- native polling 흐름 구현 — summary_status 5초마다 호출
- PROCESSING 분기 처리 — 처리 중 UI + 재호출
- detail 필드 안전 접근 — null safety + try/catch fetch

### 3순위 (기능 추가)
- admin recording_jobs 통계 대시보드
- AI 요약 두 모드 분기 (대화형 vs 보고서식, profile 라디오)
- profile.html `recording_review_mode` 토글 UI

---

## 자가 진단 채널

- `sessionStorage.erp.ensureError` — members 보강 실패
- `sessionStorage.erp.memberEnsured = '1'` — 보강 성공
- 콘솔 prefix: `[auth submit]` / `[google oauth]` / `[bridge]` / `[process-recording]` / `[recording-callback]` / `[records list_unreviewed]` / `[trigger_summarize]` / `[confirm send_to_group]` / `[send_to_group]` / `[fcm]`
- 브리지: `window.YoungmanBridge.isInApp()` / `.refreshSession()` / `.setUnreviewedCount(N)` / `.sendHeartbeat()`
- Railway log: Railway dashboard → Deployments → Logs
- recording_jobs row 진단: admin_job_diag endpoint (admin only)

## 환경

- GitHub push 자율 (PAT `~/.git-credentials`)
- Railway 자동 재배포
- **사장님 호칭: 사장님**. "쉬세요" 절대 금지.

## 메모리 참조 (`~/.claude/projects/-home-user-jdhoon/memory/`)

- `MEMORY.md` — 인덱스
- `feedback_auth_flow_lessons.md` — 인증 root cause
- `feedback_claude_prefill.md` — Sonnet 4.x prefill 금지
- `feedback_css_edit_sanity.md` — 큰 Edit 후 brace balance
- `feedback_deploy_autonomy.md` — 배포 자율
- `feedback_no_proceed_prompts.md` — "proceed?" 묻지 말 것
- `feedback_no_rest_suggestions.md` — 휴식 권유 절대 금지
- `feedback_pii_isolation.md` — PII owner_email 강제
- `feedback_readability_first.md` — 60대+ 가독성 우선
- `feedback_ledger_ux.md` — 헤더 필터 / 행 추가 모달
- `project_app_bridge.md` — RN WebView 앱
- `project_pii_crypto.md` — AES-256-GCM 라이브
- `project_ledger_system.md` — page_type 기반
- `project_whisper_claude_quality.md` — Sonnet 4.6 production
- `project_poc_success_complete.md` — 2026-05-19~20 PoC
- `deploy_cafe24.md` — FTP only
