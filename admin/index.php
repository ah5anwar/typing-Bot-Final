<?php
// ============================================================
// admin/index.php — FINAL FIXED VERSION
// NotificationEngine সংযুক্ত + Real-time chat polling + CSRF
// ============================================================

if (session_status() === PHP_SESSION_NONE) session_start();

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/security.php';
Security::setSecurityHeaders();

// ── Logout (GET request from sidebar) ─────────────────────────
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: /admin/');
    exit;
}

// ---- Login ----
if (!isset($_SESSION['admin_logged_in'])) {
    if ($_POST['username'] ?? false) {
        $u = Security::sanitizeString($_POST['username']);
        $p = $_POST['password'] ?? '';
        if ($u === Config::get('admin_username', 'admin') && password_verify($p, Config::get('admin_password'))) {
            $_SESSION['admin_logged_in']   = true;
            $_SESSION['admin_user']        = $u;
            $_SESSION['admin_last_activity'] = time();
            $_SESSION['csrf_token']        = bin2hex(random_bytes(32));
            header('Location: /admin/');
            exit;
        }
        $loginError = 'ভুল ইউজারনেম বা পাসওয়ার্ড!';
    }
    ?><!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/admin/admin.css">

<title>Admin Login — আমি প্রবাসী</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{
  min-height:100vh;
  display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#1A0B4B 0%,#0D1B55 40%,#0A2A7A 70%,#0C3A8A 100%);
  font-family:'Hind Siliguri',sans-serif;
  position:relative;overflow:hidden;
}
body::before{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at 20% 50%,rgba(108,59,255,.3) 0%,transparent 60%),
             radial-gradient(ellipse at 80% 20%,rgba(6,182,212,.2) 0%,transparent 50%),
             radial-gradient(ellipse at 60% 80%,rgba(14,165,233,.15) 0%,transparent 50%);
}
.orb{
  position:absolute;border-radius:50%;filter:blur(60px);animation:float 6s ease-in-out infinite;
}
.orb1{width:300px;height:300px;background:rgba(108,59,255,.2);top:-100px;left:-100px;animation-delay:0s}
.orb2{width:200px;height:200px;background:rgba(6,182,212,.15);bottom:50px;right:-50px;animation-delay:2s}
.orb3{width:150px;height:150px;background:rgba(14,165,233,.2);bottom:-50px;left:40%;animation-delay:4s}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-20px)}}
.login-box{
  position:relative;z-index:10;
  width:420px;
  background:rgba(255,255,255,.07);
  backdrop-filter:blur(20px);
  border:1px solid rgba(255,255,255,.15);
  border-radius:24px;
  padding:44px 40px;
  box-shadow:0 25px 60px rgba(0,0,0,.4);
}
.logo-ring{
  width:72px;height:72px;
  background:linear-gradient(135deg,#6C3BFF,#06B6D4);
  border-radius:20px;
  display:flex;align-items:center;justify-content:center;
  font-size:2rem;margin:0 auto 18px;
  box-shadow:0 8px 24px rgba(108,59,255,.5);
}
h1{
  font-family:'Sora',sans-serif;font-size:1.6rem;font-weight:800;
  color:#fff;text-align:center;margin-bottom:6px;letter-spacing:-.02em;
}
.subtitle{color:rgba(255,255,255,.5);font-size:.85rem;text-align:center;margin-bottom:30px}
label{display:block;font-size:.78rem;font-weight:600;color:rgba(255,255,255,.7);margin-bottom:7px;letter-spacing:.04em;text-transform:uppercase}
input{
  width:100%;padding:13px 16px;
  background:rgba(255,255,255,.08);
  border:1.5px solid rgba(255,255,255,.15);
  border-radius:12px;color:#fff;font-size:.9rem;
  font-family:'Hind Siliguri',sans-serif;
  transition:all .2s;outline:none;
}
input:focus{border-color:rgba(108,59,255,.8);background:rgba(108,59,255,.1);box-shadow:0 0 0 3px rgba(108,59,255,.2)}
input::placeholder{color:rgba(255,255,255,.25)}
.mb{margin-bottom:18px}
.btn-login{
  width:100%;padding:14px;margin-top:8px;
  background:linear-gradient(135deg,#6C3BFF,#0EA5E9);
  border:none;border-radius:12px;
  color:#fff;font-size:.95rem;font-weight:600;
  font-family:'Sora',sans-serif;cursor:pointer;
  transition:all .2s;
  box-shadow:0 8px 24px rgba(108,59,255,.4);
  letter-spacing:.02em;
}
.btn-login:hover{transform:translateY(-2px);box-shadow:0 12px 32px rgba(108,59,255,.5)}
.btn-login:active{transform:translateY(0)}
.alert-box{
  padding:11px 14px;border-radius:10px;
  font-size:.83rem;margin-bottom:20px;
  display:flex;align-items:center;gap:8px;
}
.alert-danger{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#FCA5A5}
.alert-warning{background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.3);color:#FDE68A}
.footer-text{margin-top:24px;text-align:center;font-size:.75rem;color:rgba(255,255,255,.25)}
</style>

</head>
<body>
<div class="orb orb1"></div>
<div class="orb orb2"></div>
<div class="orb orb3"></div>
<div class="login-box">
  <div class="logo-ring">🤖</div>
  <h1>Admin Panel</h1>
  <div class="subtitle"><?= htmlspecialchars(Config::get('company_name','আমি প্রবাসী.bd')) ?></div>

  <?php if (isset($loginError)): ?>
  <div class="alert-box alert-danger">⚠️ <?= htmlspecialchars($loginError) ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['timeout'])): ?>
  <div class="alert-box alert-warning">⏱️ Session শেষ। আবার লগইন করুন।</div>
  <?php endif; ?>

  <form method="POST">
    <div class="mb">
      <label>ইউজারনেম</label>
      <input name="username" type="text" placeholder="admin" required autofocus autocomplete="username">
    </div>
    <div class="mb">
      <label>পাসওয়ার্ড</label>
      <input name="password" type="password" placeholder="••••••••" required autocomplete="current-password">
    </div>
    <button type="submit" class="btn-login">লগইন করুন →</button>
  </form>
  <div class="footer-text">আমি প্রবাসী.bd © <?= date('Y') ?></div>
</div>
</body></html>
    <?php exit;
}

Security::requireAdminAuth();
// Ensure CSRF token is always fresh in session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ---- AJAX ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // CSRF check (chat polling exempted)
    $exemptActions = ['get_chat_messages', 'get_new_messages'];
    if (!in_array($_POST['action'], $exemptActions) && !Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    $db  = Database::getInstance();
    $act = $_POST['action'];

    switch ($act) {
        // ---- Settings ----
        case 'save_settings':
            $keys = ['bot_name','welcome_message','system_prompt','gemini_api_key','gemini_model',
                     'openrouter_api_key','openrouter_model','ai_provider','telegram_token',
                     'whatsapp_token','whatsapp_phone_id','whatsapp_verify_token',
                     'whatsapp_app_secret','messenger_page_token','messenger_verify_token',
                     'admin_telegram_group','company_name','company_phone','company_address','timezone',
                     'office_hours_start','office_hours_end','office_days',
                     'google_drive_folder_id','google_service_account_json','currency_api_key'];
            foreach ($keys as $k) {
                if (array_key_exists($k, $_POST)) Config::set($k, $_POST[$k]);
            }
            $toggles = ['faq_internet_answer','faq_service_only_mode','onboarding_custom','multilanguage_enabled','voice_enabled',
                        'document_upload_enabled','reminder_enabled','broadcast_enabled','payment_reminder_enabled',
                        'smart_notification_enabled','qr_code_enabled','payment_tracker_enabled','ocr_enabled',
                        'invoice_enabled','expiry_countdown_enabled','service_menu_enabled',
                        'currency_alert_enabled','salary_calculator_enabled','sos_enabled','fake_doc_detector_enabled',
                        'digital_locker_enabled','scam_alert_enabled','office_hours_enabled','ai_fallback_enabled'];
            foreach ($toggles as $t) Config::set($t, isset($_POST[$t]) ? '1' : '0');
            echo json_encode(['success' => true, 'message' => '✅ সেটিংস সেভ হয়েছে!']);
            break;

        // ---- FAQ ----
        case 'save_faq':
            $id = (int)($_POST['id'] ?? 0);
            $q  = Security::sanitizeString($_POST['question'] ?? '', 1000);
            $a  = Security::sanitizeString($_POST['answer'] ?? '', 2000);
            $kw = Security::sanitizeString($_POST['keywords'] ?? '', 500);
            if ($id) $db->execute("UPDATE faqs SET question=?,answer=?,keywords=? WHERE id=?", [$q,$a,$kw,$id]);
            else     $id = $db->insert("INSERT INTO faqs(question,answer,keywords) VALUES(?,?,?)", [$q,$a,$kw]);
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'delete_faq':
            $db->execute("DELETE FROM faqs WHERE id=?", [(int)$_POST['id']]);
            echo json_encode(['success' => true]);
            break;

        case 'toggle_faq':
            $db->execute("UPDATE faqs SET is_active=!is_active WHERE id=?", [(int)$_POST['id']]);
            echo json_encode(['success' => true]);
            break;

        // ---- Application Status (with NotificationEngine) ----
        case 'update_application_status':
            $appId    = (int)$_POST['app_id'];
            $statusId = (int)$_POST['status_id'];
            $notes    = Security::sanitizeString($_POST['admin_notes'] ?? '', 2000);

            $db->execute(
                "UPDATE applications SET status_id=?, admin_notes=?, updated_at=NOW() WHERE id=?",
                [$statusId, $notes, $appId]
            );

            // Smart Notification
            if (Config::isEnabled('smart_notification_enabled')) {
                require_once dirname(__DIR__) . '/services/NotificationEngine.php';
                require_once dirname(__DIR__) . '/bot/Sender.php';
                $engine = new NotificationEngine();
                $engine->onStatusChange($appId, $statusId);
            }

            echo json_encode(['success' => true, 'message' => 'স্ট্যাটাস আপডেট ও নোটিফিকেশন পাঠানো হয়েছে!']);
            break;

        // ---- Broadcast ----
        case 'send_broadcast':
            if (!Config::isEnabled('broadcast_enabled')) {
                echo json_encode(['success' => false, 'message' => 'Broadcast disabled']);
                break;
            }
            require_once dirname(__DIR__) . '/services/EmbassyNewsService.php';
            require_once dirname(__DIR__) . '/bot/Sender.php';

            $msg    = Security::sanitizeString($_POST['message'] ?? '', 2000);
            $target = $_POST['target_type'] ?? 'all';
            $cid    = $_POST['country_id'] ?: null;
            $plat   = $_POST['target_platform'] ?? null;

            $queue = new BroadcastQueue();
            $id    = $queue->create($msg, $target, $cid ? (int)$cid : null, $plat);

            echo json_encode(['success' => true, 'message' => "Broadcast #{$id} queue-এ যোগ হয়েছে। Cron Job চালালে পাঠানো শুরু হবে।"]);
            break;

        // ---- Chat Reply ----
        case 'send_chat_reply':
            require_once dirname(__DIR__) . '/bot/Sender.php';
            $cid = (int)$_POST['customer_id'];
            $msg = Security::sanitizeString($_POST['message'] ?? '', 1000);
            $c   = $db->fetchOne("SELECT * FROM customers WHERE id=?", [$cid]);
            if ($c && $msg) {
                (new Sender())->send($c['platform'], $c['platform_id'], $msg);
                $db->execute("INSERT INTO messages(customer_id,platform,direction,message_type,content) VALUES(?,?,?,?,?)",
                    [$cid, $c['platform'], 'out', 'text', $msg]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false]);
            }
            break;

        // ---- Chat Mode Toggle ----
        case 'toggle_chat_mode':
            $cid  = (int)$_POST['customer_id'];
            $mode = $_POST['mode'] === 'human' ? 'human' : 'bot';
            $db->execute("UPDATE chat_sessions SET mode=? WHERE customer_id=? ORDER BY started_at DESC LIMIT 1", [$mode, $cid]);
            echo json_encode(['success' => true, 'mode' => $mode]);
            break;

        // ---- Real-time Chat Polling ----
        case 'get_new_messages':
            $cid       = (int)$_POST['customer_id'];
            $lastId    = (int)$_POST['last_id'];
            $messages  = $db->fetchAll(
                "SELECT * FROM messages WHERE customer_id=? AND id>? ORDER BY sent_at ASC LIMIT 30",
                [$cid, $lastId]
            );
            echo json_encode(['success' => true, 'messages' => $messages]);
            break;

        // ---- Payment Transaction Add ----
        case 'add_payment_transaction':
            $payId  = (int)$_POST['payment_id'];
            $amount = (float)$_POST['amount'];
            $method = Security::sanitizeString($_POST['method'] ?? 'cash');
            $note   = Security::sanitizeString($_POST['note'] ?? '');

            $db->insert("INSERT INTO payment_transactions(payment_id,amount,method,note) VALUES(?,?,?,?)", [$payId,$amount,$method,$note]);
            $db->execute("UPDATE payments SET paid_amount=paid_amount+? WHERE id=?", [$amount,$payId]);

            if (Config::isEnabled('smart_notification_enabled')) {
                require_once dirname(__DIR__) . '/services/NotificationEngine.php';
                require_once dirname(__DIR__) . '/bot/Sender.php';
                (new NotificationEngine())->onPaymentReceived($payId, $amount);
            }
            echo json_encode(['success' => true, 'message' => "পেমেন্ট যোগ হয়েছে ও গ্রাহককে জানানো হয়েছে!"]);
            break;

        case 'logout':
            session_destroy();
            header('Location: /admin/');
            exit;
    }
    exit;
}

// ---- Page Data ----
$db      = Database::getInstance();
$page    = $_GET['page'] ?? 'dashboard';
$stats   = [
    'customers'    => $db->fetchOne("SELECT COUNT(*) as c FROM customers")['c'] ?? 0,
    'today_msgs'   => $db->fetchOne("SELECT COUNT(*) as c FROM messages WHERE DATE(sent_at)=CURDATE() AND direction='in'")['c'] ?? 0,
    'pending_apps' => $db->fetchOne("SELECT COUNT(*) as c FROM applications WHERE status_id=1")['c'] ?? 0,
    'total_income' => $db->fetchOne("SELECT COALESCE(SUM(paid_amount),0) as c FROM payments")['c'] ?? 0,
    'total_due'    => $db->fetchOne("SELECT COALESCE(SUM(balance),0) as c FROM payments WHERE balance>0")['c'] ?? 0,
    'gemini_today'     => $db->count("SELECT COUNT(*) as c FROM ai_usage_logs WHERE provider='gemini' AND DATE(created_at)=CURDATE()"),
    'openrouter_today' => $db->count("SELECT COUNT(*) as c FROM ai_usage_logs WHERE provider='openrouter' AND DATE(created_at)=CURDATE()"),
    'current_ai'       => Config::get('current_ai_provider','gemini'),
    'pending_news' => $db->fetchOne("SELECT COUNT(*) as c FROM embassy_news WHERE status='pending'")['c'] ?? 0,
];
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel — <?= htmlspecialchars(Config::get('company_name', 'Bot Admin')) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/admin/admin.css">
<style>
/* ── Chat ── */
.chat-window{height:420px;overflow-y:auto;padding:12px;background:#F0F4FF;border-radius:10px;display:flex;flex-direction:column;gap:8px;}
.msg-in{display:flex;justify-content:flex-start;}
.msg-out{display:flex;justify-content:flex-end;}
.msg-bubble{max-width:72%;padding:9px 13px;border-radius:16px;font-size:.875rem;line-height:1.5;position:relative;}
.msg-in .msg-bubble{background:#fff;border-radius:4px 16px 16px 16px;box-shadow:0 1px 4px rgba(0,0,0,.08);color:#1A1035;}
.msg-out .msg-bubble{background:linear-gradient(135deg,#6366F1,#06B6D4);border-radius:16px 4px 16px 16px;color:#fff;}
.msg-time{font-size:.68rem;opacity:.6;margin-top:4px;text-align:right;}
.msg-in .msg-time{text-align:left;}

/* ── Platform badges ── */
.plt-telegram{background:#29B6F6;color:#fff;font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:600;}
.plt-whatsapp{background:#25D366;color:#fff;font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:600;}
.plt-messenger{background:#0084FF;color:#fff;font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:600;}
.plt-default{background:#6366F1;color:#fff;font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:600;}
</style>
</head>
<body>

<?php $currentPage = $page ?? 'dashboard'; include __DIR__.'/sidebar.php'; ?>

<div class="main">

<?php if ($page === 'dashboard'): ?>
<!-- ===== DASHBOARD ===== -->
<h4 class="fw-bold mb-4">📊 ড্যাশবোর্ড</h4>
<div class="row g-3 mb-4">
<?php
$cards = [
    ['মোট গ্রাহক',    $stats['customers'],               '#1a3c6e', 'fa-users'],
    ['আজকের মেসেজ',  $stats['today_msgs'],              '#2e86c1', 'fa-comment'],
    ['পেন্ডিং আবেদন',$stats['pending_apps'],            '#e67e22', 'fa-clock'],
    ['মোট আয়',       number_format($stats['total_income']), '#27ae60', 'fa-coins'],
    ['মোট বকেয়া',    number_format($stats['total_due']), '#e74c3c', 'fa-exclamation-circle'],
    ['Pending News',  $stats['pending_news'],            '#8e44ad', 'fa-newspaper'],
];
foreach ($cards as $c): ?>
<div class="col-xl-2 col-md-4 col-6">
    <div class="stat-card">
        <div class="text-muted small mb-1"><?= $c[0] ?></div>
        <div class="fw-bold fs-4" style="color:<?= $c[2] ?>"><?= $c[1] ?></div>
        <i class="fa <?= $c[3] ?> mt-1" style="color:<?= $c[2] ?>;opacity:.4;font-size:1.2rem"></i>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- AI Widget -->
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0">🤖 AI ব্যবহার (আজ)</h6>
                <a href="/admin/customers.php?tab=ai" class="btn btn-sm btn-outline-primary">বিস্তারিত</a>
            </div>
            <div class="d-flex gap-3">
                <div class="flex-grow-1 text-center p-2 rounded <?= $stats['current_ai']==='gemini'?'border border-success bg-light':'' ?>">
                    <?php if($stats['current_ai']==='gemini'): ?><div class="badge bg-success mb-1" style="font-size:.65rem">▶ Active</div><br><?php endif; ?>
                    <div class="fw-bold fs-4 text-primary"><?= $stats['gemini_today'] ?></div>
                    <div class="small text-muted">Google Gemini</div>
                </div>
                <div class="flex-grow-1 text-center p-2 rounded <?= $stats['current_ai']==='openrouter'?'border border-success bg-light':'' ?>">
                    <?php if($stats['current_ai']==='openrouter'): ?><div class="badge bg-success mb-1" style="font-size:.65rem">▶ Active</div><br><?php endif; ?>
                    <div class="fw-bold fs-4 text-warning"><?= $stats['openrouter_today'] ?></div>
                    <div class="small text-muted">OpenRouter</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card p-3">
            <h6 class="fw-bold mb-2">⚡ দ্রুত লিংক</h6>
            <div class="d-flex flex-wrap gap-2">
                <a href="/admin/customers.php" class="btn btn-sm btn-outline-primary">👥 গ্রাহক</a>
                <a href="/admin/customers.php?tab=countries" class="btn btn-sm btn-outline-secondary">🌍 দেশ</a>
                <a href="/admin/customers.php?tab=menu" class="btn btn-sm btn-outline-info">📱 Bot মেনু</a>
                <a href="/admin/customers.php?tab=ai" class="btn btn-sm btn-outline-warning">🤖 AI Stats</a>
                <a href="/admin/news.php" class="btn btn-sm btn-outline-success">📰 News</a>
                <a href="/admin/reports.php" class="btn btn-sm btn-outline-dark">📊 রিপোর্ট</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <div class="fw-bold mb-3">📨 সর্বশেষ মেসেজ</div>
            <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead><tr><th>গ্রাহক</th><th>মেসেজ</th><th>Platform</th><th>সময়</th></tr></thead>
                <tbody>
                <?php foreach ($db->fetchAll("SELECT m.*, c.name, c.mobile FROM messages m LEFT JOIN customers c ON m.customer_id=c.id WHERE m.direction='in' ORDER BY m.sent_at DESC LIMIT 10") as $m): ?>
                <tr>
                    <td><?= htmlspecialchars($m['name'] ?? '?') ?><br><small class="text-muted"><?= htmlspecialchars($m['mobile'] ?? '') ?></small></td>
                    <td><?= htmlspecialchars(mb_substr($m['content'] ?? '', 0, 60)) ?></td>
                    <td><span class="badge bg-secondary"><?= strtoupper($m['platform']) ?></span></td>
                    <td><small><?= date('d/m H:i', strtotime($m['sent_at'])) ?></small></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <div class="fw-bold mb-3">🔔 সর্বশেষ আবেদন</div>
            <?php foreach ($db->fetchAll("SELECT a.tracking_id, c.name, st.name as sn, st.color FROM applications a LEFT JOIN customers c ON a.customer_id=c.id LEFT JOIN application_statuses st ON a.status_id=st.id ORDER BY a.created_at DESC LIMIT 5") as $a): ?>
            <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                <div>
                    <div class="small fw-semibold"><?= htmlspecialchars($a['name'] ?? '?') ?></div>
                    <code class="small text-muted"><?= htmlspecialchars($a['tracking_id']) ?></code>
                </div>
                <span class="badge" style="background:<?= htmlspecialchars($a['color'] ?? '#ccc') ?>;font-size:.65rem"><?= htmlspecialchars($a['sn'] ?? '—') ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php elseif ($page === 'customers'): ?>
<?php
// গ্রাহক পেজ → customers.php-এ redirect
header('Location: /admin/customers.php');
exit;
?>
<?php elseif ($page === 'applications'): ?>
<!-- ===== APPLICATIONS ===== -->
<?php
$allStatuses = $db->fetchAll("SELECT * FROM application_statuses ORDER BY order_no");
$allApps = $db->fetchAll("SELECT a.*, c.name, c.mobile, s.name as sn, st.name as stname, st.color,
    COALESCE(SUM(p.total_amount),0) as total_amt,
    COALESCE(SUM(p.paid_amount),0) as paid_amt
    FROM applications a
    LEFT JOIN customers c ON a.customer_id=c.id
    LEFT JOIN services s ON a.service_id=s.id
    LEFT JOIN application_statuses st ON a.status_id=st.id
    LEFT JOIN payments p ON p.application_id=a.id
    GROUP BY a.id ORDER BY a.created_at DESC");
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold">📋 আবেদন ম্যানেজমেন্ট (<?= count($allApps) ?>টি)</h4>
    <input type="text" id="appSearch" class="form-control w-auto" placeholder="সার্চ..." style="width:200px!important" oninput="filterApps(this.value)">
</div>
<div class="card p-3">
    <div class="table-responsive">
    <table class="table table-hover table-sm" id="appTable">
        <thead><tr><th>ট্র্যাকিং</th><th>গ্রাহক</th><th>সেবা</th><th>অবস্থা</th><th>💰 বিলিং</th><th>স্ট্যাটাস</th><th>নোট</th><th>তারিখ</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($allApps as $app):
            $due = (float)$app['total_amt'] - (float)$app['paid_amt'];
        ?>
        <tr>
            <td><code class="small"><?= htmlspecialchars($app['tracking_id']) ?></code></td>
            <td class="small"><?= htmlspecialchars($app['name'] ?? '—') ?><br><span class="text-muted"><?= htmlspecialchars($app['mobile'] ?? '') ?></span></td>
            <td class="small"><?= htmlspecialchars($app['sn'] ?? '—') ?></td>
            <td><span class="badge" style="background:<?= htmlspecialchars($app['color'] ?? '#6c757d') ?>"><?= htmlspecialchars($app['stname'] ?? '—') ?></span></td>
            <td class="small">
                <?php if ($app['total_amt'] > 0): ?>
                <div>মোট: <strong><?= number_format($app['total_amt'],0) ?></strong></div>
                <div>জমা: <span class="text-success"><?= number_format($app['paid_amt'],0) ?></span></div>
                <?php if ($due > 0): ?>
                <div>বকেয়া: <span class="text-danger fw-bold"><?= number_format($due,0) ?></span></div>
                <?php else: ?>
                <span class="badge bg-success">পরিশোধ ✅</span>
                <?php endif; ?>
                <?php else: ?>
                <button class="btn btn-xs btn-outline-primary py-0 px-1 small" onclick="addPaymentForApp(<?= $app['id'] ?>,<?= $app['customer_id'] ?>)">+ বিল</button>
                <?php endif; ?>
            </td>
            <td>
                <select class="form-select form-select-sm" style="width:130px" onchange="updateStatus(<?= $app['id'] ?>, this.value)">
                    <?php foreach ($allStatuses as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $s['id'] == $app['status_id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" class="form-control form-control-sm" style="width:120px" value="<?= htmlspecialchars($app['admin_notes'] ?? '') ?>" onblur="saveNotes(<?= $app['id'] ?>, this.value)" placeholder="নোট..."></td>
            <td class="small"><?= date('d/m/Y', strtotime($app['created_at'])) ?></td>
            <td>
                <button class="btn btn-sm btn-outline-danger py-0" onclick="deleteApp(<?= $app['id'] ?>, '<?= htmlspecialchars($app['tracking_id']) ?>')">🗑️</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Payment Modal for Application -->
<div class="modal fade" id="appPayModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5>💰 বিল যোগ করুন</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <input type="hidden" id="payAppId"><input type="hidden" id="payCustId">
    <div class="row g-3">
        <div class="col-6"><label>মোট টাকা</label><input id="payTotal" type="number" class="form-control" placeholder="5000"></div>
        <div class="col-6"><label>জমা দেওয়া</label><input id="payPaid" type="number" class="form-control" placeholder="2000"></div>
        <div class="col-4"><label>মুদ্রা</label>
        <select id="payCur" class="form-select">
        <?php foreach($sysCurrencies as $cur): ?><option value="<?=htmlspecialchars($cur)?>" <?=$cur==='BDT'?'selected':''?>><?=htmlspecialchars($cur)?></option><?php endforeach; ?>
        </select></div>
        <div class="col-8"><label>বিবরণ</label><input id="payDesc" class="form-control" placeholder="প্রথম কিস্তি..."></div>
    </div>
    <div id="payMsg" class="mt-2"></div>
</div>
<div class="modal-footer">
    <button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button>
    <button class="btn btn-primary" onclick="saveAppPayment()">💾 সেভ</button>
</div>
</div></div></div>

<?php elseif ($page === 'chat'): ?>
<!-- ===== LIVE CHAT ===== -->
<h4 class="fw-bold mb-4">💬 Live Chat</h4>
<div class="row g-3">
    <div class="col-md-4">
        <div class="card p-2" style="height:600px;overflow-y:auto">
            <div class="fw-semibold mb-2 px-2 py-1">গ্রাহক তালিকা</div>
            <?php
            $selCid = (int)($_GET['cid'] ?? 0);
            $chatCustomers = $db->fetchAll(
                "SELECT c.*, cs.mode,
                 (SELECT content FROM messages WHERE customer_id=c.id AND direction='in' ORDER BY sent_at DESC LIMIT 1) as last_msg,
                 (SELECT id FROM messages WHERE customer_id=c.id ORDER BY sent_at DESC LIMIT 1) as last_msg_id,
                 (SELECT COUNT(*) FROM messages WHERE customer_id=c.id AND direction='in' AND sent_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)) as recent_count
                 FROM customers c LEFT JOIN chat_sessions cs ON cs.customer_id=c.id AND cs.id=(SELECT MAX(id) FROM chat_sessions WHERE customer_id=c.id)
                 WHERE c.onboarding_done=1 ORDER BY c.last_message_at DESC"
            );
            foreach ($chatCustomers as $c):
                $isActive = $selCid == $c['id'];
            ?>
            <a href="?page=chat&cid=<?= $c['id'] ?>" class="d-block p-2 rounded mb-1 text-decoration-none <?= $isActive ? 'bg-primary text-white' : 'bg-light' ?>">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-semibold small"><?= htmlspecialchars($c['name'] ?? '?') ?></span>
                    <div class="d-flex gap-1 align-items-center">
                        <?php if ($c['recent_count'] > 0): ?><span class="badge bg-danger" style="font-size:.6rem"><?= $c['recent_count'] ?></span><?php endif; ?>
                        <span class="badge <?= ($c['mode']??'bot')==='human'?'bg-danger':'bg-secondary' ?>" style="font-size:.6rem"><?= ($c['mode']??'bot')==='human'?'Live':'Bot' ?></span>
                    </div>
                </div>
                <div class="small <?= $isActive?'text-white-50':'text-muted' ?>"><?= htmlspecialchars(mb_substr($c['last_msg'] ?? '', 0, 35)) ?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="col-md-8">
    <?php if ($selCid): ?>
    <?php
    $selCustomer = $db->fetchOne("SELECT * FROM customers WHERE id=?", [$selCid]);
    $messages    = $db->fetchAll("SELECT * FROM messages WHERE customer_id=? ORDER BY sent_at DESC LIMIT 80", [$selCid]);
    $messages    = array_reverse($messages);
    $session     = $db->fetchOne("SELECT * FROM chat_sessions WHERE customer_id=? ORDER BY started_at DESC LIMIT 1", [$selCid]);
    $lastMsgId   = !empty($messages) ? end($messages)['id'] : 0;
    ?>
    <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <strong><?= htmlspecialchars($selCustomer['name'] ?? '?') ?></strong>
                <span class="text-muted small ms-2"><?= htmlspecialchars($selCustomer['mobile'] ?? '') ?></span>
                <?php
    $plt2 = strtolower($selCustomer['platform'] ?? '');
    $cls2 = 'plt-' . (in_array($plt2,['telegram','whatsapp','messenger']) ? $plt2 : 'default');
?><span class="<?= $cls2 ?> ms-1"><?= strtoupper($plt2) ?></span>
            </div>
            <div class="d-flex gap-2">
                <button id="modeBtn" class="btn btn-sm <?= ($session['mode']??'bot')==='human'?'btn-danger':'btn-success' ?>"
                        onclick="toggleChatMode(<?= $selCid ?>, '<?= ($session['mode']??'bot')==='human'?'bot':'human' ?>')">
                    <?= ($session['mode']??'bot')==='human'?'🤖 Bot Mode':'👤 Human Mode' ?>
                </button>
            </div>
        </div>

        <div class="chat-window" id="chatWindow">
            <?php foreach ($messages as $msg): ?>
            <div class="<?= $msg['direction']==='out'?'msg-out':'msg-in' ?>">
                <div class="msg-bubble">
                    <?= nl2br(htmlspecialchars($msg['content'] ?? '')) ?>
                    <div class="msg-time"><?= date('H:i', strtotime($msg['sent_at'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-3">
            <div class="d-flex gap-2 mb-2">
                <textarea id="replyText" class="form-control" rows="2"
                    placeholder="মেসেজ লিখুন... (Ctrl+Enter পাঠান)"></textarea>
                <button class="btn btn-primary px-3" onclick="sendReply(<?= $selCid ?>)">পাঠান</button>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label class="btn btn-sm btn-outline-secondary mb-0" style="cursor:pointer">
                    📎 ফাইল পাঠান
                    <input type="file" id="chatFileInput" style="display:none"
                        accept="image/*,.pdf,.doc,.docx"
                        onchange="sendFileToCustomer(<?= $selCid ?>, this)">
                </label>
                <span id="fileStatus" class="small text-muted"></span>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card p-5 text-center text-muted">বাম থেকে একজন গ্রাহক সিলেক্ট করুন</div>
    <?php endif; ?>
    </div>
</div>

<?php elseif ($page === 'faqs'): ?>
<!-- ===== FAQs ===== -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold">❓ FAQ ম্যানেজমেন্ট</h4>
    <button class="btn btn-primary" onclick="openFaqModal()">+ নতুন FAQ</button>
</div>
<div class="card p-3">
    <p class="text-muted small mb-3">বট প্রথমে এখান থেকে উত্তর দেবে। Internet উত্তর Settings থেকে চালু/বন্ধ করুন।</p>
    <div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th style="width:38%">প্রশ্ন</th><th>উত্তর</th><th>Keywords</th><th>চালু</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($db->fetchAll("SELECT * FROM faqs ORDER BY id DESC") as $faq): ?>
        <tr>
            <td><?= htmlspecialchars(mb_substr($faq['question'],0,80)) ?></td>
            <td><?= htmlspecialchars(mb_substr($faq['answer'],0,80)) ?>...</td>
            <td><small class="text-muted"><?= htmlspecialchars($faq['keywords']??'') ?></small></td>
            <td><label class="toggle-feature"><input type="checkbox" <?= $faq['is_active']?'checked':'' ?> onchange="api({action:'toggle_faq',id:<?= $faq['id'] ?>,csrf_token:'<?= $csrf ?>'})" ><span class="toggle-slider"></span></label></td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick='editFaq(<?= json_encode($faq) ?>)'>✏️</button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteFaq(<?= $faq['id'] ?>)">🗑️</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php elseif ($page === 'settings'): ?>
<!-- ===== SETTINGS ===== -->
<h4 class="fw-bold mb-4">⚙️ সিস্টেম সেটিংস</h4>
<form id="settingsForm" method="POST" action="/admin/api.php" onsubmit="return false;">
<input type="hidden" name="csrf_token" value="<?= $csrf ?>">
<input type="hidden" name="action" value="save_settings">
<div class="row g-3">
    <div class="col-12"><div class="card p-4">
        <h6 class="fw-bold mb-3">🤖 বট ও কোম্পানি</h6>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">বটের নাম</label><input name="bot_name" class="form-control" value="<?= htmlspecialchars(Config::get('bot_name')) ?>"></div>
            <div class="col-md-6"><label class="form-label">কোম্পানির নাম</label><input name="company_name" class="form-control" value="<?= htmlspecialchars(Config::get('company_name')) ?>"></div>
            <div class="col-md-6"><label class="form-label">কোম্পানির ফোন</label><input name="company_phone" class="form-control" value="<?= htmlspecialchars(Config::get('company_phone')) ?>"></div>
            <div class="col-md-6"><label class="form-label">🌐 Timezone (সিস্টেম সময়)</label>
                <select name="timezone" class="form-select">
                    <?php
                    $tzCurrent = Config::get('timezone','Asia/Dhaka');
                    foreach (['Asia/Dhaka'=>'🇧🇩 Bangladesh (GMT+6)','Asia/Dubai'=>'🇦🇪 UAE/Dubai (GMT+4)','Asia/Riyadh'=>'🇸🇦 Saudi Arabia (GMT+3)','Asia/Muscat'=>'🇴🇲 Oman (GMT+4)','Asia/Qatar'=>'🇶🇦 Qatar (GMT+3)','Asia/Kuwait'=>'🇰🇼 Kuwait (GMT+3)','Asia/Bahrain'=>'🇧🇭 Bahrain (GMT+3)','Asia/Kuala_Lumpur'=>'🇲🇾 Malaysia (GMT+8)','Asia/Singapore'=>'🇸🇬 Singapore (GMT+8)','Asia/Kolkata'=>'🇮🇳 India (GMT+5:30)','Europe/Rome'=>'🇮🇹 Italy (GMT+1)','Europe/Lisbon'=>'🇵🇹 Portugal (GMT+0)','America/Toronto'=>'🇨🇦 Canada (GMT-5)','UTC'=>'🌐 UTC'] as $tz=>$lbl):
                    ?><option value="<?=$tz?>" <?=$tzCurrent===$tz?'selected':''?>><?=$lbl?></option><?php endforeach; ?>
                </select>
                <small class="text-muted">সব reminder, তারিখ ও সময় এই timezone-এ হবে</small>
            </div>
            <div class="col-md-6"><label class="form-label">কোম্পানির ঠিকানা</label><input name="company_address" class="form-control" value="<?= htmlspecialchars(Config::get('company_address')) ?>"></div>
            <div class="col-12"><label class="form-label">স্বাগত বার্তা</label><textarea name="welcome_message" class="form-control" rows="3"><?= htmlspecialchars(Config::get('welcome_message')) ?></textarea></div>
            <div class="col-12"><label class="form-label">AI System Prompt</label><textarea name="system_prompt" class="form-control" rows="4"><?= htmlspecialchars(Config::get('system_prompt')) ?></textarea></div>
        </div>
    </div></div>

    <div class="col-12"><div class="card p-4">
        <h6 class="fw-bold mb-3">🧠 AI সেটিংস</h6>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Gemini API Key</label>
                <div class="input-group">
                    <input name="gemini_api_key" type="password" id="geminiKeyInput" class="form-control" value="<?= htmlspecialchars(Config::get('gemini_api_key')) ?>" placeholder="AIza...">
                    <button type="button" class="btn btn-outline-primary" onclick="testGemini()">🧪 Test</button>
                </div>
                <div id="geminiTestResult" class="mt-1 small"></div>
            </div>
            <div class="col-md-6"><label class="form-label">Gemini মডেল</label>
                <?php
                $gModel = Config::get('gemini_model','gemini-1.5-flash');
                $gKnown = ['gemini-3.1-pro','gemini-3.1-flash','gemini-3.1-flash-lite','gemini-2.5-pro','gemini-2.5-flash','gemini-2.5-flash-lite','gemini-2.5-pro-exp-03-25','gemini-2.5-pro-preview-05-06','gemini-2.5-pro-preview-03-25','gemini-2.5-flash-preview-04-17','gemini-2.0-flash','gemini-2.0-flash-lite','gemini-2.0-flash-thinking-exp','gemini-1.5-pro','gemini-1.5-flash','gemini-1.5-flash-8b'];
                $gIsCustom = !in_array($gModel, $gKnown);
                ?>
                <select id="gemini_model_sel" class="form-select mb-1" onchange="onGeminiModelChange(this.value)">
                                        <optgroup label="── Gemini 3.1 (সর্বশেষ 2025) ──">
                    <option value="gemini-3.1-pro"             <?= $gModel==='gemini-3.1-pro'?'selected':'' ?>>Gemini 3.1 Pro ⭐ (সবচেয়ে শক্তিশালী)</option>
                    <option value="gemini-3.1-flash"           <?= $gModel==='gemini-3.1-flash'?'selected':'' ?>>Gemini 3.1 Flash ⚡ (দ্রুত ও সাশ্রয়ী)</option>
                    <option value="gemini-3.1-flash-lite"      <?= $gModel==='gemini-3.1-flash-lite'?'selected':'' ?>>Gemini 3.1 Flash Lite (হালকা ও সস্তা)</option>
                    </optgroup>
                    <optgroup label="── Gemini 2.5 (স্থিতিশীল) ──">
                    <option value="gemini-2.5-pro"             <?= $gModel==='gemini-2.5-pro'?'selected':'' ?>>Gemini 2.5 Pro (উন্নত)</option>
                    <option value="gemini-2.5-flash"           <?= $gModel==='gemini-2.5-flash'?'selected':'' ?>>Gemini 2.5 Flash ✅ (ভারসাম্যপূর্ণ)</option>
                    <option value="gemini-2.5-flash-lite"      <?= $gModel==='gemini-2.5-flash-lite'?'selected':'' ?>>Gemini 2.5 Flash Lite (সাশ্রয়ী)</option>
                    <option value="gemini-2.5-pro-exp-03-25"   <?= $gModel==='gemini-2.5-pro-exp-03-25'?'selected':'' ?>>Gemini 2.5 Pro Experimental</option>
                    <option value="gemini-2.5-flash-preview-04-17" <?= $gModel==='gemini-2.5-flash-preview-04-17'?'selected':'' ?>>Gemini 2.5 Flash Preview</option>
                    </optgroup>
                    <optgroup label="── Gemini 2.0 ──">
                    <option value="gemini-2.0-flash"           <?= $gModel==='gemini-2.0-flash'?'selected':'' ?>>Gemini 2.0 Flash</option>
                    <option value="gemini-2.0-flash-lite"      <?= $gModel==='gemini-2.0-flash-lite'?'selected':'' ?>>Gemini 2.0 Flash Lite</option>
                    <option value="gemini-2.0-flash-thinking-exp" <?= $gModel==='gemini-2.0-flash-thinking-exp'?'selected':'' ?>>Gemini 2.0 Flash Thinking</option>
                    </optgroup>
                    <optgroup label="── Gemini 1.5 ──">
                    <option value="gemini-1.5-pro"             <?= $gModel==='gemini-1.5-pro'?'selected':'' ?>>Gemini 1.5 Pro</option>
                    <option value="gemini-1.5-flash"           <?= $gModel==='gemini-1.5-flash'?'selected':'' ?>>Gemini 1.5 Flash</option>
                    <option value="gemini-1.5-flash-8b"        <?= $gModel==='gemini-1.5-flash-8b'?'selected':'' ?>>Gemini 1.5 Flash 8B</option>
                    </optgroup>
                    <optgroup label="── কাস্টম ──">
                    <option value="__custom__"                    <?= $gIsCustom ? 'selected' : '' ?>>✍️ নিজে লিখুন...</option>
                    </optgroup>
                </select>
                <input id="gemini_custom_model" name="gemini_model" class="form-control form-control-sm"
                       placeholder="model নাম লিখুন, যেমন: gemini-2.0-flash"
                       value="<?= htmlspecialchars($gModel) ?>"
                       oninput="validateGeminiModel(this.value)"
                       style="display:<?= $gIsCustom ? 'block' : 'none' ?>">
                <div id="geminiModelWarn" class="small mt-1" style="display:<?= $gIsCustom ? 'block' : 'none' ?>">
                    <?php if ($gIsCustom): ?>
                    <?php if (!str_starts_with($gModel,'gemini-')): ?>
                    <span class="text-danger">❌ ভুল model: <strong><?=htmlspecialchars($gModel)?></strong> — "gemini-" দিয়ে শুরু হওয়া দরকার!</span>
                    <?php else: ?>
                    <span class="text-success">✅ <?=htmlspecialchars($gModel)?></span>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <small class="text-muted">✅ প্রস্তাবিত: <strong>gemini-3.1-flash</strong> বা <strong>gemini-2.5-flash</strong> | <a href="https://ai.google.dev/gemini-api/docs/models" target="_blank">সব model ↗</a></small>
            </div>
            <div class="col-md-6"><label class="form-label">OpenRouter API Key</label><input name="openrouter_api_key" type="password" class="form-control" value="<?= htmlspecialchars(Config::get('openrouter_api_key')) ?>" placeholder="sk-or-..."></div>
            <div class="col-md-6"><label class="form-label">OpenRouter মডেল</label>
                <?php
                $orModel = Config::get('openrouter_model','mistralai/mistral-7b-instruct');
                $orKnown = ['mistralai/mistral-7b-instruct','mistralai/mixtral-8x7b-instruct','meta-llama/llama-3.1-8b-instruct:free','meta-llama/llama-3.1-70b-instruct','meta-llama/llama-3.3-70b-instruct','deepseek/deepseek-chat','deepseek/deepseek-r1','google/gemini-flash-1.5','google/gemini-2.0-flash-exp:free','anthropic/claude-3-haiku','anthropic/claude-3.5-sonnet'];
                $orIsCustom = !in_array($orModel, $orKnown);
                ?>
                <select id="openrouter_model_sel" class="form-select mb-1" onchange="onORModelChange(this.value)">
                    <optgroup label="── Mistral ──">
                    <option value="mistralai/mistral-7b-instruct" <?= $orModel==='mistralai/mistral-7b-instruct'?'selected':'' ?>>Mistral 7B (সাশ্রয়ী)</option>
                    <option value="mistralai/mixtral-8x7b-instruct" <?= $orModel==='mistralai/mixtral-8x7b-instruct'?'selected':'' ?>>Mixtral 8x7B (শক্তিশালী)</option>
                    </optgroup>
                    <optgroup label="── Meta LLaMA (ফ্রি) ──">
                    <option value="meta-llama/llama-3.1-8b-instruct:free" <?= $orModel==='meta-llama/llama-3.1-8b-instruct:free'?'selected':'' ?>>LLaMA 3.1 8B (ফ্রি ✅)</option>
                    <option value="meta-llama/llama-3.1-70b-instruct" <?= $orModel==='meta-llama/llama-3.1-70b-instruct'?'selected':'' ?>>LLaMA 3.1 70B</option>
                    <option value="meta-llama/llama-3.3-70b-instruct" <?= $orModel==='meta-llama/llama-3.3-70b-instruct'?'selected':'' ?>>LLaMA 3.3 70B (নতুন)</option>
                    </optgroup>
                    <optgroup label="── DeepSeek ──">
                    <option value="deepseek/deepseek-chat" <?= $orModel==='deepseek/deepseek-chat'?'selected':'' ?>>DeepSeek Chat (সাশ্রয়ী)</option>
                    <option value="deepseek/deepseek-r1" <?= $orModel==='deepseek/deepseek-r1'?'selected':'' ?>>DeepSeek R1 (রিজনিং)</option>
                    </optgroup>
                    <optgroup label="── Google via OpenRouter ──">
                    <option value="google/gemini-flash-1.5" <?= $orModel==='google/gemini-flash-1.5'?'selected':'' ?>>Gemini 1.5 Flash</option>
                    <option value="google/gemini-2.0-flash-exp:free" <?= $orModel==='google/gemini-2.0-flash-exp:free'?'selected':'' ?>>Gemini 2.0 Flash (ফ্রি)</option>
                    </optgroup>
                    <optgroup label="── Anthropic Claude ──">
                    <option value="anthropic/claude-3-haiku" <?= $orModel==='anthropic/claude-3-haiku'?'selected':'' ?>>Claude 3 Haiku</option>
                    <option value="anthropic/claude-3.5-sonnet" <?= $orModel==='anthropic/claude-3.5-sonnet'?'selected':'' ?>>Claude 3.5 Sonnet</option>
                    </optgroup>
                    <optgroup label="── কাস্টম ──">
                    <option value="__or_custom__" <?= (!in_array($orModel,$orKnown)?'selected':'') ?>>✍️ নিজে লিখুন...</option>
                    </optgroup>
                </select>
                <input id="or_custom_model" name="openrouter_model" class="form-control form-control-sm"
                       placeholder="provider/model-name লিখুন"
                       value="<?= htmlspecialchars($orModel) ?>"
                       style="display:<?= $orIsCustom ? 'block' : 'none' ?>">
                <small class="text-muted">Gemini fail করলে ব্যাকআপ হিসেবে কাজ করবে | <a href="https://openrouter.ai/models" target="_blank">সব মডেল দেখুন ↗</a></small>
            </div>
            <div class="col-md-4 d-flex align-items-center gap-2"><label class="toggle-feature"><input type="checkbox" name="ai_fallback_enabled" <?= Config::isEnabled('ai_fallback_enabled')?'checked':'' ?>><span class="toggle-slider"></span></label><span class="small">AI Fallback (OpenRouter)</span></div>
            <div class="col-md-4 d-flex align-items-center gap-2"><label class="toggle-feature"><input type="checkbox" name="faq_internet_answer" <?= Config::isEnabled('faq_internet_answer')?'checked':'' ?>><span class="toggle-slider"></span></label><span class="small">FAQ না পেলে AI ব্যবহার</span></div>
            <div class="col-md-4 d-flex align-items-center gap-2"><label class="toggle-feature"><input type="checkbox" name="faq_service_only_mode" <?= Config::isEnabled('faq_service_only_mode')?'checked':'' ?>><span class="toggle-slider"></span></label><span class="small">🤖 শুধু FAQ+সেবা থেকে উত্তর</span></div>
            <div class="col-md-4 d-flex align-items-center gap-2"><label class="toggle-feature"><input type="checkbox" name="embassy_news_enabled" <?= Config::isEnabled('embassy_news_enabled')?'checked':'' ?>><span class="toggle-slider"></span></label><span class="small">🌍 Embassy News চালু</span></div>
            <div class="col-md-4 d-flex align-items-center gap-2"><label class="toggle-feature"><input type="checkbox" name="google_drive_enabled" <?= Config::isEnabled('google_drive_enabled')?'checked':'' ?>><span class="toggle-slider"></span></label><span class="small">📁 Google Drive চালু</span></div>
        </div>
    </div></div>

    <div class="col-12"><div class="card p-4">
        <h6 class="fw-bold mb-3">📱 Platform Tokens</h6>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">🤖 Telegram Bot Token</label><input name="telegram_token" type="password" class="form-control" value="<?= htmlspecialchars(Config::get('telegram_token')) ?>" placeholder="123:ABC..."></div>
            <div class="col-md-6"><label class="form-label">Admin Telegram Group ID</label><input name="admin_telegram_group" class="form-control" value="<?= htmlspecialchars(Config::get('admin_telegram_group')) ?>" placeholder="-1001234567890"></div>
            <div class="col-md-6"><label class="form-label">💚 WhatsApp Access Token</label><input name="whatsapp_token" type="password" class="form-control" value="<?= htmlspecialchars(Config::get('whatsapp_token')) ?>"></div>
            <div class="col-md-3"><label class="form-label">WhatsApp Phone ID</label><input name="whatsapp_phone_id" class="form-control" value="<?= htmlspecialchars(Config::get('whatsapp_phone_id')) ?>"></div>
            <div class="col-md-3"><label class="form-label">WhatsApp App Secret</label><input name="whatsapp_app_secret" type="password" class="form-control" value="<?= htmlspecialchars(Config::get('whatsapp_app_secret')) ?>"></div>
            <div class="col-md-6"><label class="form-label">💙 Messenger Page Token</label><input name="messenger_page_token" type="password" class="form-control" value="<?= htmlspecialchars(Config::get('messenger_page_token')) ?>"></div>
            <div class="col-md-6"><label class="form-label">Messenger Verify Token</label><input name="messenger_verify_token" class="form-control" value="<?= htmlspecialchars(Config::get('messenger_verify_token')) ?>"></div>
        </div>
    </div></div>

    <div class="col-12"><div class="card p-4">
        <h6 class="fw-bold mb-3">💱 কারেন্সি রেট সেটিংস</h6>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Currency API Key (exchangerate-api.com)</label><input name="currency_api_key" class="form-control" value="<?= htmlspecialchars(Config::get('currency_api_key')) ?>"></div>
            <div class="col-md-3"><label class="form-label">📅 রেট পাঠানোর সময়</label>
                <input name="currency_send_time" type="time" class="form-control" value="<?= htmlspecialchars(Config::get('currency_send_time','09:00')) ?>"></div>
            <div class="col-md-3"><label class="form-label">📆 কতদিন পরপর পাঠাবে</label>
                <select name="currency_send_frequency" class="form-select">
                    <?php foreach(['daily'=>'প্রতিদিন','weekly'=>'প্রতি সপ্তাহ','monthly'=>'প্রতি মাস'] as $val=>$lbl): ?>
                    <option value="<?=$val?>" <?=Config::get('currency_send_frequency','daily')===$val?'selected':''?>><?=$lbl?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-6"><label class="form-label">📬 পাঠানোর দিন (weekly হলে)</label>
                <select name="currency_send_day" class="form-select">
                    <?php foreach(['0'=>'রবিবার','1'=>'সোমবার','2'=>'মঙ্গলবার','3'=>'বুধবার','4'=>'বৃহস্পতিবার','5'=>'শুক্রবার','6'=>'শনিবার'] as $d=>$dn): ?>
                    <option value="<?=$d?>" <?=Config::get('currency_send_day','1')===$d?'selected':''?>><?=$dn?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-6"><div class="alert alert-info py-2 mb-0" style="font-size:.82rem">
                ⏰ Cron Job: <code>0 <?= explode(':',Config::get('currency_send_time','09:00'))[1] ?? '0' ?> <?= explode(':',Config::get('currency_send_time','09:00'))[0] ?? '9' ?> * * *</code> → <code>php cron/currency_update.php</code>
            </div></div>
        </div>
    </div></div>

    <div class="col-12"><div class="card p-4">
        <h6 class="fw-bold mb-3">⏰ অফিস আওয়ার</h6>
        <div class="row g-3">
            <div class="col-md-2 d-flex align-items-center gap-2"><label class="toggle-feature"><input type="checkbox" name="office_hours_enabled" <?= Config::isEnabled('office_hours_enabled')?'checked':'' ?>><span class="toggle-slider"></span></label><span class="small">চালু</span></div>
            <div class="col-md-2"><label class="form-label">শুরু</label><input name="office_hours_start" type="time" class="form-control" value="<?= htmlspecialchars(Config::get('office_hours_start','09:00')) ?>"></div>
            <div class="col-md-2"><label class="form-label">শেষ</label><input name="office_hours_end" type="time" class="form-control" value="<?= htmlspecialchars(Config::get('office_hours_end','18:00')) ?>"></div>
            <div class="col-md-6"><label class="form-label">অফিস দিন (কমা দিয়ে, 0=রবি 6=শনি)</label><input name="office_days" class="form-control" value="<?= htmlspecialchars(Config::get('office_days','1,2,3,4,5,6')) ?>"></div>
        </div>
    </div></div>

    <div class="col-12"><div class="card p-4">
        <h6 class="fw-bold mb-3">🔧 সব ফিচার চালু/বন্ধ</h6>
        <div class="row g-3">
        <?php
        $features = [
            'multilanguage_enabled'=>'মাল্টি-ল্যাঙ্গুয়েজ', 'voice_enabled'=>'ভয়েস মেসেজ', 'document_upload_enabled'=>'ডকুমেন্ট আপলোড',
            'ocr_enabled'=>'OCR AI স্ক্যান', 'fake_doc_detector_enabled'=>'ফেক Doc ডিটেক্টর', 'onboarding_custom'=>'কাস্টম অনবোর্ডিং',
            'reminder_enabled'=>'মেয়াদ রিমাইন্ডার', 'payment_reminder_enabled'=>'পেমেন্ট রিমাইন্ডার', 'smart_notification_enabled'=>'স্মার্ট নোটিফিকেশন',
            'broadcast_enabled'=>'ব্রডকাস্ট', 'qr_code_enabled'=>'QR কোড কার্ড', 'payment_tracker_enabled'=>'পেমেন্ট ট্র্যাকার',
            'invoice_enabled'=>'অটো ইনভয়েস', 'expiry_countdown_enabled'=>'এক্সপায়ারি কাউন্টডাউন',
            'service_menu_enabled'=>'সার্ভিস মেনু', 'currency_alert_enabled'=>'কারেন্সি অ্যালার্ট', 'salary_calculator_enabled'=>'স্যালারি ক্যালকুলেটর',
            'sos_enabled'=>'SOS হেল্পলাইন', 'digital_locker_enabled'=>'ডিজিটাল লকার', 'scam_alert_enabled'=>'স্ক্যাম অ্যালার্ট',
        ];
        foreach ($features as $k => $label): ?>
        <div class="col-xl-3 col-md-4 col-6 d-flex align-items-center gap-2">
            <label class="toggle-feature"><input type="checkbox" name="<?= $k ?>" <?= Config::isEnabled($k)?'checked':'' ?>><span class="toggle-slider"></span></label>
            <span class="small"><?= $label ?></span>
        </div>
        <?php endforeach; ?>
        </div>
    </div></div>
</div>

<div class="mt-4 d-flex align-items-center gap-3">
    <button type="button" class="btn btn-primary btn-lg" onclick="saveSettings()">💾 সেটিংস সেভ করুন</button>
    <button type="button" class="btn btn-outline-warning" onclick="clearCache()">🗑️ Cache Clear</button>
    <span id="settingsMsg"></span>
</div>
</form>
<?php endif; ?>

</div><!-- /main -->

<!-- FAQ Modal -->
<div class="modal fade" id="faqModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">FAQ যোগ/সম্পাদনা</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <input type="hidden" id="faqId">
    <div class="mb-3"><label class="form-label fw-semibold">প্রশ্ন *</label><textarea id="faqQ" class="form-control" rows="2" placeholder="প্রশ্ন লিখুন..."></textarea></div>
    <div class="mb-3"><label class="form-label fw-semibold">উত্তর *</label><textarea id="faqA" class="form-control" rows="5" placeholder="উত্তর লিখুন..."></textarea></div>
    <div class="mb-3"><label class="form-label">Keywords (কমা দিয়ে)</label><input id="faqK" class="form-control" placeholder="ভিসা,visa,কাগজ,document"></div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button><button class="btn btn-primary" onclick="saveFaq()">সেভ করুন</button></div>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = '<?= $csrf ?>';
async function api(data) {
    try {
        const body = new URLSearchParams({...data, csrf_token: CSRF});
        const r = await fetch('/admin/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        });
        if (!r.ok) {
            console.error('HTTP Error:', r.status, r.statusText);
            if (r.status === 403) {
                // Session expired - reload to re-login
                alert('Session মেয়াদ শেষ। পুনরায় লগইন করুন।');
                location.reload();
                return { success: false };
            }
        }
        const text = await r.text();
        try { return JSON.parse(text); }
        catch(e) { console.error('Non-JSON response:', text.substring(0,200)); return { success: false, message: 'Server error' }; }
    } catch(e) {
        console.error('API error:', e);
        return { success: false, message: e.message };
    }
}

// Settings
function onORModelChange(val) {
    const ci = document.getElementById('or_custom_model');
    if (val === '__or_custom__') {
        ci.style.display = 'block';
        ci.value = '';
        ci.focus();
    } else {
        ci.style.display = 'none';
        ci.value = val; // the hidden input sends the actual value
    }
}

function onGeminiModelChange(val) {
    const ci = document.getElementById('gemini_custom_model');
    const warn = document.getElementById('geminiModelWarn');
    if (val === '__custom__') {
        ci.style.display = 'block';
        ci.value = '';
        ci.focus();
        if (warn) warn.style.display = 'none';
    } else {
        ci.style.display = 'none';
        ci.value = val;
        if (warn) warn.style.display = 'none';
    }
}

function validateGeminiModel(val) {
    const warn = document.getElementById('geminiModelWarn');
    if (!warn) return;
    if (val && !val.startsWith('gemini-')) {
        warn.innerHTML = '❌ ভুল model name! Gemini model অবশ্যই "gemini-" দিয়ে শুরু হবে। যেমন: gemini-2.0-flash';
        warn.style.display = 'block';
    } else if (val) {
        warn.innerHTML = '✅ ' + val;
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }
}

async function testGemini() {
    const el = document.getElementById('geminiTestResult');
    el.innerHTML = '<span class="text-muted">⏳ Testing...</span>';
    const r = await api({action:'test_ai'});
    if (r.success) {
        el.innerHTML = '<span class="text-success fw-bold">✅ কাজ করছে! Model: <code>'+r.model+'</code></span>';
    } else {
        let msg = '<span class="text-danger">❌ HTTP '+r.http_code+': '+r.response+'</span>';
        if (r.http_code === 404) {
            msg += '<br><span class="text-warning">⚠️ Model name ভুল! নিচের model ব্যবহার করুন: <code>gemini-2.0-flash</code></span>';
            // Auto-fix: set correct model in the hidden input
            const ci = document.getElementById('gemini_custom_model');
            if (ci) { ci.value = 'gemini-2.0-flash'; }
            const sel = document.getElementById('gemini_model_sel');
            if (sel) { sel.value = 'gemini-2.0-flash'; }
            msg += '<br><button class="btn btn-sm btn-warning mt-1" onclick="autoFixModel()">🔧 Auto-Fix করুন</button>';
        }
        el.innerHTML = msg;
    }
}

async function autoFixModel() {
    document.getElementById('geminiTestResult').innerHTML = '<span class="text-muted">⏳ Fixing...</span>';
    // Use dedicated fix action (bypasses form complexity)
    const r = await api({action:'fix_gemini_model'});
    if (r.success) {
        document.getElementById('geminiTestResult').innerHTML = '<span class="text-success fw-bold">✅ '+r.message+' পেজ reload হচ্ছে...</span>';
        setTimeout(() => location.reload(), 1500);
    } else {
        document.getElementById('geminiTestResult').innerHTML = '<span class="text-danger">❌ Fix failed: '+( r.message||'unknown')+'</span>';
    }
}

async function clearCache() {
    const r = await api({action:'clear_cache'});
    const el = document.getElementById('settingsMsg');
    el.innerHTML = r.success
        ? '<span class="text-success fw-bold">✅ Cache clear হয়েছে!</span>'
        : '<span class="text-danger">❌ সমস্যা হয়েছে</span>';
    setTimeout(() => el.innerHTML = '', 3000);
}

async function saveSettings() {
    const fd = new FormData(document.getElementById('settingsForm'));
    // gemini_model and openrouter_model come from hidden inputs (always correct)
    // csrf_token and action already in form as hidden inputs
    const data = {};
    for (const [k,v] of fd) data[k] = v;
    const r = await api(data);
    const el = document.getElementById('settingsMsg');
    el.innerHTML = r.success ? `<span class="text-success fw-bold">${r.message}</span>` : `<span class="text-danger">❌ সমস্যা হয়েছে</span>`;
    setTimeout(() => el.innerHTML = '', 4000);
}

// Status
// Application functions
function filterApps(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#appTable tbody tr').forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

async function deleteApp(id, tracking) {
    if (!confirm('আবেদন "' + tracking + '" মুছে ফেলবেন?')) return;
    const r = await api({action:'delete_application', app_id: id});
    if (r.success) { showToast('✅ মুছে ফেলা হয়েছে','success'); setTimeout(()=>location.reload(),1000); }
    else showToast('❌ '+(r.message||'সমস্যা'),'danger');
}

let appPayModal;
document.addEventListener('DOMContentLoaded', () => {
    try { appPayModal = new bootstrap.Modal('#appPayModal'); } catch(e) {}
});

function addPaymentForApp(appId, custId) {
    document.getElementById('payAppId').value = appId;
    document.getElementById('payCustId').value = custId;
    document.getElementById('payMsg').innerHTML = '';
    ['payTotal','payPaid','payDesc'].forEach(id => document.getElementById(id).value = '');
    // payCur is a select - default is first option (BDT)
    if (appPayModal) appPayModal.show();
}

async function saveAppPayment() {
    const r = await api({
        action: 'add_payment',
        customer_id: document.getElementById('payCustId').value,
        application_id: document.getElementById('payAppId').value,
        total_amount: document.getElementById('payTotal').value,
        paid_amount: document.getElementById('payPaid').value,
        currency: document.getElementById('payCur').value,
        description: document.getElementById('payDesc').value,
    });
    if (r.success) {
        document.getElementById('payMsg').innerHTML = '<div class="alert alert-success py-1">✅ সেভ হয়েছে!</div>';
        setTimeout(() => { if(appPayModal) appPayModal.hide(); location.reload(); }, 1200);
    } else {
        document.getElementById('payMsg').innerHTML = '<div class="alert alert-danger py-1">❌ '+(r.message||'সমস্যা')+'</div>';
    }
}

async function updateStatus(appId, statusId) {
    const r = await api({ action:'update_application_status', app_id:appId, status_id:statusId });
    showToast(r.message || (r.success ? '✅ আপডেট হয়েছে' : '❌ সমস্যা'), r.success ? 'success' : 'danger');
}

async function saveNotes(appId, notes) {
    await api({ action:'update_application_status', app_id:appId, status_id:0, admin_notes:notes });
}

// Chat
async function sendReply(cid) {
    const msg = document.getElementById('replyText').value.trim();
    if (!msg) return;
    const r = await api({ action:'send_chat_reply', customer_id:cid, message:msg });
    if (r.success) { document.getElementById('replyText').value=''; appendMessage(msg, 'out'); }
}

async function sendFileToCustomer(cid, input) {
    const file = input.files[0];
    if (!file) return;
    const status = document.getElementById('fileStatus');
    status.textContent = 'পাঠানো হচ্ছে...';
    const fd = new FormData();
    fd.append('action', 'send_file_to_customer');
    fd.append('customer_id', cid);
    fd.append('csrf_token', CSRF);
    fd.append('file', file);
    try {
        const r = await fetch('/admin/api.php', { method:'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            status.textContent = '✅ পাঠানো হয়েছে';
            appendMessage('[📎 ' + file.name + ']', 'out');
            setTimeout(() => { status.textContent = ''; }, 3000);
        } else {
            status.textContent = '❌ ' + (d.message || 'Error');
        }
    } catch(e) {
        status.textContent = '❌ Network error';
    }
    input.value = '';
}

async function toggleChatMode(cid, mode) {
    const r = await api({ action:'toggle_chat_mode', customer_id:cid, mode });
    if (r.success) location.reload();
}

// Real-time chat polling
<?php if ($page === 'chat' && $selCid): ?>
let lastMsgId = <?= $lastMsgId ?>;
function appendMessage(content, dir) {
    const cw = document.getElementById('chatWindow');
    const el = document.createElement('div');
    el.className = dir === 'out' ? 'msg-out' : 'msg-in';
    el.innerHTML = `<div class="msg-bubble">${content.replace(/\n/g,'<br>')}<div class="msg-time">${new Date().toLocaleTimeString('bn',{hour:'2-digit',minute:'2-digit'})}</div></div>`;
    cw.appendChild(el);
    cw.scrollTop = cw.scrollHeight;
}

async function pollMessages() {
    try {
        const r = await fetch('/admin/api.php', { method:'POST', body: new URLSearchParams({ action:'get_new_messages', customer_id:<?= $selCid ?>, last_id:lastMsgId, csrf_token: CSRF }) });
        const data = await r.json();
        if (data.messages && data.messages.length > 0) {
            data.messages.forEach(m => { appendMessage(m.content, m.direction); lastMsgId = Math.max(lastMsgId, m.id); });
        }
    } catch(e) {}
}
setInterval(pollMessages, 5000); // প্রতি ৫ সেকেন্ডে poll
document.addEventListener('DOMContentLoaded', () => {
    const cw = document.getElementById('chatWindow');
    if (cw) cw.scrollTop = cw.scrollHeight;
    // Ctrl+Enter পাঠানো
    document.getElementById('replyText')?.addEventListener('keydown', e => {
        if (e.ctrlKey && e.key === 'Enter') sendReply(<?= $selCid ?>);
    });
});
<?php endif; ?>

// FAQ
let faqModal;
document.addEventListener('DOMContentLoaded', () => {
    faqModal = new bootstrap.Modal('#faqModal');
    document.getElementById('searchInput')?.addEventListener('input', function() {
        const v = this.value.toLowerCase();
        document.querySelectorAll('#customerTable tbody tr').forEach(r => r.style.display = r.textContent.toLowerCase().includes(v) ? '' : 'none');
    });
});
function openFaqModal() { ['faqId','faqQ','faqA','faqK'].forEach(id => document.getElementById(id).value=''); faqModal.show(); }
function editFaq(f) { document.getElementById('faqId').value=f.id; document.getElementById('faqQ').value=f.question; document.getElementById('faqA').value=f.answer; document.getElementById('faqK').value=f.keywords||''; faqModal.show(); }
async function saveFaq() { const r = await api({action:'save_faq', id:document.getElementById('faqId').value, question:document.getElementById('faqQ').value, answer:document.getElementById('faqA').value, keywords:document.getElementById('faqK').value}); if(r.success){faqModal.hide();location.reload();} }
async function deleteFaq(id) { if(!confirm('মুছে ফেলবেন?'))return; await api({action:'delete_faq',id}); location.reload(); }

// Logout
async function doLogout() { if(!confirm('লগআউট করবেন?'))return; await api({action:'logout'}); location.href='/admin/'; }

// Toast
function showToast(msg, type='info') {
    const t = document.createElement('div');
    t.className = `alert alert-${type} position-fixed bottom-0 end-0 m-3 shadow`;
    t.style.cssText = 'z-index:9999;min-width:280px;border-radius:12px';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}
</script>
</body>
</html>
