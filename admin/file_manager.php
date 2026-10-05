<?php
// admin/file_manager.php — File Manager
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/config/security.php';
Security::requireAdminAuth();
Security::setSecurityHeaders();

$db      = Database::getInstance();
$csrf    = Security::generateCSRFToken();

// AJAX handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $act = $_POST['action'] ?? '';

    if ($act === 'delete_file') {
        require_once dirname(__DIR__).'/services/LocalStorageService.php';
        $cid  = (int)($_POST['customer_id'] ?? 0);
        $name = basename($_POST['file_name'] ?? '');
        if ($cid && $name) {
            $storage = new LocalStorageService();
            $ok = $storage->deleteFile($cid, $name);
            try { $db->execute("DELETE FROM documents WHERE customer_id=? AND local_path LIKE ?", [$cid, '%'.$name]); } catch(Exception $e) {}
            echo json_encode(['success'=>$ok]);
        } else {
            echo json_encode(['success'=>false]);
        }
        exit;
    }
    if ($act === 'delete_customer_files') {
        require_once dirname(__DIR__).'/services/LocalStorageService.php';
        $cid = (int)($_POST['customer_id'] ?? 0);
        if ($cid) {
            $storage = new LocalStorageService();
            $storage->deleteCustomerFolder($cid);
            echo json_encode(['success'=>true]);
        } else {
            echo json_encode(['success'=>false]);
        }
        exit;
    }
    echo json_encode(['success'=>false]);
    exit;
}

// Load data
require_once dirname(__DIR__).'/services/LocalStorageService.php';
$storage   = new LocalStorageService();
$allFiles  = $storage->getAllFiles();
$customers = [];
try {
    foreach ($db->fetchAll("SELECT id,name,mobile,platform FROM customers ORDER BY name") as $c) {
        $customers[$c['id']] = $c;
    }
} catch(Exception $e) {}

// Summary
$totalFiles = count($allFiles);
$totalSize  = array_sum(array_column($allFiles, 'size'));

// Search
$search = trim($_GET['q'] ?? '');
$filterCid = (int)($_GET['cid'] ?? 0);

$filtered = array_filter($allFiles, function($f) use ($search, $filterCid, $customers) {
    if ($filterCid && $f['customer_id'] !== $filterCid) return false;
    if ($search) {
        $cname = $customers[$f['customer_id']]['name'] ?? '';
        if (!str_contains(strtolower($f['name'] . $cname), strtolower($search))) return false;
    }
    return true;
});
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/admin/admin.css">

<title>File Manager — Admin</title>

</head>
<body>
<?php $currentPage='file_manager'; include __DIR__.'/sidebar.php'; ?>
<div class="main">

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card border-primary">
            <div class="stat-label">মোট ফাইল</div>
            <div class="stat-value text-primary"><?= $totalFiles ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card border-info">
            <div class="stat-label">মোট সাইজ</div>
            <div class="stat-value text-info"><?= $totalSize > 1048576 ? round($totalSize/1048576,1).' MB' : round($totalSize/1024,1).' KB' ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card border-success">
            <div class="stat-label">গ্রাহক (ফাইল আছে)</div>
            <div class="stat-value text-success"><?= count(array_unique(array_column($allFiles,'customer_id'))) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card border-warning">
            <div class="stat-label">Storage Path</div>
            <div class="stat-value" style="font-size:.75rem;word-break:break-all">/storage/documents/</div>
        </div>
    </div>
</div>

<!-- Controls -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="fw-bold mb-0">📁 File Manager</h5>
    <form class="d-flex gap-2" method="GET">
        <input name="q" class="form-control form-control-sm" placeholder="ফাইল/গ্রাহক সার্চ..." value="<?= htmlspecialchars($search) ?>" style="width:200px">
        <select name="cid" class="form-select form-select-sm" style="width:160px" onchange="this.form.submit()">
            <option value="">সব গ্রাহক</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filterCid==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-secondary">🔍</button>
    </form>
</div>

<!-- Folder-based File list -->
<div class="accordion" id="fileAccordion">
<?php
// Group files by customer
$byCustomer = [];
foreach ($allFiles as $f) {
    $byCustomer[$f['customer_id']][] = $f;
}
$idx = 0;
foreach ($byCustomer as $cid => $files):
    $cust = $customers[$cid] ?? ['name'=>'Unknown','mobile'=>'','platform'=>''];
    $folderName = ($cust['name'] ?: 'Unknown') . ($cust['mobile'] ? '_'.$cust['mobile'] : '');
    $totalSz = array_sum(array_column($files,'size'));
    $szText = $totalSz > 1048576 ? round($totalSz/1048576,1).' MB' : round($totalSz/1024,1).' KB';
    
    // Apply search/filter
    if ($filterCid && $cid !== $filterCid) continue;
    if ($search) {
        $match = false;
        foreach ($files as $ff) { if (str_contains(strtolower($ff['name'].$folderName), strtolower($search))) { $match=true; break; } }
        if (!$match) continue;
    }
    $idx++;
?>
<div class="card mb-2">
  <div class="card-header d-flex align-items-center justify-content-between py-2"
       style="cursor:pointer;background:#F8FAFF"
       onclick="document.getElementById('folder<?=$cid?>').classList.toggle('show')">
    <div class="d-flex align-items-center gap-2">
      <span style="font-size:1.3rem">📁</span>
      <div>
        <div class="fw-semibold" style="font-size:.9rem"><?= htmlspecialchars($folderName) ?></div>
        <small class="text-muted"><?= count($files) ?> ফাইল • <?= $szText ?></small>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-secondary"><?= strtoupper($cust['platform'] ?? '') ?></span>
      <button class="btn btn-xs btn-outline-danger py-0"
              onclick="event.stopPropagation();deleteCustomerFiles(<?=$cid?>, '<?=htmlspecialchars($folderName,ENT_QUOTES)?>')">
        🗑 সব মুছুন
      </button>
      <span class="text-muted">▼</span>
    </div>
  </div>
  <div id="folder<?=$cid?>" class="collapse show">
  <div class="table-responsive">
  <table class="table table-hover mb-0" style="font-size:.84rem">
  <thead><tr><th>ফাইল</th><th>সাইজ</th><th>তারিখ</th><th>Preview</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach ($files as $f):
      $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
      $isImg = in_array($ext, ['jpg','jpeg','png','gif','webp']);
      $isPdf = $ext === 'pdf';
      $icon = $isImg ? '🖼️' : ($isPdf ? '📄' : '📎');
      $sz = $f['size'] > 1048576 ? round($f['size']/1048576,1).' MB' : round($f['size']/1024,1).' KB';
      if ($f['name'] === '.name' || $f['name'] === '.htaccess') continue;
  ?>
  <tr>
    <td style="max-width:200px">
      <span><?= $icon ?></span>
      <span class="ms-1 text-truncate d-inline-block" style="max-width:180px;vertical-align:middle"
            title="<?= htmlspecialchars($f['name']) ?>"><?= htmlspecialchars($f['name']) ?></span>
    </td>
    <td class="text-muted"><?= $sz ?></td>
    <td class="text-muted"><?= date('d/m/Y H:i', $f['modified']) ?></td>
    <td>
      <?php if ($isImg): ?>
      <a href="<?= htmlspecialchars($f['download_url']) ?>" target="_blank">
        <img src="<?= htmlspecialchars($f['download_url']) ?>" style="height:34px;width:46px;object-fit:cover;border-radius:4px">
      </a>
      <?php elseif ($isPdf): ?>
      <a href="<?= htmlspecialchars($f['download_url']) ?>" target="_blank" class="btn btn-xs btn-outline-danger">PDF</a>
      <?php else: ?><span class="text-muted">—</span><?php endif; ?>
    </td>
    <td>
      <div class="d-flex gap-1">
        <a href="<?= htmlspecialchars($f['download_url']) ?>" target="_blank" class="btn btn-xs btn-primary">⬇</a>
        <button class="btn btn-xs btn-danger" onclick="deleteFile(<?=$f['customer_id']?>, '<?= htmlspecialchars($f['name'],ENT_QUOTES) ?>')">🗑</button>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
</div>
<?php endforeach; ?>
<?php if ($idx === 0): ?>
<div class="text-center py-5 text-muted">কোনো ফাইল নেই</div>
<?php endif; ?>
</div>

</div>

<script
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = '<?= $csrf ?>';
async function deleteFile(cid, name) {
    if (!confirm('"' + name + '" মুছে ফেলবেন?')) return;
    const r = await fetch('/admin/file_manager.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'delete_file', customer_id:cid, file_name:name, csrf_token:CSRF})
    });
    const d = await r.json();
    if (d.success) location.reload();
    else alert('মুছতে পারিনি');
}
async function deleteCustomerFiles(cid, name) {
    if (!confirm('"' + name + '" এর সব ফাইল মুছে ফেলবেন?')) return;
    const r = await fetch('/admin/file_manager.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'delete_customer_files', customer_id:cid, csrf_token:CSRF})
    });
    const d = await r.json();
    if (d.success) location.reload();
    else alert('মুছতে পারিনি');
}
</script>
</body>
</html>
