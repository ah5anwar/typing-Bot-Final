<?php
// ============================================================
// bot/BotFeatures.php
// প্রবাসীদের স্যালারি ও ওভারটাইম ক্যালকুলেটর + অন্যান্য ফিচার
// ============================================================

class SalaryCalculator {

    private Sender $sender;

    public function __construct(Sender $sender) {
        $this->sender = $sender;
    }

    // ── Entry point: keyword/callback থেকে শুরু ───────────────
    public function start(array $customer, string $platform, string $chatId): void {
        $lang = $customer['language'] ?? 'bn';
        $db   = Database::getInstance();
        $msg  = $lang === 'bn'
            ? "⏰ *স্যালারি ক্যালকুলেটর*\n\nআপনি এই মাসে মোট কত ঘণ্টা কাজ করেছেন?"
            : "⏰ *Salary Calculator*\n\nHow many total hours did you work this month?";
        $this->sender->send($platform, $chatId, $msg);
        $this->setState($customer['id'], 'ask_rate', $db);
    }

    // ── Step handler: bot_states = 'salary' থাকলে এখানে আসে ──
    public function handleStep(array $customer, string $text, string $platform, string $chatId, array $state): void {
        $this->handle($customer, $text, $platform, $chatId, $state);
    }

    public function handle(array $customer, string $text, string $platform, string $chatId, array $state): void {
        $sender = $this->sender;
        $lang   = $customer['language'] ?? 'bn';
        $db     = Database::getInstance();

        $step = $state['state_data']['salary_step'] ?? 'ask_rate';

        switch ($step) {
            case 'ask_rate':
                // গ্রাহকের ঘণ্টা ইনপুট নেওয়া (start() থেকে আসার পর প্রথম step)
                $hours = (float) preg_replace('/[^0-9.]/', '', $text);
                if ($hours <= 0) {
                    $sender->send($platform, $chatId, $lang === 'bn' ? "সঠিক সংখ্যা দিন। যেমন: 200" : "Please enter a valid number. E.g: 200");
                    return;
                }
                $this->setState($customer['id'], 'calculate', $db, ['hours' => $hours]);
                $msg = $lang === 'bn'
                    ? "💰 প্রতি ঘণ্টার বেতন কত? (যেকোনো কারেন্সিতে লিখুন)"
                    : "💰 What is your hourly rate? (any currency)";
                $sender->send($platform, $chatId, $msg);
                break;

            case 'calculate':
                $rate  = (float) preg_replace('/[^0-9.]/', '', $text);
                $hours = (float) ($state['state_data']['hours'] ?? 0);

                if ($rate <= 0) {
                    $sender->send($platform, $chatId, $lang === 'bn' ? "সঠিক সংখ্যা দিন। যেমন: 15.5" : "Please enter a valid number. E.g: 15.5");
                    return;
                }

                // হিসাব
                $regularHours   = min($hours, 192); // মাসে ৮ ঘণ্টা × ২৪ দিন = ১৯২
                $overtimeHours  = max(0, $hours - 192);
                $regularPay    = $regularHours * $rate;
                $overtimePay   = $overtimeHours * $rate * 1.5; // ওভারটাইমে ১.৫ গুণ
                $totalPay      = $regularPay + $overtimePay;

                $currency = $customer['country_id'] ? $this->getCurrency($customer['country_id']) : 'AED';

                $msg  = $lang === 'bn' ? "📊 *আপনার বেতনের হিসাব:*\n\n" : "📊 *Your Salary Breakdown:*\n\n";
                $msg .= "⏱️ মোট সময়: " . $hours . " ঘণ্টা\n";
                $msg .= "📌 নিয়মিত: " . $regularHours . " ঘণ্টা\n";
                if ($overtimeHours > 0) {
                    $msg .= "🔥 ওভারটাইম: " . $overtimeHours . " ঘণ্টা (×১.৫)\n";
                }
                $msg .= "─────────────────\n";
                $msg .= "💵 নিয়মিত বেতন: " . number_format($regularPay, 2) . "\n";
                if ($overtimeHours > 0) {
                    $msg .= "💵 ওভারটাইম বেতন: " . number_format($overtimePay, 2) . "\n";
                }
                $msg .= "─────────────────\n";
                $msg .= "✅ *মোট প্রাপ্য: " . number_format($totalPay, 2) . " " . $currency . "*";

                $sender->send($platform, $chatId, $msg);
                $this->clearState($customer['id'], $db);
                break;
        }
    }

    private function getCurrency(int $countryId): string {
        $country = Database::getInstance()->fetchOne("SELECT currency FROM countries WHERE id=?", [$countryId]);
        return $country['currency'] ?? 'AED';
    }

    private function setState(int $customerId, string $step, Database $db, array $extra = []): void {
        $data = json_encode(array_merge(['salary_step' => $step], $extra));
        $db->execute(
            "INSERT INTO bot_states (customer_id, state, state_data) VALUES (?, 'salary', ?)
             ON DUPLICATE KEY UPDATE state='salary', state_data=?, updated_at=NOW()",
            [$customerId, $data, $data]
        );
    }

    private function clearState(int $customerId, Database $db): void {
        $db->execute("UPDATE bot_states SET state='idle', state_data=NULL WHERE customer_id=?", [$customerId]);
    }
}


// ============================================================
// bot/ScamAlertHandler.php
// Admin থেকে স্ক্যাম অ্যালার্ট পাঠানো
// ============================================================

class ScamAlertHandler {

    public function sendAlert(string $message, array $countryIds = []): array {
        if (!Config::isEnabled('scam_alert_enabled')) return ['sent' => 0];

        $db     = Database::getInstance();
        $params = [];

        if (!empty($countryIds)) {
            $placeholders = implode(',', array_fill(0, count($countryIds), '?'));
            $customers    = $db->fetchAll(
                "SELECT * FROM customers WHERE onboarding_done=1 AND country_id IN ($placeholders)",
                $countryIds
            );
        } else {
            $customers = $db->fetchAll("SELECT * FROM customers WHERE onboarding_done=1");
        }

        require_once __DIR__ . '/../bot/Sender.php';
        $sender = new Sender();
        $sent   = 0;

        $alertMsg = "🚨 *সতর্কতা!*\n\n" . $message . "\n\n_— " . Config::get('company_name') . "_";

        foreach ($customers as $c) {
            try {
                $sender->send($c['platform'], $c['platform_id'], $alertMsg);
                $sent++;
                usleep(300000);
            } catch (Exception $e) {
                Logger::error("Scam alert send error: " . $e->getMessage());
            }
        }

        // DB-তে লগ
        $db->insert(
            "INSERT INTO scam_alerts (message, country_ids, sent_at) VALUES (?, ?, NOW())",
            [$message, implode(',', $countryIds)]
        );

        return ['sent' => $sent];
    }
}


// ============================================================
// bot/NewsHandler.php
// Multi-Country News Feed Handler
// ============================================================

class NewsHandler {

    public function broadcastNews(string $message, int $countryId): array {
        $db        = Database::getInstance();
        $customers = $db->fetchAll(
            "SELECT * FROM customers WHERE onboarding_done=1 AND country_id=?",
            [$countryId]
        );

        require_once __DIR__ . '/../bot/Sender.php';
        $sender  = new Sender();
        $country = $db->fetchOne("SELECT * FROM countries WHERE id=?", [$countryId]);
        $sent    = 0;

        $msg  = "🌍 *" . ($country['name_bn'] ?? 'দেশ') . " - নতুন আপডেট*\n\n";
        $msg .= $message;

        foreach ($customers as $c) {
            try {
                $sender->send($c['platform'], $c['platform_id'], $msg);
                $sent++;
                usleep(300000);
            } catch (Exception $e) {
                Logger::error("News broadcast error: " . $e->getMessage());
            }
        }

        return ['sent' => $sent, 'country' => $country['name_bn'] ?? ''];
    }
}
