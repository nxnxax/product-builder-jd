# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-18 (옵션 D 라운드 4 사이클 완성 — phone merge + backfill catch-up + 고객관리대장 UI 통일)*

## 1. 사이트 목적

**YOUNGMAN / 영맨** — 1인 사업자용 AI 영업 플랫폼.
- CRM(고객) / HRM(조직도·계약자) / 마케팅 도구 / 로또 분석 / 단체 SMS 통합
- 한국 캘리그라피 + 인장(seal-red #c8362c), Apple/Linear 미니멀 톤
- 라이브: https://youngman-biz.com (Cafe24 호스팅 + Supabase Auth + MariaDB + PHP API)
- 인증: Supabase Cloud + JWT (sb_publishable_ 키, **PHP session 안 씀**)
- 최근 추가: RN Android WebView 앱 브리지 + **통화 녹취 → AI 요약 → CRM (Phase 1+2 전체 라이브)**

## 2. 주요 파일 구조

```
[프론트 페이지]
index.html / login-complete.html / logout.html
profile.html(.js) / admin.html(.js)
org.html(.js) / contracts.html(.js) / customers.html(.js)
forms.html(.js) / board.html(.js) / card-builder.html / lotto2233.html
Marketing.html / kapp_premium.php
terms.html / privacy.html

[공통 JS]
auth-shared.js          — Supabase + 헤더/footer + 인증 + bridge.js import
bridge.js               — RN Android WebView 앱 브리지 (window.YoungmanBridge)
ledger-shared.js        — 관리대장 공통
main.js                 — 사용 안 함 (백업)

[PHP API — cafe24 webroot 에 flat 배포]
api/records.php         — 모든 CRUD (customers/employees/ledger-*/admin-*/auth-*/find-*/sms-*/
                          mobile-tokens/customer-log/app-fcm-token/recording-job)
api/process-recording.php — 통화 녹취 → CLOVA STT → gpt-4o-mini 요약 → customer_log insert
                            sync + async mode 둘 다 지원 (mode body 필드)
api/fcm_helpers.php     — Firebase Cloud Messaging HTTP v1 발송 (Phase 2 M3)
api/audio_cleanup.php   — 24h 미정리 audio cron cleanup (Phase 2 M4)
api/sms/send-bulk.php / balance.php / providers/   — SMS
api/crypto_helpers.php  — AES-256-GCM (enc:v1: prefix)
api/ledger-mobile.php   — 모바일 앱 Bearer 토큰
api/upload.php          — 파일 업로드 (kind=recording 분기 시 audio 전용 디렉터리)

[디자인 / 자산 / 문서]
style.css / logo_main.png
BRIDGE_API.md             — 앱 ↔ 웹 메시지 스펙
CALL_RECORDING_BACKEND.md — 통화 녹취 → AI 요약 백엔드 spec (앱팀 contract + 변경 이력)

[CI]
.github/workflows/deploy.yml                  — FTP 배포 + .env 어셈블
.github/workflows/audio-cleanup-schedule.yml  — 매일 KST 04:00 audio cleanup (cafe24 cron 대체)
```

## 3. 현재 완성된 기능

- ✅ RN Android WebView 앱 브리지 (bridge.js 단일 모듈)
- ✅ 앱 안 Google 로그인 (native SDK + signInWithIdToken + nonce hash)
- ✅ Supabase Auth (이메일 + Google OAuth, 회원가입/로그인/로그아웃/비번 변경)
- ✅ 인증 일원화 (logout.html / login-complete.html 단일 transition)
- ✅ 고아 user 자동 복구 (ensureMemberRowOnce)
- ✅ 로그인 유지 체크박스 (pagehide/beforeunload 자동 sb-* 삭제)
- ✅ 아이디 찾기 / 비밀번호 찾기 (SMS 인증)
- ✅ 이메일/닉네임 중복확인 + Google 신규 가입자 모달
- ✅ 모바일 백그라운드 후 토큰 만료 자동 refresh + 401 retry
- ✅ 헤더 닉네임 즉시 표시 + Solapi 미연동 안내 모달
- ✅ 검색 input 한글 IME 조합 깨짐 fix
- ✅ 조직도/계약자/고객 관리대장 통합 (page_type 기반)
- ✅ 카드 expanded 상태 re-render 후 복원 (_expandedRowIds + MutationObserver)
- ✅ PII 사용자별 격리 + AES-256-GCM 암호화 + 컬럼 폭 자동 확장
- ✅ 단체 SMS 발송 (Solapi/Aligo, 폰 미리보기, 사진 첨부) + 잔액 카드
- ✅ 모바일 하단 네비게이션 (4탭, glass 톤) + bfcache reload
- ✅ 사용자 정의 양식 빌더 시스템 (forms Phase 1~3, 8타입)
- ✅ forms 수식 함수 라이브러리 (엑셀 스타일 + ref 점표기 + 캐스케이드 picker)
- ✅ 양식 슬롯 시스템 (slot1=조직도/slot2=계약자, caret hover dropdown)
- ✅ 모바일 카드 별도 DOM 렌더 (가로스크롤 제거)
- ✅ 모바일 API 토큰 발급 UI (profile)
- ✅ 회원 탈퇴 (account-delete 트랜잭션)
- ✅ NICE 휴대폰 본인확인 심사 대응 (terms/privacy/footer)
- ✅ kapp_premium 사이트 통합 + board/upload/lotto 가독성 polish
- ✅ 로그인/회원가입 모달 가독성 polish + 모달 닫기 버튼 viewport fix
- ✅ 앱 잠금화면 후 자동 로그아웃 회귀 차단
- ✅ 모바일 모달 footer 가 하단 nav 에 가려지던 fix

### ✅ 통화 녹취 → AI 요약 → CRM 기록 (Phase 1+2 전체 라이브, e2e 1차 검증 완료)

- **Phase 1 (sync)**: 앱 m4a 업로드 → Clova STT(ko, 화자분리 2명) → gpt-4o-mini JSON 요약 → customer_log row + AES-256-GCM 암호화 → audio 즉시 unlink
- **customer_name 7단계 룰**: hint > 이름 > 호칭+나이 > 호칭만 > "고객". transcript 없는 추측 금지
- **customer_name_hint** body 필드 (앱 측 contacts lookup 우선)
- **admin bypass** (`nxnxax@gmail.com` allowlist) — quota 우회 + counter 미카운트
- **records.php customer-log resource**: list/get/update/delete + send_to_group + 자체 인증 + spec §4 표준 응답
- **Phase 2 M1**: `app-fcm-token` resource (register/unregister/list, UPSERT, 토큰 마스킹) + `recording_jobs`/`user_fcm_tokens` 테이블
- **Phase 2 M2**: `process-recording.php` body `mode:"async"` 분기 — HTTP 202 + fastcgi_finish_request + ignore_user_abort + register_shutdown_function failsafe + `recording-job` 폴링 endpoint
- **Phase 2 M3**: FCM HTTP v1 발송 (fcm_helpers.php — RS256 self-signed JWT + OAuth + send-to-token + stale 토큰 자동 정리), async 완료 시 자동 푸시
- **Phase 2 M4**: audio_cleanup.php — 24h 미정리 audio cron, GitHub Actions schedule 매일 KST 04:00, dry_run / max_age_hours / max_files 옵션, AUDIO_CLEANUP_TOKEN hash_equals 인증, audio_kept=1 보존
- **옵션 D**: customer_log_send_to_group action — **8필드 매핑** (managed/date/call_count/customer/phone/content/agent_memo/memo, content = summary+관심+문의 라벨조립) + 자동 default 그룹 생성 + lazy schema 마이그레이션 + linked_ledger_record_id 컬럼 + Idempotency. **2026-05-18 라운드 4 완성**:
  - `managed: true` 자동 (사용자가 ledger 토글로 비관리 가능, 백엔드는 항상 default true)
  - `call_count` 자동 계산 (`calculate_call_count()` — 같은 group 내 정규화 phone 매칭 카운트 + 1)
  - **phone merge** — send_to_group 호출 시 같은 group + 정규화된 phone 일치하는 기존 ledger_record 가 있으면 INSERT 대신 UPDATE. content/agent_memo 최신이 위쪽 prepend. 회차 marker `📞 {date} 통화 ({N}회차)`. 응답에 `merged: true`.
  - **backfill catch-up** (`backfill_same_phone_links()`) — send_to_group 시 같은 owner_email + 같은 정규화 phone 의 모든 unlinked customer_log 도 batch UPDATE 로 link 갱신. 응답에 `backfilled_count: N`. 한 건만 양식 전송해도 같은 phone 의 미전송 row 자동 청산.
- **is_main 응답 필드** — `ledger_group_row()` 에 isDefault snake_case alias (앱 chip picker 기본 선택용)
- **고객관리대장 UI 통일** (2026-05-18): 모든 텍스트 셀 (text/textarea) 2줄 clamp + 가운데 정렬, tel nowrap, 행 크기 고정. 클릭 시 상세 모달 (관리 → 고객명 → ... 순). 가로 스크롤 제거 (컬럼 width 1052px), 날짜 6자리(YY.MM.DD), 모바일 카드 접힘 시 "강동원 (3)번 통화함" 자연어 태그.

## 4. 아직 미완성인 기능

- ⏳ **GitHub Actions M4 dry_run 결과** — 사용자가 workflow_dispatch 트리거 후 결과 확인 (여전히 미트리거)
- ⏳ **AI 요약 두 모드 분기** — 대화형(legacy) / 요약정리형(PPT) 사용자 설정 분기. profile.html 라디오 + members.ai_summary_mode + process-recording.php prompt 분기. PPT prompt 는 `37fca8b` 에 보존. budget_condition/next_action 매핑 정책도 함께 재검토.
- ⏳ **card-builder UX** — Recraft overlay primary + AI/템플릿 토글
- ⏳ **profile/admin 디자인 일관성 감사**
- ⏳ **forms 수식 inline help** — 함수 카탈로그 모달
- ⏳ **PII 평문 → 암호문 일괄 backfill 스크립트** (현재 lazy)
- ⏳ **로또 자동 갱신** — JSON 미러 cron
- ⏳ **Supabase Email Template 한글화** (Dashboard 수동)
- ⏳ **SMS_USER_GUIDE.txt 처리** — untracked, 결정 미정
- ⏳ **RN Android 앱 측 검증 마무리** — logcat 확인

## 5. 배포 방식

- **GitHub Actions → FTP** via `SamKirkland/FTP-Deploy-Action`
- 주 워크플로우: `.github/workflows/deploy.yml`
- 보조 워크플로우: `.github/workflows/audio-cleanup-schedule.yml` (매일 KST 04:00 audio cleanup)
- 시크릿 (필수):
  - `CAFE24_FTP_PASSWORD`, `YOUNGMAN_CRYPTO_KEY`, `SUPABASE_SERVICE_KEY`
  - `NCP_CLOVA_INVOKE_URL`, `NCP_CLOVA_SECRET` (Clova STT)
  - `FIREBASE_SERVICE_ACCOUNT_JSON` (FCM 발송)
  - `AUDIO_CLEANUP_TOKEN` (M4 cron 인증)
- "배포/올려" 키워드 → push → trigger → verify 자동 (per-step 확인 묻지 말 것)
- 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)`
- **신규 페이지 추가 시** deploy.yml 의 Prepare cp 줄 + Validate test -f / php -l 둘 다 추가
- **Secret 변경 시** 빈 commit push 로 재배포 트리거: `git commit --allow-empty -m "chore: <SECRET> 반영 재배포"`

## 6. Cafe24/PHP 관련 주의사항

- 🚫 **SSH/SCP 절대 금지** — silent drop. FTP only. `server-dir: ./`
- 🚫 **cafe24 cron 미지원** — GitHub Actions schedule 로 대체 (audio_cleanup 매일 KST 04:00)
- 🚫 **cafe24 빈 POST body → 5xx HTML** — multipart/JSON body 1바이트 이상 필수
- 🚫 **cafe24 ffmpeg 미설치** — 통화녹음 transcode 불가 → Clova Speech 가 3gpp/AMR 네이티브 처리
- 🚫 **dhlottery 직접 호출 금지** — cafe24 IP 차단 영구. JSON 미러만
- 📁 **Webroot flat layout** — `api/records.php` → 배포 후 `/records.php`. `__DIR__` 기준
- 📁 **`api/sms/` 디렉토리는 deploy 시 `deploy/sms/providers/` 로 cp**
- 🔐 **YOUNGMAN_CRYPTO_KEY 분실 = 복호화 영구 불가** — GitHub Secret 백업 필수
- 🔐 **SUPABASE_SERVICE_KEY** — 비밀번호 재설정용. cafe24 서버 PHP 에서만, 절대 노출 금지
- 🔐 **NCP_CLOVA_SECRET / FIREBASE_SERVICE_ACCOUNT_JSON / AUDIO_CLEANUP_TOKEN** — 채팅 노출 금지. 노출 시 즉시 재발급
- 🔐 **사용자별 Solapi 키** — `sms_credentials` 테이블 AES-256-GCM
- 🔐 **관리자 Solapi 키** — admin_email_allowlist[0] 익명 OTP 발송용
- 📡 **deploy/.env 매번 어셈블** — FTP 로 직접 넣은 키는 다음 deploy 에 덮어쓰임
- 📡 **records.php `/auth/v1/user` 폴백** — sb_publishable 키 로그인용. 제거 시 깨짐
- 📡 **PHP timeout 30초** — send-bulk.php `set_time_limit(120)`, process-recording.php `set_time_limit(240)`
- 📊 **PII 컬럼 폭** — 암호문 100~200 chars. 새 PII 컬럼은 최소 VARCHAR(255)

## 7. 최근 수정한 파일 (커밋 흐름)

```
5900869 fix(customers): 모든 텍스트 셀 행 크기 고정 + 가운데 정렬 specificity 강화
7f01404 feat(call-recording): send_to_group 시 같은 phone 의 unlinked customer_log 일괄 link (catch-up)
706b4f9 fix(customers): 가운데 정렬 실제 적용 + 옛 데이터 ━ 구분선 display-time 제거
489bb4d ui(customers): 셀 가운데 정렬 + 모달 reorder + 회차 구분선 제거
812bc11 ui(customers/mobile): 카드 접힘 시 이름 옆에 "(N)번 통화함" 자연어 태그
baac51d ui(customers): 가로 스크롤 제거 — 컬럼 너비 축소 + 날짜 6자리 표시
674618d fix(call-recording): 옛/새 통화 혼선 차단 — prompt 안전망 + separator 강화
09dc0f5 fix(call-recording): summary 말투 변경 — 보고서식 (~했음/~임/관심 필요)
a61ca49 fix(call-recording): summary 분량 제한 해제 + 맥락 누락 절대 금지 강화
62c5834 revert+feat: PPT 톤 롤백 + 고객관리대장 행 고정/상세 모달
37fca8b feat(call-recording): AI 요약 톤/구조 변경 — 보고서식 PPT 형식 (★ git history 보존)
9057db9 feat(call-recording): phone 기반 row merge + customers.js cache 무효화
39b8969 feat(customers): level → 통화수 자동 카운팅 + managed default true
d80c9f5 fix(call-recording): customer_log_send_to_group 데이터 매핑 — 5필드 (앱팀 요청)
```

**미커밋:** `SMS_USER_GUIDE.txt` (untracked, 5/15 추가)

## 8. 절대 건드리면 안 되는 부분

- 🔒 **PII owner_email 격리** — 모든 SELECT/UPDATE/DELETE 강제. admin 우회 없음 (단 `is_admin_email_for_recording` 의 quota 우회는 예외)
- 🔒 **`git add -A` 금지** — 작업 폴더 PII 새어나갈 위험. 명시 add 만
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
- 🔒 **bridge.js 메시지 타입 이름 / window.YoungmanBridge 전역 이름**
- 🔒 **Google 로그인 signInWithIdToken 직접 / nonce raw 웹 / hash 앱 전달**
- 🔒 **deploy.yml 의 bridge.js cp**
- 🔒 **forms 사용 모드 UI = accordion-card**
- 🔒 **양식 슬롯 caret hover 만 활성화**
- 🔒 **새 entry HTML 의 inline script** — `initSupabase()` 또는 `bootApp()` 동반 필수
- 🔒 **모바일 카드 별도 DOM 렌더**
- 🔒 **NICE 본인확인 약관/처리방침**
- 🔒 **records.php $selfAuthResources** — `['customer-log', 'app-fcm-token', 'recording-job']`. global verify_auth_token 우회용
- 🔒 **customer_log_send_to_group 5필드 매핑** — customer/phone/date/content/memo. customers.html UI key. 변경 금지
- 🔒 **customer_log_default_group_field_schema lazy 마이그레이션** — 옛 9필드 → 새 5필드 자동 갱신
- 🔒 **process-recording.php async 흐름** — fastcgi_finish_request + ignore_user_abort + register_shutdown_function failsafe 셋 다 유지
- 🔒 **fcm_helpers.php RS256 self-signed JWT** — openssl_sign 직접. firebase/php-jwt 외부 라이브러리 불필요
- 🔒 **user_fcm_tokens UNIQUE token** — UPSERT 동작 (계정 전환 시 owner_email 갱신)
- 🔒 **audio_cleanup.php hash_equals** — token 비교 timing-safe. == 비교 금지
- 🔒 **audio_cleanup.php customer_log.audio_kept=1 보존**
- 🔒 **is_admin_email_for_recording allowlist** — `nxnxax@gmail.com`. records.php admin_email_allowlist() 와 양쪽 동기
- 🔒 **Clova Speech params** — `language=ko-KR`, `completion=sync`, `fullText=true`, `diarization` 2명
- 🔒 **ai_model 컬럼 값** — `naver-clova-speech+gpt-4o-mini`

## 9. 다음에 이어서 해야 할 작업

### 미완 항목 (call-recording 관련)

1. **GitHub Actions M4 audio-cleanup workflow_dispatch dry_run** — 사용자가 Actions 탭에서 수동 트리거 (https://github.com/nxnxax/product-builder-jd/actions/workflows/audio-cleanup-schedule.yml). 응답 `{ok:true, scanned, deleted, skipped, ...}` 확인 → 매일 KST 04:00 자동 cron 가동 시작. (여전히 미트리거)
2. **AI 요약 두 모드 분기 작업** — 사용자 명시 보존 요청. [[project_ai_summary_modes]]. profile.html 라디오 + `members.ai_summary_mode` 컬럼 + `process-recording.php` prompt 분기 + content 매핑 정책 동시 결정. PPT prompt 는 `37fca8b` 에 보존, 대화형 prompt 는 현재 HEAD.

### 기존 backlog

3. **SMS_USER_GUIDE.txt 처리** — 커밋 / .gitignore / 그대로
4. **card-builder UX** — Recraft overlay primary + AI/템플릿 토글
5. **PII 평문 → 암호문 backfill 스크립트** (lazy 외 일괄)
6. **forms 수식 inline help** — 함수/path 카탈로그 모달
7. **profile/admin 디자인 일관성 감사**
8. **Supabase Email Template 한글화** (Dashboard 수동)
9. **로또 자동 갱신** — JSON 미러 cron
10. **Marketing.html 브리지 포함 검토**

---

## 자가 진단 채널 (디버깅용)

- `sessionStorage.erp.ensureError` : members 보강 실패 시 JSON
- `sessionStorage.erp.memberEnsured = '1'` : 보강 성공
- `sessionStorage.erp.endSessionOnClose = '1'` : 로그인 유지 해제
- 콘솔 prefix: `[auth submit]` / `[signIn]` / `[signUp]` / `[google oauth]` / `[google native]` / `[ensure member auto]` / `[sms balance]` / `[bridge]` / `[process-recording]` / `[fcm]` / `[records]`
- 브리지 디버깅: `window.YoungmanBridge.isInApp()` / `.getAppInfo()` / `.getFcmToken()` / `.version`

## 메모리 참조 (~/.claude/projects/-home-user-jdhoon/memory/)

- `feedback_auth_flow_lessons.md` — 인증 root cause + 단일 페이지 일원화
- `feedback_css_edit_sanity.md` — 큰 Edit 후 brace balance 검증
- `feedback_deploy_autonomy.md` — "배포/올려" 키워드 → 전체 chain 자동
- `feedback_no_proceed_prompts.md` — "Do you want to proceed?" 묻지 말 것
- `feedback_pii_isolation.md` — PII owner_email 강제, git add -A 금지
- `feedback_readability_first.md` — 60대+ 가독성 우선
- `feedback_ledger_ux.md` — 헤더 클릭 필터 / 행 추가 모달 / accordion
- `feedback_paste_formatting.md` — 외부 채팅 붙여넣기 메시지 코드블록 wrap + 시작/끝 마커
- `pending_call_recording_status.md` — 2026-05-17 인계 시점 call-recording 대기 항목
- `project_app_bridge.md` — RN Android WebView 앱 연동
- `project_pii_crypto.md` — AES-256-GCM 라이브
- `project_ledger_system.md` — page_type 기반 그룹/레코드
- `project_youngman_redesign.md` — 브랜드 리디자인
- `project_nav_slots.md` — slot1/slot2 caret hover dropdown
- `project_mobile_bottom_nav.md` — 4탭 하단 nav
- `deploy_cafe24.md` — FTP only, webroot flat layout
