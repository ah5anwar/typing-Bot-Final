<?php
// ============================================================
// services/NotificationEngine.php
// স্মার্ট নোটিফিকেশন ইঞ্জিন
// স্ট্যাটাস পরিবর্তন হলে স্বয়ংক্রিয় বার্তা পাঠানো
// ============================================================

class NotificationEngine {

    private Database $db;
    private Sender   $sender;

    public function __construct() {
        $this->db     = Database::getInstance();
        $this->sender = new Sender();
    }

    // ============================================================
    // আবেদনের স্ট্যাটাস পরিবর্তনে নোটিফিকেশন
    // ============================================================
    public function onStatusChange(int $applicationId, int $newStatusId): void {
        if (!Config::isEnabled('smart_notification_enabled')) return;

        $app = $this->db->fetchOne(
            "SELECT a.*, c.platform, c.platform_id, c.name, c.language,
                    st.name as status_name, st.notify_message, st.color,
                    s.name as service_name
             FROM applications a
             JOIN customers c ON a.customer_id = c.id
             JOIN application_statuses st ON st.id = ?
             LEFT JOIN services s ON a.service_id = s.id
             WHERE a.id = ?",
            [$newStatusId, $applicationId]
        );

        if (!$app || empty($app['notify_message'])) return;

        $lang = $app['language'] ?? 'bn';
        $msg  = "📊 *আপনার আবেদনের আপডেট!*\n\n";
        $msg .= "📋 সেবা: " . ($app['service_name'] ?? 'সেবা') . "\n";
        $msg .= "🆔 ট্র্যাকিং: *" . $app['tracking_id'] . "*\n";
        $msg .= "📌 নতুন অবস্থা: *" . $app['status_name'] . "*\n\n";
        $msg .= $app['notify_message'] . "\n\n";
        $msg .= "_স্ট্যাটাস জানতে লিখুন: status_";

        try {
            $this->sender->send($app['platform'], $app['platform_id'], $msg);
            Logger::info("Status notification sent for app: " . $app['tracking_id']);
        } catch (Exception $e) {
            Logger::error("Status notification error: " . $e->getMessage());
        }
    }

    // ============================================================
    // পেমেন্ট পাওয়ার পর নোটিফিকেশন
    // ============================================================
    public function onPaymentReceived(int $paymentId, float $amount): void {
        if (!Config::isEnabled('smart_notification_enabled')) return;

        $payment = $this->db->fetchOne(
            "SELECT p.*, c.platform, c.platform_id, c.name, c.language,
                    a.tracking_id, s.name as service_name
             FROM payments p
             JOIN customers c ON p.customer_id = c.id
             LEFT JOIN applications a ON p.application_id = a.id
             LEFT JOIN services s ON a.service_id = s.id
             WHERE p.id = ?",
            [$paymentId]
        );

        if (!$payment) return;

        $msg  = "✅ *পেমেন্ট গৃহীত হয়েছে!*\n\n";
        $msg .= "💰 জমার পরিমাণ: *" . number_format($amount, 2) . " " . $payment['currency'] . "*\n";
        if ($payment['tracking_id']) $msg .= "🆔 ট্র্যাকিং: " . $payment['tracking_id'] . "\n";
        $msg .= "📋 সেবা: " . ($payment['service_name'] ?? '—') . "\n";

        $balance = (float) $payment['balance'];
        if ($balance > 0) {
            $msg .= "⚠️ বকেয়া: " . number_format($balance, 2) . " " . $payment['currency'] . "\n\n";
            $msg .= "_\"হিসাব\" লিখে পূর্ণ Invoice দেখুন।_";
        } else {
            $msg .= "✅ সম্পূর্ণ পরিশোধ হয়েছে!\n\n";
            $msg .= "_\"হিসাব\" লিখে Invoice ডাউনলোড করুন।_";
        }

        try {
            $this->sender->send($payment['platform'], $payment['platform_id'], $msg);
        } catch (Exception $e) {
            Logger::error("Payment notification error: " . $e->getMessage());
        }
    }

    // ============================================================
    // নতুন সার্ভিস শুরু হওয়ার নোটিফিকেশন
    // ============================================================
    public function onApplicationCreated(int $applicationId): void {
        $app = $this->db->fetchOne(
            "SELECT a.*, c.platform, c.platform_id, c.name, c.language, s.name as service_name, s.docs_required
             FROM applications a
             JOIN customers c ON a.customer_id = c.id
             LEFT JOIN services s ON a.service_id = s.id
             WHERE a.id = ?",
            [$applicationId]
        );

        if (!$app) return;

        // Admin Telegram Group-এ নোটিফিকেশন
        $groupId = Config::get('admin_telegram_group');
        if ($groupId) {
            $adminMsg  = "🔔 *নতুন আবেদন জমা!*\n\n";
            $adminMsg .= "👤 গ্রাহক: " . ($app['name'] ?? 'অজানা') . "\n";
            $adminMsg .= "📱 Platform: " . strtoupper($app['platform']) . "\n";
            $adminMsg .= "🆔 ট্র্যাকিং: *" . $app['tracking_id'] . "*\n";
            $adminMsg .= "📋 সেবা: " . ($app['service_name'] ?? '—') . "\n";
            $adminMsg .= "🕐 সময়: " . date('d/m/Y H:i');

            $this->sender->sendTelegramMessage($groupId, $adminMsg);
        }
    }

    // ============================================================
    // ডকুমেন্ট আপলোডের নোটিফিকেশন (Admin-এ)
    // ============================================================
    public function onDocumentUploaded(int $customerId, string $fileName, array $ocrData = []): void {
        $groupId = Config::get('admin_telegram_group');
        if (!$groupId) return;

        $customer = $this->db->fetchOne("SELECT name, mobile, platform FROM customers WHERE id=?", [$customerId]);
        if (!$customer) return;

        $msg  = "📎 *নতুন ডকুমেন্ট আপলোড!*\n\n";
        $msg .= "👤 গ্রাহক: " . ($customer['name'] ?? 'অজানা') . "\n";
        $msg .= "📱 " . ($customer['mobile'] ?? '') . "\n";
        $msg .= "📄 ফাইল: " . $fileName . "\n";

        if (!empty($ocrData['doc_type'])) {
            $msg .= "🔍 ধরন: " . $ocrData['doc_type'] . "\n";
            if (!empty($ocrData['expiry_date'])) {
                $msg .= "⏰ মেয়াদ: " . $ocrData['expiry_date'];
            }
            if ($ocrData['is_suspicious'] ?? false) {
                $msg .= "\n🚨 *সন্দেহজনক ডকুমেন্ট!*";
            }
        }

        try {
            $this->sender->sendTelegramMessage($groupId, $msg);
        } catch (Exception $e) {
            Logger::error("Document notification error: " . $e->getMessage());
        }
    }
}
