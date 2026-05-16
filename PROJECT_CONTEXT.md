# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-05-17*

## 1. 사이트 목적

**YOUNGMAN / 영맨** 브랜드의 1인 사업자용 AI 영업 플랫폼.
- CRM(고객) / HRM(조직도·계약자) / 마케팅 도구 / 로또 분석 / 단체 SMS 통합
- 한국 캘리그라피 + 인장(seal-red #c8362c) 브랜드, Apple/Linear 미니멀 톤
- 라이브: https://youngman-biz.com (cafe24 호스팅 + Supabase Auth + MariaDB)
- 인증: Supabase Cloud + JWT (sb_publishable_ 키, **PHP session 안 씀**)
- 메인 페이지: toss/niceid 스타일 7단락 zig-zag (1단락=AI 고객관리 hero)

## 2. 주요 파일 구조

```
index.html                     — 새 랜딩 (hero + 6 features + End CTA, 홀수단락 floating bg 아이콘)
index_backup_20260516.html     — 옛 디자인 백업 (deploy 안 됨)
main.js / main_backup_*.js     — 사용 안 함 (백업만 유지)

auth-shared.js                 — Supabase + 헤더/footer + 인증 전체 관리
                                 currentSession, initSupabase, getSession, performLogout
                                 navigateAfterAuth → login-complete.html
                                 ensureMemberRowOnce — 모든 페이지 boot 시 자동 members 보강
                                 translateAuthError (영문 → 한국어 친화)
                                 openSharedLoginModal / openFindIdModal / openFindPasswordModal
                                 bindBackdropClose (pointerdown/up — 드래그 close 차단)

logout.html                    — 단일 로그아웃 cleanup 페이지 (signOut scope:global + KEEP yman_nav_/userEmail.last)
login-complete.html            — 단일 로그인 transition + 신규 Google 가입자 닉네임/약관 form

profile.html / profile.js      — 내 정보 5탭 (계정/기본/보안/모바일API/문자설정/계정관리)
admin.html / admin.js          — 관리자 4탭 (대시보드/회원/설정/로그)

org.html / org.js              — 조직도 (그룹별 팀 + 수수료)
contracts.html / contracts.js  — 계약자 관리대장 (수수료 정산)
customers.html / customers.js  — 고객 관리대장
                                 + 단체 SMS 모달 (좌측 수신자 패널 / 폰 미리보기 / 충전잔액카드 /
                                   LMS·MMS 큰 배지 / 사진 첨부 drag&drop / 수신자 X 즉시 제거 / 로딩 overlay)
forms.html / forms.js          — 사용자 정의 양식
ledger-shared.js               — 공통 모달/필터/cell click/엑셀 헬퍼
                                 + 카드 expanded 상태 _expandedRowIds Set + MutationObserver 자동 복원
                                 + bfcache pageshow.persisted reload
                                 + endSessionOnClose listener (로그인 유지 체크해제 처리)

api/records.php                — 모든 CRUD (customers/employees/ledger-*/admin-*/auth-*/
                                  find-email[-send-otp][-verify-otp]/find-pwd-send-otp/verify-otp/reset/
                                  sms-credentials/mobile-tokens/account-delete)
                                 + ensure_member_pii_columns_wide(이름/휴대폰/닉네임 컬럼 자동 VARCHAR(255))
                                 + send_otp_sms_via_admin (관리자 Solapi)
                                 + supabase_admin_find_user_id / supabase_admin_set_password (service_role)
                                 + auth-member ensure 모드 + needsNickname 응답
                                 + auth-profile PUT nickname 지원
api/sms/send-bulk.php          — 단체 SMS (텍스트 + image_base64) + set_time_limit(120) + fatal handler
api/sms/balance.php            — Solapi 충전 잔액 조회 (PDO new 명시)
api/sms/providers/             — Solapi (uploadImage / getBalance / sendBulk) / Aligo
api/crypto_helpers.php         — AES-256-GCM (enc:v1: prefix)
api/ledger-mobile.php          — 모바일 앱 Bearer 토큰
api/upload.php                 — 파일 업로드

lotto2233.html / lotto_*       — 로또
board.html / board.js          — 게시판
card-builder.html              — 명함 (미완)
Marketing.html / kapp_premium.php — iframe 외부 도구

style.css                      — 디자인 토큰 + sms-phone 폰 UI + sms-balance-card + signup-input-row +
                                 .signup-extra (google 가입 모달) + .rp-remove (수신자 제거 버튼) +
                                 .check-dup-btn (중복확인) + .shared-auth-fields[hidden]{display:none}
                                 + sms-attach-empty/preview [hidden] fix
logo_main.png                  — 브랜드 로고
SMS_USER_GUIDE.txt             — 사용자 설명서
```

## 3. 현재 완성된 기능

- ✅ Supabase Auth (이메일 + Google OAuth) + 회원가입 / 로그인 / 로그아웃 / 비밀번호 변경 (admin API)
- ✅ **인증 일원화** — logout.html / login-complete.html 단일 transition 페이지
- ✅ **고아 user 자동 복구** — 모든 페이지 boot 시 ensureMemberRowOnce 호출 → members 자동 보강
- ✅ **로그인 유지 체크박스** (체크 해제 시 pagehide/beforeunload 자동 sb-* 삭제)
- ✅ **아이디 찾기 (SMS 인증)** — 관리자 Solapi 자격증명으로 OTP 발송 + 마스킹 이메일
- ✅ **비밀번호 찾기 (SMS 인증 + 새 비번 설정)** — supabase admin API 직접 변경, Google 가입자는 안내만
- ✅ **이메일/닉네임 중복확인** — 회원가입 form (한 줄 layout)
- ✅ **Google 신규 가입자 닉네임/휴대폰/약관 모달** — login-complete.html 의 needsNickname 기반, phone 도 동시 수집 → auth-profile PUT
- ✅ **헤더 표시 이름 닉네임 우선** — getDisplayName candidates 순서 변경
- ✅ 영문 에러 한국어 친화 메시지 (translateAuthError 단일 헬퍼)
- ✅ 모달 드래그 시 강제 종료 fix (pointerdown/up 둘 다 backdrop 일 때만 close)
- ✅ 조직도/계약자/고객 관리대장 통합 (page_type 기반 ledger 시스템)
- ✅ **카드 expanded 상태 re-render 후 자동 복원** (_expandedRowIds + MutationObserver)
- ✅ **셀 토글 클릭 시 카드 안 접힘** (data-cell-* selector + stopPropagation)
- ✅ PII 사용자별 격리 (owner_email 강제) + AES-256-GCM 암호화 + PII 컬럼 폭 자동 확장
- ✅ 회원 탈퇴 (account-delete, 트랜잭션 일괄 삭제)
- ✅ 모바일 API 토큰 발급/관리
- ✅ **단체 SMS 발송** — 회원별 Solapi/Aligo, 폰 UI 미리보기, 좌측 수신자 패널 + X 제거,
  큰 SMS/LMS/MMS 배지, 사진 첨부 (drag&drop + 200KB + 1500x1440 검증), 발송 결과 robust 매칭
- ✅ **Solapi 충전 잔액 카드** (모달 내 폰 미리보기 바로 위, 컴팩트)
- ✅ 새 메인 랜딩 (toss/niceid 톤) + 모바일 hero compact + floating bg 아이콘
- ✅ 모바일 하단 네비게이션 + bfcache pageshow.persisted reload
- ✅ records.php INSERT try/catch + sessionStorage.erp.ensureError 자가 진단 채널

## 4. 아직 미완성인 기능

- ⏳ **card-builder UX** — Recraft overlay + 토글 UI 미구현
- ⏳ **profile/admin 디자인 polish 일관성 감사**
- ⏳ **forms 수식 inline help** — 함수 카탈로그/예시 모달
- ⏳ **로또 자동 갱신** — dhlottery IP 차단으로 수동 JSON, cron 토큰 코드만
- ⏳ **PII 평문 → 암호문 일괄 backfill 스크립트** — 현재 lazy migration
- ⏳ **Supabase Email Template 한글화** (사용자가 Dashboard 에서 직접 수정 — 코드 X)

## 5. 배포 방식

- **GitHub Actions → FTP (port 21)** via `SamKirkland/FTP-Deploy-Action`
- 워크플로우: `.github/workflows/deploy.yml`
- 시크릿 (필수): `CAFE24_FTP_PASSWORD`, `YOUNGMAN_CRYPTO_KEY` (PII 암호화), **`SUPABASE_SERVICE_KEY` (비밀번호 재설정)**
- "배포/올려" 키워드 → push → trigger → verify 자동, per-step 확인 묻지 말 것
- 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)`
- 캐시 버스트 최신: `v=20260516-nickname-header`
- **신규 페이지 추가 시** deploy.yml 의 Prepare deploy 의 cp 줄 + Validate test -f 둘 다 추가 (누락 시 라이브 404)

## 6. Cafe24/PHP 관련 주의사항

- 🚫 **SSH/SCP 절대 사용 금지** — silent drop. FTP only. `server-dir: ./`
- 📁 **Webroot flat layout** — `api/records.php` → deploy 후 `/records.php`. `__DIR__` 기준
- 📁 **`api/sms/` 디렉토리 유지** — `deploy/sms/providers/*` 로 cp
- 🔐 **YOUNGMAN_CRYPTO_KEY 분실 = 복호화 영구 불가** — GitHub Secret 백업 필수
- 🔐 **사용자별 Solapi 키** — `sms_credentials` 테이블 AES-256-GCM. 영맨은 결제/충전 일절 관여 X
- 🔐 **관리자 Solapi 키** (admin_email_allowlist[0]) — 익명 OTP 발송용. 사용자 본인 부담 X
- 🔐 **SUPABASE_SERVICE_KEY** — 비밀번호 재설정용. service_role 키 노출 절대 금지. cafe24 서버 PHP 에서만 사용
- 📡 **deploy/.env 매번 어셈블** — 사용자가 FTP 로 직접 넣은 키는 다음 deploy 에 덮어쓰임
- 📡 **records.php `/auth/v1/user` 폴백** — sb_publishable 키 로그인용. 제거 시 깨짐
- 📡 **dhlottery 직접 호출 금지** — cafe24 IP 차단. JSON 미러만
- 📡 **PHP timeout 30초** — send-bulk.php 는 set_time_limit(120) 명시 (Solapi curl 25초 + 처리 = 30초 근접 시 502)
- 📊 **DB 컬럼 폭** — PII 암호화 'enc:v1:...' 는 100~200 chars. 새 PII 컬럼 도입 시 최소 VARCHAR(255)

## 7. 최근 수정한 파일 (오늘 +1일 커밋 흐름)

```
0fd2816 feat(auth): 헤더 우측 상단 사용자 이름을 닉네임 우선으로 표시
b99ec62 fix(auth): 구글 가입 후 닉네임 모달 — needsNickname 기반 판별
a2385b8 hotfix(auth): login-complete.html module top-level 'return' SyntaxError fix
9afb505 fix(ledger): 카드 expanded re-render 후 보존 (_expandedRowIds + MutationObserver)
0cf1f14 feat(auth): Google 신규 가입자 닉네임/약관 모달 + auth-profile PUT nickname
fdae27a feat(auth): 모달 드래그 close fix + 이메일/닉네임 중복확인 + 한줄 정렬
256684b feat(auth): 비밀번호 찾기 SMS 인증 + 새 비번 + Google 사용자 안내
3ca7959 fix(auth): normalize_resource 화이트리스트 누락 fix
2720378 feat(auth): 아이디 찾기 SMS 인증 (관리자 Solapi)
e2d3e54 feat(auth): 로그인 유지 + 아이디/비밀번호 찾기 (기본)
238eb04 fix(auth): 영문 에러 한국어 친화 메시지 통일
e028540 fix(auth): members PII 컬럼 자동 VARCHAR(255) 확장 (22001 fix)
c87c524 fix(sms): 수신자 이름/번호 추출 경로 + 빈 첨부 깨진 아이콘 제거
... (이전: SMS UI 일원화, 인증 흐름 일원화, 모바일 hero, 단체문자 발송 등)
```

## 8. 절대 건드리면 안 되는 부분

- 🔒 **PII owner_email 격리** — 모든 SELECT/UPDATE/DELETE 에 `WHERE owner_email = :current_user` 강제. admin 우회 없음
- 🔒 **`git add -A` 금지** — 작업 폴더의 .xlsx/.csv/.pdf 등 PII 새어나갈 수 있음. 명시 add 만
- 🔒 **YOUNGMAN 브랜드** — logo_main.png + seal-red(#c8362c)
- 🔒 **SSH/SCP 배포 시도 금지** — 작동 안 함
- 🔒 **서버 설정 파일 repo 커밋 금지** — supabase_config.js/php, db_config.php, .env 는 FTP/GitHub Secret 만
- 🔒 **records.php `/auth/v1/user` 폴백** 유지 — 마지막 보루
- 🔒 **dhlottery 직접 호출 부활 금지** — IP 차단 영구
- 🔒 **인증 일원화 구조** — 로그인/로그아웃은 logout.html / login-complete.html 단일 흐름. 분산된 핸들러 다시 만들지 말 것
- 🔒 **ledger UX 패턴** — 헤더 클릭 필터 / 행 추가 모달 / accordion 그룹. 인라인 거부됨
- 🔒 **카드 expanded 상태 보존** — _expandedRowIds + MutationObserver. 토글 시 자동 접힘 회귀 금지
- 🔒 **`<a href="logout.html">` native navigation** — JS click handler 의존으로 회귀 금지 (모바일 user gesture 손실 위험)
- 🔒 **module top-level `return` 금지** — `<script type="module">` 안에서 if/else 구조로 작성
- 🔒 **ensureMemberRowOnce** — 모든 페이지 boot 시 자동 호출. 제거 시 고아 user 403 회귀
- 🔒 **PII 컬럼 폭 자동 확장** — ensure_member_pii_columns_wide. 제거 시 22001 회귀
- 🔒 **OAuth click handler 동기 흐름** — `await initSupabase` 추가 금지 (모바일 navigation 차단)
- 🔒 **prompt: 'select_account'** — Google OAuth 옵션. 제거 시 계정 선택 화면 skip
- 🔒 **signOut({ scope: 'global' })** — logout.html. local 로 회귀 금지 (다른 디바이스 살아남음)
- 🔒 **forms accordion-card UI 통일** — 사용자 명시
- 🔒 **SMS 회원별 자격증명** — 영맨은 발송 중계만 (단 관리자 키는 OTP 발송용 별도)
- 🔒 **sms_logs 원문 저장 금지** — phone_masked + message_hash 만
- 🔒 **헤더 anon 결정**에 cachedName **OR** currentSession 둘 다 — 로그인 직후 헤더 stale 회귀 금지
- 🔒 **apiRequest 호출 형식** — `apiRequest('xxx', { query: 'k=v' })`. URL 직접 X (인코딩 깨짐)
- 🔒 **normalize_resource 화이트리스트** — 신규 resource 추가 시 $allowed 배열에도 추가 (안 그러면 400)

## 9. 다음에 이어서 해야 할 작업

1. **card-builder 정리** — Recraft overlay primary path + AI/템플릿 토글 UI
2. **PII 평문 → 암호문 backfill** — ledger_records / members 전체 일괄 암호화 스크립트
3. **forms 수식 inline help** — 함수/path 카탈로그 모달
4. **profile/admin 디자인 일관성 감사** — 새 랜딩 톤과 맞추기
5. **Supabase Email Template 한글화** — Dashboard → Authentication → Email Templates 직접 수정 안내
6. **로또 자동 갱신** — JSON 미러 cron 자동화 (현재 수동 + 토큰 코드만)
7. **메인 페이지 추가 다듬기** — 사용자 추가 피드백 가능성

---

## 자가 진단 채널 (디버깅용)

- `sessionStorage.erp.ensureError` : members 보강 실패 시 status/error/columns/table/sqlState JSON 저장
- `sessionStorage.erp.memberEnsured = '1'` : 보강 성공 (session 당 1회)
- `sessionStorage.erp.endSessionOnClose = '1'` : 로그인 유지 체크 해제 상태
- 콘솔 prefix: `[auth submit]` / `[signIn]` / `[signUp]` / `[google oauth]` / `[ensure member auto]` / `[members POST]` / `[sms balance]`

## 메모리 참조

- `feedback_auth_flow_lessons.md` — 인증 13가지 원인 + 단일 페이지 일원화 + 자동 ensure + 컬럼 폭 확장
- `feedback_css_edit_sanity.md` — 큰 Edit 후 brace balance + 중복 selector + module top-level return 금지
- `feedback_deploy_autonomy.md` — "배포/올려" 키워드 트리거
- `feedback_no_proceed_prompts.md` — "Do you want to proceed?" 묻지 말 것
- 기타 PII / ledger UX / 모바일 nav / 슬롯 nav 등
