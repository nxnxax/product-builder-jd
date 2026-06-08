<?php
/**
 * 서버 에러 진단 로거 — 핵심 PHP(records / recording-callback / process-recording)가 공용으로 require.
 * 사장님 2026-06-08 — 무한로딩 / 빈 팝업(서버 500) / 양식전송 실패 등 핵심기능 장애 원인 추적용.
 *
 * 원칙: 절대 예외를 던지지 않음 (모든 호출 try/catch 격리). 핵심 흐름 동작에 영향 0 — 기록만 한다.
 * fatal(E_ERROR 등)은 register_shutdown_function 으로 자동 포착 — catch 로 못 잡는 500 의 원인을 남긴다.
 */

if (!function_exists('ensure_error_logs_table')) {
    function ensure_error_logs_table($pdo) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS error_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                context VARCHAR(64) NOT NULL,
                message TEXT,
                detail TEXT,
                actor_email VARCHAR(190) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_err_created (created_at),
                INDEX idx_err_context (context)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('log_server_error')) {
    /**
     * 서버 에러/이상 상황을 error_logs 에 1행 기록. $pdo 가 PDO 가 아니면 조용히 skip.
     * context: 짧은 분류(예 'callback.update_fail'), message: 사람이 읽는 원인, detail: job_id/status 등 부가정보.
     */
    function log_server_error($pdo, $context, $message, $detail = null, $email = null) {
        try {
            if (!($pdo instanceof PDO)) return;
            ensure_error_logs_table($pdo);
            $pdo->prepare("INSERT INTO error_logs (context, message, detail, actor_email) VALUES (:c, :m, :d, :e)")
                ->execute([
                    ':c' => substr((string)$context, 0, 64),
                    ':m' => $message === null ? null : substr((string)$message, 0, 4000),
                    ':d' => $detail === null ? null : substr((string)$detail, 0, 4000),
                    ':e' => $email === null ? null : substr((string)$email, 0, 190),
                ]);
        } catch (Throwable $e) {
            // 진단 로그 자체 실패는 무시 — 핵심 흐름 보호 최우선
        }
    }
}

if (!function_exists('ym_register_fatal_logger')) {
    /**
     * fatal(치명적 PHP 오류 = 보통 HTTP 500 = 빈 팝업/무한로딩 주범)을 종료 시점에 자동 기록.
     * $GLOBALS['__ym_pdo'] 에 PDO 가 있어야 기록 가능 → 각 파일이 PDO 생성 직후 그 전역을 채워야 함.
     * 정상 요청(fatal 없음)에는 아무 동작도 안 함 → 핵심 흐름 영향 0.
     */
    function ym_register_fatal_logger($context) {
        register_shutdown_function(function () use ($context) {
            $err = error_get_last();
            if (!$err) return;
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
            if (!in_array($err['type'], $fatalTypes, true)) return;
            $pdo = $GLOBALS['__ym_pdo'] ?? null;
            if (!($pdo instanceof PDO)) return;
            log_server_error(
                $pdo,
                $context,
                (string)($err['message'] ?? 'fatal error'),
                ($err['file'] ?? '') . ':' . ($err['line'] ?? '')
            );
        });
    }
}
