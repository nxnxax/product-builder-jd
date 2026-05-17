# CALL_RECORDING_BACKEND — 웹팀 회신 spec

앱팀이 보낸 *"Backend spec — Call recording → AI summary → CRM ledger"* 에 대한
웹팀(youngman-biz.com / cafe24+PHP) 회신.

원본 spec 은 Supabase-only 셋업을 가정해서 작성됐는데, 우리 백엔드는
**cafe24 호스팅 + PHP + MariaDB + Supabase Auth(인증만)** 구조라서
구현 경로가 다르다. 인터페이스 합의 먼저 잡고 PHP 구현 시작.

---

## Phase 1 변경 이력 (2026-05-17 e2e 1차 검증 완료 시점 반영)

초기 spec 합의 후 e2e 테스트 라운드를 거치면서 확정된 변경 사항. 본문 §5/§6 등은 합의 시점 그대로 두고, 라이브 작동 사양은 이 섹션이 정본.

### A. STT 엔진 변경 — OpenAI Whisper → Naver CLOVA Speech

- **원인**: Samsung T전화 통화녹음 `.m4a` 가 실제로는 3gp4 컨테이너 (AMR-NB/AMR-WB 코덱). Whisper 지원 목록에 3gp 없음. cafe24 ffmpeg 미설치 (`exit=127`) 라 서버측 transcode 불가.
- **결정**: STT 엔진 자체를 NCP CLOVA Speech (Long Sentence Recognition) 로 교체. 3gpp/AMR 네이티브 지원 + 한국어 정확도 우위.
- **변경 endpoint**: `POST {NCP_CLOVA_INVOKE_URL}/recognizer/upload`, 헤더 `X-CLOVASPEECH-API-KEY`, multipart `media` + `params` (`language=ko-KR`, `completion=sync`, `fullText=true`, `diarization` 2명).
- **secrets 추가**: GitHub Secrets `NCP_CLOVA_INVOKE_URL`, `NCP_CLOVA_SECRET` → `deploy.yml` `.env` assembly 에서 자동 주입.
- **`ai_model` 컬럼 값**: `gpt-4o-mini` → `naver-clova-speech+gpt-4o-mini` (STT+요약 합산 표시).
- **앱 측 영향**: 0 (response shape 동일).

### B. LLM 요약 — `customer_name` 결정 룰 7단계 + `customer_name_hint` body 필드

LLM 이 추측으로 customer_name 채우지 않도록 prompt 강화 + 앱 측 폰 contacts lookup 결과를 받을 수 있는 hint 필드 신설.

**룰 우선순위** (백엔드에서 LLM 호출 결과 덮어쓰는 §1 포함):

| 순위 | 조건 | customer_name 값 |
|---|---|---|
| §1 | request body 에 `customer_name_hint` 제공 (앱 측 contacts lookup 결과) | hint 값 그대로 (LLM 출력 무시) |
| §2 | transcript 에 이름 명시 | "{이름}님" |
| §3 | "사장님" 호칭 + 나이/연령대 명시 | "{연령대}대 남성" |
| §4 | "사모님" 호칭 + 나이/연령대 명시 | "{연령대}대 여성" |
| §5 | "사장님" 호칭만 (나이 없음) | "남성" |
| §6 | "사모님" 호칭만 (나이 없음) | "여성" |
| §7 | 정보 없음 | "고객" |

금지: 음성 timbre / 어휘 / 말투로 성별·연령 추정. 영업측이 다른 사람을 부른 호칭은 적용 제외. Few-shot 테스트 케이스 prompt 안에 포함.

**`customer_name_hint` body 필드 (NEW)**:

```json
POST /process-recording.php
{
  "storage_path": "...",
  "client_request_id": "...",
  "phone_number": "...",
  "customer_name_hint": "string | null"   // NEW (선택, 최대 80자)
}
```

서버 처리: `customer_name_hint` 가 비어있지 않으면 LLM 출력 무시하고 hint 값으로 강제 지정. 비어있거나 누락이면 LLM 출력 (§2~§7) 사용.

**앱 측 영향**: hint 안 보내면 자동 폴백. 앱 측 READ_CONTACTS 권한 + native module 작업 끝나면 hint 보내기 시작하면 됨.

### C. `records.php` customer-log resource — 자체 인증 + 응답 shape 통일

**문제**: 같은 JWT 가 `upload.php` / `process-recording.php` 는 200 통과하는데 `records.php` 만 `"지원하지 않는 인증 토큰"` 401 떨굼. `verify_auth_token` 의 firebase/HS256/userinfo 다단계 검증 중 어느 단계에서 fail.

**해결**:
- `records.php` 의 `$selfAuthResources = ['customer-log']` 등록 → global `verify_auth_token` 우회.
- `customer-log` resource handler 안에서 자체 `/auth/v1/user` 호출 (검증된 단순 패턴, `upload.php` 와 동일).
- 다른 resource (admin/customers/forms 등 web 페이지가 의존) 의 인증 흐름은 그대로 유지 → 회귀 위험 0.

**응답 shape 통일** — customer-log resource 의 모든 응답을 spec §4 표준으로:

| 상황 | 이전 | 변경 후 |
|---|---|---|
| 성공 (단일) | `{ok:true, customer_log:{...}}` | `{status:"ok", customer_log:{...}}` |
| 성공 (list) | `{ok:true, items:[...], next_before}` | `{status:"ok", items:[...], next_before}` |
| 성공 (delete) | `{ok:true}` | `{status:"ok"}` |
| 인증 실패 | `{ok:false, error:"..."}` | `{status:"error", code:"unauthorized", message:"..."}` |
| id/patch 검증 실패 | `{ok:false, error:"..."}` | `{status:"error", code:"invalid_request", message:"..."}` |
| 없거나 권한 없음 | `{ok:false, error:"..."}` | `{status:"error", code:"not_found", message:"..."}` |
| 마이그레이션/upstream | `{ok:false, error:"..."}` | `{status:"error", code:"upstream_failed", message:"..."}` |

**앱 측 영향**: ApiError 핸들링 코드 spec §4 표준 기준이라 이번 변경으로 records.php 응답도 자연스럽게 처리됨 (이전엔 records.php 만 별도 분기 필요했을 것).

### D-extra. 옵션 D — 양식 전송 (customer_log → ledger_records mirror)

앱팀 추가 요청 (Req 2/3) 통합 처리. customer_log 와 ledger 시스템이 분리된 구조라서 둘을 연결하는 명시적 transfer endpoint 추가.

**흐름:**
```
1. /process-recording.php → customer_log row 생성 (기존, 변경 없음)
2. 앱 SummaryReview 모달에서 필요 시 customer_log_update 로 편집
3. 앱이 "양식 전송" 버튼 → GET /records.php?resource=ledger-groups&page_type=customer 로 그룹 목록 조회
4. 사용자가 그룹 선택 (0개면 모달이 "기본 그룹 자동 생성" 옵션 표시 또는 group_id=null 로 호출)
5. 앱: POST /records.php?resource=customer-log
   {
     "action": "customer_log_send_to_group",
     "id": "<customer_log_id>",
     "group_id": <ledger_group_id | null>,
     "override": { "customer_name": "...", "agent_memo": "..." }   // 선택, 사용자가 모달에서 수정한 값
   }
6. 백엔드:
   - customer_log row owner 일치 + group_id owner+page_type='customer' 검증
   - group_id null/invalid → 자동 default 그룹 생성 (제목 "그룹제목을 설정해주세요")
   - customer_log 의 9필드 복호화 → override 적용 → data_json 으로 ledger_records insert
   - customer_log.linked_ledger_record_id 컬럼에 새 ledger_records.id 저장
7. 응답: { status: "ok", customer_log: {...}, ledger_record: {...}, group: {...} }
```

**Idempotency:** 같은 customer_log 가 이미 전송됐으면 (`linked_ledger_record_id` 존재) 새 ledger_record 만들지 않고 기존 것 반환 (`duplicate: true`).

**자동 default 그룹 정의:**
- `owner_email = current`, `page_type = 'customer'`, `name = '그룹제목을 설정해주세요'`, `is_default = 1`
- `field_schema_json` (AES-256-GCM 암호화 저장) = 9필드 평행 매핑:
  - `customer_name` (text, "고객명") / `phone_number` (text, "연락처") / `consult_at` (text, "상담 일시")
  - `summary` (textarea, "요약") / `interest` (text, "관심 항목") / `inquiry` (textarea, "문의 내용")
  - `budget_condition` (text, "예산/조건") / `next_action` (text, "다음 액션") / `agent_memo` (textarea, "내 메모")

**스키마 변경:** `customer_log` 테이블에 `linked_ledger_record_id INT NULL` 컬럼 + 인덱스 추가. lazy ALTER (records.php / process-recording.php 양쪽 ensure 함수 동기화).

**응답 `customer_log_row`:** `linked_ledger_record_id` 필드 노출 (앱이 양식 전송 여부 즉시 확인).

### E. Admin quota bypass (Req 1)

운영자 계정 (`nxnxax@gmail.com`) 은 통화 요약 quota 영구 우회. `process-recording.php` 의 plan 체크 + counter 증분 두 분기에서 `is_admin_email_for_recording()` 검사:

```php
$isAdminUser = is_admin_email_for_recording($ownerEmail);
if (!$isAdminUser && $plan === 'free' && $freeUsed >= 5) { 403 plan_required }
// ...
if (!$isAdminUser && $plan === 'free') { UPDATE free_summaries_used += 1 }
```

allowlist 는 records.php 의 `admin_email_allowlist()` 와 같은 패턴 (`['nxnxax@gmail.com']`). 추후 admin 추가 시 두 곳 모두 갱신 필요 (admin 추가 빈도 낮아 trade-off 수용). `members.is_admin` 컬럼 도입 안 함.

**Quick fix 병행:** 운영자가 phpMyAdmin 으로 `UPDATE members SET plan='premium', free_summaries_used=0 WHERE email='nxnxax@gmail.com'` 실행하면 기존 막힌 상태 즉시 해제. Long-term code fix 는 deploy 시점에 자동 적용.

### F. Phase 2 — async + FCM 인프라 (Milestone 1: 토큰 등록 + 스키마)

비동기 처리 + 푸시 알림을 위한 토대. **M1 은 schema + FCM 토큰 등록만**. async mode 분기 + FCM 발송 코드는 M2/M3 에서.

#### M1 변경 사항

**테이블:**
- `user_fcm_tokens` — owner_email / token (UNIQUE) / device_id / platform / last_seen_at / created_at
- `recording_jobs` — id(CHAR(36)) / owner_email / customer_log_id / status (queued|processing|completed|failed) / storage_path / client_request_id (UNIQUE per owner) / error_message / fcm_sent_at / started_at / completed_at / timestamps

**resource:** `app-fcm-token` (records.php whitelist + selfAuthResources 등록 — customer-log 와 같은 self-auth + spec §4 응답 shape)

**호출:**
```
POST https://youngman-biz.com/records.php?resource=app-fcm-token
Authorization: Bearer <JWT>

{ "action": "register",   "token": "<FCM 토큰>", "device_id"?: "...", "platform"?: "android"|"ios" }
{ "action": "unregister", "token": "..." }
{ "action": "list" }   // 사용자 본인 토큰 목록 (마스킹)
```

응답 (register/list):
```json
{ "status": "ok", "fcm_token": { "id", "token_masked", "device_id", "platform", "last_seen_at", "created_at" } }
{ "status": "ok", "items": [{...}, ...] }
{ "status": "ok", "deleted": 1 }
```

**UPSERT 동작**: 같은 token 이 다른 owner_email 로 재등록 시 owner 갱신 (계정 전환). last_seen_at 자동 touch.

**보안**: token 응답에 마스킹 (`abcdef12...wxyz`). 평문 token 응답 안 함.

#### 앱 측 통합

bridge.js 의 FCM 토큰 수신 핸들러에서 `app-fcm-token register` 호출. SIGNED_IN 시 등록 / SIGNED_OUT 시 unregister / 토큰 refresh 시 재등록.

#### M2 (다음 commit)
- `process-recording.php?mode=async` 분기 → 즉시 `{status:"queued", job_id}` 응답
- 백그라운드 처리 (PHP `fastcgi_finish_request` + `ignore_user_abort`)
- recording_jobs row 생성/갱신 → 완료 시 customer_log row insert

#### M3 (그 다음 commit)
- FCM HTTP v1 API 호출 (Firebase service account JSON 으로 OAuth token 발급)
- 작업 완료 시 user_fcm_tokens 의 owner 토큰들로 push
- 사용자 작업 필요: Firebase 콘솔에서 service account JSON 다운로드 + GitHub Secret `FIREBASE_SERVICE_ACCOUNT_JSON` 등록

#### M4 (별개)
- cron 으로 24h 미정리 audio 파일 cleanup (실패 retry 윈도우용)

### D. e2e 1차 검증 결과 (2026-05-17)

| 라운드 | 호출 | 결과 |
|---|---|---|
| R1 — upload | `POST /upload.php` (multipart) | 200 / `status:ok` / storage_path 반환 ✓ |
| R1 — process | `POST /process-recording.php` | 200 / Clova STT + gpt-4o-mini 요약 / customer_log row 생성 ✓ |
| R2 — update | `POST /records.php?resource=customer-log` action=update | 200 / patch 반영 / 다른 필드 무변경 ✓ |
| R3 — delete × 2 | action=delete | 200 / `{status:"ok"}` × 2 ✓ |

**검증 항목** (앱팀 라운드 1 응답 페이로드 기준):
- `status: "ok"`, `id: CHAR(36) UUID`, `summary` 한국어 3문장, `transcript` Clova STT 정상, `audio_kept: false`, `ai_model: naver-clova-speech+gpt-4o-mini`, `customer_name: "고객"` (호칭 없는 짧은 통화 룰 §7 폴백) — 모두 spec 부합.

**Idempotency 패턴 합의** (Phase 2 반영 예정): 앱 측이 `client_request_id` 를 audio file uri 기준 deterministic 으로 생성. 401/네트워크 에러 후 재시도 시 같은 id 그대로 → 서버 24h idempotency 분기로 같은 row 반환 (Clova/LLM 비용 0). 사용자 "다시 처리" 시에만 새 id.

---

## 0. TL;DR — 앱 client 코드에 영향 있는 변경

| 영역 | 원본 spec | 우리 구현 | 앱 client 영향 |
|---|---|---|---|
| 오디오 업로드 | `supabase.storage.from('recordings').upload(...)` | `POST https://youngman-biz.com/upload.php` (multipart, Bearer JWT) | **변경** — supabase-js storage 호출 제거, fetch 로 교체 |
| Process endpoint | `{SUPABASE_URL}/functions/v1/process-recording` | `POST https://youngman-biz.com/process-recording.php` | **변경** — URL 만 |
| 요청 body | spec §4 | spec §4 와 동일 (단 `storage_path` 형식은 cafe24 상대경로) | 영향 적음 |
| 응답 body | spec §4 | spec §4 와 동일 shape (PII 는 서버에서 평문 복호화 응답) | 영향 없음 |
| 후속 수정 (PATCH/DELETE) | `supabase-js .from('customer_log').update(...)` | `POST https://youngman-biz.com/records.php` (action 파라미터) | **변경** — supabase-js 직접 호출 제거 |
| Auth | Supabase JWT (Bearer) | Supabase JWT (Bearer) | 동일 |
| `id` 타입 | `uuid` | `CHAR(36)` uuid (서버 생성) | 동일 |
| `user_id` 컬럼 | `uuid REFERENCES auth.users` | **`owner_email VARCHAR(255)`** (서버 내부 격리 키) | 서버 내부 — 앱은 보내지 않음 (JWT 에서 추출) |
| Plan 컬럼 위치 | `profiles.plan` | `members.plan` / `members.free_summaries_used` | 서버 내부 |
| `customers` FK | `customer_id uuid REFERENCES customers(id)` | **FK 제거**, `phone_number` 기반 lookup | 서버 내부 |

요약: **2개의 HTTP 호출 URL 바꾸기 + supabase-js storage/db 호출 → fetch 로 교체**.
나머지는 서버 내부 사정이라 앱에 영향 없음.

---

## 1. 우리 쪽 4가지 결정 (사용자 확정)

1. **구현 경로**: PHP on cafe24 — 기존 AI 기능(saju-reading.php, card-design.php) 과 동일 패턴 (OpenAI/Anthropic curl-direct). Edge Function 신설 안 함.
2. **파일 저장소**: cafe24 disk — 기존 upload.php 패턴 재사용, audio 전용 디렉터리 신설. Supabase Storage 미사용.
3. **무료 요약 횟수**: **5회** (`FREE_QUOTA = 5`).
4. **오디오 보존**: 요약 성공 후 즉시 삭제 — `audio_kept` default `false`. 실패 시 1회 재시도 위해 24h 유지 후 cron 삭제.

---

## 2. 오디오 업로드 — cafe24 disk

### Endpoint

```
POST https://youngman-biz.com/upload.php
Authorization: Bearer <user JWT>
Content-Type: multipart/form-data
```

### Request

multipart form fields:

| field | type | required | 비고 |
|---|---|---|---|
| `file` | binary | yes | 오디오 파일 (50MB 이하) |
| `kind` | string | yes | 고정값: `"recording"` |
| `recorded_at` | string | no | ISO8601 (e.g. `"2026-05-17T17:16:26+09:00"`) — 디렉터리 분기 (yyyy-mm-dd) 에 사용 |
| `original_filename` | string | no | logging 용 |

서버 측 검증:
- JWT → `email` 추출 → `owner_email` 격리 키
- mime: `audio/mp4 m4a 3gpp amr ogg mpeg wav aac opus` 중 하나
- 파일 크기 ≤ 50 MB
- 저장 경로: `uploads/recordings/<sha256(owner_email)[0:16]>/<yyyy-mm-dd>/<uuid>.<ext>`

### Response (200)

```json
{
  "status": "ok",
  "storage_path": "uploads/recordings/a1b2c3d4e5f60718/2026-05-17/9f0e8d7c-...m4a",
  "bytes": 384210,
  "mime": "audio/mp4"
}
```

`storage_path` 는 다음 `/process-recording.php` 호출에 그대로 전달.

### Error

| HTTP | code | 메시지 |
|---|---|---|
| 401 | `unauthorized` | JWT 누락/만료 |
| 413 | `file_too_large` | > 50MB |
| 415 | `unsupported_mime` | mime 미허용 |
| 500 | `upload_failed` | disk 쓰기 실패 |

---

## 3. `customer_log` 테이블 — MariaDB 버전

원본 spec §2 의 PostgreSQL DDL 을 MariaDB + PII 암호화 정책에 맞춰 변환.

```sql
CREATE TABLE IF NOT EXISTS customer_log (
  id CHAR(36) PRIMARY KEY,            -- UUID v4, 서버 생성
  owner_email VARCHAR(255) NOT NULL,  -- ★ user_id 대신. JWT email 에서 추출
  customer_phone_lookup VARCHAR(64) DEFAULT NULL,
    -- ★ customers FK 대신. 평문 phone E.164 정규화 hash 후 비교용

  -- PII (AES-256-GCM 'enc:v1:' prefix — crypto_helpers.php 통과)
  customer_name VARCHAR(255) DEFAULT NULL,
  phone_number VARCHAR(255) DEFAULT NULL,
  summary TEXT NOT NULL,
  interest TEXT DEFAULT NULL,
  inquiry TEXT DEFAULT NULL,
  budget_condition TEXT DEFAULT NULL,
  next_action TEXT DEFAULT NULL,
  agent_memo TEXT DEFAULT NULL,
  transcript LONGTEXT DEFAULT NULL,

  -- meta (비암호화)
  consult_at DATETIME NOT NULL,
  audio_storage_path VARCHAR(512) DEFAULT NULL,
  audio_kept TINYINT(1) NOT NULL DEFAULT 0,   -- ★ default false (요약 성공 후 삭제)
  ai_model VARCHAR(64) DEFAULT NULL,
  ai_generated_at DATETIME DEFAULT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'app-auto',
    -- 'app-auto' | 'app-manual' | 'web' | 'manual-entry'

  client_request_id VARCHAR(64) DEFAULT NULL,  -- idempotency key
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY ix_customer_log_owner (owner_email, consult_at),
  KEY ix_customer_log_phone (owner_email, customer_phone_lookup),
  UNIQUE KEY ux_customer_log_idem (owner_email, client_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**격리 규칙 (records.php 패턴과 동일):**
- 모든 SELECT/UPDATE/DELETE 는 `WHERE owner_email = :jwt_email` 강제.
- RLS 없음 — PHP 레이어에서 강제 (`PII isolation` memory rule).
- admin 우회 없음.

**PII 응답 시 복호화:**
서버에서 `customer_name`, `phone_number`, `summary`, `interest`, `inquiry`, `budget_condition`, `next_action`, `agent_memo`, `transcript` 는 `decrypt_pii()` 통과 후 평문으로 응답. 앱은 평문만 받음.

---

## 4. `members` 테이블 plan/quota 컬럼

`profiles` 가 아닌 기존 `members` 테이블 (records.php:215) 에 추가.

```sql
ALTER TABLE members
  ADD COLUMN IF NOT EXISTS plan VARCHAR(16) NOT NULL DEFAULT 'free',
  ADD COLUMN IF NOT EXISTS free_summaries_used INT NOT NULL DEFAULT 0;
```

조회 키: `members.email` (이미 PRIMARY 또는 UNIQUE).

**plan 값:** `'free' | 'premium'`
**무료 quota:** 5 (서버 상수). `'free'` 이면서 `free_summaries_used >= 5` → 403 `plan_required`.

`auth.login` 브리지 payload 에 다음 두 필드 echo 권장 (앱이 UI 에 즉시 반영):
```json
{ "plan": "free", "free_summaries_used": 2 }
```
(추후 bridge.js 에서 onAuthStateChange 시 records.php 한 번 더 fetch 해서 채우면 됨 — 앱팀 변경 불필요)

---

## 5. `process-recording.php` endpoint

### Request

```
POST https://youngman-biz.com/process-recording.php
Authorization: Bearer <user JWT>
Content-Type: application/json
```

```json
{
  "storage_path": "uploads/recordings/a1b2c3d4.../2026-05-17/9f0e8d7c-...m4a",
  "duration_sec": 273,
  "original_filename": "01059102542_20260517171626.m4a",
  "recorded_at": "2026-05-17T17:16:26+09:00",
  "phone_number": "010-5910-2542",
  "client_request_id": "uuid-from-app"
}
```

`storage_path` 는 `/upload.php` 응답에서 받은 값 그대로 전달.

### 서버 처리

1. JWT 검증 → `owner_email` 추출 (records.php `/auth/v1/user` 폴백 재사용).
2. **Idempotency**: `SELECT * FROM customer_log WHERE owner_email=? AND client_request_id=?` 가 24h 내 존재하면 기존 row 그대로 반환 (200, 새 처리 안 함).
3. Plan 체크: `plan='free'` 이고 `free_summaries_used >= 5` → 403 `plan_required`.
4. `storage_path` 가 owner 소유인지 검증 (sha256 hash 비교).
5. 파일 fopen → OpenAI Whisper API multipart 전송 (`whisper-1`, `language: ko`).
6. STT 결과를 LLM 에 전달 → JSON 응답 파싱 (§7 프롬프트).
7. `customer_log` insert — PII 컬럼은 `encrypt_pii()` 통과 후 저장.
8. `members.free_summaries_used += 1` (plan=free 일 때만).
9. **오디오 삭제** (`audio_kept=false`): `unlink($absolute_storage_path)` + `audio_storage_path` 는 그대로 row 에 기록 (감사용).
10. row 를 다시 select → PII 복호화 → 응답.

### Response (200)

원본 spec §4 와 **동일 shape**. 우리가 채울 값:

```json
{
  "status": "ok",
  "customer_log": {
    "id": "9f0e8d7c-...",
    "owner_email": "user@example.com",
    "customer_phone_lookup": null,
    "customer_name": "김상우",
    "phone_number": "010-5910-2542",
    "consult_at": "2026-05-17T17:16:26+09:00",
    "summary": "...",
    "interest": "...",
    "inquiry": "...",
    "budget_condition": "...",
    "next_action": "...",
    "agent_memo": null,
    "audio_storage_path": "uploads/recordings/.../...m4a",
    "audio_kept": false,
    "transcript": "<full STT text>",
    "ai_model": "gpt-4o-mini",
    "ai_generated_at": "2026-05-17T17:18:01+09:00",
    "source": "app-auto",
    "client_request_id": "uuid-from-app",
    "created_at": "...",
    "updated_at": "..."
  },
  "plan": {
    "plan": "free",
    "free_summaries_used": 1,
    "free_quota": 5
  }
}
```

**원본 spec 과 차이:** `user_id` → `owner_email`. `customer_id` → `customer_phone_lookup`. 그 외 필드명/타입 동일.

### Error 응답

원본 spec §4 와 동일 — `unauthorized` / `plan_required` / `invalid_audio` / `upstream_failed` 코드 그대로.

추가:
| HTTP | code | 의미 |
|---|---|---|
| 409 | `duplicate_request` | idempotency 충돌이 24h 윈도우 밖일 때 (드물어야 함) |

### LLM 모델 결정

기본값 **`gpt-4o-mini`** (저렴 + 한국어 JSON 출력 안정).
fallback: `gpt-4o` (mini 가 JSON 파싱 실패 시 1회 재시도).
환경변수 `OPENAI_API_KEY` 는 cafe24 .env 에 이미 존재.

---

## 6. `customer_log` 후속 수정 — `records.php` action 추가

원본 spec §7 에서 앱이 "supabase-js 로 직접 PATCH" 하는 부분. 우리는 supabase-js DB 직접 호출 안 쓰므로 PHP endpoint 추가.

### PATCH

```
POST https://youngman-biz.com/records.php
Authorization: Bearer <user JWT>
Content-Type: application/json

{
  "action": "customer_log_update",
  "id": "9f0e8d7c-...",
  "patch": {
    "customer_name": "김상우 (수정)",
    "phone_number": "010-5910-2542",
    "agent_memo": "자료 발송함",
    "next_action": "다음 주 콜백"
  }
}
```

서버: `WHERE id=? AND owner_email=?` 강제. patch 의 PII 필드는 자동 암호화 후 저장.

### DELETE

```
POST https://youngman-biz.com/records.php
{
  "action": "customer_log_delete",
  "id": "9f0e8d7c-..."
}
```

### LIST

```
POST https://youngman-biz.com/records.php
{
  "action": "customer_log_list",
  "limit": 50,
  "before": "2026-05-17T00:00:00+09:00"   // 페이지네이션
}
```

응답: `{ items: [ ...customer_log row(s) ], next_before: "..." }` — PII 복호화된 평문.

### GET by id

```
POST https://youngman-biz.com/records.php
{ "action": "customer_log_get", "id": "9f0e8d7c-..." }
```

---

## 7. LLM 프롬프트

원본 spec §5 그대로 사용. 변경 없음.

`response_format: { type: "json_object" }` (gpt-4o-mini 지원) 강제 + JSON 파싱 실패 시 1회 재시도.

---

## 8. Phase 1 / Phase 2 분리

### Phase 1 (이번 작업) — sync only

- `/upload.php` (audio kind 추가)
- `/process-recording.php` (sync, 폴링/FCM 없음)
- `customer_log` 테이블 + `records.php` CRUD action
- `members.plan` / `members.free_summaries_used`
- 오디오 즉시 삭제 (성공 시)

### Phase 2 (이번 작업 범위 아님)

- `process-recording.php?mode=async` + `recording_jobs` 테이블
- FCM dispatcher (bridge.js 가 토큰 받아둔 거 → PHP 에서 발송) + `user_fcm_tokens` 테이블
- 24h 미정리 오디오 cron 삭제 (실패 retry 윈도우용)
- 실패 자동 재시도 큐

Phase 1 가 라이브 동작 확인되고 사용자 피드백 수집 후 Phase 2 진행.

---

## 9. 앱팀 8번 open question 답변

1. **`profiles` 가 plan/quota 의 올바른 위치인가, 별도 `user_plans` 테이블인가?**
   → 우리는 `profiles` 가 없음. **`members` 테이블에 컬럼 추가** (§4).

2. **`customers(id uuid)` FK 가 존재하는가? 없으면 Phase 1 에서 FK 제거?**
   → `customers` 테이블은 있으나 PII 가 암호화돼 있어서 id 매칭이 비효율. **FK 제거하고 `customer_phone_lookup` (정규화된 phone) 으로 lookup**. 추후 매칭은 `records.php` 에서 처리.

3. **무료 quota 값?**
   → **5회** (`FREE_QUOTA = 5`).

4. **오디오 보존 정책?**
   → **요약 성공 후 즉시 삭제** (`audio_kept` default `false`). 실패 시에만 24h 유지 후 Phase 2 cron 삭제.

---

## 10. 앱 client 의사코드 (변경분 요약)

```js
// 1) 오디오 업로드 — supabase.storage → fetch
const fd = new FormData();
fd.append('file', audioBlob, filename);
fd.append('kind', 'recording');
fd.append('recorded_at', recordedAt);
fd.append('original_filename', filename);

const up = await fetch('https://youngman-biz.com/upload.php', {
  method: 'POST',
  headers: { Authorization: `Bearer ${jwt}` },
  body: fd,
}).then(r => r.json());
// up.storage_path → 아래로 전달

// 2) Process — Edge Function URL → cafe24 PHP URL
const res = await fetch('https://youngman-biz.com/process-recording.php', {
  method: 'POST',
  headers: {
    Authorization: `Bearer ${jwt}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    storage_path: up.storage_path,
    duration_sec, original_filename, recorded_at, phone_number,
    client_request_id: uuidv4(),
  }),
}).then(r => r.json());
// res.customer_log → 프리뷰 렌더

// 3) 사용자 수정 — supabase-js .update → fetch records.php
await fetch('https://youngman-biz.com/records.php', {
  method: 'POST',
  headers: {
    Authorization: `Bearer ${jwt}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    action: 'customer_log_update',
    id: res.customer_log.id,
    patch: { agent_memo: '...', next_action: '...' },
  }),
});
```

기존 `supabase-js` import 는 인증(JWT) 용도만 유지. storage/db 호출은 모두 제거.

---

## 11. 합의 후 웹팀 작업 순서 (참고용)

이건 우리 쪽 to-do — 앱팀이 검토할 필요는 없지만, 진척도 공유:

1. `records.php` 에 schema bootstrap (CREATE TABLE + ALTER) 추가
2. `upload.php` 에 `kind=recording` 분기 + audio mime 화이트리스트
3. `process-recording.php` 신설 (Whisper + LLM + insert + audio unlink)
4. `records.php` 에 `customer_log_list / get / update / delete` 4개 action 추가
5. `deploy.yml` 에 `process-recording.php` cp + validate 줄 추가
6. 라이브 smoke test (테스트 계정 + 짧은 mp3)
7. 앱팀에 endpoint URL/응답 샘플 회신

---

## 12. 앱팀 회신 요청 항목

이 spec 에 대해 다음 4가지만 OK / NOT-OK 회신 부탁:

1. **2개 URL 변경** (`upload.php`, `process-recording.php`) 수용 가능한가?
2. **`supabase-js` storage/db 직접 호출 제거** 후 fetch 로 교체 작업 부담 OK?
3. **응답 shape** — `user_id` → `owner_email`, `customer_id` → `customer_phone_lookup` 외 나머지 spec §4 와 동일. 앱 UI 에 영향 있는지?
4. **LLM 모델 `gpt-4o-mini`** 로 시작 OK? (비용/품질 트레이드오프 — 후속 교체 가능)

그 외 추가 요구사항/우려 있으면 같이 회신.
