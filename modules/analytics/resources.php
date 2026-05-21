<?php
/**
 * Resource Analytics Dashboard
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Analytics for resource requests, utilization, and distribution
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admins and responders can access
role_guard(['admin', 'responder']);

// Date range filters
$date_range = $_GET['range'] ?? '30';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime("-{$date_range} days"));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

if ($date_range !== 'custom') {
    $start_date = date('Y-m-d', strtotime("-{$date_range} days"));
    $end_date = date('Y-m-d');
}

$range_label = $date_range === 'custom' ? "$start_date → $end_date" : "Last $date_range days";

// ============================================
// RESOURCE REQUEST STATISTICS
// ============================================

$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) as in_transit,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN urgency = 'critical' THEN 1 ELSE 0 END) as critical_urgent,
        SUM(CASE WHEN urgency = 'high' THEN 1 ELSE 0 END) as high_urgent,
        SUM(quantity) as total_quantity_requested,
        SUM(CASE WHEN status = 'delivered' THEN quantity ELSE 0 END) as total_delivered
    FROM resource_requests
    WHERE DATE(requested_at) BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$overview = $stmt->fetch();

// ============================================
// RESOURCE TYPE DISTRIBUTION
// ============================================

$stmt = $pdo->prepare("
    SELECT 
        resource_type,
        COUNT(*) as request_count,
        SUM(quantity) as total_quantity,
        SUM(CASE WHEN status = 'delivered' THEN quantity ELSE 0 END) as delivered_quantity,
        ROUND(AVG(CASE 
            WHEN urgency = 'critical' THEN 4
            WHEN urgency = 'high' THEN 3
            WHEN urgency = 'medium' THEN 2
            ELSE 1
        END), 1) as avg_urgency_score
    FROM resource_requests
    WHERE DATE(requested_at) BETWEEN ? AND ?
    GROUP BY resource_type
    ORDER BY request_count DESC
");
$stmt->execute([$start_date, $end_date]);
$type_distribution = $stmt->fetchAll();

// ============================================
// REQUEST TRENDS
// ============================================

$stmt = $pdo->prepare("
    SELECT 
        DATE(requested_at) as date,
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(quantity) as quantity_requested
    FROM resource_requests
    WHERE DATE(requested_at) BETWEEN ? AND ?
    GROUP BY DATE(requested_at)
    ORDER BY date ASC
");
$stmt->execute([$start_date, $end_date]);
$trends = $stmt->fetchAll();

$trend_dates = [];
$trend_requests = [];
$trend_delivered = [];
$trend_quantity = [];
foreach ($trends as $trend) {
    $trend_dates[] = date('M j', strtotime($trend['date']));
    $trend_requests[] = $trend['total_requests'];
    $trend_delivered[] = $trend['delivered'];
    $trend_quantity[] = $trend['quantity_requested'];
}

// ============================================
// URGENCY DISTRIBUTION
// ============================================

$stmt = $pdo->prepare("
    SELECT 
        urgency,
        COUNT(*) as count,
        ROUND(AVG(TIMESTAMPDIFF(HOUR, requested_at, 
            CASE WHEN status = 'delivered' THEN updated_at ELSE NOW() END))) as avg_response_hours
    FROM resource_requests
    WHERE DATE(requested_at) BETWEEN ? AND ?
    GROUP BY urgency
");
$stmt->execute([$start_date, $end_date]);
$urgency_stats = $stmt->fetchAll();

$urgency_labels = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
$urgency_counts = [];
$urgency_response = [];
$urgency_colors = ['#e8271d', '#d97706', '#1d6ef5', '#16a34a'];
foreach ($urgency_stats as $i => $stat) {
    $urgency_counts[] = $stat['count'];
    $urgency_response[] = $stat['avg_response_hours'] ?? 0;
}

// ============================================
// FULFILLMENT RATE BY RESOURCE TYPE
// ============================================

$fulfillment_data = [];
foreach ($type_distribution as $type) {
    $fulfillment_data[] = [
        'type' => $type['resource_type'],
        'rate' => $type['total_quantity'] > 0 ? round(($type['delivered_quantity'] / $type['total_quantity']) * 100) : 0,
        'count' => $type['request_count'],
        'urg_score' => $type['avg_urgency_score']
    ];
}

// Calculate total delivered quantity
$total_delivered_qty = array_sum(array_column($type_distribution, 'delivered_quantity'));

// Top resource types by request count
$top_resource_types = array_slice($type_distribution, 0, 8);
$max_requests = $top_resource_types[0]['request_count'] ?? 1;

$page_title = 'Resource Analytics';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resource Analytics — DisasterResponse</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
  --orange:     #ea580c;
  --orange-light:#fff7ed;

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
.hero-range-chip {
  display: inline-flex; align-items: center; gap: .4rem;
  background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12);
  border-radius: 20px; padding: .25rem .85rem;
  font-family: var(--ff-mono); font-size: .68rem; color: rgba(255,255,255,.65);
  margin-top: .8rem;
}

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
.print-btn {
  margin-left: auto;
  padding: .42rem 1rem;
  background: transparent; color: var(--muted);
  border: 1px solid var(--border); border-radius: var(--r);
  font-family: var(--ff-body); font-size: .78rem; font-weight: 500;
  cursor: pointer; transition: all var(--ease);
  display: flex; align-items: center; gap: .35rem;
}
.print-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--surface-2); }

/* ─── STAT TILES ──────────────────────────────────────────── */
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
  padding: 1.1rem 1.1rem .95rem;
  box-shadow: var(--shadow);
  position: relative; overflow: hidden;
  transition: transform var(--ease), box-shadow var(--ease);
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
.kpi-teal::before   { background: var(--teal); }
.kpi-purple::before { background: var(--purple); }

.kpi-icon {
  width: 36px; height: 36px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; margin-bottom: .7rem;
}
.kpi-red    .kpi-icon { background: var(--red-light);    color: var(--red); }
.kpi-amber  .kpi-icon { background: var(--amber-light);  color: var(--amber); }
.kpi-blue   .kpi-icon { background: var(--blue-light);   color: var(--blue); }
.kpi-green  .kpi-icon { background: var(--green-light);  color: var(--green); }
.kpi-teal   .kpi-icon { background: var(--teal-light);   color: var(--teal); }
.kpi-purple .kpi-icon { background: var(--purple-light); color: var(--purple); }

.kpi-num {
  font-family: var(--ff-head); font-size: 2rem; font-weight: 800;
  line-height: 1; letter-spacing: -.01em;
}
.kpi-red    .kpi-num { color: var(--red); }
.kpi-amber  .kpi-num { color: var(--amber); }
.kpi-blue   .kpi-num { color: var(--blue); }
.kpi-green  .kpi-num { color: var(--green); }
.kpi-teal   .kpi-num { color: var(--teal); }
.kpi-purple .kpi-num { color: var(--purple); }

.kpi-unit { font-size: 1rem; font-weight: 500; opacity: .7; }
.kpi-lbl  { font-size: .67rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .1em; margin-top: .2rem; }

/* ─── SECTION LABEL ───────────────────────────────────────── */
.sec { display: flex; align-items: center; gap: .6rem; margin-bottom: .85rem; }
.sec-icon { width: 26px; height: 26px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: .78rem; }
.sec-title { font-family: var(--ff-head); font-size: .78rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--muted); }
.sec-line { flex:1; height:1px; background: var(--border); }

/* ─── CHART CARDS ─────────────────────────────────────────── */
.ccard {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow);
  overflow: hidden;
  margin-bottom: 1.1rem;
  animation: fadeUp .4s ease both;
}
.ccard-hd {
  display: flex; align-items: center; justify-content: space-between;
  padding: .8rem 1.25rem;
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
}
.ccard-hd-left { display: flex; align-items: center; gap: .55rem; }
.ccard-hd-icon {
  width: 28px; height: 28px; border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  font-size: .85rem;
}
.ccard-title {
  font-family: var(--ff-head); font-size: .82rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .1em; color: var(--text-2);
}
.cpad { padding: 1.1rem 1.25rem; }

/* ─── TYPE DISTRIBUTION LIST ──────────────────────────────── */
.type-list { padding: .85rem 1.25rem; }
.type-row {
  display: flex; align-items: center; gap: .85rem;
  padding: .55rem 0;
  border-bottom: 1px solid var(--border);
}
.type-row:last-child { border-bottom: none; }
.type-name { flex: 1; font-size: .82rem; font-weight: 600; color: var(--text); text-transform: capitalize; }
.type-pct  { font-family: var(--ff-mono); font-size: .72rem; font-weight: 600; color: var(--muted); white-space: nowrap; min-width: 40px; text-align: right; }
.type-bar-track { width: 80px; height: 6px; background: var(--bg); border-radius: 4px; overflow: hidden; }
.type-bar-fill  { height: 100%; border-radius: 4px; }
.type-cnt { font-family: var(--ff-mono); font-size: .72rem; font-weight: 600; min-width: 32px; text-align: right; }

/* ─── FULFILLMENT BAR ────────────────────────────────────── */
.fulfill-row {
  display: flex; align-items: center; gap: .75rem;
  padding: .65rem 0;
  border-bottom: 1px solid var(--border);
}
.fulfill-name { flex: 1; font-size: .82rem; font-weight: 500; color: var(--text-2); text-transform: capitalize; }
.fulfill-rate { font-family: var(--ff-mono); font-size: .72rem; font-weight: 600; min-width: 40px; text-align: right; }
.fulfill-bar-track { width: 100px; height: 6px; background: var(--bg); border-radius: 4px; overflow: hidden; }
.fulfill-bar-fill { height: 100%; border-radius: 4px; }

/* ─── URGENCY BADGE ──────────────────────────────────────── */
.urgency-badge {
  display: inline-block;
  padding: .2rem .55rem;
  border-radius: 4px;
  font-family: var(--ff-mono);
  font-size: .64rem;
  font-weight: 700;
  text-transform: uppercase;
}
.urg-critical { background: rgba(232,39,29,.12); color: #e8271d; border: 1px solid rgba(232,39,29,.25); }
.urg-high     { background: rgba(217,119,6,.12);  color: #d97706; border: 1px solid rgba(217,119,6,.25); }
.urg-medium   { background: rgba(29,110,245,.12); color: #1d6ef5; border: 1px solid rgba(29,110,245,.25); }
.urg-low      { background: rgba(22,163,74,.12);  color: #16a34a; border: 1px solid rgba(22,163,74,.25); }

/* ─── INSIGHTS BANNER ─────────────────────────────────────── */
.insights {
  background: var(--surface);
  border: 1px solid var(--border);
  border-left: 4px solid var(--teal);
  border-radius: var(--r-lg);
  padding: 1.1rem 1.4rem;
  box-shadow: var(--shadow);
  margin-bottom: 1.5rem;
  display: flex; gap: 1rem; align-items: flex-start;
}
.insights-icon {
  width: 38px; height: 38px; border-radius: var(--r);
  background: var(--teal-light); color: var(--teal);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; flex-shrink: 0;
}
.insights-title {
  font-family: var(--ff-head); font-size: .82rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .1em; color: var(--text);
  margin-bottom: .4rem;
}
.insight-item {
  display: flex; align-items: baseline; gap: .5rem;
  font-size: .8rem; color: var(--text-2); margin-bottom: .2rem;
}
.insight-item::before { content: '▸'; color: var(--teal); font-size: .7rem; flex-shrink: 0; }
.insight-item strong { font-weight: 600; color: var(--text); }

/* ─── EMPTY STATE ─────────────────────────────────────────── */
.empty-state {
  text-align: center; padding: 2rem 1rem; color: var(--muted-2);
}
.empty-state i { font-size: 2rem; display: block; margin-bottom: .5rem; opacity: .3; }

@keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

/* ─── PRINT ───────────────────────────────────────────────── */
@media print {
  .topbar, .page-hero, .filter-bar, .no-print { display: none !important; }
  body { background: #fff; color: #000; }
  .ccard { border: 1px solid #ddd; box-shadow: none; }
}

/* ─── RESPONSIVE ──────────────────────────────────────────── */
@media (max-width: 768px) {
  .hero-title { font-size: 1.35rem; }
  .kpi-grid { grid-template-columns: 1fr 1fr; }
  .type-row { flex-wrap: wrap; }
  .type-name { flex: 1 0 100%; margin-bottom: .25rem; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar no-print">
  <a class="brand" href="../admin/admin_dashboard.php">
    <i class="bi bi-box-seam-fill" style="color:#fff;font-size:1.1rem"></i>
    <div>
      <span class="brand-text">Disaster<span style="opacity:.7">Response</span></span>
      <span class="brand-sub">Analytics Hub</span>
    </div>
  </a>
  <div class="nav-area">
    <a href="incidents.php" class="npill"><i class="bi bi-graph-up-arrow"></i> Incidents</a>
    <a href="resources.php" class="npill active"><i class="bi bi-box-seam"></i> Resources</a>
    <a href="volunteers.php" class="npill"><i class="bi bi-person-heart"></i> Volunteers</a>
    <a href="../admin/dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
  </div>
  <a href="../auth/logout.php" class="logout-btn no-print" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</nav>

<!-- PAGE HERO -->
<div class="page-hero no-print">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-box-seam me-1"></i>Analytics Module</div>
    <div class="hero-title">Resource Analytics</div>
    <div class="hero-sub">Request trends, fulfillment rates &amp; utilization metrics</div>
    <div class="hero-range-chip"><i class="bi bi-calendar-range me-1"></i><?= htmlspecialchars($range_label) ?> &nbsp;·&nbsp; <?= number_format($overview['total_requests'] ?? 0) ?> requests</div>
  </div>
</div>

<!-- PAGE -->
<div class="page">

  <!-- FILTER BAR -->
  <div class="filter-bar no-print">
    <form method="GET" style="display:contents">
      <div>
        <label>Date Range</label>
        <select name="range" onchange="this.form.submit()">
          <option value="7"    <?= $date_range=='7'    ?'selected':'' ?>>Last 7 days</option>
          <option value="30"   <?= $date_range=='30'   ?'selected':'' ?>>Last 30 days</option>
          <option value="90"   <?= $date_range=='90'   ?'selected':'' ?>>Last 90 days</option>
          <option value="365"  <?= $date_range=='365'  ?'selected':'' ?>>Last year</option>
          <option value="custom" <?= $date_range=='custom'?'selected':'' ?>>Custom range</option>
        </select>
      </div>
      <?php if ($date_range === 'custom'): ?>
      <div>
        <label>Start Date</label>
        <input type="date" name="start_date" value="<?= $start_date ?>">
      </div>
      <div>
        <label>End Date</label>
        <input type="date" name="end_date" value="<?= $end_date ?>">
      </div>
      <button type="submit" class="filter-btn"><i class="bi bi-funnel me-1"></i>Apply</button>
      <?php endif; ?>
    </form>
    <button class="print-btn no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print Report</button>
  </div>

  <!-- KPI GRID -->
  <div class="kpi-grid">
    <div class="kpi kpi-blue">
      <div class="kpi-icon"><i class="bi bi-box-seam"></i></div>
      <div class="kpi-num"><?= number_format($overview['total_requests'] ?? 0) ?></div>
      <div class="kpi-lbl">Total Requests</div>
    </div>
    <div class="kpi kpi-amber">
      <div class="kpi-icon"><i class="bi bi-clock-history"></i></div>
      <div class="kpi-num"><?= number_format($overview['pending'] ?? 0) ?></div>
      <div class="kpi-lbl">Pending</div>
    </div>
    <div class="kpi kpi-green">
      <div class="kpi-icon"><i class="bi bi-check-circle-fill"></i></div>
      <div class="kpi-num"><?= number_format($overview['delivered'] ?? 0) ?></div>
      <div class="kpi-lbl">Delivered</div>
    </div>
    <div class="kpi kpi-purple">
      <div class="kpi-icon"><i class="bi bi-percent"></i></div>
      <div class="kpi-num"><?= round(($overview['total_delivered'] / max($overview['total_quantity_requested'], 1)) * 100) ?><span class="kpi-unit">%</span></div>
      <div class="kpi-lbl">Fulfillment Rate</div>
    </div>
    <div class="kpi kpi-red">
      <div class="kpi-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
      <div class="kpi-num"><?= number_format($overview['critical_urgent'] ?? 0) ?></div>
      <div class="kpi-lbl">Critical Urgency</div>
    </div>
    <div class="kpi kpi-teal">
      <div class="kpi-icon"><i class="bi bi-arrow-repeat"></i></div>
      <div class="kpi-num"><?= number_format($overview['in_transit'] ?? 0) ?></div>
      <div class="kpi-lbl">In Transit</div>
    </div>
    <div class="kpi kpi-amber">
      <div class="kpi-icon"><i class="bi bi-truck"></i></div>
      <div class="kpi-num"><?= number_format($overview['approved'] ?? 0) ?></div>
      <div class="kpi-lbl">Approved</div>
    </div>
    <div class="kpi kpi-blue">
      <div class="kpi-icon"><i class="bi bi-database"></i></div>
      <div class="kpi-num"><?= number_format($overview['total_quantity_requested'] ?? 0) ?></div>
      <div class="kpi-lbl">Total Units Requested</div>
    </div>
  </div>

  <!-- INSIGHTS -->
  <div class="insights">
    <div class="insights-icon"><i class="bi bi-lightbulb-fill"></i></div>
    <div>
      <div class="insights-title">Resource Insights — <?= htmlspecialchars($range_label) ?></div>
      <?php if (!empty($type_distribution)): ?>
      <div class="insight-item">Most requested resource: <strong><?= ucfirst(str_replace('_',' ',$type_distribution[0]['resource_type'])) ?></strong> (<?= $type_distribution[0]['request_count'] ?> requests)</div>
      <?php endif; ?>
      <div class="insight-item">Requests with critical urgency: <strong><?= round(($overview['critical_urgent'] / max($overview['total_requests'],1)) * 100) ?>%</strong> of all requests</div>
      <div class="insight-item">Total delivered quantity: <strong><?= number_format($total_delivered_qty) ?></strong> units</div>
      <?php if ($overview['total_requests'] > 0): $fulfill_rate = round(($overview['delivered'] / $overview['total_requests']) * 100); ?>
      <div class="insight-item">Request completion rate: <strong><?= $fulfill_rate ?>%</strong> delivered vs total requests</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- REQUEST TRENDS CHART -->
  <div class="sec">
    <span class="sec-icon" style="background:var(--blue-light);color:var(--blue)"><i class="bi bi-bar-chart-line-fill"></i></span>
    <span class="sec-title">Request Trends</span>
    <div class="sec-line"></div>
  </div>
  <div class="ccard" style="animation-delay:.05s">
    <div class="ccard-hd">
      <div class="ccard-hd-left">
        <span class="ccard-hd-icon" style="background:var(--blue-light);color:var(--blue)"><i class="bi bi-graph-up"></i></span>
        <span class="ccard-title">Daily Request Trends</span>
      </div>
      <span style="font-family:var(--ff-mono);font-size:.68rem;color:var(--muted)"><?= count($trends) ?> data points</span>
    </div>
    <div class="cpad"><canvas id="trendsChart" height="260"></canvas></div>
  </div>

  <div class="row g-3" style="margin-bottom:1.1rem">
    <!-- Urgency Distribution -->
    <div class="col-lg-5">
      <div class="ccard" style="animation-delay:.1s">
        <div class="ccard-hd">
          <div class="ccard-hd-left">
            <span class="ccard-hd-icon" style="background:var(--red-light);color:var(--red)"><i class="bi bi-pie-chart-fill"></i></span>
            <span class="ccard-title">Requests by Urgency</span>
          </div>
        </div>
        <div class="cpad"><canvas id="urgencyChart" height="230"></canvas></div>
      </div>
    </div>
    <!-- Urgency Response Times -->
    <div class="col-lg-7">
      <div class="ccard" style="animation-delay:.15s">
        <div class="ccard-hd">
          <div class="ccard-hd-left">
            <span class="ccard-hd-icon" style="background:var(--teal-light);color:var(--teal)"><i class="bi bi-clock-fill"></i></span>
            <span class="ccard-title">Response Time by Urgency</span>
          </div>
          <span style="font-family:var(--ff-mono);font-size:.68rem;color:var(--teal)">Hours to delivery</span>
        </div>
        <div class="cpad"><canvas id="responseChart" height="230"></canvas></div>
      </div>
    </div>
  </div>

  <div class="row g-3" style="margin-bottom:1.1rem">
    <!-- Request Types Distribution -->
    <div class="col-lg-6">
      <div class="ccard" style="animation-delay:.2s">
        <div class="ccard-hd">
          <div class="ccard-hd-left">
            <span class="ccard-hd-icon" style="background:var(--purple-light);color:var(--purple)"><i class="bi bi-tags-fill"></i></span>
            <span class="ccard-title">Requests by Resource Type</span>
          </div>
        </div>
        <div class="type-list">
          <?php foreach ($top_resource_types as $i => $type): 
            $w = $max_requests > 0 ? round($type['request_count'] / $max_requests * 100) : 0;
            $colors = ['var(--red)','var(--blue)','var(--amber)','var(--green)','var(--teal)','var(--purple)'];
            $col = $colors[$i % count($colors)];
          ?>
          <div class="type-row">
            <div class="type-name"><?= ucfirst(str_replace('_',' ',$type['resource_type'])) ?></div>
            <div class="type-bar-track"><div class="type-bar-fill" style="width:<?= $w ?>%;background:<?= $col ?>"></div></div>
            <div class="type-cnt" style="color:var(--muted)"><?= $type['request_count'] ?></div>
            <div class="type-pct" style="color:<?= $col ?>"><?= round($type['request_count'] / max($overview['total_requests'],1) * 100) ?>%</div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($type_distribution)): ?>
            <div class="empty-state"><i class="bi bi-inbox"></i><p>No data available</p></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <!-- Fulfillment Rates -->
    <div class="col-lg-6">
      <div class="ccard" style="animation-delay:.25s">
        <div class="ccard-hd">
          <div class="ccard-hd-left">
            <span class="ccard-hd-icon" style="background:var(--green-light);color:var(--green)"><i class="bi bi-check-circle-fill"></i></span>
            <span class="ccard-title">Fulfillment Rate by Type</span>
          </div>
          <span style="font-family:var(--ff-mono);font-size:.68rem;color:var(--muted)">Quantity delivered vs requested</span>
        </div>
        <div class="cpad">
          <?php if (!empty($fulfillment_data)): ?>
            <?php foreach ($fulfillment_data as $f): 
              $rate_color = $f['rate'] >= 80 ? 'var(--green)' : ($f['rate'] >= 50 ? 'var(--amber)' : 'var(--red)');
            ?>
            <div class="fulfill-row">
              <div class="fulfill-name"><?= ucfirst(str_replace('_',' ',$f['type'])) ?></div>
              <div class="fulfill-bar-track"><div class="fulfill-bar-fill" style="width:<?= $f['rate'] ?>%;background:<?= $rate_color ?>"></div></div>
              <div class="fulfill-rate" style="color:<?= $rate_color ?>"><?= $f['rate'] ?>%</div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="empty-state"><i class="bi bi-inbox"></i><p>No fulfillment data available</p></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div><!-- /page -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const gridColor = 'rgba(0,0,0,.05)';
const tickOpts  = { color:'#9ca3af', font:{ family:"'IBM Plex Mono',monospace", size:10 } };
const legOpts   = { labels:{ color:'#6b7280', font:{ family:"'Barlow',sans-serif", size:11 }, usePointStyle:true, padding:14 } };

// Trends Chart
new Chart(document.getElementById('trendsChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($trend_dates) ?>,
    datasets: [
      { label: 'Total Requests', data: <?= json_encode($trend_requests) ?>, borderColor: '#1d6ef5', backgroundColor: 'rgba(29,110,245,.06)', fill: true, tension: .35, borderWidth: 2, pointRadius: 3 },
      { label: 'Delivered',      data: <?= json_encode($trend_delivered) ?>, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.05)', fill: true, tension: .35, borderWidth: 2, pointRadius: 3 }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: true,
    plugins: { legend: legOpts },
    scales: { y: { grid: { color: gridColor }, ticks: tickOpts }, x: { grid: { display: false }, ticks: tickOpts } }
  }
});

// Urgency Distribution Chart
new Chart(document.getElementById('urgencyChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($urgency_labels) ?>,
    datasets: [{ data: <?= json_encode($urgency_counts) ?>, backgroundColor: ['#fff0ef','#fffbeb','#eff5ff','#f0fdf4'], borderColor: ['#e8271d','#d97706','#1d6ef5','#16a34a'], borderWidth: 2.5, hoverOffset: 8 }]
  },
  options: {
    responsive: true, maintainAspectRatio: true, cutout: '65%',
    plugins: { legend: { position: 'bottom', labels: legOpts.labels } }
  }
});

// Response Time by Urgency Chart
new Chart(document.getElementById('responseChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($urgency_labels) ?>,
    datasets: [{ label: 'Avg Response Time (hours)', data: <?= json_encode($urgency_response) ?>, backgroundColor: ['#e8271d','#d97706','#1d6ef5','#16a34a'], borderRadius: 6 }]
  },
  options: {
    responsive: true, maintainAspectRatio: true,
    plugins: { legend: { display: false } },
    scales: {
      y: { grid: { color: gridColor }, ticks: { ...tickOpts, stepSize: 24 }, title: { display: true, text: 'Hours', color: '#9ca3af', font: { size: 10 } } },
      x: { grid: { display: false }, ticks: tickOpts }
    }
  }
});
</script>
</body>
</html>