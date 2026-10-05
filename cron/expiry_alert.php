<?php
// ============================================================
// cron/expiry_alert.php — COMPLETE REWRITE
// ডকুমেন্টের মেয়াদ শেষের আগে গ্রাহককে SMS-like reminder পাঠানো
// cPanel Cron: 0 8 * * * php /home/USER/public_html/cron/expiry_alert.php
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/bot/Sender.php';

if (!Config::isEnabled('reminder_enabled')) {
    echo "Reminder disabled in settings."; exit;
}

$db     = Database::getInstance();
$sender = new Sender();
$today  = date('Y-m-d');
$total  = 0;
$errors = 0;

// ── Strategy: reminders টেবিল থেকে আজকের due reminders পাঠানো ──
$dueReminders = $db->fetchAll(
    "SELECT r.*,
            c.platform, c.platform_id, c.name, c.language,
            d.doc_type, d.doc_number, d.holder_name, d.expiry_date, d.file_name
     FROM reminders r
     JOIN customers c ON r.customer_id = c.id
     LEFT JOIN documents d ON r.document_id = d.id
     WHERE r.is_sent = 0
       AND r.trigger_date <= ?
       AND c.onboarding_done = 1
     ORDER BY r.trigger_date ASC
     LIMIT 200",
    [$today]
);

foreach ($dueReminders as $rem) {
    try {
        $lang = $rem['language'] ?? 'bn';
        $name = $rem['name'] ?? 'গ্রাহক';
        $exp  = $rem['expiry_date'];

        // Custom message আছে কিনা
        $msg = $rem['message'] ?: null;

        // Default message তৈরি (reminder টেবিলে message না থাকলে)
        if (!$msg) {
            $docType   = $rem['doc_type']   ?? 'ডকুমেন্ট';
            $docNumber = $rem['doc_number'] ?? '';
            $daysLeft  = $exp ? (int) ceil((strtotime($exp) - time()) / 86400) : null;

            $docLabel = match($docType) {
                'passport'        => 'পাসপোর্ট',
                'visa'            => 'ভিসা',
                'nid'             => 'জাতীয় পরিচয়পত্র',
                'driving_license' => 'ড্রাইভিং লাইসেন্স',
                default           => 'ডকুমেন্ট',
            };

            $numStr = $docNumber ? " ({$docNumber})" : '';
            $expStr = $exp ? date('d/m/Y', strtotime($exp)) : '';

            if ($daysLeft !== null && $daysLeft <= 0) {
                $msg = "🔴 *মেয়াদ শেষ!*\n\n{$name} ভাই/বোন,\n\nআপনার *{$docLabel}{$numStr}* এর মেয়াদ শেষ হয়ে গেছে।\nমেয়াদ শেষ হয়েছিল: {$expStr}\n\nদ্রুত রিনিউ করুন।\n\n📞 " . Config::get('company_phone','');
            } elseif ($daysLeft !== null && $daysLeft <= 7) {
                $msg = "🚨 *অতি জরুরি — মেয়াদ শেষ হচ্ছে!*\n\n{$name} ভাই/বোন,\n\nআপনার *{$docLabel}{$numStr}* এর মেয়াদ মাত্র *{$daysLeft} দিন* বাকি!\nমেয়াদ শেষ: {$expStr}\n\n❗ আজই যোগাযোগ করুন:\n📞 " . Config::get('company_phone','');
            } elseif ($daysLeft !== null && $daysLeft <= 15) {
                $msg = "⚠️ *জরুরি মেয়াদ সতর্কতা!*\n\n{$name} ভাই/বোন,\n\nআপনার *{$docLabel}{$numStr}* এর মেয়াদ মাত্র *{$daysLeft} দিন* বাকি!\nমেয়াদ শেষ: {$expStr}\n\n🔔 এখনই রিনিউ করুন!\n📞 " . Config::get('company_phone','');
            } else {
                $msg = "⏰ *মেয়াদ সতর্কতা*\n\n{$name} ভাই/বোন,\n\nআপনার *{$docLabel}{$numStr}* এর মেয়াদ আর মাত্র *{$daysLeft} দিন* বাকি।\nমেয়াদ শেষ: {$expStr}\n\n📋 রিনিউ করার ব্যবস্থা নিন।\n📞 " . Config::get('company_phone','');
            }
        }

        // পাঠানো
        $sender->send($rem['platform'], $rem['platform_id'], $msg);

        // Sent mark করা
        $db->execute(
            "UPDATE reminders SET is_sent=1, sent_at=NOW() WHERE id=?",
            [$rem['id']]
        );

        $total++;
        Logger::info("Reminder sent: customer #{$rem['customer_id']}, type: {$rem['reminder_type']}");
        usleep(350000); // Rate limit

    } catch (Exception $e) {
        $errors++;
        Logger::error("Reminder #{$rem['id']} failed: " . $e->getMessage());
    }
}

// ── Strategy 2: documents টেবিল সরাসরি চেক করা (reminders টেবিলে না থাকলে) ──
// OCR হয়েছে এমন ডকুমেন্ট যেগুলোর জন্য reminders নেই সেগুলো এখানে ধরা পড়বে
$noReminderDocs = $db->fetchAll(
    "SELECT d.*, c.platform, c.platform_id, c.name, c.language
     FROM documents d
     JOIN customers c ON d.customer_id = c.id
     WHERE d.ocr_processed = 1
       AND d.expiry_date IS NOT NULL
       AND d.expiry_date BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)
       AND c.onboarding_done = 1
       AND NOT EXISTS (
           SELECT 1 FROM reminders r
           WHERE r.document_id = d.id AND r.is_sent = 1
       )
       AND NOT EXISTS (
           SELECT 1 FROM reminders r
           WHERE r.document_id = d.id AND r.trigger_date <= ? AND r.is_sent = 0
       )",
    [$today, $today, $today]
);

foreach ($noReminderDocs as $doc) {
    try {
        $exp      = $doc['expiry_date'];
        $daysLeft = (int) ceil((strtotime($exp) - time()) / 86400);
        $lang     = $doc['language'] ?? 'bn';
        $name     = $doc['name'] ?? 'গ্রাহক';
        $docLabel = match($doc['doc_type'] ?? 'other') {
            'passport'        => 'পাসপোর্ট',
            'visa'            => 'ভিসা',
            'nid'             => 'জাতীয় পরিচয়পত্র',
            'driving_license' => 'ড্রাইভিং লাইসেন্স',
            default           => 'ডকুমেন্ট',
        };
        $numStr = !empty($doc['doc_number']) ? " ({$doc['doc_number']})" : '';
        $expStr = date('d/m/Y', strtotime($exp));

        $msg = $daysLeft <= 0
            ? "🔴 *মেয়াদ শেষ!*\n\n{$name} ভাই/বোন,\n\nআপনার *{$docLabel}{$numStr}* মেয়াদ শেষ হয়ে গেছে ({$expStr})।\n\n⚡ দ্রুত যোগাযোগ করুন: " . Config::get('company_phone','')
            : "⚠️ *মেয়াদ সতর্কতা*\n\n{$name} ভাই/বোন,\n\nআপনার *{$docLabel}{$numStr}* এর মেয়াদ {$expStr} তারিখে শেষ হবে (আর {$daysLeft} দিন)।\n\n📞 " . Config::get('company_phone','');

        $sender->send($doc['platform'], $doc['platform_id'], $msg);

        // Reminders টেবিলে sent হিসেবে লগ করা
        $db->execute(
            "INSERT INTO reminders (customer_id, document_id, reminder_type, doc_type, doc_number, trigger_date, message, is_sent, sent_at)
             VALUES (?,?,?,?,?,?,?,1,NOW())",
            [$doc['customer_id'], $doc['id'], 'custom', $doc['doc_type'], $doc['doc_number'], $today, $msg]
        );

        $total++;
        usleep(350000);

    } catch (Exception $e) {
        $errors++;
        Logger::error("NoReminder doc #{$doc['id']}: " . $e->getMessage());
    }
}

$summary = "Expiry alerts done. Sent: {$total}, Errors: {$errors}";
Logger::info($summary);
echo $summary . "\n";
