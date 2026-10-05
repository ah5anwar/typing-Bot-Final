<?php
// ============================================================
// services/LocalStorageService.php
// গ্রাহকের ফাইল সার্ভারে সংরক্ষণ
// ============================================================

class LocalStorageService {

    private string $storageDir;
    private string $baseUrl;

    public function __construct() {
        $this->storageDir = BASE_PATH . '/storage/documents';
        $this->baseUrl    = Config::get('site_url', '');
        if (!$this->baseUrl) {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $this->baseUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
    }

    // ── ফাইল সেভ করা ──────────────────────────────────────────
    public function saveFile(array $customer, string $tmpFile, string $originalName): ?array {
        if (!file_exists($tmpFile)) {
            Logger::error("LocalStorage: tmp file not found: $tmpFile");
            return null;
        }

        // গ্রাহকের folder — নাম_মোবাইল format
        $cname  = preg_replace('/[^A-Za-z0-9_]/', '_', $customer['name'] ?? 'Customer');
        $cmob   = preg_replace('/[^0-9]/', '', $customer['mobile'] ?? '');
        $dirTag = trim($cname,'_') . ($cmob ? '_'.$cmob : '_'.$customer['id']);
        $dirTag = substr($dirTag, 0, 60);

        $custDir = $this->storageDir . '/' . $customer['id'];
        if (!is_dir($custDir)) {
            mkdir($custDir, 0755, true);
            file_put_contents($custDir . '/.htaccess', "Deny from all\n");
            file_put_contents($custDir . '/.name', $dirTag); // folder label
        }

        // সব storage-এ .htaccess
        if (!file_exists($this->storageDir . '/.htaccess')) {
            @mkdir($this->storageDir, 0755, true);
            file_put_contents($this->storageDir . '/.htaccess', "Deny from all\n");
        }

        // Safe filename
        $ext      = strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) ?: 'bin';
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $safeName = substr($safeName, 0, 50) ?: 'file';
        $fileName = date('Ymd_His') . '_' . $safeName . '.' . $ext;
        $destPath = $custDir . '/' . $fileName;

        // Copy (not move — tmp used by OCR too)
        if (!copy($tmpFile, $destPath)) {
            Logger::error("LocalStorage: copy failed: $tmpFile → $destPath");
            return null;
        }

        // Secure download token
        $token    = $this->generateToken($customer['id'], $fileName);
        $downloadUrl = $this->baseUrl . '/storage/download.php?token=' . $token;

        Logger::info("LocalStorage: saved file for customer #{$customer['id']}: $fileName");

        return [
            'local_path'   => $destPath,
            'file_name'    => $fileName,
            'download_url' => $downloadUrl,
            'token'        => $token,
            'size'         => filesize($destPath),
        ];
    }

    // ── Secure Token তৈরি ──────────────────────────────────────
    public function generateToken(int $customerId, string $fileName, int $expiry = 0): string {
        $secret = Config::get('app_secret', 'default_secret_2025');
        $data   = $customerId . '|' . $fileName . '|' . $expiry;
        $sig    = hash_hmac('sha256', $data, $secret);
        return base64_encode($data . '|' . $sig);
    }

    // ── Token যাচাই ────────────────────────────────────────────
    public function verifyToken(string $token): ?array {
        try {
            $decoded = base64_decode($token);
            $parts   = explode('|', $decoded);
            if (count($parts) !== 4) return null;
            [$customerId, $fileName, $expiry, $sig] = $parts;

            $secret   = Config::get('app_secret', 'default_secret_2025');
            $data     = $customerId . '|' . $fileName . '|' . $expiry;
            $expected = hash_hmac('sha256', $data, $secret);

            if (!hash_equals($expected, $sig)) return null;
            if ($expiry > 0 && time() > $expiry) return null;

            $filePath = $this->storageDir . '/' . $customerId . '/' . basename($fileName);
            if (!file_exists($filePath)) return null;

            return [
                'customer_id' => (int)$customerId,
                'file_name'   => $fileName,
                'file_path'   => $filePath,
            ];
        } catch(Exception $e) {
            return null;
        }
    }

    // ── গ্রাহকের সব ফাইল ──────────────────────────────────────
    public function getCustomerFiles(int $customerId): array {
        $dir   = $this->storageDir . '/' . $customerId;
        $files = [];
        if (!is_dir($dir)) return $files;

        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || $f === '.htaccess') continue;
            $path = $dir . '/' . $f;
            if (!is_file($path)) continue;
            $token = $this->generateToken($customerId, $f);
            $files[] = [
                'name'         => $f,
                'size'         => filesize($path),
                'modified'     => filemtime($path),
                'download_url' => $this->baseUrl . '/storage/download.php?token=' . $token,
                'token'        => $token,
            ];
        }
        rsort($files);
        return $files;
    }

    // ── সব গ্রাহকের ফাইল (admin) ──────────────────────────────
    public function getAllFiles(): array {
        $all = [];
        if (!is_dir($this->storageDir)) return $all;
        foreach (scandir($this->storageDir) as $cid) {
            if (!is_numeric($cid)) continue;
            foreach ($this->getCustomerFiles((int)$cid) as $f) {
                $f['customer_id'] = (int)$cid;
                $all[] = $f;
            }
        }
        return $all;
    }

    // ── ফাইল মুছা ──────────────────────────────────────────────
    public function deleteFile(int $customerId, string $fileName): bool {
        $path = $this->storageDir . '/' . $customerId . '/' . basename($fileName);
        if (file_exists($path)) { @unlink($path); return true; }
        return false;
    }

    // ── গ্রাহকের সম্পূর্ণ ফোল্ডার মুছা ──────────────────────
    public function deleteCustomerFolder(int $customerId): bool {
        $dir = $this->storageDir . '/' . $customerId;
        if (!is_dir($dir)) return true;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            @unlink($dir . '/' . $f);
        }
        @rmdir($dir);
        Logger::info("LocalStorage: deleted all files for customer #$customerId");
        return true;
    }

    public function getStorageDir(): string { return $this->storageDir; }
    public function getBaseUrl(): string    { return $this->baseUrl; }
}
