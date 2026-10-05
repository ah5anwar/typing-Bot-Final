<?php
// storage/download.php — Secure file download
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/config/security.php';
require_once dirname(__DIR__).'/services/LocalStorageService.php';

$token = $_GET['token'] ?? '';
if (!$token) { http_response_code(400); die('Invalid request'); }

$storage = new LocalStorageService();
$info    = $storage->verifyToken($token);

if (!$info) { http_response_code(403); die('Access denied or link expired'); }

// Check if bot user (via platform_id in session or query)
// OR admin
$isAdmin = false;
if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['admin_logged_in'])) {
    $isAdmin = true;
}

// Bot customer access via signed token (token itself is the auth)
// Admin access via session
// Both allowed here since token is cryptographically signed

$filePath = $info['file_path'];
$fileName = $info['file_name'];
$mimeMap  = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png'  => 'image/png',
    'gif'  => 'image/gif',  'webp' => 'image/webp',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'txt'  => 'text/plain',
    'zip'  => 'application/zip',
];
$ext  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

// Inline preview for images/pdf, download for others
$disposition = in_array($ext, ['jpg','jpeg','png','gif','webp','pdf']) ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
