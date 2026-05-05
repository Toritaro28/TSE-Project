<?php
session_start();
require_once 'db.php';

// If user is logged in, remove their token from the database
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
}

// Destroy Session
session_unset();
session_destroy();

// Destroy Cookie
setcookie('remember_token', '', time() - 3600, "/");

// Redirect to login page
header("Location: login.php");
exit();
?>