<?php
/**
 * System Logs & Audit Trail
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Displays system logs, user actions, and audit trail for security monitoring
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admin can access
role_guard(['admin']);

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filters
$log_type = $_GET['log_type'] ?? '';
$user_filter = $_GET['user_id'] ?? '';
$date_filter = $_GET['date'] ?? '';

// Build query
$where_conditions = [];
$params = [];

if ($log_type) {
    $where_conditions[] = "sl.action LIKE ?";
    $params[] = "%$log_type%";
}
if ($user_filter) {
    $where_conditions[] = "sl.user_id = ?";
    $params[] = $user_filter;
}
if ($date_filter) {
    $where_conditions[] = "DATE(sl.created_at) = ?";
    $params[] = $date_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM system_logs sl $where_clause";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_logs = $stmt->fetch()['total'];
$total_pages = ceil($total_logs / $per_page);

// Fetch logs
$sql = "
    SELECT sl.*, u.full_name as user_name, u.role as user_role
    FROM system_logs sl
    LEFT JOIN users u ON sl.user_id = u.id
    $where_clause
    ORDER BY sl.created_at DESC
    LIMIT $offset, $per_page
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get users for filter dropdown
$stmt = $pdo->query("SELECT id, full_name, role FROM users ORDER BY full_name");
$users = $stmt->fetchAll();

// Get log type statistics
$stmt = $pdo->query("
    SELECT 
        SUBSTRING_INDEX(action, ' ', 1) as action_type,
        COUNT(*) as count
    FROM system_logs
    GROUP BY action_type
    ORDER BY count DESC
    LIMIT 8
");
$action_stats = $stmt->fetchAll();

$page_title = 'System Logs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Logs — DisasterResponse</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* ═══ TOKENS ══════════════════════════════════════════════════ */
:root {
  --bg:         #f0f2f5;
  --surface:    #ffffff;
  --surface-2:  #f7f8fa;
  --border:     #e2e5ea;
  --border-2:   #d0d4db;

  --navy:       #0f1b2d;
  --navy-2:     #1a2b42;

  --red:        #e8271d;
  --red-light:  #fff0ef;
  --amber:      #d97706;
  --amber-light:#fffbeb;
  --blue:       #1d6ef5;
  --blue-light: #eff5ff;
  --green:      #16a34a;
  --green-light:#f0fdf4;
  --teal:       #0891b2;
  --teal-light: #ecfeff;
  --purple:     #7c3aed;
  --purple-light:#f5f3ff;

  --text:       #0f1b2d;
  --text-2:     #374151;
  --muted:      #6b7280;
  --muted-2:    #9ca3af;

  --ff-head: 'Barlow Condensed', sans-serif;
  --ff-body: 'Barlow', sans-serif;
  --ff-mono: 'IBM Plex Mono', monospace;

  --r:       8px;
  --r-lg:    12px;
  --shadow:  0 1px 3px rgba(15,27,45,.08), 0 4px 16px rgba(15,27,45,.06);
  --shadow-lg:0 4px 24px rgba(15,27,45,.12);
  --ease:    .18s cubic-bezier(.4,0,.2,1);
}

*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }
body { font-family: var(--ff-body); background: var(--bg); color: var(--text); font-size: 14px; min-height: 100vh; }

::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-2); border-radius: 4px; }

/* ─── TOPBAR ─────────────────────────────────────────────── */
.topbar {
  background: var(--navy);
  height: 54px;
  display: flex; align-items: stretch;
  position: sticky; top: 0; z-index: 300;
  box-shadow: 0 2px 12px rgba(15,27,45,.35);
}
.brand {
  display: flex; align-items: center; gap: .5rem;
  padding: 0 2rem 0 1.25rem;
  background: var(--red);
  text-decoration: none;
  clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 100%, 0 100%);
  flex-shrink: 0;
}
.brand-text { font-family: var(--ff-head); font-weight: 800; font-size: 1.1rem; color: #fff; text-transform: uppercase; letter-spacing: .03em; }
.brand-sub  { font-family: var(--ff-mono); font-size: .5rem; font-weight: 600; color: rgba(255,255,255,.65); letter-spacing: .12em; text-transform: uppercase; display: block; margin-top: -2px; }
.nav-area {
  display: flex; align-items: center; padding: 0 .75rem; gap: .1rem; flex: 1;
  overflow-x: auto;
}
.nav-area::-webkit-scrollbar { height:0; }
.npill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .3rem .75rem; border-radius: 5px;
  color: rgba(255,255,255,.6); font-size: .78rem; font-weight: 500;
  text-decoration: none; white-space: nowrap;
  transition: all var(--ease);
}
.npill:hover { color: #fff; background: rgba(255,255,255,.1); }
.npill.active { color: #fff; background: rgba(255,255,255,.15); }
.npill i { font-size: .85rem; }
.logout-btn {
  display: flex; align-items: center; gap: .3rem;
  margin: auto 1.25rem;
  padding: .3rem .7rem; border-radius: 5px;
  border: 1px solid rgba(232,39,29,.4); background: rgba(232,39,29,.12);
  color: #ff7a74; font-size: .75rem; font-weight: 600;
  text-decoration: none; transition: all var(--ease); white-space: nowrap;
}
.logout-btn:hover { background: var(--red); color: #fff; border-color: var(--red); }

/* ─── HERO HEADER ─────────────────────────────────────────── */
.page-hero {
  background: var(--navy);
  padding: 1.4rem 0;
  border-bottom: 3px solid var(--red);
  position: relative; overflow: hidden;
}
.page-hero::before {
  content: '';
  position: absolute; right: -60px; top: -60px;
  width: 280px; height: 280px;
  background: radial-gradient(circle, rgba(232,39,29,.12) 0%, transparent 65%);
  pointer-events: none;
}
.hero-eyebrow {
  font-family: var(--ff-mono); font-size: .62rem; font-weight: 600;
  letter-spacing: .16em; text-transform: uppercase;
  color: var(--red); margin-bottom: .3rem;
}
.hero-title {
  font-family: var(--ff-head); font-weight: 800; font-size: 1.8rem;
  color: #fff; letter-spacing: .02em; text-transform: uppercase; line-height: 1.1;
}
.hero-sub { color: rgba(255,255,255,.45); font-size: .8rem; margin-top: .25rem; font-family: var(--ff-mono); }

/* ─── PAGE ────────────────────────────────────────────────── */
.page { max-width: 1480px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }

/* ─── FILTER BAR ──────────────────────────────────────────── */
.filter-bar {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: .9rem 1.25rem;
  margin-bottom: 1.5rem;
  box-shadow: var(--shadow);
  display: flex; align-items: flex-end; gap: 1rem; flex-wrap: wrap;
}
.filter-bar label {
  font-family: var(--ff-head); font-size: .68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .1em; color: var(--muted);
  display: block; margin-bottom: .3rem;
}
.filter-bar select,
.filter-bar input[type="date"] {
  font-family: var(--ff-mono); font-size: .78rem;
  background: var(--surface-2); color: var(--text);
  border: 1px solid var(--border); border-radius: var(--r);
  padding: .4rem .75rem; outline: none;
  transition: border-color var(--ease);
}
.filter-bar select:focus,
.filter-bar input:focus { border-color: var(--blue); }
.filter-btn {
  padding: .42rem 1.1rem;
  background: var(--navy); color: #fff;
  border: none; border-radius: var(--r);
  font-family: var(--ff-head); font-size: .8rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em;
  cursor: pointer; transition: all var(--ease);
}
.filter-btn:hover { background: var(--red); }
.reset-btn {
  padding: .42rem 1rem;
  background: transparent; color: var(--muted);
  border: 1px solid var(--border); border-radius: var(--r);
  font-family: var(--ff-body); font-size: .78rem; font-weight: 500;
  cursor: pointer; transition: all var(--ease);
  display: flex; align-items: center; gap: .35rem;
  text-decoration: none;
}
.reset-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--surface-2); }

/* ─── STAT CARDS ──────────────────────────────────────────── */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: .85rem;
  margin-bottom: 1.5rem;
}
@media (max-width: 900px) { .kpi-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 500px) { .kpi-grid { grid-template-columns: 1fr 1fr; } }

.kpi {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 1rem 1rem;
  box-shadow: var(--shadow);
  position: relative; overflow: hidden;
  transition: transform var(--ease), box-shadow var(--ease);
  text-align: center;
}
.kpi::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 3px;
}
.kpi:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }

.kpi-red::before    { background: var(--red); }
.kpi-amber::before  { background: var(--amber); }
.kpi-blue::before   { background: var(--blue); }
.kpi-green::before  { background: var(--green); }
.kpi-purple::before { background: var(--purple); }
.kpi-teal::before   { background: var(--teal); }

.kpi-num {
  font-family: var(--ff-head); font-size: 1.6rem; font-weight: 800;
  line-height: 1; letter-spacing: -.01em;
}
.kpi-red    .kpi-num { color: var(--red); }
.kpi-amber  .kpi-num { color: var(--amber); }
.kpi-blue   .kpi-num { color: var(--blue); }
.kpi-green  .kpi-num { color: var(--green); }
.kpi-purple .kpi-num { color: var(--purple); }
.kpi-teal   .kpi-num { color: var(--teal); }

.kpi-lbl { font-size: .67rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-top: .2rem; }

/* ─── SECTION LABEL ───────────────────────────────────────── */
.sec { display: flex; align-items: center; gap: .6rem; margin-bottom: .85rem; }
.sec-icon { width: 26px; height: 26px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: .78rem; }
.sec-title { font-family: var(--ff-head); font-size: .78rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--muted); }
.sec-line { flex:1; height:1px; background: var(--border); }

/* ─── LOGS TABLE ──────────────────────────────────────────── */
.log-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow);
  overflow: hidden;
  margin-bottom: 1.1rem;
}
.log-card-hd {
  display: flex; align-items: center; justify-content: space-between;
  padding: .8rem 1.25rem;
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
}
.log-card-hd-left { display: flex; align-items: center; gap: .55rem; }
.log-card-hd-icon {
  width: 28px; height: 28px; border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  font-size: .85rem;
}
.log-card-title {
  font-family: var(--ff-head); font-size: .82rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .1em; color: var(--text-2);
}
.log-count {
  font-family: var(--ff-mono); font-size: .68rem; color: var(--muted);
}

/* Log Entry */
.log-entry {
  padding: .85rem 1.25rem;
  border-bottom: 1px solid var(--border);
  transition: background var(--ease);
}
.log-entry:last-child { border-bottom: none; }
.log-entry:hover { background: #f4f6fb; }

.log-icon {
  width: 36px; height: 36px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem;
  flex-shrink: 0;
}
.log-icon-login    { background: var(--green-light);  color: var(--green); }
.log-icon-logout   { background: var(--amber-light);  color: var(--amber); }
.log-icon-create   { background: var(--blue-light);   color: var(--blue); }
.log-icon-update   { background: var(--blue-light);   color: var(--blue); }
.log-icon-delete   { background: var(--red-light);    color: var(--red); }
.log-icon-verify   { background: var(--green-light);  color: var(--green); }
.log-icon-export   { background: var(--purple-light); color: var(--purple); }
.log-icon-default  { background: var(--surface-2);    color: var(--muted); }

.log-action {
  font-weight: 600; font-size: .85rem; color: var(--text);
  margin-bottom: .2rem;
}
.log-meta {
  font-size: .72rem; color: var(--muted-2);
  font-family: var(--ff-mono);
}
.log-user {
  display: inline-flex; align-items: center; gap: .25rem;
  padding: .15rem .5rem;
  background: var(--surface-2);
  border-radius: 4px;
  font-size: .7rem;
  font-weight: 500;
  margin-right: .5rem;
}
.log-time {
  font-family: var(--ff-mono); font-size: .7rem; color: var(--muted);
  white-space: nowrap;
}
.log-ip {
  font-family: var(--ff-mono); font-size: .65rem; color: var(--muted);
}
.log-agent {
  font-size: .68rem; color: var(--muted-2);
  margin-top: .5rem;
  padding-top: .4rem;
  border-top: 1px solid var(--border);
}

/* Pagination */
.pagination .page-link {
  background: var(--surface);
  border-color: var(--border);
  color: var(--muted);
  font-family: var(--ff-mono);
  font-size: .75rem;
  padding: .4rem .8rem;
  margin: 0 2px;
  border-radius: 6px;
}
.pagination .active .page-link {
  background: var(--red);
  border-color: var(--red);
  color: #fff;
}
.pagination .page-link:hover {
  background: var(--navy);
  color: #fff;
  border-color: var(--navy);
}

/* Empty State */
.empty-state {
  text-align: center; padding: 3rem 1rem; color: var(--muted-2);
}
.empty-state i { font-size: 2.5rem; display: block; margin-bottom: .75rem; opacity: .3; }

@keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
.log-card { animation: fadeUp .4s ease both; }

/* Responsive */
@media (max-width: 768px) {
  .hero-title { font-size: 1.35rem; }
  .kpi-grid { grid-template-columns: repeat(2,1fr); }
  .log-entry { flex-direction: column; gap: 0.75rem; }
  .log-icon { width: 32px; height: 32px; font-size: .9rem; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar no-print">
  <a class="brand" href="dashboard.php">
    <i class="bi bi-journal-bookmark-fill" style="color:#fff;font-size:1.1rem"></i>
    <div>
      <span class="brand-text">Disaster<span style="opacity:.7">Response</span></span>
      <span class="brand-sub">Admin Console</span>
    </div>
  </a>
  <div class="nav-area">
    <a href="dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="analytics.php" class="npill"><i class="bi bi-graph-up"></i> Analytics</a>
    <a href="export.php" class="npill"><i class="bi bi-download"></i> Export</a>
    <a href="system_logs.php" class="npill active"><i class="bi bi-journal-bookmark-fill"></i> Logs</a>
  </div>
  <a href="../auth/logout.php" class="logout-btn no-print" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</nav>

<!-- PAGE HERO -->
<div class="page-hero no-print">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-shield-lock-fill me-1"></i>Security &amp; Audit</div>
    <div class="hero-title">System Logs &amp; Audit Trail</div>
    <div class="hero-sub">Track user actions and system events for security monitoring</div>
  </div>
</div>

<!-- PAGE -->
<div class="page">

  <!-- FILTER BAR -->
  <div class="filter-bar no-print">
    <form method="GET" style="display:contents">
      <div>
        <label>Log Type</label>
        <select name="log_type" class="form-select">
          <option value="">All Actions</option>
          <option value="login" <?= $log_type == 'login' ? 'selected' : '' ?>>Login</option>
          <option value="logout" <?= $log_type == 'logout' ? 'selected' : '' ?>>Logout</option>
          <option value="create" <?= $log_type == 'create' ? 'selected' : '' ?>>Create</option>
          <option value="update" <?= $log_type == 'update' ? 'selected' : '' ?>>Update</option>
          <option value="delete" <?= $log_type == 'delete' ? 'selected' : '' ?>>Delete</option>
          <option value="verify" <?= $log_type == 'verify' ? 'selected' : '' ?>>Verify</option>
          <option value="export" <?= $log_type == 'export' ? 'selected' : '' ?>>Export</option>
        </select>
      </div>
      <div>
        <label>User</label>
        <select name="user_id" class="form-select">
          <option value="">All Users</option>
          <?php foreach ($users as $user): ?>
            <option value="<?= $user['id'] ?>" <?= $user_filter == $user['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($user['full_name']) ?> (<?= $user['role'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Date</label>
        <input type="date" name="date" class="form-control" value="<?= $date_filter ?>">
      </div>
      <button type="submit" class="filter-btn"><i class="bi bi-funnel me-1"></i>Apply</button>
    </form>
    <a href="system_logs.php" class="reset-btn"><i class="bi bi-arrow-repeat me-1"></i>Reset</a>
  </div>

  <!-- KPI Grid - Action Statistics -->
  <div class="kpi-grid">
    <?php foreach ($action_stats as $stat): 
      $color_class = match($stat['action_type']) {
        'login' => 'green', 'logout' => 'amber', 'create' => 'blue', 
        'update' => 'teal', 'delete' => 'red', 'verify' => 'green', 
        'export' => 'purple', default => 'blue'
      };
    ?>
    <div class="kpi kpi-<?= $color_class ?>">
      <div class="kpi-num"><?= number_format($stat['count']) ?></div>
      <div class="kpi-lbl"><?= ucfirst($stat['action_type']) ?> Events</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Logs Section -->
  <div class="sec">
    <span class="sec-icon" style="background:var(--blue-light);color:var(--blue)"><i class="bi bi-journal-bookmark-fill"></i></span>
    <span class="sec-title">Audit Trail</span>
    <div class="sec-line"></div>
  </div>

  <div class="log-card">
    <div class="log-card-hd">
      <div class="log-card-hd-left">
        <span class="log-card-hd-icon" style="background:var(--blue-light);color:var(--blue)"><i class="bi bi-clock-history"></i></span>
        <span class="log-card-title">Event Log</span>
      </div>
      <div class="log-count"><?= number_format($total_logs) ?> total entries</div>
    </div>

    <?php if (count($logs) > 0): ?>
      <?php foreach ($logs as $log): 
        // Determine icon class based on action
        $icon_class = 'default';
        $icon_icon = 'bi-question-circle';
        if (strpos($log['action'], 'login') !== false) {
          $icon_class = 'login';
          $icon_icon = 'bi-box-arrow-in-right';
        } elseif (strpos($log['action'], 'logout') !== false) {
          $icon_class = 'logout';
          $icon_icon = 'bi-box-arrow-right';
        } elseif (strpos($log['action'], 'create') !== false || strpos($log['action'], 'added') !== false) {
          $icon_class = 'create';
          $icon_icon = 'bi-plus-circle';
        } elseif (strpos($log['action'], 'update') !== false || strpos($log['action'], 'edited') !== false) {
          $icon_class = 'update';
          $icon_icon = 'bi-pencil-square';
        } elseif (strpos($log['action'], 'delete') !== false || strpos($log['action'], 'removed') !== false) {
          $icon_class = 'delete';
          $icon_icon = 'bi-trash';
        } elseif (strpos($log['action'], 'verify') !== false) {
          $icon_class = 'verify';
          $icon_icon = 'bi-check2-circle';
        } elseif (strpos($log['action'], 'export') !== false) {
          $icon_class = 'export';
          $icon_icon = 'bi-download';
        }
      ?>
        <div class="log-entry">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div class="d-flex gap-3">
              <div class="log-icon log-icon-<?= $icon_class ?>">
                <i class="bi <?= $icon_icon ?>"></i>
              </div>
              <div>
                <div class="log-action"><?= htmlspecialchars($log['action']) ?></div>
                <div class="log-meta">
                  <?php if ($log['user_name']): ?>
                    <span class="log-user">
                      <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($log['user_name']) ?>
                      <span class="badge bg-secondary ms-1" style="font-size:0.6rem;"><?= $log['user_role'] ?></span>
                    </span>
                  <?php else: ?>
                    <span class="log-user">
                      <i class="bi bi-person-x me-1"></i>System / Unknown
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="text-end">
              <div class="log-time">
                <i class="bi bi-clock me-1"></i><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?>
              </div>
              <?php if ($log['ip_address']): ?>
                <div class="log-ip">
                  <i class="bi bi-wifi me-1"></i><?= $log['ip_address'] ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($log['user_agent']): ?>
            <div class="log-agent">
              <i class="bi bi-browser-chrome me-1"></i><?= htmlspecialchars(substr($log['user_agent'], 0, 120)) ?>...
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      
      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
      <div class="d-flex justify-content-center py-3">
        <nav>
          <ul class="pagination mb-0">
            <?php if ($page > 1): ?>
              <li class="page-item">
                <a class="page-link" href="?page=<?= $page - 1 ?>&log_type=<?= urlencode($log_type) ?>&user_id=<?= urlencode($user_filter) ?>&date=<?= urlencode($date_filter) ?>">
                  <i class="bi bi-chevron-left"></i>
                </a>
              </li>
            <?php endif; ?>
            
            <?php 
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            for ($i = $start_page; $i <= $end_page; $i++): ?>
              <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $i ?>&log_type=<?= urlencode($log_type) ?>&user_id=<?= urlencode($user_filter) ?>&date=<?= urlencode($date_filter) ?>">
                  <?= $i ?>
                </a>
              </li>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
              <li class="page-item">
                <a class="page-link" href="?page=<?= $page + 1 ?>&log_type=<?= urlencode($log_type) ?>&user_id=<?= urlencode($user_filter) ?>&date=<?= urlencode($date_filter) ?>">
                  <i class="bi bi-chevron-right"></i>
                </a>
              </li>
            <?php endif; ?>
          </ul>
        </nav>
      </div>
      <?php endif; ?>
      
    <?php else: ?>
      <div class="empty-state">
        <i class="bi bi-inbox"></i>
        <p>No logs found matching the criteria.</p>
      </div>
    <?php endif; ?>
  </div>

  <div class="text-muted small text-center mt-3">
    <i class="bi bi-info-circle me-1"></i>
    Showing <?= count($logs) ?> of <?= number_format($total_logs) ?> log entries
  </div>

</div><!-- /page -->

</body>
</html>