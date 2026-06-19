<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$active_page = 'holidays';
$message = '';
$message_type = '';

// ============================================================
// HANDLE ACTIONS
// ============================================================

// Add Holiday
if (isset($_POST['add_holiday'])) {
    $name = trim($_POST['holiday_name']);
    $date = $_POST['holiday_date'];
    if ($name && $date) {
        try {
            $stmt = $pdo->prepare("INSERT INTO public_holidays (holiday_date, holiday_name) VALUES (?, ?)");
            $stmt->execute([$date, $name]);
            $message = "Holiday added successfully.";
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = "Error: " . ($e->getCode() == 23000 ? "That date already has a holiday." : "Could not add holiday.");
            $message_type = 'error';
        }
    }
}

// Edit Holiday
if (isset($_POST['edit_holiday'])) {
    $id = $_POST['holiday_id'];
    $name = trim($_POST['holiday_name']);
    $date = $_POST['holiday_date'];
    if (!$id) {
        $message = "Invalid holiday ID. Please try again.";
        $message_type = 'error';
    } elseif (!$name || !$date) {
        $message = "Holiday name and date are required.";
        $message_type = 'error';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE public_holidays SET holiday_date = ?, holiday_name = ? WHERE id = ?");
            $stmt->execute([$date, $name, $id]);
            $message = "Holiday updated successfully.";
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = ($e->getCode() == 23000) ? "That date already has a holiday." : "Error updating holiday.";
            $message_type = 'error';
        }
    }
}

// Delete Holiday
if (isset($_POST['delete_holiday'])) {
    $id = $_POST['holiday_id'];
    $stmt = $pdo->prepare("DELETE FROM public_holidays WHERE id = ?");
    $stmt->execute([$id]);
    $message = "Holiday deleted.";
    $message_type = 'success';
}

// ============================================================
// FETCH DATA
// ============================================================

$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'date_asc';

$orderClause = match($sort) {
    'date_desc' => 'ORDER BY holiday_date DESC',
    'name_asc' => 'ORDER BY holiday_name ASC',
    'name_desc' => 'ORDER BY holiday_name DESC',
    default => 'ORDER BY holiday_date ASC',
};

if ($search) {
    $stmt = $pdo->prepare("SELECT * FROM public_holidays WHERE holiday_name LIKE ? $orderClause");
    $stmt->execute(["%$search%"]);
} else {
    $stmt = $pdo->query("SELECT * FROM public_holidays $orderClause");
}
$holidays = $stmt->fetchAll();
$total_count = count($holidays);

// Upcoming (today + future)
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT * FROM public_holidays WHERE holiday_date >= ? ORDER BY holiday_date ASC LIMIT 5");
$stmt->execute([$today]);
$upcoming = $stmt->fetchAll();

// Count this year
$this_year = date('Y');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM public_holidays WHERE YEAR(holiday_date) = ?");
$stmt->execute([$this_year]);
$this_year_count = $stmt->fetchColumn();

// Count total
$stmt = $pdo->query("SELECT COUNT(*) FROM public_holidays");
$total_all = $stmt->fetchColumn();

// Edit target (set via GET param)
$edit_id = $_GET['edit'] ?? null;
$edit_holiday = null;
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM public_holidays WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_holiday = $stmt->fetch();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Public Holidays — LeafPoint</title>
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
      --yellow-status: oklch(68% 0.15 85);
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
    .sidebar-user { display: flex; align-items: center; gap: 10px; padding: 12px 8px; border-top: 1px solid rgba(255,255,255,0.07); margin-top: auto; }
    .sidebar-user .avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--accent), oklch(48% 0.13 165)); display: grid; place-items: center; font-size: 14px; font-weight: 700; color: #fff; flex-shrink: 0; }
    .sidebar-user .user-name { font-size: 13px; font-weight: 600; color: #fff; }
    .sidebar-user .user-role { font-size: 10px; color: var(--sidebar-muted); }

    .main { flex: 1; overflow-y: auto; overflow-x: hidden; background: var(--bg-gradient); display: flex; flex-direction: column; }
    .main-inner { padding: 24px 30px 36px; display: flex; flex-direction: column; gap: 20px; max-width: 1200px; width: 100%; }

    .topbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .topbar .title { font-family: var(--font-display); font-size: 20px; font-weight: 700; letter-spacing: -0.02em; }
    .topbar .admin-badge { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; background: oklch(92% 0.04 310); color: oklch(38% 0.1 310); }

    .card { background: var(--surface-glass); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border: 1px solid var(--border-glass); border-radius: var(--radius-lg); box-shadow: var(--shadow-card); padding: 22px 24px; display: flex; flex-direction: column; gap: 14px; transition: box-shadow 0.2s, background 0.2s; }
    .card:hover { background: var(--surface-glass-hover); box-shadow: var(--shadow-card-hover); }
    .card-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .card-title { font-family: var(--font-display); font-size: 15px; font-weight: 700; letter-spacing: -0.01em; }
    .card-subtitle { font-size: 11px; color: var(--muted); }

    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }

    .form-group { display: flex; flex-direction: column; gap: 5px; }
    .form-label { font-size: 12px; font-weight: 700; color: var(--fg-secondary); text-transform: uppercase; letter-spacing: 0.01em; }
    .form-input { width: 100%; padding: 10px 14px; font-family: var(--font-body); font-size: 14px; color: var(--fg); background: var(--surface-solid); border: 1.5px solid var(--border-subtle); border-radius: var(--radius-sm); outline: none; transition: all 0.2s; }
    .form-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px oklch(56% 0.19 148 / 0.1); }
    .form-row { display: flex; gap: 10px; align-items: flex-end; }

    .btn { padding: 10px 20px; border: none; border-radius: var(--radius-sm); font-family: var(--font-body); font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.2s; letter-spacing: -0.01em; }
    .btn-primary { background: var(--accent); color: #fff; box-shadow: 0 4px 16px var(--accent-glow); }
    .btn-primary:hover { background: var(--accent-dark); transform: translateY(-1px); }
    .btn-danger { background: var(--red-status); color: #fff; }
    .btn-danger:hover { background: oklch(46% 0.2 22); }
    .btn-ghost { background: transparent; color: var(--fg-secondary); border: 1.5px solid var(--border-subtle); }
    .btn-ghost:hover { border-color: var(--accent); color: var(--accent); }
    .btn-sm { padding: 5px 12px; font-size: 11px; }

    .search-row { display: flex; gap: 8px; align-items: center; }
    .search-input { flex: 1; padding: 9px 14px; border: 1.5px solid var(--border-subtle); border-radius: var(--radius-sm); font-family: var(--font-body); font-size: 13px; color: var(--fg); background: var(--surface-solid); outline: none; transition: border-color 0.2s; }
    .search-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px oklch(56% 0.19 148 / 0.1); }
    .sort-select { padding: 9px 30px 9px 12px; border: 1.5px solid var(--border-subtle); border-radius: var(--radius-sm); font-family: var(--font-body); font-size: 13px; color: var(--fg); background: var(--surface-solid); outline: none; cursor: pointer; -webkit-appearance: none; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1.5l5 5 5-5' stroke='%23888' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 8px center; }

    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    table thead th { text-align: left; padding: 10px 12px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); font-weight: 700; border-bottom: 1px solid var(--border-subtle); white-space: nowrap; background: oklch(98% 0.002 250 / 0.4); }
    table tbody td { padding: 12px; border-bottom: 1px solid oklch(94% 0.003 250); }
    table tbody tr { transition: background 0.15s; }
    table tbody tr:hover { background: rgba(0,0,0,0.018); }
    table tbody tr:last-child td { border-bottom: none; }

    .holiday-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; background: oklch(92% 0.06 310 / 0.4); color: oklch(36% 0.1 310); }
    .past-badge { opacity: 0.5; }

    .stats-mini { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .stat-mini { background: rgba(0,0,0,0.02); border-radius: var(--radius-sm); padding: 14px; display: flex; flex-direction: column; gap: 4px; }
    .stat-mini .sm-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 700; }
    .stat-mini .sm-value { font-family: var(--font-display); font-size: 24px; font-weight: 800; }
    .stat-mini .sm-value.accent { color: var(--accent); }
    .stat-mini .sm-value.blue { color: var(--blue-info); }

    .upcoming-list { display: flex; flex-direction: column; gap: 6px; }
    .upcoming-item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: var(--radius-sm); background: rgba(0,0,0,0.018); }
    .upcoming-item .ui-date { font-family: var(--font-mono); font-size: 12px; font-weight: 700; color: var(--muted); min-width: 85px; }
    .upcoming-item .ui-name { font-size: 13px; font-weight: 600; }
    .upcoming-item .ui-days { margin-left: auto; font-size: 11px; color: var(--accent); font-weight: 600; }
    .empty-state { text-align: center; padding: 24px; color: var(--muted); font-size: 13px; }

    .toast { position: fixed; top: 20px; left: 50%; transform: translateX(-50%) translateY(-120px); background: var(--surface-solid); border: 1px solid var(--green-status); border-radius: var(--radius-md); padding: 14px 20px; font-weight: 600; font-size: 14px; color: var(--fg); box-shadow: 0 8px 30px rgba(0,0,0,0.12); z-index: 100; display: flex; align-items: center; gap: 8px; transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
    .toast.show { transform: translateX(-50%) translateY(0); }
    .toast.error { border-color: var(--red-status); }

    .bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 30; height: 64px; background: oklch(98% 0.003 250 / 0.92); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border-top: 1px solid rgba(0,0,0,0.06); align-items: center; justify-content: space-around; padding: 0 8px; padding-bottom: env(safe-area-inset-bottom, 0px); }
    .bottom-nav a { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 6px 10px; border: none; background: none; font: 10px/1 var(--font-body); color: var(--muted); cursor: pointer; transition: color 0.15s; font-weight: 500; text-decoration: none; }
    .bottom-nav a .nav-icon { font-size: 20px; line-height: 1; }
    .bottom-nav a.active { color: var(--accent); font-weight: 700; }

    @media (min-width: 640px) { .topbar .title { font-size: 20px; } .card { padding: 22px 24px; } }
    @media (min-width: 1024px) { .card { padding: 24px 28px; } .card-title { font-size: 17px; } }
    @media (max-width: 800px) { .sidebar { width: 210px; min-width: 210px; } .main-inner { padding: 16px 12px 80px; } .grid-2 { grid-template-columns: 1fr; } }
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
          <div style="display:flex;align-items:center;gap:10px;">
            <span class="title">🎌 Public Holidays</span>
            <span class="admin-badge">👑 Admin</span>
          </div>
        </header>

        <!-- Stats -->
        <div class="stats-mini">
          <div class="stat-mini">
            <span class="sm-label">Total Holidays</span>
            <span class="sm-value accent"><?= $total_all ?></span>
          </div>
          <div class="stat-mini">
            <span class="sm-label">This Year (<?= $this_year ?>)</span>
            <span class="sm-value blue"><?= $this_year_count ?></span>
          </div>
        </div>

        <!-- Add / Edit Form + Upcoming -->
        <div class="grid-2">
          <!-- Form -->
          <div class="card">
            <div class="card-header">
              <span class="card-title"><?= $edit_holiday ? '✏️ Edit Holiday' : '➕ Add Holiday' ?></span>
              <?php if ($edit_holiday): ?>
              <a href="public_holidays.php" class="btn btn-ghost btn-sm">Cancel Edit</a>
              <?php endif; ?>
            </div>
            <form method="POST">
              <?php if ($edit_holiday): ?>
              <input type="hidden" name="holiday_id" value="<?= $edit_holiday['id'] ?>">
              <?php endif; ?>
              <div class="form-group">
                <label class="form-label">Holiday Name</label>
                <input type="text" name="holiday_name" class="form-input" required
                       value="<?= htmlspecialchars($edit_holiday['holiday_name'] ?? '') ?>"
                       placeholder="e.g. Merdeka Day">
              </div>
              <div class="form-group">
                <label class="form-label">Date</label>
                <input type="date" name="holiday_date" class="form-input" required
                       value="<?= htmlspecialchars($edit_holiday['holiday_date'] ?? '') ?>">
              </div>
              <button type="submit" name="<?= $edit_holiday ? 'edit_holiday' : 'add_holiday' ?>" class="btn btn-primary" style="width:100%;margin-top:4px;">
                <?= $edit_holiday ? 'Update Holiday' : '+ Add Holiday' ?>
              </button>
            </form>
            <?php if ($edit_holiday): ?>
            <form method="POST" onsubmit="return confirm('Delete this holiday?')" style="margin-top:0;">
              <input type="hidden" name="holiday_id" value="<?= $edit_holiday['id'] ?>">
              <button type="submit" name="delete_holiday" class="btn btn-danger" style="width:100%;">🗑 Delete Holiday</button>
            </form>
            <?php endif; ?>
          </div>

          <!-- Upcoming -->
          <div class="card">
            <div class="card-header">
              <span class="card-title">📅 Upcoming Holidays</span>
            </div>
            <?php if (count($upcoming) > 0): ?>
            <div class="upcoming-list">
              <?php foreach ($upcoming as $h): ?>
              <?php
                $hdate = new DateTime($h['holiday_date']);
                $now = new DateTime();
                $days_left = $now->diff($hdate)->days;
                $is_today = $h['holiday_date'] === $today;
              ?>
              <div class="upcoming-item">
                <span class="ui-date"><?= $hdate->format('M d, Y') ?></span>
                <span class="ui-name"><?= htmlspecialchars($h['holiday_name']) ?></span>
                <span class="ui-days"><?= $is_today ? 'Today' : ($days_left . 'd left') ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">No upcoming holidays</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Search + Sort + Table -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">📋 All Holidays (<?= $total_count ?>)</span>
          </div>
          <form method="GET" class="search-row">
            <input type="text" name="search" class="search-input" placeholder="🔍 Search holiday name…" value="<?= htmlspecialchars($search) ?>">
            <select name="sort" class="sort-select" onchange="this.form.submit()">
              <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>Date ↑</option>
              <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>Date ↓</option>
              <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
              <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
            </select>
            <?php if ($search): ?>
            <a href="public_holidays.php" class="btn btn-ghost btn-sm">Clear</a>
            <?php endif; ?>
          </form>

          <?php if (count($holidays) > 0): ?>
          <div style="overflow-x:auto;">
            <table>
              <thead>
                <tr><th>Date</th><th>Holiday</th><th>Status</th><th style="width:60px;"></th></tr>
              </thead>
              <tbody>
                <?php foreach ($holidays as $h): ?>
                <?php $is_past = $h['holiday_date'] < $today; ?>
                <tr class="<?= $is_past ? 'past-badge' : '' ?>">
                  <td style="font-family:var(--font-mono);font-size:13px;font-weight:600;"><?= date('M d, Y', strtotime($h['holiday_date'])) ?></td>
                  <td>
                    <span class="holiday-badge <?= $is_past ? 'past-badge' : '' ?>">🎌 <?= htmlspecialchars($h['holiday_name']) ?></span>
                  </td>
                  <td style="font-size:11px;font-weight:600;color:<?= $is_past ? 'var(--muted)' : 'var(--green-status)' ?>;"><?= $is_past ? 'Past' : 'Upcoming' ?></td>
                  <td>
                    <a href="?edit=<?= $h['id'] ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $sort !== 'date_asc' ? '&sort=' . $sort : '' ?>" class="btn btn-ghost btn-sm">Edit</a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <div class="empty-state"><?= $search ? 'No holidays matching "' . htmlspecialchars($search) . '"' : 'No holidays added yet' ?></div>
          <?php endif; ?>
        </div>

      </div>
    </main>
  </div>

  <?php include 'boss_bottom_nav.php'; ?>

  <script>
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