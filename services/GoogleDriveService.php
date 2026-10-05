<?php
// ============================================================
// services/GoogleDriveService.php — FIXED (private_key + debug)
// ============================================================

class GoogleDriveService {

    private string $accessToken = '';
    private string $rootFolderId;
    private bool   $ready = false;
    private string $serviceEmail = '';
    private int    $lastHttpCode = 0;

    public function __construct() {
        $this->rootFolderId = Config::get('google_drive_folder_id', '');
        $this->authenticate();
    }

    private function authenticate(): void {
        $json = Config::get('google_service_account_json', '');
        if (!$json) {
            Logger::error('GoogleDrive: service account JSON not set in Admin Panel → Settings');
            return;
        }

        // JSON parse — stripslashes ব্যবহার না করা (JSON corrupt হয়)
        $sa = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try with stripslashes as fallback
            $sa = json_decode(stripslashes($json), true);
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('GoogleDrive: JSON parse error — ' . json_last_error_msg());
            return;
        }
        if (!isset($sa['private_key'], $sa['client_email'])) {
            Logger::error('GoogleDrive: private_key বা client_email নেই JSON-এ');
            return;
        }
        $this->serviceEmail = $sa['client_email'];

        // private_key fix: \n → real newline (সব ভ্যারিয়েন্ট handle করা)
        $pk = $sa['private_key'];
        // Case 1: literal \n (double escaped)
        $pk = str_replace('\\n', "\n", $pk);
        // Case 2: এখনো \n থাকলে (single escaped, json_decode করেনি)
        if (!str_contains($pk, "\n") && str_contains($pk, 'BEGIN')) {
            $pk = str_replace('\n', "\n", $pk);
        }
        $sa['private_key'] = $pk;

        try {
            $this->accessToken = $this->getJWTToken($sa);
            $this->ready       = !empty($this->accessToken);
            if ($this->ready) Logger::info('GoogleDrive: authenticated successfully');
        } catch (Exception $e) {
            Logger::error('GoogleDrive auth failed: ' . $e->getMessage());
        }
    }

    private function getJWTToken(array $sa): string {
        if (!function_exists('openssl_sign')) {
            throw new Exception('OpenSSL extension not enabled on server');
        }

        $now     = time();
        $header  = $this->b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->b64u(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $sigInput = $header . '.' . $payload;
        $sig      = '';

        $pkey = openssl_pkey_get_private($sa['private_key']);
        if (!$pkey) {
            throw new Exception('Invalid private_key: ' . openssl_error_string());
        }

        if (!openssl_sign($sigInput, $sig, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new Exception('JWT sign failed: ' . openssl_error_string());
        }

        $jwt  = $sigInput . '.' . $this->b64u($sig);

        $resp = $this->httpPost('https://oauth2.googleapis.com/token',
            'grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer&assertion=' . $jwt,
            ['Content-Type: application/x-www-form-urlencoded'], false
        );

        $data = json_decode($resp, true);
        if (empty($data['access_token'])) {
            throw new Exception('Token error: ' . substr($resp, 0, 300));
        }
        return $data['access_token'];
    }

    // ── Create customer folder ─────────────────────────────────
    public function createCustomerFolder(array $customer): ?array {
        if (!$this->ready) {
            Logger::error('GoogleDrive createFolder: not authenticated');
            return null;
        }

        // Folder name: sanitize for Drive
        $name   = preg_replace('/[\/\\\\:*?"<>|]/', '', ($customer['name'] ?? 'Customer') . '_' . ($customer['mobile'] ?? $customer['id']));
        $name   = trim($name) ?: 'Customer_' . $customer['id'];
        $parent = $this->rootFolderId ?: 'root';

        $resp = $this->drivePost('https://www.googleapis.com/drive/v3/files?supportsAllDrives=true', [
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => [$parent],
        ]);

        $folder = json_decode($resp, true);
        if (empty($folder['id'])) {
            Logger::error('GoogleDrive createFolder failed: ' . $resp);
            return null;
        }

        // Make folder readable by anyone with link
        $this->setPermission($folder['id'], 'reader', 'anyone');

        $url = 'https://drive.google.com/drive/folders/' . $folder['id'];
        Database::getInstance()->execute(
            "UPDATE customers SET drive_folder_id=?, drive_folder_url=? WHERE id=?",
            [$folder['id'], $url, $customer['id']]
        );

        Logger::info("GoogleDrive: folder created for customer #{$customer['id']}: {$folder['id']}");
        return ['id' => $folder['id'], 'url' => $url];
    }

    // ── Upload file ────────────────────────────────────────────
    public function uploadCustomerFile(array $customer, string $localFile, string $fileName): ?array {
        if (!$this->ready) {
            Logger::error('GoogleDrive upload: not authenticated');
            return null;
        }
        if (!file_exists($localFile)) {
            Logger::error("GoogleDrive upload: local file not found: $localFile");
            return null;
        }

        // Use root folder directly - SA-owned subfolders cause storageQuotaExceeded
        // Files go into: rootFolder/CustomerName_file.ext
        $folderId = $this->rootFolderId ?: null;
        
        // Try to create/use customer subfolder only if root folder is set
        if ($folderId) {
            $db = Database::getInstance();
            $freshCust = $db->fetchOne("SELECT drive_folder_id FROM customers WHERE id=?", [$customer['id']]);
            $custFolderId = $freshCust['drive_folder_id'] ?? '';
            if ($custFolderId) {
                $folderId = $custFolderId;
            }
            // Don't auto-create subfolder here - it would be SA-owned
        }

        // Build multipart body
        $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $mimeMap  = [
            'pdf'  => 'application/pdf',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $mimeType = $mimeMap[$ext] ?? 'application/octet-stream';
        $boundary = 'bot_boundary_' . uniqid();
        // Prefix filename with customer name for organization in root folder
        $custName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $customer['name'] ?? 'Customer');
        $finalName = $custName . '_' . $fileName;
        $meta     = json_encode(['name' => $finalName, 'parents' => array_filter([$folderId])]);

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $meta . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mimeType}\r\n\r\n";
        $body .= file_get_contents($localFile) . "\r\n";
        $body .= "--{$boundary}--";

        $uploadUrl = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name&supportsAllDrives=true';
        $uploadHeaders = [
            'Authorization: Bearer ' . $this->accessToken,
            "Content-Type: multipart/related; boundary={$boundary}",
            'Content-Length: ' . strlen($body),
        ];

        $resp = $this->httpPost($uploadUrl, $body, $uploadHeaders, false);
        $file = json_decode($resp, true);

        // If upload fails, log and return null
        if (empty($file['id'])) {
            Logger::error('GoogleDrive upload failed: ' . $resp);
            return null;
        }

        $this->setPermission($file['id'], 'reader', 'anyone');
        Logger::info("GoogleDrive: uploaded {$finalName} → {$file['id']}");

        return [
            'id'   => $file['id'],
            'name' => $finalName,
            'url'  => 'https://drive.google.com/file/d/' . $file['id'] . '/view',
        ];
    }

    // ── Delete file/folder ─────────────────────────────────────
    public function deleteFile(string $fileId): bool {
        if (!$this->ready || !$fileId) return false;
        $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->accessToken],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 204;
    }

    public function deleteFolder(string $folderId): bool {
        return $this->deleteFile($folderId);
    }

    public function isReady(): bool { return $this->ready; }

    // ── Set permission ─────────────────────────────────────────
    private function setPermission(string $fileId, string $role, string $type): void {
        $this->drivePost(
            "https://www.googleapis.com/drive/v3/files/{$fileId}/permissions",
            ['role' => $role, 'type' => $type]
        );
    }

    // ── Drive JSON POST ────────────────────────────────────────
    private function drivePost(string $url, array $data): string {
        return $this->httpPost($url, json_encode($data), [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ], false);
    }

    // ── Generic HTTP POST ──────────────────────────────────────
    private function httpPost(string $url, string $body, array $headers, bool $unused = false): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $this->lastHttpCode = $code;
        if ($err)    Logger::error("GoogleDrive cURL: $err");
        if ($code >= 400) Logger::error("GoogleDrive HTTP $code: " . $resp);
        return $resp ?: '';
    }

    private function b64u(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
