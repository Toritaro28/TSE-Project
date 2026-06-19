<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit();
}

$active_page = 'dashboard';
$user_id = $_SESSION['user_id'];
$date_today = date('Y-m-d');
$current_month = date('m');
$current_year = date('Y');

// ============================================================
// 1. User Gamification Stats
// ============================================================
$stmt = $pdo->prepare("SELECT total_points, current_streak, plant_current_stage, plant_highest_stage, plant_status FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// 2. Tree Stage Mapping
// ============================================================
$tree_stages = [
    1 => ['name' => 'Sprout',                    'emoji' => '🌱', 'desc' => 'Your journey begins. A tiny sprout breaks through the soil.'],
    2 => ['name' => 'Sapling',                   'emoji' => '🪴', 'desc' => 'Growing stronger with each on-time day.'],
    3 => ['name' => 'Young Tree',                'emoji' => '🌲', 'desc' => 'A young tree taking shape — consistency is key.'],
    4 => ['name' => 'Blooming Tree',             'emoji' => '🌸', 'desc' => 'Your tree blooms! Vibrant and full of life.'],
    5 => ['name' => 'Ancient Oak',               'emoji' => '🌳', 'desc' => 'A mighty oak — your dedication is unmatched.'],
    6 => ['name' => 'Magical Blooming Tree',     'emoji' => '💐', 'desc' => 'Magic surrounds your tree. Almost at the pinnacle.'],
    7 => ['name' => 'World Tree',                'emoji' => '🌍', 'desc' => 'The World Tree — you have reached the ultimate stage!'],
];
$current_tree = $tree_stages[$user['plant_current_stage']];

// ============================================================
// 3. Tree Health & Progress
// ============================================================
$tree_health = 'healthy';
if ($user['plant_status'] === 'Withered') {
    $tree_health = ($user['current_streak'] > 0) ? 'recovering' : 'withered';
}

$stage_progress = 0;
if ($user['plant_current_stage'] < 7) {
    $thresholds = [1 => 0, 2 => 20, 3 => 40, 4 => 60, 5 => 80, 6 => 100, 7 => 120];
    $current_threshold = $thresholds[$user['plant_current_stage']];
    $next_threshold = $thresholds[$user['plant_current_stage'] + 1];
    $streak = $user['current_streak'];
    if ($streak >= $next_threshold) {
        $stage_progress = 100;
    } elseif ($streak > $current_threshold) {
        $stage_progress = round(($streak - $current_threshold) / ($next_threshold - $current_threshold) * 100);
    }
}
$stage_maxed = ($user['plant_current_stage'] >= 7);

// ============================================================
// 4. Today's Attendance
// ============================================================
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ?");
$stmt->execute([$user_id, $date_today]);
$attendance = $stmt->fetch();

$has_checked_in = $attendance ? true : false;
$has_checked_out = ($attendance && $attendance['check_out_time']) ? true : false;
$check_in_time = ($attendance && $attendance['check_in_time']) ? date('h:i A', strtotime($attendance['check_in_time'])) : '—:— —';
$check_out_time = ($attendance && $attendance['check_out_time']) ? date('h:i A', strtotime($attendance['check_out_time'])) : '—:— —';
$attendance_status = $attendance ? ucfirst(str_replace('_', ' ', $attendance['status'])) : 'Not Checked In';
$today_points = $attendance ? $attendance['points_earned'] : 0;

// Check if employee is on approved leave today
$stmt = $pdo->prepare("SELECT id, leave_type FROM leave_requests WHERE user_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ? LIMIT 1");
$stmt->execute([$user_id, $date_today, $date_today]);
$on_leave_today = $stmt->fetch();
$is_on_approved_leave = $on_leave_today ? true : false;
$on_leave_type = $on_leave_today ? $on_leave_today['leave_type'] : '';

// Check if today is a weekend or public holiday
$day_of_week = (int)date('N');
$is_weekend = ($day_of_week >= 6);
$stmt = $pdo->prepare("SELECT id, holiday_name FROM public_holidays WHERE holiday_date = ? LIMIT 1");
$stmt->execute([$date_today]);
$holiday_today = $stmt->fetch();
$is_public_holiday = (bool)$holiday_today;
$holiday_name = $holiday_today ? $holiday_today['holiday_name'] : '';
$is_non_working_day = $is_weekend || $is_public_holiday;
$non_working_reason = $is_public_holiday ? "🎌 $holiday_name" : 'Weekend';

// ============================================================
// 5. Quick Summary Data
// ============================================================
$stmt = $pdo->prepare("SELECT * FROM leave_balances WHERE user_id = ? AND year = ?");
$stmt->execute([$user_id, $current_year]);
$leave_balance = $stmt->fetch();
if (!$leave_balance) {
    $leave_balance = ['al_total' => 14, 'al_used' => 0, 'mc_total' => 14, 'mc_used' => 0];
}
$al_remaining = $leave_balance['al_total'] - $leave_balance['al_used'];
$mc_remaining = $leave_balance['mc_total'] - $leave_balance['mc_used'];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id = ? AND status = 'pending'");
$stmt->execute([$user_id]);
$pending_leave_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM attendance WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ? GROUP BY status");
$stmt->execute([$user_id, $current_month, $current_year]);
$monthly_raw = $stmt->fetchAll();
$present_days = 0; $absent_days = 0; $total_tracked = 0;
foreach ($monthly_raw as $row) {
    if (in_array($row['status'], ['on_time', 'grace_period', 'late'])) $present_days += $row['cnt'];
    elseif ($row['status'] === 'absent') $absent_days += $row['cnt'];
    if (!in_array($row['status'], ['on_leave', 'public_holiday'])) $total_tracked += $row['cnt'];
}
$attendance_rate = $total_tracked > 0 ? round(($present_days / $total_tracked) * 100) : 0;

$stmt = $pdo->query("SELECT COUNT(*) FROM reward_items WHERE is_active = 1 AND stock_quantity > 0");
$available_rewards = $stmt->fetchColumn();

// Fetch earned badges (table may not exist yet)
$earned_badges = [];
try {
    $stmt = $pdo->prepare("SELECT badge_name, badge_icon, earned_at FROM badges WHERE user_id = ? ORDER BY earned_at DESC");
    $stmt->execute([$user_id]);
    $earned_badges = $stmt->fetchAll();
} catch (PDOException $e) {
    // badges table not created yet — skip silently
}

// ============================================================
// 6. Mini Calendar Data (calendar preview card)
// ============================================================
$cal_data = [];
$stmt = $pdo->prepare("SELECT date, status FROM attendance WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ?");
$stmt->execute([$user_id, $current_month, $current_year]);
while ($row = $stmt->fetch()) {
    $cal_data[$row['date']] = $row['status'];
}
// Overlay approved leaves
$last_day = date('Y-m-t', mktime(0,0,0,$current_month,1,$current_year));
$first_day = date('Y-m-01', mktime(0,0,0,$current_month,1,$current_year));
$stmt = $pdo->prepare("SELECT start_date, end_date, leave_type FROM leave_requests WHERE user_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ?");
$stmt->execute([$user_id, $last_day, $first_day]);
while ($row = $stmt->fetch()) {
    $cur = strtotime(max($row['start_date'], $first_day));
    $end = strtotime(min($row['end_date'], $last_day));
    while ($cur <= $end) {
        $ds = date('Y-m-d', $cur);
        if (!isset($cal_data[$ds])) $cal_data[$ds] = 'leave_' . $row['leave_type'];
        $cur = strtotime('+1 day', $cur);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>LeafPoint — Dashboard</title>
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
      --gold: oklch(70% 0.18 82); --gold-soft: oklch(82% 0.12 82);
      --green-status: oklch(60% 0.18 145); --red-status: oklch(53% 0.22 22);
      --yellow-withered: oklch(68% 0.14 85); --blue-leave: oklch(56% 0.16 255);
      --sidebar-bg: oklch(13% 0.02 252); --sidebar-fg: oklch(84% 0.006 250); --sidebar-muted: oklch(60% 0.016 250);
      --radius-sm: 10px; --radius-md: 16px; --radius-lg: 22px; --radius-xl: 28px;
      --shadow-card: 0 2px 20px rgba(0,0,0,0.045), 0 0 0 1px rgba(0,0,0,0.035);
      --shadow-card-hover: 0 8px 38px rgba(0,0,0,0.08), 0 0 0 1px rgba(0,0,0,0.05);
      --shadow-glow: 0 0 60px var(--accent-glow);
      --font-display: -apple-system, BlinkMacSystemFont, 'SF Pro Display', system-ui, sans-serif;
      --font-body: -apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, sans-serif;
      --font-mono: 'SF Mono', ui-monospace, 'JetBrains Mono', Menlo, monospace;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { width: 100%; height: 100%; font-family: var(--font-body); color: var(--fg); background: var(--bg-deep); -webkit-font-smoothing: antialiased; overflow: hidden; }
    .app { display: flex; height: 100vh; width: 100%; }

    /* ---- Sidebar ---- */
    .sidebar {
      width: 250px; min-width: 250px; height: 100%;
      background: var(--sidebar-bg); color: var(--sidebar-fg);
      display: flex; flex-direction: column; padding: 26px 18px 18px;
      gap: 5px; z-index: 10; border-right: 1px solid rgba(255,255,255,0.06);
    }
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

    /* ---- Bottom nav (mobile) ---- */
    .bottom-nav {
      display: none;
      position: fixed; bottom: 0; left: 0; right: 0; z-index: 30;
      height: 64px; background: oklch(98% 0.003 250 / 0.92);
      backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px);
      border-top: 1px solid rgba(0,0,0,0.06);
      align-items: center; justify-content: space-around;
      padding: 0 8px; padding-bottom: env(safe-area-inset-bottom, 0px);
    }
    .bottom-nav a { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 6px 10px; border: none; background: none; font: 10px/1 var(--font-body); color: var(--muted); cursor: pointer; transition: color 0.15s; font-weight: 500; text-decoration: none; }
    .bottom-nav a .nav-icon { font-size: 20px; line-height: 1; }
    .bottom-nav a.active { color: var(--accent); font-weight: 700; }

    /* ---- Main ---- */
    .main { flex: 1; overflow-y: auto; overflow-x: hidden; background: var(--bg-gradient); display: flex; flex-direction: column; }
    .main-inner { padding: 24px 30px 36px; display: flex; flex-direction: column; gap: 20px; max-width: 1200px; width: 100%; }

    /* ---- Header ---- */
    .dashboard-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; }
    .greeting h1 { font-family: var(--font-display); font-size: 24px; font-weight: 700; letter-spacing: -0.02em; color: var(--fg); }
    .greeting span { font-size: 13px; color: var(--muted); display: block; margin-top: 2px; }
    .header-pills { display: flex; gap: 8px; flex-wrap: wrap; }
    .pill { display: inline-flex; align-items: center; gap: 5px; padding: 7px 13px; border-radius: 999px; font-size: 12px; font-weight: 600; letter-spacing: -0.01em; }
    .pill-streak { background: oklch(94% 0.05 83); color: oklch(42% 0.13 78); }
    .pill-points { background: oklch(93% 0.05 158); color: oklch(40% 0.13 152); }

    /* ---- Tree Hero ---- */
    .tree-hero {
      position: relative; background: var(--surface-glass); backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px); border: 1px solid var(--border-glass);
      border-radius: var(--radius-xl); box-shadow: var(--shadow-card), var(--shadow-glow);
      padding: 28px 32px 24px; display: flex; gap: 32px; align-items: center; overflow: hidden;
    }
    .tree-hero::before { content: ''; position: absolute; top: -60px; right: -40px; width: 280px; height: 280px; background: radial-gradient(circle, oklch(62% 0.18 148 / 0.12), transparent 70%); pointer-events: none; }
    .tree-hero::after { content: ''; position: absolute; bottom: -30px; left: 20%; width: 200px; height: 120px; background: radial-gradient(ellipse, oklch(74% 0.18 82 / 0.08), transparent 70%); pointer-events: none; }
    .tree-visual { flex-shrink: 0; width: 200px; height: 260px; display: grid; place-items: center; position: relative; z-index: 1; }
    .tree-visual svg { width: 100%; height: 100%; display: none; }
    .tree-visual svg.active { display: block; }

    .tree-hero[data-health="healthy"] { --canopy-green: oklch(58% 0.17 142); --canopy-mid: oklch(52% 0.14 144); --canopy-dark: oklch(45% 0.12 146); --trunk-fill: #5D4037; --trunk-dark: #4E342E; --blossom-fill: oklch(79% 0.11 8 / 0.85); --magic-fill: oklch(74% 0.08 280 / 0.8); }
    .tree-hero[data-health="withered"] { --canopy-green: oklch(68% 0.1 88); --canopy-mid: oklch(64% 0.09 90); --canopy-dark: oklch(60% 0.08 85); --trunk-fill: #7B6B5A; --trunk-dark: #6B5D50; --blossom-fill: oklch(70% 0.05 80 / 0.3); --magic-fill: oklch(68% 0.04 270 / 0.3); }
    .tree-hero[data-health="recovering"] { --canopy-green: oklch(62% 0.14 140); --canopy-mid: oklch(56% 0.12 143); --canopy-dark: oklch(50% 0.11 145); --trunk-fill: #6D5A4A; --trunk-dark: #5D4C3E; --blossom-fill: oklch(78% 0.10 10 / 0.6); --magic-fill: oklch(72% 0.07 278 / 0.55); }
    @keyframes treeBreathe { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.015); } }
    .tree-hero[data-health="healthy"] .tree-visual svg.active { animation: treeBreathe 4s ease-in-out infinite; }
    @keyframes recoveryPulse { 0%, 100% { filter: drop-shadow(0 0 8px oklch(62% 0.2 148 / 0.3)); } 50% { filter: drop-shadow(0 0 24px oklch(62% 0.2 148 / 0.7)); } }
    .tree-hero[data-health="recovering"] .tree-visual svg.active { animation: recoveryPulse 1.2s ease-in-out infinite; }
    .tree-hero[data-health="withered"] .tree-visual svg.active { filter: grayscale(30%) sepia(20%); }

    .tree-info-panel { flex: 1; display: flex; flex-direction: column; gap: 14px; z-index: 1; min-width: 0; }
    .tree-title-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .tree-title-row h2 { font-family: var(--font-display); font-size: 26px; font-weight: 800; letter-spacing: -0.02em; color: var(--fg); }
    .tree-health-badge { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; }
    .tree-health-badge.healthy { background: oklch(92% 0.06 148); color: oklch(38% 0.1 148); }
    .tree-health-badge.withered { background: oklch(92% 0.06 82); color: oklch(42% 0.12 80); }
    .tree-health-badge.recovering { background: oklch(93% 0.05 170); color: oklch(40% 0.1 170); }
    .tree-stage-desc { font-size: 14px; color: var(--fg-secondary); line-height: 1.5; max-width: 460px; }
    .tree-meta-row { display: flex; gap: 20px; flex-wrap: wrap; }
    .tree-meta-item { display: flex; flex-direction: column; gap: 3px; }
    .tree-meta-item .meta-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); font-weight: 700; }
    .tree-meta-item .meta-value { font-family: var(--font-display); font-size: 20px; font-weight: 700; letter-spacing: -0.01em; }
    .tree-meta-item .meta-value.accent { color: var(--accent); }
    .tree-meta-item .meta-value.gold { color: var(--gold); }

    .stage-path { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; padding-top: 4px; }
    .stage-step { display: flex; align-items: center; gap: 6px; }
    .stage-dot { width: 36px; height: 36px; border-radius: 50%; display: grid; place-items: center; font-size: 17px; font-weight: 700; background: oklch(90% 0.006 250); color: var(--muted); border: 2px solid transparent; transition: all 0.3s; font-family: var(--font-display); position: relative; }
    .stage-dot.earned { background: oklch(88% 0.05 148); color: oklch(38% 0.1 148); border-color: oklch(78% 0.1 148); }
    .stage-dot.current { background: var(--accent); color: #fff; border-color: var(--accent); box-shadow: 0 0 18px var(--accent-glow); animation: stagePulse 2.2s ease-in-out infinite; }
    @keyframes stagePulse { 0%, 100% { box-shadow: 0 0 12px var(--accent-glow); } 50% { box-shadow: 0 0 28px oklch(62% 0.22 148 / 0.55); } }
    .stage-connector { width: 18px; height: 2px; background: oklch(88% 0.006 250); border-radius: 1px; }
    .stage-connector.earned { background: oklch(78% 0.1 148); }
    .stage-step:last-child .stage-connector { display: none; }

    .recovery-bar-wrap { display: flex; flex-direction: column; gap: 5px; }
    .recovery-bar-wrap .recovery-label { font-size: 11px; font-weight: 600; color: var(--muted); display: flex; justify-content: space-between; }
    .recovery-bar { width: 100%; height: 8px; border-radius: 999px; background: oklch(90% 0.008 240); overflow: hidden; }
    .recovery-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--accent), oklch(64% 0.18 150)); transition: width 0.6s ease; }

    /* ---- Glass card ---- */
    .card-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; }
    .card-grid.col-span-2 { grid-column: span 2; }
    .glass-card { background: var(--surface-glass); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border: 1px solid var(--border-glass); border-radius: var(--radius-lg); box-shadow: var(--shadow-card); padding: 20px 22px; transition: box-shadow 0.2s, background 0.2s; display: flex; flex-direction: column; gap: 14px; }
    .glass-card:hover { background: var(--surface-glass-hover); box-shadow: var(--shadow-card-hover); }
    .glass-card .card-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .glass-card .card-title { font-family: var(--font-display); font-size: 15px; font-weight: 700; letter-spacing: -0.01em; color: var(--fg); }
    .glass-card .card-subtitle { font-size: 11px; color: var(--muted); }

    /* ---- Attendance ---- */
    .check-actions { display: flex; gap: 12px; }
    .btn-check { flex: 1; min-width: 120px; padding: 16px 20px; border: none; border-radius: var(--radius-md); font-family: var(--font-display); font-size: 16px; font-weight: 700; letter-spacing: -0.01em; cursor: pointer; transition: all 0.2s; display: flex; flex-direction: column; align-items: center; gap: 5px; }
    .btn-check-in { background: var(--accent); color: #fff; box-shadow: 0 6px 22px oklch(56% 0.18 148 / 0.3); }
    .btn-check-in:hover:not(:disabled) { background: oklch(50% 0.16 148); transform: translateY(-2px); box-shadow: 0 8px 30px oklch(56% 0.18 148 / 0.42); }
    .btn-check-in:disabled { background: oklch(87% 0.02 155); color: oklch(58% 0.04 155); cursor: default; box-shadow: none; }
    .btn-check-out { background: var(--surface-solid); color: var(--fg); border: 1.5px solid var(--border-subtle); }
    .btn-check-out:hover:not(:disabled) { border-color: var(--accent); color: var(--accent); transform: translateY(-2px); }
    .btn-check-out:disabled { opacity: 0.4; cursor: default; }
    .btn-check .btn-icon { font-size: 26px; line-height: 1; }
    .btn-check .btn-sub { font-size: 11px; font-weight: 500; opacity: 0.7; }
    .check-status-row { display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px; }
    .check-status-row .cs-item { display: flex; flex-direction: column; gap: 2px; }
    .check-status-row .cs-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 700; }
    .check-status-row .cs-value { font-family: var(--font-mono); font-size: 14px; font-weight: 600; }
    @keyframes checkInPulse { 0% { box-shadow: 0 0 0 0 oklch(56% 0.18 148 / 0.45); } 70% { box-shadow: 0 0 0 14px oklch(56% 0.18 148 / 0); } 100% { box-shadow: 0 0 0 0 oklch(56% 0.18 148 / 0); } }
    .btn-check-in:not(:disabled) { animation: checkInPulse 2.2s ease-in-out infinite; }

    /* ---- Stat cards ---- */
    .stat-big { font-family: var(--font-display); font-size: 32px; font-weight: 800; letter-spacing: -0.03em; line-height: 1; display: flex; align-items: baseline; gap: 6px; }
    .stat-big .unit { font-size: 13px; font-weight: 600; color: var(--muted); }
    .stat-big.points { color: var(--fg); }
    .stat-big.streak { color: var(--gold); }

    /* ---- Summary cards ---- */
    .summary-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; }
    .summary-card { background: var(--surface-glass); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border: 1px solid var(--border-glass); border-radius: var(--radius-lg); box-shadow: var(--shadow-card); padding: 18px 20px; display: flex; align-items: center; gap: 14px; transition: all 0.2s; text-decoration: none; color: inherit; }
    .summary-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-card-hover); background: var(--surface-glass-hover); }
    .summary-card .sc-icon { width: 48px; height: 48px; border-radius: var(--radius-sm); display: grid; place-items: center; font-size: 24px; flex-shrink: 0; }
    .summary-card .sc-icon.leave-bg { background: oklch(90% 0.06 255 / 0.5); }
    .summary-card .sc-icon.rate-bg { background: oklch(90% 0.06 148 / 0.5); }
    .summary-card .sc-icon.reward-bg { background: oklch(92% 0.06 82 / 0.5); }
    .summary-card .sc-info { display: flex; flex-direction: column; gap: 2px; }
    .summary-card .sc-value { font-family: var(--font-display); font-size: 22px; font-weight: 700; }
    .summary-card .sc-label { font-size: 11px; color: var(--muted); font-weight: 600; }

    /* ---- Mini calendar ---- */
    .cal-mini { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; text-align: center; }
    .cal-mini .cal-day-header { font-size: 9px; text-transform: uppercase; letter-spacing: 0.07em; color: var(--muted); font-weight: 700; padding: 3px 0; }
    .cal-mini .cal-day { aspect-ratio: 1; display: grid; place-items: center; font-size: 10px; font-weight: 500; border-radius: 4px; font-family: var(--font-mono); }
    .cal-mini .cal-day.other-month { color: oklch(80% 0.004 250); }
    .cal-mini .cal-day.today { font-weight: 800; box-shadow: inset 0 0 0 2px var(--accent); }
    .cal-mini .cal-day.present { background: oklch(88% 0.06 145); color: oklch(36% 0.08 145); }
    .cal-mini .cal-day.absent { background: oklch(88% 0.04 22); color: oklch(38% 0.1 22); }
    .cal-mini .cal-day.leave { background: oklch(86% 0.05 255); color: oklch(36% 0.08 255); }
    .cal-mini .cal-day.other-month { color: oklch(80% 0.004 250); }

    /* ---- Responsive ---- */
    @media (max-width: 1100px) {
      .card-grid { grid-template-columns: 1fr 1fr; }
      .card-grid.col-span-2 { grid-column: span 2; }
      .summary-grid { grid-template-columns: 1fr 1fr 1fr; }
      .tree-hero { flex-direction: column; align-items: center; text-align: center; }
      .tree-info-panel { align-items: center; }
      .tree-stage-desc { max-width: 100%; }
      .stage-path { justify-content: center; }
    }
    @media (max-width: 800px) {
      .sidebar { width: 210px; min-width: 210px; }
      .card-grid { grid-template-columns: 1fr; }
      .card-grid.col-span-2 { grid-column: span 1; }
      .summary-grid { grid-template-columns: 1fr; }
      .main-inner { padding: 16px 12px; }
      .tree-visual { width: 160px; height: 210px; }
    }
    @media (max-width: 660px) {
      .sidebar { display: none; }
      .bottom-nav { display: flex; }
      .tree-hero { padding: 18px 16px 16px; }
      .tree-title-row h2 { font-size: 20px; }
      .main-inner { padding: 14px 10px 80px; }
    }
  </style>
</head>
<body>
  <div class="app">
    <?php include 'employee_sidebar.php'; ?>

    <main class="main">
      <div class="main-inner">

        <header class="dashboard-header">
          <div class="greeting">
            <h1>Good <?= date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening') ?>, <?= htmlspecialchars(explode(' ', $_SESSION['name'])[0]) ?> 👋</h1>
            <span><?= date('l, F j, Y') ?></span>
          </div>
          <div class="header-pills">
            <span class="pill pill-streak">🔥 <?= $user['current_streak'] ?>-Day Streak</span>
            <span class="pill pill-points">⭐ <?= number_format($user['total_points']) ?> Points</span>
          </div>
        </header>

        <!-- ==================================================
             WORLD TREE HERO
             ================================================== -->
        <section class="tree-hero" data-health="<?= $tree_health ?>" data-stage="<?= $user['plant_current_stage'] ?>">

          <div class="tree-visual" id="tree-visual">
            <svg viewBox="0 0 200 260" xmlns="http://www.w3.org/2000/svg" class="tree-svg" data-stage="1"><ellipse cx="100" cy="230" rx="45" ry="6" fill="#00000015"/><ellipse cx="100" cy="224" rx="28" ry="6" fill="var(--trunk-dark)"/><path d="M78 218 Q78 230 86 230 L114 230 Q122 230 122 218 Z" fill="var(--trunk-fill)"/><ellipse cx="100" cy="218" rx="22" ry="4" fill="var(--trunk-dark)"/><path d="M100 218 Q98 195 100 175" stroke="var(--trunk-fill)" stroke-width="5" stroke-linecap="round" fill="none"/><ellipse cx="88" cy="180" rx="16" ry="7" fill="var(--canopy-green)" transform="rotate(-30 88 180)"/><ellipse cx="112" cy="174" rx="14" ry="6" fill="var(--canopy-mid)" transform="rotate(25 112 174)"/><circle cx="100" cy="168" r="2.5" fill="var(--canopy-green)" opacity="0.7"/></svg>
            <svg viewBox="0 0 200 260" xmlns="http://www.w3.org/2000/svg" class="tree-svg" data-stage="2"><ellipse cx="100" cy="235" rx="48" ry="7" fill="#00000015"/><ellipse cx="100" cy="228" rx="30" ry="6" fill="var(--trunk-dark)"/><path d="M76 222 Q76 234 86 234 L114 234 Q124 234 124 222 Z" fill="var(--trunk-fill)"/><ellipse cx="100" cy="222" rx="24" ry="4" fill="var(--trunk-dark)"/><path d="M100 222 Q98 198 100 170 Q102 148 100 130" stroke="var(--trunk-fill)" stroke-width="7" stroke-linecap="round" fill="none"/><path d="M100 188 Q88 176 76 172" stroke="var(--trunk-fill)" stroke-width="4" stroke-linecap="round" fill="none"/><path d="M100 176 Q112 164 122 160" stroke="var(--trunk-fill)" stroke-width="3.5" stroke-linecap="round" fill="none"/><ellipse cx="76" cy="170" rx="20" ry="10" fill="var(--canopy-green)" transform="rotate(-20 76 170)"/><ellipse cx="122" cy="158" rx="18" ry="9" fill="var(--canopy-mid)" transform="rotate(18 122 158)"/><ellipse cx="100" cy="128" rx="22" ry="12" fill="var(--canopy-dark)"/><ellipse cx="88" cy="136" rx="14" ry="7" fill="var(--canopy-green)" transform="rotate(-35 88 136)" opacity="0.7"/></svg>
            <svg viewBox="0 0 200 280" xmlns="http://www.w3.org/2000/svg" class="tree-svg" data-stage="3"><ellipse cx="100" cy="252" rx="50" ry="7" fill="#00000015"/><ellipse cx="100" cy="244" rx="32" ry="7" fill="var(--trunk-dark)"/><path d="M74 238 Q74 250 86 250 L114 250 Q126 250 126 238 Z" fill="var(--trunk-fill)"/><ellipse cx="100" cy="238" rx="26" ry="4.5" fill="var(--trunk-dark)"/><path d="M100 238 Q97 210 100 180 Q103 152 100 118" stroke="var(--trunk-fill)" stroke-width="9" stroke-linecap="round" fill="none"/><path d="M97 208 Q94 192 96 176" stroke="var(--trunk-fill)" stroke-width="7" stroke-linecap="round" fill="none"/><path d="M100 198 Q84 180 68 174" stroke="var(--trunk-fill)" stroke-width="5" stroke-linecap="round" fill="none"/><path d="M100 186 Q116 170 132 164" stroke="var(--trunk-fill)" stroke-width="5" stroke-linecap="round" fill="none"/><path d="M100 168 Q84 152 66 140" stroke="var(--trunk-fill)" stroke-width="4" stroke-linecap="round" fill="none"/><path d="M100 156 Q116 140 134 130" stroke="var(--trunk-fill)" stroke-width="4" stroke-linecap="round" fill="none"/><ellipse cx="68" cy="172" rx="24" ry="14" fill="var(--canopy-green)" transform="rotate(-15 68 172)"/><ellipse cx="132" cy="162" rx="22" ry="13" fill="var(--canopy-mid)" transform="rotate(15 132 162)"/><ellipse cx="66" cy="138" rx="20" ry="12" fill="var(--canopy-dark)" transform="rotate(-22 66 138)"/><ellipse cx="134" cy="128" rx="19" ry="11" fill="var(--canopy-green)" transform="rotate(20 134 128)"/><ellipse cx="100" cy="112" rx="26" ry="16" fill="var(--canopy-mid)"/><ellipse cx="84" cy="125" rx="17" ry="10" fill="var(--canopy-green)" opacity="0.6" transform="rotate(-30 84 125)"/><ellipse cx="118" cy="120" rx="15" ry="9" fill="var(--canopy-dark)" opacity="0.5" transform="rotate(25 118 120)"/></svg>
            <svg viewBox="0 0 220 290" xmlns="http://www.w3.org/2000/svg" class="tree-svg" data-stage="4"><ellipse cx="110" cy="264" rx="55" ry="8" fill="#00000015"/><ellipse cx="110" cy="256" rx="36" ry="7" fill="var(--trunk-dark)"/><path d="M82 248 Q82 262 92 262 L128 262 Q138 262 138 248 Z" fill="var(--trunk-fill)"/><ellipse cx="110" cy="248" rx="28" ry="5" fill="var(--trunk-dark)"/><path d="M103 248 Q100 218 95 182 Q90 148 88 114 Q84 92 86 76" stroke="var(--trunk-fill)" stroke-width="11" stroke-linecap="round" fill="none"/><path d="M117 248 Q114 218 110 182 Q106 148 104 114 Q100 92 102 76" stroke="var(--trunk-dark)" stroke-width="8" stroke-linecap="round" fill="none"/><path d="M95 152 Q78 134 58 118" stroke="var(--trunk-fill)" stroke-width="5.5" stroke-linecap="round" fill="none"/><path d="M106 138 Q124 120 142 106" stroke="var(--trunk-dark)" stroke-width="5" stroke-linecap="round" fill="none"/><path d="M95 114 Q76 96 64 78" stroke="var(--trunk-fill)" stroke-width="4.5" stroke-linecap="round" fill="none"/><path d="M104 102 Q118 82 128 64" stroke="var(--trunk-dark)" stroke-width="4" stroke-linecap="round" fill="none"/><path d="M88 86 Q70 70 58 52" stroke="var(--trunk-fill)" stroke-width="3" stroke-linecap="round" fill="none"/><circle cx="84" cy="70" r="24" fill="var(--canopy-green)"/><circle cx="106" cy="54" r="28" fill="var(--canopy-dark)"/><circle cx="126" cy="66" r="22" fill="var(--canopy-mid)"/><circle cx="62" cy="58" r="18" fill="var(--canopy-green)"/><circle cx="142" cy="60" r="16" fill="var(--canopy-mid)"/><circle cx="94" cy="40" r="20" fill="var(--canopy-dark)"/><circle cx="114" cy="36" r="18" fill="var(--canopy-green)"/><circle cx="78" cy="32" r="14" fill="var(--canopy-mid)"/><circle cx="136" cy="48" r="13" fill="var(--canopy-green)"/><circle cx="80" cy="62" r="4.5" fill="var(--blossom-fill)"/><circle cx="106" cy="46" r="5.5" fill="var(--blossom-fill)"/><circle cx="128" cy="58" r="4" fill="var(--blossom-fill)"/><circle cx="64" cy="50" r="3.5" fill="var(--blossom-fill)"/><circle cx="140" cy="52" r="3" fill="var(--blossom-fill)"/><circle cx="94" cy="32" r="3.5" fill="var(--blossom-fill)"/><circle cx="116" cy="28" r="3" fill="var(--blossom-fill)"/><circle cx="74" cy="26" r="2.5" fill="var(--blossom-fill)"/></svg>
            <svg viewBox="0 0 240 300" xmlns="http://www.w3.org/2000/svg" class="tree-svg" data-stage="5"><ellipse cx="120" cy="274" rx="60" ry="9" fill="#00000015"/><ellipse cx="120" cy="266" rx="40" ry="8" fill="var(--trunk-dark)"/><path d="M90 256 Q90 272 102 272 L138 272 Q150 272 150 256 Z" fill="var(--trunk-fill)"/><ellipse cx="120" cy="256" rx="30" ry="5.5" fill="var(--trunk-dark)"/><path d="M120 256 Q116 220 118 178 Q120 142 118 96" stroke="var(--trunk-fill)" stroke-width="16" stroke-linecap="round" fill="none"/><path d="M114 210 Q110 180 113 150" stroke="var(--trunk-dark)" stroke-width="12" stroke-linecap="round" fill="none"/><path d="M126 210 Q130 180 127 150" stroke="var(--trunk-fill)" stroke-width="10" stroke-linecap="round" fill="none"/><path d="M112 256 Q96 262 78 256" stroke="var(--trunk-fill)" stroke-width="8" stroke-linecap="round" fill="none"/><path d="M128 256 Q144 262 162 256" stroke="var(--trunk-dark)" stroke-width="7" stroke-linecap="round" fill="none"/><path d="M118 186 Q96 166 68 152" stroke="var(--trunk-fill)" stroke-width="8" stroke-linecap="round" fill="none"/><path d="M122 178 Q144 158 172 144" stroke="var(--trunk-dark)" stroke-width="8" stroke-linecap="round" fill="none"/><path d="M118 148 Q92 128 58 110" stroke="var(--trunk-fill)" stroke-width="6" stroke-linecap="round" fill="none"/><path d="M122 140 Q148 120 182 104" stroke="var(--trunk-dark)" stroke-width="6" stroke-linecap="round" fill="none"/><path d="M118 120 Q98 104 78 82" stroke="var(--trunk-fill)" stroke-width="5" stroke-linecap="round" fill="none"/><path d="M122 114 Q142 98 162 76" stroke="var(--trunk-dark)" stroke-width="5" stroke-linecap="round" fill="none"/><ellipse cx="68" cy="148" rx="32" ry="20" fill="var(--canopy-green)" transform="rotate(-10 68 148)"/><ellipse cx="172" cy="140" rx="30" ry="19" fill="var(--canopy-mid)" transform="rotate(10 172 140)"/><ellipse cx="58" cy="108" rx="28" ry="18" fill="var(--canopy-dark)" transform="rotate(-18 58 108)"/><ellipse cx="182" cy="102" rx="26" ry="17" fill="var(--canopy-green)" transform="rotate(15 182 102)"/><ellipse cx="78" cy="80" rx="26" ry="16" fill="var(--canopy-mid)" transform="rotate(-25 78 80)"/><ellipse cx="162" cy="76" rx="24" ry="15" fill="var(--canopy-dark)" transform="rotate(22 162 76)"/><ellipse cx="120" cy="56" rx="32" ry="20" fill="var(--canopy-green)"/><ellipse cx="100" cy="70" rx="22" ry="14" fill="var(--canopy-mid)" opacity="0.5" transform="rotate(-20 100 70)"/><ellipse cx="140" cy="66" rx="20" ry="13" fill="var(--canopy-dark)" opacity="0.45" transform="rotate(18 140 66)"/><circle cx="112" cy="170" r="5" fill="var(--trunk-dark)" opacity="0.5"/><circle cx="130" cy="162" r="4" fill="var(--trunk-dark)" opacity="0.4"/></svg>
            <svg viewBox="0 0 260 310" xmlns="http://www.w3.org/2000/svg" class="tree-svg" data-stage="6"><ellipse cx="130" cy="280" rx="65" ry="10" fill="#00000015"/><ellipse cx="130" cy="272" rx="42" ry="8" fill="var(--trunk-dark)"/><path d="M98 262 Q98 278 112 278 L148 278 Q162 278 162 262 Z" fill="var(--trunk-fill)"/><ellipse cx="130" cy="262" rx="32" ry="6" fill="var(--trunk-dark)"/><ellipse cx="130" cy="282" rx="68" ry="11" fill="var(--magic-fill)" opacity="0.06"/><path d="M130 262 Q125 222 128 178 Q131 140 128 90" stroke="var(--trunk-fill)" stroke-width="18" stroke-linecap="round" fill="none"/><path d="M122 210 Q118 180 122 148" stroke="var(--trunk-dark)" stroke-width="14" stroke-linecap="round" fill="none"/><path d="M138 210 Q142 180 138 148" stroke="var(--trunk-fill)" stroke-width="12" stroke-linecap="round" fill="none"/><path d="M120 262 Q102 270 80 260" stroke="var(--trunk-fill)" stroke-width="9" stroke-linecap="round" fill="none"/><path d="M140 262 Q158 270 180 260" stroke="var(--trunk-dark)" stroke-width="8" stroke-linecap="round" fill="none"/><path d="M128 192 Q104 170 70 150" stroke="var(--trunk-fill)" stroke-width="9" stroke-linecap="round" fill="none"/><path d="M132 184 Q156 162 190 142" stroke="var(--trunk-dark)" stroke-width="9" stroke-linecap="round" fill="none"/><path d="M128 154 Q98 132 60 108" stroke="var(--trunk-fill)" stroke-width="7" stroke-linecap="round" fill="none"/><path d="M132 148 Q162 126 200 102" stroke="var(--trunk-dark)" stroke-width="7" stroke-linecap="round" fill="none"/><path d="M128 126 Q106 108 82 84" stroke="var(--trunk-fill)" stroke-width="6" stroke-linecap="round" fill="none"/><path d="M132 120 Q154 102 178 78" stroke="var(--trunk-dark)" stroke-width="6" stroke-linecap="round" fill="none"/><path d="M130 106 Q112 90 96 66" stroke="var(--trunk-fill)" stroke-width="5" stroke-linecap="round" fill="none"/><path d="M130 100 Q148 84 164 60" stroke="var(--trunk-dark)" stroke-width="5" stroke-linecap="round" fill="none"/><ellipse cx="70" cy="146" rx="36" ry="22" fill="var(--canopy-green)" transform="rotate(-10 70 146)"/><ellipse cx="190" cy="138" rx="34" ry="21" fill="var(--canopy-mid)" transform="rotate(10 190 138)"/><ellipse cx="60" cy="106" rx="32" ry="20" fill="var(--canopy-dark)" transform="rotate(-16 60 106)"/><ellipse cx="200" cy="100" rx="30" ry="19" fill="var(--canopy-green)" transform="rotate(14 200 100)"/><ellipse cx="82" cy="76" rx="28" ry="18" fill="var(--canopy-mid)" transform="rotate(-24 82 76)"/><ellipse cx="178" cy="72" rx="26" ry="17" fill="var(--canopy-dark)" transform="rotate(20 178 72)"/><ellipse cx="130" cy="50" rx="36" ry="22" fill="var(--canopy-green)"/><ellipse cx="106" cy="62" rx="24" ry="15" fill="var(--canopy-mid)" opacity="0.45" transform="rotate(-20 106 62)"/><ellipse cx="154" cy="58" rx="22" ry="14" fill="var(--canopy-dark)" opacity="0.4" transform="rotate(18 154 58)"/><circle cx="68" cy="118" r="11" fill="var(--magic-fill)" opacity="0.8"/><circle cx="84" cy="94" r="8" fill="var(--magic-fill)" opacity="0.7"/><circle cx="192" cy="110" r="10" fill="var(--magic-fill)" opacity="0.8"/><circle cx="176" cy="88" r="7" fill="var(--magic-fill)" opacity="0.65"/><circle cx="82" cy="68" r="9" fill="var(--magic-fill)" opacity="0.75"/><circle cx="178" cy="62" r="8" fill="var(--magic-fill)" opacity="0.7"/><circle cx="130" cy="42" r="11" fill="var(--magic-fill)" opacity="0.85"/><circle cx="110" cy="50" r="7" fill="var(--magic-fill)" opacity="0.6"/><circle cx="150" cy="46" r="8" fill="var(--magic-fill)" opacity="0.65"/></svg>
            <svg viewBox="0 0 300 340" xmlns="http://www.w3.org/2000/svg" class="tree-svg" data-stage="7"><ellipse cx="150" cy="310" rx="75" ry="11" fill="#00000015"/><ellipse cx="150" cy="300" rx="48" ry="9" fill="var(--trunk-dark)"/><path d="M112 288 Q112 306 128 306 L172 306 Q188 306 188 288 Z" fill="var(--trunk-fill)"/><ellipse cx="150" cy="288" rx="38" ry="7" fill="var(--trunk-dark)"/><ellipse cx="150" cy="240" rx="110" ry="140" fill="var(--magic-fill)" opacity="0.08"/><ellipse cx="150" cy="240" rx="130" ry="160" fill="var(--magic-fill)" opacity="0.04"/><path d="M150 288 Q144 240 148 190 Q152 140 150 80" stroke="var(--trunk-fill)" stroke-width="22" stroke-linecap="round" fill="none"/><path d="M140 230 Q136 195 141 160" stroke="var(--trunk-dark)" stroke-width="17" stroke-linecap="round" fill="none"/><path d="M160 230 Q164 195 159 160" stroke="var(--trunk-fill)" stroke-width="15" stroke-linecap="round" fill="none"/><path d="M138 288 Q115 298 90 286" stroke="var(--trunk-fill)" stroke-width="12" stroke-linecap="round" fill="none"/><path d="M162 288 Q185 298 210 286" stroke="var(--trunk-dark)" stroke-width="11" stroke-linecap="round" fill="none"/><path d="M125 282 Q105 290 80 278" stroke="var(--trunk-fill)" stroke-width="8" stroke-linecap="round" fill="none"/><path d="M175 282 Q195 290 220 278" stroke="var(--trunk-dark)" stroke-width="7" stroke-linecap="round" fill="none"/><path d="M148 210 Q118 185 80 160" stroke="var(--trunk-fill)" stroke-width="11" stroke-linecap="round" fill="none"/><path d="M152 202 Q182 178 220 154" stroke="var(--trunk-dark)" stroke-width="11" stroke-linecap="round" fill="none"/><path d="M148 168 Q110 140 60 108" stroke="var(--trunk-fill)" stroke-width="9" stroke-linecap="round" fill="none"/><path d="M152 162 Q190 134 240 102" stroke="var(--trunk-dark)" stroke-width="9" stroke-linecap="round" fill="none"/><path d="M148 136 Q118 112 88 80" stroke="var(--trunk-fill)" stroke-width="8" stroke-linecap="round" fill="none"/><path d="M152 130 Q182 106 212 74" stroke="var(--trunk-dark)" stroke-width="8" stroke-linecap="round" fill="none"/><path d="M150 112 Q130 92 110 64" stroke="var(--trunk-fill)" stroke-width="7" stroke-linecap="round" fill="none"/><path d="M150 106 Q170 86 190 58" stroke="var(--trunk-dark)" stroke-width="7" stroke-linecap="round" fill="none"/><path d="M150 96 Q135 80 120 54" stroke="var(--trunk-fill)" stroke-width="6" stroke-linecap="round" fill="none"/><path d="M150 92 Q165 76 180 50" stroke="var(--trunk-dark)" stroke-width="6" stroke-linecap="round" fill="none"/><ellipse cx="80" cy="156" rx="42" ry="26" fill="var(--canopy-green)" transform="rotate(-10 80 156)"/><ellipse cx="220" cy="150" rx="40" ry="25" fill="var(--canopy-mid)" transform="rotate(10 220 150)"/><ellipse cx="60" cy="108" rx="38" ry="24" fill="var(--canopy-dark)" transform="rotate(-16 60 108)"/><ellipse cx="240" cy="102" rx="36" ry="23" fill="var(--canopy-green)" transform="rotate(14 240 102)"/><ellipse cx="88" cy="76" rx="34" ry="22" fill="var(--canopy-mid)" transform="rotate(-24 88 76)"/><ellipse cx="212" cy="72" rx="32" ry="21" fill="var(--canopy-dark)" transform="rotate(20 212 72)"/><ellipse cx="110" cy="52" rx="30" ry="20" fill="var(--canopy-green)" transform="rotate(-30 110 52)"/><ellipse cx="190" cy="48" rx="28" ry="19" fill="var(--canopy-mid)" transform="rotate(26 190 48)"/><ellipse cx="150" cy="36" rx="40" ry="26" fill="var(--canopy-dark)"/><ellipse cx="130" cy="50" rx="26" ry="17" fill="var(--canopy-green)" opacity="0.4" transform="rotate(-18 130 50)"/><ellipse cx="170" cy="48" rx="24" ry="16" fill="var(--canopy-mid)" opacity="0.35" transform="rotate(16 170 48)"/><circle cx="78" cy="120" r="13" fill="var(--magic-fill)" opacity="0.8"/><circle cx="96" cy="94" r="10" fill="var(--magic-fill)" opacity="0.7"/><circle cx="222" cy="114" r="12" fill="var(--magic-fill)" opacity="0.8"/><circle cx="204" cy="90" r="9" fill="var(--magic-fill)" opacity="0.65"/><circle cx="112" cy="46" r="10" fill="var(--magic-fill)" opacity="0.75"/><circle cx="188" cy="42" r="9" fill="var(--magic-fill)" opacity="0.7"/><circle cx="150" cy="28" r="14" fill="var(--magic-fill)" opacity="0.9"/><circle cx="130" cy="36" r="8" fill="var(--magic-fill)" opacity="0.6"/><circle cx="170" cy="34" r="9" fill="var(--magic-fill)" opacity="0.65"/></svg>
          </div>

          <div class="tree-info-panel">
            <div class="tree-title-row">
              <h2><?= $current_tree['name'] ?></h2>
              <span class="tree-health-badge <?= $tree_health ?>"><?= $tree_health === 'healthy' ? '🌱 Healthy' : ($tree_health === 'recovering' ? '🔄 Recovering' : '🍂 Withered') ?></span>
            </div>
            <p class="tree-stage-desc"><?= $current_tree['desc'] ?></p>
            <div class="tree-meta-row">
              <div class="tree-meta-item"><span class="meta-label">Current Stage</span><span class="meta-value accent"><?= $user['plant_current_stage'] ?> / 7</span></div>
              <div class="tree-meta-item"><span class="meta-label">Highest Achieved</span><span class="meta-value gold">Stage <?= $user['plant_highest_stage'] ?></span></div>
              <div class="tree-meta-item"><span class="meta-label">Streak</span><span class="meta-value gold"><?= $user['current_streak'] ?> days</span></div>
              <?php if (!$stage_maxed): ?>
              <div class="tree-meta-item"><span class="meta-label">Progress to Stage <?= $user['plant_current_stage'] + 1 ?></span><span class="meta-value accent"><?= $stage_progress ?>%</span></div>
              <?php endif; ?>
            </div>

            <div class="stage-path">
              <?php for ($s = 1; $s <= 7; $s++):
                $dot_class = ($s < $user['plant_current_stage']) ? 'earned' : (($s == $user['plant_current_stage']) ? 'current' : '');
              ?>
              <div class="stage-step">
                <div class="stage-dot <?= $dot_class ?>" title="Stage <?= $s ?>: <?= $tree_stages[$s]['name'] ?> — <?= $s <= $user['plant_current_stage'] ? ($s == $user['plant_current_stage'] ? 'Current' : 'Achieved') : 'Locked' ?>"><?= $tree_stages[$s]['emoji'] ?></div>
                <?php if ($s < 7): ?><div class="stage-connector <?= $s < $user['plant_current_stage'] ? 'earned' : '' ?>"></div><?php endif; ?>
              </div>
              <?php endfor; ?>
            </div>

            <?php if ($tree_health !== 'healthy'): ?>
            <div class="recovery-bar-wrap">
              <div class="recovery-label"><span><?= $tree_health === 'recovering' ? '🔄 Recovery Progress' : '⚠️ Streak Reset' ?></span><span><?= $user['current_streak'] > 0 ? min(100, round($user['current_streak'] / 5 * 100)) : 0 ?>%</span></div>
              <div class="recovery-bar"><div class="recovery-fill" style="width:<?= $user['current_streak'] > 0 ? min(100, round($user['current_streak'] / 5 * 100)) : 0 ?>%;"></div></div>
              <span style="font-size:10px;color:var(--muted);">Attend 5 consecutive on-time days to restore tree health</span>
            </div>
            <?php endif; ?>
          </div>
        </section>

        <!-- ==================================================
             ROW 1 — ATTENDANCE + POINTS + STREAK
             ================================================== -->
        <div class="card-grid">
          <!-- Check In / Out (spans 2 columns for button width) -->
          <div class="glass-card col-span-2">
            <div class="card-header">
              <div class="card-title">Today's Attendance</div>
              <?php
                if ($has_checked_in) {
                    $badge_text = $has_checked_out ? '✅ Shift Completed' : '✅ Checked In';
                    $badge_color = 'var(--green-status)';
                } elseif ($is_on_approved_leave) {
                    $badge_text = '🏖️ On Approved Leave';
                    $badge_color = 'var(--blue-info)';
                } elseif ($is_non_working_day) {
                    $badge_text = $is_public_holiday ? "🎌 $holiday_name" : '📴 Weekend';
                    $badge_color = 'var(--purple-holiday)';
                } else {
                    $badge_text = '⚠️ Not Checked In';
                    $badge_color = 'var(--red-status)';
                }
              ?>
              <span style="font-size:12px;font-weight:600;color:<?= $badge_color ?>;"><?= $badge_text ?></span>
            </div>
            <div class="check-actions">
              <button class="btn-check btn-check-in" id="btn-check-in" <?= ($has_checked_in || $is_on_approved_leave || $is_non_working_day) ? 'disabled' : '' ?>><span class="btn-icon">✓</span> Check In<span class="btn-sub"><?= $is_on_approved_leave ? 'On Leave Today' : ($is_non_working_day ? $non_working_reason : $check_in_time) ?></span></button>
              <button class="btn-check btn-check-out" id="btn-check-out" <?= (!$has_checked_in || $has_checked_out) ? 'disabled' : '' ?>><span class="btn-icon">↩</span> Check Out<span class="btn-sub"><?= $check_out_time ?></span></button>
            </div>
            <div class="check-status-row">
              <div class="cs-item"><span class="cs-label">Check-in</span><span class="cs-value"><?= $check_in_time ?></span></div>
              <div class="cs-item"><span class="cs-label">Check-out</span><span class="cs-value"><?= $check_out_time ?></span></div>
              <div class="cs-item"><span class="cs-label">Status</span><span class="cs-value" style="color:var(--green-status);"><?= $attendance_status ?></span></div>
              <div class="cs-item"><span class="cs-label">Today's Pts</span><span class="cs-value" style="color:var(--accent);"><?= $today_points ?></span></div>
            </div>
          </div>

          <!-- Points + Streak -->
          <div class="glass-card">
            <div class="card-header"><div class="card-title">Your Stats</div></div>
            <div class="stat-big points">⭐ <?= number_format($user['total_points']) ?><span class="unit">pts</span></div>
            <div class="stat-big streak">🔥 <?= $user['current_streak'] ?><span class="unit">day streak</span></div>
            <div style="font-size:11px;color:var(--muted);">
              <?php if ($user['current_streak'] >= 5): ?>✅ 5-Day Bonus active (+5 pts/day)
              <?php elseif ($user['current_streak'] > 0): ?>🔒 <?= 5 - $user['current_streak'] ?> more days for 5-Day Bonus
              <?php else: ?>Check in on time to start a streak
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- ==================================================
             ROW 2 — QUICK SUMMARY + CALENDAR PREVIEW
             ================================================== -->
        <div class="summary-grid">
          <a href="leave.php" class="summary-card" title="View Leave & Calendar">
            <div class="sc-icon leave-bg">📅</div>
            <div class="sc-info">
              <span class="sc-value"><?= $al_remaining ?> AL / <?= $mc_remaining ?> MC</span>
              <span class="sc-label">Leave Balance<?= $pending_leave_count > 0 ? " · $pending_leave_count pending" : '' ?></span>
            </div>
          </a>

          <div class="summary-card">
            <div class="sc-icon rate-bg">📊</div>
            <div class="sc-info">
              <span class="sc-value"><?= $attendance_rate ?>%</span>
              <span class="sc-label">Attendance Rate · <?= $present_days ?> present / <?= $total_tracked ?> tracked</span>
            </div>
          </div>

          <a href="rewards_store.php" class="summary-card" title="Browse Rewards Store">
            <div class="sc-icon reward-bg">🎁</div>
            <div class="sc-info">
              <span class="sc-value"><?= $available_rewards ?></span>
              <span class="sc-label">Rewards Available · <?= number_format($user['total_points']) ?> pts</span>
            </div>
          </a>
        </div>

        <!-- ==================================================
             BADGE WALL
             ================================================== -->
        <?php if (count($earned_badges) > 0): ?>
        <div class="glass-card">
          <div class="card-header">
            <div class="card-title">🏆 Earned Badges</div>
            <span style="font-size:11px;color:var(--muted);"><?= count($earned_badges) ?> badge<?= count($earned_badges) !== 1 ? 's' : '' ?></span>
          </div>
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <?php foreach ($earned_badges as $badge): ?>
            <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:var(--radius-sm);background:oklch(94% 0.04 82 / 0.3);border:1px solid oklch(84% 0.1 82 / 0.4);">
              <span style="font-size:28px;"><?= htmlspecialchars($badge['badge_icon']) ?></span>
              <div>
                <div style="font-size:13px;font-weight:700;color:var(--fg);"><?= htmlspecialchars($badge['badge_name']) ?></div>
                <div style="font-size:10px;color:var(--muted);"><?= date('M d, Y', strtotime($badge['earned_at'])) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- ==================================================
             ROW 3 — CALENDAR PREVIEW
             ================================================== -->
        <div class="glass-card">
          <div class="card-header">
            <a href="leave.php" style="text-decoration:none;color:inherit;"><div class="card-title">📅 <?= date('F Y') ?> — Attendance Preview</div></a>
            <a href="leave.php" style="font-size:11px;color:var(--accent);font-weight:600;text-decoration:none;">Full Calendar →</a>
          </div>
          <div class="cal-mini" id="cal-grid"></div>
        </div>

      </div>
    </main>
  </div>

  <!-- Mobile bottom nav -->
  <?php include 'employee_bottom_nav.php'; ?>

  <script>
    const $ = (s) => document.querySelector(s);
    const $$ = (s) => document.querySelectorAll(s);

    // Activate correct tree SVG (data-driven only — no demo toggles)
    function setTreeStage(stage) {
      $$('.tree-svg').forEach(svg => {
        svg.classList.toggle('active', parseInt(svg.dataset.stage) === stage);
      });
    }
    setTreeStage(<?= $user['plant_current_stage'] ?>);

    // Check In / Out
    const state = {
      checkedIn: <?= $has_checked_in ? 'true' : 'false' ?>,
      checkedOut: <?= $has_checked_out ? 'true' : 'false' ?>,
    };

    function doCheckIn(lat, lng) {
      const body = { action: 'check_in' };
      if (lat !== null) { body.latitude = lat; body.longitude = lng; }
      fetch('process_attendance.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      }).then(res => res.json()).then(data => {
        if (data.success) {
          let msg = data.badge ? "🏆 30-Day Iron Man! +100 Bonus Points!" :
            (data.bonus_points > 0 ? `Checked in! +${data.base_points} Base + ${data.bonus_points} Streak Bonus Points!` :
            `Checked in! Points earned: ${data.base_points}`);
          alert(msg); location.reload();
        } else { alert(data.message); }
      });
    }

    function processCheckIn() {
      // Request browser geolocation (non-blocking — check-in proceeds either way)
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
          function(pos) { doCheckIn(pos.coords.latitude, pos.coords.longitude); },
          function(err) {
            // Permission denied, unavailable, or timeout — proceed without GPS
            console.log('Geolocation skipped: ' + err.message);
            doCheckIn(null, null);
          },
          { timeout: 8000, maximumAge: 60000 }
        );
      } else {
        doCheckIn(null, null);
      }
    }

    function processCheckOut() {
      fetch('process_attendance.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'check_out' })
      }).then(() => location.reload());
    }

    $('#btn-check-in').addEventListener('click', () => { if (!state.checkedIn) processCheckIn(); });
    $('#btn-check-out').addEventListener('click', () => { if (state.checkedIn && !state.checkedOut) processCheckOut(); });

    // Mini calendar (display only)
    (function() {
      const calData = <?= json_encode($cal_data) ?>;
      const now = new Date();
      const calMonth = now.getMonth();
      const calYear = now.getFullYear();
      const firstDay = new Date(calYear, calMonth, 1).getDay();
      const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
      const today = now.getDate();
      let html = ['S','M','T','W','T','F','S'].map(h => `<div class="cal-day-header">${h}</div>`).join('');
      for (let i = 0; i < firstDay; i++) html += '<div class="cal-day other-month"></div>';
      for (let d = 1; d <= daysInMonth; d++) {
        const key = `${calYear}-${String(calMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const s = calData[key] || '';
        let cls = 'cal-day';
        if (d === today) cls += ' today';
        if (['on_time','grace_period','late'].includes(s)) cls += ' present';
        else if (s === 'absent') cls += ' absent';
        else if (s.startsWith('leave_') || s.startsWith('on_leave_')) cls += ' leave';
        html += `<div class="${cls}">${d}</div>`;
      }
      $('#cal-grid').innerHTML = html;
    })();
  </script>
</body>
</html>