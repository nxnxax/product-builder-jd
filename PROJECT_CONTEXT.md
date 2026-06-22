# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-06-22 세션 — **출시 직전 안정화**: 자는 사이 '유령 로그아웃' 차단(logout.html 이중가드) / RTDN 자동갱신 사용량 reset 정밀검사+자가치유(rtdn.php) / PWA 바탕화면 바로가기→일반 브라우저 탭 방식으로 전환 / 폴리스 전 기간 실패빈도 지도 보강 / 사후 통화 가져오기(share-import) ledger 미진입 = 서버 무변경+앱 1줄 수정으로 진단. 최신 commit `b5bde2f`.*
*상태: Play스토어 정식출시 완료. 구글애즈/메타 광고 머신러닝 중 — 곧 대량 설치 유입 예정. **"큰 이상 절대 금지" = 출시 직전 최우선.***

---

## 1. 사이트 목적
**YOUNGMAN / 영맨** — 1인 사업자용 AI 영업·CRM 플랫폼.
- 슬로건: "고객관리, 이제 전화만 하세요". 전화 통화를 AI가 자동 요약 → 고객관리대장(장부)에 자동 보관.
- 기능: CRM(고객) / HRM(조직도·계약자 관리대장) / 마케팅 / 로또 / 단체 SMS / **AI 통화요약→미확인요약→고객관리대장** / 사주풀이·궁합.
- 디자인: 한국 캘리그라피 + 인장(seal-red `#c8362c`), Apple/Linear 미니멀. **사용자 60대+ 다수 → 고객데이터 글자는 가독성 우선(작은 글자/얇은 weight 금지)**.
- 라이브: https://youngman-biz.com (Cafe24 + Supabase + MariaDB + PHP). 앱: Google Play `com.youngmanapp`.
- **결제**: Google Play Billing(구독 + 일회성 충전권). PortOne+토스 코드는 미래용 keep.
- 앱: RN Android WebView + bridge.js + native CallScreening/통화후모달/명함첩 overlay.
- 사업자: 어센트라(Ascentra) 393-39-01518 / 대표 장동훈 (nxnxax@gmail.com = 하드코딩 admin).

---

## 2. 주요 파일 구조
```
[프론트] index.html(첫화면+이벤트팝업+Google Play버튼+PC유틸바) / subscribe.html(구독·할인가·결제후폴링)
 billing.html(구독·충전권 관리) / admin.html(+매출탭+진단탭) / customers.html(+?demo=1 광고데모)
 logout.html(단일 로그아웃 transition — 이중 재발사 가드) / login-complete.html(로그인 transition)
 profile / org / contracts / forms / board / card-builder / lotto2233.html(사주·궁합) / unreviewed(미확인요약)
 manifest.json + sw.js(PWA, display:browser=일반 탭 바로가기) + app-icon-192.png
[공통 JS]
 auth-shared.js — Supabase + 헤더/nav + 인증 + apiRequest + SIGNED_OUT race safety + pageshow(bfcache)reload
 customers.js / admin.js(+매출 loadRevenue) / bridge.js(RN 브리지: notifyLogin/Logout, snapshot)
[PHP API — cafe24 webroot flat. api/billing → /billing/ 매핑]
 records.php — CRUD + auth-profile(plan/분/충전잔액) + admin-*(admin-revenue/payment-trace/backlog-holds/overcharge-scan)
   + customer-log(send_to_group=ledger mirror) + mark_usage(차감+ledger 진입 게이트) ★전 응답 no-store
 process-recording.php — 통화 audio 수신=audio_pending만 저장(lazy-STT). 한도/topup 게이트(async분기 앞)
 recording-callback.php — Railway STT 결과 → auto_confirm=1 이면 customer_log INSERT + ledger mirror + FCM
 billing_helpers.php — plan 금액/한도 함수 + topup 헬퍼 + 스키마 ensure
 fcm_helpers.php — FCM v1(통화요약 call_summary_ready / 관리자 결제알림 notify_admin_new_payment, 채널 yk_admin_notice_v1)
 billing/verify-google-purchase.php(구독검증→plan/한도/세금) / rtdn.php(자동갱신·해지·환불 RTDN)
 billing/{verify-payment,cron-renew,webhook-portone,cancel-subscription,topup-verify,topup-settings,config,google_play_helpers}.php
 billing/topup-testsetup.php·notify-test.php — (임시 guarded 테스트, 출시 전 비활성 예정)
 saju-reading.php(Qwen 점쟁이 사주/궁합)
[Railway worker] worker/main.py — STT(Together Whisper) + LLM(Together Qwen). 둘 다 Together.
[배포] .github/workflows/deploy.yml — 파일 명시 cp+lint 후 FTP. .env=GitHub Secrets 재조립.
```

---

## 3. 현재 완성된 기능
**핵심(검증·라이브):** AI 통화요약(Together Whisper+Qwen, 줄글/보고서형 v2) / 미확인요약→고객관리대장(라지-STT 모델) / 회차 자물쇠(round_log_ids) / 명함첩 FCM / PII AES-256-GCM / 조직도·계약자·고객 관리대장 / 단체 SMS / 로또(JSON 미러) / 사주·궁합.

**결제(라이브):** Google Play 구독(Sales 14,900 / Master 28,900 / Agency 39,900, VAT포함 영구할인가) + 일회성 충전권(`youngman_topup_80min` ₩5,000=80분) + 차감 게이트(■4: 한도+충전잔액 0이면 402 topup_required) + 플랜변경(deferred 안내).

**이번/최근 세션 완성:**
- **자동갱신 사용량 reset 정밀검사 완료 + 자가치유**: RTDN RENEWED(type2)/RECOVERED가 `usage_seconds_period=0` reset + 기간연장 + **plan/summary_limit_minutes 재확정**(planKey 알 때). 2차 안전망 = process-recording 30일 lazy reset. cron-renew는 카드(PortOne)용이라 Google 구독자 안 건드림(members.portone_billing_key NULL). → **사용량 갱신은 이중 안전망, fail-safe.**
- **로그아웃 안정화**: 6/19 explicit-logout 수정 + bfcache reload가 맞물려 "자는 사이 유령 로그아웃→통화모달 사망" 회귀 발생. logout.html에 **navType('navigate'만 발사) + 같은 URL(_t) 소비 마커** 이중가드로 재발사 루프 차단(native·auth-shared 무변경=캐시버스트 회피).
- **관리자 매출 통계 탭 + 실시간 결제 알림**: admin-revenue(실시간 결제내역 최상단/일간·주간·월간 달력 기간선택/순마진/결제취소/유료회원현황). 실결제·자동갱신·초과결제·충전 발생 시 nxnxax 폰에 FCM(채널 yk_admin_notice_v1, heads-up+소리+잠금화면). RTDN 갱신도 payments에 멱등 기록.
- **LG 무더기차감 방어**: 앱 백그라운드 사망→백로그 일괄 auto_confirm 부당차감. 서버 HOLD 가드(4중조건) + admin-overcharge-scan(데이터 지문 스캐너) 라이브. 근본해결=앱(백로그 자동전송 중단, v217). 피해자 보상 = 사장님 결정 대기.
- **index 마케팅/UX**: Google Play 다운로드 버튼(컬러 로고, 모바일/PC 분리) / PC전용 유틸(즐겨찾기 Ctrl+D 안내·바탕화면 바로가기) / PWA = `display:browser`(별도 앱창 아닌 **일반 브라우저 탭 바로가기**). 개인자료함 upload=admin 전용. 데모 고객 20명.
- **폴리스 알고리즘 강화**: git 720커밋/fix303 전수분석 → 전 기간 실패빈도 지도 + 누락 2클래스(STT입력 포맷·사용자별 캐시 UI) 추가. 점검표는 무변경(성능 유지).

---

## 4. 아직 미완성 / 검증 대기
- **★ 사후 통화 가져오기(share-import)**: 타사 전화앱 최근통화→공유→영맨이 "기본그룹>고객관리대장"에 바로 들어가야 하는데 미확인요약에 머묾. **진단 완료: 서버 무변경. 앱이 ledger 진입 게이트 `mark_usage(click_type='auto_confirm', user_initiated:true)`를 안 부르고 send_to_group 직접 호출이 원인.** → 앱팀 전달사항 작성 완료(앱이 mark_usage(auto_confirm) 1회 호출로 수정). 앱 수정+사장님폰 v229+ 재테스트 대기.
- **★ 로그아웃 native robustness(앱팀)**: web 이중가드로 재발사는 막았으나, native `isExplicitlyLoggedOut`가 한번 켜지면 유효 세션 와도 안 풀림 = 좀비 상태. 앱팀 ①유효 auth.login 도착 시 플래그 해제 ②모달을 플래그만으로 죽이지 말 것. (구조적 뿌리 = WebView+RN 이중 refresh 책임, [[project_auth_root_cause_chatgpt]])
- **RTDN 운영 실증**: 코드는 정상이나 Google→서버 RTDN 실제 도착(Pub/Sub·RTDN_SHARED_TOKEN 설정)은 첫 자동갱신 발생 시 매출탭 실시간결제내역으로 확인 필요. (안 와도 30일 안전망이 사용량은 reset)
- **LG 피해자 보상 여부**: 확정 피해자(LG 사용자) 환불/보상 = 사장님 결정 대기.
- **출시 전 정리**: 테스트 endpoint(topup-testsetup.php / notify-test.php) 비활성화. 앱 릴리즈 빌드 v229+ 확인.

---

## 5. 배포 방식
- GitHub Actions → FTP(cafe24). deploy.yml이 **파일 명시 cp+lint 후 업로드** → **신규 파일(PHP/정적)은 cp 등록 필수**(안 하면 라이브 404). api/billing → /billing/.
- 클로드 직접 push 가능(PAT `~/.git-credentials`). **GitHub Secret 쓰기는 사장님 UI**(PAT 불가).
- "배포/올림" = 자율 push→Actions 확인→verify 까지 한 번에(중간 승인 묻지 않음). 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)` (PHP GET 401/405=정상 작동, 404=cp 누락).
- **캐시버스트**: JS/CSS module 변경 시 모든 importer `?v=` 같은 commit 갱신(안 하면 옛 캐시=효과 0). auth-shared.js 변경은 전 페이지 ?v 통일 필요(인스턴스 2개=인증 깨짐). logout.html은 매번 `_t=` fresh라 연쇄 없음.
- 환경: gh CLI 없음(Bash에서 `gh` 안 됨) → 라이브 curl 폴링으로 배포 검증. node module 문법검사 = `node --input-type=module --check < file.js`.

---

## 6. Cafe24/PHP 주의사항
- 🚫 **SSH/SCP 금지**(silent drop), FTP only. cafe24 cron 미지원 → GitHub Actions schedule.
- 🚫 빈 POST body→5xx. ffmpeg 미설치(transcode는 Railway worker). **DB 직접접근 불가**(phpMyAdmin/직접 X) → admin 패널·진단탭·Railway 로그·사장님 SQL로만 진단.
- 🔑 records.php 시작 `opcache_invalidate(__FILE__,true)` 제거 금지. .env 값 따옴표 금지·JSON시크릿 한 줄. 이메일 비교는 `WHERE LOWER(email)=LOWER(:e)`.
- 🔐 `YOUNGMAN_CRYPTO_KEY` 분실=복호화 영구 불가. 🚫 `git add -A` 금지(PII) — 명시 파일만 add.
- **★ 모든 API 응답에 `Cache-Control: no-store`**(records.php 적용됨, 신규 엔드포인트도 포함) — 누락 시 WebView 캐시로 "바꿔도 안 바뀜".

---

## 7. 최근 수정한 파일 (2026-06-22 세션)
```
로그아웃 가드:  logout.html (navType + erp.logoutConsumedSig 소비마커 이중가드, KEEP_PATTERNS 추가)
자동갱신 자가치유: api/billing/rtdn.php (RENEWED/RECOVERED 시 plan·summary_limit·summary_limit_minutes 재확정)
PWA/마케팅:    index.html (manifest link/theme-color, SW 등록, 유틸바 absolute tuck, 바로가기=일반탭 안내)
              manifest.json (display:browser) + sw.js(no-op) + app-icon-192.png  ※deploy.yml cp 등록함
폴리스 메모리:  feedback_polis_algorithm.md (실패빈도 지도 + 로그20·21) / feedback_auth_flow_lessons.md(#15)
[직전 세션] admin.html·admin.js(매출탭) records.php(admin-revenue·overcharge-scan·backlog-hold)
  fcm_helpers.php(notify_admin_new_payment) verify-google-purchase·rtdn·webhook·cron-renew(INSERT IGNORE)
  index.html(Google Play 버튼) auth-shared.js(SIGNED_OUT 우선순위·upload adminOnly) billing.html(충전권)
```

---

## 8. 절대 건드리면 안 되는 부분
- 🔒 **검증된 통화/STT/인증/결제 흐름은 사장님 명시 동의 없이 변경 금지**([[feedback_no_working_flow_break]]). 변경 시 baseline commit 기록 + 롤백 준비.
- 🔒 **고객관리대장 진입 = `mark_usage(click_type='auto_confirm')` 게이트**(정식 통화 "양식으로 전송"이 호출). process-recording은 audio_pending만 저장(lazy-STT). 이 모델 깨지 말 것.
- 🔒 **한도/차단 검사는 "실제 차감지점 + 모든 early-return(async enqueue) 앞"**. async 분기 뒤 = 죽은코드([[feedback_polis_algorithm]] 11).
- 🔒 **결제 단일기준 = `portone_plan_amount`**(공급가+VAT=청구액 일치 유지). mark_usage만 차감(usage_counted_at 멱등=이중차감 없음).
- 🔒 logout.html 이중가드(navType + 소비마커) 유지 — 제거 시 유령 로그아웃 재발. auth-shared `pageshow(persisted)` 및 SIGNED_OUT race safety net 유지.
- 🔒 records.php no-store 헤더 / opcache_invalidate / index.html `initSupabase→refreshAppHeader` 유지.
- 🔒 worker `CLAUDE_SYSTEM_PROMPT`(요약 품질) 무수정. admin 판별=서버 `current_user_is_admin`→auth-profile `is_admin`. nxnxax@gmail.com=하드코딩 admin(통화 한도 우회).
- 🔒 Google Play 구독자는 DB로 plan 못 내림(앱 재검증이 verify-google-purchase로 되돌림) — 해지해야 free.

---

## 9. 다음에 이어서 해야 할 작업
1. **★ 사후 가져오기(share-import) 마무리**: 앱팀이 share-import 큐 drain에서 `mark_usage(auto_confirm, user_initiated:true)` 1회 호출로 수정 → 사장님폰 v229+에서 "기본그룹>고객관리대장" 즉시 표시 확인. (서버는 무변경이 정답 — 출시 직전 핵심파일 risk 회피)
2. **★ 로그아웃 native robustness**: 앱팀에 isExplicitlyLoggedOut 해제 조건 2가지 전달·반영 확인.
3. **RTDN 자동갱신 실증**: 첫 유료 사용자 갱신일에 매출탭 실시간 결제내역에 갱신건 찍히는지 확인.
4. **출시 전 정리**: topup-testsetup.php / notify-test.php 비활성화. 릴리즈 빌드 v229+ 확인.
5. **LG 피해자 보상 결정**(사장님) + 자동 양식전송 부당청구 앱 수정 확인([[project_auto_confirm_overcharge]]).

---

## 환경 / 메모리 / 작업 원칙
- **호칭: 사장님**. "쉬세요" 금지(작업 종료는 사장님 권한). "PoC"→"테스트", "ship/deploy"→"배포/올림". 토큰 최소·짧은 답변·일반인 용어. 외부 채팅(앱팀) 붙여넣기용은 코드블록으로 감싸기.
- **"proceed?" 묻지 말 것** — 요청의 함의된 체인을 끝까지 실행 후 결과 보고. "배포/올림"은 push→검증까지 자율.
- **"정밀작업해" 트리거** = 원인 하나 찾고 바로 고치지 말고, 다른 원인·충돌·부작용 전체 교차검증 후 한 번에 안전하게([[feedback_precision_work]]).
- **★ 매 작업 전/완료선언 전 [[feedback_polis_algorithm]] 점검표 + 실패빈도 지도부터 확인** — 세션 클리어로 내 경험은 휘발되니 이 메모리가 유일한 누적 경험. 새 실패 패턴은 즉시 기록.
- 메모리 인덱스 = `~/.claude/projects/-home-user-jdhoon/memory/MEMORY.md`. 핵심 첫 참조:
  - `feedback_polis_algorithm` — 점검표+실패빈도지도(작업 시작 시 1순위)
  - `feedback_auth_flow_lessons` — 로그인/로그아웃/인증 15+케이스(인증 증상 첫 참조)
  - `feedback_api_cache_nostore` / `feedback_cache_bust_always` — "안 바뀜" 캐시 1순위
  - `project_topup_and_launch_pricing` / `project_new_pricing_2026_05_26` — 결제·할인가·충전권
  - `project_auto_confirm_overcharge` / `feedback_pii_isolation` / `deploy_cafe24`
```
