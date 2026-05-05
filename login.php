<?php
session_start();
require_once 'db.php';

// Function to route users based on role
function routeUser($role) {
    if ($role === 'admin') {
        header("Location: Boss/admin_dashboard.php");
    } else {
        header("Location: Employee/employee_dashboard.php");
    }
    exit();
}

// 1. Check if already logged in via Session
if (isset($_SESSION['user_id'])) {
    routeUser($_SESSION['role']);
}

// 2. Check if seamless "Remember Me" cookie exists (For QR Code scannings)
if (isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];
        routeUser($user['role']);
    }
}

$error = '';

// 3. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Verify Password
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];

        // If 'Remember Me' is checked, generate a token and save to DB + Cookie
        if ($remember) {
            $token = bin2hex(random_bytes(32));
            // Save to DB
            $updateToken = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
            $updateToken->execute([$token, $user['id']]);
            // Save to browser cookie (lasts 30 days)
            setcookie('remember_token', $token, time() + (86400 * 30), "/");
        }

        routeUser($user['role']);
    } else {
        $error = "Invalid email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Portal | Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f4f7f6; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .login-card { background: #fff; padding: 40px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .login-card h2 { text-align: center; color: #2c3e50; margin-bottom: 20px; }
        .tree-icon { display: block; margin: 0 auto 20px auto; font-size: 40px; text-align: center; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; color: #7f8c8d; font-size: 14px; }
        input[type="email"], input[type="password"] { width: 100%; padding: 12px; border: 1px solid #bdc3c7; border-radius: 5px; font-size: 16px; outline: none; }
        input[type="email"]:focus, input[type="password"]:focus { border-color: #27ae60; }
        .remember-forgot { display: flex; justify-content: space-between; align-items: center; font-size: 14px; margin-bottom: 20px; }
        .remember-forgot a { color: #27ae60; text-decoration: none; }
        .btn-login { width: 100%; padding: 12px; background: #27ae60; border: none; border-radius: 5px; color: #fff; font-size: 16px; font-weight: bold; cursor: pointer; transition: background 0.3s; }
        .btn-login:hover { background: #219150; }
        .error { color: #e74c3c; font-size: 14px; text-align: center; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="tree-icon">🌳</div>
    <h2>Welcome Back</h2>
    
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" required autofocus placeholder="name@company.com">
        </div>
        
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required placeholder="••••••••">
        </div>

        <div class="remember-forgot">
            <label>
                <input type="checkbox" name="remember"> Remember Me
            </label>
            <a href="#">Forgot Password?</a>
        </div>

        <button type="submit" class="btn-login">Login</button>
    </form>
</div>

</body>
</html>