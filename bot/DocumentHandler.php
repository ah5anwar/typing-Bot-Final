<?php
// ============================================================
// bot/DocumentHandler.php — FAKE DETECTION INTEGRATED
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/OCRService.php';
require_once __DIR__ . '/../services/FakeDocumentService.php';
require_once __DIR__ . '/../services/FileDownloader.php';

class DocumentHandler {

    private Database $db;
    private Sender   $sender;

    public function __construct(Sender $sender) {
        $this->db     = Database::getInstance();
        $this->sender = $sender;
    }

    // ============================================================
    // MAIN
    // ============================================================
    public function handle(array $customer, array $msgData, array $state = []): void {
        $platform = $msgData['platform'];
        $chatId   = $msgData['chat_id'];
        $lang     = $customer['language'] ?? 'bn';

        $this->sender->send($platform, $chatId,
            $lang === 'bn'
                ? "📎 ডকুমেন্ট পেয়েছি। যাচাই করছি... ⏳"
                : "📎 Document received. Processing... ⏳"
        );

        try {
            // ── 1: ফাইল ডাউনলোড ──────────────────────────────────
            $dl        = new FileDownloader();
            $localFile = $dl->download($msgData, $platform);
            if (!$localFile || !file_exists($localFile)) {
                throw new Exception('File download failed');
            }

            $fileName = $msgData['file_name'] ?? ('doc_' . date('YmdHis') . '.jpg');
            $isImage  = $msgData['type'] === 'image';

            // ── 2: FAKE DETECTION আগে চালানো (image হলে) ─────────
            // গ্রাহককে অপেক্ষা করাই, কিন্তু fake check আগে
            $fakeResult = null;
            if ($isImage && Config::isEnabled('fake_doc_detector_enabled')) {
                $fakeSvc    = new FakeDocumentService();
                $fakeResult = $fakeSvc->check($localFile, 0, $customer); // docId পরে update হবে
                $rec        = $fakeResult['recommendation'] ?? 'accept';

                // reject হলে আর এগোবো না
                if ($rec === 'reject' && ($fakeResult['confidence_score'] ?? 0) >= 70) {
                    $custMsg = $fakeSvc->buildCustomerMessage($fakeResult, $lang);
                    if ($custMsg) $this->sender->send($platform, $chatId, $custMsg);
                    @unlink($localFile);
                    return;
                }
            }

            // ── 3: Local Storage-এ সেভ ───────────────────────────
            $uploadResult = null;
            if (Config::isEnabled('document_upload_enabled')) {
                try {
                    require_once __DIR__ . '/../services/LocalStorageService.php';
                    $ls     = new LocalStorageService();
                    $saved  = $ls->saveFile($customer, $localFile, $fileName);
                    if ($saved) {
                        $uploadResult = ['id'=>null,'url'=>$saved['download_url'],'name'=>$saved['file_name']];
                        Logger::info("LocalStorage: file saved for customer #{$customer['id']}");
                    }
                } catch(Exception $e) {
                    Logger::error('DocumentHandler LocalStorage: ' . $e->getMessage());
                }
            }

            // ── 4: DB-তে ডকুমেন্ট রেকর্ড ─────────────────────────
            $docId = $this->db->insert(
                "INSERT INTO documents (customer_id, file_name, drive_file_id, drive_url, platform,
                  is_suspicious, fake_check_done, fake_confidence, fake_recommendation, fake_issues)
                 VALUES (?,?,?,?,?,?,?,?,?,?)",
                [
                    $customer['id'],
                    $fileName,
                    $uploadResult['id']   ?? null,
                    $uploadResult['url']  ?? null,
                    $platform,
                    ($fakeResult['is_suspicious'] ?? false) ? 1 : 0,
                    ($fakeResult['checked'] ?? false) ? 1 : 0,
                    $fakeResult['confidence_score'] ?? null,
                    $fakeResult['recommendation']   ?? null,
                    json_encode($fakeResult['issues'] ?? [], JSON_UNESCAPED_UNICODE),
                ]
            );

            // Fake result-এ docId আপডেট (suspicious_documents টেবিলে)
            if ($fakeResult['is_suspicious'] ?? false) {
                try {
                    $this->db->execute(
                        "UPDATE suspicious_documents SET document_id=? WHERE document_id=0 AND customer_id=? ORDER BY created_at DESC LIMIT 1",
                        [$docId, $customer['id']]
                    );
                } catch (Exception $e) { /* table might not exist yet */ }
            }

            // ── 5: সেভ confirmation ──────────────────────────────
            if ($uploadResult) {
                $this->sender->send($platform, $chatId,
                    $lang === 'bn'
                        ? "✅ আপনার ডকুমেন্ট সফলভাবে জমা হয়েছে!"
                        : "✅ Your document has been saved successfully!"
                );
            }

            // ── 6: Locker state ────────────────────────────────────
            if (($state['state'] ?? '') === 'waiting_locker_file' && $uploadResult) {
                $this->saveToLocker($customer, $uploadResult, $state, $platform, $chatId, $lang);
                @unlink($localFile);
                return;
            }

            // ── 7: OCR স্ক্যান ─────────────────────────────────────
            if ($isImage && Config::isEnabled('ocr_enabled')) {
                $this->runOCR($customer, $localFile, $docId, $platform, $chatId, $lang);
            } elseif (!$isImage) {
                $this->sender->send($platform, $chatId,
                    $lang === 'bn'
                        ? "📄 ফাইল জমা হয়েছে। (ছবি পাঠালে তথ্য স্বয়ংক্রিয়ভাবে বের হবে।)"
                        : "📄 File saved. (Send a photo to extract data automatically.)"
                );
            }

            // ── 8: Fake Detection মেসেজ পাঠানো (OCR-এর পরে) ──────
            if ($fakeResult) {
                $fakeSvc  = new FakeDocumentService();
                $custMsg  = $fakeSvc->buildCustomerMessage($fakeResult, $lang);
                if ($custMsg) {
                    $this->sender->send($platform, $chatId, $custMsg);
                }
                // Admin alert
                $fakeSvc->alertAdmin($fakeResult, $customer, $docId);
            }

            @unlink($localFile);

        } catch (Exception $e) {
            Logger::error("DocumentHandler: " . $e->getMessage());
            $this->sender->send($platform, $chatId,
                $lang === 'bn'
                    ? "দুঃখিত, সমস্যা হয়েছে। আবার চেষ্টা করুন।"
                    : "Sorry, processing failed. Please try again."
            );
        }
    }

    // ── OCR ──────────────────────────────────────────────────────
    private function runOCR(array $customer, string $localFile, int $docId, string $platform, string $chatId, string $lang): void {
        try {
            $ocr    = new OCRService();
            $result = $ocr->scanDocument($localFile);

            if (!$result['success']) {
                $err = $result['error'] ?? '';
                if (str_contains($err, 'API Key')) {
                    $this->sender->send($platform, $chatId,
                        $lang === 'bn' ? "⚠️ Gemini API Key সেট করা নেই।" : "⚠️ Gemini API Key not set."
                    );
                } elseif (!($result['is_clear'] ?? true)) {
                    $this->sender->send($platform, $chatId,
                        $lang === 'bn' ? "⚠️ ছবি অস্পষ্ট। স্পষ্ট আলোতে কাছ থেকে তুলুন।" : "⚠️ Image unclear. Take a closer photo in good lighting."
                    );
                } else {
                    $this->sender->send($platform, $chatId,
                        $lang === 'bn' ? "⚠️ ডকুমেন্ট স্ক্যান করা যায়নি।" : "⚠️ Couldn't scan document."
                    );
                }
                return;
            }

            // DB সেভ + Reminder
            $ocr->saveToDatabase($docId, $result, $customer['id']);

            // গ্রাহককে ফলাফল
            $this->sender->send($platform, $chatId, $ocr->formatForCustomer($result, $lang));

            // Customer নাম আপডেট
            if (!empty($result['holder_name']) && empty($customer['name'])) {
                $this->db->execute("UPDATE customers SET name=? WHERE id=?", [$result['holder_name'], $customer['id']]);
            }

            // Admin notification
            $this->notifyAdmin($customer, $result, $docId);

        } catch (Exception $e) {
            Logger::error('runOCR: ' . $e->getMessage());
            $this->sender->send($platform, $chatId,
                $lang === 'bn' ? "স্ক্যান করতে সমস্যা হয়েছে।" : "Scan failed."
            );
        }
    }

    // ── Admin Notification ────────────────────────────────────────
    private function notifyAdmin(array $customer, array $ocr, int $docId): void {
        $gid = Config::get('admin_telegram_group');
        if (!$gid) return;

        $exp      = $ocr['expiry_date'] ?? null;
        $daysLeft = $exp ? (int) ceil((strtotime($exp) - time()) / 86400) : null;
        $docType  = $ocr['doc_type'] ?? '—';
        $docNum   = $ocr['doc_number'] ?? '—';

        $msg  = "📎 *নতুন ডকুমেন্ট!*\n\n";
        $msg .= "👤 " . ($customer['name'] ?? '?') . " | " . ($customer['mobile'] ?? '') . "\n";
        $msg .= "📄 {$docType} | {$docNum}\n";
        if ($exp) {
            $msg .= "⏳ মেয়াদ: " . date('d/m/Y', strtotime($exp));
            if ($daysLeft !== null) $msg .= " ({$daysLeft}d)";
            if ($daysLeft !== null && $daysLeft <= 30) $msg .= " ⚠️";
            if ($daysLeft !== null && $daysLeft <= 0)  $msg .= " ❌EXPIRED";
        }
        try { $this->sender->sendTelegramMessage($gid, $msg); }
        catch (Exception $e) { /* silent */ }
    }

    // ── Locker ────────────────────────────────────────────────────
    private function saveToLocker(array $customer, array $upload, array $state, string $platform, string $chatId, string $lang): void {
        try {
            require_once __DIR__ . '/../services/DigitalLockerService.php';
            $pw = $state['state_data']['pending_password'] ?? 'locker2025';
            (new DigitalLockerService())->saveFile($customer, $upload['url'], $upload['name'], $pw);
            $this->sender->send($platform, $chatId,
                $lang === 'bn' ? "🔒 লকারে সেভ হয়েছে!" : "🔒 Saved to locker!"
            );
            $this->db->execute("UPDATE bot_states SET state='idle',state_data=NULL WHERE customer_id=?", [$customer['id']]);
        } catch (Exception $e) { Logger::error('LockerSave: '.$e->getMessage()); }
    }
}


// ── MemberCardHandler ──────────────────────────────────────────
class MemberCardHandler {
    private Sender   $sender;
    private Database $db;

    public function __construct(Sender $sender) {
        $this->sender = $sender;
        $this->db     = Database::getInstance();
    }

    public function handle(array $customer, string $platform, string $chatId): void {
        require_once __DIR__ . '/../services/Services.php';
        $lang = $customer['language'] ?? 'bn';
        $this->sender->send($platform, $chatId,
            $lang === 'bn' ? "🪪 Member Card তৈরি হচ্ছে..." : "🪪 Generating Member Card..."
        );
        $qr = new QRCodeService();
        if ($qr->generateForCustomer($customer['id'])) {
            $qr->sendCardToCustomer($this->db->fetchOne("SELECT * FROM customers WHERE id=?", [$customer['id']]));
        } else {
            $this->sender->send($platform, $chatId,
                $lang === 'bn' ? "Card তৈরি করতে সমস্যা হয়েছে।" : "Couldn't generate card."
            );
        }
    }
}
