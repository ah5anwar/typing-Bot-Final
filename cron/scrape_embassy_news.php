<?php
// ============================================================
// cron/scrape_embassy_news.php
// Embassy নিউজ স্ক্র্যাপ করা (প্রতিদিন একবার)
// cPanel Cron: 0 7 * * * php /home/user/public_html/cron/scrape_embassy_news.php
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/bot/Sender.php';
require_once dirname(__DIR__) . '/services/EmbassyNewsService.php';

$service = new EmbassyNewsService();
$found   = $service->scrapeNews();

Logger::info("Embassy news scraped: " . count($found) . " new items");

// Admin-কে জানানো (pending items থাকলে)
$pendingCount = Database::getInstance()->fetchOne(
    "SELECT COUNT(*) as c FROM embassy_news WHERE status='pending'"
)['c'] ?? 0;

if ($pendingCount > 0) {
    $groupId = Config::get('admin_telegram_group');
    if ($groupId) {
        require_once dirname(__DIR__) . '/bot/Sender.php';
        $sender = new Sender();
        $sender->sendTelegramMessage(
            $groupId,
            "🌍 *Embassy News আপডেট!*\n\n{$pendingCount}টি নতুন আপডেট পাওয়া গেছে।\n\nAdmin Panel থেকে অনুমোদন দিন:\n/admin/news.php"
        );
    }
}

echo "Scraped: " . count($found) . " | Pending: " . $pendingCount;
