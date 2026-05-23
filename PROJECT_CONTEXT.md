# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-24 AM 세션 — ✅ **양식으로 전송 STT 누락 fix** (phone_lookup HMAC 통일) + **고객관리대장 처리중 placeholder 부활** + **고객 거주지 자동 인식** (Claude region 추출).*

---

## 1. 사이트 목적

**YOUNGMAN / 영맨** — 1인 사업자용 AI 영업 플랫폼.
- 슬로건: "단 한 건의 고객정보 누락 없이 관리"
- CRM(고객) / HRM(조직도·계약자) / 마케팅 / 로또 / 단체 SMS / **AI 통화 요약 → 미확인 요약 → 고객관리대장 전송**
- 디자인: 한국 캘리그라피 + 인장(seal-red `#c8362c`), Apple/Linear 미니멀
- 라이브: https://youngman-biz.com (Cafe24 + Supabase + MariaDB + PHP)
- 결제: PortOne V2 + 토스페이먼츠 정기결제
- 앱: RN Android WebView + bridge.js (v49 active, audio 업로드 v40+ 필요)
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
unreviewed.html  ← 2026-05-23 부활 (lazy-STT 카드 UI + 체크박스 일괄 동작)

[공통 JS]
auth-shared.js  — Supabase + 헤더/footer/bottom-nav + 인증 + placeholder masker
                  + 앱 nav 에 "미확인 요약" 항목 추가 (window.YoungmanBridge.setUnreviewedCount)
bridge.js       — RN WebView 브리지 (heartbeat 포함)
ledger-shared.js — 관리대장 공통

[PHP API — cafe24 webroot flat]
api/records.php           — CRUD + customer_log_send_to_group + customer_log_cancel
                            + list_unreviewed / trigger_summarize / discard / preview / confirm / summary_status
api/process-recording.php — 통화 audio 업로드. lazy-STT 모드 (status='audio_pending')
                            + placeholder customer_log / mirror / Railway dispatch 모두 제거
api/recording-callback.php — Railway worker 결과 수신 + auto_confirm 자동 confirm 분기
                            + send_to_group mirror 실패 시 customer_log DELETE fallback
api/cron-process-jobs.php  — 5분 cron + queued/failed_retryable/processing(10분 stuck) 처리
                            (audio_pending 은 자동 처리 안 함 — lazy-STT)
api/job-status.php         — 앱 polling (audio_pending status label + result_url 분기)
api/recording-audio.php    — HMAC signed audio URL (10분)
api/audio_cleanup.php      — 7일 cron cleanup (audio_pending/failed_retryable storage_path 영구 보존)
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
- Google 로그인 race fix (73b7f20) — supabaseClient 미준비면 버튼 disabled
- OAuth 후 헤더 깜빡임 fix (02c3270) — localStorage sb-*-auth-token fallback

### CRM / HRM
- 조직도/계약자/고객 관리대장 + AES-256-GCM 암호화
- 양식 빌더 (Phase 1~3, 8타입)
- 회차별 content 분할 + "대화내용 전문보기" 버튼
- 단체 SMS + 잔액 카드

### 통화 녹취 — lazy-STT 모드 (2026-05-24 양식으로 전송 placeholder 부활)
```
통화 종료 → /process-recording.php
  → recording_jobs INSERT (status='audio_pending', review_required=1)
  → audio 저장 + 즉시 응답
  → placeholder customer_log / mirror / Railway dispatch 모두 안 함 (process-recording 시점 lazy 유지)

사용자가 미확인 요약 페이지 또는 통화 종료 모달에서:
  ① "요약보기" 누름 (auto_confirm=0)
     → trigger_summarize(auto_confirm=false) → status='queued' → Railway dispatch
     → STT 완료 callback → status='ready_to_review' + summary_json_encrypted 저장
     → 사용자 클릭 → preview → 모달에서 결과 표시
     → "고객관리대장 전송" → confirm → customer_log INSERT + send_to_group mirror

  ② "양식으로 전송" 누름 (auto_confirm=1) ★ 2026-05-24 placeholder 부활
     → trigger_summarize 즉시 placeholder customer_log INSERT (source='app-processing',
        summary='(AI 요약 처리 중...)', transcript=NULL) + ledger mirror
     → 응답에 customer_log_id 포함 (native v40+ 가 모달 즉시 닫기 결정에 활용)
     → 사용자 고객관리대장 보면 "(AI 요약 처리 중...)" 회차 카드 (회색 + 깜박임)
     → Railway STT 완료 → callback §7 분기 (customer_log_id 있음) → customer_log UPDATE
     → ledger refresh (refresh=true) → 회차 content 갱신 + region 자동 매핑
     → 5초 polling 으로 customers.js 자동 갱신 → 실제 요약으로 표시
     → 실패 시 → customer_log DELETE + status='ready_to_review' fallback (미확인 요약 복원)

  ③ "폐기" 또는 모달 "취소" → discard → recording_jobs DELETE + audio unlink

추가 안전망:
  · STT partial fail 감지 — duration≥20s + transcript<10chars 면 ready_to_review fallback
  · callback UPDATE 분기 COALESCE NULLIF 보호 (두 번째 callback 빈 값 덮어쓰기 방지)
  · phone_lookup HMAC-SHA256 통일 (callback INSERT 와 records.php 동일 함수)
```

### Claude 추출 필드 (2026-05-24 region 추가)
- customer_name / summary / interest / inquiry / budget_condition / next_action / **region** / transcript
- region: 고객 본인 현재 거주지만 추출. 모델하우스/매장/행선지/본가/직장은 제외.
  · "수원에 사는데요 모델하우스가 분당" → "수원" 만
  · 명확하지 않으면 null
- customer_log.region 컬럼 (AES-256-GCM 암호문) lazy migration
- ledger send_to_group 에서 group field_schema 의 label="지역" 또는 key="region" 자동 매칭 → 사용자 그룹 schema 가변적이어도 작동

### 미확인 요약 UI (unreviewed.html, 2026-05-23 부활)
- 카드 layout: 좌측 (고객명/전화번호·통화시간/날짜) + 우측 (요약보기/양식으로 전송 버튼)
- 카드 상태별 버튼 사이클:
  · audio_pending: [요약보기] [양식으로 전송]
  · queued/processing(auto_confirm=0): [요약중...(spinner)] [양식으로 전송]
  · queued/processing(auto_confirm=1): [요약보기(disabled)] [전송 중...(spinner)]
  · ready_to_review: [✓요약완료(녹색)] [고객관리대장 전송]
  · failed_retryable: [재시도] [폐기]
  · failed_permanent: [폐기]
- 날짜 구분선 (오늘 / 어제 / N월 N일)
- 5초 polling (queued/processing 카드 있을 때만)
- 체크박스 + 전체선택 + 1개+ 선택 시 [선택 삭제] / [양식으로 전송] 인라인 버튼 활성화
- 낙관적 UI — confirm/discard 시 카드 즉시 DOM 제거 (load() 백그라운드 정합성)

### 앱 하단 nav (auth-shared.js)
- 앱(isInApp()=true): [홈/고객관리대장/**미확인 요약**/설정] 4탭
- 웹: [홈/고객관리대장/신규양식 슬롯1/신규양식 슬롯2] 4탭
- "미확인 요약" href = `unreviewed.html` (deep link 아닌 web URL — v38 fallback)
- badge: `window.YoungmanBridge.setUnreviewedCount(n)` + localStorage 캐시
- 앱이 native UnreviewedSummariesScreen 사용 시 WebView URL intercept 가능

### API endpoint 일관성 (앱팀 v46/v49 요청)
- trigger_summarize / preview / summary_status 응답에 ok + processing 필드 명시
  · ok=true && processing=true → polling 계속
  · ok=true && processing=false → 결과 표시 (또는 폐기/완료)
  · ok=false → 에러 표시
- summary_status 경량 endpoint 신설 (summary 필드 제외, polling 부하 절감)
- dispatch_error 필드 — env_file_missing / RAILWAY_WORKER_URL_missing /
  RECORDING_WORKER_TOKEN_missing / http_xxx / exception

### 결제 / 기타 (유지)
- PortOne V2 + 토스 정기결제
- plan_default_summary_limit_minutes — Free=30/Plus=300/Pro=1000
- overage_top_up — 5000원/71분/70원per분

---

## 4. 아직 미완성 (다음 세션 작업)

### ⏳ 1순위 — 2026-05-24 3개 fix 검증
사장님 다음 통화 테스트 대기:
1. **양식으로 전송 STT 누락 fix** (commit `6d21674`) — phone_lookup HMAC 통일.
   "전문보기" 모달이 실제 STT transcript 표시되어야 함 (기존: "저장되어 있지 않습니다").
2. **고객관리대장 처리중 placeholder** (commit `d4a6d70`) — 양식으로 전송 후 고객관리대장에
   "(AI 요약 처리 중...)" 회차 카드 (회색 + 깜박임) 표시 → 5초 polling 으로 자동 갱신.
   ⚠️ native 모달 즉시 닫기는 앱팀 v40+ 작업 필요 (영맨 단독 X).
3. **지역 자동 인식** (commit `9c2a080`) — 통화 중 "평택에 거주중" 같은 문장 →
   고객관리대장 "지역" 컬럼에 "평택" 자동 입력.
   · 모델하우스/행선지/본가/직장 등은 추출 안 함 (Claude 문맥 파악).
   · 옛 통화 데이터는 재처리 안 함. 새 통화부터 적용.

### ⏳ 앱팀 v40+ 작업 (영맨 진단 완료)

**(A) 모달 자동 종료 시 audio 업로드 누락**
- 사장님 요구사항: "통화 종료 → 모달 → 대기시간 후 자동 종료 → 미확인 요약에 데이터 보관"
- 현재 native v49 는 모달 자동 종료 시 process-recording 호출 안 함 → 영맨에 audio 안 도착 → 미확인 요약 누락
- 앱팀 v40+ 에서 모달 자동 종료 시점에도 audio 업로드 호출 필요

**(B) UnreviewedPreview native screen 하단 버튼 SafeArea 누락**
- 폰 네비게이션 바와 [요약 폐기]/[고객관리대장 전송] 버튼 겹침
- SafeAreaView 또는 bottom padding (env(safe-area-inset-bottom) + 12pt) 추가

**(C) 통화 종료 직후 audio 부분 업로드 의심**
- 사장님이 "양식으로 전송" 바로 누르면 native 가 audio buffer 의 시작 부분만 flush + 업로드 → 후반부 누락 가능성
- 사장님이 14초 audio + "아 예 여보세요? 아 네 그렇습니다." transcript 확인
- 영맨이 audio 길이 검증 fix (STT partial fail 감지) 적용했지만 근본 원인은 native audio capture timing
- 앱팀 작업: "양식으로 전송" 버튼 활성화를 audio fully written + uploaded 후로 늦춤

### ⚠️ 보안 마무리 (사장님 작업 필수)
1. **RECORDING_WORKER_TOKEN rotate** — 진단 중 screenshot 노출 + hash prefix 노출됨
   - Railway Variables → 새 token 생성 (랜덤 64+자, **따옴표 없이**)
   - cafe24 .env 동일 새 값 (FileZilla 파일 닫고 업로드 — lock 시 PHP 못 읽음)
   - GitHub Secrets 도 동기화
2. **cafe24 webroot 의 admin_env_diag.php FTP 직접 삭제** — git 에서는 제거됐지만 (commit 5d0d0bd) deploy mirror 안 됐을 수 있음

### 기존 backlog (낮은 우선순위)
- AI 요약 두 모드 분기 (대화형 vs 보고서식)
- PortOne Webhook URL 등록 + 정식 토스 키 발급 후 라이브 결제 검증
- card-builder UX / forms 수식 inline help / profile/admin 디자인 일관성
- records.php dead code cleanup (700줄 — Phase 9)
- schema 정리 (review_required / recording_review_mode 컬럼 — 이제 lazy-STT 부활로 유지)
- GitHub Actions cron 7시간 간격 (비상 2번 — process-jobs.yml schedule)
- 진단 컬럼 cleanup (response_elapsed_ms — 모니터링 필요 없으면 ALTER TABLE DROP)

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
- 앱팀 새 빌드 시 같은 경로 덮어쓰기 (앱 내 URL 변경 X)

---

## 6. Cafe24/PHP 관련 주의사항

- 🚫 **SSH/SCP 절대 금지** — silent drop. FTP only.
- 🚫 **cafe24 cron 미지원** — GitHub Actions schedule.
- 🚫 **cafe24 빈 POST body → 5xx HTML** — 1바이트 이상 필수.
- 🚫 **cafe24 ffmpeg 미설치** — m4a transcode 는 Railway worker 강제.
- 🚫 **cafe24 PHP-FPM opcache** — modified time 무시 가능성. `opcache_invalidate()` 또는 reset 필요할 때 있음.
- 🚫 **cafe24 .env 파일 lock** — FileZilla 등에서 열어둔 상태로 업로드 시 PHP 가 새 내용 못 읽음.
- 🚫 **cafe24 .env 는 매 deploy 마다 GitHub Secrets 로부터 재생성됨** — FTP 직접 수정값은 다음 push 시 덮어쓰여짐.
- 🚫 **dhlottery 직접 호출 금지** (IP 차단).
- 🚫 **`git add -A` 금지** — PII 누설 위험.
- 🔑 **.env 값에 따옴표 절대 금지** (2026-05-23 학습)
  - `RECORDING_WORKER_TOKEN="abc"` 처럼 따옴표 있으면 일부 PHP 함수가 strip 안 함 →
    internal HTTP 호출 시 token mismatch → 401 → send_to_group 100% 실패
  - 모든 .env parsing 코드는 `trim($v, "\"' \t\r\n")` 패턴 사용
- 📁 Webroot flat layout. `api/sms/` → `deploy/sms/providers/` / `api/billing/` → `deploy/billing/`
- 🔐 `YOUNGMAN_CRYPTO_KEY` 분실 = 복호화 영구 불가
- 📡 PHP 30초 timeout → process-recording set_time_limit(300) + Railway 위임
- 📡 records.php `/auth/v1/user` 폴백 — sb_publishable_ asymmetric JWT
- 📡 db_config.php — `return [host, port, database, user, password]`
- 📊 PII 컬럼 폭 — 암호문 100~200 chars, VARCHAR(255)+
- 📊 Whisper 25MB 제한 + iPhone/Galaxy m4a codec 변종 거부 → **mp3 통일 변환 (Railway worker main.py:565 transcode_to_mp3)**
- 📊 Authorization 헤더 fallback 7단계 (records.php read_authorization_header)

### Railway worker quirks
- 🚫 `railway.json` 의 `startCommand` 가 Dockerfile 모드에서 shell expansion 안 됨 — Dockerfile CMD 의 `sh -c` wrap 사용.
- 🚫 Failed deployment 가 누적되어도 옛 Active 가 계속 traffic 받음 — dashboard 확인 습관.

---

## 7. 최근 수정한 파일 (commit 흐름)

```
# 2026-05-24 AM 세션 — 양식으로 전송 흐름 완성 (3개 fix)
9c2a080 feat(call): 통화 내용 고객 거주지 자동 인식 → 고객관리대장 "지역" ★ 신규 기능
d4a6d70 feat(call): 양식으로 전송 placeholder 부활 — 고객관리대장 처리중 카드 ★ UX 개선
6d21674 fix(call): 양식으로 전송 STT 전문 누락 root cause — phone_lookup HMAC 통일 ★ 핵심 FIX

# 2026-05-23 PM 세션 — 미확인 요약 부활 + 전송 실패율 fix
f32d8fc fix(callback): 전송 실패율 80~90% 근본 원인 — .env 따옴표 strip 누락 ★ 핵심 FIX
9d1d3ce fix(callback): STT 부분 실패 자동 감지 + UPDATE 분기 COALESCE 보호
9514045 fix(callback): auto_confirm mirror 실패 시 미확인 요약 자동 복원 (silent failure 방지)
76b2a83 fix(api): preview 응답 ok/processing 필드 명시 + summary_status 경량 endpoint 추가
f0b9524 fix(api): trigger_summarize 응답에 ok + processing 필드 명시 (앱팀 v46 요청)
fa4938b fix(unreviewed): confirm/discard 시 카드 즉시 DOM 제거 (낙관적 UI)
8314144 fix(unreviewed): 일괄 동작 버튼 — 전체선택 헤더 안 인라인 + 1개+ 활성화
db86c1f fix(unreviewed): trigger_summarize .env parsing + 카드 UX 재설계 + 체크박스 일괄 동작
7e82952 feat(unreviewed): 카드 UI 재설계 + 양식으로 전송 백그라운드 자동 confirm
aad194b fix(unreviewed): 미확인요약 시스템 전체 정합성 — lazy-STT 정책 fix 5건
32df40d feat(nav): 앱 하단 nav — 신규양식 슬롯 2개 → "미확인 요약" 1개 부활
8ccddf5 feat(call): 미확인 요약 부활 — lazy-STT 모드 + 앱팀 v39 연동 ★ 시스템 부활
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

### lazy-STT 모드 (2026-05-23 부활 / 2026-05-24 placeholder 부분 부활)
- 🔒 process-recording.php — status='audio_pending' INSERT, placeholder/mirror/dispatch 안 함 (process-recording 시점은 lazy 유지)
- 🔒 trigger_summarize endpoint — auto_confirm 파라미터 + Railway dispatch
- 🔒 **trigger_summarize(auto_confirm=1) 시점에 placeholder customer_log INSERT + ledger mirror** (2026-05-24)
  · source='app-processing' / summary='(AI 요약 처리 중...)' / transcript=NULL / region=NULL
  · 응답에 customer_log_id 포함 — native v40+ 모달 즉시 닫기 결정에 활용
- 🔒 recording-callback.php — §7 분기 (customer_log_id 있음) UPDATE + ledger refresh
- 🔒 callback UPDATE 분기 COALESCE NULLIF 보호 (region 포함, 두 번째 callback 빈 값 덮어쓰기 방지)
- 🔒 callback INSERT 분기 (auto_confirm=1) — customer_log INSERT + send_to_group + 실패 fallback
- 🔒 recording_jobs.auto_confirm 컬럼 (TINYINT NOT NULL DEFAULT 0)
- 🔒 cron-process-jobs.php — audio_pending 자동 처리 제외 (lazy)
- 🔒 audio_cleanup.php — audio_pending / failed_retryable storage_path 영구 보존
- 🔒 list_unreviewed query — customer_log_id IS NULL + status IN ('audio_pending','queued','processing','ready_to_review','failed_retryable','failed_permanent')
- 🔒 phone_lookup 함수 통일 — callback INSERT 도 records.php 의 customer_phone_lookup_key (HMAC-SHA256) 사용 (2026-05-24 root cause fix)

### 미확인 요약 UI (unreviewed.html)
- 🔒 카드 layout (좌측 info / 우측 버튼 2개 stack)
- 🔒 5초 polling (queued/processing 카드 있을 때만)
- 🔒 낙관적 UI — confirm/discard 시 카드 즉시 DOM 제거 (removeCardFromDom)
- 🔒 체크박스 + 전체선택 + 1개+ 선택 시 인라인 버튼 활성화
- 🔒 날짜 구분선 (오늘 / 어제 / N월 N일 (요일))

### 고객관리대장 처리중 placeholder (customers.js / style.css, 2026-05-24)
- 🔒 customers.js renderContentWithTranscriptButtons — placeholder 회차 ("(AI 요약 처리 중...)" 포함) 시각화
  · "전문보기" 버튼 숨김 (transcript 아직 NULL)
  · class="content-round-processing" → 회색 배경 + 좌측 깜박이는 빨간 막대
- 🔒 customers.js startProcessingPollIfNeeded — placeholder 있으면 5초 polling, page hidden 시 skip
- 🔒 style.css .content-round-processing — 깜박임 애니메이션 (opacity 0.85↔1.0, 1.6s)

### 지역 자동 인식 (2026-05-24)
- 🔒 worker/main.py CLAUDE_SYSTEM_PROMPT — JSON schema region 필드 + region 결정 규칙 섹션 (추출/제외/예시)
- 🔒 worker/main.py CallbackResult.region (Optional[str])
- 🔒 customer_log.region 컬럼 (VARCHAR 255, AES-256-GCM 암호문)
- 🔒 records.php find_region_field_key — label="지역" 또는 key="region"/"지역" 자동 매칭
- 🔒 send_to_group MERGE/INSERT/refresh 분기 모두 region 적용 (LLM 추출 시만 갱신, 못 추출하면 기존 유지)

### API 응답 일관성 (앱팀 v46/v49 spec)
- 🔒 trigger_summarize / preview / summary_status 응답에 ok + processing 필드 필수
- 🔒 dispatch_error 필드 진단

### 결제
- 🔒 plan_default_summary_limit_minutes — Free=30/Plus=300/Pro=1000
- 🔒 overage_top_up — 5000원/71분/70원per분
- 🔒 PortOne V2 + 토스 — subscribe.html 의 `requestIssueBillingKey`

### 일반
- 🔒 YOUNGMAN 브랜드 — `logo_main.png` + seal-red `#c8362c`
- 🔒 cron-process-jobs max_retry=2
- 🔒 audio_cleanup 7일 (audio_pending 제외)
- 🔒 ledger UX — 헤더 클릭 필터 / 행 추가 모달 / accordion
- 🔒 placeholder masker (auth-shared.js setupPlaceholderMasker) — MutationObserver 패턴
- 🔒 메인 hero CTA 임시 (베타 다운로드) — 결제사 승인 후 원복 예정
- 🔒 OG/Twitter image = og-thumbnail.png, favicon/logo = logo_main.png (역할 분리)

---

## 9. 다음에 이어서 해야 할 작업

### 1순위 — 2026-05-24 3개 fix 검증
사장님 다음 통화 테스트 후 확인:
1. **양식으로 전송 STT 전문** — "전문보기" 모달이 실제 transcript 표시 (commit 6d21674).
2. **고객관리대장 처리중 카드** — 양식으로 전송 후 "(AI 요약 처리 중...)" 회차 표시 + 자동 갱신 (commit d4a6d70).
3. **지역 자동 입력** — 통화 중 거주지 언급 시 "지역" 컬럼 자동 채워짐 (commit 9c2a080).

### 2순위 — 앱팀 v40+ 명세 (이미 정리됨, 사장님이 앱팀에 전달)
1. **"양식으로 전송" 모달 즉시 닫기** — trigger_summarize 응답 `auto_confirm=true` + `customer_log_id!=null` 이면 모달 닫고 토스트.
2. **"요약보기" 흐름은 그대로 유지** — 응답 `customer_log_id=null` 이므로 기존 분기 보존.
3. 통화 종료 모달 자동 종료 시 audio 업로드 누락 fix.
4. UnreviewedPreview native screen 하단 버튼 SafeArea 추가.
5. "양식으로 전송" 버튼 활성화를 audio fully written 후로 늦춤 (audio 일부만 업로드 방지).

### 3순위 — 보안 마무리 (사장님 작업)
1. RECORDING_WORKER_TOKEN rotate (3곳 동기화 — Railway Variables + cafe24 .env + GitHub Secrets, **따옴표 없이**)
2. cafe24 webroot 의 admin_env_diag.php FTP 직접 삭제

### 4순위 — 옛 통화 region backfill (사장님 결정 필요)
- 옛 customer_log 의 transcript 에서 Claude 로 region 만 재추출 + UPDATE
- LLM API 비용 발생 (사장님 옛 통화 개수 × Claude tokens)
- 사장님이 명시적으로 요청하면 진행

### 5순위 — Lottie 비서 애니메이션 (보류)
- 사장님이 lottiefiles.com 에서 secretary writing 무료 애니메이션 선택 → 영맨이 cafe24 호스팅 + 앱팀 lottie-react-native 통합

---

## 자가 진단 채널

- `sessionStorage.erp.ensureError` — members 보강 실패
- `sessionStorage.erp.memberEnsured = '1'` — 보강 성공
- 콘솔 prefix: `[auth submit]` / `[google oauth]` / `[bridge]` / `[process-recording]` / `[trigger_summarize]` / `[recording-callback]` / `[recording-callback auto_confirm]` / `[send_to_group]` / `[discard]` / `[confirm]` / `[fcm]`
- 브리지: `window.YoungmanBridge.isInApp()` / `.refreshSession()` / `.sendHeartbeat()` / `.setUnreviewedCount(n)`
- Railway log: Railway dashboard → Deployments → 가장 위 ACTIVE → Logs

### 진단 SQL (사장님 phpMyAdmin)
```sql
-- 최근 통화 lazy-STT 흐름 진단
SELECT id, status, customer_log_id, auto_confirm, duration_sec,
       audio_sha256, client_request_id, retry_count,
       LEFT(error_message, 300) AS err,
       TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec
FROM recording_jobs WHERE owner_email='nxnxax@gmail.com'
ORDER BY created_at DESC LIMIT 5;

-- mirror 실패 진단 (mirror_diag 포함)
SELECT id, status, SUBSTRING(error_message, 1, 800) AS err
FROM recording_jobs WHERE owner_email='nxnxax@gmail.com'
  AND error_message LIKE '%mirror%'
ORDER BY created_at DESC LIMIT 3;
```

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
