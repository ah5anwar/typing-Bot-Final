<?php
// ============================================================
// services/QRCodeService.php (সম্পূর্ণ আপগ্রেড)
// QR Code + Digital Member ID Card
// ============================================================

require_once __DIR__ . '/../vendor/phpqrcode/qrlib.php';

class QRCodeService {

    private string $qrDir;
    private string $cardDir;
    private string $baseUrl;

    public function __construct() {
        $this->qrDir   = BASE_PATH . '/public/qr';
        $this->cardDir = BASE_PATH . '/public/cards';
        $this->baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com');

        if (!is_dir($this->qrDir))   mkdir($this->qrDir,   0755, true);
        if (!is_dir($this->cardDir)) mkdir($this->cardDir, 0755, true);
    }

    // ============================================================
    // গ্রাহকের QR Code তৈরি করা
    // ============================================================
    public function generateForCustomer(int $customerId): ?string {
        $db       = Database::getInstance();
        $customer = $db->fetchOne("SELECT * FROM customers WHERE id = ?", [$customerId]);
        if (!$customer) return null;

        // QR Code-এ এই URL থাকবে
        $token    = $this->generateToken($customerId);
        $trackUrl = $this->baseUrl . '/track/?cid=' . $customerId . '&token=' . $token;

        $qrPath = $this->qrDir . '/qr_' . $customerId . '.png';
        QRcode::png($trackUrl, $qrPath, QR_ECLEVEL_M, 6, 2);

        if (file_exists($qrPath)) {
            $qrUrl = $this->baseUrl . '/public/qr/qr_' . $customerId . '.png';
            $db->execute("UPDATE customers SET qr_code = ? WHERE id = ?", [$qrUrl, $customerId]);

            // Member ID Card তৈরি করা
            $this->generateMemberCard($customer, $qrPath, $token);

            return $qrUrl;
        }

        return null;
    }

    // ============================================================
    // Digital Member ID Card HTML তৈরি করা
    // ============================================================
    public function generateMemberCard(array $customer, string $qrPath, string $token): ?string {
        $qrBase64  = file_exists($qrPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($qrPath)) : QRcode::base64png($this->baseUrl . '/track/?cid=' . $customer['id']);
        $memberId  = 'MEM-' . str_pad($customer['id'], 6, '0', STR_PAD_LEFT);
        $joinDate  = date('d/m/Y', strtotime($customer['created_at']));
        $company   = Config::get('company_name', 'ভিসা সার্ভিস');
        $platform  = strtoupper($customer['platform']);

        $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: Arial, sans-serif; background: #f0f4f8; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
  .card { width: 380px; background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.15); }
  .card-header { background: linear-gradient(135deg, #1a3c6e, #2e86c1); padding: 24px; color: white; position: relative; }
  .card-header::after { content: ""; position: absolute; bottom: -20px; left: 0; right: 0; height: 40px; background: white; border-radius: 50% 50% 0 0; }
  .company { font-size: 13px; opacity: 0.8; margin-bottom: 4px; }
  .card-title { font-size: 18px; font-weight: bold; }
  .member-badge { position: absolute; top: 20px; right: 20px; background: rgba(255,255,255,0.2); padding: 6px 12px; border-radius: 20px; font-size: 11px; }
  .card-body { padding: 30px 24px 24px; }
  .member-info { display: flex; align-items: center; gap: 16px; margin-bottom: 20px; }
  .avatar { width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #1a3c6e, #2e86c1); display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; font-weight: bold; flex-shrink: 0; }
  .member-name { font-size: 18px; font-weight: bold; color: #1a3c6e; }
  .member-id { font-size: 12px; color: #888; margin-top: 2px; font-family: monospace; }
  .member-platform { font-size: 11px; background: #e8f4f8; color: #2e86c1; padding: 2px 8px; border-radius: 10px; display: inline-block; margin-top: 4px; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
  .info-item { background: #f8f9fa; border-radius: 10px; padding: 10px 12px; }
  .info-label { font-size: 10px; color: #999; text-transform: uppercase; margin-bottom: 2px; }
  .info-value { font-size: 13px; font-weight: 600; color: #333; }
  .qr-section { display: flex; justify-content: center; padding: 16px; background: #f8f9fa; border-radius: 14px; margin-bottom: 16px; }
  .qr-section img { width: 120px; height: 120px; }
  .scan-text { text-align: center; font-size: 11px; color: #999; margin-top: 8px; }
  .card-footer { background: #1a3c6e; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; }
  .footer-text { color: rgba(255,255,255,0.7); font-size: 10px; }
  .valid-badge { background: #27ae60; color: white; padding: 4px 10px; border-radius: 10px; font-size: 10px; font-weight: bold; }
</style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <div class="company">' . htmlspecialchars($company) . '</div>
    <div class="card-title">Digital Member Card</div>
    <div class="member-badge">MEMBER</div>
  </div>
  <div class="card-body">
    <div class="member-info">
      <div class="avatar">' . mb_substr($customer['name'] ?? 'G', 0, 1) . '</div>
      <div>
        <div class="member-name">' . htmlspecialchars($customer['name'] ?? 'গ্রাহক') . '</div>
        <div class="member-id">' . $memberId . '</div>
        <span class="member-platform">' . $platform . '</span>
      </div>
    </div>
    <div class="info-grid">
      <div class="info-item">
        <div class="info-label">মোবাইল</div>
        <div class="info-value">' . htmlspecialchars($customer['mobile'] ?? '—') . '</div>
      </div>
      <div class="info-item">
        <div class="info-label">যোগদান</div>
        <div class="info-value">' . $joinDate . '</div>
      </div>
      <div class="info-item">
        <div class="info-label">Member ID</div>
        <div class="info-value">' . $memberId . '</div>
      </div>
      <div class="info-item">
        <div class="info-label">প্ল্যাটফর্ম</div>
        <div class="info-value">' . $platform . '</div>
      </div>
    </div>
    <div class="qr-section">
      <div>
        <img src="' . $qrBase64 . '" alt="QR Code">
        <div class="scan-text">স্ক্যান করে সব তথ্য দেখুন</div>
      </div>
    </div>
  </div>
  <div class="card-footer">
    <div class="footer-text">' . htmlspecialchars($company) . ' | ' . date('Y') . '</div>
    <div class="valid-badge">✓ VALID</div>
  </div>
</div>
</body>
</html>';

        $cardPath = $this->cardDir . '/card_' . $customer['id'] . '.html';
        file_put_contents($cardPath, $html);

        $cardUrl = $this->baseUrl . '/public/cards/card_' . $customer['id'] . '.html';
        Database::getInstance()->execute(
            "UPDATE customers SET qr_code = ? WHERE id = ?",
            [$this->baseUrl . '/public/qr/qr_' . $customer['id'] . '.png', $customer['id']]
        );

        return $cardUrl;
    }

    // ============================================================
    // Agent Digital Visiting Card
    // ============================================================
    public function generateAgentCard(array $agent): ?string {
        $company   = Config::get('company_name', 'ভিসা সার্ভিস');
        $waLink    = 'https://wa.me/' . preg_replace('/[^0-9]/', '', $agent['whatsapp_number'] ?? '');
        $tgLink    = 'https://t.me/' . ltrim($agent['telegram_id'] ?? '', '@');
        $qrData    = $waLink ?: $tgLink ?: Config::get('company_phone', '');
        $qrBase64  = QRcode::base64png($qrData, 180);

        $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: Arial, sans-serif; background: #f0f4f8; display:flex; justify-content:center; align-items:center; min-height:100vh; }
  .card { width:360px; background:white; border-radius:20px; overflow:hidden; box-shadow:0 10px 40px rgba(0,0,0,0.15); }
  .top { background:linear-gradient(135deg,#1a3c6e,#2e86c1); padding:30px 24px; text-align:center; color:white; }
  .avatar { width:80px; height:80px; border-radius:50%; background:rgba(255,255,255,0.2); border:3px solid rgba(255,255,255,0.5); display:flex; align-items:center; justify-content:center; font-size:32px; font-weight:bold; margin:0 auto 12px; }
  .name { font-size:20px; font-weight:bold; }
  .role { font-size:13px; opacity:0.8; margin-top:4px; }
  .body { padding:24px; }
  .contact-item { display:flex; align-items:center; gap:12px; padding:10px 14px; background:#f8f9fa; border-radius:10px; margin-bottom:10px; font-size:14px; }
  .contact-icon { font-size:20px; }
  .qr-wrap { text-align:center; padding:16px; background:#f8f9fa; border-radius:14px; }
  .qr-wrap img { width:140px; height:140px; }
  .qr-label { font-size:11px; color:#999; margin-top:8px; }
  .footer { background:#1a3c6e; padding:10px 24px; text-align:center; color:rgba(255,255,255,0.7); font-size:11px; }
</style>
</head>
<body>
<div class="card">
  <div class="top">
    <div class="avatar">' . mb_substr($agent['name'], 0, 1) . '</div>
    <div class="name">' . htmlspecialchars($agent['name']) . '</div>
    <div class="role">Visa Consultant | ' . htmlspecialchars($company) . '</div>
  </div>
  <div class="body">
    ' . ($agent['mobile'] ? '<div class="contact-item"><span class="contact-icon">📞</span>' . htmlspecialchars($agent['mobile']) . '</div>' : '') . '
    ' . ($agent['whatsapp_number'] ? '<div class="contact-item"><span class="contact-icon">💚</span>WhatsApp: ' . htmlspecialchars($agent['whatsapp_number']) . '</div>' : '') . '
    ' . ($agent['telegram_id'] ? '<div class="contact-item"><span class="contact-icon">🤖</span>Telegram: ' . htmlspecialchars($agent['telegram_id']) . '</div>' : '') . '
    <div class="qr-wrap">
      <img src="' . $qrBase64 . '" alt="QR">
      <div class="qr-label">স্ক্যান করে সরাসরি যোগাযোগ করুন</div>
    </div>
  </div>
  <div class="footer">' . htmlspecialchars($company) . ' | ' . date('Y') . '</div>
</div>
</body>
</html>';

        $path = $this->cardDir . '/agent_' . $agent['id'] . '.html';
        file_put_contents($path, $html);

        $url = $this->baseUrl . '/public/cards/agent_' . $agent['id'] . '.html';
        Database::getInstance()->execute(
            "UPDATE agents SET qr_card_url = ? WHERE id = ?",
            [$url, $agent['id']]
        );

        return $url;
    }

    // ============================================================
    // QR Token তৈরি
    // ============================================================
    private function generateToken(int $customerId): string {
        return substr(md5($customerId . 'bot_secret_2025_' . Config::get('admin_username')), 0, 16);
    }

    // ============================================================
    // Token Verify করা (tracking page-এ)
    // ============================================================
    public function verifyToken(int $customerId, string $token): bool {
        return $token === $this->generateToken($customerId);
    }

    // ============================================================
    // গ্রাহককে QR Card পাঠানো (Bot থেকে)
    // ============================================================
    public function sendCardToCustomer(array $customer): void {
        $qrUrl  = $customer['qr_code'] ?? null;
        $cardUrl = $this->baseUrl . '/public/cards/card_' . $customer['id'] . '.html';

        if (!$qrUrl) {
            $qrUrl = $this->generateForCustomer($customer['id']);
        }

        require_once __DIR__ . '/../bot/Sender.php';
        $sender = new Sender();
        $lang   = $customer['language'] ?? 'bn';

        $msg  = $lang === 'bn'
            ? "🪪 *আপনার Digital Member Card*\n\n"
            : "🪪 *Your Digital Member Card*\n\n";
        $msg .= "Member ID: *MEM-" . str_pad($customer['id'], 6, '0', STR_PAD_LEFT) . "*\n\n";
        $msg .= "📱 QR Code: " . $qrUrl . "\n";
        $msg .= "🎫 Member Card: " . $cardUrl . "\n\n";
        $msg .= $lang === 'bn'
            ? "QR Code স্ক্যান করলে আপনার সব তথ্য ও আবেদনের অবস্থা দেখা যাবে।"
            : "Scan QR code to view all your information and application status.";

        $sender->send($customer['platform'], $customer['platform_id'], $msg);
    }
}
