<?php
// ============================================================
// services/PDFInvoiceService.php — FIXED VERSION
// ============================================================

class PDFInvoiceService {

    private string $dir;
    private string $baseUrl;

    public function __construct() {
        $this->dir     = BASE_PATH . '/public/invoices';
        $this->baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com');
        if (!is_dir($this->dir)) @mkdir($this->dir, 0755, true);
    }

    public function generate(array $customer, array $payment, array $application = [], bool $send = false): ?string {
        if (!Config::isEnabled('invoice_enabled')) return null;

        $no   = 'INV-' . date('Ymd') . '-' . str_pad($payment['id'] ?? rand(1000,9999), 5, '0', STR_PAD_LEFT);
        $tmpl = Config::get('invoice_template', '');
        $html = $tmpl ? $this->renderCustom($tmpl, $customer, $payment, $application, $no)
                      : $this->renderDefault($customer, $payment, $application, $no);

        $filename = 'invoice_c' . ($customer['id']??0) . '_p' . ($payment['id']??0) . '_' . time() . '.html';
        $path     = $this->dir . '/' . $filename;

        if (file_put_contents($path, $html) === false) {
            Logger::error('Invoice: could not write file ' . $path);
            return null;
        }

        $url = $this->baseUrl . '/public/invoices/' . $filename;

        // Log it
        try {
            Database::getInstance()->execute(
                "INSERT INTO invoice_logs (customer_id, payment_id, invoice_no, invoice_url) VALUES (?,?,?,?)",
                [$customer['id']??0, $payment['id']??0, $no, $url]
            );
        } catch (Exception $e) { /* silent */ }

        if ($send) $this->sendToCustomer($customer, $payment, $application, $url);
        return $url;
    }

    private function renderDefault(array $c, array $p, array $a, string $no): string {
        $co  = htmlspecialchars(Config::get('company_name','ভিসা সার্ভিস'));
        $ph  = htmlspecialchars(Config::get('company_phone',''));
        $ad  = htmlspecialchars(Config::get('company_address',''));
        $dt  = date('d/m/Y H:i');
        $bal = (float)($p['balance'] ?? $p['total_amount'] - $p['paid_amount']);
        $sc  = $bal <= 0 ? '#27ae60' : '#e74c3c';
        $st  = $bal <= 0 ? '✅ PAID' : '⚠️ DUE';
        $cur = htmlspecialchars($p['currency'] ?? 'BDT');

        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;font-size:13px;color:#333;background:#f5f5f5}
.page{max-width:794px;margin:0 auto;background:white;min-height:1000px}
.hdr{background:linear-gradient(135deg,#1a3c6e,#2e86c1);color:white;padding:28px 36px;display:flex;justify-content:space-between;align-items:center}
.hdr h1{font-size:22px}.hdr small{font-size:11px;opacity:.8}
.hdr-right{text-align:right}.hdr-right .inv{font-size:28px;font-weight:300;opacity:.9}
.meta{background:#f8f9fa;padding:10px 36px;display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #1a3c6e}
.badge{padding:5px 14px;border-radius:50px;font-size:11px;font-weight:bold;color:white;background:{$sc}}
.body{padding:28px 36px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px}
.box{background:#f8f9fa;border-left:4px solid #1a3c6e;border-radius:6px;padding:14px}
.box h4{font-size:10px;text-transform:uppercase;color:#888;margin-bottom:6px}
table{width:100%;border-collapse:collapse;margin-bottom:20px}
th{background:#1a3c6e;color:white;padding:9px 12px;text-align:left;font-size:12px}
td{padding:9px 12px;border-bottom:1px solid #e9ecef;font-size:13px}
tr:nth-child(even) td{background:#f9f9f9}
.totals{float:right;width:280px}
.tr{display:flex;justify-content:space-between;padding:7px 12px;border-radius:4px}
.tr.main{background:#1a3c6e;color:white;font-weight:bold;font-size:15px;margin-top:6px}
.tr.paid{color:#27ae60}
.tr.due{color:{$sc};font-weight:bold}
.foot{clear:both;margin-top:40px;padding:16px 36px;background:#f8f9fa;border-top:2px solid #1a3c6e;text-align:center;font-size:11px;color:#888}
@media print{body{background:white}}
</style></head><body><div class="page">
<div class="hdr">
  <div><h1>{$co}</h1><small>{$ad}</small><br><small>{$ph}</small></div>
  <div class="hdr-right"><div class="inv">INVOICE</div><small>{$no}</small><br><small>{$dt}</small></div>
</div>
<div class="meta">
  <span><strong>{$no}</strong> &nbsp;|&nbsp; {$dt}</span>
  <span class="badge">{$st}</span>
</div>
<div class="body">
<div class="grid">
  <div class="box"><h4>গ্রাহকের তথ্য</h4>
    <strong>
HTML . htmlspecialchars($c['name']??'—') . <<<HTML
</strong><br>
HTML . htmlspecialchars($c['mobile']??'') . '<br>' . htmlspecialchars($c['address']??'') . <<<HTML
  </div>
  <div class="box"><h4>আবেদনের তথ্য</h4>
    ট্র্যাকিং: <strong>
HTML . htmlspecialchars($a['tracking_id']??'—') . <<<HTML
</strong><br>
সেবা: 
HTML . htmlspecialchars($a['service_name']??'—') . '<br>অবস্থা: ' . htmlspecialchars($a['status_name']??'—') . <<<HTML
  </div>
</div>
<table>
<tr><th>বিবরণ</th><th>কারেন্সি</th><th style="text-align:right">পরিমাণ</th></tr>
<tr><td>
HTML . htmlspecialchars($a['service_name']??'সেবা চার্জ') . '<br><small style="color:#888">' . htmlspecialchars($p['description']??'') . <<<HTML
</small></td>
<td>{$cur}</td>
<td style="text-align:right">
HTML . number_format((float)($p['total_amount']??0), 2) . <<<HTML
</td></tr>
</table>
<div class="totals">
  <div class="tr main"><span>মোট</span><span>
HTML . number_format((float)($p['total_amount']??0),2) . " {$cur}" . <<<HTML
</span></div>
  <div class="tr paid"><span>✅ পরিশোধিত</span><span>
HTML . number_format((float)($p['paid_amount']??0),2) . <<<HTML
</span></div>
  <div class="tr due"><span>
HTML . ($bal>0?'⚠️ বকেয়া':'✅ সম্পূর্ণ পরিশোধ') . '</span><span>' . number_format(abs($bal),2) . <<<HTML
</span></div>
</div>
</div>
<div class="foot">{$co} &nbsp;|&nbsp; {$ph} &nbsp;|&nbsp; {$ad}<br>
এটি কম্পিউটার তৈরি রশিদ — কোনো স্বাক্ষরের প্রয়োজন নেই</div>
</div>
<script>if(location.search.includes('print=1'))window.print()</script>
</body></html>
HTML;
    }

    private function renderCustom(string $tmpl, array $c, array $p, array $a, string $no): string {
        $bal = (float)($p['balance'] ?? $p['total_amount'] - $p['paid_amount']);
        $vars = [
            '{{company_name}}'    => Config::get('company_name'),
            '{{company_phone}}'   => Config::get('company_phone'),
            '{{company_address}}' => Config::get('company_address'),
            '{{customer_name}}'   => $c['name'] ?? '',
            '{{customer_mobile}}' => $c['mobile'] ?? '',
            '{{customer_address}}'=> $c['address'] ?? '',
            '{{tracking_id}}'     => $a['tracking_id'] ?? '',
            '{{service_name}}'    => $a['service_name'] ?? '',
            '{{status_name}}'     => $a['status_name'] ?? '',
            '{{total_amount}}'    => number_format((float)($p['total_amount']??0), 2),
            '{{paid_amount}}'     => number_format((float)($p['paid_amount']??0), 2),
            '{{balance}}'         => number_format(abs($bal), 2),
            '{{currency}}'        => $p['currency'] ?? 'BDT',
            '{{date}}'            => date('d/m/Y'),
            '{{invoice_no}}'      => $no,
        ];
        return str_replace(array_keys($vars), array_values($vars), $tmpl);
    }

    public function sendToCustomer(array $c, array $p, array $a, string $url): void {
        require_once __DIR__ . '/../bot/Sender.php';
        $sender = new Sender();
        $lang   = $c['language'] ?? 'bn';
        $bal    = (float)($p['balance'] ?? $p['total_amount'] - $p['paid_amount']);
        $cur    = $p['currency'] ?? 'BDT';

        $msg  = $lang==='bn' ? "🧾 *আপনার Invoice তৈরি হয়েছে!*\n\n" : "🧾 *Your Invoice is Ready!*\n\n";
        $msg .= "📋 " . ($a['service_name']??'সেবা') . "\n";
        $msg .= "🆔 " . ($a['tracking_id']??'—') . "\n";
        $msg .= "─────────────────\n";
        $msg .= "💰 মোট: " . number_format((float)($p['total_amount']??0),2) . " {$cur}\n";
        $msg .= "✅ জমা: " . number_format((float)($p['paid_amount']??0),2) . " {$cur}\n";
        $msg .= ($bal>0?"⚠️":"✅") . " বকেয়া: " . number_format(abs($bal),2) . " {$cur}\n";
        $msg .= "─────────────────\n";
        $msg .= "📄 Invoice: " . $url;

        $sender->send($c['platform'], $c['platform_id'], $msg);
    }
}
