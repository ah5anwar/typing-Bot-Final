<?php
// cron/telegram_polling.php — Webhook-এর বিকল্প
// cPanel Cron: * * * * * php /home/USER/public_html/cron/telegram_polling.php
// ⚠️ Webhook সেট থাকলে এটি বন্ধ রাখুন!
require_once dirname(__DIR__).'/config/bootstrap.php';
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/bot/BotEngine.php';

$token=Config::get('telegram_token');
if (!$token) { exit("Token not set\n"); }

$file=BASE_PATH.'/tmp/tg_offset.txt';
if (!is_dir(dirname($file))) mkdir(dirname($file),0755,true);
$offset=(int)@file_get_contents($file);

$ch=curl_init("https://api.telegram.org/bot{$token}/getUpdates?offset={$offset}&limit=50&timeout=10");
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>15]);
$res=curl_exec($ch); curl_close($ch);

$data=json_decode($res,true);
if (!($data['ok']??false)) { exit("API error\n"); }

$engine=new BotEngine(); $done=0;
foreach ($data['result']??[] as $u) {
    $uid=$u['update_id']; if ($uid<=$offset) continue;
    try {
        $msg=$u['message']??null; $cb=$u['callback_query']??null;
        $from=$cb?$cb['from']:($msg['from']??null); if (!$from) { $offset=$uid; continue; }
        $pid=(string)$from['id']; $chat=(string)($cb?$cb['message']['chat']['id']:($msg['chat']['id']??$pid));
        $name=trim(($from['first_name']??'').' '.($from['last_name']??''));
        $d=['platform'=>'telegram','platform_id'=>$pid,'chat_id'=>$chat,'from_name'=>$name,'type'=>'text','text'=>'','file_id'=>null,'file_name'=>null,'raw'=>$u];
        if ($cb)                    { $d['type']='callback'; $d['text']=$cb['data']??''; }
        elseif(isset($msg['text']))      { $d['text']=$msg['text']; }
        elseif(isset($msg['photo']))     { $d['type']='image';    $d['file_id']=end($msg['photo'])['file_id']; $d['text']=$msg['caption']??''; }
        elseif(isset($msg['voice']))     { $d['type']='voice';    $d['file_id']=$msg['voice']['file_id']; }
        elseif(isset($msg['document']))  { $d['type']='document'; $d['file_id']=$msg['document']['file_id']; $d['file_name']=$msg['document']['file_name']??'doc'; }
        else { $offset=$uid; continue; }
        $engine->process($d); $done++;
    } catch(Exception $e) { Logger::error("Poll #{$uid}: ".$e->getMessage()); }
    $offset=$uid;
    usleep(100000);
}
file_put_contents($file,$offset+1);
echo "Done: {$done} | Offset: ".($offset+1)."\n";
