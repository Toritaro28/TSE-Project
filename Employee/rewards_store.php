<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';

// Handle Redemption
if (isset($_POST['redeem_item'])) {
    $item_id = $_POST['item_id'];
    
    // Fetch user points and item details
    $stmt = $pdo->prepare("SELECT total_points FROM users WHERE id = ?"); $stmt->execute([$user_id]); $user = $stmt->fetch();
    $stmt = $pdo->prepare("SELECT * FROM reward_items WHERE id = ?"); $stmt->execute([$item_id]); $item = $stmt->fetch();

    if ($item['stock_quantity'] <= 0) {
        $message = "<div class='alert error'>Sorry, this item is out of stock!</div>";
    } elseif ($user['total_points'] < $item['points_required']) {
        $message = "<div class='alert error'>Not enough points!</div>";
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
            $message = "<div class='alert success'>Item Redeemed! Status is Pending for Annual Dinner.</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='alert error'>Transaction failed.</div>";
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
            $message = "<div class='alert success'>Order cancelled. Points have been fully refunded!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
        }
    }
}

// Fetch Data
$stmt = $pdo->prepare("SELECT total_points FROM users WHERE id = ?"); $stmt->execute([$user_id]); $user = $stmt->fetch();
$items = $pdo->query("SELECT * FROM reward_items WHERE is_active = 1")->fetchAll();
$my_orders = $pdo->prepare("SELECT rr.*, ri.name as item_name FROM reward_redemptions rr JOIN reward_items ri ON rr.item_id = ri.id WHERE rr.user_id = ? ORDER BY rr.created_at DESC");
$my_orders->execute([$user_id]);
$orders = $my_orders->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rewards Store</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; }
        .points-badge { background: #f39c12; color: white; padding: 10px 20px; border-radius: 20px; font-size: 20px; font-weight: bold; }
        
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; }
        .item-card { border: 1px solid #ddd; padding: 15px; border-radius: 8px; text-align: center; background: #fafafa; }
        .item-img { width: 100%; height: 150px; object-fit: cover; border-radius: 5px; background: #ecf0f1; margin-bottom: 10px; font-size: 50px; display:flex; align-items:center; justify-content:center; }
        .price { font-size: 18px; font-weight: bold; color: #e67e22; margin: 10px 0; }
        
        button { width: 100%; padding: 10px; border-radius: 5px; border: none; font-weight: bold; cursor: pointer; color: white; }
        .btn-buy { background: #27ae60; }
        .btn-buy:disabled { background: #bdc3c7; cursor: not-allowed; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #34495e; color: white; }
        .alert { padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; font-weight: bold; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <a href="employee_dashboard.php" style="text-decoration:none; color:#f39c12; font-weight:bold;">&larr; Back</a>
        <h1>Rewards Store</h1>
        <div class="points-badge">⭐ <?= $user['total_points']; ?> Pts</div>
    </div>
    <?= $message; ?>

    <h2>Available Items</h2>
    <div class="grid">
        <?php foreach ($items as $item): ?>
            <div class="item-card">
                <?php if($item['image_url']): ?>
                    <img src="<?= htmlspecialchars($item['image_url']) ?>" class="item-img">
                <?php else: ?>
                    <div class="item-img">🎁</div>
                <?php endif; ?>
                <h3><?= htmlspecialchars($item['name']); ?></h3>
                <div class="price"><?= $item['points_required']; ?> Points</div>
                <p style="color:gray; font-size:14px;">Stock: <?= $item['stock_quantity']; ?></p>
                
                <form method="POST">
                    <input type="hidden" name="item_id" value="<?= $item['id']; ?>">
                    <?php if ($item['stock_quantity'] <= 0): ?>
                        <button type="button" class="btn-buy" disabled>Out of Stock</button>
                    <?php elseif ($user['total_points'] < $item['points_required']): ?>
                        <button type="button" class="btn-buy" disabled>Not Enough Points</button>
                    <?php else: ?>
                        <button type="submit" name="redeem_item" class="btn-buy" onclick="return confirm('Redeem this item? Points will be deducted.');">Redeem Now</button>
                    <?php endif; ?>
                </form>
            </div>
        <?php endforeach; ?>
    </div>

    <h2 style="margin-top: 40px;">My Redemptions</h2>
    <table>
        <tr><th>Item</th><th>Points</th><th>Status</th><th>Action</th></tr>
        <?php foreach ($orders as $order): ?>
        <tr>
            <td><?= htmlspecialchars($order['item_name']); ?></td>
            <td><?= $order['points_spent']; ?> Pts</td>
            <td><strong><?= ucfirst($order['status']); ?></strong></td>
            <td>
                <?php if ($order['status'] === 'pending'): ?>
                    <form method="POST">
                        <input type="hidden" name="redemption_id" value="<?= $order['id']; ?>">
                        <button type="submit" name="cancel_order" style="background:#c0392b; width:auto; padding:5px 15px;" onclick="return confirm('Cancel order and refund points?');">Cancel</button>
                    </form>
                <?php else: ?>
                    -
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

</body>
</html>