<?php
// ============================================================
// bot/MenuHandler.php — reads menu from DB
// ============================================================

if (!class_exists('MenuHandler')):
class MenuHandler {
    private Database $db;
    private Sender   $sender;

    public function __construct(Sender $sender) {
        $this->db     = Database::getInstance();
        $this->sender = $sender;
    }

    public function showMainMenu(array $c, string $pl, string $chat): void {
        $lang = $c['language'] ?? 'bn';

        // DB থেকে মেনু আইটেম লোড
        $items = $this->db->fetchAll(
            "SELECT * FROM bot_menu_items WHERE is_active=1 ORDER BY order_no ASC"
        );

        // Fallback default menu
        if (empty($items)) {
            $items = [
                ['icon'=>'📋','label'=>'আমাদের সেবা',    'action'=>'show_categories'],
                ['icon'=>'🔍','label'=>'আবেদনের অবস্থা', 'action'=>'check_status'],
                ['icon'=>'💰','label'=>'পেমেন্ট হিসাব',  'action'=>'payment_info'],
                ['icon'=>'📁','label'=>'আমার ডকুমেন্ট', 'action'=>'doc_folder'],
                ['icon'=>'🆘','label'=>'জরুরি সাহায্য',  'action'=>'sos'],
            ];
        }

        // Menu title from settings
        $titleKey = $lang === 'bn' ? 'bot_menu_title_bn' : 'bot_menu_title_en';
        $title    = Config::get($titleKey, $lang === 'bn'
            ? "🏠 *প্রধান মেনু*\n\nআপনি কী করতে চান?"
            : "🏠 *Main Menu*\n\nWhat would you like to do?"
        );

        $buttons = array_map(fn($item) => [
            'text' => trim(($item['icon']??'') . ' ' . $item['label']),
            'data' => $item['action'],
        ], $items);

        $this->sender->send($pl, $chat, $title, $buttons);
    }

    public function showCategories(array $c, string $pl, string $chat): void {
        if (!Config::isEnabled('service_menu_enabled')) return;
        $cats = $this->db->fetchAll("SELECT * FROM service_categories WHERE is_active=1 ORDER BY id");
        if (empty($cats)) return;

        $lang = $c['language'] ?? 'bn';
        $msg  = $lang === 'bn' ? "✈️ আমাদের সেবার ক্যাটাগরি:" : "✈️ Our service categories:";
        $btns = array_map(fn($x) => ['text'=>$x['icon'].' '.$x['name'], 'data'=>'cat_'.$x['id']], $cats);
        $btns[] = ['text'=>'⬅️ মেনু', 'data'=>'main_menu'];
        $this->sender->send($pl, $chat, $msg, $btns);
    }

    public function showCategoryServices(array $c, string $pl, string $chat, int $catId): void {
        $svcs = $this->db->fetchAll("SELECT * FROM services WHERE category_id=? AND is_active=1", [$catId]);
        if (empty($svcs)) {
            $this->sender->send($pl, $chat, $c['language']==='bn' ? "এই ক্যাটাগরিতে কোনো সেবা নেই।" : "No services available.");
            return;
        }
        $msg = "📋 *সেবার তালিকা:*\n\n"; $btns = [];
        foreach ($svcs as $s) {
            $msg .= "• " . $s['name'];
            if ($s['price']>0) $msg .= " — " . number_format($s['price'],2) . " " . $s['currency'];
            $msg .= "\n";
            $btns[] = ['text'=>mb_substr($s['name'],0,20), 'data'=>'svc_'.$s['id']];
        }
        $btns[] = ['text'=>'⬅️ ক্যাটাগরি', 'data'=>'show_categories'];
        $this->sender->send($pl, $chat, $msg, $btns);
    }

    public function showServiceDetail(array $c, string $pl, string $chat, int $svcId): void {
        $s = $this->db->fetchOne(
            "SELECT s.*, cf.name_bn as cfrom, ct.name_bn as cfor FROM services s
             LEFT JOIN countries cf ON s.country_from_id=cf.id
             LEFT JOIN countries ct ON s.country_for_id=ct.id WHERE s.id=?", [$svcId]
        );
        if (!$s) return;

        $msg  = "✈️ *" . $s['name'] . "*\n\n";
        if ($s['description']) $msg .= "📄 " . $s['description'] . "\n\n";
        if ($s['price']>0)     $msg .= "💰 মূল্য: *" . number_format($s['price'],2) . " " . $s['currency'] . "*\n";
        if ($s['cfrom'])        $msg .= "🌍 দেশ: " . $s['cfrom'] . "\n";
        if ($s['cfor'])         $msg .= "👥 প্রযোজ্য: " . $s['cfor'] . "\n";
        if ($s['duration'])     $msg .= "⏱️ সময়: " . $s['duration'] . "\n";

        if ($s['docs_required']) {
            $msg .= "\n📎 *প্রয়োজনীয় কাগজপত্র:*\n";
            foreach (array_filter(explode("\n", $s['docs_required'])) as $d) $msg .= "• " . trim($d) . "\n";
        }

        $this->sender->send($pl, $chat, $msg, [
            ['text'=>'✅ এই সেবা নিতে চাই', 'data'=>'apply_'.$svcId],
            ['text'=>'⬅️ ফিরে যান',          'data'=>'cat_'.($s['category_id']??0)],
        ]);

        if ($gid = Config::get('admin_telegram_group')) {
            $this->sender->sendTelegramMessage($gid,
                "👁 সেবা দেখছেন: " . $s['name'] . "\n👤 " . ($c['name']??'?') . " | " . ($c['mobile']??'') . "\n📱 " . strtoupper($c['platform'])
            );
        }
    }
}
endif;
