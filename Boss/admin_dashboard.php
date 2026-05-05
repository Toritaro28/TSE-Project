<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = '';

// Handle Leave Approval / Rejection
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
            $message = "<div class='alert success'>Leave Approved Successfully! Balance updated.</div>";

        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE leave_requests SET status = 'rejected', admin_remark = ? WHERE id = ?")->execute([$admin_remark, $request_id]);
            $message = "<div class='alert error'>Leave Rejected. Employee has been notified.</div>";
        }
    }
}

// Fetch Pending Leave Requests
$stmt = $pdo->prepare("SELECT lr.*, u.name FROM leave_requests lr JOIN users u ON lr.user_id = u.id WHERE lr.status = 'pending' ORDER BY lr.applied_at ASC");
$stmt->execute();
$pending_leaves = $stmt->fetchAll();
$pending_count = count($pending_leaves);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Boss Dashboard</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; }
        
        .top-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .btn { padding: 10px 20px; text-decoration: none; border-radius: 5px; color: white; font-weight: bold; border: none; cursor: pointer; }
        .btn-calendar { background: #8e44ad; }
        .btn-logout { background: #95a5a6; }
        .btn-approve { background: #27ae60; }
        .btn-reject { background: #c0392b; }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #34495e; color: white; }
        tr:hover { background-color: #f1f1f1; }

        .red-dot { background: #e74c3c; color: white; padding: 2px 8px; border-radius: 50%; font-size: 14px; margin-left: 5px; }
        .alert { padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; font-weight: bold; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>

<div class="container">
    <div class="top-nav">
        <h1>Welcome, Boss (<?= htmlspecialchars($_SESSION['name']); ?>)</h1>
        <div>
            <a href="store_admin.php" class="btn" style="background: #f39c12; color:white;">🎁 Manage Store</a> <!-- NEW BUTTON -->
            <a href="master_calendar.php" class="btn btn-calendar">📅 Master Calendar</a>
            <a href="../logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>

    <?= $message; ?>

    <h2>Pending Leave Approvals <?php if($pending_count > 0) echo "<span class='red-dot'>$pending_count</span>"; ?></h2>
    
    <?php if ($pending_count === 0): ?>
        <p style="color: gray; font-style: italic;">No pending leave requests at the moment. Great!</p>
    <?php else: ?>
        <table>
            <tr>
                <th>Employee</th>
                <th>Type</th>
                <th>Dates</th>
                <th>Reason</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($pending_leaves as $req): ?>
            <tr>
                <td><strong><?= htmlspecialchars($req['name']); ?></strong></td>
                <td><span style="background: #ecf0f1; padding: 3px 8px; border-radius: 3px; font-weight:bold;"><?= $req['leave_type']; ?></span></td>
                <td><?= $req['start_date']; ?> to <?= $req['end_date']; ?></td>
                <td><?= htmlspecialchars($req['reason']); ?></td>
                <td>
                    <!-- Approve Form -->
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="request_id" value="<?= $req['id']; ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-approve" onclick="return confirm('Approve this leave? Balance will be deducted.');">Approve</button>
                    </form>
                    
                    <!-- Reject Form (Uses JS prompt to ask for reason) -->
                    <form method="POST" style="display:inline;" onsubmit="return rejectLeave(this);">
                        <input type="hidden" name="request_id" value="<?= $req['id']; ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="admin_remark" class="remark-input">
                        <button type="submit" class="btn btn-reject">Reject</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<script>
function rejectLeave(form) {
    let reason = prompt("Please provide a reason for rejecting this leave:");
    if (reason === null || reason.trim() === "") {
        alert("Rejection cancelled. You must provide a reason.");
        return false;
    }
    form.querySelector('.remark-input').value = reason;
    return true;
}
</script>

</body>
</html>