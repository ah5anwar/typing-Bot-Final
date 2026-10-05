<?php
// bot/OnboardingHandler.php
if (!class_exists('OnboardingHandler')):
class OnboardingHandler {
    private Database $db;
    private Sender   $sender;
    public function __construct(Sender $sender) { $this->db=Database::getInstance(); $this->sender=$sender; }

    public function process(array $customer, array $msgData): void {
        $platform=$msgData['platform']; $chatId=$msgData['chat_id'];
        $text=trim($msgData['text']??''); $lang=$customer['language']??'bn'; $step=(int)$customer['onboarding_step'];
        if (in_array($text,['lang_bn','lang_en'])) return;

        $fields = Config::isEnabled('onboarding_custom')
            ? $this->db->fetchAll("SELECT * FROM onboarding_fields WHERE is_active=1 ORDER BY order_no")
            : [
                ['field_key'=>'name',   'field_label_bn'=>'📝 আপনার পূর্ণ নাম লিখুন:',     'field_label_en'=>'📝 Enter your full name:'],
                ['field_key'=>'mobile', 'field_label_bn'=>'📱 মোবাইল নম্বর লিখুন:',         'field_label_en'=>'📱 Enter your mobile number:'],
                ['field_key'=>'address','field_label_bn'=>'🏠 আপনার ঠিকানা লিখুন:',          'field_label_en'=>'🏠 Enter your address:'],
              ];

        if ($step===0) {
            $this->sender->send($platform,$chatId, Config::get('welcome_message','আমাদের সেবায় আপনাকে স্বাগতম! 🌟'));
            if (!empty($fields)) {
                $this->sender->send($platform,$chatId, $lang==='bn' ? $fields[0]['field_label_bn'] : ($fields[0]['field_label_en']??$fields[0]['field_label_bn']));
            }
            $this->db->execute("UPDATE customers SET onboarding_step=1 WHERE id=?",[$customer['id']]);
            return;
        }

        $fi = $step-1;
        if (isset($fields[$fi]) && $text!=='') {
            $key=$fields[$fi]['field_key'];
            if (in_array($key,['name','mobile','address'])) {
                $this->db->execute("UPDATE customers SET `{$key}`=? WHERE id=?",[$text,$customer['id']]);
            } else {
                $row=$this->db->fetchOne("SELECT extra_data FROM customers WHERE id=?",[$customer['id']]);
                $extra=json_decode($row['extra_data']??'{}',true)?:[];
                $extra[$key]=$text;
                $this->db->execute("UPDATE customers SET extra_data=? WHERE id=?",[json_encode($extra,JSON_UNESCAPED_UNICODE),$customer['id']]);
            }
        }

        $next=$step+1; $nextField=$fields[$next-1]??null;
        if ($nextField) {
            $this->sender->send($platform,$chatId, $lang==='bn' ? $nextField['field_label_bn'] : ($nextField['field_label_en']??$nextField['field_label_bn']));
            $this->db->execute("UPDATE customers SET onboarding_step=? WHERE id=?",[$next,$customer['id']]);
        } else {
            $this->db->execute("UPDATE customers SET onboarding_done=1 WHERE id=?",[$customer['id']]);
            $this->sender->send($platform,$chatId, $lang==='bn' ? "✅ ধন্যবাদ! তথ্য জমা হয়েছে।\n\n\"Menu\" লিখুন আমাদের সেবা দেখতে। 😊" : "✅ Thank you! Info saved.\n\nType \"Menu\" to see our services. 😊");
            $this->setupBackground($customer['id']);
        }
    }

    private function setupBackground(int $cid): void {
        try {
            if (Config::isEnabled('document_upload_enabled')) {
                require_once __DIR__ . '/../services/GoogleDriveService.php';
                $c=(new GoogleDriveService())->createCustomerFolder($this->db->fetchOne("SELECT * FROM customers WHERE id=?",[$cid])??[]);
            }
        } catch(Exception $e) { Logger::error('Drive folder: '.$e->getMessage()); }
        try {
            if (Config::isEnabled('qr_code_enabled')) {
                require_once __DIR__.'/../services/QRCodeService.php';
                require_once __DIR__.'/../vendor/phpqrcode/qrlib.php';
                (new QRCodeService())->generateForCustomer($cid);
            }
        } catch(Exception $e) { Logger::error('QR: '.$e->getMessage()); }
    }
}
endif;
