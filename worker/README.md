# YOUNGMAN AI 통화 요약 Worker (Railway)

영맨 (youngman-biz.com) 의 AI 통화 요약 외부 worker.
cafe24 PHP 의 60초 nginx timeout / set_time_limit 240초 한계 우회.

## 역할

- STT (OpenAI Whisper) + LLM (Claude Sonnet 4.6) 처리
- DB / FCM / customer_log INSERT 는 cafe24 책임 (callback)
- 긴 통화 (10분+) 청크 분할 (다음 sprint)

## 아키텍처

```
[cafe24]                  [Railway worker]
process-recording.php  →  POST /process (webhook)
  ├ job INSERT (queued)     ├ audio 다운로드 (cafe24)
  ├ Railway 호출              ├ Whisper API
  └ FCM 'queued'             ├ Claude API
                              └ POST /recording-callback (cafe24)
                                └ X-Worker-Token

[cafe24]
recording-callback.php
  ├ token 검증
  ├ customer_log INSERT
  ├ recording_jobs.completed
  └ FCM 'call_summary_ready'
```

## Railway 셋업 가이드 (사장님용)

### 1. 계정 가입
- https://railway.app 접속 → GitHub 로 sign in
- 무료 크레딧 $5/월 제공. Hobby plan $5/월 권장 (안정성)

### 2. 새 프로젝트 생성
- Dashboard → "New Project" → "Deploy from GitHub repo"
- 영맨 repo 선택 (`nxnxax/product-builder-jd`)
- Railway 가 자동으로 Nixpacks 빌더 감지

### 3. Root Directory 설정 (중요)
- 프로젝트 설정 → Service → Settings → "Root Directory"
- 값: `worker`
- (cafe24 PHP 파일들과 분리 — Railway 는 `worker/` 폴더만 빌드)

### 4. 환경변수 등록
프로젝트 설정 → Variables 탭에서 다음 등록:

| 변수 | 값 | 비고 |
|------|------|------|
| `RECORDING_WORKER_TOKEN` | GitHub Secret 과 동일한 값 | 양방향 인증 |
| `OPENAI_API_KEY` | sk-... | Whisper + gpt-4o-mini fallback |
| `ANTHROPIC_API_KEY` | sk-ant-api03-... | Claude Sonnet 4.6 |
| `CAFE24_BASE_URL` | `https://youngman-biz.com` | callback URL |
| `STT_PROVIDER` | `whisper` | 기본값 |
| `LLM_PROVIDER` | `anthropic` | 기본값 |

### 5. Deploy
- 자동으로 Nixpacks 가 Python 3.11 빌드 + uvicorn 실행
- 첫 deploy 후 Railway 가 public URL 발급 (예: `youngman-worker.up.railway.app`)

### 6. cafe24 .env 에 Railway URL 등록
GitHub Secret 에 `RAILWAY_WORKER_URL` 추가:
- 값: Railway 발급 URL (예: `https://youngman-worker.up.railway.app`)
- 영맨 deploy.yml 이 다음 빈 commit push 시 .env 에 어셈블
- 없으면 영맨은 기존 cafe24 자체 처리 흐름 사용

### 7. 검증
- `curl https://youngman-worker.up.railway.app/`
- 응답: `{ "ok": true, "service": "youngman-worker", "config": { ... } }`
- `config.token_set: true`, `openai_set: true`, `anthropic_set: true` 확인

## 로컬 테스트

```bash
cd worker
pip install -r requirements.txt
export RECORDING_WORKER_TOKEN=test-token
export OPENAI_API_KEY=sk-...
export ANTHROPIC_API_KEY=sk-ant-...
export CAFE24_BASE_URL=https://youngman-biz.com
uvicorn main:app --reload --port 8080
```

## 다음 sprint (Phase 2 worker)

- 긴 통화 (10분+) 청크 분할 (ffmpeg)
- 청크별 병렬 Whisper + Claude map-reduce 요약
- 자체 Whisper 호스팅 (Modal Serverless or RunPod) — 비용 -90%
