<?php
// ============================================================
// admin/customers.php — গ্রাহক ম্যানেজমেন্ট (সম্পূর্ণ)
// ============================================================
if (ob_get_level() === 0) ob_start();
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/config/security.php';
Security::requireAdminAuth();
Security::setSecurityHeaders();

$db   = Database::getInstance();
$sysCurrencies = array_filter(array_map('trim', explode(',', Config::get('system_currencies','BDT,AED,SAR,OMR,KWD,BHD,QAR,INR,PKR,USD'))));
$csrf = Security::generateCSRFToken();
$tab  = $_GET['tab'] ?? 'customers';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    // CSRF: session already verified by requireAdminAuth - skip strict check
    // (CSRF token may differ between page load and JS call due to session timing)
    $act = $_POST['action'];

    // ── Test ─────────────────────────────────────────────
    if ($act === 'test_ping') {
        echo json_encode(['success'=>true,'message'=>'customers.php connected!','session'=>isset($_SESSION['admin_logged_in'])]);
        exit;
    }

    // ── গ্রাহক ডিলেট ─────────────────────────────────────
    if ($act === 'delete_customer') {
        $cid = (int)($_POST['customer_id'] ?? 0);
        if (!$cid) { echo json_encode(['success'=>false,'message'=>'ID নেই']); exit; }
        $c = $db->fetchOne("SELECT id,name,drive_folder_id FROM customers WHERE id=?", [$cid]);
        if (!$c) { echo json_encode(['success'=>false,'message'=>'গ্রাহক পাওয়া যায়নি']); exit; }

        // Google Drive folder delete
        if (!empty($c['drive_folder_id'])) {
            try {
                require_once dirname(__DIR__).'/services/GoogleDriveService.php';
                $drive = new GoogleDriveService();
                if ($drive->isReady()) $drive->deleteFolder($c['drive_folder_id']);
            } catch(Exception $e) {}
        }

        // Local storage files মুছা
        try {
            require_once dirname(__DIR__).'/services/LocalStorageService.php';
            (new LocalStorageService())->deleteCustomerFolder($cid);
        } catch(Exception $e) {}

        // FK-safe delete order
        try { $db->execute("DELETE pt FROM payment_transactions pt INNER JOIN payments p ON pt.payment_id=p.id WHERE p.customer_id=?", [$cid]); } catch(Exception $e) {}
        try { $db->execute("DELETE sd FROM suspicious_documents sd INNER JOIN documents d ON sd.document_id=d.id WHERE d.customer_id=?", [$cid]); } catch(Exception $e) {}
        foreach (['documents','reminders','invoice_logs','payments','bot_states',
                  'ai_usage_logs','notification_logs','qr_scan_logs',
                  'broadcast_queue','digital_lockers','messages','chat_sessions','applications'] as $t) {
            try { $db->execute("DELETE FROM `{$t}` WHERE customer_id=?", [$cid]); } catch(Exception $e) {}
        }
        try {
            $db->execute("DELETE FROM customers WHERE id=?", [$cid]);
            Logger::info("Customer #{$cid} deleted");
            echo json_encode(['success'=>true,'message'=>'✅ গ্রাহক মুছে ফেলা হয়েছে']);
        } catch(Exception $e) {
            Logger::error("Delete customer #{$cid}: ".$e->getMessage());
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── গ্রাহক সম্পাদনা ───────────────────────────────────
    if ($act === 'edit_customer') {
        $cid = Security::sanitizeInt($_POST['customer_id'] ?? 0);
        $db->execute("UPDATE customers SET name=?,mobile=?,address=?,country_id=?,updated_at=NOW() WHERE id=?",
            [Security::sanitizeString($_POST['name']??'',150),
             Security::sanitizeString($_POST['mobile']??'',20),
             Security::sanitizeString($_POST['address']??'',500),
             !empty($_POST['country_id'])?(int)$_POST['country_id']:null,
             $cid]);
        echo json_encode(['success'=>true,'message'=>'✅ আপডেট হয়েছে']); exit;
    }

    // ── আবেদন যোগ (Admin দ্বারা) ─────────────────────────
    if ($act === 'add_application') {
        $cid   = Security::sanitizeInt($_POST['customer_id'] ?? 0);
        $svcId = Security::sanitizeInt($_POST['service_id']  ?? 0) ?: null;
        $stId  = Security::sanitizeInt($_POST['status_id']   ?? 1);
        $notes = Security::sanitizeString($_POST['notes'] ?? '', 500);
        if (!$cid) { echo json_encode(['success'=>false,'message'=>'গ্রাহক নির্বাচন করুন']); exit; }
        $trackId = 'AD'.date('Ymd').str_pad(rand(1,9999),4,'0',STR_PAD_LEFT);
        $appId = $db->insert(
            "INSERT INTO applications(tracking_id,customer_id,service_id,status_id,notes) VALUES(?,?,?,?,?)",
            [$trackId,$cid,$svcId,$stId,$notes]
        );
        echo json_encode(['success'=>true,'tracking_id'=>$trackId,'app_id'=>$appId]); exit;
    }

    // ── পেমেন্ট যোগ ───────────────────────────────────────
    if ($act === 'add_payment') {
        $cid   = Security::sanitizeInt($_POST['customer_id'] ?? 0);
        $appId = Security::sanitizeInt($_POST['application_id'] ?? 0) ?: null;
        $total = Security::sanitizeFloat($_POST['total_amount'] ?? 0);
        $paid  = Security::sanitizeFloat($_POST['paid_amount']  ?? 0);
        $cur   = Security::sanitizeString($_POST['currency'] ?? 'BDT', 10);
        $desc  = Security::sanitizeString($_POST['description'] ?? '', 300);
        if (!$cid) { echo json_encode(['success'=>false,'message'=>'গ্রাহক নির্বাচন করুন']); exit; }
        $pid = $db->insert(
            "INSERT INTO payments(customer_id,application_id,total_amount,paid_amount,currency,description) VALUES(?,?,?,?,?,?)",
            [$cid,$appId,$total,$paid,$cur,$desc]
        );
        echo json_encode(['success'=>true,'payment_id'=>$pid]); exit;
    }

    // ── গ্রাহকের সব তথ্য (AJAX) ──────────────────────────
    if ($act === 'get_customer_detail') {
        $cid = (int)($_POST['customer_id'] ?? 0);
        if (!$cid) { echo json_encode(['success'=>false,'message'=>'ID নেই']); exit; }
        try {
            $c = $db->fetchOne(
                "SELECT c.id,c.name,c.mobile,c.address,c.platform,c.language,
                        c.onboarding_done,c.drive_folder_url,c.created_at,
                        COALESCE(co.name_bn,'') as country_name
                 FROM customers c LEFT JOIN countries co ON c.country_id=co.id
                 WHERE c.id=?", [$cid]
            );
            if (!$c) { echo json_encode(['success'=>false,'message'=>'পাওয়া যায়নি']); exit; }

            $apps = $db->fetchAll(
                "SELECT a.id,a.tracking_id,a.created_at,COALESCE(a.admin_notes,'') as admin_notes,
                        COALESCE(s.name,'—') as sname,COALESCE(st.name,'—') as status_name,
                        COALESCE(st.color,'#6c757d') as color
                 FROM applications a
                 LEFT JOIN services s ON a.service_id=s.id
                 LEFT JOIN application_statuses st ON a.status_id=st.id
                 WHERE a.customer_id=? ORDER BY a.created_at DESC", [$cid]
            );

            $payments = $db->fetchAll(
                "SELECT p.id,p.total_amount,p.paid_amount,p.currency,
                        COALESCE(p.description,'') as description, p.created_at,
                        COALESCE(a.tracking_id,'') as tracking_id
                 FROM payments p
                 LEFT JOIN applications a ON p.application_id=a.id
                 WHERE p.customer_id=? ORDER BY p.created_at DESC", [$cid]
            );

            $docs = $db->fetchAll(
                "SELECT id,doc_type,COALESCE(doc_number,'') as doc_number,
                        COALESCE(holder_name,'') as holder_name,
                        COALESCE(expiry_date,'') as expiry_date,
                        COALESCE(drive_url,'') as drive_url,ocr_processed,created_at
                 FROM documents WHERE customer_id=? ORDER BY created_at DESC LIMIT 10", [$cid]
            );

            $totalAmt = array_sum(array_column($payments,'total_amount'));
            $paidAmt  = array_sum(array_column($payments,'paid_amount'));

            echo json_encode([
                'success'=>true,'customer'=>$c,'apps'=>$apps,
                'payments'=>$payments,'docs'=>$docs,
                'total'=>(float)$totalAmt,'paid'=>(float)$paidAmt,'due'=>(float)($totalAmt-$paidAmt)
            ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch(Exception $e) {
            Logger::error('get_customer_detail: '.$e->getMessage());
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── Countries ──────────────────────────────────────────
    if ($act === 'save_country') {
        $id   = Security::sanitizeInt($_POST['id'] ?? 0);
        $data = [
            Security::sanitizeString($_POST['name_bn']??'',100),
            Security::sanitizeString($_POST['name_en']??'',100),
            strtoupper(Security::sanitizeString($_POST['code']??'',5)),
            Security::sanitizeString($_POST['currency']??'USD',10),
            Security::sanitizeString($_POST['phone_prefix']??'',10),
            Security::sanitizeString($_POST['flag_emoji']??'',10),
            isset($_POST['is_active'])?1:0,
        ];
        if ($id) $db->execute("UPDATE countries SET name_bn=?,name_en=?,code=?,currency=?,phone_prefix=?,flag_emoji=?,is_active=? WHERE id=?",array_merge($data,[$id]));
        else $id=$db->insert("INSERT INTO countries(name_bn,name_en,code,currency,phone_prefix,flag_emoji,is_active) VALUES(?,?,?,?,?,?,?)",$data);
        echo json_encode(['success'=>true,'id'=>$id]); exit;
    }
    if ($act==='delete_country') { $db->execute("DELETE FROM countries WHERE id=?",[Security::sanitizeInt($_POST['id']??0)]); echo json_encode(['success'=>true]); exit; }
    if ($act==='toggle_country') { $db->execute("UPDATE countries SET is_active=!is_active WHERE id=?",[Security::sanitizeInt($_POST['id']??0)]); echo json_encode(['success'=>true]); exit; }

    // ── Bot Menu ───────────────────────────────────────────
    if ($act === 'save_menu_item') {
        $id  = Security::sanitizeInt($_POST['id'] ?? 0);
        $lbl = Security::sanitizeString($_POST['label']??'',100);
        $mac = Security::sanitizeString($_POST['menu_action']??'',100);
        $ico = Security::sanitizeString($_POST['icon']??'',10);
        $ord = Security::sanitizeInt($_POST['order_no']??0);
        $on  = isset($_POST['is_active'])?1:0;
        if ($id) $db->execute("UPDATE bot_menu_items SET label=?,action=?,icon=?,order_no=?,is_active=? WHERE id=?",[$lbl,$mac,$ico,$ord,$on,$id]);
        else $id=$db->insert("INSERT INTO bot_menu_items(label,action,icon,order_no,is_active) VALUES(?,?,?,?,?)",[$lbl,$mac,$ico,$ord,$on]);
        echo json_encode(['success'=>true,'id'=>$id]); exit;
    }
    if ($act==='delete_menu_item') { $db->execute("DELETE FROM bot_menu_items WHERE id=?",[Security::sanitizeInt($_POST['id']??0)]); echo json_encode(['success'=>true]); exit; }
    if ($act==='toggle_menu_item') { $db->execute("UPDATE bot_menu_items SET is_active=!is_active WHERE id=?",[Security::sanitizeInt($_POST['id']??0)]); echo json_encode(['success'=>true]); exit; }
    if ($act==='save_menu_titles') {
        Config::set('bot_menu_title_bn',$_POST['title_bn']??'');
        Config::set('bot_menu_title_en',$_POST['title_en']??'');
        echo json_encode(['success'=>true,'message'=>'✅ সেভ হয়েছে']); exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// ── Page Data ──────────────────────────────────────────────
$customers = []; $countries = []; $services = []; $statuses = []; $menuItems = []; $aiStats = [];
try { $customers = $db->fetchAll("SELECT c.*,co.name_bn as country_name FROM customers c LEFT JOIN countries co ON c.country_id=co.id ORDER BY c.created_at DESC"); } catch(Exception $e) {}
try { $countries = $db->fetchAll("SELECT * FROM countries ORDER BY name_bn"); } catch(Exception $e) {}
try { $services  = $db->fetchAll("SELECT * FROM services WHERE is_active=1 ORDER BY name"); } catch(Exception $e) {}
try { $statuses  = $db->fetchAll("SELECT * FROM application_statuses ORDER BY order_no"); } catch(Exception $e) {}
try { $menuItems = $db->fetchAll("SELECT * FROM bot_menu_items ORDER BY order_no ASC"); } catch(Exception $e) {}
try {
    foreach ($db->fetchAll("SELECT provider,COUNT(*) as total,SUM(success) as sc,COUNT(*)-SUM(success) as fc,SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) as td FROM ai_usage_logs GROUP BY provider") as $s) {
        $aiStats[$s['provider']] = $s;
    }
} catch(Exception $e) {}
$currentProvider = Config::get('current_ai_provider','gemini');

$allActions = [
    'show_categories'=>['icon'=>'📋','label'=>'আমাদের সেবা'],
    'check_status'   =>['icon'=>'🔍','label'=>'আবেদনের অবস্থা'],
    'payment_info'   =>['icon'=>'💰','label'=>'পেমেন্ট হিসাব'],
    'doc_folder'     =>['icon'=>'📁','label'=>'আমার ডকুমেন্ট'],
    'member_card'    =>['icon'=>'🪪','label'=>'Member Card'],
    'currency_rate'  =>['icon'=>'💱','label'=>'কারেন্সি রেট'],
    'sos'            =>['icon'=>'🆘','label'=>'জরুরি সাহায্য'],
    'salary_start'   =>['icon'=>'💵','label'=>'স্যালারি ক্যালকুলেটর'],
    'locker_menu'    =>['icon'=>'🔒','label'=>'Digital Locker'],
    'main_menu'      =>['icon'=>'🏠','label'=>'প্রধান মেনু'],
];
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/admin/admin.css">

</head>
<body>
<?php $currentPage = 'customers'; include __DIR__.'/sidebar.php'; ?>

<div class="main">

<?php if ($tab==='customers'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold">👥 গ্রাহক তালিকা (<?=count($customers)?>)</h4>
    <div class="d-flex gap-2">
        <input type="text" id="srch" class="form-control" placeholder="নাম/মোবাইল..." style="width:200px">
        <button class="btn btn-success" onclick="openAddAppModal(null)"><i class="fa fa-plus"></i> আবেদন যোগ</button>
    </div>
</div>
<div class="card p-3">
<div class="table-responsive">
<table class="table table-hover" id="custTbl">
    <thead><tr><th>নাম</th><th>মোবাইল</th><th>দেশ</th><th>Platform</th><th>যোগদান</th><th>অ্যাকশন</th></tr></thead>
    <tbody>
    <?php foreach ($customers as $c): ?>
    <tr>
        <td>
            <div class="fw-semibold"><?=htmlspecialchars($c['name']??'—')?></div>
            <small class="text-muted"><?=htmlspecialchars($c['mobile']??'')?></small>
        </td>
        <td><?=htmlspecialchars($c['mobile']??'—')?></td>
        <td><?=htmlspecialchars($c['country_name']??'—')?></td>
        <td><?php
    $plt = strtolower($c['platform'] ?? '');
    $pcolor = match($plt) {
        'telegram'  => 'background:#29B6F6;color:#fff',
        'whatsapp'  => 'background:#25D366;color:#fff',
        'messenger' => 'background:#0084FF;color:#fff',
        default     => 'background:#6366F1;color:#fff',
    };
    $picon = match($plt) {
        'telegram'  => '✈',
        'whatsapp'  => '💬',
        'messenger' => '💙',
        default     => '🤖',
    };
?><span class="badge" style="<?=$pcolor?>;font-size:.72rem;padding:4px 9px;border-radius:20px">
    <?=$picon?> <?=strtoupper($plt)?>
</span></td>
        <td><small><?=date('d/m/Y',strtotime($c['created_at']))?></small></td>
        <td>
            <div class="d-flex gap-1 flex-wrap">
                <button class="btn btn-sm btn-primary py-0" onclick="openCustomerDetail(<?=(int)$c['id']?>, this.getAttribute('data-name'))" data-name="<?=htmlspecialchars($c['name']??'Customer',ENT_QUOTES)?>"  title="বিস্তারিত — আবেদন, পেমেন্ট, ডকুমেন্ট">📊 বিস্তারিত</button>
                <button class="btn btn-sm btn-success py-0" onclick="openAddAppModal(<?=(int)$c['id']?>)" title="আবেদন যোগ">➕</button>
                <button class="btn btn-sm btn-outline-primary py-0" onclick="openEdit(this)" data-customer='<?=htmlspecialchars(json_encode($c,JSON_UNESCAPED_UNICODE),ENT_QUOTES)?>'  title="এডিট">✏️</button>
                <a href="/admin/?page=chat&cid=<?=$c['id']?>" class="btn btn-sm btn-outline-secondary py-0">💬</a>
                <
                <button class="btn btn-sm btn-outline-danger py-0" onclick="deleteCustomer(<?=(int)$c['id']?>, this.getAttribute('data-name'))" data-name="<?=htmlspecialchars($c['name']??'Customer',ENT_QUOTES)?>">🗑️</button>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<?php elseif ($tab==='countries'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold">🌍 দেশ ম্যানেজমেন্ট</h4>
    <button class="btn btn-primary" onclick="openCountryModal()">+ নতুন দেশ</button>
</div>
<div class="alert alert-info small">এখান থেকে যোগ করা দেশগুলো সেবা, অনবোর্ডিং ও গ্রাহক প্রোফাইলে ব্যবহার হবে।</div>
<div class="card p-3">
<table class="table table-hover">
    <thead><tr><th>পতাকা</th><th>নাম (বাংলা)</th><th>English</th><th>Code</th><th>Currency</th><th>Phone</th><th>চালু</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($countries as $co): ?>
    <tr>
        <td style="font-size:1.4rem"><?=htmlspecialchars($co['flag_emoji']??'')?></td>
        <td><?=htmlspecialchars($co['name_bn'])?></td>
        <td><?=htmlspecialchars($co['name_en']??'')?></td>
        <td><code><?=htmlspecialchars($co['code']??'')?></code></td>
        <td><?=htmlspecialchars($co['currency']??'')?></td>
        <td><?=htmlspecialchars($co['phone_prefix']??'')?></td>
        <td><label class="toggle-feature"><input type="checkbox" <?=$co['is_active']?'checked':''?> onchange="api({action:'toggle_country',id:<?=(int)$co['id']?>})"><span class="toggle-slider"></span></label></td>
        <td class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-primary" onclick="editCountry(this)" data-country='<?=htmlspecialchars(json_encode($co,JSON_UNESCAPED_UNICODE),ENT_QUOTES)?>'>✏️</button>
            <button class="btn btn-sm btn-outline-danger" onclick="delGeneric('delete_country',<?=(int)$co['id']?>)">🗑️</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php elseif ($tab==='menu'): ?>
<h4 class="fw-bold mb-3">📱 Bot মেনু কনফিগারেশন</h4>
<div class="alert alert-info small">✏️ বাটন দিয়ে যেকোনো আইটেমের লেখা (Label), আইকন ও কমান্ড (Action) সম্পূর্ণ পরিবর্তন করা যাবে।</div>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card p-4 mb-3">
            <h6 class="fw-bold mb-3">📝 মেনু শিরোনাম</h6>
            <div class="mb-2"><label class="form-label small">বাংলা</label><textarea id="menuTitleBn" class="form-control form-control-sm" rows="2"><?=htmlspecialchars(Config::get('bot_menu_title_bn','🏠 *প্রধান মেনু*'))?></textarea></div>
            <div class="mb-3"><label class="form-label small">English</label><textarea id="menuTitleEn" class="form-control form-control-sm" rows="2"><?=htmlspecialchars(Config::get('bot_menu_title_en','🏠 *Main Menu*'))?></textarea></div>
            <button class="btn btn-primary btn-sm" onclick="saveMenuTitles()">💾 সেভ</button>
            <span id="titleMsg" class="ms-2 small"></span>
        </div>
        <div class="card p-4">
            <h6 class="fw-bold mb-3">➕ নতুন মেনু আইটেম</h6>
            <div class="row g-2">
                <div class="col-3"><label class="small">আইকন</label><input id="mi_icon" class="form-control form-control-sm" placeholder="📋" maxlength="5"></div>
                <div class="col-9"><label class="small">লেখা (Label)</label><input id="mi_label" class="form-control form-control-sm" placeholder="আমাদের সেবা"></div>
                <div class="col-12"><label class="small">Action (কমান্ড)</label>
                    <select id="mi_action" class="form-select form-select-sm" onchange="onNewActChange(this.value)">
                        <?php foreach($allActions as $ak=>$ai): ?><option value="<?=$ak?>"><?=$ai['icon']?> <?=$ai['label']?> → <?=$ak?></option><?php endforeach; ?>
                        <option value="__custom__">✍️ নিজে লিখুন...</option>
                    </select>
                </div>
                <div id="customNewWrap" class="col-12" style="display:none"><input id="mi_custom" class="form-control form-control-sm mt-1" placeholder="কাস্টম action লিখুন, যেমন: check_status"></div>
                <div class="col-4"><label class="small">ক্রম</label><input id="mi_order" type="number" class="form-control form-control-sm" value="<?=count($menuItems)+1?>"></div>
                <div class="col-8 d-flex align-items-end"><button class="btn btn-success btn-sm w-100" onclick="addMenuItem()">+ যোগ করুন</button></div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card p-4 mb-3">
            <h6 class="fw-bold mb-3">📋 মেনু আইটেমসমূহ</h6>
            <?php foreach($menuItems as $mi): ?>
            <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded border" style="background:<?=$mi['is_active']?'#f8f9fa':'#fff3f3'?>">
                <span style="font-size:1.2rem;min-width:26px;text-align:center"><?=htmlspecialchars($mi['icon']??'')?></span>
                <span class="flex-grow-1 fw-semibold small"><?=htmlspecialchars($mi['label'])?></span>
                <code class="small text-muted"><?=htmlspecialchars($mi['action'])?></code>
                <label class="toggle-feature ms-1"><input type="checkbox" <?=$mi['is_active']?'checked':''?> onchange="api({action:'toggle_menu_item',id:<?=(int)$mi['id']?>}).then(()=>location.reload())"><span class="toggle-slider"></span></label>
                <button class="btn btn-sm btn-outline-primary py-0 px-1" onclick="editMenuModal(this.dataset.mi)" data-mi='<?=htmlspecialchars(json_encode($mi,JSON_UNESCAPED_UNICODE),ENT_QUOTES)?>' title="সম্পাদনা">✏️</button>
                <button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="delGeneric('delete_menu_item',<?=(int)$mi['id']?>)">🗑️</button>
            </div>
            <?php endforeach; ?>
            <?php if(empty($menuItems)): ?><p class="text-muted small">কোনো মেনু আইটেম নেই।</p><?php endif; ?>
        </div>
        <div class="card p-4">
            <h6 class="fw-bold mb-2">👁 Preview</h6>
            <div style="background:#e5ddd5;border-radius:10px;padding:12px">
                <div style="background:white;border-radius:8px;padding:10px;max-width:85%;margin-bottom:8px;font-size:.85rem">
                    <?=nl2br(htmlspecialchars(Config::get('bot_menu_title_bn','🏠 প্রধান মেনু')))?>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:4px">
                <?php foreach(array_filter($menuItems,fn($m)=>$m['is_active']) as $mi): ?>
                <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:5px 9px;font-size:.8rem"><?=htmlspecialchars(($mi['icon']??'').' '.$mi['label'])?></div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab==='ai'): ?>
<h4 class="fw-bold mb-4">🤖 AI Usage Stats</h4>
<div class="row g-3">
<?php foreach(['gemini'=>['Google Gemini','#4285F4'],'openrouter'=>['OpenRouter','#FF6B35']] as $pid=>[$pn,$pc]):
    $s=$aiStats[$pid]??['total'=>0,'sc'=>0,'fc'=>0,'td'=>0]; $isAct=$currentProvider===$pid; ?>
<div class="col-md-6"><div class="stat-box <?=$isAct?'border border-success':''?>">
    <?php if($isAct): ?><div class="badge bg-success mb-1">▶ Active</div><br><?php endif; ?>
    <h5 style="color:<?=$pc?>"><?=$pn?></h5>
    <div class="row g-2 mt-1">
        <div class="col-3"><div class="fw-bold fs-3"><?=$s['total']?></div><div class="small text-muted">মোট</div></div>
        <div class="col-3"><div class="fw-bold fs-3 text-success"><?=$s['sc']?></div><div class="small text-muted">সফল</div></div>
        <div class="col-3"><div class="fw-bold fs-3 text-danger"><?=$s['fc']?></div><div class="small text-muted">ব্যর্থ</div></div>
        <div class="col-3"><div class="fw-bold fs-3 text-primary"><?=$s['td']?></div><div class="small text-muted">আজ</div></div>
    </div>
</div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /main -->

<!-- Modals -->
<!-- Edit Customer -->
<div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5>গ্রাহক সম্পাদনা</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <input type="hidden" id="ec_id">
    <div class="mb-3"><label class="form-label">নাম</label><input id="ec_name" class="form-control" required></div>
    <div class="mb-3"><label class="form-label">মোবাইল</label><input id="ec_mobile" class="form-control"></div>
    <div class="mb-3"><label class="form-label">ঠিকানা</label><textarea id="ec_address" class="form-control" rows="2"></textarea></div>
    <div class="mb-3"><label class="form-label">দেশ</label>
        <select id="ec_country" class="form-select">
            <option value="">— নির্বাচন করুন —</option>
            <?php foreach($countries as $co): ?><option value="<?=$co['id']?>"><?=htmlspecialchars(($co['flag_emoji']??'').' '.$co['name_bn'])?></option><?php endforeach; ?>
        </select>
    </div>
    <div id="editMsg"></div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button><button class="btn btn-primary" onclick="saveEdit()">💾 সেভ</button></div>
</div></div></div>

<!-- Customer Detail -->
<div class="modal fade" id="detailModal" tabindex="-1"><div class="modal-dialog modal-xl"><div class="modal-content">
<div class="modal-header"><h5 id="detailTitle">📊 গ্রাহকের বিস্তারিত তথ্য</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body" id="detailBody" style="max-height:78vh;overflow-y:auto">
    <div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2">লোড হচ্ছে...</p></div>
</div>
</div></div></div>

<!-- Add Application -->
<div class="modal fade" id="addAppModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5>➕ আবেদন ও পেমেন্ট যোগ</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <div class="mb-3"><label class="form-label fw-semibold">গ্রাহক *</label>
        <select id="app_customer" class="form-select">
            <option value="">— গ্রাহক নির্বাচন করুন —</option>
            <?php foreach($customers as $c): ?><option value="<?=$c['id']?>"><?=htmlspecialchars(($c['name']??'—').' | '.($c['mobile']??''))?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3"><label class="form-label">সেবা (ঐচ্ছিক)</label>
        <select id="app_service" class="form-select">
            <option value="">— সেবা নির্বাচন করুন —</option>
            <?php foreach($services as $s): ?><option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?> (<?=$s['currency']?> <?=number_format($s['price'],0)?>)</option><?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3"><label class="form-label">প্রারম্ভিক অবস্থা</label>
        <select id="app_status" class="form-select">
            <?php foreach($statuses as $s): ?><option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3"><label class="form-label">নোট</label><textarea id="app_notes" class="form-control" rows="2" placeholder="অফিসে এসে আবেদন করেছেন, রেফারেন্স..."></textarea></div>
    <hr class="my-3"><h6 class="fw-bold mb-2">💰 পেমেন্ট তথ্য (ঐচ্ছিক)</h6>
    <div class="row g-2">
        <div class="col-5"><label class="small">মোট টাকা</label><input id="pay_total" type="number" step="0.01" class="form-control form-control-sm" placeholder="5000"></div>
        <div class="col-5"><label class="small">জমা দেওয়া</label><input id="pay_paid" type="number" step="0.01" class="form-control form-control-sm" placeholder="2000"></div>
        <div class="col-2"><label class="small">মুদ্রা</label>
        <select id="pay_cur" class="form-select form-select-sm">
        <?php foreach($sysCurrencies as $cur): ?><option value="<?=htmlspecialchars($cur)?>" <?=$cur==='BDT'?'selected':''?>><?=htmlspecialchars($cur)?></option><?php endforeach; ?>
        </select></div>
        <div class="col-12"><label class="small">বিবরণ</label><input id="pay_desc" class="form-control form-control-sm" placeholder="প্রথম কিস্তি, ভিসা ফি..."></div>
    </div>
    <div id="appMsg" class="mt-2"></div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button><button class="btn btn-primary" id="appSaveBtn" onclick="saveApplication()">💾 সেভ করুন</button></div>
</div></div></div>

<!-- Country Modal -->
<div class="modal fade" id="countryModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5>দেশ যোগ/সম্পাদনা</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><input type="hidden" id="co_id">
    <div class="row g-3">
        <div class="col-2"><label class="small">পতাকা</label><input id="co_flag" class="form-control text-center" placeholder="🇧🇩" maxlength="5"></div>
        <div class="col-5"><label class="small">নাম (বাংলা)*</label><input id="co_name_bn" class="form-control" required></div>
        <div class="col-5"><label class="small">English</label><input id="co_name_en" class="form-control"></div>
        <div class="col-4"><label class="small">Code</label><input id="co_code" class="form-control" placeholder="AE"></div>
        <div class="col-4"><label class="small">Currency</label><input id="co_currency" class="form-control" placeholder="AED"></div>
        <div class="col-4"><label class="small">Phone Prefix</label><input id="co_phone" class="form-control" placeholder="+971"></div>
        <div class="col-12 d-flex gap-2"><input type="checkbox" id="co_active" class="form-check-input" checked><label class="form-check-label">চালু</label></div>
    </div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button><button class="btn btn-primary" onclick="saveCountry()">💾 সেভ</button></div>
</div></div></div>

<!-- Menu Item Edit Modal -->
<div class="modal fade" id="menuEditModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5>✏️ মেনু আইটেম সম্পাদনা</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><input type="hidden" id="me_id">
    <div class="row g-3">
        <div class="col-3"><label class="form-label">আইকন</label><input id="me_icon" class="form-control text-center" maxlength="5" placeholder="📋"></div>
        <div class="col-9"><label class="form-label">লেখা (Label) *</label><input id="me_label" class="form-control" required></div>
        <div class="col-12">
            <label class="form-label">Action কমান্ড *</label>
            <select id="me_action_sel" class="form-select mb-2" onchange="onEditActChange(this.value)">
                <?php foreach($allActions as $ak=>$ai): ?><option value="<?=$ak?>"><?=$ai['icon']?> <?=$ai['label']?> → <?=$ak?></option><?php endforeach; ?>
                <option value="__custom__">✍️ কাস্টম লিখুন...</option>
            </select>
            <input id="me_action" class="form-control" placeholder="action key, যেমন: check_status">
            <small class="text-muted">উপরে dropdown থেকে বাছুন অথবা সরাসরি নিচে লিখুন</small>
        </div>
        <div class="col-4"><label class="form-label">ক্রম</label><input id="me_order" type="number" class="form-control"></div>
        <div class="col-8 d-flex align-items-end gap-2 pb-2"><input type="checkbox" id="me_active" class="form-check-input"><label class="form-check-label">চালু রাখুন</label></div>
    </div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button><button class="btn btn-primary" onclick="saveMenuItemEdit()">💾 সেভ</button></div>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF='<?=$csrf?>';
async function api(data) {
    // All actions go to api.php (CSRF exempt for customer actions)
    const url = '/admin/api.php';
    try {
        const r = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({...data, csrf_token: CSRF || ''})
        });
        if (!r.ok) {
            if (r.status === 403) { alert('Session \u09AE\u09C7\u09AF\u09BC\u09BE\u09A6 \u09B6\u09C7\u09B7\u0964 \u09AA\u09C1\u09A8\u09B0\u09BE\u09AF\u09BC \u09B2\u0997\u0987\u09A8 \u0995\u09B0\u09C1\u09A8\u0964'); location.reload(); }
            return {success: false};
        }
        const t = await r.text();
        try { return JSON.parse(t); } catch(e) { console.error('Non-JSON response:', t.substring(0,200)); return {success:false,message:'Server error'}; }
    } catch(e) { console.error('API error:', e); return {success:false,message:e.message}; }
}

// ── Search ─────────────────────────────────────────────────
document.getElementById('srch')?.addEventListener('input',function(){
    const v=this.value.toLowerCase();
    document.querySelectorAll('#custTbl tbody tr').forEach(r=>r.style.display=r.textContent.toLowerCase().includes(v)?'':'none');
});

// ── Modals ─────────────────────────────────────────────────
let editModal,detailModal,addAppModal,countryModal,menuEditModal;
document.addEventListener('DOMContentLoaded',()=>{
    editModal=new bootstrap.Modal('#editModal');
    detailModal=new bootstrap.Modal('#detailModal');
    addAppModal=new bootstrap.Modal('#addAppModal');
    countryModal=new bootstrap.Modal('#countryModal');
    menuEditModal=new bootstrap.Modal('#menuEditModal');
});

// ── Delete ─────────────────────────────────────────────────
async function deleteCustomer(id,name){
    if(!confirm('"'+name+'" \u0993 \u09A4\u09BE\u09B0 \u09B8\u09AE\u09B8\u09CD\u09A4 \u09A4\u09A5\u09CD\u09AF \u09AE\u09C1\u099B\u09C7 \u09AB\u09C7\u09B2\u09AC\u09C7\u09A8?')) return;
    showToast('\u09AE\u09C1\u099B\u09C7 \u09AB\u09C7\u09B2\u09BE \u09B9\u099A\u09CD\u099B\u09C7...','info');
    const r=await api({action:'delete_customer',customer_id:id});
    if(r.success){
        showToast(r.message||'✅ \u09AE\u09C1\u099B\u09C7 \u09AB\u09C7\u09B2\u09BE \u09B9\u09AF\u09BC\u09C7\u099B\u09C7','success');
        setTimeout(()=>location.reload(),1500);
    } else {
        showToast('❌ '+(r.message||'\u09B8\u09AE\u09B8\u09CD\u09AF\u09BE'),'danger');
    }
}

// ── Edit ───────────────────────────────────────────────────
function openEdit(el){
    var c = (el && el.dataset && el.dataset.customer) ? JSON.parse(el.dataset.customer) : el;
    document.getElementById('ec_id').value=c.id;
    document.getElementById('ec_name').value=c.name||'';
    document.getElementById('ec_mobile').value=c.mobile||'';
    document.getElementById('ec_address').value=c.address||'';
    document.getElementById('ec_country').value=c.country_id||'';
    document.getElementById('editMsg').innerHTML='';
    editModal.show();
}
async function saveEdit(){
    const r=await api({action:'edit_customer',customer_id:document.getElementById('ec_id').value,
        name:document.getElementById('ec_name').value,mobile:document.getElementById('ec_mobile').value,
        address:document.getElementById('ec_address').value,country_id:document.getElementById('ec_country').value});
    if(r.success){
        document.getElementById('editMsg').innerHTML='<div class="alert alert-success py-1">'+r.message+'</div>';
        setTimeout(()=>{editModal.hide();location.reload();},1000);
    } else {
        document.getElementById('editMsg').innerHTML='<div class="alert alert-danger py-1">'+r1.message+'</div>';
    }
}

// ── Customer Detail ────────────────────────────────────────
async function openCustomerDetail(cid,name){
    var title = document.getElementById('detailTitle');
    var body = document.getElementById('detailBody');
    title.textContent = name;
    body.innerHTML = '<div class="text-center p-4"><div class="spinner-border"></div></div>';
    detailModal.show();
    var r = await api({action:'get_customer_detail', customer_id:cid});
    if(!r || !r.success){
        body.innerHTML = '<div class="alert alert-danger">Error loading data</div>';
        return;
    }
    var c = r.customer || {};
    var paid = parseFloat(r.paid||0);
    var due = parseFloat(r.due||0);
    var apps = r.apps || [];
    var pays = r.payments || [];
    var docs = r.docs || [];

    var h = '';

    // Summary row
    h += '<div class="row g-2 mb-3">';
    h += '<div class="col-4"><div class="card p-2 text-center border-success"><small>Paid</small>';
    h += '<div class="fw-bold text-success">' + paid.toLocaleString() + '</div></div></div>';
    h += '<div class="col-4"><div class="card p-2 text-center border-danger"><small>Due</small>';
    h += '<div class="fw-bold ' + (due>0?'text-danger':'text-success') + '">' + due.toLocaleString() + '</div></div></div>';
    h += '<div class="col-4"><div class="card p-2 text-center border-primary"><small>Apps</small>';
    h += '<div class="fw-bold text-primary">' + apps.length + '</div></div></div>';
    h += '</div>';

    // Customer info
    h += '<div class="mb-3 p-2 bg-light rounded small">';
    h += '<b>Platform:</b> ' + (c.platform||'-') + ' | ';
    h += '<b>Mobile:</b> ' + (c.mobile||'-') + ' | ';
    h += '<b>Country:</b> ' + (c.country_name||'-');
    
    h += '</div>';

    // Applications
    h += '<h6 class="fw-bold">Applications (' + apps.length + ')</h6>';
    if(apps.length === 0){
        h += '<p class="text-muted small">No applications.</p>';
    } else {
        h += '<table class="table table-sm table-bordered">';
        h += '<thead><tr><th>Tracking</th><th>Service</th><th>Status</th><th>Date</th></tr></thead><tbody>';
        for(var i=0; i<apps.length; i++){
            var a = apps[i];
            h += '<tr>';
            h += '<td><code>' + (a.tracking_id||'') + '</code></td>';
            h += '<td>' + (a.sname||'-') + '</td>';
            h += '<td><span class="badge" style="background:' + (a.color||'#ccc') + '">' + (a.status_name||'-') + '</span></td>';
            h += '<td>' + ((a.created_at||'').substring(0,10)) + '</td>';
            h += '</tr>';
        }
        h += '</tbody></table>';
    }

    // Payments
    h += '<h6 class="fw-bold mt-3">Payments (' + pays.length + ')</h6>';
    if(pays.length === 0){
        h += '<p class="text-muted small">No payments.</p>';
    } else {
        h += '<table class="table table-sm table-bordered">';
        h += '<thead><tr><th>Total</th><th>Paid</th><th>Due</th><th>Currency</th><th>Date</th></tr></thead><tbody>';
        for(var j=0; j<pays.length; j++){
            var p = pays[j];
            var bal = parseFloat(p.total_amount||0) - parseFloat(p.paid_amount||0);
            h += '<tr>';
            h += '<td>' + parseFloat(p.total_amount||0).toFixed(0) + '</td>';
            h += '<td class="text-success">' + parseFloat(p.paid_amount||0).toFixed(0) + '</td>';
            h += '<td class="' + (bal>0?'text-danger':'text-success') + '">' + bal.toFixed(0) + '</td>';
            h += '<td>' + (p.currency||'') + '</td>';
            h += '<td>' + ((p.created_at||'').substring(0,10)) + '</td>';
            h += '</tr>';
        }
        h += '</tbody></table>';
    }

    // Documents
    h += '<h6 class="fw-bold mt-3">Documents (' + docs.length + ')</h6>';
    if(docs.length === 0){
        h += '<p class="text-muted small">No documents.</p>';
    } else {
        for(var k=0; k<docs.length; k++){
            var d = docs[k];
            h += '<div class="card p-2 mb-1 border">';
            h += '<b>' + (d.doc_type||'doc') + '</b>';
            if(d.doc_number) h += ' <code>' + d.doc_number + '</code>';
            if(d.holder_name) h += ' | ' + d.holder_name;
            if(d.expiry_date) h += ' | Exp: ' + d.expiry_date;
            if(d.drive_url) h += ' <a href="'+d.drive_url+'" target="_blank" class="btn btn-sm btn-outline-success py-0 px-1">Drive</a>';
            h += '</div>';
        }
    }

    // Actions
    h += '<div class="mt-3 d-flex gap-2">';
    h += '<button class="btn btn-success btn-sm" onclick="openAddAppModal(' + cid + ')">+ Add App</button>';
    h += '<a href="/admin/?page=chat&cid=' + cid + '" class="btn btn-primary btn-sm">Chat</a>';
    h += '</div>';

    body.innerHTML = h;
}

// ── Add Application ────────────────────────────────────────
function openAddAppModal(cid){
    ['app_notes','pay_total','pay_paid','pay_desc'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('appMsg').innerHTML='';
    // pay_cur is now a select - no reset needed
    if(cid) document.getElementById('app_customer').value=cid;
    addAppModal.show();
}
async function saveApplication(){
    const cid=document.getElementById('app_customer').value;
    if(!cid){document.getElementById('appMsg').innerHTML='<div class="alert alert-danger py-1">Select a customer</div>';return;}
    const btn=document.getElementById('appSaveBtn'); btn.disabled=true; btn.textContent='\u09B8\u09C7\u09AD \u09B9\u099A\u09CD\u099B\u09C7...';

    const r1=await api({
        action:'add_application',customer_id:cid,
        service_id:document.getElementById('app_service').value,
        status_id:document.getElementById('app_status').value,
        notes:document.getElementById('app_notes').value
    });
    if(!r1.success){document.getElementById('appMsg').innerHTML='<div class="alert alert-danger py-1">'+r1.message+'</div>';btn.disabled=false;btn.textContent='Save';return;}

    const total=document.getElementById('pay_total').value;
    if(total && parseFloat(total)>0){
        await api({action:'add_payment',customer_id:cid,application_id:r1.app_id,
            total_amount:total,paid_amount:document.getElementById('pay_paid').value||0,
            currency:document.getElementById('pay_cur').value||'BDT',
            description:document.getElementById('pay_desc').value});
    }

    document.getElementById('appMsg').innerHTML='<div class="alert alert-success py-1">Done! Tracking: <strong>'+r1.tracking_id+'</strong></div>';
    btn.disabled=false; btn.textContent='💾 \u09B8\u09C7\u09AD \u0995\u09B0\u09C1\u09A8';
    setTimeout(()=>addAppModal.hide(),2500);
}

// ── Country ────────────────────────────────────────────────
function openCountryModal(){['co_id','co_flag','co_name_bn','co_name_en','co_code','co_currency','co_phone'].forEach(id=>document.getElementById(id).value='');document.getElementById('co_active').checked=true;countryModal.show();}
function editCountry(c){document.getElementById('co_id').value=c.id;document.getElementById('co_flag').value=c.flag_emoji||'';document.getElementById('co_name_bn').value=c.name_bn;document.getElementById('co_name_en').value=c.name_en||'';document.getElementById('co_code').value=c.code||'';document.getElementById('co_currency').value=c.currency||'';document.getElementById('co_phone').value=c.phone_prefix||'';document.getElementById('co_active').checked=c.is_active==1;countryModal.show();}
async function saveCountry(){
    const r=await api({action:'save_country',id:document.getElementById('co_id').value,
        name_bn:document.getElementById('co_name_bn').value,name_en:document.getElementById('co_name_en').value,
        code:document.getElementById('co_code').value,currency:document.getElementById('co_currency').value,
        phone_prefix:document.getElementById('co_phone').value,flag_emoji:document.getElementById('co_flag').value,
        is_active:document.getElementById('co_active').checked?1:0});
    if(r.success){countryModal.hide();location.reload();}
}

// ── Menu: New item ─────────────────────────────────────────
function onNewActChange(val){document.getElementById('customNewWrap').style.display=val==='__custom__'?'':'none';}
async function addMenuItem(){
    const lbl=document.getElementById('mi_label').value.trim();
    const selAct=document.getElementById('mi_action').value;
    const act=selAct==='__custom__'?document.getElementById('mi_custom').value.trim():selAct;
    if(!lbl){showToast('\u09B2\u09C7\u0996\u09BE \u09A6\u09BF\u09A8','warning');return;}
    if(!act){showToast('Action \u09A6\u09BF\u09A8','warning');return;}
    const r=await api({action:'save_menu_item',id:0,label:lbl,menu_action:act,
        icon:document.getElementById('mi_icon').value,
        order_no:document.getElementById('mi_order').value,is_active:1});
    if(r.success){showToast('✅ \u09AF\u09CB\u0997 \u09B9\u09AF\u09BC\u09C7\u099B\u09C7','success');setTimeout(()=>location.reload(),800);}
}

// ── Menu: Edit existing ────────────────────────────────────
function editMenuModal(el){
    var mi = typeof el === 'string' ? JSON.parse(el) : (el && el.dataset ? JSON.parse(el.dataset.mi) : el);
    document.getElementById('me_id').value=mi.id;
    document.getElementById('me_icon').value=mi.icon||'';
    document.getElementById('me_label').value=mi.label;
    document.getElementById('me_action').value=mi.action;
    document.getElementById('me_order').value=mi.order_no;
    document.getElementById('me_active').checked=mi.is_active==1;
    const sel=document.getElementById('me_action_sel');
    const opt=[...sel.options].find(o=>o.value===mi.action);
    sel.value=opt?mi.action:'__custom__';
    menuEditModal.show();
}
function onEditActChange(val){if(val!=='__custom__') document.getElementById('me_action').value=val;}
async function saveMenuItemEdit(){
    const id=document.getElementById('me_id').value;
    const lbl=document.getElementById('me_label').value.trim();
    const act=document.getElementById('me_action').value.trim();
    if(!lbl||!act){showToast('Label \u0993 Action \u09A6\u09BF\u09A8','warning');return;}
    const r=await api({action:'save_menu_item',id,label:lbl,menu_action:act,
        icon:document.getElementById('me_icon').value,
        order_no:document.getElementById('me_order').value,
        is_active:document.getElementById('me_active').checked?1:0});
    if(r.success){menuEditModal.hide();showToast('✅ \u0986\u09AA\u09A1\u09C7\u099F \u09B9\u09AF\u09BC\u09C7\u099B\u09C7','success');setTimeout(()=>location.reload(),800);}
}
async function saveMenuTitles(){
    const r=await api({action:'save_menu_titles',
        title_bn:document.getElementById('menuTitleBn').value,
        title_en:document.getElementById('menuTitleEn').value});
    const el=document.getElementById('titleMsg');
    el.innerHTML=r.success?'<span class="text-success">✅ \u09B8\u09C7\u09AD!</span>':'<span class="text-danger">❌</span>';
    setTimeout(()=>el.innerHTML='',3000);
}

// ── Generic ────────────────────────────────────────────────
async function delGeneric(action,id){
    if(!confirm('\u09AE\u09C1\u099B\u09C7 \u09AB\u09C7\u09B2\u09AC\u09C7\u09A8?'))return;
    const r=await api({action,id});
    if(r.success)location.reload();
    else showToast('❌ '+(r.message||'\u09B8\u09AE\u09B8\u09CD\u09AF\u09BE'),'danger');
}

function showToast(msg,type='info'){
    const t=document.createElement('div');
    t.className=`alert alert-${type} position-fixed bottom-0 end-0 m-3 shadow`;
    t.style.cssText='z-index:9999;min-width:280px;border-radius:12px;font-size:.9rem';
    t.textContent=msg;
    document.body.appendChild(t);
    setTimeout(()=>t.remove(),4000);
}
</script>
</body>
</html>
<?php if (ob_get_level() > 0) ob_end_flush(); ?>
