<?php
// ============================================================
// admin/fake_documents.php
// সন্দেহজনক ডকুমেন্ট পর্যালোচনা ও verdict
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/security.php';
Security::requireAdminAuth();
Security::setSecurityHeaders();

$db   = Database::getInstance();
$csrf = Security::generateCSRFToken();

// ── AJAX ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF error']); exit;
    }

    $act = $_POST['action'];

    // Admin verdict দেওয়া
    if ($act === 'set_verdict') {
        $sid     = Security::sanitizeInt($_POST['suspicious_id'] ?? 0);
        $verdict = in_array($_POST['verdict'] ?? '', ['genuine','fake','unclear']) ? $_POST['verdict'] : 'unclear';
        $note    = Security::sanitizeString($_POST['note'] ?? '', 500);

        $db->execute(
            "UPDATE suspicious_documents SET admin_reviewed=1, admin_verdict=?, admin_note=?, reviewed_at=NOW() WHERE id=?",
            [$verdict, $note, $sid]
        );

        // verdict: fake হলে গ্রাহককে জানানো
        if ($verdict === 'fake') {
            $sd = $db->fetchOne("SELECT sd.*, c.platform, c.platform_id, c.language FROM suspicious_documents sd JOIN customers c ON sd.customer_id=c.id WHERE sd.id=?", [$sid]);
            if ($sd) {
                require_once dirname(__DIR__) . '/bot/Sender.php';
                $sender = new Sender();
                $lang   = $sd['language'] ?? 'bn';
                $msg = $lang === 'bn'
                    ? "❌ *ডকুমেন্ট প্রত্যাখ্যান করা হয়েছে*\n\nআমাদের টিম আপনার ডকুমেন্ট যাচাই করেছে এবং এটি গ্রহণযোগ্য নয় বলে নির্ধারণ করেছে।\n\nঅনুগ্রহ করে মূল ডকুমেন্টের স্পষ্ট ছবি পাঠান অথবা আমাদের সাথে যোগাযোগ করুন:\n📞 " . Config::get('company_phone','')
                    : "❌ *Document Rejected*\n\nOur team has reviewed your document and determined it is not acceptable.\n\nPlease send a clear photo of the original document or contact us:\n📞 " . Config::get('company_phone','');
                try { $sender->send($sd['platform'], $sd['platform_id'], $msg); } catch(Exception $e) {}
            }
        } elseif ($verdict === 'genuine') {
            // genuine হলে গ্রাহককে সুখবর জানানো
            $sd = $db->fetchOne("SELECT sd.*, c.platform, c.platform_id, c.language FROM suspicious_documents sd JOIN customers c ON sd.customer_id=c.id WHERE sd.id=?", [$sid]);
            if ($sd) {
                require_once dirname(__DIR__) . '/bot/Sender.php';
                $lang = $sd['language'] ?? 'bn';
                $msg  = $lang === 'bn'
                    ? "✅ *ডকুমেন্ট যাচাই সম্পন্ন*\n\nআপনার ডকুমেন্ট আমাদের টিম যাচাই করেছে এবং এটি গ্রহণযোগ্য।"
                    : "✅ *Document Verified*\n\nOur team has reviewed your document and it is accepted.";
                try { (new Sender())->send($sd['platform'], $sd['platform_id'], $msg); } catch(Exception $e) {}
                // is_suspicious = 0 করা
                $db->execute("UPDATE documents SET is_suspicious=0 WHERE id=?", [$sd['document_id'] ?? 0]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Verdict সেভ হয়েছে।']);
        exit;
    }

    if ($act === 'send_reminder') {
        $sid = Security::sanitizeInt($_POST['suspicious_id'] ?? 0);
        $sd  = $db->fetchOne("SELECT sd.*, c.platform, c.platform_id, c.language, c.name FROM suspicious_documents sd JOIN customers c ON sd.customer_id=c.id WHERE sd.id=?", [$sid]);
        if ($sd) {
            require_once dirname(__DIR__) . '/bot/Sender.php';
            $lang = $sd['language'] ?? 'bn';
            $msg  = $lang === 'bn'
                ? "⚠️ *ডকুমেন্ট পুনরায় পাঠান*\n\nআপনার পাঠানো ডকুমেন্টটি যাচাই করা যাচ্ছে না।\n\nঅনুগ্রহ করে:\n• মূল ডকুমেন্টের ছবি তুলুন\n• ভালো আলোতে তুলুন\n• পুরো ডকুমেন্ট frame-এ রাখুন\n• আবার পাঠান\n\n📞 " . Config::get('company_phone','')
                : "⚠️ *Resend Document*\n\nYour document could not be verified. Please retake and resend in good lighting.\n📞 " . Config::get('company_phone','');
            try { (new Sender())->send($sd['platform'], $sd['platform_id'], $msg); echo json_encode(['success'=>true,'message'=>'Reminder পাঠানো হয়েছে']); }
            catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        } else {
            echo json_encode(['success'=>false,'message'=>'Not found']);
        }
        exit;
    }
}

// ── Page Data ─────────────────────────────────────────────────
$filter   = $_GET['filter'] ?? 'pending'; // pending, all, genuine, fake

$where = "WHERE 1=1";
$params = [];
if ($filter === 'pending') { $where .= " AND sd.admin_reviewed=0"; }
elseif ($filter === 'fake')    { $where .= " AND sd.admin_verdict='fake'"; }
elseif ($filter === 'genuine') { $where .= " AND sd.admin_verdict='genuine'"; }

$records = $db->fetchAll(
    "SELECT sd.*, c.name, c.mobile, c.platform,
            d.file_name, d.drive_url, d.doc_type, d.doc_number,
            d.holder_name, d.expiry_date, d.quality_score, d.extracted_data
     FROM suspicious_documents sd
     JOIN customers c ON sd.customer_id = c.id
     LEFT JOIN documents d ON sd.document_id = d.id
     {$where}
     ORDER BY sd.created_at DESC
     LIMIT 100",
    $params
);

$stats = [
    'pending' => $db->fetchOne("SELECT COUNT(*) as c FROM suspicious_documents WHERE admin_reviewed=0")['c'] ?? 0,
    'fake'    => $db->fetchOne("SELECT COUNT(*) as c FROM suspicious_documents WHERE admin_verdict='fake'")['c'] ?? 0,
    'genuine' => $db->fetchOne("SELECT COUNT(*) as c FROM suspicious_documents WHERE admin_verdict='genuine'")['c'] ?? 0,
    'total'   => $db->fetchOne("SELECT COUNT(*) as c FROM suspicious_documents")['c'] ?? 0,
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
<?php $currentPage = 'fake_documents'; include __DIR__.'/sidebar.php'; ?>

<div class="main">
<h4 class="fw-bold mb-3">🚨 সন্দেহজনক ডকুমেন্ট পর্যালোচনা</h4>

<!-- Stats -->
<div class="row g-3 mb-4">
    <?php foreach ([
        ['পর্যালোচনা বাকি', $stats['pending'], '#e67e22', 'fa-clock'],
        ['জাল ডকুমেন্ট',   $stats['fake'],    '#e74c3c', 'fa-times-circle'],
        ['আসল ডকুমেন্ট',  $stats['genuine'], '#27ae60', 'fa-check-circle'],
        ['মোট সন্দেহজনক',  $stats['total'],   '#8e44ad', 'fa-exclamation-triangle'],
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

<!-- Filter -->
<div class="d-flex gap-2 mb-3">
    <?php foreach (['pending'=>'⏳ বাকি', 'all'=>'সব', 'fake'=>'❌ জাল', 'genuine'=>'✅ আসল'] as $f=>$lbl): ?>
    <a href="?filter=<?=$f?>" class="btn btn-sm <?=$filter===$f?'btn-primary':'btn-outline-secondary'?>"><?=$lbl?></a>
    <?php endforeach; ?>
</div>

<!-- কিভাবে কাজ করে (info box) -->
<div class="alert alert-info small mb-3">
    <strong>🤖 কিভাবে Fake Detection কাজ করে:</strong>
    গ্রাহক ছবি পাঠালে Gemini AI স্বয়ংক্রিয়ভাবে font, editing signs, watermark, data consistency চেক করে।
    সন্দেহজনক হলে এই তালিকায় আসে। আপনি verdict দিলে গ্রাহককে স্বয়ংক্রিয় মেসেজ যাবে।
</div>

<!-- Records -->
<div class="card p-3">
<?php if (empty($records)): ?>
<div class="text-center text-muted py-5">
    <i class="fa fa-check-circle fa-3x mb-3" style="color:#27ae60"></i>
    <div>কোনো সন্দেহজনক ডকুমেন্ট নেই।</div>
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover">
    <thead>
        <tr>
            <th>গ্রাহক</th>
            <th>ডকুমেন্ট</th>
            <th>সমস্যাসমূহ</th>
            <th>নিশ্চিততা</th>
            <th>AI সিদ্ধান্ত</th>
            <th>Admin Verdict</th>
            <th>অ্যাকশন</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($records as $r):
        $issues = json_decode($r['issues'] ?? '[]', true) ?: [];
        $conf   = (int)($r['confidence_score'] ?? 0);
        $confColor = $conf >= 70 ? '#e74c3c' : ($conf >= 40 ? '#e67e22' : '#27ae60');
        $recLabel = match($r['recommendation'] ?? '') {
            'reject' => ['❌ প্রত্যাখ্যান', 'danger'],
            'review' => ['🔍 যাচাই করুন', 'warning'],
            default  => ['✅ গ্রহণযোগ্য', 'success'],
        };
        $verdictLabel = match($r['admin_verdict'] ?? null) {
            'fake'    => ['❌ জাল', 'danger'],
            'genuine' => ['✅ আসল', 'success'],
            'unclear' => ['❓ অস্পষ্ট', 'secondary'],
            default   => ['⏳ বাকি', 'warning'],
        };
    ?>
    <tr>
        <td>
            <div class="fw-semibold"><?=htmlspecialchars($r['name'] ?? '—')?></div>
            <small class="text-muted"><?=htmlspecialchars($r['mobile'] ?? '')?></small><br>
            <span class="badge bg-secondary" style="font-size:.65rem"><?=strtoupper($r['platform']??'')?></span>
        </td>
        <td>
            <div><?=htmlspecialchars($r['doc_type'] ?? '—')?></div>
            <?php if($r['doc_number']): ?><code class="small"><?=htmlspecialchars($r['doc_number'])?></code><?php endif; ?>
            <?php if($r['drive_url']): ?><br><a href="<?=htmlspecialchars($r['drive_url'])?>" target="_blank" class="btn btn-xs btn-outline-success" style="font-size:.7rem;padding:1px 5px">📁 Drive</a><?php endif; ?>
        </td>
        <td>
            <?php if(empty($issues)): ?>
            <span class="text-muted small">—</span>
            <?php else: ?>
            <ul class="mb-0 ps-3" style="font-size:.8rem">
                <?php foreach(array_slice($issues,0,3) as $issue): ?>
                <li><?=htmlspecialchars($issue)?></li>
                <?php endforeach; ?>
                <?php if(count($issues)>3): ?><li class="text-muted">+<?=count($issues)-3?> আরো...</li><?php endif; ?>
            </ul>
            <?php endif; ?>
        </td>
        <td>
            <div class="confidence-bar mb-1"><div class="confidence-fill" style="width:<?=$conf?>%;background:<?=$confColor?>"></div></div>
            <small style="color:<?=$confColor?>;font-weight:bold"><?=$conf?>%</small>
        </td>
        <td><span class="badge bg-<?=$recLabel[1]?>"><?=$recLabel[0]?></span></td>
        <td>
            <?php if($r['admin_reviewed']): ?>
            <span class="badge bg-<?=$verdictLabel[1]?>"><?=$verdictLabel[0]?></span>
            <?php if($r['admin_note']): ?>
            <div class="small text-muted mt-1"><?=htmlspecialchars(mb_substr($r['admin_note'],0,40))?></div>
            <?php endif; ?>
            <?php else: ?>
            <!-- Verdict buttons -->
            <div class="d-flex gap-1">
                <button class="btn btn-sm btn-success py-0 px-1" onclick="setVerdict(<?=$r['id']?>,'genuine')" title="আসল">✅</button>
                <button class="btn btn-sm btn-danger py-0 px-1"  onclick="setVerdict(<?=$r['id']?>,'fake')"    title="জাল">❌</button>
                <button class="btn btn-sm btn-secondary py-0 px-1" onclick="setVerdict(<?=$r['id']?>,'unclear')" title="অস্পষ্ট">❓</button>
            </div>
            <?php endif; ?>
        </td>
        <td>
            <div class="d-flex gap-1 flex-column">
                <?php if(!$r['admin_reviewed']): ?>
                <button class="btn btn-sm btn-outline-warning py-0 px-2" style="font-size:.75rem" onclick="sendReminder(<?=$r['id']?>)">🔔 পুনরায় পাঠাতে বলুন</button>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-info py-0 px-2" style="font-size:.75rem" onclick='showDetails(<?=json_encode($r,JSON_UNESCAPED_UNICODE)?>)'>🔍 বিস্তারিত</button>
                <small class="text-muted"><?=date('d/m/Y H:i',strtotime($r['created_at']))?></small>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div>

</div><!-- /main -->

<!-- Verdict Modal -->
<div class="modal fade" id="verdictModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Verdict দিন</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
    <input type="hidden" id="vSid">
    <input type="hidden" id="vVerdict">
    <div id="vLabel" class="mb-3 fs-5 text-center fw-bold"></div>
    <div class="mb-3"><label class="form-label">Admin নোট (ঐচ্ছিক)</label>
        <textarea id="vNote" class="form-control" rows="3" placeholder="কেন এই সিদ্ধান্ত নেওয়া হলো..."></textarea>
    </div>
    <div id="vInfo" class="alert alert-info small"></div>
</div>
<div class="modal-footer">
    <button class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button>
    <button class="btn btn-primary" onclick="submitVerdict()">নিশ্চিত করুন</button>
</div>
</div></div></div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">🔍 বিস্তারিত তথ্য</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div id="detailsContent"></div></div>
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

let vm, dm;
document.addEventListener('DOMContentLoaded',()=>{ vm=new bootstrap.Modal('#verdictModal'); dm=new bootstrap.Modal('#detailsModal'); });

function setVerdict(sid, verdict) {
    document.getElementById('vSid').value     = sid;
    document.getElementById('vVerdict').value = verdict;
    document.getElementById('vNote').value    = '';
    const labels = {
        genuine: ['✅ আসল হিসেবে চিহ্নিত করুন', 'info', 'গ্রাহককে স্বয়ংক্রিয় approval মেসেজ যাবে।'],
        fake:    ['❌ জাল হিসেবে চিহ্নিত করুন',  'danger', 'গ্রাহককে rejection মেসেজ যাবে এবং পুনরায় পাঠাতে বলা হবে।'],
        unclear: ['❓ অস্পষ্ট',                   'warning', 'গ্রাহককে কোনো মেসেজ যাবে না।'],
    };
    const [lbl, type, info] = labels[verdict];
    document.getElementById('vLabel').textContent         = lbl;
    document.getElementById('vLabel').className           = `mb-3 fs-5 text-center fw-bold text-${type}`;
    document.getElementById('vInfo').textContent          = info;
    document.getElementById('vInfo').className            = `alert alert-${type} small`;
    vm.show();
}

async function submitVerdict() {
    const r = await api({
        action:        'set_verdict',
        suspicious_id:  document.getElementById('vSid').value,
        verdict:        document.getElementById('vVerdict').value,
        note:           document.getElementById('vNote').value,
    });
    if (r.success) { vm.hide(); showToast(r.message,'success'); setTimeout(()=>location.reload(),1500); }
    else showToast(r.message||'সমস্যা','danger');
}

async function sendReminder(sid) {
    if(!confirm('গ্রাহককে পুনরায় ডকুমেন্ট পাঠাতে বলবেন?')) return;
    const r = await api({action:'send_reminder', suspicious_id:sid});
    showToast(r.success ? '✅ '+r.message : '❌ '+(r.message||'সমস্যা'), r.success?'success':'danger');
}

function showDetails(r) {
    const issues = typeof r.issues==='string' ? JSON.parse(r.issues||'[]') : (r.issues||[]);
    const ocr    = typeof r.extracted_data==='string' ? JSON.parse(r.extracted_data||'{}') : (r.extracted_data||{});
    let html = `<div class="row g-3">
      <div class="col-md-6">
        <h6 class="fw-bold">AI Detection Result</h6>
        <table class="table table-sm table-bordered">
          <tr><td>নিশ্চিততা</td><td><strong>${r.confidence_score||0}%</strong></td></tr>
          <tr><td>Image Quality</td><td>${r.quality_score||'—'}/100</td></tr>
          <tr><td>AI সিদ্ধান্ত</td><td>${r.recommendation||'—'}</td></tr>
          <tr><td>Admin Verdict</td><td>${r.admin_verdict||'বাকি'}</td></tr>
        </table>`;
    if(issues.length>0) {
        html += '<h6 class="fw-bold mt-2">সমস্যাসমূহ</h6><ul class="small">';
        issues.forEach(i=>html+=`<li>${i}</li>`);
        html += '</ul>';
    }
    html += '</div><div class="col-md-6">';
    if(Object.keys(ocr).length>0) {
        html += '<h6 class="fw-bold">OCR তথ্য</h6><table class="table table-sm table-bordered">';
        const map={holder_name:'নাম',doc_number:'নম্বর',doc_type:'ধরন',expiry_date:'মেয়াদ',issue_country:'ইস্যু দেশ',nationality:'জাতীয়তা'};
        for(const[k,l] of Object.entries(map)){
            if(ocr[k]&&ocr[k]!=='null') html+=`<tr><td>${l}</td><td>${ocr[k]}</td></tr>`;
        }
        html += '</table>';
    }
    html += '</div></div>';
    document.getElementById('detailsContent').innerHTML=html;
    dm.show();
}

function showToast(msg,type='info'){const t=document.createElement('div');t.className=`alert alert-${type} position-fixed bottom-0 end-0 m-3 shadow`;t.style.cssText='z-index:9999;min-width:280px;border-radius:12px';t.textContent=msg;document.body.appendChild(t);setTimeout(()=>t.remove(),4000);}
</script>
</body>
</html>
