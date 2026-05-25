<?php
/**
 * Pending Incidents List
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

role_guard(['admin', 'responder']);

$stmt = $pdo->prepare("
    SELECT i.*, u.full_name as reporter_name, u.phone as reporter_phone
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id = u.id
    WHERE i.status = 'reported'
    ORDER BY i.severity DESC, i.reported_at ASC
");
$stmt->execute();
$pending_incidents = $stmt->fetchAll();
$pending_count     = count($pending_incidents);
$high_critical     = count(array_filter($pending_incidents, fn($i) => $i['severity'] >= 3));

$severity_config = [
    1 => ['label'=>'Low',      'var'=>'--green',  'cls'=>'sev-low'],
    2 => ['label'=>'Medium',   'var'=>'--blue',   'cls'=>'sev-med'],
    3 => ['label'=>'High',     'var'=>'--amber',  'cls'=>'sev-high'],
    4 => ['label'=>'Critical', 'var'=>'--red',    'cls'=>'sev-crit'],
];
$type_icons = [
    'flood'=>'bi-water','fire'=>'bi-fire','earthquake'=>'bi-house-exclamation',
    'landslide'=>'bi-triangle','drought'=>'bi-sun','accident'=>'bi-car-front',
    'building_collapse'=>'bi-buildings','disease_outbreak'=>'bi-bug','other'=>'bi-exclamation-triangle',
];

function time_ago($ts) {
    $d = time() - $ts;
    if ($d < 60)     return $d . 's ago';
    if ($d < 3600)   return floor($d/60)   . 'm ago';
    if ($d < 86400)  return floor($d/3600) . 'h ago';
    if ($d < 604800) return floor($d/86400). 'd ago';
    return date('M j, Y', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pending Incidents — DisasterResponse</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
<style>
/* ═══ TOKENS ══════════════════════════════════════════════════ */
:root {
  --bg:           #f0f2f5;
  --surface:      #ffffff;
  --surface-2:    #f7f8fa;
  --border:       #e2e5ea;
  --border-2:     #d0d4db;
  --navy:         #0f1b2d;
  --red:          #e8271d;
  --red-light:    #fff0ef;
  --red-mid:      #fecaca;
  --amber:        #d97706;
  --amber-light:  #fffbeb;
  --blue:         #1d6ef5;
  --blue-light:   #eff5ff;
  --green:        #16a34a;
  --green-light:  #f0fdf4;
  --teal:         #0891b2;
  --teal-light:   #ecfeff;
  --purple:       #7c3aed;
  --purple-light: #f5f3ff;
  --text:         #0f1b2d;
  --text-2:       #374151;
  --muted:        #6b7280;
  --muted-2:      #9ca3af;
  --ff-head: 'Barlow Condensed', sans-serif;
  --ff-body: 'Barlow', sans-serif;
  --ff-mono: 'IBM Plex Mono', monospace;
  --r:     8px;
  --r-lg:  12px;
  --r-xl:  16px;
  --shadow:    0 1px 3px rgba(15,27,45,.08), 0 4px 16px rgba(15,27,45,.06);
  --shadow-lg: 0 4px 24px rgba(15,27,45,.12);
  --ease: .18s cubic-bezier(.4,0,.2,1);
}
*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }
body { font-family: var(--ff-body); background: var(--bg); color: var(--text); font-size: 14px; min-height: 100vh; }
::-webkit-scrollbar { width:5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-2); border-radius: 4px; }

/* ─── TOPBAR ─────────────────────────────────────────────── */
.topbar {
  background: var(--navy); height: 54px;
  display: flex; align-items: stretch;
  position: sticky; top: 0; z-index: 300;
  box-shadow: 0 2px 12px rgba(15,27,45,.35);
}
.brand {
  display: flex; align-items: center; gap: .5rem;
  padding: 0 2rem 0 1.25rem; background: var(--red);
  text-decoration: none; flex-shrink: 0;
  clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 100%, 0 100%);
}
.brand-text { font-family: var(--ff-head); font-weight: 800; font-size: 1.1rem; color: #fff; text-transform: uppercase; letter-spacing: .03em; }
.brand-sub  { font-family: var(--ff-mono); font-size: .5rem; font-weight: 600; color: rgba(255,255,255,.65); letter-spacing: .12em; text-transform: uppercase; display: block; margin-top: -2px; }
.nav-area   { display: flex; align-items: center; padding: 0 .75rem; gap: .1rem; flex: 1; overflow-x: auto; }
.nav-area::-webkit-scrollbar { height: 0; }
.npill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .3rem .75rem; border-radius: 5px;
  color: rgba(255,255,255,.6); font-size: .78rem; font-weight: 500;
  text-decoration: none; white-space: nowrap; transition: all var(--ease);
}
.npill:hover { color:#fff; background: rgba(255,255,255,.1); }
.npill.active { color:#fff; background: rgba(255,255,255,.15); }
.npill i { font-size: .85rem; }
.nav-right { display: flex; align-items: center; gap: .65rem; padding: 0 1.25rem; border-left: 1px solid rgba(255,255,255,.08); flex-shrink: 0; }
.user-chip { font-family: var(--ff-mono); font-size: .7rem; color: rgba(255,255,255,.6); white-space: nowrap; }
.logout-btn {
  display: flex; align-items: center; gap: .3rem;
  padding: .28rem .65rem; border-radius: 5px;
  border: 1px solid rgba(232,39,29,.4); background: rgba(232,39,29,.12);
  color: #ff7a74; font-size: .74rem; font-weight: 600;
  text-decoration: none; transition: all var(--ease); white-space: nowrap;
}
.logout-btn:hover { background: var(--red); color: #fff; border-color: var(--red); }

/* ─── PAGE HERO ──────────────────────────────────────────── */
.page-hero {
  background: var(--navy); padding: 1.4rem 0;
  border-bottom: 3px solid var(--amber);
  position: relative; overflow: hidden;
}
.page-hero::before {
  content:''; position:absolute; right:-40px; top:-40px;
  width:240px; height:240px;
  background: radial-gradient(circle, rgba(217,119,6,.12) 0%, transparent 65%);
  pointer-events: none;
}
.hero-eyebrow {
  font-family: var(--ff-mono); font-size: .62rem; font-weight: 600;
  letter-spacing: .16em; text-transform: uppercase; color: var(--amber); margin-bottom: .3rem;
}
.hero-title { font-family: var(--ff-head); font-weight: 800; font-size: 1.8rem; color: #fff; letter-spacing: .02em; text-transform: uppercase; line-height: 1.1; }
.hero-sub   { color: rgba(255,255,255,.45); font-size: .8rem; margin-top: .25rem; font-family: var(--ff-mono); }
.hero-chips { display: flex; gap: .65rem; margin-top: .9rem; flex-wrap: wrap; }
.hero-chip  {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .25rem .8rem; border-radius: 20px;
  font-family: var(--ff-mono); font-size: .68rem; font-weight: 600;
}
.chip-amber { background: rgba(217,119,6,.18); border: 1px solid rgba(217,119,6,.35); color: #fcd34d; }
.chip-red   { background: rgba(232,39,29,.18); border: 1px solid rgba(232,39,29,.35); color: #fca5a5; }
.chip-green { background: rgba(22,163,74,.15);  border: 1px solid rgba(22,163,74,.3);  color: #86efac; }

/* ─── PAGE ───────────────────────────────────────────────── */
.page { max-width: 1100px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }

/* ─── KPI ROW ────────────────────────────────────────────── */
.kpi-row { display: grid; grid-template-columns: repeat(4,1fr); gap: .85rem; margin-bottom: 1.5rem; }
@media(max-width:768px){ .kpi-row { grid-template-columns: 1fr 1fr; } }

.kpi {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r-lg); padding: 1rem 1rem .85rem;
  box-shadow: var(--shadow); position: relative; overflow: hidden;
  transition: transform var(--ease), box-shadow var(--ease);
}
.kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.kpi:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
.kpi-red::before    { background: var(--red); }
.kpi-amber::before  { background: var(--amber); }
.kpi-blue::before   { background: var(--blue); }
.kpi-green::before  { background: var(--green); }

.kpi-icon { width:34px; height:34px; border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:.95rem; margin-bottom:.65rem; }
.kpi-red   .kpi-icon { background:var(--red-light);   color:var(--red); }
.kpi-amber .kpi-icon { background:var(--amber-light);  color:var(--amber); }
.kpi-blue  .kpi-icon { background:var(--blue-light);   color:var(--blue); }
.kpi-green .kpi-icon { background:var(--green-light);  color:var(--green); }

.kpi-num { font-family: var(--ff-head); font-size:2rem; font-weight:800; line-height:1; }
.kpi-red   .kpi-num { color:var(--red); }
.kpi-amber .kpi-num { color:var(--amber); }
.kpi-blue  .kpi-num { color:var(--blue); }
.kpi-green .kpi-num { color:var(--green); }
.kpi-lbl { font-size:.67rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.1em; margin-top:.15rem; }

/* ─── SECTION LABEL ──────────────────────────────────────── */
.sec { display:flex; align-items:center; gap:.6rem; margin-bottom:.85rem; }
.sec-icon  { width:26px; height:26px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:.78rem; }
.sec-title { font-family:var(--ff-head); font-size:.78rem; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); }
.sec-line  { flex:1; height:1px; background:var(--border); }
.sec-count { font-family:var(--ff-mono); font-size:.7rem; font-weight:600; background:var(--red-light); color:var(--red); border:1px solid var(--red-mid); padding:.12rem .55rem; border-radius:20px; }

/* ─── SORT/FILTER BAR ─────────────────────────────────────── */
.toolbar {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r-lg); padding: .65rem 1rem;
  margin-bottom: 1rem; box-shadow: var(--shadow);
  display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
}
.toolbar-label { font-family: var(--ff-head); font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); white-space: nowrap; }
.filter-chip {
  padding: .22rem .7rem; border-radius: 20px;
  font-family: var(--ff-mono); font-size: .68rem; font-weight: 600;
  border: 1px solid var(--border); background: var(--surface-2);
  color: var(--muted); cursor: pointer; transition: all var(--ease);
  white-space: nowrap;
}
.filter-chip:hover, .filter-chip.on { border-color: var(--red); background: var(--red-light); color: var(--red); }
.toolbar-right { margin-left: auto; }
.refresh-btn {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .3rem .8rem; border-radius: var(--r); border: 1px solid var(--border);
  background: var(--surface-2); color: var(--muted);
  font-size: .75rem; font-weight: 500; text-decoration: none;
  transition: all var(--ease); cursor: pointer;
}
.refresh-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--surface); }

/* ─── INCIDENT CARD ──────────────────────────────────────── */
.inc-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r-xl); box-shadow: var(--shadow);
  margin-bottom: .9rem; overflow: hidden;
  transition: transform var(--ease), box-shadow var(--ease), border-color var(--ease);
  animation: fadeUp .35s ease both;
}
.inc-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
.inc-card.crit  { border-left: 4px solid var(--red); }
.inc-card.high  { border-left: 4px solid var(--amber); }
.inc-card.med   { border-left: 4px solid var(--blue); }
.inc-card.low   { border-left: 4px solid var(--green); }

/* Card header */
.inc-hd {
  display: flex; align-items: center; justify-content: space-between;
  padding: .75rem 1.25rem; background: var(--surface-2);
  border-bottom: 1px solid var(--border); gap: .75rem; flex-wrap: wrap;
}
.inc-hd-left  { display: flex; align-items: center; gap: .65rem; flex-wrap: wrap; }

.inc-id {
  font-family: var(--ff-mono); font-size: .72rem; font-weight: 700;
  color: var(--navy); background: rgba(15,27,45,.07);
  border: 1px solid rgba(15,27,45,.12);
  padding: .2rem .6rem; border-radius: 4px; white-space: nowrap;
}

.sev-badge {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .2rem .65rem; border-radius: 4px;
  font-family: var(--ff-mono); font-size: .63rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em; white-space: nowrap;
}
.sev-crit { background: var(--red-light);    color: var(--red);    border: 1px solid #fecaca; }
.sev-high { background: var(--amber-light);  color: var(--amber);  border: 1px solid #fde68a; }
.sev-med  { background: var(--blue-light);   color: var(--blue);   border: 1px solid #bfdbfe; }
.sev-low  { background: var(--green-light);  color: var(--green);  border: 1px solid #bbf7d0; }

.time-chip {
  font-family: var(--ff-mono); font-size: .67rem; color: var(--muted-2);
  display: inline-flex; align-items: center; gap: .25rem;
}
.inc-hd-right { display: flex; align-items: center; gap: .5rem; }

/* verify button */
.btn-verify {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .32rem .9rem; border-radius: var(--r);
  background: var(--green); color: #fff;
  font-family: var(--ff-head); font-size: .78rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .07em;
  text-decoration: none; border: none;
  transition: all var(--ease); white-space: nowrap;
  box-shadow: 0 2px 8px rgba(22,163,74,.3);
}
.btn-verify:hover { background: #15803d; color: #fff; box-shadow: 0 4px 14px rgba(22,163,74,.45); transform: translateY(-1px); }

/* Card body */
.inc-body { padding: 1.1rem 1.25rem; }
.inc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
@media(max-width:560px){ .inc-grid { grid-template-columns: 1fr; } }

.field-label {
  font-family: var(--ff-head); font-size: .65rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .1em; color: var(--muted);
  margin-bottom: .2rem;
}
.field-val {
  font-size: .84rem; font-weight: 500; color: var(--text);
  display: flex; align-items: center; gap: .35rem;
}
.field-val i { color: var(--muted-2); font-size: .85rem; }
.field-val.mono { font-family: var(--ff-mono); font-size: .78rem; }

.type-icon-box {
  width: 32px; height: 32px; border-radius: var(--r);
  display: inline-flex; align-items: center; justify-content: center;
  font-size: .95rem; flex-shrink: 0;
  background: var(--surface-2); border: 1px solid var(--border);
}
.desc-text {
  font-size: .81rem; color: var(--text-2); line-height: 1.55;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.location-val {
  font-size: .82rem; color: var(--text-2);
  display: flex; align-items: flex-start; gap: .35rem;
}
.location-val i { color: var(--red); margin-top: .1rem; flex-shrink: 0; }

/* action footer */
.inc-footer {
  display: flex; align-items: center; gap: .5rem;
  padding: .75rem 1.25rem 1rem; flex-wrap: wrap;
}
.btn-action {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .28rem .75rem; border-radius: var(--r);
  font-size: .75rem; font-weight: 500;
  text-decoration: none; transition: all var(--ease);
  border: 1px solid var(--border); background: var(--surface-2); color: var(--text-2);
  cursor: pointer;
}
.btn-action:hover { background: var(--surface); border-color: var(--navy); color: var(--navy); }
.btn-action.blue  { border-color: rgba(29,110,245,.25); color: var(--blue); background: var(--blue-light); }
.btn-action.blue:hover { background: var(--blue); color: #fff; border-color: var(--blue); }

/* ─── EMPTY STATE ─────────────────────────────────────────── */
.empty-state {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r-xl); box-shadow: var(--shadow);
  text-align: center; padding: 4rem 2rem;
}
.empty-icon { width: 72px; height: 72px; border-radius: 50%; background: var(--green-light); color: var(--green); display: flex; align-items: center; justify-content: center; font-size: 1.8rem; margin: 0 auto 1.2rem; }
.empty-title { font-family: var(--ff-head); font-size: 1.3rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text); margin-bottom: .35rem; }
.empty-sub   { font-size: .84rem; color: var(--muted); margin-bottom: 1.3rem; }
.btn-outline-nav {
  display: inline-flex; align-items: center; gap: .4rem;
  padding: .45rem 1.1rem; border-radius: var(--r);
  border: 1.5px solid var(--navy); color: var(--navy); background: transparent;
  font-family: var(--ff-head); font-size: .82rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .08em;
  text-decoration: none; transition: all var(--ease);
}
.btn-outline-nav:hover { background: var(--navy); color: #fff; }

/* ─── MAP MODAL ───────────────────────────────────────────── */
.modal-content { border: none; border-radius: var(--r-xl); overflow: hidden; box-shadow: var(--shadow-lg); }
.modal-hd {
  display: flex; align-items: center; justify-content: space-between;
  padding: .85rem 1.25rem; background: var(--navy);
  border-bottom: 3px solid var(--red);
}
.modal-hd-title { font-family: var(--ff-head); font-weight: 700; font-size: 1rem; text-transform: uppercase; letter-spacing: .08em; color: #fff; display: flex; align-items: center; gap: .4rem; }
.modal-close { background: none; border: none; color: rgba(255,255,255,.6); font-size: 1.1rem; cursor: pointer; transition: color var(--ease); line-height: 1; padding: .1rem; }
.modal-close:hover { color: #fff; }

@keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }

@media(max-width:768px){
  .hero-title { font-size:1.35rem; }
  .inc-hd-right .btn-verify span { display:none; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar">
  <a class="brand" href="/disaster_response/index.php">
    <i class="bi bi-shield-fill-exclamation" style="color:#fff;font-size:1.1rem"></i>
    <div>
      <span class="brand-text">Disaster<span style="opacity:.7">Response</span></span>
      <span class="brand-sub">Admin Console</span>
    </div>
  </a>
  <div class="nav-area">
    <a href="../admin/dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="pending.php" class="npill active"><i class="bi bi-clock-history"></i> Pending
      <?php if ($pending_count > 0): ?>
        <span style="background:var(--red);color:#fff;font-size:.52rem;font-family:var(--ff-mono);font-weight:700;min-width:16px;height:16px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;padding:0 .3rem"><?= $pending_count ?></span>
      <?php endif; ?>
    </a>
    <a href="all.php" class="npill"><i class="bi bi-list-ul"></i> All Incidents</a>
    <a href="../analytics/incidents.php" class="npill"><i class="bi bi-graph-up"></i> Analytics</a>
    <a href="../mapping/map.php" class="npill"><i class="bi bi-map"></i> Live Map</a>
  </div>
  <div class="nav-right">
    <span class="user-chip d-none d-md-flex align-items-center gap-1"><i class="bi bi-person-circle"></i><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?></span>
    <a href="/disaster_response/modules/auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
  </div>
</nav>

<!-- PAGE HERO -->
<div class="page-hero">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-clock-history me-1"></i>Incident Management</div>
    <div class="hero-title">Pending Verification</div>
    <div class="hero-sub">Review and verify incoming incident reports before dispatch</div>
    <div class="hero-chips">
      <span class="hero-chip chip-amber"><i class="bi bi-hourglass-split"></i><?= $pending_count ?> Awaiting Review</span>
      <?php if ($high_critical > 0): ?>
      <span class="hero-chip chip-red"><i class="bi bi-fire"></i><?= $high_critical ?> High / Critical</span>
      <?php endif; ?>
      <span class="hero-chip chip-green"><i class="bi bi-calendar3"></i><?= date('l, M j Y') ?></span>
    </div>
  </div>
</div>

<!-- PAGE -->
<div class="page">

  <!-- KPI ROW -->
  <div class="kpi-row">
    <div class="kpi kpi-amber">
      <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
      <div class="kpi-num"><?= $pending_count ?></div>
      <div class="kpi-lbl">Pending Verification</div>
    </div>
    <div class="kpi kpi-red">
      <div class="kpi-icon"><i class="bi bi-fire"></i></div>
      <div class="kpi-num"><?= $high_critical ?></div>
      <div class="kpi-lbl">High / Critical</div>
    </div>
    <div class="kpi kpi-blue">
      <div class="kpi-icon"><i class="bi bi-shield-fill"></i></div>
      <div class="kpi-num"><?= count(array_filter($pending_incidents, fn($i) => $i['severity'] <= 2)) ?></div>
      <div class="kpi-lbl">Low / Medium</div>
    </div>
    <div class="kpi kpi-green">
      <div class="kpi-icon"><i class="bi bi-clock"></i></div>
      <div class="kpi-num"><?= $pending_count > 0 ? round((time() - strtotime($pending_incidents[count($pending_incidents)-1]['reported_at'])) / 60) : 0 ?><span style="font-size:1rem;font-weight:500">m</span></div>
      <div class="kpi-lbl">Oldest Pending</div>
    </div>
  </div>

  <?php if (empty($pending_incidents)): ?>

    <!-- EMPTY STATE -->
    <div class="empty-state">
      <div class="empty-icon"><i class="bi bi-check-circle-fill"></i></div>
      <div class="empty-title">All Clear — No Pending Incidents</div>
      <div class="empty-sub">All incident reports have been reviewed. Great work, team!</div>
      <a href="all.php" class="btn-outline-nav"><i class="bi bi-list-ul"></i> View All Incidents</a>
    </div>

  <?php else: ?>

    <!-- TOOLBAR -->
    <div class="toolbar">
      <span class="toolbar-label">Filter by Severity</span>
      <span class="filter-chip on" onclick="filterCards('all', this)">All (<?= $pending_count ?>)</span>
      <?php
        $crits = count(array_filter($pending_incidents, fn($i) => $i['severity'] == 4));
        $highs = count(array_filter($pending_incidents, fn($i) => $i['severity'] == 3));
        $meds  = count(array_filter($pending_incidents, fn($i) => $i['severity'] == 2));
        $lows  = count(array_filter($pending_incidents, fn($i) => $i['severity'] == 1));
        if ($crits) echo "<span class='filter-chip' onclick=\"filterCards('4', this)\">Critical ($crits)</span>";
        if ($highs) echo "<span class='filter-chip' onclick=\"filterCards('3', this)\">High ($highs)</span>";
        if ($meds)  echo "<span class='filter-chip' onclick=\"filterCards('2', this)\">Medium ($meds)</span>";
        if ($lows)  echo "<span class='filter-chip' onclick=\"filterCards('1', this)\">Low ($lows)</span>";
      ?>
      <div class="toolbar-right">
        <a href="pending.php" class="refresh-btn"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
      </div>
    </div>

    <!-- SECTION HEADING -->
    <div class="sec">
      <span class="sec-icon" style="background:var(--amber-light);color:var(--amber)"><i class="bi bi-clock-history"></i></span>
      <span class="sec-title">Incident Queue</span>
      <span class="sec-count"><?= $pending_count ?> pending</span>
      <div class="sec-line"></div>
    </div>

    <!-- INCIDENT CARDS -->
    <?php foreach ($pending_incidents as $idx => $incident):
      $sev   = $severity_config[$incident['severity']] ?? $severity_config[1];
      $icon  = $type_icons[$incident['incident_type']] ?? 'bi-exclamation-triangle';
      $ago   = time_ago(strtotime($incident['reported_at']));
      $card_cls = match((int)$incident['severity']) { 4=>'crit', 3=>'high', 2=>'med', default=>'low' };
      $delay = min($idx * 0.04, 0.4);
    ?>
    <div class="inc-card <?= $card_cls ?>" data-sev="<?= $incident['severity'] ?>" style="animation-delay:<?= $delay ?>s">

      <!-- Header -->
      <div class="inc-hd">
        <div class="inc-hd-left">
          <span class="inc-id"><i class="bi bi-hash"></i>INC-<?= str_pad($incident['id'],5,'0',STR_PAD_LEFT) ?></span>
          <span class="sev-badge <?= $sev['cls'] ?>">
            <?php if ($incident['severity'] == 4): ?><i class="bi bi-fire"></i><?php
            elseif ($incident['severity'] == 3): ?><i class="bi bi-exclamation-triangle-fill"></i><?php
            elseif ($incident['severity'] == 2): ?><i class="bi bi-info-circle-fill"></i><?php
            else: ?><i class="bi bi-check-circle"></i><?php endif; ?>
            <?= $sev['label'] ?> Severity
          </span>
          <span class="time-chip"><i class="bi bi-clock"></i><?= $ago ?></span>
        </div>
        <div class="inc-hd-right">
          <a href="verify.php?id=<?= $incident['id'] ?>" class="btn-verify">
            <i class="bi bi-check2-circle"></i><span>Verify &amp; Dispatch</span>
          </a>
        </div>
      </div>

      <!-- Body -->
      <div class="inc-body">
        <div class="inc-grid">
          <div>
            <div class="field-label">Incident Type</div>
            <div class="field-val">
              <span class="type-icon-box"><i class="bi <?= $icon ?>"></i></span>
              <?= ucfirst(str_replace('_',' ',$incident['incident_type'])) ?>
            </div>
          </div>
          <div>
            <div class="field-label">Reported By</div>
            <div class="field-val"><i class="bi bi-person-fill"></i>
              <?= htmlspecialchars($incident['reporter_name'] ?? 'Anonymous') ?>
              <?php if ($incident['reporter_phone']): ?>
                <span class="mono" style="font-family:var(--ff-mono);font-size:.72rem;color:var(--muted)"> · <?= htmlspecialchars($incident['reporter_phone']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div style="margin-bottom:.85rem">
          <div class="field-label">Location</div>
          <div class="location-val">
            <i class="bi bi-geo-alt-fill"></i>
            <span><?= htmlspecialchars($incident['location_name'] ?? 'Coordinates: '.$incident['latitude'].', '.$incident['longitude']) ?></span>
          </div>
        </div>

        <div>
          <div class="field-label">Description</div>
          <div class="desc-text"><?= htmlspecialchars($incident['description']) ?></div>
        </div>
      </div>

      <!-- Footer actions -->
      <div class="inc-footer">
        <a href="verify.php?id=<?= $incident['id'] ?>" class="btn-action blue"><i class="bi bi-check-lg"></i> Verify</a>
        <a href="view.php?id=<?= $incident['id'] ?>"   class="btn-action"><i class="bi bi-eye"></i> Details</a>
        <button onclick="showMap(<?= (float)$incident['latitude'] ?>, <?= (float)$incident['longitude'] ?>, '<?= htmlspecialchars(addslashes($incident['location_name'] ?? 'Incident')) ?>')" class="btn-action"><i class="bi bi-map"></i> Map</button>
        <span style="margin-left:auto;font-family:var(--ff-mono);font-size:.67rem;color:var(--muted-2)">
          <i class="bi bi-calendar3 me-1"></i><?= date('M j, Y · H:i', strtotime($incident['reported_at'])) ?>
        </span>
      </div>

    </div>
    <?php endforeach; ?>

  <?php endif; ?>

</div><!-- /page -->

<!-- MAP MODAL -->
<div class="modal fade" id="mapModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-hd">
        <span class="modal-hd-title"><i class="bi bi-geo-alt-fill"></i> Incident Location</span>
        <button class="modal-close" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i></button>
      </div>
      <div style="padding:.75rem 1.1rem;background:var(--surface-2);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.5rem">
        <i class="bi bi-info-circle" style="color:var(--blue);font-size:.85rem"></i>
        <span id="mapLocLabel" style="font-size:.8rem;color:var(--text-2)">Loading…</span>
      </div>
      <div id="modalMap" style="height:420px;width:100%"></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
let modalMap = null;

function showMap(lat, lng, label) {
  document.getElementById('mapLocLabel').textContent = label;
  const el = document.getElementById('mapModal');
  const modal = new bootstrap.Modal(el);
  el.addEventListener('shown.bs.modal', function handler() {
    if (modalMap) { modalMap.remove(); modalMap = null; }
    modalMap = L.map('modalMap').setView([lat, lng], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors'
    }).addTo(modalMap);
    const icon = L.divIcon({
      html: '<div style="background:#e8271d;width:16px;height:16px;border-radius:50%;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.35)"></div>',
      iconSize:[16,16], iconAnchor:[8,8], className:''
    });
    L.marker([lat, lng], {icon}).addTo(modalMap)
      .bindPopup(`<strong style="font-family:sans-serif">${label}</strong><br><small style="font-family:monospace">${lat.toFixed(5)}, ${lng.toFixed(5)}</small>`)
      .openPopup();
    el.removeEventListener('shown.bs.modal', handler);
  }, {once:true});
  modal.show();
}

// Severity filter
function filterCards(sev, el) {
  document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('on'));
  el.classList.add('on');
  document.querySelectorAll('.inc-card').forEach(card => {
    card.style.display = (sev === 'all' || card.dataset.sev === sev) ? '' : 'none';
  });
}

// Auto-refresh
setTimeout(() => location.reload(), 60000);
</script>
</body>
</html>