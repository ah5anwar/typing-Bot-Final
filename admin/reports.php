<?php
// ============================================================
// admin/reports.php - রিপোর্ট ও Analytics
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/security.php';
Security::requireAdminAuth();
Security::setSecurityHeaders();
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in'])) { header('Location: /admin/'); exit; }

$db = Database::getInstance();

// Stats
$stats = [
    'total_customers'  => $db->fetchOne("SELECT COUNT(*) as c FROM customers")['c'],
    'total_apps'       => $db->fetchOne("SELECT COUNT(*) as c FROM applications")['c'],
    'total_income'     => $db->fetchOne("SELECT COALESCE(SUM(paid_amount),0) as c FROM payments")['c'],
    'total_due'        => $db->fetchOne("SELECT COALESCE(SUM(balance),0) as c FROM payments WHERE balance>0")['c'],
    'telegram_users'   => $db->fetchOne("SELECT COUNT(*) as c FROM customers WHERE platform='telegram'")['c'],
    'whatsapp_users'   => $db->fetchOne("SELECT COUNT(*) as c FROM customers WHERE platform='whatsapp'")['c'],
    'messenger_users'  => $db->fetchOne("SELECT COUNT(*) as c FROM customers WHERE platform='messenger'")['c'],
];

// Monthly customers (last 6 months)
$monthlyData = $db->fetchAll(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count
     FROM customers GROUP BY month ORDER BY month DESC LIMIT 6"
);

// Status distribution
$statusDist = $db->fetchAll(
    "SELECT st.name, st.color, COUNT(a.id) as count
     FROM application_statuses st
     LEFT JOIN applications a ON a.status_id = st.id
     GROUP BY st.id ORDER BY st.order_no"
);

// Top services
$topServices = $db->fetchAll(
    "SELECT s.name, COUNT(a.id) as count, COALESCE(SUM(p.total_amount),0) as revenue
     FROM services s
     LEFT JOIN applications a ON a.service_id = s.id
     LEFT JOIN payments p ON p.application_id = a.id
     GROUP BY s.id ORDER BY count DESC LIMIT 10"
);

// Monthly revenue
$monthlyRevenue = $db->fetchAll(
    "SELECT DATE_FORMAT(created_at,'%Y-%m') as month, SUM(paid_amount) as revenue
     FROM payments GROUP BY month ORDER BY month DESC LIMIT 6"
);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/admin/admin.css">

</head>
<body>
<?php $currentPage = 'reports'; include __DIR__.'/sidebar.php'; ?>
<div class="main">
    <h4 class="fw-bold mb-4">📊 রিপোর্ট ও Analytics</h4>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <?php
        $cards = [
            ['মোট গ্রাহক', number_format($stats['total_customers']), '#1a3c6e', 'fa-users'],
            ['মোট আবেদন', number_format($stats['total_apps']), '#2e86c1', 'fa-file-alt'],
            ['মোট আয়', number_format($stats['total_income'], 2), '#27ae60', 'fa-coins'],
            ['মোট বকেয়া', number_format($stats['total_due'], 2), '#e74c3c', 'fa-exclamation-circle'],
        ];
        foreach ($cards as $c): ?>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:50px;height:50px;border-radius:12px;background:<?= $c[2] ?>20;display:flex;align-items:center;justify-content:center">
                        <i class="fa <?= $c[3] ?>" style="color:<?= $c[2] ?>;font-size:1.3rem"></i>
                    </div>
                    <div><div class="fw-bold fs-4"><?= $c[1] ?></div><div class="text-muted small"><?= $c[0] ?></div></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Platform Distribution -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card text-center">
                <div style="font-size:2rem">🤖</div>
                <div class="fw-bold fs-3"><?= $stats['telegram_users'] ?></div>
                <div class="text-muted small">Telegram গ্রাহক</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card text-center">
                <div style="font-size:2rem">💚</div>
                <div class="fw-bold fs-3"><?= $stats['whatsapp_users'] ?></div>
                <div class="text-muted small">WhatsApp গ্রাহক</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card text-center">
                <div style="font-size:2rem">💙</div>
                <div class="fw-bold fs-3"><?= $stats['messenger_users'] ?></div>
                <div class="text-muted small">Messenger গ্রাহক</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card text-center">
                <div style="font-size:2rem">📄</div>
                <div class="fw-bold fs-3"><?= $db->fetchOne("SELECT COUNT(*) as c FROM documents")['c'] ?></div>
                <div class="text-muted small">মোট ডকুমেন্ট</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <!-- Monthly Customers Chart -->
        <div class="col-md-6">
            <div class="chart-card">
                <h6 class="fw-bold mb-3">📈 মাসিক নতুন গ্রাহক</h6>
                <canvas id="customerChart" height="200"></canvas>
            </div>
        </div>
        <!-- Monthly Revenue Chart -->
        <div class="col-md-6">
            <div class="chart-card">
                <h6 class="fw-bold mb-3">💰 মাসিক আয়</h6>
                <canvas id="revenueChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <!-- Status Distribution -->
        <div class="col-md-5">
            <div class="chart-card">
                <h6 class="fw-bold mb-3">📊 আবেদনের অবস্থা</h6>
                <canvas id="statusChart" height="250"></canvas>
            </div>
        </div>
        <!-- Top Services -->
        <div class="col-md-7">
            <div class="chart-card">
                <h6 class="fw-bold mb-3">🏆 সেরা সেবা (আবেদন সংখ্যা)</h6>
                <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>সেবা</th><th>আবেদন</th><th>আয়</th></tr></thead>
                    <tbody>
                    <?php foreach ($topServices as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['name']) ?></td>
                        <td><span class="badge bg-primary"><?= $s['count'] ?></span></td>
                        <td><?= number_format($s['revenue'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Applications -->
    <div class="chart-card">
        <div class="d-flex justify-content-between mb-3">
            <h6 class="fw-bold">📋 সর্বশেষ আবেদন</h6>
            <a href="/admin/?page=applications" class="btn btn-sm btn-outline-primary">সব দেখুন</a>
        </div>
        <div class="table-responsive">
        <table class="table table-hover table-sm">
            <thead><tr><th>ট্র্যাকিং</th><th>গ্রাহক</th><th>সেবা</th><th>অবস্থা</th><th>তারিখ</th></tr></thead>
            <tbody>
            <?php
            $recentApps = $db->fetchAll(
                "SELECT a.*, c.name, s.name as sname, st.name as stname, st.color
                 FROM applications a
                 LEFT JOIN customers c ON a.customer_id=c.id
                 LEFT JOIN services s ON a.service_id=s.id
                 LEFT JOIN application_statuses st ON a.status_id=st.id
                 ORDER BY a.created_at DESC LIMIT 15"
            );
            foreach ($recentApps as $a): ?>
            <tr>
                <td><code><?= htmlspecialchars($a['tracking_id']) ?></code></td>
                <td><?= htmlspecialchars($a['name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($a['sname'] ?? '—') ?></td>
                <td><span class="badge" style="background:<?= htmlspecialchars($a['color'] ?? '#ccc') ?>"><?= htmlspecialchars($a['stname'] ?? '—') ?></span></td>
                <td><small><?= date('d/m/Y', strtotime($a['created_at'])) ?></small></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
// Monthly Customers
const mData = <?= json_encode(array_reverse($monthlyData)) ?>;
new Chart(document.getElementById('customerChart'), {
    type: 'bar',
    data: {
        labels: mData.map(d => d.month),
        datasets: [{ label: 'গ্রাহক', data: mData.map(d => d.count), backgroundColor: '#2e86c1', borderRadius: 6 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});

// Monthly Revenue
const rData = <?= json_encode(array_reverse($monthlyRevenue)) ?>;
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: rData.map(d => d.month),
        datasets: [{ label: 'আয়', data: rData.map(d => d.revenue||0), borderColor: '#27ae60', backgroundColor: '#27ae6020', fill: true, tension: 0.4 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});

// Status Donut
const sData = <?= json_encode($statusDist) ?>;
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: sData.map(d => d.name),
        datasets: [{ data: sData.map(d => d.count), backgroundColor: sData.map(d => d.color) }]
    },
    options: { plugins: { legend: { position: 'bottom' } } }
});
</script>
</body>
</html>
