<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = '';

// Handle Adding New Item
if (isset($_POST['add_item'])) {
    $name = $_POST['name'];
    $image_url = $_POST['image_url'];
    $points = $_POST['points_required'];
    $stock = $_POST['stock_quantity'];

    $stmt = $pdo->prepare("INSERT INTO reward_items (name, image_url, points_required, stock_quantity) VALUES (?, ?, ?, ?)");
    $stmt->execute([$name, $image_url, $points, $stock]);
    $message = "<div class='alert success'>Item added to the store!</div>";
}

// Handle Processing Orders
if (isset($_POST['process_order'])) {
    $redemption_id = $_POST['redemption_id'];
    $action = $_POST['action'];

    // Fetch the redemption details
    $stmt = $pdo->prepare("SELECT * FROM reward_redemptions WHERE id = ? AND status = 'pending'");
    $stmt->execute([$redemption_id]);
    $order = $stmt->fetch();

    if ($order) {
        if ($action === 'complete') {
            $pdo->prepare("UPDATE reward_redemptions SET status = 'completed' WHERE id = ?")->execute([$redemption_id]);
            $message = "<div class='alert success'>Order Completed! (Handed over to employee).</div>";
        } elseif ($action === 'reject') {
            try {
                $pdo->beginTransaction();
                // 1. Mark as rejected
                $pdo->prepare("UPDATE reward_redemptions SET status = 'rejected' WHERE id = ?")->execute([$redemption_id]);
                // 2. Refund Points to user
                $pdo->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?")->execute([$order['points_spent'], $order['user_id']]);
                // 3. Add to Point Transactions
                $pdo->prepare("INSERT INTO point_transactions (user_id, amount, description) VALUES (?, ?, ?)")
                    ->execute([$order['user_id'], $order['points_spent'], "Refund: Boss rejected store order"]);
                // 4. Return Stock to item
                $pdo->prepare("UPDATE reward_items SET stock_quantity = stock_quantity + 1 WHERE id = ?")->execute([$order['item_id']]);
                $pdo->commit();
                $message = "<div class='alert error'>Order Rejected. Points and Stock have been refunded.</div>";
            } catch (Exception $e) {
                $pdo->rollBack();
            }
        }
    }
}

$items = $pdo->query("SELECT * FROM reward_items ORDER BY created_at DESC")->fetchAll();
$pending_orders = $pdo->query("SELECT rr.*, u.name as user_name, ri.name as item_name FROM reward_redemptions rr JOIN users u ON rr.user_id = u.id JOIN reward_items ri ON rr.item_id = ri.id WHERE rr.status = 'pending'")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Store Manager</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1, h2 { color: #2c3e50; }
        .flex-row { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 30px; }
        .box { flex: 1; padding: 20px; border-radius: 8px; background: #ecf0f1; min-width: 300px; }
        input, button { width: 100%; padding: 10px; margin-top: 5px; margin-bottom: 15px; border-radius: 5px; border: 1px solid #ccc; box-sizing: border-box; }
        button { background: #3498db; color: white; border: none; font-weight: bold; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; background: white; border-radius: 5px; overflow: hidden; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #34495e; color: white; }
        .alert { padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; font-weight: bold; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>

<div class="container">
    <a href="admin_dashboard.php" style="text-decoration:none; color:#f39c12; font-weight:bold;">&larr; Back to Dashboard</a>
    <h1>Manage Rewards Store</h1>
    <?= $message; ?>

    <div class="flex-row">
        <!-- Add Item Form -->
        <div class="box">
            <h2>Add New Item</h2>
            <form method="POST">
                <label>Item Name</label><input type="text" name="name" required placeholder="e.g. iPhone 15 Pro">
                <label>Image URL (Optional)</label><input type="text" name="image_url" placeholder="https://link-to-image.jpg">
                <div style="display:flex; gap:10px;">
                    <div style="flex:1;"><label>Points Price</label><input type="number" name="points_required" required></div>
                    <div style="flex:1;"><label>Stock Qty</label><input type="number" name="stock_quantity" required></div>
                </div>
                <button type="submit" name="add_item" style="background:#27ae60;">+ Add to Store</button>
            </form>
        </div>

        <!-- Pending Orders -->
        <div class="box" style="flex:2;">
            <h2>Pending Redemptions</h2>
            <?php if (count($pending_orders) > 0): ?>
                <table>
                    <tr><th>Employee</th><th>Item</th><th>Points Spent</th><th>Actions</th></tr>
                    <?php foreach ($pending_orders as $order): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($order['user_name']); ?></strong></td>
                        <td><?= htmlspecialchars($order['item_name']); ?></td>
                        <td><?= $order['points_spent']; ?> Pts</td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="redemption_id" value="<?= $order['id']; ?>">
                                <button type="submit" name="action" value="complete" style="background:#27ae60; width:auto; padding:5px 10px; margin:0;">Complete</button>
                                <button type="submit" name="action" value="reject" style="background:#c0392b; width:auto; padding:5px 10px; margin:0;" onclick="return confirm('Reject and refund points?');">Reject</button>
                                <input type="hidden" name="process_order" value="1">
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            <?php else: ?>
                <p>No pending redemptions.</p>
            <?php endif; ?>
        </div>
    </div>

    <h2>Current Inventory</h2>
    <table>
        <tr><th>Item</th><th>Price</th><th>Stock</th></tr>
        <?php foreach ($items as $item): ?>
        <tr>
            <td><?= htmlspecialchars($item['name']); ?></td>
            <td><?= $item['points_required']; ?> Pts</td>
            <td><?= $item['stock_quantity']; ?> left</td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

</body>
</html>