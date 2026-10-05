<?php
// ============================================================
// admin/documents.php
// গ্রাহকদের সব ডকুমেন্ট + OCR ডেটা + মেয়াদ স্ট্যাটাস
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/security.php';
Security::requireAdminAuth();

// OCR TXT ডাউনলোড
if (isset($_GET['dl']) && $_GET['dl'] === 'ocr' && isset($_GET['doc'])) {
    $db  = Database::getInstance();
    $did = (int)$_GET['doc'];
    $doc = $db->fetchOne("SELECT * FROM documents WHERE id=?", [$did]);
    if ($doc && $doc['extracted_data']) {
        $data  = json_decode($doc['extracted_data'], true) ?: [];
        $lines = ["Document OCR Data", str_repeat("=",40)];
        $map   = ['doc_type'=>'Document Type','name'=>'Name','holder_name'=>'Holder Name',
                  'passport_no'=>'Passport No','dob'=>'Date of Birth','expiry_date'=>'Expiry Date',
                  'issue_date'=>'Issue Date','nationality'=>'Nationality','issue_country'=>'Issued In',
                  'visa_type'=>'Visa Type'];
        foreach ($map as $k => $label) {
            $v = $data[$k] ?? $doc[$k] ?? null;
            if ($v) $lines[] = "{$label}: {$v}";
        }
        $lines[] = str_repeat("-",40);
        $lines[] = "Exported: ".date('d/m/Y H:i');
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="ocr_doc_'.$did.'.txt"');
        echo implode("\n", $lines);
        exit;
    }
}
Security::setSecurityHeaders();
require_once dirname(__DIR__) . '/services/OCRService.php';

$db   = Database::getInstance();
$csrf = Security::generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF error']); exit;
    }

    if ($_POST['action'] === 'delete_document') {
        $did = Security::sanitizeInt($_POST['doc_id'] ?? 0);
        $doc = $db->fetchOne("SELECT * FROM documents WHERE id=?", [$did]);
        if ($doc) {
            // Drive থেকে মুছা
            if (!empty($doc['drive_file_id'])) {
                try {
                    require_once dirname(__DIR__) . '/services/GoogleDriveService.php';
                    $gd = new GoogleDriveService();
                    if ($gd->isReady()) $gd->deleteFile($doc['drive_file_id']);
                } catch (Exception $e) { Logger::error("Del doc drive: " . $e->getMessage()); }
            }
            // Related reminders মুছা
            $db->execute("DELETE FROM reminders WHERE document_id=?", [$did]);
            $db->execute("DELETE FROM documents WHERE id=?", [$did]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Not found']);
        }
        exit;
    }

    if ($_POST['action'] === 'send_manual_reminder') {
        $did = Security::sanitizeInt($_POST['doc_id'] ?? 0);
        $doc = $db->fetchOne(
            "SELECT d.*, c.platform, c.platform_id, c.name, c.language FROM documents d JOIN customers c ON d.customer_id=c.id WHERE d.id=?",
            [$did]
        );
        if ($doc && $doc['expiry_date']) {
            require_once dirname(__DIR__) . '/bot/Sender.php';
            $sender  = new Sender();
            $daysLeft = (int) ceil((strtotime($doc['expiry_date']) - time()) / 86400);
            $expStr  = date('d/m/Y', strtotime($doc['expiry_date']));
            $docType = match($doc['doc_type'] ?? '') {
                'passport' => 'পাসপোর্ট', 'visa' => 'ভিসা',
                'nid' => 'জাতীয় পরিচয়পত্র', default => 'ডকুমেন্ট'
            };
            $numStr = !empty($doc['doc_number']) ? " ({$doc['doc_number']})" : '';
            $msg = $daysLeft <= 0
                ? "🔴 আপনার *{$docType}{$numStr}* এর মেয়াদ শেষ হয়ে গেছে! ({$expStr}) দ্রুত রিনিউ করুন।"
                : "⚠️ আপনার *{$docType}{$numStr}* মেয়াদ শেষ হবে {$expStr} তারিখে। আর {$daysLeft} দিন বাকি।\n\n📞 " . Config::get('company_phone','');
            $sender->send($doc['platform'], $doc['platform_id'], $msg);
            echo json_encode(['success' => true, 'message' => 'Reminder পাঠানো হয়েছে']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Document বা expiry date নেই']);
        }
        exit;
    }
    exit;
}

// Filters
$filter    = $_GET['filter']   ?? 'all';   // all, expiring, expired, no_ocr
$search    = Security::sanitizeString($_GET['q'] ?? '', 100);
$today     = date('Y-m-d');
$in30      = date('Y-m-d', strtotime('+30 days'));

// Build query
$where = "WHERE 1=1";
$params = [];

if ($filter === 'expiring') {
    $where .= " AND d.expiry_date BETWEEN ? AND ?";
    $params[] = $today; $params[] = $in30;
} elseif ($filter === 'expired') {
    $where .= " AND d.expiry_date < ?";
    $params[] = $today;
} elseif ($filter === 'no_ocr') {
    $where .= " AND d.ocr_processed = 0 AND d.doc_type IS NOT NULL";
}

if ($search) {
    $where .= " AND (c.name LIKE ? OR c.mobile LIKE ? OR d.doc_number LIKE ? OR d.holder_name LIKE ?)";
    $s = "%{$search}%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}

$docs = $db->fetchAll(
    "SELECT d.*, c.name as cust_name, c.mobile, c.platform, c.platform_id, c.language
     FROM documents d
     JOIN customers c ON d.customer_id = c.id
     {$where}
     ORDER BY
       CASE WHEN d.expiry_date IS NOT NULL THEN 0 ELSE 1 END,
       d.expiry_date ASC,
       d.created_at DESC
     LIMIT 200",
    $params
);

// Stats
$stats = [
    'total'    => $db->fetchOne("SELECT COUNT(*) as c FROM documents")['c'] ?? 0,
    'ocr_done' => $db->fetchOne("SELECT COUNT(*) as c FROM documents WHERE ocr_processed=1")['c'] ?? 0,
    'expiring' => $db->fetchOne("SELECT COUNT(*) as c FROM documents WHERE expiry_date BETWEEN ? AND ?", [$today, $in30])['c'] ?? 0,
    'expired'  => $db->fetchOne("SELECT COUNT(*) as c FROM documents WHERE expiry_date < ?", [$today])['c'] ?? 0,
];

$filterLabels = ['all'=>'সব', 'expiring'=>"মেয়াদ শেষ হচ্ছে ({$stats['expiring']})", 'expired'=>"মেয়াদ শেষ ({$stats['expired']})", 'no_ocr'=>'OCR হয়নি'];
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
<?php $currentPage = 'documents'; include __DIR__.'/sidebar.php'; ?>

<div class="main">
<h4 class="fw-bold mb-3">📄 ডকুমেন্ট ম্যানেজমেন্ট</h4>

<!-- Stats -->
<div class="row g-3 mb-4">
    <?php foreach ([
        ['মোট ডকুমেন্ট', $stats['total'],    '#1a3c6e', 'fa-file'],
        ['OCR সম্পন্ন',  $stats['ocr_done'], '#27ae60', 'fa-check-circle'],
        ['মেয়াদ শেষ হচ্ছে',$stats['expiring'],'#e67e22','fa-clock'],
        ['মেয়াদ শেষ',    $stats['expired'],  '#e74c3c', 'fa-exclamation-circle'],
    ] as [$label, $val, $color, $icon]): ?>
    <div class="col-md-3 col-6">
        <div class="card p-3 text-center">
            <i class="fa <?=$icon?> mb-1" style="color:<?=$color?>;font-size:1.3rem"></i>
            <div class="fw-bold fs-3" style="color:<?=$color?>"><?=$val?></div>
            <div class="small text-muted"><?=$label?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filter + Search -->
<div class="card p-3 mb-3">
    <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
        <div class="btn-group">
            <?php foreach ($filterLabels as $k => $lbl): ?>
            <a href="?filter=<?=$k?><?=$search?"&q=".urlencode($search):''?>" class="btn btn-sm <?=$filter===$k?'btn-primary':'btn-outline-secondary'?>"><?=$lbl?></a>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="filter" value="<?=$filter?>">
        <input name="q" class="form-control form-control-sm w-auto" placeholder="নাম / মোবাইল / নম্বর সার্চ..." value="<?=htmlspecialchars($search)?>">
        <button class="btn btn-sm btn-primary">🔍</button>
        <?php if($search): ?><a href="?filter=<?=$filter?>" class="btn btn-sm btn-outline-secondary">✕ Clear</a><?php endif; ?>
    </form>
</div>

<!-- Table -->
<div class="card p-3">
<div class="table-responsive">
<table class="table table-hover">
    <thead>
        <tr>
            <th>গ্রাহক</th>
            <th>ডকুমেন্ট</th>
            <th>নম্বর</th>
            <th>মেয়াদ শেষ</th>
            <th>অবস্থা</th>
            <th>স্ক্যান তারিখ</th>
            <th>অ্যাকশন</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($docs as $d):
        $exp      = $d['expiry_date'];
        $daysLeft = $exp ? (int) ceil((strtotime($exp) - time()) / 86400) : null;
        $status   = $daysLeft === null ? 'unknown'
            : ($daysLeft <= 0 ? 'expired'
            : ($daysLeft <= 30 ? 'critical'
            : ($daysLeft <= 90 ? 'warning' : 'valid')));
        $statusLabels = ['expired'=>'মেয়াদ শেষ', 'critical'=>'জরুরি', 'warning'=>'সতর্কতা', 'valid'=>'ঠিক আছে', 'unknown'=>'তারিখ নেই'];
        $statusLabel  = $statusLabels[$status];
        $docTypeLabel = match($d['doc_type'] ?? '') {
            'passport'=>'🛂 পাসপোর্ট','visa'=>'📋 ভিসা','nid'=>'🪪 NID',
            'driving_license'=>'🚗 DL','birth_certificate'=>'📜 জন্ম সনদ',
            default=>($d['doc_type'] ? '📄 '.ucfirst($d['doc_type']) : '📎 ফাইল')
        };
    ?>
    <tr>
        
    </tr>
    <?php endforeach; ?>
    <?php if(empty($docs)): ?>
    <tr><td colspan="7" class="text-center text-muted py-5">কোনো ডকুমেন্ট নেই</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
</div>
</div><!-- /main -->

<!-- OCR Modal -->
<div class="modal fade" id="ocrModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">🔍 OCR ডেটা</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><pre id="ocrData" class="bg-light p-3 rounded small" style="max-height:400px;overflow-y:auto"></pre></div>
</div></div></div>

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

let ocrModal;
document.addEventListener('DOMContentLoaded',()=>{ocrModal=new bootstrap.Modal('#ocrModal');});

function showOCR(data) {
    const labels = {
        doc_type:'ডকুমেন্ট ধরন', holder_name:'নাম', father_name:'পিতার নাম', mother_name:'মাতার নাম',
        date_of_birth:'জন্ম তারিখ', gender:'লিঙ্গ', nationality:'জাতীয়তা', doc_number:'নম্বর',
        visa_type:'ভিসার ধরন', issue_date:'ইস্যু তারিখ', expiry_date:'মেয়াদ শেষ',
        issue_country:'ইস্যুকারী দেশ', destination_country:'গন্তব্য দেশ',
        days_until_expiry:'বাকি দিন', expiry_status:'মেয়াদ অবস্থা',
    };
    let html = '';
    for(const [k,label] of Object.entries(labels)){
        if(data[k]!==null && data[k]!==undefined && data[k]!=='null'){
            html += `<tr><td class="fw-semibold">${label}</td><td>${data[k]}</td></tr>`;
        }
    }
    document.getElementById('ocrData').innerHTML = html
        ? '<table class="table table-sm table-bordered mb-0">'+html+'</table>'
        : '<em>ডেটা নেই</em>';
    ocrModal.show();
}

async function deleteDoc(id){
    if(!confirm('এই ডকুমেন্ট ও Drive থেকে মুছে ফেলবেন?')) return;
    const r=await api({action:'delete_document',doc_id:id});
    if(r.success)location.reload();
    else alert('সমস্যা: '+(r.message||'Unknown'));
}

async function sendReminder(id){
    const r=await api({action:'send_manual_reminder',doc_id:id});
    alert(r.success ? '✅ '+r.message : '❌ '+(r.message||'সমস্যা'));
}
</script>
</body>
</html>
