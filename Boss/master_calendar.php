<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

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
      --bg: oklch(97% 0.005 245);
      --bg-gradient: radial-gradient(ellipse at 50% 0%, oklch(90% 0.04 170 / 0.2), oklch(97% 0.004 245) 55%);
      --surface-glass: rgba(255, 255, 255, 0.55);
      --surface-glass-hover: rgba(255, 255, 255, 0.74);
      --surface-solid: #ffffff;
      --fg: oklch(16% 0.018 252);
      --fg-secondary: oklch(38% 0.022 250);
      --muted: oklch(54% 0.016 250);
      --border-glass: rgba(255, 255, 255, 0.4);
      --border-subtle: rgba(0, 0, 0, 0.055);

      --accent: oklch(56% 0.19 148);
      --accent-soft: oklch(74% 0.14 148);
      --accent-glow: oklch(62% 0.21 148 / 0.35);
      --gold: oklch(70% 0.19 82);

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
    html, body {
      width: 100%; min-height: 100%;
      font-family: var(--font-body);
      color: var(--fg);
      background: var(--bg);
      -webkit-font-smoothing: antialiased;
      overflow-x: hidden;
    }

    .page {
      max-width: 100%;
      margin: 0 auto;
      padding: 0 16px 32px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      min-height: 100vh;
    }

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
      padding: 4px; gap: 2px;
      min-height: 110px;
      background: rgba(0,0,0,0.015);
      border: 1.5px solid transparent;
      transition: all 0.2s;
      overflow: hidden;
    }
    .cal-cell:hover {
      border-color: var(--accent-soft);
      box-shadow: 0 2px 12px rgba(0,0,0,0.06);
      z-index: 2;
      position: relative;
    }
    .cal-cell.today {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px oklch(56% 0.19 148 / 0.15);
    }
    .cal-cell.has-holiday {
      background: oklch(92% 0.06 310 / 0.3);
      border-color: oklch(82% 0.07 310 / 0.3);
    }
    .cal-cell .day-num {
      font-size: 13px; font-weight: 600; text-align: right;
      color: var(--muted); margin-bottom: 2px;
    }
    .cal-cell.today .day-num {
      color: var(--accent); font-weight: 800;
    }
    .cal-cell .holiday-name {
      font-size: 10px; font-weight: 700; color: var(--purple-holiday);
      text-align: center; padding: 2px 4px;
      background: oklch(93% 0.05 310 / 0.5);
      border-radius: 4px; margin-bottom: 2px;
    }

    /* Employee badges */
    .badge {
      font-size: 10px; padding: 3px 6px; border-radius: 4px;
      color: #fff; display: flex; align-items: center; gap: 4px;
      font-weight: 600; overflow: hidden; white-space: nowrap;
      text-overflow: ellipsis; cursor: default;
      transition: opacity 0.2s;
    }
    .badge:hover { opacity: 0.85; }
    .badge.bg-present { background-color: var(--green-present); }
    .badge.bg-absent { background-color: var(--red-absent); }
    .badge.bg-al { background-color: var(--blue-annual); }
    .badge.bg-mc { background-color: var(--yellow-medical); }
    .badge.bg-ul { background-color: var(--gray-unpaid); }
    .badge.bg-holiday { background-color: var(--purple-holiday); }
    .badge.filtered-out { display: none; }

    .cal-cell .more-indicator {
      font-size: 10px; color: var(--muted); text-align: center;
      font-weight: 600; cursor: pointer;
    }

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

    /* ---- Responsive ---- */
    @media (min-width: 640px) {
      .page { padding: 0 20px 32px; }
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
      .page { max-width: 1300px; padding: 0 32px 48px; gap: 22px; }
      .card { padding: 24px 28px; }
      .card-title { font-size: 17px; }
      .calendar-grid { gap: 6px; }
      .cal-cell { min-height: 130px; padding: 8px; }
      .cal-day-header { font-size: 12px; padding: 8px 0; }
      .stat-card { padding: 18px; }
      .stat-card .stat-value { font-size: 32px; }
      .legend-item { font-size: 12px; }
    }

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

  <div class="page">

    <!-- Top bar -->
    <header class="topbar">
      <div class="topbar-left">
        <a class="back-btn" href="admin_dashboard.php" aria-label="Back">←</a>
        <span class="title">📅 Master Calendar</span>
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

        for ($day = 1; $day <= $days_in_month; $day++) {
            $current_date = sprintf("%04d-%02d-%02d", $year, $month, $day);
            $is_today = ($current_date === $today_str);
            $is_holiday = isset($public_holidays[$current_date]);
            $holiday_name = $is_holiday ? $public_holidays[$current_date] : '';

            $cell_class = 'cal-cell';
            if ($is_today) $cell_class .= ' today';
            if ($is_holiday) $cell_class .= ' has-holiday';

            echo "<div class='$cell_class' data-date='$current_date'>";
            echo "<div class='day-num'>$day</div>";

            // Holiday name
            if ($is_holiday) {
                echo "<div class='holiday-name'>🎌 " . htmlspecialchars($holiday_name) . "</div>";
            }

            // Employee badges
            if (isset($attendance_data[$current_date])) {
                $max_show = 8; // Limit visible badges per day
                $count = 0;
                foreach ($attendance_data[$current_date] as $record) {
                    if ($count >= $max_show) break;
                    $name = htmlspecialchars($record['name']);
                    $stat = $record['status'];

                    if (in_array($stat, ['on_time', 'grace_period', 'late'])) {
                        echo "<div class='badge bg-present' data-status='present' data-name='$name' title='$name — Present'>✓ $name</div>";
                    } elseif ($stat === 'absent') {
                        echo "<div class='badge bg-absent' data-status='absent' data-name='$name' title='$name — Absent'>✕ $name</div>";
                    } elseif ($stat === 'leave_AL') {
                        echo "<div class='badge bg-al' data-status='leave' data-name='$name' title='$name — Annual Leave'>🏖️ $name</div>";
                    } elseif ($stat === 'leave_MC') {
                        echo "<div class='badge bg-mc' data-status='leave' data-name='$name' title='$name — Medical Leave'>🏥 $name</div>";
                    } elseif ($stat === 'leave_UL') {
                        echo "<div class='badge bg-ul' data-status='leave' data-name='$name' title='$name — Unpaid Leave'>📅 $name</div>";
                    }
                    $count++;
                }
                $total_records = count($attendance_data[$current_date]);
                if ($total_records > $max_show) {
                    $remaining = $total_records - $max_show;
                    echo "<div class='more-indicator'>+$remaining more</div>";
                }
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

  </div>

  <script>
    /* ---- DOM refs ---- */
    const $ = (s) => document.querySelector(s);
    const $$ = (s) => document.querySelectorAll(s);

    let activeFilter = 'all';
    let activeEmployee = 'all';

    /* ---- Filter chips ---- */
    function applyFilters() {
      const allBadges = $$('.badge');

      allBadges.forEach(badge => {
        const status = badge.dataset.status; // 'present', 'absent', 'leave'
        const name = badge.dataset.name;
        let visible = true;

        // Status filter
        if (activeFilter !== 'all' && status !== activeFilter) {
          visible = false;
        }

        // Employee filter
        if (activeEmployee !== 'all' && name !== activeEmployee) {
          visible = false;
        }

        badge.classList.toggle('filtered-out', !visible);
      });

      // Update more-indicator counts
      $$('.cal-cell').forEach(cell => {
        const badges = cell.querySelectorAll('.badge');
        const visibleBadges = cell.querySelectorAll('.badge:not(.filtered-out)');
        const moreIndicator = cell.querySelector('.more-indicator');
        if (moreIndicator) {
          // Hide more-indicator when filtering
          moreIndicator.style.display = (activeFilter !== 'all' || activeEmployee !== 'all') ? 'none' : '';
        }
      });
    }

    $$('#filter-chips .filter-chip').forEach(chip => {
      chip.addEventListener('click', function() {
        $$('#filter-chips .filter-chip').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        activeFilter = this.dataset.filter;
        applyFilters();
      });
    });

    $('#employee-filter').addEventListener('change', function() {
      activeEmployee = this.value;
      applyFilters();
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