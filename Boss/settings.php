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

// Handle leave rolling window save
if (isset($_POST['save_leave'])) {
    $rolling = (int)$_POST['leave_rolling_months'];
    $rolling = in_array($rolling, [1, 3, 6, 12]) ? $rolling : 3;

    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('leave_rolling_months', ?, 'Max months ahead employee can apply for leave') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$rolling]);
    $message = "Leave rolling window set to $rolling month(s).";
    $message_type = 'success';
}

// Handle office IP save
if (isset($_POST['save_ip'])) {
    $ip = trim($_POST['office_ip']);

    // Validate IPv4 format
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('office_ip', ?, 'Company Public Wi-Fi IP') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$ip]);
        $message = "Office IP updated to $ip.";
        $message_type = 'success';
    } else {
        $message = "Invalid IP address format. Please enter a valid IPv4 address (e.g. 192.168.1.100).";
        $message_type = 'error';
    }
}

// Handle IP validation toggle
if (isset($_POST['save_validation'])) {
    $enabled = isset($_POST['enable_ip_validation']) ? 1 : 0;
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('enable_ip_validation', ?, 'Enable/disable office IP validation for check-in') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$enabled]);
    $message = "IP validation " . ($enabled ? 'enabled' : 'disabled') . ".";
    $message_type = 'success';
}

// Handle GPS coordinate save
if (isset($_POST['save_gps'])) {
    $lat = $_POST['office_lat'];
    $lng = $_POST['office_lng'];
    if (is_numeric($lat) && is_numeric($lng) && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('office_lat', ?, 'Company Office Latitude') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$lat]);
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('office_lng', ?, 'Company Office Longitude') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$lng]);
        $message = "Office coordinates updated to $lat, $lng.";
        $message_type = 'success';
    } else {
        $message = "Invalid coordinates. Latitude must be -90 to 90, longitude -180 to 180.";
        $message_type = 'error';
    }
}

// Handle radius save
if (isset($_POST['save_radius'])) {
    $radius = (int)$_POST['office_radius'];
    if ($radius >= 50 && $radius <= 5000) {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('office_radius', ?, 'Office GPS radius in meters') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$radius]);
        $message = "Office radius set to $radius meters.";
        $message_type = 'success';
    } else {
        $message = "Radius must be between 50 and 5000 meters.";
        $message_type = 'error';
    }
}

// Handle GPS validation toggle
if (isset($_POST['save_gps_toggle'])) {
    $enabled = isset($_POST['enable_gps_validation']) ? 1 : 0;
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('enable_gps_validation', ?, 'Enable/disable GPS validation for check-in') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$enabled]);
    $message = "GPS validation " . ($enabled ? 'enabled' : 'disabled') . ".";
    $message_type = 'success';
}

// Fetch current values
$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'leave_rolling_months'");
$current_rolling = (int)($stmt->fetchColumn() ?: 3);

$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'office_ip'");
$current_ip = $stmt->fetchColumn() ?: '192.168.1.100';

$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'office_lat'");
$current_lat = $stmt->fetchColumn() ?: '3.141592';

$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'office_lng'");
$current_lng = $stmt->fetchColumn() ?: '101.686530';

$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'office_radius'");
$current_radius = (int)($stmt->fetchColumn() ?: 200);

$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_ip_validation'");
$ip_validation_enabled = (int)($stmt->fetchColumn() ?: 0);

$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_gps_validation'");
$gps_validation_enabled = (int)($stmt->fetchColumn() ?: 0);
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
    .main-inner { padding: 24px 30px 36px; display: flex; flex-direction: column; gap: 20px; max-width: 900px; width: 100%; }

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
            <button type="submit" name="save_leave" class="btn btn-primary" style="width:100%;margin-top:8px;">💾 Save Window</button>
          </form>
        </section>

        <!-- Office IP Section -->
        <section class="card">
          <div class="card-header">
            <span class="card-title">🌐 Office IP Address</span>
            <span class="current-value">Current: <?= htmlspecialchars($current_ip) ?></span>
          </div>
          <form method="POST">
            <div class="form-group">
              <label class="form-label">Company Wi-Fi Public IP</label>
              <p style="font-size:12px;color:var(--muted);margin-bottom:4px;">Used for IP-based attendance validation. Employees must be on this network to check in.</p>
              <input type="text" name="office_ip" class="form-input"
                     value="<?= htmlspecialchars($current_ip) ?>"
                     placeholder="e.g. 192.168.1.100"
                     pattern="^(\d{1,3}\.){3}\d{1,3}$"
                     title="Enter a valid IPv4 address (e.g. 192.168.1.100)"
                     required>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px;">
              <button type="submit" name="save_ip" class="btn btn-primary" style="flex:1;">💾 Update IP</button>
              <button type="button" class="btn" style="background:var(--border-subtle);color:var(--fg-secondary);"
                      onclick="document.querySelector('[name=office_ip]').value = '<?= htmlspecialchars($current_ip) ?>';">↺ Reset</button>
            </div>
          </form>
        </section>

        <!-- Office GPS Section with Interactive Map -->
        <section class="card">
          <div class="card-header">
            <span class="card-title">📍 Office Location</span>
            <span class="current-value">📍 <?= htmlspecialchars($current_lat) ?>, <?= htmlspecialchars($current_lng) ?></span>
          </div>
          <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
          <!-- Address Search -->
          <div style="display:flex;gap:8px;">
            <input type="text" id="search-input" class="form-input" placeholder="Search address, company name, or place…" style="flex:1;">
            <button type="button" id="search-btn" class="btn btn-primary" style="white-space:nowrap;">🔍 Search</button>
            <button type="button" id="locate-btn" class="btn" style="background:var(--border-subtle);color:var(--fg-secondary);white-space:nowrap;" title="Use my current location">📍 My Location</button>
          </div>
          <div id="search-status" style="font-size:11px;color:var(--muted);min-height:16px;"></div>
          <!-- Map -->
          <div id="map" style="height:320px;border-radius:var(--radius-md);border:1.5px solid var(--border-subtle);z-index:1;"></div>
          <p style="font-size:10px;color:var(--muted);">Search an address, click the map, or use My Location to set the office coordinates. Map tiles © OpenStreetMap.</p>
          <form method="POST" id="gps-form">
            <input type="hidden" name="office_lat" id="lat-input" value="<?= htmlspecialchars($current_lat) ?>">
            <input type="hidden" name="office_lng" id="lng-input" value="<?= htmlspecialchars($current_lng) ?>">
            <div class="form-row" style="margin-top:8px;">
              <div class="form-group">
                <label class="form-label">Latitude</label>
                <input type="text" class="form-input" id="lat-display" value="<?= htmlspecialchars($current_lat) ?>" readonly>
              </div>
              <div class="form-group">
                <label class="form-label">Longitude</label>
                <input type="text" class="form-input" id="lng-display" value="<?= htmlspecialchars($current_lng) ?>" readonly>
              </div>
            </div>
            <button type="submit" name="save_gps" class="btn btn-primary" style="width:100%;margin-top:8px;">💾 Save Coordinates</button>
          </form>
        </section>

        <!-- Radius + GPS Toggle -->
        <section class="card">
          <div class="card-header">
            <span class="card-title">📍 GPS Validation</span>
            <span class="current-value" style="background:<?= $gps_validation_enabled ? 'oklch(92% 0.06 148)' : 'oklch(92% 0.003 250)' ?>;color:<?= $gps_validation_enabled ? 'oklch(36% 0.1 148)' : 'oklch(48% 0.005 250)' ?>;"><?= $gps_validation_enabled ? 'Enabled' : 'Disabled' ?></span>
          </div>
          <form method="POST">
            <div class="form-group">
              <label class="form-label">Office Radius (meters)</label>
              <p style="font-size:12px;color:var(--muted);margin-bottom:4px;">Employees must be within this distance from the office to check in. Range: 50–5000 meters.</p>
              <input type="number" name="office_radius" class="form-input" value="<?= $current_radius ?>" min="50" max="5000" required>
            </div>
            <button type="submit" name="save_radius" class="btn btn-primary" style="width:100%;margin-top:4px;">💾 Save Radius</button>
          </form>
          <form method="POST" style="margin-top:8px;">
            <label class="radio-item <?= $gps_validation_enabled ? 'selected' : '' ?>" style="cursor:pointer;">
              <input type="checkbox" name="enable_gps_validation" <?= $gps_validation_enabled ? 'checked' : '' ?>
                     onchange="this.closest('form').submit(); this.closest('form').querySelector('[name=save_gps_toggle]').click();" style="display:none;">
              <span class="ri-label" style="flex:1;">Require GPS Location for Check-In</span>
              <span class="ri-desc"><?= $gps_validation_enabled ? 'ON — employees must be within ' . $current_radius . 'm' : 'OFF — any location allowed (dev mode)' ?></span>
            </label>
            <button type="submit" name="save_gps_toggle" class="btn btn-primary" style="display:none;"></button>
          </form>
        </section>

        <!-- IP Validation Toggle -->
        <section class="card">
          <div class="card-header">
            <span class="card-title">🛡️ IP Validation</span>
            <span class="current-value" style="background:<?= $ip_validation_enabled ? 'oklch(92% 0.06 148)' : 'oklch(92% 0.003 250)' ?>;color:<?= $ip_validation_enabled ? 'oklch(36% 0.1 148)' : 'oklch(48% 0.005 250)' ?>;"><?= $ip_validation_enabled ? 'Enabled' : 'Disabled' ?></span>
          </div>
          <form method="POST">
            <label class="radio-item <?= $ip_validation_enabled ? 'selected' : '' ?>" style="cursor:pointer;">
              <input type="checkbox" name="enable_ip_validation" <?= $ip_validation_enabled ? 'checked' : '' ?>
                     onchange="this.closest('form').submit(); this.closest('form').querySelector('[name=save_validation]').click();" style="display:none;">
              <span class="ri-label" style="flex:1;">Require Office IP for Check-In</span>
              <span class="ri-desc"><?= $ip_validation_enabled ? 'ON — employees must be on office WiFi' : 'OFF — any IP allowed (dev mode)' ?></span>
            </label>
            <p style="font-size:10px;color:var(--muted);margin-top:6px;">
              When enabled, employee check-ins are blocked unless their IP matches the Office IP above.
              Localhost (<code>127.0.0.1</code>, <code>::1</code>) always bypasses validation regardless of this setting.
            </p>
            <button type="submit" name="save_validation" class="btn btn-primary" style="display:none;"></button>
          </form>
        </section>
      </div>
    </main>
  </div>

  <?php include 'boss_bottom_nav.php'; ?>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    // --- Shared helpers ---
    const $ = (s) => document.getElementById(s);
    const officeLat = <?= (float)$current_lat ?>;
    const officeLng = <?= (float)$current_lng ?>;

    // --- Leaflet Map ---
    const map = L.map('map').setView([officeLat, officeLng], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap', maxZoom: 19
    }).addTo(map);
    let marker = L.marker([officeLat, officeLng]).addTo(map).bindPopup('Office Location').openPopup();

    function updateCoords(lat, lng) {
      marker.setLatLng([lat, lng]);
      $('lat-input').value = lat.toFixed(6);
      $('lng-input').value = lng.toFixed(6);
      $('lat-display').value = lat.toFixed(6);
      $('lng-display').value = lng.toFixed(6);
      map.setView([lat, lng], map.getZoom());
      marker.bindPopup('Office Location').openPopup();
    }

    // Click anywhere on map to set location
    map.on('click', function(e) { updateCoords(e.latlng.lat, e.latlng.lng); });

    // --- Address Search (Nominatim / OpenStreetMap) ---
    function setStatus(text, isError) {
      $('search-status').textContent = text;
      $('search-status').style.color = isError ? 'var(--red-status)' : 'var(--muted)';
    }

    $('search-btn').addEventListener('click', function() {
      const query = $('search-input').value.trim();
      if (!query) { setStatus('Please enter an address or place name.', true); return; }
      setStatus('Searching…', false);
      fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(query))
        .then(r => r.json())
        .then(results => {
          if (results.length === 0) {
            setStatus('Address not found. Try a more specific search.', true);
          } else {
            const r = results[0];
            updateCoords(parseFloat(r.lat), parseFloat(r.lon));
            setStatus('Found: ' + r.display_name, false);
          }
        })
        .catch(function() {
          setStatus('Search request failed. Check your internet connection.', true);
        });
    });

    // Enter key triggers search
    $('search-input').addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); $('search-btn').click(); }
    });

    // --- Use My Current Location ---
    $('locate-btn').addEventListener('click', function() {
      if (!navigator.geolocation) {
        setStatus('Geolocation is not supported by your browser.', true); return;
      }
      setStatus('Getting your location…', false);
      navigator.geolocation.getCurrentPosition(
        function(pos) {
          updateCoords(pos.coords.latitude, pos.coords.longitude);
          setStatus('Location set to your current position.', false);
        },
        function(err) {
          const msgs = {1:'Permission denied. Check browser settings.',2:'Position unavailable.',3:'Request timed out.'};
          setStatus(msgs[err.code] || 'Geolocation failed.', true);
        },
        { timeout: 10000, enableHighAccuracy: true }
      );
    });

    // --- Radio items ---
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