<?php
// employee_sidebar.php — Reusable sidebar for all employee pages
// Set $active_page before including: 'dashboard', 'leave', or 'rewards'
if (!isset($active_page)) $active_page = '';

$nav_items = [
    ['page' => 'dashboard', 'href' => 'employee_dashboard.php', 'icon' => '◈', 'label' => 'Dashboard'],
    ['page' => 'leave',     'href' => 'leave.php',             'icon' => '📅', 'label' => 'Leave & Calendar'],
    ['page' => 'rewards',   'href' => 'rewards_store.php',      'icon' => '🎁', 'label' => 'Rewards Store'],
];
?>
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="logo-icon">🌿</div>
    LeafPoint
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <?php foreach ($nav_items as $item): ?>
    <a href="<?= $item['href'] ?>" class="<?= $active_page === $item['page'] ? 'active' : '' ?>">
      <span class="nav-icon"><?= $item['icon'] ?></span> <?= $item['label'] ?>
    </a>
    <?php endforeach; ?>
    <div class="nav-section">Account</div>
    <a href="../logout.php"><span class="nav-icon">🚪</span> Logout</a>
  </nav>
  <div class="sidebar-user">
    <div class="avatar"><?= strtoupper(substr($_SESSION['name'], 0, 1)) ?></div>
    <div style="display:flex;flex-direction:column;gap:1px;">
      <div class="user-name"><?= htmlspecialchars($_SESSION['name']) ?></div>
      <div class="user-role">Employee</div>
    </div>
  </div>
</aside>
