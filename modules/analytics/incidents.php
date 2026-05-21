<?php
/**
 * Incident Analytics Dashboard
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

role_guard(['admin', 'responder']);

$date_range = $_GET['range'] ?? '30';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime("-{$date_range} days"));
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
if ($date_range !== 'custom') {
    $start_date = date('Y-m-d', strtotime("-{$date_range} days"));
    $end_date   = date('Y-m-d');
}

// ─── STATS ────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) as total_incidents, COUNT(DISTINCT incident_type) as incident_types, COUNT(DISTINCT reporter_id) as unique_reporters, SUM(CASE WHEN severity=4 THEN 1 ELSE 0 END) as critical, SUM(CASE WHEN severity=3 THEN 1 ELSE 0 END) as high, SUM(CASE WHEN severity=2 THEN 1 ELSE 0 END) as medium, SUM(CASE WHEN severity=1 THEN 1 ELSE 0 END) as low, AVG(TIMESTAMPDIFF(MINUTE, reported_at, CASE WHEN status='resolved' THEN updated_at ELSE NOW() END)) as avg_response_minutes FROM incidents WHERE DATE(reported_at) BETWEEN ? AND ?");
$stmt->execute([$start_date, $end_date]);
$overview = $stmt->fetch();

$stmt = $pdo->prepare("SELECT DATE(reported_at) as date, COUNT(*) as total, SUM(CASE WHEN severity=4 THEN 1 ELSE 0 END) as critical, SUM(CASE WHEN severity=3 THEN 1 ELSE 0 END) as high, SUM(CASE WHEN severity=2 THEN 1 ELSE 0 END) as medium, SUM(CASE WHEN severity=1 THEN 1 ELSE 0 END) as low, SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) as resolved FROM incidents WHERE DATE(reported_at) BETWEEN ? AND ? GROUP BY DATE(reported_at) ORDER BY date ASC");
$stmt->execute([$start_date, $end_date]);
$daily_trends = $stmt->fetchAll();

$trend_dates = []; $trend_totals = []; $trend_critical = []; $trend_high = []; $trend_resolved = [];
foreach ($daily_trends as $t) {
    $trend_dates[]    = date('M j', strtotime($t['date']));
    $trend_totals[]   = $t['total'];
    $trend_critical[] = $t['critical'];
    $trend_high[]     = $t['high'];
    $trend_resolved[] = $t['resolved'];
}

$stmt = $pdo->prepare("SELECT incident_type, COUNT(*) as count, ROUND(COUNT(*)*100.0/(SELECT COUNT(*) FROM incidents WHERE DATE(reported_at) BETWEEN ? AND ?),1) as percentage, AVG(severity) as avg_severity FROM incidents WHERE DATE(reported_at) BETWEEN ? AND ? GROUP BY incident_type ORDER BY count DESC");
$stmt->execute([$start_date, $end_date, $start_date, $end_date]);
$type_distribution = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT HOUR(reported_at) as hour, COUNT(*) as count FROM incidents WHERE DATE(reported_at) BETWEEN ? AND ? GROUP BY HOUR(reported_at) ORDER BY hour ASC");
$stmt->execute([$start_date, $end_date]);
$hourly_patterns = $stmt->fetchAll();
$hourly_labels = []; $hourly_counts = [];
for ($i = 0; $i < 24; $i++) { $hourly_labels[] = sprintf("%02d:00", $i); $hourly_counts[$i] = 0; }
foreach ($hourly_patterns as $p) { $hourly_counts[$p['hour']] = $p['count']; }
$hourly_counts = array_values($hourly_counts);

$stmt = $pdo->prepare("SELECT DAYOFWEEK(reported_at) as dow, COUNT(*) as count, AVG(severity) as avg_severity FROM incidents WHERE DATE(reported_at) BETWEEN ? AND ? GROUP BY DAYOFWEEK(reported_at) ORDER BY dow ASC");
$stmt->execute([$start_date, $end_date]);
$weekday_patterns = $stmt->fetchAll();
$weekday_labels = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$weekday_counts = array_fill(0,7,0); $weekday_severity = array_fill(0,7,0);
foreach ($weekday_patterns as $p) { $weekday_counts[$p['dow']-1]=$p['count']; $weekday_severity[$p['dow']-1]=round($p['avg_severity'],1); }

$stmt = $pdo->prepare("SELECT DATE(updated_at) as date, AVG(TIMESTAMPDIFF(MINUTE, reported_at, updated_at)) as avg_response_minutes FROM incidents WHERE status='resolved' AND updated_at IS NOT NULL AND DATE(reported_at) BETWEEN ? AND ? GROUP BY DATE(updated_at) ORDER BY date ASC");
$stmt->execute([$start_date, $end_date]);
$response_trends = $stmt->fetchAll();
$response_dates = []; $response_times = [];
foreach ($response_trends as $t) { $response_dates[]=date('M j',strtotime($t['date'])); $response_times[]=round($t['avg_response_minutes']); }

$stmt = $pdo->prepare("SELECT location_name, COUNT(*) as incident_count, AVG(severity) as avg_severity FROM incidents WHERE location_name IS NOT NULL AND location_name!='' AND DATE(reported_at) BETWEEN ? AND ? GROUP BY location_name ORDER BY incident_count DESC LIMIT 10");
$stmt->execute([$start_date, $end_date]);
$top_locations = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT status, COUNT(*) as count, ROUND(COUNT(*)*100.0/(SELECT COUNT(*) FROM incidents WHERE DATE(reported_at) BETWEEN ? AND ?),1) as percentage FROM incidents WHERE DATE(reported_at) BETWEEN ? AND ? GROUP BY status ORDER BY count DESC");
$stmt->execute([$start_date, $end_date, $start_date, $end_date]);
$status_distribution = $stmt->fetchAll();
$status_labels = []; $status_counts = [];
foreach ($status_distribution as $s) { $status_labels[]=ucfirst(str_replace('-',' ',$s['status'])); $status_counts[]=$s['count']; }

$max_hour = count($hourly_counts) ? array_search(max($hourly_counts), $hourly_counts) : 0;
$range_label = $date_range === 'custom' ? "$start_date → $end_date" : "Last $date_range days";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Incident Analytics — DisasterResponse</title>
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
.kpi-navy::before   { background: var(--navy); }

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
.kpi-navy   .kpi-icon { background: rgba(15,27,45,.08);  color: var(--navy); }

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
.kpi-navy   .kpi-num { color: var(--navy); }

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

/* ─── LOCATIONS TABLE ─────────────────────────────────────── */
.loc-tbl { width: 100%; border-collapse: collapse; }
.loc-tbl thead tr { background: var(--surface-2); }
.loc-tbl thead th {
  padding: .65rem 1.25rem;
  font-family: var(--ff-head); font-size: .68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .12em; color: var(--muted);
  border-bottom: 2px solid var(--border); white-space: nowrap;
}
.loc-tbl tbody tr { border-bottom: 1px solid var(--border); transition: background var(--ease); }
.loc-tbl tbody tr:last-child { border-bottom: none; }
.loc-tbl tbody tr:hover { background: #f4f6fb; }
.loc-tbl td { padding: .7rem 1.25rem; font-size: .82rem; color: var(--text-2); vertical-align: middle; }
.loc-name { font-weight: 600; color: var(--text); }
.loc-count {
  font-family: var(--ff-mono); font-size: .78rem; font-weight: 600;
  color: var(--blue);
}
.sev-bar-wrap { display: flex; align-items: center; gap: .5rem; }
.sev-bar-track { flex: 1; height: 5px; background: var(--bg); border-radius: 4px; overflow: hidden; max-width: 60px; }
.sev-bar-fill  { height: 100%; border-radius: 4px; }
.sev-val { font-family: var(--ff-mono); font-size: .7rem; color: var(--muted); white-space: nowrap; }

/* Rank badge */
.rank {
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; border-radius: 50%;
  font-family: var(--ff-mono); font-size: .65rem; font-weight: 700;
  background: var(--surface-2); border: 1px solid var(--border); color: var(--muted);
}
.rank-1 { background: #fef9c3; border-color: #fde047; color: #854d0e; }
.rank-2 { background: #f1f5f9; border-color: #cbd5e1; color: #334155; }
.rank-3 { background: #fff7ed; border-color: #fdba74; color: #9a3412; }

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

/* ─── INSIGHTS BANNER ─────────────────────────────────────── */
.insights {
  background: var(--surface);
  border: 1px solid var(--border);
  border-left: 4px solid var(--amber);
  border-radius: var(--r-lg);
  padding: 1.1rem 1.4rem;
  box-shadow: var(--shadow);
  margin-bottom: 1.5rem;
  display: flex; gap: 1rem; align-items: flex-start;
}
.insights-icon {
  width: 38px; height: 38px; border-radius: var(--r);
  background: var(--amber-light); color: var(--amber);
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
.insight-item::before { content: '▸'; color: var(--amber); font-size: .7rem; flex-shrink: 0; }
.insight-item strong { font-weight: 600; color: var(--text); }

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
}
</style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar no-print">
  <a class="brand" href="../admin/dashboard.php">
    <i class="bi bi-shield-fill-exclamation" style="color:#fff;font-size:1.1rem"></i>
    <div>
      <span class="brand-text">Disaster<span style="opacity:.7">Response</span></span>
      <span class="brand-sub">Admin Console</span>
    </div>
  </a>
  <div class="nav-area">
    <a href="incidents.php" class="npill active"><i class="bi bi-graph-up-arrow"></i> Incidents</a>
    <a href="resources.php" class="npill"><i class="bi bi-box-seam"></i> Resources</a>
    <a href="volunteers.php" class="npill"><i class="bi bi-person-heart"></i> Volunteers</a>
    <a href="../admin/dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
  </div>
  <a href="../auth/logout.php" class="logout-btn no-print" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</nav>

<!-- PAGE HERO -->
<div class="page-hero no-print">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-bar-chart-steps me-1"></i>Analytics Module</div>
    <div class="hero-title">Incident Analytics</div>
    <div class="hero-sub">Comprehensive trends, patterns &amp; performance metrics</div>
    <div class="hero-range-chip"><i class="bi bi-calendar-range me-1"></i><?= htmlspecialchars($range_label) ?> &nbsp;·&nbsp; <?= number_format($overview['total_incidents'] ?? 0) ?> incidents</div>
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
      <div class="kpi-icon"><i class="bi bi-stack"></i></div>
      <div class="kpi-num"><?= number_format($overview['total_incidents'] ?? 0) ?></div>
      <div class="kpi-lbl">Total Incidents</div>
    </div>
    <div class="kpi kpi-red">
      <div class="kpi-icon"><i class="bi bi-fire"></i></div>
      <div class="kpi-num"><?= number_format($overview['critical'] ?? 0) ?></div>
      <div class="kpi-lbl">Critical Severity</div>
    </div>
    <div class="kpi kpi-green">
      <div class="kpi-icon"><i class="bi bi-stopwatch-fill"></i></div>
      <div class="kpi-num"><?= round($overview['avg_response_minutes'] ?? 0) ?><span class="kpi-unit"> min</span></div>
      <div class="kpi-lbl">Avg Response Time</div>
    </div>
    <div class="kpi kpi-amber">
      <div class="kpi-icon"><i class="bi bi-tags-fill"></i></div>
      <div class="kpi-num"><?= $overview['incident_types'] ?? 0 ?></div>
      <div class="kpi-lbl">Incident Types</div>
    </div>
    <div class="kpi kpi-teal">
      <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
      <div class="kpi-num"><?= number_format($overview['unique_reporters'] ?? 0) ?></div>
      <div class="kpi-lbl">Unique Reporters</div>
    </div>
    <div class="kpi kpi-purple">
      <div class="kpi-icon"><i class="bi bi-shield-fill"></i></div>
      <div class="kpi-num"><?= number_format($overview['high'] ?? 0) ?></div>
      <div class="kpi-lbl">High Severity</div>
    </div>
    <div class="kpi kpi-navy">
      <div class="kpi-icon"><i class="bi bi-check-circle-fill"></i></div>
      <div class="kpi-num"><?= count($trend_resolved) ? array_sum($trend_resolved) : 0 ?></div>
      <div class="kpi-lbl">Resolved (period)</div>
    </div>
    <div class="kpi kpi-amber">
      <div class="kpi-icon"><i class="bi bi-geo-alt-fill"></i></div>
      <div class="kpi-num"><?= count($top_locations) ?></div>
      <div class="kpi-lbl">Affected Locations</div>
    </div>
  </div>

  <!-- INSIGHTS -->
  <div class="insights">
    <div class="insights-icon"><i class="bi bi-lightbulb-fill"></i></div>
    <div>
      <div class="insights-title">Key Insights — <?= htmlspecialchars($range_label) ?></div>
      <div class="insight-item">Peak reporting hour: <strong><?= sprintf("%02d:00 – %02d:00", $max_hour, $max_hour+1) ?></strong></div>
      <?php if (!empty($type_distribution)): ?>
      <div class="insight-item">Most common type: <strong><?= ucfirst(str_replace('_',' ',$type_distribution[0]['incident_type'])) ?></strong> (<?= $type_distribution[0]['count'] ?> incidents, <?= $type_distribution[0]['percentage'] ?>%)</div>
      <?php endif; ?>
      <?php $nz = array_filter($weekday_severity); if (!empty($nz)): $best_day = array_search(min($nz), $weekday_severity); ?>
      <div class="insight-item">Lowest avg severity day: <strong><?= $weekday_labels[$best_day] ?></strong></div>
      <?php endif; ?>
      <?php if (($overview['total_incidents'] ?? 0) > 0): $res_rate = round(array_sum($trend_resolved) / $overview['total_incidents'] * 100); ?>
      <div class="insight-item">Resolution rate for period: <strong><?= $res_rate ?>%</strong></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- DAILY TRENDS -->
  <div class="sec">
    <span class="sec-icon" style="background:var(--blue-light);color:var(--blue)"><i class="bi bi-bar-chart-line-fill"></i></span>
    <span class="sec-title">Trends</span>
    <div class="sec-line"></div>
  </div>
  <div class="ccard" style="animation-delay:.05s">
    <div class="ccard-hd">
      <div class="ccard-hd-left">
        <span class="ccard-hd-icon" style="background:var(--blue-light);color:var(--blue)"><i class="bi bi-graph-up"></i></span>
        <span class="ccard-title">Daily Incident Trends</span>
      </div>
      <span style="font-family:var(--ff-mono);font-size:.68rem;color:var(--muted)"><?= count($daily_trends) ?> data points</span>
    </div>
    <div class="cpad"><canvas id="trendsChart" height="260"></canvas></div>
  </div>

  <div class="row g-3" style="margin-bottom:1.1rem">
    <!-- Response Time -->
    <div class="col-lg-8">
      <div class="ccard h-100" style="animation-delay:.1s">
        <div class="ccard-hd">
          <div class="ccard-hd-left">
            <span class="ccard-hd-icon" style="background:var(--green-light);color:var(--green)"><i class="bi bi-stopwatch-fill"></i></span>
            <span class="ccard-title">Response Time Trend</span>
          </div>
          <span style="font-family:var(--ff-mono);font-size:.68rem;color:var(--green)">Resolved incidents only</span>
        </div>
        <div class="cpad"><canvas id="responseChart" height="220"></canvas></div>
      </div>
    </div>
    <!-- Type distribution list -->
    <div class="col-lg-4">
      <div class="ccard h-100" style="animation-delay:.15s">
        <div class="ccard-hd">
          <div class="ccard-hd-left">
            <span class="ccard-hd-icon" style="background:var(--purple-light);color:var(--purple)"><i class="bi bi-tags-fill"></i></span>
            <span class="ccard-title">By Type</span>
          </div>
        </div>
        <div class="type-list">
          <?php
          $type_colors = ['var(--red)','var(--blue)','var(--amber)','var(--green)','var(--teal)','var(--purple)'];
          $max_type = $type_distribution[0]['count'] ?? 1;
          foreach ($type_distribution as $i => $td):
            $col = $type_colors[$i % count($type_colors)];
            $w = $max_type > 0 ? round($td['count'] / $max_type * 100) : 0;
          ?>
          <div class="type-row">
            <div class="type-name"><?= ucfirst(str_replace('_',' ',$td['incident_type'])) ?></div>
            <div class="type-bar-track"><div class="type-bar-fill" style="width:<?= $w ?>%;background:<?= $col ?>"></div></div>
            <div class="type-pct" style="color:<?= $col ?>"><?= $td['percentage'] ?>%</div>
            <div class="type-cnt" style="color:var(--muted)"><?= $td['count'] ?></div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($type_distribution)): ?>
            <div style="text-align:center;padding:1.5rem;color:var(--muted-2);font-size:.82rem">No data available</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- PATTERNS -->
  <div class="sec">
    <span class="sec-icon" style="background:var(--amber-light);color:var(--amber)"><i class="bi bi-clock-history"></i></span>
    <span class="sec-title">Temporal Patterns</span>
    <div class="sec-line"></div>
  </div>
  <div class="row g-3" style="margin-bottom:1.1rem">
    <div class="col-lg-6">
      <div class="ccard" style="animation-delay:.2s">
        <div class="ccard-hd">
          <div class="ccard-hd-left">
            <span class="ccard-hd-icon" style="background:var(--teal-light);color:var(--teal)"><i class="bi bi-clock-fill"></i></span>
            <span class="ccard-title">Incidents by Hour of Day</span>
          </div>
          <span style="font-family:var(--ff-mono);font-size:.68rem;color:var(--teal)">Peak: <?= sprintf("%02d:00",$max_hour) ?></span>
        </div>
        <div class="cpad"><canvas id="hourlyChart" height="230"></canvas></div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="ccard" style="animation-delay:.25s">
        <div class="ccard-hd">
          <div class="ccard-hd-left">
            <span class="ccard-hd-icon" style="background:var(--purple-light);color:var(--purple)"><i class="bi bi-calendar-week-fill"></i></span>
            <span class="ccard-title">Incidents by Day of Week</span>
          </div>
        </div>
        <div class="cpad"><canvas id="weeklyChart" height="230"></canvas></div>
      </div>
    </div>
  </div>

  <!-- DISTRIBUTION -->
  <div class="sec">
    <span class="sec-icon" style="background:var(--red-light);color:var(--red)"><i class="bi bi-pie-chart-fill"></i></span>
    <span class="sec-title">Distribution</span>
    <div class="sec-line"></div>
  </div>
  <div class="row g-3" style="margin-bottom:1.1rem">
    <div class="col-lg-5">
      <div class="ccard" style="animation-delay:.3s">
        <div class="ccard-hd">
          <div class="ccard-hd-left">
            <span class="ccard-hd-icon" style="background:var(--red-light);color:var(--red)"><i class="bi bi-pie-chart-fill"></i></span>
            <span class="ccard-title">Incident Status</span>
          </div>
        </div>
        <div class="cpad"><canvas id="statusChart" height="250"></canvas></div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="ccard" style="animation-delay:.3s">
        <div class="ccard-hd">
          <div class="ccard-hd-left">
            <span class="ccard-hd-icon" style="background:var(--green-light);color:var(--green)"><i class="bi bi-geo-alt-fill"></i></span>
            <span class="ccard-title">Top Incident Locations</span>
          </div>
          <span style="font-family:var(--ff-mono);font-size:.68rem;color:var(--muted)">Top 10</span>
        </div>
        <?php if (count($top_locations) > 0): $max_loc = $top_locations[0]['incident_count']; ?>
        <div class="table-responsive">
          <table class="loc-tbl">
            <thead><tr><th>#</th><th>Location</th><th>Incidents</th><th>Avg Severity</th></tr></thead>
            <tbody>
              <?php foreach ($top_locations as $i => $loc):
                $rank_cls = $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank'));
                $sev = round($loc['avg_severity'],1);
                $sev_col = $sev >= 3.5 ? 'var(--red)' : ($sev >= 2.5 ? 'var(--amber)' : ($sev >= 1.5 ? 'var(--blue)' : 'var(--green)'));
                $bar_w = $max_loc > 0 ? round($loc['incident_count']/$max_loc*100) : 0;
              ?>
              <tr>
                <td><span class="rank <?= $rank_cls ?>"><?= $i+1 ?></span></td>
                <td><div class="loc-name"><?= htmlspecialchars($loc['location_name']) ?></div></td>
                <td>
                  <div style="display:flex;align-items:center;gap:.5rem">
                    <div style="width:60px;height:5px;background:var(--bg);border-radius:4px;overflow:hidden"><div style="height:100%;border-radius:4px;background:var(--blue);width:<?= $bar_w ?>%"></div></div>
                    <span class="loc-count"><?= $loc['incident_count'] ?></span>
                  </div>
                </td>
                <td>
                  <div class="sev-bar-wrap">
                    <div class="sev-bar-track"><div class="sev-bar-fill" style="width:<?= round($sev/4*100) ?>%;background:<?= $sev_col ?>"></div></div>
                    <span class="sev-val" style="color:<?= $sev_col ?>"><?= $sev ?>/4</span>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
          <div style="text-align:center;padding:2rem;color:var(--muted-2)"><i class="bi bi-geo-alt" style="font-size:1.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>No location data available</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /page -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const gridColor = 'rgba(0,0,0,.05)';
const tickOpts  = { color:'#9ca3af', font:{ family:"'IBM Plex Mono',monospace", size:10 } };
const legOpts   = { labels:{ color:'#6b7280', font:{ family:"'Barlow',sans-serif", size:11 }, usePointStyle:true, padding:14 } };

// Trends
new Chart(document.getElementById('trendsChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($trend_dates) ?>,
    datasets: [
      { label:'Total',    data:<?= json_encode($trend_totals) ?>,   borderColor:'#1d6ef5', backgroundColor:'rgba(29,110,245,.06)', fill:true, tension:.35, borderWidth:2, pointRadius:3 },
      { label:'Critical', data:<?= json_encode($trend_critical) ?>, borderColor:'#e8271d', backgroundColor:'transparent',          fill:false, tension:.35, borderWidth:2, pointRadius:3 },
      { label:'High',     data:<?= json_encode($trend_high) ?>,     borderColor:'#d97706', backgroundColor:'transparent',          fill:false, tension:.35, borderWidth:1.5, borderDash:[4,3], pointRadius:2 },
      { label:'Resolved', data:<?= json_encode($trend_resolved) ?>, borderColor:'#16a34a', backgroundColor:'rgba(22,163,74,.05)',  fill:true,  tension:.35, borderWidth:2, pointRadius:3 }
    ]
  },
  options: {
    responsive:true, maintainAspectRatio:true,
    plugins: { legend: legOpts },
    scales: {
      y: { grid:{ color:gridColor }, ticks: tickOpts },
      x: { grid:{ display:false },   ticks: tickOpts }
    }
  }
});

// Status
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($status_labels) ?>,
    datasets:[{ data:<?= json_encode($status_counts) ?>, backgroundColor:['#fffbeb','#eff5ff','#f0fdf4','#fff0ef','#f9fafb'], borderColor:['#d97706','#1d6ef5','#16a34a','#e8271d','#9ca3af'], borderWidth:2.5, hoverOffset:8 }]
  },
  options: { responsive:true, maintainAspectRatio:true, cutout:'65%', plugins:{ legend:{ position:'bottom', labels: legOpts.labels } } }
});

// Hourly
new Chart(document.getElementById('hourlyChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($hourly_labels) ?>,
    datasets:[{ label:'Incidents', data:<?= json_encode($hourly_counts) ?>, backgroundColor:(ctx)=>{const v=ctx.raw;const m=Math.max(...<?= json_encode($hourly_counts) ?>);return v===m?'#e8271d':'rgba(29,110,245,.45)';}, borderRadius:4 }]
  },
  options: {
    responsive:true, maintainAspectRatio:true,
    plugins:{ legend:{display:false} },
    scales: { y:{ grid:{color:gridColor}, ticks:tickOpts }, x:{ grid:{display:false}, ticks:{ ...tickOpts, maxTicksLimit:8 } } }
  }
});

// Weekly
new Chart(document.getElementById('weeklyChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($weekday_labels) ?>,
    datasets:[
      { label:'Incidents',    data:<?= json_encode($weekday_counts) ?>,   backgroundColor:'rgba(124,58,237,.45)', borderRadius:4, yAxisID:'y' },
      { label:'Avg Severity', data:<?= json_encode($weekday_severity) ?>, type:'line', borderColor:'#e8271d', fill:false, tension:.3, borderWidth:2, pointRadius:4, yAxisID:'y1' }
    ]
  },
  options: {
    responsive:true, maintainAspectRatio:true,
    plugins:{ legend: legOpts },
    scales: {
      y:  { grid:{color:gridColor}, ticks:tickOpts },
      y1: { position:'right', grid:{drawOnChartArea:false}, ticks:{ ...tickOpts, min:0, max:4 }, title:{display:true, text:'Severity', color:'#9ca3af', font:{size:9}} }
    }
  }
});

// Response Time
new Chart(document.getElementById('responseChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($response_dates) ?>,
    datasets:[{ label:'Avg Response (min)', data:<?= json_encode($response_times) ?>, borderColor:'#16a34a', backgroundColor:'rgba(22,163,74,.07)', fill:true, tension:.35, borderWidth:2.5, pointRadius:4, pointBackgroundColor:'#16a34a' }]
  },
  options: {
    responsive:true, maintainAspectRatio:true,
    plugins:{ legend:{ labels:{ color:'#6b7280', font:{ family:"'Barlow',sans-serif", size:11 }, usePointStyle:true } } },
    scales: { y:{ grid:{color:gridColor}, ticks:tickOpts }, x:{ grid:{display:false}, ticks:tickOpts } }
  }
});
</script>
</body>
</html>