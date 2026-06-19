<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$active_page = 'store';
$message = '';
$message_type = '';

// Edit target
$edit_id = $_GET['edit'] ?? null;
$edit_item = null;
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM reward_items WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_item = $stmt->fetch();
}

// Handle Add / Edit Item
if (isset($_POST['save_item'])) {
    $name = trim($_POST['name']);
    $image_url = $_POST['image_url'];
    $points = (int)$_POST['points_required'];
    $stock = (int)$_POST['stock_quantity'];
    $item_id = $_POST['item_id'] ?? null;

    if (!$name || $points <= 0) {
        $message = "Item name and valid points are required.";
        $message_type = 'error';
    } elseif ($item_id) {
        // Edit existing
        $stmt = $pdo->prepare("UPDATE reward_items SET name = ?, image_url = ?, points_required = ?, stock_quantity = ? WHERE id = ?");
        $stmt->execute([$name, $image_url, $points, $stock, $item_id]);
        $message = "Item updated successfully.";
        $message_type = 'success';
    } else {
        // Add new
        $stmt = $pdo->prepare("INSERT INTO reward_items (name, image_url, points_required, stock_quantity) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $image_url, $points, $stock]);
        $message = "Item added to the store!";
        $message_type = 'success';
    }
}

// Handle Toggle Active / Inactive
if (isset($_POST['toggle_item'])) {
    $item_id = $_POST['item_id'];
    $stmt = $pdo->prepare("UPDATE reward_items SET is_active = NOT is_active WHERE id = ?");
    $stmt->execute([$item_id]);
    $stmt = $pdo->prepare("SELECT name, is_active FROM reward_items WHERE id = ?");
    $stmt->execute([$item_id]);
    $toggled = $stmt->fetch();
    $message = '"' . $toggled['name'] . '" is now ' . ($toggled['is_active'] ? 'Active' : 'Hidden') . '.';
    $message_type = 'success';
}

// Handle Delete
if (isset($_POST['delete_item'])) {
    $item_id = $_POST['item_id'];

    // Check for existing redemptions
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reward_redemptions WHERE item_id = ?");
    $stmt->execute([$item_id]);
    $ref_count = $stmt->fetchColumn();

    if ($ref_count > 0) {
        $message = "Cannot delete: this item has $ref_count redemption record(s). Deactivate it instead.";
        $message_type = 'error';
    } else {
        $stmt = $pdo->prepare("DELETE FROM reward_items WHERE id = ?");
        $stmt->execute([$item_id]);
        $message = "Item permanently deleted.";
        $message_type = 'success';
    }
}

// Handle Stock Adjustment
if (isset($_POST['adjust_stock'])) {
    $item_id = $_POST['item_id'];
    $delta = (int)$_POST['stock_delta'];

    if ($delta != 0) {
        $stmt = $pdo->prepare("UPDATE reward_items SET stock_quantity = GREATEST(0, stock_quantity + ?) WHERE id = ?");
        $stmt->execute([$delta, $item_id]);
        $message = "Stock adjusted by " . ($delta > 0 ? '+' : '') . $delta . ".";
        $message_type = 'success';
    }
}

// Handle Processing Orders
if (isset($_POST['process_order'])) {
    $redemption_id = $_POST['redemption_id'];
    $action = $_POST['action'];

    $stmt = $pdo->prepare("SELECT * FROM reward_redemptions WHERE id = ? AND status = 'pending'");
    $stmt->execute([$redemption_id]);
    $order = $stmt->fetch();

    if ($order) {
        if ($action === 'complete') {
            $pdo->prepare("UPDATE reward_redemptions SET status = 'completed' WHERE id = ?")->execute([$redemption_id]);
            $message = "Order Completed! Item handed over to employee.";
            $message_type = 'success';
        } elseif ($action === 'reject') {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE reward_redemptions SET status = 'rejected' WHERE id = ?")->execute([$redemption_id]);
                $pdo->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?")->execute([$order['points_spent'], $order['user_id']]);
                $pdo->prepare("INSERT INTO point_transactions (user_id, amount, description) VALUES (?, ?, ?)")
                    ->execute([$order['user_id'], $order['points_spent'], "Refund: Boss rejected store order"]);
                $pdo->prepare("UPDATE reward_items SET stock_quantity = stock_quantity + 1 WHERE id = ?")->execute([$order['item_id']]);
                $pdo->commit();
                $message = "Order Rejected. Points and Stock refunded.";
                $message_type = 'success';
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Transaction failed. Please try again.";
                $message_type = 'error';
            }
        }
    }
}

// Refresh edit item after actions
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM reward_items WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_item = $stmt->fetch();
}

$items = $pdo->query("SELECT * FROM reward_items ORDER BY created_at DESC")->fetchAll();
$pending_orders = $pdo->query("SELECT rr.*, u.name as user_name, ri.name as item_name FROM reward_redemptions rr JOIN users u ON rr.user_id = u.id JOIN reward_items ri ON rr.item_id = ri.id WHERE rr.status = 'pending'")->fetchAll();
$pending_count = count($pending_orders);

// Count redemptions per item (for delete protection display)
$ref_counts = [];
$stmt = $pdo->query("SELECT item_id, COUNT(*) as cnt FROM reward_redemptions GROUP BY item_id");
while ($row = $stmt->fetch()) {
    $ref_counts[$row['item_id']] = $row['cnt'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>Store Manager — LeafPoint</title>
  <style>
    :root {
      --bg: oklch(96.5% 0.006 245);
      --surface-glass: rgba(255, 255, 255, 0.52);
      --surface-glass-hover: rgba(255, 255, 255, 0.72);
      --surface-solid: #ffffff;
      --fg: oklch(16% 0.018 252); --fg-secondary: oklch(36% 0.022 250); --muted: oklch(53% 0.016 250);
      --border-glass: rgba(255, 255, 255, 0.38); --border-subtle: rgba(0, 0, 0, 0.055);
      --accent: oklch(56% 0.19 148); --accent-soft: oklch(74% 0.14 148); --accent-dark: oklch(48% 0.16 148);
      --accent-glow: oklch(62% 0.21 148 / 0.3); --gold: oklch(70% 0.19 82);
      --green-status: oklch(58% 0.17 142); --red-status: oklch(53% 0.22 22); --yellow-status: oklch(68% 0.15 85);
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

    .main { flex: 1; overflow-y: auto; overflow-x: hidden; background: radial-gradient(ellipse at 70% 0%, oklch(90% 0.04 170 / 0.18), oklch(97% 0.004 245) 55%); display: flex; flex-direction: column; }
    .main-inner { padding: 24px 30px 36px; display: flex; flex-direction: column; gap: 20px; max-width: 1200px; width: 100%; }

    .topbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .topbar .topbar-left { display: flex; align-items: center; gap: 10px; }
    .topbar .title { font-family: var(--font-display); font-size: 18px; font-weight: 700; letter-spacing: -0.02em; white-space: nowrap; }
    .topbar .admin-badge { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: 0.02em; background: oklch(92% 0.04 310); color: oklch(38% 0.1 310); white-space: nowrap; }

    .card { background: var(--surface-glass); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border: 1px solid var(--border-glass); border-radius: var(--radius-lg); box-shadow: var(--shadow-card); padding: 22px 24px; display: flex; flex-direction: column; gap: 14px; transition: box-shadow 0.2s, background 0.2s; }
    .card:hover { background: var(--surface-glass-hover); box-shadow: var(--shadow-card-hover); }
    .card-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .card-title { font-family: var(--font-display); font-size: 15px; font-weight: 700; letter-spacing: -0.01em; }
    .card-subtitle { font-size: 11px; color: var(--muted); }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .form-group { display: flex; flex-direction: column; gap: 5px; }
    .form-label { font-size: 12px; font-weight: 700; color: var(--fg-secondary); letter-spacing: 0.01em; text-transform: uppercase; }
    .form-input { width: 100%; padding: 10px 14px; font-family: var(--font-body); font-size: 14px; color: var(--fg); background: var(--surface-solid); border: 1.5px solid var(--border-subtle); border-radius: var(--radius-sm); transition: all 0.2s; outline: none; }
    .form-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px oklch(56% 0.19 148 / 0.1); }
    .btn { padding: 10px 18px; border: none; border-radius: var(--radius-sm); font-family: var(--font-body); font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.2s; letter-spacing: -0.01em; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
    .btn-primary { background: var(--accent); color: #fff; box-shadow: 0 4px 16px var(--accent-glow); }
    .btn-primary:hover { background: var(--accent-dark); box-shadow: 0 6px 22px oklch(56% 0.19 148 / 0.4); transform: translateY(-1px); }
    .btn-success { background: var(--green-status); color: #fff; }
    .btn-success:hover { background: oklch(50% 0.15 142); }
    .btn-danger { background: var(--red-status); color: #fff; }
    .btn-danger:hover { background: oklch(46% 0.2 22); }
    .btn-ghost { background: transparent; color: var(--fg-secondary); border: 1.5px solid var(--border-subtle); }
    .btn-ghost:hover { border-color: var(--accent); color: var(--accent); }
    .btn-sm { padding: 4px 10px; font-size: 11px; }
    .btn-warn { background: var(--yellow-status); color: #fff; }
    .btn-warn:hover { background: oklch(58% 0.12 82); }

    .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    table thead th { text-align: left; padding: 10px 12px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); font-weight: 700; border-bottom: 1px solid var(--border-subtle); white-space: nowrap; background: oklch(98% 0.002 250 / 0.4); }
    table tbody td { padding: 10px 12px; border-bottom: 1px solid oklch(94% 0.003 250); white-space: nowrap; }
    table tbody tr { transition: background 0.15s; }
    table tbody tr:hover { background: rgba(0,0,0,0.018); }
    table tbody tr:last-child td { border-bottom: none; }

    .status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; }
    .status-badge.active { background: oklch(92% 0.06 148); color: oklch(36% 0.1 148); }
    .status-badge.inactive { background: oklch(92% 0.003 250); color: oklch(48% 0.005 250); }

    .empty-state { text-align: center; padding: 32px 16px; color: var(--muted); }
    .empty-state .empty-icon { font-size: 36px; margin-bottom: 6px; }
    .empty-state .empty-text { font-size: 13px; font-weight: 600; }
    .infobox { padding: 10px 14px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 600; }
    .infobox.warn { background: oklch(94% 0.05 82 / 0.5); color: oklch(42% 0.12 80); }

    .toast { position: fixed; top: 20px; left: 50%; transform: translateX(-50%) translateY(-120px); background: var(--surface-solid); border: 1px solid var(--green-status); border-radius: var(--radius-md); padding: 14px 20px; font-weight: 600; font-size: 14px; color: var(--fg); box-shadow: 0 8px 30px rgba(0,0,0,0.12); z-index: 100; display: flex; align-items: center; gap: 8px; transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
    .toast.show { transform: translateX(-50%) translateY(0); }
    .toast.error { border-color: var(--red-status); }

    .bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 30; height: 64px; background: oklch(98% 0.003 250 / 0.92); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border-top: 1px solid rgba(0,0,0,0.06); align-items: center; justify-content: space-around; padding: 0 8px; padding-bottom: env(safe-area-inset-bottom, 0px); }
    .bottom-nav a { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 6px 10px; border: none; background: none; font: 10px/1 var(--font-body); color: var(--muted); cursor: pointer; transition: color 0.15s; font-weight: 500; text-decoration: none; }
    .bottom-nav a .nav-icon { font-size: 20px; line-height: 1; }
    .bottom-nav a.active { color: var(--accent); font-weight: 700; }

    @media (min-width: 640px) { .topbar .title { font-size: 20px; } .card { padding: 24px 28px; } }
    @media (min-width: 1024px) { .card-title { font-size: 17px; } table { font-size: 14px; } }
    @media (max-width: 800px) { .sidebar { width: 210px; min-width: 210px; } .main-inner { padding: 16px 12px 80px; } }
    @media (max-width: 660px) { .sidebar { display: none; } .bottom-nav { display: flex; } .main-inner { padding: 14px 10px 80px; } .form-row { grid-template-columns: 1fr; } }
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
        <header class="topbar">
          <div class="topbar-left">
            <span class="title">🎁 Store Manager</span>
            <span class="admin-badge">👑 Admin</span>
          </div>
          <?php if ($pending_count > 0): ?>
          <span style="font-size:12px;font-weight:700;color:var(--red-status);"><?= $pending_count ?> pending</span>
          <?php endif; ?>
        </header>

        <div class="form-row">
          <!-- Add / Edit Item Form -->
          <section class="card">
            <div class="card-header">
              <span class="card-title"><?= $edit_item ? '✏️ Edit Item' : '➕ Add New Item' ?></span>
              <?php if ($edit_item): ?>
              <a href="store_admin.php" class="btn btn-ghost btn-sm">Cancel Edit</a>
              <?php endif; ?>
            </div>
            <form method="POST">
              <?php if ($edit_item): ?>
              <input type="hidden" name="item_id" value="<?= $edit_item['id'] ?>">
              <?php endif; ?>
              <div class="form-group">
                <label class="form-label">Item Name</label>
                <input type="text" name="name" class="form-input" required
                       value="<?= htmlspecialchars($edit_item['name'] ?? '') ?>"
                       placeholder="e.g. iPhone 15 Pro">
              </div>
              <div class="form-group">
                <label class="form-label">Image URL (Optional)</label>
                <input type="text" name="image_url" class="form-input"
                       value="<?= htmlspecialchars($edit_item['image_url'] ?? '') ?>"
                       placeholder="https://link-to-image.jpg">
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label class="form-label">Points Price</label>
                  <input type="number" name="points_required" class="form-input" required
                         value="<?= htmlspecialchars($edit_item['points_required'] ?? '') ?>"
                         placeholder="500" min="1">
                </div>
                <div class="form-group">
                  <label class="form-label">Stock Qty</label>
                  <input type="number" name="stock_quantity" class="form-input" required
                         value="<?= htmlspecialchars($edit_item['stock_quantity'] ?? '') ?>"
                         placeholder="10" min="0">
                </div>
              </div>
              <button type="submit" name="save_item" class="btn btn-primary" style="width:100%;margin-top:4px;">
                <?= $edit_item ? 'Update Item' : '+ Add to Store' ?>
              </button>
            </form>

            <?php if ($edit_item): ?>
            <!-- Stock quick-adjust -->
            <form method="POST" style="display:flex;gap:8px;align-items:flex-end;">
              <input type="hidden" name="item_id" value="<?= $edit_item['id'] ?>">
              <div class="form-group" style="flex:1;">
                <label class="form-label">Quick Stock Adjust (+/-)</label>
                <input type="number" name="stock_delta" class="form-input" placeholder="e.g. +5 or -2" required style="padding:8px 12px;font-size:13px;">
              </div>
              <button type="submit" name="adjust_stock" class="btn btn-warn btn-sm" style="height:40px;">Adjust Stock</button>
            </form>

            <!-- Toggle + Delete -->
            <div style="display:flex;gap:8px;">
              <form method="POST" style="flex:1;">
                <input type="hidden" name="item_id" value="<?= $edit_item['id'] ?>">
                <button type="submit" name="toggle_item" class="btn <?= $edit_item['is_active'] ? 'btn-ghost' : 'btn-success' ?>" style="width:100%;">
                  <?= $edit_item['is_active'] ? '🔽 Deactivate' : '🔼 Activate' ?>
                </button>
              </form>
              <form method="POST" style="flex:1;" onsubmit="return confirm('Permanently delete this item?')">
                <input type="hidden" name="item_id" value="<?= $edit_item['id'] ?>">
                <button type="submit" name="delete_item" class="btn btn-danger" style="width:100%;"
                  <?= isset($ref_counts[$edit_item['id']]) ? 'disabled title="Cannot delete — has redemptions"' : '' ?>>
                  🗑 Delete
                </button>
              </form>
            </div>
            <?php if (isset($ref_counts[$edit_item['id']])): ?>
            <div class="infobox warn">⚠ Cannot delete: <?= $ref_counts[$edit_item['id']] ?> redemption record(s) reference this item. Deactivate it instead.</div>
            <?php endif; ?>
            <?php endif; ?>
          </section>

          <!-- Pending Orders -->
          <section class="card">
            <div class="card-header">
              <span class="card-title">📦 Pending Redemptions</span>
              <span class="card-subtitle"><?= $pending_count ?> order<?= $pending_count !== 1 ? 's' : '' ?></span>
            </div>
            <?php if ($pending_count > 0): ?>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Employee</th><th>Item</th><th>Points</th><th>Actions</th></tr></thead>
                <tbody>
                  <?php foreach ($pending_orders as $order): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($order['user_name']) ?></strong></td>
                    <td><?= htmlspecialchars($order['item_name']) ?></td>
                    <td><?= number_format($order['points_spent']) ?> pts</td>
                    <td>
                      <form method="POST" style="display:inline-flex;gap:6px;">
                        <input type="hidden" name="redemption_id" value="<?= $order['id'] ?>">
                        <input type="hidden" name="process_order" value="1">
                        <button type="submit" name="action" value="complete" class="btn btn-success btn-sm">Complete</button>
                        <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm" onclick="return confirm('Reject and refund points?')">Reject</button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
            <div class="empty-state"><div class="empty-icon">✅</div><div class="empty-text">No pending redemptions</div></div>
            <?php endif; ?>
          </section>
        </div>

        <!-- Inventory -->
        <section class="card">
          <div class="card-header">
            <span class="card-title">📋 Current Inventory</span>
            <span class="card-subtitle"><?= count($items) ?> item<?= count($items) !== 1 ? 's' : '' ?></span>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Item</th><th>Price</th><th>Stock</th><th>Status</th><th style="width:80px;">Actions</th></tr></thead>
              <tbody>
                <?php foreach ($items as $item):
                  $has_refs = isset($ref_counts[$item['id']]);
                ?>
                <tr style="<?= !$item['is_active'] ? 'opacity:0.5;' : '' ?>">
                  <td><strong><?= htmlspecialchars($item['name']) ?></strong><?= $has_refs ? ' <span style="font-size:10px;color:var(--muted);">(' . $ref_counts[$item['id']] . ' sold)</span>' : '' ?></td>
                  <td>⭐ <?= number_format($item['points_required']) ?> pts</td>
                  <td><?= $item['stock_quantity'] ?> left</td>
                  <td><span class="status-badge <?= $item['is_active'] ? 'active' : 'inactive' ?>"><?= $item['is_active'] ? 'Active' : 'Hidden' ?></span></td>
                  <td>
                    <form method="POST" style="display:inline-flex;gap:4px;">
                      <a href="?edit=<?= $item['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
                      <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                      <button type="submit" name="toggle_item" class="btn <?= $item['is_active'] ? 'btn-ghost' : 'btn-success' ?> btn-sm" title="<?= $item['is_active'] ? 'Deactivate' : 'Activate' ?>"><?= $item['is_active'] ? '🔽' : '🔼' ?></button>
                      <button type="submit" name="delete_item" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')" <?= $has_refs ? 'disabled title="Has redemptions"' : '' ?>>🗑</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($items) === 0): ?>
                <tr><td colspan="5"><div class="empty-state"><div class="empty-text">No items in store yet</div></div></td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </main>
  </div>

  <nav class="bottom-nav">
    <a href="admin_dashboard.php"><span class="nav-icon">📋</span>Approvals</a>
    <a href="master_calendar.php"><span class="nav-icon">📅</span>Calendar</a>
    <a href="store_admin.php" class="active"><span class="nav-icon">🎁</span>Store</a>
    <a href="../logout.php"><span class="nav-icon">🚪</span>Logout</a>
  </nav>

  <script>
    function showToast(icon, msg, type) {
      const t = document.getElementById('toast');
      document.getElementById('toast-icon').textContent = icon;
      document.getElementById('toast-msg').textContent = msg;
      t.className = 'toast ' + (type || 'success') + ' show';
      t.style.display = 'flex';
      clearTimeout(t._timeout);
      t._timeout = setTimeout(() => { t.classList.remove('show'); setTimeout(() => { t.style.display = 'none'; }, 350); }, 3500);
    }
    <?php if ($message): ?>
    showToast('<?= $message_type === 'success' ? '✅' : '❌' ?>', <?= json_encode($message) ?>, '<?= $message_type ?>');
    <?php endif; ?>
  </script>
</body>
</html>