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
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
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
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>Sign In — LeafPoint</title>
  <style>
    /* ============================================================
       LEAFPOINT LOGIN — Design tokens match the World Tree dashboard
       ============================================================ */
    :root {
      --bg: oklch(97% 0.005 245);
      --surface: rgba(255, 255, 255, 0.48);
      --surface-solid: #ffffff;
      --surface-input: rgba(255, 255, 255, 0.62);
      --fg: oklch(16% 0.018 252);
      --fg-secondary: oklch(38% 0.022 250);
      --muted: oklch(54% 0.016 250);
      --border: rgba(255, 255, 255, 0.4);
      --border-subtle: rgba(0, 0, 0, 0.08);
      --accent: oklch(56% 0.19 148);
      --accent-deep: oklch(48% 0.17 146);
      --accent-soft: oklch(74% 0.14 148);
      --accent-glow: oklch(62% 0.21 148 / 0.35);
      --gold: oklch(70% 0.19 82);
      --red: oklch(53% 0.22 22);
      --radius-sm: 10px;
      --radius-md: 16px;
      --radius-lg: 22px;
      --radius-xl: 28px;
      --shadow-card: 0 2px 16px rgba(0,0,0,0.04), 0 0 0 1px rgba(0,0,0,0.03);
      --shadow-elevated: 0 8px 32px rgba(0,0,0,0.07), 0 0 0 1px rgba(0,0,0,0.04);
      --font-display: -apple-system, BlinkMacSystemFont, 'SF Pro Display', system-ui, sans-serif;
      --font-body: -apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, sans-serif;
      --font-mono: 'SF Mono', ui-monospace, 'JetBrains Mono', Menlo, monospace;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
      width: 100%; min-height: 100%;
      font-family: var(--font-body);
      color: var(--fg);
      background: var(--bg);
      background-image:
        radial-gradient(ellipse at 20% 0%, oklch(62% 0.14 148 / 0.12), transparent 55%),
        radial-gradient(ellipse at 80% 100%, oklch(62% 0.10 148 / 0.08), transparent 50%),
        radial-gradient(ellipse at 50% 50%, oklch(72% 0.06 170 / 0.06), transparent 60%);
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      overflow-x: hidden;
      display: grid;
      place-items: center;
      min-height: 100vh;
      min-height: 100dvh;
      padding: 16px;
    }

    /* ============================================================
       LOGIN CARD — single centered card
       ============================================================ */
    .login-card {
      width: 100%;
      max-width: 460px;
      padding: 44px 40px 40px;
      display: flex; flex-direction: column; gap: 24px;
      background: var(--surface);
      backdrop-filter: blur(24px);
      -webkit-backdrop-filter: blur(24px);
      border: 1px solid var(--border);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-elevated);
      animation: cardIn 0.5s ease-out;
    }
    @keyframes cardIn {
      from { opacity: 0; transform: translateY(12px) scale(0.99); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .login-logo {
      display: flex; align-items: center; gap: 10px;
      font-family: var(--font-display);
      font-size: 22px; font-weight: 800;
      letter-spacing: -0.03em;
      color: var(--fg);
    }
    .login-logo .logo-icon {
      width: 40px; height: 40px; border-radius: var(--radius-sm);
      background: linear-gradient(135deg, var(--accent), oklch(52% 0.16 150));
      display: grid; place-items: center; font-size: 20px;
      box-shadow: 0 4px 12px var(--accent-glow);
    }

    .login-welcome h1 {
      font-family: var(--font-display);
      font-size: 26px; font-weight: 800;
      letter-spacing: -0.03em; line-height: 1.15;
      color: var(--fg);
    }
    .login-welcome p {
      margin-top: 6px;
      font-size: 14px; color: var(--muted);
      line-height: 1.4;
    }

    .form-group { display: flex; flex-direction: column; gap: 5px; }
    .form-label {
      font-size: 13px; font-weight: 700;
      color: var(--fg-secondary);
      letter-spacing: -0.01em;
    }
    .form-input-wrap { position: relative; }
    .form-input {
      width: 100%; height: 48px;
      padding: 0 16px;
      background: var(--surface-input);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1.5px solid var(--border-subtle);
      border-radius: var(--radius-md);
      font: 15px/1.4 var(--font-body);
      color: var(--fg);
      transition: all 0.2s ease;
      outline: none;
    }
    .form-input::placeholder { color: oklch(62% 0.01 250); }
    .form-input:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 4px oklch(62% 0.21 148 / 0.1);
      background: var(--surface-solid);
    }
    .form-input.error {
      border-color: var(--red);
      box-shadow: 0 0 0 4px oklch(53% 0.22 22 / 0.08);
    }
    .form-input.success { border-color: var(--accent); }
    .form-error-msg {
      font-size: 11px; color: var(--red); font-weight: 600;
      min-height: 16px; transition: opacity 0.2s;
    }

    .form-row { display: flex; align-items: center; justify-content: space-between; }

    .remember-me { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; color: var(--fg-secondary); user-select: none; }
    .remember-me input[type="checkbox"] {
      width: 18px; height: 18px; accent-color: var(--accent);
      cursor: pointer; border-radius: 4px;
    }

    .forgot-link {
      font-size: 13px; font-weight: 600; color: var(--accent);
      text-decoration: none; transition: color 0.15s;
    }
    .forgot-link:hover { color: var(--accent-deep); text-decoration: underline; }

    .login-btn {
      width: 100%; height: 52px;
      border: none; border-radius: var(--radius-md);
      background: linear-gradient(135deg, var(--accent), oklch(50% 0.16 148));
      color: #fff;
      font: 16px/1 var(--font-display);
      font-weight: 700;
      letter-spacing: -0.01em;
      cursor: pointer;
      transition: all 0.25s ease;
      display: grid; place-items: center;
      box-shadow: 0 4px 18px var(--accent-glow);
      position: relative;
      overflow: hidden;
    }
    .login-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 28px oklch(62% 0.21 148 / 0.45);
      background: linear-gradient(135deg, oklch(58% 0.19 148), oklch(52% 0.17 148));
    }
    .login-btn:active { transform: translateY(0); }
    .login-btn.loading { pointer-events: none; opacity: 0.85; }
    .login-btn .btn-text { transition: opacity 0.2s; }
    .login-btn.loading .btn-text { opacity: 0; }
    .login-btn .spinner {
      position: absolute; inset: 0;
      display: grid; place-items: center;
      opacity: 0; transition: opacity 0.2s;
    }
    .login-btn.loading .spinner { opacity: 1; }
    .spinner-ring {
      width: 26px; height: 26px;
      border: 2.5px solid rgba(255,255,255,0.35);
      border-top-color: #fff;
      border-radius: 50%;
      animation: spin 0.7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    .divider {
      display: flex; align-items: center; gap: 12px;
      color: var(--muted); font-size: 11px; font-weight: 600;
      text-transform: uppercase; letter-spacing: 0.06em;
    }
    .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border-subtle); }

    .sso-btn {
      width: 100%; height: 46px;
      border: 1.5px solid var(--border-subtle);
      border-radius: var(--radius-md);
      background: var(--surface-input);
      font: 14px/1 var(--font-body);
      font-weight: 600; color: var(--fg-secondary);
      cursor: pointer; transition: all 0.15s;
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .sso-btn:hover { background: var(--surface-solid); border-color: oklch(80% 0.03 250); }

    .login-footer {
      text-align: center; font-size: 11px; color: var(--muted);
      padding: 6px 0 0;
      border-top: 1px solid var(--border-subtle);
    }

    /* ============================================================
       TOAST
       ============================================================ */
    .toast {
      position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
      padding: 12px 24px; border-radius: var(--radius-md);
      background: var(--accent); color: #fff;
      font: 14px/1.3 var(--font-body); font-weight: 700;
      box-shadow: 0 8px 28px var(--accent-glow);
      z-index: 1000;
      animation: toastIn 0.35s ease-out, toastOut 0.35s 3.5s ease-out forwards;
      pointer-events: none;
    }
    .toast.error { background: var(--red); box-shadow: 0 8px 28px oklch(53% 0.22 22 / 0.3); }
    @keyframes toastIn { from { opacity: 0; transform: translateX(-50%) translateY(-16px); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }
    @keyframes toastOut { from { opacity: 1; transform: translateX(-50%) translateY(0); } to { opacity: 0; transform: translateX(-50%) translateY(-10px); } }

    /* ============================================================
       RESPONSIVE
       ============================================================ */
    @media (max-width: 500px) {
      .login-card {
        max-width: 100%;
        padding: 32px 24px 28px;
        border-radius: var(--radius-lg);
      }
      .login-welcome h1 { font-size: 22px; }
    }
    @media (max-width: 380px) {
      .login-card { padding: 24px 16px 22px; }
      .form-row { flex-direction: column; gap: 10px; align-items: flex-start; }
      .forgot-link { align-self: flex-end; }
    }
  </style>
</head>
<body>

<!-- ============================================================
     LOGIN CARD
     ============================================================ -->
<div class="login-card" id="login-card">

  <div class="login-logo">
    <span class="logo-icon">🌳</span>
    LeafPoint
  </div>
  <div class="login-welcome">
    <h1>Welcome Back</h1>
    <p>Sign in to continue your attendance journey</p>
  </div>

  <form id="login-form" method="POST" action="" novalidate autocomplete="on">
    <div style="display:flex;flex-direction:column;gap:18px;">

      <!-- Email -->
      <div class="form-group">
        <label class="form-label" for="username">Username</label>
        <div class="form-input-wrap">
          <input class="form-input" type="text" id="username" name="username"
                 placeholder="your.username"
                 autocomplete="username" required />
        </div>
        <span class="form-error-msg" id="email-error"></span>
      </div>

      <!-- Password -->
      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <div class="form-input-wrap">
          <input class="form-input" type="password" id="password" name="password"
                 placeholder="••••••••"
                 autocomplete="current-password" required minlength="6" />
        </div>
        <span class="form-error-msg" id="password-error"></span>
      </div>

      <!-- Remember + Forgot -->
      <div class="form-row">
        <label class="remember-me">
          <input type="checkbox" id="remember" name="remember" />
          Remember me
        </label>
        <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
      </div>

      <!-- Login button -->
      <button type="submit" class="login-btn" id="login-btn">
        <span class="btn-text">Sign In</span>
        <span class="spinner"><span class="spinner-ring"></span></span>
      </button>


    </div>
  </form>

  <div class="login-footer">
    &copy; <?= date('Y') ?> LeafPoint Attendance System. All rights reserved.
  </div>

</div>

<!-- ============================================================
     JAVASCRIPT
     ============================================================ -->
<script>
(function() {
  const form = document.getElementById('login-form');
  const loginBtn = document.getElementById('login-btn');
  const usernameInput = document.getElementById('username');
  const passwordInput = document.getElementById('password');
  const usernameError = document.getElementById('email-error');
  const passwordError = document.getElementById('password-error');

  // ---- TOAST ----
  function showToast(msg, type) {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();
    const toast = document.createElement('div');
    toast.className = 'toast ' + (type === 'error' ? 'error' : '');
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 4000);
  }

  // ---- Show PHP error as toast on page load ----
  <?php if ($error): ?>
  showToast(<?= json_encode($error) ?>, 'error');
  <?php endif; ?>

  // ---- VALIDATION ----
  function clearErrors() {
    usernameInput.classList.remove('error','success');
    passwordInput.classList.remove('error','success');
    usernameError.textContent = '';
    passwordError.textContent = '';
  }

  function setLoading(loading) {
    if (loading) {
      loginBtn.classList.add('loading');
      loginBtn.disabled = true;
    } else {
      loginBtn.classList.remove('loading');
      loginBtn.disabled = false;
    }
  }

  // ---- Real-time validation ----
  usernameInput.addEventListener('blur', function() {
    if (usernameInput.value.trim() === '') {
      usernameInput.classList.add('error');
      usernameInput.classList.remove('success');
      usernameError.textContent = 'Username is required';
    } else {
      usernameInput.classList.remove('error');
      usernameInput.classList.add('success');
      usernameError.textContent = '';
    }
  });

  usernameInput.addEventListener('input', function() {
    if (usernameInput.classList.contains('error') && usernameInput.value.trim() !== '') {
      usernameInput.classList.remove('error');
      usernameInput.classList.add('success');
      usernameError.textContent = '';
    }
  });

  passwordInput.addEventListener('blur', function() {
    if (passwordInput.value.trim() === '') {
      passwordInput.classList.add('error');
      passwordInput.classList.remove('success');
      passwordError.textContent = 'Password is required';
    } else if (passwordInput.value.length < 6) {
      passwordInput.classList.add('error');
      passwordInput.classList.remove('success');
      passwordError.textContent = 'Password must be at least 6 characters';
    } else {
      passwordInput.classList.remove('error');
      passwordInput.classList.add('success');
      passwordError.textContent = '';
    }
  });

  passwordInput.addEventListener('input', function() {
    if (passwordInput.classList.contains('error') && passwordInput.value.length >= 6) {
      passwordInput.classList.remove('error');
      passwordInput.classList.add('success');
      passwordError.textContent = '';
    }
  });

  // ---- FORM SUBMIT ----
  form.addEventListener('submit', function(e) {
    clearErrors();

    let hasError = false;

    // Validate username
    if (usernameInput.value.trim() === '') {
      usernameInput.classList.add('error');
      usernameError.textContent = 'Username is required';
      hasError = true;
    }

    // Validate password
    if (passwordInput.value.trim() === '') {
      passwordInput.classList.add('error');
      passwordError.textContent = 'Password is required';
      hasError = true;
    } else if (passwordInput.value.length < 6) {
      passwordInput.classList.add('error');
      passwordError.textContent = 'Password must be at least 6 characters';
      hasError = true;
    }

    if (hasError) {
      e.preventDefault();
      const firstError = form.querySelector('.form-input.error');
      if (firstError) firstError.focus();
      return;
    }

    // Validation passed — show loading state, then let form submit to PHP
    setLoading(true);

    // Store remembered username before submit
    if (document.getElementById('remember').checked) {
      try { localStorage.setItem('leafpoint_remembered_username', usernameInput.value.trim()); } catch(_) {}
    }
  });

  // ---- Restore remembered username ----
  try {
    const remembered = localStorage.getItem('leafpoint_remembered_username');
    if (remembered) {
      usernameInput.value = remembered;
      document.getElementById('remember').checked = true;
    }
  } catch(_) {}

  // ---- Keyboard shortcut — Enter focuses username ----
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && document.activeElement === document.body) {
      usernameInput.focus();
    }
  });
})();
</script>

</body>
</html>