<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit();
}

$active_page = 'history';
$user_id = $_SESSION['user_id'];

// Filter
$filter = $_GET['filter'] ?? 'all';

// Fetch transactions
$sql = "SELECT * FROM point_transactions WHERE user_id = ?";
$params = [$user_id];

if ($filter === 'earned') {
    $sql .= " AND amount > 0";
} elseif ($filter === 'spent') {
    $sql .= " AND amount < 0";
}

$sql .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Compute running balance (from oldest to newest)
$balance = 0;
$running = [];
foreach (array_reverse($transactions) as $t) {
    $balance += $t['amount'];
    $running[$t['id']] = $balance;
}

// Count by type
$earned_count = 0;
$spent_count = 0;
foreach ($transactions as $t) {
    if ($t['amount'] > 0) $earned_count++;
    else $spent_count++;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Points History — LeafPoint</title>
  <style>
    :root {
      --bg-deep: oklch(96% 0.006 235);
      --bg-gradient: radial-gradient(ellipse at 50% 0%, oklch(92% 0.03 170 / 0.35), oklch(97% 0.004 245) 55%);
      --surface-glass: rgba(255, 255, 255, 0.52);
      --surface-glass-hover: rgba(255, 255, 255, 0.72);
      --surface-solid: #ffffff;
      --fg: oklch(16% 0.016 252); --fg-secondary: oklch(38% 0.02 250); --muted: oklch(55% 0.014 250);
      --border-glass: rgba(255, 255, 255, 0.38); --border-subtle: rgba(0, 0, 0, 0.055);
      --accent: oklch(56% 0.18 148); --accent-soft: oklch(72% 0.13 148); --accent-glow: oklch(62% 0.2 148 / 0.4);
      --gold: oklch(70% 0.18 82);
      --green-status: oklch(60% 0.18 145); --red-status: oklch(53% 0.22 22);
      --sidebar-bg: oklch(13% 0.02 252); --sidebar-fg: oklch(84% 0.006 250); --sidebar-muted: oklch(60% 0.016 250);
      --radius-sm: 10px; --radius-md: 16px; --radius-lg: 22px;
      --shadow-card: 0 2px 20px rgba(0,0,0,0.045), 0 0 0 1px rgba(0,0,0,0.035);
      --shadow-card-hover: 0 8px 38px rgba(0,0,0,0.08), 0 0 0 1px rgba(0,0,0,0.05);
      --font-display: -apple-system, BlinkMacSystemFont, 'SF Pro Display', system-ui, sans-serif;
      --font-body: -apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, sans-serif;
      --font-mono: 'SF Mono', ui-monospace, 'JetBrains Mono', Menlo, monospace;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { width: 100%; height: 100%; font-family: var(--font-body); color: var(--fg); background: var(--bg-deep); -webkit-font-smoothing: antialiased; overflow: hidden; }
    .app { display: flex; height: 100vh; width: 100%; }

    .sidebar { width: 250px; min-width: 250px; height: 100%; background: var(--sidebar-bg); color: var(--sidebar-fg); display: flex; flex-direction: column; padding: 26px 18px 18px; gap: 5px; z-index: 10; border-right: 1px solid rgba(255,255,255,0.06); }
    @media (max-width: 800px) { .sidebar { width: 210px; min-width: 210px; } }
    .sidebar-brand { display: flex; align-items: center; gap: 11px; padding: 0 8px 24px; font-family: var(--font-display); font-size: 19px; font-weight: 700; letter-spacing: -0.02em; color: #fff; }
    .sidebar-brand .logo-icon { width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, var(--accent), oklch(46% 0.15 158)); display: grid; place-items: center; font-size: 20px; }
    .sidebar-nav { display: flex; flex-direction: column; gap: 2px; flex: 1; }
    .sidebar-nav .nav-section { font-size: 10px; text-transform: uppercase; letter-spacing: 0.09em; color: var(--sidebar-muted); padding: 14px 10px 5px; font-weight: 600; }
    .sidebar-nav a { display: flex; align-items: center; gap: 9px; width: 100%; padding: 10px 10px; border: none; border-radius: 9px; background: transparent; color: var(--sidebar-fg); font: 13px/1.4 var(--font-body); cursor: pointer; transition: background 0.15s; text-align: left; letter-spacing: -0.01em; text-decoration: none; }
    .sidebar-nav a:hover { background: rgba(255,255,255,0.055); }
    .sidebar-nav a.active { background: rgba(255,255,255,0.1); color: #fff; font-weight: 600; }
    .sidebar-nav a .nav-icon { font-size: 16px; width: 22px; text-align: center; flex-shrink: 0; }
    .sidebar-user { display: flex; align-items: center; gap: 10px; padding: 12px 8px; border-top: 1px solid rgba(255,255,255,0.07); margin-top: auto; }
    .sidebar-user .avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--accent), oklch(48% 0.13 165)); display: grid; place-items: center; font-size: 14px; font-weight: 700; color: #fff; flex-shrink: 0; }
    .sidebar-user .user-name { font-size: 13px; font-weight: 600; color: #fff; }
    .sidebar-user .user-role { font-size: 10px; color: var(--sidebar-muted); }

    .main { flex: 1; overflow-y: auto; overflow-x: hidden; background: var(--bg-gradient); display: flex; flex-direction: column; }
    .main-inner { padding: 24px 30px 36px; display: flex; flex-direction: column; gap: 20px; max-width: 1000px; width: 100%; }

    .topbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .topbar .title { font-family: var(--font-display); font-size: 20px; font-weight: 700; letter-spacing: -0.02em; }

    .card { background: var(--surface-glass); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border: 1px solid var(--border-glass); border-radius: var(--radius-lg); box-shadow: var(--shadow-card); padding: 22px 24px; display: flex; flex-direction: column; gap: 14px; }
    .card-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .card-title { font-family: var(--font-display); font-size: 15px; font-weight: 700; letter-spacing: -0.01em; }

    /* Filter chips */
    .filter-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .filter-chip { padding: 8px 16px; border-radius: 999px; border: 1.5px solid var(--border-subtle); background: transparent; color: var(--fg-secondary); font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; font-family: var(--font-body); text-decoration: none; }
    .filter-chip:hover { border-color: var(--accent-soft); color: var(--accent); }
    .filter-chip.active { background: var(--accent); color: #fff; border-color: var(--accent); box-shadow: 0 2px 10px oklch(56% 0.19 148 / 0.3); }

    /* Table */
    .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    table thead th { text-align: left; padding: 12px 14px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); font-weight: 700; border-bottom: 1px solid var(--border-subtle); white-space: nowrap; background: oklch(98% 0.002 250 / 0.4); }
    table tbody td { padding: 14px; border-bottom: 1px solid oklch(94% 0.003 250); white-space: nowrap; }
    table tbody tr { transition: background 0.15s; }
    table tbody tr:hover { background: rgba(0,0,0,0.018); }

    .amount { font-family: var(--font-mono); font-weight: 700; font-size: 14px; }
    .amount.positive { color: var(--green-status); }
    .amount.negative { color: var(--red-status); }
    .balance-col { font-family: var(--font-mono); font-weight: 600; font-size: 13px; color: var(--fg-secondary); }
    .desc { font-size: 13px; color: var(--fg); }
    .date-col { font-size: 12px; color: var(--muted); }

    .empty-state { text-align: center; padding: 40px; color: var(--muted); }
    .empty-state .empty-icon { font-size: 40px; margin-bottom: 8px; }
    .empty-state .empty-text { font-size: 14px; font-weight: 600; }

    .bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 30; height: 64px; background: oklch(98% 0.003 250 / 0.92); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border-top: 1px solid rgba(0,0,0,0.06); align-items: center; justify-content: space-around; padding: 0 8px; padding-bottom: env(safe-area-inset-bottom, 0px); }
    .bottom-nav a { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 6px 10px; border: none; background: none; font: 10px/1 var(--font-body); color: var(--muted); cursor: pointer; transition: color 0.15s; font-weight: 500; text-decoration: none; }
    .bottom-nav a .nav-icon { font-size: 20px; line-height: 1; }
    .bottom-nav a.active { color: var(--accent); font-weight: 700; }

    @media (max-width: 800px) { .sidebar { width: 210px; min-width: 210px; } .main-inner { padding: 16px 12px 80px; } }
    @media (max-width: 660px) { .sidebar { display: none; } .bottom-nav { display: flex; } .main-inner { padding: 14px 10px 80px; } }
  </style>
</head>
<body>
  <div class="app">
    <?php include 'employee_sidebar.php'; ?>

    <main class="main">
      <div class="main-inner">
        <header class="topbar">
          <span class="title">📊 Points History</span>
        </header>

        <div class="filter-row">
          <a href="?filter=all" class="filter-chip <?= $filter === 'all' ? 'active' : '' ?>">All (<?= $earned_count + $spent_count ?>)</a>
          <a href="?filter=earned" class="filter-chip <?= $filter === 'earned' ? 'active' : '' ?>">⬆ Earned (<?= $earned_count ?>)</a>
          <a href="?filter=spent" class="filter-chip <?= $filter === 'spent' ? 'active' : '' ?>">⬇ Spent (<?= $spent_count ?>)</a>
          <span style="margin-left:auto;font-size:12px;color:var(--muted);"><?= count($transactions) ?> transaction<?= count($transactions) !== 1 ? 's' : '' ?></span>
        </div>

        <div class="card">
          <?php if (count($transactions) > 0): ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Points</th>
                  <th>Description</th>
                  <th>Balance</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($transactions as $t): ?>
                <tr>
                  <td class="date-col"><?= date('M d, Y', strtotime($t['created_at'])) ?><br><span style="font-size:10px;"><?= date('h:i A', strtotime($t['created_at'])) ?></span></td>
                  <td>
                    <span class="amount <?= $t['amount'] >= 0 ? 'positive' : 'negative' ?>">
                      <?= $t['amount'] >= 0 ? '+' : '' ?><?= $t['amount'] ?>
                    </span>
                  </td>
                  <td class="desc"><?= htmlspecialchars($t['description']) ?></td>
                  <td class="balance-col"><?= number_format($running[$t['id']]) ?> pts</td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <div class="empty-state">
            <div class="empty-icon">📊</div>
            <div class="empty-text">No point transactions yet</div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>

  <?php include 'employee_bottom_nav.php'; ?>
</body>
</html>