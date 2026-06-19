<?php
// employee_bottom_nav.php — Shared mobile bottom nav for all Employee pages
// Set $active_page before including
if (!isset($active_page)) $active_page = '';

$items = [
    ['page' => 'dashboard', 'href' => 'employee_dashboard.php', 'icon' => '◈', 'label' => 'Dashboard'],
    ['page' => 'leave',     'href' => 'leave.php',              'icon' => '📅', 'label' => 'Leave'],
    ['page' => 'rewards',   'href' => 'rewards_store.php',      'icon' => '🎁', 'label' => 'Rewards'],
    ['page' => 'history',   'href' => 'points_history.php',     'icon' => '📊', 'label' => 'Points'],
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
