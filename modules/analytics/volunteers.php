<?php
/**
 * Volunteer Analytics Dashboard
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Volunteer engagement, task completion, and performance analytics
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

role_guard(['admin', 'responder']);

$date_range = $_GET['range'] ?? '30';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime("-{$date_range} days"));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

if ($date_range !== 'custom') {
    $start_date = date('Y-m-d', strtotime("-{$date_range} days"));
    $end_date = date('Y-m-d');
}

$range_label = $date_range === 'custom' ? "$start_date → $end_date" : "Last $date_range days";

// Volunteer Statistics
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM volunteers");
$stmt->execute();
$total_volunteers = $stmt->fetch()['total'];

$stmt = $pdo->prepare("SELECT COUNT(*) as available FROM volunteers WHERE availability_status = 'available'");
$stmt->execute();
$available_volunteers = $stmt->fetch()['available'];

$stmt = $pdo->prepare("
    SELECT COUNT(*) as total_tasks, 
           SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
           SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
           SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned
    FROM volunteer_tasks 
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$task_stats = $stmt->fetch();

// Top volunteers
$stmt = $pdo->prepare("
    SELECT u.full_name, COUNT(vt.id) as tasks_completed,
           ROUND(AVG(CASE WHEN vt.completed_at IS NOT NULL 
               THEN TIMESTAMPDIFF(HOUR, vt.created_at, vt.completed_at) 
               ELSE NULL END), 1) as avg_task_hours
    FROM users u 
    JOIN volunteer_tasks vt ON u.id = vt.volunteer_id
    WHERE vt.status = 'completed' AND DATE(vt.completed_at) BETWEEN ? AND ?
    GROUP BY u.id 
    ORDER BY tasks_completed DESC 
    LIMIT 10
");
$stmt->execute([$start_date, $end_date]);
$top_volunteers = $stmt->fetchAll();

// Skill distribution
$stmt = $pdo->prepare("SELECT skills FROM volunteers");
$stmt->execute();
$all_skills = $stmt->fetchAll();
$skill_counts = [];
foreach ($all_skills as $v) {
    $skills = explode(', ', $v['skills']);
    foreach ($skills as $s) { 
        if (trim($s)) $skill_counts[$s] = ($skill_counts[$s] ?? 0) + 1; 
    }
}
arsort($skill_counts);
$top_skills = array_slice($skill_counts, 0, 8);
$max_skill = $top_skills ? max($top_skills) : 1;

// Monthly trends
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, 
           COUNT(*) as tasks, 
           SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM volunteer_tasks 
    WHERE DATE(created_at) BETWEEN ? AND ? 
    GROUP BY DATE_FORMAT(created_at, '%Y-%m') 
    ORDER BY month ASC
");
$stmt->execute([$start_date, $end_date]);
$monthly = $stmt->fetchAll();

$months = []; $tasks = []; $completed = [];
foreach ($monthly as $m) { 
    $months[] = date('M Y', strtotime($m['month'] . '-01')); 
    $tasks[] = $m['tasks']; 
    $completed[] = $m['completed']; 
}

// Calculate completion rate
$completion_rate = ($task_stats['total_tasks'] ?? 0) > 0 
    ? round(($task_stats['completed'] / $task_stats['total_tasks']) * 100) 
    : 0;

// Calculate engagement metrics
$active_volunteers = ($task_stats['total_tasks'] ?? 0) > 0 
    ? round(($task_stats['completed'] / max($task_stats['total_tasks'], 1)) * 100) 
    : 0;

$page_title = 'Volunteer Analytics';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Volunteer Analytics — DisasterResponse</title>
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

/* ─── TOP VOLUNTEERS TABLE ────────────────────────────────── */
.vol-tbl {
  width: 100%; border-collapse: collapse;
}
.vol-tbl thead tr {
  background: var(--surface-2);
  border-bottom: 1px solid var(--border-2);
}
.vol-tbl thead th {
  padding: .7rem 1.25rem;
  font-family: var(--ff-head); font-size: .68rem; font-weight: 700;
  letter-spacing: .09em; text-transform: uppercase;
  color: var(--muted);
}
.vol-tbl tbody tr {
  border-bottom: 1px solid var(--border);
  transition: background var(--ease);
}
.vol-tbl tbody tr:hover { background: #f4f6fb; }
.vol-tbl td {
  padding: .75rem 1.25rem;
  font-size: .82rem;
  color: var(--text-2);
  vertical-align: middle;
}
.vol-name { font-weight: 600; color: var(--text); }
.vol-count {
  font-family: var(--ff-mono); font-size: .78rem; font-weight: 600;
  color: var(--green);
}
.rank {
  display: inline-flex; align-items: center; justify-content: center;
  width: 24px; height: 24px; border-radius: 50%;
  font-family: var(--ff-mono); font-size: .68rem; font-weight: 700;
  background: var(--surface-2); border: 1px solid var(--border-2); color: var(--muted);
}
.rank-1 { background: #fef9c3; border-color: #fde047; color: #854d0e; }
.rank-2 { background: #f1f5f9; border-color: #cbd5e1; color: #334155; }
.rank-3 { background: #fff7ed; border-color: #fdba74; color: #9a3412; }

/* ─── SKILL LIST ──────────────────────────────────────────── */
.skill-list { padding: .85rem 1.25rem; }
.skill-row {
  display: flex; align-items: center; gap: .85rem;
  padding: .65rem 0;
  border-bottom: 1px solid var(--border);
}
.skill-row:last-child { border-bottom: none; }
.skill-name { flex: 1; font-size: .82rem; font-weight: 600; color: var(--text); text-transform: capitalize; }
.skill-count { font-family: var(--ff-mono); font-size: .72rem; font-weight: 600; color: var(--muted); min-width: 50px; text-align: right; }
.skill-bar-track { width: 100px; height: 6px; background: var(--bg); border-radius: 4px; overflow: hidden; }
.skill-bar-fill { height: 100%; border-radius: 4px; }

/* ─── INSIGHTS BANNER ─────────────────────────────────────── */
.insights {
  background: var(--surface);
  border: 1px solid var(--border);
  border-left: 4px solid var(--purple);
  border-radius: var(--r-lg);
  padding: 1.1rem 1.4rem;
  box-shadow: var(--shadow);
  margin-bottom: 1.5rem;
  display: flex; gap: 1rem; align-items: flex-start;
}
.insights-icon {
  width: 38px; height: 38px; border-radius: var(--r);
  background: var(--purple-light); color: var(--purple);
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
.insight-item::before { content: '▸'; color: var(--purple); font-size: .7rem; flex-shrink: 0; }
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
  .skill-row { flex-wrap: wrap; }
  .skill-name { flex: 1 0 100%; margin-bottom: .25rem; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar no-print">
  <a class="brand" href="../admin/dashboard.php">
    <i class="bi bi-person-heart" style="color:#fff;font-size:1.1rem"></i>
    <div>
      <span class="brand-text">Disaster<span style="opacity:.7">Response</span></span>
      <span class="brand-sub">Analytics Hub</span>
    </div>
  </a>
  <div class="nav-area">
    <a href="incidents.php" class="npill"><i class="bi bi-graph-up-arrow"></i> Incidents</a>
    <a href="resources.php" class="npill"><i class="bi bi-box-seam"></i> Resources</a>
    <a href="volunteers.php" class="npill active"><i class="bi bi-person-heart"></i> Volunteers</a>
    <a href="../admin/dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
  </div>
  <a href="../auth/logout.php" class="logout-btn no-print" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</nav>

<!-- PAGE HERO -->
<div class="page-hero no-print">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-person-heart me-1"></i>Analytics Module</div>
    <div class="hero-title">Volunteer Analytics</div>
    <div class="hero-sub">Engagement metrics, task completion &amp; performance insights</div>
    <div class="hero-range-chip"><i class="bi bi-calendar-range me-1"></i><?= htmlspecialchars($range_label) ?> &nbsp;·&nbsp; <?= number_format($total_volunteers ?? 0) ?> volunteers</div>
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
      <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
      <div class="kpi-num"><?= number_format($total_volunteers) ?></div>
      <div class="kpi-lbl">Total Volunteers</div>
    </div>
    <div class="kpi kpi-green">
      <div class="kpi-icon"><i class="bi bi-check-circle-fill"></i></div>
      <div class="kpi-num"><?= number_format($available_volunteers) ?></div>
      <div class="kpi-lbl">Available Now</div>
    </div>
    <div class="kpi kpi-amber">
      <div class="kpi-icon"><i class="bi bi-list-check"></i></div>
      <div class="kpi-num"><?= number_format($task_stats['total_tasks'] ?? 0) ?></div>
      <div class="kpi-lbl">Total Tasks</div>
    </div>
    <div class="kpi kpi-purple">
      <div class="kpi-icon"><i class="bi bi-check2-all"></i></div>
      <div class="kpi-num"><?= number_format($task_stats['completed'] ?? 0) ?></div>
      <div class="kpi-lbl">Completed Tasks</div>
    </div>
    <div class="kpi kpi-red">
      <div class="kpi-icon"><i class="bi bi-percent"></i></div>
      <div class="kpi-num"><?= $completion_rate ?><span class="kpi-unit">%</span></div>
      <div class="kpi-lbl">Completion Rate</div>
    </div>
    <div class="kpi kpi-teal">
      <div class="kpi-icon"><i class="bi bi-play-circle-fill"></i></div>
      <div class="kpi-num"><?= number_format($task_stats['in_progress'] ?? 0) ?></div>
      <div class="kpi-lbl">In Progress</div>
    </div>
    <div class="kpi kpi-amber">
      <div class="kpi-icon"><i class="bi bi-clock-history"></i></div>
      <div class="kpi-num"><?= number_format($task_stats['assigned'] ?? 0) ?></div>
      <div class="kpi-lbl">Assigned</div>
    </div>
    <div class="kpi kpi-blue">
      <div class="kpi-icon"><i class="bi bi-trophy-fill"></i></div>
      <div class="kpi-num"><?= count($top_volunteers) ?></div>
      <div class="kpi-lbl">Top Performers</div>
    </div>
  </div>

  <!-- INSIGHTS -->
  <div class="insights">
    <div class="insights-icon"><i class="bi bi-lightbulb-fill"></i></div>
    <div>
      <div class="insights-title">Volunteer Insights — <?= htmlspecialchars($range_label) ?></div>
      <?php if (!empty($top_volunteers)): ?>
      <div class="insight-item">Top volunteer: <strong><?= htmlspecialchars($top_volunteers[0]['full_name']) ?></strong> (<?= $top_volunteers[0]['tasks_completed'] ?> tasks completed)</div>
      <?php endif; ?>
      <div class="insight-item">Most common skill: <strong><?= !empty($top_skills) ? array_key_first($top_skills) : 'N/A' ?></strong> (<?= current($top_skills) ?> volunteers)</div>
      <div class="insight-item">Volunteer engagement rate: <strong><?= $active_volunteers ?>%</strong> task completion</div>
      <?php if (($task_stats['total_tasks'] ?? 0) > 0): ?>
      <div class="insight-item">Average completion time: <strong><?= round(($task_stats['completed'] / max($task_stats['total_tasks'], 1)) * 100) ?>%</strong> of tasks completed</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ACTIVITY TRENDS -->
  <div class="sec">
    <span class="sec-icon" style="background:var(--teal-light);color:var(--teal)"><i class="bi bi-bar-chart-line-fill"></i></span>
    <span class="sec-title">Activity Trends</span>
    <div class="sec-line"></div>
  </div>
  <div class="ccard" style="animation-delay:.05s">
    <div class="ccard-hd">
      <div class="ccard-hd-left">
        <span class="ccard-hd-icon" style="background:var(--teal-light);color:var(--teal)"><i class="bi bi-graph-up"></i></span>
        <span class="ccard-title">Volunteer Activity Trends</span>
      </div>
      <span style="font-family:var(--ff-mono);font-size:.68rem;color:var(--muted)"><?= count($monthly) ?> months analyzed</span>
    </div>
    <div class="cpad"><canvas id="trendsChart" height="260"></canvas></div>
  </div>

  <div class="row g-3" style="margin-bottom:1.1rem">
    <!-- Top Volunteers Table -->
    <div class="col-lg-7">
      <div class="ccard" style="animation-delay:.1s">
        <div class="ccard-hd">
          <div class="ccard-hd-left">
            <span class="ccard-hd-icon" style="background:var(--green-light);color:var(--green)"><i class="bi bi-trophy-fill"></i></span>
            <span class="ccard-title">Top Volunteers</span>
          </div>
          <span style="font-family:var(--ff-mono);font-size:.68rem;color:var(--green)">By completed tasks</span>
        </div>
        <div class="table-responsive">
          <table class="vol-tbl">
            <thead>
              <tr><th>Rank</th><th>Volunteer</th><th>Tasks Completed</th><th>Avg Time</th></tr>
            </thead>
            <tbody>
              <?php if (!empty($top_volunteers)): ?>
                <?php foreach ($top_volunteers as $i => $vol): 
                  $rank_cls = $i===0 ? 'rank-1' : ($i===1 ? 'rank-2' : ($i===2 ? 'rank-3' : 'rank'));
                ?>
                <tr>
                  <td><span class="rank <?= $rank_cls ?>"><?= $i+1 ?></span></td>
                  <td><div class="vol-name"><?= htmlspecialchars($vol['full_name']) ?></div></td>
                  <td><span class="vol-count"><?= $vol['tasks_completed'] ?></span></td>
                  <td><span class="vol-count" style="color:var(--teal)"><?= $vol['avg_task_hours'] ?? '—' ?> hrs</span></td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="4" class="empty-state" style="padding:2rem"><i class="bi bi-inbox"></i><p>No volunteer data available</p></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Top Skills Distribution -->
    <div class="col-lg-5">
      <div class="ccard" style="animation-delay:.15s">
        <div class="ccard-hd">
          <div class="ccard-hd-left">
            <span class="ccard-hd-icon" style="background:var(--purple-light);color:var(--purple)"><i class="bi bi-tags-fill"></i></span>
            <span class="ccard-title">Top Skills</span>
          </div>
          <span style="font-family:var(--ff-mono);font-size:.68rem;color:var(--muted)">Most common expertise</span>
        </div>
        <div class="skill-list">
          <?php if (!empty($top_skills)): ?>
            <?php foreach ($top_skills as $skill => $count): 
              $percentage = ($count / $max_skill) * 100;
            ?>
            <div class="skill-row">
              <div class="skill-name"><?= htmlspecialchars($skill) ?></div>
              <div class="skill-bar-track"><div class="skill-bar-fill" style="width:<?= $percentage ?>%;background:var(--blue)"></div></div>
              <div class="skill-count"><?= $count ?> volunteers</div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="empty-state"><i class="bi bi-inbox"></i><p>No skill data available</p></div>
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

// Activity Trends Chart
new Chart(document.getElementById('trendsChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($months) ?>,
    datasets: [
      { label: 'Total Tasks', data: <?= json_encode($tasks) ?>, borderColor: '#1d6ef5', backgroundColor: 'rgba(29,110,245,.06)', fill: true, tension: .35, borderWidth: 2, pointRadius: 4 },
      { label: 'Completed',   data: <?= json_encode($completed) ?>, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.05)', fill: true, tension: .35, borderWidth: 2, pointRadius: 4 }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: true,
    plugins: { legend: legOpts },
    scales: {
      y: { grid: { color: gridColor }, ticks: tickOpts },
      x: { grid: { display: false }, ticks: tickOpts }
    }
  }
});
</script>
</body>
</html>