<?php
// ============================================================
// setup.php — প্রথমবার Setup
// ⚠️ সেটআপ শেষে এই ফাইল মুছুন!
// ============================================================
require_once __DIR__.'/config/config.php';

$msg=''; $err=''; $step=$_POST['step']??'';

if ($step==='set_password') {
    $u=$_POST['username']??'admin'; $p=$_POST['password']??''; $c=$_POST['confirm']??'';
    if (strlen($p)<8) { $err='পাসওয়ার্ড কমপক্ষে ৮ অক্ষর!'; }
    elseif ($p!==$c)  { $err='পাসওয়ার্ড মিলছে না!'; }
    else { Config::set('admin_username',$u); Config::set('admin_password',password_hash($p,PASSWORD_BCRYPT)); $msg='✅ Admin পাসওয়ার্ড সেট হয়েছে!'; }
}

if ($step==='set_webhook') {
    $tok=trim($_POST['token']??''); $url=trim($_POST['url']??'');
    if ($tok&&$url) {
        Config::set('telegram_token',$tok);
        $ch=curl_init("https://api.telegram.org/bot{$tok}/setWebhook");
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['url'=>rtrim($url,'/').'webhook/telegram.php'])]);
        $res=json_decode(curl_exec($ch),true); curl_close($ch);
        if ($res['ok']??false) $msg='✅ Telegram Webhook সেট হয়েছে!';
        else $err='❌ '.($res['description']??'সমস্যা হয়েছে');
    }
}

$host='https://'.($_SERVER['HTTP_HOST']??'yourdomain.com');
$checks=[
    ['PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.' (8.0+)', PHP_MAJOR_VERSION>=8],
    ['cURL Extension', extension_loaded('curl')],
    ['OpenSSL Extension', extension_loaded('openssl')],
    ['GD Extension', extension_loaded('gd')],
    ['HTTPS চালু', str_starts_with($host,'https')],
    ['Database সংযোগ', (function(){ try{Database::getInstance();return true;}catch(Exception $e){return false;}})()],
    ['Telegram Token সেট', (bool)Config::get('telegram_token')],
    ['Gemini API Key সেট', (bool)Config::get('gemini_api_key')],
    ['logs/ write permission', is_writable(BASE_PATH.'/logs')||@mkdir(BASE_PATH.'/logs',0755,true)],
];
?><!DOCTYPE html><html lang="bn"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bot Setup v11</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head><body class="bg-light py-4">
<div class="container" style="max-width:720px">
<div class="text-center mb-4">
    <h3 class="fw-bold" style="color:#1a3c6e">🤖 Multi-Platform Bot v11</h3>
    <div class="alert alert-danger fw-bold">⚠️ সেটআপ শেষে setup.php অবশ্যই মুছুন!</div>
</div>
<?php if($msg):?><div class="alert alert-success"><?=$msg?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger"><?=$err?></div><?php endif;?>

<!-- System Check -->
<div class="card mb-3 p-4">
<h5 class="fw-bold mb-3">📊 সিস্টেম চেক</h5>
<?php foreach($checks as[$label,$ok]):?>
<div class="d-flex align-items-center gap-2 mb-1"><?= $ok ? "✅" : "❌" ?> <span class="<?= $ok ? "" : "text-danger fw-bold" ?>"><?= htmlspecialchars($label) ?></span></div>
<?php endforeach;?>
</div>

<!-- Admin Password -->
<div class="card mb-3 p-4">
<h5 class="fw-bold mb-3">🔐 Admin পাসওয়ার্ড সেট</h5>
<form method="POST"><input type="hidden" name="step" value="set_password">
<div class="row g-3">
<div class="col-12"><label>Username</label><input name="username" class="form-control" value="admin"></div>
<div class="col-6"><label>পাসওয়ার্ড (৮+)</label><input name="password" type="password" class="form-control" required></div>
<div class="col-6"><label>নিশ্চিত করুন</label><input name="confirm" type="password" class="form-control" required></div>
<div class="col-12"><button class="btn btn-primary">পাসওয়ার্ড সেট করুন</button></div>
</div></form></div>

<!-- Telegram Webhook -->
<div class="card mb-3 p-4">
<h5 class="fw-bold mb-3">🤖 Telegram Webhook সেটআপ</h5>
<form method="POST"><input type="hidden" name="step" value="set_webhook">
<div class="mb-3"><label>Bot Token (@BotFather থেকে)</label><input name="token" class="form-control" value="<?=htmlspecialchars(Config::get('telegram_token'))?>" placeholder="1234567:ABC..."></div>
<div class="mb-3"><label>সাইটের URL</label><input name="url" class="form-control" value="<?=$host?>"></div>
<div class="alert alert-info p-2">Webhook: <code><?=$host?>/webhook/telegram.php</code></div>
<button class="btn btn-success">Webhook সেট করুন</button></form></div>

<!-- Other Webhooks -->
<div class="card mb-3 p-4">
<h5 class="fw-bold mb-3">💚 WhatsApp & 💙 Messenger</h5>
<div class="alert alert-secondary small">
<strong>WhatsApp Webhook:</strong> <code><?=$host?>/webhook/whatsapp.php</code><br>
<strong>WhatsApp Verify Token:</strong> <code>my_verify_token_2025</code><br><br>
<strong>Messenger Webhook:</strong> <code><?=$host?>/webhook/messenger.php</code><br>
<strong>Messenger Verify Token:</strong> <code>my_messenger_token_2025</code>
</div></div>

<!-- Cron Jobs -->
<div class="card mb-3 p-4">
<h5 class="fw-bold mb-3">⏰ Cron Jobs (cPanel → Advanced → Cron Jobs)</h5>
<pre class="bg-dark text-light p-3 rounded small">
* * * * *    php /home/USER/public_html/cron/agent_timeout.php
0 9 * * *    php /home/USER/public_html/cron/reminder.php
0 10 1 * *   php /home/USER/public_html/cron/payment_reminder.php
0 8 * * *    php /home/USER/public_html/cron/currency_update.php
0 6 * * 1    php /home/USER/public_html/cron/expiry_alert.php
*/5 * * * *  php /home/USER/public_html/cron/broadcast_queue.php
0 7 * * *    php /home/USER/public_html/cron/scrape_embassy_news.php</pre>
<p class="text-muted small">USER = cPanel username | php path: <code>/usr/local/bin/php</code></p>
</div>

<div class="text-center">
<a href="/admin/" class="btn btn-primary btn-lg">Admin Panel খুলুন →</a>
</div>
<div class="alert alert-danger text-center fw-bold mt-3">⚠️ সেটআপ শেষে setup.php মুছুন!</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
