<?php
require_once 'db.php';

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate secure reset token with 1-hour expiry
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?");
            $stmt->execute([$token, $expiry, $user['id']]);

            // Generate reset link and redirect directly (dev mode — no email sending)
            header("Location: reset_password.php?token=" . urlencode($token));
            exit();
        } else {
            $message = "No account found with that email address.";
            $message_type = 'error';
        }
    } else {
        $message = "Please enter a valid email address.";
        $message_type = 'error';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>Forgot Password — LeafPoint</title>
  <style>
    :root {
      --bg: oklch(97% 0.005 245);
      --surface: rgba(255, 255, 255, 0.48);
      --surface-solid: #ffffff;
      --surface-input: rgba(255, 255, 255, 0.62);
      --fg: oklch(16% 0.018 252); --fg-secondary: oklch(38% 0.022 250); --muted: oklch(54% 0.016 250);
      --border-subtle: rgba(0, 0, 0, 0.08); --accent: oklch(56% 0.19 148); --accent-deep: oklch(48% 0.17 146);
      --accent-glow: oklch(62% 0.21 148 / 0.35); --red: oklch(53% 0.22 22);
      --radius-sm: 10px; --radius-md: 16px; --radius-xl: 28px;
      --shadow-elevated: 0 8px 32px rgba(0,0,0,0.07), 0 0 0 1px rgba(0,0,0,0.04);
      --font-display: -apple-system, BlinkMacSystemFont, 'SF Pro Display', system-ui, sans-serif;
      --font-body: -apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, sans-serif;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { width: 100%; min-height: 100%; font-family: var(--font-body); color: var(--fg); background: var(--bg); display: grid; place-items: center; padding: 16px; }
    .card { width: 100%; max-width: 420px; padding: 40px 36px 36px; background: var(--surface); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border: 1px solid rgba(255,255,255,0.4); border-radius: var(--radius-xl); box-shadow: var(--shadow-elevated); animation: cardIn 0.4s ease-out; }
    @keyframes cardIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
    .logo { display: flex; align-items: center; gap: 10px; font-family: var(--font-display); font-size: 22px; font-weight: 800; letter-spacing: -0.03em; color: var(--fg); margin-bottom: 20px; }
    .logo span { width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, var(--accent), oklch(52% 0.16 150)); display: grid; place-items: center; font-size: 20px; box-shadow: 0 4px 12px var(--accent-glow); }
    h1 { font-family: var(--font-display); font-size: 24px; font-weight: 800; letter-spacing: -0.03em; margin-bottom: 8px; }
    p { font-size: 14px; color: var(--muted); line-height: 1.5; margin-bottom: 20px; }
    .form-input { width: 100%; height: 48px; padding: 0 16px; background: var(--surface-input); border: 1.5px solid var(--border-subtle); border-radius: var(--radius-md); font: 15px var(--font-body); color: var(--fg); outline: none; transition: all 0.2s; margin-bottom: 16px; }
    .form-input:focus { border-color: var(--accent); box-shadow: 0 0 0 4px oklch(62% 0.21 148 / 0.1); background: var(--surface-solid); }
    .btn { width: 100%; height: 48px; border: none; border-radius: var(--radius-md); background: linear-gradient(135deg, var(--accent), oklch(50% 0.16 148)); color: #fff; font: 16px var(--font-display); font-weight: 700; cursor: pointer; box-shadow: 0 4px 18px var(--accent-glow); transition: all 0.2s; }
    .btn:hover { transform: translateY(-1px); }
    .back-link { display: block; text-align: center; margin-top: 16px; font-size: 13px; color: var(--accent); font-weight: 600; text-decoration: none; }
    .alert { padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; line-height: 1.5; margin-bottom: 16px; word-break: break-all; }
    .alert.success { background: oklch(94% 0.04 148 / 0.5); color: oklch(36% 0.1 148); }
    .alert.error { background: oklch(94% 0.04 22 / 0.4); color: oklch(40% 0.12 22); }
    .dev-link { font-family: monospace; font-size: 11px; }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo"><span>🌿</span> LeafPoint</div>
    <h1>Forgot Password</h1>

    <?php if ($message): ?>
    <div class="alert <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <p>Enter your registered email address. You will be redirected to reset your password.</p>
    <form method="POST">
      <input type="email" name="email" class="form-input" placeholder="your.email@company.com" required autofocus>
      <button type="submit" class="btn">Reset Password</button>
    </form>

    <a href="login.php" class="back-link">← Back to Login</a>
  </div>
</body>
</html>