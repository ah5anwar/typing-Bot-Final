<?php
// webhook/telegram.php — Telegram Bot Webhook
require_once dirname(__DIR__).'/config/bootstrap.php';
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/config/security.php';
require_once dirname(__DIR__).'/bot/BotEngine.php';

http_response_code(200);
header('Content-Type: application/json');

$raw = file_get_contents('php://input');
if (!$raw) { echo '{"ok":true}'; exit; }

$u = json_decode($raw, true);
if (!$u) { echo '{"ok":true}'; exit; }

$msg = $u['message']        ?? null;
$cb  = $u['callback_query'] ?? null;

if (!$msg && !$cb) { echo '{"ok":true}'; exit; }

$from = $cb ? $cb['from'] : ($msg['from'] ?? null);
if (!$from) { echo '{"ok":true}'; exit; }

$pid  = (string)($from['id'] ?? '');
$chat = (string)($cb ? ($cb['message']['chat']['id'] ?? $pid) : ($msg['chat']['id'] ?? $pid));
$name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));

if (!Security::checkRateLimit($pid)) { echo '{"ok":true}'; exit; }

// answerCallbackQuery (spinner বন্ধ)
if ($cb) {
    $tok = Config::get('telegram_token');
    if ($tok && !empty($cb['id'])) {
        $ch = curl_init("https://api.telegram.org/bot{$tok}/answerCallbackQuery");
        curl_setopt_array($ch,[
            CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_TIMEOUT=>5,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode(['callback_query_id'=>$cb['id']])
        ]);
        curl_exec($ch); curl_close($ch);
    }
}

// Profile Photo (নতুন গ্রাহকের জন্য)
$profilePhotoUrl = '';
$tok = Config::get('telegram_token');
if ($tok && !$cb) {
    try {
        $db = Database::getInstance();
        $existing = $db->fetchOne(
            "SELECT profile_photo_url FROM customers WHERE platform='telegram' AND platform_id=?",
            [$pid]
        );
        if (!($existing['profile_photo_url'] ?? '')) {
            $ch = curl_init("https://api.telegram.org/bot{$tok}/getUserProfilePhotos?user_id={$pid}&limit=1");
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>8]);
            $pResp = curl_exec($ch); curl_close($ch);
            $pData = json_decode($pResp, true);
            if (!empty($pData['result']['photos'][0])) {
                $fileId = end($pData['result']['photos'][0])['file_id'];
                $ch2 = curl_init("https://api.telegram.org/bot{$tok}/getFile?file_id={$fileId}");
                curl_setopt_array($ch2,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>8]);
                $fResp = json_decode(curl_exec($ch2), true); curl_close($ch2);
                if (!empty($fResp['result']['file_path'])) {
                    $profilePhotoUrl = "https://api.telegram.org/file/bot{$tok}/" . $fResp['result']['file_path'];
                }
            }
        }
    } catch (Exception $e) {}
}

// Message data
$d = [
    'platform'      => 'telegram',
    'platform_id'   => $pid,
    'chat_id'       => $chat,
    'from_name'     => $name,
    'from_mobile'   => '',
    'profile_photo' => $profilePhotoUrl,
    'type'          => 'text',
    'text'          => '',
    'file_id'       => null,
    'file_name'     => null,
    'raw'           => $u,
];

if ($cb) {
    $d['type'] = 'callback';
    $d['text'] = $cb['data'] ?? '';
} elseif (isset($msg['text']))   { $d['text'] = $msg['text']; }
elseif (isset($msg['photo']))    { $d['type']='image';   $d['file_id']=end($msg['photo'])['file_id']; $d['text']=$msg['caption']??''; }
elseif (isset($msg['voice']))    { $d['type']='voice';   $d['file_id']=$msg['voice']['file_id']; }
elseif (isset($msg['audio']))    { $d['type']='voice';   $d['file_id']=$msg['audio']['file_id']; }
elseif (isset($msg['document'])) { $d['type']='document';$d['file_id']=$msg['document']['file_id'];$d['file_name']=$msg['document']['file_name']??'doc'; }
elseif (isset($msg['sticker']))  { $d['text']='🎭'; } // sticker → treat as text
else { echo '{"ok":true}'; exit; }

try {
    (new BotEngine())->process($d);
} catch (Throwable $e) {
    Logger::error('TG webhook exception: ' . $e->getMessage() . ' | Line: ' . $e->getLine());
}

echo '{"ok":true}';
