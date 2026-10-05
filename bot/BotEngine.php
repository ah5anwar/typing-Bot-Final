<?php
// ============================================================
// bot/BotEngine.php — FIXES:
// 1. Platform থেকে নাম/মোবাইল/ছবি auto-fetch
// 2. Currency table empty হলে fallback rates দেখানো
// 3. Agent 5-min timeout → auto bot mode
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/Sender.php';
require_once __DIR__ . '/AIHandler.php';
require_once __DIR__ . '/FAQHandler.php';
require_once __DIR__ . '/Handlers.php'; // loads OnboardingHandler, MenuHandler, TrackingHandler
require_once __DIR__ . '/DocumentHandler.php';
require_once __DIR__ . '/BotFeatures.php';

class BotEngine {

    private Database $db;
    private Sender   $sender;

    public function __construct() {
        $this->db     = Database::getInstance();
        $this->sender = new Sender();
    }

    public function process(array $msgData): void {
        $platform = $msgData['platform'];
        $pid      = $msgData['platform_id'];
        $chatId   = $msgData['chat_id'];

        // ── Rate Limit ─────────────────────────────────────────
        if (!Security::checkRateLimit($pid)) return;

        // ── গ্রাহক খোঁজা বা তৈরি করা ──────────────────────────
        $customer = $this->db->fetchOne(
            "SELECT * FROM customers WHERE platform=? AND platform_id=?",
            [$platform, $pid]
        );

        if (!$customer) {
            // নতুন গ্রাহক — platform থেকে যা পাওয়া যায় তা সেভ
            $name     = $msgData['from_name'] ?? '';
            $mobile   = $msgData['from_mobile'] ?? '';  // WhatsApp থেকে আসে
            $photoUrl = $msgData['profile_photo'] ?? ''; // Telegram getUserProfilePhotos থেকে

            $id = $this->db->insert(
                "INSERT INTO customers (platform,platform_id,name,mobile,profile_photo_url,onboarding_step,onboarding_done) VALUES (?,?,?,?,?,0,0)",
                [$platform, $pid, $name, $mobile, $photoUrl]
            );
            $customer = $this->db->fetchOne("SELECT * FROM customers WHERE id=?", [$id]);

            // ভাষা নির্বাচন বা সরাসরি onboarding
            if (Config::isEnabled('multilanguage_enabled')) {
                $this->sender->sendLanguageSelection($platform, $chatId);
            } else {
                (new OnboardingHandler($this->sender))->process($customer, $msgData);
            }
            $this->logMessage($id, $platform, 'in', $msgData['type'], $msgData['text'] ?? '', []);
            return;
        }

        // ── Agent Timeout Check (5 মিনিট) ──────────────────────
        $this->checkAgentTimeout($customer['id']);

        // ── Session ────────────────────────────────────────────
        $session = $this->getOrCreateSession($customer['id'], $platform);
        $state   = $this->db->fetchOne("SELECT * FROM bot_states WHERE customer_id=?", [$customer['id']]) ?? ['state'=>'idle','state_data'=>null];

        $this->logMessage($customer['id'], $platform, 'in', $msgData['type'], $msgData['text'] ?? '', []);

        // ── Human mode → এজেন্টকে forward ────────────────────
        if ($session['mode'] === 'human') {
            $this->forwardToAgent($customer, $msgData, $session);
            return;
        }

        // ── ভাষা নির্বাচন (onboarding-এর আগে) ─────────────────
        $msgText = $msgData['text'] ?? '';
        if (in_array($msgText, ['lang_bn', 'lang_en'])) {
            $lang = $msgText === 'lang_bn' ? 'bn' : 'en';
            $this->db->execute("UPDATE customers SET language=? WHERE id=?", [$lang, $customer['id']]);
            $customer['language'] = $lang;
            $welcome = Config::get('welcome_message', 'আমাদের সেবায় স্বাগতম! 🌟');
            $this->sender->send($platform, $chatId, $welcome);
            $fields = $this->db->fetchAll("SELECT * FROM onboarding_fields WHERE is_active=1 ORDER BY order_no");
            $firstQ = !empty($fields)
                ? ($lang === 'bn' ? $fields[0]['field_label_bn'] : ($fields[0]['field_label_en'] ?? $fields[0]['field_label_bn']))
                : ($lang === 'bn' ? '📝 আপনার পূর্ণ নাম লিখুন:' : '📝 Enter your full name:');
            $this->sender->send($platform, $chatId, $firstQ);
            $this->db->execute("UPDATE customers SET onboarding_step=1 WHERE id=?", [$customer['id']]);
            return;
        }

        // ── Onboarding ─────────────────────────────────────────
        if (!$customer['onboarding_done']) {
            (new OnboardingHandler($this->sender))->process($customer, $msgData);
            return;
        }

        // ── Message type routing ────────────────────────────────
        switch ($msgData['type']) {
            case 'image':
            case 'document':
                require_once __DIR__ . '/../services/GoogleDriveService.php';
                (new DocumentHandler($this->sender))->handle($customer, $msgData, $state);
                break;

            case 'voice':
            case 'audio':
                $this->handleVoice($customer, $msgData, $session, $state);
                break;

            case 'callback':
                $this->handleCallback($customer, $msgData, $session, $state);
                break;

            default: // text
                $this->handleText($customer, $msgText, $msgData, $session, $state);
        }
    }

    // ── Agent Timeout (5 min) ──────────────────────────────────
    private function checkAgentTimeout(int $cid): void {
        $session = $this->db->fetchOne(
            "SELECT * FROM chat_sessions WHERE customer_id=? AND mode='human'",
            [$cid]
        );
        if (!$session) return;

        $lastMsg = $this->db->fetchOne(
            "SELECT sent_at FROM messages WHERE customer_id=? AND direction='out' ORDER BY sent_at DESC LIMIT 1",
            [$cid]
        );
        if (!$lastMsg) return;

        $lastTime = strtotime($lastMsg['sent_at']);
        $diff     = time() - $lastTime;

        if ($diff >= 300) { // 5 মিনিট = 300 সেকেন্ড
            $this->db->execute("UPDATE chat_sessions SET mode='bot' WHERE customer_id=?", [$cid]);
            $c = $this->db->fetchOne("SELECT * FROM customers WHERE id=?", [$cid]);
            if ($c) {
                $lang = $c['language'] ?? 'bn';
                $msg  = $lang === 'bn'
                    ? "⏰ এজেন্ট এখন ব্যস্ত আছেন। আমি (রিয়া) আবার আপনার সাথে আছি। কীভাবে সাহায্য করতে পারি?"
                    : "⏰ Agent is busy. I'm (Riya) back with you. How can I help?";
                $this->sender->send($c['platform'], $c['platform_id'], $msg);
            }
        }
    }

    // ── Text Handler ───────────────────────────────────────────
    private function handleText(array $customer, string $text, array $msgData, array $session, array $state): void {
        $platform = $msgData['platform'];
        $chatId   = $msgData['chat_id'];
        $lang     = $customer['language'] ?? 'bn';
        $lower    = mb_strtolower(trim($text), 'UTF-8');

        // Locker waiting
        $stateKey = $state['state'] ?? 'idle';
        if (str_starts_with($stateKey, 'locker') || $stateKey === 'waiting_locker_file') {
            $stateData = is_string($state['state_data']) ? json_decode($state['state_data'], true) : ($state['state_data'] ?? []);
            require_once __DIR__ . '/../services/DigitalLockerService.php';
            (new DigitalLockerService())->handleBotFlow($customer, $text, $platform, $chatId, ['state'=>$stateKey,'state_data'=>$stateData]);
            return;
        }

        // Salary calculator
        if ($stateKey === 'salary') {
            (new SalaryCalculator($this->sender))->handleStep($customer, $text, $platform, $chatId, $state);
            return;
        }

        // Quick commands
        $commands = [
            ['কীওয়ার্ড' => ['menu','মেনু','হোম','home','শুরু','start','/start'],
             'action'   => fn() => (new MenuHandler($this->sender))->showMainMenu($customer, $platform, $chatId)],
            ['কীওয়ার্ড' => ['status','স্ট্যাটাস','track','ট্র্যাক','আবেদন'],
             'action'   => fn() => (new TrackingHandler($this->sender))->process($customer, $text, $platform, $chatId)],
            ['কীওয়ার্ড' => ['হিসাব','invoice','পেমেন্ট','payment'],
             'action'   => fn() => $this->handlePaymentInfo($customer, $platform, $chatId)],
            ['কীওয়ার্ড' => ['কাগজ','document','ফাইল','drive'],
             'action'   => fn() => $this->sendDriveLink($customer, $platform, $chatId)],
            ['কীওয়ার্ড' => ['card','কার্ড','member'],
             'action'   => fn() => (new MemberCardHandler($this->sender))->handle($customer, $platform, $chatId)],
            ['কীওয়ার্ড' => ['locker','লকার'],
             'action'   => fn() => $this->startLockerFlow($customer, $platform, $chatId)],
            ['কীওয়ার্ড' => ['salary','বেতন','স্যালারি','ক্যালকুলেটর'],
             'action'   => fn() => (new SalaryCalculator($this->sender))->start($customer, $platform, $chatId)],
            ['কীওয়ার্ড' => ['rate','রেট','কারেন্সি','currency','exchange'],
             'action'   => fn() => $this->handleCurrencyRate($customer, $platform, $chatId)],
            ['কীওয়ার্ড' => ['sos','জরুরি','emergency','help','সাহায্য'],
             'action'   => fn() => $this->handleSOS($customer, $platform, $chatId, $session)],
            ['কীওয়ার্ড' => ['agent','এজেন্ট','human','মানুষ'],
             'action'   => fn() => $this->transferToAgent($customer, $platform, $chatId, $session)],
        ];

        foreach ($commands as $cmd) {
            foreach ($cmd['কীওয়ার্ড'] as $kw) {
                if ($lower === $kw || str_starts_with($lower, $kw . ' ')) {
                    ($cmd['action'])();
                    return;
                }
            }
        }

        // FAQ check
        $faqAnswer = (new FAQHandler())->findAnswer($text);
        if ($faqAnswer) {
            $this->sender->send($platform, $chatId, $faqAnswer);
            $this->logMessage($customer['id'], $platform, 'out', 'text', $faqAnswer, []);
            return;
        }

        // AI Response
        $ai       = new AIHandler();
        $response = $ai->getResponse($text, $customer);
        if ($response) {
            $this->sender->send($platform, $chatId, $response);
            $this->logMessage($customer['id'], $platform, 'out', 'text', $response, []);
            return;
        }

        // No answer
        $msg  = $lang === 'bn' ? "বুঝতে পারিনি, একটু বিস্তারিত বলুন। 🙏\n\n" : "Could you clarify? Or contact us:\n";
        $msg .= $this->getAgentContact();
        $this->sender->send($platform, $chatId, $msg);
    }

    // ── Callback Handler ───────────────────────────────────────
    private function handleCallback(array $customer, array $msgData, array $session, array $state): void {
        $platform = $msgData['platform'];
        $chatId   = $msgData['chat_id'];
        $data     = $msgData['text'] ?? '';
        $lang     = $customer['language'] ?? 'bn';
        $menu     = new MenuHandler($this->sender);

        $map = [
            'main_menu'       => fn() => $menu->showMainMenu($customer, $platform, $chatId),
            'show_categories' => fn() => $menu->showCategories($customer, $platform, $chatId),
            'check_status'    => fn() => (new TrackingHandler($this->sender))->process($customer, '', $platform, $chatId),
            'payment_info'    => fn() => $this->handlePaymentInfo($customer, $platform, $chatId),
            'doc_folder'      => fn() => $this->sendDriveLink($customer, $platform, $chatId),
            'member_card'     => fn() => (new MemberCardHandler($this->sender))->handle($customer, $platform, $chatId),
            'currency_rate'   => fn() => $this->handleCurrencyRate($customer, $platform, $chatId),
            'sos'             => fn() => $this->handleSOS($customer, $platform, $chatId, $session),
            'salary_start'    => fn() => (new SalaryCalculator($this->sender))->start($customer, $platform, $chatId),
            'locker_menu'     => fn() => $this->startLockerFlow($customer, $platform, $chatId),
            'locker_view'     => fn() => $this->lockerAskPassword($customer, $platform, $chatId, 'view'),
            'locker_add'      => fn() => $this->lockerAskPassword($customer, $platform, $chatId, 'add'),
        ];

        if (isset($map[$data])) { ($map[$data])(); return; }
        if (str_starts_with($data, 'cat_'))  { $menu->showCategoryServices($customer, $platform, $chatId, (int)substr($data, 4)); return; }
        if (str_starts_with($data, 'svc_'))  { $menu->showServiceDetail($customer, $platform, $chatId, (int)substr($data, 4)); return; }
        if (str_starts_with($data, 'apply_')){ $this->handleApply($customer, $platform, $chatId, (int)substr($data, 6)); return; }

        // Language selection (just in case)
        if (in_array($data, ['lang_bn', 'lang_en'])) {
            $lang = $data === 'lang_bn' ? 'bn' : 'en';
            $this->db->execute("UPDATE customers SET language=? WHERE id=?", [$lang, $customer['id']]);
            $customer['language'] = $lang;
            $welcome = Config::get('welcome_message', 'আমাদের সেবায় স্বাগতম!');
            $this->sender->send($platform, $chatId, $welcome);
            $fields = $this->db->fetchAll("SELECT * FROM onboarding_fields WHERE is_active=1 ORDER BY order_no");
            $q = !empty($fields)
                ? ($lang==='bn' ? $fields[0]['field_label_bn'] : ($fields[0]['field_label_en'] ?? $fields[0]['field_label_bn']))
                : ($lang==='bn' ? '📝 আপনার পূর্ণ নাম লিখুন:' : '📝 Enter your full name:');
            $this->sender->send($platform, $chatId, $q);
            $this->db->execute("UPDATE customers SET onboarding_step=1 WHERE id=?", [$customer['id']]);
        }
    }

    // ── Voice ──────────────────────────────────────────────────
    private function handleVoice(array $customer, array $msgData, array $session, array $state): void {
        if (!Config::isEnabled('voice_enabled')) return;
        $platform = $msgData['platform']; $chatId = $msgData['chat_id']; $lang = $customer['language'] ?? 'bn';
        if (!$msgData['file_id']) return;
        $url  = $this->sender->getFileUrl($msgData['file_id'], $platform);
        if (!$url) return;
        $ai   = new AIHandler();
        $text = $ai->voiceToText($url, $platform);
        if ($text) {
            $this->sender->send($platform, $chatId, "🎙️ _\"{$text}\"_");
            $fake = $msgData; $fake['type'] = 'text'; $fake['text'] = $text;
            $this->handleText($customer, $text, $fake, $session, $state);
        } else {
            $this->sender->send($platform, $chatId, $lang==='bn' ? "ভয়েস বুঝতে পারিনি। টাইপ করে লিখুন।" : "Couldn't understand voice. Please type.");
        }
    }

    // ── Currency Rate (with fallback) ──────────────────────────
    private function handleCurrencyRate(array $customer, string $platform, string $chatId): void {
        $rates = $this->db->fetchAll("SELECT * FROM currency_rates WHERE to_currency='BDT' ORDER BY from_currency LIMIT 15");

        if (empty($rates)) {
            // Fallback hardcoded rates
            $fallback = ['AED'=>30.5,'SAR'=>29.3,'OMR'=>286.0,'KWD'=>358.0,'BHD'=>294.0,
                         'QAR'=>30.2,'INR'=>1.32,'PKR'=>0.40,'MYR'=>24.5,'USD'=>110.0,'GBP'=>140.0,'EUR'=>119.0];
            $msg = "💱 *আজকের কারেন্সি রেট (আনুমানিক)*\n📅 " . date('d/m/Y') . "\n\n";
            foreach ($fallback as $cur => $rate) {
                $msg .= "1 {$cur} = " . number_format($rate, 2) . " BDT\n";
            }
            $msg .= "\n_⚠️ এটি আনুমানিক রেট। সঠিক রেটের জন্য ব্যাংকে যোগাযোগ করুন।_";
            $this->sender->send($platform, $chatId, $msg);

            // Background-এ DB-তে সেভ
            $this->saveFallbackRates($fallback);
            return;
        }

        $msg = "💱 *আজকের কারেন্সি রেট*\n📅 " . date('d/m/Y H:i') . "\n\n";
        foreach ($rates as $r) {
            $msg .= "1 {$r['from_currency']} = " . number_format((float)$r['rate'], 2) . " BDT\n";
        }
        $this->sender->send($platform, $chatId, $msg);
    }

    private function saveFallbackRates(array $rates): void {
        foreach ($rates as $cur => $rate) {
            $this->db->execute(
                "INSERT INTO currency_rates (from_currency,to_currency,rate) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE rate=?, updated_at=NOW()",
                [$cur,'BDT',$rate,$rate]
            );
        }
    }

    // ── Payment Info ───────────────────────────────────────────
    private function handlePaymentInfo(array $customer, string $platform, string $chatId): void {
        $lang = $customer['language'] ?? 'bn';
        $payments = $this->db->fetchAll(
            "SELECT p.*,a.tracking_id,s.name as sname FROM payments p
             LEFT JOIN applications a ON p.application_id=a.id
             LEFT JOIN services s ON a.service_id=s.id
             WHERE p.customer_id=? ORDER BY p.created_at DESC LIMIT 5",
            [$customer['id']]
        );

        if (empty($payments)) {
            $this->sender->send($platform, $chatId, $lang==='bn' ? "কোনো পেমেন্ট রেকর্ড নেই।" : "No payment records found.");
            return;
        }

        $msg = $lang === 'bn' ? "💰 *পেমেন্ট তথ্য:*\n\n" : "💰 *Payment Info:*\n\n";
        foreach ($payments as $p) {
            $bal = (float)$p['total_amount'] - (float)$p['paid_amount'];
            $msg .= "🔹 " . ($p['sname'] ?? 'সেবা') . "\n";
            $msg .= "   মোট: " . number_format((float)$p['total_amount'],2) . " {$p['currency']}\n";
            $msg .= "   জমা: " . number_format((float)$p['paid_amount'],2) . "\n";
            $msg .= "   " . ($bal>0 ? "⚠️ বকেয়া: ".number_format($bal,2) : "✅ সম্পূর্ণ পরিশোধ") . "\n\n";
        }

        if (Config::isEnabled('invoice_enabled')) {
            require_once __DIR__ . '/../services/PDFInvoiceService.php';
            $inv = new PDFInvoiceService();
            $app = $this->db->fetchOne("SELECT a.*,s.name as service_name,st.name as status_name FROM applications a LEFT JOIN services s ON a.service_id=s.id LEFT JOIN application_statuses st ON a.status_id=st.id WHERE a.customer_id=? ORDER BY a.created_at DESC LIMIT 1",[$customer['id']]);
            $url = $inv->generate($customer, $payments[0], $app ?? []);
            if ($url) $msg .= "📄 Invoice: {$url}";
        }

        $this->sender->send($platform, $chatId, $msg);
    }

    // ── Drive Link ─────────────────────────────────────────────
    private function sendDriveLink(array $customer, string $platform, string $chatId): void {
        $lang = $customer['language'] ?? 'bn';
        if (!empty($customer['drive_folder_url'])) {
            $msg = $lang === 'bn'
                ? "📁 *আপনার Google Drive ফোল্ডার:*\n{$customer['drive_folder_url']}\n\nএখানে আপনার সমস্ত ডকুমেন্ট সংরক্ষিত আছে।"
                : "📁 *Your Google Drive Folder:*\n{$customer['drive_folder_url']}\n\nAll your documents are stored here.";
        } else {
            $msg = $lang === 'bn'
                ? "📁 ডকুমেন্ট ফোল্ডার এখনো তৈরি হয়নি। একটি ডকুমেন্টের ছবি পাঠান।"
                : "📁 Your folder isn't created yet. Send a document photo to start.";
        }
        $this->sender->send($platform, $chatId, $msg);
    }

    // ── SOS ────────────────────────────────────────────────────
    private function handleSOS(array $customer, string $platform, string $chatId, array $session): void {
        if (!Config::isEnabled('sos_enabled')) return;
        $this->transferToAgent($customer, $platform, $chatId, $session);
        $lang = $customer['language'] ?? 'bn';
        $msg  = $lang === 'bn'
            ? "🆘 *জরুরি সাহায্য*\n\nআমরা আপনার বার্তা পেয়েছি। এজেন্ট শীঘ্রই যোগাযোগ করবে।\n\n"
            : "🆘 *Emergency Help*\n\nWe received your message. An agent will contact you shortly.\n\n";
        $msg .= $this->getAgentContact();
        $this->sender->send($platform, $chatId, $msg);
    }

    // ── Apply for service ──────────────────────────────────────
    private function handleApply(array $customer, string $platform, string $chatId, int $svcId): void {
        $svc  = $this->db->fetchOne("SELECT * FROM services WHERE id=?", [$svcId]);
        if (!$svc) return;
        $lang     = $customer['language'] ?? 'bn';
        $trackId  = 'TG' . date('Ymd') . str_pad($customer['id'], 4, '0', STR_PAD_LEFT) . rand(10,99);
        $appId    = $this->db->insert(
            "INSERT INTO applications(tracking_id,customer_id,service_id,status_id) VALUES(?,?,?,1)",
            [$trackId, $customer['id'], $svcId]
        );
        if ($svc['price'] > 0) {
            $this->db->insert(
                "INSERT INTO payments(customer_id,application_id,total_amount,paid_amount,currency) VALUES(?,?,?,0,?)",
                [$customer['id'], $appId, $svc['price'], $svc['currency']]
            );
        }
        $msg  = $lang === 'bn' ? "✅ *আবেদন গ্রহণ হয়েছে!*\n\n" : "✅ *Application Submitted!*\n\n";
        $msg .= "🔢 ট্র্যাকিং: *{$trackId}*\n";
        $msg .= "📋 সেবা: {$svc['name']}\n";
        if ($svc['price'] > 0) $msg .= "💰 মূল্য: " . number_format($svc['price'],2) . " {$svc['currency']}\n";
        $msg .= $lang === 'bn' ? "\n\"status\" লিখে অবস্থা জানুন।" : "\nType \"status\" to track your application.";
        $this->sender->send($platform, $chatId, $msg);
        if ($gid = Config::get('admin_telegram_group')) {
            $this->sender->sendTelegramMessage($gid,
                "🆕 নতুন আবেদন!\n👤 ".($customer['name']??'?')."\n📋 {$svc['name']}\n🔢 {$trackId}"
            );
        }
    }

    // ── Transfer to Agent ──────────────────────────────────────
    private function transferToAgent(array $customer, string $platform, string $chatId, array $session): void {
        $this->db->execute("UPDATE chat_sessions SET mode='human', updated_at=NOW() WHERE id=?", [$session['id']]);
        if ($gid = Config::get('admin_telegram_group')) {
            $this->sender->sendTelegramMessage($gid,
                "🔔 *এজেন্ট দরকার!*\n👤 ".($customer['name']??'?')."\n📱 ".($customer['mobile']??'')."\nPlatform: ".strtoupper($platform)."\nID: ".$customer['platform_id']
            );
        }
    }

    // ── Locker helpers ─────────────────────────────────────────
    private function startLockerFlow(array $c, string $pl, string $chat): void {
        require_once __DIR__ . '/../services/DigitalLockerService.php';
        $state = $this->db->fetchOne("SELECT * FROM bot_states WHERE customer_id=?", [$c['id']]) ?? [];
        (new DigitalLockerService())->handleBotFlow($c, '', $pl, $chat, ['state'=>'locker_menu','state_data'=>[]]);
    }
    private function lockerAskPassword(array $c, string $pl, string $chat, string $action): void {
        require_once __DIR__ . '/../services/DigitalLockerService.php';
        (new DigitalLockerService())->handleBotFlow($c, '', $pl, $chat, ['state'=>"ask_password_{$action}",'state_data'=>[]]);
    }

    // ── Forward to Agent ───────────────────────────────────────
    private function forwardToAgent(array $customer, array $msgData, array $session): void {
        $gid  = Config::get('admin_telegram_group');
        $text = $msgData['text'] ?? ($msgData['type'] !== 'text' ? "[{$msgData['type']}]" : '');
        if ($gid && $text) {
            $this->sender->sendTelegramMessage($gid,
                "💬 *" . ($customer['name']??'?') . "*:\n{$text}"
            );
        }
    }

    // ── Agent Contact ──────────────────────────────────────────
    private function getAgentContact(): string {
        $agents = $this->db->fetchAll("SELECT name, mobile, whatsapp_number FROM agents WHERE is_active=1 LIMIT 3");
        if (empty($agents)) return "📞 " . Config::get('company_phone', '');
        $lines = [];
        foreach ($agents as $a) {
            $n = "• {$a['name']}:";
            if ($a['whatsapp_number']) $n .= " " . $a['whatsapp_number'];
            elseif ($a['mobile'])      $n .= " " . $a['mobile'];
            $lines[] = $n;
        }
        return implode("\n", $lines);
    }

    // ── Session ────────────────────────────────────────────────
    private function getOrCreateSession(int $cid, string $platform): array {
        $s = $this->db->fetchOne("SELECT * FROM chat_sessions WHERE customer_id=? AND platform=? ORDER BY id DESC LIMIT 1", [$cid, $platform]);
        if (!$s) {
            $id = $this->db->insert("INSERT INTO chat_sessions (customer_id,platform,mode) VALUES (?,?,'bot')", [$cid, $platform]);
            $s  = $this->db->fetchOne("SELECT * FROM chat_sessions WHERE id=?", [$id]);
        }
        return $s ?? ['id'=>0,'mode'=>'bot'];
    }

    // ── Log Message ────────────────────────────────────────────
    private function logMessage(int $cid, string $platform, string $dir, string $type, string $content, array $raw): void {
        try {
            $this->db->execute(
                "INSERT INTO messages (customer_id,platform,direction,message_type,content) VALUES (?,?,?,?,?)",
                [$cid, $platform, $dir, $type, mb_substr($content, 0, 1000, 'UTF-8')]
            );
            $this->db->execute("UPDATE customers SET last_message_at=NOW() WHERE id=?", [$cid]);
        } catch (Exception $e) {}
    }
}
