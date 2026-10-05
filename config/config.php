<?php
// ============================================================
// config/config.php — Main Bootstrap
// ============================================================
define('BASE_PATH', dirname(__DIR__));
// Timezone is set dynamically - see Config::applyTimezone() called below
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', BASE_PATH . '/logs/error.log');

require_once __DIR__ . '/database.php';

class Config {
    private static array $cache = [];
    public static function get(string $key, string $default = ''): string {
        if (isset(self::$cache[$key])) return self::$cache[$key];
        try {
            $r = Database::getInstance()->fetchOne(
                "SELECT setting_value FROM settings WHERE setting_key=?", [$key]
            );
            return self::$cache[$key] = $r ? ($r['setting_value'] ?? $default) : $default;
        } catch (Exception $e) { return $default; }
    }
    public static function set(string $key, string $value): void {
        Database::getInstance()->execute(
            "INSERT INTO settings(setting_key,setting_value) VALUES(?,?)
             ON DUPLICATE KEY UPDATE setting_value=?, updated_at=NOW()",
            [$key, $value, $value]
        );
        self::$cache[$key] = $value;
    }
    public static function isEnabled(string $key): bool {
        return self::get($key, '0') === '1';
    }
    public static function clearCache(): void { self::$cache = []; }

    // DB লোড হওয়ার পরে Timezone সেট করা
    public static function applyTimezone(): void {
        try {
            $tz = self::get('timezone', 'Asia/Dhaka');
            if ($tz && function_exists('date_default_timezone_set')) {
                date_default_timezone_set($tz);
            }
        } catch (Exception $e) {
            date_default_timezone_set('Asia/Dhaka'); // fallback
        }
    }
}

class Logger {
    public static function log(string $msg, string $level = 'INFO'): void {
        $dir = BASE_PATH . '/logs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(
            $dir . '/bot.log',
            '[' . date('Y-m-d H:i:s') . '][' . $level . '] ' . $msg . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
    public static function info(string $m): void  { self::log($m, 'INFO'); }
    public static function error(string $m): void { self::log($m, 'ERROR'); }
    public static function warn(string $m): void  { self::log($m, 'WARN'); }
}

class RateLimiter {
    public static function check(string $platformId, int $max = 15): bool {
        try {
            $db  = Database::getInstance();
            $key = date('YmdHi');
            $db->execute(
                "INSERT INTO rate_limits(platform_id,minute_key,count) VALUES(?,?,1)
                 ON DUPLICATE KEY UPDATE count=count+1",
                [$platformId, $key]
            );
            $row = $db->fetchOne(
                "SELECT count FROM rate_limits WHERE platform_id=? AND minute_key=?",
                [$platformId, $key]
            );
            return ($row['count'] ?? 0) <= $max;
        } catch (Exception $e) { return true; }
    }
}

// Auto-apply timezone — DB থেকে সরাসরি পড়া
try {
    $__tz = null;
    if (class_exists('Database')) {
        $__db = Database::getInstance();
        if ($__db) {
            $__row = $__db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='timezone' LIMIT 1");
            $__tz  = $__row['setting_value'] ?? null;
        }
    }
    date_default_timezone_set($__tz ?: 'Asia/Dhaka');
    unset($__tz, $__db, $__row);
} catch(Exception $e) {
    date_default_timezone_set('Asia/Dhaka');
}
