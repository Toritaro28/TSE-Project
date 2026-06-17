<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = ''; // 'success' or 'error' for toast styling

// Handle Redemption
if (isset($_POST['redeem_item'])) {
    $item_id = $_POST['item_id'];

    // Fetch user points and item details
    $stmt = $pdo->prepare("SELECT total_points FROM users WHERE id = ?"); $stmt->execute([$user_id]); $user = $stmt->fetch();
    $stmt = $pdo->prepare("SELECT * FROM reward_items WHERE id = ?"); $stmt->execute([$item_id]); $item = $stmt->fetch();

    if (!$item) {
        $message = "Item not found.";
        $message_type = 'error';
    } elseif ($item['stock_quantity'] <= 0) {
        $message = "Sorry, this item is out of stock!";
        $message_type = 'error';
    } elseif ($user['total_points'] < $item['points_required']) {
        $message = "Not enough points! You need " . $item['points_required'] . " points.";
        $message_type = 'error';
    } else {
        // Escrow Transaction
        try {
            $pdo->beginTransaction();
            // 1. Deduct Stock
            $pdo->prepare("UPDATE reward_items SET stock_quantity = stock_quantity - 1 WHERE id = ?")->execute([$item_id]);
            // 2. Deduct User Points
            $pdo->prepare("UPDATE users SET total_points = total_points - ? WHERE id = ?")->execute([$item['points_required'], $user_id]);
            // 3. Create Redemption Record
            $pdo->prepare("INSERT INTO reward_redemptions (user_id, item_id, points_spent) VALUES (?, ?, ?)")->execute([$user_id, $item_id, $item['points_required']]);
            // 4. Ledger entry
            $pdo->prepare("INSERT INTO point_transactions (user_id, amount, description) VALUES (?, ?, ?)")->execute([$user_id, -$item['points_required'], "Redeemed: " . $item['name']]);
            $pdo->commit();
            $message = "Item Redeemed! Status is Pending for Annual Dinner. " . $item['points_required'] . " points deducted.";
            $message_type = 'success';
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Transaction failed. Please try again.";
            $message_type = 'error';
        }
    }
}

// Handle Cancelation / Refund
if (isset($_POST['cancel_order'])) {
    $redemption_id = $_POST['redemption_id'];

    $stmt = $pdo->prepare("SELECT * FROM reward_redemptions WHERE id = ? AND user_id = ? AND status = 'pending'");
    $stmt->execute([$redemption_id, $user_id]);
    $order = $stmt->fetch();

    if ($order) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE reward_redemptions SET status = 'cancelled' WHERE id = ?")->execute([$redemption_id]);
            $pdo->prepare("UPDATE reward_items SET stock_quantity = stock_quantity + 1 WHERE id = ?")->execute([$order['item_id']]);
            $pdo->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?")->execute([$order['points_spent'], $user_id]);
            $pdo->prepare("INSERT INTO point_transactions (user_id, amount, description) VALUES (?, ?, ?)")->execute([$user_id, $order['points_spent'], "Refund: Cancelled item redemption"]);
            $pdo->commit();
            $message = "Order cancelled. " . $order['points_spent'] . " points have been fully refunded!";
            $message_type = 'success';
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Cancellation failed. Please try again.";
            $message_type = 'error';
        }
    }
}

// Fetch Data
$stmt = $pdo->prepare("SELECT total_points FROM users WHERE id = ?"); $stmt->execute([$user_id]); $user = $stmt->fetch();
$items = $pdo->query("SELECT * FROM reward_items WHERE is_active = 1")->fetchAll();
$my_orders = $pdo->prepare("SELECT rr.*, ri.name as item_name FROM reward_redemptions rr JOIN reward_items ri ON rr.item_id = ri.id WHERE rr.user_id = ? ORDER BY rr.created_at DESC");
$my_orders->execute([$user_id]);
$orders = $my_orders->fetchAll();

// Count pending for badge
$pending_count = 0;
foreach ($orders as $o) {
    if ($o['status'] === 'pending') $pending_count++;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>Rewards Store — LeafPoint</title>
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
      --accent-dark: oklch(48% 0.16 148);
      --gold: oklch(70% 0.19 82);
      --gold-soft: oklch(82% 0.13 82);

      --green-status: oklch(58% 0.17 142);
      --yellow-status: oklch(68% 0.15 85);
      --red-status: oklch(53% 0.22 22);
      --blue-info: oklch(56% 0.16 255);
      --gray-muted: oklch(68% 0.005 250);

      --radius-sm: 10px;
      --radius-md: 16px;
      --radius-lg: 22px;
      --radius-xl: 28px;
      --shadow-card: 0 2px 16px rgba(0,0,0,0.04), 0 0 0 1px rgba(0,0,0,0.03);
      --shadow-card-hover: 0 6px 28px rgba(0,0,0,0.07), 0 0 0 1px rgba(0,0,0,0.04);
      --shadow-modal: 0 20px 60px rgba(0,0,0,0.18), 0 0 0 1px rgba(0,0,0,0.06);

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

    /* ---- Page shell (mobile-first) ---- */
    .page {
      max-width: 480px;
      margin: 0 auto;
      padding: 0 16px 100px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      min-height: 100vh;
    }

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
    .topbar .cart-btn {
      width: 36px; height: 36px; border-radius: 50%;
      background: var(--surface-glass); border: 1px solid var(--border-subtle);
      display: grid; place-items: center; font-size: 18px;
      cursor: pointer; transition: background 0.15s; color: var(--fg);
      position: relative; text-decoration: none;
    }
    .topbar .cart-btn:hover { background: var(--surface-glass-hover); }
    .topbar .cart-btn .cart-badge {
      position: absolute; top: -4px; right: -4px;
      width: 18px; height: 18px; border-radius: 50%;
      background: var(--accent); color: #fff;
      font-size: 10px; font-weight: 700;
      display: grid; place-items: center;
    }

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
       POINTS HERO
       ============================================================ */
    .points-hero {
      position: relative;
      background: var(--surface-glass);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-card), 0 0 50px oklch(70% 0.19 82 / 0.2);
      padding: 24px 20px;
      display: flex; flex-direction: column; align-items: center;
      gap: 8px; text-align: center; overflow: hidden;
    }
    .points-hero::before {
      content: '';
      position: absolute; top: -50px; right: -40px;
      width: 200px; height: 200px;
      background: radial-gradient(circle, oklch(70% 0.19 82 / 0.15), transparent 70%);
      pointer-events: none;
    }
    .points-hero::after {
      content: '';
      position: absolute; bottom: -30px; left: -30px;
      width: 160px; height: 160px;
      background: radial-gradient(circle, oklch(56% 0.19 148 / 0.1), transparent 70%);
      pointer-events: none;
    }
    .points-hero .points-icon {
      font-size: 40px; position: relative; z-index: 1;
      animation: floatCoin 3s ease-in-out infinite;
    }
    @keyframes floatCoin {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-6px); }
    }
    .points-hero .points-value {
      font-family: var(--font-display); font-size: 42px; font-weight: 800;
      letter-spacing: -0.03em; color: var(--fg); position: relative; z-index: 1;
    }
    .points-hero .points-value .pts-unit {
      font-size: 18px; font-weight: 600; color: var(--muted);
      margin-left: 4px;
    }
    .points-hero .points-sub {
      font-size: 13px; color: var(--muted); position: relative; z-index: 1;
      max-width: 280px; line-height: 1.5;
    }

    /* ============================================================
       STORE GRID
       ============================================================ */
    .store-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
    }
    .reward-card {
      background: var(--surface-glass);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border: 1px solid var(--border-glass);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
      overflow: hidden;
      display: flex; flex-direction: column;
      transition: all 0.25s;
    }
    .reward-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-card-hover);
      background: var(--surface-glass-hover);
    }
    .reward-card .card-image {
      width: 100%; aspect-ratio: 4/3;
      display: grid; place-items: center;
      font-size: 48px;
      position: relative; overflow: hidden;
      background: linear-gradient(135deg, oklch(60% 0.08 150 / 0.3), oklch(50% 0.05 150 / 0.2));
    }
    .reward-card .card-image .img-icon {
      position: relative; z-index: 1;
      filter: drop-shadow(0 4px 12px rgba(0,0,0,0.1));
    }
    .reward-card .card-body {
      padding: 14px;
      display: flex; flex-direction: column; gap: 8px;
      flex: 1;
    }
    .reward-card .card-name {
      font-family: var(--font-display); font-size: 14px; font-weight: 700;
      letter-spacing: -0.01em; color: var(--fg);
    }
    .reward-card .card-price {
      display: flex; align-items: center; gap: 4px;
      font-family: var(--font-mono); font-size: 13px; font-weight: 700;
      color: var(--gold);
    }
    .reward-card .card-stock {
      font-size: 10px; color: var(--muted);
    }
    .reward-card .card-stock.low { color: var(--red-status); font-weight: 600; }
    .reward-card .btn-redeem {
      width: 100%; padding: 10px; border: none;
      border-radius: var(--radius-sm);
      background: var(--accent); color: #fff;
      font-family: var(--font-body); font-size: 13px; font-weight: 700;
      cursor: pointer; transition: all 0.2s;
      letter-spacing: -0.01em;
    }
    .reward-card .btn-redeem:hover {
      background: var(--accent-dark);
      box-shadow: 0 4px 16px oklch(56% 0.19 148 / 0.3);
    }
    .reward-card .btn-redeem:disabled {
      background: oklch(88% 0.01 155); color: oklch(60% 0.03 155);
      cursor: not-allowed;
    }
    .reward-card .btn-redeem:disabled:hover { box-shadow: none; }

    /* ============================================================
       CONFIRMATION MODAL
       ============================================================ */
    .modal-overlay {
      position: fixed; inset: 0; z-index: 50;
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
      max-width: 400px; width: 100%;
      text-align: center;
      display: flex; flex-direction: column; gap: 16px;
      transform: translateY(20px) scale(0.96);
      transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .modal-overlay.open .modal-dialog {
      transform: translateY(0) scale(1);
    }
    .modal-dialog .modal-icon {
      font-size: 56px;
      animation: modalPopIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes modalPopIn {
      0% { transform: scale(0); opacity: 0; }
      60% { transform: scale(1.2); }
      100% { transform: scale(1); opacity: 1; }
    }
    .modal-dialog .modal-title {
      font-family: var(--font-display); font-size: 20px; font-weight: 800;
      letter-spacing: -0.02em;
    }
    .modal-dialog .modal-desc {
      font-size: 14px; color: var(--fg-secondary); line-height: 1.5;
    }
    .modal-dialog .modal-detail {
      background: oklch(96% 0.01 240); border-radius: var(--radius-sm);
      padding: 12px 16px; font-size: 12px; color: var(--fg-secondary);
      display: flex; flex-direction: column; gap: 4px;
    }
    .modal-dialog .modal-detail .md-row {
      display: flex; justify-content: space-between;
    }
    .modal-dialog .modal-detail .md-row .md-label { color: var(--muted); }
    .modal-dialog .modal-detail .md-row .md-value { font-weight: 700; color: var(--fg); }
    .modal-actions {
      display: flex; gap: 10px;
    }
    .modal-actions .btn {
      flex: 1; padding: 12px; border-radius: var(--radius-sm);
      font-family: var(--font-body); font-size: 14px; font-weight: 700;
      cursor: pointer; transition: all 0.2s;
      letter-spacing: -0.01em; border: none;
    }
    .btn-cancel {
      background: oklch(92% 0.006 250); color: var(--fg-secondary);
    }
    .btn-cancel:hover { background: oklch(87% 0.006 250); }
    .btn-confirm {
      background: var(--accent); color: #fff;
      box-shadow: 0 4px 16px oklch(56% 0.19 148 / 0.3);
    }
    .btn-confirm:hover {
      background: var(--accent-dark);
      box-shadow: 0 6px 22px oklch(56% 0.19 148 / 0.4);
    }

    /* ---- Toast ---- */
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
    .toast.error { border-color: var(--red-status); }

    /* ============================================================
       REDEMPTION HISTORY TABLE
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

    table.history-table {
      width: 100%; border-collapse: collapse;
      font-size: 13px; min-width: 480px;
    }
    table.history-table thead th {
      text-align: left; padding: 10px 12px;
      font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em;
      color: var(--muted); font-weight: 700;
      border-bottom: 1px solid var(--border-subtle);
      white-space: nowrap;
    }
    table.history-table tbody td {
      padding: 12px;
      border-bottom: 1px solid oklch(94% 0.003 250);
      white-space: nowrap;
    }
    table.history-table tbody tr {
      transition: background 0.15s;
    }
    table.history-table tbody tr:hover { background: rgba(0,0,0,0.018); }
    table.history-table tbody tr:last-child td { border-bottom: none; }

    .status-badge {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 5px 12px; border-radius: 999px;
      font-size: 11px; font-weight: 700; letter-spacing: 0.01em;
    }
    .status-badge.pending { background: oklch(94% 0.06 85); color: oklch(42% 0.12 80); }
    .status-badge.completed { background: oklch(92% 0.06 148); color: oklch(36% 0.1 148); }
    .status-badge.cancelled { background: oklch(92% 0.003 250); color: oklch(48% 0.005 250); }
    .status-badge.rejected { background: oklch(92% 0.04 22); color: oklch(40% 0.12 22); }
    .status-dot {
      width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0;
    }
    .status-dot.pending { background: var(--yellow-status); animation: pulse-dot 1.6s ease-in-out infinite; }
    .status-dot.completed { background: var(--green-status); }
    .status-dot.cancelled { background: var(--gray-muted); }
    .status-dot.rejected { background: var(--red-status); }
    @keyframes pulse-dot {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.35; }
    }

    .empty-state {
      text-align: center; padding: 32px 16px; color: var(--muted);
    }
    .empty-state .empty-icon { font-size: 40px; margin-bottom: 8px; }
    .empty-state .empty-text { font-size: 14px; font-weight: 600; }
    .empty-state .empty-sub { font-size: 11px; margin-top: 4px; }

    /* ---- Responsive ---- */
    @media (min-width: 600px) {
      .page {
        max-width: 720px; padding: 0 24px 40px; gap: 20px;
      }
      .topbar { padding: 18px 0 12px; }
      .topbar .title { font-size: 20px; }
      .card { padding: 22px 24px; }
      .points-hero { padding: 32px 28px; }
      .points-hero .points-value { font-size: 52px; }
      .store-grid { grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
      .reward-card .card-name { font-size: 15px; }
      table.history-table { font-size: 14px; }
      table.history-table thead th { font-size: 11px; }
    }
    @media (min-width: 1024px) {
      .page {
        max-width: 1060px; padding: 0 32px 48px; gap: 24px;
      }
      .card { padding: 26px 30px; }
      .card-title { font-size: 17px; }
      .points-hero { padding: 36px 32px; }
      .points-hero .points-value { font-size: 60px; }
      .store-grid { grid-template-columns: repeat(4, 1fr); gap: 16px; }
      .reward-card .card-name { font-size: 16px; }
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
       MODAL
       ========================================================== -->
  <div class="modal-overlay" id="modal-overlay">
    <div class="modal-dialog" id="modal-dialog">
      <span class="modal-icon" id="modal-icon">🎁</span>
      <div class="modal-title" id="modal-title">Confirm Redemption</div>
      <div class="modal-desc" id="modal-desc">You are about to redeem this reward using your points.</div>
      <div class="modal-detail" id="modal-detail">
        <div class="md-row"><span class="md-label">Item</span><span class="md-value" id="md-item">—</span></div>
        <div class="md-row"><span class="md-label">Points Required</span><span class="md-value" id="md-points">—</span></div>
        <div class="md-row"><span class="md-label">Your Balance</span><span class="md-value" id="md-balance">—</span></div>
        <div class="md-row"><span class="md-label">Remaining After</span><span class="md-value" id="md-remaining">—</span></div>
      </div>
      <div class="modal-actions">
        <button class="btn btn-cancel" id="btn-cancel">Cancel</button>
        <button class="btn btn-confirm" id="btn-confirm">Confirm Redemption</button>
      </div>
    </div>
  </div>

  <!-- ==========================================================
       PAGE
       ========================================================== -->
  <div class="page">

    <!-- Top bar -->
    <header class="topbar">
      <a class="back-btn" href="employee_dashboard.php" aria-label="Back">←</a>
      <span class="title">🎁 Rewards Store</span>
      <span class="cart-btn" aria-label="Pending redemptions" title="Pending orders">
        📋
        <?php if ($pending_count > 0): ?>
        <span class="cart-badge" id="cart-badge"><?= $pending_count ?></span>
        <?php endif; ?>
      </span>
    </header>

    <!-- ======================================================
         POINTS HERO
         ====================================================== -->
    <section class="points-hero" id="points-hero">
      <span class="points-icon">⭐</span>
      <div class="points-value">
        <?= number_format($user['total_points']) ?><span class="pts-unit">pts</span>
      </div>
      <p class="points-sub">Earn more points through consistent attendance and streaks. Redeem for rewards at the annual dinner.</p>
    </section>

    <!-- ======================================================
         STORE GRID
         ====================================================== -->
    <section class="store-grid" id="store-grid">
      <?php if (count($items) === 0): ?>
        <div class="empty-state" style="grid-column: 1 / -1;">
          <div class="empty-icon">📦</div>
          <div class="empty-text">No rewards available</div>
          <div class="empty-sub">Check back later for new items</div>
        </div>
      <?php endif; ?>

      <?php foreach ($items as $item): ?>
        <?php
          $can_afford = $user['total_points'] >= $item['points_required'];
          $in_stock = $item['stock_quantity'] > 0;
          $stock_class = $item['stock_quantity'] <= 2 ? ' low' : '';
          $shortfall = $item['points_required'] - $user['total_points'];
          // Generate a consistent icon per item based on name
          $icons = ['🎁', '🎧', '📱', '⌨️', '🎮', '☕', '🎬', '🖱️', '⌚', '🛒', '📐', '🪴', '💻', '🎯', '📚'];
          $icon = $icons[$item['id'] % count($icons)];
        ?>
        <div class="reward-card" data-id="<?= $item['id'] ?>"
             data-name="<?= htmlspecialchars($item['name']) ?>"
             data-points="<?= $item['points_required'] ?>"
             data-stock="<?= $item['stock_quantity'] ?>">
          <div class="card-image">
            <?php if ($item['image_url']): ?>
              <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0;">
            <?php else: ?>
              <span class="img-icon"><?= $icon ?></span>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <span class="card-name"><?= htmlspecialchars($item['name']) ?></span>
            <span class="card-price">⭐ <?= number_format($item['points_required']) ?> pts</span>
            <span class="card-stock<?= $stock_class ?>"><?= $item['stock_quantity'] ?> left in stock</span>
            <?php if (!$in_stock): ?>
              <button class="btn-redeem" disabled>Out of Stock</button>
            <?php elseif (!$can_afford): ?>
              <button class="btn-redeem" disabled>Need <?= number_format($shortfall) ?> more pts</button>
            <?php else: ?>
              <button class="btn-redeem btn-redeem-action" data-id="<?= $item['id'] ?>"
                      data-name="<?= htmlspecialchars($item['name']) ?>"
                      data-points="<?= $item['points_required'] ?>">Redeem Reward</button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>

    <!-- ======================================================
         REDEMPTION HISTORY
         ====================================================== -->
    <section class="card" id="history-card">
      <div class="card-header">
        <span class="card-title">📜 My Redemptions</span>
        <span class="card-subtitle"><?= count($orders) ?> item<?= count($orders) !== 1 ? 's' : '' ?></span>
      </div>
      <?php if (count($orders) > 0): ?>
      <div class="table-wrap">
        <table class="history-table" id="history-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Product</th>
              <th>Points</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="history-body">
            <?php foreach ($orders as $order): ?>
            <tr>
              <td><?= date('M d, Y', strtotime($order['created_at'])) ?></td>
              <td><?= htmlspecialchars($order['item_name']) ?></td>
              <td><?= number_format($order['points_spent']) ?> pts</td>
              <td>
                <?php $status = $order['status']; ?>
                <span class="status-badge <?= $status ?>"><span class="status-dot <?= $status ?>"></span> <?= ucfirst($status) ?></span>
              </td>
              <td>
                <?php if ($order['status'] === 'pending'): ?>
                  <form method="POST" style="display:inline;" onsubmit="return confirmCancel(this);">
                    <input type="hidden" name="redemption_id" value="<?= $order['id'] ?>">
                    <button type="submit" name="cancel_order" style="background:var(--red-status);color:#fff;border:none;padding:5px 14px;border-radius:999px;font-size:11px;font-weight:600;cursor:pointer;">Cancel</button>
                  </form>
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
        <div class="empty-icon">🎁</div>
        <div class="empty-text">No redemptions yet</div>
        <div class="empty-sub">Redeem your first reward from the store</div>
      </div>
      <?php endif; ?>
    </section>

  </div>

  <!-- Hidden form for modal redemption -->
  <form method="POST" id="redeem-form" style="display:none;">
    <input type="hidden" name="item_id" id="redeem-item-id">
    <input type="hidden" name="redeem_item" value="1">
  </form>

  <!-- ==========================================================
       SCRIPTS
       ========================================================== -->
  <script>
    /* ---- DOM refs ---- */
    const $ = (s) => document.querySelector(s);
    const $$ = (s) => document.querySelectorAll(s);

    const modalOverlay = $('#modal-overlay');
    const btnCancel = $('#btn-cancel');
    const btnConfirm = $('#btn-confirm');
    const toast = $('#toast');
    const toastIcon = $('#toast-icon');
    const toastMsg = $('#toast-msg');
    const redeemForm = $('#redeem-form');
    const redeemItemId = $('#redeem-item-id');

    let userPoints = <?= $user['total_points'] ?>;

    /* ---- Show PHP message as toast on page load ---- */
    <?php if ($message): ?>
    showToast('<?= $message_type === 'success' ? '✅' : '❌' ?>', <?= json_encode($message) ?>, '<?= $message_type ?>');
    <?php endif; ?>

    /* ---- Modal ---- */
    function openModal(itemId, itemName, itemPoints) {
      $('#modal-icon').textContent = '🎁';
      $('#modal-title').textContent = 'Confirm Redemption';
      $('#modal-desc').textContent = 'You are about to redeem this reward using your points.';
      $('#md-item').textContent = itemName;
      $('#md-points').textContent = '⭐ ' + itemPoints.toLocaleString() + ' pts';
      $('#md-balance').textContent = '⭐ ' + userPoints.toLocaleString() + ' pts';
      $('#md-remaining').textContent = '⭐ ' + (userPoints - itemPoints).toLocaleString() + ' pts';
      $('#btn-confirm').textContent = 'Confirm Redemption';
      redeemItemId.value = itemId;
      modalOverlay.classList.add('open');
    }

    function closeModal() {
      modalOverlay.classList.remove('open');
    }

    btnCancel.addEventListener('click', closeModal);
    modalOverlay.addEventListener('click', function(e) {
      if (e.target === modalOverlay) closeModal();
    });

    btnConfirm.addEventListener('click', function() {
      redeemForm.submit();
    });

    /* ---- Attach redeem buttons ---- */
    $$('.btn-redeem-action').forEach(btn => {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        const id = this.dataset.id;
        const name = this.dataset.name;
        const points = parseInt(this.dataset.points);
        openModal(id, name, points);
      });
    });

    /* ---- Confirm cancel ---- */
    function confirmCancel(form) {
      return confirm('Cancel this order? Your points will be fully refunded.');
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

    /* ---- Keyboard: Escape to close modal ---- */
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && modalOverlay.classList.contains('open')) {
        closeModal();
      }
    });
  </script>

</body>
</html>