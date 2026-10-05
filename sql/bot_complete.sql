-- ============================================================
-- bot_complete.sql — Multi-Platform Bot সম্পূর্ণ Database
-- এই একটি ফাইলই phpMyAdmin-এ Import করুন
-- Version: v11 | সব migration একসাথে
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;
SET character_set_client    = utf8mb4;
SET character_set_results   = utf8mb4;
SET character_set_connection= utf8mb4;
SET collation_connection = utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. COUNTRIES (দেশ)
-- ============================================================
CREATE TABLE IF NOT EXISTS `countries` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `name_bn`      VARCHAR(100) NOT NULL,
  `name_en`      VARCHAR(100) NOT NULL,
  `code`         VARCHAR(5)   NOT NULL,
  `currency`     VARCHAR(10)  DEFAULT 'USD',
  `phone_prefix` VARCHAR(10)  DEFAULT '',
  `flag_emoji`   VARCHAR(10)  DEFAULT '',
  `is_active`    TINYINT(1)   DEFAULT 1,
  `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `countries` (`name_bn`, `name_en`, `code`, `currency`, `phone_prefix`, `flag_emoji`) VALUES
('বাংলাদেশ',   'Bangladesh',   'BD', 'BDT', '+880', '🇧🇩'),
('দুবাই / UAE', 'UAE / Dubai',  'AE', 'AED', '+971', '🇦🇪'),
('ভারত',        'India',        'IN', 'INR', '+91',  '🇮🇳'),
('পাকিস্তান',   'Pakistan',     'PK', 'PKR', '+92',  '🇵🇰'),
('ওমান',         'Oman',         'OM', 'OMR', '+968', '🇴🇲'),
('সৌদি আরব',    'Saudi Arabia', 'SA', 'SAR', '+966', '🇸🇦'),
('বাহারাইন',    'Bahrain',      'BH', 'BHD', '+973', '🇧🇭'),
('কুয়েত',       'Kuwait',       'KW', 'KWD', '+965', '🇰🇼'),
('কাতার',       'Qatar',        'QA', 'QAR', '+974', '🇶🇦'),
('নেপাল',       'Nepal',        'NP', 'NPR', '+977', '🇳🇵'),
('শ্রীলঙ্কা',   'Sri Lanka',    'LK', 'LKR', '+94',  '🇱🇰'),
('মালয়েশিয়া', 'Malaysia',     'MY', 'MYR', '+60',  '🇲🇾'),
('সিঙ্গাপুর',  'Singapore',    'SG', 'SGD', '+65',  '🇸🇬');

-- ============================================================
-- 2. SETTINGS (সব কনফিগারেশন)
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `description`   VARCHAR(255),
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
-- Company
('company_name',    'আপনার কোম্পানির নাম',  'কোম্পানির নাম'),
('company_phone',   '',                       'কোম্পানির ফোন'),
('company_address', '',                       'কোম্পানির ঠিকানা'),
-- Bot
('bot_name',         'রিয়া - ভিসা কনসালট্যান্ট', 'বটের নাম'),
('welcome_message',  'আস্সালামু আলাইকুম! 👋\n\nআমি **রিয়া**, আপনার ভিসা কনসালট্যান্ট।\n\nসৌদি আরব, UAE, কাতার, মালয়েশিয়াসহ যেকোনো দেশের ভিসা নিয়ে সাহায্য করতে প্রস্তুত।\n\nনিচের বাটন থেকে বেছে নিন অথবা সরাসরি প্রশ্ন করুন। 😊', 'স্বাগত বার্তা'),
('system_prompt', 'তুমি [COMPANY_NAME]-এর একজন স্মার্ট ও বন্ধুসুলভ কাস্টমার সার্ভিস এক্সিকিউটিভ। তোমার নাম রিয়া। তোমার কাজ: faq ও services থেকে তথ্য প্রদান এবং গ্রাহকের ডেটা সংগ্রহ করা। বাস্তব মানুষের মতো কথা বলো।

LANGUAGE: গ্রাহকের ভাষা হুবহু অনুসরণ করো। সবসময় আপনি সম্বোধন করো।

GREETING: প্রথম মেসেজে একবারই সালাম ও পরিচয় দাও। পরে আর না। Known Customer Info-তে নাম থাকলে নাম ধরে ডাকো।

KNOWLEDGE BASE: শুধুমাত্র faq ও services থেকে উত্তর দাও। এর বাইরে কিছু বলবে না। সব সেবা জানতে চাইলে শুধু নামের লিস্ট দাও। নির্দিষ্ট সেবা বলার সময় প্রযোজ্য দেশ অবশ্যই উল্লেখ করো। কাগজপত্র লাইন বাই লাইন দাও। বিস্তারিত বলার পর জিজ্ঞেস করো সে নিতে চায় কিনা। একই নামে একাধিক সেবা থাকলে আগে জিজ্ঞেস করো কোনটি চায়।

CUSTOMER DATA: Known Customer Info চেক করো। নাম/মোবাইল/ঠিকানা না থাকলে ধাপে ধাপে সংগ্রহ করো একটি একটি করে। Onboarding Status COMPLETE হলে আর ব্যক্তিগত প্রশ্ন করো না। গ্রাহক প্রশ্ন করলে আগে উত্তর দাও তারপর মিসিং তথ্য জিজ্ঞেস করো।

MEMORY: Previous Conversation চেক করো। একই প্রশ্ন বা তথ্য বারবার দিও না।

SPECIAL CASES:
- ভিসা রিজেক্ট: সহানুভূতি দেখাও, কারণ জানতে চাও
- ফ্রি ভিসা: সতর্ক করো, প্রতারণার কথা বলো
- দাম জানতে চাইলে: দেশ জিজ্ঞেস করো
- রাগান্বিত: ক্ষমা চাও, সমস্যা জানতে চাও
- SOS: জরুরি ঘোষণা, [COMPANY_PHONE]-এ যোগাযোগ করতে বলো

NEVER: AI/Bot বলো না। জানি না বলো না। FAQ/Services-এর বাইরে তথ্য দিও না। ভিসা গ্যারান্টি দিও না। বারবার সালাম দিও না। একসাথে একাধিক প্রশ্ন করো না। ৩টির বেশি emoji ব্যবহার করো না।', 'AI System Prompt'),
-- AI
('gemini_api_key',        '',                      'Gemini API Key'),
('gemini_model',          'gemini-3.1-flash',      'Gemini মডেল'),
('openrouter_api_key',    '',                      'OpenRouter API Key'),
('openrouter_model',      'mistralai/mistral-7b-instruct', 'OpenRouter মডেল'),
('ai_provider',           'gemini',                'Primary AI'),
('ai_fallback_enabled',   '1',                     'AI Fallback'),
('ai_usage_tracking',     '1',                     'AI Usage Tracking'),
('current_ai_provider',   'gemini',                'বর্তমান AI'),
('faq_internet_answer',   '0',                     'FAQ না পেলে AI থেকে উত্তর'),
-- Platforms
('telegram_token',        '',                      'Telegram Bot Token'),
('whatsapp_token',        '',                      'WhatsApp Access Token'),
('whatsapp_phone_id',     '',                      'WhatsApp Phone Number ID'),
('whatsapp_app_secret',   '',                      'WhatsApp App Secret'),
('whatsapp_verify_token', 'my_verify_token_2025',  'WhatsApp Webhook Verify Token'),
('messenger_page_token',  '',                      'Messenger Page Token'),
('messenger_verify_token','my_messenger_token_2025','Messenger Verify Token'),
-- Admin
('admin_telegram_group',  '',                      'Admin Telegram Group ID'),
('admin_username',        'admin',                 'Admin লগইন নাম'),
('admin_password',        '',                      'Admin পাসওয়ার্ড (bcrypt)'),
-- Google Drive
('google_drive_folder_id',        '',              'Root Google Drive Folder ID'),
('google_service_account_json',   '',              'Google Service Account JSON'),
('google_drive_enabled',          '1',             'Google Drive চালু/বন্ধ'),
-- Features: ON
('multilanguage_enabled',         '1',  'মাল্টি-ল্যাঙ্গুয়েজ'),
('voice_enabled',                 '1',  'ভয়েস মেসেজ'),
('document_upload_enabled',       '1',  'ডকুমেন্ট আপলোড'),
('ocr_enabled',                   '1',  'OCR স্ক্যান'),
('fake_doc_detector_enabled',     '1',  'Fake Doc Detector'),
('digital_locker_enabled',        '1',  'Digital Locker'),
('qr_code_enabled',               '1',  'QR Code ID Card'),
('invoice_enabled',               '1',  'Invoice PDF'),
('reminder_enabled',              '1',  'মেয়াদ Reminder'),
('payment_reminder_enabled',      '1',  'পেমেন্ট Reminder'),
('payment_tracker_enabled',       '1',  'পেমেন্ট Tracker'),
('smart_notification_enabled',    '1',  'Smart Notification'),
('broadcast_enabled',             '1',  'Broadcast'),
('service_menu_enabled',          '1',  'Service Menu'),
('currency_alert_enabled',        '1',  'Currency Alert'),
('salary_calculator_enabled',     '1',  'Salary Calculator'),
('sos_enabled',                   '1',  'SOS হেল্পলাইন'),
('scam_alert_enabled',            '1',  'Scam Alert'),
('drive_link_enabled',            '1',  'Drive Link Share'),
('expiry_countdown_enabled',      '1',  'Expiry Countdown'),
('embassy_news_enabled',          '1',  'Embassy News'),
-- Features: OFF (default)
('onboarding_custom',             '0',  'Custom Onboarding'),
('office_hours_enabled',          '0',  'Office Hours Auto-reply'),
-- Office hours
('office_hours_start',  '09:00',                                          'অফিস শুরু'),
('office_hours_end',    '18:00',                                          'অফিস শেষ'),
('office_days',         '1,2,3,4,5,6',                                   'অফিসের দিন'),
('office_closed_msg',   'আমাদের অফিস এখন বন্ধ। অফিস সময়: সোম-শনি সকাল ৯টা-সন্ধ্যা ৬টা।', 'অফিস বন্ধ বার্তা'),
-- Currency
('currency_api_key',    '',                                                'ExchangeRate API Key'),
-- Bot Menu
('bot_menu_title_bn',   '🏠 *প্রধান মেনু*\n\nআপনি কী করতে চান?',         'মেনু শিরোনাম (বাংলা)'),
('bot_menu_title_en',   '🏠 *Main Menu*\n\nWhat would you like to do?',   'মেনু শিরোনাম (English)'),
-- Invoice
('invoice_template',    '',                                                'Custom HTML Invoice Template');

-- ============================================================
-- 3. AI MODELS
-- ============================================================
CREATE TABLE IF NOT EXISTS `ai_models` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `provider`   ENUM('gemini','openrouter') NOT NULL,
  `model_name` VARCHAR(100) NOT NULL,
  `api_key`    TEXT,
  `priority`   INT DEFAULT 1,
  `is_active`  TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `ai_models` (`provider`, `model_name`, `priority`) VALUES
('gemini',      'gemini-1.5-flash',                  1),
('gemini',      'gemini-1.5-pro',                    2),
('openrouter',  'mistralai/mistral-7b-instruct',      3),
('openrouter',  'meta-llama/llama-3-8b-instruct',    4);

-- ============================================================
-- 4. AGENTS (এজেন্ট / কনসালট্যান্ট)
-- ============================================================
CREATE TABLE IF NOT EXISTS `agents` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `name`             VARCHAR(100) NOT NULL,
  `mobile`           VARCHAR(20),
  `telegram_id`      VARCHAR(50),
  `whatsapp_number`  VARCHAR(20),
  `messenger_id`     VARCHAR(50),
  `qr_card_url`      VARCHAR(500),
  `photo_url`        VARCHAR(500) COMMENT 'প্রোফাইল ছবির URL',
  `is_active`        TINYINT(1) DEFAULT 1,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. ONBOARDING FIELDS
-- ============================================================
CREATE TABLE IF NOT EXISTS `onboarding_fields` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `field_key`      VARCHAR(50)  NOT NULL,
  `field_label_bn` VARCHAR(100) NOT NULL,
  `field_label_en` VARCHAR(100) NOT NULL,
  `field_type`     ENUM('text','number','select','date') DEFAULT 'text',
  `is_required`    TINYINT(1) DEFAULT 1,
  `is_active`      TINYINT(1) DEFAULT 1,
  `order_no`       INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `onboarding_fields` (`field_key`, `field_label_bn`, `field_label_en`, `is_required`, `order_no`) VALUES
('name',    'আপনার পূর্ণ নাম লিখুন:',     'Please enter your full name:',   1, 1),
('mobile',  'আপনার মোবাইল নম্বর লিখুন:', 'Enter your mobile number:',      1, 2),
('address', 'আপনার ঠিকানা লিখুন:',       'Enter your address:',            1, 3);

-- ============================================================
-- 6. CUSTOMERS (গ্রাহক)
-- ============================================================
CREATE TABLE IF NOT EXISTS `customers` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `platform`         ENUM('telegram','whatsapp','messenger') NOT NULL,
  `platform_id`      VARCHAR(100) NOT NULL,
  `name`             VARCHAR(150),
  `mobile`           VARCHAR(20),
  `address`          TEXT,
  `extra_data`       JSON,
  `language`         ENUM('bn','en') DEFAULT 'bn',
  `country_id`       INT,
  `onboarding_step`  INT DEFAULT 0,
  `onboarding_done`  TINYINT(1) DEFAULT 0,
  `qr_code`          VARCHAR(255),
  `member_card_url`  VARCHAR(500),
  `drive_folder_id`  VARCHAR(255),
  `drive_folder_url` VARCHAR(500),
  `profile_photo_url` VARCHAR(500) NULL,
  `last_message_at`  TIMESTAMP NULL,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `platform_uid` (`platform`, `platform_id`),
  FOREIGN KEY (`country_id`) REFERENCES `countries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. SERVICE CATEGORIES (সেবার ক্যাটাগরি)
-- ============================================================
CREATE TABLE IF NOT EXISTS `service_categories` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(100) NOT NULL,
  `icon`       VARCHAR(10) DEFAULT '📋',
  `is_active`  TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `service_categories` (`name`, `icon`) VALUES
('ভিসা সেবা',    '✈️'),
('পাসপোর্ট সেবা','📘'),
('মেডিকেল সেবা', '🏥'),
('অন্যান্য সেবা','📋');

-- ============================================================
-- 8. SERVICES (সেবা)
-- ============================================================
CREATE TABLE IF NOT EXISTS `services` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `category_id`     INT,
  `name`            VARCHAR(200) NOT NULL,
  `description`     TEXT,
  `price`           DECIMAL(10,2) DEFAULT 0,
  `currency`        VARCHAR(10)   DEFAULT 'BDT',
  `country_from_id` INT,
  `country_for_id`  INT,
  `docs_required`   TEXT,
  `duration`        VARCHAR(100),
  `is_active`       TINYINT(1) DEFAULT 1,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`)     REFERENCES `service_categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`country_from_id`) REFERENCES `countries`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`country_for_id`)  REFERENCES `countries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 9. APPLICATION STATUSES (আবেদনের অবস্থা)
-- ============================================================
CREATE TABLE IF NOT EXISTS `application_statuses` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `name`           VARCHAR(100) NOT NULL,
  `name_en`        VARCHAR(100),
  `color`          VARCHAR(7)   DEFAULT '#6c757d',
  `notify_message` TEXT,
  `order_no`       INT DEFAULT 0,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `application_statuses` (`name`, `name_en`, `color`, `notify_message`, `order_no`) VALUES
('পেন্ডিং',  'Pending',    '#ffc107', 'আপনার আবেদনটি পেয়েছি, প্রক্রিয়া শুরু হয়েছে। 📋', 1),
('প্রসেসিং', 'Processing', '#17a2b8', 'আপনার আবেদনটি প্রক্রিয়াধীন। শীঘ্রই আপডেট পাবেন। ⏳', 2),
('এম্বাসি',  'Embassy',    '#fd7e14', 'ফাইলটি এম্বাসিতে জমা দেওয়া হয়েছে। ৫-৭ দিন লাগবে। 🏛️', 3),
('মেডিকেল', 'Medical',    '#6f42c1', 'মেডিকেল প্রক্রিয়া চলছে। 🏥', 4),
('স্ট্যাম্পিং','Stamping',  '#20c997', 'ভিসা স্ট্যাম্পিং চলছে। ✅', 5),
('কমপ্লিট',  'Complete',   '#28a745', 'অভিনন্দন! 🎉 কাজ সম্পন্ন। অফিস থেকে সংগ্রহ করুন।', 6),
('বাতিল',    'Cancelled',  '#dc3545', 'দুঃখিত, আবেদন বাতিল হয়েছে। বিস্তারিত জানতে যোগাযোগ করুন। ❌', 7);

-- ============================================================
-- 10. APPLICATIONS (আবেদন)
-- ============================================================
CREATE TABLE IF NOT EXISTS `applications` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `tracking_id`         VARCHAR(20) NOT NULL UNIQUE,
  `customer_id`         INT NOT NULL,
  `service_id`          INT,
  `status_id`           INT DEFAULT 1,
  `notes`               TEXT,
  `expected_completion` DATE NULL,
  `admin_notes`         TEXT,
  `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`service_id`)  REFERENCES `services`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`status_id`)   REFERENCES `application_statuses`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 11. PAYMENTS (পেমেন্ট)
-- ============================================================
CREATE TABLE IF NOT EXISTS `payments` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id`    INT NOT NULL,
  `application_id` INT,
  `total_amount`   DECIMAL(10,2) DEFAULT 0,
  `paid_amount`    DECIMAL(10,2) DEFAULT 0,
  `balance`        DECIMAL(10,2) GENERATED ALWAYS AS (`total_amount` - `paid_amount`) STORED,
  `currency`       VARCHAR(10) DEFAULT 'BDT',
  `description`    TEXT,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`)    REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`application_id`) REFERENCES `applications`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payment_transactions` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `payment_id` INT NOT NULL,
  `amount`     DECIMAL(10,2) NOT NULL,
  `method`     VARCHAR(50) DEFAULT 'cash',
  `note`       VARCHAR(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 12. DOCUMENTS (ডকুমেন্ট + OCR + Fake Detection)
-- ============================================================
CREATE TABLE IF NOT EXISTS `documents` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id`        INT NOT NULL,
  `application_id`     INT,
  `platform`           ENUM('telegram','whatsapp','messenger'),
  `file_name`          VARCHAR(255),
  `drive_file_id`      VARCHAR(255),
  `drive_url`          VARCHAR(500),
  -- Document Type
  `doc_type`           VARCHAR(50)  DEFAULT 'general',
  -- OCR Fields
  `holder_name`        VARCHAR(200) NULL COMMENT 'ডকুমেন্টে যার নাম',
  `father_name`        VARCHAR(200) NULL,
  `mother_name`        VARCHAR(200) NULL,
  `date_of_birth`      DATE         NULL,
  `gender`             VARCHAR(10)  NULL,
  `nationality`        VARCHAR(100) NULL,
  `doc_number`         VARCHAR(100) NULL COMMENT 'পাসপোর্ট/ভিসা/NID নম্বর',
  `visa_type`          VARCHAR(100) NULL,
  `issue_date`         DATE         NULL,
  `issue_country`      VARCHAR(100) NULL,
  `destination_country` VARCHAR(100) NULL,
  `expiry_date`        DATE         NULL,
  `extracted_data`     JSON         COMMENT 'সম্পূর্ণ OCR JSON',
  `ocr_processed`      TINYINT(1)   DEFAULT 0,
  `ocr_confidence`     INT          DEFAULT NULL,
  -- Fake Detection Fields
  `is_suspicious`      TINYINT(1)   DEFAULT 0,
  `fake_check_done`    TINYINT(1)   DEFAULT 0,
  `fake_confidence`    INT          DEFAULT NULL,
  `fake_issues`        TEXT         DEFAULT NULL,
  `fake_recommendation` VARCHAR(20) DEFAULT NULL,
  `quality_score`      INT          DEFAULT NULL,
  `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`)    REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`application_id`) REFERENCES `applications`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 13. SUSPICIOUS DOCUMENTS (সন্দেহজনক ডকুমেন্ট)
-- ============================================================
CREATE TABLE IF NOT EXISTS `suspicious_documents` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id`      INT NOT NULL,
  `document_id`      INT NOT NULL,
  `confidence_score` INT DEFAULT 0,
  `issues`           TEXT,
  `recommendation`   VARCHAR(20) DEFAULT 'review',
  `admin_reviewed`   TINYINT(1)  DEFAULT 0,
  `admin_verdict`    ENUM('genuine','fake','unclear') DEFAULT NULL,
  `admin_note`       TEXT,
  `reviewed_at`      TIMESTAMP NULL,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 14. CHAT SESSIONS ও MESSAGES
-- ============================================================
CREATE TABLE IF NOT EXISTS `chat_sessions` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT NOT NULL,
  `platform`   ENUM('telegram','whatsapp','messenger') NOT NULL,
  `mode`       ENUM('bot','human') DEFAULT 'bot',
  `agent_id`   INT,
  `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`agent_id`)    REFERENCES `agents`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `messages` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id`  INT NOT NULL,
  `session_id`   INT,
  `platform`     ENUM('telegram','whatsapp','messenger') NOT NULL,
  `direction`    ENUM('in','out') NOT NULL,
  `message_type` ENUM('text','image','voice','document','button','location') DEFAULT 'text',
  `content`      TEXT,
  `raw_data`     JSON,
  `sent_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 15. FAQs (প্রশ্নোত্তর)
-- ============================================================
CREATE TABLE IF NOT EXISTS `faqs` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `question`   TEXT NOT NULL,
  `answer`     TEXT NOT NULL,
  `keywords`   VARCHAR(500),
  `is_active`  TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `faqs` (`question`, `answer`, `keywords`) VALUES
('ভিসার জন্য কি কি কাগজ লাগে?',
 'ভিসার ধরন অনুযায়ী কাগজ আলাদা। সাধারণত পাসপোর্ট, ছবি, ব্যাংক স্টেটমেন্ট এবং স্পনসর লেটার লাগে। নির্দিষ্ট ভিসার জন্য আমাদের সার্ভিস মেনু দেখুন।',
 'কাগজ,ডকুমেন্ট,documents,visa'),
('ভিসা পেতে কতদিন লাগে?',
 'সাধারণত ৭-১৫ কার্যদিবস। দেশ ও ভিসার ধরনের উপর নির্ভর করে।',
 'সময়,দিন,days,time'),
('সার্ভিস চার্জ কত?',
 'সার্ভিস চার্জ ভিসার ধরন ও দেশ অনুযায়ী আলাদা। "Menu" লিখে পাঠান বা আমাদের সাথে যোগাযোগ করুন।',
 'চার্জ,টাকা,price,fee'),
('আমার ফাইল কোথায় আছে?',
 'ট্র্যাকিং নম্বর বা মোবাইল নম্বর দিয়ে "status" লিখুন।',
 'ফাইল,status,track,ট্র্যাক'),
('পেমেন্ট কিভাবে করব?',
 'অফিসে সরাসরি নগদ, বিকাশ বা ব্যাংক ট্রান্সফারে পেমেন্ট করা যাবে।',
 'পেমেন্ট,টাকা,payment,bkash');

-- ============================================================
-- 16. REMINDERS (মেয়াদ ও পেমেন্ট রিমাইন্ডার)
-- ============================================================
CREATE TABLE IF NOT EXISTS `reminders` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id`   INT NOT NULL,
  `document_id`   INT,
  `reminder_type` ENUM('expiry_30','expiry_15','expiry_7','expiry_3','payment','custom') NOT NULL,
  `doc_type`      VARCHAR(50)  NULL,
  `doc_number`    VARCHAR(100) NULL,
  `message`       TEXT,
  `trigger_date`  DATE NOT NULL,
  `is_sent`       TINYINT(1) DEFAULT 0,
  `sent_at`       TIMESTAMP NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 17. BROADCASTS (ব্রডকাস্ট)
-- ============================================================
CREATE TABLE IF NOT EXISTS `broadcasts` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `title`             VARCHAR(255),
  `message`           TEXT NOT NULL,
  `target_type`       ENUM('all','country','platform') DEFAULT 'all',
  `target_country_id` INT,
  `target_platform`   ENUM('telegram','whatsapp','messenger','all') DEFAULT 'all',
  `sent_count`        INT DEFAULT 0,
  `failed_count`      INT DEFAULT 0,
  `status`            ENUM('draft','queued','sending','sent','failed') DEFAULT 'draft',
  `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `sent_at`           TIMESTAMP NULL,
  FOREIGN KEY (`target_country_id`) REFERENCES `countries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `broadcast_queue` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `broadcast_id` INT NOT NULL,
  `customer_id`  INT NOT NULL,
  `status`       ENUM('pending','sent','failed') DEFAULT 'pending',
  `sent_at`      TIMESTAMP NULL,
  `error`        VARCHAR(255),
  FOREIGN KEY (`broadcast_id`) REFERENCES `broadcasts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`)  REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 18. EMBASSY NEWS
-- ============================================================
CREATE TABLE IF NOT EXISTS `embassy_news` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `country_code` VARCHAR(5) NOT NULL,
  `country_name` VARCHAR(100),
  `source_url`   VARCHAR(500),
  `content`      TEXT,
  `status`       ENUM('pending','approved','sent','rejected') DEFAULT 'pending',
  `sent_count`   INT DEFAULT 0,
  `scraped_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `sent_at`      TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 19. INVOICE & LOCKER & SCAM
-- ============================================================
CREATE TABLE IF NOT EXISTS `invoice_logs` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT NOT NULL,
  `payment_id`  INT NOT NULL,
  `invoice_no`  VARCHAR(50),
  `invoice_url` VARCHAR(500),
  `sent_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `digital_lockers` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id`   INT NOT NULL,
  `file_name`     VARCHAR(255) NOT NULL,
  `drive_url`     VARCHAR(500),
  `password_hash` VARCHAR(255),
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `scam_alerts` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `message`     TEXT NOT NULL,
  `country_ids` VARCHAR(255),
  `sent_at`     TIMESTAMP NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `invoice_templates` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `name`         VARCHAR(100) NOT NULL,
  `html_template` LONGTEXT,
  `is_default`   TINYINT(1) DEFAULT 0,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 20. CURRENCY RATES
-- ============================================================
CREATE TABLE IF NOT EXISTS `currency_rates` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `from_currency` VARCHAR(10) NOT NULL,
  `to_currency`   VARCHAR(10) NOT NULL,
  `rate`          DECIMAL(15,6) NOT NULL,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `currency_pair` (`from_currency`, `to_currency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 21. SYSTEM TABLES
-- ============================================================
CREATE TABLE IF NOT EXISTS `bot_states` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT NOT NULL UNIQUE,
  `state`       VARCHAR(100) DEFAULT 'idle',
  `state_data`  JSON,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `platform_id` VARCHAR(100) NOT NULL,
  `minute_key`  VARCHAR(20)  NOT NULL,
  `count`       INT DEFAULT 1,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_rate` (`platform_id`, `minute_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `security_logs` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `type`        VARCHAR(50),
  `ip_address`  VARCHAR(45),
  `platform_id` VARCHAR(100),
  `details`     TEXT,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notification_logs` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT,
  `type`        VARCHAR(50),
  `message`     TEXT,
  `status`      ENUM('sent','failed') DEFAULT 'sent',
  `sent_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `qr_scan_logs` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT,
  `ip_address`  VARCHAR(45),
  `user_agent`  VARCHAR(255),
  `scanned_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `admin_sessions` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `session_token` VARCHAR(64) NOT NULL UNIQUE,
  `username`      VARCHAR(50),
  `ip_address`    VARCHAR(45),
  `expires_at`    TIMESTAMP,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 22. AI USAGE LOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `ai_usage_logs` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `provider`    VARCHAR(50)  NOT NULL DEFAULT 'gemini',
  `model_name`  VARCHAR(100),
  `customer_id` INT DEFAULT NULL,
  `tokens_used` INT DEFAULT 0,
  `success`     TINYINT(1)   DEFAULT 1,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 23. BOT MENU ITEMS (Admin থেকে কাস্টমাইজযোগ্য মেনু)
-- ============================================================
CREATE TABLE IF NOT EXISTS `bot_menu_items` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `label`      VARCHAR(100) NOT NULL,
  `action`     VARCHAR(100) NOT NULL,
  `icon`       VARCHAR(10)  DEFAULT '',
  `order_no`   INT          DEFAULT 0,
  `is_active`  TINYINT(1)   DEFAULT 1,
  `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `bot_menu_items` (`label`, `action`, `icon`, `order_no`) VALUES
('আমাদের সেবা',     'show_categories', '📋', 1),
('আবেদনের অবস্থা', 'check_status',    '🔍', 2),
('পেমেন্ট হিসাব',  'payment_info',    '💰', 3),
('আমার ডকুমেন্ট',  'doc_folder',      '📁', 4),
('Member Card',     'member_card',     '🪪', 5),
('কারেন্সি রেট',   'currency_rate',   '💱', 6),
('জরুরি সাহায্য',  'sos',             '🆘', 7);

-- ============================================================
-- 24. INDEXES (পারফরম্যান্সের জন্য)
-- ============================================================
CREATE INDEX IF NOT EXISTS `idx_customers_platform`    ON `customers`        (`platform`, `platform_id`);
CREATE INDEX IF NOT EXISTS `idx_customers_onboarding`  ON `customers`        (`onboarding_done`);
CREATE INDEX IF NOT EXISTS `idx_messages_customer`     ON `messages`         (`customer_id`, `sent_at`);
CREATE INDEX IF NOT EXISTS `idx_applications_tracking` ON `applications`     (`tracking_id`);
CREATE INDEX IF NOT EXISTS `idx_applications_customer` ON `applications`     (`customer_id`);
CREATE INDEX IF NOT EXISTS `idx_reminders_date`        ON `reminders`        (`trigger_date`, `is_sent`);
CREATE INDEX IF NOT EXISTS `idx_documents_customer`    ON `documents`        (`customer_id`);
CREATE INDEX IF NOT EXISTS `idx_docs_expiry`           ON `documents`        (`expiry_date`, `customer_id`);
CREATE INDEX IF NOT EXISTS `idx_docs_type`             ON `documents`        (`doc_type`, `customer_id`);
CREATE INDEX IF NOT EXISTS `idx_ai_provider_date`      ON `ai_usage_logs`    (`provider`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_suspicious_review`     ON `suspicious_documents` (`admin_reviewed`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_broadcast_status`      ON `broadcast_queue`  (`broadcast_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_embassy_country`       ON `embassy_news`     (`country_code`, `scraped_at`);
CREATE INDEX IF NOT EXISTS `idx_rate_limits`           ON `rate_limits`      (`platform_id`, `minute_key`);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- সম্পূর্ণ! phpMyAdmin-এ এই একটি ফাইলই Import করুন।
-- ============================================================
SELECT 'Bot database created successfully! 🎉' AS result;

-- ── ইতিমধ্যে ইন্সটল করা থাকলে এই line চালান ──
-- ALTER TABLE `agents` ADD COLUMN IF NOT EXISTS `photo_url` VARCHAR(500) NULL COMMENT 'প্রোফাইল ছবি URL';


-- ============================================================
-- ============================================================
-- v9: Extra columns & settings (safe to run multiple times)
-- ============================================================

-- customers: profile photo
ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `profile_photo_url` VARCHAR(500) NULL AFTER `drive_folder_url`;

-- agents: photo
ALTER TABLE `agents`
  ADD COLUMN IF NOT EXISTS `photo_url` VARCHAR(500) NULL COMMENT 'Profile photo URL';

-- New settings (safe IGNORE)
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('faq_service_only_mode', '0',            'শুধু FAQ+সেবা থেকে উত্তর (AI দিয়ে)'),
('agent_timeout_minutes', '5',            'Agent timeout minutes'),
('timezone',              'Asia/Dhaka',   'System timezone'),
('google_drive_enabled',  '1',            'Google Drive on/off'),
('embassy_news_enabled',  '1',            'Embassy News on/off');

-- ============================================================
-- Cron Jobs (cPanel → Cron Jobs, USER=your cPanel username)
-- * * * * *    php /home/USER/public_html/cron/agent_timeout.php
-- 0 9 * * *    php /home/USER/public_html/cron/reminder.php
-- 0 10 1 * *   php /home/USER/public_html/cron/payment_reminder.php
-- 0 8 * * *    php /home/USER/public_html/cron/currency_update.php
-- 0 6 * * 1    php /home/USER/public_html/cron/expiry_alert.php
-- */5 * * * *  php /home/USER/public_html/cron/broadcast_queue.php
-- 0 7 * * *    php /home/USER/public_html/cron/scrape_embassy_news.php
-- ============================================================

SET FOREIGN_KEY_CHECKS = 1;
-- ── Wrong model fix (gemini-3.1-flash does not exist!) ──────
UPDATE `settings` 
SET `setting_value` = 'gemini-2.0-flash'
WHERE `setting_key` = 'gemini_model' 
  AND (`setting_value` NOT LIKE 'gemini-%' 
       OR `setting_value` IN ('gemini-3.1-flash','gemini-3.0','gemini-3','gemini-ultra'));

-- Fix invalid gemini model name if set
UPDATE `settings` SET `setting_value` = 'gemini-2.0-flash' 
WHERE `setting_key` = 'gemini_model' 
  AND `setting_value` NOT REGEXP '^gemini-(1\\.5|2\\.0|2\\.5)';
