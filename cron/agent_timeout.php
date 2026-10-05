<?php
// cron/agent_timeout.php
// এজেন্ট ৫ মিনিটে জবাব না দিলে স্বয়ংক্রিয়ভাবে bot mode
// cPanel Cron: * * * * * php /home/USER/public_html/cron/agent_timeout.php

require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/bot/Sender.php';

$timeoutMinutes = (int)Config::get('agent_timeout_minutes', '5');
$db     = Database::getInstance();
$sender = new Sender();

// human mode-এ থাকা sessions যেগুলোতে timeout হয়েছে
$timedOut = $db->fetchAll(
    "SELECT cs.*, c.platform, c.platform_id, c.name, c.language
     FROM chat_sessions cs
     JOIN customers c ON cs.customer_id = c.id
     WHERE cs.mode = 'human'
       AND cs.updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
     LIMIT 50",
    [$timeoutMinutes]
);

$count = 0;
foreach ($timedOut as $s) {
    // bot mode-এ ফেরানো
    $db->execute(
        "UPDATE chat_sessions SET mode='bot', updated_at=NOW() WHERE id=?",
        [$s['id']]
    );

    // গ্রাহককে জানানো
    $lang = $s['language'] ?? 'bn';
    $msg  = $lang === 'bn'
        ? "⏰ এজেন্ট এখন ব্যস্ত আছেন।\n\nআমি (রিয়া) আবার আপনার সঙ্গে আছি।\n\nকীভাবে সাহায্য করতে পারি? \"menu\" লিখুন।"
        : "⏰ Agent is currently busy.\n\nI'm (Riya) back with you.\n\nHow can I help? Type \"menu\".";

    try {
        $sender->send($s['platform'], $s['platform_id'], $msg);
        $count++;
    } catch (Exception $e) {
        Logger::error("AgentTimeout send to #{$s['customer_id']}: " . $e->getMessage());
    }
}

$summary = "Agent timeout processed: {$count} sessions returned to bot mode.";
Logger::info($summary);
echo $summary . "\n";
