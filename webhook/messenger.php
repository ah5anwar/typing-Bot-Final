<?php
require_once dirname(__DIR__).'/config/bootstrap.php';
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/config/security.php';
require_once dirname(__DIR__).'/bot/BotEngine.php';

if ($_SERVER['REQUEST_METHOD']==='GET') {
    $vt=Config::get('messenger_verify_token','my_messenger_token_2025');
    if (($_GET['hub_mode']??'')==='subscribe' && ($_GET['hub_verify_token']??'')===$vt) { echo $_GET['hub_challenge']??''; } else { http_response_code(403); }
    exit;
}

$raw=file_get_contents('php://input');
if (!$raw) { http_response_code(200); exit('EVENT_RECEIVED'); }
$data=json_decode($raw,true);
if (!$data||$data['object']!=='page') { http_response_code(200); exit('EVENT_RECEIVED'); }

foreach ($data['entry']??[] as $entry) {
    foreach ($entry['messaging']??[] as $ev) {
        $pid=$ev['sender']['id']??''; if (!$pid) continue;
        if (!Security::checkRateLimit($pid)) continue;
        $d=['platform'=>'messenger','platform_id'=>$pid,'chat_id'=>$pid,'from_name'=>'','type'=>'text','text'=>'','file_id'=>null,'file_name'=>null,'raw'=>$ev];
        if (isset($ev['message'])) {
            $m=$ev['message'];
            if (isset($m['text'])) { $d['text']=$m['text']; }
            elseif (isset($m['attachments'])) {
                $att=$m['attachments'][0]; $t=$att['type'];
                if ($t==='image')  { $d['type']='image';    $d['file_id']=$att['payload']['url']??null; }
                elseif($t==='audio'){ $d['type']='voice';   $d['file_id']=$att['payload']['url']??null; }
                elseif($t==='file') { $d['type']='document'; $d['file_id']=$att['payload']['url']??null; }
            }
        } elseif (isset($ev['postback'])) { $d['type']='callback'; $d['text']=$ev['postback']['payload']??''; }
        else continue;
        try { (new BotEngine())->process($d); } catch(Exception $e) { Logger::error('MSG: '.$e->getMessage()); }
    }
}
http_response_code(200); echo 'EVENT_RECEIVED';
