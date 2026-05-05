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

// 1. Fetch all attendance for this month
$attendance_data = [];
$stmt = $pdo->prepare("
    SELECT a.date, a.status, u.name 
    FROM attendance a 
    JOIN users u ON a.user_id = u.id 
    WHERE MONTH(a.date) = ? AND YEAR(a.date) = ?
");
$stmt->execute([$month, $year]);
while ($row = $stmt->fetch()) {
    $attendance_data[$row['date']][] = ['name' => $row['name'], 'status' => $row['status']];
}

// 2. Fetch all APPROVED leaves for this month
$stmt = $pdo->prepare("
    SELECT lr.start_date, lr.end_date, lr.leave_type, u.name 
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
            $attendance_data[$date_str][] = ['name' => $row['name'], 'status' => 'leave_' . $row['leave_type']];
        }
        $current = strtotime('+1 day', $current);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Master Calendar</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1, h2 { color: #2c3e50; text-align: center; }
        
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .calendar-header a { text-decoration: none; padding: 8px 20px; background: #2c3e50; color: white; border-radius: 5px; font-weight: bold; }
        
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
        .cal-head { font-weight: bold; background: #34495e; color: white; padding: 10px 0; text-align: center; }
        .cal-day { border: 1px solid #ddd; background: #fff; border-radius: 5px; min-height: 120px; padding: 5px; display: flex; flex-direction: column; }
        .day-num { font-weight: bold; margin-bottom: 5px; text-align: right; color: #7f8c8d; border-bottom: 1px solid #eee; padding-bottom: 3px; }
        
        /* Employee Badges inside Calendar */
        .badge { font-size: 11px; padding: 3px 5px; border-radius: 3px; margin-bottom: 3px; color: white; display: flex; align-items: center; gap: 5px; font-weight: bold; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .bg-present { background-color: #27ae60; }
        .bg-absent { background-color: #c0392b; }
        .bg-al { background-color: #2980b9; }
        .bg-mc { background-color: #f39c12; }
        
    </style>
</head>
<body>

<div class="container">
    <a href="admin_dashboard.php" style="text-decoration:none; color:#8e44ad; font-weight:bold;">&larr; Back to Dashboard</a>
    <h1>Master Company Calendar</h1>

    <div class="calendar-header">
        <?php 
            $prev_month = $month - 1; $prev_year = $year;
            if($prev_month == 0) { $prev_month = 12; $prev_year--; }
            $next_month = $month + 1; $next_year = $year;
            if($next_month == 13) { $next_month = 1; $next_year++; }
        ?>
        <a href="?m=<?= $prev_month ?>&y=<?= $prev_year ?>">&laquo; Previous Month</a>
        <h2><?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></h2>
        <a href="?m=<?= $next_month ?>&y=<?= $next_year ?>">Next Month &raquo;</a>
    </div>

    <div class="calendar-grid">
        <div class="cal-head">Sun</div><div class="cal-head">Mon</div><div class="cal-head">Tue</div>
        <div class="cal-head">Wed</div><div class="cal-head">Thu</div><div class="cal-head">Fri</div><div class="cal-head">Sat</div>
        
        <?php
        $first_day_of_month = date('w', mktime(0,0,0,$month,1,$year));
        for ($i = 0; $i < $first_day_of_month; $i++) {
            echo "<div class='cal-day' style='background:transparent; border:none;'></div>";
        }

        for ($day = 1; $day <= $days_in_month; $day++) {
            $current_date = sprintf("%04d-%02d-%02d", $year, $month, $day);
            echo "<div class='cal-day'><div class='day-num'>$day</div>";

            // List everyone who has a record on this date
            if (isset($attendance_data[$current_date])) {
                foreach ($attendance_data[$current_date] as $record) {
                    $name = htmlspecialchars($record['name']);
                    $stat = $record['status'];
                    
                    if (in_array($stat, ['on_time', 'grace_period', 'late'])) {
                        echo "<div class='badge bg-present' title='$name (Present)'><i class='fa-solid fa-user-check'></i> $name</div>";
                    } elseif ($stat === 'absent') {
                        echo "<div class='badge bg-absent' title='$name (Absent)'><i class='fa-solid fa-user-times'></i> $name</div>";
                    } elseif ($stat === 'leave_AL') {
                        echo "<div class='badge bg-al' title='$name (Annual Leave)'><i class='fa-solid fa-plane'></i> $name</div>";
                    } elseif ($stat === 'leave_MC') {
                        echo "<div class='badge bg-mc' title='$name (Medical Leave)'><i class='fa-solid fa-capsules'></i> $name</div>";
                    }
                }
            }
            echo "</div>";
        }
        ?>
    </div>
</div>

</body>
</html>