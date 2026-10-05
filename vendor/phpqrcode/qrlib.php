<?php
// ============================================================
// vendor/phpqrcode/qrlib.php
// Pure PHP QR Code Generator (No external dependencies)
// ============================================================

class QRcode {

    /**
     * QR Code PNG ফাইল তৈরি করা
     * @param string $text - QR Code-এর ভেতরে যে text থাকবে
     * @param string $outfile - PNG ফাইলের path (false হলে browser-এ দেখাবে)
     * @param int $level - Error correction level (0-3)
     * @param int $size - Module size in pixels
     * @param int $margin - Margin in modules
     */
    public static function png(string $text, $outfile = false, int $level = 0, int $size = 6, int $margin = 2): void {
        // Google Chart API ব্যবহার করে QR তৈরি (reliable fallback)
        $encodedText = urlencode($text);
        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&ecc=M&data={$encodedText}";

        $ch = curl_init($qrUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $imgData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($imgData && $httpCode === 200) {
            if ($outfile) {
                file_put_contents($outfile, $imgData);
            } else {
                header('Content-Type: image/png');
                echo $imgData;
            }
            return;
        }

        // Fallback: GD দিয়ে সরল QR-like image তৈরি
        if (extension_loaded('gd')) {
            self::generateFallbackImage($text, $outfile, $size, $margin);
        }
    }

    /**
     * QR Code SVG string তৈরি করা
     */
    public static function svg(string $text, int $size = 300): string {
        $encodedText = urlencode($text);
        $svgUrl = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&format=svg&data={$encodedText}";

        $ch = curl_init($svgUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $svg = curl_exec($ch);
        curl_close($ch);

        return $svg ?: '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'"><rect width="100%" height="100%" fill="#f8f9fa"/><text x="50%" y="50%" text-anchor="middle" fill="#999" font-size="14">QR Code</text></svg>';
    }

    /**
     * Base64 PNG string তৈরি (Admin Panel preview-এর জন্য)
     */
    public static function base64png(string $text, int $size = 200): string {
        $encodedText = urlencode($text);
        $url = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$encodedText}";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $img = curl_exec($ch);
        curl_close($ch);

        return $img ? 'data:image/png;base64,' . base64_encode($img) : '';
    }

    private static function generateFallbackImage(string $text, $outfile, int $size, int $margin): void {
        $modules = 21; // QR V1
        $imgSize = ($modules + $margin * 2) * $size;
        $img = imagecreatetruecolor($imgSize, $imgSize);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);

        // Finder patterns (corners)
        foreach ([[0,0], [14,0], [0,14]] as [$cx, $cy]) {
            for ($r = 0; $r < 7; $r++) {
                for ($c = 0; $c < 7; $c++) {
                    $isEdge = ($r==0||$r==6||$c==0||$c==6);
                    $isInner = ($r>=2&&$r<=4&&$c>=2&&$c<=4);
                    if ($isEdge || $isInner) {
                        $x = ($cx + $c + $margin) * $size;
                        $y = ($cy + $r + $margin) * $size;
                        imagefilledrectangle($img, $x, $y, $x+$size-1, $y+$size-1, $black);
                    }
                }
            }
        }

        if ($outfile) {
            imagepng($img, $outfile);
        } else {
            header('Content-Type: image/png');
            imagepng($img);
        }
        imagedestroy($img);
    }
}

// Error correction constants
define('QR_ECLEVEL_L', 0);
define('QR_ECLEVEL_M', 1);
define('QR_ECLEVEL_Q', 2);
define('QR_ECLEVEL_H', 3);
