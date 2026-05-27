<?php
/**
 * PortOne V2 결제 시스템 헬퍼.
 *
 * 환경변수 (cafe24 .env / GitHub Secrets 어셈블):
 *   PORTONE_STORE_ID         — store-xxxxxxxx 형태 (가맹점 ID)
 *   PORTONE_API_SECRET       — V2 API Secret (Authorization 헤더용)
 *   PORTONE_WEBHOOK_SECRET   — Webhook signature 검증용
 *   PORTONE_CHANNEL_KEY_TOSS — 토스페이먼츠 채널 키
 *
 * API base: https://api.portone.io
 * Webhook spec: Standard Webhooks (svix 호환) — webhook-id / webhook-timestamp / webhook-signature 헤더.
 *
 * 환경변수 누락 시 모든 함수는 throw — endpoint 가 503 으로 응답해야 함.
 */

if (!function_exists('billing_load_env_value')) {
    /**
     * cafe24 의 PHP 는 .env 자동 로드 X. process-recording.php 의 load_env_value 패턴
     * 그대로 — webroot 의 .env 파일을 직접 파싱해서 값 반환.
     */
    function billing_load_env_value(string $key): string {
        foreach ([__DIR__, dirname(__DIR__)] as $dir) {
            $path = $dir . '/.env';
            if (!is_file($path)) continue;
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $m)) {
                    if (strcasecmp($m[1], $key) === 0) return trim($m[2], "\"' \t\r\n");
                }
            }
        }
        return '';
    }
}

if (!function_exists('portone_env')) {
    function portone_env(string $key): string {
        // 1. getenv (Apache 환경변수 또는 process env)
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        // 2. $_ENV superglobal
        $env = $_ENV[$key] ?? null;
        if ($env !== null && $env !== '') return (string)$env;
        // 3. webroot 의 .env 파일 직접 파싱 (cafe24 PHP 환경)
        $fromFile = billing_load_env_value($key);
        if ($fromFile !== '') return $fromFile;
        throw new RuntimeException("PortOne 환경변수 누락: {$key}");
    }
}

if (!function_exists('portone_api_call')) {
    /**
     * PortOne V2 API 호출 — Authorization: PortOne {API_SECRET}.
     * 반환: ['status' => int, 'body' => array | null, 'raw' => string].
     */
    function portone_api_call(string $method, string $path, ?array $body = null): array {
        $secret = portone_env('PORTONE_API_SECRET');
        $url = 'https://api.portone.io' . $path;
        $ch = curl_init($url);
        $headers = [
            'Authorization: PortOne ' . $secret,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];
        if ($body !== null && $method !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('PortOne API 호출 실패: ' . $err);
        }
        $decoded = json_decode((string)$raw, true);
        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null, 'raw' => (string)$raw];
    }
}

if (!function_exists('portone_verify_webhook')) {
    /**
     * Standard Webhooks signature 검증 (PortOne V2 webhook).
     * 헤더: webhook-id / webhook-timestamp / webhook-signature.
     * signature 포맷: "v1,{base64_hmac_sha256}". 여러 signature 가 공백으로 구분될 수 있음.
     * 비교 문자열: "{webhook_id}.{webhook_timestamp}.{body}".
     */
    function portone_verify_webhook(string $body, array $headers): bool {
        $secret = portone_env('PORTONE_WEBHOOK_SECRET');
        // PortOne 의 webhook secret 은 base64 인코딩 — "whsec_xxx" prefix 제거 후 디코딩.
        $secretClean = preg_replace('/^whsec_/', '', $secret);
        $secretBytes = base64_decode($secretClean, true);
        if ($secretBytes === false) {
            // base64 가 아니면 raw bytes 로 그대로.
            $secretBytes = $secret;
        }
        $lower = array_change_key_case($headers, CASE_LOWER);
        $id = $lower['webhook-id'] ?? '';
        $ts = $lower['webhook-timestamp'] ?? '';
        $sig = $lower['webhook-signature'] ?? '';
        if ($id === '' || $ts === '' || $sig === '') return false;
        // replay 방지 — 5분 이내 timestamp 만 허용.
        $tsInt = (int)$ts;
        if ($tsInt === 0 || abs(time() - $tsInt) > 300) return false;
        $signed = $id . '.' . $ts . '.' . $body;
        $expected = base64_encode(hash_hmac('sha256', $signed, $secretBytes, true));
        // 헤더의 signature 는 "v1,xxx" 또는 "v1,xxx v1,yyy" 형태.
        $sigList = preg_split('/\s+/', trim($sig));
        foreach ($sigList as $candidate) {
            $parts = explode(',', $candidate, 2);
            if (count($parts) !== 2) continue;
            if (hash_equals($expected, $parts[1])) return true;
        }
        return false;
    }
}

if (!function_exists('portone_plan_amount')) {
    /** 우리 plan 코드 → 결제 청구 금액 (KRW, VAT 포함 = 공급가액 + 10%).
     *  2026-05-28 사장님 — VAT 별도 정책 전환:
     *    sales  : 공급가액 24,000 + VAT 2,400 = 청구 26,400
     *    master : 공급가액 47,000 + VAT 4,700 = 청구 51,700
     *    agency : 공급가액 89,000 + VAT 8,900 = 청구 97,900
     *  Google Play / PortOne 결제 시 실제 청구 금액 = 이 값.
     *  사용자 카드 표시는 plan_supply_amount() = 공급가액 + "(VAT 별도)" 표기.
     */
    function portone_plan_amount(string $plan): int {
        switch (strtolower($plan)) {
            case 'sales':    return 26400;
            case 'master':   return 51700;
            case 'agency':   return 97900;
            // 옛 가입자 호환 (라이브 구독자 없으나 fail-safe)
            case 'plus':     return 26400;  // → sales
            case 'pro':      return 51700;  // → master
            case 'premium':  return 26400;  // → sales
            default:         return 0;
        }
    }
}

if (!function_exists('plan_supply_amount')) {
    /** 공급가액 (KRW, VAT 제외). 사용자 카드 표시 / 세금계산서 공급가액 / 사장님 실 매출 기준. */
    function plan_supply_amount(string $plan): int {
        switch (strtolower($plan)) {
            case 'sales':    return 24000;
            case 'master':   return 47000;
            case 'agency':   return 89000;
            case 'plus':     return 24000;
            case 'pro':      return 47000;
            case 'premium':  return 24000;
            default:         return 0;
        }
    }
}

if (!function_exists('plan_vat_amount')) {
    /** 부가세 (KRW, 공급가액의 10%). 세금계산서 세액. */
    function plan_vat_amount(string $plan): int {
        return (int)round(plan_supply_amount($plan) * 0.1);
    }
}

if (!function_exists('plan_default_summary_limit')) {
    /**
     * plan 별 default summary_limit (회 단위 — 레거시).
     * 분 단위 한도는 plan_default_summary_limit_minutes() 참조 — 신규 흐름은 분 단위만 사용.
     */
    function plan_default_summary_limit(string $plan): ?int {
        switch (strtolower($plan)) {
            case 'agency':
            case 'master':
            case 'pro':       return null;  // 무제한 (회 단위는 deprecated)
            case 'sales':
            case 'plus':
            case 'premium':   return 20;
            // 사장님 2026-05-25 — trialing 폐지: free 와 동일 (0회 — AI 요약은 유료 플랜).
            case 'trialing':
            case 'free':
            default:          return 0;
        }
    }
}

if (!function_exists('plan_default_summary_limit_minutes')) {
    /**
     * plan 별 default summary_limit_minutes (분 단위 — 신규 분 기반 과금).
     * 2026-05-26 사장님 — 신규 요금제:
     *   - Free   : 0분 (무료, AI 요약은 유료 plan 만)
     *   - Sales  : 300분/월   (₩24,000)
     *   - Master : 700분/월   (₩47,000)
     *   - Agency : 1,500분/월 (₩89,000)
     * null = 무제한 (admin 수동 부여 시만, 일반 결제에서는 사용 안 함).
     */
    function plan_default_summary_limit_minutes(string $plan): ?int {
        switch (strtolower($plan)) {
            case 'agency':   return 1500;
            case 'master':   return 700;
            case 'sales':    return 300;
            // 옛 plan 호환 (자동 migration 으로 사라지지만 fail-safe)
            case 'pro':      return 700;
            case 'plus':
            case 'premium':  return 300;
            case 'trialing':
            case 'free':
            default:         return 0;
        }
    }
}

if (!function_exists('overage_top_up_seconds')) {
    /**
     * 자동 충전 1회 단위 (초). 5,000원 / 분당 70원 = 71.43분 ≈ 4,286초.
     * 사용자가 5,000원 자동결제 시 이 초 만큼 overage_balance_seconds 에 충전.
     */
    function overage_top_up_seconds(): int { return 4286; }
    function overage_top_up_amount_won(): int { return 5000; }
    function overage_per_minute_won(): int { return 70; }
}

if (!function_exists('charge_overage_top_up')) {
    /**
     * 사용자의 등록된 PortOne billingKey 로 자동 충전 결제 (5,000원).
     * 성공 시 members.overage_balance_seconds += 4286 (분당 70원 = 71분).
     *
     * 호출 전 검증:
     *   - members.overage_enabled = 1 (자동 충전 사전 동의)
     *   - members.portone_billing_key 존재
     *   - members.plan_status = 'active' 또는 'trialing'
     *
     * 반환:
     *   [
     *     'ok' => bool,
     *     'reason' => ?string,           // 실패 사유 (no_billing_key / overage_not_enabled / 등)
     *     'payment_id' => ?string,
     *     'amount' => int,               // 실제 결제된 금액
     *     'added_seconds' => int,        // balance 에 추가된 초
     *     'new_balance_seconds' => int,
     *   ]
     */
    function charge_overage_top_up(PDO $pdo, string $ownerEmail): array {
        $row = null;
        try {
            $ps = $pdo->prepare('SELECT plan, plan_status, portone_billing_key, portone_customer_id,
                                        overage_enabled, overage_balance_seconds, overage_top_up_count
                                 FROM members WHERE email = :e LIMIT 1');
            $ps->execute([':e' => $ownerEmail]);
            $row = $ps->fetch();
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'db_error', 'amount' => 0, 'added_seconds' => 0, 'new_balance_seconds' => 0];
        }
        if (!$row) return ['ok' => false, 'reason' => 'member_not_found', 'amount' => 0, 'added_seconds' => 0, 'new_balance_seconds' => 0];
        if ((int)($row['overage_enabled'] ?? 0) !== 1) {
            return ['ok' => false, 'reason' => 'overage_not_enabled', 'amount' => 0, 'added_seconds' => 0, 'new_balance_seconds' => (int)($row['overage_balance_seconds'] ?? 0)];
        }
        $billingKey = trim((string)($row['portone_billing_key'] ?? ''));
        if ($billingKey === '') {
            return ['ok' => false, 'reason' => 'no_billing_key', 'amount' => 0, 'added_seconds' => 0, 'new_balance_seconds' => 0];
        }
        $planStatus = strtolower((string)($row['plan_status'] ?? ''));
        if (!in_array($planStatus, ['active', 'trialing'], true)) {
            return ['ok' => false, 'reason' => 'plan_inactive_' . $planStatus, 'amount' => 0, 'added_seconds' => 0, 'new_balance_seconds' => 0];
        }

        $amount = overage_top_up_amount_won();
        $addSeconds = overage_top_up_seconds();
        $paymentId = 'topup-' . date('YmdHis') . '-' . substr(md5($ownerEmail), 0, 8);
        $orderName = 'YOUNGMAN 자동 충전 (' . number_format($amount) . '원 = ' . round($addSeconds / 60) . '분)';

        try {
            $resp = portone_api_call('POST', '/payments/' . urlencode($paymentId) . '/billing-key', [
                'billingKey' => $billingKey,
                'orderName' => $orderName,
                'amount' => ['total' => $amount],
                'currency' => 'KRW',
                'customer' => ['id' => $row['portone_customer_id'] ?? $ownerEmail, 'email' => $ownerEmail],
            ]);
        } catch (Throwable $e) {
            error_log('[charge_overage_top_up] portone call failed (' . $ownerEmail . '): ' . $e->getMessage());
            return ['ok' => false, 'reason' => 'portone_api_error', 'amount' => 0, 'added_seconds' => 0, 'new_balance_seconds' => 0];
        }

        $okHttp = ($resp['status'] >= 200 && $resp['status'] < 300);
        $paymentStatus = is_array($resp['body']) ? portone_extract_status($resp['body']) : '';
        $paidAmount = is_array($resp['body']) ? portone_extract_amount($resp['body']) : 0;

        if (!$okHttp || $paymentStatus !== 'PAID') {
            error_log('[charge_overage_top_up] payment failed: status=' . $resp['status'] . ', payment_status=' . $paymentStatus . ', owner=' . $ownerEmail);
            try {
                $pdo->prepare("INSERT INTO payments (owner_email, portone_payment_id, amount, currency, status, raw_event_json)
                               VALUES (:e, :pid, :amt, 'KRW', :st, :raw)")
                    ->execute([
                        ':e' => $ownerEmail, ':pid' => $paymentId, ':amt' => $amount,
                        ':st' => $paymentStatus !== '' ? $paymentStatus : 'failed',
                        ':raw' => substr($resp['raw'], 0, 4000),
                    ]);
            } catch (Throwable $e) {}
            return ['ok' => false, 'reason' => 'payment_' . ($paymentStatus !== '' ? strtolower($paymentStatus) : 'http_' . $resp['status']), 'amount' => 0, 'added_seconds' => 0, 'new_balance_seconds' => (int)($row['overage_balance_seconds'] ?? 0)];
        }

        $newBalance = (int)($row['overage_balance_seconds'] ?? 0) + $addSeconds;
        try {
            $pdo->prepare("UPDATE members SET
                    overage_balance_seconds = COALESCE(overage_balance_seconds,0) + :add,
                    overage_top_up_count = COALESCE(overage_top_up_count,0) + 1,
                    overage_last_top_up_at = NOW()
                WHERE email = :e")
                ->execute([':add' => $addSeconds, ':e' => $ownerEmail]);
        } catch (Throwable $e) {
            error_log('[charge_overage_top_up] members update failed: ' . $e->getMessage());
        }
        try {
            $pdo->prepare("INSERT INTO payments (owner_email, portone_payment_id, amount, currency, status, paid_at, raw_event_json)
                           VALUES (:e, :pid, :amt, 'KRW', 'PAID', NOW(), :raw)")
                ->execute([
                    ':e' => $ownerEmail, ':pid' => $paymentId, ':amt' => $paidAmount ?: $amount,
                    ':raw' => substr($resp['raw'], 0, 4000),
                ]);
        } catch (Throwable $e) {}

        // FCM 알림 발송 — 자동 충전 결제 완료 사후 통지 (전자상거래법 권장).
        try {
            if (function_exists('send_overage_charged_fcm')) {
                send_overage_charged_fcm($pdo, $ownerEmail, $paidAmount ?: $amount, $addSeconds, $newBalance);
            } else {
                require_once __DIR__ . '/fcm_helpers.php';
                if (function_exists('send_overage_charged_fcm')) {
                    send_overage_charged_fcm($pdo, $ownerEmail, $paidAmount ?: $amount, $addSeconds, $newBalance);
                }
            }
        } catch (Throwable $e) {
            error_log('[charge_overage_top_up] FCM 발송 실패 (결제는 성공): ' . $e->getMessage());
        }

        return [
            'ok' => true,
            'reason' => null,
            'payment_id' => $paymentId,
            'amount' => $paidAmount ?: $amount,
            'added_seconds' => $addSeconds,
            'new_balance_seconds' => $newBalance,
        ];
    }
}

if (!function_exists('portone_plan_label')) {
    function portone_plan_label(string $plan): string {
        switch (strtolower($plan)) {
            case 'sales':   return 'YOUNGMAN Sales 월간 구독';
            case 'master':  return 'YOUNGMAN Master 월간 구독';
            case 'agency':  return 'YOUNGMAN Agency 월간 구독';
            // 옛 가입자 호환
            case 'plus':    return 'YOUNGMAN Sales 월간 구독';
            case 'pro':     return 'YOUNGMAN Master 월간 구독';
            case 'premium': return 'YOUNGMAN Sales 월간 구독';
            default:        return 'YOUNGMAN 구독';
        }
    }
}

if (!function_exists('portone_response')) {
    function portone_response(array $data, int $http = 200): void {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($http);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('billing_pdo')) {
    /**
     * cafe24 db_config.php 표준 — `return [host=>..., port=>..., database=>...,
     * user=>..., password=>...]` array 반환 패턴. process-recording / records 와 동일.
     * 첫 호출 시 billing 관련 테이블 (subscriptions/payments/usage_logs) lazy CREATE.
     */
    function billing_pdo(): PDO {
        $candidates = [
            __DIR__ . '/db_config.php',          // api/billing 의 부모 (api/)
            dirname(__DIR__) . '/db_config.php', // webroot
        ];
        $candidates[] = dirname(__DIR__, 1) . '/db_config.php';
        $candidates[] = dirname(__DIR__, 2) . '/db_config.php';
        $dbConfigPath = null;
        foreach ($candidates as $p) {
            if (is_file($p)) { $dbConfigPath = $p; break; }
        }
        if ($dbConfigPath === null) {
            throw new RuntimeException('db_config.php 위치를 찾을 수 없음.');
        }
        $db = require $dbConfigPath;
        if (!is_array($db)) {
            throw new RuntimeException('db_config.php 가 array 반환 안 함.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'] ?? 'localhost',
            (int)($db['port'] ?? 3306),
            $db['database'] ?? '');
        $pdo = new PDO($dsn, $db['user'] ?? '', $db['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // billing 관련 테이블 자동 CREATE — records.php 의 ensure_* 함수 미경유 endpoint 안전망.
        billing_ensure_tables($pdo);
        return $pdo;
    }
}

if (!function_exists('billing_ensure_tables')) {
    function billing_ensure_tables(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS subscriptions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    owner_email VARCHAR(255) NOT NULL,
                    plan VARCHAR(16) NOT NULL DEFAULT 'free',
                    status VARCHAR(16) NOT NULL DEFAULT 'active',
                    portone_customer_id VARCHAR(64) NULL DEFAULT NULL,
                    portone_billing_key VARCHAR(128) NULL DEFAULT NULL,
                    portone_subscription_id VARCHAR(64) NULL DEFAULT NULL,
                    current_period_start DATETIME NULL DEFAULT NULL,
                    current_period_end DATETIME NULL DEFAULT NULL,
                    cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_sub_owner (owner_email),
                    INDEX idx_sub_status (status),
                    INDEX idx_sub_period_end (current_period_end)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS payments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    owner_email VARCHAR(255) NOT NULL,
                    portone_payment_id VARCHAR(64) NULL DEFAULT NULL,
                    portone_transaction_id VARCHAR(64) NULL DEFAULT NULL,
                    portone_subscription_id VARCHAR(64) NULL DEFAULT NULL,
                    amount INT NOT NULL DEFAULT 0,
                    currency VARCHAR(8) NOT NULL DEFAULT 'KRW',
                    status VARCHAR(32) NOT NULL DEFAULT 'pending',
                    paid_at DATETIME NULL DEFAULT NULL,
                    raw_event_json LONGTEXT NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_pay_owner (owner_email),
                    INDEX idx_pay_payment_id (portone_payment_id),
                    INDEX idx_pay_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usage_logs (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    owner_email VARCHAR(255) NOT NULL,
                    feature VARCHAR(40) NOT NULL,
                    amount INT NOT NULL DEFAULT 1,
                    plan VARCHAR(16) NULL DEFAULT NULL,
                    metadata_json TEXT NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_usage_owner (owner_email),
                    INDEX idx_usage_feature (feature),
                    INDEX idx_usage_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            // members 의 결제 컬럼들도 안전망 lazy ALTER (process-recording 의 ensure 미경유 케이스).
            $cols = [];
            try {
                foreach ($pdo->query("SHOW COLUMNS FROM members")->fetchAll() as $c) $cols[] = $c['Field'];
            } catch (Throwable $e) { /* members 자체 없는 환경 — 무시 */ }
            $addColumns = [
                // 사장님 2026-05-25 — trialing 폐지: 신규 default 'active' (옛 가입자 호환 코드는 별도).
                'plan_status'              => "VARCHAR(16) NOT NULL DEFAULT 'active'",
                'portone_customer_id'      => 'VARCHAR(64) NULL DEFAULT NULL',
                'portone_billing_key'      => 'VARCHAR(128) NULL DEFAULT NULL',
                'portone_subscription_id'  => 'VARCHAR(64) NULL DEFAULT NULL',
                'current_period_start'     => 'DATETIME NULL DEFAULT NULL',
                'current_period_end'       => 'DATETIME NULL DEFAULT NULL',
                'cancel_at_period_end'     => 'TINYINT(1) NOT NULL DEFAULT 0',
                'summary_limit'            => 'INT NULL DEFAULT 0',
                'last_usage_reset_at'      => 'DATETIME NULL DEFAULT NULL',
                // 분 기반 과금 (2026-05-19 추가)
                'summary_limit_minutes'    => 'INT NULL DEFAULT 0',      // 이번달 한도 (분). 2026-05-26 free=0 으로 변경.
                'usage_seconds_period'     => 'INT NOT NULL DEFAULT 0',  // 이번달 누적 사용 (초)
                'overage_enabled'          => 'TINYINT(1) NOT NULL DEFAULT 0',   // 자동 충전 동의 여부
                'overage_balance_seconds'  => 'INT NOT NULL DEFAULT 0',          // 충전 잔여 (초)
                'overage_top_up_count'     => 'INT NOT NULL DEFAULT 0',          // 이번달 충전 횟수
                'overage_last_top_up_at'   => 'DATETIME NULL DEFAULT NULL',
                'last_usage_warning_pct'   => 'INT NOT NULL DEFAULT 0',          // FCM 중복 발송 방지 (0/80/90/100)
                // 통화 녹취 자동 저장 vs 검토 후 저장 (앱팀 2026-05-20 요청 — Native Outbox 흐름)
                'recording_review_mode'    => "VARCHAR(16) NOT NULL DEFAULT 'auto'", // 'auto' = customer_log 자동 INSERT, 'review' = ready_to_review 단계 거침
            ];
            foreach ($addColumns as $col => $def) {
                if (!empty($cols) && !in_array($col, $cols, true)) {
                    try { $pdo->exec("ALTER TABLE members ADD COLUMN `{$col}` {$def}"); }
                    catch (Throwable $e) { error_log('[billing_ensure_tables] ALTER ' . $col . ': ' . $e->getMessage()); }
                }
            }
            // 사장님 2026-05-28 — VAT 별도 정책 + 세금계산서 발행 준비.
            // subscriptions / payments 에 공급가액/세액/합계/세금계산서 ID 컬럼 lazy ALTER.
            $taxColumns = [
                'supply_amount'         => 'INT NOT NULL DEFAULT 0',
                'vat_amount'            => 'INT NOT NULL DEFAULT 0',
                'total_amount'          => 'INT NOT NULL DEFAULT 0',
                'tax_invoice_id'        => 'VARCHAR(64) NULL DEFAULT NULL',
                'tax_invoice_issued_at' => 'DATETIME NULL DEFAULT NULL',
            ];
            foreach (['subscriptions', 'payments'] as $tbl) {
                $tblCols = [];
                try {
                    foreach ($pdo->query("SHOW COLUMNS FROM {$tbl}")->fetchAll() as $c) $tblCols[] = $c['Field'];
                } catch (Throwable $e) { continue; }
                foreach ($taxColumns as $col => $def) {
                    if (!in_array($col, $tblCols, true)) {
                        try { $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `{$col}` {$def}"); }
                        catch (Throwable $e) { error_log("[billing_ensure_tables] ALTER {$tbl}.{$col}: " . $e->getMessage()); }
                    }
                }
            }
            // 사업자 회원 정보 (세금계산서 발행자 사전 등록용) — members lazy ALTER.
            $bizColumns = [
                'business_number'      => 'VARCHAR(20) NULL DEFAULT NULL',   // 사업자등록번호 (xxx-xx-xxxxx)
                'business_name'        => 'VARCHAR(100) NULL DEFAULT NULL',  // 상호
                'business_ceo'         => 'VARCHAR(50) NULL DEFAULT NULL',   // 대표자명
                'business_email'       => 'VARCHAR(255) NULL DEFAULT NULL',  // 세금계산서 수신 이메일
                'business_auto_invoice' => 'TINYINT(1) NOT NULL DEFAULT 0',  // 자동 발행 동의
            ];
            foreach ($bizColumns as $col => $def) {
                if (!empty($cols) && !in_array($col, $cols, true)) {
                    try { $pdo->exec("ALTER TABLE members ADD COLUMN `{$col}` {$def}"); }
                    catch (Throwable $e) { error_log('[billing_ensure_tables] ALTER members.' . $col . ': ' . $e->getMessage()); }
                }
            }
            // 일괄 마이그레이션 — 사장님 2026-05-26: plus→sales, pro→master, premium→sales 전환.
            // 라이브 PortOne 구독자 없음 (사장님 확인) — 자유롭게 plan key 교체.
            // idempotent: 두 번째 호출부터 영향 받는 row 0개.
            try {
                $pdo->exec("UPDATE members SET plan = 'sales'  WHERE plan = 'plus'");
                $pdo->exec("UPDATE members SET plan = 'master' WHERE plan = 'pro'");
                $pdo->exec("UPDATE members SET plan = 'sales'  WHERE plan = 'premium'");
                // 사장님 2026-05-25 — trialing 폐지: 옛 trialing 가입자 자동 → free.
                $pdo->exec("UPDATE members SET plan = 'free' WHERE plan = 'trialing'");
                $pdo->exec("UPDATE members SET plan_status = 'active' WHERE plan_status = 'trialing'");
                // 신규 plan key 회 단위 한도 정리 (회 단위는 deprecated 이지만 호환 유지)
                $pdo->exec("UPDATE members SET summary_limit = 20   WHERE plan = 'sales'  AND (summary_limit IS NULL OR summary_limit < 20)");
                $pdo->exec("UPDATE members SET summary_limit = NULL WHERE plan IN ('master','agency')");
                $pdo->exec("UPDATE members SET summary_limit = 0    WHERE plan = 'free'");
            } catch (Throwable $e) {
                error_log('[billing_ensure_tables] plan migration: ' . $e->getMessage());
            }
            // 분 단위 한도 마이그레이션 — 신규 plan key 기준. NULL/기존값 무관하게 신규 default 강제.
            // 사장님 2026-05-26 — 옛 plus(300)→sales(300) / 옛 pro(1000)→master(700) 변환.
            // admin 수동 override 가 있을 수 있으나 라이브 구독자 없음이라 안전.
            try {
                $pdo->exec("UPDATE members SET summary_limit_minutes = 1500 WHERE plan = 'agency'");
                $pdo->exec("UPDATE members SET summary_limit_minutes = 700  WHERE plan = 'master'");
                $pdo->exec("UPDATE members SET summary_limit_minutes = 300  WHERE plan = 'sales'");
                $pdo->exec("UPDATE members SET summary_limit_minutes = 0    WHERE plan = 'free'");
            } catch (Throwable $e) {
                error_log('[billing_ensure_tables] minutes migration: ' . $e->getMessage());
            }
            $done = true;
        } catch (Throwable $e) {
            error_log('[billing_ensure_tables] failed: ' . $e->getMessage());
            // 다음 호출에서 재시도 가능하도록 $done 그대로.
        }
    }
}

if (!function_exists('billing_supabase_url')) {
    /**
     * .env 의 VITE_SUPABASE_URL 이 `https://xxx.supabase.co/rest/v1/` 형태 — root 만 추출.
     */
    function billing_supabase_url(): string {
        $raw = billing_load_env_value('VITE_SUPABASE_URL') ?: (getenv('VITE_SUPABASE_URL') ?: '');
        return rtrim((string)preg_replace('#/(rest|auth)/v1/?.*$#', '', $raw), '/');
    }
}

if (!function_exists('billing_anon_key')) {
    function billing_anon_key(): string {
        return (string)(billing_load_env_value('VITE_SUPABASE_ANON_KEY') ?: (getenv('VITE_SUPABASE_ANON_KEY') ?: ''));
    }
}

if (!function_exists('billing_require_bearer_email')) {
    /**
     * Authorization Bearer 추출 + Supabase /auth/v1/user 검증 + email 반환.
     * 실패 시 portone_response 로 401 응답 (debug 동봉).
     */
    function billing_require_bearer_email(): string {
        // 헤더에서 Bearer 추출
        $h = '';
        if (function_exists('getallheaders')) {
            $hdrs = getallheaders();
            if (is_array($hdrs)) {
                foreach ($hdrs as $k => $v) { if (strcasecmp((string)$k, 'authorization') === 0) { $h = (string)$v; break; } }
            }
        }
        if ($h === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) $h = (string)$_SERVER['HTTP_AUTHORIZATION'];
        $token = '';
        if (preg_match('/Bearer\s+(.+)/i', $h, $m)) $token = trim($m[1]);
        if ($token === '') {
            portone_response(['status' => 'error', 'code' => 'unauthorized', 'message' => '로그인 필요 (Bearer 없음).', 'debug' => ['stage' => 'no_bearer']], 401);
        }
        $supabaseUrl = billing_supabase_url();
        $anonKey = billing_anon_key();
        if ($supabaseUrl === '' || $anonKey === '') {
            portone_response(['status' => 'error', 'code' => 'config', 'message' => '서버 인증 설정 누락 (.env Supabase URL/Key).', 'debug' => ['url_set' => $supabaseUrl !== '', 'key_set' => $anonKey !== '']], 500);
        }
        $ch = curl_init($supabaseUrl . '/auth/v1/user');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'apikey: ' . $anonKey],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200 || !$resp) {
            $hint = $status === 401 ? '세션 만료 — 페이지 새로고침/재로그인 후 다시 시도.' :
                    ($status === 404 ? 'Supabase URL 경로 오류.' :
                    ($status === 0 ? 'Supabase 네트워크 실패.' : 'Supabase 응답 ' . $status));
            portone_response(['status' => 'error', 'code' => 'unauthorized', 'message' => '토큰 검증 실패. ' . $hint, 'debug' => ['stage' => 'supabase_call', 'auth_status' => $status, 'token_len' => strlen($token)]], 401);
        }
        $data = json_decode((string)$resp, true);
        $email = strtolower(trim((string)($data['email'] ?? '')));
        if ($email === '') {
            portone_response(['status' => 'error', 'code' => 'unauthorized', 'message' => '이메일 추출 실패.'], 401);
        }
        return $email;
    }
}

if (!function_exists('portone_extract_status')) {
    /** PortOne V2 응답에서 결제 상태를 여러 nested 위치에서 시도해 추출. */
    function portone_extract_status(array $payment): string {
        return strtoupper((string)(
            $payment['status']
            ?? $payment['payment']['status']
            ?? $payment['transaction']['status']
            ?? $payment['data']['status']
            ?? ''
        ));
    }
}

if (!function_exists('portone_extract_amount')) {
    function portone_extract_amount(array $payment): int {
        return (int)(
            $payment['amount']['total']
            ?? $payment['amount']
            ?? $payment['payment']['amount']['total']
            ?? $payment['data']['amount']['total']
            ?? 0
        );
    }
}
