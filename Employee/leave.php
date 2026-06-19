<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit();
}

$active_page = 'leave';
$user_id = $_SESSION['user_id'];
$current_year = date('Y');
$message = '';
$message_type = ''; // 'success' or 'error' for toast styling

// 1. Auto-Initialize Leave Balances
$stmt = $pdo->prepare("SELECT * FROM leave_balances WHERE user_id = ? AND year = ?");
$stmt->execute([$user_id, $current_year]);
$balance = $stmt->fetch();

if (!$balance) {
    $pdo->prepare("INSERT INTO leave_balances (user_id, year, al_total, mc_total) VALUES (?, ?, 14, 14)")
        ->execute([$user_id, $current_year]);
    $stmt->execute([$user_id, $current_year]);
    $balance = $stmt->fetch();
}

// 2. Handle Application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_leave'])) {
    $leave_type = $_POST['leave_type'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = $_POST['reason'];
    $custom_reason = isset($_POST['custom_reason']) ? trim($_POST['custom_reason']) : null;

    // If "Other" reason selected, use the custom reason text
    if ($reason === 'Other' && $custom_reason) {
        $reason = $custom_reason;
    }

    $days_requested = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'leave_rolling_months'");
$rolling_months = (int)($stmt->fetchColumn() ?: 3);
$max_date = date('Y-m-d', strtotime("+{$rolling_months} months"));

    if ($start_date > $end_date) {
        $message = "End date cannot be before start date!";
        $message_type = 'error';
    } elseif ($start_date > $max_date) {
        $message = "You can only apply up to 3 months in advance!";
        $message_type = 'error';
    } else {
        $can_apply = true;
        if ($leave_type === 'AL' && ($balance['al_used'] + $days_requested > $balance['al_total'])) {
            $can_apply = false; $message = "Not enough Annual Leave balance!";
            $message_type = 'error';
        } elseif ($leave_type === 'MC' && ($balance['mc_used'] + $days_requested > $balance['mc_total'])) {
            $can_apply = false; $message = "Not enough Medical Leave balance!";
            $message_type = 'error';
        }

        if ($can_apply) {
            $check_overlap = $pdo->prepare("SELECT id FROM leave_requests WHERE user_id = ? AND status != 'rejected' AND (start_date <= ? AND end_date >= ?)");
            $check_overlap->execute([$user_id, $end_date, $start_date]);
            if ($check_overlap->fetch()) {
                $can_apply = false; $message = "Dates overlap with an existing leave request!";
                $message_type = 'error';
            }
        }

        if ($can_apply) {
            $stmt = $pdo->prepare("INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, reason, custom_reason) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $leave_type, $start_date, $end_date, $reason, $custom_reason]);
            $message = "Leave applied successfully! Waiting for Boss approval.";
            $message_type = 'success';

            // Refresh balance after insert
            $stmt = $pdo->prepare("SELECT * FROM leave_balances WHERE user_id = ? AND year = ?");
            $stmt->execute([$user_id, $current_year]);
            $balance = $stmt->fetch();
        }
    }
}

// 3. Fetch Leave History for the Table
$stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE user_id = ? ORDER BY applied_at DESC");
$stmt->execute([$user_id]);
$leave_history = $stmt->fetchAll();

// 4. Calendar Data Prep
$month = isset($_GET['m']) ? $_GET['m'] : date('m');
$year = isset($_GET['y']) ? $_GET['y'] : date('Y');
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

$attendance_data = [];
$stmt = $pdo->prepare("SELECT date, status FROM attendance WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ?");
$stmt->execute([$user_id, $month, $year]);
while ($row = $stmt->fetch()) {
    $attendance_data[$row['date']] = $row['status'];
}

// FETCH ONLY APPROVED LEAVES FOR CALENDAR
$stmt = $pdo->prepare("SELECT start_date, end_date, leave_type FROM leave_requests WHERE user_id = ? AND status = 'approved'");
$stmt->execute([$user_id]);
while ($row = $stmt->fetch()) {
    $current = strtotime($row['start_date']);
    $last = strtotime($row['end_date']);
    while ($current <= $last) {
        $attendance_data[date('Y-m-d', $current)] = 'on_leave_' . $row['leave_type'];
        $current = strtotime('+1 day', $current);
    }
}

// Computed values for the UI
$al_remaining = $balance['al_total'] - $balance['al_used'];
$mc_remaining = $balance['mc_total'] - $balance['mc_used'];
$al_used_pct = $balance['al_total'] > 0 ? round(($balance['al_used'] / $balance['al_total']) * 100) : 0;
$mc_used_pct = $balance['mc_total'] > 0 ? round(($balance['mc_used'] / $balance['mc_total']) * 100) : 0;
$ul_used = 0;
// Count UL days from history
foreach ($leave_history as $lr) {
    if ($lr['leave_type'] === 'UL' && $lr['status'] !== 'rejected') {
        $ul_days = (strtotime($lr['end_date']) - strtotime($lr['start_date'])) / (60 * 60 * 24) + 1;
        $ul_used += $ul_days;
    }
}
$pending_count = 0;
foreach ($leave_history as $lr) {
    if ($lr['status'] === 'pending') $pending_count++;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>Leave Management — LeafPoint</title>
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
      --gold-soft: oklch(82% 0.13 82);

      --green-status: oklch(58% 0.17 142);
      --yellow-status: oklch(68% 0.15 85);
      --red-status: oklch(53% 0.22 22);
      --blue-info: oklch(56% 0.16 255);
      --purple-accent: oklch(53% 0.18 310);

      --radius-sm: 10px;
      --radius-md: 16px;
      --radius-lg: 22px;
      --radius-xl: 28px;
      --shadow-card: 0 2px 16px rgba(0,0,0,0.04), 0 0 0 1px rgba(0,0,0,0.03);
      --shadow-card-hover: 0 6px 28px rgba(0,0,0,0.07), 0 0 0 1px rgba(0,0,0,0.04);

      --font-display: -apple-system, BlinkMacSystemFont, 'SF Pro Display', system-ui, sans-serif;
      --font-body: -apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, sans-serif;
      --font-mono: 'SF Mono', ui-monospace, 'JetBrains Mono', Menlo, monospace;

      --sidebar-bg: oklch(13% 0.02 252);
      --sidebar-fg: oklch(84% 0.006 250);
      --sidebar-muted: oklch(60% 0.016 250);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      width: 100%; height: 100%;
      font-family: var(--font-body);
      color: var(--fg);
      background: var(--bg-deep, var(--bg));
      -webkit-font-smoothing: antialiased;
      overflow: hidden;
    }

    /* ---- App Shell ---- */
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

    /* ---- Main ---- */
    .main { flex: 1; overflow-y: auto; overflow-x: hidden; background: var(--bg-gradient, var(--bg)); display: flex; flex-direction: column; }
    .main-inner { padding: 24px 30px 36px; display: flex; flex-direction: column; gap: 20px; max-width: 1200px; width: 100%; }

    /* ---- Page shell (mobile-first fallback) ---- */
    .page { width: 100%; display: flex; flex-direction: column; gap: 16px; }

    /* ---- Top bar ---- */
    .topbar {
      position: sticky; top: 0; z-index: 20;
      display: flex; align-items: center; justify-content: space-between;
      padding: 14px 0 10px;
      background: oklch(97% 0.005 245 / 0.85);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
    }
    .topbar .back-btn {
      width: 36px; height: 36px; border-radius: 50%;
      background: var(--surface-glass); border: 1px solid var(--border-subtle);
      display: grid; place-items: center; font-size: 18px;
      cursor: pointer; transition: background 0.15s; color: var(--fg);
      text-decoration: none;
    }
    .topbar .back-btn:hover { background: var(--surface-glass-hover); }
    .topbar .title {
      font-family: var(--font-display); font-size: 18px; font-weight: 700;
      letter-spacing: -0.02em;
    }
    .topbar .spacer { width: 36px; }

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
    }
    .card-title {
      font-family: var(--font-display); font-size: 15px; font-weight: 700;
      letter-spacing: -0.01em;
    }
    .card-subtitle { font-size: 11px; color: var(--muted); }

    /* ============================================================
       BALANCE SUMMARY CARDS
       ============================================================ */
    .balance-grid {
      display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;
    }
    .balance-card {
      background: var(--surface-glass);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-md);
      box-shadow: var(--shadow-card);
      padding: 14px;
      display: flex; flex-direction: column; gap: 6px;
      transition: all 0.2s;
      cursor: default;
      position: relative;
      overflow: hidden;
    }
    .balance-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-card-hover);
      background: var(--surface-glass-hover);
    }
    .balance-card::after {
      content: '';
      position: absolute; top: -20px; right: -20px;
      width: 60px; height: 60px; border-radius: 50%;
      opacity: 0.12; pointer-events: none;
    }
    .balance-card.annual::after { background: var(--blue-info); }
    .balance-card.medical::after { background: var(--yellow-status); }
    .balance-card.unpaid::after { background: var(--muted); }
    .balance-card .bc-icon {
      font-size: 24px; line-height: 1;
    }
    .balance-card .bc-label {
      font-size: 11px; font-weight: 600; text-transform: uppercase;
      letter-spacing: 0.04em; color: var(--muted);
    }
    .balance-card .bc-value {
      font-family: var(--font-display); font-size: 28px; font-weight: 800;
      letter-spacing: -0.02em; line-height: 1;
    }
    .balance-card.annual .bc-value { color: var(--blue-info); }
    .balance-card.medical .bc-value { color: var(--yellow-status); }
    .balance-card.unpaid .bc-value { color: var(--fg-secondary); }
    .balance-card .bc-sub {
      font-size: 10px; color: var(--muted);
    }
    .balance-card .bc-bar {
      width: 100%; height: 4px; border-radius: 999px;
      background: oklch(90% 0.006 250); overflow: hidden;
    }
    .balance-card .bc-bar-fill {
      height: 100%; border-radius: 999px; transition: width 0.5s ease;
    }
    .balance-card.annual .bc-bar-fill { background: var(--blue-info); }
    .balance-card.medical .bc-bar-fill { background: var(--yellow-status); }
    .balance-card.unpaid .bc-bar-fill { background: var(--muted); }

    /* ============================================================
       APPLY LEAVE FORM
       ============================================================ */
    .form-group {
      display: flex; flex-direction: column; gap: 5px;
    }
    .form-label {
      font-size: 12px; font-weight: 700; color: var(--fg-secondary);
      letter-spacing: 0.01em; text-transform: uppercase;
    }
    .form-label .required { color: var(--red-status); }
    .form-input, .form-select, .form-textarea {
      width: 100%; padding: 11px 14px;
      font-family: var(--font-body); font-size: 14px; color: var(--fg);
      background: var(--surface-solid);
      border: 1.5px solid var(--border-subtle);
      border-radius: var(--radius-sm);
      transition: all 0.2s;
      -webkit-appearance: none;
      appearance: none;
      outline: none;
    }
    .form-input:focus, .form-select:focus, .form-textarea:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px oklch(56% 0.19 148 / 0.12);
    }
    .form-input.error, .form-select.error, .form-textarea.error {
      border-color: var(--red-status);
      box-shadow: 0 0 0 3px oklch(53% 0.22 22 / 0.1);
    }
    .form-select {
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1.5l5 5 5-5' stroke='%23888' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 14px center;
      padding-right: 36px;
      cursor: pointer;
    }
    .form-textarea {
      min-height: 90px; resize: vertical;
      line-height: 1.5;
    }
    .form-hint {
      font-size: 10px; color: var(--muted);
    }
    .form-error-msg {
      font-size: 10px; color: var(--red-status); font-weight: 600; display: none;
    }
    .form-group.has-error .form-error-msg { display: block; }
    .form-group.has-error .form-input,
    .form-group.has-error .form-select,
    .form-group.has-error .form-textarea { border-color: var(--red-status); }

    .form-row {
      display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
    }

    /* ---- Duration display ---- */
    .duration-preview {
      display: flex; align-items: center; gap: 8px;
      padding: 10px 14px; border-radius: var(--radius-sm);
      background: oklch(96% 0.02 148 / 0.3);
      font-size: 13px; font-weight: 600; color: var(--accent);
    }
    .duration-preview .dp-icon { font-size: 18px; }

    /* ---- Submit button ---- */
    .btn-submit {
      width: 100%; padding: 14px 24px;
      border: none; border-radius: var(--radius-md);
      background: var(--accent); color: #fff;
      font-family: var(--font-display); font-size: 16px; font-weight: 700;
      letter-spacing: -0.01em; cursor: pointer;
      transition: all 0.2s;
      box-shadow: 0 6px 22px oklch(56% 0.19 148 / 0.3);
      position: relative; overflow: hidden;
    }
    .btn-submit:hover {
      background: oklch(50% 0.17 148);
      box-shadow: 0 8px 30px oklch(56% 0.19 148 / 0.42);
      transform: translateY(-1px);
    }
    .btn-submit:active { transform: scale(0.98); }
    .btn-submit.loading {
      pointer-events: none; opacity: 0.8;
    }
    .btn-submit.loading .btn-text { opacity: 0; }
    .btn-submit.loading .btn-spinner {
      display: flex;
    }
    .btn-spinner {
      display: none;
      position: absolute; inset: 0;
      align-items: center; justify-content: center; gap: 6px;
    }
    .btn-spinner .spinner-dot {
      width: 8px; height: 8px; border-radius: 50%; background: #fff;
      animation: spinnerBounce 0.6s ease-in-out infinite;
    }
    .btn-spinner .spinner-dot:nth-child(2) { animation-delay: 0.1s; }
    .btn-spinner .spinner-dot:nth-child(3) { animation-delay: 0.2s; }
    @keyframes spinnerBounce {
      0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; }
      40% { transform: scale(1); opacity: 1; }
    }

    /* Success toast */
    .toast {
      position: fixed; top: 20px; left: 50%; transform: translateX(-50%) translateY(-120px);
      background: var(--surface-solid); border: 1px solid var(--green-status);
      border-radius: var(--radius-md); padding: 14px 20px;
      font-weight: 600; font-size: 14px; color: var(--fg);
      box-shadow: 0 8px 30px rgba(0,0,0,0.12);
      z-index: 100;
      display: flex; align-items: center; gap: 8px;
      transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .toast.show { transform: translateX(-50%) translateY(0); }
    .toast.success { border-color: var(--green-status); }
    .toast.error { border-color: var(--red-status); }

    /* ============================================================
       LEAVE HISTORY TABLE
       ============================================================ */
    .table-wrap {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      margin: -4px -4px 0;
      padding: 4px;
    }
    .table-wrap::-webkit-scrollbar { height: 4px; }
    .table-wrap::-webkit-scrollbar-track { background: transparent; }
    .table-wrap::-webkit-scrollbar-thumb { background: oklch(85% 0.005 250); border-radius: 2px; }

    table.leave-table {
      width: 100%; border-collapse: collapse;
      font-size: 13px; min-width: 500px;
    }
    table.leave-table thead th {
      text-align: left; padding: 10px 12px;
      font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em;
      color: var(--muted); font-weight: 700;
      border-bottom: 1px solid var(--border-subtle);
      white-space: nowrap;
    }
    table.leave-table tbody td {
      padding: 12px;
      border-bottom: 1px solid oklch(94% 0.003 250);
      white-space: nowrap;
    }
    table.leave-table tbody tr {
      transition: background 0.15s;
    }
    table.leave-table tbody tr:hover {
      background: rgba(0,0,0,0.018);
    }
    table.leave-table tbody tr:last-child td { border-bottom: none; }
    .leave-type-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 4px 10px; border-radius: 999px;
      font-size: 11px; font-weight: 600;
    }
    .leave-type-badge.annual { background: oklch(90% 0.05 255); color: oklch(38% 0.08 255); }
    .leave-type-badge.medical { background: oklch(92% 0.06 88); color: oklch(42% 0.1 88); }
    .leave-type-badge.unpaid { background: oklch(90% 0.004 250); color: oklch(45% 0.005 250); }

    .status-badge {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 5px 12px; border-radius: 999px;
      font-size: 11px; font-weight: 700; letter-spacing: 0.01em;
    }
    .status-badge.pending { background: oklch(94% 0.06 85); color: oklch(42% 0.12 80); }
    .status-badge.approved { background: oklch(92% 0.06 148); color: oklch(36% 0.1 148); }
    .status-badge.rejected { background: oklch(92% 0.04 22); color: oklch(40% 0.12 22); }
    .status-dot {
      width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0;
    }
    .status-dot.pending { background: var(--yellow-status); animation: pulse-dot 1.6s ease-in-out infinite; }
    .status-dot.approved { background: var(--green-status); }
    .status-dot.rejected { background: var(--red-status); }
    @keyframes pulse-dot {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.35; }
    }
    .rejection-reason {
      font-size: 10px; color: var(--red-status); font-style: italic;
      max-width: 160px; white-space: normal; word-break: break-word;
    }
    .empty-state {
      text-align: center; padding: 32px 16px; color: var(--muted);
    }
    .empty-state .empty-icon { font-size: 40px; margin-bottom: 8px; }
    .empty-state .empty-text { font-size: 14px; font-weight: 600; }
    .empty-state .empty-sub { font-size: 11px; margin-top: 4px; }

    /* ============================================================
       CALENDAR SECTION (preserved from existing functionality)
       ============================================================ */
    .calendar-grid {
      display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center;
    }
    .cal-head {
      font-size: 10px; text-transform: uppercase; letter-spacing: 0.07em;
      color: var(--muted); font-weight: 700; padding: 8px 0;
    }
    .cal-cell {
      aspect-ratio: 1; border-radius: var(--radius-sm);
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      gap: 4px; font-size: 13px; font-weight: 500; color: var(--fg);
      background: rgba(0,0,0,0.02); border: 1.5px solid transparent;
      transition: all 0.2s; cursor: default;
    }
    .cal-cell:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .cal-cell .day-num { font-size: 13px; font-weight: 600; }
    .cal-cell .day-icon { font-size: 16px; line-height: 1; }
    .cal-cell.status-present { background: oklch(92% 0.06 145 / 0.5); border-color: oklch(82% 0.08 145 / 0.4); }
    .cal-cell.status-absent { background: oklch(92% 0.04 22 / 0.4); border-color: oklch(82% 0.06 22 / 0.3); }
    .cal-cell.status-al { background: oklch(90% 0.06 255 / 0.4); border-color: oklch(80% 0.08 255 / 0.3); }
    .cal-cell.status-mc { background: oklch(93% 0.07 88 / 0.4); border-color: oklch(83% 0.08 88 / 0.3); }
    .cal-cell.status-ul { background: oklch(91% 0.003 250 / 0.4); border-color: oklch(82% 0.003 250 / 0.3); }
    .cal-cell.status-future { background: transparent; border-color: transparent; color: oklch(78% 0.004 250); }

    .calendar-nav {
      display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 8px;
    }
    .calendar-nav a {
      width: 32px; height: 32px; border-radius: 50%;
      background: var(--surface-glass); border: 1px solid var(--border-subtle);
      display: grid; place-items: center; text-decoration: none; color: var(--fg);
      font-size: 16px; transition: all 0.15s;
    }
    .calendar-nav a:hover { background: var(--surface-glass-hover); border-color: var(--accent); color: var(--accent); }
    .calendar-nav .month-label { font-family: var(--font-display); font-size: 15px; font-weight: 700; }

    .legend {
      display: flex; flex-wrap: wrap; gap: 10px; justify-content: center;
      font-size: 10px; color: var(--muted); font-weight: 600;
    }
    .legend span { display: inline-flex; align-items: center; gap: 4px; }
    .legend .dot { width: 8px; height: 8px; border-radius: 2px; display: inline-block; }

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

    /* ---- Responsive ---- */
    @media (min-width: 600px) {
      .main-inner { padding: 24px 30px 36px; }
      .topbar { padding: 18px 0 12px; }
      .topbar .title { font-size: 20px; }
      .card { padding: 22px 24px; }
      .balance-grid { gap: 14px; }
      .balance-card { padding: 18px; }
      .balance-card .bc-value { font-size: 32px; }
      .form-row { gap: 16px; }
    }
    @media (min-width: 1024px) {
      .card { padding: 26px 30px; }
      .card-title { font-size: 17px; }
      .balance-card .bc-value { font-size: 36px; }
      .form-row { grid-template-columns: 1fr 1fr 1fr; gap: 18px; }
      table.leave-table { font-size: 14px; }
      table.leave-table thead th { font-size: 11px; }
    }
    @media (max-width: 800px) {
      .sidebar { width: 210px; min-width: 210px; }
      .main-inner { padding: 16px 12px 80px; }
    }
    @media (max-width: 660px) {
      .sidebar { display: none; }
      .bottom-nav { display: flex; }
      .main-inner { padding: 14px 10px 80px; }
    }
  </style>
</head>
<body>

  <!-- ==========================================================
       TOAST
       ========================================================== -->
  <div class="toast" id="toast" style="display:none;">
    <span id="toast-icon">✅</span>
    <span id="toast-msg"></span>
  </div>

  <!-- ==========================================================
       APP SHELL
       ========================================================== -->
  <div class="app">
    <?php include 'employee_sidebar.php'; ?>

    <main class="main">
      <div class="main-inner">
        <div class="page">

          <!-- Top bar -->
          <header class="topbar">
            <a class="back-btn" href="employee_dashboard.php" aria-label="Back">←</a>
            <span class="title">📋 Leave Management</span>
            <div class="spacer"></div>
          </header>

    <!-- ======================================================
         SECTION 1 — LEAVE BALANCE CARDS
         ====================================================== -->
    <section>
      <div class="balance-grid">
        <!-- Annual Leave -->
        <div class="balance-card annual">
          <span class="bc-icon">🏖️</span>
          <span class="bc-label">Annual Leave</span>
          <span class="bc-value"><?= $al_remaining ?></span>
          <span class="bc-sub">days remaining</span>
          <div class="bc-bar">
            <div class="bc-bar-fill" style="width: <?= $al_used_pct ?>%;" title="<?= $balance['al_used'] ?> of <?= $balance['al_total'] ?> used"></div>
          </div>
        </div>

        <!-- Medical Leave -->
        <div class="balance-card medical">
          <span class="bc-icon">🏥</span>
          <span class="bc-label">Medical Leave</span>
          <span class="bc-value"><?= $mc_remaining ?></span>
          <span class="bc-sub">days remaining</span>
          <div class="bc-bar">
            <div class="bc-bar-fill" style="width: <?= $mc_used_pct ?>%;" title="<?= $balance['mc_used'] ?> of <?= $balance['mc_total'] ?> used"></div>
          </div>
        </div>

        <!-- Unpaid Leave -->
        <div class="balance-card unpaid">
          <span class="bc-icon">📅</span>
          <span class="bc-label">Unpaid Used</span>
          <span class="bc-value"><?= $ul_used ?></span>
          <span class="bc-sub">days this year</span>
          <div class="bc-bar">
            <div class="bc-bar-fill" style="width: <?= min(100, $ul_used * 10) ?>%;" title="<?= $ul_used ?> unpaid days used"></div>
          </div>
        </div>
      </div>
    </section>

    <!-- ======================================================
         SECTION 2 — APPLY LEAVE FORM
         ====================================================== -->
    <section class="card" id="apply-leave-card">
      <div class="card-header">
        <span class="card-title">✍️ Apply for Leave</span>
        <span class="card-subtitle">All fields required</span>
      </div>

      <form id="leave-form" method="POST" novalidate>
        <input type="hidden" name="apply_leave" value="1">

        <!-- Leave Type -->
        <div class="form-group" id="fg-leave-type" style="margin-bottom: 14px;">
          <label class="form-label" for="leave-type">Leave Type <span class="required">*</span></label>
          <select class="form-select" id="leave-type" name="leave_type" required>
            <option value="" disabled selected>Select leave type…</option>
            <option value="AL">Annual Leave (AL)</option>
            <option value="MC">Medical Leave (MC)</option>
            <option value="UL">Unpaid Leave (UL)</option>
          </select>
          <span class="form-error-msg">Please select a leave type</span>
        </div>

        <!-- Date Row -->
        <div class="form-row" style="margin-bottom: 14px;">
          <div class="form-group" id="fg-start-date">
            <label class="form-label" for="start-date">Start Date <span class="required">*</span></label>
            <input type="date" class="form-input" id="start-date" name="start_date" required />
            <span class="form-error-msg">Please select a start date</span>
          </div>
          <div class="form-group" id="fg-end-date">
            <label class="form-label" for="end-date">End Date <span class="required">*</span></label>
            <input type="date" class="form-input" id="end-date" name="end_date" required />
            <span class="form-error-msg">Please select an end date</span>
            <span class="form-hint" id="date-hint" style="display:none;"></span>
          </div>
        </div>

        <!-- Duration preview -->
        <div class="duration-preview" id="duration-preview" style="display:none;margin-bottom:14px;">
          <span class="dp-icon">📆</span>
          <span id="duration-text">0 working days</span>
        </div>

        <!-- Reason -->
        <div class="form-group" id="fg-reason" style="margin-bottom: 14px;">
          <label class="form-label" for="reason">Reason <span class="required">*</span></label>
          <select class="form-select" id="reason" name="reason" required>
            <option value="" disabled selected>Select a reason…</option>
            <option value="Family Event">Family Event</option>
            <option value="Vacation">Vacation</option>
            <option value="Medical Appointment">Medical Appointment</option>
            <option value="Emergency">Emergency</option>
            <option value="Other">Others</option>
          </select>
          <span class="form-error-msg">Please select a reason</span>
        </div>

        <!-- Custom Reason -->
        <div class="form-group" id="fg-custom-reason" style="display:none;margin-bottom:14px;">
          <label class="form-label" for="custom-reason">Please describe <span class="required">*</span></label>
          <textarea class="form-textarea" id="custom-reason" name="custom_reason" placeholder="Provide details about your leave request…" rows="3"></textarea>
          <span class="form-error-msg">Please provide a reason</span>
        </div>

        <!-- Submit -->
        <button type="submit" class="btn-submit" id="btn-submit" name="apply_leave">
          <span class="btn-text">Apply Leave</span>
          <span class="btn-spinner">
            <span class="spinner-dot"></span>
            <span class="spinner-dot"></span>
            <span class="spinner-dot"></span>
          </span>
        </button>
      </form>
    </section>

    <!-- ======================================================
         SECTION 3 — LEAVE REQUEST HISTORY
         ====================================================== -->
    <section class="card" id="history-card">
      <div class="card-header">
        <span class="card-title">📜 Leave Request History</span>
        <span class="card-subtitle" id="history-count"><?= count($leave_history) ?> request<?= count($leave_history) !== 1 ? 's' : '' ?></span>
      </div>

      <?php if (count($leave_history) > 0): ?>
      <div class="table-wrap">
        <table class="leave-table" id="leave-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Dates</th>
              <th>Duration</th>
              <th>Status</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody id="leave-table-body">
            <?php foreach ($leave_history as $req): ?>
            <?php
              $type_icons = ['AL' => '🏖️', 'MC' => '🏥', 'UL' => '📅'];
              $type_labels = ['AL' => 'Annual', 'MC' => 'Medical', 'UL' => 'Unpaid'];
              $type_class = strtolower($type_labels[$req['leave_type']]);
              $start = new DateTime($req['start_date']);
              $end = new DateTime($req['end_date']);
              $duration = $start->diff($end)->days + 1;
            ?>
            <tr>
              <td><span class="leave-type-badge <?= $type_class ?>"><?= $type_icons[$req['leave_type']] ?> <?= $type_labels[$req['leave_type']] ?></span></td>
              <td><?= date('M d', strtotime($req['start_date'])) ?> – <?= date('M d, Y', strtotime($req['end_date'])) ?></td>
              <td><?= $duration ?> day<?= $duration !== 1 ? 's' : '' ?></td>
              <td>
                <?php if ($req['status'] === 'pending'): ?>
                  <span class="status-badge pending"><span class="status-dot pending"></span> Pending</span>
                <?php elseif ($req['status'] === 'approved'): ?>
                  <span class="status-badge approved"><span class="status-dot approved"></span> Approved</span>
                <?php else: ?>
                  <span class="status-badge rejected"><span class="status-dot rejected"></span> Rejected</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($req['status'] === 'rejected' && $req['admin_remark']): ?>
                  <span class="rejection-reason"><?= htmlspecialchars($req['admin_remark']) ?></span>
                <?php else: ?>
                  <span style="font-size:11px;color:var(--muted);">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon">📭</div>
        <div class="empty-text">No leave requests yet</div>
        <div class="empty-sub">Your applied leaves will appear here</div>
      </div>
      <?php endif; ?>
    </section>

    <!-- ======================================================
         SECTION 4 — ATTENDANCE CALENDAR
         ====================================================== -->
    <section class="card" id="calendar-card">
      <div class="card-header">
        <span class="card-title">📅 Attendance Heatmap</span>
        <span class="card-subtitle">Leaves appear after approval</span>
      </div>

      <div class="calendar-nav">
        <?php
            $prev_month = $month - 1; $prev_year = $year;
            if($prev_month == 0) { $prev_month = 12; $prev_year--; }
            $next_month = $month + 1; $next_year = $year;
            if($next_month == 13) { $next_month = 1; $next_year++; }
        ?>
        <a href="?m=<?= $prev_month ?>&y=<?= $prev_year ?>">‹</a>
        <span class="month-label"><?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></span>
        <a href="?m=<?= $next_month ?>&y=<?= $next_year ?>">›</a>
      </div>

      <div class="calendar-grid">
        <div class="cal-head">Sun</div><div class="cal-head">Mon</div><div class="cal-head">Tue</div>
        <div class="cal-head">Wed</div><div class="cal-head">Thu</div><div class="cal-head">Fri</div><div class="cal-head">Sat</div>

        <?php
        $first_day_of_month = date('w', mktime(0,0,0,$month,1,$year));
        for ($i = 0; $i < $first_day_of_month; $i++) {
            echo "<div class='cal-cell' style='border:none; background:transparent;'></div>";
        }

        for ($day = 1; $day <= $days_in_month; $day++) {
            $current_date = sprintf("%04d-%02d-%02d", $year, $month, $day);
            $status_class = 'status-future';
            $icon = '○';

            if (isset($attendance_data[$current_date])) {
                $stat = $attendance_data[$current_date];
                if (in_array($stat, ['on_time', 'grace_period', 'late'])) {
                    $status_class = 'status-present'; $icon = '✓';
                } elseif ($stat === 'absent') {
                    $status_class = 'status-absent'; $icon = '✕';
                } elseif ($stat === 'on_leave_AL') {
                    $status_class = 'status-al'; $icon = '🏖️';
                } elseif ($stat === 'on_leave_MC') {
                    $status_class = 'status-mc'; $icon = '🏥';
                } elseif ($stat === 'on_leave_UL') {
                    $status_class = 'status-ul'; $icon = '📅';
                }
            }

            echo "<div class='cal-cell $status_class'><div class='day-num'>$day</div><div class='day-icon'>$icon</div></div>";
        }
        ?>
      </div>

      <div class="legend">
        <span><span class="dot" style="background:var(--green-status);"></span> Present</span>
        <span><span class="dot" style="background:var(--red-status);"></span> Absent</span>
        <span><span class="dot" style="background:var(--blue-info);"></span> Annual Leave</span>
        <span><span class="dot" style="background:var(--yellow-status);"></span> Medical Leave</span>
        <span><span class="dot" style="background:var(--muted);"></span> Unpaid Leave</span>
      </div>
    </section>

        </div><!-- /.page -->
      </div><!-- /.main-inner -->
    </main>
  </div><!-- /.app -->

  <!-- Mobile bottom nav -->
  <?php include 'employee_bottom_nav.php'; ?>

  <!-- ==========================================================
       SCRIPTS
       ========================================================== -->
  <script>
    /* ---- DOM refs ---- */
    const $ = (s) => document.querySelector(s);
    const $$ = (s) => document.querySelectorAll(s);

    const form = $('#leave-form');
    const leaveType = $('#leave-type');
    const startDate = $('#start-date');
    const endDate = $('#end-date');
    const reason = $('#reason');
    const customReason = $('#custom-reason');
    const durationPreview = $('#duration-preview');
    const durationText = $('#duration-text');
    const dateHint = $('#date-hint');
    const btnSubmit = $('#btn-submit');
    const toast = $('#toast');
    const toastIcon = $('#toast-icon');
    const toastMsg = $('#toast-msg');

    /* ---- Set min date to today ---- */
    const today = new Date().toISOString().split('T')[0];
    startDate.setAttribute('min', today);
    endDate.setAttribute('min', today);

    /* ---- Show PHP message as toast on page load ---- */
    <?php if ($message): ?>
    showToast('<?= $message_type === 'success' ? '✅' : '❌' ?>', <?= json_encode($message) ?>, '<?= $message_type ?>');
    <?php endif; ?>

    /* ---- Show/hide custom reason ---- */
    reason.addEventListener('change', () => {
      const fg = $('#fg-custom-reason');
      if (reason.value === 'Other') {
        fg.style.display = 'flex';
        customReason.setAttribute('required', '');
      } else {
        fg.style.display = 'none';
        customReason.removeAttribute('required');
        customReason.value = '';
        fg.classList.remove('has-error');
      }
    });

    /* ---- Duration calculation ---- */
    function calculateDuration() {
      const start = startDate.value;
      const end = endDate.value;
      if (!start || !end) {
        durationPreview.style.display = 'none';
        dateHint.style.display = 'none';
        return;
      }

      const s = new Date(start + 'T00:00:00');
      const e = new Date(end + 'T00:00:00');

      if (e < s) {
        dateHint.style.display = 'block';
        dateHint.textContent = 'End date must be after start date';
        dateHint.style.color = 'var(--red-status)';
        durationPreview.style.display = 'none';
        return;
      }

      dateHint.style.display = 'none';
      const diffMs = e - s;
      const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24)) + 1;

      durationPreview.style.display = 'flex';
      durationText.textContent = diffDays + ' working day' + (diffDays !== 1 ? 's' : '');

      // Warn if > 5 days
      if (diffDays > 5) {
        dateHint.style.display = 'block';
        dateHint.textContent = 'Requests over 5 days may require additional approval';
        dateHint.style.color = 'var(--yellow-status)';
      }
    }

    startDate.addEventListener('change', () => {
      if (!endDate.value || endDate.value < startDate.value) {
        endDate.value = startDate.value;
      }
      endDate.setAttribute('min', startDate.value);
      calculateDuration();
    });
    endDate.addEventListener('change', calculateDuration);

    /* ---- Clear field error on input ---- */
    $$('.form-input, .form-select, .form-textarea').forEach(el => {
      el.addEventListener('input', () => {
        el.closest('.form-group')?.classList.remove('has-error');
      });
      el.addEventListener('change', () => {
        el.closest('.form-group')?.classList.remove('has-error');
      });
    });

    /* ---- Form validation ---- */
    function validateForm() {
      let valid = true;
      const groups = [
        { id: 'fg-leave-type', el: leaveType, test: () => leaveType.value !== '' },
        { id: 'fg-start-date', el: startDate, test: () => startDate.value !== '' },
        { id: 'fg-end-date', el: endDate, test: () => endDate.value !== '' && new Date(endDate.value + 'T00:00:00') >= new Date(startDate.value + 'T00:00:00') },
        { id: 'fg-reason', el: reason, test: () => reason.value !== '' },
      ];

      groups.forEach(g => {
        const fg = document.getElementById(g.id);
        if (!g.test()) {
          fg.classList.add('has-error');
          valid = false;
        } else {
          fg.classList.remove('has-error');
        }
      });

      // Custom reason check
      if (reason.value === 'Other') {
        const fgCustom = $('#fg-custom-reason');
        if (customReason.value.trim() === '') {
          fgCustom.classList.add('has-error');
          valid = false;
        } else {
          fgCustom.classList.remove('has-error');
        }
      }

      return valid;
    }

    /* ---- Toast ---- */
    function showToast(icon, message, type) {
      toastIcon.textContent = icon;
      toastMsg.textContent = message;
      toast.className = 'toast ' + (type || 'success') + ' show';
      toast.style.display = 'flex';
      clearTimeout(toast._timeout);
      toast._timeout = setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => { toast.style.display = 'none'; }, 350);
      }, 3500);
    }

    /* ---- Submit handler ---- */
    form.addEventListener('submit', function(e) {
      if (!validateForm()) {
        e.preventDefault();
        showToast('❌', 'Please fix the highlighted fields.', 'error');
        return;
      }

      // Show loading state, then let form submit to PHP
      btnSubmit.classList.add('loading');
    });

    /* ---- Keyboard shortcut: Ctrl+Enter to submit ---- */
    document.addEventListener('keydown', function(e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        form.dispatchEvent(new Event('submit'));
      }
    });
  </script>

</body>
</html>