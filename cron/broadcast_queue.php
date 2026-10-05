<?php
// ============================================================
// cron/broadcast_queue.php
// Broadcast Queue প্রসেস করা
// cPanel Cron: */5 * * * * php /home/user/public_html/cron/broadcast_queue.php
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/bot/Sender.php';
require_once dirname(__DIR__) . '/services/EmbassyNewsService.php';

if (!Config::isEnabled('broadcast_enabled')) { echo "Broadcast disabled."; exit; }

$queue  = new BroadcastQueue();
$result = $queue->process();

Logger::info("Broadcast queue processed: " . $result['processed'] . " messages");
echo "Broadcast queue processed: " . $result['processed'];
