<?php
// bot/TrackingHandler.php
if (!class_exists('TrackingHandler')):
class TrackingHandler {
    private Database $db;
    private Sender   $sender;
    public function __construct(Sender $sender) { $this->db=Database::getInstance(); $this->sender=$sender; }

    public function process(array $c, string $input, string $pl, string $chat): void {
        $lang=($c['language']??'bn'); $lower=mb_strtolower(trim($input),'UTF-8');
        if (in_array($lower,['status','স্ট্যাটাস','track','ট্র্যাক'])) { $this->all($c,$pl,$chat,$lang); return; }
        $app=$this->find($input,$c['id']);
        if (!$app) { $this->sender->send($pl,$chat,$lang==='bn'?"❌ কোনো আবেদন পাওয়া যায়নি।\n\nট্র্যাকিং নম্বর বা মোবাইল নম্বর দিয়ে চেষ্টা করুন।":"❌ No application found."); return; }
        $this->status($app,$c,$pl,$chat,$lang);
    }

    private function find(string $in, int $cid): ?array {
        $b="SELECT a.*,s.name as service_name,st.name as status_name,st.color,st.order_no as status_order FROM applications a LEFT JOIN services s ON a.service_id=s.id LEFT JOIN application_statuses st ON a.status_id=st.id";
        return $this->db->fetchOne("{$b} WHERE a.tracking_id=?",[strtoupper($in)])
            ?? $this->db->fetchOne("{$b} WHERE a.customer_id=? ORDER BY a.created_at DESC LIMIT 1",[$cid])
            ?? $this->db->fetchOne("{$b} LEFT JOIN customers cu ON a.customer_id=cu.id WHERE cu.mobile=? ORDER BY a.created_at DESC LIMIT 1",[$in]);
    }

    private function all(array $c, string $pl, string $chat, string $lang): void {
        $apps=$this->db->fetchAll("SELECT a.*,s.name as service_name,st.name as status_name FROM applications a LEFT JOIN services s ON a.service_id=s.id LEFT JOIN application_statuses st ON a.status_id=st.id WHERE a.customer_id=? ORDER BY a.created_at DESC LIMIT 5",[$c['id']]);
        if (empty($apps)) { $this->sender->send($pl,$chat,$lang==='bn'?"আপনার কোনো আবেদন নেই।":"You have no applications."); return; }
        $msg=$lang==='bn'?"📋 *আপনার আবেদনসমূহ:*\n\n":"📋 *Your Applications:*\n\n";
        foreach ($apps as $a) { $msg.="🔹 ".($a['service_name']??'সেবা')."\n   🆔 ".$a['tracking_id']."\n   📌 ".($a['status_name']??'—')."\n   📅 ".date('d/m/Y',strtotime($a['created_at']))."\n\n"; }
        $this->sender->send($pl,$chat,$msg);
    }

    private function status(array $app, array $c, string $pl, string $chat, string $lang): void {
        $msg="🔍 *আবেদনের অবস্থা*\n\n📋 সেবা: ".($app['service_name']??'সেবা')."\n🆔 ট্র্যাকিং: *".$app['tracking_id']."*\n📌 অবস্থা: *".($app['status_name']??'—')."*\n📅 তারিখ: ".date('d/m/Y',strtotime($app['created_at']));
        if (Config::isEnabled('expiry_countdown_enabled')) {
            $doc=$this->db->fetchOne("SELECT expiry_date FROM documents WHERE customer_id=? AND expiry_date IS NOT NULL ORDER BY created_at DESC LIMIT 1",[$c['id']]);
            if ($doc && $doc['expiry_date']) {
                $days=(int)ceil((strtotime($doc['expiry_date'])-time())/86400);
                $msg.=$days>0?"\n⏰ ডকুমেন্ট মেয়াদ: আর *{$days} দিন* বাকি".($days<=30?"\n⚠️ দ্রুত রিনিউ করুন!":""):"\n❌ *ডকুমেন্টের মেয়াদ শেষ! দ্রুত রিনিউ করুন।*";
            }
        }
        $this->sender->send($pl,$chat,$msg);
    }
}
endif;
