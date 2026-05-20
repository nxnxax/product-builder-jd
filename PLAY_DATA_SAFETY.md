# YOUNGMAN — Google Play Console "Data Safety" 입력 자료집

> Play Console > App content > Data safety form 작성 시 그대로 옮겨 적을 수 있는 raw 답안집.
> 본 문서는 운영 자료이며 회원/일반 공개 페이지가 아님. (개인정보처리방침은 [privacy.html](privacy.html), 이용약관은 [terms.html](terms.html))

최종 갱신: 2026-05-20

---

## 0. 앱 기본 정보

| 항목 | 값 |
|---|---|
| 앱 이름 | YOUNGMAN (영맨) |
| 패키지명 | (앱팀이 입력) |
| 운영사 | 어센트라 (Ascentra) — 사업자등록번호 393-39-01518 |
| 대표자 | 장동훈 |
| 개인정보처리방침 URL | https://youngman-biz.com/privacy.html |
| 이용약관 URL | https://youngman-biz.com/terms.html |
| 환불정책 URL | https://youngman-biz.com/refund.html |
| 자동결제 안내 URL | https://youngman-biz.com/auto-billing.html |
| 계정 삭제 방법 URL | https://youngman-biz.com/profile.html (앱 내 [내 정보] → 회원 탈퇴) |
| 고객 지원 이메일 | nxnxax@gmail.com |

---

## 1. Data Safety: Does your app collect or share any of the required user data types?

**답: Yes (수집/공유 함)**

### 1-1. 데이터 수집 여부
**답: Yes — Data is collected**

### 1-2. 데이터 공유 여부 (제3자에게 전송)
**답: Yes — Data is shared with third parties**

근거: STT/LLM 처리를 위해 OpenAI / Anthropic 으로 통화 녹음 데이터 일시 전송. PG사(PortOne / 토스페이먼츠) 로 결제 정보 전송.

### 1-3. 모든 데이터가 전송 중 암호화되는가?
**답: Yes (All user data is encrypted in transit)**
- 모든 API 통신 HTTPS (TLS 1.2+)
- Supabase / OpenAI / Anthropic / PortOne / FCM 전부 TLS

### 1-4. 사용자가 데이터 삭제를 요청할 수 있는가?
**답: Yes (Users can request that their data be deleted)**
- 앱 내 [내 정보] → 회원 탈퇴 (account-delete API) 가 모든 사용자 데이터 + audio 파일 + Supabase auth.users 까지 일괄 삭제
- 결제 내역은 전자상거래법 5년 보관 의무로 owner_email 익명화 처리

---

## 2. 데이터 카테고리별 상세 (Data types collected)

### 2-1. Personal info — Name
- **수집**: No (회원 닉네임은 사용자 표시명. 실명 미수집)
- 단 통화 녹음 AI 요약 결과 안에 고객 이름이 회원에 의해 입력될 수 있음. 이건 회원의 CRM 데이터로서 회사가 따로 사용하지 않음.

### 2-2. Personal info — Email address
- **수집**: Yes (회원가입 필수)
- 목적: Account management
- 필수: Required
- 공유: Yes — Supabase Inc.(인증 인프라, 미국). OpenAI / Anthropic 에는 전송 안 됨.
- 사용자 삭제 가능: Yes (회원 탈퇴)
- 임시: No (장기 보관)

### 2-3. Personal info — User IDs
- **수집**: Yes (Supabase user.id UUID, 영맨 자체 owner_email)
- 목적: Account management, App functionality
- 공유: Yes — Supabase
- 사용자 삭제 가능: Yes
- 임시: No

### 2-4. Personal info — Phone number
- **수집**: Yes — 단, 사용자 본인 휴대폰번호(아이디/비밀번호 찾기 SMS 인증용)와 회원이 CRM 에 입력한 고객 전화번호(영맨 자체 키로 AES-256-GCM 암호화 저장).
- 목적: Account management (본인 인증), App functionality (CRM 저장)
- 필수: 휴대폰 인증은 옵션, CRM 입력은 사용자가 직접 입력하는 것이므로 필수 아님
- 공유: No (영맨 자체 DB 만)
- 사용자 삭제 가능: Yes

### 2-5. Personal info — Address
- **수집**: No

### 2-6. Financial info — User payment info
- **수집**: Yes (BillingKey 토큰, 결제 일시·금액·상태)
- 목적: Purchases (구독 결제, 자동 충전)
- 필수: 유료 플랜 가입 시 필요. Free 사용자는 미수집.
- 공유: Yes — (주)아임포트(PortOne) + 토스페이먼츠 (한국 PG)
- 사용자 삭제 가능: 부분 — 회원 탈퇴 시 owner_email 익명화, 결제 내역 자체는 전자상거래법상 5년 보관 후 파기
- 명시: 카드 번호 원문은 미저장, BillingKey 토큰만 보관

### 2-7. Financial info — Purchase history
- **수집**: Yes (구독 갱신 이력)
- 목적: Purchases, Account management
- 공유: Yes — PortOne
- 사용자 삭제 가능: 부분 (5년 보관 후 파기)

### 2-8. Audio — Voice or sound recordings (★ 통화 녹음)
- **수집**: Yes — 매우 중요
- 목적: App functionality (AI 요약 → CRM 자동 저장)
- 필수: 사용자가 영맨 앱에서 통화 녹음 권한을 허용한 경우에만. 권한 거부 시 본 기능 미작동.
- 공유: Yes — OpenAI L.L.C.(STT 변환), Anthropic PBC(텍스트 요약). 두 위탁사 모두 학습 미사용 정책.
- 사용자 삭제 가능: Yes — 원본 음성은 처리 직후 24시간 이내 영구 삭제(자동 cron). 요약 결과는 사용자가 CRM 에서 개별/일괄 삭제 가능.
- 임시: 원본 음성은 Yes (Transient), 요약 결과는 No (회원 자발적 보관)
- **Data Safety 분류**: "Audio files" 와 "Other audio" 둘 다 해당. "Voice or sound recordings" 로 명시.

### 2-9. Photos and videos — Photos
- **수집**: Optional — 명함 제작 (card-builder.html) / 단체 SMS 첨부 이미지
- 목적: App functionality
- 필수: No
- 공유: No (영맨 자체 호스팅)
- 사용자 삭제 가능: Yes

### 2-10. Files and docs
- **수집**: Optional (upload.html — 개인 자료함)
- 목적: App functionality
- 필수: No
- 공유: No
- 사용자 삭제 가능: Yes

### 2-11. Messages — Other in-app messages
- **수집**: Yes — 회원의 단체 SMS 발송 내용(Solapi/Aligo 위탁), 통화 AI 요약 결과(transcript)
- 목적: App functionality
- 공유: SMS 본문은 Solapi/Aligo 로 전송(발송 위탁). transcript 는 외부 공유 없음.
- 사용자 삭제 가능: Yes

### 2-12. App activity — App interactions / In-app search history / Other user-generated content
- **수집**: Yes (CRM 데이터 — 고객명·메모·비고·일정 등 회원이 직접 입력)
- 목적: App functionality
- 공유: No (Supabase / Cafe24 인프라 외 공유 없음)
- 사용자 삭제 가능: Yes (회원 탈퇴 시 일괄 삭제)

### 2-13. App info and performance — Crash logs / Diagnostics
- **수집**: Yes (PHP error_log, console.warn 등 운영 진단용)
- 목적: Analytics (오류 진단), App functionality
- 공유: No
- 사용자 삭제 가능: 부분 (개별 식별자 미포함 운영 로그는 통계 목적)

### 2-14. Device or other IDs — Device or other IDs
- **수집**: Yes — FCM 푸시 토큰 (앱별 발급)
- 목적: App functionality (푸시 알림 발송), Account management (디바이스별 토큰 관리)
- 공유: Yes — Google LLC (Firebase Cloud Messaging)
- 사용자 삭제 가능: Yes (회원 탈퇴 시 user_fcm_tokens row 삭제 + 토큰 무효화)

### 2-15. Location
- **수집**: No (영맨 미사용)

### 2-16. Web browsing / Health / Fitness / Contacts (단말 연락처)
- **수집**: No — 단, 회원이 앱 내에서 "통화 상대방 전화번호" 를 수동/자동으로 매칭할 때 단말 연락처가 사용될 수 있음. 이는 회원이 직접 입력하는 CRM 데이터로서 영맨 서버는 매칭된 결과 값(이름+전화번호)만 받음. 전체 연락처 목록을 영맨 서버로 전송하지 않음.

---

## 3. 보안 관행 (Security Practices)

### 3-1. Data is encrypted in transit
**답: Yes**
- 모든 통신 HTTPS (TLS 1.2+)
- Cafe24 SSL, Supabase TLS, OpenAI/Anthropic/Google/PortOne 전부 TLS

### 3-2. You can request that data be deleted
**답: Yes**
- 앱/웹 내 [내 정보] → 회원 탈퇴 한 번으로 일괄 삭제
- 완전 삭제 대상: members / ledger_records / ledger_groups / customer_log / recording_jobs / usage_logs / user_fcm_tokens / mobile_api_tokens / sms_credentials / 업로드 파일 (uploads/recordings/*) / Supabase auth.users
- 익명화 대상 (전자상거래법 보관 의무 / 다른 사용자 열람 일관성 유지): payments / subscriptions (5년 후 파기) / community_posts (작성자 표시만 익명화, 본문 유지)

### 3-3. Committed to follow the Google Play Families Policy
**답: No (적용 대상 아님 — 13세 미만 대상 아님)**

### 3-4. Independent security review
**답: No (아직 미시행)**

---

## 4. Why is each data type collected (상세 사유)

| Data type | Purpose options to select |
|---|---|
| Email address | Account management |
| User IDs | Account management, App functionality |
| Phone number | Account management, App functionality |
| User payment info | Purchases |
| Purchase history | Purchases, Account management |
| **Voice or sound recordings (통화 녹음)** | **App functionality** |
| Photos / Files | App functionality |
| Messages (SMS / transcript) | App functionality |
| User-generated content (CRM 입력) | App functionality |
| Crash logs / Diagnostics | Analytics |
| Device IDs (FCM token) | App functionality, Account management |

---

## 5. 권한 (Permissions) — Play Console 별도 입력 항목과의 매핑

| Android 권한 | 사용 사유 | Data Safety 매핑 |
|---|---|---|
| `READ_PHONE_STATE` | 통화 시작/종료 감지 | App functionality |
| `READ_CALL_LOG` | 통화 후 녹음 파일 매칭 | App functionality |
| `READ_EXTERNAL_STORAGE` / `READ_MEDIA_AUDIO` | 단말 녹음 파일 접근 | App functionality (Voice or sound recordings) |
| `READ_CONTACTS` | (선택) 통화 상대방 이름 매칭 | App functionality (Contacts — 단말 내 처리, 서버 전송 X) |
| `POST_NOTIFICATIONS` (Android 13+) | 푸시 알림 표시 | App functionality |
| `INTERNET` | API 통신 | App functionality |
| `FOREGROUND_SERVICE` | 통화 직후 녹음 탐색/업로드 한시 실행 | App functionality |
| `FOREGROUND_SERVICE_DATA_SYNC` | 업로드 작업 FGS type | App functionality |
| `SYSTEM_ALERT_WINDOW` (Overlay) | 통화 직후 사용자 액션 UI (보조) | App functionality |
| `USE_FULL_SCREEN_INTENT` | 잠금화면 통화 직후 알림 | App functionality |

---

## 6. 통화 녹음/AI 처리 관련 Play 정책 대응 체크

| 정책 | 영맨 대응 |
|---|---|
| Full-Screen Intent 제한 사용 | 통화 직후 액션 한정. 광고/반복 노출 금지. dismiss 가능 |
| FGS 정책 (Android 14+) | 통화 이벤트 처리 시에만 짧게 실행. 적절한 FGS type 선언. 작업 완료 즉시 종료 |
| Overlay 정책 | 보조 UX. 메인은 notification 기반 |
| 통화녹음/개인정보 | 본 문서 §2-8 명시 + privacy.html §1-1 명시. 최초 실행 시 동의 흐름 필요(앱팀) |
| Notification 권한 (Android 13+) | POST_NOTIFICATIONS 권한 요청 → 사용자 거부 시 통화 알림만 미작동, 다른 기능은 정상 |
| Background 제한 (제조사별) | 안내 페이지에 배터리 최적화/자동시작 권한 안내. 강제 X |
| Data Safety 작성 | 본 문서 (PLAY_DATA_SAFETY.md) 기준 |
| 계정 삭제 기능 | 앱 [내 정보] → 회원 탈퇴 + 웹 https://youngman-biz.com/profile.html. all-data delete + Supabase auth user delete 까지 |
| 구독 해지 흐름 | 앱/웹 [내 정보] → [구독 관리] → 해지 (현 결제주기 종료/즉시 해지 둘 다 가능) |

---

## 7. Play Store 심사 대비 — 함께 준비할 자료 체크리스트

| 자료 | 상태 |
|---|---|
| 개인정보처리방침 URL (privacy.html) | ✓ 게시 + 통화녹음/AI 섹션 보강됨 |
| 이용약관 URL (terms.html) | ✓ 게시 + AI 통화 요약 / 유료 서비스 조항 보강됨 |
| 환불정책 URL (refund.html) | ✓ 게시 |
| 자동결제 안내 URL (auto-billing.html) | ✓ 게시 |
| AI 처리 설명 | 본 문서 + privacy.html §1-1 |
| 통화 기능 설명 영상/스크린샷 | (앱팀 작성) |
| overlay / fullscreen 사용 사유 | 본 문서 §5, §6 |
| FGS 사용 사유 | 본 문서 §5, §6 |
| Data Safety form | 본 문서 §1~§6 |
| 계정 삭제 기능 | account-delete API 구현 완료 (records.php) |
| 구독 해지 흐름 | billing/cancel-subscription.php 구현 완료 |

---

## 8. 사용자 동의 흐름 (최초 실행 시 — 앱팀 작업 항목)

다음 동의 흐름을 앱 최초 실행 시 표시 권장:

```
[YOUNGMAN 시작하기]

영맨은 다음과 같이 작동합니다:

✓ 통화가 끝나면 통화 녹음 파일을 영맨 서버로 일시 업로드합니다.
✓ AI(OpenAI Whisper + Anthropic Claude)가 자동으로 통화 내용을 요약합니다.
✓ 요약 결과는 회원님의 고객관리대장에 자동 저장됩니다.
✓ 원본 음성 파일은 처리 후 24시간 이내 영구 삭제됩니다.
✓ 모든 요약 결과는 영맨 자체 키로 암호화되어 저장됩니다.

이용 시 주의사항:
• 통화 상대방의 개인정보 보호는 회원님의 책임입니다.
• 한국 외 지역에서는 양 당사자 동의 녹음만 적법한 경우가 있습니다.
• 의료/금융 등 민감 통화는 별도 적법 근거 없이 사용을 권하지 않습니다.

자세한 내용은 [개인정보처리방침] [이용약관]을 확인해주세요.

[ ] 위 내용을 모두 확인하고 동의합니다 (필수)
[ ] 마케팅 알림 수신에 동의합니다 (선택)

[동의하고 시작]   [취소]
```

위 텍스트는 앱팀이 단말 화면에 옮겨 적을 수 있도록 작성한 권고안. 디자인은 앱팀 결정.

---

## 9. 금지 사항 — 영맨 앱이 절대 하지 않는 행위 (Play 심사 대응)

- ❌ 사용자 모르게 상시 녹음 (사용자 권한 허용 + 통화 종료 이벤트 기반으로만 동작)
- ❌ 숨겨진 background 동작 (FGS 는 통화 직후 한시적)
- ❌ dismiss 불가능한 fullscreen popup
- ❌ unrelated 광고 popup
- ❌ 과도한 battery optimization 강요
- ❌ deceptive behavior (앱 설명과 다른 기능)
- ❌ 민감 정보 잠금화면 노출 (잠금 시 최소 정보만)
- ❌ 사용자 단말 연락처 목록 전체 서버 전송 (회원이 직접 매칭한 결과만)
- ❌ AI 처리 위탁사의 모델 학습에 사용자 데이터 제공 (각 사 정책 기준 학습 미사용)

---

## 부록: 영맨 데이터 흐름 다이어그램 (운영 참고용)

```
[회원 단말 (영맨 앱)]
   │
   │ ① 통화 종료 감지 (READ_PHONE_STATE)
   │ ② 단말 녹음 파일 탐색 (READ_MEDIA_AUDIO)
   │
   ▼
[영맨 서버 (cafe24, 한국)] — upload.php
   │ AES-256-GCM 키는 영맨 자체 보관
   │
   │ ③ Railway worker (미국) 위임
   ▼
[Railway worker] — worker/main.py
   │ ④ 음성 → OpenAI Whisper (STT, 미국)
   │ ⑤ 텍스트 → Anthropic Claude (요약, 미국)
   │ ⑥ 결과를 영맨 서버로 callback
   ▼
[영맨 서버] — recording-callback.php
   │ ⑦ 결과 AES-256-GCM 암호화
   │ ⑧ recording_jobs / customer_log INSERT
   │ ⑨ 원본 음성 24시간 이내 영구 삭제 (audio_cleanup.php cron)
   │
   ▼
[FCM (Google, 미국)] — 푸시 알림 발송
   │
   ▼
[회원 단말] — 앱 알림 표시 → CRM 화면 갱신
```

위 다이어그램의 ④⑤⑩(FCM) 단계가 국외 이전에 해당. privacy.html §6 의 위탁사 표와 일치.
