# 🤖 Multi-Platform Bot v11 — সম্পূর্ণ ইন্সটলেশন গাইড
## WhatsApp + Telegram + Messenger | PHP 8.2 + MySQL + cPanel

---

## ⚡ ইন্সটলেশন — ৫ ধাপ

### ধাপ ১: MySQL Database (cPanel → MySQL Databases)
```
Database: yourname_bot  |  User: yourname_user  |  Privilege: ALL
```

### ধাপ ২: SQL Import (phpMyAdmin)
```
Connection Collation → utf8mb4_unicode_ci
Import: sql/bot_complete.sql  ← একটিমাত্র ফাইল!
```

### ধাপ ৩: config/database.php
```php
define('DB_NAME', 'yourname_bot');
define('DB_USER', 'yourname_user');
define('DB_PASS', 'yourpassword');
```

### ধাপ ৪: ফাইল আপলোড + SSL
```
1. public_html/ এ সব ফাইল Extract
2. cPanel → SSL/TLS → Let's Encrypt Install
```

### ধাপ ৫: Setup
```
https://yourdomain.com/setup.php → Admin Password + Telegram Webhook
সেটআপ শেষে setup.php মুছুন!
```

---

## ⏰ Cron Jobs (cPanel → Advanced → Cron Jobs)
**USER = আপনার cPanel username**

```
* * * * *    php /home/USER/public_html/cron/agent_timeout.php
0 9 * * *    php /home/USER/public_html/cron/reminder.php
0 10 1 * *   php /home/USER/public_html/cron/payment_reminder.php
0 8 * * *    php /home/USER/public_html/cron/currency_update.php
0 6 * * 1    php /home/USER/public_html/cron/expiry_alert.php
*/5 * * * *  php /home/USER/public_html/cron/broadcast_queue.php
0 7 * * *    php /home/USER/public_html/cron/scrape_embassy_news.php
```

> php path: `/usr/local/bin/php` (cPanel Terminal: `which php`)

---

## Webhook URLs
```
Telegram:  https://yourdomain.com/webhook/telegram.php
WhatsApp:  https://yourdomain.com/webhook/whatsapp.php
Messenger: https://yourdomain.com/webhook/messenger.php
```

## Admin Pages
```
Dashboard:          /admin/
গ্রাহক ও কনফিগ:    /admin/customers.php
Member Cards:       /admin/member_cards.php
ডকুমেন্ট:          /admin/documents.php
সন্দেহজনক:         /admin/fake_documents.php
সেবা:              /admin/services.php
Embassy News:       /admin/news.php
রিপোর্ট:           /admin/reports.php
Status Track:       /track/
Digital Locker:     /locker/
```

## Bot Commands
```
menu/মেনু   status   হিসাব   কাগজ   card
locker      salary   rate    sos    agent
```

## নিরাপত্তা চেকলিস্ট
- [ ] setup.php মুছুন
- [ ] Admin password শক্তিশালী
- [ ] SSL চালু (HTTPS)
- [ ] cPanel Backup চালু
