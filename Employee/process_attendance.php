<?php
session_start();
require_once '../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$date_today = date('Y-m-d');
$time_now = date('H:i:s');

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

if ($action === 'check_in') {
    // 1. Check if already checked in today
    $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
    $stmt->execute([$user_id, $date_today]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Already checked in!']);
        exit();
    }

    // 1b. Check if employee is on approved leave today
    $stmt = $pdo->prepare("SELECT id, leave_type FROM leave_requests WHERE user_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ? LIMIT 1");
    $stmt->execute([$user_id, $date_today, $date_today]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'You are currently on approved leave. Check-in is not allowed.']);
        exit();
    }

    // 1c. Check if today is a weekend or public holiday (streak pause)
    $day_of_week = (int)date('N', strtotime($date_today)); // 1=Mon...6=Sat,7=Sun
    $is_weekend = ($day_of_week >= 6);

    $stmt = $pdo->prepare("SELECT id FROM public_holidays WHERE holiday_date = ? LIMIT 1");
    $stmt->execute([$date_today]);
    $is_holiday = (bool)$stmt->fetch();

    if ($is_weekend || $is_holiday) {
        $reason = $is_holiday ? 'public holiday' : 'weekend';
        echo json_encode(['success' => false, 'message' => "Check-in is not available — today is a $reason. Your streak is preserved."]);
        exit();
    }

    // 2. Determine Time & Base Status
    $status = 'absent';
    $base_points = -2;
    $is_on_time = false;

    if ($time_now <= '09:00:59') {
        $status = 'on_time';
        $base_points = 10;
        $is_on_time = true;
    } elseif ($time_now <= '10:00:59') {
        $status = 'grace_period';
        $base_points = 7;
    } elseif ($time_now <= '11:49:59') {
        $status = 'late';
        $base_points = 5;
    }

    // 3. GAMIFICATION ENGINE (Fetch current stats)
    $stmt = $pdo->prepare("SELECT current_streak, plant_current_stage, plant_highest_stage, plant_status FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    $current_streak = $user['current_streak'];
    $current_stage = $user['plant_current_stage'];
    $highest_stage = $user['plant_highest_stage'];
    $plant_status = $user['plant_status'];
    
    $bonus_points = 0;
    $badge_unlocked = false;

    if ($is_on_time) {
        $current_streak += 1;
        
        // Streak Bonuses
        if ($current_streak >= 5) {
            $bonus_points += 5; // +5 Extra points every day after 5 days
        }
        if ($current_streak == 30) {
            $bonus_points += 100; // Iron Man Bonus
            $badge_unlocked = true;
        }

        // Tree Evolution & Recovery Logic
        if ($current_streak >= 5 && $current_stage < $highest_stage) {
            // RECOVERY: They rebuilt their streak, restore the tree!
            $current_stage = $highest_stage;
        } else {
            // EVOLUTION: 20 work days = ~1 month
            if ($current_streak >= 120) $current_stage = 7; // World Tree
            elseif ($current_streak >= 100) $current_stage = 6;
            elseif ($current_streak >= 80) $current_stage = 5;
            elseif ($current_streak >= 60) $current_stage = 4;
            elseif ($current_streak >= 40) $current_stage = 3;
            elseif ($current_streak >= 20) $current_stage = 2; // Small Tree
        }

        $plant_status = 'Healthy';
        if ($current_stage > $highest_stage) {
            $highest_stage = $current_stage;
        }

    } else {
        // LATE OR ABSENT: PUNISHMENT ENGINE
        $current_streak = 0; // Streak broken!

        if ($plant_status === 'Healthy') {
            $plant_status = 'Withered'; // 1st Offense: Turns Yellow
        } else {
            // 2nd Consecutive Offense: Downgrade Stage!
            $current_stage = max(1, $current_stage - 1); 
        }
    }

    $total_points = $base_points + $bonus_points;
    $desc = "Check-in ($status)";
    if ($bonus_points > 0) $desc .= " + Streak Bonus";

    // 4. Save Everything to Database
    try {
        $pdo->beginTransaction();

        // Save Attendance
        $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, check_in_time, status, points_earned) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $date_today, $time_now, $status, $total_points]);

        // Save Ledger
        $stmt = $pdo->prepare("INSERT INTO point_transactions (user_id, amount, description) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $total_points, $desc]);

        // Update User Gamification Profile
        $stmt = $pdo->prepare("UPDATE users SET total_points = total_points + ?, current_streak = ?, plant_current_stage = ?, plant_highest_stage = ?, plant_status = ? WHERE id = ?");
        $stmt->execute([$total_points, $current_streak, $current_stage, $highest_stage, $plant_status, $user_id]);

        $pdo->commit();

        echo json_encode([
            'success' => true, 
            'base_points' => $base_points,
            'bonus_points' => $bonus_points,
            'badge' => $badge_unlocked,
            'message' => "Checked in successfully!"
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
    exit();
}

if ($action === 'check_out') {
    $stmt = $pdo->prepare("UPDATE attendance SET check_out_time = ? WHERE user_id = ? AND date = ?");
    $stmt->execute([$time_now, $user_id, $date_today]);
    echo json_encode(['success' => true, 'message' => 'Checked out successfully!']);
    exit();
}
?>