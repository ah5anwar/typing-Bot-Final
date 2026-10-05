<?php
// admin/test_gemini.php - Gemini API diagnostics
// ⚠️ সেটআপের পরে এই ফাইল মুছুন!
if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__).'/config/config.php';

if (empty($_SESSION['admin_logged_in'])) {
    die('<h2>Admin login required</h2><a href="/admin/">Login</a>');
}

$key = Config::get('gemini_api_key', '');
$results = [];

$models = [
    'gemini-2.5-flash-preview-04-17',
    'gemini-2.5-pro-preview-05-06',
    'gemini-2.0-flash',
    'gemini-2.0-flash-lite',
    'gemini-1.5-flash',
    'gemini-1.5-pro',
];

if ($_POST['test'] ?? false) {
    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'contents'           => [['role' => 'user', 'parts' => [['text' => 'Say OK']]]],
                'system_instruction' => ['parts' => [['text' => 'Reply with just: OK']]],
                'generationConfig'   => ['maxOutputTokens' => 10],
            ]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $data  = json_decode($resp, true);
        $text  = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        $error = $data['error']['message'] ?? $err ?? null;

        $results[$model] = [
            'code'  => $code,
            'ok'    => $code === 200 && $text,
            'text'  => $text,
            'error' => $error,
        ];
    }
}
?>
<!DOCTYPE html><html lang="bn"><head><meta charset="UTF-8"><title>Gemini Test</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head><body class="p-4 bg-light">
<div class="container" style="max-width:800px">
<h4 class="fw-bold mb-3">🔬 Gemini API Diagnostic</h4>
<div class="alert alert-info">API Key: <code><?= substr($key,0,8).'...' ?></code> (<?= $key ? 'সেট আছে' : '❌ নেই' ?>)</div>

<form method="POST">
<button class="btn btn-primary mb-3">▶ সব মডেল টেস্ট করুন</button>
</form>

<?php if ($results): ?>
<table class="table table-bordered">
<thead><tr><th>Model</th><th>Status</th><th>Response / Error</th></tr></thead>
<tbody>
<?php foreach ($results as $model => $r): ?>
<tr class="<?= $r['ok'] ? 'table-success' : 'table-danger' ?>">
    <td><code><?= $model ?></code></td>
    <td>HTTP <?= $r['code'] ?> <?= $r['ok'] ? '✅' : '❌' ?></td>
    <td><?= htmlspecialchars($r['text'] ?? $r['error'] ?? '—') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<a href="/admin/" class="btn btn-secondary">← Admin</a>
<br><br><div class="alert alert-warning">⚠️ সেটআপের পরে এই ফাইল মুছুন!</div>
</div></body></html>
