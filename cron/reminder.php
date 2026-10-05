<?php
// ============================================================
// cron/reminder.php
// ডকুমেন্ট মেয়াদ রিমাইন্ডার
// cPanel Cron: 0 9 * * * php /home/your_cpanel_user/public_html/cron/reminder.php
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/bot/Sender.php';

if (!Config::isEnabled('reminder_enabled')) exit;

$db      = Database::getInstance();
$sender  = new Sender();
$today   = date('Y-m-d');

// আজকের রিমাইন্ডার খোঁজা
$reminders = $db->fetchAll(
    "SELECT r.*, c.platform, c.platform_id, c.language, c.name
     FROM reminders r
     JOIN customers c ON r.customer_id = c.id
     WHERE r.trigger_date = ? AND r.is_sent = 0",
    [$today]
);

Logger::info("Reminder cron: found " . count($reminders) . " reminders for today");

foreach ($reminders as $reminder) {
    try {
        $msg = $reminder['message'] ?: "⚠️ আপনার ডকুমেন্টের মেয়াদ শেষ হতে চলেছে। দ্রুত রিনিউ করুন।";

        $sender->send($reminder['platform'], $reminder['platform_id'], $msg);

        // সেন্ট মার্ক করা
        $db->execute("UPDATE reminders SET is_sent = 1, sent_at = NOW() WHERE id = ?", [$reminder['id']]);

        Logger::info("Reminder sent to customer: " . $reminder['name']);
        usleep(500000); // 0.5 সেকেন্ড বিরতি

    } catch (Exception $e) {
        Logger::error("Reminder send error: " . $e->getMessage());
    }
}

echo "Reminder cron done. Processed: " . count($reminders);
