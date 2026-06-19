<?php
// boss_bottom_nav.php — Shared mobile bottom nav for all Boss pages
// Set $active_page before including
if (!isset($active_page)) $active_page = '';

$main_items = [
    ['page' => 'approvals', 'href' => 'admin_dashboard.php', 'icon' => '📋', 'label' => 'Approvals'],
    ['page' => 'calendar',  'href' => 'master_calendar.php',  'icon' => '📅', 'label' => 'Calendar'],
    ['page' => 'store',     'href' => 'store_admin.php',      'icon' => '🎁', 'label' => 'Store'],
    ['page' => 'analytics', 'href' => 'analytics.php',         'icon' => '📊', 'label' => 'Analytics'],
];

$more_items = [
    ['page' => 'holidays',  'href' => 'public_holidays.php',   'icon' => '🎌', 'label' => 'Holidays'],
    ['page' => 'settings',  'href' => 'settings.php',          'icon' => '⚙️', 'label' => 'Settings'],
];

$more_active = in_array($active_page, ['holidays', 'settings']);
?>
<nav class="bottom-nav" id="bottom-nav">
  <?php foreach ($main_items as $item): ?>
  <a href="<?= $item['href'] ?>" class="<?= $active_page === $item['page'] ? 'active' : '' ?>">
    <span class="nav-icon"><?= $item['icon'] ?></span><?= $item['label'] ?>
  </a>
  <?php endforeach; ?>
  <a href="#more" class="more-toggle <?= $more_active ? 'active' : '' ?>" id="more-toggle" onclick="toggleMore(event)">
    <span class="nav-icon"><?= $more_active ? '🔽' : '⋯' ?></span>More
  </a>
</nav>

<!-- More menu popup -->
<div class="more-menu" id="more-menu">
  <?php foreach ($more_items as $item): ?>
  <a href="<?= $item['href'] ?>" class="<?= $active_page === $item['page'] ? 'active' : '' ?>">
    <span class="nav-icon"><?= $item['icon'] ?></span><?= $item['label'] ?>
  </a>
  <?php endforeach; ?>
  <a href="../logout.php"><span class="nav-icon">🚪</span>Logout</a>
</div>
<div class="more-backdrop" id="more-backdrop" onclick="toggleMore()"></div>

<style>
  .more-toggle { cursor: pointer; }
  .more-menu {
    display: none; position: fixed; bottom: 72px; right: 8px; z-index: 35;
    background: oklch(98% 0.003 250 / 0.96);
    backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px);
    border: 1px solid rgba(0,0,0,0.08); border-radius: 14px;
    padding: 6px; min-width: 160px;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
    flex-direction: column; gap: 2px;
  }
  .more-menu.open { display: flex; }
  .more-menu a {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 14px; border-radius: 10px;
    font: 13px/1.4 var(--font-body, system-ui); color: var(--fg-secondary, #555);
    text-decoration: none; font-weight: 600; transition: background 0.15s;
  }
  .more-menu a:hover { background: rgba(0,0,0,0.04); }
  .more-menu a.active { color: var(--accent, oklch(56% 0.19 148)); font-weight: 700; }
  .more-backdrop { display: none; position: fixed; inset: 0; z-index: 34; background: rgba(0,0,0,0.2); }
  .more-backdrop.open { display: block; }
</style>

<script>
  (function() {
    if (document.getElementById('more-menu-setup')) return;
    var s = document.createElement('span'); s.id = 'more-menu-setup'; s.style.display = 'none';
    document.body.appendChild(s);
    window.toggleMore = function(e) { if (e) e.preventDefault();
      var m = document.getElementById('more-menu');
      var b = document.getElementById('more-backdrop');
      var t = document.getElementById('more-toggle');
      var open = m.classList.contains('open');
      m.classList.toggle('open', !open);
      b.classList.toggle('open', !open);
      if (!open) { t.querySelector('.nav-icon').textContent = '🔽'; }
      else { t.querySelector('.nav-icon').textContent = '⋯'; }
    };
    document.getElementById('more-backdrop').addEventListener('click', function() {
      document.getElementById('more-menu').classList.remove('open');
      this.classList.remove('open');
      var t = document.getElementById('more-toggle');
      if (t) t.querySelector('.nav-icon').textContent = '⋯';
    });
  })();
</script>
