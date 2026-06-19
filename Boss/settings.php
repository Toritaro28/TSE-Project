<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$active_page = 'settings';
$message = '';
$message_type = '';

// Handle save
if (isset($_POST['save_settings'])) {
    $rolling = (int)$_POST['leave_rolling_months'];
    $rolling = in_array($rolling, [1, 3, 6, 12]) ? $rolling : 3;

    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('leave_rolling_months', ?, 'Max months ahead employee can apply for leave') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$rolling]);
    $message = "Settings saved. Leave rolling window set to $rolling month(s).";
    $message_type = 'success';
}

// Fetch current value
$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'leave_rolling_months'");
$current_rolling = (int)($stmt->fetchColumn() ?: 3);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Settings — LeafPoint</title>
  <style>
    :root {
      --bg: oklch(96.5% 0.006 245);
      --bg-gradient: radial-gradient(ellipse at 70% 0%, oklch(90% 0.04 170 / 0.18), oklch(97% 0.004 245) 55%);
      --surface-glass: rgba(255, 255, 255, 0.52);
      --surface-glass-hover: rgba(255, 255, 255, 0.72);
      --surface-solid: #ffffff;
      --fg: oklch(16% 0.018 252); --fg-secondary: oklch(36% 0.022 250); --muted: oklch(53% 0.016 250);
      --border-glass: rgba(255, 255, 255, 0.38); --border-subtle: rgba(0, 0, 0, 0.055);
      --accent: oklch(56% 0.19 148); --accent-soft: oklch(74% 0.14 148); --accent-dark: oklch(48% 0.16 148);
      --accent-glow: oklch(62% 0.21 148 / 0.3); --gold: oklch(70% 0.19 82);
      --green-status: oklch(58% 0.17 142); --red-status: oklch(53% 0.22 22); --blue-info: oklch(56% 0.16 255);
      --sidebar-bg: oklch(13% 0.02 252); --sidebar-fg: oklch(84% 0.006 250); --sidebar-muted: oklch(60% 0.016 250);
      --radius-sm: 10px; --radius-md: 16px; --radius-lg: 22px;
      --shadow-card: 0 2px 16px rgba(0,0,0,0.04), 0 0 0 1px rgba(0,0,0,0.03);
      --shadow-card-hover: 0 6px 28px rgba(0,0,0,0.07), 0 0 0 1px rgba(0,0,0,0.04);
      --font-display: -apple-system, BlinkMacSystemFont, 'SF Pro Display', system-ui, sans-serif;
      --font-body: -apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, sans-serif;
      --font-mono: 'SF Mono', ui-monospace, 'JetBrains Mono', Menlo, monospace;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { width: 100%; height: 100%; font-family: var(--font-body); color: var(--fg); background: var(--bg); -webkit-font-smoothing: antialiased; overflow: hidden; }
    .app { display: flex; height: 100vh; width: 100%; }

    .sidebar { width: 250px; min-width: 250px; height: 100%; background: var(--sidebar-bg); color: var(--sidebar-fg); display: flex; flex-direction: column; padding: 26px 18px 18px; gap: 5px; z-index: 10; border-right: 1px solid rgba(255,255,255,0.06); }
    .sidebar-brand { display: flex; align-items: center; gap: 11px; padding: 0 8px 24px; font-family: var(--font-display); font-size: 19px; font-weight: 700; letter-spacing: -0.02em; color: #fff; }
    .sidebar-brand .logo-icon { width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, var(--accent), oklch(46% 0.15 158)); display: grid; place-items: center; font-size: 20px; }
    .sidebar-nav { display: flex; flex-direction: column; gap: 2px; flex: 1; }
    .sidebar-nav .nav-section { font-size: 10px; text-transform: uppercase; letter-spacing: 0.09em; color: var(--sidebar-muted); padding: 14px 10px 5px; font-weight: 600; }
    .sidebar-nav a { display: flex; align-items: center; gap: 9px; width: 100%; padding: 10px 10px; border: none; border-radius: 9px; background: transparent; color: var(--sidebar-fg); font: 13px/1.4 var(--font-body); cursor: pointer; transition: background 0.15s; text-align: left; letter-spacing: -0.01em; text-decoration: none; }
    .sidebar-nav a:hover { background: rgba(255,255,255,0.055); }
    .sidebar-nav a.active { background: rgba(255,255,255,0.1); color: #fff; font-weight: 600; }
    .sidebar-nav a .nav-icon { font-size: 16px; width: 22px; text-align: center; flex-shrink: 0; }
    .sidebar-user { display: flex; align-items: center; gap: 10px; padding: 12px 8px; border-top: 1px solid rgba(255,255,255,0.07); margin-top: auto; }
    .sidebar-user .avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--accent), oklch(48% 0.13 165)); display: grid; place-items: center; font-size: 14px; font-weight: 700; color: #fff; flex-shrink: 0; }
    .sidebar-user .user-name { font-size: 13px; font-weight: 600; color: #fff; }
    .sidebar-user .user-role { font-size: 10px; color: var(--sidebar-muted); }

    .main { flex: 1; overflow-y: auto; overflow-x: hidden; background: var(--bg-gradient); display: flex; flex-direction: column; }
    .main-inner { padding: 24px 30px 36px; display: flex; flex-direction: column; gap: 20px; max-width: 700px; width: 100%; }

    .topbar { display: flex; align-items: center; gap: 10px; }
    .topbar .title { font-family: var(--font-display); font-size: 20px; font-weight: 700; letter-spacing: -0.02em; }
    .topbar .admin-badge { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: 0.02em; background: oklch(92% 0.04 310); color: oklch(38% 0.1 310); white-space: nowrap; }

    .card { background: var(--surface-glass); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border: 1px solid var(--border-glass); border-radius: var(--radius-lg); box-shadow: var(--shadow-card); padding: 22px 24px; display: flex; flex-direction: column; gap: 14px; transition: box-shadow 0.2s, background 0.2s; }
    .card:hover { background: var(--surface-glass-hover); box-shadow: var(--shadow-card-hover); }
    .card-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .card-title { font-family: var(--font-display); font-size: 15px; font-weight: 700; letter-spacing: -0.01em; }

    .form-group { display: flex; flex-direction: column; gap: 6px; }
    .form-label { font-size: 12px; font-weight: 700; color: var(--fg-secondary); text-transform: uppercase; letter-spacing: 0.01em; }
    .radio-group { display: flex; flex-direction: column; gap: 8px; }
    .radio-item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: var(--radius-sm); border: 1.5px solid var(--border-subtle); cursor: pointer; transition: all 0.15s; }
    .radio-item:hover { border-color: var(--accent-soft); background: rgba(0,0,0,0.01); }
    .radio-item.selected { border-color: var(--accent); background: oklch(94% 0.04 148 / 0.3); }
    .radio-item input[type="radio"] { accent-color: var(--accent); width: 18px; height: 18px; cursor: pointer; }
    .radio-item .ri-label { font-weight: 600; font-size: 14px; color: var(--fg); }
    .radio-item .ri-desc { font-size: 12px; color: var(--muted); margin-left: auto; }
    .current-value { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 999px; font-size: 13px; font-weight: 700; background: oklch(93% 0.04 148 / 0.4); color: oklch(38% 0.1 148); }

    .btn { padding: 12px 24px; border: none; border-radius: var(--radius-sm); font-family: var(--font-body); font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
    .btn-primary { background: var(--accent); color: #fff; box-shadow: 0 4px 16px var(--accent-glow); }
    .btn-primary:hover { background: var(--accent-dark); transform: translateY(-1px); box-shadow: 0 6px 22px oklch(56% 0.19 148 / 0.4); }

    .toast { position: fixed; top: 20px; left: 50%; transform: translateX(-50%) translateY(-120px); background: var(--surface-solid); border: 1px solid var(--green-status); border-radius: var(--radius-md); padding: 14px 20px; font-weight: 600; font-size: 14px; color: var(--fg); box-shadow: 0 8px 30px rgba(0,0,0,0.12); z-index: 100; display: flex; align-items: center; gap: 8px; transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
    .toast.show { transform: translateX(-50%) translateY(0); }
    .toast.error { border-color: var(--red-status); }

    .bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 30; height: 64px; background: oklch(98% 0.003 250 / 0.92); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border-top: 1px solid rgba(0,0,0,0.06); align-items: center; justify-content: space-around; padding: 0 8px; padding-bottom: env(safe-area-inset-bottom, 0px); }
    .bottom-nav a { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 6px 10px; border: none; background: none; font: 10px/1 var(--font-body); color: var(--muted); cursor: pointer; transition: color 0.15s; font-weight: 500; text-decoration: none; }
    .bottom-nav a .nav-icon { font-size: 20px; line-height: 1; }
    .bottom-nav a.active { color: var(--accent); font-weight: 700; }

    @media (max-width: 800px) { .sidebar { width: 210px; min-width: 210px; } .main-inner { padding: 16px 12px 80px; } }
    @media (max-width: 660px) { .sidebar { display: none; } .bottom-nav { display: flex; } .main-inner { padding: 14px 10px 80px; } }
  </style>
</head>
<body>
  <div class="toast" id="toast" style="display:none;">
    <span id="toast-icon">✅</span>
    <span id="toast-msg"></span>
  </div>

  <div class="app">
    <?php include 'boss_sidebar.php'; ?>

    <main class="main">
      <div class="main-inner">
        <header class="topbar">
          <span class="title">⚙️ System Settings</span>
          <span class="admin-badge">👑 Admin</span>
        </header>

        <section class="card">
          <div class="card-header">
            <span class="card-title">📅 Leave Rolling Window</span>
            <span class="current-value">Current: <?= $current_rolling ?> month(s)</span>
          </div>
          <form method="POST">
            <div class="form-group">
              <label class="form-label">Maximum Months Ahead</label>
              <p style="font-size:12px;color:var(--muted);margin-bottom:4px;">Employees can only apply for leave up to this many months in advance.</p>
              <div class="radio-group" id="radio-group">
                <?php foreach ([1, 3, 6, 12] as $m): ?>
                <label class="radio-item <?= $current_rolling === $m ? 'selected' : '' ?>">
                  <input type="radio" name="leave_rolling_months" value="<?= $m ?>" <?= $current_rolling === $m ? 'checked' : '' ?>>
                  <span class="ri-label"><?= $m ?> month<?= $m > 1 ? 's' : '' ?></span>
                  <span class="ri-desc">Up to <?= date('F Y', strtotime("+{$m} months")) ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <button type="submit" name="save_settings" class="btn btn-primary" style="width:100%;margin-top:8px;">💾 Save Settings</button>
          </form>
        </section>
      </div>
    </main>
  </div>

  <nav class="bottom-nav">
    <a href="admin_dashboard.php"><span class="nav-icon">📋</span>Approvals</a>
    <a href="master_calendar.php"><span class="nav-icon">📅</span>Calendar</a>
    <a href="store_admin.php"><span class="nav-icon">🎁</span>Store</a>
    <a href="../logout.php"><span class="nav-icon">🚪</span>Logout</a>
  </nav>

  <script>
    document.querySelectorAll('.radio-item').forEach(item => {
      item.addEventListener('click', function() { this.querySelector('input[type="radio"]').checked = true; });
    });
    document.querySelectorAll('input[type="radio"]').forEach(radio => {
      radio.addEventListener('change', function() {
        document.querySelectorAll('.radio-item').forEach(i => i.classList.remove('selected'));
        this.closest('.radio-item').classList.add('selected');
      });
    });

    function showToast(icon, msg, type) {
      const t = document.getElementById('toast');
      document.getElementById('toast-icon').textContent = icon;
      document.getElementById('toast-msg').textContent = msg;
      t.className = 'toast ' + (type || 'success') + ' show';
      t.style.display = 'flex';
      clearTimeout(t._timeout);
      t._timeout = setTimeout(() => { t.classList.remove('show'); setTimeout(() => { t.style.display = 'none'; }, 350); }, 3500);
    }
    <?php if ($message): ?>
    showToast('<?= $message_type === 'success' ? '✅' : '❌' ?>', <?= json_encode($message) ?>, '<?= $message_type ?>');
    <?php endif; ?>
  </script>
</body>
</html>