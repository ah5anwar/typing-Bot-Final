#!/bin/bash
# ============================================================
# FINAL INSTALLATION GUIDE
# সম্পূর্ণ ইন্সটলেশন গাইড
# ============================================================

echo "
╔══════════════════════════════════════════════════════╗
║   Multi-Platform Bot - Complete Installation Guide    ║
║   WhatsApp + Telegram + Messenger + AI               ║
╚══════════════════════════════════════════════════════╝

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ধাপ ১: ফাইল আপলোডের ক্রম (cPanel File Manager)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

1. Phase 1 ZIP: সব ফাইল public_html/-এ আপলোড করুন
2. Phase 2 ZIP: নতুন ফাইল যোগ + BotEngine.php replace
3. Phase 3 ZIP: নতুন ফাইল যোগ + BotEngine.php replace
4. Final  ZIP: নতুন ফাইল যোগ + BotEngine.php replace

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ধাপ ২: Database Setup
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

phpMyAdmin → Import (এই ক্রমে):
1. sql/database.sql          (Phase 1 - মূল DB)
2. sql/migration_phase3.sql  (Phase 3 upgrade)
3. sql/migration_final.sql   (Final upgrade)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ধাপ ৩: config/database.php আপডেট
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

define('DB_HOST', 'localhost');
define('DB_NAME', 'yourname_bot');    ← আপনার DB নাম
define('DB_USER', 'yourname_user');   ← আপনার DB user
define('DB_PASS', 'your_password');   ← আপনার password

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ধাপ ৪: Folder তৈরি করুন (cPanel → File Manager)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

public_html/
├── public/qr/          ← QR Code images
├── public/cards/       ← Member ID Cards
├── public/invoices/    ← Invoice HTML files
├── tmp/                ← Temporary files
└── logs/               ← Error logs

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ধাপ ৫: setup.php দিয়ে প্রাথমিক সেটআপ
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

https://yourdomain.com/setup.php

1. Admin password সেট করুন
2. Telegram webhook সেট করুন
3. WhatsApp ও Messenger URL নোট করুন

সেটআপের পর setup.php মুছে দিন!

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ধাপ ৬: Admin Panel সেটিংস
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

https://yourdomain.com/admin/

1. AI API Key (Gemini / OpenRouter)
2. Telegram Bot Token
3. WhatsApp Access Token + Phone ID
4. Messenger Page Token
5. Google Drive Service Account JSON
6. Google Drive Root Folder ID
7. Admin Telegram Group ID
8. Company Name / Phone / Address
9. সেবা ও FAQ যোগ করুন
10. এজেন্ট যোগ করুন

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ধাপ ৭: cPanel Cron Jobs
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Minute Hour Day Month Weekday   Command
------- ---- --- ----- -------   -------
0       9    *   *     *         php /home/USER/public_html/cron/reminder.php
0       10   1   *     *         php /home/USER/public_html/cron/payment_reminder.php
0       8    *   *     *         php /home/USER/public_html/cron/currency_update.php
0       6    *   *     1         php /home/USER/public_html/cron/expiry_alert.php
*/5     *    *   *     *         php /home/USER/public_html/cron/broadcast_queue.php
0       7    *   *     *         php /home/USER/public_html/cron/scrape_embassy_news.php

(USER = আপনার cPanel username)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Webhook URLs
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Telegram:  https://yourdomain.com/webhook/telegram.php
WhatsApp:  https://yourdomain.com/webhook/whatsapp.php
Messenger: https://yourdomain.com/webhook/messenger.php
Admin:     https://yourdomain.com/admin/
Track:     https://yourdomain.com/track/
Locker:    https://yourdomain.com/locker/
Reports:   https://yourdomain.com/admin/reports.php
News:      https://yourdomain.com/admin/news.php
Services:  https://yourdomain.com/admin/services.php

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
API Keys সংগ্রহের লিংক
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Gemini:    https://aistudio.google.com/apikey
OpenRouter:https://openrouter.ai/keys
Telegram:  @BotFather (Telegram-এ)
WhatsApp:  https://developers.facebook.com
Messenger: https://developers.facebook.com
Google:    https://console.cloud.google.com

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Bot কমান্ড সমূহ
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

menu       → মূল মেনু
status     → আবেদনের অবস্থা
হিসাব      → পেমেন্ট + Invoice
কাগজ       → Drive ফোল্ডার লিংক
card       → Member ID Card
locker     → Digital Locker
salary     → স্যালারি ক্যালকুলেটর
rate       → কারেন্সি রেট
agent      → Human Agent
sos        → জরুরি সাহায্য

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
সম্পূর্ণ ফিচার তালিকা (২৬টি)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

✅ WhatsApp + Telegram + Messenger Webhook
✅ Gemini + OpenRouter AI (Fallback সহ)
✅ Admin Panel থেকে সব কিছু নিয়ন্ত্রণ
✅ FAQ সিস্টেম (Database-based)
✅ সেবা ম্যানেজমেন্ট (১১+ দেশ)
✅ অনবোর্ডিং কাস্টমাইজেশন
✅ Application Tracking
✅ Live Chat (Human Handover)
✅ Google Drive Document Upload
✅ OCR AI (পাসপোর্ট/ভিসা স্ক্যান)
✅ Fake Document Detection
✅ Invoice PDF Generator (HTML)
✅ Payment Tracker
✅ QR Code Member ID Card
✅ Agent Digital Visiting Card
✅ Digital Locker (পাসওয়ার্ড সুরক্ষিত)
✅ Salary Calculator
✅ Currency Rate Alert (Daily)
✅ Document Expiry Reminder
✅ Payment Reminder
✅ Smart Status Notification
✅ Broadcast Queue System
✅ Embassy News Scraper
✅ Multi-Country News Feed
✅ Scam Alert System
✅ SOS Emergency Support
✅ Voice Message (AI Transcription)
✅ Multi-language (বাংলা/English/Hindi/Arabic)
✅ Office Hours Auto-Reply
✅ Rate Limiting + Security
✅ Reports & Analytics Dashboard
"
