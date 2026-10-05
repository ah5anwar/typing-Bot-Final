<?php
// admin/diagnostics.php — System Diagnostics
if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/config/security.php';
Security::requireAdminAuth();
Security::setSecurityHeaders();

$db   = Database::getInstance();
$csrf = Security::generateCSRFToken();

$checks = [];

// ── 1. Settings ────────────────────────────────────────────────
foreach ([
    'telegram_token', 'gemini_api_key', 'gemini_model',
    'whatsapp_token', 'messenger_page_token',
    'google_service_account_json', 'google_drive_folder_id',
    'timezone', 'company_name',
] as $k) {
    $v = Config::get($k, '');
    $short = $v ? '✅ SET ('.strlen($v).' chars)' . ($k==='gemini_model'||$k==='timezone'||$k==='company_name' ? ' = '.htmlspecialchars(substr($v,0,40)) : '') : '❌ NOT SET';
    $checks['settings'][$k] = $short;
}

// ── 2. Telegram Webhook ────────────────────────────────────────
$tok = Config::get('telegram_token','');
if ($tok) {
    try {
        $ch = curl_init("https://api.telegram.org/bot{$tok}/getWebhookInfo");
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>8]);
        $resp = curl_exec($ch); curl_close($ch);
        $wh = json_decode($resp??'', true);
        $whUrl = $wh['result']['url'] ?? '';
        $checks['telegram']['webhook_url']     = $whUrl ?: '❌ NOT SET';
        $checks['telegram']['pending_updates'] = $wh['result']['pending_update_count'] ?? 0;
        $checks['telegram']['last_error']      = $wh['result']['last_error_message'] ?? '✅ none';
        $checks['telegram']['webhook_ok']      = !empty($whUrl) && str_starts_with($whUrl,'https');
    } catch(Exception $e) {
        $checks['telegram']['webhook_url'] = '❌ cURL error: '.$e->getMessage();
        $checks['telegram']['webhook_ok']  = false;
    }
} else {
    $checks['telegram']['webhook_url'] = '❌ Token not set';
    $checks['telegram']['webhook_ok']  = false;
}

// ── 3. DB Tables ───────────────────────────────────────────────
foreach (['customers','settings','faqs','services','applications',
          'messages','payments','payment_transactions','documents','embassy_news'] as $t) {
    try {
        $c = $db->fetchOne("SELECT COUNT(*) as c FROM `{$t}`")['c'] ?? 0;
        $checks['db'][$t] = "✅ {$c} rows";
    } catch(Exception $e) {
        $checks['db'][$t] = "❌ ".$e->getMessage();
    }
}

// ── 4. Timezone ────────────────────────────────────────────────
$checks['timezone'] = [
    'setting'  => Config::get('timezone','Asia/Dhaka'),
    'active'   => date_default_timezone_get(),
    'now'      => date('Y-m-d H:i:s'),
    'match'    => Config::get('timezone','Asia/Dhaka') === date_default_timezone_get(),
];

// ── 5. PHP Extensions ──────────────────────────────────────────
foreach (['curl','openssl','gd','pdo','pdo_mysql','json','mbstring','zip'] as $ext) {
    $checks['php'][$ext] = extension_loaded($ext) ? '✅' : '❌ MISSING!';
}

// ── 6. File Permissions ────────────────────────────────────────
foreach (['logs','tmp','public/qr','public/cards'] as $d) {
    $path = BASE_PATH.'/'.$d;
    if (!is_dir($path)) @mkdir($path, 0755, true);
    $checks['files'][$d] = is_writable($path) ? '✅ writable' : '❌ NOT writable';
}

// ── 7. Recent errors ───────────────────────────────────────────
$errors = [];
$logFile = BASE_PATH.'/logs/bot.log';
if (file_exists($logFile)) {
    $lines = array_slice(file($logFile), -60);
    foreach (array_reverse($lines) as $line) {
        if (str_contains($line,'ERROR') || str_contains($line,'FATAL')) {
            $errors[] = trim($line);
            if (count($errors) >= 15) break;
        }
    }
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
<?php include __DIR__.'/sidebar.php'; ?>
<div class="main">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">🔍 System Diagnostics</h4>
    <div class="d-flex gap-2">
        <a href="diagnostics.php" class="btn btn-outline-secondary btn-sm">🔄 Refresh</a>
        <a href="/admin/" class="btn btn-secondary btn-sm">← Admin</a>
    </div>
</div>

<!-- Settings -->
<div class="card mb-3">
    <div class="card-header bg-primary text-white">⚙️ Settings (DB)</div>
    <div class="card-body p-0">
    <?php foreach ($checks['settings'] as $k=>$v): ?>
    <div class="d-flex gap-3 px-3 py-2 border-bottom">
        <code class="text-muted" style="min-width:260px"><?= $k ?></code>
        <span class="<?= str_starts_with($v,'✅')?'text-success':'text-danger' ?>"><?= $v ?></span>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- Telegram -->
<div class="card mb-3">
    <div class="card-header">🤖 Telegram Webhook</div>
    <div class="card-body">
        <div class="row g-2 mb-2">
            <div class="col-md-8">
                <strong>Webhook URL:</strong><br>
                <code class="<?= $checks['telegram']['webhook_ok']?'text-success':'text-danger' ?>">
                    <?= htmlspecialchars($checks['telegram']['webhook_url']) ?>
                </code>
            </div>
            <div class="col-md-4">
                <div><strong>Pending:</strong> <?= $checks['telegram']['pending_updates']??0 ?></div>
                <div><strong>Last Error:</strong>
                    <span class="<?= str_contains($checks['telegram']['last_error']??'','✅')?'text-success':'text-danger' ?>">
                        <?= htmlspecialchars($checks['telegram']['last_error']??'') ?>
                    </span>
                </div>
            </div>
        </div>
        <?php if (!($checks['telegram']['webhook_ok']??false)): ?>
        <div class="alert alert-warning py-2 mb-0">
            ⚠️ Webhook সেট নেই!
            <button class="btn btn-primary btn-sm ms-2" onclick="setWebhook()">🔗 এখনই Webhook সেট করুন</button>
        </div>
        <?php endif; ?>
        <div id="whResult"></div>
    </div>
</div>

<!-- Timezone -->
<div class="card mb-3">
    <div class="card-header">🕐 Timezone</div>
    <div class="card-body">
    <div class="d-flex gap-4 flex-wrap">
        <div><strong>Settings:</strong> <code><?= $checks['timezone']['setting'] ?></code></div>
        <div><strong>Active:</strong>
            <code class="<?= $checks['timezone']['match']?'text-success':'text-warning' ?>">
                <?= $checks['timezone']['active'] ?>
            </code>
        </div>
        <div><strong>Server Time:</strong> <strong class="text-primary"><?= $checks['timezone']['now'] ?></strong></div>
    </div>
    <?php if (!$checks['timezone']['match']): ?>
    <div class="alert alert-warning mt-2 mb-0 py-2">⚠️ Timezone mismatch! Settings → Save করুন।</div>
    <?php endif; ?>
    </div>
</div>

<!-- DB Tables -->
<div class="card mb-3">
    <div class="card-header">🗄️ Database</div>
    <div class="card-body">
    <div class="row g-2">
    <?php foreach ($checks['db'] as $t=>$s): ?>
    <div class="col-md-3 col-6 small">
        <code><?= $t ?></code>: <span class="<?= str_contains($s,'❌')?'text-danger':'text-success' ?>"><?= $s ?></span>
    </div>
    <?php endforeach; ?>
    </div>
    </div>
</div>

<!-- PHP Extensions -->
<div class="card mb-3">
    <div class="card-header">🐘 PHP Extensions</div>
    <div class="card-body">
    <div class="d-flex gap-3 flex-wrap">
    <?php foreach ($checks['php'] as $ext=>$s): ?>
    <span class="<?= str_contains($s,'❌')?'text-danger':'text-success' ?>"><?= $s ?> <?= $ext ?></span>
    <?php endforeach; ?>
    </div>
    </div>
</div>

<!-- File Permissions -->
<div class="card mb-3">
    <div class="card-header">📁 File Permissions</div>
    <div class="card-body">
    <?php foreach ($checks['files'] as $d=>$s): ?>
    <div class="small mb-1"><code><?= $d ?></code>: <span class="<?= str_contains($s,'❌')?'text-danger':'text-success' ?>"><?= $s ?></span></div>
    <?php endforeach; ?>
    </div>
</div>

<!-- AI Test -->
<div class="card mb-3">
    <div class="card-header">🤖 AI Test</div>
    <div class="card-body">
    <div class="d-flex gap-3 flex-wrap align-items-center">
        <div>Model: <code class="text-primary"><?= Config::get('gemini_model','not set') ?></code></div>
        <div>Key: <code><?= Config::get('gemini_api_key') ? '✅ SET' : '❌ NOT SET' ?></code></div>
        <button class="btn btn-outline-primary btn-sm" onclick="testAI()">🧪 Test Gemini</button>
        <button class="btn btn-outline-warning btn-sm" onclick="fixModel()">🔧 Fix Model</button>
    </div>
    <div id="aiResult" class="mt-2"></div>
    </div>
</div>



<!-- Recent Errors -->
<div class="card mb-3">
    <div class="card-header text-danger">❌ Recent Errors (bot.log)</div>
    <div class="card-body" style="max-height:300px;overflow-y:auto">
    <?php if ($errors): ?>
    <?php foreach ($errors as $e): ?>
    <div class="small text-danger mb-1 font-monospace border-bottom pb-1"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <?php else: ?>
    <span class="text-success">✅ কোনো error নেই</span>
    <?php endif; ?>
    </div>
</div>

</div><!-- /main -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = '<?= $csrf ?>';

async function post(data) {
    try {
        const r = await fetch('/admin/api.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: new URLSearchParams({...data, csrf_token: CSRF})
        });
        return await r.json();
    } catch(e) { return {success:false, message: e.message}; }
}

async function setWebhook() {
    document.getElementById('whResult').innerHTML = '<div class="alert alert-info py-2">⏳ সেট করা হচ্ছে...</div>';
    const url = window.location.origin + '/webhook/telegram.php';
    const r = await post({action:'set_telegram_webhook', webhook_url: url});
    document.getElementById('whResult').innerHTML = r.success
        ? '<div class="alert alert-success py-2">✅ Webhook সেট হয়েছে! URL: '+url+'</div>'
        : '<div class="alert alert-danger py-2">❌ '+r.message+'</div>';
    if (r.success) setTimeout(()=>location.reload(), 2000);
}

async function testAI() {
    document.getElementById('aiResult').innerHTML = '<span class="text-muted">⏳ Testing...</span>';
    const r = await post({action:'test_ai'});
    const cls = r.success ? 'success' : 'danger';
    const msg = r.success
        ? '✅ কাজ করছে! Model: <code>'+r.model+'</code>'
        : '❌ HTTP '+r.http_code+': '+r.response + (r.http_code===404?' <strong>→ Model ভুল!</strong>':'');
    document.getElementById('aiResult').innerHTML = '<div class="alert alert-'+cls+' py-2">'+msg+'</div>';
    if (!r.success && r.http_code === 404) {
        document.getElementById('aiResult').innerHTML +=
            '<button class="btn btn-warning btn-sm" onclick="fixModel()">🔧 Auto-Fix Model</button>';
    }
}

async function fixModel() {
    const r = await post({action:'fix_gemini_model'});
    document.getElementById('aiResult').innerHTML = '<div class="alert alert-'+(r.success?'success':'danger')+' py-2">'+(r.success?'✅ '+r.message:'❌ '+r.message)+'</div>';
    if (r.success) setTimeout(()=>location.reload(), 1500);
}

async function testDrive() {
    document.getElementById('driveResult').innerHTML = '<span class="text-muted">⏳ Testing...</span>';
    const r = await post({action:'test_drive'});
    document.getElementById('driveResult').innerHTML = '<div class="alert alert-'+(r.success?'success':'danger')+' py-2">'+(r.success?'✅ '+r.message:'❌ '+r.message)+'</div>';
}
</script>
</body>
</html>
