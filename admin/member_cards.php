<?php
// admin/member_cards.php — Member Card ম্যানেজমেন্ট
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/config/security.php';
Security::requireAdminAuth();
Security::setSecurityHeaders();

$db   = Database::getInstance();
$csrf = Security::generateCSRFToken();

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!Security::verifyCSRFToken($_POST['csrf_token']??'')) { echo json_encode(['success'=>false]); exit; }

    if ($_POST['action']==='generate_card') {
        $cid = Security::sanitizeInt($_POST['customer_id']??0);
        $c   = $db->fetchOne("SELECT * FROM customers WHERE id=?",[$cid]);
        if (!$c) { echo json_encode(['success'=>false,'message'=>'গ্রাহক পাওয়া যায়নি']); exit; }
        try {
            require_once dirname(__DIR__).'/services/Services.php';
            require_once dirname(__DIR__).'/vendor/phpqrcode/qrlib.php';
            $qr  = new QRCodeService();
            $url = $qr->generateForCustomer($cid);
            $qr->sendCardToCustomer($db->fetchOne("SELECT * FROM customers WHERE id=?",[$cid]));
            echo json_encode(['success'=>true,'qr_url'=>$url,'message'=>'✅ Card তৈরি হয়েছে ও গ্রাহককে পাঠানো হয়েছে!']);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action']==='generate_all') {
        $customers = $db->fetchAll("SELECT * FROM customers WHERE onboarding_done=1 AND (qr_code IS NULL OR qr_code='') LIMIT 50");
        require_once dirname(__DIR__).'/services/Services.php';
        require_once dirname(__DIR__).'/vendor/phpqrcode/qrlib.php';
        $qr   = new QRCodeService();
        $done = 0;
        foreach ($customers as $c) {
            try { if ($qr->generateForCustomer($c['id'])) $done++; usleep(200000); }
            catch(Exception $e) { Logger::error("Card gen #{$c['id']}: ".$e->getMessage()); }
        }
        echo json_encode(['success'=>true,'message'=>"{$done}টি Member Card তৈরি হয়েছে।"]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown']); exit;
}

$customers = $db->fetchAll(
    "SELECT c.*, co.name_bn as country_name FROM customers c
     LEFT JOIN countries co ON c.country_id=co.id
     WHERE c.onboarding_done=1
     ORDER BY c.created_at DESC"
);
$totalWithCard    = $db->count("SELECT COUNT(*) as c FROM customers WHERE qr_code IS NOT NULL AND qr_code!=''");
$totalWithoutCard = $db->count("SELECT COUNT(*) as c FROM customers WHERE onboarding_done=1 AND (qr_code IS NULL OR qr_code='')");
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
<?php $currentPage = 'member_cards'; include __DIR__.'/sidebar.php'; ?>

<div class="main">
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold">🪪 Member Card ম্যানেজমেন্ট</h4>
    <button class="btn btn-success" onclick="generateAll()">
        <i class="fa fa-magic"></i> সবার Card তৈরি করুন (<?=$totalWithoutCard?>টি বাকি)
    </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card p-3 text-center">
        <div class="fw-bold fs-3 text-success"><?=$totalWithCard?></div>
        <div class="text-muted small">Card তৈরি হয়েছে</div>
    </div></div>
    <div class="col-md-4"><div class="card p-3 text-center">
        <div class="fw-bold fs-3 text-warning"><?=$totalWithoutCard?></div>
        <div class="text-muted small">Card বাকি</div>
    </div></div>
    <div class="col-md-4"><div class="card p-3 text-center">
        <div class="fw-bold fs-3 text-primary"><?=count($customers)?></div>
        <div class="text-muted small">মোট গ্রাহক</div>
    </div></div>
</div>

<!-- How it works -->
<div class="alert alert-info mb-3">
    <strong>🪪 Member Card কী?</strong> প্রতিটি গ্রাহকের জন্য একটি ডিজিটাল পরিচয়পত্র তৈরি হয়।
    এতে তার Member ID, QR Code থাকে। গ্রাহক "card" লিখলে বট তাকে এই Card পাঠায়।
    QR Code স্ক্যান করলে তার Status Track page খোলে।
</div>

<!-- Search -->
<div class="mb-3"><input type="text" id="srch" class="form-control" placeholder="নাম/মোবাইল সার্চ..." style="max-width:300px"></div>

<div class="card p-3">
<div class="table-responsive">
<table class="table table-hover" id="cardTbl">
    <thead><tr><th>Member ID</th><th>নাম</th><th>মোবাইল</th><th>Platform</th><th>QR Code</th><th>অ্যাকশন</th></tr></thead>
    <tbody>
    <?php foreach ($customers as $c):
        $memId = 'MEM-'.str_pad($c['id'],6,'0',STR_PAD_LEFT);
    ?>
    <tr>
        <td><code class="small"><?=$memId?></code></td>
        <td><?=htmlspecialchars($c['name']??'—')?></td>
        <td><?=htmlspecialchars($c['mobile']??'—')?></td>
        <td><span class="badge bg-info"><?=strtoupper($c['platform'])?></span></td>
        <td>
            <?php if ($c['qr_code']): ?>
            <a href="<?=htmlspecialchars($c['qr_code'])?>" target="_blank">
                <img src="<?=htmlspecialchars($c['qr_code'])?>" style="width:45px;height:45px;border-radius:4px" alt="QR">
            </a>
            <?php else: ?>
            <span class="text-muted small">তৈরি হয়নি</span>
            <?php endif; ?>
        </td>
        <td class="d-flex gap-1">
            <button class="btn btn-sm btn-primary py-0" onclick="generateCard(<?=(int)$c['id']?>)">
                <i class="fa fa-id-card"></i> <?=$c['qr_code']?'পুনরায় তৈরি':'তৈরি করুন'?>
            </button>
            <?php if ($c['qr_code']): ?>
            <a href="<?=htmlspecialchars($c['qr_code'])?>" target="_blank" class="btn btn-sm btn-outline-success py-0">
                <i class="fa fa-eye"></i>
            </a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF='<?=$csrf?>';
async function api(data){
    try {
        const r=await fetch('/admin/api.php', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:new URLSearchParams({...data,csrf_token:CSRF})
        });
        if(!r.ok){console.error('HTTP',r.status);if(r.status===403){alert('Session মেয়াদ শেষ। পুনরায় লগইন করুন।');location.reload();}return{success:false};}
        const t=await r.text();
        try{return JSON.parse(t);}catch(e){console.error('Non-JSON:',t.substring(0,100));return{success:false};}
    }catch(e){console.error(e);return{success:false};}
}

document.getElementById('srch').addEventListener('input',function(){
    const v=this.value.toLowerCase();
    document.querySelectorAll('#cardTbl tbody tr').forEach(r=>r.style.display=r.textContent.toLowerCase().includes(v)?'':'none');
});

async function generateCard(cid){
    showToast('Card তৈরি হচ্ছে...','info');
    const r=await api({action:'generate_card',customer_id:cid});
    showToast(r.success?r.message:'❌ '+(r.message||'সমস্যা'),r.success?'success':'danger');
    if(r.success) setTimeout(()=>location.reload(),2000);
}

async function generateAll(){
    if(!confirm('সব গ্রাহকের Member Card তৈরি করবেন? (কিছুক্ষণ সময় লাগতে পারে)')) return;
    showToast('তৈরি হচ্ছে...','info');
    const r=await api({action:'generate_all'});
    showToast(r.success?r.message:'❌ '+(r.message||'সমস্যা'),r.success?'success':'danger');
    if(r.success) setTimeout(()=>location.reload(),2000);
}

function showToast(msg,type='info'){const t=document.createElement('div');t.className=`alert alert-${type} position-fixed bottom-0 end-0 m-3 shadow`;t.style.cssText='z-index:9999;min-width:280px;border-radius:12px';t.textContent=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),4000);}
</script>
</body>
</html>
