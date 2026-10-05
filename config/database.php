<?php
// ============================================================
// config/database.php
// ★ এই ফাইলটি সম্পাদনা করুন — আপনার cPanel DB তথ্য দিন
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'creativesit_aichatbot');   // ← cPanel MySQL DB নাম
define('DB_USER', 'creativesit_aichatbot');   // ← cPanel MySQL username
define('DB_PASS', 'Anwar24252646.@');  // ← MySQL password

class Database {
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct() {
        try {
            // charset=utf8mb4 DSN-এ থাকলে বাংলা সঠিকভাবে কাজ করে
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // বাংলা সহ সব Unicode সঠিকভাবে সংরক্ষণ করতে
                PDO::MYSQL_ATTR_INIT_COMMAND =>
                    "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; " .
                    "SET character_set_client    = utf8mb4; " .
                    "SET character_set_results   = utf8mb4; " .
                    "SET character_set_connection= utf8mb4;",
            ]);
        } catch (PDOException $e) {
            error_log('[BOT-DB] ' . $e->getMessage());
            http_response_code(200);
            exit('{"ok":true}');
        }
    }

    public static function getInstance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public function fetchOne(string $sql, array $p = []): ?array {
        $s = $this->pdo->prepare($sql);
        $s->execute($p);
        return $s->fetch() ?: null;
    }

    public function fetchAll(string $sql, array $p = []): array {
        $s = $this->pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchAll();
    }

    public function execute(string $sql, array $p = []): bool {
        return $this->pdo->prepare($sql)->execute($p);
    }

    public function insert(string $sql, array $p = []): int {
        $this->pdo->prepare($sql)->execute($p);
        return (int) $this->pdo->lastInsertId();
    }

    public function count(string $sql, array $p = []): int {
        $r = $this->fetchOne($sql, $p);
        return $r ? (int) reset($r) : 0;
    }

    public function getConnection(): PDO { return $this->pdo; }
}
