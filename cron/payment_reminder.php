<?php
// ============================================================
// cron/payment_reminder.php
// বকেয়া পেমেন্ট রিমাইন্ডার
// cPanel Cron: 0 10 1 * * php /home/user/public_html/cron/payment_reminder.php
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/bot/Sender.php';

if (!Config::isEnabled('payment_reminder_enabled')) exit;

$db     = Database::getInstance();
$sender = new Sender();

$duePayments = $db->fetchAll(
    "SELECT p.*, c.platform, c.platform_id, c.name, c.language, s.name as service_name
     FROM payments p
     JOIN customers c ON p.customer_id = c.id
     LEFT JOIN applications a ON p.application_id = a.id
     LEFT JOIN services s ON a.service_id = s.id
     WHERE p.balance > 0"
);

Logger::info("Payment reminder: " . count($duePayments) . " due payments");

foreach ($duePayments as $payment) {
    $lang = $payment['language'] ?? 'bn';
    $msg  = $lang === 'bn'
        ? "💰 সম্মানিত {$payment['name']},\n\n"
            . "আপনার \"" . ($payment['service_name'] ?? 'সেবা') . "\" এর বকেয়া:\n"
            . "বকেয়া: {$payment['balance']} {$payment['currency']}\n\n"
            . "দয়া করে পরিশোধ করুন। ধন্যবাদ।"
        : "💰 Dear {$payment['name']},\n\n"
            . "Outstanding payment for \"" . ($payment['service_name'] ?? 'Service') . "\":\n"
            . "Balance: {$payment['balance']} {$payment['currency']}\n\n"
            . "Please clear your dues. Thank you.";

    try {
        $sender->send($payment['platform'], $payment['platform_id'], $msg);
        usleep(500000);
    } catch (Exception $e) {
        Logger::error("Payment reminder error: " . $e->getMessage());
    }
}

echo "Payment reminder done.";


// ============================================================
// NOTE: আলাদা ফাইল হিসেবে save করুন: cron/currency_update.php
// cPanel Cron: 0 8 * * * php /home/user/public_html/cron/currency_update.php
// ============================================================
/*
<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/services/Services.php';
require_once dirname(__DIR__) . '/bot/Sender.php';

$cs = new CurrencyService();
$cs->updateRates();

// প্রতিদিন সকালে গ্রাহকদের রেট পাঠানো
if (date('H') == 8) {
    $cs->broadcastRates();
}

echo "Currency updated.";
*/
