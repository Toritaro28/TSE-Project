<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = '';
$message_type = '';
$active_page = 'approvals';

// ============================================================
// STATS QUERIES (new — to support the dashboard stat cards)
// ============================================================
$current_month = date('m');
$current_year = date('Y');
$today = date('Y-m-d');

// Pending count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'");
$stmt->execute();
$stat_pending = $stmt->fetchColumn();

// Approved this month
$stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'approved' AND MONTH(applied_at) = ? AND YEAR(applied_at) = ?");
$stmt->execute([$current_month, $current_year]);
$stat_approved = $stmt->fetchColumn();

// Rejected this month
$stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'rejected' AND MONTH(applied_at) = ? AND YEAR(applied_at) = ?");
$stmt->execute([$current_month, $current_year]);
$stat_rejected = $stmt->fetchColumn();

// On leave today
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM leave_requests WHERE status = 'approved' AND start_date <= ? AND end_date >= ?");
$stmt->execute([$today, $today]);
$stat_onleave = $stmt->fetchColumn();

// ============================================================
// HANDLE LEAVE APPROVAL / REJECTION (preserved — identical logic)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    $admin_remark = $_POST['admin_remark'] ?? '';

    // Fetch the request details
    $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE id = ?");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch();

    if ($req && $req['status'] === 'pending') {
        if ($action === 'approve') {
            // Calculate days
            $days = (strtotime($req['end_date']) - strtotime($req['start_date'])) / (60 * 60 * 24) + 1;
            $leave_year = date('Y', strtotime($req['start_date']));

            // Deduct balance
            if ($req['leave_type'] === 'AL') {
                $pdo->prepare("UPDATE leave_balances SET al_used = al_used + ? WHERE user_id = ? AND year = ?")->execute([$days, $req['user_id'], $leave_year]);
            } elseif ($req['leave_type'] === 'MC') {
                $pdo->prepare("UPDATE leave_balances SET mc_used = mc_used + ? WHERE user_id = ? AND year = ?")->execute([$days, $req['user_id'], $leave_year]);
            }

            $pdo->prepare("UPDATE leave_requests SET status = 'approved' WHERE id = ?")->execute([$request_id]);
            $message = "Leave Approved Successfully! Balance updated.";
            $message_type = 'success';

        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE leave_requests SET status = 'rejected', admin_remark = ? WHERE id = ?")->execute([$admin_remark, $request_id]);
            $message = "Leave Rejected. Employee has been notified.";
            $message_type = 'error';
        }

        // Refresh stats after action
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'");
        $stmt->execute();
        $stat_pending = $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'approved' AND MONTH(applied_at) = ? AND YEAR(applied_at) = ?");
        $stmt->execute([$current_month, $current_year]);
        $stat_approved = $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'rejected' AND MONTH(applied_at) = ? AND YEAR(applied_at) = ?");
        $stmt->execute([$current_month, $current_year]);
        $stat_rejected = $stmt->fetchColumn();
    }
}

// ============================================================
// FETCH ALL LEAVE REQUESTS (for tab switcher: pending + history)
// ============================================================
$stmt = $pdo->prepare("SELECT lr.*, u.name, u.email FROM leave_requests lr JOIN users u ON lr.user_id = u.id ORDER BY lr.applied_at DESC");
$stmt->execute();
$all_requests = $stmt->fetchAll();

// Separate for convenience
$pending_leaves = array_filter($all_requests, fn($r) => $r['status'] === 'pending');
$pending_count = count($pending_leaves);

// Avatar color palette
$avatar_colors = [
    '#4A90D9', '#E8734A', '#50B86C', '#9B59B6', '#F1C40F', '#1ABC9C',
    '#E74C3C', '#3498DB', '#2ECC71', '#E67E22', '#8E44AD', '#16A085',
    '#D35400', '#2980B9', '#27AE60',
];

// Helper: get employee leave balance for drawer
function getLeaveBalance($pdo, $user_id, $year) {
    $stmt = $pdo->prepare("SELECT * FROM leave_balances WHERE user_id = ? AND year = ?");
    $stmt->execute([$user_id, $year]);
    $bal = $stmt->fetch();
    if (!$bal) {
        return ['al_total' => 14, 'al_used' => 0, 'mc_total' => 14, 'mc_used' => 0];
    }
    return $bal;
}

// Prepare enriched request data for JavaScript
$requests_enriched = array_map(function($r) use ($avatar_colors) {
    $initials = '';
    foreach (explode(' ', $r['name']) as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    $r['initials'] = $initials;
    $r['avatarBg'] = $avatar_colors[$r['user_id'] % count($avatar_colors)];
    $start = new DateTime($r['start_date']);
    $end = new DateTime($r['end_date']);
    $r['duration'] = $start->diff($end)->days + 1;
    return $r;
}, $all_requests);
$requests_json = json_encode(array_values($requests_enriched));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>Leave Approvals — LeafPoint Admin</title>
  <style>
    /* ============================================================
       DESIGN TOKENS — LeafPoint System
       ============================================================ */
    :root {
      --bg: oklch(96.5% 0.006 245);
      --bg-gradient: radial-gradient(ellipse at 70% 0%, oklch(90% 0.04 170 / 0.18), oklch(97% 0.004 245) 55%);
      --surface-glass: rgba(255, 255, 255, 0.52);
      --surface-glass-hover: rgba(255, 255, 255, 0.72);
      --surface-solid: #ffffff;
      --surface-raised: rgba(255, 255, 255, 0.85);
      --fg: oklch(16% 0.018 252); --fg-secondary: oklch(36% 0.022 250); --muted: oklch(53% 0.016 250);
      --border-glass: rgba(255, 255, 255, 0.38); --border-subtle: rgba(0, 0, 0, 0.055);
      --accent: oklch(56% 0.19 148); --accent-soft: oklch(74% 0.14 148); --accent-dark: oklch(48% 0.16 148);
      --accent-glow: oklch(62% 0.21 148 / 0.3); --gold: oklch(70% 0.19 82);
      --green-status: oklch(58% 0.17 142); --yellow-status: oklch(68% 0.15 85); --red-status: oklch(53% 0.22 22);
      --blue-info: oklch(56% 0.16 255); --purple-accent: oklch(53% 0.18 310);
      --sidebar-bg: oklch(13% 0.02 252); --sidebar-fg: oklch(84% 0.006 250); --sidebar-muted: oklch(60% 0.016 250);

      --radius-sm: 10px;
      --radius-md: 16px;
      --radius-lg: 22px;
      --radius-xl: 28px;
      --shadow-card: 0 2px 16px rgba(0,0,0,0.04), 0 0 0 1px rgba(0,0,0,0.03);
      --shadow-card-hover: 0 6px 28px rgba(0,0,0,0.07), 0 0 0 1px rgba(0,0,0,0.04);
      --shadow-modal: 0 20px 60px rgba(0,0,0,0.18), 0 0 0 1px rgba(0,0,0,0.06);
      --shadow-drawer: -8px 0 40px rgba(0,0,0,0.12);

      --font-display: -apple-system, BlinkMacSystemFont, 'SF Pro Display', system-ui, sans-serif;
      --font-body: -apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, sans-serif;
      --font-mono: 'SF Mono', ui-monospace, 'JetBrains Mono', Menlo, monospace;

      --drawer-width: 420px;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { width: 100%; height: 100%; font-family: var(--font-body); color: var(--fg); background: var(--bg); -webkit-font-smoothing: antialiased; overflow: hidden; }

    /* ---- App Shell ---- */
    .app { display: flex; height: 100vh; width: 100%; }

    /* ---- Sidebar ---- */
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

    /* ---- Main ---- */
    .main { flex: 1; overflow-y: auto; overflow-x: hidden; background: var(--bg-gradient, radial-gradient(ellipse at 70% 0%, oklch(90% 0.04 170 / 0.18), oklch(97% 0.004 245) 55%)); display: flex; flex-direction: column; }
    .main-inner { padding: 24px 30px 36px; display: flex; flex-direction: column; gap: 20px; max-width: 1200px; width: 100%; }

    /* ---- Page shell ---- */
    .page { width: 100%; display: flex; flex-direction: column; gap: 16px; }

    /* ---- Top bar ---- */
    .topbar {
      position: sticky; top: 0; z-index: 25;
      display: flex; align-items: center; justify-content: space-between;
      padding: 14px 0 10px;
      background: oklch(96.5% 0.006 245 / 0.85);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      gap: 12px;
      flex-wrap: wrap;
    }
    .topbar .topbar-left {
      display: flex; align-items: center; gap: 10px;
    }
    .topbar .back-btn, .topbar .nav-btn {
      width: 36px; height: 36px; border-radius: 50%;
      background: var(--surface-glass); border: 1px solid var(--border-subtle);
      display: grid; place-items: center; font-size: 18px;
      cursor: pointer; transition: background 0.15s; color: var(--fg);
      text-decoration: none; flex-shrink: 0;
    }
    .topbar .back-btn:hover, .topbar .nav-btn:hover { background: var(--surface-glass-hover); }
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
    .topbar .notif-badge {
      position: relative;
      padding: 6px 14px; border-radius: 999px;
      background: var(--surface-glass); border: 1px solid var(--border-subtle);
      font-size: 12px; font-weight: 700; cursor: pointer;
      transition: all 0.15s; white-space: nowrap;
      display: inline-flex; align-items: center; gap: 6px;
    }
    .topbar .notif-badge:hover { background: var(--surface-glass-hover); }
    .notif-dot {
      width: 10px; height: 10px; border-radius: 50%;
      background: var(--red-status);
      animation: pulse-dot 1.4s ease-in-out infinite;
    }
    @keyframes pulse-dot {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.5; transform: scale(1.4); }
    }

    .topbar .topbar-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .topbar .action-link {
      padding: 8px 16px; border-radius: 999px;
      background: var(--surface-glass); border: 1px solid var(--border-subtle);
      font-size: 12px; font-weight: 700; cursor: pointer;
      transition: all 0.15s; white-space: nowrap;
      text-decoration: none; color: var(--fg-secondary);
      display: inline-flex; align-items: center; gap: 5px;
    }
    .topbar .action-link:hover { background: var(--surface-glass-hover); color: var(--accent); }

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
       STATS GRID — 4 Summary Cards
       ============================================================ */
    .stats-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
    }
    .stat-card {
      background: var(--surface-glass);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-md);
      box-shadow: var(--shadow-card);
      padding: 16px 14px;
      display: flex; flex-direction: column; gap: 8px;
      transition: all 0.2s; cursor: default;
      position: relative; overflow: hidden;
    }
    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-card-hover);
      background: var(--surface-glass-hover);
    }
    .stat-card::after {
      content: '';
      position: absolute; top: -18px; right: -18px;
      width: 60px; height: 60px; border-radius: 50%;
      opacity: 0.1; pointer-events: none;
    }
    .stat-card.pending::after { background: var(--yellow-status); }
    .stat-card.approved::after { background: var(--green-status); }
    .stat-card.rejected::after { background: var(--red-status); }
    .stat-card.on-leave::after { background: var(--blue-info); }
    .stat-card .sc-icon { font-size: 22px; line-height: 1; }
    .stat-card .sc-label {
      font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em;
      color: var(--muted); font-weight: 700;
    }
    .stat-card .sc-value {
      font-family: var(--font-display); font-size: 30px; font-weight: 800;
      letter-spacing: -0.02em; line-height: 1;
    }
    .stat-card.pending .sc-value { color: var(--yellow-status); }
    .stat-card.approved .sc-value { color: var(--green-status); }
    .stat-card.rejected .sc-value { color: var(--red-status); }
    .stat-card.on-leave .sc-value { color: var(--blue-info); }

    /* ============================================================
       TAB SWITCHER
       ============================================================ */
    .tab-switcher {
      display: flex; gap: 4px; background: oklch(93% 0.004 250);
      padding: 4px; border-radius: 999px;
    }
    .tab-btn {
      padding: 9px 20px; border-radius: 999px;
      border: none; background: transparent; color: var(--fg-secondary);
      font-size: 13px; font-weight: 600; cursor: pointer;
      transition: all 0.2s; font-family: var(--font-body);
      letter-spacing: -0.01em; white-space: nowrap;
    }
    .tab-btn.active {
      background: var(--surface-solid); color: var(--fg);
      box-shadow: 0 1px 4px rgba(0,0,0,0.08);
    }
    .tab-btn .tab-count {
      font-size: 10px; margin-left: 4px; opacity: 0.6;
    }

    /* ---- Search + Filter row ---- */
    .search-filter-row {
      display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
    }
    .search-input {
      flex: 1; min-width: 160px; padding: 9px 14px;
      border: 1.5px solid var(--border-subtle);
      border-radius: var(--radius-sm);
      font-family: var(--font-body); font-size: 13px; color: var(--fg);
      background: var(--surface-solid); outline: none;
      transition: border-color 0.2s;
    }
    .search-input:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px oklch(56% 0.19 148 / 0.1);
    }
    .filter-select {
      padding: 9px 32px 9px 12px;
      border: 1.5px solid var(--border-subtle);
      border-radius: var(--radius-sm);
      font-family: var(--font-body); font-size: 13px; color: var(--fg);
      background: var(--surface-solid);
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1.5l5 5 5-5' stroke='%23888' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 10px center;
      -webkit-appearance: none; appearance: none; outline: none;
      cursor: pointer; transition: border-color 0.2s;
    }
    .filter-select:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px oklch(56% 0.19 148 / 0.1);
    }

    /* ============================================================
       TABLE
       ============================================================ */
    .table-wrap {
      overflow-x: auto; -webkit-overflow-scrolling: touch;
      margin: -4px -4px 0; padding: 4px;
    }
    .table-wrap::-webkit-scrollbar { height: 4px; }
    .table-wrap::-webkit-scrollbar-track { background: transparent; }
    .table-wrap::-webkit-scrollbar-thumb { background: oklch(84% 0.005 250); border-radius: 2px; }

    table.data-table {
      width: 100%; border-collapse: collapse;
      font-size: 13px; min-width: 820px;
    }
    table.data-table thead th {
      text-align: left; padding: 11px 12px;
      font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em;
      color: var(--muted); font-weight: 700;
      border-bottom: 1px solid var(--border-subtle);
      white-space: nowrap; background: oklch(98% 0.002 250 / 0.4);
      position: sticky; top: 0;
    }
    table.data-table tbody td {
      padding: 12px;
      border-bottom: 1px solid oklch(94% 0.003 250);
      white-space: nowrap;
    }
    table.data-table tbody tr {
      transition: background 0.15s; cursor: pointer;
    }
    table.data-table tbody tr:hover { background: rgba(0,0,0,0.025); }
    table.data-table tbody tr:last-child td { border-bottom: none; }

    .employee-cell {
      display: flex; align-items: center; gap: 8px;
    }
    .employee-cell .emp-avatar {
      width: 32px; height: 32px; border-radius: 50%;
      display: grid; place-items: center; font-size: 14px; font-weight: 700;
      color: #fff; flex-shrink: 0;
    }
    .employee-cell .emp-name { font-weight: 600; color: var(--fg); }

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

    .action-btns { display: flex; gap: 6px; }
    .btn-sm {
      padding: 6px 14px; border-radius: 999px;
      border: none; font-size: 11px; font-weight: 700;
      cursor: pointer; transition: all 0.2s;
      font-family: var(--font-body); letter-spacing: -0.01em;
    }
    .btn-sm-approve {
      background: oklch(90% 0.06 148); color: oklch(36% 0.1 148);
    }
    .btn-sm-approve:hover { background: var(--green-status); color: #fff; }
    .btn-sm-reject {
      background: oklch(92% 0.03 22); color: oklch(40% 0.1 22);
    }
    .btn-sm-reject:hover { background: var(--red-status); color: #fff; }

    .empty-state {
      text-align: center; padding: 40px 16px; color: var(--muted);
    }
    .empty-state .empty-icon { font-size: 40px; margin-bottom: 8px; }
    .empty-state .empty-text { font-size: 14px; font-weight: 600; }
    .empty-state .empty-sub { font-size: 11px; margin-top: 4px; }

    /* ============================================================
       DETAIL DRAWER
       ============================================================ */
    .drawer-overlay {
      position: fixed; inset: 0; z-index: 45;
      background: rgba(0,0,0,0.35);
      backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
      opacity: 0; pointer-events: none;
      transition: opacity 0.3s;
    }
    .drawer-overlay.open { opacity: 1; pointer-events: auto; }
    .drawer {
      position: fixed; top: 0; right: 0; bottom: 0; z-index: 46;
      width: min(var(--drawer-width), 100vw);
      background: var(--surface-raised);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      box-shadow: var(--shadow-drawer);
      transform: translateX(100%);
      transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
      display: flex; flex-direction: column;
      overflow-y: auto;
    }
    .drawer.open { transform: translateX(0); }
    .drawer-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 18px 20px; border-bottom: 1px solid var(--border-subtle);
      position: sticky; top: 0; background: var(--surface-raised); z-index: 2;
    }
    .drawer-header .drawer-title {
      font-family: var(--font-display); font-size: 17px; font-weight: 700;
      letter-spacing: -0.01em;
    }
    .drawer-close {
      width: 34px; height: 34px; border-radius: 50%;
      border: 1px solid var(--border-subtle); background: var(--surface-solid);
      display: grid; place-items: center; font-size: 18px; cursor: pointer;
      transition: all 0.15s;
    }
    .drawer-close:hover { background: oklch(95% 0.005 250); }
    .drawer-body {
      padding: 20px; display: flex; flex-direction: column; gap: 18px;
      flex: 1;
    }
    .drawer-section {
      display: flex; flex-direction: column; gap: 10px;
    }
    .drawer-section .ds-title {
      font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em;
      color: var(--muted); font-weight: 700;
    }
    .drawer-info-row {
      display: flex; justify-content: space-between; align-items: center;
      padding: 8px 0; border-bottom: 1px solid oklch(94% 0.003 250);
    }
    .drawer-info-row .di-label { font-size: 12px; color: var(--muted); }
    .drawer-info-row .di-value { font-size: 13px; font-weight: 600; color: var(--fg); text-align: right; }

    .drawer-emp-card {
      display: flex; align-items: center; gap: 12px;
      padding: 12px; border-radius: var(--radius-sm);
      background: rgba(0,0,0,0.025);
    }
    .drawer-emp-card .dec-avatar {
      width: 44px; height: 44px; border-radius: 50%;
      display: grid; place-items: center; font-size: 18px; font-weight: 700;
      color: #fff; flex-shrink: 0;
    }
    .drawer-emp-card .dec-info { flex: 1; }
    .drawer-emp-card .dec-name { font-size: 15px; font-weight: 700; }
    .drawer-emp-card .dec-email { font-size: 11px; color: var(--muted); }

    .balance-row {
      display: flex; gap: 10px;
    }
    .balance-chip {
      flex: 1; padding: 10px 12px; border-radius: var(--radius-sm);
      background: rgba(0,0,0,0.018); text-align: center;
      display: flex; flex-direction: column; gap: 2px;
    }
    .balance-chip .bc-type { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); font-weight: 600; }
    .balance-chip .bc-days { font-family: var(--font-display); font-size: 24px; font-weight: 700; }
    .balance-chip.annual .bc-days { color: var(--blue-info); }
    .balance-chip.medical .bc-days { color: var(--yellow-status); }

    .drawer-actions {
      display: flex; gap: 10px; padding-top: 8px;
    }
    .btn-approve, .btn-drawer-reject {
      flex: 1; padding: 13px; border-radius: var(--radius-sm);
      border: none; font-family: var(--font-body); font-size: 14px;
      font-weight: 700; cursor: pointer; transition: all 0.2s;
      letter-spacing: -0.01em;
    }
    .btn-approve {
      background: var(--green-status); color: #fff;
      box-shadow: 0 4px 16px oklch(58% 0.17 142 / 0.3);
    }
    .btn-approve:hover { background: oklch(50% 0.15 142); box-shadow: 0 6px 22px oklch(58% 0.17 142 / 0.4); }
    .btn-drawer-reject {
      background: var(--surface-solid); color: var(--red-status);
      border: 1.5px solid oklch(82% 0.06 22);
    }
    .btn-drawer-reject:hover { background: oklch(95% 0.02 22); border-color: var(--red-status); }

    /* ============================================================
       MODALS
       ============================================================ */
    .modal-overlay {
      position: fixed; inset: 0; z-index: 55;
      background: rgba(0,0,0,0.45);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      display: flex; align-items: center; justify-content: center;
      padding: 20px;
      opacity: 0; pointer-events: none;
      transition: opacity 0.25s;
    }
    .modal-overlay.open { opacity: 1; pointer-events: auto; }
    .modal-dialog {
      background: var(--surface-solid);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-modal);
      padding: 28px 24px 20px;
      max-width: 440px; width: 100%;
      text-align: center;
      display: flex; flex-direction: column; gap: 14px;
      transform: translateY(20px) scale(0.96);
      transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .modal-overlay.open .modal-dialog {
      transform: translateY(0) scale(1);
    }
    .modal-dialog .modal-icon { font-size: 48px; }
    .modal-dialog .modal-title {
      font-family: var(--font-display); font-size: 18px; font-weight: 800;
      letter-spacing: -0.02em;
    }
    .modal-dialog .modal-desc {
      font-size: 13px; color: var(--fg-secondary); line-height: 1.5;
    }
    .modal-actions {
      display: flex; gap: 10px;
    }
    .modal-actions .btn {
      flex: 1; padding: 12px; border-radius: var(--radius-sm);
      font-family: var(--font-body); font-size: 14px; font-weight: 700;
      cursor: pointer; transition: all 0.2s; border: none;
      letter-spacing: -0.01em;
    }
    .btn-modal-cancel {
      background: oklch(92% 0.006 250); color: var(--fg-secondary);
    }
    .btn-modal-cancel:hover { background: oklch(87% 0.006 250); }
    .btn-modal-approve {
      background: var(--green-status); color: #fff;
      box-shadow: 0 4px 16px oklch(58% 0.17 142 / 0.3);
    }
    .btn-modal-approve:hover { background: oklch(50% 0.15 142); }
    .btn-modal-reject {
      background: var(--red-status); color: #fff;
      box-shadow: 0 4px 16px oklch(53% 0.22 22 / 0.25);
    }
    .btn-modal-reject:hover { background: oklch(46% 0.2 22); }

    /* Rejection reason textarea in modal */
    .rejection-form {
      display: flex; flex-direction: column; gap: 8px;
      text-align: left;
    }
    .rejection-form .rj-label {
      font-size: 12px; font-weight: 700; color: var(--fg-secondary);
    }
    .rejection-form .rj-label .required { color: var(--red-status); }
    .rejection-form textarea {
      width: 100%; min-height: 90px; padding: 10px 12px;
      border: 1.5px solid var(--border-subtle);
      border-radius: var(--radius-sm);
      font-family: var(--font-body); font-size: 13px; color: var(--fg);
      background: var(--surface-solid); resize: vertical; outline: none;
    }
    .rejection-form textarea:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px oklch(56% 0.19 148 / 0.1);
    }
    .rejection-form .rj-error {
      font-size: 10px; color: var(--red-status); font-weight: 600; display: none;
    }

    /* Toast */
    .toast {
      position: fixed; top: 20px; left: 50%; transform: translateX(-50%) translateY(-120px);
      background: var(--surface-solid); border: 1px solid var(--green-status);
      border-radius: var(--radius-md); padding: 14px 20px;
      font-weight: 600; font-size: 14px; color: var(--fg);
      box-shadow: 0 8px 30px rgba(0,0,0,0.12);
      z-index: 100; display: flex; align-items: center; gap: 8px;
      transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .toast.show { transform: translateX(-50%) translateY(0); }
    .toast.error { border-color: var(--red-status); }

    /* Bottom nav (mobile) */
    .bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 30; height: 64px; background: oklch(98% 0.003 250 / 0.92); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border-top: 1px solid rgba(0,0,0,0.06); align-items: center; justify-content: space-around; padding: 0 8px; padding-bottom: env(safe-area-inset-bottom, 0px); }
    .bottom-nav a { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 6px 10px; border: none; background: none; font: 10px/1 var(--font-body); color: var(--muted); cursor: pointer; transition: color 0.15s; font-weight: 500; text-decoration: none; }
    .bottom-nav a .nav-icon { font-size: 20px; line-height: 1; }
    .bottom-nav a.active { color: var(--accent); font-weight: 700; }

    /* Responsive */
    @media (min-width: 640px) {
      .topbar { padding: 16px 0 12px; }
      .topbar .title { font-size: 20px; }
      .stats-grid { grid-template-columns: repeat(4, 1fr); gap: 12px; }
      .stat-card .sc-value { font-size: 34px; }
      .card { padding: 20px 22px; }
      table.data-table { font-size: 13px; min-width: 720px; }
    }
    @media (min-width: 1024px) {
      .card { padding: 24px 28px; }
      .card-title { font-size: 17px; }
      .stats-grid { gap: 16px; }
      .stat-card { padding: 20px 18px; }
      .stat-card .sc-value { font-size: 38px; }
      table.data-table { font-size: 14px; }
      table.data-table thead th { font-size: 11px; }
    }
    @media (max-width: 800px) { .sidebar { width: 210px; min-width: 210px; } .main-inner { padding: 16px 12px 80px; } }
    @media (max-width: 660px) { .sidebar { display: none; } .bottom-nav { display: flex; } .main-inner { padding: 14px 10px 80px; } }

    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after {
        animation-duration: 0.01ms !important;
        transition-duration: 0.01ms !important;
      }
    }

    button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
      outline: 2px solid var(--accent); outline-offset: 2px;
    }
  </style>
</head>
<body>

  <div class="toast" id="toast" style="display:none;">
    <span id="toast-icon">✅</span>
    <span id="toast-msg"></span>
  </div>

  <div class="app">
    <?php include 'boss_sidebar.php'; ?>

    <main class="main">
      <div class="main-inner">
        <div class="page">

  <!-- ==========================================================
       APPROVAL CONFIRMATION MODAL
       ========================================================== -->
  <div class="modal-overlay" id="modal-approve">
    <div class="modal-dialog">
      <span class="modal-icon">✅</span>
      <div class="modal-title">Approve Leave Request?</div>
      <div class="modal-desc" id="approve-desc">You are about to approve this leave. The balance will be deducted.</div>
      <div class="modal-actions">
        <button class="btn btn-modal-cancel" onclick="closeModal('modal-approve')">Cancel</button>
        <form method="POST" id="approve-form" style="flex:1;display:flex;">
          <input type="hidden" name="request_id" id="approve-request-id">
          <input type="hidden" name="action" value="approve">
          <button type="submit" class="btn btn-modal-approve" style="width:100%;">Yes, Approve</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ==========================================================
       REJECTION MODAL
       ========================================================== -->
  <div class="modal-overlay" id="modal-reject">
    <div class="modal-dialog">
      <span class="modal-icon">⚠️</span>
      <div class="modal-title">Reject Leave Request?</div>
      <div class="modal-desc">Please provide a reason for rejecting this leave request.</div>
      <form method="POST" id="reject-form">
        <input type="hidden" name="request_id" id="reject-request-id">
        <input type="hidden" name="action" value="reject">
        <div class="rejection-form">
          <span class="rj-label">Rejection Reason <span class="required">*</span></span>
          <textarea name="admin_remark" id="rejection-textarea" placeholder="Provide a reason for rejection…" required></textarea>
          <span class="rj-error" id="rj-error">Please provide a rejection reason</span>
        </div>
        <div class="modal-actions" style="margin-top:14px;">
          <button type="button" class="btn btn-modal-cancel" onclick="closeModal('modal-reject')">Cancel</button>
          <button type="submit" class="btn btn-modal-reject">Confirm Rejection</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==========================================================
       DETAIL DRAWER
       ========================================================== -->
  <div class="drawer-overlay" id="drawer-overlay"></div>
  <aside class="drawer" id="drawer">
    <div class="drawer-header">
      <span class="drawer-title">📋 Leave Request Detail</span>
      <button class="drawer-close" id="drawer-close" aria-label="Close drawer">✕</button>
    </div>
    <div class="drawer-body" id="drawer-body">
      <!-- Populated by JS -->
    </div>
  </aside>

  <!-- ==========================================================
       PAGE
       ========================================================== -->
  <div class="page">

    <!-- Top bar -->
    <header class="topbar">
      <div class="topbar-left">
        <span class="title">📋 Leave Approvals</span>
        <span class="admin-badge">👑 Admin</span>
      </div>
      <div class="topbar-actions">
        <?php if ($stat_pending > 0): ?>
        <span class="notif-badge" id="notif-badge" onclick="switchTab('pending')">
          <span class="notif-dot"></span>
          <span id="notif-text"><?= $stat_pending ?> Pending</span>
        </span>
        <?php endif; ?>
        <a href="store_admin.php" class="action-link">🎁 Store</a>
        <a href="master_calendar.php" class="action-link">📅 Calendar</a>
        <a href="../logout.php" class="action-link">Logout</a>
      </div>
    </header>

    <!-- ======================================================
         SECTION 1 — SUMMARY STATS
         ====================================================== -->
    <div class="stats-grid" id="stats-grid">
      <div class="stat-card pending">
        <span class="sc-icon">⏳</span>
        <span class="sc-label">Pending Requests</span>
        <span class="sc-value" id="stat-pending"><?= $stat_pending ?></span>
      </div>
      <div class="stat-card approved">
        <span class="sc-icon">✅</span>
        <span class="sc-label">Approved This Month</span>
        <span class="sc-value" id="stat-approved"><?= $stat_approved ?></span>
      </div>
      <div class="stat-card rejected">
        <span class="sc-icon">❌</span>
        <span class="sc-label">Rejected This Month</span>
        <span class="sc-value" id="stat-rejected"><?= $stat_rejected ?></span>
      </div>
      <div class="stat-card on-leave">
        <span class="sc-icon">🏖️</span>
        <span class="sc-label">On Leave Today</span>
        <span class="sc-value" id="stat-onleave"><?= $stat_onleave ?></span>
      </div>
    </div>

    <!-- ======================================================
         SECTION 2 — TABLE WITH TABS
         ====================================================== -->
    <section class="card" id="table-card">
      <div class="card-header">
        <div class="tab-switcher" id="tab-switcher">
          <button class="tab-btn active" data-tab="pending">Pending <span class="tab-count">(<?= $stat_pending ?>)</span></button>
          <button class="tab-btn" data-tab="approved">Approved <span class="tab-count">(<?= $stat_approved ?>)</span></button>
          <button class="tab-btn" data-tab="rejected">Rejected <span class="tab-count">(<?= $stat_rejected ?>)</span></button>
        </div>
      </div>

      <!-- Search & Filter -->
      <div class="search-filter-row" id="search-filter-row">
        <input type="text" class="search-input" id="search-input" placeholder="🔍 Search by employee name…" />
        <select class="filter-select" id="filter-type">
          <option value="all">All Types</option>
          <option value="AL">Annual Leave</option>
          <option value="MC">Medical Leave</option>
          <option value="UL">Unpaid Leave</option>
        </select>
      </div>

      <!-- Table -->
      <div class="table-wrap">
        <table class="data-table" id="data-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Type</th>
              <th>Start</th>
              <th>End</th>
              <th>Days</th>
              <th>Reason</th>
              <th>Applied</th>
              <th>Status</th>
              <th id="th-actions">Actions</th>
            </tr>
          </thead>
          <tbody id="table-body">
            <!-- Rendered by JS -->
          </tbody>
        </table>
      </div>
    </section>

        </div><!-- /.page -->
      </div><!-- /.main-inner -->
    </main>
  </div><!-- /.app -->

  <!-- Mobile bottom nav -->
  <?php include 'boss_bottom_nav.php'; ?>

  <!-- ==========================================================
       SCRIPTS
       ========================================================== -->
  <script>
    /* ---- Data from PHP ---- */
    const allRequests = <?= $requests_json ?>;

    /* ---- Small cache of leave balances (fetched on demand via drawer) ---- */
    const balanceCache = {};

    /* ---- State ---- */
    const state = {
      activeTab: 'pending',
      searchQuery: '',
      filterType: 'all',
    };

    /* ---- DOM refs ---- */
    const $ = (s) => document.querySelector(s);
    const $$ = (s) => document.querySelectorAll(s);
    const tableBody = $('#table-body');
    const tableCard = $('#table-card');
    const searchInput = $('#search-input');
    const filterType = $('#filter-type');
    const thActions = $('#th-actions');
    const toast = $('#toast');
    const toastIcon = $('#toast-icon');
    const toastMsg = $('#toast-msg');
    const drawer = $('#drawer');
    const drawerOverlay = $('#drawer-overlay');
    const drawerBody = $('#drawer-body');

    /* ---- Filter helpers ---- */
    function getVisibleRequests() {
      let filtered = allRequests.filter(r => {
        if (state.activeTab === 'pending') return r.status === 'pending';
        if (state.activeTab === 'approved') return r.status === 'approved';
        if (state.activeTab === 'rejected') return r.status === 'rejected';
        return true;
      });
      if (state.searchQuery) {
        const q = state.searchQuery.toLowerCase();
        filtered = filtered.filter(r => r.name.toLowerCase().includes(q));
      }
      if (state.filterType !== 'all') {
        filtered = filtered.filter(r => r.leave_type === state.filterType);
      }
      return filtered;
    }

    /* ---- Render table ---- */
    function renderTable() {
      const visible = getVisibleRequests();
      const isHistoryTab = state.activeTab !== 'pending';

      // Update actions column header
      if (thActions) {
        thActions.textContent = isHistoryTab ? 'Notes' : 'Actions';
      }

      if (visible.length === 0) {
        const tabLabel = state.activeTab.charAt(0).toUpperCase() + state.activeTab.slice(1);
        tableBody.innerHTML = `<tr><td colspan="9">
          <div class="empty-state">
            <div class="empty-icon">📭</div>
            <div class="empty-text">No ${tabLabel} requests found</div>
            <div class="empty-sub">${state.searchQuery || state.filterType !== 'all' ? 'Try adjusting your search or filter' : ''}</div>
          </div>
        </td></tr>`;
        return;
      }

      const typeLabels = { AL: 'Annual', MC: 'Medical', UL: 'Unpaid' };
      const typeIcons = { AL: '🏖️', MC: '🏥', UL: '📅' };
      const statusLabels = { pending: 'Pending', approved: 'Approved', rejected: 'Rejected' };

      let html = '';
      visible.forEach((r, i) => {
        const startDate = new Date(r.start_date + 'T00:00:00');
        const endDate = new Date(r.end_date + 'T00:00:00');
        const appliedDate = new Date(r.applied_at.replace(' ', 'T'));

        const startStr = startDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        const endStr = endDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        const appliedStr = appliedDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

        html += `<tr data-id="${r.id}">
          <td>
            <div class="employee-cell">
              <div class="emp-avatar" style="background:${r.avatarBg};">${r.initials}</div>
              <span class="emp-name">${r.name}</span>
            </div>
          </td>
          <td><span class="leave-type-badge ${r.leave_type.toLowerCase() === 'al' ? 'annual' : r.leave_type.toLowerCase() === 'mc' ? 'medical' : 'unpaid'}">${typeIcons[r.leave_type]} ${typeLabels[r.leave_type]}</span></td>
          <td>${startStr}</td>
          <td>${endStr}</td>
          <td>${r.duration} day${r.duration !== 1 ? 's' : ''}</td>
          <td>${r.custom_reason || r.reason}</td>
          <td>${appliedStr}</td>
          <td><span class="status-badge ${r.status}"><span class="status-dot ${r.status}"></span> ${statusLabels[r.status]}</span></td>
          <td>
            ${isHistoryTab
              ? (r.admin_remark ? `<span style="font-size:11px;color:var(--muted);">${r.admin_remark}</span>` : '<span style="font-size:11px;color:var(--muted);">—</span>')
              : `<div class="action-btns">
                  <button class="btn-sm btn-sm-approve" data-id="${r.id}" title="Approve">✓ Approve</button>
                  <button class="btn-sm btn-sm-reject" data-id="${r.id}" title="Reject">✕ Reject</button>
                </div>`
            }
          </td>
        </tr>`;
      });

      tableBody.innerHTML = html;

      // Attach row click to open drawer
      tableBody.querySelectorAll('tr').forEach(row => {
        row.addEventListener('click', function(e) {
          if (e.target.closest('.btn-sm') || e.target.closest('button')) return;
          const id = parseInt(this.dataset.id);
          openDrawer(id);
        });
      });

      // Attach action button handlers
      $$('.btn-sm-approve').forEach(btn => {
        btn.addEventListener('click', function(e) {
          e.stopPropagation();
          openApproveModal(parseInt(this.dataset.id));
        });
      });
      $$('.btn-sm-reject').forEach(btn => {
        btn.addEventListener('click', function(e) {
          e.stopPropagation();
          openRejectModal(parseInt(this.dataset.id));
        });
      });
    }

    /* ---- Drawer ---- */
    function openDrawer(requestId) {
      const r = allRequests.find(x => x.id === requestId);
      if (!r) return;

      const startDate = new Date(r.start_date + 'T00:00:00');
      const endDate = new Date(r.end_date + 'T00:00:00');
      const typeLabels = { AL: 'Annual Leave', MC: 'Medical Leave', UL: 'Unpaid Leave' };

      // Fetch balances (cached)
      const cacheKey = r.user_id + '_' + new Date().getFullYear();
      if (!balanceCache[cacheKey]) {
        // We'll embed balance data in the request data attributes or fetch via API
        // For now, show a note
      }

      drawerBody.innerHTML = `
        <div class="drawer-section">
          <span class="ds-title">Employee</span>
          <div class="drawer-emp-card">
            <div class="dec-avatar" style="background:${r.avatarBg};">${r.initials}</div>
            <div class="dec-info">
              <span class="dec-name">${r.name}</span>
              <span class="dec-email">${r.email || '—'}</span>
            </div>
          </div>
        </div>

        <div class="drawer-section">
          <span class="ds-title">Leave Details</span>
          <div class="drawer-info-row"><span class="di-label">Type</span><span class="di-value">${typeLabels[r.leave_type]}</span></div>
          <div class="drawer-info-row"><span class="di-label">Date Range</span><span class="di-value">${startDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric' })} – ${endDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}</span></div>
          <div class="drawer-info-row"><span class="di-label">Total Days</span><span class="di-value">${r.duration} working day${r.duration !== 1 ? 's' : ''}</span></div>
          <div class="drawer-info-row"><span class="di-label">Reason</span><span class="di-value">${r.reason}</span></div>
          ${r.custom_reason ? `<div class="drawer-info-row"><span class="di-label">Details</span><span class="di-value">${r.custom_reason}</span></div>` : ''}
          <div class="drawer-info-row"><span class="di-label">Applied</span><span class="di-value">${new Date(r.applied_at.replace(' ', 'T')).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}</span></div>
          <div class="drawer-info-row"><span class="di-label">Status</span><span class="di-value" style="color:${r.status === 'approved' ? 'var(--green-status)' : r.status === 'rejected' ? 'var(--red-status)' : 'var(--yellow-status)'};">${r.status.charAt(0).toUpperCase() + r.status.slice(1)}</span></div>
          ${r.admin_remark ? `<div class="drawer-info-row"><span class="di-label">Remark</span><span class="di-value" style="color:var(--red-status);">${r.admin_remark}</span></div>` : ''}
        </div>

        ${r.status === 'pending' ? `
        <div class="drawer-actions" id="drawer-actions">
          <button class="btn-approve" id="drawer-approve">✓ Approve</button>
          <button class="btn-drawer-reject" id="drawer-reject">✕ Reject</button>
        </div>
        ` : ''}
      `;

      drawer.classList.add('open');
      drawerOverlay.classList.add('open');

      // Drawer action handlers
      const drawerApprove = $('#drawer-approve');
      const drawerReject = $('#drawer-reject');
      if (drawerApprove) {
        drawerApprove.addEventListener('click', () => {
          closeDrawer();
          openApproveModal(r.id);
        });
      }
      if (drawerReject) {
        drawerReject.addEventListener('click', () => {
          closeDrawer();
          openRejectModal(r.id);
        });
      }
    }

    function closeDrawer() {
      drawer.classList.remove('open');
      drawerOverlay.classList.remove('open');
    }

    $('#drawer-close').addEventListener('click', closeDrawer);
    drawerOverlay.addEventListener('click', closeDrawer);

    /* ---- Modals ---- */
    function openModal(id) {
      document.getElementById(id).classList.add('open');
    }
    function closeModal(id) {
      document.getElementById(id).classList.remove('open');
    }

    function openApproveModal(requestId) {
      const r = allRequests.find(x => x.id === requestId);
      if (!r) return;
      document.getElementById('approve-request-id').value = requestId;
      document.getElementById('approve-desc').textContent =
        `You are about to approve ${r.name}'s ${r.leave_type} leave (${r.start_date} to ${r.end_date}). The balance will be deducted.`;
      openModal('modal-approve');
    }

    function openRejectModal(requestId) {
      const r = allRequests.find(x => x.id === requestId);
      if (!r) return;
      document.getElementById('reject-request-id').value = requestId;
      document.getElementById('rejection-textarea').value = '';
      document.getElementById('rj-error').style.display = 'none';
      openModal('modal-reject');
    }

    // Close modals on overlay click
    $$('.modal-overlay').forEach(overlay => {
      overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeModal(this.id);
      });
    });

    // Rejection form validation
    document.getElementById('reject-form').addEventListener('submit', function(e) {
      const textarea = document.getElementById('rejection-textarea');
      const reason = textarea.value.trim();
      if (!reason) {
        e.preventDefault();
        document.getElementById('rj-error').style.display = 'block';
        textarea.focus();
      }
    });

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

    <?php if ($message): ?>
    showToast('<?= $message_type === 'success' ? '✅' : ($message_type === 'error' ? '❌' : '✅') ?>', <?= json_encode($message) ?>, '<?= $message_type ?>');
    <?php endif; ?>

    /* ---- Tab switching ---- */
    function switchTab(tab) {
      state.activeTab = tab;
      state.searchQuery = '';
      state.filterType = 'all';
      searchInput.value = '';
      filterType.value = 'all';
      $$('.tab-btn').forEach(b => {
        b.classList.remove('active');
        if (b.dataset.tab === tab) b.classList.add('active');
      });
      renderTable();
    }

    $$('.tab-btn').forEach(btn => {
      btn.addEventListener('click', function() {
        switchTab(this.dataset.tab);
      });
    });

    /* ---- Search & Filter ---- */
    searchInput.addEventListener('input', function() {
      state.searchQuery = this.value;
      renderTable();
    });
    filterType.addEventListener('change', function() {
      state.filterType = this.value;
      renderTable();
    });

    /* ---- Keyboard shortcuts ---- */
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        if (document.getElementById('modal-approve').classList.contains('open')) closeModal('modal-approve');
        else if (document.getElementById('modal-reject').classList.contains('open')) closeModal('modal-reject');
        else if (drawer.classList.contains('open')) closeDrawer();
      }
    });

    /* ---- Init ---- */
    renderTable();
  </script>

</body>
</html>