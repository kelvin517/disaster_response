<?php
/**
 * Manage Resource Requests
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows responders to view and manage pending resource requests
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only responders and admins can manage requests
role_guard(['responder', 'admin']);

// Filters
$status_filter = $_GET['status'] ?? 'pending';
$urgency_filter = $_GET['urgency'] ?? '';
$type_filter = $_GET['type'] ?? '';

// Build query
$where_conditions = ["1=1"];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "rr.status = ?";
    $params[] = $status_filter;
}
if ($urgency_filter) {
    $where_conditions[] = "rr.urgency = ?";
    $params[] = $urgency_filter;
}
if ($type_filter) {
    $where_conditions[] = "rr.resource_type = ?";
    $params[] = $type_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Fetch requests
$sql = "
    SELECT rr.*, u.full_name as requester_name, u.phone as requester_phone, 
           i.incident_type, i.location_name as incident_location
    FROM resource_requests rr
    LEFT JOIN users u ON rr.user_id = u.id
    LEFT JOIN incidents i ON rr.incident_id = i.id
    WHERE $where_clause
    ORDER BY 
        CASE rr.urgency 
            WHEN 'critical' THEN 1 
            WHEN 'high' THEN 2 
            WHEN 'medium' THEN 3 
            WHEN 'low' THEN 4 
        END ASC,
        rr.requested_at ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Get statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned,
        SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) as in_transit,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN urgency = 'critical' THEN 1 ELSE 0 END) as critical,
        SUM(CASE WHEN urgency = 'high' THEN 1 ELSE 0 END) as high
    FROM resource_requests
";
$stats = $pdo->query($stats_sql)->fetch();

$urgency_colors = [
    'critical' => 'danger',
    'high' => 'warning',
    'medium' => 'info',
    'low' => 'success'
];

$resource_types = [
    'food' => '🍲 Food', 'water' => '💧 Water', 'medicine' => '💊 Medicine',
    'shelter' => '🏠 Shelter', 'clothing' => '👕 Clothing', 'blankets' => '🛏️ Blankets',
    'first_aid' => '🩹 First Aid', 'transport' => '🚛 Transport', 'other' => '📦 Other'
];

$page_title = 'Manage Resource Requests';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Resource Requests - DisasterResponse</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
.page { max-width: 1400px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }

/* ─── KPI CARDS ──────────────────────────────────────────── */
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

/* ─── FILTER BAR ──────────────────────────────────────────── */
.filter-bar {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 1rem;
  margin-bottom: 1.5rem;
  box-shadow: var(--shadow);
  display: flex; align-items: flex-end; gap: 1rem; flex-wrap: wrap;
}
.filter-bar label {
  font-family: var(--ff-head); font-size: .68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .1em; color: var(--muted);
  display: block; margin-bottom: .3rem;
}
.filter-bar select {
  font-family: var(--ff-mono); font-size: .78rem;
  background: var(--surface-2); color: var(--text);
  border: 1px solid var(--border); border-radius: var(--r);
  padding: .4rem .75rem; outline: none;
  transition: border-color var(--ease);
}
.filter-bar select:focus { border-color: var(--blue); }
.filter-btn {
  padding: .42rem 1.1rem;
  background: var(--navy); color: #fff;
  border: none; border-radius: var(--r);
  font-family: var(--ff-head); font-size: .8rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em;
  cursor: pointer; transition: all var(--ease);
}
.filter-btn:hover { background: var(--red); }

/* ─── REQUEST CARD ───────────────────────────────────────── */
.request-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  margin-bottom: 1rem;
  transition: all var(--ease);
  box-shadow: var(--shadow);
}
.request-card:hover { transform: translateX(4px); border-color: var(--red); box-shadow: var(--shadow-lg); }

.urgency-critical { border-left: 4px solid #dc3545; }
.urgency-high { border-left: 4px solid #fd7e14; }
.urgency-medium { border-left: 4px solid #ffc107; }
.urgency-low { border-left: 4px solid #28a745; }

.request-body { padding: 1rem; }

.badge-status {
  padding: .25rem .7rem;
  border-radius: 20px;
  font-size: .7rem;
  font-weight: 600;
  display: inline-block;
}

.btn-action {
  background: var(--red);
  border: none;
  border-radius: 30px;
  padding: .3rem 1rem;
  font-size: .7rem;
  font-weight: 600;
  color: #fff;
  text-decoration: none;
  display: inline-block;
  transition: all var(--ease);
}
.btn-action:hover { background: #c82333; transform: translateY(-1px); }

/* ─── EMPTY STATE ─────────────────────────────────────────── */
.empty-state {
  text-align: center; padding: 3rem 1rem; background: var(--surface); border-radius: var(--r-lg); border: 1px solid var(--border);
  color: var(--muted-2);
}
.empty-state i { font-size: 2.5rem; display: block; margin-bottom: .75rem; opacity: .3; }

/* ─── RESPONSIVE ──────────────────────────────────────────── */
@media (max-width: 768px) {
  .hero-title { font-size: 1.35rem; }
  .kpi-num { font-size: 1.2rem; }
  .filter-bar { flex-direction: column; align-items: stretch; }
  .filter-bar select { width: 100%; }
  .filter-btn { width: 100%; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar">
  <a class="brand" href="../responders/dashboard.php">
    <i class="bi bi-list-check" style="color:#fff;font-size:1.1rem"></i>
    <div>
      <span class="brand-text">Disaster<span style="opacity:.7">Response</span></span>
      <span class="brand-sub">Resource Hub</span>
    </div>
  </a>
  <div class="nav-area">
    <a href="manage.php" class="npill active"><i class="bi bi-list-check"></i> Requests</a>
    <a href="inventory.php" class="npill"><i class="bi bi-box-seam"></i> Inventory</a>
    <a href="reports.php" class="npill"><i class="bi bi-graph-up"></i> Reports</a>
  </div>
  <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</nav>

<!-- PAGE HERO -->
<div class="page-hero">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-list-check me-1"></i>Fulfillment Center</div>
    <div class="hero-title">Resource Requests</div>
    <div class="hero-sub">View and fulfill aid requests from affected communities</div>
  </div>
</div>

<!-- PAGE -->
<div class="page">
  
  <!-- KPI Grid -->
  <div class="kpi-grid">
    <div class="kpi"><div class="kpi-num" style="color: #e8271d;"><?= $stats['pending'] ?? 0 ?></div><div class="kpi-lbl">Pending</div></div>
    <div class="kpi"><div class="kpi-num" style="color: #d97706;"><?= $stats['assigned'] ?? 0 ?></div><div class="kpi-lbl">Assigned</div></div>
    <div class="kpi"><div class="kpi-num" style="color: #1d6ef5;"><?= $stats['in_transit'] ?? 0 ?></div><div class="kpi-lbl">In Transit</div></div>
    <div class="kpi"><div class="kpi-num" style="color: #16a34a;"><?= $stats['delivered'] ?? 0 ?></div><div class="kpi-lbl">Delivered</div></div>
    <div class="kpi"><div class="kpi-num" style="color: #e8271d;"><?= $stats['critical'] ?? 0 ?></div><div class="kpi-lbl">Critical</div></div>
    <div class="kpi"><div class="kpi-num" style="color: #fd7e14;"><?= $stats['high'] ?? 0 ?></div><div class="kpi-lbl">High Urgency</div></div>
  </div>

  <!-- Filter Bar -->
  <div class="filter-bar">
    <form method="GET" style="display: contents;">
      <div>
        <label>Status</label>
        <select name="status" class="form-select">
          <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
          <option value="assigned" <?= $status_filter == 'assigned' ? 'selected' : '' ?>>Assigned</option>
          <option value="in_transit" <?= $status_filter == 'in_transit' ? 'selected' : '' ?>>In Transit</option>
          <option value="delivered" <?= $status_filter == 'delivered' ? 'selected' : '' ?>>Delivered</option>
          <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Requests</option>
        </select>
      </div>
      <div>
        <label>Urgency</label>
        <select name="urgency" class="form-select">
          <option value="">All Urgencies</option>
          <option value="critical" <?= $urgency_filter == 'critical' ? 'selected' : '' ?>>Critical</option>
          <option value="high" <?= $urgency_filter == 'high' ? 'selected' : '' ?>>High</option>
          <option value="medium" <?= $urgency_filter == 'medium' ? 'selected' : '' ?>>Medium</option>
          <option value="low" <?= $urgency_filter == 'low' ? 'selected' : '' ?>>Low</option>
        </select>
      </div>
      <div>
        <label>Resource Type</label>
        <select name="type" class="form-select">
          <option value="">All Types</option>
          <?php foreach ($resource_types as $key => $label): ?>
            <option value="<?= $key ?>" <?= $type_filter == $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="filter-btn">Apply Filters</button>
    </form>
  </div>

  <!-- Requests List -->
  <?php if (count($requests) > 0): ?>
    <?php foreach ($requests as $request): ?>
      <div class="request-card urgency-<?= $request['urgency'] ?>">
        <div class="request-body">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
              <div class="d-flex gap-2 flex-wrap mb-2">
                <span class="badge bg-<?= $urgency_colors[$request['urgency']] ?>">
                  <?= ucfirst($request['urgency']) ?> Urgency
                </span>
                <span class="badge bg-secondary"><?= ucfirst(str_replace('_', ' ', $request['status'])) ?></span>
              </div>
              <div>
                <strong><i class="bi bi-box-seam me-1"></i><?= $resource_types[$request['resource_type']] ?? ucfirst($request['resource_type']) ?></strong>
                <span class="text-muted ms-2">x <?= number_format($request['quantity']) ?> units</span>
              </div>
              <div class="small text-muted mt-1">
                <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($request['requester_name'] ?? 'Anonymous') ?>
                <span class="mx-1">•</span>
                <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($request['requester_phone'] ?? 'No phone') ?>
              </div>
              <?php if ($request['location_name']): ?>
                <div class="small text-muted">
                  <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($request['location_name']) ?>
                </div>
              <?php endif; ?>
              <?php if ($request['notes']): ?>
                <div class="small text-muted mt-1">
                  <i class="bi bi-chat-text me-1"></i><?= htmlspecialchars(substr($request['notes'], 0, 100)) ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="text-end">
              <div class="small text-muted">
                <i class="bi bi-clock me-1"></i><?= date('M j, H:i', strtotime($request['requested_at'])) ?>
              </div>
              <?php if ($request['status'] == 'pending'): ?>
                <a href="fulfill.php?id=<?= $request['id'] ?>" class="btn-action d-inline-block mt-2">
                  <i class="bi bi-check-lg me-1"></i>Fulfill
                </a>
              <?php elseif ($request['status'] == 'assigned'): ?>
                <a href="fulfill.php?id=<?= $request['id'] ?>" class="btn-action d-inline-block mt-2">
                  <i class="bi bi-truck me-1"></i>In Transit
                </a>
              <?php elseif ($request['status'] == 'in_transit'): ?>
                <a href="fulfill.php?id=<?= $request['id'] ?>" class="btn-action d-inline-block mt-2">
                  <i class="bi bi-check2-all me-1"></i>Deliver
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="empty-state">
      <i class="bi bi-inbox"></i>
      <p>No resource requests found matching the criteria.</p>
    </div>
  <?php endif; ?>

</div>

</body>
</html>