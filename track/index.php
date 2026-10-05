<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>আবেদনের অবস্থা - ট্র্যাক করুন</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap">
<style>
:root{
  --p1:#6C3BFF;--p2:#8B5CF6;--p3:#EDE9FE;
  --a1:#06B6D4;--a2:#0EA5E9;--a3:#E0F2FE;
  --g1:#10B981;--g3:#D1FAE5;
  --o1:#F59E0B;--o3:#FEF3C7;
  --r1:#EF4444;--r3:#FEE2E2;
  --bg:#F0F4FF;--surface:#FFF;--border:#E4E8FF;
  --text:#1A1035;--text2:#5B5B8A;--text3:#9999CC;
}
*{box-sizing:border-box}
body{
  background:var(--bg);min-height:100vh;
  font-family:'Hind Siliguri',sans-serif;
  color:var(--text);
}
.hero-bar{
  background:linear-gradient(135deg,#1A0B4B 0%,#0D1B55 50%,#0A2A7A 100%);
  padding:28px 0 24px;
  position:relative;overflow:hidden;
}
.hero-bar::before{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at 30% 50%,rgba(108,59,255,.3),transparent 60%),
             radial-gradient(ellipse at 70% 30%,rgba(6,182,212,.2),transparent 50%);
}
.hero-bar h4{color:#fff;font-family:'Sora',sans-serif;font-weight:800;font-size:1.4rem;letter-spacing:-.02em}
.hero-bar p{color:rgba(255,255,255,.55);font-size:.85rem}
.search-wrap{
  background:rgba(255,255,255,.1);
  backdrop-filter:blur(10px);
  border:1.5px solid rgba(255,255,255,.2);
  border-radius:16px;padding:20px;
}
.search-wrap input{
  background:rgba(255,255,255,.1);
  border:1.5px solid rgba(255,255,255,.2);
  border-radius:10px;color:#fff;
  font-family:'Hind Siliguri',sans-serif;
  font-size:.9rem;padding:12px 16px;
}
.search-wrap input::placeholder{color:rgba(255,255,255,.4)}
.search-wrap input:focus{outline:none;border-color:rgba(6,182,212,.8);background:rgba(6,182,212,.1)}
.search-wrap .btn-search{
  background:linear-gradient(135deg,var(--p1),var(--a2));
  border:none;border-radius:10px;
  color:#fff;padding:12px 24px;font-weight:600;
  font-family:'Hind Siliguri',sans-serif;
  cursor:pointer;transition:all .2s;white-space:nowrap;
}
.search-wrap .btn-search:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(108,59,255,.4)}
.card{
  background:var(--surface);
  border:1.5px solid var(--border);
  border-radius:16px;
  box-shadow:0 2px 12px rgba(108,59,255,.06);
}
.profile-avatar{
  width:72px;height:72px;border-radius:20px;
  background:linear-gradient(135deg,var(--p1),var(--a1));
  display:flex;align-items:center;justify-content:center;
  font-size:1.8rem;font-weight:800;color:#fff;margin:0 auto 12px;
  box-shadow:0 6px 20px rgba(108,59,255,.3);
  font-family:'Sora',sans-serif;
}
.badge-platform{
  display:inline-block;padding:3px 12px;border-radius:20px;
  font-size:.7rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;
}
.badge-telegram{background:var(--a3);color:#0369A1}
.badge-whatsapp{background:var(--g3);color:#065F46}
.badge-messenger{background:var(--p3);color:#5B21B6}
.nav-tabs{border-bottom:2px solid var(--border)!important;gap:4px}
.nav-tabs .nav-link{
  color:var(--text2);border:none!important;border-radius:10px 10px 0 0;
  padding:10px 18px;font-weight:500;font-size:.875rem;transition:all .15s;
}
.nav-tabs .nav-link:hover{color:var(--p1);background:var(--p3)}
.nav-tabs .nav-link.active{
  color:var(--p1)!important;font-weight:700;
  background:var(--p3)!important;
  border-bottom:2px solid var(--p1)!important;
}
.timeline{position:relative;padding-left:30px}
.timeline::before{
  content:'';position:absolute;left:9px;top:4px;bottom:4px;
  width:2px;
  background:linear-gradient(180deg,var(--p1),var(--a1),var(--border));
  border-radius:2px;
}
.timeline-item{position:relative;margin-bottom:18px}
.timeline-dot{
  position:absolute;left:-26px;width:20px;height:20px;
  border-radius:50%;border:2px solid var(--border);background:var(--bg);
  transition:all .2s;
}
.timeline-dot.active{
  background:linear-gradient(135deg,var(--p1),var(--a1));
  border-color:transparent;
  box-shadow:0 0 0 4px rgba(108,59,255,.15);
}
.timeline-dot.done{background:var(--text3);border-color:var(--text3)}
.doc-item{
  display:flex;align-items:center;gap:12px;
  padding:12px 16px;background:var(--bg);
  border-radius:12px;margin-bottom:10px;
  border:1px solid var(--border);
  transition:border-color .15s;
}
.doc-item:hover{border-color:var(--p2)}
.alert{border:none;border-radius:12px;font-size:.855rem;padding:12px 16px}
.alert-danger{background:var(--r3);color:#991B1B}
.btn-outline{
  border:1.5px solid var(--border);border-radius:8px;
  background:transparent;color:var(--text2);
  padding:6px 14px;font-size:.8rem;cursor:pointer;
  transition:all .15s;text-decoration:none;display:inline-flex;align-items:center;gap:5px;
}
.btn-outline:hover{border-color:var(--p1);color:var(--p1);background:var(--p3)}
.btn-primary-sm{
  background:linear-gradient(135deg,var(--p1),var(--a2));
  border:none;border-radius:8px;color:#fff;
  padding:6px 16px;font-size:.8rem;cursor:pointer;
  text-decoration:none;display:inline-flex;align-items:center;gap:5px;
  box-shadow:0 3px 10px rgba(108,59,255,.3);
}
.empty-state{text-align:center;padding:40px 20px;color:var(--text3)}
.empty-state .icon{font-size:2.5rem;margin-bottom:12px}
</style>
</head>
<body>

<?php
// OCR text download
if (isset($_GET['dl']) && $_GET['dl'] === 'ocr' && isset($_GET['doc'])) {
    require_once dirname(__DIR__).'/config/config.php';
    $db  = Database::getInstance();
    $did = (int)$_GET['doc'];
    $doc = $db->fetchOne("SELECT * FROM documents WHERE id=?", [$did]);
    if ($doc && $doc['extracted_data']) {
        $data = json_decode($doc['extracted_data'], true) ?: [];
        $txt  = "Document OCR Data\n";
        $txt .= str_repeat("=",40)."\n";
        foreach ($data as $k => $v) {
            if ($v) $txt .= ucfirst(str_replace('_',' ',$k)).": ".(is_bool($v)?($v?'Yes':'No'):$v)."\n";
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="doc_'.$did.'_ocr.txt"');
        echo $txt;
        exit;
    }
}
?>
<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/services/QRCodeService.php';

$db          = Database::getInstance();
$result      = null;
$customer    = null;
$error       = null;
$searched    = false;
$company     = Config::get('company_name', 'ভিসা সার্ভিস');
$allStatuses = $db->fetchAll("SELECT * FROM application_statuses ORDER BY order_no");
$qrService   = new QRCodeService();

// QR Code স্ক্যানের মাধ্যমে আসলে (token দিয়ে)
if (isset($_GET['cid']) && isset($_GET['token'])) {
    $cid   = (int) $_GET['cid'];
    $token = $_GET['token'];

    if ($qrService->verifyToken($cid, $token)) {
        $customer = $db->fetchOne("SELECT * FROM customers WHERE id = ?", [$cid]);
    } else {
        $error = 'Invalid QR code or token.';
    }
}

// Manual Search
if ($_POST['tracking'] ?? false) {
    $searched = true;
    $input    = trim($_POST['tracking']);

    // Tracking ID দিয়ে
    $app = $db->fetchOne(
        "SELECT a.*, s.name as service_name, st.name as status_name, st.color, st.order_no as status_order, c.id as cid
         FROM applications a
         LEFT JOIN services s ON a.service_id = s.id
         LEFT JOIN application_statuses st ON a.status_id = st.id
         LEFT JOIN customers c ON a.customer_id = c.id
         WHERE a.tracking_id = ?",
        [strtoupper($input)]
    );

    // Mobile দিয়ে
    if (!$app) {
        $cust = $db->fetchOne("SELECT id FROM customers WHERE mobile = ? LIMIT 1", [$input]);
        if ($cust) {
            $customer = $db->fetchOne("SELECT * FROM customers WHERE id = ?", [$cust['id']]);
        }
    } else {
        $result   = $app;
        $customer = $db->fetchOne("SELECT * FROM customers WHERE id = ?", [$app['cid']]);
    }

    if (!$result && !$customer) {
        $error = 'কোনো রেকর্ড পাওয়া যায়নি।';
    }
}

// Customer থেকে সব applications লোড
$applications = [];
$payments     = [];
$documents    = [];

if ($customer) {
    $applications = $db->fetchAll(
        "SELECT a.*, s.name as service_name, st.name as status_name, st.color, st.order_no as status_order
         FROM applications a
         LEFT JOIN services s ON a.service_id = s.id
         LEFT JOIN application_statuses st ON a.status_id = st.id
         WHERE a.customer_id = ?
         ORDER BY a.created_at DESC",
        [$customer['id']]
    );

    $payments = $db->fetchAll(
        "SELECT p.*, a.tracking_id, s.name as service_name
         FROM payments p
         LEFT JOIN applications a ON p.application_id = a.id
         LEFT JOIN services s ON a.service_id = s.id
         WHERE p.customer_id = ?
         ORDER BY p.created_at DESC",
        [$customer['id']]
    );

    $documents = $db->fetchAll(
        "SELECT * FROM documents WHERE customer_id = ? ORDER BY created_at DESC",
        [$customer['id']]
    );

    if (!$result && !empty($applications)) {
        $result = $applications[0];
    }
}
?>

<!-- Hero -->
<div class="hero-bar">
  <div class="container py-0">
    <div class="text-center position-relative" style="z-index:1">
      <h4><?= htmlspecialchars($company) ?></h4>
      <p class="mb-4">আবেদনের অবস্থা ট্র্যাক করুন</p>
      <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
          <div class="search-wrap">
            <form method="POST" class="d-flex gap-2">
              <input type="text" name="tracking"
                placeholder="ট্র্যাকিং নম্বর বা মোবাইল নম্বর"
                value="<?= htmlspecialchars($_POST['tracking'] ?? '') ?>"
                class="flex-grow-1">
              <button type="submit" class="btn-search">🔍 খুঁজুন</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="container py-4">
    <?php if ($error): ?>
    <div class="row justify-content-center mb-3">
        <div class="col-lg-8"><div class="alert alert-danger">❌ <?= htmlspecialchars($error) ?></div></div>
    </div>
    <?php endif; ?>

    <?php if ($customer): ?>
    <div class="row g-3">

        <!-- Left: Customer Profile -->
        <div class="col-lg-4">
            <div class="card p-4 mb-3">
                <div class="text-center mb-3">
                    <div class="profile-avatar"><?= mb_substr($customer['name'] ?? 'G', 0, 1) ?></div>
                    <h5 class="fw-bold mb-1"><?= htmlspecialchars($customer['name'] ?? '—') ?></h5>
                    <span class="badge-platform badge-<?= strtolower($customer['platform']) ?>"><?= strtoupper($customer['platform']) ?></span>
                    <div class="text-muted small mt-1">MEM-<?= str_pad($customer['id'], 6, '0', STR_PAD_LEFT) ?></div>
                </div>
                <hr>
                <div class="small">
                    <?php if ($customer['mobile']): ?>
                    <div class="d-flex gap-2 mb-2"><span>📱</span><span><?= htmlspecialchars($customer['mobile']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($customer['address']): ?>
                    <div class="d-flex gap-2 mb-2"><span>🏠</span><span><?= htmlspecialchars($customer['address']) ?></span></div>
                    <?php endif; ?>
                    <div class="d-flex gap-2 mb-2"><span>📅</span><span>যোগদান: <?= date('d/m/Y', strtotime($customer['created_at'])) ?></span></div>
                </div>

                <?php if ($customer['qr_code']): ?>
                <hr>
                <div class="text-center">
                    <img src="<?= htmlspecialchars($customer['qr_code']) ?>" alt="QR" style="width:100px;height:100px" onerror="this.style.display='none'">
                    <div class="text-muted small mt-1">Member QR Code</div>
                </div>
                <?php endif; ?>

                <
            </div>
        </div>

        <!-- Right: Details -->
        <div class="col-lg-8">
            <ul class="nav nav-tabs mb-3" id="tabs">
                <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-apps">📋 আবেদন (<?= count($applications) ?>)</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-pay">💰 পেমেন্ট (<?= count($payments) ?>)</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-docs">📎 ডকুমেন্ট (<?= count($documents) ?>)</a></li>
            </ul>

            <div class="tab-content">

                <!-- Applications Tab -->
                <div class="tab-pane fade show active" id="tab-apps">
                    <?php foreach ($applications as $app): ?>
                    <div class="card p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h6 class="fw-bold mb-1"><?= htmlspecialchars($app['service_name'] ?? 'সেবা') ?></h6>
                                <code class="text-muted"><?= htmlspecialchars($app['tracking_id']) ?></code>
                            </div>
                            <span class="badge" style="background:<?= htmlspecialchars($app['color'] ?? '#ccc') ?>"><?= htmlspecialchars($app['status_name'] ?? '—') ?></span>
                        </div>

                        <!-- Status Timeline -->
                        <div class="timeline">
                            <?php foreach ($allStatuses as $s):
                                $isActive = $s['id'] == ($app['status_id'] ?? 0);
                                $isDone   = $s['order_no'] < ($app['status_order'] ?? 0);
                                $dotClass = $isActive ? 'active' : ($isDone ? 'done' : '');
                            ?>
                            <div class="timeline-item">
                                <div class="timeline-dot <?= $dotClass ?>" <?= $isActive ? 'style="background:'.htmlspecialchars($s['color']).';border-color:'.htmlspecialchars($s['color']).'"' : '' ?>></div>
                                <span class="small <?= $isActive ? 'fw-bold' : 'text-muted' ?>" style="<?= $isActive ? 'color:'.htmlspecialchars($s['color']) : '' ?>">
                                    <?= $isActive ? '▶ ' : '' ?><?= htmlspecialchars($s['name']) ?>
                                    <?= $isActive ? ' ← আপনি এখানে' : '' ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="text-muted small mt-2">আবেদন: <?= date('d/m/Y', strtotime($app['created_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($applications)): ?>
                    <div class="text-center text-muted py-4">কোনো আবেদন নেই</div>
                    <?php endif; ?>
                </div>

                <!-- Payments Tab -->
                <div class="tab-pane fade" id="tab-pay">
                    <?php foreach ($payments as $p): ?>
                    <div class="card p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($p['service_name'] ?? 'সেবা') ?></div>
                                <?php if ($p['tracking_id']): ?>
                                <code class="small text-muted"><?= htmlspecialchars($p['tracking_id']) ?></code>
                                <?php endif; ?>
                            </div>
                            <span class="badge <?= $p['balance'] <= 0 ? 'bg-success' : 'bg-warning text-dark' ?>">
                                <?= $p['balance'] <= 0 ? 'পরিশোধিত' : 'বকেয়া আছে' ?>
                            </span>
                        </div>
                        <hr class="my-2">
                        <div class="row text-center small">
                            <div class="col-4"><div class="text-muted">মোট</div><div class="fw-bold"><?= number_format($p['total_amount'],2) ?></div></div>
                            <div class="col-4"><div class="text-muted">জমা</div><div class="fw-bold text-success"><?= number_format($p['paid_amount'],2) ?></div></div>
                            <div class="col-4"><div class="text-muted">বকেয়া</div><div class="fw-bold <?= $p['balance']>0?'text-danger':'' ?>"><?= number_format($p['balance'],2) ?></div></div>
                        </div>
                        <div class="text-muted small mt-2"><?= $p['currency'] ?> | <?= date('d/m/Y', strtotime($p['created_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($payments)): ?>
                    <div class="text-center text-muted py-4">কোনো পেমেন্ট রেকর্ড নেই</div>
                    <?php endif; ?>
                </div>

                <!-- Documents Tab -->
                <div class="tab-pane fade" id="tab-docs">
                    <?php foreach ($documents as $doc):
                        $ocr = json_decode($doc['extracted_data'] ?? '{}', true) ?: [];
                    ?>
                    <div class="doc-item">
                        <span style="font-size:1.4rem">📄</span>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small"><?= htmlspecialchars($doc['file_name'] ?? 'ডকুমেন্ট') ?></div>
                            <?php if (!empty($ocr['doc_type'])): ?>
                            <span class="badge bg-secondary" style="font-size:0.65rem"><?= htmlspecialchars($ocr['doc_type']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($doc['expiry_date'])): ?>
                            <?php $daysLeft = (int)ceil((strtotime($doc['expiry_date'])-time())/86400); ?>
                            <span class="badge <?= $daysLeft<=30?'bg-danger':($daysLeft<=90?'bg-warning text-dark':'bg-success') ?>" style="font-size:0.65rem">
                                মেয়াদ: <?= date('d/m/Y', strtotime($doc['expiry_date'])) ?>
                                <?= $daysLeft>0?"({$daysLeft}d)":"(মেয়াদ শেষ)" ?>
                            </span>
                            <?php endif; ?>
                            <div class="text-muted" style="font-size:0.7rem"><?= date('d/m/Y H:i', strtotime($doc['created_at'])) ?></div>
                        </div>
                        <div class="d-flex gap-1">
                        <?php if ($doc['drive_url']): ?>
                        <a href="<?= htmlspecialchars($doc['drive_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">📥 Drive</a>
                        <?php endif; ?>
                        <?php if (!empty($doc['extracted_data'])): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, [])) ?>&dl=ocr&doc=<?= $doc['id'] ?>" class="btn btn-sm btn-outline-secondary">📄 OCR</a>
                        <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($documents)): ?>
                    <div class="text-center text-muted py-4">কোনো ডকুমেন্ট নেই</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$customer && !$error): ?>
    <div class="row justify-content-center">
        <div class="col-lg-6">
          <div class="empty-state">
            <div class="icon">🔍</div>
            <h5 style="color:var(--text2);font-weight:600">ট্র্যাকিং নম্বর দিয়ে আবেদনের অবস্থা জানুন</h5>
            <p style="color:var(--text3);font-size:.85rem;margin-top:8px">মোবাইল নম্বর দিয়েও সার্চ করতে পারবেন</p>
          </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
