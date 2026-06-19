<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$active_page = 'analytics';
$today = date('Y-m-d');
$current_month = date('m');
$current_year = date('Y');

// ============================================================
// SECTION 1 — Quick Stats
// ============================================================
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'employee'");
$total_employees = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM attendance WHERE date = ? AND status IN ('on_time','grace_period','late')");
$stmt->execute([$today]);
$active_today = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'");
$pending_leaves = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM reward_redemptions WHERE status = 'pending'");
$pending_rewards = $stmt->fetchColumn();

// ============================================================
// SECTION 2 — Attendance Analytics
// ============================================================
$stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM attendance WHERE MONTH(date) = ? AND YEAR(date) = ? GROUP BY status");
$stmt->execute([$current_month, $current_year]);
$att_raw = $stmt->fetchAll();

$present = 0; $late = 0; $absent = 0; $on_leave = 0; $total_tracked = 0;
foreach ($att_raw as $r) {
    if (in_array($r['status'], ['on_time','grace_period','late'])) $present += $r['cnt'];
    if ($r['status'] === 'late') $late += $r['cnt'];
    if ($r['status'] === 'absent') $absent += $r['cnt'];
    if ($r['status'] === 'on_leave') $on_leave += $r['cnt'];
    if (!in_array($r['status'], ['on_leave','public_holiday'])) $total_tracked += $r['cnt'];
}
$attendance_rate = $total_tracked > 0 ? round(($present / $total_tracked) * 100) : 0;
$late_rate = $total_tracked > 0 ? round(($late / $total_tracked) * 100) : 0;
$leave_rate = $present + $absent + $late > 0 ? round(($on_leave / ($present + $absent + $late + $on_leave)) * 100) : 0;
$total_days_tracked = $total_tracked;

// ============================================================
// SECTION 3 — Rewards Analytics
// ============================================================
$stmt = $pdo->query("SELECT COUNT(*) FROM reward_redemptions");
$total_redemptions = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT SUM(points_spent) FROM reward_redemptions WHERE status != 'cancelled' AND status != 'rejected'");
$total_points_redeemed = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->query("
    SELECT ri.name, COUNT(rr.id) as cnt
    FROM reward_redemptions rr
    JOIN reward_items ri ON rr.item_id = ri.id
    WHERE rr.status != 'cancelled' AND rr.status != 'rejected'
    GROUP BY rr.item_id
    ORDER BY cnt DESC
    LIMIT 5
");
$top_items = $stmt->fetchAll();
$most_redeemed = $top_items[0] ?? null;

// ============================================================
// SECTION 4 — Tree Analytics
// ============================================================
$stmt = $pdo->query("SELECT plant_current_stage, COUNT(*) as cnt FROM users WHERE role = 'employee' GROUP BY plant_current_stage ORDER BY plant_current_stage");
$stage_counts = array_fill(1, 7, 0);
while ($row = $stmt->fetch()) {
    $stage_counts[$row['plant_current_stage']] = $row['cnt'];
}

// ============================================================
// SECTION 5 — Leaderboard
// ============================================================
$stmt = $pdo->query("SELECT id, name, total_points, current_streak, plant_current_stage FROM users WHERE role = 'employee' ORDER BY total_points DESC LIMIT 10");
$top_points = $stmt->fetchAll();

$stmt = $pdo->query("SELECT id, name, total_points, current_streak, plant_current_stage FROM users WHERE role = 'employee' ORDER BY current_streak DESC LIMIT 10");
$top_streaks = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Analytics — LeafPoint</title>
  <style>
    :root {
      --bg: oklch(96.5% 0.006 245);
      --bg-gradient: radial-gradient(ellipse at 70% 0%, oklch(90% 0.04 170 / 0.18), oklch(97% 0.004 245) 55%);
      --surface-glass: rgba(255, 255, 255, 0.52);
      --surface-glass-hover: rgba(255, 255, 255, 0.72);
      --surface-solid: #ffffff;
      --fg: oklch(16% 0.018 252); --fg-secondary: oklch(36% 0.022 250); --muted: oklch(53% 0.016 250);
      --border-glass: rgba(255, 255, 255, 0.38); --border-subtle: rgba(0, 0, 0, 0.055);
      --accent: oklch(56% 0.19 148); --accent-soft: oklch(74% 0.14 148); --accent-glow: oklch(62% 0.21 148 / 0.3);
      --gold: oklch(70% 0.19 82); --green-status: oklch(58% 0.17 142);
      --red-status: oklch(53% 0.22 22); --yellow-status: oklch(68% 0.15 85); --blue-info: oklch(56% 0.16 255);
      --sidebar-bg: oklch(13% 0.02 252); --sidebar-fg: oklch(84% 0.006 250); --sidebar-muted: oklch(60% 0.016 250);
      --radius-sm: 10px; --radius-md: 16px; --radius-lg: 22px;
      --shadow-card: 0 2px 16px rgba(0,0,0,0.04), 0 0 0 1px rgba(0,0,0,0.03);
      --shadow-card-hover: 0 6px 28px rgba(0,0,0,0.07), 0 0 0 1px rgba(0,0,0,0.04);
      --font-display: -apple-system, BlinkMacSystemFont, 'SF Pro Display', system-ui, sans-serif;
      --font-body: -apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, sans-serif;
      --font-mono: 'SF Mono', ui-monospace, 'JetBrains Mono', Menlo, monospace;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { width: 100%; height: 100%; font-family: var(--font-body); color: var(--fg); background: var(--bg); -webkit-font-smoothing: antialiased; overflow: hidden; }
    .app { display: flex; height: 100vh; width: 100%; }

    .sidebar { width: 250px; min-width: 250px; height: 100%; background: var(--sidebar-bg); color: var(--sidebar-fg); display: flex; flex-direction: column; padding: 26px 18px 18px; gap: 5px; z-index: 10; border-right: 1px solid rgba(255,255,255,0.06); }
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
    .main-inner { padding: 24px 30px 36px; display: flex; flex-direction: column; gap: 20px; max-width: 1200px; width: 100%; }

    .topbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .topbar .title { font-family: var(--font-display); font-size: 20px; font-weight: 700; letter-spacing: -0.02em; }
    .topbar .admin-badge { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; background: oklch(92% 0.04 310); color: oklch(38% 0.1 310); }

    .card { background: var(--surface-glass); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border: 1px solid var(--border-glass); border-radius: var(--radius-lg); box-shadow: var(--shadow-card); padding: 20px 22px; display: flex; flex-direction: column; gap: 14px; transition: box-shadow 0.2s; }
    .card:hover { box-shadow: var(--shadow-card-hover); }
    .card-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .card-title { font-family: var(--font-display); font-size: 15px; font-weight: 700; letter-spacing: -0.01em; }
    .card-subtitle { font-size: 11px; color: var(--muted); }

    /* Stats grid */
    .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .stat-card { background: var(--surface-glass); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border: 1px solid var(--border-glass); border-radius: var(--radius-md); box-shadow: var(--shadow-card); padding: 16px 14px; display: flex; flex-direction: column; gap: 8px; transition: all 0.2s; position: relative; overflow: hidden; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-card-hover); }
    .stat-card::after { content: ''; position: absolute; top: -18px; right: -18px; width: 60px; height: 60px; border-radius: 50%; opacity: 0.1; pointer-events: none; }
    .stat-card.employees::after { background: var(--accent); }
    .stat-card.active::after { background: var(--green-status); }
    .stat-card.pending-leave::after { background: var(--yellow-status); }
    .stat-card.pending-reward::after { background: var(--gold); }
    .stat-card .sc-icon { font-size: 22px; }
    .stat-card .sc-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 700; }
    .stat-card .sc-value { font-family: var(--font-display); font-size: 30px; font-weight: 800; letter-spacing: -0.02em; }
    .stat-card.employees .sc-value { color: var(--accent); }
    .stat-card.active .sc-value { color: var(--green-status); }
    .stat-card.pending-leave .sc-value { color: var(--yellow-status); }
    .stat-card.pending-reward .sc-value { color: var(--gold); }

    /* Analytics row */
    .analytics-row { display: flex; gap: 18px; flex-wrap: wrap; }
    .analytics-item { flex: 1; min-width: 160px; display: flex; flex-direction: column; gap: 8px; }
    .analytics-item .a-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 700; }
    .analytics-item .a-value { font-family: var(--font-display); font-size: 32px; font-weight: 800; letter-spacing: -0.02em; }
    .analytics-bar-wrap { width: 100%; height: 8px; border-radius: 999px; background: oklch(90% 0.006 250); overflow: hidden; }
    .analytics-bar-fill { height: 100%; border-radius: 999px; }
    .analytics-bar-fill.green { background: var(--green-status); }
    .analytics-bar-fill.red { background: var(--red-status); }
    .analytics-bar-fill.gold { background: var(--gold); }
    .analytics-bar-fill.blue { background: var(--blue-info); }

    /* Stage bars */
    .stage-row { display: flex; align-items: center; gap: 12px; padding: 6px 0; }
    .stage-row .stage-label { width: 140px; font-size: 13px; font-weight: 600; flex-shrink: 0; }
    .stage-row .stage-bar-wrap { flex: 1; height: 24px; border-radius: 6px; background: oklch(92% 0.004 250); overflow: hidden; position: relative; }
    .stage-row .stage-bar-fill { height: 100%; border-radius: 6px; background: linear-gradient(90deg, var(--accent), oklch(64% 0.18 150)); transition: width 0.6s ease; display: flex; align-items: center; padding-left: 10px; }
    .stage-row .stage-count { font-size: 12px; font-weight: 700; color: #fff; min-width: 40px; }

    /* Leaderboard */
    .leader-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    .leader-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .leader-table thead th { text-align: left; padding: 8px 10px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); font-weight: 700; border-bottom: 1px solid var(--border-subtle); }
    .leader-table tbody td { padding: 10px; border-bottom: 1px solid oklch(94% 0.003 250); white-space: nowrap; }
    .rank { font-family: var(--font-display); font-weight: 800; font-size: 16px; width: 28px; }
    .rank.gold { color: var(--gold); }
    .rank.silver { color: oklch(60% 0.02 250); }
    .rank.bronze { color: oklch(55% 0.05 55); }
    .emp-cell { display: flex; align-items: center; gap: 8px; }
    .emp-avatar { width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; font-weight: 700; color: #fff; flex-shrink: 0; }

    /* Top items */
    .top-item-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; }
    .top-item-rank { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; font-weight: 700; color: #fff; background: var(--accent); flex-shrink: 0; }
    .top-item-info { flex: 1; }
    .top-item-name { font-size: 13px; font-weight: 600; }
    .top-item-count { font-size: 11px; color: var(--muted); }

    .bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 30; height: 64px; background: oklch(98% 0.003 250 / 0.92); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border-top: 1px solid rgba(0,0,0,0.06); align-items: center; justify-content: space-around; padding: 0 8px; padding-bottom: env(safe-area-inset-bottom, 0px); }
    .bottom-nav a { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 6px 10px; border: none; background: none; font: 10px/1 var(--font-body); color: var(--muted); cursor: pointer; transition: color 0.15s; font-weight: 500; text-decoration: none; }
    .bottom-nav a .nav-icon { font-size: 20px; line-height: 1; }
    .bottom-nav a.active { color: var(--accent); font-weight: 700; }

    .note { font-size: 11px; color: var(--muted); text-align: center; }

    @media (min-width: 640px) {
      .stats-grid { grid-template-columns: repeat(4, 1fr); gap: 12px; }
      .stat-card .sc-value { font-size: 34px; }
      .topbar .title { font-size: 20px; }
    }
    @media (min-width: 1024px) {
      .card { padding: 24px 28px; }
      .card-title { font-size: 17px; }
      .stat-card .sc-value { font-size: 38px; }
      .analytics-item .a-value { font-size: 36px; }
    }
    @media (max-width: 800px) { .sidebar { width: 210px; min-width: 210px; } .main-inner { padding: 16px 12px 80px; } .leader-grid { grid-template-columns: 1fr; } }
    @media (max-width: 660px) { .sidebar { display: none; } .bottom-nav { display: flex; } .main-inner { padding: 14px 10px 80px; } .stats-grid { grid-template-columns: 1fr 1fr; } }
  </style>
</head>
<body>
  <div class="app">
    <?php include 'boss_sidebar.php'; ?>

    <main class="main">
      <div class="main-inner">

        <header class="topbar">
          <div style="display:flex;align-items:center;gap:10px;">
            <span class="title">📊 Analytics Dashboard</span>
            <span class="admin-badge">👑 Admin</span>
          </div>
          <span class="note"><?= date('F Y') ?> · Updated just now</span>
        </header>

        <!-- ==================================================
             SECTION 1 — QUICK STATS
             ================================================== -->
        <div class="stats-grid">
          <div class="stat-card employees">
            <span class="sc-icon">👥</span>
            <span class="sc-label">Total Employees</span>
            <span class="sc-value"><?= $total_employees ?></span>
          </div>
          <div class="stat-card active">
            <span class="sc-icon">✅</span>
            <span class="sc-label">Active Today</span>
            <span class="sc-value"><?= $active_today ?></span>
          </div>
          <div class="stat-card pending-leave">
            <span class="sc-icon">📋</span>
            <span class="sc-label">Pending Leaves</span>
            <span class="sc-value"><?= $pending_leaves ?></span>
          </div>
          <div class="stat-card pending-reward">
            <span class="sc-icon">🎁</span>
            <span class="sc-label">Pending Rewards</span>
            <span class="sc-value"><?= $pending_rewards ?></span>
          </div>
        </div>

        <!-- ==================================================
             SECTION 2 — ATTENDANCE ANALYTICS
             ================================================== -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">📅 Attendance Overview — <?= date('F Y') ?></span>
            <span class="card-subtitle"><?= $total_days_tracked ?> employee-days tracked</span>
          </div>
          <div class="analytics-row">
            <div class="analytics-item">
              <span class="a-label">Attendance Rate</span>
              <span class="a-value" style="color:var(--green-status);"><?= $attendance_rate ?>%</span>
              <div class="analytics-bar-wrap"><div class="analytics-bar-fill green" style="width:<?= $attendance_rate ?>%;"></div></div>
              <span style="font-size:11px;color:var(--muted);"><?= $present ?> present</span>
            </div>
            <div class="analytics-item">
              <span class="a-label">Late Rate</span>
              <span class="a-value" style="color:var(--gold);"><?= $late_rate ?>%</span>
              <div class="analytics-bar-wrap"><div class="analytics-bar-fill gold" style="width:<?= $late_rate ?>%;"></div></div>
              <span style="font-size:11px;color:var(--muted);"><?= $late ?> late arrivals</span>
            </div>
            <div class="analytics-item">
              <span class="a-label">Leave Rate</span>
              <span class="a-value" style="color:var(--blue-info);"><?= $leave_rate ?>%</span>
              <div class="analytics-bar-wrap"><div class="analytics-bar-fill blue" style="width:<?= $leave_rate ?>%;"></div></div>
              <span style="font-size:11px;color:var(--muted);"><?= $on_leave ?> leave days</span>
            </div>
            <div class="analytics-item">
              <span class="a-label">Absent Days</span>
              <span class="a-value" style="color:var(--red-status);"><?= $absent ?></span>
              <div class="analytics-bar-wrap"><div class="analytics-bar-fill red" style="width:<?= $total_tracked > 0 ? round(($absent / $total_tracked) * 100) : 0 ?>%;"></div></div>
              <span style="font-size:11px;color:var(--muted);">no-shows</span>
            </div>
          </div>
        </div>

        <!-- ==================================================
             SECTION 3 — REWARDS ANALYTICS
             ================================================== -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">🎁 Rewards Overview</span>
          </div>
          <div class="analytics-row">
            <div class="analytics-item">
              <span class="a-label">Total Redemptions</span>
              <span class="a-value" style="color:var(--accent);"><?= $total_redemptions ?></span>
            </div>
            <div class="analytics-item">
              <span class="a-label">Points Redeemed</span>
              <span class="a-value" style="color:var(--gold);"><?= number_format($total_points_redeemed) ?></span>
            </div>
            <div class="analytics-item" style="min-width:200px;">
              <span class="a-label">Most Redeemed Item</span>
              <?php if ($most_redeemed): ?>
              <span class="a-value" style="font-size:18px;color:var(--fg);"><?= htmlspecialchars($most_redeemed['name']) ?></span>
              <span style="font-size:11px;color:var(--muted);"><?= $most_redeemed['cnt'] ?>× redeemed</span>
              <?php else: ?>
              <span style="color:var(--muted);">No data yet</span>
              <?php endif; ?>
            </div>
          </div>
          <?php if (count($top_items) > 0): ?>
          <div style="display:flex;flex-direction:column;gap:0;padding-top:8px;">
            <?php foreach ($top_items as $i => $item): ?>
            <div class="top-item-row">
              <span class="top-item-rank"><?= $i + 1 ?></span>
              <div class="top-item-info">
                <div class="top-item-name"><?= htmlspecialchars($item['name']) ?></div>
                <div class="top-item-count"><?= $item['cnt'] ?> redemption<?= $item['cnt'] !== 1 ? 's' : '' ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- ==================================================
             SECTION 4 — TREE ANALYTICS
             ================================================== -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">🌳 World Tree Distribution</span>
            <span class="card-subtitle"><?= $total_employees ?> employees</span>
          </div>
          <?php
          $stage_names = [1=>'Sprout', 2=>'Sapling', 3=>'Young Tree', 4=>'Blooming Tree', 5=>'Ancient Oak', 6=>'Magical Blooming', 7=>'World Tree'];
          $stage_emojis = [1=>'🌱', 2=>'🪴', 3=>'🌲', 4=>'🌸', 5=>'🌳', 6=>'💐', 7=>'🌍'];
          $max_stage = max($stage_counts) ?: 1;
          ?>
          <?php for ($s = 1; $s <= 7; $s++): ?>
          <div class="stage-row">
            <span class="stage-label"><?= $stage_emojis[$s] ?> Stage <?= $s ?>: <?= $stage_names[$s] ?></span>
            <div class="stage-bar-wrap">
              <div class="stage-bar-fill" style="width:<?= $max_stage > 0 ? round(($stage_counts[$s] / $max_stage) * 100) : 0 ?>%;">
                <?php if ($stage_counts[$s] > 0): ?>
                <span class="stage-count"><?= $stage_counts[$s] ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endfor; ?>
        </div>

        <!-- ==================================================
             SECTION 5 — LEADERBOARD
             ================================================== -->
        <div class="leader-grid">
          <!-- Top by Points -->
          <div class="card">
            <div class="card-header">
              <span class="card-title">⭐ Top by Points</span>
            </div>
            <table class="leader-table">
              <thead><tr><th></th><th>Employee</th><th>Points</th><th>Stage</th></tr></thead>
              <tbody>
                <?php
                $colors = ['#4A90D9','#E8734A','#50B86C','#9B59B6','#F1C40F','#1ABC9C','#E74C3C','#3498DB','#2ECC71','#E67E22'];
                foreach ($top_points as $i => $emp):
                  $rank_class = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : ''));
                  $initials = implode('', array_map(fn($w) => strtoupper(substr($w, 0, 1)), explode(' ', $emp['name'])));
                ?>
                <tr>
                  <td class="rank <?= $rank_class ?>"><?= $i + 1 ?></td>
                  <td>
                    <div class="emp-cell">
                      <div class="emp-avatar" style="background:<?= $colors[$emp['id'] % 10] ?>;"><?= $initials ?></div>
                      <?= htmlspecialchars($emp['name']) ?>
                    </div>
                  </td>
                  <td style="font-family:var(--font-mono);font-weight:700;"><?= number_format($emp['total_points']) ?></td>
                  <td><?= $stage_emojis[$emp['plant_current_stage']] ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Top by Streak -->
          <div class="card">
            <div class="card-header">
              <span class="card-title">🔥 Top by Streak</span>
            </div>
            <table class="leader-table">
              <thead><tr><th></th><th>Employee</th><th>Streak</th><th>Stage</th></tr></thead>
              <tbody>
                <?php foreach ($top_streaks as $i => $emp):
                  $rank_class = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : ''));
                  $initials = implode('', array_map(fn($w) => strtoupper(substr($w, 0, 1)), explode(' ', $emp['name'])));
                ?>
                <tr>
                  <td class="rank <?= $rank_class ?>"><?= $i + 1 ?></td>
                  <td>
                    <div class="emp-cell">
                      <div class="emp-avatar" style="background:<?= $colors[$emp['id'] % 10] ?>;"><?= $initials ?></div>
                      <?= htmlspecialchars($emp['name']) ?>
                    </div>
                  </td>
                  <td style="font-family:var(--font-mono);font-weight:700;"><?= $emp['current_streak'] ?> days</td>
                  <td><?= $stage_emojis[$emp['plant_current_stage']] ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </main>
  </div>

  <?php include 'boss_bottom_nav.php'; ?>
</body>
</html>