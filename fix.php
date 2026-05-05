<?php
require_once 'db.php';

// Generate the true encrypted hash for the word "password"
$real_hash = password_hash('123456', PASSWORD_DEFAULT);

// Fetch all users
$stmt = $pdo->prepare("SELECT id FROM users");
$stmt->execute();
$users = $stmt->fetchAll();

// Update every user in the database to use this new hash
if ($users) {
    $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    foreach ($users as $user) {
        $updateStmt->execute([$real_hash, $user['id']]);
    }
    echo "<h1>Success!</h1>";
    echo "<p>All user passwords have been officially set to: <b>123456</b></p>";
} else {
    echo "<h1>Error:</h1>";
    echo "<p>No users found in the database to update.</p>";
}

echo "<a href='login.php'>Click here to go to the Login page</a>";
?>