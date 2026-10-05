<?php
// ============================================================
// services/Services.php — FIXED CurrencyService + QRCodeService
// ============================================================

if (!class_exists('QRCodeService')) {
    class QRCodeService {
        private string $qrDir;
        private string $cardDir;
        private string $baseUrl;

        public function __construct() {
            $this->qrDir   = BASE_PATH . '/public/qr';
            $this->cardDir = BASE_PATH . '/public/cards';
            $this->baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com');
            foreach ([$this->qrDir, $this->cardDir] as $d) {
                if (!is_dir($d)) @mkdir($d, 0755, true);
            }
        }

        public function generateForCustomer(int $cid): ?string {
            $db  = Database::getInstance();
            $c   = $db->fetchOne("SELECT * FROM customers WHERE id=?", [$cid]);
            if (!$c) return null;

            $token  = substr(md5($cid . 'qr_salt_2025_' . Config::get('admin_username')), 0, 16);
            $url    = $this->baseUrl . '/track/?cid=' . $cid . '&token=' . $token;
            $qrPath = $this->qrDir . '/qr_' . $cid . '.png';

            // Google QR API
            $img = @file_get_contents("https://api.qrserver.com/v1/create-qr-code/?size=280x280&ecc=M&data=" . urlencode($url));
            if ($img) {
                file_put_contents($qrPath, $img);
            } else {
                // Fallback: GD simple QR placeholder
                if (extension_loaded('gd')) {
                    $im = imagecreatetruecolor(200, 200);
                    $w  = imagecolorallocate($im, 255,255,255);
                    $b  = imagecolorallocate($im, 0,0,0);
                    imagefill($im, 0, 0, $w);
                    imagerectangle($im, 10, 10, 190, 190, $b);
                    imagestring($im, 3, 50, 90, 'QR:' . $cid, $b);
                    imagepng($im, $qrPath);
                    imagedestroy($im);
                }
            }

            if (file_exists($qrPath)) {
                $qrUrl = $this->baseUrl . '/public/qr/qr_' . $cid . '.png';
                $db->execute("UPDATE customers SET qr_code=? WHERE id=?", [$qrUrl, $cid]);
                return $qrUrl;
            }
            return null;
        }

        public function generateAgentCard(array $agent): ?string {
            $co   = Config::get('company_name','ভিসা সার্ভিস');
            $wa   = $agent['whatsapp_number'] ?? '';
            $qrData = $wa ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $wa) : ($agent['telegram_id'] ?? $co);
            $qrB64  = '';
            $img = @file_get_contents("https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=" . urlencode($qrData));
            if ($img) $qrB64 = 'data:image/png;base64,' . base64_encode($img);

            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f0f4f8}
.c{width:340px;background:white;border-radius:18px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.15)}
.top{background:linear-gradient(135deg,#1a3c6e,#2e86c1);padding:28px 24px;text-align:center;color:white}
.av{width:70px;height:70px;border-radius:50%;background:rgba(255,255,255,.2);border:3px solid rgba(255,255,255,.5);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:bold;margin:0 auto 10px}
.nm{font-size:18px;font-weight:bold}.rl{font-size:12px;opacity:.8;margin-top:3px}
.bd{padding:20px}
.ci{display:flex;align-items:center;gap:10px;padding:9px 12px;background:#f8f9fa;border-radius:8px;margin-bottom:8px;font-size:13px}
.qw{text-align:center;padding:14px;background:#f8f9fa;border-radius:12px}
.qw img{width:130px;height:130px}.qt{font-size:11px;color:#999;margin-top:6px}
.ft{background:#1a3c6e;padding:10px;text-align:center;color:rgba(255,255,255,.7);font-size:10px}
</style></head><body><div class="c">
<div class="top"><div class="av">' . (!empty($agent['photo_url']) ? '<img src="'.$agent['photo_url'].'" style="width:100%;height:100%;object-fit:cover">' : mb_substr($agent['name'],0,1,'UTF-8')) . '</div>
<div class="nm">' . htmlspecialchars($agent['name']) . '</div>
<div class="rl">Visa Consultant | ' . htmlspecialchars($co) . '</div></div>
<div class="bd">';
            if ($agent['mobile'])          $html .= '<div class="ci"><span>📞</span>' . htmlspecialchars($agent['mobile']) . '</div>';
            if ($agent['whatsapp_number']) $html .= '<div class="ci"><span>💚</span>' . htmlspecialchars($agent['whatsapp_number']) . '</div>';
            if ($agent['telegram_id'])     $html .= '<div class="ci"><span>🤖</span>' . htmlspecialchars($agent['telegram_id']) . '</div>';
            if ($qrB64) $html .= '<div class="qw"><img src="' . $qrB64 . '"><div class="qt">স্ক্যান করে যোগাযোগ করুন</div></div>';
            $html .= '</div><div class="ft">' . htmlspecialchars($co) . ' | ' . date('Y') . '</div></div></body></html>';

            $path = $this->cardDir . '/agent_' . $agent['id'] . '.html';
            file_put_contents($path, $html);
            $url = $this->baseUrl . '/public/cards/agent_' . $agent['id'] . '.html';
            Database::getInstance()->execute("UPDATE agents SET qr_card_url=? WHERE id=?", [$url, $agent['id']]);
            return $url;
        }

        public function sendCardToCustomer(array $c): void {
            require_once __DIR__ . '/../bot/Sender.php';
            $sender = new Sender();
            $qrUrl  = $c['qr_code'] ?? $this->generateForCustomer($c['id']);
            $memId  = 'MEM-' . str_pad($c['id'], 6, '0', STR_PAD_LEFT);
            $lang   = $c['language'] ?? 'bn';

            $msg  = $lang==='bn' ? "🪪 *আপনার Digital Member Card*\n\n" : "🪪 *Your Digital Member Card*\n\n";
            $msg .= "Member ID: *{$memId}*\n\n";
            if ($qrUrl) $msg .= "📱 QR Code: {$qrUrl}\n";
            $msg .= $lang==='bn' ? "QR Code স্ক্যান করলে আপনার সব তথ্য দেখা যাবে।" : "Scan QR code to view all your details.";

            $sender->send($c['platform'], $c['platform_id'], $msg);
        }

        public function verifyToken(int $cid, string $token): bool {
            return $token === substr(md5($cid . 'qr_salt_2025_' . Config::get('admin_username')), 0, 16);
        }
    }
}


// ── CurrencyService ────────────────────────────────────────────
class CurrencyService {

    public function updateRates(): bool {
        $apiKey = Config::get('currency_api_key', '');
        return $apiKey ? $this->fetchFromAPI($apiKey) : $this->useFallback();
    }

    private function fetchFromAPI(string $key): bool {
        // Support both exchangerate-api.com and fixer.io formats
        $url = "https://v6.exchangerate-api.com/v6/{$key}/latest/USD";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_TIMEOUT=>15]);
        $resp = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($resp, true);
        if (!$data || ($data['result']??'') !== 'success') {
            Logger::error('CurrencyService API error: ' . substr($resp,0,200));
            return $this->useFallback();
        }

        $rates = $data['conversion_rates'] ?? [];
        $usdToBdt = (float)($rates['BDT'] ?? 110);
        $db = Database::getInstance();

        $targets = ['AED','SAR','OMR','KWD','BHD','QAR','INR','PKR','NPR','LKR','USD','EUR','GBP'];
        foreach ($targets as $cur) {
            if (!isset($rates[$cur])) continue;
            // 1 foreign = ? BDT
            $usdToForeign = (float)$rates[$cur];
            $rate = $usdToForeign > 0 ? round($usdToBdt / $usdToForeign, 4) : 0;
            if ($rate <= 0) continue;
            $db->execute(
                "INSERT INTO currency_rates (from_currency, to_currency, rate) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE rate=?, updated_at=NOW()",
                [$cur, 'BDT', $rate, $rate]
            );
        }
        Logger::info('CurrencyService: rates updated from API');
        return true;
    }

    private function useFallback(): bool {
        // Approximate rates — update periodically
        $rates = [
            'AED'=>30.5, 'SAR'=>29.3, 'OMR'=>286.0, 'KWD'=>358.0,
            'BHD'=>294.0, 'QAR'=>30.2, 'INR'=>1.32, 'PKR'=>0.40,
            'NPR'=>0.82, 'LKR'=>0.34, 'USD'=>110.0, 'EUR'=>119.0, 'GBP'=>140.0,
        ];
        $db = Database::getInstance();
        foreach ($rates as $cur => $rate) {
            $db->execute(
                "INSERT INTO currency_rates (from_currency, to_currency, rate) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE rate=?, updated_at=NOW()",
                [$cur, 'BDT', $rate, $rate]
            );
        }
        Logger::info('CurrencyService: fallback rates used');
        return true;
    }

    public function broadcastRates(): void {
        if (!Config::isEnabled('currency_alert_enabled')) return;

        $db    = Database::getInstance();
        $rates = $db->fetchAll("SELECT * FROM currency_rates WHERE to_currency='BDT' ORDER BY from_currency");
        if (empty($rates)) return;

        $msg = "💱 *আজকের কারেন্সি রেট*\n📅 " . date('d/m/Y H:i') . "\n\n";
        foreach ($rates as $r) {
            $msg .= "1 {$r['from_currency']} = " . number_format((float)$r['rate'],2) . " BDT\n";
        }

        require_once __DIR__ . '/../bot/Sender.php';
        $sender    = new Sender();
        $customers = $db->fetchAll("SELECT * FROM customers WHERE onboarding_done=1");
        foreach ($customers as $c) {
            try { $sender->send($c['platform'], $c['platform_id'], $msg); usleep(300000); }
            catch (Exception $e) { Logger::error('Currency broadcast: '.$e->getMessage()); }
        }
    }
}
