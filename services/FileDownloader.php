<?php
// ============================================================
// services/FileDownloader.php
// প্ল্যাটফর্ম থেকে ফাইল ডাউনলোড করা
// ============================================================

class FileDownloader {
    private string $tmpDir;

    public function __construct() {
        $this->tmpDir = BASE_PATH . '/tmp';
        if (!is_dir($this->tmpDir)) mkdir($this->tmpDir, 0755, true);
    }

    public function download(array $msgData, string $platform): ?string {
        $fileId = $msgData['file_id'] ?? null;
        if (!$fileId) return null;

        switch ($platform) {
            case 'telegram':
                return $this->downloadTelegram($fileId);
            case 'whatsapp':
                return $this->downloadWhatsApp($fileId);
            case 'messenger':
                return $this->downloadByUrl($fileId, 'messenger');
            default:
                return null;
        }
    }

    private function downloadTelegram(string $fileId): ?string {
        $token    = Config::get('telegram_token');
        $sender   = new Sender();
        $fileUrl  = $sender->getTelegramFileUrl($fileId);

        if (!$fileUrl) return null;
        return $this->downloadByUrl($fileUrl, 'telegram');
    }

    private function downloadWhatsApp(string $mediaId): ?string {
        $token    = Config::get('whatsapp_token');
        $sender   = new Sender();
        $mediaUrl = $sender->getWhatsAppMediaUrl($mediaId);

        if (!$mediaUrl) return null;

        $localFile = $this->tmpDir . '/' . uniqid('wa_') . '.tmp';
        $this->fetchUrl($mediaUrl, $localFile, ['Authorization: Bearer ' . $token]);
        return file_exists($localFile) ? $localFile : null;
    }

    public function downloadByUrl(string $url, string $platform): ?string {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return null;

        $ext       = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $localFile = $this->tmpDir . '/' . uniqid($platform . '_') . '.' . $ext;
        $headers   = [];

        if ($platform === 'whatsapp') {
            $headers[] = 'Authorization: Bearer ' . Config::get('whatsapp_token');
        }

        $this->fetchUrl($url, $localFile, $headers);
        return file_exists($localFile) && filesize($localFile) > 0 ? $localFile : null;
    }

    private function fetchUrl(string $url, string $savePath, array $headers = []): void {
        $ch = curl_init($url);
        $fp = fopen($savePath, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
    }
}
