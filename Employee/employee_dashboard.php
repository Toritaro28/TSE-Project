<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$date_today = date('Y-m-d');

// Fetch User Gamification Stats
$stmt = $pdo->prepare("SELECT total_points, current_streak, plant_current_stage, plant_status FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Map stages to emojis and names
$tree_stages = [
    1 => ['name' => 'Sprout', 'emoji' => '🌱'],
    2 => ['name' => 'Small Tree', 'emoji' => '🌿'],
    3 => ['name' => 'Big Tree', 'emoji' => '🌲'],
    4 => ['name' => 'Blooming Tree', 'emoji' => '🌸'],
    5 => ['name' => 'Bigger Tree', 'emoji' => '🌳'],
    6 => ['name' => 'Blooming Bigger', 'emoji' => '🌺'],
    7 => ['name' => 'The World Tree', 'emoji' => '🌍✨']
];

$current_tree = $tree_stages[$user['plant_current_stage']];

// Check attendance for today
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ?");
$stmt->execute([$user_id, $date_today]);
$attendance = $stmt->fetch();

$has_checked_in = $attendance ? true : false;
$has_checked_out = ($attendance && $attendance['check_out_time']) ? true : false;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; text-align: center; }
        .dashboard-card { background: white; max-width: 500px; margin: 0 auto; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; }
        
        /* Gamification UI */
        .plant-container { margin: 20px auto; padding: 20px; background: #eafaf1; border-radius: 50%; width: 150px; height: 150px; display: flex; align-items: center; justify-content: center; font-size: 80px; box-shadow: inset 0 0 15px rgba(39, 174, 96, 0.2); }
        .plant-name { font-weight: bold; color: #27ae60; font-size: 18px; margin-bottom: 5px; }
        .plant-status { font-size: 14px; color: #7f8c8d; margin-bottom: 20px; }
        
        /* Withered CSS Filter */
        .withered-plant { filter: grayscale(80%) sepia(100%) hue-rotate(10deg) saturate(300%) brightness(0.8); background: #fdf2e9; box-shadow: inset 0 0 15px rgba(230, 126, 34, 0.2); }
        .withered-text { color: #e67e22 !important; }

        .stats { display: flex; justify-content: space-around; margin: 20px 0; background: #ecf0f1; padding: 15px; border-radius: 8px; }
        .stat-box { font-size: 18px; font-weight: bold; color: #2c3e50; }
        .bonus-text { color: #f39c12; font-size: 14px; }

        .btn { padding: 15px 30px; font-size: 18px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; width: 100%; margin-top: 10px; color: white; transition: 0.3s; }
        .btn-checkin { background-color: #27ae60; }
        .btn-checkin:hover { background-color: #219150; }
        .btn-checkout { background-color: #e74c3c; }
        .btn-checkout:hover { background-color: #c0392b; }
        .btn-logout { background-color: #95a5a6; margin-top: 30px; padding: 10px 20px; width: auto; }
    </style>
</head>
<body>

<div class="dashboard-card">
    <h1>Welcome, <?= htmlspecialchars($_SESSION['name']); ?></h1>
    
    <!-- THE WORLD TREE -->
    <div class="plant-name <?= $user['plant_status'] === 'Withered' ? 'withered-text' : '' ?>">
        Stage <?= $user['plant_current_stage']; ?>: <?= $current_tree['name']; ?>
    </div>
    
    <div class="plant-container <?= $user['plant_status'] === 'Withered' ? 'withered-plant' : '' ?>">
        <?= $current_tree['emoji']; ?>
    </div>
    
    <div class="plant-status">
        Status: <b class="<?= $user['plant_status'] === 'Withered' ? 'withered-text' : '' ?>"><?= $user['plant_status']; ?></b>
    </div>

    <!-- STATS -->
    <div class="stats">
        <div class="stat-box">
            ⭐ <?= $user['total_points']; ?> Pts
            <?php if ($has_checked_in && $attendance['status'] == 'on_time' && $user['current_streak'] >= 5): ?>
                <br><span class="bonus-text">(10 Base + 5 Bonus)</span>
            <?php endif; ?>
        </div>
        <div class="stat-box">🔥 <?= $user['current_streak']; ?> Day Streak</div>
    </div>

    <!-- ATTENDANCE BUTTONS -->
    <?php if (!$has_checked_in): ?>
        <button class="btn btn-checkin" onclick="processCheckIn()">📍 CHECK IN</button>
    <?php elseif ($has_checked_in && !$has_checked_out): ?>
        <button class="btn btn-checkout" onclick="processCheckOut()">🏃 CHECK OUT</button>
    <?php else: ?>
        <p style="color: gray; font-weight: bold;">Shift Completed Today.</p>
    <?php endif; ?>

    <br>
    <br>
    <a href="leave.php"><button class="btn" style="background-color: #3498db; width: auto; padding: 10px 20px;">📅 Leave & Calendar</button></a>
    <a href="rewards_store.php"><button class="btn" style="background-color: #f39c12; width: auto; padding: 10px 20px;">🎁 Rewards Store</button></a>
    <a href="../logout.php"><button class="btn btn-logout">Logout</button></a>
</div>

<script>
    // Note: I removed the GPS requirement here temporarily so you can easily test the gamification!
    function processCheckIn() {
        fetch('process_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'check_in' })
        })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                if(data.badge) { alert("🏆 INCREDIBLE! 30-Day Streak! You unlocked the Monthly Iron Man badge and got +100 Points!"); }
                else if (data.bonus_points > 0) { alert(`Checked in! You earned ${data.base_points} Base + ${data.bonus_points} Streak Bonus Points!`); }
                else { alert(`Checked in! Points earned: ${data.base_points}`); }
                location.reload();
            } else { alert(data.message); }
        });
    }

    function processCheckOut() {
        fetch('process_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'check_out' })
        }).then(() => location.reload());
    }
</script>

</body>
</html>