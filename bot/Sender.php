<?php
// ============================================================
// bot/Sender.php
// তিনটি প্ল্যাটফর্মে মেসেজ পাঠানোর ক্লাস
// ============================================================

class Sender {

    // ============================================================
    // মূল সেন্ড ফাংশন - প্ল্যাটফর্ম অনুযায়ী রাউট করে
    // ============================================================
    public function send(string $platform, string $chatId, string $text, array $buttons = []): void {
        switch ($platform) {
            case 'telegram':
                $this->sendTelegramMessage($chatId, $text, $buttons);
                break;
            case 'whatsapp':
                $this->sendWhatsAppMessage($chatId, $text, $buttons);
                break;
            case 'messenger':
                $this->sendMessengerMessage($chatId, $text, $buttons);
                break;
        }
    }

    // ============================================================
    // TELEGRAM
    // ============================================================
    public function sendTelegramMessage(string $chatId, string $text, array $buttons = []): bool {
        $token = Config::get('telegram_token');
        if (!$token) { Logger::error('Telegram token not set'); return false; }

        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ];

        if (!empty($buttons)) {
            $keyboard = $this->buildTelegramKeyboard($buttons);
            $payload['reply_markup'] = json_encode($keyboard);
        }

        return $this->curlPost("https://api.telegram.org/bot{$token}/sendMessage", $payload);
    }

    private function buildTelegramKeyboard(array $buttons): array {
        // buttons format: [['text' => 'Button', 'data' => 'callback_data'], ...]
        // অথবা grouped: [[btn1, btn2], [btn3]] (প্রতিটি সারি)
        if (isset($buttons[0]['text'])) {
            // Flat array - প্রতি ২টা করে সারি
            $rows = [];
            $chunk = array_chunk($buttons, 2);
            foreach ($chunk as $row) {
                $r = [];
                foreach ($row as $btn) {
                    $r[] = ['text' => $btn['text'], 'callback_data' => $btn['data']];
                }
                $rows[] = $r;
            }
            return ['inline_keyboard' => $rows];
        }
        return ['inline_keyboard' => $buttons];
    }

    public function sendTelegramPhoto(string $chatId, string $photoPath, string $caption = ''): bool {
        $token = Config::get('telegram_token');
        if (!$token) return false;

        $url  = "https://api.telegram.org/bot{$token}/sendPhoto";
        $data = [
            'chat_id' => $chatId,
            'caption' => $caption,
        ];

        if (str_starts_with($photoPath, 'http')) {
            $data['photo'] = $photoPath;
            return $this->curlPost($url, $data);
        }

        // Local file
        $ch = curl_init($url);
        $data['photo'] = new CURLFile($photoPath);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        curl_close($ch);
        return !empty($result);
    }

    // Telegram থেকে ফাইল URL পাওয়া
    public function getTelegramFileUrl(string $fileId): ?string {
        $token    = Config::get('telegram_token');
        $response = $this->curlGet("https://api.telegram.org/bot{$token}/getFile?file_id={$fileId}");
        $data     = json_decode($response, true);

        if ($data['ok'] && isset($data['result']['file_path'])) {
            return "https://api.telegram.org/file/bot{$token}/" . $data['result']['file_path'];
        }
        return null;
    }

    // ============================================================
    // WHATSAPP
    // ============================================================
    public function sendWhatsAppMessage(string $to, string $text, array $buttons = []): bool {
        $token   = Config::get('whatsapp_token');
        $phoneId = Config::get('whatsapp_phone_id');
        if (!$token || !$phoneId) { Logger::error('WhatsApp credentials not set'); return false; }

        $url = "https://graph.facebook.com/v18.0/{$phoneId}/messages";

        if (!empty($buttons) && count($buttons) <= 3) {
            // Interactive Button Message
            $btnList = [];
            foreach (array_slice($buttons, 0, 3) as $btn) {
                $btnList[] = [
                    'type'  => 'reply',
                    'reply' => ['id' => $btn['data'], 'title' => mb_substr($btn['text'], 0, 20)]
                ];
            }
            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'interactive',
                'interactive'       => [
                    'type' => 'button',
                    'body' => ['text' => $text],
                    'action' => ['buttons' => $btnList]
                ]
            ];
        } elseif (!empty($buttons)) {
            // List Message (3-এর বেশি বাটনের জন্য)
            $rows = [];
            foreach ($buttons as $btn) {
                $rows[] = ['id' => $btn['data'], 'title' => mb_substr($btn['text'], 0, 24)];
            }
            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'interactive',
                'interactive'       => [
                    'type'   => 'list',
                    'body'   => ['text' => $text],
                    'action' => [
                        'button'   => 'বিকল্প দেখুন',
                        'sections' => [['title' => 'অপশন', 'rows' => $rows]]
                    ]
                ]
            ];
        } else {
            // Simple Text
            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'text',
                'text'              => ['body' => $text]
            ];
        }

        return $this->curlPost($url, $payload, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);
    }

    // WhatsApp থেকে মিডিয়া URL পাওয়া
    public function getWhatsAppMediaUrl(string $mediaId): ?string {
        $token = Config::get('whatsapp_token');
        $response = $this->curlGet(
            "https://graph.facebook.com/v18.0/{$mediaId}",
            ['Authorization: Bearer ' . $token]
        );
        $data = json_decode($response, true);
        return $data['url'] ?? null;
    }

    // ============================================================
    // MESSENGER
    // ============================================================
    public function sendMessengerMessage(string $recipientId, string $text, array $buttons = []): bool {
        $pageToken = Config::get('messenger_page_token');
        if (!$pageToken) { Logger::error('Messenger token not set'); return false; }

        $url = "https://graph.facebook.com/v18.0/me/messages?access_token={$pageToken}";

        if (!empty($buttons)) {
            $quickReplies = [];
            foreach (array_slice($buttons, 0, 13) as $btn) {
                $quickReplies[] = [
                    'content_type' => 'text',
                    'title'        => mb_substr($btn['text'], 0, 20),
                    'payload'      => $btn['data']
                ];
            }
            $payload = [
                'recipient' => ['id' => $recipientId],
                'message'   => [
                    'text'          => $text,
                    'quick_replies' => $quickReplies
                ]
            ];
        } else {
            $payload = [
                'recipient' => ['id' => $recipientId],
                'message'   => ['text' => $text]
            ];
        }

        return $this->curlPost($url, $payload);
    }

    // ============================================================
    // Language Selection বাটন পাঠানো
    // ============================================================
    public function sendLanguageSelection(string $platform, string $chatId): void {
        $text    = "আপনার পছন্দের ভাষা বেছে নিন:\nPlease select your language:";
        $buttons = [
            ['text' => '🇧🇩 বাংলা', 'data' => 'lang_bn'],
            ['text' => '🇬🇧 English', 'data' => 'lang_en'],
        ];
        $this->send($platform, $chatId, $text, $buttons);
    }

    // ============================================================
    // CURL Helper Functions
    // ============================================================
    private function curlPost(string $url, array $data, array $headers = []): bool {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $defaultHeaders = ['Content-Type: application/json'];
        $headers = array_merge($defaultHeaders, $headers);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            Logger::error("CURL POST failed [{$httpCode}] to {$url}: {$response}");
            return false;
        }
        return true;
    }

    private function curlGet(string $url, array $headers = []): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $response = curl_exec($ch);
        curl_close($ch);
        return $response ?: '';
    }
}
