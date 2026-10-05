<?php
// ============================================================
// admin/services.php - সেবা ম্যানেজমেন্ট
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/security.php';
Security::requireAdminAuth();
Security::setSecurityHeaders();
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in'])) { header('Location: /admin/'); exit; }

$db = Database::getInstance();

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    $act = $_POST['action'];

    if ($act === 'save_service') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            $_POST['name'] ?? '', $_POST['description'] ?? '',
            (float)($_POST['price'] ?? 0), $_POST['currency'] ?? 'BDT',
            $_POST['category_id'] ?: null, $_POST['country_from_id'] ?: null,
            $_POST['country_for_id'] ?: null, $_POST['docs_required'] ?? '',
            $_POST['duration'] ?? '', isset($_POST['is_active']) ? 1 : 0
        ];
        if ($id) {
            $db->execute("UPDATE services SET name=?,description=?,price=?,currency=?,category_id=?,country_from_id=?,country_for_id=?,docs_required=?,duration=?,is_active=? WHERE id=?",
                array_merge($data, [$id]));
        } else {
            $id = $db->insert("INSERT INTO services(name,description,price,currency,category_id,country_from_id,country_for_id,docs_required,duration,is_active) VALUES(?,?,?,?,?,?,?,?,?,?)", $data);
        }
        echo json_encode(['success' => true, 'id' => $id]);

    } elseif ($act === 'delete_service') {
        $db->execute("DELETE FROM services WHERE id=?", [(int)$_POST['id']]);
        echo json_encode(['success' => true]);

    } elseif ($act === 'toggle_service') {
        $db->execute("UPDATE services SET is_active=!is_active WHERE id=?", [(int)$_POST['id']]);
        echo json_encode(['success' => true]);

    } elseif ($act === 'save_category') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->execute("UPDATE service_categories SET name=?,icon=? WHERE id=?", [$_POST['name'], $_POST['icon'] ?? '📋', $id]);
        } else {
            $id = $db->insert("INSERT INTO service_categories(name,icon) VALUES(?,?)", [$_POST['name'], $_POST['icon'] ?? '📋']);
        }
        echo json_encode(['success' => true, 'id' => $id]);

    } elseif ($act === 'delete_category') {
        $db->execute("DELETE FROM service_categories WHERE id=?", [(int)$_POST['id']]);
        echo json_encode(['success' => true]);

    } elseif ($act === 'save_agent') {
        $id = (int)($_POST['id'] ?? 0);
        $photoUrl = htmlspecialchars(strip_tags($_POST['photo_url'] ?? ''));
        $data = [$_POST['name'], $_POST['mobile'] ?? '', $_POST['telegram_id'] ?? '', $_POST['whatsapp_number'] ?? '', $photoUrl, isset($_POST['is_active']) ? 1 : 0];
        if ($id) {
            $db->execute("UPDATE agents SET name=?,mobile=?,telegram_id=?,whatsapp_number=?,photo_url=?,is_active=? WHERE id=?", array_merge($data, [$id]));
        } else {
            $id = $db->insert("INSERT INTO agents(name,mobile,telegram_id,whatsapp_number,photo_url,is_active) VALUES(?,?,?,?,?,?)", $data);
        }
        echo json_encode(['success' => true, 'id' => $id]);

    } elseif ($act === 'delete_agent') {
        $db->execute("DELETE FROM agents WHERE id=?", [(int)$_POST['id']]);
        echo json_encode(['success' => true]);

    } elseif ($act === 'save_onboarding_field') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [$_POST['field_key'], $_POST['field_label_bn'], $_POST['field_label_en'] ?? '', (int)($_POST['order_no'] ?? 0), isset($_POST['is_required']) ? 1 : 0, 1];
        if ($id) {
            $db->execute("UPDATE onboarding_fields SET field_key=?,field_label_bn=?,field_label_en=?,order_no=?,is_required=?,is_active=? WHERE id=?", array_merge($data, [$id]));
        } else {
            $id = $db->insert("INSERT INTO onboarding_fields(field_key,field_label_bn,field_label_en,order_no,is_required,is_active) VALUES(?,?,?,?,?,?)", $data);
        }
        echo json_encode(['success' => true, 'id' => $id]);

    } elseif ($act === 'delete_onboarding_field') {
        $db->execute("DELETE FROM onboarding_fields WHERE id=?", [(int)$_POST['id']]);
        echo json_encode(['success' => true]);

    } elseif ($act === 'save_status') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [$_POST['name'], $_POST['name_en'] ?? '', $_POST['color'] ?? '#6c757d', $_POST['notify_message'] ?? '', (int)($_POST['order_no'] ?? 0)];
        if ($id) {
            $db->execute("UPDATE application_statuses SET name=?,name_en=?,color=?,notify_message=?,order_no=? WHERE id=?", array_merge($data, [$id]));
        } else {
            $id = $db->insert("INSERT INTO application_statuses(name,name_en,color,notify_message,order_no) VALUES(?,?,?,?,?)", $data);
        }
        echo json_encode(['success' => true, 'id' => $id]);

    } elseif ($act === 'save_invoice_template') {
        Config::set('invoice_template', $_POST['html_template'] ?? '');
        echo json_encode(['success' => true, 'message' => 'টেমপ্লেট সেভ হয়েছে!']);

    } elseif ($act === 'save_payment') {
        $id = (int)($_POST['customer_id'] ?? 0);
        $appId  = $_POST['application_id'] ?: null;
        $total  = (float)$_POST['total_amount'];
        $paid   = (float)$_POST['paid_amount'];
        $cur    = $_POST['currency'] ?? 'BDT';
        $desc   = $_POST['description'] ?? '';
        $payId  = $db->insert("INSERT INTO payments(customer_id,application_id,total_amount,paid_amount,currency,description) VALUES(?,?,?,?,?,?)",
            [$id, $appId, $total, $paid, $cur, $desc]);

        // Invoice পাঠানো
        if (Config::isEnabled('invoice_enabled') && $payId) {
            require_once dirname(__DIR__) . '/services/InvoiceService.php';
            require_once dirname(__DIR__) . '/bot/Sender.php';
            $customer = $db->fetchOne("SELECT * FROM customers WHERE id=?", [$id]);
            $payment  = $db->fetchOne("SELECT * FROM payments WHERE id=?", [$payId]);
            $app      = $appId ? $db->fetchOne("SELECT a.*, s.name as service_name, st.name as status_name FROM applications a LEFT JOIN services s ON a.service_id=s.id LEFT JOIN application_statuses st ON a.status_id=st.id WHERE a.id=?", [$appId]) : [];
            if ($customer && $payment) {
                $inv = new InvoiceService();
                $inv->sendToCustomer($customer, $payment, $app ?? []);
            }
        }
        echo json_encode(['success' => true, 'id' => $payId]);

    } elseif ($act === 'send_scam_alert') {
        require_once dirname(__DIR__) . '/bot/BotFeatures.php';
        require_once dirname(__DIR__) . '/bot/Sender.php';
        $handler    = new ScamAlertHandler();
        $countryIds = $_POST['country_ids'] ? explode(',', $_POST['country_ids']) : [];
        $result     = $handler->sendAlert($_POST['message'], $countryIds);
        echo json_encode(['success' => true, 'sent' => $result['sent']]);

    } elseif ($act === 'send_country_news') {
        require_once dirname(__DIR__) . '/bot/BotFeatures.php';
        require_once dirname(__DIR__) . '/bot/Sender.php';
        $handler = new NewsHandler();
        $result  = $handler->broadcastNews($_POST['message'], (int)$_POST['country_id']);
        echo json_encode(['success' => true, 'sent' => $result['sent'], 'country' => $result['country']]);
    }
    exit;
}

// CSRF
$csrf = Security::generateCSRFToken();

// Data
$services    = $db->fetchAll("SELECT s.*, sc.name as cat_name FROM services s LEFT JOIN service_categories sc ON s.category_id=sc.id ORDER BY s.id DESC");
$categories  = $db->fetchAll("SELECT * FROM service_categories ORDER BY id");
$countries   = $db->fetchAll("SELECT * FROM countries ORDER BY name_bn");
$agents      = $db->fetchAll("SELECT * FROM agents ORDER BY id");
$statuses    = $db->fetchAll("SELECT * FROM application_statuses ORDER BY order_no");
$onboardings = $db->fetchAll("SELECT * FROM onboarding_fields ORDER BY order_no");
$currencyList = Config::get('system_currencies', 'BDT,AED,SAR,OMR,KWD,BHD,QAR,INR,PKR,USD,MYR,SGD,EUR,GBP');
$currencies   = array_filter(array_map('trim', explode(',', $currencyList)));
$tab         = $_GET['tab'] ?? 'services';
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

<?php $currentPage = 'services'; include __DIR__.'/sidebar.php'; ?>

<div class="main">
<?php if ($tab === 'services'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold">✈️ সেবা ম্যানেজমেন্ট</h5>
    <button class="btn btn-primary" onclick="openServiceModal()">+ নতুন সেবা</button>
</div>
<div class="card p-3">
    <div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>নাম</th><th>ক্যাটাগরি</th><th>মূল্য</th><th>সময়</th><th>চালু/বন্ধ</th><th>অ্যাকশন</th></tr></thead>
        <tbody>
        <?php foreach ($services as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['name']) ?></td>
            <td><span class="badge platform-badge platform-telegram"><?= htmlspecialchars($s['cat_name'] ?? '—') ?></span></td>
            <td><?= $s['price'] > 0 ? number_format($s['price'],2).' '.$s['currency'] : '—' ?></td>
            <td><?= htmlspecialchars($s['duration'] ?? '—') ?></td>
            <td><label class="toggle-feature"><input type="checkbox" <?= $s['is_active']?'checked':'' ?> onchange="api({action:'toggle_service',id:<?= $s['id'] ?>})"><span class="toggle-slider"></span></label></td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick='editService(<?= json_encode($s) ?>)'>✏️</button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteItem('delete_service',<?= $s['id'] ?>)">🗑️</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php elseif ($tab === 'categories'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold">🏷️ সেবা ক্যাটাগরি</h5>
    <button class="btn btn-primary" onclick="openCatModal()">+ নতুন ক্যাটাগরি</button>
</div>
<div class="card p-3">
<table class="table">
    <thead><tr><th>আইকন</th><th>নাম</th><th>অ্যাকশন</th></tr></thead>
    <tbody>
    <?php foreach ($categories as $c): ?>
    <tr>
        <td style="font-size:1.4rem"><?= htmlspecialchars($c['icon']) ?></td>
        <td><?= htmlspecialchars($c['name']) ?></td>
        <td>
            <button class="btn btn-sm btn-outline-primary" onclick='editCat(<?= json_encode($c) ?>)'>✏️</button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteItem('delete_category',<?= $c['id'] ?>)">🗑️</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php elseif ($tab === 'agents'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold">👤 এজেন্ট ম্যানেজমেন্ট</h5>
    <button class="btn btn-primary" onclick="openAgentModal()">+ নতুন এজেন্ট</button>
</div>
<div class="card p-3">
<table class="table">
    <thead><tr><th>ছবি</th><th>নাম</th><th>মোবাইল</th><th>Telegram ID</th><th>চালু</th><th>অ্যাকশন</th></tr></thead>
    <tbody>
    <?php foreach ($agents as $a): ?>
    <tr>
        <td>
            <?php if (!empty($a['photo_url'])): ?>
            <img src="<?= htmlspecialchars($a['photo_url']) ?>" alt="ছবি" style="width:40px;height:40px;border-radius:50%;object-fit:cover">
            <?php else: ?>
            <div style="width:40px;height:40px;border-radius:50%;background:#1a3c6e;display:flex;align-items:center;justify-content:center;color:white;font-weight:bold;font-size:1.1rem">
                <?= mb_substr($a['name'], 0, 1, 'UTF-8') ?>
            </div>
            <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($a['name']) ?></td>
        <td><?= htmlspecialchars($a['mobile'] ?? '—') ?></td>
        <td><?= htmlspecialchars($a['telegram_id'] ?? '—') ?></td>
        <td><?= $a['is_active'] ? '✅' : '❌' ?></td>
        <td>
            <button class="btn btn-sm btn-outline-primary" onclick='editAgent(<?= json_encode($a) ?>)'>✏️</button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteItem('delete_agent',<?= $a['id'] ?>)">🗑️</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php elseif ($tab === 'statuses'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold">📊 আবেদনের স্ট্যাটাস</h5>
    <button class="btn btn-primary" onclick="openStatusModal()">+ নতুন স্ট্যাটাস</button>
</div>
<div class="card p-3">
<table class="table">
    <thead><tr><th>ক্রম</th><th>নাম (বাংলা)</th><th>রং</th><th>নোটিফিকেশন বার্তা</th><th>অ্যাকশন</th></tr></thead>
    <tbody>
    <?php foreach ($statuses as $s): ?>
    <tr>
        <td><?= $s['order_no'] ?></td>
        <td><span class="badge" style="background:<?= htmlspecialchars($s['color']) ?>"><?= htmlspecialchars($s['name']) ?></span></td>
        <td><input type="color" value="<?= htmlspecialchars($s['color']) ?>" disabled style="width:40px;height:28px;border:none"></td>
        <td><small><?= htmlspecialchars(mb_substr($s['notify_message'] ?? '', 0, 60)) ?>...</small></td>
        <td>
            <button class="btn btn-sm btn-outline-primary" onclick='editStatus(<?= json_encode($s) ?>)'>✏️</button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteItem('delete_status',<?= $s['id'] ?>)">🗑️</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php elseif ($tab === 'onboarding'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold">📋 অনবোর্ডিং ফিল্ড</h5>
    <button class="btn btn-primary" onclick="openOnboardModal()">+ নতুন ফিল্ড</button>
</div>
<div class="alert alert-info">কাস্টম অনবোর্ডিং চালু করতে Settings → "কাস্টম অনবোর্ডিং" চালু করুন।</div>
<div class="card p-3">
<table class="table">
    <thead><tr><th>ক্রম</th><th>Key</th><th>প্রশ্ন (বাংলা)</th><th>বাধ্যতামূলক</th><th>অ্যাকশন</th></tr></thead>
    <tbody>
    <?php foreach ($onboardings as $o): ?>
    <tr>
        <td><?= $o['order_no'] ?></td>
        <td><code><?= htmlspecialchars($o['field_key']) ?></code></td>
        <td><?= htmlspecialchars($o['field_label_bn']) ?></td>
        <td><?= $o['is_required'] ? '✅' : '—' ?></td>
        <td>
            <button class="btn btn-sm btn-outline-primary" onclick='editOnboard(<?= json_encode($o) ?>)'>✏️</button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteItem('delete_onboarding_field',<?= $o['id'] ?>)">🗑️</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php elseif ($tab === 'payments'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold">💰 পেমেন্ট যোগ করুন</h5>
</div>
<div class="card p-4 mb-4">
    <h6 class="fw-bold mb-3">নতুন পেমেন্ট / চালান যোগ</h6>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">গ্রাহক</label>
            <select id="pay_customer" class="form-select">
                <?php foreach ($db->fetchAll("SELECT id, name, mobile FROM customers WHERE onboarding_done=1 ORDER BY name") as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name'] ?? '?') ?> - <?= htmlspecialchars($c['mobile'] ?? '') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">আবেদন (ঐচ্ছিক)</label>
            <select id="pay_app" class="form-select">
                <option value="">— নির্বাচন করুন —</option>
                <?php foreach ($db->fetchAll("SELECT a.id, a.tracking_id, s.name FROM applications a LEFT JOIN services s ON a.service_id=s.id ORDER BY a.id DESC LIMIT 50") as $a): ?>
                <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['tracking_id']) ?> - <?= htmlspecialchars($a['name'] ?? '') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">মোট পরিমাণ</label>
            <input type="number" id="pay_total" class="form-control" step="0.01" min="0">
        </div>
        <div class="col-md-3">
            <label class="form-label">জমা পরিমাণ</label>
            <input type="number" id="pay_paid" class="form-control" step="0.01" min="0">
        </div>
        <div class="col-md-2">
            <label class="form-label">কারেন্সি</label>
            <select id="pay_currency" class="form-select">
                <?php foreach ($currencies as $cur): ?><option><?= $cur ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">বিবরণ</label>
            <input type="text" id="pay_desc" class="form-control" placeholder="ভিসা ফি, সার্ভিস চার্জ...">
        </div>
        <div class="col-12">
            <button class="btn btn-success" onclick="savePayment()">💾 সেভ করুন ও Invoice পাঠান</button>
            <span id="payMsg" class="ms-2"></span>
        </div>
    </div>
</div>

<div class="card p-3">
    <h6 class="fw-bold mb-3">সর্বশেষ পেমেন্ট</h6>
    <table class="table table-sm">
        <thead><tr><th>গ্রাহক</th><th>সেবা</th><th>মোট</th><th>জমা</th><th>বকেয়া</th><th>তারিখ</th></tr></thead>
        <tbody>
        <?php foreach ($db->fetchAll("SELECT p.*, c.name, a.tracking_id, s.name as sname FROM payments p LEFT JOIN customers c ON p.customer_id=c.id LEFT JOIN applications a ON p.application_id=a.id LEFT JOIN services s ON a.service_id=s.id ORDER BY p.created_at DESC LIMIT 20") as $p): ?>
        <tr>
            <td><?= htmlspecialchars($p['name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($p['sname'] ?? '—') ?></td>
            <td><?= number_format($p['total_amount'],2).' '.$p['currency'] ?></td>
            <td class="text-success"><?= number_format($p['paid_amount'],2) ?></td>
            <td class="<?= $p['balance'] > 0 ? 'text-danger fw-bold' : 'text-success' ?>"><?= number_format($p['balance'],2) ?></td>
            <td><small><?= date('d/m/Y', strtotime($p['created_at'])) ?></small></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php elseif ($tab === 'invoice'): ?>
<div class="mb-3"><h5 class="fw-bold">🧾 Invoice HTML টেমপ্লেট</h5></div>
<div class="card p-4">
    <p class="text-muted small mb-2">যেসব Variable ব্যবহার করা যাবে: <code>{{customer_name}} {{mobile}} {{tracking_id}} {{service_name}} {{total_amount}} {{paid_amount}} {{balance}} {{currency}} {{date}} {{invoice_no}} {{company_name}} {{company_phone}}</code></p>
    <textarea id="invoiceTemplate" class="form-control font-monospace" rows="20" style="font-size:0.8rem"><?= htmlspecialchars(Config::get('invoice_template')) ?></textarea>
    <button class="btn btn-primary mt-3" onclick="saveInvoiceTemplate()">💾 সেভ করুন</button>
    <span id="invMsg" class="ms-2"></span>
</div>

<?php elseif ($tab === 'currencies'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold">💱 মুদ্রা ম্যানেজমেন্ট</h5>
</div>

<div class="row g-4">
<div class="col-md-6">
<div class="card p-4">
    <h6 class="fw-bold mb-3">বর্তমান মুদ্রা সমূহ</h6>
    <div id="currencyList" class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($currencies as $cur): ?>
        <span class="badge bg-primary fs-6 d-flex align-items-center gap-2" style="padding:.5rem .8rem">
            <?= htmlspecialchars($cur) ?>
            <button type="button" class="btn-close btn-close-white" style="font-size:.6rem"
                onclick="removeCurrency('<?= htmlspecialchars($cur) ?>')"></button>
        </span>
    <?php endforeach; ?>
    </div>
    <div class="input-group">
        <input id="newCurInput" type="text" class="form-control" placeholder="যেমন: KRW, THB, JPY..."
            maxlength="10" style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
        <button class="btn btn-success" onclick="addCurrency()">+ যোগ করুন</button>
    </div>
    <div class="form-text mt-1">মুদ্রার কোড লিখুন (যেমন: USD, BDT, AED)</div>
    <div id="curMsg" class="mt-2"></div>
</div>
</div>

<div class="col-md-6">
<div class="card p-4">
    <h6 class="fw-bold mb-3">প্রচলিত মুদ্রা কোড</h6>
    <div class="row g-1 small">
    <?php
    $commonCurrencies = [
        'BDT'=>'🇧🇩 বাংলাদেশী টাকা','AED'=>'🇦🇪 UAE দিরহাম','SAR'=>'🇸🇦 সৌদি রিয়াল',
        'QAR'=>'🇶🇦 কাতারি রিয়াল','OMR'=>'🇴🇲 ওমানি রিয়াল','KWD'=>'🇰🇼 কুয়েতি দিনার',
        'BHD'=>'🇧🇭 বাহরাইনি দিনার','MYR'=>'🇲🇾 মালয়েশিয়ান রিঙ্গিত','SGD'=>'🇸🇬 সিঙ্গাপুর ডলার',
        'USD'=>'🇺🇸 আমেরিকান ডলার','EUR'=>'🇪🇺 ইউরো','GBP'=>'🇬🇧 ব্রিটিশ পাউন্ড',
        'INR'=>'🇮🇳 ভারতীয় রুপি','PKR'=>'🇵🇰 পাকিস্তানি রুপি','LYD'=>'🇱🇾 লিবিয়ান দিনার',
        'JPY'=>'🇯🇵 জাপানি ইয়েন','KRW'=>'🇰🇷 কোরিয়ান ওন','THB'=>'🇹🇭 থাই বাট',
    ];
    foreach ($commonCurrencies as $code => $name):
        $active = in_array($code, $currencies);
    ?>
    <div class="col-6 d-flex align-items-center justify-content-between py-1 border-bottom">
        <span><?= $name ?> <code class="ms-1"><?= $code ?></code></span>
        <?php if ($active): ?>
        <span class="badge bg-success">✅ আছে</span>
        <?php else: ?>
        <button class="btn btn-xs btn-outline-primary py-0 px-1" style="font-size:.7rem"
            onclick="quickAdd('<?= $code ?>')">+ যোগ</button>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>
</div>
</div>

<?php elseif ($tab === 'alerts'): ?>
<div class="mb-3"><h5 class="fw-bold">🚨 স্ক্যাম অ্যালার্ট ও দেশ-ভিত্তিক নিউজ</h5></div>
<div class="row g-4">
    <div class="col-md-6">
        <div class="card p-4">
            <h6 class="fw-bold mb-3">⚠️ স্ক্যাম অ্যালার্ট</h6>
            <div class="mb-3">
                <label class="form-label">সতর্কতার বার্তা</label>
                <textarea id="scamMsg" class="form-control" rows="4" placeholder="সতর্কতার বিবরণ..."></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">দেশ (সব = খালি রাখুন)</label>
                <select id="scamCountries" class="form-select" multiple>
                    <?php foreach ($countries as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name_bn']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-danger" onclick="sendScamAlert()">🚨 অ্যালার্ট পাঠান</button>
            <span id="scamResult" class="ms-2"></span>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card p-4">
            <h6 class="fw-bold mb-3">🌍 দেশ-ভিত্তিক নিউজ</h6>
            <div class="mb-3">
                <label class="form-label">দেশ বেছে নিন</label>
                <select id="newsCountry" class="form-select">
                    <?php foreach ($countries as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name_bn']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">নিউজ / আপডেট</label>
                <textarea id="newsMsg" class="form-control" rows="4" placeholder="নতুন নিয়ম বা আপডেট..."></textarea>
            </div>
            <button class="btn btn-primary" onclick="sendNews()">📢 পাঠান</button>
            <span id="newsResult" class="ms-2"></span>
        </div>
    </div>
</div>
<?php endif; ?>
</div>

<!-- SERVICE MODAL -->
<div class="modal fade" id="serviceModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header"><h5 class="modal-title">সেবা যোগ/সম্পাদনা</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <input type="hidden" id="svc_id">
    <div class="row g-3">
        <div class="col-md-8"><label class="form-label">সেবার নাম *</label><input id="svc_name" class="form-control" placeholder="যেমন: সৌদি আরব ভিসা"></div>
        <div class="col-md-4"><label class="form-label">ক্যাটাগরি</label>
            <select id="svc_cat" class="form-select"><?php foreach($categories as $c): ?><option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option><?php endforeach; ?></select>
        </div>
        <div class="col-12"><label class="form-label">বিবরণ</label><textarea id="svc_desc" class="form-control" rows="3"></textarea></div>
        <div class="col-md-4"><label class="form-label">মূল্য</label><input id="svc_price" type="number" class="form-control" step="0.01" min="0" value="0"></div>
        <div class="col-md-4"><label class="form-label">কারেন্সি</label>
            <select id="svc_currency" class="form-select"><?php foreach($currencies as $cur): ?><option><?=$cur?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-4"><label class="form-label">সময়কাল</label><input id="svc_duration" class="form-control" placeholder="৭-১৫ কার্যদিবস"></div>
        <div class="col-md-6"><label class="form-label">কোন দেশের ভিসা</label>
            <select id="svc_from" class="form-select"><option value="">— নির্বাচন করুন —</option><?php foreach($countries as $c): ?><option value="<?=$c['id']?>"><?=htmlspecialchars($c['name_bn'])?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-6"><label class="form-label">কোন দেশের নাগরিকদের জন্য</label>
            <select id="svc_for" class="form-select"><option value="">— সবার জন্য —</option><?php foreach($countries as $c): ?><option value="<?=$c['id']?>"><?=htmlspecialchars($c['name_bn'])?></option><?php endforeach; ?></select>
        </div>
        <div class="col-12"><label class="form-label">প্রয়োজনীয় ডকুমেন্টস (প্রতিটি নতুন লাইনে)</label><textarea id="svc_docs" class="form-control" rows="4" placeholder="পাসপোর্ট (৬ মাস মেয়াদ)&#10;৩ কপি ছবি&#10;ব্যাংক স্টেটমেন্ট"></textarea></div>
        <div class="col-12 d-flex align-items-center gap-2">
            <input type="checkbox" id="svc_active" class="form-check-input" checked> <label class="form-check-label">চালু</label>
        </div>
    </div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button><button class="btn btn-primary" onclick="saveService()">সেভ করুন</button></div>
</div></div></div>

<!-- CATEGORY MODAL -->
<div class="modal fade" id="catModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">ক্যাটাগরি</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <input type="hidden" id="cat_id">
    <div class="mb-3"><label class="form-label">আইকন (Emoji)</label><input id="cat_icon" class="form-control" placeholder="✈️" maxlength="5"></div>
    <div class="mb-3"><label class="form-label">নাম</label><input id="cat_name" class="form-control" required></div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button><button class="btn btn-primary" onclick="saveCat()">সেভ</button></div>
</div></div></div>

<!-- AGENT MODAL -->
<div class="modal fade" id="agentModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">👤 এজেন্ট</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <input type="hidden" id="agent_id">
    <!-- Photo preview -->
    <div class="text-center mb-3">
        <div id="agentPhotoWrap" style="width:80px;height:80px;border-radius:50%;margin:0 auto;border:3px solid #dee2e6;overflow:hidden;background:#1a3c6e;display:flex;align-items:center;justify-content:center">
            <img id="agentPhotoImg" src="" alt="" style="width:100%;height:100%;object-fit:cover;display:none">
            <span id="agentInitialSpan" style="color:white;font-size:2rem;font-weight:bold">?</span>
        </div>
        <small class="text-muted d-block mt-1">প্রোফাইল ছবি</small>
    </div>
    <div class="mb-3"><label class="form-label">প্রোফাইল ছবির URL</label>
        <input id="agent_photo" class="form-control" placeholder="https://... ছবির সরাসরি লিংক"
               oninput="previewPhoto(this.value)">
        <small class="text-muted">Imgur, Google Drive (direct link), বা যেকোনো ছবির URL</small>
    </div>
    <div class="mb-3"><label class="form-label">নাম *</label><input id="agent_name" class="form-control" required oninput="updateInitial(this.value)"></div>
    <div class="row g-2">
        <div class="col-6"><label class="form-label">মোবাইল</label><input id="agent_mobile" class="form-control" placeholder="+971..."></div>
        <div class="col-6"><label class="form-label">WhatsApp</label><input id="agent_wa" class="form-control" placeholder="+971..."></div>
    </div>
    <div class="mb-3 mt-2"><label class="form-label">Telegram Chat ID</label><input id="agent_telegram" class="form-control" placeholder="123456789"></div>
    <div class="d-flex gap-2 align-items-center"><input type="checkbox" id="agent_active" class="form-check-input" checked><label>চালু রাখুন</label></div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button><button class="btn btn-primary" onclick="saveAgent()">💾 সেভ</button></div>
</div></div></div>

<!-- STATUS MODAL -->
<div class="modal fade" id="statusModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">স্ট্যাটাস</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <input type="hidden" id="st_id">
    <div class="row g-3">
        <div class="col-8"><label class="form-label">নাম (বাংলা)</label><input id="st_name" class="form-control" required></div>
        <div class="col-4"><label class="form-label">ক্রম</label><input id="st_order" type="number" class="form-control" value="1"></div>
        <div class="col-8"><label class="form-label">নাম (ইংরেজি)</label><input id="st_name_en" class="form-control"></div>
        <div class="col-4"><label class="form-label">রং</label><input id="st_color" type="color" class="form-control form-control-color" value="#28a745"></div>
        <div class="col-12"><label class="form-label">স্বয়ংক্রিয় নোটিফিকেশন বার্তা</label><textarea id="st_msg" class="form-control" rows="3" placeholder="স্ট্যাটাস পরিবর্তনে গ্রাহককে পাঠানো বার্তা"></textarea></div>
    </div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button><button class="btn btn-primary" onclick="saveStatus()">সেভ</button></div>
</div></div></div>

<!-- ONBOARDING MODAL -->
<div class="modal fade" id="onboardModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">অনবোর্ডিং ফিল্ড</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <input type="hidden" id="ob_id">
    <div class="row g-3">
        <div class="col-6"><label class="form-label">Field Key (ইংরেজি)</label><input id="ob_key" class="form-control" placeholder="passport_no"></div>
        <div class="col-6"><label class="form-label">ক্রম</label><input id="ob_order" type="number" class="form-control" value="1"></div>
        <div class="col-12"><label class="form-label">প্রশ্ন (বাংলা)</label><input id="ob_label_bn" class="form-control" placeholder="আপনার পাসপোর্ট নম্বর লিখুন:"></div>
        <div class="col-12"><label class="form-label">প্রশ্ন (ইংরেজি)</label><input id="ob_label_en" class="form-control" placeholder="Enter your passport number:"></div>
        <div class="col-12 d-flex gap-2 align-items-center"><input type="checkbox" id="ob_required" class="form-check-input" checked><label>বাধ্যতামূলক</label></div>
    </div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button><button class="btn btn-primary" onclick="saveOnboard()">সেভ</button></div>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = '<?= $csrf ?>';
async function api(data) {
    try {
        const r = await fetch('/admin/api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({...data, csrf_token: CSRF || csrf || ''})
        });
        if (!r.ok) {
            if (r.status === 403) { alert('Session মেয়াদ শেষ। পুনরায় লগইন করুন।'); location.reload(); }
            return {success: false};
        }
        const t = await r.text();
        try { return JSON.parse(t); } catch(e) { console.error('Non-JSON:', t.substring(0,100)); return {success:false}; }
    } catch(e) { console.error('API error:', e); return {success:false}; }
}
// ── Currency Management ────────────────────────────────────
let currentCurrencies = <?= json_encode(array_values($currencies)) ?>;

function renderCurrencies() {
    const list = document.getElementById('currencyList');
    if (!list) return;
    list.innerHTML = currentCurrencies.map(c =>
        `<span class="badge bg-primary fs-6 d-flex align-items-center gap-2" style="padding:.5rem .8rem">
            ${c}
            <button type="button" class="btn-close btn-close-white" style="font-size:.6rem"
                onclick="removeCurrency('${c}')"></button>
        </span>`
    ).join('');
}

async function saveCurrencies() {
    const r = await api({action:'save_settings', system_currencies: currentCurrencies.join(',')});
    const msg = document.getElementById('curMsg');
    if (msg) {
        msg.innerHTML = r.success
            ? '<div class="alert alert-success py-1">✅ সেভ হয়েছে!</div>'
            : '<div class="alert alert-danger py-1">❌ সমস্যা হয়েছে</div>';
        setTimeout(() => { if(msg) msg.innerHTML=''; location.reload(); }, 1500);
    }
}

function addCurrency() {
    const inp = document.getElementById('newCurInput');
    const code = inp.value.trim().toUpperCase();
    if (!code || code.length < 2 || code.length > 5) {
        document.getElementById('curMsg').innerHTML = '<div class="alert alert-warning py-1">সঠিক কোড দিন (২-৫ অক্ষর)</div>';
        return;
    }
    if (currentCurrencies.includes(code)) {
        document.getElementById('curMsg').innerHTML = '<div class="alert alert-info py-1">'+code+' ইতিমধ্যে আছে</div>';
        return;
    }
    currentCurrencies.push(code);
    inp.value = '';
    saveCurrencies();
}

function removeCurrency(code) {
    if (!confirm(code + ' মুদ্রা সরাবেন?')) return;
    currentCurrencies = currentCurrencies.filter(c => c !== code);
    saveCurrencies();
}

function quickAdd(code) {
    if (!currentCurrencies.includes(code)) {
        currentCurrencies.push(code);
        saveCurrencies();
    }
}

async function deleteItem(action, id) {
    if(!confirm('মুছে ফেলবেন?')) return;
    await api({action, id});
    location.reload();
}

// Service
let svcModal;
document.addEventListener('DOMContentLoaded', () => { svcModal = new bootstrap.Modal('#serviceModal'); });
function openServiceModal() { document.getElementById('svc_id').value=''; document.getElementById('svc_name').value=''; document.getElementById('svc_desc').value=''; document.getElementById('svc_price').value=0; document.getElementById('svc_docs').value=''; document.getElementById('svc_duration').value=''; document.getElementById('svc_active').checked=true; svcModal.show(); }
function editService(s) { document.getElementById('svc_id').value=s.id; document.getElementById('svc_name').value=s.name; document.getElementById('svc_desc').value=s.description||''; document.getElementById('svc_price').value=s.price; document.getElementById('svc_docs').value=s.docs_required||''; document.getElementById('svc_duration').value=s.duration||''; document.getElementById('svc_active').checked=s.is_active==1; document.getElementById('svc_cat').value=s.category_id||''; document.getElementById('svc_from').value=s.country_from_id||''; document.getElementById('svc_for').value=s.country_for_id||''; document.getElementById('svc_currency').value=s.currency||'BDT'; svcModal.show(); }
async function saveService() { const r = await api({action:'save_service', id:document.getElementById('svc_id').value, name:document.getElementById('svc_name').value, description:document.getElementById('svc_desc').value, price:document.getElementById('svc_price').value, currency:document.getElementById('svc_currency').value, category_id:document.getElementById('svc_cat').value, country_from_id:document.getElementById('svc_from').value, country_for_id:document.getElementById('svc_for').value, docs_required:document.getElementById('svc_docs').value, duration:document.getElementById('svc_duration').value, is_active:document.getElementById('svc_active').checked?1:0}); if(r.success){svcModal.hide();location.reload();} }

// Category
let catModal;
document.addEventListener('DOMContentLoaded', () => { catModal = new bootstrap.Modal('#catModal'); });
function openCatModal() { document.getElementById('cat_id').value=''; document.getElementById('cat_name').value=''; document.getElementById('cat_icon').value=''; catModal.show(); }
function editCat(c) { document.getElementById('cat_id').value=c.id; document.getElementById('cat_name').value=c.name; document.getElementById('cat_icon').value=c.icon||''; catModal.show(); }
async function saveCat() { const r = await api({action:'save_category', id:document.getElementById('cat_id').value, name:document.getElementById('cat_name').value, icon:document.getElementById('cat_icon').value}); if(r.success){catModal.hide();location.reload();} }

// Agent
let agentModal;
document.addEventListener('DOMContentLoaded', () => { agentModal = new bootstrap.Modal('#agentModal'); });
function openAgentModal() {
    ['agent_id','agent_name','agent_mobile','agent_telegram','agent_wa','agent_photo'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('agentInitialSpan').textContent='?';
    document.getElementById('agentPhotoImg').style.display='none';
    document.getElementById('agentInitialSpan').style.display='';
    document.getElementById('agent_active').checked=true;
    agentModal.show();
}
function editAgent(a) {
    document.getElementById('agent_id').value=a.id;
    document.getElementById('agent_name').value=a.name;
    document.getElementById('agent_mobile').value=a.mobile||'';
    document.getElementById('agent_telegram').value=a.telegram_id||'';
    document.getElementById('agent_wa').value=a.whatsapp_number||'';
    document.getElementById('agent_photo').value=a.photo_url||'';
    document.getElementById('agent_active').checked=a.is_active==1;
    updateInitial(a.name);
    if(a.photo_url) previewPhoto(a.photo_url);
    agentModal.show();
}
async function saveAgent() {
    const r = await api({
        action:'save_agent',
        id: document.getElementById('agent_id').value,
        name: document.getElementById('agent_name').value,
        mobile: document.getElementById('agent_mobile').value,
        telegram_id: document.getElementById('agent_telegram').value,
        whatsapp_number: document.getElementById('agent_wa').value,
        photo_url: document.getElementById('agent_photo').value,
        is_active: document.getElementById('agent_active').checked ? 1 : 0
    });
    if(r.success){ agentModal.hide(); location.reload(); }
}

function previewPhoto(url) {
    const img = document.getElementById('agentPhotoImg');
    const ini = document.getElementById('agentInitialSpan');
    if(url) {
        img.src = url;
        img.style.display = 'block';
        ini.style.display = 'none';
        img.onerror = () => { img.style.display='none'; ini.style.display=''; };
    } else {
        img.style.display = 'none';
        ini.style.display = '';
    }
}

function updateInitial(name) {
    const ini = document.getElementById('agentInitialSpan');
    ini.textContent = name ? name.charAt(0).toUpperCase() : '?';
}

// Status
let stModal;
document.addEventListener('DOMContentLoaded', () => { stModal = new bootstrap.Modal('#statusModal'); });
function openStatusModal() { document.getElementById('st_id').value=''; document.getElementById('st_name').value=''; document.getElementById('st_name_en').value=''; document.getElementById('st_msg').value=''; document.getElementById('st_order').value=1; document.getElementById('st_color').value='#28a745'; stModal.show(); }
function editStatus(s) { document.getElementById('st_id').value=s.id; document.getElementById('st_name').value=s.name; document.getElementById('st_name_en').value=s.name_en||''; document.getElementById('st_msg').value=s.notify_message||''; document.getElementById('st_order').value=s.order_no; document.getElementById('st_color').value=s.color||'#28a745'; stModal.show(); }
async function saveStatus() { const r = await api({action:'save_status', id:document.getElementById('st_id').value, name:document.getElementById('st_name').value, name_en:document.getElementById('st_name_en').value, color:document.getElementById('st_color').value, notify_message:document.getElementById('st_msg').value, order_no:document.getElementById('st_order').value}); if(r.success){stModal.hide();location.reload();} }

// Onboarding
let obModal;
document.addEventListener('DOMContentLoaded', () => { obModal = new bootstrap.Modal('#onboardModal'); });
function openOnboardModal() { document.getElementById('ob_id').value=''; ['ob_key','ob_label_bn','ob_label_en'].forEach(id=>document.getElementById(id).value=''); document.getElementById('ob_order').value=1; obModal.show(); }
function editOnboard(o) { document.getElementById('ob_id').value=o.id; document.getElementById('ob_key').value=o.field_key; document.getElementById('ob_label_bn').value=o.field_label_bn; document.getElementById('ob_label_en').value=o.field_label_en||''; document.getElementById('ob_order').value=o.order_no; document.getElementById('ob_required').checked=o.is_required==1; obModal.show(); }
async function saveOnboard() { const r = await api({action:'save_onboarding_field', id:document.getElementById('ob_id').value, field_key:document.getElementById('ob_key').value, field_label_bn:document.getElementById('ob_label_bn').value, field_label_en:document.getElementById('ob_label_en').value, order_no:document.getElementById('ob_order').value, is_required:document.getElementById('ob_required').checked?1:0}); if(r.success){obModal.hide();location.reload();} }

// Payment
async function savePayment() { const r = await api({action:'save_payment', customer_id:document.getElementById('pay_customer').value, application_id:document.getElementById('pay_app').value, total_amount:document.getElementById('pay_total').value, paid_amount:document.getElementById('pay_paid').value, currency:document.getElementById('pay_currency').value, description:document.getElementById('pay_desc').value}); document.getElementById('payMsg').innerHTML = r.success ? '<span class="text-success">✅ সেভ হয়েছে! Invoice পাঠানো হচ্ছে।</span>' : '<span class="text-danger">❌ সমস্যা</span>'; }

// Invoice Template
async function saveInvoiceTemplate() { const r = await api({action:'save_invoice_template', html_template:document.getElementById('invoiceTemplate').value}); document.getElementById('invMsg').innerHTML = r.success ? '<span class="text-success">✅ '+r.message+'</span>' : '<span class="text-danger">❌</span>'; }

// Scam Alert
async function sendScamAlert() { if(!confirm('সব গ্রাহককে সতর্কতা পাঠাবেন?')) return; const sel = Array.from(document.getElementById('scamCountries').selectedOptions).map(o=>o.value).join(','); const r = await api({action:'send_scam_alert', message:document.getElementById('scamMsg').value, country_ids:sel}); document.getElementById('scamResult').innerHTML = r.success ? '<span class="text-success">✅ '+r.sent+' জনকে পাঠানো হয়েছে</span>' : '<span class="text-danger">❌</span>'; }

// News
async function sendNews() { const r = await api({action:'send_country_news', message:document.getElementById('newsMsg').value, country_id:document.getElementById('newsCountry').value}); document.getElementById('newsResult').innerHTML = r.success ? `<span class="text-success">✅ ${r.country}-তে ${r.sent} জনকে পাঠানো হয়েছে</span>` : '<span class="text-danger">❌</span>'; }
</script>
</body>
</html>
