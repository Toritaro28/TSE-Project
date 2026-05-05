<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$current_year = date('Y');
$message = '';

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

    $days_requested = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
    $max_date = date('Y-m-d', strtotime('+3 months'));
    
    if ($start_date > $end_date) {
        $message = "<div class='alert error'>End date cannot be before start date!</div>";
    } elseif ($start_date > $max_date) {
        $message = "<div class='alert error'>You can only apply up to 3 months in advance!</div>";
    } else {
        $can_apply = true;
        if ($leave_type === 'AL' && ($balance['al_used'] + $days_requested > $balance['al_total'])) {
            $can_apply = false; $message = "<div class='alert error'>Not enough Annual Leave balance!</div>";
        } elseif ($leave_type === 'MC' && ($balance['mc_used'] + $days_requested > $balance['mc_total'])) {
            $can_apply = false; $message = "<div class='alert error'>Not enough Medical Leave balance!</div>";
        }

        if ($can_apply) {
            $check_overlap = $pdo->prepare("SELECT id FROM leave_requests WHERE user_id = ? AND status != 'rejected' AND (start_date <= ? AND end_date >= ?)");
            $check_overlap->execute([$user_id, $end_date, $start_date]);
            if ($check_overlap->fetch()) {
                $can_apply = false; $message = "<div class='alert error'>Dates overlap with an existing leave request!</div>";
            }
        }

        if ($can_apply) {
            $stmt = $pdo->prepare("INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, reason) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $leave_type, $start_date, $end_date, $reason]);
            $message = "<div class='alert success'>Leave applied successfully! Waiting for Boss approval.</div>";
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave & Calendar</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1, h2 { color: #2c3e50; text-align: center; }
        
        .flex-row { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 20px; }
        .box { flex: 1; padding: 20px; border-radius: 8px; background: #ecf0f1; min-width: 250px; }
        .balance-item { display: flex; justify-content: space-between; font-size: 18px; margin-bottom: 10px; font-weight: bold; }
        
        input, select, button { width: 100%; padding: 10px; margin-top: 5px; margin-bottom: 15px; border-radius: 5px; border: 1px solid #ccc; }
        button.btn-apply { background: #3498db; color: white; border: none; font-weight: bold; cursor: pointer; }
        button.btn-apply:hover { background: #2980b9; }

        .alert { padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; font-weight: bold; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }

        /* History Table */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; background: white; border-radius: 5px; overflow: hidden; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #34495e; color: white; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; color: white; }
        .bg-pending { background-color: #f39c12; }
        .bg-approved { background-color: #27ae60; }
        .bg-rejected { background-color: #c0392b; }

        /* Calendar */
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .calendar-header a { text-decoration: none; padding: 5px 15px; background: #2c3e50; color: white; border-radius: 5px; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; text-align: center; }
        .cal-head { font-weight: bold; background: #34495e; color: white; padding: 10px 0; }
        .cal-day { padding: 15px 5px; border-radius: 5px; background: #fff; border: 1px solid #ddd; display: flex; flex-direction: column; align-items: center; min-height: 80px; }
        .day-num { font-size: 14px; font-weight: bold; margin-bottom: 8px; }
        .cal-icon { font-size: 24px; }

        .status-present { background-color: #d5f5e3; color: #27ae60; border-color: #27ae60; }
        .status-absent { background-color: #fadbd8; color: #c0392b; border-color: #c0392b; }
        .status-al { background-color: #d6eaf8; color: #2980b9; border-color: #2980b9; }
        .status-mc { background-color: #fcf3cf; color: #f39c12; border-color: #f39c12; }
        .status-ul { background-color: #ebedef; color: #7f8c8d; border-color: #7f8c8d; }
        .status-ph { background-color: #ebdef0; color: #8e44ad; border-color: #8e44ad; }
        .status-future { background-color: #ffffff; color: #bdc3c7; border: 1px dashed #bdc3c7; }

        .legend { display: flex; flex-wrap: wrap; gap: 15px; justify-content: center; margin-top: 20px; font-size: 14px; }
    </style>
</head>
<body>

<div class="container">
    <a href="employee_dashboard.php" style="text-decoration:none; color:#3498db; font-weight:bold;">&larr; Back to Dashboard</a>
    <h1>Leave Management</h1>

    <?= $message; ?>

    <div class="flex-row">
        <!-- Leave Balances -->
        <div class="box">
            <h2>Your Balances (<?= $current_year ?>)</h2>
            <div class="balance-item">
                <span>Annual Leave (AL):</span>
                <span style="color:#2980b9"><?= $balance['al_total'] - $balance['al_used'] ?> / <?= $balance['al_total'] ?></span>
            </div>
            <div class="balance-item">
                <span>Medical Leave (MC):</span>
                <span style="color:#f39c12"><?= $balance['mc_total'] - $balance['mc_used'] ?> / <?= $balance['mc_total'] ?></span>
            </div>
        </div>

        <!-- Apply Form -->
        <div class="box">
            <h2>Apply for Leave</h2>
            <form method="POST">
                <select name="leave_type" required>
                    <option value="AL">Annual Leave (AL)</option>
                    <option value="MC">Medical Leave (MC)</option>
                    <option value="UL">Unpaid Leave (UL)</option>
                </select>
                <div style="display:flex; gap:10px;">
                    <div style="flex:1;"><label>Start Date</label><input type="date" name="start_date" required></div>
                    <div style="flex:1;"><label>End Date</label><input type="date" name="end_date" required></div>
                </div>
                <input type="text" name="reason" placeholder="Reason (e.g., Family trip, Sick)" required>
                <button type="submit" name="apply_leave" class="btn-apply">Submit Application</button>
            </form>
        </div>
    </div>

    <!-- NEW SECTION: LEAVE HISTORY -->
    <div class="box" style="flex: 100%; margin-bottom: 30px;">
        <h2>Your Leave History</h2>
        <?php if (count($leave_history) > 0): ?>
            <table>
                <tr>
                    <th>Type</th>
                    <th>Dates</th>
                    <th>Status</th>
                    <th>Boss's Remark</th>
                </tr>
                <?php foreach ($leave_history as $req): ?>
                <tr>
                    <td><strong><?= $req['leave_type']; ?></strong></td>
                    <td><?= $req['start_date']; ?> to <?= $req['end_date']; ?></td>
                    <td>
                        <?php if ($req['status'] === 'pending'): ?>
                            <span class="status-badge bg-pending">Pending</span>
                        <?php elseif ($req['status'] === 'approved'): ?>
                            <span class="status-badge bg-approved">Approved</span>
                        <?php else: ?>
                            <span class="status-badge bg-rejected">Rejected</span>
                        <?php endif; ?>
                    </td>
                    <td><i style="color:#7f8c8d;"><?= htmlspecialchars($req['admin_remark'] ? $req['admin_remark'] : '-'); ?></i></td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p style="text-align:center; color:gray;">You have not applied for any leave yet.</p>
        <?php endif; ?>
    </div>

    <!-- Calendar Section -->
    <hr><br>
    <div class="calendar-header">
        <?php 
            $prev_month = $month - 1; $prev_year = $year;
            if($prev_month == 0) { $prev_month = 12; $prev_year--; }
            $next_month = $month + 1; $next_year = $year;
            if($next_month == 13) { $next_month = 1; $next_year++; }
        ?>
        <a href="?m=<?= $prev_month ?>&y=<?= $prev_year ?>">&laquo; Prev</a>
        <h2>Attendance Heatmap (<?= date('F Y', mktime(0,0,0,$month,1,$year)) ?>)</h2>
        <a href="?m=<?= $next_month ?>&y=<?= $next_year ?>">Next &raquo;</a>
    </div>
    
    <p style="text-align:center; color:#7f8c8d; font-size: 14px;"><i>Note: Leaves will only appear on the calendar after they are Approved.</i></p>

    <div class="calendar-grid">
        <div class="cal-head">Sun</div><div class="cal-head">Mon</div><div class="cal-head">Tue</div>
        <div class="cal-head">Wed</div><div class="cal-head">Thu</div><div class="cal-head">Fri</div><div class="cal-head">Sat</div>
        
        <?php
        $first_day_of_month = date('w', mktime(0,0,0,$month,1,$year));
        for ($i = 0; $i < $first_day_of_month; $i++) {
            echo "<div class='cal-day' style='border:none; background:transparent;'></div>";
        }

        for ($day = 1; $day <= $days_in_month; $day++) {
            $current_date = sprintf("%04d-%02d-%02d", $year, $month, $day);
            $status_class = 'status-future';
            $icon = '<i class="fa-regular fa-calendar cal-icon"></i>'; 

            if (isset($attendance_data[$current_date])) {
                $stat = $attendance_data[$current_date];
                if (in_array($stat, ['on_time', 'grace_period', 'late'])) {
                    $status_class = 'status-present'; $icon = '<i class="fa-solid fa-user-check cal-icon"></i>';
                } elseif ($stat === 'absent') {
                    $status_class = 'status-absent'; $icon = '<i class="fa-solid fa-user-times cal-icon"></i>';
                } elseif ($stat === 'on_leave_AL') {
                    $status_class = 'status-al'; $icon = '<i class="fa-solid fa-plane cal-icon"></i>';
                } elseif ($stat === 'on_leave_MC') {
                    $status_class = 'status-mc'; $icon = '<i class="fa-solid fa-capsules cal-icon"></i>';
                } elseif ($stat === 'on_leave_UL') {
                    $status_class = 'status-ul'; $icon = '<i class="fa-solid fa-wallet cal-icon"></i>';
                }
            } elseif ($current_date <= date('Y-m-d')) {
                $status_class = 'status-future'; $icon = '<i class="fa-regular fa-calendar cal-icon"></i>';
            }

            echo "<div class='cal-day $status_class'><div class='day-num'>$day</div>$icon</div>";
        }
        ?>
    </div>

    <!-- UI Legend -->
    <div class="legend">
        <span><i class="fa-solid fa-user-check" style="color:#27ae60"></i> Present</span>
        <span><i class="fa-solid fa-user-times" style="color:#c0392b"></i> Absent</span>
        <span><i class="fa-solid fa-plane" style="color:#2980b9"></i> Annual Leave</span>
        <span><i class="fa-solid fa-capsules" style="color:#f39c12"></i> Medical Leave</span>
        <span><i class="fa-solid fa-star" style="color:#8e44ad"></i> Public Holiday</span>
        <span><i class="fa-solid fa-wallet" style="color:#7f8c8d"></i> Unpaid Leave</span>
    </div>
</div>

</body>
</html>