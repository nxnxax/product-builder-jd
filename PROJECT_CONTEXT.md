# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-24 AM 세션 종료 — ✅ **양식으로 전송 STT 전문 누락 fix** (phone_lookup HMAC 통일) + **고객관리대장 처리중 placeholder 부활** + **고객 거주지 자동 인식** (Claude region 추출) + **미확인 요약 카드 UI 다듬기**.*

---

## 1. 사이트 목적

**YOUNGMAN / 영맨** — 1인 사업자용 AI 영업 플랫폼.
- 슬로건: "단 한 건의 고객정보 누락 없이 관리"
- CRM(고객) / HRM(조직도·계약자) / 마케팅 / 로또 / 단체 SMS / **AI 통화 요약 → 미확인 요약 → 고객관리대장 전송 (자동 지역 인식)**
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
unreviewed.html  ← lazy-STT 카드 UI + 체크박스 일괄 동작 + 2줄 라벨

[공통 JS]
auth-shared.js   — Supabase + 헤더/footer/bottom-nav + 인증 + placeholder masker
                   + 앱 nav 에 "미확인 요약" 항목 (window.YoungmanBridge.setUnreviewedCount)
bridge.js        — RN WebView 브리지 (heartbeat 포함)
ledger-shared.js — 관리대장 공통 (getEffectiveFields = default + server schema 합침)
customers.js     — 고객관리대장. DEFAULT_FIELDS 에 region 포함 (서버 schema 없어도 UI 표시)
                   + placeholder 회차 시각화 + 5초 polling

[PHP API — cafe24 webroot flat]
api/records.php            — CRUD + customer_log_send_to_group + transcripts_by_phone
                             + trigger_summarize (placeholder INSERT + ledger mirror)
                             + confirm / preview / discard / summary_status
                             + find_region_field_key + resolve_region_data_key (fallback)
api/process-recording.php  — 통화 audio 업로드. lazy-STT (status='audio_pending')
api/recording-callback.php — Railway worker 결과 수신
                             + auto_confirm 자동 confirm 분기 (region 포함)
                             + STT partial fail 감지 + send_to_group mirror fallback
                             + phone_lookup HMAC-SHA256 통일 (rc_phone_lookup_auto)
api/cron-process-jobs.php  — 5분 cron + queued/failed_retryable/processing(10분 stuck) 처리
api/job-status.php         — 앱 polling
api/recording-audio.php    — HMAC signed audio URL (10분)
api/audio_cleanup.php      — 7일 cron cleanup
api/upload.php             — multipart audio 수신
api/billing_helpers.php / billing/* — 결제

[Railway worker]
worker/Dockerfile  — python:3.11-slim + ffmpeg + uvicorn (sh -c CMD)
worker/main.py     — Whisper + Claude Sonnet 4.6 + transcode_to_mp3
                     + region 추출 (문맥 파악 — 거주지만)
worker/railway.json — DOCKERFILE builder

[Asset]
og-thumbnail.png / logo_main.png

[베타 APK 호스팅]
tester.html → /download/youngman-latest.apk (사장님 FTP 직접 업로드)
```

---

## 3. 현재 완성된 기능

### 인증
- Supabase + Google OAuth + 6중 race guard + bridge.js heartbeat
- 7단계 auth header fallback (`/auth/v1/user` 폴백 — sb_publishable_)
- Google 로그인 race fix + OAuth 후 헤더 깜빡임 fix

### CRM / HRM
- 조직도/계약자/고객 관리대장 + AES-256-GCM 암호화
- 양식 빌더 (Phase 1~3, 8타입)
- 회차별 content 분할 + "대화내용 전문보기" 버튼
- 단체 SMS + 잔액 카드
- **고객 거주지 자동 인식** (2026-05-24) — Claude 가 통화 transcript 에서 문맥 파악

### 통화 녹취 — lazy-STT 모드 (2026-05-24 양식으로 전송 placeholder 부활)
```
통화 종료 → /process-recording.php
  → recording_jobs INSERT (status='audio_pending')
  → audio 저장 + 즉시 응답 (process-recording 시점은 lazy — placeholder 안 만듦)

① "요약보기" (auto_confirm=0)
  → trigger_summarize → Railway dispatch → callback (status='ready_to_review')
  → 사용자 클릭 → preview 모달 → "고객관리대장 전송" → confirm
  → records.php confirm 분기에서 customer_log INSERT + send_to_group mirror

② "양식으로 전송" (auto_confirm=1) ★ 2026-05-24 placeholder 부활
  → trigger_summarize 즉시 placeholder customer_log INSERT
     (source='app-processing', summary='(AI 요약 처리 중...)')
  → ledger_records mirror (즉시 회차 카드 표시)
  → 응답에 customer_log_id 포함 (native v40+ 모달 즉시 닫기 결정에 활용)
  → 사장님 고객관리대장 → "(AI 요약 처리 중...)" 회차 카드 (회색+깜박임)
  → Railway STT 완료 → callback §7 분기 (customer_log_id 있음) UPDATE
  → ledger refresh (refresh=true) → 회차 content + region 갱신
  → 5초 polling 으로 customers.js 자동 갱신 → 실제 요약으로 표시
  → 실패 시: customer_log DELETE + status='ready_to_review' fallback

③ "폐기" / 모달 "취소" → discard → recording_jobs DELETE + audio unlink

안전망:
  · STT partial fail (duration≥20s + transcript<10chars) → ready_to_review fallback
  · callback UPDATE COALESCE NULLIF 보호 (region 포함, 빈 값 덮어쓰기 방지)
  · phone_lookup HMAC-SHA256 통일 (callback + records.php 동일)
```

### Claude 추출 필드 (2026-05-24 region 추가)
- customer_name / summary / interest / inquiry / budget_condition / next_action / **region** / transcript
- **region** = 고객 본인 현재 거주지만 (모델하우스/매장/행선지/본가/직장 제외)
  · "수원에 사는데요 모델하우스가 분당" → "수원" 만
  · 명확하지 않으면 null
- customer_log.region 컬럼 (AES-256-GCM 암호문) lazy migration

### 미확인 요약 UI (unreviewed.html)
- 카드 layout (좌측 info / 우측 버튼 2개 stack)
- **전화번호 / 통화시간 줄바꿈 분리** (2026-05-24)
- **"✓ 요약완료" 버튼 2줄** ("✓ 요약완료" + "내용확인하기", 2026-05-24)
- 5초 polling (queued/processing 카드 있을 때만)
- 낙관적 UI — confirm/discard 시 카드 즉시 DOM 제거
- 체크박스 + 전체선택 + 1개+ 선택 시 인라인 버튼 활성화
- 날짜 구분선 (오늘 / 어제 / N월 N일 (요일))

### 고객관리대장 처리중 placeholder (customers.js / style.css)
- placeholder 회차 ("(AI 요약 처리 중...)" 포함) 시각화
  · "전문보기" 버튼 숨김 (transcript NULL)
  · 회색 배경 + 좌측 깜박이는 빨간 막대 (cl-pulse 1.6s)
- startProcessingPollIfNeeded — placeholder 있으면 5초 polling, page hidden skip

### 지역 자동 매핑 (records.php)
- find_region_field_key — strict + loose 매칭 (label "지역" / key "region"/"area")
- resolve_region_data_key — schema 못 찾으면 'region' fallback (client DEFAULT_FIELDS 호환)
- send_to_group MERGE/INSERT/refresh 3분기 모두 fallback 적용
- customer_log_default_group_field_schema 에 region 필드 추가 (새 그룹 대비)

### API endpoint 일관성 (앱팀 v46/v49 spec)
- trigger_summarize / preview / summary_status 응답에 ok + processing 필드
- summary_status 경량 endpoint (polling 부하 절감)
- dispatch_error 필드 (env_file_missing / RAILWAY_WORKER_URL_missing / etc)

### 결제 / 기타
- PortOne V2 + 토스 정기결제
- plan_default_summary_limit_minutes — Free=30/Plus=300/Pro=1000
- overage_top_up — 5000원/71분/70원per분

---

## 4. 아직 미완성 (다음 세션 작업)

### ⏳ 1순위 — 2026-05-24 fix 누적 검증
사장님 다음 통화 테스트:
1. **양식으로 전송 STT 전문** (6d21674) — "전문보기" 모달이 실제 transcript 표시 ✅ 검증 완료
2. **고객관리대장 처리중 카드** (d4a6d70) — "(AI 요약 처리 중...)" 회차 + 자동 갱신
3. **지역 자동 입력** (21fffb9) — "지역" 컬럼 자동 채워짐 ✅ 검증 완료
4. **카드 UI** (6959a79, 79e2f1a) — 전화번호/통화시간 줄바꿈 + 요약완료 2줄

### ⏳ 2순위 — 앱팀 v40+ 명세 (이미 정리됨, 사장님이 앱팀에 전달)
1. **"양식으로 전송" 모달 즉시 닫기** — trigger_summarize 응답에 `auto_confirm=true` + `customer_log_id!=null` 보면 모달 닫고 토스트
2. **"요약보기" 흐름은 그대로 유지** — `customer_log_id=null` 이므로 기존 분기 보존
3. 통화 종료 모달 자동 종료 시 audio 업로드 누락 fix
4. UnreviewedPreview native screen 하단 버튼 SafeArea
5. "양식으로 전송" 버튼 활성화를 audio fully written 후로 늦춤

### ⚠️ 보안 마무리 (사장님 작업)
1. RECORDING_WORKER_TOKEN rotate (3곳 동기화 — Railway + cafe24 .env + GitHub Secrets, **따옴표 없이**)
2. cafe24 webroot 의 admin_env_diag.php FTP 직접 삭제

### 4순위 — 옛 통화 region backfill (사장님 결정 필요)
- 옛 customer_log 의 transcript 에서 Claude 로 region 재추출 + UPDATE
- LLM API 비용 발생 (사장님 옛 통화 개수 × Claude tokens)

### 기존 backlog (낮은 우선순위)
- AI 요약 두 모드 분기 (대화형 vs 보고서식)
- PortOne Webhook URL 등록 + 정식 토스 키 발급 후 라이브 결제 검증
- card-builder UX / forms 수식 inline help / profile/admin 디자인 일관성
- records.php dead code cleanup (700줄 — Phase 9)
- Lottie 비서 애니메이션 (사장님이 lottiefiles.com 에서 선택 후)

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
- 🔑 **.env 값에 따옴표 절대 금지** (2026-05-23 학습) — 일부 PHP 함수가 strip 안 함. 모든 .env parsing 은 `trim($v, "\"' \t\r\n")` 사용.
- 🔑 **phone_lookup 함수 통일 필수** (2026-05-24 학습) — callback + records.php 양쪽 `customer_phone_lookup_key` (HMAC-SHA256) 사용. 짧은 substr 형식과 다르면 lookup mismatch.
- 📁 Webroot flat layout. `api/sms/` → `deploy/sms/providers/` / `api/billing/` → `deploy/billing/`
- 🔐 `YOUNGMAN_CRYPTO_KEY` 분실 = 복호화 영구 불가
- 📡 PHP 30초 timeout → process-recording `set_time_limit(300)` + Railway 위임
- 📡 records.php `/auth/v1/user` 폴백 — sb_publishable_ asymmetric JWT
- 📡 db_config.php — `return [host, port, database, user, password]`
- 📊 PII 컬럼 폭 — 암호문 100~200 chars, VARCHAR(255)+
- 📊 Whisper 25MB 제한 + iPhone/Galaxy m4a codec 변종 거부 → **mp3 통일 변환** (worker main.py:565 transcode_to_mp3)
- 📊 Authorization 헤더 fallback 7단계 (records.php read_authorization_header)
- 🔑 **client DEFAULT_FIELDS vs server schema 비대칭 학습** (2026-05-24) — customers.js 의 DEFAULT_FIELDS 에 region 있는데 PHP default 에 없으면 UI 표시되지만 매핑 실패. send_to_group 는 'region' fallback key 사용.

### Railway worker quirks
- 🚫 `railway.json` 의 `startCommand` 가 Dockerfile 모드에서 shell expansion 안 됨 — Dockerfile CMD `sh -c` wrap.
- 🚫 Failed deployment 가 누적되어도 옛 Active 가 traffic 받음 — dashboard 확인 습관.

---

## 7. 최근 수정한 파일

```
# 2026-05-24 AM 세션 — 양식으로 전송 흐름 완성 + 지역 자동 인식 + UI 다듬기
79e2f1a fix(unreviewed): "요약완료" 버튼 2줄 ("✓ 요약완료" + "내용확인하기")
6959a79 fix(unreviewed): 카드 전화번호/통화시간 줄바꿈 분리
21fffb9 fix(call): region 매핑 root cause — server schema vs client DEFAULT_FIELDS 불일치 ★ 핵심 FIX
9c2a080 feat(call): 통화 내용 고객 거주지 자동 인식 → 고객관리대장 "지역" ★ 신규 기능
d4a6d70 feat(call): 양식으로 전송 placeholder 부활 → 고객관리대장 처리중 카드
6d21674 fix(call): 양식으로 전송 STT 전문 누락 root cause — phone_lookup HMAC 통일 ★ 핵심 FIX

# 2026-05-23 PM 세션 — 미확인 요약 부활 + 전송 실패율 fix
f32d8fc fix(callback): 전송 실패율 80~90% 근본 원인 — .env 따옴표 strip 누락
9d1d3ce fix(callback): STT 부분 실패 자동 감지 + COALESCE 보호
9514045 fix(callback): auto_confirm mirror 실패 시 미확인 요약 자동 복원
76b2a83 fix(api): preview 응답 ok/processing 필드 + summary_status 경량 endpoint
f0b9524 fix(api): trigger_summarize 응답 ok + processing 필드
fa4938b fix(unreviewed): confirm/discard 시 카드 즉시 DOM 제거
8314144 fix(unreviewed): 일괄 동작 버튼 — 전체선택 헤더 안 인라인
db86c1f fix(unreviewed): trigger_summarize .env parsing + 카드 UX
7e82952 feat(unreviewed): 카드 UI 재설계 + 양식으로 전송 백그라운드 자동 confirm
aad194b fix(unreviewed): 미확인 요약 시스템 전체 정합성 — lazy-STT 정책 fix
32df40d feat(nav): 앱 하단 nav — "미확인 요약" 부활
8ccddf5 feat(call): 미확인 요약 부활 — lazy-STT 모드 + 앱팀 v39 연동
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
  · source='app-processing' / summary='(AI 요약 처리 중...)' / transcript=NULL
  · 응답에 customer_log_id 포함 — native v40+ 모달 즉시 닫기 결정에 활용
- 🔒 recording-callback.php §7 분기 (customer_log_id 있음) UPDATE + ledger refresh
- 🔒 callback UPDATE COALESCE NULLIF 보호 (region 포함)
- 🔒 callback INSERT (auto_confirm=1) — customer_log INSERT + send_to_group + 실패 fallback
- 🔒 recording_jobs.auto_confirm 컬럼 (TINYINT NOT NULL DEFAULT 0)
- 🔒 cron-process-jobs.php — audio_pending 자동 처리 제외 (lazy)
- 🔒 audio_cleanup.php — audio_pending / failed_retryable storage_path 영구 보존
- 🔒 list_unreviewed query — customer_log_id IS NULL + status IN (...)
- 🔒 phone_lookup 함수 통일 — callback INSERT 도 customer_phone_lookup_key (HMAC-SHA256) 사용 (2026-05-24)

### 미확인 요약 UI (unreviewed.html)
- 🔒 카드 layout (좌측 info / 우측 버튼 2개 stack)
- 🔒 전화번호 / 통화시간 줄바꿈 분리 (.un-card-duration)
- 🔒 "✓ 요약완료" 버튼 2줄 (.done-multi .bl1/.bl2)
- 🔒 5초 polling (queued/processing 카드 있을 때만)
- 🔒 낙관적 UI — confirm/discard 시 카드 즉시 DOM 제거
- 🔒 체크박스 + 전체선택 + 인라인 버튼 활성화
- 🔒 날짜 구분선 (오늘 / 어제 / N월 N일 (요일))

### 고객관리대장 처리중 placeholder (customers.js / style.css)
- 🔒 renderContentWithTranscriptButtons — placeholder 회차 ("(AI 요약 처리 중...)") 시각화
  · "전문보기" 버튼 숨김 (transcript NULL)
  · class="content-round-processing" → 회색 + 좌측 빨간 깜박임 막대
- 🔒 startProcessingPollIfNeeded — placeholder 있으면 5초 polling, page hidden skip
- 🔒 style.css .content-round-processing — cl-pulse 1.6s animation

### 지역 자동 인식 (2026-05-24)
- 🔒 worker/main.py CLAUDE_SYSTEM_PROMPT — JSON schema region 필드 + region 결정 규칙 (추출/제외/예시)
- 🔒 worker/main.py CallbackResult.region (Optional[str])
- 🔒 customer_log.region 컬럼 (VARCHAR 255, AES-256-GCM 암호문)
- 🔒 records.php find_region_field_key + resolve_region_data_key (fallback 'region')
- 🔒 send_to_group MERGE/INSERT/refresh 분기 모두 region 적용 (LLM 추출 시만 갱신)
- 🔒 customer_log_default_group_field_schema 에 region 필드 (새 그룹용)

### API 응답 일관성 (앱팀 v46/v49 spec)
- 🔒 trigger_summarize / preview / summary_status 응답 ok + processing 필드 필수
- 🔒 dispatch_error 필드 진단

### 결제
- 🔒 plan_default_summary_limit_minutes — Free=30/Plus=300/Pro=1000
- 🔒 overage_top_up — 5000원/71분/70원per분
- 🔒 PortOne V2 + 토스 — subscribe.html `requestIssueBillingKey`

### 일반
- 🔒 YOUNGMAN 브랜드 — `logo_main.png` + seal-red `#c8362c`
- 🔒 cron-process-jobs max_retry=2
- 🔒 audio_cleanup 7일 (audio_pending 제외)
- 🔒 ledger UX — 헤더 클릭 필터 / 행 추가 모달 / accordion
- 🔒 placeholder masker (auth-shared.js setupPlaceholderMasker) — MutationObserver
- 🔒 메인 hero CTA 임시 (베타 다운로드) — 결제사 승인 후 원복 예정
- 🔒 OG/Twitter image = og-thumbnail.png, favicon/logo = logo_main.png

---

## 9. 다음에 이어서 해야 할 작업

### 1순위 — 2026-05-24 fix 누적 검증 (다음 통화 테스트 결과 받기)
- 양식으로 전송 → 고객관리대장 placeholder 카드 → 자동 갱신
- 지역 자동 입력 (✅ 사장님 보고로 검증됨)
- 미확인 요약 카드 UI (줄바꿈 + 2줄 버튼)

### 2순위 — 앱팀 v40+ 명세 (사장님이 전달)
명세서 텍스트는 이전 세션 채팅 또는 본 PROJECT_CONTEXT.md 4번 항목 참조.
핵심: native 모달이 trigger_summarize 응답의 `auto_confirm=true` + `customer_log_id!=null` 보면 즉시 닫고 토스트.

### 3순위 — 보안 마무리 (사장님 작업 필수)
1. RECORDING_WORKER_TOKEN rotate (3곳 동기화, **따옴표 없이**)
2. cafe24 webroot admin_env_diag.php FTP 직접 삭제

### 4순위 — 옛 통화 region backfill (사장님 결정 시)
- 옛 customer_log.transcript 에서 Claude 로 region 만 재추출 + UPDATE
- LLM API 비용 발생 → 사장님 명시 요청 시 진행

### 5순위 — 미해결 backlog
- AI 요약 두 모드 분기 / PortOne 라이브 검증 / card-builder UX / records.php dead code cleanup / Lottie 비서 애니메이션

---

## 자가 진단 채널

- `sessionStorage.erp.ensureError` — members 보강 실패
- `sessionStorage.erp.memberEnsured = '1'` — 보강 성공
- 콘솔 prefix: `[auth submit]` / `[google oauth]` / `[bridge]` / `[process-recording]` / `[trigger_summarize]` / `[trigger_summarize placeholder]` / `[recording-callback]` / `[recording-callback auto_confirm]` / `[send_to_group]` / `[send_to_group §7 refresh]` / `[discard]` / `[confirm]` / `[fcm]`
- 브리지: `window.YoungmanBridge.isInApp()` / `.refreshSession()` / `.sendHeartbeat()` / `.setUnreviewedCount(n)`
- Railway log: Railway dashboard → Deployments → 가장 위 ACTIVE → Logs

### 진단 SQL (사장님 phpMyAdmin)
```sql
-- 최근 lazy-STT 흐름 진단
SELECT id, status, customer_log_id, auto_confirm, duration_sec,
       audio_sha256, client_request_id, retry_count,
       LEFT(error_message, 300) AS err,
       TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec
FROM recording_jobs WHERE owner_email='nxnxax@gmail.com'
ORDER BY created_at DESC LIMIT 5;

-- customer_log 컬럼 길이 진단 (region/summary 정상 저장 여부)
-- region_enc_len NULL/0 = Claude 추출 실패, 50+ = 정상
SELECT id, consult_at, source, ai_model,
       LENGTH(region) AS region_enc_len,
       LENGTH(transcript) AS tr_enc_len,
       LENGTH(summary) AS sum_enc_len
FROM customer_log WHERE owner_email='nxnxax@gmail.com'
ORDER BY ai_generated_at DESC LIMIT 10;

-- ledger group schema 확인
SELECT id, name, page_type, LENGTH(field_schema_json) AS schema_len
FROM ledger_groups WHERE owner_email='nxnxax@gmail.com'
ORDER BY page_type, sort_no;
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
