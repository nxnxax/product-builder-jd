-- 2026-05-21 미확인요약 시스템 폐기 — DB cleanup migration
-- 사장님 결정 (Phase 7+8): STT 자동 처리 회귀, 미확인요약 시스템 전체 삭제
--
-- 실행 전 반드시 확인:
--   1) 현재 상태 통계 SELECT (Step 0) 먼저 실행하여 영향 row 수 확인
--   2) 사장님이 Step 1-3 의 영향 동의 후 실행
--   3) 백업 권장 (mysqldump recording_jobs 만 따로)
--
-- 실행 방법:
--   cafe24 phpMyAdmin → 영맨 DB 선택 → SQL 탭 → 아래 쿼리 단계별로 paste

-- ═══════════════════════════════════════════════════
-- Step 0: 현재 상태 통계 (실행 전 영향 확인)
-- ═══════════════════════════════════════════════════
SELECT status, COUNT(*) AS cnt
FROM recording_jobs
GROUP BY status
ORDER BY cnt DESC;

-- audio_pending / ready_to_review row 들의 owner / 시점 / audio 보존 여부
SELECT
    status,
    COUNT(*) AS cnt,
    SUM(CASE WHEN storage_path IS NOT NULL AND storage_path != '' THEN 1 ELSE 0 END) AS has_audio,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS within_7d,
    MIN(created_at) AS oldest,
    MAX(created_at) AS newest
FROM recording_jobs
WHERE status IN ('audio_pending', 'ready_to_review')
GROUP BY status;

-- ═══════════════════════════════════════════════════
-- Step 1: 7일 이내 + audio 있는 audio_pending → queued
-- cron-process-jobs.php 가 5분 마다 queued/failed_retryable 자동 재처리.
-- Railway STT 진행 + callback INSERT 으로 customer_log + ledger row 자동 생성.
-- ═══════════════════════════════════════════════════
UPDATE recording_jobs
SET status = 'queued',
    updated_at = NOW()
WHERE status = 'audio_pending'
  AND storage_path IS NOT NULL
  AND storage_path != ''
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);

-- ═══════════════════════════════════════════════════
-- Step 2: 7일 지났거나 audio 없는 audio_pending → dismissed
-- audio_cleanup.php 가 dismissed 도 cleanup 함.
-- ═══════════════════════════════════════════════════
UPDATE recording_jobs
SET status = 'dismissed',
    updated_at = NOW(),
    error_message = CONCAT(IFNULL(error_message, ''), ' [auto-dismissed: 미확인요약 시스템 폐기 + audio 만료]')
WHERE status = 'audio_pending'
  AND (storage_path IS NULL OR storage_path = '' OR created_at < DATE_SUB(NOW(), INTERVAL 7 DAY));

-- ═══════════════════════════════════════════════════
-- Step 3: ready_to_review 처리
-- 정책 결정:
--   a) summary_json_encrypted 있고 customer_log_id 없으면 → 데이터 손실 위험.
--      → 사장님이 수동 판단 후 처리 (아래 SELECT 결과 확인).
--   b) summary_json_encrypted 있고 customer_log_id 있으면 → 이미 처리됨. saved 로 UPDATE.
--   c) summary_json_encrypted 없음 → dismissed.
-- ═══════════════════════════════════════════════════

-- 3a) 미처리 ready_to_review 조회 (사장님 수동 처리용)
SELECT id, owner_email, customer_name_hint, phone_number, duration_sec, recorded_at, created_at
FROM recording_jobs
WHERE status = 'ready_to_review'
  AND customer_log_id IS NULL
  AND summary_json_encrypted IS NOT NULL
ORDER BY created_at DESC;

-- 3b) customer_log_id 있는 ready_to_review → saved
UPDATE recording_jobs
SET status = 'saved',
    updated_at = NOW()
WHERE status = 'ready_to_review'
  AND customer_log_id IS NOT NULL;

-- 3c) summary 없는 ready_to_review → dismissed
UPDATE recording_jobs
SET status = 'dismissed',
    updated_at = NOW(),
    error_message = CONCAT(IFNULL(error_message, ''), ' [auto-dismissed: 미확인요약 시스템 폐기 + summary 없음]')
WHERE status = 'ready_to_review'
  AND summary_json_encrypted IS NULL;

-- ═══════════════════════════════════════════════════
-- Step 4: 정리 후 통계
-- ═══════════════════════════════════════════════════
SELECT status, COUNT(*) AS cnt
FROM recording_jobs
GROUP BY status
ORDER BY cnt DESC;
