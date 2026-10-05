<?php
// ============================================================
// services/DigitalLockerService.php — FIXED VERSION
// ============================================================

class DigitalLockerService {

    private Database $db;

    public function __construct() { $this->db = Database::getInstance(); }

    public function saveFile(array $customer, string $driveUrl, string $fileName, string $password): bool {
        if (!Config::isEnabled('digital_locker_enabled')) return false;
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $this->db->insert(
            "INSERT INTO digital_lockers (customer_id, file_name, drive_url, password_hash) VALUES (?,?,?,?)",
            [$customer['id'], $fileName, $driveUrl, $hash]
        );
        return true;
    }

    public function getFiles(array $customer, string $password): ?array {
        $files = $this->db->fetchAll(
            "SELECT * FROM digital_lockers WHERE customer_id=? ORDER BY created_at DESC",
            [$customer['id']]
        );
        if (empty($files)) return [];
        if (!password_verify($password, $files[0]['password_hash'])) return null;
        return $files;
    }

    public function handleBotFlow(array $customer, string $text, string $platform, string $chatId, array $state): void {
        require_once __DIR__ . '/../bot/Sender.php';
        $sender = new Sender();
        $lang   = $customer['language'] ?? 'bn';
        $step   = $state['state_data']['locker_step'] ?? 'menu';

        switch ($step) {
            case 'menu':
                $msg = $lang==='bn'
                    ? "🔒 *ডিজিটাল লকার*\n\nআপনার গুরুত্বপূর্ণ ডকুমেন্ট নিরাপদে সংরক্ষণ করুন।"
                    : "🔒 *Digital Locker*\n\nSecurely store your important documents.";
                $sender->send($platform, $chatId, $msg, [
                    ['text'=>'📥 ফাইল দেখুন','data'=>'locker_view'],
                    ['text'=>'📤 ফাইল যোগ করুন','data'=>'locker_add'],
                    ['text'=>'⬅️ মেনু','data'=>'main_menu'],
                ]);
                break;

            case 'ask_password_view':
            case 'ask_password_add':
                $action = str_replace('ask_password_', '', $step);
                $this->setState($customer['id'], "verify_password_{$action}");
                $sender->send($platform, $chatId, $lang==='bn' ? "🔑 আপনার লকার পাসওয়ার্ড লিখুন:" : "🔑 Enter your locker password:");
                break;

            case 'verify_password_view':
                $files = $this->getFiles($customer, $text);
                if ($files === null) {
                    $sender->send($platform, $chatId, $lang==='bn' ? "❌ ভুল পাসওয়ার্ড!" : "❌ Wrong password!");
                } elseif (empty($files)) {
                    $sender->send($platform, $chatId, $lang==='bn' ? "লকার খালি। ফাইল যোগ করুন।" : "Locker is empty.");
                } else {
                    $msg = $lang==='bn' ? "📁 *আপনার লকার ফাইলসমূহ:*\n\n" : "📁 *Your locker files:*\n\n";
                    foreach ($files as $i => $f) {
                        $msg .= ($i+1) . ". " . $f['file_name'] . "\n";
                        $msg .= "   🔗 " . $f['drive_url'] . "\n";
                        $msg .= "   📅 " . date('d/m/Y', strtotime($f['created_at'])) . "\n\n";
                    }
                    $sender->send($platform, $chatId, $msg);
                }
                $this->clearState($customer['id']);
                break;

            case 'verify_password_add':
                if (strlen($text) < 4) {
                    $sender->send($platform, $chatId, $lang==='bn' ? "পাসওয়ার্ড কমপক্ষে ৪ অক্ষরের হতে হবে।" : "Password must be at least 4 characters.");
                    return;
                }
                $this->setState($customer['id'], 'waiting_locker_file', ['pending_password'=>$text]);
                $sender->send($platform, $chatId, $lang==='bn'
                    ? "✅ পাসওয়ার্ড সেট হয়েছে! এখন যে ফাইলটি সেভ করতে চান সেটির ছবি বা ডকুমেন্ট পাঠান।"
                    : "✅ Password set! Now send the file/photo you want to save.");
                break;
        }
    }

    public function setState(int $cid, string $step, array $extra=[]): void {
        $data = json_encode(array_merge(['locker_step'=>$step], $extra), JSON_UNESCAPED_UNICODE);
        $this->db->execute(
            "INSERT INTO bot_states (customer_id,state,state_data) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE state=?,state_data=?,updated_at=NOW()",
            [$cid,'locker',$data,'locker',$data]
        );
    }

    public function clearState(int $cid): void {
        $this->db->execute("UPDATE bot_states SET state='idle',state_data=NULL WHERE customer_id=?", [$cid]);
    }
}
