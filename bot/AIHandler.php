<?php
// ============================================================
// bot/AIHandler.php — AI Response Handler
// Gemini 3.1/2.5/2.0/1.5 + OpenRouter fallback
// ============================================================

class AIHandler {

    private string $systemPrompt;
    private Database $db;

    public function __construct() {
        $this->db           = Database::getInstance();
        $this->systemPrompt = Config::get('system_prompt',
            'তুমি একটি ভিসা ও ইমিগ্রেশন সেবার AI সহকারী। সহায়ক হও।');
    }

    // ── Main Entry Point ───────────────────────────────────────
    public function getResponse(string $msg, array $customer = []): ?string {
        if (!$msg) return null;

        $ctx  = $this->buildContext($customer);
        $gKey = Config::get('gemini_api_key', '');
        Logger::info("AIHandler::getResponse mode=" .
            (Config::isEnabled('faq_service_only_mode') ? 'faq_only' : 'normal') .
            " gemini_key=" . ($gKey ? 'SET' : 'NOT_SET'));

        // FAQ+Service only mode
        if (Config::isEnabled('faq_service_only_mode')) {
            return $this->getFAQServiceResponse($msg, $customer, $ctx);
        }

        // Normal mode: Gemini → OpenRouter
        return $this->callAI($msg, $ctx);
    }

    // ── FAQ + Service Mode ─────────────────────────────────────
    private function getFAQServiceResponse(string $msg, array $customer, string $ctx): ?string {
        $faqs = $this->db->fetchAll("SELECT question,answer,keywords FROM faqs WHERE is_active=1");
        $svcs = $this->db->fetchAll("SELECT name,description,price,currency,duration,docs_required FROM services WHERE is_active=1");

        $faqBlock = '';
        foreach ($faqs as $f) {
            $faqBlock .= "Q: {$f['question']}\nA: {$f['answer']}\n\n";
        }
        $svcBlock = '';
        foreach ($svcs as $s) {
            $svcBlock .= "সেবা: {$s['name']}";
            if ($s['price'] > 0)     $svcBlock .= " | মূল্য: {$s['price']} {$s['currency']}";
            if ($s['description'])   $svcBlock .= " | {$s['description']}";
            if ($s['duration'])      $svcBlock .= " | সময়: {$s['duration']}";
            if ($s['docs_required']) $svcBlock .= " | কাগজ: " . str_replace("\n", ", ", trim($s['docs_required']));
            $svcBlock .= "\n";
        }

        $fullSystem = $this->systemPrompt
            . "\n\n## KNOWLEDGE BASE (শুধু এখান থেকে উত্তর দাও)\n\n"
            . "### FAQ:\n" . ($faqBlock ?: "কোনো FAQ নেই।")
            . "\n### Services:\n" . ($svcBlock ?: "কোনো সেবা নেই।")
            . "\n\nএর বাইরে কিছু বলবে না।\n\n" . $ctx;

        return $this->callAI($msg, $fullSystem, true);
    }

    // ── Core AI Caller ─────────────────────────────────────────
    private function callAI(string $msg, string $systemOrCtx, bool $fullSystem = false): ?string {
        $geminiKey = Config::get('gemini_api_key', '');
        if ($geminiKey) {
            try {
                $result = $this->gemini($msg, $systemOrCtx, $geminiKey, $fullSystem);
                if ($result) {
                    $this->log('gemini', Config::get('gemini_model', ''), null, true);
                    Config::set('current_ai_provider', 'gemini');
                    return $result;
                }
            } catch (Exception $e) {
                Logger::error("Gemini failed: " . $e->getMessage());
                $this->log('gemini', Config::get('gemini_model', ''), null, false);
            }
        } else {
            Logger::error("AI: Gemini API key not set");
        }

        // OpenRouter fallback
        if (Config::isEnabled('ai_fallback_enabled')) {
            $orKey = Config::get('openrouter_api_key', '');
            if ($orKey) {
                try {
                    $result = $this->openrouter($msg, $systemOrCtx, $orKey, $fullSystem);
                    if ($result) {
                        $this->log('openrouter', Config::get('openrouter_model', ''), null, true);
                        Config::set('current_ai_provider', 'openrouter');
                        return $result;
                    }
                } catch (Exception $e) {
                    Logger::error("OpenRouter failed: " . $e->getMessage());
                    $this->log('openrouter', Config::get('openrouter_model', ''), null, false);
                }
            }
        }

        return null;
    }

    // ── Gemini API ─────────────────────────────────────────────
    private function gemini(string $msg, string $ctx, string $apiKey, bool $ctxIsFull = false): ?string {
        $primary = trim(Config::get('gemini_model', 'gemini-3.1-flash'));
        if (empty($primary) || in_array($primary, ['__custom__', ''])) {
            $primary = 'gemini-3.1-flash';
        }

        // Fallback chain
        $chain = array_unique(array_filter([
            $primary,
            'gemini-3.1-flash',
            'gemini-2.5-flash',
            'gemini-2.5-flash-preview-04-17',
            'gemini-2.0-flash',
            'gemini-1.5-flash',
        ]));

        $sysText = $ctxIsFull ? $ctx : ($this->systemPrompt . ($ctx ? "\n\n" . $ctx : ''));

        foreach ($chain as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
            try {
                $resp = $this->http($url, [
                    'contents'           => [['role' => 'user', 'parts' => [['text' => $msg]]]],
                    'system_instruction' => ['parts' => [['text' => $sysText]]],
                    'generationConfig'   => ['temperature' => 0.7, 'maxOutputTokens' => 700],
                ]);

                $data = json_decode($resp, true);
                if (!$data) {
                    Logger::error("Gemini [{$model}]: invalid JSON: " . substr($resp, 0, 100));
                    continue;
                }
                if (isset($data['error'])) {
                    $code = $data['error']['code'] ?? 0;
                    $emsg = $data['error']['message'] ?? '';
                    Logger::error("Gemini [{$model}] Error {$code}: {$emsg}");
                    if (in_array($code, [401, 403])) break;
                    continue;
                }
                if (isset($data['promptFeedback']['blockReason'])) {
                    Logger::error("Gemini [{$model}] BLOCKED");
                    continue;
                }

                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if ($text) {
                    if ($model !== $primary) {
                        Logger::info("Gemini fallback: {$primary} → {$model}");
                        Config::set('gemini_model', $model);
                    }
                    return trim($text);
                }
            } catch (Exception $e) {
                Logger::error("Gemini [{$model}]: " . $e->getMessage());
                continue;
            }
        }
        return null;
    }

    // ── OpenRouter API ─────────────────────────────────────────
    private function openrouter(string $msg, string $ctx, string $apiKey, bool $ctxIsFull = false): ?string {
        $model = trim(Config::get('openrouter_model', 'mistralai/mistral-7b-instruct'));
        if (empty($model) || $model === '__or_custom__') {
            $model = 'mistralai/mistral-7b-instruct';
        }
        $sysText = $ctxIsFull ? $ctx : ($this->systemPrompt . ($ctx ? "\n\n" . $ctx : ''));
        $resp = $this->http(
            'https://openrouter.ai/api/v1/chat/completions',
            [
                'model'       => $model,
                'messages'    => [
                    ['role' => 'system', 'content' => $sysText],
                    ['role' => 'user',   'content' => $msg],
                ],
                'max_tokens'  => 700,
                'temperature' => 0.7,
            ],
            ['Authorization: Bearer ' . $apiKey, 'HTTP-Referer: https://bot.local']
        );
        $data = json_decode($resp, true);
        if (!$data) throw new Exception("OpenRouter: invalid JSON");
        if (isset($data['error'])) throw new Exception($data['error']['message'] ?? 'OpenRouter error');
        return $data['choices'][0]['message']['content'] ?? null;
    }

    // ── Voice to Text ──────────────────────────────────────────
    public function voiceToText(string $fileUrl, string $platform): ?string {
        $key = Config::get('gemini_api_key');
        if (!$key) return null;
        require_once __DIR__ . '/../services/FileDownloader.php';
        $dl   = new FileDownloader();
        $file = $dl->downloadByUrl($fileUrl, $platform);
        if (!$file || !file_exists($file)) return null;
        try {
            $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $mime = match($ext) { 'mp3'=>'audio/mp3','wav'=>'audio/wav','m4a'=>'audio/mp4',default=>'audio/ogg' };
            $b64  = base64_encode(file_get_contents($file));
            $model = Config::get('gemini_model', 'gemini-3.1-flash');
            $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
            $resp = $this->http($url, [
                'contents' => [['parts' => [
                    ['text' => 'এই অডিওটি transcript করো। শুধু transcript দাও।'],
                    ['inline_data' => ['mime_type' => $mime, 'data' => $b64]],
                ]]],
                'generationConfig' => ['maxOutputTokens' => 300],
            ]);
            @unlink($file);
            $r = json_decode($resp, true);
            return $r['candidates'][0]['content']['parts'][0]['text'] ?? null;
        } catch (Exception $e) {
            Logger::error('Voice: ' . $e->getMessage());
            @unlink($file);
            return null;
        }
    }

    // ── OCR ────────────────────────────────────────────────────
    public function extractDocumentData(string $localFile): ?array {
        $key = Config::get('gemini_api_key');
        if (!$key || !file_exists($localFile)) {
            Logger::error("OCR: key=" . ($key ? 'SET' : 'MISSING') . " file=" . (file_exists($localFile) ? 'OK' : 'MISSING'));
            return null;
        }
        $ext  = strtolower(pathinfo($localFile, PATHINFO_EXTENSION));
        $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : 'image/png';
        $b64  = base64_encode(file_get_contents($localFile));
        if (!$b64) { Logger::error("OCR: failed to encode file"); return null; }

        $prompt = 'এই ডকুমেন্ট থেকে তথ্য বের করো। শুধু JSON (কোনো markdown নয়):
{"doc_type":"passport|visa|nid|other","name":"","passport_no":"","dob":"YYYY-MM-DD","expiry_date":"YYYY-MM-DD","nationality":"","visa_type":"","issue_date":"YYYY-MM-DD","issue_country":"","is_clear":true}
তথ্য না থাকলে null দাও।';
        try {
            $model = Config::get('gemini_model', 'gemini-3.1-flash');
            $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
            $resp = $this->http($url, [
                'contents' => [['parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => $mime, 'data' => $b64]],
                ]]],
                'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 500],
            ]);
            $r    = json_decode($resp, true);
            if (isset($r['error'])) { Logger::error("OCR: " . $r['error']['message']); return null; }
            $text = $r['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $text = preg_replace('/```(?:json)?\s*|\s*```/', '', trim($text));
            preg_match('/\{.*\}/s', $text, $m);
            if (!$m) { Logger::error("OCR: no JSON in response"); return null; }
            $d = json_decode($m[0], true);
            return json_last_error() === JSON_ERROR_NONE ? $d : null;
        } catch (Exception $e) {
            Logger::error('OCR: ' . $e->getMessage());
            return null;
        }
    }

    // ── Context Builder ────────────────────────────────────────
    private function buildContext(array $c): string {
        if (empty($c)) return '';
        $ctx  = "## Known Customer Info\n";
        $ctx .= "নাম: "    . ($c['name']    ?: 'জানা নেই') . "\n";
        $ctx .= "মোবাইল: " . ($c['mobile']  ?: 'সংগ্রহ করতে হবে') . "\n";
        $ctx .= "ঠিকানা: " . ($c['address'] ?: 'সংগ্রহ করতে হবে') . "\n";
        $ctx .= "ভাষা: "   . ($c['language'] === 'en' ? 'English' : 'বাংলা') . "\n";
        $ctx .= "Onboarding: " . ($c['onboarding_done'] ? 'COMPLETE' : 'INCOMPLETE') . "\n";
        try {
            $app = $this->db->fetchOne(
                "SELECT a.tracking_id, s.name as sname, st.name as status_name
                 FROM applications a
                 LEFT JOIN services s ON a.service_id=s.id
                 LEFT JOIN application_statuses st ON a.status_id=st.id
                 WHERE a.customer_id=? ORDER BY a.created_at DESC LIMIT 1",
                [$c['id'] ?? 0]
            );
            if ($app) $ctx .= "সর্বশেষ আবেদন: {$app['sname']} ({$app['status_name']})\n";

            $msgs = $this->db->fetchAll(
                "SELECT direction, content FROM messages
                 WHERE customer_id=? AND message_type='text'
                 ORDER BY sent_at DESC LIMIT 6",
                [$c['id'] ?? 0]
            );
            if ($msgs) {
                $ctx .= "\n## Previous Conversation\n";
                foreach (array_reverse($msgs) as $m) {
                    $who  = $m['direction'] === 'in' ? 'গ্রাহক' : 'রিয়া';
                    $text = mb_substr(strip_tags($m['content'] ?? ''), 0, 120, 'UTF-8');
                    if ($text) $ctx .= "{$who}: {$text}\n";
                }
            }
        } catch (Exception $e) {}
        return $ctx;
    }

    // ── HTTP ────────────────────────────────────────────────────
    private function http(string $url, array $data, array $headers = []): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) throw new Exception("cURL: $err");
        if ($code >= 500) throw new Exception("HTTP {$code} from Gemini API");
        return $resp ?: '';
    }

    // ── Log ─────────────────────────────────────────────────────
    private function log(string $provider, string $model, ?int $cid, bool $ok): void {
        try {
            $this->db->execute(
                "INSERT INTO ai_usage_logs (provider,model_name,customer_id,success) VALUES (?,?,?,?)",
                [$provider, $model, $cid, $ok ? 1 : 0]
            );
        } catch (Exception $e) {}
    }

    // ── Aliases ─────────────────────────────────────────────────
    public function callGeminiDirect(string $msg, string $ctx, string $apiKey): ?string {
        return $this->gemini($msg, $ctx, $apiKey);
    }
    public function callOpenRouterDirect(string $msg, string $ctx, string $apiKey): ?string {
        return $this->openrouter($msg, $ctx, $apiKey);
    }
}
