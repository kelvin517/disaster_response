<?php
/**
 * User Management
 * Disaster Response & Resource Coordination System
 * Admin only - Manage system users
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

role_guard(['admin']);

// Handle user status toggle
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ? AND role != 'admin'");
    $stmt->execute([$user_id]);
    redirect('users.php');
}

// Handle user deletion
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
    $stmt->execute([$user_id]);
    $_SESSION['success'] = "User deleted successfully.";
    redirect('users.php');
}

// Fetch all users
$stmt = $pdo->prepare("
    SELECT id, full_name, email, phone, role, is_active, created_at, last_login
    FROM users
    ORDER BY created_at DESC
");
$stmt->execute();
$users = $stmt->fetchAll();

// Get user statistics
$admin_count = 0;
$responder_count = 0;
$volunteer_count = 0;
$victim_count = 0;
$active_count = 0;

foreach ($users as $user) {
    switch($user['role']) {
        case 'admin': $admin_count++; break;
        case 'responder': $responder_count++; break;
        case 'volunteer': $volunteer_count++; break;
        default: $victim_count++;
    }
    if ($user['is_active']) $active_count++;
}

$page_title = 'User Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Management — DisasterResponse</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
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
.page { max-width: 1400px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }

/* ─── STAT CARDS ──────────────────────────────────────────── */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: .85rem;
  margin-bottom: 1.5rem;
}
@media (max-width: 900px) { .kpi-grid { grid-template-columns: repeat(3,1fr); } }
@media (max-width: 500px) { .kpi-grid { grid-template-columns: repeat(2,1fr); } }

.kpi {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 1rem;
  text-align: center;
  box-shadow: var(--shadow);
  transition: all var(--ease);
}
.kpi::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: var(--red);
  border-radius: var(--r-lg) var(--r-lg) 0 0;
}
.kpi:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
.kpi-num { font-family: var(--ff-head); font-size: 1.6rem; font-weight: 800; line-height: 1; }
.kpi-lbl { font-size: .67rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-top: .2rem; }

/* ─── USER TABLE CARD ─────────────────────────────────────── */
.user-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow);
  overflow: hidden;
}
.user-header {
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  padding: 0.85rem 1.25rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.user-header-title {
  font-family: var(--ff-head);
  font-size: 0.85rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--text-2);
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.user-header-title i { color: var(--red); }
.user-count {
  font-family: var(--ff-mono);
  font-size: 0.7rem;
  color: var(--muted);
  background: var(--surface);
  padding: 0.2rem 0.6rem;
  border-radius: 20px;
}

/* User Table */
.user-table {
  width: 100%;
  border-collapse: collapse;
}
.user-table thead tr {
  background: var(--surface-2);
  border-bottom: 1px solid var(--border-2);
}
.user-table thead th {
  padding: 0.8rem 1rem;
  font-family: var(--ff-head);
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--muted);
  white-space: nowrap;
}
.user-table tbody tr {
  border-bottom: 1px solid var(--border);
  transition: background var(--ease);
}
.user-table tbody tr:hover { background: var(--surface-2); }
.user-table td {
  padding: 0.9rem 1rem;
  font-size: 0.82rem;
  color: var(--text-2);
  vertical-align: middle;
}
.user-name {
  font-weight: 600;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.user-avatar {
  width: 32px;
  height: 32px;
  background: var(--blue-light);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--blue);
  font-size: 0.9rem;
}
.user-email {
  font-family: var(--ff-mono);
  font-size: 0.73rem;
  color: var(--muted-2);
}
.user-phone {
  font-family: var(--ff-mono);
  font-size: 0.73rem;
  color: var(--muted-2);
  white-space: nowrap;
}
.user-joined {
  font-family: var(--ff-mono);
  font-size: 0.71rem;
  color: var(--muted);
  white-space: nowrap;
}

/* Role Badge */
.role-badge {
  display: inline-block;
  padding: 0.2rem 0.7rem;
  border-radius: 4px;
  font-size: 0.65rem;
  font-weight: 700;
  font-family: var(--ff-mono);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.role-admin     { background: rgba(232,39,29,0.12); color: #e8271d; border: 1px solid rgba(232,39,29,0.25); }
.role-responder { background: rgba(6,182,212,0.12); color: #0891b2; border: 1px solid rgba(6,182,212,0.25); }
.role-volunteer { background: rgba(34,197,94,0.12); color: #16a34a; border: 1px solid rgba(34,197,94,0.25); }
.role-victim    { background: rgba(167,139,250,0.12); color: #7c3aed; border: 1px solid rgba(167,139,250,0.25); }

/* Status Badge */
.status-badge {
  display: inline-block;
  padding: 0.2rem 0.6rem;
  border-radius: 20px;
  font-size: 0.65rem;
  font-weight: 600;
}
.status-active { background: rgba(22,163,74,0.12); color: #16a34a; border: 1px solid rgba(22,163,74,0.25); }
.status-inactive { background: rgba(107,114,128,0.12); color: #6b7280; border: 1px solid rgba(107,114,128,0.25); }

/* Action Buttons */
.btn-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: transparent;
  color: var(--muted);
  transition: all var(--ease);
  margin: 0 2px;
  text-decoration: none;
}
.btn-icon:hover {
  border-color: var(--red);
  color: var(--red);
  background: var(--red-light);
}
.btn-icon.danger:hover {
  border-color: var(--red);
  color: var(--red);
  background: var(--red-light);
}
.btn-icon i { font-size: 0.9rem; }

/* Empty State */
.empty-state {
  text-align: center;
  padding: 3rem;
  color: var(--muted-2);
}
.empty-state i { font-size: 2.5rem; display: block; margin-bottom: 0.75rem; opacity: 0.3; }

/* Flash Message */
.flash-message {
  position: fixed;
  top: 70px;
  right: 20px;
  z-index: 1000;
  background: var(--surface);
  border-left: 4px solid var(--green);
  border-radius: var(--r);
  padding: 0.8rem 1rem;
  box-shadow: var(--shadow-lg);
  animation: slideIn 0.3s ease-out;
}
@keyframes slideIn {
  from { transform: translateX(100%); opacity: 0; }
  to { transform: translateX(0); opacity: 1; }
}

@media (max-width: 768px) {
  .hero-title { font-size: 1.35rem; }
  .user-table thead th { font-size: 0.6rem; padding: 0.5rem; }
  .user-table td { padding: 0.6rem 0.5rem; font-size: 0.75rem; }
  .user-name { font-size: 0.8rem; }
  .btn-icon { width: 26px; height: 26px; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar">
  <a class="brand" href="admin_dashboard.php">
    <i class="bi bi-shield-lock-fill" style="color:#fff;font-size:1.1rem"></i>
    <div>
      <span class="brand-text">Disaster<span style="opacity:.7">Response</span></span>
      <span class="brand-sub">Admin Console</span>
    </div>
  </a>
  <div class="nav-area">
    <a href="admin_dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="analytics.php" class="npill"><i class="bi bi-graph-up"></i> Analytics</a>
    <a href="export.php" class="npill"><i class="bi bi-download"></i> Export</a>
    <a href="system_logs.php" class="npill"><i class="bi bi-journal-bookmark-fill"></i> Logs</a>
    <a href="users.php" class="npill active"><i class="bi bi-people"></i> Users</a>
  </div>
  <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</nav>

<!-- PAGE HERO -->
<div class="page-hero">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-people-fill me-1"></i>User Administration</div>
    <div class="hero-title">User Management</div>
    <div class="hero-sub">Manage system users, roles, and account status</div>
  </div>
</div>

<!-- PAGE -->
<div class="page">

  <!-- Flash Message -->
  <?php if (isset($_SESSION['success'])): ?>
    <div class="flash-message" id="flashMessage">
      <i class="bi bi-check-circle-fill me-2" style="color: var(--green);"></i>
      <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
    </div>
    <script>setTimeout(() => { const el = document.getElementById('flashMessage'); if(el) el.remove(); }, 3000);</script>
  <?php endif; ?>

  <!-- KPI Grid -->
  <div class="kpi-grid">
    <div class="kpi"><div class="kpi-num" style="color: var(--blue);"><?= count($users) ?></div><div class="kpi-lbl">Total Users</div></div>
    <div class="kpi"><div class="kpi-num" style="color: var(--green);"><?= $active_count ?></div><div class="kpi-lbl">Active</div></div>
    <div class="kpi"><div class="kpi-num" style="color: var(--red);"><?= $admin_count ?></div><div class="kpi-lbl">Admins</div></div>
    <div class="kpi"><div class="kpi-num" style="color: var(--teal);"><?= $responder_count ?></div><div class="kpi-lbl">Responders</div></div>
    <div class="kpi"><div class="kpi-num" style="color: var(--green);"><?= $volunteer_count ?></div><div class="kpi-lbl">Volunteers</div></div>
    <div class="kpi"><div class="kpi-num" style="color: var(--purple);"><?= $victim_count ?></div><div class="kpi-lbl">Victims</div></div>
  </div>

  <!-- User Table Card -->
  <div class="user-card">
    <div class="user-header">
      <div class="user-header-title">
        <i class="bi bi-people-fill"></i>
        Registered Users
      </div>
      <div class="user-count">
        <i class="bi bi-database me-1"></i><?= count($users) ?> records
      </div>
    </div>
    <div class="table-responsive">
      <table class="user-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Contact</th>
            <th>Role</th>
            <th>Status</th>
            <th>Joined</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($users) > 0): ?>
            <?php foreach ($users as $user): ?>
              <tr>
                <td>
                  <div class="user-name">
                    <div class="user-avatar">
                      <i class="bi bi-person-fill"></i>
                    </div>
                    <?= htmlspecialchars($user['full_name']) ?>
                  </div>
                 </td>
                <td>
                  <div class="user-email"><?= htmlspecialchars($user['email']) ?></div>
                  <div class="user-phone"><?= htmlspecialchars($user['phone'] ?? '—') ?></div>
                </td>
                <td>
                  <span class="role-badge role-<?= $user['role'] ?>">
                    <?= ucfirst($user['role']) ?>
                  </span>
                </td>
                <td>
                  <span class="status-badge <?= $user['is_active'] ? 'status-active' : 'status-inactive' ?>">
                    <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                  </span>
                </td>
                <td>
                  <span class="user-joined">
                    <i class="bi bi-calendar3 me-1"></i><?= date('M j, Y', strtotime($user['created_at'])) ?>
                  </span>
                </td>
                <td>
                  <?php if ($user['role'] != 'admin'): ?>
                    <a href="?toggle_status=1&id=<?= $user['id'] ?>" class="btn-icon" title="<?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>">
                      <i class="bi bi-<?= $user['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                    </a>
                    <a href="?delete=1&id=<?= $user['id'] ?>" class="btn-icon danger" onclick="return confirm('Delete this user permanently?')" title="Delete">
                      <i class="bi bi-trash3"></i>
                    </a>
                  <?php else: ?>
                    <span class="text-muted small">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6">
                <div class="empty-state">
                  <i class="bi bi-people"></i>
                  <p>No users found.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>