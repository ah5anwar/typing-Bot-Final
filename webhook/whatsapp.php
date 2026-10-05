<?php
// webhook/whatsapp.php — Auto profile fetch
require_once dirname(__DIR__).'/config/bootstrap.php';
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/config/security.php';
require_once dirname(__DIR__).'/bot/BotEngine.php';

if ($_SERVER['REQUEST_METHOD']==='GET') {
    $vt = Config::get('whatsapp_verify_token','my_verify_token_2025');
    if (($_GET['hub_mode']??'')==='subscribe' && ($_GET['hub_verify_token']??'')===$vt) {
        echo $_GET['hub_challenge']??'';
    } else { http_response_code(403); }
    exit;
}

$raw = file_get_contents('php://input');
if (!$raw) { http_response_code(200); exit('OK'); }

$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if ($sig && !Security::verifyWhatsAppSignature($raw, $sig)) {
    Logger::error('WA signature fail'); http_response_code(200); exit('OK');
}

$data = json_decode($raw, true);
if (!$data) { http_response_code(200); exit('OK'); }

$msgs     = $data['entry'][0]['changes'][0]['value']['messages']  ?? [];
$contacts = $data['entry'][0]['changes'][0]['value']['contacts']   ?? [];

foreach ($msgs as $msg) {
    $pid  = $msg['from'] ?? ''; if (!$pid) continue;
    if (!Security::checkRateLimit($pid)) continue;

    // WhatsApp contacts block-এ নাম ও মোবাইল থাকে
    $contactInfo = $contacts[0] ?? [];
    $name        = $contactInfo['profile']['name'] ?? '';
    $mobile      = $pid; // WhatsApp ID is the phone number with country code

    $d = [
        'platform'      => 'whatsapp',
        'platform_id'   => $pid,
        'chat_id'       => $pid,
        'from_name'     => $name,
        'from_mobile'   => '+' . ltrim($mobile, '+'), // WA number = mobile
        'profile_photo' => '',  // WhatsApp API doesn't provide profile photos
        'type'          => $msg['type'] ?? 'text',
        'text'          => '',
        'file_id'       => null,
        'file_name'     => null,
        'raw'           => $msg,
    ];

    $type = $msg['type'] ?? 'text';
    switch ($type) {
        case 'text':        $d['text'] = $msg['text']['body'] ?? ''; break;
        case 'image':       $d['file_id'] = $msg['image']['id']    ?? null; $d['text'] = $msg['image']['caption']    ?? ''; break;
        case 'audio':       $d['type']='voice'; $d['file_id'] = $msg['audio']['id'] ?? null; break;
        case 'voice':       $d['file_id'] = $msg['voice']['id']    ?? null; break;
        case 'document':    $d['file_id'] = $msg['document']['id'] ?? null; $d['file_name'] = $msg['document']['filename'] ?? 'doc'; break;
        case 'interactive':
            $d['type'] = 'callback';
            $d['text'] = $msg['interactive']['button_reply']['id']
                      ?? $msg['interactive']['list_reply']['id']
                      ?? '';
            break;
        default: continue 2;
    }

    try { (new BotEngine())->process($d); }
    catch (Exception $e) { Logger::error('WA: '.$e->getMessage()); }
}

http_response_code(200); echo 'OK';
