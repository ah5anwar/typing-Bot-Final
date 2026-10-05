<?php
// admin/billing.php — বিল ম্যানেজমেন্ট
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/config/security.php';
Security::requireAdminAuth();
Security::setSecurityHeaders();

$db   = Database::getInstance();
$csrf = Security::generateCSRFToken();

// ── AJAX ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $act = $_POST['action'];

    switch ($act) {
        case 'add_payment':
            $cid   = (int)($_POST['customer_id'] ?? 0);
            $appId = !empty($_POST['application_id']) ? (int)$_POST['application_id'] : null;
            $total = (float)($_POST['total_amount'] ?? 0);
            $paid  = (float)($_POST['paid_amount'] ?? 0);
            $cur   = Security::sanitizeString($_POST['currency'] ?? 'BDT', 10);
            $desc  = Security::sanitizeString($_POST['description'] ?? '', 500);
            $due   = (float)($_POST['due_date'] ?? 0);
            if (!$cid || $total <= 0) { echo json_encode(['success'=>false,'message'=>'তথ্য অসম্পূর্ণ']); exit; }
            $pid = $db->insert(
                "INSERT INTO payments(customer_id,application_id,total_amount,paid_amount,currency,description,created_at) VALUES(?,?,?,?,?,?,NOW())",
                [$cid,$appId,$total,$paid,$cur,$desc]
            );
            echo json_encode(['success'=>true,'id'=>$pid,'message'=>'✅ পেমেন্ট যোগ হয়েছে']); exit;

        case 'add_installment':
            $payId = (int)($_POST['payment_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $note = Security::sanitizeString($_POST['note'] ?? '', 300);
            if (!$payId || $amount <= 0) { echo json_encode(['success'=>false,'message'=>'তথ্য ভুল']); exit; }
            $pay = $db->fetchOne("SELECT * FROM payments WHERE id=?", [$payId]);
            if (!$pay) { echo json_encode(['success'=>false,'message'=>'পেমেন্ট পাওয়া যায়নি']); exit; }
            $newPaid = min((float)$pay['total_amount'], (float)$pay['paid_amount'] + $amount);
            $db->execute("UPDATE payments SET paid_amount=? WHERE id=?", [$newPaid, $payId]);
            // Log installment
            try {
                $db->execute(
                    "INSERT INTO payment_transactions(payment_id,amount,note,created_at) VALUES(?,?,?,NOW())",
                    [$payId, $amount, $note]
                );
            } catch(Exception $e) {}
            echo json_encode(['success'=>true,'paid'=>$newPaid,'due'=>(float)$pay['total_amount']-$newPaid,'message'=>"✅ কিস্তি যোগ হয়েছে! মোট জমা: ".number_format($newPaid,0)." BDT"]); exit;

        case 'delete_payment':
            $pid = (int)($_POST['payment_id'] ?? 0);
            try { $db->execute("DELETE FROM payment_transactions WHERE payment_id=?",[$pid]); } catch(Exception $e) {}
            $db->execute("DELETE FROM payments WHERE id=?",[$pid]);
            echo json_encode(['success'=>true]); exit;

        case 'get_payment_detail':
            $pid = (int)($_POST['payment_id'] ?? 0);
            $p = $db->fetchOne("SELECT p.*,c.name,c.mobile,a.tracking_id,s.name as sname FROM payments p LEFT JOIN customers c ON p.customer_id=c.id LEFT JOIN applications a ON p.application_id=a.id LEFT JOIN services s ON a.service_id=s.id WHERE p.id=?",[$pid]);
            $txns = [];
            try { $txns = $db->fetchAll("SELECT * FROM payment_transactions WHERE payment_id=? ORDER BY created_at DESC",[$pid]); } catch(Exception $e) {}
            echo json_encode(['success'=>true,'payment'=>$p,'transactions'=>$txns]); exit;
    }
    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

// ── Data ───────────────────────────────────────────────────────
$currencyList = Config::get('system_currencies', 'BDT,AED,SAR,OMR,KWD,BHD,QAR,INR,PKR,USD');
$currencies   = array_filter(array_map('trim', explode(',', $currencyList)));
$filter = $_GET['filter'] ?? 'all'; // all, due, paid
$search = trim($_GET['q'] ?? '');

$sql = "SELECT p.*,c.name,c.mobile,c.id as cid,a.tracking_id,s.name as sname
        FROM payments p
        LEFT JOIN customers c ON p.customer_id=c.id
        LEFT JOIN applications a ON p.application_id=a.id
        LEFT JOIN services s ON a.service_id=s.id
        WHERE 1=1";
$params = [];
if ($search) { $sql .= " AND (c.name LIKE ? OR c.mobile LIKE ? OR a.tracking_id LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($filter === 'due')  $sql .= " AND p.paid_amount < p.total_amount";
if ($filter === 'paid') $sql .= " AND p.paid_amount >= p.total_amount";
$sql .= " ORDER BY p.created_at DESC";
$payments = $db->fetchAll($sql, $params);

// Summary
$totalBill = array_sum(array_column($payments,'total_amount'));
$totalPaid = array_sum(array_column($payments,'paid_amount'));
$totalDue  = $totalBill - $totalPaid;

// Customers for dropdown
$customers = $db->fetchAll("SELECT id,name,mobile FROM customers WHERE onboarding_done=1 ORDER BY name");
// Applications for dropdown
$applications = $db->fetchAll("SELECT a.id,a.tracking_id,c.name as cname,s.name as sname FROM applications a LEFT JOIN customers c ON a.customer_id=c.id LEFT JOIN services s ON a.service_id=s.id ORDER BY a.created_at DESC LIMIT 200");
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
<?php include __DIR__.'/sidebar.php'; ?>

<div class="main">

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card p-3 text-center border-primary">
            <div class="text-muted small">মোট বিল</div>
            <div class="fw-bold fs-4 text-primary"><?= number_format($totalBill,0) ?> ৳</div>
            <div class="text-muted small"><?= count($payments) ?>টি রেকর্ড</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3 text-center border-success">
            <div class="text-muted small">মোট জমা</div>
            <div class="fw-bold fs-4 text-success"><?= number_format($totalPaid,0) ?> ৳</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3 text-center border-danger">
            <div class="text-muted small">মোট বকেয়া</div>
            <div class="fw-bold fs-4 text-danger"><?= number_format($totalDue,0) ?> ৳</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3 text-center border-warning">
            <div class="text-muted small">আদায় হার</div>
            <div class="fw-bold fs-4 text-warning"><?= $totalBill > 0 ? number_format($totalPaid/$totalBill*100,1) : 0 ?>%</div>
        </div>
    </div>
</div>

<!-- Controls -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="fw-bold mb-0">💰 বিল ম্যানেজমেন্ট</h5>
    <div class="d-flex gap-2 flex-wrap">
        <form class="d-flex gap-2" method="GET">
            <input name="q" class="form-control form-control-sm" placeholder="গ্রাহক/ট্র্যাকিং সার্চ..." value="<?= htmlspecialchars($search) ?>" style="width:200px">
            <select name="filter" class="form-select form-select-sm" style="width:120px" onchange="this.form.submit()">
                <option value="all" <?= $filter==='all'?'selected':'' ?>>সব</option>
                <option value="due" <?= $filter==='due'?'selected':'' ?>>বকেয়া আছে</option>
                <option value="paid" <?= $filter==='paid'?'selected':'' ?>>পরিশোধ</option>
            </select>
            <button class="btn btn-sm btn-outline-secondary">🔍</button>
        </form>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addBillModal">
            ➕ নতুন বিল
        </button>
    </div>
</div>

<!-- Table -->
<div class="card p-0 shadow-sm">
<div class="table-responsive">
<table class="table table-hover mb-0" id="billTable">
<thead class="table-dark">
<tr><th>গ্রাহক</th><th>সেবা/ট্র্যাকিং</th><th>মোট বিল</th><th>জমা</th><th>বকেয়া</th><th>স্ট্যাটাস</th><th>তারিখ</th><th>Action</th></tr>
</thead>
<tbody>
<?php foreach ($payments as $p):
    $total = (float)$p['total_amount'];
    $paid  = (float)$p['paid_amount'];
    $due   = $total - $paid;
    $pct   = $total > 0 ? round($paid/$total*100) : 0;
    $status = $due <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'due');
    $badges = ['paid'=>'<span class="badge badge-paid">✅ পরিশোধ</span>',
               'partial'=>'<span class="badge badge-partial">⚡ আংশিক</span>',
               'due'=>'<span class="badge badge-due">❌ বকেয়া</span>'];
?>
<tr>
    <td>
        <div class="fw-semibold"><?= htmlspecialchars($p['name'] ?? '—') ?></div>
        <small class="text-muted"><?= htmlspecialchars($p['mobile'] ?? '') ?></small>
    </td>
    <td>
        <div class="small"><?= htmlspecialchars($p['sname'] ?? '—') ?></div>
        <?php if ($p['tracking_id']): ?>
        <code class="small"><?= htmlspecialchars($p['tracking_id']) ?></code>
        <?php endif; ?>
        <?php if ($p['description']): ?>
        <div class="text-muted small"><?= htmlspecialchars(substr($p['description'],0,40)) ?></div>
        <?php endif; ?>
    </td>
    <td class="fw-semibold"><?= number_format($total,0) ?> <small><?= $p['currency'] ?></small></td>
    <td class="text-success"><?= number_format($paid,0) ?></td>
    <td class="<?= $due > 0 ? 'text-danger fw-bold' : 'text-success' ?>"><?= number_format($due,0) ?></td>
    <td>
        <?= $badges[$status] ?>
        <div class="progress mt-1" style="height:4px;width:70px">
            <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
        </div>
    </td>
    <td><small><?= date('d/m/Y', strtotime($p['created_at'])) ?></small></td>
    <td>
        <div class="d-flex gap-1">
            <?php if ($due > 0): ?>
            <button class="btn btn-xs btn-success py-0 px-1" style="font-size:.75rem"
                onclick="openInstallment(<?= $p['id'] ?>,<?= $due ?>,<?=json_encode($p['name']??'')?>)">
                + কিস্তি
            </button>
            <?php endif; ?>
            <button class="btn btn-xs btn-info py-0 px-1" style="font-size:.75rem"
                onclick="viewDetail(<?= $p['id'] ?>)">📋</button>
            <button class="btn btn-xs btn-danger py-0 px-1" style="font-size:.75rem"
                onclick="deleteBill(<?= $p['id'] ?>)">🗑️</button>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($payments)): ?>
<tr><td colspan="8" class="text-center py-5 text-muted">কোনো বিল নেই</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

</div> <!-- main-content -->

<!-- Add Bill Modal -->
<div class="modal fade" id="addBillModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header bg-primary text-white"><h5>💰 নতুন বিল যোগ করুন</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label fw-semibold">গ্রাহক <span class="text-danger">*</span></label>
            <select id="bill_cid" class="form-select" onchange="loadCustomerApps(this.value)">
                <option value="">— গ্রাহক বেছে নিন —</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['mobile']??'') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">আবেদন (ঐচ্ছিক)</label>
            <select id="bill_appid" class="form-select">
                <option value="">— আবেদন নির্বাচন করুন —</option>
                <?php foreach ($applications as $a): ?>
                <option value="<?= $a['id'] ?>" data-cid="<?= $a['id'] ?>">
                    <?= htmlspecialchars($a['tracking_id']) ?> — <?= htmlspecialchars($a['sname']??'') ?> (<?= htmlspecialchars($a['cname']??'') ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">মোট বিল <span class="text-danger">*</span></label>
            <div class="input-group">
                <input id="bill_total" type="number" class="form-control" placeholder="10000" min="0" step="0.01">
                <span class="input-group-text">৳</span>
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">প্রথম জমা</label>
            <div class="input-group">
                <input id="bill_paid" type="number" class="form-control" placeholder="5000" min="0" step="0.01" value="0">
                <span class="input-group-text">৳</span>
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">মুদ্রা</label>
            <select id="bill_cur" class="form-select">
                <?php foreach ($currencies as $cur): ?>
                <option value="<?= htmlspecialchars($cur) ?>" <?= $cur==='BDT'?'selected':'' ?>><?= htmlspecialchars($cur) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label fw-semibold">বিবরণ</label>
            <input id="bill_desc" class="form-control" placeholder="যেমন: সৌদি ভিসা প্রসেসিং ফি">
        </div>
    </div>
    <div id="addBillMsg" class="mt-3"></div>
</div>
<div class="modal-footer">
    <button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button>
    <button class="btn btn-primary" onclick="saveBill()">💾 বিল সেভ করুন</button>
</div>
</div></div></div>

<!-- Installment Modal -->
<div class="modal fade" id="installModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<div class="modal-header bg-success text-white"><h5>+ কিস্তি যোগ করুন</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <input type="hidden" id="inst_pid">
    <div id="instName" class="alert alert-info py-2 small mb-3"></div>
    <div class="mb-3">
        <label class="form-label fw-semibold">কিস্তির পরিমাণ <span class="text-danger">*</span></label>
        <div class="input-group">
            <input id="inst_amount" type="number" class="form-control form-control-lg" placeholder="0" min="1" step="0.01">
            <span class="input-group-text">৳</span>
        </div>
        <div class="form-text">বকেয়া: <strong id="inst_due_display" class="text-danger"></strong> ৳</div>
    </div>
    <div class="mb-3">
        <label class="form-label">মন্তব্য</label>
        <input id="inst_note" class="form-control" placeholder="যেমন: ২য় কিস্তি - নগদ">
    </div>
    <div id="instMsg"></div>
</div>
<div class="modal-footer">
    <button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button>
    <button class="btn btn-success btn-lg" onclick="saveInstallment()">✅ কিস্তি নিশ্চিত করুন</button>
</div>
</div></div></div>

<!-- Detail Modal -->
<div class="modal fade" id="detailModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><h5>📋 পেমেন্ট বিস্তারিত</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body" id="detailBody">লোড হচ্ছে...</div>
</div></div></div>

<div id="toast" class="position-fixed bottom-0 end-0 m-3" style="z-index:9999"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = '<?= $csrf ?>';

async function api(data) {
    const r = await fetch(location.href, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({...data, csrf_token: CSRF})
    });
    const t = await r.text();
    try { return JSON.parse(t); } catch(e) { return {success:false,message:'Server error'}; }
}

let addBillModal, installModal, detailModal;
document.addEventListener('DOMContentLoaded', () => {
    addBillModal  = new bootstrap.Modal('#addBillModal');
    installModal  = new bootstrap.Modal('#installModal');
    detailModal   = new bootstrap.Modal('#detailModal');
});

async function saveBill() {
    const cid   = document.getElementById('bill_cid').value;
    const total = document.getElementById('bill_total').value;
    if (!cid || !total) { showMsg('addBillMsg','গ্রাহক ও মোট বিল দিন','danger'); return; }
    const r = await api({
        action:'add_payment',
        customer_id: cid,
        application_id: document.getElementById('bill_appid').value,
        total_amount: total,
        paid_amount: document.getElementById('bill_paid').value || 0,
        currency: document.getElementById('bill_cur').value,
        description: document.getElementById('bill_desc').value,
    });
    if (r.success) {
        showMsg('addBillMsg', r.message, 'success');
        setTimeout(() => location.reload(), 1200);
    } else {
        showMsg('addBillMsg', r.message || 'সমস্যা হয়েছে', 'danger');
    }
}

function openInstallment(pid, due, name) {
    document.getElementById('inst_pid').value = pid;
    document.getElementById('inst_due_display').textContent = parseFloat(due).toLocaleString();
    document.getElementById('instName').textContent = name + ' — বকেয়া: ' + parseFloat(due).toLocaleString() + ' ৳';
    document.getElementById('inst_amount').value = '';
    document.getElementById('inst_note').value = '';
    document.getElementById('instMsg').innerHTML = '';
    installModal.show();
    setTimeout(() => document.getElementById('inst_amount').focus(), 400);
}

async function saveInstallment() {
    const pid    = document.getElementById('inst_pid').value;
    const amount = document.getElementById('inst_amount').value;
    if (!amount || parseFloat(amount) <= 0) { showMsg('instMsg','পরিমাণ দিন','danger'); return; }
    const r = await api({
        action: 'add_installment',
        payment_id: pid,
        amount: amount,
        note: document.getElementById('inst_note').value,
    });
    if (r.success) {
        showMsg('instMsg', r.message, 'success');
        setTimeout(() => location.reload(), 1500);
    } else {
        showMsg('instMsg', r.message || 'সমস্যা', 'danger');
    }
}

async function viewDetail(pid) {
    document.getElementById('detailBody').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';
    detailModal.show();
    const r = await api({action:'get_payment_detail', payment_id:pid});
    if (!r.success) { document.getElementById('detailBody').innerHTML = '<div class="alert alert-danger">লোড হয়নি</div>'; return; }
    const p = r.payment;
    const total = parseFloat(p.total_amount||0);
    const paid = parseFloat(p.paid_amount||0);
    const due = total - paid;
    let html = '<div class="row g-3 mb-3">';
    html += '<div class="col-md-6"><strong>গ্রাহক:</strong> '+p.name+' ('+p.mobile+')</div>';
    html += '<div class="col-md-6"><strong>সেবা:</strong> '+(p.sname||'—')+' '+(p.tracking_id?'<code>'+p.tracking_id+'</code>':'')+'</div>';
    html += '<div class="col-md-4"><strong>মোট:</strong> '+total.toLocaleString()+' '+p.currency+'</div>';
    html += '<div class="col-md-4"><strong>জমা:</strong> <span class="text-success">'+paid.toLocaleString()+'</span></div>';
    html += '<div class="col-md-4"><strong>বকেয়া:</strong> <span class="'+(due>0?'text-danger fw-bold':'text-success')+'">'+due.toLocaleString()+'</span></div>';
    html += '</div>';
    if (r.transactions && r.transactions.length > 0) {
        html += '<h6 class="fw-bold">💳 কিস্তির ইতিহাস</h6>';
        html += '<table class="table table-sm table-bordered"><thead><tr><th>তারিখ</th><th>পরিমাণ</th><th>মন্তব্য</th></tr></thead><tbody>';
        r.transactions.forEach(t => {
            html += '<tr><td>'+t.created_at.substring(0,10)+'</td><td class="text-success fw-semibold">'+parseFloat(t.amount).toLocaleString()+' ৳</td><td>'+(t.note||'—')+'</td></tr>';
        });
        html += '</tbody></table>';
    } else {
        html += '<div class="text-muted small">কোনো কিস্তির রেকর্ড নেই।</div>';
    }
    document.getElementById('detailBody').innerHTML = html;
}

async function deleteBill(pid) {
    if (!confirm('এই বিল রেকর্ড মুছে ফেলবেন?')) return;
    const r = await api({action:'delete_payment', payment_id:pid});
    if (r.success) { showToast('✅ মুছে ফেলা হয়েছে','success'); setTimeout(()=>location.reload(),1000); }
    else showToast('❌ '+(r.message||'সমস্যা'),'danger');
}

function showMsg(id, msg, type) {
    document.getElementById(id).innerHTML = '<div class="alert alert-'+type+' py-2">'+msg+'</div>';
}

function showToast(msg, type='info') {
    const t = document.createElement('div');
    t.className = 'alert alert-'+type+' shadow';
    t.textContent = msg;
    document.getElementById('toast').appendChild(t);
    setTimeout(() => t.remove(), 3000);
}
</script>
</body>
</html>
