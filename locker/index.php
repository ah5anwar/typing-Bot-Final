<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ডিজিটাল লকার - নিরাপদ ডকুমেন্ট</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
body { background: linear-gradient(135deg, #1a3c6e, #2e86c1); min-height: 100vh; display: flex; align-items: center; }
.locker-card { border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
</style>
</head>
<body>
<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/services/DigitalLockerService.php';

$files    = null;
$error    = null;
$customer = null;
$searched = false;

if ($_POST['mobile'] ?? false) {
    $searched = true;
    $mobile   = trim($_POST['mobile']);
    $password = trim($_POST['password']);
    $db       = Database::getInstance();

    $customer = $db->fetchOne(
        "SELECT * FROM customers WHERE mobile=? AND onboarding_done=1 LIMIT 1",
        [$mobile]
    );

    if (!$customer) {
        $error = 'এই মোবাইল নম্বরে কোনো গ্রাহক পাওয়া যায়নি।';
    } else {
        $locker = new DigitalLockerService();
        $files  = $locker->getFiles($customer, $password);
        if ($files === null) {
            $error = 'ভুল পাসওয়ার্ড! আবার চেষ্টা করুন।';
        }
    }
}
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="locker-card bg-white p-5">
                <div class="text-center mb-4">
                    <div style="font-size:3rem">🔒</div>
                    <h4 class="fw-bold" style="color:#1a3c6e">ডিজিটাল লকার</h4>
                    <p class="text-muted small">আপনার নিরাপদ ডকুমেন্ট সংগ্রহ</p>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-danger">❌ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($files !== null && $customer): ?>
                <div class="alert alert-success mb-3">
                    ✅ স্বাগতম, <strong><?= htmlspecialchars($customer['name'] ?? 'গ্রাহক') ?></strong>!
                </div>
                <?php if (empty($files)): ?>
                <p class="text-center text-muted">আপনার লকারে কোনো ফাইল নেই।</p>
                <?php else: ?>
                <h6 class="fw-bold mb-3">📁 আপনার ফাইলসমূহ (<?= count($files) ?>টি)</h6>
                <?php foreach ($files as $i => $f): ?>
                <div class="d-flex align-items-center gap-3 p-3 mb-2 rounded-3" style="background:#f8f9fa">
                    <div style="font-size:1.5rem">📄</div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold small"><?= htmlspecialchars($f['file_name']) ?></div>
                        <div class="text-muted" style="font-size:0.7rem"><?= date('d/m/Y H:i', strtotime($f['created_at'])) ?></div>
                    </div>
                    <a href="<?= htmlspecialchars($f['drive_url']) ?>" target="_blank" class="btn btn-sm btn-primary">
                        🔗 খুলুন
                    </a>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                <div class="mt-3 text-center">
                    <a href="?" class="btn btn-outline-secondary btn-sm">🔓 লগআউট</a>
                </div>

                <?php else: ?>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">মোবাইল নম্বর</label>
                        <input name="mobile" class="form-control form-control-lg" placeholder="+880 1X XXXX XXXX"
                               value="<?= htmlspecialchars($_POST['mobile'] ?? '') ?>" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">লকার পাসওয়ার্ড</label>
                        <input name="password" type="password" class="form-control form-control-lg" placeholder="আপনার পাসওয়ার্ড" required>
                    </div>
                    <button class="btn btn-primary w-100 btn-lg">🔓 লকার খুলুন</button>
                </form>
                <p class="text-center text-muted small mt-3">
                    লকার পাসওয়ার্ড সেট করতে বটে "locker" লিখুন।
                </p>
                <?php endif; ?>
            </div>

            <p class="text-center text-white-50 small mt-3">
                <?= htmlspecialchars(Config::get('company_name', 'ভিসা সার্ভিস')) ?> - সুরক্ষিত ডকুমেন্ট সেবা
            </p>
        </div>
    </div>
</div>
</body>
</html>
