<?php
// admin/sidebar.php
if (!isset($currentPage)) {
    $script = basename($_SERVER['PHP_SELF'] ?? '');
    $url    = $_SERVER['REQUEST_URI'] ?? '';
    $currentPage = match(true) {
        $script==='billing.php'        => 'billing',
        $script==='diagnostics.php'    => 'diagnostics',
        $script==='file_manager.php'   => 'file_manager',
        $script==='customers.php'      => str_contains($url,'tab=countries') ? 'countries'
                                        : (str_contains($url,'tab=ai') ? 'ai_usage'
                                        : (str_contains($url,'tab=menu') ? 'bot_menu' : 'customers')),
        $script==='documents.php'      => 'documents',
        $script==='fake_documents.php' => 'fake_documents',
        $script==='services.php'       => str_contains($url,'tab=agents') ? 'agents'
                                        : (str_contains($url,'tab=statuses') ? 'statuses'
                                        : (str_contains($url,'tab=currencies') ? 'currencies' : 'services')),
        $script==='member_cards.php'   => 'member_cards',
        $script==='news.php'           => str_contains($url,'tab=broadcast') ? 'broadcast' : 'news',
        $script==='reports.php'        => 'reports',
        str_contains($url,'page=chat')         => 'chat',
        str_contains($url,'page=applications') => 'applications',
        str_contains($url,'page=settings')     => 'settings',
        str_contains($url,'page=faqs')         => 'faqs',
        default => 'dashboard',
    };
}
$suspicious = 0;
try { $suspicious = (int)(Database::getInstance()->fetchOne("SELECT COUNT(*) as c FROM suspicious_documents WHERE admin_reviewed=0")['c'] ?? 0); } catch(Exception $e) {}

function _nl(string $url, string $icon, string $label, string $id, string $cp, int $badge=0): void {
    $a = $cp === $id ? ' active' : '';
    $b = $badge > 0 ? "<span class='sb-badge'>$badge</span>" : '';
    echo "<a href='$url' class='sb-link$a'><i class='fas $icon'></i><span>$label</span>$b</a>\n";
}
$cp = $currentPage;
?>
<style>
/* ── Sidebar ONLY styles ─────────────────── */
.sb{position:fixed;top:0;left:0;width:220px;height:100vh;
    background:#0F172A;background:linear-gradient(180deg,#0F172A 0%,#1E1B4B 100%);
    display:flex;flex-direction:column;overflow-y:auto;overflow-x:hidden;
    z-index:1040;font-family:'Hind Siliguri','Segoe UI',sans-serif;}
.sb::-webkit-scrollbar{width:3px;}
.sb::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);}

/* Brand */
.sb-brand{padding:18px 14px 14px;border-bottom:1px solid rgba(255,255,255,.06);flex-shrink:0;}
.sb-logo{display:flex;align-items:center;gap:8px;color:#fff;font-size:.95rem;font-weight:700;}
.sb-icon{width:32px;height:32px;border-radius:9px;
  background:linear-gradient(135deg,#6366F1,#06B6D4);
  display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;}
.sb-sub{font-size:.68rem;color:rgba(255,255,255,.3);margin-top:2px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

/* Section */
.sb-section{font-size:.58rem;font-weight:700;letter-spacing:.13em;text-transform:uppercase;
  color:rgba(255,255,255,.2);padding:12px 14px 3px;display:block;}

/* Links */
.sb-link{display:flex!important;align-items:center;gap:8px;
  padding:8px 14px;color:rgba(255,255,255,.55)!important;
  text-decoration:none!important;font-size:.83rem;
  transition:background .12s,color .12s;position:relative;
  white-space:nowrap;line-height:1.35;}
.sb-link i{width:15px;text-align:center;font-size:.78rem;flex-shrink:0;opacity:.7;}
.sb-link span{flex:1;}
.sb-link:hover{background:rgba(255,255,255,.06)!important;color:rgba(255,255,255,.9)!important;}
.sb-link.active{background:rgba(99,102,241,.7)!important;color:#fff!important;font-weight:600;}
.sb-link.active::before{content:'';position:absolute;left:0;top:0;bottom:0;
  width:3px;background:#06B6D4;border-radius:0 2px 2px 0;}
.sb-link.logout{color:#FCA5A5!important;}
.sb-link.logout:hover{background:rgba(239,68,68,.1)!important;}
.sb-hr{border:none;border-top:1px solid rgba(255,255,255,.06);margin:4px 14px;}
.sb-badge{background:#EF4444;color:#fff;font-size:.6rem;font-weight:700;
  padding:1px 6px;border-radius:10px;margin-left:auto;}

/* Page wrapper — push content past sidebar */
.main{margin-left:220px;padding:24px 28px;min-height:100vh;background:#F8FAFF;}
</style>

<div class="sb">
  <div class="sb-brand">
    <div class="sb-logo">
      <div class="sb-icon">🤖</div>
      <span>Bot Admin</span>
    </div>
    <div class="sb-sub"><?= htmlspecialchars(Config::get('company_name','আপনার কোম্পানির নাম')) ?></div>
  </div>
  <nav style="flex:1;padding:6px 0;">
    <span class="sb-section">মূল</span>
    <?php
    _nl('/admin/',                            'fa-chart-pie',          'ড্যাশবোর্ড',       'dashboard',    $cp);
    _nl('/admin/?page=chat',                  'fa-comments',           'Live Chat',          'chat',         $cp);
    _nl('/admin/?page=applications',          'fa-clipboard-list',     'আবেদন সমূহ',        'applications', $cp);
    _nl('/admin/billing.php',                 'fa-money-bill-wave',    'বিল ম্যানেজমেন্ট',   'billing',      $cp);
    _nl('/admin/diagnostics.php',             'fa-stethoscope',        'Diagnostics',        'diagnostics',  $cp);
    ?>
    <span class="sb-section">গ্রাহক</span>
    <?php
    _nl('/admin/customers.php',               'fa-users',              'গ্রাহক তালিকা',      'customers',    $cp);
    _nl('/admin/customers.php?tab=countries', 'fa-globe',              'দেশ',                'countries',    $cp);
    _nl('/admin/member_cards.php',            'fa-id-card',            'Member Cards',       'member_cards', $cp);
    ?>
    <span class="sb-section">ডকুমেন্ট</span>
    <?php
    _nl('/admin/documents.php',               'fa-file-alt',           'ডকুমেন্ট',           'documents',    $cp);
    _nl('/admin/fake_documents.php',          'fa-exclamation-triangle','সন্দেহজনক ডকু.',     'fake_documents',$cp,$suspicious);
    _nl('/admin/file_manager.php',            'fa-folder-open',        'File Manager',       'file_manager', $cp);
    ?>
    <span class="sb-section">সেবা</span>
    <?php
    _nl('/admin/services.php',                'fa-concierge-bell',     'সেবা ও ক্যাটাগরি',  'services',     $cp);
    _nl('/admin/services.php?tab=agents',     'fa-user-tie',           'এজেন্ট',             'agents',       $cp);
    _nl('/admin/services.php?tab=statuses',   'fa-tasks',              'স্ট্যাটাস',          'statuses',     $cp);
    _nl('/admin/services.php?tab=currencies', 'fa-coins',              'মুদ্রা',             'currencies',   $cp);
    _nl('/admin/?page=faqs',                  'fa-question-circle',    'FAQ',                'faqs',         $cp);
    ?>
    <span class="sb-section">কনফিগ</span>
    <?php
    _nl('/admin/news.php',                    'fa-newspaper',          'Embassy News',       'news',         $cp);
    _nl('/admin/news.php?tab=broadcast',      'fa-bullhorn',           'Broadcast',          'broadcast',    $cp);
    _nl('/admin/reports.php',                 'fa-chart-bar',          'রিপোর্ট',            'reports',      $cp);
    _nl('/admin/customers.php?tab=ai',        'fa-robot',              'AI Usage',           'ai_usage',     $cp);
    _nl('/admin/customers.php?tab=menu',      'fa-bars',               'Bot মেনু',           'bot_menu',     $cp);
    _nl('/admin/?page=settings',              'fa-cog',                'সেটিংস',             'settings',     $cp);
    ?>
    <div class="sb-hr"></div>
    <a href="/admin/?logout=1" onclick="return confirm('লগআউট করবেন?')" class="sb-link logout">
      <i class="fas fa-sign-out-alt"></i><span>লগআউট</span>
    </a>
  </nav>
</div>
