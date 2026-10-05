<?php
// ============================================================
// admin/api.php — Dedicated AJAX API endpoint
// সব admin AJAX request এখানে আসবে
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/security.php';

header('Content-Type: application/json; charset=utf-8');

// Auth check
if (empty($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'লগইন প্রয়োজন', 'redirect' => '/admin/']);
    exit;
}

// CSRF check
$act = $_POST['action'] ?? $_GET['action'] ?? '';
$exempt = ['ping', 'get_new_messages', 'get_chat_messages', 'toggle_chat_mode', 'send_chat_reply', 'send_file_to_customer', 'delete_customer', 'get_customer_detail', 'edit_customer', 'add_application', 'add_payment'];
if (!in_array($act, $exempt)) {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    // Generate token if missing in session
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    if ($token && !hash_equals($_SESSION['csrf_token'], $token)) {
        echo json_encode([
            'success' => false,
            'message' => 'CSRF token ভুল। পেজ reload করুন।',
            'new_csrf' => $_SESSION['csrf_token']
        ]);
        exit;
    }
}

$db  = Database::getInstance();

switch ($act) {

    // ── Settings Save ─────────────────────────────────────────
    case 'save_settings':
        $keys = ['bot_name','welcome_message','system_prompt','gemini_api_key','gemini_model','news_countries','system_currencies',
                 'openrouter_api_key','openrouter_model','ai_provider','telegram_token',
                 'whatsapp_token','whatsapp_phone_id','whatsapp_verify_token','whatsapp_app_secret',
                 'messenger_page_token','messenger_verify_token','admin_telegram_group',
                 'company_name','company_phone','company_address','timezone',
                 'office_hours_start','office_hours_end','office_days',
                 'currency_api_key','currency_send_time','currency_send_frequency','currency_send_day',
                 'invoice_template','agent_timeout_minutes'];
        // Valid Gemini models
        $validGeminiModels = ['gemini-3.1-pro','gemini-3.1-flash','gemini-3.1-flash-lite','gemini-2.5-pro','gemini-2.5-flash','gemini-2.5-flash-lite','gemini-2.5-pro-exp-03-25','gemini-2.5-pro-preview-05-06','gemini-2.5-pro-preview-03-25','gemini-2.5-flash-preview-04-17','gemini-2.0-flash','gemini-2.0-flash-lite','gemini-2.0-flash-thinking-exp','gemini-1.5-pro','gemini-1.5-flash','gemini-1.5-flash-8b'];

        $saved = 0;
        foreach ($keys as $k) {
            if (array_key_exists($k, $_POST)) {
                $val = trim($_POST[$k]);
                if (empty($val)) continue;

                // gemini_model: reject obviously wrong values
                if ($k === 'gemini_model') {
                    // Must start with 'gemini-' 
                    if (!str_starts_with($val, 'gemini-')) {
                        Logger::error("Invalid gemini_model rejected: {$val}");
                        continue;
                    }
                    // Warn if not in known list (but still save - user may have newer model)
                    if (!in_array($val, $validGeminiModels)) {
                        Logger::warn("Unknown gemini_model saved: {$val} - verify at https://ai.google.dev/models");
                    }
                }

                Config::set($k, $val);
                $saved++;
            }
        }
        $toggles = ['faq_internet_answer','faq_service_only_mode','onboarding_custom',
                    'multilanguage_enabled','voice_enabled','document_upload_enabled',
                    'reminder_enabled','broadcast_enabled','payment_reminder_enabled',
                    'smart_notification_enabled','qr_code_enabled','payment_tracker_enabled',
                    'ocr_enabled','invoice_enabled','expiry_countdown_enabled',
                    'service_menu_enabled','currency_alert_enabled','salary_calculator_enabled',
                    'sos_enabled','fake_doc_detector_enabled','digital_locker_enabled',
                    'scam_alert_enabled','office_hours_enabled','ai_fallback_enabled',
                    'embassy_news_enabled'];
        foreach ($toggles as $t) {
            Config::set($t, isset($_POST[$t]) ? '1' : '0');
        }
        Config::clearCache();
        // Re-apply timezone immediately after save
        $newTz = Config::get('timezone', 'Asia/Dhaka');
        if ($newTz) date_default_timezone_set($newTz);
        echo json_encode(['success' => true, 'message' => "✅ সেটিংস সেভ হয়েছে! ({$saved}টি আপডেট)"]); break;

    // ── Customer Delete ───────────────────────────────────────
    case 'delete_customer':
        $cid = (int)($_POST['customer_id'] ?? 0);
        if (!$cid) { echo json_encode(['success'=>false,'message'=>'ID নেই']); break; }
        $c = $db->fetchOne("SELECT id,name FROM customers WHERE id=?", [$cid]);
        if (!$c) { echo json_encode(['success'=>false,'message'=>'গ্রাহক পাওয়া যায়নি']); break; }

        $log = [];

        // Google Drive folder delete (background, non-blocking)
        if (!empty($c['drive_folder_id'])) {
            try {
                require_once dirname(__DIR__).'/services/GoogleDriveService.php';
                $drive = new GoogleDriveService();
                if ($drive->isReady()) {
                    $drive->deleteFolder($c['drive_folder_id']);
                    $log[] = 'Drive folder deleted';
                }
            } catch(Exception $e) {
                $log[] = 'Drive skip: '.$e->getMessage();
            }
        }

        // Step 1: payment_transactions via payments JOIN
        try { $db->execute("DELETE pt FROM payment_transactions pt INNER JOIN payments p ON pt.payment_id=p.id WHERE p.customer_id=?",[$cid]); $log[]='txn ok'; }
        catch(Exception $e){ $log[]='txn:'.$e->getMessage(); }

        // Step 2: suspicious_documents via documents JOIN
        try { $db->execute("DELETE sd FROM suspicious_documents sd INNER JOIN documents d ON sd.document_id=d.id WHERE d.customer_id=?",[$cid]); $log[]='sus ok'; }
        catch(Exception $e){ $log[]='sus:'.$e->getMessage(); }

        // Step 3: all direct child tables in FK-safe order
        foreach (['documents','reminders','invoice_logs','payments','bot_states',
                  'ai_usage_logs','notification_logs','qr_scan_logs',
                  'broadcast_queue','digital_lockers','messages','chat_sessions','applications'] as $t) {
            try { $db->execute("DELETE FROM `{$t}` WHERE customer_id=?",[$cid]); $log[]=$t.' ok'; }
            catch(Exception $e){ $log[]="{$t}:".$e->getMessage(); }
        }

        // Step 4: delete customer
        try {
            $db->execute("DELETE FROM customers WHERE id=?",[$cid]);
            Logger::info("Customer #{$cid} ({$c['name']}) deleted. Log: ".implode(', ',$log));
            echo json_encode(['success'=>true,'message'=>'✅ গ্রাহক ও সকল তথ্য মুছে ফেলা হয়েছে']);
        } catch(Exception $e) {
            Logger::error("Customer #{$cid} delete FAILED: ".$e->getMessage()." | Log: ".implode(', ',$log));
            echo json_encode(['success'=>false,'message'=>'মুছতে পারিনি: '.$e->getMessage()]);
        }
        break;

    // ── Customer Detail ───────────────────────────────────────
    case 'get_customer_detail':
        $cid = (int)($_POST['customer_id'] ?? 0);
        if (!$cid) { echo json_encode(['success'=>false,'message'=>'ID নেই']); break; }
        try {
            $c = $db->fetchOne(
                "SELECT c.id,c.name,c.mobile,c.address,c.platform,c.language,c.onboarding_done,
                        c.profile_photo_url,c.drive_folder_url,c.created_at,
                        co.name_bn as country_name
                 FROM customers c LEFT JOIN countries co ON c.country_id=co.id
                 WHERE c.id=?", [$cid]
            );
            if (!$c) { echo json_encode(['success'=>false,'message'=>'গ্রাহক পাওয়া যায়নি']); break; }

            $apps = $db->fetchAll(
                "SELECT a.id,a.tracking_id,a.created_at,a.admin_notes,
                        COALESCE(s.name,'—') as sname,
                        COALESCE(st.name,'—') as status_name,
                        COALESCE(st.color,'#6c757d') as color
                 FROM applications a
                 LEFT JOIN services s ON a.service_id=s.id
                 LEFT JOIN application_statuses st ON a.status_id=st.id
                 WHERE a.customer_id=? ORDER BY a.created_at DESC", [$cid]
            );

            $payments = $db->fetchAll(
                "SELECT p.id,p.total_amount,p.paid_amount,p.currency,p.description,p.created_at,
                        COALESCE(a.tracking_id,'') as tracking_id
                 FROM payments p
                 LEFT JOIN applications a ON p.application_id=a.id
                 WHERE p.customer_id=? ORDER BY p.created_at DESC", [$cid]
            );

            $docs = $db->fetchAll(
                "SELECT id,doc_type,doc_number,holder_name,expiry_date,drive_url,ocr_processed,created_at
                 FROM documents WHERE customer_id=? ORDER BY created_at DESC LIMIT 10", [$cid]
            );

            $totalAmt = array_sum(array_column($payments,'total_amount'));
            $paidAmt  = array_sum(array_column($payments,'paid_amount'));

            echo json_encode([
                'success'  => true,
                'customer' => $c,
                'apps'     => $apps,
                'payments' => $payments,
                'docs'     => $docs,
                'total'    => (float)$totalAmt,
                'paid'     => (float)$paidAmt,
                'due'      => (float)($totalAmt - $paidAmt),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch(Exception $e) {
            Logger::error('get_customer_detail: '.$e->getMessage());
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Add Application ───────────────────────────────────────
    case 'add_application':
        $cid   = (int)($_POST['customer_id'] ?? 0);
        $svcId = (int)($_POST['service_id']  ?? 0) ?: null;
        $stId  = (int)($_POST['status_id']   ?? 1);
        $notes = Security::sanitizeString($_POST['notes'] ?? '', 500);
        if (!$cid) { echo json_encode(['success'=>false,'message'=>'গ্রাহক নির্বাচন করুন']); exit; }
        $trackId = 'AD'.date('Ymd').str_pad(rand(1,9999),4,'0',STR_PAD_LEFT);
        $appId = $db->insert("INSERT INTO applications(tracking_id,customer_id,service_id,status_id,notes) VALUES(?,?,?,?,?)",
            [$trackId,$cid,$svcId,$stId,$notes]);
        echo json_encode(['success'=>true,'tracking_id'=>$trackId,'app_id'=>$appId]); break;

    // ── Add Payment ───────────────────────────────────────────
    case 'add_payment':
        $cid   = (int)($_POST['customer_id'] ?? 0);
        $appId = (int)($_POST['application_id'] ?? 0) ?: null;
        $total = (float)($_POST['total_amount'] ?? 0);
        $paid  = (float)($_POST['paid_amount']  ?? 0);
        $cur   = Security::sanitizeString($_POST['currency'] ?? 'BDT', 10);
        $desc  = Security::sanitizeString($_POST['description'] ?? '', 300);
        $pid = $db->insert("INSERT INTO payments(customer_id,application_id,total_amount,paid_amount,currency,description) VALUES(?,?,?,?,?,?)",
            [$cid,$appId,$total,$paid,$cur,$desc]);
        echo json_encode(['success'=>true,'payment_id'=>$pid]); break;

    // ── Edit Customer ─────────────────────────────────────────
    case 'edit_customer':
        $cid = (int)($_POST['customer_id'] ?? 0);
        $db->execute("UPDATE customers SET name=?,mobile=?,address=?,country_id=?,updated_at=NOW() WHERE id=?", [
            Security::sanitizeString($_POST['name']??'',150),
            Security::sanitizeString($_POST['mobile']??'',20),
            Security::sanitizeString($_POST['address']??'',500),
            !empty($_POST['country_id'])?(int)$_POST['country_id']:null,
            $cid
        ]);
        echo json_encode(['success'=>true,'message'=>'✅ আপডেট হয়েছে']); break;

    // ── Country CRUD ──────────────────────────────────────────
    case 'save_country':
        $id = (int)($_POST['id']??0);
        $data = [
            Security::sanitizeString($_POST['name_bn']??'',100),
            Security::sanitizeString($_POST['name_en']??'',100),
            strtoupper(Security::sanitizeString($_POST['code']??'',5)),
            Security::sanitizeString($_POST['currency']??'USD',10),
            Security::sanitizeString($_POST['phone_prefix']??'',10),
            Security::sanitizeString($_POST['flag_emoji']??'',10),
            isset($_POST['is_active'])?1:0,
        ];
        if ($id) $db->execute("UPDATE countries SET name_bn=?,name_en=?,code=?,currency=?,phone_prefix=?,flag_emoji=?,is_active=? WHERE id=?",array_merge($data,[$id]));
        else $id=$db->insert("INSERT INTO countries(name_bn,name_en,code,currency,phone_prefix,flag_emoji,is_active) VALUES(?,?,?,?,?,?,?)",$data);
        echo json_encode(['success'=>true,'id'=>$id]); break;

    case 'delete_country':
        $db->execute("DELETE FROM countries WHERE id=?",[(int)($_POST['id']??0)]);
        echo json_encode(['success'=>true]); break;

    case 'toggle_country':
        $db->execute("UPDATE countries SET is_active=!is_active WHERE id=?",[(int)($_POST['id']??0)]);
        echo json_encode(['success'=>true]); break;

    // ── Bot Menu ──────────────────────────────────────────────
    case 'save_menu_item':
        $id = (int)($_POST['id']??0);
        $lbl = Security::sanitizeString($_POST['label']??'',100);
        $mac = Security::sanitizeString($_POST['menu_action']??'',100);
        $ico = Security::sanitizeString($_POST['icon']??'',10);
        $ord = (int)($_POST['order_no']??0);
        $on  = isset($_POST['is_active'])?1:0;
        if ($id) $db->execute("UPDATE bot_menu_items SET label=?,action=?,icon=?,order_no=?,is_active=? WHERE id=?",[$lbl,$mac,$ico,$ord,$on,$id]);
        else $id=$db->insert("INSERT INTO bot_menu_items(label,action,icon,order_no,is_active) VALUES(?,?,?,?,?)",[$lbl,$mac,$ico,$ord,$on]);
        echo json_encode(['success'=>true,'id'=>$id]); break;

    case 'delete_menu_item':
        $db->execute("DELETE FROM bot_menu_items WHERE id=?",[(int)($_POST['id']??0)]);
        echo json_encode(['success'=>true]); break;

    case 'toggle_menu_item':
        $db->execute("UPDATE bot_menu_items SET is_active=!is_active WHERE id=?",[(int)($_POST['id']??0)]);
        echo json_encode(['success'=>true]); break;

    case 'save_menu_titles':
        Config::set('bot_menu_title_bn',$_POST['title_bn']??'');
        Config::set('bot_menu_title_en',$_POST['title_en']??'');
        echo json_encode(['success'=>true,'message'=>'✅ সেভ হয়েছে']); break;

    // ── FAQ ───────────────────────────────────────────────────
    case 'save_faq':
        $id=(int)($_POST['id']??0); $q=Security::sanitizeString($_POST['question']??'',1000);
        $a=Security::sanitizeString($_POST['answer']??'',2000); $kw=Security::sanitizeString($_POST['keywords']??'',500);
        if ($id) $db->execute("UPDATE faqs SET question=?,answer=?,keywords=? WHERE id=?",[$q,$a,$kw,$id]);
        else $id=$db->insert("INSERT INTO faqs(question,answer,keywords) VALUES(?,?,?)",[$q,$a,$kw]);
        echo json_encode(['success'=>true,'id'=>$id]); break;

    case 'delete_faq':
        $db->execute("DELETE FROM faqs WHERE id=?",[(int)($_POST['id']??0)]);
        echo json_encode(['success'=>true]); break;

    case 'toggle_faq':
        $db->execute("UPDATE faqs SET is_active=!is_active WHERE id=?",[(int)($_POST['id']??0)]);
        echo json_encode(['success'=>true]); break;

    // ── Delete Application ───────────────────────────────────
    case 'delete_application':
        $aid = (int)($_POST['app_id'] ?? 0);
        if (!$aid) { echo json_encode(['success'=>false,'message'=>'ID নেই']); break; }
        try {
            $db->execute("DELETE FROM payment_transactions WHERE payment_id IN (SELECT id FROM payments WHERE application_id=?)", [$aid]);
            $db->execute("DELETE FROM payments WHERE application_id=?", [$aid]);
            $db->execute("DELETE FROM applications WHERE id=?", [$aid]);
            echo json_encode(['success'=>true,'message'=>'✅ আবেদন মুছে ফেলা হয়েছে']);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Application Status Update ─────────────────────────────
    case 'update_application_status':
        $appId    = (int)($_POST['app_id']??0);
        $statusId = (int)($_POST['status_id']??0);
        $notes    = Security::sanitizeString($_POST['admin_notes']??'',500);
        if ($statusId) $db->execute("UPDATE applications SET status_id=?,admin_notes=?,updated_at=NOW() WHERE id=?",[$statusId,$notes,$appId]);
        else           $db->execute("UPDATE applications SET admin_notes=? WHERE id=?",[$notes,$appId]);
        // Send status notification
        if ($statusId) {
            try {
                $app  = $db->fetchOne("SELECT a.*,c.platform,c.platform_id,c.language,st.notify_message FROM applications a JOIN customers c ON a.customer_id=c.id LEFT JOIN application_statuses st ON st.id=? WHERE a.id=?",[$statusId,$appId]);
                if ($app && $app['notify_message']) {
                    require_once dirname(__DIR__).'/bot/Sender.php';
                    (new Sender())->send($app['platform'],$app['platform_id'],$app['notify_message']);
                }
            } catch(Exception $e) {}
        }
        echo json_encode(['success'=>true,'message'=>'✅ স্ট্যাটাস আপডেট হয়েছে']); break;

    // ── News / Broadcast ──────────────────────────────────────
    case 'scrape_news':
        require_once dirname(__DIR__).'/services/EmbassyNewsService.php';
        require_once dirname(__DIR__).'/bot/Sender.php';
        $service = new EmbassyNewsService();
        $force     = ($_POST['force']??'0') === '1';
        $countries = Security::sanitizeString($_POST['countries']??'', 200);
        $result    = $service->scrapeNews($force, $countries);
        $found   = $result['found'] ?? 0;
        $errMsg  = $result['error'] ?? '';
        if ($errMsg) { echo json_encode(['success'=>false,'found'=>0,'message'=>$errMsg]); break; }
        $msg = $result['message'] ?? ($found > 0 ? $found.'টি আপডেট পাওয়া গেছে!' : 'কোনো নতুন আপডেট নেই।');
        echo json_encode(['success'=>true,'found'=>$found,'message'=>$msg]); break;

    case 'approve_news':
        require_once dirname(__DIR__).'/services/EmbassyNewsService.php';
        require_once dirname(__DIR__).'/bot/Sender.php';
        $service = new EmbassyNewsService();
        $result  = $service->approveAndSend((int)($_POST['news_id']??0));
        echo json_encode($result); break;

    case 'reject_news':
        $db->execute("UPDATE embassy_news SET status='rejected' WHERE id=?",[(int)($_POST['news_id']??0)]);
        echo json_encode(['success'=>true]); break;

    case 'create_broadcast':
        require_once dirname(__DIR__).'/services/EmbassyNewsService.php';
        $queue = new BroadcastQueue();
        $id = $queue->create($_POST['message']??'',$_POST['target_type']??'all',
            !empty($_POST['country_id'])?(int)$_POST['country_id']:null,
            $_POST['platform']??'all');
        echo json_encode(['success'=>true,'id'=>$id,'message'=>"Broadcast Queue-এ যোগ হয়েছে!"]); break;

    // ── Member Card ───────────────────────────────────────────
    case 'generate_card':
        $cid = (int)($_POST['customer_id']??0);
        $c   = $db->fetchOne("SELECT * FROM customers WHERE id=?",[$cid]);
        if (!$c) { echo json_encode(['success'=>false,'message'=>'পাওয়া যায়নি']); exit; }
        try {
            require_once dirname(__DIR__).'/services/Services.php';
            require_once dirname(__DIR__).'/vendor/phpqrcode/qrlib.php';
            $qr  = new QRCodeService();
            $url = $qr->generateForCustomer($cid);
            echo json_encode(['success'=>true,'qr_url'=>$url,'message'=>'✅ Card তৈরি হয়েছে!']);
        } catch(Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        break;

    // ── Verdict for suspicious doc ────────────────────────────
    case 'set_verdict':
        $sid     = (int)($_POST['suspicious_id']??0);
        $verdict = in_array($_POST['verdict']??'',['genuine','fake','unclear']) ? $_POST['verdict'] : 'unclear';
        $note    = Security::sanitizeString($_POST['note']??'',500);
        $db->execute("UPDATE suspicious_documents SET admin_reviewed=1,admin_verdict=?,admin_note=?,reviewed_at=NOW() WHERE id=?",[$verdict,$note,$sid]);
        echo json_encode(['success'=>true,'message'=>'Verdict সেভ হয়েছে']); break;

    case 'send_reminder':
        $did = (int)($_POST['doc_id']??0);
        $doc = $db->fetchOne("SELECT d.*,c.platform,c.platform_id,c.name,c.language FROM documents d JOIN customers c ON d.customer_id=c.id WHERE d.id=?",[$did]);
        if ($doc && $doc['expiry_date']) {
            require_once dirname(__DIR__).'/bot/Sender.php';
            $days = (int)ceil((strtotime($doc['expiry_date'])-time())/86400);
            $msg  = "⚠️ আপনার ".($doc['doc_type']??'ডকুমেন্ট')." ".($doc['doc_number']??"")." এর মেয়াদ ".date('d/m/Y',strtotime($doc['expiry_date']))." তারিখে শেষ। আর {$days} দিন বাকি।\n📞 ".Config::get('company_phone','');
            (new Sender())->send($doc['platform'],$doc['platform_id'],$msg);
            echo json_encode(['success'=>true,'message'=>'✅ Reminder পাঠানো হয়েছে']);
        } else { echo json_encode(['success'=>false,'message'=>'তথ্য নেই']); }
        break;

    case 'delete_document':
        $did = (int)($_POST['doc_id']??0);
        $doc = $db->fetchOne("SELECT * FROM documents WHERE id=?",[$did]);
        if ($doc) {
            if (!empty($doc['drive_file_id'])) {
                try { require_once dirname(__DIR__).'/services/GoogleDriveService.php'; (new GoogleDriveService())->deleteFile($doc['drive_file_id']); } catch(Exception $e) {}
            }
            $db->execute("DELETE FROM reminders WHERE document_id=?",[$did]);
            $db->execute("DELETE FROM suspicious_documents WHERE document_id=?",[$did]);
            $db->execute("DELETE FROM documents WHERE id=?",[$did]);
            echo json_encode(['success'=>true]);
        } else { echo json_encode(['success'=>false,'message'=>'পাওয়া যায়নি']); }
        break;

    // ── Chat Messages ─────────────────────────────────────────
    case 'get_new_messages':
    case 'get_chat_messages':
        $cid    = (int)($_POST['customer_id']??0);
        $lastId = (int)($_POST['last_id']??0);
        $msgs   = $db->fetchAll(
            "SELECT id,direction,message_type,content,sent_at FROM messages
             WHERE customer_id=? AND id>? ORDER BY id ASC LIMIT 20",
            [$cid, $lastId]
        );
        echo json_encode(['success'=>true,'messages'=>$msgs]); break;

    case 'send_chat_reply':
        $cid = (int)($_POST['customer_id']??0);
        $msg = Security::sanitizeString($_POST['message']??'',2000);
        $c   = $db->fetchOne("SELECT * FROM customers WHERE id=?",[$cid]);
        if ($c && $msg) {
            require_once dirname(__DIR__).'/bot/Sender.php';
            (new Sender())->send($c['platform'],$c['platform_id'],$msg);
            $db->execute("INSERT INTO messages(customer_id,platform,direction,message_type,content) VALUES(?,?,?,?,?)",
                [$cid,$c['platform'],'out','text',$msg]);
            $db->execute("UPDATE chat_sessions SET mode='human' WHERE customer_id=?",[$cid]);
            echo json_encode(['success'=>true]);
        } else { echo json_encode(['success'=>false]); }
        break;

    case 'set_human_mode':
        $cid  = (int)($_POST['customer_id']??0);
        $mode = $_POST['mode']??'human';
        $db->execute("UPDATE chat_sessions SET mode=? WHERE customer_id=?",[$mode,$cid]);
        echo json_encode(['success'=>true]); break;

    // ── AI Test ──────────────────────────────────────────────
    case 'test_ai':
        $key = Config::get('gemini_api_key','');
        if (!$key) { echo json_encode(['success'=>false,'message'=>'Gemini API Key সেট নেই!','http_code'=>0]); break; }

        $savedModel = trim(Config::get('gemini_model','gemini-2.0-flash'));

        // Test the saved model first, then fallbacks
        $modelsToTest = array_unique(array_filter([$savedModel,'gemini-3.1-flash','gemini-2.5-flash','gemini-2.0-flash','gemini-1.5-flash']));

        $workingModel = null;
        $lastCode = 0;
        $lastErr  = '';

        foreach ($modelsToTest as $testModel) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$testModel}:generateContent?key={$key}";
            $ch  = curl_init($url);
            curl_setopt_array($ch,[
                CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>15,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
                CURLOPT_POSTFIELDS=>json_encode([
                    'contents'=>[['role'=>'user','parts'=>[['text'=>'Say: OK']]]],
                    'system_instruction'=>['parts'=>[['text'=>'Reply: OK']]],
                    'generationConfig'=>['maxOutputTokens'=>10],
                ]),
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
            curl_close($ch);
            $lastCode = $code;

            $data = json_decode($resp,true);
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            $lastErr = $data['error']['message'] ?? '';

            if ($code === 200 && $text) {
                $workingModel = $testModel;
                // Auto-save the working model
                if ($testModel !== $savedModel) {
                    Config::set('gemini_model', $testModel);
                    Config::clearCache();
                }
                break;
            }
        }

        if ($workingModel) {
            echo json_encode([
                'success'   => true,
                'model'     => $workingModel,
                'http_code' => 200,
                'response'  => 'OK',
                'fixed'     => $workingModel !== $savedModel,
                'message'   => $workingModel !== $savedModel
                    ? "⚠️ '{$savedModel}' কাজ করে না। '{$workingModel}' তে auto-fix হয়েছে!"
                    : "✅ কাজ করছে!",
            ]);
        } else {
            echo json_encode([
                'success'   => false,
                'model'     => $savedModel,
                'http_code' => $lastCode,
                'response'  => $lastErr ?: 'সব model fail করেছে',
            ]);
        }
        break;

    // ── Set Telegram Webhook ─────────────────────────────────
    case 'set_telegram_webhook':
        $tok = Config::get('telegram_token','');
        $url = Security::sanitizeString($_POST['webhook_url']??'', 500);
        if (!$tok) { echo json_encode(['success'=>false,'message'=>'Telegram token সেট নেই!']); break; }
        if (!$url) { echo json_encode(['success'=>false,'message'=>'webhook_url দিন']); break; }
        $ch = curl_init("https://api.telegram.org/bot{$tok}/setWebhook");
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['url'=>$url,'drop_pending_updates'=>true])]);
        $resp = curl_exec($ch); curl_close($ch);
        $r = json_decode($resp,true);
        echo json_encode(['success'=>($r['ok']??false),'message'=>$r['description']??'Done']); break;

    // ── Test Google Drive ─────────────────────────────────────
    case 'test_drive':
        $json = Config::get('google_service_account_json','');
        if (!$json) { echo json_encode(['success'=>false,'message'=>'Service Account JSON সেট নেই']); break; }
        $json = stripslashes($json);
        $sa = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success'=>false,'message'=>'JSON parse error: '.json_last_error_msg()]);
            break;
        }
        if (!isset($sa['private_key'],$sa['client_email'])) {
            echo json_encode(['success'=>false,'message'=>'JSON-এ private_key বা client_email নেই']);
            break;
        }
        if (!function_exists('openssl_sign')) {
            echo json_encode(['success'=>false,'message'=>'PHP openssl extension নেই - hosting provider-কে বলুন']);
            break;
        }
        try {
            require_once dirname(__DIR__).'/services/GoogleDriveService.php';
            $drive = new GoogleDriveService();
            if (!$drive->isReady()) {
                echo json_encode(['success'=>false,'message'=>'Authentication failed - logs/bot.log দেখুন']);
                break;
            }
            echo json_encode(['success'=>true,'message'=>'Google Drive সংযুক্ত! Authentication সফল।']);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Fix Gemini Model ─────────────────────────────────────
    case 'fix_gemini_model':
        Config::set('gemini_model', 'gemini-3.1-flash');
        Config::clearCache();
        echo json_encode(['success'=>true,'message'=>'✅ Gemini model = gemini-3.1-flash সেট হয়েছে!']); break;

    // ── Clear Cache ───────────────────────────────────────────
    case 'clear_cache':
        Config::clearCache();
        // Clear OPcache if available
        if (function_exists('opcache_reset')) opcache_reset();
        // Clear any tmp files older than 1 day
        $tmpDir = BASE_PATH.'/tmp';
        if (is_dir($tmpDir)) {
            foreach (glob($tmpDir.'/*') as $f) {
                if (is_file($f) && filemtime($f) < time()-86400) @unlink($f);
            }
        }
        Logger::info('Cache cleared by admin');
        echo json_encode(['success'=>true,'message'=>'✅ Cache clear হয়েছে']); break;

    // ── Ping (test) ───────────────────────────────────────────
    case 'ping':
        echo json_encode(['success'=>true,'time'=>date('H:i:s'),'csrf'=>$_SESSION['csrf_token']??'']); break;

    // ── Chat Mode Toggle ──────────────────────────────────
    case 'toggle_chat_mode':
        $cid  = (int)($_POST['customer_id'] ?? 0);
        $mode = ($_POST['mode'] ?? 'bot') === 'human' ? 'human' : 'bot';
        if (!$cid) { echo json_encode(['success'=>false]); break; }
        $sess = $db->fetchOne("SELECT id FROM chat_sessions WHERE customer_id=? ORDER BY started_at DESC LIMIT 1", [$cid]);
        if ($sess) {
            $db->execute("UPDATE chat_sessions SET mode=? WHERE id=?", [$mode, $sess['id']]);
        } else {
            $db->execute("INSERT INTO chat_sessions(customer_id,platform,mode) VALUES(?,'unknown',?)", [$cid,$mode]);
        }
        echo json_encode(['success'=>true,'mode'=>$mode]);
        break;

    // ── Chat Send File ───────────────────────────────────
    case 'send_file_to_customer':
        $cid = (int)($_POST['customer_id'] ?? 0);
        if (!$cid) { echo json_encode(['success'=>false,'message'=>'No customer']); break; }
        if (empty($_FILES['file']['tmp_name'])) { echo json_encode(['success'=>false,'message'=>'No file']); break; }
        $customer = $db->fetchOne("SELECT * FROM customers WHERE id=?", [$cid]);
        if (!$customer) { echo json_encode(['success'=>false,'message'=>'Not found']); break; }
        try {
            require_once dirname(__DIR__).'/config/bootstrap.php';
            require_once dirname(__DIR__).'/services/LocalStorageService.php';
            $tmpPath  = $_FILES['file']['tmp_name'];
            $origName = basename($_FILES['file']['name']);
            // Save to local storage
            $ls     = new LocalStorageService();
            $saved  = $ls->saveFile($customer, $tmpPath, $origName);
            if (!$saved) throw new Exception('File save failed');
            // Send download link to customer
            $sender = new Sender();
            $lang   = $customer['language'] ?? 'bn';
            $msg = ($lang === 'bn')
                ? "📎 আপনার জন্য একটি ফাইল পাঠানো হয়েছে:
".$saved['download_url']
                : "📎 A file has been sent to you:
".$saved['download_url'];
            $sender->send($customer['platform'], $customer['chat_id'], $msg);
            $db->insert("INSERT INTO messages(customer_id,platform,direction,content,sent_at) VALUES(?,?,?,?,NOW())",
                [$cid, $customer['platform'], 'out', '[📎 '.$origName.']']);
            echo json_encode(['success'=>true,'file_name'=>$origName]);
        } catch(Exception $e) {
            Logger::error('send_file: '.$e->getMessage());
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Chat Send Reply ───────────────────────────────────
    case 'send_chat_reply':
        $cid     = (int)($_POST['customer_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        if (!$cid || !$message) { echo json_encode(['success'=>false,'message'=>'Empty']); break; }
        $customer = $db->fetchOne("SELECT * FROM customers WHERE id=?", [$cid]);
        if (!$customer) { echo json_encode(['success'=>false,'message'=>'Not found']); break; }
        try {
            require_once dirname(__DIR__).'/config/bootstrap.php';
            $sender = new Sender();
            $sender->send($customer['platform'], $customer['chat_id'], $message);
            $db->insert("INSERT INTO messages(customer_id,platform,direction,content,sent_at) VALUES(?,?,?,?,NOW())",
                [$cid, $customer['platform'], 'out', $message]);
            echo json_encode(['success'=>true]);
        } catch(Exception $e) {
            Logger::error('send_chat_reply: '.$e->getMessage());
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    // ── Chat Get New Messages ─────────────────────────────
    case 'get_new_messages':
        $cid    = (int)($_POST['customer_id'] ?? 0);
        $lastId = (int)($_POST['last_id'] ?? 0);
        $msgs   = $db->fetchAll("SELECT * FROM messages WHERE customer_id=? AND id>? ORDER BY sent_at ASC LIMIT 30", [$cid,$lastId]);
        echo json_encode(['success'=>true,'messages'=>$msgs]);
        break;

    // ── Services ─────────────────────────────────────────────
    case 'save_service':
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            Security::sanitizeString($_POST['name'] ?? '', 200),
            Security::sanitizeString($_POST['description'] ?? '', 2000),
            (float)($_POST['price'] ?? 0),
            Security::sanitizeString($_POST['currency'] ?? 'BDT', 10),
            !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
            !empty($_POST['country_from_id']) ? (int)$_POST['country_from_id'] : null,
            !empty($_POST['country_for_id'])  ? (int)$_POST['country_for_id']  : null,
            Security::sanitizeString($_POST['docs_required'] ?? '', 2000),
            Security::sanitizeString($_POST['duration'] ?? '', 100),
            isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($id) {
            $db->execute("UPDATE services SET name=?,description=?,price=?,currency=?,category_id=?,country_from_id=?,country_for_id=?,docs_required=?,duration=?,is_active=? WHERE id=?", array_merge($data, [$id]));
        } else {
            $id = $db->insert("INSERT INTO services(name,description,price,currency,category_id,country_from_id,country_for_id,docs_required,duration,is_active) VALUES(?,?,?,?,?,?,?,?,?,?)", $data);
        }
        echo json_encode(['success'=>true,'id'=>$id,'message'=>'✅ সেবা সেভ হয়েছে']); break;

    case 'delete_service':
        $db->execute("DELETE FROM services WHERE id=?", [(int)($_POST['id']??0)]);
        echo json_encode(['success'=>true]); break;

    case 'toggle_service':
        $db->execute("UPDATE services SET is_active=!is_active WHERE id=?", [(int)($_POST['id']??0)]);
        echo json_encode(['success'=>true]); break;

    // ── Categories ────────────────────────────────────────────
    case 'save_category':
        $id = (int)($_POST['id'] ?? 0);
        $name = Security::sanitizeString($_POST['name'] ?? '', 100);
        $icon = Security::sanitizeString($_POST['icon'] ?? '📋', 10);
        if ($id) $db->execute("UPDATE service_categories SET name=?,icon=? WHERE id=?", [$name,$icon,$id]);
        else $id = $db->insert("INSERT INTO service_categories(name,icon) VALUES(?,?)", [$name,$icon]);
        echo json_encode(['success'=>true,'id'=>$id]); break;

    case 'delete_category':
        $db->execute("DELETE FROM service_categories WHERE id=?", [(int)($_POST['id']??0)]);
        echo json_encode(['success'=>true]); break;

    // ── Agents ────────────────────────────────────────────────
    case 'save_agent':
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            Security::sanitizeString($_POST['name'] ?? '', 150),
            Security::sanitizeString($_POST['mobile'] ?? '', 20),
            Security::sanitizeString($_POST['telegram_id'] ?? '', 50),
            Security::sanitizeString($_POST['whatsapp_number'] ?? '', 20),
            Security::sanitizeString($_POST['photo_url'] ?? '', 500),
            isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($id) {
            $db->execute("UPDATE agents SET name=?,mobile=?,telegram_id=?,whatsapp_number=?,photo_url=?,is_active=? WHERE id=?", array_merge($data, [$id]));
        } else {
            $id = $db->insert("INSERT INTO agents(name,mobile,telegram_id,whatsapp_number,photo_url,is_active) VALUES(?,?,?,?,?,?)", $data);
        }
        echo json_encode(['success'=>true,'id'=>$id,'message'=>'✅ এজেন্ট সেভ হয়েছে']); break;

    case 'delete_agent':
        $db->execute("DELETE FROM agents WHERE id=?", [(int)($_POST['id']??0)]);
        echo json_encode(['success'=>true]); break;

    // ── Application Statuses ──────────────────────────────────
    case 'save_status':
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            Security::sanitizeString($_POST['name'] ?? '', 100),
            Security::sanitizeString($_POST['name_en'] ?? '', 100),
            Security::sanitizeString($_POST['color'] ?? '#6c757d', 20),
            Security::sanitizeString($_POST['notify_message'] ?? '', 1000),
            (int)($_POST['order_no'] ?? 0),
        ];
        if ($id) $db->execute("UPDATE application_statuses SET name=?,name_en=?,color=?,notify_message=?,order_no=? WHERE id=?", array_merge($data, [$id]));
        else $id = $db->insert("INSERT INTO application_statuses(name,name_en,color,notify_message,order_no) VALUES(?,?,?,?,?)", $data);
        echo json_encode(['success'=>true,'id'=>$id]); break;

    case 'delete_status':
        $db->execute("DELETE FROM application_statuses WHERE id=?", [(int)($_POST['id']??0)]);
        echo json_encode(['success'=>true]); break;

    // ── Onboarding Fields ─────────────────────────────────────
    case 'save_onboarding_field':
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            Security::sanitizeString($_POST['field_key'] ?? '', 50),
            Security::sanitizeString($_POST['field_label_bn'] ?? '', 200),
            Security::sanitizeString($_POST['field_label_en'] ?? '', 200),
            (int)($_POST['order_no'] ?? 0),
            isset($_POST['is_required']) ? 1 : 0,
            1,
        ];
        if ($id) $db->execute("UPDATE onboarding_fields SET field_key=?,field_label_bn=?,field_label_en=?,order_no=?,is_required=?,is_active=? WHERE id=?", array_merge($data, [$id]));
        else $id = $db->insert("INSERT INTO onboarding_fields(field_key,field_label_bn,field_label_en,order_no,is_required,is_active) VALUES(?,?,?,?,?,?)", $data);
        echo json_encode(['success'=>true,'id'=>$id]); break;

    case 'delete_onboarding_field':
        $db->execute("DELETE FROM onboarding_fields WHERE id=?", [(int)($_POST['id']??0)]);
        echo json_encode(['success'=>true]); break;

    // ── Payment ───────────────────────────────────────────────
    case 'save_payment':
        $cid   = (int)($_POST['customer_id'] ?? 0);
        $appId = !empty($_POST['application_id']) ? (int)$_POST['application_id'] : null;
        $total = (float)($_POST['total_amount'] ?? 0);
        $paid  = (float)($_POST['paid_amount']  ?? 0);
        $cur   = Security::sanitizeString($_POST['currency'] ?? 'BDT', 10);
        $desc  = Security::sanitizeString($_POST['description'] ?? '', 300);
        $pid   = $db->insert("INSERT INTO payments(customer_id,application_id,total_amount,paid_amount,currency,description) VALUES(?,?,?,?,?,?)",
            [$cid,$appId,$total,$paid,$cur,$desc]);
        echo json_encode(['success'=>true,'id'=>$pid,'message'=>'✅ পেমেন্ট সেভ হয়েছে']); break;

    default:
        echo json_encode(['success'=>false,'message'=>"Unknown action: {$act}"]);
}
