<?php
// ============================================================
// services/OCRService.php — COMPLETE REWRITE
// সব ধরনের ডকুমেন্ট স্ক্যান → DB-তে সেভ → Reminder schedule
// ============================================================

class OCRService {

    private ?string $geminiKey;
    private Database $db;

    public function __construct() {
        $this->geminiKey = Config::get('gemini_api_key') ?: null;
        $this->db        = Database::getInstance();
    }

    // ============================================================
    // MAIN: ছবি থেকে সব তথ্য বের করা
    // ============================================================
    public function scanDocument(string $localFile): array {
        if (!$this->geminiKey) {
            return ['success' => false, 'error' => 'Gemini API Key সেট করা নেই।'];
        }
        if (!file_exists($localFile)) {
            return ['success' => false, 'error' => 'ফাইল পাওয়া যায়নি।'];
        }

        try {
            $ext      = strtolower(pathinfo($localFile, PATHINFO_EXTENSION));
            $mimeType = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : 'image/png';
            $imgB64   = base64_encode(file_get_contents($localFile));

            $prompt = <<<PROMPT
এই ডকুমেন্টের ছবি দেখো। নিচের JSON format-এ সব তথ্য দাও।
শুধু JSON দাও — কোনো ব্যাখ্যা নয়, কোনো markdown নয়।
ডকুমেন্টে যা লেখা আছে হুবহু সেভাবে দাও।

{
  "doc_type": "passport | visa | nid | driving_license | birth_certificate | other",
  "is_clear": true,
  "holder_name": "",
  "father_name": "",
  "mother_name": "",
  "date_of_birth": "YYYY-MM-DD or null",
  "gender": "male | female | null",
  "nationality": "",
  "doc_number": "",
  "visa_type": "",
  "issue_date": "YYYY-MM-DD or null",
  "expiry_date": "YYYY-MM-DD or null",
  "issue_country": "",
  "destination_country": ""
}

তারিখ অবশ্যই YYYY-MM-DD format-এ দাও।
যে তথ্য নেই বা পড়া যাচ্ছে না সেটা null দাও।
PROMPT;

            $respText = $this->callVision($prompt, $imgB64, $mimeType);
            $data     = $this->parseJson($respText);

            if (!$data) {
                Logger::error('OCR: JSON parse failed. Response: ' . substr($respText, 0, 300));
                return ['success' => false, 'error' => 'ডকুমেন্ট পড়তে পারিনি।'];
            }

            // মেয়াদ হিসাব
            if (!empty($data['expiry_date'])) {
                $exp  = strtotime($data['expiry_date']);
                $days = (int) ceil(($exp - time()) / 86400);
                $data['days_until_expiry'] = $days;
                $data['expiry_status']     = $days <= 0 ? 'expired'
                    : ($days <= 30 ? 'critical' : ($days <= 90 ? 'warning' : ($days <= 180 ? 'notice' : 'valid')));
            }

            return array_merge(['success' => true], $data);

        } catch (Exception $e) {
            Logger::error('OCR scanDocument: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Alias for backward compat ─────────────────────────────
    public function extractAll(string $f): array       { return $this->scanDocument($f); }
    public function extractPassport(string $f): array  { return $this->scanDocument($f); }

    // ============================================================
    // DB-তে সেভ + Reminder সেট করা
    // ============================================================
    public function saveToDatabase(int $docId, array $ocr, int $customerId): void {
        if (!$ocr['success']) return;

        // ── 1. documents টেবিল আপডেট ────────────────────────────
        $this->db->execute(
            "UPDATE documents SET
               doc_type             = ?,
               holder_name          = ?,
               father_name          = ?,
               mother_name          = ?,
               date_of_birth        = ?,
               gender               = ?,
               nationality          = ?,
               doc_number           = ?,
               visa_type            = ?,
               issue_date           = ?,
               expiry_date          = ?,
               issue_country        = ?,
               destination_country  = ?,
               extracted_data       = ?,
               ocr_processed        = 1
             WHERE id = ?",
            [
                $ocr['doc_type']            ?? 'other',
                $ocr['holder_name']         ?? null,
                $ocr['father_name']         ?? null,
                $ocr['mother_name']         ?? null,
                $this->validDate($ocr['date_of_birth']  ?? null),
                $ocr['gender']              ?? null,
                $ocr['nationality']         ?? null,
                $ocr['doc_number']          ?? null,
                $ocr['visa_type']           ?? null,
                $this->validDate($ocr['issue_date']     ?? null),
                $this->validDate($ocr['expiry_date']    ?? null),
                $ocr['issue_country']       ?? null,
                $ocr['destination_country'] ?? null,
                json_encode($ocr, JSON_UNESCAPED_UNICODE),
                $docId,
            ]
        );

        // ── 2. Customer নাম আপডেট (যদি খালি থাকে) ───────────────
        if (!empty($ocr['holder_name'])) {
            $c = $this->db->fetchOne("SELECT name FROM customers WHERE id=?", [$customerId]);
            if (empty($c['name'])) {
                $this->db->execute("UPDATE customers SET name=? WHERE id=?", [$ocr['holder_name'], $customerId]);
            }
        }

        // ── 3. Reminder সেট করা ──────────────────────────────────
        if (!empty($ocr['expiry_date'])) {
            $this->scheduleReminders($customerId, $docId, $ocr);
        }
    }

    // ============================================================
    // Reminder Schedule করা
    // ============================================================
    private function scheduleReminders(int $customerId, int $docId, array $ocr): void {
        $expiryTs  = strtotime($ocr['expiry_date']);
        if (!$expiryTs || $expiryTs <= 0) return;

        $docType   = $ocr['doc_type']   ?? 'document';
        $docNumber = $ocr['doc_number'] ?? '';
        $docLabel  = $this->docLabel($docType);

        // 30, 15, 7, 3 দিন আগে reminder
        $schedule = [
            ['type'=>'expiry_30','days'=>30,'msg_bn'=>"⚠️ *মেয়াদ সতর্কতা!*\n\nআপনার {$docLabel} ({$docNumber}) -এর মেয়াদ আর মাত্র *৩০ দিন* বাকি!\nমেয়াদ শেষ: " . date('d/m/Y', $expiryTs) . "\n\n🔔 দ্রুত রিনিউ করুন।"],
            ['type'=>'expiry_15','days'=>15,'msg_bn'=>"⚠️ *জরুরি মেয়াদ সতর্কতা!*\n\nআপনার {$docLabel} ({$docNumber}) -এর মেয়াদ আর মাত্র *১৫ দিন* বাকি!\nমেয়াদ শেষ: " . date('d/m/Y', $expiryTs) . "\n\n🚨 এখনই রিনিউ করুন!"],
            ['type'=>'expiry_7', 'days'=>7, 'msg_bn'=>"🚨 *অতি জরুরি!*\n\nআপনার {$docLabel} ({$docNumber}) -এর মেয়াদ মাত্র *৭ দিন* বাকি!\nমেয়াদ শেষ: " . date('d/m/Y', $expiryTs) . "\n\n❗ আজই যোগাযোগ করুন: " . Config::get('company_phone','')],
            ['type'=>'expiry_3', 'days'=>3, 'msg_bn'=>"🔴 *মেয়াদ প্রায় শেষ!*\n\nআপনার {$docLabel} ({$docNumber}) মাত্র *৩ দিন* পর মেয়াদ শেষ হবে!\n\n⚡ এখনই যোগাযোগ করুন: " . Config::get('company_phone','')],
        ];

        foreach ($schedule as $rem) {
            $triggerDate = date('Y-m-d', $expiryTs - ($rem['days'] * 86400));

            // অতীতের তারিখ হলে skip
            if (strtotime($triggerDate) <= time()) continue;

            // ইতিমধ্যে আছে কিনা চেক
            $exists = $this->db->fetchOne(
                "SELECT id FROM reminders WHERE customer_id=? AND document_id=? AND reminder_type=?",
                [$customerId, $docId, $rem['type']]
            );
            if ($exists) continue;

            $this->db->insert(
                "INSERT INTO reminders (customer_id, document_id, reminder_type, doc_type, doc_number, trigger_date, message, is_sent) VALUES (?,?,?,?,?,?,?,0)",
                [$customerId, $docId, $rem['type'], $docType, $docNumber, $triggerDate, $rem['msg_bn']]
            );
        }

        Logger::info("Reminders scheduled for customer #{$customerId}, doc #{$docId}, expiry: {$ocr['expiry_date']}");
    }

    // ============================================================
    // Fake Document Detection
    // ============================================================
    public function detectFakeDocument(string $localFile): array {
        if (!$this->geminiKey || !file_exists($localFile)) {
            return ['is_suspicious' => false];
        }

        try {
            $ext      = strtolower(pathinfo($localFile, PATHINFO_EXTENSION));
            $mime     = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : 'image/png';
            $imgB64   = base64_encode(file_get_contents($localFile));

            $prompt = 'এই ডকুমেন্টের ছবিটি দেখো। শুধু JSON দাও:
{"is_suspicious":false,"confidence_score":0,"issues":[],"quality_score":100,"is_blurry":false,"recommendation":"accept","notes":""}';

            $text  = $this->callVision($prompt, $imgB64, $mime);
            $data  = $this->parseJson($text);
            return $data ?: ['is_suspicious' => false, 'recommendation' => 'accept'];

        } catch (Exception $e) {
            Logger::error('FakeDetect: ' . $e->getMessage());
            return ['is_suspicious' => false];
        }
    }

    // ============================================================
    // গ্রাহককে ফলাফল পাঠানোর জন্য formatted message
    // ============================================================
    public function formatForCustomer(array $ocr, string $lang = 'bn'): string {
        if (!($ocr['success'] ?? false)) {
            return $lang === 'bn'
                ? "দুঃখিত, ডকুমেন্ট পড়তে পারিনি। ছবিটি স্পষ্ট করে তুলুন।"
                : "Sorry, couldn't read the document. Please take a clearer photo.";
        }

        $docLabel = $this->docLabel($ocr['doc_type'] ?? 'other');
        $msg = "✅ *{$docLabel} স্ক্যান সম্পন্ন!*\n\n";

        $fieldMap = [
            'holder_name'          => '👤 নাম',
            'father_name'          => '👨 পিতার নাম',
            'mother_name'          => '👩 মাতার নাম',
            'date_of_birth'        => '🎂 জন্ম তারিখ',
            'nationality'          => '🌍 জাতীয়তা',
            'doc_number'           => '🔢 ডকুমেন্ট নম্বর',
            'visa_type'            => '📋 ভিসার ধরন',
            'issue_date'           => '📅 ইস্যু তারিখ',
            'expiry_date'          => '⏳ মেয়াদ শেষ',
            'issue_country'        => '🏳️ ইস্যুকারী দেশ',
            'destination_country'  => '✈️ গন্তব্য দেশ',
        ];

        foreach ($fieldMap as $key => $label) {
            $val = $ocr[$key] ?? null;
            if (!$val || $val === 'null') continue;

            // তারিখ format
            if (in_array($key, ['date_of_birth','issue_date','expiry_date']) && strlen($val) === 10) {
                $val = date('d/m/Y', strtotime($val));
            }
            $msg .= "{$label}: *{$val}*\n";
        }

        // মেয়াদ সতর্কতা
        $days = $ocr['days_until_expiry'] ?? null;
        if ($days !== null) {
            $msg .= "\n";
            if ($days <= 0) {
                $msg .= "❌ *মেয়াদ শেষ হয়ে গেছে! দ্রুত রিনিউ করুন।*";
            } elseif ($days <= 7) {
                $msg .= "🔴 *মাত্র {$days} দিন বাকি! এখনই যোগাযোগ করুন।*";
            } elseif ($days <= 30) {
                $msg .= "🟠 *মাত্র {$days} দিন বাকি! শীঘ্রই রিনিউ করুন।*";
            } elseif ($days <= 90) {
                $msg .= "🟡 *{$days} দিন বাকি। রিনিউ করার পরিকল্পনা করুন।*";
            } else {
                $msg .= "✅ মেয়াদ ঠিক আছে ({$days} দিন বাকি)।";
            }
        }

        // Reminder set হয়েছে কিনা জানানো
        if (!empty($ocr['expiry_date']) && ($days ?? 999) > 0) {
            $msg .= "\n\n🔔 _মেয়াদের আগে স্বয়ংক্রিয় reminder পাঠানো হবে।_";
        }

        if (!($ocr['is_clear'] ?? true)) {
            $msg .= "\n\n⚠️ ছবি কিছুটা অস্পষ্ট। আরো স্পষ্ট ছবি পাঠালে ভালো ফলাফল পাবেন।";
        }

        return $msg;
    }

    // ============================================================
    // Admin Panel-এর জন্য গ্রাহকের সব ডকুমেন্ট summary
    // ============================================================
    public static function getCustomerDocumentSummary(int $customerId): array {
        $db   = Database::getInstance();
        $docs = $db->fetchAll(
            "SELECT * FROM documents WHERE customer_id=? AND ocr_processed=1 ORDER BY created_at DESC",
            [$customerId]
        );

        $summary = [];
        foreach ($docs as $d) {
            $exp      = $d['expiry_date'];
            $daysLeft = $exp ? (int) ceil((strtotime($exp) - time()) / 86400) : null;
            $summary[] = [
                'id'          => $d['id'],
                'type'        => $d['doc_type'],
                'number'      => $d['doc_number'],
                'holder'      => $d['holder_name'],
                'expiry_date' => $exp,
                'days_left'   => $daysLeft,
                'status'      => $daysLeft === null ? 'unknown'
                    : ($daysLeft <= 0 ? 'expired'
                    : ($daysLeft <= 30 ? 'critical'
                    : ($daysLeft <= 90 ? 'warning' : 'valid'))),
                'drive_url'   => $d['drive_url'],
                'file_name'   => $d['file_name'],
            ];
        }
        return $summary;
    }

    // ── Helpers ───────────────────────────────────────────────
    private function docLabel(string $type): string {
        return match($type) {
            'passport'          => 'পাসপোর্ট',
            'visa'              => 'ভিসা',
            'nid'               => 'জাতীয় পরিচয়পত্র (NID)',
            'driving_license'   => 'ড্রাইভিং লাইসেন্স',
            'birth_certificate' => 'জন্ম সনদ',
            default             => 'ডকুমেন্ট',
        };
    }

    private function validDate(?string $date): ?string {
        if (!$date || $date === 'null') return null;
        $ts = strtotime($date);
        if (!$ts || $ts <= 0) return null;
        // Sanity check: 1900-2100
        $year = (int) date('Y', $ts);
        return ($year >= 1900 && $year <= 2100) ? date('Y-m-d', $ts) : null;
    }

    private function callVision(string $prompt, string $imgB64, string $mime): string {
        $ocrModel = Config::get("gemini_model", "gemini-3.1-flash") ?: "gemini-3.1-flash";
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$ocrModel}:generateContent?key={$this->geminiKey}";
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
                'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 600],
            ]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) throw new Exception("Gemini Vision HTTP {$code}: " . substr($resp, 0, 200));
        $data = json_decode($resp, true);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    private function parseJson(string $text): ?array {
        $text = preg_replace('/```(?:json)?\s*/i', '', $text);
        $text = str_replace('```', '', trim($text));
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $d = json_decode($m[0], true);
            return json_last_error() === JSON_ERROR_NONE ? $d : null;
        }
        return null;
    }
}
