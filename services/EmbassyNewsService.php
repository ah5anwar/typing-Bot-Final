<?php
// services/EmbassyNewsService.php — FIXED
class EmbassyNewsService {
    private Database $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function scrapeNews(bool $force = false, string $countryCodes = ''): array {
        if (Config::get('embassy_news_enabled','1') === '0') {
            return ['error'=>'Embassy News বন্ধ আছে। Settings থেকে চালু করুন।'];
        }
        $key = Config::get('gemini_api_key','');
        if (!$key) {
            return ['error'=>'Gemini API Key সেট করা নেই। Settings → Gemini API Key দিন।'];
        }

        $allSources = [
            'AE'=>'UAE / দুবাই', 'SA'=>'সৌদি আরব', 'OM'=>'ওমান',
            'QA'=>'কাতার',       'KW'=>'কুয়েত',     'BH'=>'বাহারাইন',
            'MY'=>'মালয়েশিয়া',  'SG'=>'সিঙ্গাপুর',  'LY'=>'লিবিয়া',
            'IT'=>'ইতালি',       'GB'=>'যুক্তরাজ্য', 'US'=>'আমেরিকা',
        ];
        // Use selected countries or fallback to saved setting
        $selected = $countryCodes
            ? array_filter(explode(',', $countryCodes))
            : array_filter(explode(',', Config::get('news_countries','AE,SA,OM,QA,KW,BH,MY,SG')));
        $sources = [];
        foreach ($selected as $code) {
            $code = strtoupper(trim($code));
            if (isset($allSources[$code])) {
                $sources[] = ['code'=>$code, 'name'=>$allSources[$code]];
            }
        }
        if (empty($sources)) {
            foreach ($allSources as $code=>$name) $sources[] = ['code'=>$code,'name'=>$name];
        }

        $found = 0; $errors = [];
        foreach ($sources as $src) {
            try {
                // force=false হলে আজকের news থাকলে skip
                if (!$force) {
                    $exists = $this->db->fetchOne(
                        "SELECT id FROM embassy_news WHERE country_code=? AND DATE(scraped_at)=CURDATE()",
                        [$src['code']]
                    );
                    if ($exists) continue;
                }

                $summary = $this->generateNews($key, $src['name']);
                if ($summary) {
                    $this->db->insert(
                        "INSERT INTO embassy_news (country_code,country_name,source_url,content,status,scraped_at) VALUES (?,?,?,?,?,NOW())",
                        [$src['code'],$src['name'],'Gemini AI',$summary,'pending']
                    );
                    $found++;
                }
                sleep(1);
            } catch (Exception $e) {
                $errors[] = $src['name'].': '.substr($e->getMessage(),0,50);
                Logger::error("EmbassyNews [{$src['code']}]: ".$e->getMessage());
            }
        }

        if ($found === 0 && empty($errors)) {
            return ['found'=>0,'message'=>'আজকের news ইতিমধ্যে সংগ্রহ হয়েছে। "Force" দিয়ে আবার চেষ্টা করুন।'];
        }
        return ['found'=>$found,'errors'=>$errors,'message'=>$found.'টি দেশের আপডেট পাওয়া গেছে!'];
    }

    private function generateNews(string $key, string $country): ?string {
        $prompt = "আজকের তারিখে {$country}-এ প্রবাসী বাংলাদেশি কর্মীদের জন্য ভিসা, ইকামা, ওয়ার্ক পারমিট বা ইমিগ্রেশন সংক্রান্ত কোনো গুরুত্বপূর্ণ আপডেট বা নতুন নিয়ম থাকলে ৩-৫ লাইনে বাংলায় সহজ ভাষায় লেখো। যদি সত্যিই কোনো উল্লেখযোগ্য আপডেট না থাকে তাহলে সেই দেশের সর্বশেষ একটি গুরুত্বপূর্ণ তথ্য দাও যা প্রবাসীদের জানা দরকার। উত্তরে শুধু বাংলায় লেখো, কোনো ইংরেজি বা মার্কডাউন ব্যবহার করো না।";

        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/" . (Config::get("gemini_model","gemini-3.1-flash") ?: "gemini-3.1-flash") . ":generateContent?key={$key}");
        curl_setopt_array($ch,[
            CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_TIMEOUT=>25,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode(['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['maxOutputTokens'=>300,'temperature'=>0.7]]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) throw new Exception("Gemini HTTP $code");
        $r    = json_decode($resp, true);
        $text = trim($r['candidates'][0]['content']['parts'][0]['text'] ?? '');
        return strlen($text) > 20 ? $text : null;
    }

    public function approveAndSend(int $newsId): array {
        $news = $this->db->fetchOne("SELECT * FROM embassy_news WHERE id=?",[$newsId]);
        if (!$news) return ['success'=>false,'message'=>'পাওয়া যায়নি'];

        $country   = $this->db->fetchOne("SELECT id FROM countries WHERE code=?",[$news['country_code']]);
        // Send to: matching country + customers with no country set + all (if no country match found)
        if ($country) {
            $customers = $this->db->fetchAll(
                "SELECT * FROM customers WHERE onboarding_done=1 AND (country_id=? OR country_id IS NULL)",
                [$country['id']]
            );
        } else {
            $customers = $this->db->fetchAll("SELECT * FROM customers WHERE onboarding_done=1");
        }

        if (empty($customers)) {
            $this->db->execute("UPDATE embassy_news SET status='sent',sent_at=NOW(),sent_count=0 WHERE id=?",[$newsId]);
            return ['success'=>true,'sent'=>0,'message'=>'এই দেশের কোনো গ্রাহক নেই।'];
        }

        require_once __DIR__.'/../bot/Sender.php';
        $sender = new Sender();
        $flag   = ['AE'=>'🇦🇪','SA'=>'🇸🇦','OM'=>'🇴🇲','QA'=>'🇶🇦','KW'=>'🇰🇼','BH'=>'🇧🇭','MY'=>'🇲🇾','SG'=>'🇸🇬'][$news['country_code']] ?? '🌍';
        $msg    = "{$flag} *{$news['country_name']} — সর্বশেষ আপডেট*\n\n{$news['content']}\n\n_".date('d/m/Y H:i',strtotime($news['scraped_at']))."_";

        $sent = 0;
        foreach ($customers as $c) {
            try { $sender->send($c['platform'],$c['platform_id'],$msg); $sent++; usleep(350000); }
            catch (Exception $e) { Logger::error('EmbassyNews send: '.$e->getMessage()); }
        }
        $this->db->execute("UPDATE embassy_news SET status='sent',sent_at=NOW(),sent_count=? WHERE id=?",[$sent,$newsId]);
        return ['success'=>true,'sent'=>$sent,'message'=>"{$sent}জন গ্রাহককে পাঠানো হয়েছে।"];
    }
}

class BroadcastQueue {
    private Database $db;
    private int $batchSize = 50;
    public function __construct() { $this->db = Database::getInstance(); }

    public function create(string $message, string $target='all', ?int $countryId=null, ?string $platform=null): int {
        $id = $this->db->insert(
            "INSERT INTO broadcasts (message,target_type,target_country_id,target_platform,status) VALUES (?,?,?,?,?)",
            [$message,$target,$countryId,$platform??'all','queued']
        );
        $sql = "SELECT id FROM customers WHERE onboarding_done=1"; $p=[];
        if ($countryId) { $sql.=" AND country_id=?"; $p[]=$countryId; }
        if ($platform && $platform!=='all') { $sql.=" AND platform=?"; $p[]=$platform; }
        $customers = $this->db->fetchAll($sql,$p);
        foreach ($customers as $c) {
            $this->db->execute("INSERT INTO broadcast_queue (broadcast_id,customer_id,status) VALUES (?,?,'pending')",[$id,$c['id']]);
        }
        Logger::info("Broadcast #{$id} queued for ".count($customers)." customers");
        return $id;
    }

    public function process(): array {
        if (!Config::isEnabled('broadcast_enabled')) return ['processed'=>0];
        $broadcasts = $this->db->fetchAll(
            "SELECT * FROM broadcasts WHERE status IN ('queued','sending') ORDER BY created_at ASC LIMIT 3"
        );
        if (empty($broadcasts)) return ['processed'=>0];

        require_once __DIR__.'/../bot/Sender.php';
        $sender = new Sender();
        $total  = 0;

        foreach ($broadcasts as $b) {
            $this->db->execute("UPDATE broadcasts SET status='sending' WHERE id=?",[$b['id']]);
            $items = $this->db->fetchAll(
                "SELECT bq.*,c.platform,c.platform_id FROM broadcast_queue bq JOIN customers c ON bq.customer_id=c.id WHERE bq.broadcast_id=? AND bq.status='pending' LIMIT ?",
                [$b['id'],$this->batchSize]
            );
            if (empty($items)) {
                $sent   = $this->db->count("SELECT COUNT(*) as c FROM broadcast_queue WHERE broadcast_id=? AND status='sent'",[$b['id']]);
                $failed = $this->db->count("SELECT COUNT(*) as c FROM broadcast_queue WHERE broadcast_id=? AND status='failed'",[$b['id']]);
                $this->db->execute("UPDATE broadcasts SET status='sent',sent_count=?,failed_count=?,sent_at=NOW() WHERE id=?",[$sent,$failed,$b['id']]);
                continue;
            }
            foreach ($items as $item) {
                try {
                    $sender->send($item['platform'],$item['platform_id'],$b['message']);
                    $this->db->execute("UPDATE broadcast_queue SET status='sent',sent_at=NOW() WHERE id=?",[$item['id']]);
                    $total++; usleep(350000);
                } catch(Exception $e) {
                    $this->db->execute("UPDATE broadcast_queue SET status='failed',error=? WHERE id=?",
                        [substr($e->getMessage(),0,200),$item['id']]);
                }
            }
        }
        return ['processed'=>$total];
    }
}
