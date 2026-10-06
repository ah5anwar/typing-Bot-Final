# 🤖 Multi-Platform Bot - ইন্সটলেশন গাইড

## প্রজেক্ট পরিচিতি
WhatsApp + Telegram + Messenger-এর জন্য সম্পূর্ণ অটোমেশন বট।
PHP 8.2 + MySQL + cPanel Shared Hosting-এ কাজ করে।

---

## 📁 ফোল্ডার স্ট্রাকচার

```
public_html/
├── admin/          → Admin Panel
├── bot/            → বট কোর লজিক
├── config/         → কনফিগারেশন (⚠️ বাইরে রাখুন)
├── cron/           → Scheduled Jobs
├── services/       → External API Services
├── track/          → পাবলিক স্ট্যাটাস ট্র্যাকিং
├── webhook/        → Platform Webhooks
├── public/         → Public Assets (QR Code ইত্যাদি)
├── tmp/            → Temporary Files (auto-created)
├── logs/           → Error Logs (auto-created)
├── sql/            → Database Schema
└── setup.php       → প্রথমবার সেটআপ (পরে মুছুন!)
```

---

## 🚀 ধাপে ধাপে ইন্সটলেশন

### ধাপ ১: ডেটাবেজ তৈরি
1. cPanel → MySQL Databases
2. নতুন Database তৈরি করুন: `yourname_bot`
3. নতুন User তৈরি করুন এবং সব permission দিন
4. phpMyAdmin খুলুন → `sql/database.sql` ফাইলটি Import করুন

### ধাপ ২: config/ ফাইল সেটআপ
```php
// config/database.php ফাইলে আপনার DB তথ্য দিন:
define('DB_HOST', 'localhost');
define('DB_NAME', 'yourname_bot');
define('DB_USER', 'yourname_botuser');
define('DB_PASS', 'your_password');
```

### ধাপ ৩: ফাইল আপলোড
1. cPanel File Manager বা FTP দিয়ে সব ফাইল public_html/-এ আপলোড করুন
2. `config/` ফোল্ডারটি `public_html/`-এর বাইরে রাখুন (নিরাপত্তার জন্য)

### ধাপ ৪: SSL Certificate
1. cPanel → SSL/TLS → Let's Encrypt
2. আপনার Domain-এ ফ্রি SSL ইন্সটল করুন
3. WhatsApp ও Messenger-এর জন্য HTTPS বাধ্যতামূলক!

### ধাপ ৫: প্রথমবার সেটআপ
1. ব্রাউজারে যান: `https://yourdomain.com/setup.php`
2. Admin পাসওয়ার্ড সেট করুন
3. Telegram Webhook সেট করুন
4. WhatsApp ও Messenger Webhook URL নোট করুন
5. **সেটআপ শেষে setup.php ফাইলটি মুছে দিন!**

### ধাপ ৬: Admin Panel কনফিগার
1. `https://yourdomain.com/admin/` খুলুন
2. লগইন করুন
3. সেটিংস → AI API Key দিন (Gemini/OpenRouter)
4. সেটিংস → Platform Tokens দিন
5. সেবা ও FAQ যোগ করুন

### ধাপ ৭: Cron Job সেটআপ
cPanel → Cron Jobs → এই কমান্ডগুলো যোগ করুন:

```
0 9 * * *   php /home/username/public_html/cron/reminder.php
0 10 1 * *  php /home/username/public_html/cron/payment_reminder.php
0 8 * * *   php /home/username/public_html/cron/currency_update.php
```

---

## 🔑 প্রয়োজনীয় API Keys

| সার্ভিস | কোথায় পাবেন | খরচ |
|---------|------------|-----|
| Gemini API | aistudio.google.com | ফ্রি টায়ার আছে |
| OpenRouter | openrouter.ai | Pay-as-you-go |
| Telegram Bot Token | @BotFather | সম্পূর্ণ ফ্রি |
| WhatsApp Meta API | developers.facebook.com | ফ্রি (১০০০ msg/মাস) |
| Messenger API | developers.facebook.com | ফ্রি |
| Google Drive API | console.cloud.google.com | ফ্রি (15GB) |
| ExchangeRate-API | exchangerate-api.com | ফ্রি টায়ার আছে |

---

## 📱 WhatsApp Meta API সেটআপ (বিস্তারিত)

1. developers.facebook.com → My Apps → Create App
2. "Business" type বেছে নিন
3. WhatsApp → Add Product
4. Webhook URL: `https://yourdomain.com/webhook/whatsapp.php`
5. Verify Token: Admin Panel থেকে কপি করুন
6. Subscribe to: messages, message_deliveries

---

## 🤖 Telegram Bot সেটআপ

1. Telegram-এ @BotFather মেসেজ করুন
2. /newbot কমান্ড দিন
3. Bot নাম ও username দিন
4. Token কপি করুন
5. setup.php পেজে Token দিয়ে Webhook সেট করুন

---

## 💚 Google Drive API সেটআপ

1. console.cloud.google.com → New Project
2. Enable: Google Drive API
3. Create Service Account → JSON Key ডাউনলোড করুন
4. Admin Panel → সেটিংস-এ JSON-এর content পেস্ট করুন
5. Drive-এ একটি Root Folder তৈরি করুন
6. Folder ID কপি করে Admin Panel-এ দিন
7. Service Account email-কে Folder-এ Editor access দিন

---

## 🔧 সাধারণ সমস্যা ও সমাধান

**Webhook কাজ করছে না:**
- SSL Certificate ঠিক আছে কিনা দেখুন
- Token সঠিক কিনা দেখুন
- logs/bot.log ফাইল চেক করুন

**AI উত্তর দিচ্ছে না:**
- API Key সঠিক কিনা দেখুন
- Gemini API-এর Rate Limit শেষ হয়নি তো?
- OpenRouter Fallback চালু আছে কিনা দেখুন

**Google Drive আপলোড হচ্ছে না:**
- Service Account JSON সঠিক কিনা দেখুন
- Root Folder ID সঠিক কিনা দেখুন
- Service Account-এর Folder access আছে কিনা দেখুন

---

## 📞 Webhook URLs সারসংক্ষেপ

- Telegram: `https://yourdomain.com/webhook/telegram.php`
- WhatsApp: `https://yourdomain.com/webhook/whatsapp.php`
- Messenger: `https://yourdomain.com/webhook/messenger.php`
- Admin Panel: `https://yourdomain.com/admin/`
- Status Tracking: `https://yourdomain.com/track/`
- First Setup: `https://yourdomain.com/setup.php` *(পরে মুছুন)*

---

## ⚠️ নিরাপত্তা সতর্কতা

1. `setup.php` সেটআপের পরে অবশ্যই মুছুন
2. `config/` ফোল্ডার `public_html/`-এর বাইরে রাখুন
3. Admin Panel-এ শক্তিশালী পাসওয়ার্ড ব্যবহার করুন
4. `logs/` এবং `tmp/` ফোল্ডার publicly accessible রাখবেন না
