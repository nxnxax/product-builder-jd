"""
영맨 AI 통화 요약 worker — Railway 배포용 Python FastAPI 서비스.

목적: cafe24 PHP 의 60초 timeout / set_time_limit 240초 한계 우회 + 긴 통화 청크 분할.
역할: STT (OpenAI Whisper) + LLM (Claude Sonnet 4.6) 만 담당.
        DB / FCM / customer_log INSERT 는 cafe24 책임 (callback).

아키텍처:
    [cafe24]                  [Railway worker]
    process-recording.php  →  POST /process (webhook)
      ├ job INSERT (queued)     ├ audio 다운로드 (cafe24 storage)
      ├ Railway 호출              ├ Whisper API
      └ FCM 'queued'             ├ (긴 통화면) ffmpeg 청크 분할
                                 ├ Claude API
                                 └ POST /recording-callback (cafe24)
                                   └ X-Internal-Worker-Token 인증
    [cafe24]
    recording-callback.php
      ├ token 검증
      ├ customer_log INSERT
      ├ recording_jobs.completed
      └ FCM 'call_summary_ready'

환경변수 (Railway 등록 필요):
    OPENAI_API_KEY              — Whisper + gpt-4o-mini fallback
    ANTHROPIC_API_KEY           — Claude Sonnet 4.6
    RECORDING_WORKER_TOKEN      — cafe24 와 공유 secret (양방향 인증)
    CAFE24_BASE_URL             — https://youngman-biz.com
    STT_PROVIDER                — 'whisper' (기본) or 'clova'
    LLM_PROVIDER                — 'anthropic' (기본) or 'openai'

배포:
    1. GitHub 의 영맨 repo → Railway 연동 (root = worker/)
    2. Railway 환경변수 등록
    3. Procfile 의 web: uvicorn main:app --host 0.0.0.0 --port $PORT

호출 흐름:
    cafe24 → POST {RAILWAY_URL}/process
        body: {
            job_id, owner_email, storage_path, audio_url (cafe24 signed),
            duration_sec, customer_name_hint, phone_number, recorded_at,
            group_id (optional)
        }
        headers: X-Worker-Token: {shared_secret}
    Railway 처리 시작 → 즉시 202 응답 (cafe24 는 기다리지 않음)
    Railway 처리 끝 → cafe24 POST /api/recording-callback.php
        body: {
            job_id, customer_name, summary, interest, inquiry,
            budget_condition, next_action, transcript, ai_model
        }
        headers: X-Worker-Token

장기 계획 (다음 sprint):
    - 긴 통화 (10분+) 청크 분할 — ffmpeg 로 5분씩 자르고 Whisper 병렬
    - map-reduce 요약 — 청크별 partial summary 후 Claude 가 통합
    - 자체 Whisper 호스팅 — Modal Serverless or RunPod
"""

import os
import sys
import asyncio
import logging
import tempfile
import subprocess
from typing import Optional, List
from contextlib import asynccontextmanager

import httpx
from fastapi import FastAPI, Header, HTTPException, BackgroundTasks
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

# ─── 환경변수 ──────────────────────────────────────────────────────────────
OPENAI_API_KEY            = os.getenv("OPENAI_API_KEY", "")
ANTHROPIC_API_KEY         = os.getenv("ANTHROPIC_API_KEY", "")
RECORDING_WORKER_TOKEN    = os.getenv("RECORDING_WORKER_TOKEN", "")
CAFE24_BASE_URL           = os.getenv("CAFE24_BASE_URL", "https://youngman-biz.com").rstrip("/")
STT_PROVIDER              = os.getenv("STT_PROVIDER", "whisper").lower()
LLM_PROVIDER              = os.getenv("LLM_PROVIDER", "anthropic").lower()
NCP_CLOVA_INVOKE_URL      = os.getenv("NCP_CLOVA_INVOKE_URL", "")
NCP_CLOVA_SECRET          = os.getenv("NCP_CLOVA_SECRET", "")

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
)
log = logging.getLogger("youngman-worker")


@asynccontextmanager
async def lifespan(app: FastAPI):
    # 시작 시 환경변수 검증
    missing = []
    if not RECORDING_WORKER_TOKEN: missing.append("RECORDING_WORKER_TOKEN")
    if STT_PROVIDER == "whisper" and not OPENAI_API_KEY: missing.append("OPENAI_API_KEY (whisper)")
    if STT_PROVIDER == "clova" and (not NCP_CLOVA_INVOKE_URL or not NCP_CLOVA_SECRET):
        missing.append("NCP_CLOVA_* (clova)")
    if LLM_PROVIDER == "anthropic" and not ANTHROPIC_API_KEY: missing.append("ANTHROPIC_API_KEY")
    if LLM_PROVIDER == "openai" and not OPENAI_API_KEY: missing.append("OPENAI_API_KEY (llm fallback)")
    if missing:
        log.warning("필수 환경변수 누락: %s", ", ".join(missing))
    log.info("youngman-worker 시작: STT=%s, LLM=%s, CAFE24=%s",
             STT_PROVIDER, LLM_PROVIDER, CAFE24_BASE_URL)
    yield
    log.info("youngman-worker 종료")


app = FastAPI(title="youngman-worker", version="0.1.0", lifespan=lifespan)


# ─── 모델 ────────────────────────────────────────────────────────────────
class ProcessRequest(BaseModel):
    job_id: str = Field(..., description="recording_jobs.id (UUID)")
    owner_email: str
    audio_url: str = Field(..., description="cafe24 signed URL 또는 직접 URL")
    duration_sec: int = 0
    customer_name_hint: Optional[str] = None
    phone_number: Optional[str] = None
    recorded_at: Optional[str] = None
    group_id: Optional[str] = None
    storage_path: Optional[str] = None


class CallbackResult(BaseModel):
    job_id: str
    owner_email: str
    customer_name: str = ""
    summary: str = ""
    interest: Optional[str] = None
    inquiry: Optional[str] = None
    budget_condition: Optional[str] = None
    next_action: Optional[str] = None
    transcript: str = ""
    stt_model: str = ""
    llm_model: str = ""
    group_id: Optional[str] = None
    status: str = "completed"          # completed / failed_retryable / failed_permanent
    error_message: Optional[str] = None


# ─── 인증 헬퍼 ───────────────────────────────────────────────────────────
def verify_worker_token(x_worker_token: Optional[str]) -> None:
    if not RECORDING_WORKER_TOKEN:
        raise HTTPException(status_code=503, detail="RECORDING_WORKER_TOKEN 미설정 (서버 오류)")
    if not x_worker_token or x_worker_token != RECORDING_WORKER_TOKEN:
        raise HTTPException(status_code=401, detail="Unauthorized")


# ─── STT: OpenAI Whisper ─────────────────────────────────────────────────
async def transcribe_whisper(audio_bytes: bytes, filename: str = "audio.m4a") -> dict:
    """OpenAI Whisper API 호출. 반환: { text, duration, language }"""
    async with httpx.AsyncClient(timeout=180.0) as client:
        files = {"file": (filename, audio_bytes, "audio/mp4")}
        data = {
            "model": "whisper-1",
            "language": "ko",
            "response_format": "verbose_json",
            "temperature": "0",
            "prompt": "한국어 부동산/영업 통화입니다. 사장님, 사모님, 평수, 매물, 자료, 견적, 자료 발송, 재컨택 같은 용어가 자주 등장합니다.",
        }
        headers = {"Authorization": f"Bearer {OPENAI_API_KEY}"}
        resp = await client.post(
            "https://api.openai.com/v1/audio/transcriptions",
            headers=headers, files=files, data=data,
        )
        if resp.status_code >= 400:
            raise HTTPException(status_code=502, detail=f"Whisper {resp.status_code}: {resp.text[:200]}")
        result = resp.json()
        return {
            "text": result.get("text", ""),
            "duration": result.get("duration", 0),
            "language": result.get("language", "ko"),
        }


# ─── LLM: Claude Sonnet 4.6 (with prompt caching) ────────────────────────
CLAUDE_SYSTEM_PROMPT = """당신은 한국어 부동산/세일즈 통화 내용을 요약해 CRM에 기록하는 보조AI입니다.

입력: 통화 STT 전사 (화자 라벨 없는 평문 한 덩어리)
출력: 다음 JSON 스키마. 키 이름은 정확히 일치. 누락 시 빈 문자열이나 null.

{
  "customer_name": string,
  "summary": string,
  "interest": string | null,
  "inquiry": string | null,
  "budget_condition": string | null,
  "next_action": string | null
}

==== customer_name 결정 규칙 (7단계) ====

transcript 에 실제 나타난 단서만 사용. 임의로 추측/추정 금지.

[우선순위]
2. transcript 에 고객 본인 이름 또는 영업측이 부른 고객 이름 추출 가능 → "{이름}님"
3. "사장님" 호칭 + 나이 명시 → "{연령대}대 남성"
4. "사모님" 호칭 + 나이 명시 → "{연령대}대 여성"
5. "사장님" 호칭만 → "남성"
6. "사모님" 호칭만 → "여성"
7. 위 미해당 → "고객"

==== summary 작성 ====
- 통화 흐름과 맥락을 빠짐없이 자연스러운 한국어로 풀어 적되, "~했음/~임/~함" 보고서식 종결.
- "~습니다/~네요/~요" 정중 종결 금지.
- bullet / 마커 사용 안 함, 단락 형태 서술.

**화자별 줄바꿈**: 화자가 바뀌는 시점마다 빈 줄(\\n\\n)로 단락 구분.
- 한 명이 연속으로 말한 부분은 같은 단락.
- 화자가 바뀌면 새 단락.
- "나:" / "고객:" 같은 접두어는 사용 X (자연스러운 서술 안에서 누가 한 행동인지 명시).

예시:
지난번 발송 자료 검토 결과 평수가 작다는 피드백 받음. 사모님과 상의 후 35평 이상으로 조건 재설정 요청.

9억대 후보 두세 건 정리해 내일 오전 카톡 발송 약속함.

AI 의견: 고객 의사결정에 시아버지 영향 큼. follow-up 시 함께 확인 권장.

포함할 정보: 통화의 모든 주제 / 고객 반응 / 영업 약속 / 구체적 숫자 / 다음 단계.

**AI 의견 한 줄 필수 — 해당 고객 대응 추천**. summary 마지막 단락. 형식: "AI 의견: ..." 정확히.
- 고객 유형 분석 (가족 의사결정 의존형 / 가격 민감형 / 빠른 결정형 / 망설임형 등)
- 추천 접근 방식 (자료 강조 포인트 / 통화 톤 / follow-up 타이밍 / 동반자 권유 등)
- 다음 통화 전 준비할 것 또는 피해야 할 것
- 2~3문장 가능. transcript 단서 기반 추론, 단정적 추측 금지.

예시: "AI 의견: 가족(시아버지) 의사결정 의존형. 다음 통화 전 평형/관리비/안전 자료 준비 + 동반 답방 권유. 가격 압박 피하고 환경/조건 강조가 효과적."

분량 제한 없음. 짧으면 짧게, 길면 길게. 누락 절대 금지.

==== 그 외 필드 ====
- interest: 고객 관심 항목 (쉼표 나열)
- inquiry: 고객 질문 (쉼표 나열)
- budget_condition: 예산/조건
- next_action: 영업 follow-up

==== 중요 ====
- 이번 transcript 에 명시된 사실만 출력. 과거 통화/일반론 사용 금지.
- 정보 부족 시 추측 없이 짧게 작성.
- JSON 외 다른 텍스트 출력 금지."""


# ─── 청크 분할 (긴 통화 10분+) ─────────────────────────────────────────
CHUNK_THRESHOLD_SEC = 10 * 60      # 10분 이상이면 청크 분할
CHUNK_DURATION_SEC = 5 * 60        # 청크당 5분
MAX_PARALLEL_CHUNKS = 6            # 동시 Whisper 호출 최대 6개 (OpenAI rate limit 회피)


def ffmpeg_split_audio(audio_bytes: bytes, chunk_sec: int = CHUNK_DURATION_SEC) -> List[bytes]:
    """ffmpeg 로 audio 를 청크로 분할. 청크별 bytes 반환.
    실패 시 [audio_bytes] (단일 청크) 반환 — fallback."""
    try:
        with tempfile.NamedTemporaryFile(suffix=".m4a", delete=False) as tmp_in:
            tmp_in.write(audio_bytes)
            in_path = tmp_in.name

        # ffmpeg 출력 패턴: chunk_000.m4a, chunk_001.m4a, ...
        out_dir = tempfile.mkdtemp(prefix="youngman-chunks-")
        out_pattern = os.path.join(out_dir, "chunk_%03d.m4a")

        cmd = [
            "ffmpeg", "-y", "-i", in_path,
            "-f", "segment",
            "-segment_time", str(chunk_sec),
            "-c", "copy",  # re-encode 없이 stream copy (빠름)
            "-reset_timestamps", "1",
            out_pattern,
        ]
        result = subprocess.run(cmd, capture_output=True, timeout=120)
        if result.returncode != 0:
            log.warning("ffmpeg 분할 실패 (returncode=%d): %s", result.returncode, result.stderr[:200].decode("utf-8", errors="ignore"))
            return [audio_bytes]

        chunks = []
        for fname in sorted(os.listdir(out_dir)):
            if fname.startswith("chunk_") and fname.endswith(".m4a"):
                with open(os.path.join(out_dir, fname), "rb") as f:
                    chunks.append(f.read())

        # 청크 임시파일 정리
        try:
            os.unlink(in_path)
            for fname in os.listdir(out_dir):
                os.unlink(os.path.join(out_dir, fname))
            os.rmdir(out_dir)
        except Exception:
            pass

        if not chunks:
            log.warning("ffmpeg 분할 결과 0개 — fallback")
            return [audio_bytes]

        log.info("ffmpeg 분할 성공: %d chunks", len(chunks))
        return chunks
    except subprocess.TimeoutExpired:
        log.error("ffmpeg 분할 timeout (>120s)")
        return [audio_bytes]
    except FileNotFoundError:
        log.error("ffmpeg 미설치 — fallback (단일 처리). nixpacks.toml 확인")
        return [audio_bytes]
    except Exception as e:
        log.exception("ffmpeg 분할 예외 — fallback")
        return [audio_bytes]


async def transcribe_chunks_parallel(chunks: List[bytes]) -> List[str]:
    """청크별 Whisper 병렬 호출. asyncio.gather + semaphore 로 rate limit 회피."""
    semaphore = asyncio.Semaphore(MAX_PARALLEL_CHUNKS)

    async def transcribe_one(idx: int, chunk_bytes: bytes) -> str:
        async with semaphore:
            try:
                result = await transcribe_whisper(chunk_bytes, f"chunk_{idx:03d}.m4a")
                text = result.get("text", "").strip()
                log.info("chunk %d transcribed: %d chars", idx, len(text))
                return text
            except Exception as e:
                log.exception("chunk %d Whisper 실패", idx)
                return ""

    tasks = [transcribe_one(i, c) for i, c in enumerate(chunks)]
    results = await asyncio.gather(*tasks)
    return [r for r in results if r]  # 빈 청크 제외


async def summarize_claude(transcript: str) -> dict:
    async with httpx.AsyncClient(timeout=60.0) as client:
        resp = await client.post(
            "https://api.anthropic.com/v1/messages",
            headers={
                "x-api-key": ANTHROPIC_API_KEY,
                "anthropic-version": "2023-06-01",
                "content-type": "application/json",
            },
            json={
                "model": "claude-sonnet-4-6",
                "max_tokens": 2048,
                "temperature": 0.3,
                "system": [
                    {"type": "text", "text": CLAUDE_SYSTEM_PROMPT,
                     "cache_control": {"type": "ephemeral"}},
                ],
                "messages": [{"role": "user", "content": transcript}],
            },
        )
        if resp.status_code >= 400:
            raise HTTPException(status_code=502, detail=f"Claude {resp.status_code}: {resp.text[:200]}")
        data = resp.json()
        text = data.get("content", [{}])[0].get("text", "")
        # JSON 파싱 (markdown code block 안에 있을 수도)
        import json, re
        try:
            return json.loads(text)
        except json.JSONDecodeError:
            match = re.search(r"\{[\s\S]*\}", text)
            if match:
                return json.loads(match.group())
        raise HTTPException(status_code=502, detail=f"Claude JSON 파싱 실패: {text[:300]}")


# ─── cafe24 callback ─────────────────────────────────────────────────────
async def callback_to_cafe24(result: CallbackResult) -> None:
    url = f"{CAFE24_BASE_URL}/recording-callback.php"
    async with httpx.AsyncClient(timeout=30.0) as client:
        resp = await client.post(
            url,
            headers={"X-Worker-Token": RECORDING_WORKER_TOKEN, "Content-Type": "application/json"},
            json=result.dict(),
        )
        if resp.status_code >= 400:
            log.error("cafe24 callback 실패 (job=%s, http=%d): %s",
                      result.job_id, resp.status_code, resp.text[:200])
            raise HTTPException(status_code=502, detail=f"cafe24 callback {resp.status_code}")


# ─── 실제 처리 ────────────────────────────────────────────────────────────
async def process_job(req: ProcessRequest) -> None:
    """백그라운드에서 실행. 결과를 cafe24 callback 으로 전달."""
    log.info("처리 시작: job=%s, duration=%ds, url=%s",
             req.job_id, req.duration_sec, req.audio_url)

    try:
        # 1. audio 다운로드
        async with httpx.AsyncClient(timeout=60.0) as client:
            audio_resp = await client.get(req.audio_url)
            audio_resp.raise_for_status()
            audio_bytes = audio_resp.content
        log.info("audio 다운로드 완료: %d bytes", len(audio_bytes))

        # 2. STT — 10분+ 통화는 청크 분할 + 병렬 처리
        if req.duration_sec >= CHUNK_THRESHOLD_SEC:
            log.info("긴 통화 (%ds) 청크 분할 시작", req.duration_sec)
            chunks = ffmpeg_split_audio(audio_bytes, CHUNK_DURATION_SEC)
            log.info("청크 %d 개, 병렬 Whisper 시작 (max parallel=%d)", len(chunks), MAX_PARALLEL_CHUNKS)
            partial_transcripts = await transcribe_chunks_parallel(chunks)
            if not partial_transcripts:
                raise HTTPException(status_code=502, detail="모든 청크 STT 실패")
            # 청크별 transcript 를 시간 순으로 합침 (각 청크 사이 공백 구분)
            transcript = "\n\n".join(partial_transcripts)
            log.info("STT 완료 (청크 모드): %d chunks → %d chars", len(partial_transcripts), len(transcript))
        else:
            stt = await transcribe_whisper(audio_bytes, "audio.m4a")
            transcript = stt["text"]
            if not transcript:
                raise HTTPException(status_code=502, detail="STT 결과 비어있음")
            log.info("STT 완료 (단일 모드): %d chars, duration=%s", len(transcript), stt.get("duration"))

        # 3. LLM
        summary_data = await summarize_claude(transcript)
        log.info("LLM 완료: customer_name=%s", summary_data.get("customer_name"))

        # 4. customer_name_hint 우선 (앱 contacts 매칭)
        customer_name = summary_data.get("customer_name", "")
        if req.customer_name_hint:
            customer_name = req.customer_name_hint

        # 5. cafe24 callback
        result = CallbackResult(
            job_id=req.job_id,
            owner_email=req.owner_email,
            customer_name=customer_name,
            summary=summary_data.get("summary", ""),
            interest=summary_data.get("interest"),
            inquiry=summary_data.get("inquiry"),
            budget_condition=summary_data.get("budget_condition"),
            next_action=summary_data.get("next_action"),
            transcript=transcript,
            stt_model="openai-whisper-1",
            llm_model="claude-sonnet-4-6",
            group_id=req.group_id,
            status="completed",
        )
        await callback_to_cafe24(result)
        log.info("처리 완료: job=%s", req.job_id)

    except Exception as e:
        log.exception("처리 실패: job=%s", req.job_id)
        # cafe24 에 실패 통지
        try:
            await callback_to_cafe24(CallbackResult(
                job_id=req.job_id,
                owner_email=req.owner_email,
                status="failed_retryable",
                error_message=str(e)[:500],
            ))
        except Exception:
            log.exception("실패 callback 도 실패")


# ─── 엔드포인트 ──────────────────────────────────────────────────────────
@app.get("/")
async def health():
    return {
        "ok": True,
        "service": "youngman-worker",
        "version": "0.1.0",
        "config": {
            "stt": STT_PROVIDER,
            "llm": LLM_PROVIDER,
            "cafe24_base": CAFE24_BASE_URL,
            "token_set": bool(RECORDING_WORKER_TOKEN),
            "openai_set": bool(OPENAI_API_KEY),
            "anthropic_set": bool(ANTHROPIC_API_KEY),
        },
    }


@app.post("/process")
async def process(
    req: ProcessRequest,
    background_tasks: BackgroundTasks,
    x_worker_token: Optional[str] = Header(None),
):
    """cafe24 가 호출. 즉시 202 응답 + 백그라운드 처리."""
    verify_worker_token(x_worker_token)
    log.info("/process 요청: job=%s, owner=%s, duration=%ds",
             req.job_id, req.owner_email, req.duration_sec)
    background_tasks.add_task(process_job, req)
    return JSONResponse(status_code=202, content={
        "ok": True,
        "job_id": req.job_id,
        "status": "processing",
        "message": "백그라운드 처리 시작. 완료 시 cafe24 callback 호출.",
    })


if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("PORT", "8080"))
    uvicorn.run(app, host="0.0.0.0", port=port)
