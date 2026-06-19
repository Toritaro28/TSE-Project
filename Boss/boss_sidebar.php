<?php
// boss_sidebar.php — Reusable sidebar for all Boss/Admin pages
// Set $active_page before including: 'approvals', 'calendar', or 'store'
if (!isset($active_page)) $active_page = '';

$boss_name = $_SESSION['name'] ?? 'Boss';
$boss_initial = strtoupper(substr($boss_name, 0, 1));

$nav_items = [
    ['page' => 'approvals', 'href' => 'admin_dashboard.php', 'icon' => '📋', 'label' => 'Leave Approvals'],
    ['page' => 'calendar',  'href' => 'master_calendar.php',  'icon' => '📅', 'label' => 'Master Calendar'],
    ['page' => 'store',     'href' => 'store_admin.php',      'icon' => '🎁', 'label' => 'Store Manager'],
    ['page' => 'analytics', 'href' => 'analytics.php',         'icon' => '📊', 'label' => 'Analytics'],
    ['page' => 'holidays',  'href' => 'public_holidays.php',   'icon' => '🎌', 'label' => 'Public Holidays'],
];
?>
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="logo-icon">🌿</div>
    LeafPoint
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Admin</div>
    <?php foreach ($nav_items as $item): ?>
    <a href="<?= $item['href'] ?>" class="<?= $active_page === $item['page'] ? 'active' : '' ?>">
      <span class="nav-icon"><?= $item['icon'] ?></span> <?= $item['label'] ?>
    </a>
    <?php endforeach; ?>
    <div class="nav-section">Account</div>
    <a href="../logout.php"><span class="nav-icon">🚪</span> Logout</a>
  </nav>
  <div class="sidebar-user">
    <div class="avatar"><?= $boss_initial ?></div>
    <div style="display:flex;flex-direction:column;gap:1px;">
      <div class="user-name"><?= htmlspecialchars($boss_name) ?></div>
      <div class="user-role">Administrator</div>
    </div>
  </div>
</aside>
