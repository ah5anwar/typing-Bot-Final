<?php
// ============================================================
// services/InvoiceService.php
// Invoice PDF তৈরি করা (TCPDF / HTML-based)
// ============================================================

class InvoiceService {

    // ============================================================
    // গ্রাহকের জন্য Invoice তৈরি করা
    // ============================================================
    public function generate(array $customer, array $payment, array $application = []): ?string {
        if (!Config::isEnabled('invoice_enabled')) return null;

        // Custom HTML Template আছে কিনা দেখা
        $customTemplate = Config::get('invoice_template');
        if ($customTemplate) {
            $html = $this->renderCustomTemplate($customTemplate, $customer, $payment, $application);
        } else {
            $html = $this->renderDefaultTemplate($customer, $payment, $application);
        }

        return $this->htmlToPdf($html, $customer, $payment);
    }

    // ============================================================
    // Default Invoice Template
    // ============================================================
    private function renderDefaultTemplate(array $customer, array $payment, array $application): string {
        $company   = Config::get('company_name', 'ভিসা সার্ভিস');
        $phone     = Config::get('company_phone', '');
        $address   = Config::get('company_address', '');
        $invoiceNo = 'INV-' . date('Ymd') . '-' . str_pad($payment['id'], 4, '0', STR_PAD_LEFT);
        $date      = date('d/m/Y');

        return '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 13px; color: #333; }
  .header { background: #1a3c6e; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
  .header h1 { font-size: 22px; }
  .header p { font-size: 11px; opacity: 0.85; margin-top: 4px; }
  .invoice-meta { background: #f0f4f8; padding: 15px 20px; display: flex; justify-content: space-between; }
  .section { padding: 20px; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
  .info-box h3 { font-size: 11px; text-transform: uppercase; color: #888; margin-bottom: 6px; }
  .info-box p { font-size: 13px; color: #333; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  th { background: #1a3c6e; color: white; padding: 10px 12px; text-align: left; font-size: 12px; }
  td { padding: 10px 12px; border-bottom: 1px solid #e9ecef; font-size: 13px; }
  tr:nth-child(even) td { background: #f8f9fa; }
  .total-row td { font-weight: bold; background: #e8f4f8 !important; font-size: 14px; }
  .balance-row td { color: #dc3545; font-weight: bold; background: #fff5f5 !important; }
  .paid-row td { color: #28a745; font-weight: bold; }
  .footer { background: #f8f9fa; padding: 15px 20px; text-align: center; color: #888; font-size: 11px; border-top: 2px solid #1a3c6e; margin-top: 20px; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 50px; font-size: 11px; font-weight: bold; }
  .badge-paid { background: #d4edda; color: #155724; }
  .badge-due { background: #f8d7da; color: #721c24; }
</style>
</head>
<body>

<div class="header">
  <div>
    <h1>' . htmlspecialchars($company) . '</h1>
    <p>' . htmlspecialchars($address) . '</p>
    <p>' . htmlspecialchars($phone) . '</p>
  </div>
  <div style="text-align:right">
    <div style="font-size:20px;font-weight:bold">INVOICE</div>
    <div style="font-size:13px;opacity:0.85">' . $invoiceNo . '</div>
    <div style="font-size:12px;opacity:0.7">' . $date . '</div>
  </div>
</div>

<div class="invoice-meta">
  <div>
    <strong>Invoice No:</strong> ' . $invoiceNo . '<br>
    <strong>তারিখ:</strong> ' . $date . '
  </div>
  <div style="text-align:right">
    <span class="badge ' . ($payment['balance'] <= 0 ? 'badge-paid' : 'badge-due') . '">
      ' . ($payment['balance'] <= 0 ? '✅ পরিশোধিত' : '⚠️ বকেয়া আছে') . '
    </span>
  </div>
</div>

<div class="section">
  <div class="info-grid">
    <div class="info-box">
      <h3>গ্রাহকের তথ্য</h3>
      <p><strong>' . htmlspecialchars($customer['name'] ?? '—') . '</strong></p>
      <p>' . htmlspecialchars($customer['mobile'] ?? '') . '</p>
      <p>' . htmlspecialchars($customer['address'] ?? '') . '</p>
    </div>
    <div class="info-box">
      <h3>আবেদনের তথ্য</h3>
      <p>ট্র্যাকিং: <strong>' . htmlspecialchars($application['tracking_id'] ?? '—') . '</strong></p>
      <p>সেবা: ' . htmlspecialchars($application['service_name'] ?? '—') . '</p>
      <p>অবস্থা: ' . htmlspecialchars($application['status_name'] ?? '—') . '</p>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>বিবরণ</th>
        <th style="text-align:right">পরিমাণ</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>' . htmlspecialchars($application['service_name'] ?? 'সেবা চার্জ') . '<br>
            <small style="color:#888">' . htmlspecialchars($payment['description'] ?? '') . '</small>
        </td>
        <td style="text-align:right">' . number_format($payment['total_amount'], 2) . ' ' . htmlspecialchars($payment['currency'] ?? 'BDT') . '</td>
      </tr>
      <tr class="paid-row">
        <td>জমা দেওয়া হয়েছে</td>
        <td style="text-align:right">- ' . number_format($payment['paid_amount'], 2) . ' ' . htmlspecialchars($payment['currency'] ?? 'BDT') . '</td>
      </tr>
      <tr class="total-row">
        <td>মোট বকেয়া</td>
        <td style="text-align:right">' . number_format($payment['balance'], 2) . ' ' . htmlspecialchars($payment['currency'] ?? 'BDT') . '</td>
      </tr>
    </tbody>
  </table>
</div>

<div class="footer">
  <p>ধন্যবাদ আমাদের সেবা গ্রহণ করার জন্য।</p>
  <p style="margin-top:5px">' . htmlspecialchars($company) . ' | ' . htmlspecialchars($phone) . '</p>
  <p style="margin-top:3px;font-size:10px">এটি একটি কম্পিউটার তৈরি রশিদ, কোনো স্বাক্ষরের প্রয়োজন নেই।</p>
</div>

</body>
</html>';
    }

    // ============================================================
    // Custom HTML Template Render
    // ============================================================
    private function renderCustomTemplate(string $template, array $customer, array $payment, array $application): string {
        $vars = [
            '{{company_name}}'   => Config::get('company_name'),
            '{{company_phone}}'  => Config::get('company_phone'),
            '{{company_address}}'=> Config::get('company_address'),
            '{{customer_name}}'  => $customer['name'] ?? '',
            '{{customer_mobile}}'=> $customer['mobile'] ?? '',
            '{{customer_address}}'=> $customer['address'] ?? '',
            '{{tracking_id}}'    => $application['tracking_id'] ?? '',
            '{{service_name}}'   => $application['service_name'] ?? '',
            '{{status_name}}'    => $application['status_name'] ?? '',
            '{{total_amount}}'   => number_format($payment['total_amount'], 2),
            '{{paid_amount}}'    => number_format($payment['paid_amount'], 2),
            '{{balance}}'        => number_format($payment['balance'], 2),
            '{{currency}}'       => $payment['currency'] ?? 'BDT',
            '{{date}}'           => date('d/m/Y'),
            '{{invoice_no}}'     => 'INV-' . date('Ymd') . '-' . str_pad($payment['id'], 4, '0', STR_PAD_LEFT),
        ];
        return str_replace(array_keys($vars), array_values($vars), $template);
    }

    // ============================================================
    // HTML → PDF রূপান্তর
    // ============================================================
    private function htmlToPdf(string $html, array $customer, array $payment): ?string {
        $dir      = BASE_PATH . '/public/invoices';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $filename = 'invoice_' . $customer['id'] . '_' . $payment['id'] . '_' . time() . '.pdf';
        $filepath = $dir . '/' . $filename;

        // TCPDF ইন্সটল আছে কিনা দেখা
        if (file_exists(BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php')) {
            require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
            $pdf->SetCreator('Bot');
            $pdf->SetTitle('Invoice');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->AddPage();
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->Output($filepath, 'F');
        } else {
            // Fallback: HTML ফাইল সেভ করা (PDF নয়, কিন্তু কাজ করবে)
            $htmlFile = str_replace('.pdf', '.html', $filepath);
            file_put_contents($htmlFile, $html);
            $filepath = $htmlFile;
            $filename = str_replace('.pdf', '.html', $filename);
        }

        if (file_exists($filepath)) {
            $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') . '/public/invoices/' . $filename;
            return $url;
        }

        return null;
    }

    // ============================================================
    // গ্রাহককে Invoice পাঠানো
    // ============================================================
    public function sendToCustomer(array $customer, array $payment, array $application = []): void {
        $url = $this->generate($customer, $payment, $application);
        if (!$url) return;

        require_once __DIR__ . '/../bot/Sender.php';
        $sender = new Sender();

        $msg  = $customer['language'] === 'bn'
            ? "🧾 আপনার ইনভয়েস তৈরি হয়েছে!\n\n"
            : "🧾 Your invoice is ready!\n\n";
        $msg .= "💰 মোট: " . number_format($payment['total_amount'], 2) . " " . $payment['currency'] . "\n";
        $msg .= "✅ জমা: " . number_format($payment['paid_amount'], 2) . " " . $payment['currency'] . "\n";
        $msg .= "⚠️ বকেয়া: " . number_format($payment['balance'], 2) . " " . $payment['currency'] . "\n\n";
        $msg .= "📄 ডাউনলোড করুন: " . $url;

        $sender->send($customer['platform'], $customer['platform_id'], $msg);
    }
}
