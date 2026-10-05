<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/services/Services.php';
require_once dirname(__DIR__) . '/bot/Sender.php';

// Update rates first
$cs = new CurrencyService();
$result = $cs->updateRates();
Logger::info("Currency rates updated: " . ($result ? 'success' : 'failed'));

// Check if we should broadcast based on schedule
if (Config::isEnabled('currency_alert_enabled')) {
    $freq    = Config::get('currency_send_frequency', 'daily');
    $sendDay = (int)Config::get('currency_send_day', '1'); // 0=Sun,1=Mon...
    $today   = (int)date('w'); // current day of week
    $dayOfMonth = (int)date('j');

    $shouldBroadcast = match($freq) {
        'daily'   => true,
        'weekly'  => ($today === $sendDay),
        'monthly' => ($dayOfMonth === 1),
        default   => true,
    };

    if ($shouldBroadcast) {
        $cs->broadcastRates();
        Logger::info("Currency broadcast done (frequency: $freq).");
    } else {
        Logger::info("Currency broadcast skipped (frequency: $freq, today: $today, sendDay: $sendDay).");
    }
}
echo "Currency update done.
";
