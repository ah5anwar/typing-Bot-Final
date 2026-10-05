<?php
// ============================================================
// admin/news.php - Embassy News + Broadcast Queue Management
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/security.php';
Security::requireAdminAuth();
Security::setSecurityHeaders();

$db  = Database::getInstance();
$tab = $_GET['tab'] ?? 'news';

// AJAX Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    $act = $_POST['action'];

    switch ($act) {
        case 'scrape_news':
            require_once dirname(__DIR__) . '/services/EmbassyNewsService.php';
            require_once dirname(__DIR__) . '/bot/Sender.php';
            $service = new EmbassyNewsService();
            $force   = isset($_POST['force']) && $_POST['force'] === '1';
            $result  = $service->scrapeNews($force);
            $found   = $result['found']   ?? 0;
            $errors  = $result['errors']  ?? [];
            $errMsg  = $result['error']   ?? '';
            $msg     = $result['message'] ?? '';
            if ($errMsg) {
                echo json_encode(['success'=>false, 'found'=>0, 'message'=>$errMsg]);
            } else {
                if (!$msg) $msg = $found > 0 ? $found.'টি নতুন আপডেট পাওয়া গেছে!' : 'কোনো নতুন আপডেট নেই।';
                if (!empty($errors)) $msg .= ' | সমস্যা: '.implode(', ',array_slice($errors,0,2));
                echo json_encode(['success'=>true, 'found'=>$found, 'message'=>$msg]);
            }
            break;

        case 'approve_news':
            require_once dirname(__DIR__) . '/services/EmbassyNewsService.php';
            require_once dirname(__DIR__) . '/bot/Sender.php';
            $service = new EmbassyNewsService();
            $result  = $service->approveAndSend((int)$_POST['news_id']);
            echo json_encode($result);
            break;

        case 'reject_news':
            $db->execute("UPDATE embassy_news SET status='rejected' WHERE id=?", [(int)$_POST['news_id']]);
            echo json_encode(['success' => true]);
            break;

        case 'create_broadcast':
            require_once dirname(__DIR__) . '/services/EmbassyNewsService.php';
            require_once dirname(__DIR__) . '/bot/Sender.php';
            $queue = new BroadcastQueue();
            $id    = $queue->create(
                $_POST['message'],
                $_POST['target_type'] ?? 'all',
                $_POST['country_id'] ?: null,
                $_POST['platform'] ?: null
            );
            echo json_encode(['success' => true, 'id' => $id, 'message' => "Broadcast #{$id} queue-এ যোগ হয়েছে"]);
            break;

        case 'add_payment_transaction':
            $payId  = (int)$_POST['payment_id'];
            $amount = (float)$_POST['amount'];
            $method = Security::sanitizeString($_POST['method'] ?? 'cash');
            $note   = Security::sanitizeString($_POST['note'] ?? '');

            $db->insert(
                "INSERT INTO payment_transactions (payment_id, amount, method, note) VALUES (?,?,?,?)",
                [$payId, $amount, $method, $note]
            );

            // Payment paid_amount আপডেট
            $db->execute(
                "UPDATE payments SET paid_amount = paid_amount + ? WHERE id = ?",
                [$amount, $payId]
            );

            // Notification পাঠানো
            require_once dirname(__DIR__) . '/services/NotificationEngine.php';
            require_once dirname(__DIR__) . '/bot/Sender.php';
            $engine = new NotificationEngine();
            $engine->onPaymentReceived($payId, $amount);

            echo json_encode(['success' => true, 'message' => 'পেমেন্ট যোগ হয়েছে']);
            break;

        case 'generate_agent_card':
            require_once dirname(__DIR__) . '/services/QRCodeService.php';
            require_once dirname(__DIR__) . '/vendor/phpqrcode/qrlib.php';
            $agent = $db->fetchOne("SELECT * FROM agents WHERE id=?", [(int)$_POST['agent_id']]);
            if ($agent) {
                $qr  = new QRCodeService();
                $url = $qr->generateAgentCard($agent);
                echo json_encode(['success' => true, 'url' => $url]);
            } else {
                echo json_encode(['success' => false]);
            }
            break;

        case 'generate_member_card':
            require_once dirname(__DIR__) . '/services/QRCodeService.php';
            require_once dirname(__DIR__) . '/vendor/phpqrcode/qrlib.php';
            $customerId = (int)$_POST['customer_id'];
            $qr  = new QRCodeService();
            $url = $qr->generateForCustomer($customerId);
            $customer = $db->fetchOne("SELECT * FROM customers WHERE id=?", [$customerId]);
            if ($customer) {
                require_once dirname(__DIR__) . '/bot/Sender.php';
                $qr->sendCardToCustomer($customer);
            }
            echo json_encode(['success' => !!$url, 'url' => $url]);
            break;

        case 'update_application_notes':
            $db->execute(
                "UPDATE applications SET admin_notes=?, expected_completion=? WHERE id=?",
                [Security::sanitizeString($_POST['notes'] ?? '', 2000), $_POST['expected_date'] ?: null, (int)$_POST['app_id']]
            );
            echo json_encode(['success' => true]);
            break;
    }
    exit;
}

$csrf = Security::generateCSRFToken();

// Data
try {
    $pendingNews = $db->fetchAll("SELECT * FROM embassy_news WHERE status='pending' ORDER BY scraped_at DESC");
    $sentNews    = $db->fetchAll("SELECT * FROM embassy_news WHERE status='sent' ORDER BY sent_at DESC LIMIT 20");
} catch (Exception $e) {
    Logger::error('news.php DB: ' . $e->getMessage());
    $pendingNews = []; $sentNews = [];
}
try {
    $broadcasts = $db->fetchAll("SELECT b.*, (SELECT COUNT(*) FROM broadcast_queue bq WHERE bq.broadcast_id=b.id AND bq.status='pending') as pending_count FROM broadcasts b ORDER BY b.created_at DESC LIMIT 20");
} catch (Exception $e) {
    Logger::error('news.php broadcasts: ' . $e->getMessage());
    $broadcasts = [];
}
try {
    $countries = $db->fetchAll("SELECT * FROM countries WHERE is_active=1 ORDER BY name_bn");
    $agents    = $db->fetchAll("SELECT * FROM agents WHERE is_active=1");
} catch (Exception $e) {
    $countries = []; $agents = [];
}
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
<?php $currentPage = 'news'; include __DIR__.'/sidebar.php'; ?>

<div class="main">

<?php if ($tab === 'news'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0">🌍 Embassy News</h4>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" onclick="scrapeNews(false)">
            <i class="fa fa-search"></i> নতুন নিউজ খুঁজুন
        </button>
        <button class="btn btn-outline-secondary" onclick="scrapeNews(true)">
            🔄 Force Refresh
        </button>
    </div>
</div>

<!-- Country Selection -->
<div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <strong class="small">📍 দেশ নির্বাচন করুন (যেসব দেশের নিউজ আনতে চান):</strong>
        <div>
            <button class="btn btn-xs btn-outline-secondary py-0 px-2" style="font-size:.75rem" onclick="selectAll(true)">সব বেছে নিন</button>
            <button class="btn btn-xs btn-outline-secondary py-0 px-2 ms-1" style="font-size:.75rem" onclick="selectAll(false)">সব বাদ দিন</button>
        </div>
    </div>
    <div class="row g-2">
        <?php
        // DB থেকে সব দেশ লোড করা (যেকোনো নতুন দেশ স্বয়ংক্রিয়ভাবে আসবে)
        $dbCountries = $db->fetchAll("SELECT code, name_bn, name_en, flag_emoji FROM countries WHERE is_active=1 ORDER BY name_bn");
        $newsCountries = [];
        foreach ($dbCountries as $co) {
            $flag = $co['flag_emoji'] ?: '🌍';
            $name = $co['name_bn'] ?: $co['name_en'];
            $newsCountries[$co['code']] = $flag . ' ' . $name;
        }
        // Fallback if DB empty
        if (empty($newsCountries)) {
            $newsCountries = [
                'AE'=>'🇦🇪 UAE', 'SA'=>'🇸🇦 সৌদি আরব', 'OM'=>'🇴🇲 ওমান',
                'QA'=>'🇶🇦 কাতার', 'KW'=>'🇰🇼 কুয়েত', 'BH'=>'🇧🇭 বাহরাইন',
                'MY'=>'🇲🇾 মালয়েশিয়া', 'SG'=>'🇸🇬 সিঙ্গাপুর',
            ];
        }
        $savedCodes = array_filter(explode(',', Config::get('news_countries', implode(',', array_keys($newsCountries)))));
        foreach ($newsCountries as $code => $label):
        ?>
        <div class="col-md-3 col-6">
            <label class="d-flex align-items-center gap-1 small">
                <input type="checkbox" class="country-check" value="<?= $code ?>"
                    <?= in_array($code, $savedCodes) ? 'checked' : '' ?>>
                <?= $label ?>
            </label>
        </div>
        <?php endforeach; ?>
    </div>
    <button class="btn btn-sm btn-outline-primary mt-2" onclick="saveCountries()">💾 দেশ সেভ করুন</button>
    <span id="countrySaveMsg" class="ms-2 small"></span>
</div>

<div id="scrapeResult" class="mb-3"></div>

<?php if (empty($pendingNews) && empty($sentNews)): ?>
<div class="alert alert-info">
    <strong>ℹ️ কোনো নিউজ নেই।</strong><br>
    "নতুন নিউজ খুঁজুন" বাটন চাপুন। Gemini AI দিয়ে ৮টি দেশের আপডেট সংগ্রহ করা হবে।<br>
    <small class="text-muted">আজকের নিউজ ইতিমধ্যে থাকলে Force Refresh করুন।</small>
    <div class="mt-2">
        <button class="btn btn-primary btn-sm" onclick="scrapeNews(false)">🔍 নিউজ খুঁজুন</button>
        <button class="btn btn-outline-secondary btn-sm ms-2" onclick="scrapeNews(true)">🔄 Force Refresh</button>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($pendingNews)): ?>
<h6 class="fw-bold text-warning mb-3">⏳ অনুমোদনের অপেক্ষায় (<?= count($pendingNews) ?>টি)</h6>
<?php foreach ($pendingNews as $news): ?>
<div class="card p-3 mb-3 news-card pending">
    <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
            <span class="badge bg-warning text-dark me-2"><?= htmlspecialchars($news['country_name']) ?></span>
            <small class="text-muted"><?= date('d/m/Y H:i', strtotime($news['scraped_at'])) ?></small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-success" onclick="approveNews(<?= $news['id'] ?>)">✅ অনুমোদন দিন ও পাঠান</button>
            <button class="btn btn-sm btn-outline-danger" onclick="rejectNews(<?= $news['id'] ?>)">❌</button>
        </div>
    </div>
    <p class="mb-1"><?= nl2br(htmlspecialchars($news['content'])) ?></p>
    <small class="text-muted">🔗 <a href="<?= htmlspecialchars($news['source_url']) ?>" target="_blank"><?= htmlspecialchars($news['source_url']) ?></a></small>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($sentNews)): ?>
<h6 class="fw-bold text-success mb-3 mt-4">✅ পাঠানো হয়েছে</h6>
<?php foreach (array_slice($sentNews, 0, 5) as $news): ?>
<div class="card p-3 mb-2 news-card sent">
    <div class="d-flex justify-content-between">
        <span><span class="badge bg-success me-2"><?= htmlspecialchars($news['country_name']) ?></span> <?= htmlspecialchars(mb_substr($news['content'], 0, 100)) ?>...</span>
        <small class="text-muted"><?= $news['sent_count'] ?> জনকে পাঠানো</small>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php elseif ($tab === 'broadcast'): ?>
<h4 class="fw-bold mb-4">📢 Broadcast Queue</h4>
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card p-4">
            <h6 class="fw-bold mb-3">নতুন Broadcast তৈরি</h6>
            <div class="mb-3">
                <label class="form-label">লক্ষ্য</label>
                <select id="bc_target" class="form-select" onchange="toggleCountry()">
                    <option value="all">সব গ্রাহক</option>
                    <option value="country">নির্দিষ্ট দেশ</option>
                    <option value="platform">নির্দিষ্ট Platform</option>
                </select>
            </div>
            <div id="country_select" class="mb-3 d-none">
                <label class="form-label">দেশ বেছে নিন</label>
                <select id="bc_country" class="form-select">
                    <option value="">— সব দেশ —</option>
                    <?php foreach ($countries as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name_bn']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="platform_select" class="mb-3 d-none">
                <label class="form-label">Platform</label>
                <select id="bc_platform" class="form-select">
                    <option value="telegram">Telegram</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="messenger">Messenger</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">মেসেজ</label>
                <textarea id="bc_message" class="form-control" rows="5" placeholder="মেসেজ লিখুন..."></textarea>
            </div>
            <button class="btn btn-primary" onclick="createBroadcast()">📋 Queue-এ যোগ করুন</button>
            <div id="bc_result" class="mt-2"></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-4">
            <h6 class="fw-bold mb-3">📊 Broadcast ইতিহাস</h6>
            <?php foreach ($broadcasts as $b): ?>
            <div class="d-flex justify-content-between align-items-center p-2 mb-2 bg-light rounded">
                <div>
                    <div class="small fw-semibold"><?= htmlspecialchars(mb_substr($b['message'], 0, 50)) ?>...</div>
                    <div class="text-muted" style="font-size:.7rem">
                        <?= date('d/m/Y H:i', strtotime($b['created_at'])) ?> |
                        <?php if ($b['status'] === 'sent'): ?>
                        <span class="text-success">✅ পাঠানো: <?= $b['sent_count'] ?></span>
                        <?php elseif ($b['status'] === 'sending'): ?>
                        <span class="text-warning">⏳ চলছে... বাকি: <?= $b['pending_count'] ?></span>
                        <?php else: ?>
                        <span class="text-info">🕐 Queue: <?= $b['pending_count'] ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge bg-<?= $b['status']==='sent'?'success':($b['status']==='sending'?'warning':'secondary') ?>"><?= $b['status'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php elseif ($tab === 'agents'): ?>
<h4 class="fw-bold mb-4">🪪 Agent Digital Visiting Card</h4>
<div class="row g-3">
    <?php foreach ($agents as $agent): ?>
    <div class="col-md-4">
        <div class="card p-3 text-center">
            <div style="font-size:2.5rem;margin-bottom:8px">👤</div>
            <h6 class="fw-bold"><?= htmlspecialchars($agent['name']) ?></h6>
            <div class="text-muted small mb-2"><?= htmlspecialchars($agent['mobile'] ?? '') ?></div>

            <?php if ($agent['qr_card_url']): ?>
            <a href="<?= htmlspecialchars($agent['qr_card_url']) ?>" target="_blank" class="btn btn-sm btn-outline-success mb-2">
                🔗 Card দেখুন
            </a>
            <?php endif; ?>

            <button class="btn btn-sm btn-primary" onclick="generateAgentCard(<?= $agent['id'] ?>)">
                🪪 <?= $agent['qr_card_url'] ? 'পুনরায় তৈরি' : 'Card তৈরি' ?>
            </button>
            <div id="agent_result_<?= $agent['id'] ?>" class="mt-2"></div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($agents)): ?>
    <div class="col-12 text-center text-muted py-5">
        এজেন্ট যোগ করুন → <a href="/admin/services.php?tab=agents">এজেন্ট ম্যানেজমেন্ট</a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

</div><!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = '<?= $csrf ?>';
const csrf = CSRF; // backward compat
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

function selectAll(checked) {
    document.querySelectorAll('.country-check').forEach(cb => cb.checked = checked);
}

async function saveCountries() {
    const codes = [...document.querySelectorAll('.country-check:checked')].map(cb => cb.value).join(',');
    const r = await api({action:'save_settings', news_countries: codes});
    document.getElementById('countrySaveMsg').textContent = r.success ? '✅ সেভ হয়েছে' : '❌ সমস্যা';
    setTimeout(() => document.getElementById('countrySaveMsg').textContent = '', 2000);
}

async function scrapeNews(force=false) {
    const codes = [...document.querySelectorAll('.country-check:checked')].map(cb => cb.value).join(',');
    if (!codes) { alert('অন্তত একটি দেশ বেছে নিন।'); return; }
    document.getElementById('scrapeResult').innerHTML = '<div class="alert alert-info">⏳ নিউজ খোঁজা হচ্ছে... (৩০-৬০ সেকেন্ড লাগতে পারে)</div>';
    const r = await api({action:'scrape_news', force: force ? '1' : '0', countries: codes});
    if (r.found > 0) {
        document.getElementById('scrapeResult').innerHTML = '<div class="alert alert-success">✅ ' + (r.message||r.found+'টি আপডেট পাওয়া গেছে!') + '</div>';
        setTimeout(() => location.reload(), 2000);
    } else if (!force && r.message && r.message.includes('ইতিমধ্যে')) {
        document.getElementById('scrapeResult').innerHTML = '<div class="alert alert-info">ℹ️ ' + r.message + ' <button class="btn btn-sm btn-warning ms-2" onclick="scrapeNews(true)">🔄 Force Refresh</button></div>';
    } else {
        document.getElementById('scrapeResult').innerHTML = '<div class="alert alert-warning">⚠️ ' + (r.message||'কোনো আপডেট পাওয়া যায়নি।') + '</div>';
    }
}

async function approveNews(id) {
    if(!confirm('এই নিউজটি সব গ্রাহককে পাঠাবেন?')) return;
    const btn = event.target; btn.disabled=true; btn.textContent='পাঠানো হচ্ছে...';
    const r = await api({action:'approve_news', news_id: id});
    if(r.success) { alert(`✅ ${r.sent} জনকে পাঠানো হয়েছে!`); location.reload(); }
    else { btn.disabled=false; btn.textContent='✅ অনুমোদন দিন ও পাঠান'; }
}

async function rejectNews(id) {
    if(!confirm('বাতিল করবেন?')) return;
    await api({action:'reject_news', news_id: id});
    location.reload();
}

function toggleCountry() {
    const val = document.getElementById('bc_target').value;
    document.getElementById('country_select').classList.toggle('d-none', val !== 'country');
    document.getElementById('platform_select').classList.toggle('d-none', val !== 'platform');
}

async function createBroadcast() {
    const msg = document.getElementById('bc_message').value;
    if (!msg) return alert('মেসেজ লিখুন');
    const target  = document.getElementById('bc_target').value;
    const country = document.getElementById('bc_country')?.value || '';
    const platform = document.getElementById('bc_platform')?.value || '';
    const r = await api({action:'create_broadcast', message:msg, target_type:target, country_id:country, platform});
    document.getElementById('bc_result').innerHTML = r.success
        ? `<div class="alert alert-success">✅ ${r.message}</div>`
        : '<div class="alert alert-danger">❌ সমস্যা হয়েছে</div>';
    if(r.success) document.getElementById('bc_message').value = '';
}

async function generateAgentCard(agentId) {
    document.getElementById('agent_result_'+agentId).innerHTML = '<small class="text-muted">তৈরি হচ্ছে...</small>';
    const r = await api({action:'generate_agent_card', agent_id:agentId});
    if(r.success) {
        document.getElementById('agent_result_'+agentId).innerHTML = `<a href="${r.url}" target="_blank" class="btn btn-sm btn-success w-100 mt-1">🔗 দেখুন</a>`;
    }
}
</script>
</body>
</html>
