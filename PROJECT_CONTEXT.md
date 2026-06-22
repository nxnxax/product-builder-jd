# PROJECT_CONTEXT — youngman-biz.com

*최종 갱신: 2026-06-23 세션 — **출시 직후 트래킹/통계 정밀화**: ① 방문자 집계 핵심버그 수정(index가 pageview 미발사 → 광고 랜딩 유입 전량 누락이었음) ② 사주/궁합 다중클릭 → LLM 중복호출(비용 2~3배) 차단 ③ 통화기록 가져오기 안내 배너+설명서 페이지(import-guide.html, 네이비+골드 동적 디자인) ④ 안내영상 유튜브→관리자 직접 업로드(자체호스팅 9:16) ⑤ 앱팀 통계 스펙 구현(install-ping.php + admin-stats.php, KST). 최신 commit `79132be`.*
*상태: Play스토어 정식출시 완료. 구글애즈(영상)/메타(릴스) 광고 진행 중. **"큰 이상 절대 금지 · 통계 오류=잘못된 판단=최악" = 최우선.***

---

## 1. 사이트 목적
**YOUNGMAN / 영맨** — 1인 사업자용 AI 영업·CRM 플랫폼.
- 슬로건: "고객관리, 이제 전화만 하세요". 전화 통화를 AI가 자동 요약 → 고객관리대장(장부)에 자동 보관.
- 기능: CRM(고객) / HRM(조직도·계약자 관리대장) / 마케팅 / 로또 / 단체 SMS / **AI 통화요약→미확인요약→고객관리대장** / 사주풀이·궁합.
- 디자인: 한국 캘리그라피 + 인장(seal-red `#c8362c`). Apple/Linear 미니멀. **사용자 60대+ 다수 → 고객데이터 글자는 가독성 우선(작은 글자/얇은 weight 금지)**. ※ 프로모/안내 요소는 사장님 선호로 **붉은색 지양 → 딥네이비+골드** 톤 사용(붉은색=경고등 느낌).
- 라이브: https://youngman-biz.com (Cafe24 + Supabase + MariaDB + PHP). 앱: Google Play `com.youngmanapp`.
- **결제**: Google Play Billing(구독 + 일회성 충전권). PortOne+토스 코드는 미래용 keep.
- 앱: RN Android WebView + bridge.js + native CallScreening/통화후모달/명함첩 overlay.
- 사업자: 어센트라(Ascentra) 393-39-01518 / 대표 장동훈 (nxnxax@gmail.com = 하드코딩 admin).

---

## 2. 주요 파일 구조
```
[프론트] index.html(첫화면+이벤트팝업+Google Play버튼+PC유틸바+상단 안내배너+★pageview 직접발사)
 import-guide.html(타사 통화기록 가져오기 설명서 — 네이비+골드, 자체호스팅 영상+관리자 업로드)
 subscribe.html(구독·할인가·결제후폴링) / billing.html(구독·충전권 관리) / admin.html(매출탭+진단탭+통계탭)
 customers.html(+?demo=1 광고데모) / logout.html(로그아웃 transition·이중 재발사 가드) / login-complete.html
 profile / org / contracts / forms / board / card-builder / lotto2233.html(사주·궁합) / unreviewed(미확인요약)
 manifest.json + sw.js(PWA, display:browser=일반 탭 바로가기) + app-icon-192.png
[공통 JS]
 auth-shared.js — Supabase + 헤더/nav + 인증 + apiRequest + bootApp + tryLogPageview(bootApp 안에서만 발사!)
   exports: initSupabase/getSession/getAccessToken/apiRequest/isAdmin/bootApp/mountAppHeader 등
 customers.js / admin.js(매출 loadRevenue) / bridge.js(RN: notifyLogin/Logout, snapshot)
[PHP API — cafe24 webroot flat. api/xxx.php → /xxx.php, api/billing → /billing/]
 records.php — CRUD + auth-profile + admin-*(revenue/payment-trace/backlog-holds/overcharge-scan) + 통계(pageview daily,
   DAU/MAU) + customer-log(send_to_group=ledger mirror) + mark_usage(차감+ledger 진입 게이트) ★전 응답 no-store
 process-recording.php — 통화 audio=audio_pending만 저장(lazy-STT). 한도/topup 게이트(async분기 앞)
 recording-callback.php — Railway STT 결과 → auto_confirm=1 이면 customer_log INSERT + ledger mirror + FCM
 pageview.php — 방문자/유입 트래킹(session_id distinct, is_bot 필터, UTM 저장). bootApp/index가 POST
 install-ping.php(신규) — 앱 설치 1회 핑(install_log, device_id UNIQUE dedup, KST, 무인증·always200)
 admin-stats.php(신규) — 일별 installs/signups/payments(KST 자정), admin(nxnxax) 토큰검증
 guide-video.php(신규) — import-guide 안내영상 GET(공개조회)/POST(관리자 업로드, media/import-guide.mp4)
 billing_helpers.php / fcm_helpers.php(채널 yk_admin_notice_v1)
 billing/verify-google-purchase.php / rtdn.php(자동갱신 자가치유) / verify-payment / cron-renew / webhook-portone
   / cancel-subscription / topup-verify / topup-settings / config / google_play_helpers
 billing/topup-testsetup.php·notify-test.php — (임시 guarded 테스트, 출시 전 비활성 예정)
 saju-reading.php(Qwen 점쟁이 사주/궁합 — 호출당 activity_logs 1건)
[Railway worker] worker/main.py — STT(Together Whisper) + LLM(Together Qwen). 둘 다 Together.
[배포] .github/workflows/deploy.yml — 파일 명시 cp+lint 후 FTP. .env=GitHub Secrets 재조립.
```

---

## 3. 현재 완성된 기능
**핵심(라이브):** AI 통화요약(Together Whisper+Qwen, 줄글/보고서형 v2) / 미확인요약→고객관리대장(라지-STT) / 회차 자물쇠 / 명함첩 FCM / PII AES-256-GCM / 조직도·계약자·고객 관리대장 / 단체 SMS / 로또(JSON 미러) / 사주·궁합 / Google Play 구독(Sales 14,900·Master 28,900·Agency 39,900 VAT포함)·충전권·차감 게이트(■4).

**이번 세션(2026-06-23) 완성:**
- **★ 방문자 집계 핵심버그 수정**: pageview는 `bootApp()` 안에서만 발사되는데 **index.html은 bootApp 미사용** → 광고로 youngman-biz.com(메인)에 들어온 유입이 전량 카운트 누락이었음. index에 동일 키('erp.pvSid')·페이로드로 **직접 1회 발사** 추가(auth-shared 무변경=캐시 캐스케이드 회피). 이제 광고 유입 집계됨. (과거 누락분은 복구 불가)
- **사주/궁합 중복호출 차단**: runSaju/runGunghap이 버튼 비활성화·await 없어 다중클릭 시 Together Qwen이 2~3회 호출(활동로그 연달아 + 비용 2~3배). onGenerate 진입가드 + saju/gunghap 버튼 disable+await로 **1회만**.
- **통화기록 가져오기 안내 배너 + 설명서**: index 상단 배너(모바일 full-bleed·PC 풀폭, **딥네이비+골드 동적 shimmer·라인SVG 아이콘**, "타사 전화앱 통화기록에서 / 영맨으로 전송 메뉴얼") → `import-guide.html`(5단계 스테퍼 + 결과 안내).
- **안내영상 = 자체호스팅 + 관리자 업로드**: 유튜브 제거 → `<video>`(9:16 object-fit:cover, autoplay·mute·loop). `guide-video.php`로 **관리자(nxnxax)만** 업로드(고정파일명 media/import-guide.mp4, finfo MIME검증, 80MB). 일반회원엔 업로드 UI 안 보임. ※ **사장님이 1080×1920 mp4 직접 업로드 필요(아직 미업로드 → "준비 중" placeholder).**
- **앱팀 통계 스펙 구현**: `install-ping.php`(install_log, device_id UNIQUE dedup, KST, 무인증·always200) + `admin-stats.php`(?start_date&end_date, admin 토큰, daily installs/signups/payments_new/payments_active_total + summary current_active, KST 자정, raw.errors 투명성). 스펙의 'users'→실제 `members`로 매핑.
- **직전 세션**: 유령 로그아웃 이중가드(logout.html) / RTDN 자동갱신 사용량 자가치유(rtdn.php) / PWA 일반탭 바로가기 / 관리자 매출탭+실시간 결제 FCM / LG 무더기차감 방어·스캐너.

---

## 4. 아직 미완성 / 검증 대기
- **★ 앱팀 RN 작업(통계)**: ① 첫 실행 시 device_id(uuid) 생성+AsyncStorage 저장+`/install-ping.php` 호출(Install Referrer 추출) ② 사장님 전용 admin 화면(`/admin-stats.php` 호출, 일별 그래프). 서버는 완성·라이브. 앱 훅 붙이면 사장님 폰 첫 핑으로 실측 검증.
- **★ 사후 통화 가져오기(share-import)**: 타사앱 통화→공유→영맨이 미확인요약에 머묾. **서버 무변경 진단 완료**: 앱이 ledger 진입 게이트 `mark_usage(click_type='auto_confirm', user_initiated:true)`를 호출하면 해결. 앱팀 전달 완료, 앱 수정+사장님폰 v229+ 재테스트 대기.
- **★ 로그아웃 native robustness(앱팀)**: web 이중가드로 재발사는 막음. native `isExplicitlyLoggedOut`가 유효 세션 와도 안 풀리는 좀비 상태 → 앱팀 ①유효 auth.login 시 해제 ②모달을 플래그만으로 죽이지 말 것.
- **안내영상 업로드**: 사장님이 1080×1920 mp4를 import-guide.html 관리자 업로드로 올려야 영상 표시됨.
- **RTDN 자동갱신 실증**: 첫 유료 갱신일에 매출탭 실시간결제내역 확인.
- **출시 전 정리**: topup-testsetup.php / notify-test.php 비활성화. LG 피해자 보상 결정(사장님) + 자동 양식전송 부당청구 앱 수정([[project_auto_confirm_overcharge]]).

---

## 5. 배포 방식
- GitHub Actions → FTP(cafe24). deploy.yml이 **파일 명시 cp+lint 후 업로드** → **신규 파일(PHP/정적)은 cp 등록 필수**(안 하면 라이브 404). api/xxx.php → /xxx.php, api/billing → /billing/.
- 런타임 생성 디렉토리(api/uploads, media/)는 FTP가 mirror-delete 아니라 **배포해도 보존됨**(기존 업로드가 살아있는 게 증거).
- 클로드 직접 push 가능(PAT). **GitHub Secret 쓰기는 사장님 UI**. "배포/올림"=자율 push→Actions→verify까지(중간 승인 묻지 않음).
- 검증: `curl -sk https://youngman-biz.com/<file>?cb=$(date +%s)` (PHP GET 401/405=정상, 404=cp 누락). **cafe24 WAF가 본문없는 probe POST를 502로 막음** — 실제 데이터 담긴 요청은 정상 통과(502≠버그). `gh` CLI 없음 → 라이브 curl 폴링으로 검증.
- **캐시버스트**: JS/CSS module 변경 시 모든 importer `?v=` 같은 commit 갱신. auth-shared.js 변경은 전 페이지 ?v 통일(인스턴스 2개=인증 깨짐) → 가능하면 **auth-shared 안 건드리는 방향**으로 우회. node module 문법검사 = `node --input-type=module --check < file.js`.

---

## 6. Cafe24/PHP 주의사항
- 🚫 **SSH/SCP 금지**(silent drop), FTP only. cafe24 cron 미지원 → GitHub Actions schedule.
- 🚫 빈 POST body→5xx/WAF 502. ffmpeg 미설치(transcode는 Railway worker). **DB 직접접근 불가** → admin 패널·진단탭·Railway 로그·사장님 SQL로만 진단/정리.
- 🕐 **시간대 설정이 전역에 없음** → 신규 시간 관련 엔드포인트는 `date_default_timezone_set('Asia/Seoul')` + MySQL `SET time_zone='+09:00'` 명시. 기존 저장값은 KST로 간주(매출탭이 정상 동작 = cafe24 서버 KST). install-ping은 **서버가 KST stamp**(기기시계 무관).
- 🔑 records.php 시작 `opcache_invalidate(__FILE__,true)` 유지. .env 값 따옴표 금지·JSON 한 줄. 이메일 비교 `WHERE LOWER(email)=LOWER(:e)`.
- 🔐 `YOUNGMAN_CRYPTO_KEY` 분실=복호화 영구 불가. 🚫 `git add -A` 금지(PII) — 명시 파일만.
- **★ 모든 API 응답 `Cache-Control: no-store`** — 누락 시 WebView 캐시로 "바꿔도 안 바뀜".
- 🆙 파일 업로드 엔드포인트: 고정 파일명(traversal 방지)+finfo MIME 실검증+크기상한+media/.htaccess 스크립트차단. (upload.php·guide-video.php 패턴)

---

## 7. 최근 수정한 파일 (2026-06-23 세션)
```
방문자버그:  index.html (fireLandingPageview 직접 발사 추가)
사주 중복:   lotto2233.html (onGenerate 가드 + runSaju/runGunghap 버튼 disable+await)
배너/설명서: index.html (promo-banner 네이비+골드 동적, 모바일 full-bleed, 문구)
            import-guide.html (신규→재작성: 자체호스팅 video + 관리자 업로드 UI)
안내영상:    api/guide-video.php (신규: 관리자 업로드 + 공개조회)
통계:        api/install-ping.php (신규) / api/admin-stats.php (신규)
배포등록:    .github/workflows/deploy.yml (import-guide / guide-video / install-ping / admin-stats)
[직전] logout.html(이중가드) rtdn.php(자가치유) manifest.json sw.js app-icon-192.png
  admin.html·admin.js·records.php(매출탭) fcm_helpers.php index.html(Google Play버튼)
```

---

## 8. 절대 건드리면 안 되는 부분
- 🔒 **검증된 통화/STT/인증/결제 흐름은 사장님 명시 동의 없이 변경 금지**([[feedback_no_working_flow_break]]). baseline commit 기록 + 롤백 준비.
- 🔒 **고객관리대장 진입 = `mark_usage(click_type='auto_confirm')` 게이트**. process-recording은 audio_pending만 저장(lazy-STT). 이 모델 유지.
- 🔒 **한도/차단 검사는 "실제 차감지점 + 모든 early-return(async enqueue) 앞"**. async 분기 뒤 = 죽은코드([[feedback_polis_algorithm]] 11).
- 🔒 **결제 단일기준 = `portone_plan_amount`**(공급가+VAT=청구액 일치). mark_usage만 차감(usage_counted_at 멱등).
- 🔒 **방문자 pageview는 'erp.pvSid' 세션키 + is_bot=0 distinct**. index의 fireLandingPageview / auth-shared.tryLogPageview 동일 키 유지(다르면 visitor 중복/누락).
- 🔒 통계 엔드포인트(install-ping/admin-stats) **KST 고정 + admin(nxnxax) 게이트** 유지. install_log.device_id UNIQUE(dedup 핵심).
- 🔒 logout.html 이중가드(navType + 소비마커) 유지. records.php no-store / opcache_invalidate 유지.
- 🔒 worker `CLAUDE_SYSTEM_PROMPT` 무수정. admin 판별=`is_admin_email`(nxnxax 화이트리스트). Google Play 구독자는 DB로 plan 못 내림(앱 재검증이 되돌림) — 해지해야 free.

---

## 9. 다음에 이어서 해야 할 작업
1. **★ 통계 — 앱팀 RN 훅 연동 검증**: install-ping(첫 실행 1회 핑) + admin 화면(admin-stats 호출). 서버 완성. 사장님 폰 첫 핑이 admin-stats installs에 잡히는지 실측.
2. **★ 사후 가져오기(share-import)**: 앱이 `mark_usage(auto_confirm, user_initiated:true)` 호출로 수정 → 사장님폰 v229+ "기본그룹>고객관리대장" 즉시표시 확인.
3. **★ 로그아웃 native robustness**: 앱팀 isExplicitlyLoggedOut 해제조건 2가지 반영 확인.
4. **안내영상 업로드**: 사장님이 import-guide.html 관리자 UI로 1080×1920 mp4 업로드.
5. **광고 분석**: 광고 목적지가 사이트(youngman-biz.com)면 이제 방문자 잡힘. UTM 붙이면 관리자>통계>유입경로에 google/meta 집계. (구글 동영상캠페인 "조회수"는 시청수≠방문, 앱설치 캠페인은 스토어로 가 웹 미집계 — 정상)
6. **출시 전 정리**: 테스트 endpoint 비활성화. RTDN 갱신 실증. LG 피해자 보상 결정.

---

## 환경 / 작업 원칙
- **호칭: 사장님**. "쉬세요" 금지. "PoC"→"테스트", "ship/deploy"→"배포/올림". 토큰 최소·짧은 답변·일반인 용어. 외부 채팅(앱팀) 붙여넣기용은 코드블록.
- **"proceed?" 묻지 말 것** — 함의된 체인 끝까지 실행 후 보고. "배포/올림"은 push→검증까지 자율.
- **"정밀작업해" 트리거** = 원인 하나 찾고 바로 고치지 말고, 다른 원인·충돌·부작용 전체 교차검증 후 한 번에([[feedback_precision_work]]).
- **★ "수치가 이상하다" = 설명·발뺌 먼저 X, 코드부터 깐다**(2026-06-23 교훈: 방문자 0이 "광고 탓"인 줄 알았으나 index pageview 버그였음). 트래킹/분석 코드는 핵심 페이지(랜딩)가 실제로 타는지 grep 확인.
- **★ 매 작업 전/완료선언 전 [[feedback_polis_algorithm]] 점검표 + 실패빈도 지도 확인** — 세션 클리어로 경험 휘발, 이 메모리가 유일한 누적. 새 실패 패턴 즉시 기록.
- 메모리 인덱스 = `~/.claude/projects/-home-user-jdhoon/memory/MEMORY.md`. 핵심: `feedback_polis_algorithm`(점검표·실패빈도지도, 작업시작 1순위) / `feedback_auth_flow_lessons`(인증 증상 첫참조) / `feedback_api_cache_nostore`·`feedback_cache_bust_always`(안바뀜=캐시) / `project_topup_and_launch_pricing`·`project_new_pricing_2026_05_26`(결제) / `project_auto_confirm_overcharge` / `feedback_pii_isolation` / `deploy_cafe24` / `feedback_precision_work`.
```
