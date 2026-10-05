<?php
// config/security.php
class Security {
    public static function checkRateLimit(string $pid, int $max = 15): bool {
        return RateLimiter::check($pid, $max);
    }
    public static function verifyWhatsAppSignature(string $payload, string $sig): bool {
        $secret = Config::get('whatsapp_app_secret', '');
        if (!$secret) return true;
        return hash_equals('sha256=' . hash_hmac('sha256', $payload, $secret), $sig);
    }
    public static function sanitizeString(string $in, int $max = 500): string {
        return mb_substr(trim(htmlspecialchars(strip_tags($in), ENT_QUOTES, 'UTF-8')), 0, $max, 'UTF-8');
    }
    public static function sanitizeInt(mixed $in): int {
        return (int) filter_var($in, FILTER_SANITIZE_NUMBER_INT);
    }
    public static function sanitizeFloat(mixed $in): float {
        return (float) filter_var($in, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }
    public static function generateCSRFToken(): string {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
    public static function verifyCSRFToken(string $token): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    public static function isSQLInjection(string $in): bool {
        return (bool) preg_match('/(union\s+select|insert\s+into|drop\s+table|delete\s+from|exec\s*\(|javascript:)/i', $in);
    }
    public static function requireAdminAuth(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['admin_logged_in'])) { header('Location: /admin/'); exit; }
        if (!empty($_SESSION['admin_last_activity']) && time() - $_SESSION['admin_last_activity'] > 7200) {
            session_destroy(); header('Location: /admin/?timeout=1'); exit;
        }
        $_SESSION['admin_last_activity'] = time();
    }
    public static function setSecurityHeaders(): void {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
        }
    }
    public static function logEvent(string $type, string $detail, string $pid = ''): void {
        try {
            Database::getInstance()->execute(
                "INSERT INTO security_logs(type,ip_address,platform_id,details) VALUES(?,?,?,?)",
                [$type, $_SERVER['REMOTE_ADDR'] ?? '', $pid, $detail]
            );
        } catch (Exception $e) {}
    }
}
