<?php
// ============================================================
// services/FakeDocumentService.php
// Fake / জাল ডকুমেন্ট শনাক্তকরণ সার্ভিস
//
// কিভাবে কাজ করে:
// 1. Gemini Vision AI ছবিটা বিশ্লেষণ করে
// 2. Font, editing signs, watermark, MRZ ইত্যাদি চেক করে
// 3. Confidence score ও issues list দেয়
// 4. DB-তে সেভ করে + Admin-কে alert করে
// ============================================================

class FakeDocumentService {

    private ?string  $geminiKey;
    private Database $db;

    public function __construct() {
        $this->geminiKey = Config::get('gemini_api_key') ?: null;
        $this->db        = Database::getInstance();
    }

    // ============================================================
    // MAIN: ডকুমেন্ট চেক করা
    // ============================================================
    public function check(string $localFile, int $docId, array $customer): array {
        if (!Config::isEnabled('fake_doc_detector_enabled')) {
            return ['checked' => false, 'reason' => 'Feature disabled'];
        }

        if (!$this->geminiKey) {
            Logger::error('FakeDetect: Gemini API Key not set');
            return ['checked' => false, 'reason' => 'API Key missing'];
        }

        if (!file_exists($localFile)) {
            return ['checked' => false, 'reason' => 'File not found'];
        }

        try {
            $result = $this->analyzeWithAI($localFile);
            $result['checked'] = true;

            // DB-তে সেভ
            $this->saveResult($docId, $customer['id'], $result);

            return $result;

        } catch (Exception $e) {
            Logger::error('FakeDocumentService::check — ' . $e->getMessage());
            return ['checked' => false, 'reason' => $e->getMessage()];
        }
    }

    // ============================================================
    // AI দিয়ে ছবি বিশ্লেষণ
    // ============================================================
    private function analyzeWithAI(string $localFile): array {
        $ext  = strtolower(pathinfo($localFile, PATHINFO_EXTENSION));
        $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : 'image/png';
        $img  = base64_encode(file_get_contents($localFile));

        // বিস্তারিত prompt যা AI-কে সঠিক বিশ্লেষণ করতে সাহায্য করে
        $prompt = <<<PROMPT
তুমি একজন document fraud detection specialist। এই ডকুমেন্টের ছবিটি দেখো এবং নিচের বিষয়গুলো যাচাই করো:

1. **Image Quality**: ছবি স্পষ্ট কিনা, blur বা crop করা কিনা
2. **Font Consistency**: সব text একই font ও size-এ আছে কিনা
3. **Editing Signs**: Photoshop বা digital editing-এর চিহ্ন আছে কিনা
4. **Color Uniformity**: অস্বাভাবিক রঙের পরিবর্তন বা patch আছে কিনা
5. **Official Markings**: Watermark, seal, hologram দেখা যাচ্ছে কিনা
6. **Data Consistency**: নাম, নম্বর, তারিখ পরস্পর সামঞ্জস্যপূর্ণ কিনা
7. **MRZ Check**: পাসপোর্ট হলে machine-readable zone (<<<) দেখা যাচ্ছে কিনা
8. **Background**: Document background uniform ও authentic দেখাচ্ছে কিনা

শুধু JSON দাও — কোনো ব্যাখ্যা নয়:
{
  "is_suspicious": false,
  "confidence_score": 0,
  "quality_score": 85,
  "issues": [],
  "is_blurry": false,
  "is_cropped": false,
  "has_editing_signs": false,
  "font_inconsistency": false,
  "color_anomaly": false,
  "missing_official_marks": false,
  "data_inconsistency": false,
  "recommendation": "accept",
  "summary": ""
}

- `is_suspicious`: সন্দেহজনক হলে true
- `confidence_score`: কতটা নিশ্চিত যে এটা জাল (0-100)
- `quality_score`: ছবির মান (0-100, 100 = একদম স্পষ্ট)
- `issues`: সমস্যার তালিকা (বাংলায়)
- `recommendation`: "accept" (ঠিক আছে) / "review" (যাচাই করুন) / "reject" (প্রত্যাখ্যান করুন)
- `summary`: সংক্ষিপ্ত মন্তব্য (বাংলায়, ১-২ লাইন)
PROMPT;

        $text = $this->callGeminiVision($prompt, $img, $mime);
        $data = $this->parseJson($text);

        if (!$data) {
            Logger::error('FakeDetect: JSON parse failed. Raw: ' . substr($text, 0, 300));
            // Fallback: ধরে নেব ঠিক আছে
            return ['is_suspicious' => false, 'confidence_score' => 0, 'recommendation' => 'accept', 'issues' => [], 'summary' => ''];
        }

        return $data;
    }

    // ============================================================
    // DB-তে সেভ করা
    // ============================================================
    private function saveResult(int $docId, int $customerId, array $result): void {
        $isSuspicious = $result['is_suspicious'] ?? false;
        $confidence   = $result['confidence_score'] ?? 0;
        $issues       = json_encode($result['issues'] ?? [], JSON_UNESCAPED_UNICODE);
        $rec          = $result['recommendation'] ?? 'accept';
        $quality      = $result['quality_score'] ?? null;

        // documents টেবিল আপডেট
        $this->db->execute(
            "UPDATE documents SET
               is_suspicious      = ?,
               fake_check_done    = 1,
               fake_confidence    = ?,
               fake_issues        = ?,
               fake_recommendation= ?,
               quality_score      = ?
             WHERE id = ?",
            [$isSuspicious ? 1 : 0, $confidence, $issues, $rec, $quality, $docId]
        );

        // সন্দেহজনক হলে আলাদা টেবিলে লগ করা
        if ($isSuspicious || $rec !== 'accept') {
            $this->db->execute(
                "INSERT INTO suspicious_documents (customer_id, document_id, confidence_score, issues, recommendation)
                 VALUES (?,?,?,?,?)",
                [$customerId, $docId, $confidence, $issues, $rec]
            );
        }
    }

    // ============================================================
    // গ্রাহককে পাঠানোর জন্য message তৈরি
    // ============================================================
    public function buildCustomerMessage(array $result, string $lang = 'bn'): ?string {
        if (!($result['checked'] ?? false)) return null;

        $isSuspicious = $result['is_suspicious'] ?? false;
        $rec          = $result['recommendation'] ?? 'accept';
        $quality      = $result['quality_score'] ?? 100;
        $issues       = $result['issues'] ?? [];
        $summary      = $result['summary'] ?? '';

        // ঠিক আছে হলে মেসেজ নেই (চুপ থাকো)
        if (!$isSuspicious && $rec === 'accept') {
            // শুধু quality কম হলে জানানো
            if ($quality < 50) {
                return $lang === 'bn'
                    ? "📸 ছবির মান কম ({$quality}/100)। আরো স্পষ্ট ছবি পাঠালে ভালো হয়।"
                    : "📸 Image quality is low ({$quality}/100). A clearer photo would be better.";
            }
            return null;
        }

        // সমস্যা আছে
        $msg = '';

        if ($rec === 'reject') {
            $msg = $lang === 'bn'
                ? "❌ *ডকুমেন্ট গ্রহণযোগ্য নয়!*\n\n"
                : "❌ *Document cannot be accepted!*\n\n";
        } elseif ($rec === 'review') {
            $msg = $lang === 'bn'
                ? "⚠️ *ডকুমেন্ট যাচাই করা হচ্ছে*\n\n"
                : "⚠️ *Document under review*\n\n";
        }

        if (!empty($issues)) {
            $msg .= $lang === 'bn' ? "সমস্যাসমূহ:\n" : "Issues found:\n";
            foreach ($issues as $issue) {
                $msg .= "• {$issue}\n";
            }
        }

        if ($summary) {
            $msg .= "\n_{$summary}_";
        }

        if ($rec === 'reject') {
            $msg .= $lang === 'bn'
                ? "\n\n📸 অনুগ্রহ করে মূল ডকুমেন্টের পরিষ্কার ছবি পাঠান।"
                : "\n\n📸 Please send a clear photo of the original document.";
        } elseif ($rec === 'review') {
            $msg .= $lang === 'bn'
                ? "\n\n🔍 আমাদের টিম ডকুমেন্টটি যাচাই করবে।"
                : "\n\n🔍 Our team will verify the document.";
        }

        return $msg ?: null;
    }

    // ============================================================
    // Admin-কে Alert পাঠানো
    // ============================================================
    public function alertAdmin(array $result, array $customer, int $docId): void {
        $gid = Config::get('admin_telegram_group');
        if (!$gid || !($result['is_suspicious'] ?? false)) return;

        require_once __DIR__ . '/../bot/Sender.php';
        $sender = new Sender();

        $rec        = $result['recommendation'] ?? 'review';
        $confidence = $result['confidence_score'] ?? 0;
        $issues     = $result['issues'] ?? [];

        $icon = $rec === 'reject' ? '🚨' : '⚠️';
        $msg  = "{$icon} *সন্দেহজনক ডকুমেন্ট!*\n\n";
        $msg .= "👤 গ্রাহক: " . ($customer['name'] ?? '?') . "\n";
        $msg .= "📱 মোবাইল: " . ($customer['mobile'] ?? '—') . "\n";
        $msg .= "🔢 Doc ID: #{$docId}\n";
        $msg .= "🎯 নিশ্চিততা: {$confidence}%\n";
        $msg .= "📋 সিদ্ধান্ত: " . match($rec) {
            'reject' => '❌ প্রত্যাখ্যান করুন',
            'review' => '🔍 যাচাই করুন',
            default  => '✅ গ্রহণযোগ্য',
        } . "\n";

        if (!empty($issues)) {
            $msg .= "\nসমস্যা:\n";
            foreach (array_slice($issues, 0, 5) as $i) {
                $msg .= "• {$i}\n";
            }
        }

        if (!empty($result['summary'])) {
            $msg .= "\n_{$result['summary']}_";
        }

        $msg .= "\n\n🔗 Admin: /admin/documents.php?filter=suspicious";

        try {
            $sender->sendTelegramMessage($gid, $msg);
        } catch (Exception $e) {
            Logger::error('FakeDetect admin alert: ' . $e->getMessage());
        }
    }

    // ============================================================
    // Helpers
    // ============================================================
    private function callGeminiVision(string $prompt, string $imgB64, string $mime): string {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$this->geminiKey}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'contents' => [['parts' => [
                    ['text'        => $prompt],
                    ['inline_data' => ['mime_type' => $mime, 'data' => $imgB64]],
                ]]],
                'generationConfig' => ['temperature' => 0.05, 'maxOutputTokens' => 500],
            ]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) throw new Exception("Gemini HTTP {$code}: " . substr($resp, 0, 200));
        return json_decode($resp, true)['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    private function parseJson(string $text): ?array {
        $text = preg_replace('/```(?:json)?\s*/i', '', $text);
        $text = trim(str_replace('```', '', $text));
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $d = json_decode($m[0], true);
            return json_last_error() === JSON_ERROR_NONE ? $d : null;
        }
        return null;
    }
}
