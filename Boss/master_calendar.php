<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$active_page = 'calendar';
$month = isset($_GET['m']) ? $_GET['m'] : date('m');
$year = isset($_GET['y']) ? $_GET['y'] : date('Y');
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

// Prev / next month calculation
$prev_month = $month - 1; $prev_year = $year;
if ($prev_month == 0) { $prev_month = 12; $prev_year--; }
$next_month = $month + 1; $next_year = $year;
if ($next_month == 13) { $next_month = 1; $next_year++; }

// 1. Fetch all attendance for this month
$attendance_data = [];
$stmt = $pdo->prepare("
    SELECT a.date, a.status, u.name, u.id as user_id
    FROM attendance a
    JOIN users u ON a.user_id = u.id
    WHERE MONTH(a.date) = ? AND YEAR(a.date) = ?
");
$stmt->execute([$month, $year]);
while ($row = $stmt->fetch()) {
    $attendance_data[$row['date']][] = ['name' => $row['name'], 'status' => $row['status'], 'user_id' => $row['user_id']];
}

// 2. Fetch all APPROVED leaves for this month
$stmt = $pdo->prepare("
    SELECT lr.start_date, lr.end_date, lr.leave_type, u.name, u.id as user_id
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    WHERE lr.status = 'approved'
");
$stmt->execute();
while ($row = $stmt->fetch()) {
    $current = strtotime($row['start_date']);
    $last = strtotime($row['end_date']);
    while ($current <= $last) {
        $date_str = date('Y-m-d', $current);
        // Only add to array if it's within the viewed month
        if (date('m', $current) == $month && date('Y', $current) == $year) {
            $attendance_data[$date_str][] = ['name' => $row['name'], 'status' => 'leave_' . $row['leave_type'], 'user_id' => $row['user_id']];
        }
        $current = strtotime('+1 day', $current);
    }
}

// 3. Fetch public holidays for this month
$public_holidays = [];
$stmt = $pdo->prepare("SELECT holiday_date, holiday_name FROM public_holidays WHERE MONTH(holiday_date) = ? AND YEAR(holiday_date) = ?");
$stmt->execute([$month, $year]);
while ($row = $stmt->fetch()) {
    $public_holidays[$row['holiday_date']] = $row['holiday_name'];
}

// 4. Compute statistics for the month
$stat_present_days = 0;
$stat_absent_days = 0;
$stat_leave_days = 0;
$stat_holiday_days = 0;
$unique_employees = [];

foreach ($attendance_data as $date => $records) {
    foreach ($records as $record) {
        $s = $record['status'];
        if (in_array($s, ['on_time', 'grace_period', 'late'])) {
            $stat_present_days++;
        } elseif ($s === 'absent') {
            $stat_absent_days++;
        } elseif (strpos($s, 'leave_') === 0) {
            $stat_leave_days++;
        }
        if (!in_array($record['user_id'], $unique_employees)) {
            $unique_employees[] = $record['user_id'];
        }
    }
}
$stat_holiday_days = count($public_holidays);
$total_tracked = $stat_present_days + $stat_absent_days;
$attendance_rate = $total_tracked > 0 ? round(($stat_present_days / $total_tracked) * 100) : 0;

// 5. Get all employees for the employee filter
$stmt = $pdo->query("SELECT id, name FROM users WHERE role = 'employee' ORDER BY name");
$all_employees = $stmt->fetchAll();

// Month label
$month_label = date('F Y', mktime(0, 0, 0, $month, 1, $year));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>Master Calendar — LeafPoint</title>
  <style>
    /* ============================================================
       DESIGN TOKENS — LeafPoint System
       ============================================================ */
    :root {
      --bg: oklch(96.5% 0.006 245);
      --bg-gradient: radial-gradient(ellipse at 50% 0%, oklch(90% 0.04 170 / 0.2), oklch(97% 0.004 245) 55%);
      --surface-glass: rgba(255, 255, 255, 0.55);
      --surface-glass-hover: rgba(255, 255, 255, 0.74);
      --surface-solid: #ffffff;
      --fg: oklch(16% 0.018 252); --fg-secondary: oklch(38% 0.022 250); --muted: oklch(54% 0.016 250);
      --border-glass: rgba(255, 255, 255, 0.4); --border-subtle: rgba(0, 0, 0, 0.055);
      --accent: oklch(56% 0.19 148); --accent-soft: oklch(74% 0.14 148);
      --accent-glow: oklch(62% 0.21 148 / 0.35); --gold: oklch(70% 0.19 82);
      --sidebar-bg: oklch(13% 0.02 252); --sidebar-fg: oklch(84% 0.006 250); --sidebar-muted: oklch(60% 0.016 250);

      --green-present: oklch(62% 0.18 145);
      --red-absent: oklch(55% 0.22 22);
      --blue-annual: oklch(58% 0.17 255);
      --yellow-medical: oklch(70% 0.16 88);
      --purple-holiday: oklch(55% 0.18 310);
      --gray-unpaid: oklch(70% 0.005 250);

      --radius-sm: 10px;
      --radius-md: 16px;
      --radius-lg: 22px;
      --radius-xl: 28px;
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

    .main { flex: 1; overflow-y: auto; overflow-x: hidden; background: var(--bg-gradient, var(--bg)); display: flex; flex-direction: column; }
    .main-inner { padding: 24px 30px 36px; display: flex; flex-direction: column; gap: 20px; max-width: 1300px; width: 100%; }

    .page { width: 100%; display: flex; flex-direction: column; gap: 16px; }

    /* ---- Top bar ---- */
    .topbar {
      position: sticky; top: 0; z-index: 25;
      display: flex; align-items: center; justify-content: space-between;
      padding: 14px 0 10px;
      background: oklch(97% 0.005 245 / 0.85);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      gap: 12px;
      flex-wrap: wrap;
    }
    .topbar .topbar-left {
      display: flex; align-items: center; gap: 10px;
    }
    .topbar .back-btn {
      width: 36px; height: 36px; border-radius: 50%;
      background: var(--surface-glass); border: 1px solid var(--border-subtle);
      display: grid; place-items: center; font-size: 18px;
      cursor: pointer; transition: background 0.15s; color: var(--fg);
      text-decoration: none; flex-shrink: 0;
    }
    .topbar .back-btn:hover { background: var(--surface-glass-hover); }
    .topbar .title {
      font-family: var(--font-display); font-size: 18px; font-weight: 700;
      letter-spacing: -0.02em; white-space: nowrap;
    }
    .topbar .admin-badge {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 5px 12px; border-radius: 999px;
      font-size: 11px; font-weight: 700; letter-spacing: 0.02em;
      background: oklch(92% 0.04 310); color: oklch(38% 0.1 310);
      white-space: nowrap;
    }
    .topbar .today-btn {
      padding: 8px 16px; border-radius: 999px;
      border: 1.5px solid var(--accent); background: transparent;
      color: var(--accent); font-size: 12px; font-weight: 700;
      cursor: pointer; transition: all 0.15s;
      font-family: var(--font-body); white-space: nowrap;
    }
    .topbar .today-btn:hover { background: var(--accent); color: #fff; }

    /* ---- Glass card ---- */
    .card {
      background: var(--surface-glass);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      padding: 18px;
      display: flex; flex-direction: column; gap: 14px;
      transition: box-shadow 0.2s, background 0.2s;
    }
    .card:hover { background: var(--surface-glass-hover); box-shadow: var(--shadow-card-hover); }
    .card-header {
      display: flex; align-items: center; justify-content: space-between; gap: 10px;
      flex-wrap: wrap;
    }
    .card-title {
      font-family: var(--font-display); font-size: 15px; font-weight: 700;
      letter-spacing: -0.01em;
    }
    .card-subtitle { font-size: 11px; color: var(--muted); }

    /* ============================================================
       MONTH NAVIGATION
       ============================================================ */
    .month-nav {
      display: flex; align-items: center; justify-content: center; gap: 16px;
    }
    .month-nav .nav-arrow {
      width: 40px; height: 40px; border-radius: 50%;
      background: var(--surface-glass); border: 1px solid var(--border-subtle);
      display: grid; place-items: center; font-size: 20px;
      cursor: pointer; transition: all 0.2s; color: var(--fg);
      text-decoration: none;
    }
    .month-nav .nav-arrow:hover {
      background: var(--surface-glass-hover);
      border-color: var(--accent);
      color: var(--accent);
    }
    .month-nav .month-label {
      font-family: var(--font-display); font-size: 20px; font-weight: 800;
      letter-spacing: -0.02em; color: var(--fg);
      min-width: 180px; text-align: center;
    }

    /* ============================================================
       STATS GRID
       ============================================================ */
    .stats-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
    }
    .stat-card {
      background: rgba(0,0,0,0.02);
      border-radius: var(--radius-sm);
      padding: 14px;
      display: flex; flex-direction: column; gap: 4px;
      transition: transform 0.15s;
    }
    .stat-card:hover { transform: translateY(-1px); }
    .stat-card .stat-icon { font-size: 22px; line-height: 1; }
    .stat-card .stat-label {
      font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em;
      color: var(--muted); font-weight: 700;
    }
    .stat-card .stat-value {
      font-family: var(--font-display); font-size: 26px; font-weight: 800;
      letter-spacing: -0.02em;
    }
    .stat-card.present-stat .stat-value { color: var(--green-present); }
    .stat-card.absent-stat .stat-value { color: var(--red-absent); }
    .stat-card.leave-stat .stat-value { color: var(--blue-annual); }
    .stat-card.rate-stat .stat-value { color: var(--accent); }

    /* Rate ring */
    .attendance-rate-ring {
      display: flex; align-items: center; gap: 14px;
      padding: 12px 0;
    }
    .rate-circle {
      width: 80px; height: 80px; border-radius: 50%;
      background: conic-gradient(var(--accent) 0deg 324deg, oklch(90% 0.006 250) 324deg 360deg);
      display: grid; place-items: center; flex-shrink: 0;
    }
    .rate-circle .rate-inner {
      width: 62px; height: 62px; border-radius: 50%;
      background: var(--surface-solid);
      display: grid; place-items: center;
      font-family: var(--font-display); font-size: 20px; font-weight: 800;
      color: var(--accent);
    }
    .rate-info { display: flex; flex-direction: column; gap: 3px; }
    .rate-info .rate-label { font-size: 11px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
    .rate-info .rate-detail { font-size: 13px; color: var(--fg-secondary); }

    /* ============================================================
       FILTERS
       ============================================================ */
    .filter-row {
      display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
    }
    .filter-chips {
      display: flex; flex-wrap: wrap; gap: 6px;
    }
    .filter-chip {
      padding: 7px 14px; border-radius: 999px;
      border: 1.5px solid var(--border-subtle);
      background: transparent; color: var(--fg-secondary);
      font-size: 12px; font-weight: 600; cursor: pointer;
      transition: all 0.2s; font-family: var(--font-body);
      letter-spacing: -0.01em; white-space: nowrap;
    }
    .filter-chip:hover { border-color: var(--accent-soft); color: var(--accent); }
    .filter-chip.active {
      background: var(--accent); color: #fff;
      border-color: var(--accent);
      box-shadow: 0 2px 10px oklch(56% 0.19 148 / 0.3);
    }
    .filter-select {
      padding: 8px 32px 8px 12px;
      border: 1.5px solid var(--border-subtle);
      border-radius: 999px;
      font-family: var(--font-body); font-size: 12px; font-weight: 600;
      color: var(--fg); background: var(--surface-solid);
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1.5l5 5 5-5' stroke='%23888' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 10px center;
      -webkit-appearance: none; appearance: none; outline: none;
      cursor: pointer; transition: border-color 0.2s;
    }
    .filter-select:focus { border-color: var(--accent); }

    /* ============================================================
       CALENDAR GRID
       ============================================================ */
    .calendar-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 4px;
    }
    .cal-day-header {
      text-align: center;
      font-size: 10px; text-transform: uppercase; letter-spacing: 0.07em;
      color: var(--muted); font-weight: 700;
      padding: 6px 0;
    }
    .cal-day-header.weekend { color: oklch(55% 0.05 10 / 0.5); }

    .cal-cell {
      border-radius: var(--radius-sm);
      display: flex; flex-direction: column;
      padding: 6px 8px; gap: 3px;
      min-height: 80px;
      background: rgba(0,0,0,0.015);
      border: 1.5px solid transparent;
      transition: all 0.2s;
      overflow: hidden;
      cursor: pointer;
    }
    .cal-cell:hover {
      border-color: var(--accent-soft);
      box-shadow: 0 2px 12px rgba(0,0,0,0.06);
      z-index: 2; position: relative;
      background: rgba(0,0,0,0.03);
    }
    .cal-cell.today {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px oklch(56% 0.19 148 / 0.15);
    }
    .cal-cell.has-holiday {
      background: oklch(92% 0.06 310 / 0.25);
      border-color: oklch(82% 0.07 310 / 0.3);
    }
    .cal-cell .day-num {
      font-size: 13px; font-weight: 600; text-align: right;
      color: var(--muted); line-height: 1;
    }
    .cal-cell.today .day-num { color: var(--accent); font-weight: 800; }
    .cal-cell .holiday-name {
      font-size: 9px; font-weight: 700; color: var(--purple-holiday);
      text-align: center; padding: 1px 4px; line-height: 1.3;
      background: oklch(93% 0.05 310 / 0.4);
      border-radius: 3px;
    }
    .cal-cell .cal-stat {
      font-size: 10px; font-weight: 600; display: flex; align-items: center; gap: 4px;
      line-height: 1.4;
    }
    .cal-cell .cal-stat.present { color: var(--green-present); }
    .cal-cell .cal-stat.leave   { color: var(--blue-annual); }
    .cal-cell .cal-stat.absent  { color: var(--red-absent); }

    /* Day Detail Modal */
    .day-modal-overlay {
      position: fixed; inset: 0; z-index: 50;
      background: rgba(0,0,0,0.35); backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
      display: flex; align-items: center; justify-content: center;
      padding: 20px; opacity: 0; pointer-events: none;
      transition: opacity 0.25s;
    }
    .day-modal-overlay.open { opacity: 1; pointer-events: auto; }
    .day-modal {
      background: var(--surface-solid); border-radius: var(--radius-xl);
      box-shadow: 0 20px 60px rgba(0,0,0,0.18);
      padding: 24px; max-width: 520px; width: 100%; max-height: 80vh;
      display: flex; flex-direction: column; gap: 14px;
      transform: translateY(20px); transition: transform 0.3s;
    }
    .day-modal-overlay.open .day-modal { transform: translateY(0); }
    .day-modal-header { display: flex; align-items: center; justify-content: space-between; }
    .day-modal-title { font-family: var(--font-display); font-size: 17px; font-weight: 700; }
    .day-modal-close { width: 32px; height: 32px; border-radius: 50%; border: 1px solid var(--border-subtle); background: none; cursor: pointer; font-size: 16px; display: grid; place-items: center; }
    .day-modal-stats { display: flex; gap: 16px; }
    .day-modal-stat { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; }
    .day-modal-list { display: flex; flex-direction: column; gap: 4px; max-height: 400px; overflow-y: auto; }
    .day-modal-row { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 6px; font-size: 12px; }
    .day-modal-row:nth-child(odd) { background: rgba(0,0,0,0.015); }
    .day-modal-row .dm-name { flex: 1; font-weight: 600; }
    .day-modal-row .dm-status { font-size: 10px; padding: 2px 8px; border-radius: 999px; font-weight: 700; color: #fff; }
    .day-modal-row .dm-status.present { background: var(--green-present); }
    .day-modal-row .dm-status.absent { background: var(--red-absent); }
    .day-modal-row .dm-status.leave { background: var(--blue-annual); }

    /* ============================================================
       LEGEND
       ============================================================ */
    .legend {
      display: flex; flex-wrap: wrap; gap: 10px;
    }
    .legend-item {
      display: inline-flex; align-items: center; gap: 6px;
      font-size: 11px; font-weight: 600; color: var(--fg-secondary);
    }
    .legend-dot {
      width: 10px; height: 10px; border-radius: 3px;
      flex-shrink: 0;
    }
    .legend-dot.present { background: var(--green-present); }
    .legend-dot.absent { background: var(--red-absent); }
    .legend-dot.annual { background: var(--blue-annual); }
    .legend-dot.medical { background: var(--yellow-medical); }
    .legend-dot.unpaid { background: var(--gray-unpaid); }
    .legend-dot.holiday { background: var(--purple-holiday); }

    /* ---- Empty day ---- */
    .cal-cell.empty-day {
      background: transparent; border: none; min-height: 0;
    }
    .cal-cell.empty-day:hover { box-shadow: none; border-color: transparent; }

    /* ---- Bottom nav (mobile) ---- */
    .bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 30; height: 64px; background: oklch(98% 0.003 250 / 0.92); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border-top: 1px solid rgba(0,0,0,0.06); align-items: center; justify-content: space-around; padding: 0 8px; padding-bottom: env(safe-area-inset-bottom, 0px); }
    .bottom-nav a { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 6px 10px; border: none; background: none; font: 10px/1 var(--font-body); color: var(--muted); cursor: pointer; transition: color 0.15s; font-weight: 500; text-decoration: none; }
    .bottom-nav a .nav-icon { font-size: 20px; line-height: 1; }
    .bottom-nav a.active { color: var(--accent); font-weight: 700; }

    /* ---- Responsive ---- */
    @media (min-width: 640px) {
      .topbar { padding: 16px 0 12px; }
      .topbar .title { font-size: 20px; }
      .stats-grid { grid-template-columns: repeat(4, 1fr); gap: 12px; }
      .stat-card .stat-value { font-size: 28px; }
      .calendar-grid { gap: 5px; }
      .cal-cell { min-height: 120px; padding: 6px; }
      .cal-day-header { font-size: 11px; }
      .badge { font-size: 11px; }
      .month-nav .month-label { font-size: 24px; min-width: 220px; }
    }
    @media (min-width: 1024px) {
      .card { padding: 24px 28px; }
      .card-title { font-size: 17px; }
      .calendar-grid { gap: 6px; }
      .cal-cell { min-height: 130px; padding: 8px; }
      .cal-day-header { font-size: 12px; padding: 8px 0; }
      .stat-card { padding: 18px; }
      .stat-card .stat-value { font-size: 32px; }
      .legend-item { font-size: 12px; }
    }
    @media (max-width: 800px) { .sidebar { width: 210px; min-width: 210px; } .main-inner { padding: 16px 12px 80px; } }
    @media (max-width: 660px) { .sidebar { display: none; } .bottom-nav { display: flex; } .main-inner { padding: 14px 10px 80px; } }

    @media (max-width: 500px) {
      .calendar-grid { gap: 2px; }
      .cal-cell { min-height: 80px; padding: 2px; }
      .badge { font-size: 8px; padding: 2px 4px; gap: 2px; }
      .cal-cell .day-num { font-size: 11px; }
      .month-nav .month-label { font-size: 16px; min-width: 130px; }
      .month-nav .nav-arrow { width: 32px; height: 32px; font-size: 16px; }
    }
  </style>
</head>
<body>

  <div class="app">
    <?php include 'boss_sidebar.php'; ?>

    <main class="main">
      <div class="main-inner">
        <div class="page">

    <!-- Top bar -->
    <header class="topbar">
      <div class="topbar-left">
        <span class="title">📅 Master Calendar</span>
        <span class="admin-badge">👑 Admin</span>
      </div>
      <a class="today-btn" href="?m=<?= date('m') ?>&y=<?= date('Y') ?>">Today</a>
    </header>

    <!-- ======================================================
         SECTION 1 — STATS + RATE
         ====================================================== -->
    <section class="card" id="stats-card">
      <div class="card-header">
        <span class="card-title">📊 Monthly Overview — <?= $month_label ?></span>
        <span class="card-subtitle"><?= count($unique_employees) ?> employee<?= count($unique_employees) !== 1 ? 's' : '' ?> tracked</span>
      </div>

      <div class="attendance-rate-ring">
        <div class="rate-circle" id="rate-circle">
          <div class="rate-inner" id="rate-value"><?= $attendance_rate ?>%</div>
        </div>
        <div class="rate-info">
          <span class="rate-label">Overall Attendance Rate</span>
          <span class="rate-detail"><?= $stat_present_days ?> present of <?= $total_tracked ?> tracked employee-days</span>
        </div>
      </div>

      <div class="stats-grid">
        <div class="stat-card present-stat">
          <span class="stat-icon">✅</span>
          <span class="stat-label">Present Days</span>
          <span class="stat-value"><?= $stat_present_days ?></span>
        </div>
        <div class="stat-card absent-stat">
          <span class="stat-icon">❌</span>
          <span class="stat-label">Absent Days</span>
          <span class="stat-value"><?= $stat_absent_days ?></span>
        </div>
        <div class="stat-card leave-stat">
          <span class="stat-icon">🏖️</span>
          <span class="stat-label">Leave Days</span>
          <span class="stat-value"><?= $stat_leave_days ?></span>
        </div>
        <div class="stat-card rate-stat">
          <span class="stat-icon">📈</span>
          <span class="stat-label">Attendance Rate</span>
          <span class="stat-value"><?= $attendance_rate ?>%</span>
        </div>
      </div>
    </section>

    <!-- ======================================================
         SECTION 2 — CALENDAR
         ====================================================== -->
    <section class="card" id="calendar-card">
      <!-- Month Navigation -->
      <div class="month-nav">
        <a class="nav-arrow" href="?m=<?= $prev_month ?>&y=<?= $prev_year ?>" aria-label="Previous month">‹</a>
        <span class="month-label"><?= $month_label ?></span>
        <a class="nav-arrow" href="?m=<?= $next_month ?>&y=<?= $next_year ?>" aria-label="Next month">›</a>
      </div>

      <!-- Filters -->
      <div class="filter-row">
        <div class="filter-chips" id="filter-chips">
          <button class="filter-chip active" data-filter="all">Show All</button>
          <button class="filter-chip" data-filter="present">🟩 Present</button>
          <button class="filter-chip" data-filter="absent">🟥 Absent</button>
          <button class="filter-chip" data-filter="leave">🏖️ Leave</button>
        </div>
        <select class="filter-select" id="employee-filter" style="margin-left:auto;">
          <option value="all">All Employees</option>
          <?php foreach ($all_employees as $emp): ?>
          <option value="<?= htmlspecialchars($emp['name']) ?>"><?= htmlspecialchars($emp['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Calendar Grid -->
      <div class="calendar-grid" id="calendar-grid">
        <?php
        $weekday_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        foreach ($weekday_names as $i => $dname):
            $is_weekend = ($i === 0 || $i === 6);
        ?>
        <div class="cal-day-header<?= $is_weekend ? ' weekend' : '' ?>"><?= $dname ?></div>
        <?php endforeach; ?>

        <?php
        $first_day_of_month = date('w', mktime(0, 0, 0, $month, 1, $year));
        $today_str = date('Y-m-d');

        // Empty cells before first day
        for ($i = 0; $i < $first_day_of_month; $i++) {
            echo "<div class='cal-cell empty-day'></div>";
        }

        // Store day detail data for JS modal
        $day_details_json = [];

        for ($day = 1; $day <= $days_in_month; $day++) {
            $current_date = sprintf("%04d-%02d-%02d", $year, $month, $day);
            $is_today = ($current_date === $today_str);
            $is_holiday = isset($public_holidays[$current_date]);
            $holiday_name = $is_holiday ? $public_holidays[$current_date] : '';

            // Count summary stats
            $cnt_present = 0;
            $cnt_leave = 0;
            $cnt_absent = 0;
            $day_records = [];

            if (isset($attendance_data[$current_date])) {
                foreach ($attendance_data[$current_date] as $record) {
                    $stat = $record['status'];
                    if (in_array($stat, ['on_time', 'grace_period', 'late'])) {
                        $cnt_present++;
                        $day_records[] = ['name' => $record['name'], 'status' => 'present'];
                    } elseif ($stat === 'absent') {
                        $cnt_absent++;
                        $day_records[] = ['name' => $record['name'], 'status' => 'absent'];
                    } elseif (strpos($stat, 'leave_') === 0) {
                        $cnt_leave++;
                        $day_records[] = ['name' => $record['name'], 'status' => 'leave'];
                    }
                }
            }
            $day_details_json[$current_date] = $day_records;

            $cell_class = 'cal-cell';
            if ($is_today) $cell_class .= ' today';
            if ($is_holiday) $cell_class .= ' has-holiday';

            echo "<div class='$cell_class' data-date='$current_date' onclick='openDayDetail(\"$current_date\")'>";
            echo "<div class='day-num'>$day</div>";

            if ($is_holiday) {
                echo "<div class='holiday-name'>🎌 " . htmlspecialchars($holiday_name) . "</div>";
            }

            if ($cnt_present + $cnt_leave + $cnt_absent > 0) {
                echo "<div class='cal-stat present'>✓ $cnt_present Present</div>";
                if ($cnt_leave > 0) echo "<div class='cal-stat leave'>🏖️ $cnt_leave Leave</div>";
                if ($cnt_absent > 0) echo "<div class='cal-stat absent'>✕ $cnt_absent Absent</div>";
            }

            echo "</div>";
        }
        ?>
      </div>

      <!-- Legend -->
      <div class="legend">
        <span class="legend-item"><span class="legend-dot present"></span> Present</span>
        <span class="legend-item"><span class="legend-dot absent"></span> Absent</span>
        <span class="legend-item"><span class="legend-dot annual"></span> Annual Leave</span>
        <span class="legend-item"><span class="legend-dot medical"></span> Medical Leave</span>
        <span class="legend-item"><span class="legend-dot unpaid"></span> Unpaid Leave</span>
        <span class="legend-item"><span class="legend-dot holiday"></span> Public Holiday</span>
      </div>
    </section>

        </div><!-- /.page -->
      </div><!-- /.main-inner -->
    </main>
  </div><!-- /.app -->

  <!-- Day Detail Modal -->
  <div class="day-modal-overlay" id="day-modal-overlay">
    <div class="day-modal">
      <div class="day-modal-header">
        <span class="day-modal-title" id="dm-title">—</span>
        <button class="day-modal-close" onclick="closeDayDetail()">✕</button>
      </div>
      <div class="day-modal-stats" id="dm-stats"></div>
      <div class="day-modal-list" id="dm-list"></div>
    </div>
  </div>

  <?php include 'boss_bottom_nav.php'; ?>

  <script>
    const $ = (s) => document.querySelector(s);
    const $$ = (s) => document.querySelectorAll(s);

    /* ---- Day detail data from PHP ---- */
    const dayDetails = <?= json_encode($day_details_json) ?>;

    /* ---- Day Detail Modal ---- */
    function openDayDetail(dateStr) {
      const records = dayDetails[dateStr] || [];
      const d = new Date(dateStr + 'T00:00:00');
      const dateLabel = d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

      let present = 0, leave = 0, absent = 0;
      let rowsHtml = '';

      records.forEach(r => {
        if (r.status === 'present') present++;
        else if (r.status === 'leave') leave++;
        else absent++;

        const statusLabel = r.status === 'present' ? 'Present' : (r.status === 'leave' ? 'Leave' : 'Absent');
        rowsHtml += `<div class="day-modal-row">
          <span class="dm-name">${r.name}</span>
          <span class="dm-status ${r.status}">${statusLabel}</span>
        </div>`;
      });

      if (rowsHtml === '') {
        rowsHtml = '<div style="text-align:center;padding:20px;color:var(--muted);">No attendance records for this day.</div>';
      }

      document.getElementById('dm-title').textContent = '📅 ' + dateLabel;
      document.getElementById('dm-stats').innerHTML =
        `<div class="day-modal-stat" style="color:var(--green-status);">✓ ${present} Present</div>` +
        `<div class="day-modal-stat" style="color:var(--blue-annual);">🏖️ ${leave} Leave</div>` +
        `<div class="day-modal-stat" style="color:var(--red-status);">✕ ${absent} Absent</div>`;
      document.getElementById('dm-list').innerHTML = rowsHtml;
      document.getElementById('day-modal-overlay').classList.add('open');
    }

    function closeDayDetail() {
      document.getElementById('day-modal-overlay').classList.remove('open');
    }

    document.getElementById('day-modal-overlay').addEventListener('click', function(e) {
      if (e.target === this) closeDayDetail();
    });
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') closeDayDetail();
    });

    /* ---- Filter chips ---- */
    $$('#filter-chips .filter-chip').forEach(chip => {
      chip.addEventListener('click', function() {
        $$('#filter-chips .filter-chip').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
      });
    });

    /* ---- Rate ring animation ---- */
    const rate = <?= $attendance_rate ?>;
    const rateCircle = $('#rate-circle');
    if (rateCircle) {
      const ringAngle = (rate / 100) * 360;
      rateCircle.style.background = `conic-gradient(var(--accent) 0deg ${ringAngle}deg, oklch(90% 0.006 250) ${ringAngle}deg 360deg)`;
    }

    /* ---- Keyboard: Alt+arrows for month nav ---- */
    document.addEventListener('keydown', function(e) {
      if (e.altKey) {
        if (e.key === 'ArrowLeft') {
          window.location.href = '?m=<?= $prev_month ?>&y=<?= $prev_year ?>';
        } else if (e.key === 'ArrowRight') {
          window.location.href = '?m=<?= $next_month ?>&y=<?= $next_year ?>';
        } else if (e.key === 't') {
          window.location.href = '?m=<?= date('m') ?>&y=<?= date('Y') ?>';
        }
      }
    });
  </script>

</body>
</html>