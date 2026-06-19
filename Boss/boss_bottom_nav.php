<?php
// boss_bottom_nav.php — Shared mobile bottom nav for all Boss pages
// Set $active_page before including
if (!isset($active_page)) $active_page = '';

$items = [
    ['page' => 'approvals', 'href' => 'admin_dashboard.php', 'icon' => '📋', 'label' => 'Approvals'],
    ['page' => 'calendar',  'href' => 'master_calendar.php',  'icon' => '📅', 'label' => 'Calendar'],
    ['page' => 'store',     'href' => 'store_admin.php',      'icon' => '🎁', 'label' => 'Store'],
    ['page' => 'analytics', 'href' => 'analytics.php',         'icon' => '📊', 'label' => 'Analytics'],
];
?>
<nav class="bottom-nav">
  <?php foreach ($items as $item): ?>
  <a href="<?= $item['href'] ?>" class="<?= $active_page === $item['page'] ? 'active' : '' ?>">
    <span class="nav-icon"><?= $item['icon'] ?></span><?= $item['label'] ?>
  </a>
  <?php endforeach; ?>
  <a href="../logout.php"><span class="nav-icon">🚪</span>Logout</a>
</nav>
