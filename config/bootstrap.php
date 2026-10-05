<?php
// config/bootstrap.php — Global error handler (সব webhook-এ include করুন)
set_exception_handler(function (Throwable $e) {
    $dir = defined('BASE_PATH') ? BASE_PATH . '/logs' : __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/error.log',
        '[' . date('Y-m-d H:i:s') . '] EXCEPTION: ' . $e->getMessage() .
        ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    if (!headers_sent()) { http_response_code(200); header('Content-Type: application/json'); }
    echo '{"ok":true}'; exit;
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $dir = defined('BASE_PATH') ? BASE_PATH . '/logs' : __DIR__ . '/../logs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/fatal.log',
            '[' . date('Y-m-d H:i:s') . '] FATAL: ' . $e['message'] .
            ' in ' . $e['file'] . ':' . $e['line'] . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        if (!headers_sent()) { http_response_code(200); echo '{"ok":true}'; }
    }
});

// Timezone: config.php লোড হওয়ার পর স্বয়ংক্রিয়ভাবে সেট হয়
