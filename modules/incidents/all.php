<?php
/**
 * All Incidents List
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

role_guard(['admin', 'responder']);

$records_per_page = 20;
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$status_filter   = $_GET['status']   ?? '';
$severity_filter = $_GET['severity'] ?? '';
$type_filter     = $_GET['type']     ?? '';
$search          = $_GET['search']   ?? '';

$where = []; $params = [];
if ($status_filter)   { $where[] = "i.status = ?";     $params[] = $status_filter; }
if ($severity_filter) { $where[] = "i.severity = ?";   $params[] = $severity_filter; }
if ($type_filter)     { $where[] = "i.incident_type = ?"; $params[] = $type_filter; }
if ($search) {
    $where[] = "(i.location_name LIKE ? OR i.description LIKE ? OR u.full_name LIKE ?)";
    $t = "%$search%"; $params[] = $t; $params[] = $t; $params[] = $t;
}
$wc = $where ? "WHERE ".implode(" AND ", $where) : "";

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM incidents i LEFT JOIN users u ON i.reporter_id=u.id $wc");
$stmt->execute($params);
$total_records = $stmt->fetch()['total'];
$total_pages   = ceil($total_records / $records_per_page);

$stmt = $pdo->prepare("
    SELECT i.*, u.full_name as reporter_name, u.phone as reporter_phone, r.full_name as responder_name
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id=u.id
    LEFT JOIN users r ON i.assigned_to=r.id
    $wc ORDER BY i.reported_at DESC LIMIT $offset, $records_per_page
");
$stmt->execute($params);
$incidents = $stmt->fetchAll();

$stmt = $pdo->query("
    SELECT COUNT(*) as total,
        SUM(CASE WHEN status='reported'     THEN 1 ELSE 0 END) as reported,
        SUM(CASE WHEN status='acknowledged' THEN 1 ELSE 0 END) as acknowledged,
        SUM(CASE WHEN status='in-progress'  THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status='resolved'     THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN status='cancelled'    THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN status='rejected'     THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN severity=4 THEN 1 ELSE 0 END) as critical,
        SUM(CASE WHEN severity=3 THEN 1 ELSE 0 END) as high,
        SUM(CASE WHEN severity=2 THEN 1 ELSE 0 END) as medium,
        SUM(CASE WHEN severity=1 THEN 1 ELSE 0 END) as low
    FROM incidents i
");
$stats = $stmt->fetch();

$stmt = $pdo->query("SELECT DISTINCT incident_type FROM incidents ORDER BY incident_type");
$incident_types = $stmt->fetchAll();

$sev_cfg = [
    1 => ['label'=>'Low',      'cls'=>'sev-low',  'icon'=>'bi-check-circle-fill'],
    2 => ['label'=>'Medium',   'cls'=>'sev-med',  'icon'=>'bi-info-circle-fill'],
    3 => ['label'=>'High',     'cls'=>'sev-high', 'icon'=>'bi-exclamation-triangle-fill'],
    4 => ['label'=>'Critical', 'cls'=>'sev-crit', 'icon'=>'bi-fire'],
];
$st_cfg = [
    'reported'     => ['label'=>'Reported',     'cls'=>'st-reported'],
    'acknowledged' => ['label'=>'Under Review',  'cls'=>'st-ack'],
    'in-progress'  => ['label'=>'In Progress',   'cls'=>'st-prog'],
    'resolved'     => ['label'=>'Resolved',      'cls'=>'st-resolved'],
    'cancelled'    => ['label'=>'Cancelled',      'cls'=>'st-cancel'],
    'rejected'     => ['label'=>'Rejected',       'cls'=>'st-reject'],
];
$type_icons = [
    'flood'=>'bi-water','fire'=>'bi-fire','earthquake'=>'bi-house-exclamation',
    'landslide'=>'bi-triangle','drought'=>'bi-sun','accident'=>'bi-car-front',
    'building_collapse'=>'bi-buildings','disease_outbreak'=>'bi-bug','other'=>'bi-exclamation-triangle',
];

function safe($v, $d='—') { return !empty($v) ? htmlspecialchars($v) : $d; }

$page_qs = fn($p) => http_build_query(array_filter([
    'page'=>$p,'status'=>$status_filter,'severity'=>$severity_filter,
    'type'=>$type_filter,'search'=>$search
]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>All Incidents — DisasterResponse</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* ═══ TOKENS ══════════════════════════════════════════════════ */
:root {
  --bg:          #f0f2f5;
  --surface:     #ffffff;
  --surface-2:   #f7f8fa;
  --border:      #e2e5ea;
  --border-2:    #d0d4db;
  --navy:        #0f1b2d;
  --red:         #e8271d;
  --red-light:   #fff0ef;
  --red-mid:     #fecaca;
  --amber:       #d97706;
  --amber-light: #fffbeb;
  --blue:        #1d6ef5;
  --blue-light:  #eff5ff;
  --green:       #16a34a;
  --green-light: #f0fdf4;
  --teal:        #0891b2;
  --teal-light:  #ecfeff;
  --purple:      #7c3aed;
  --purple-light:#f5f3ff;
  --orange:      #ea580c;
  --text:        #0f1b2d;
  --text-2:      #374151;
  --muted:       #6b7280;
  --muted-2:     #9ca3af;
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
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:var(--ff-body); background:var(--bg); color:var(--text); font-size:14px; min-height:100vh; }
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:var(--border-2); border-radius:4px; }

/* ─── TOPBAR ─────────────────────────────────────────────── */
.topbar {
  background:var(--navy); height:54px;
  display:flex; align-items:stretch;
  position:sticky; top:0; z-index:300;
  box-shadow:0 2px 12px rgba(15,27,45,.35);
}
.brand {
  display:flex; align-items:center; gap:.5rem;
  padding:0 2rem 0 1.25rem; background:var(--red);
  text-decoration:none; flex-shrink:0;
  clip-path:polygon(0 0,calc(100% - 14px) 0,100% 100%,0 100%);
}
.brand-text { font-family:var(--ff-head); font-weight:800; font-size:1.1rem; color:#fff; text-transform:uppercase; letter-spacing:.03em; }
.brand-sub  { font-family:var(--ff-mono); font-size:.5rem; font-weight:600; color:rgba(255,255,255,.65); letter-spacing:.12em; text-transform:uppercase; display:block; margin-top:-2px; }
.nav-area   { display:flex; align-items:center; padding:0 .75rem; gap:.1rem; flex:1; overflow-x:auto; }
.nav-area::-webkit-scrollbar { height:0; }
.npill {
  display:inline-flex; align-items:center; gap:.3rem;
  padding:.3rem .75rem; border-radius:5px;
  color:rgba(255,255,255,.6); font-size:.78rem; font-weight:500;
  text-decoration:none; white-space:nowrap; transition:all var(--ease);
}
.npill:hover { color:#fff; background:rgba(255,255,255,.1); }
.npill.active { color:#fff; background:rgba(255,255,255,.15); }
.npill i { font-size:.85rem; }
.nbadge { background:var(--red); color:#fff; font-size:.52rem; font-weight:700; font-family:var(--ff-mono); min-width:16px; height:16px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; padding:0 .3rem; }
.nav-right { display:flex; align-items:center; gap:.65rem; padding:0 1.25rem; border-left:1px solid rgba(255,255,255,.08); flex-shrink:0; }
.user-chip  { font-family:var(--ff-mono); font-size:.7rem; color:rgba(255,255,255,.6); white-space:nowrap; }
.logout-btn {
  display:flex; align-items:center; gap:.3rem;
  padding:.28rem .65rem; border-radius:5px;
  border:1px solid rgba(232,39,29,.4); background:rgba(232,39,29,.12);
  color:#ff7a74; font-size:.74rem; font-weight:600;
  text-decoration:none; transition:all var(--ease); white-space:nowrap;
}
.logout-btn:hover { background:var(--red); color:#fff; border-color:var(--red); }

/* ─── PAGE HERO ──────────────────────────────────────────── */
.page-hero {
  background:var(--navy); padding:1.4rem 0;
  border-bottom:3px solid var(--blue);
  position:relative; overflow:hidden;
}
.page-hero::before {
  content:''; position:absolute; right:-40px; top:-40px;
  width:220px; height:220px;
  background:radial-gradient(circle,rgba(29,110,245,.1) 0%,transparent 65%);
  pointer-events:none;
}
.hero-eyebrow { font-family:var(--ff-mono); font-size:.62rem; font-weight:600; letter-spacing:.16em; text-transform:uppercase; color:var(--blue); margin-bottom:.3rem; }
.hero-title   { font-family:var(--ff-head); font-weight:800; font-size:1.75rem; color:#fff; letter-spacing:.02em; text-transform:uppercase; line-height:1.1; }
.hero-sub     { color:rgba(255,255,255,.45); font-size:.8rem; margin-top:.25rem; font-family:var(--ff-mono); }
.hero-chips   { display:flex; gap:.65rem; margin-top:.9rem; flex-wrap:wrap; }
.hero-chip    { display:inline-flex; align-items:center; gap:.35rem; padding:.25rem .8rem; border-radius:20px; font-family:var(--ff-mono); font-size:.68rem; font-weight:600; }
.chip-blue    { background:rgba(29,110,245,.18); border:1px solid rgba(29,110,245,.35); color:#93c5fd; }
.chip-red     { background:rgba(232,39,29,.18);  border:1px solid rgba(232,39,29,.35);  color:#fca5a5; }
.chip-green   { background:rgba(22,163,74,.15);  border:1px solid rgba(22,163,74,.3);   color:#86efac; }

/* ─── PAGE ───────────────────────────────────────────────── */
.page { max-width:1480px; margin:0 auto; padding:1.5rem 1.25rem 4rem; }

/* ─── STATUS TILES ───────────────────────────────────────── */
.tiles-scroll { overflow-x:auto; padding-bottom:.5rem; margin-bottom:1.25rem; }
.tiles-scroll::-webkit-scrollbar { height:3px; }
.tiles-row { display:flex; gap:.65rem; min-width:max-content; }

.s-tile {
  min-width:130px; flex-shrink:0;
  background:var(--surface); border:1.5px solid var(--border);
  border-radius:var(--r-lg); padding:.85rem .9rem .75rem;
  box-shadow:var(--shadow); cursor:pointer;
  transition:transform var(--ease), box-shadow var(--ease), border-color var(--ease);
  text-decoration:none; display:block; position:relative; overflow:hidden;
}
.s-tile::before { content:''; position:absolute; bottom:0; left:0; right:0; height:3px; transition:height var(--ease); }
.s-tile:hover   { transform:translateY(-3px); box-shadow:var(--shadow-lg); }
.s-tile:hover::before { height:4px; }
.s-tile.on      { border-color: transparent; }

.t-all::before     { background:var(--navy); }
.t-reported::before { background:var(--red); }
.t-ack::before     { background:var(--amber); }
.t-prog::before    { background:var(--blue); }
.t-resolved::before{ background:var(--green); }
.t-cancelled::before{ background:var(--muted-2); }
.t-rejected::before { background:var(--purple); }

.t-all.on     { background:rgba(15,27,45,.06);  border-color:var(--navy); }
.t-reported.on{ background:var(--red-light);    border-color:var(--red); }
.t-ack.on     { background:var(--amber-light);  border-color:var(--amber); }
.t-prog.on    { background:var(--blue-light);   border-color:var(--blue); }
.t-resolved.on{ background:var(--green-light);  border-color:var(--green); }
.t-rejected.on{ background:var(--purple-light); border-color:var(--purple); }

.tile-num {
  font-family:var(--ff-head); font-size:1.8rem; font-weight:800; line-height:1;
  letter-spacing:-.01em;
}
.t-all      .tile-num { color:var(--navy); }
.t-reported .tile-num { color:var(--red); }
.t-ack      .tile-num { color:var(--amber); }
.t-prog     .tile-num { color:var(--blue); }
.t-resolved .tile-num { color:var(--green); }
.t-cancelled.tile-num { color:var(--muted); }
.t-rejected .tile-num { color:var(--purple); }
.tile-lbl { font-size:.65rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.1em; margin-top:.2rem; }

/* ─── FILTER BAR ──────────────────────────────────────────── */
.filter-bar {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r-lg); padding:.85rem 1.1rem;
  margin-bottom:1.1rem; box-shadow:var(--shadow);
  display:flex; align-items:center; gap:.65rem; flex-wrap:wrap;
}
.search-wrap { position:relative; flex:1; min-width:200px; }
.search-wrap i { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:var(--muted-2); font-size:.85rem; pointer-events:none; }
.search-input {
  width:100%; padding:.45rem .75rem .45rem 2.1rem;
  font-family:var(--ff-body); font-size:.84rem;
  background:var(--surface-2); color:var(--text);
  border:1.5px solid var(--border); border-radius:var(--r);
  outline:none; transition:border-color var(--ease), box-shadow var(--ease);
}
.search-input:focus { border-color:var(--blue); box-shadow:0 0 0 3px rgba(29,110,245,.1); }
.search-input::placeholder { color:var(--muted-2); }

.filter-select {
  font-family:var(--ff-body); font-size:.82rem;
  background:var(--surface-2); color:var(--text);
  border:1.5px solid var(--border); border-radius:var(--r);
  padding:.42rem .75rem; outline:none; cursor:pointer;
  transition:border-color var(--ease);
}
.filter-select:focus { border-color:var(--blue); }

.btn-apply {
  display:inline-flex; align-items:center; gap:.35rem;
  padding:.42rem 1rem; border-radius:var(--r);
  background:var(--navy); color:#fff; border:none;
  font-family:var(--ff-head); font-size:.8rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.07em;
  cursor:pointer; transition:all var(--ease); white-space:nowrap;
}
.btn-apply:hover { background:var(--blue); }
.btn-reset {
  display:inline-flex; align-items:center; gap:.3rem;
  padding:.42rem .8rem; border-radius:var(--r);
  background:transparent; color:var(--muted); border:1px solid var(--border);
  font-size:.78rem; font-weight:500; text-decoration:none;
  transition:all var(--ease); white-space:nowrap; cursor:pointer;
}
.btn-reset:hover { border-color:var(--border-2); color:var(--text); background:var(--surface-2); }
.btn-export {
  display:inline-flex; align-items:center; gap:.3rem;
  padding:.42rem .85rem; border-radius:var(--r);
  background:var(--green-light); color:var(--green);
  border:1px solid rgba(22,163,74,.3);
  font-size:.78rem; font-weight:600;
  cursor:pointer; transition:all var(--ease); white-space:nowrap; margin-left:auto;
}
.btn-export:hover { background:var(--green); color:#fff; border-color:var(--green); }

/* ─── RESULTS INFO ────────────────────────────────────────── */
.results-bar {
  display:flex; align-items:center; justify-content:space-between;
  margin-bottom:.6rem; flex-wrap:wrap; gap:.5rem;
}
.results-info { font-family:var(--ff-mono); font-size:.7rem; color:var(--muted); }
.results-info strong { color:var(--text); }

/* ─── INCIDENTS TABLE ─────────────────────────────────────── */
.tbl-wrap {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r-xl); box-shadow:var(--shadow);
  overflow:hidden; animation:fadeUp .4s ease both;
}
.itbl { width:100%; border-collapse:collapse; }
.itbl thead tr { background:var(--surface-2); }
.itbl thead th {
  padding:.75rem 1rem;
  font-family:var(--ff-head); font-size:.68rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.12em; color:var(--muted);
  border-bottom:2px solid var(--border); white-space:nowrap;
}
.itbl thead th.sortable { cursor:pointer; user-select:none; }
.itbl thead th.sortable:hover { color:var(--text); }
.itbl tbody tr { border-bottom:1px solid var(--border); transition:background var(--ease); cursor:pointer; }
.itbl tbody tr:last-child { border-bottom:none; }
.itbl tbody tr:hover { background:#f4f6fb; }
.itbl td { padding:.8rem 1rem; font-size:.825rem; color:var(--text-2); vertical-align:middle; }

.inc-id { font-family:var(--ff-mono); font-size:.73rem; font-weight:700; color:var(--navy); }
.inc-type-cell { display:flex; align-items:center; gap:.4rem; }
.type-ico { width:26px; height:26px; border-radius:5px; background:var(--surface-2); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:.78rem; color:var(--muted); flex-shrink:0; }
.inc-loc  { font-size:.8rem; color:var(--text); font-weight:500; max-width:160px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.inc-rep  { font-size:.79rem; color:var(--text-2); }
.inc-date { font-family:var(--ff-mono); font-size:.72rem; color:var(--muted); white-space:nowrap; }
.inc-resp { font-size:.79rem; color:var(--blue); font-weight:500; }
.no-resp  { color:var(--muted-2); }

/* severity/status badges */
.badge-sm {
  display:inline-flex; align-items:center; gap:.25rem;
  padding:.18rem .55rem; border-radius:4px;
  font-family:var(--ff-mono); font-size:.6rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.06em; white-space:nowrap;
}
.sev-crit { background:var(--red-light);    color:var(--red);    border:1px solid var(--red-mid); }
.sev-high { background:var(--amber-light);  color:var(--amber);  border:1px solid #fde68a; }
.sev-med  { background:var(--blue-light);   color:var(--blue);   border:1px solid #bfdbfe; }
.sev-low  { background:var(--green-light);  color:var(--green);  border:1px solid #bbf7d0; }

.st-reported { background:var(--red-light);    color:var(--red);    border:1px solid var(--red-mid); }
.st-ack      { background:var(--amber-light);  color:var(--amber);  border:1px solid #fde68a; }
.st-prog     { background:var(--blue-light);   color:var(--blue);   border:1px solid #bfdbfe; }
.st-resolved { background:var(--green-light);  color:var(--green);  border:1px solid #bbf7d0; }
.st-cancel   { background:#f9fafb; color:var(--muted); border:1px solid var(--border-2); }
.st-reject   { background:var(--purple-light); color:var(--purple); border:1px solid #ddd6fe; }

/* ─── EMPTY STATE ─────────────────────────────────────────── */
.empty-row td { padding:3.5rem 1rem !important; text-align:center; }
.empty-icon { width:56px; height:56px; border-radius:50%; background:var(--surface-2); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:1.4rem; color:var(--muted-2); margin:0 auto .85rem; }

/* ─── PAGINATION ──────────────────────────────────────────── */
.pag-wrap { display:flex; align-items:center; justify-content:space-between; margin-top:1.1rem; flex-wrap:wrap; gap:.75rem; }
.pag-info { font-family:var(--ff-mono); font-size:.7rem; color:var(--muted); }
.pag-info strong { color:var(--text); }
.pag { display:flex; gap:.3rem; align-items:center; }
.pag-btn {
  display:inline-flex; align-items:center; justify-content:center;
  min-width:32px; height:32px; padding:0 .5rem;
  border-radius:var(--r); border:1px solid var(--border);
  background:var(--surface); color:var(--text-2);
  font-family:var(--ff-mono); font-size:.75rem; font-weight:600;
  text-decoration:none; transition:all var(--ease);
}
.pag-btn:hover { border-color:var(--blue); color:var(--blue); background:var(--blue-light); }
.pag-btn.on    { background:var(--navy); border-color:var(--navy); color:#fff; }
.pag-btn.disabled { opacity:.4; pointer-events:none; }
.pag-ellipsis { font-family:var(--ff-mono); font-size:.75rem; color:var(--muted-2); padding:0 .2rem; }

/* ─── SECTION LABEL ──────────────────────────────────────── */
.sec { display:flex; align-items:center; gap:.6rem; margin-bottom:.75rem; }
.sec-icon  { width:26px; height:26px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:.78rem; }
.sec-title { font-family:var(--ff-head); font-size:.78rem; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); }
.sec-line  { flex:1; height:1px; background:var(--border); }

@keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
@media(max-width:768px){ .hero-title{font-size:1.35rem;} .itbl th:nth-child(4), .itbl td:nth-child(4), .itbl th:nth-child(8), .itbl td:nth-child(8) { display:none; } }
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
    <a href="../admin/admin_dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="pending.php" class="npill">
      <i class="bi bi-clock-history"></i> Pending
      <?php if (($stats['reported'] ?? 0) > 0): ?><span class="nbadge"><?= $stats['reported'] ?></span><?php endif; ?>
    </a>
    <a href="all.php" class="npill active"><i class="bi bi-list-ul"></i> All Incidents</a>
    <a href="../analytics/incidents.php" class="npill"><i class="bi bi-graph-up"></i> Analytics</a>
    <a href="../mapping/map.php" class="npill"><i class="bi bi-map"></i> Live Map</a>
    <a href="../resources/manage.php" class="npill"><i class="bi bi-box-seam"></i> Resources</a>
  </div>
  <div class="nav-right">
    <span class="user-chip d-none d-md-flex align-items-center gap-1"><i class="bi bi-person-circle"></i><?= safe($_SESSION['full_name'] ?? 'User') ?></span>
    <a href="/disaster_response/modules/auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
  </div>
</nav>

<!-- PAGE HERO -->
<div class="page-hero">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-list-ul me-1"></i>Incident Management</div>
    <div class="hero-title">All Incidents</div>
    <div class="hero-sub">View, search &amp; manage all emergency reports system-wide</div>
    <div class="hero-chips">
      <span class="hero-chip chip-blue"><i class="bi bi-stack"></i><?= number_format($stats['total'] ?? 0) ?> Total Incidents</span>
      <?php if ($stats['reported'] ?? 0): ?>
        <span class="hero-chip chip-red"><i class="bi bi-clock-history"></i><?= $stats['reported'] ?> Pending Review</span>
      <?php endif; ?>
      <span class="hero-chip chip-green"><i class="bi bi-check-circle-fill"></i><?= number_format($stats['resolved'] ?? 0) ?> Resolved</span>
    </div>
  </div>
</div>

<!-- PAGE -->
<div class="page">

  <!-- STATUS TILES -->
  <div class="tiles-scroll">
    <div class="tiles-row">
      <?php
      $tiles = [
        ['val'=>$stats['total']??0,        'lbl'=>'All Incidents', 'href'=>'all.php',                      'cls'=>'t-all',      'active'=>$status_filter===''],
        ['val'=>$stats['reported']??0,      'lbl'=>'Reported',      'href'=>'all.php?status=reported',      'cls'=>'t-reported', 'active'=>$status_filter==='reported'],
        ['val'=>$stats['acknowledged']??0,  'lbl'=>'Under Review',  'href'=>'all.php?status=acknowledged',  'cls'=>'t-ack',      'active'=>$status_filter==='acknowledged'],
        ['val'=>$stats['in_progress']??0,   'lbl'=>'In Progress',   'href'=>'all.php?status=in-progress',   'cls'=>'t-prog',     'active'=>$status_filter==='in-progress'],
        ['val'=>$stats['resolved']??0,      'lbl'=>'Resolved',      'href'=>'all.php?status=resolved',      'cls'=>'t-resolved', 'active'=>$status_filter==='resolved'],
        ['val'=>$stats['cancelled']??0,     'lbl'=>'Cancelled',     'href'=>'all.php?status=cancelled',     'cls'=>'t-cancelled','active'=>$status_filter==='cancelled'],
        ['val'=>$stats['rejected']??0,      'lbl'=>'Rejected',      'href'=>'all.php?status=rejected',      'cls'=>'t-rejected', 'active'=>$status_filter==='rejected'],
      ];
      foreach ($tiles as $t): ?>
        <a href="<?= $t['href'] ?>" class="s-tile <?= $t['cls'] ?> <?= $t['active']?'on':'' ?>">
          <div class="tile-num"><?= number_format($t['val']) ?></div>
          <div class="tile-lbl"><?= $t['lbl'] ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- FILTER BAR -->
  <div class="filter-bar">
    <div class="search-wrap">
      <i class="bi bi-search"></i>
      <input type="text" id="searchInput" class="search-input"
             placeholder="Search location, reporter, description…"
             value="<?= htmlspecialchars($search) ?>">
    </div>
    <select id="severityFilter" class="filter-select">
      <option value="">All Severities</option>
      <option value="4" <?= $severity_filter=='4'?'selected':'' ?>>Critical</option>
      <option value="3" <?= $severity_filter=='3'?'selected':'' ?>>High</option>
      <option value="2" <?= $severity_filter=='2'?'selected':'' ?>>Medium</option>
      <option value="1" <?= $severity_filter=='1'?'selected':'' ?>>Low</option>
    </select>
    <select id="typeFilter" class="filter-select">
      <option value="">All Types</option>
      <?php foreach ($incident_types as $t): ?>
        <option value="<?= $t['incident_type'] ?>" <?= $type_filter==$t['incident_type']?'selected':'' ?>>
          <?= ucfirst(str_replace('_',' ',$t['incident_type'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button id="applyFilters" class="btn-apply"><i class="bi bi-funnel"></i> Filter</button>
    <a href="all.php" class="btn-reset"><i class="bi bi-arrow-repeat"></i> Reset</a>
    <button id="exportCSV" class="btn-export"><i class="bi bi-download"></i> Export CSV</button>
  </div>

  <!-- RESULTS BAR -->
  <div class="results-bar">
    <div class="sec" style="margin-bottom:0;flex:1">
      <span class="sec-icon" style="background:var(--blue-light);color:var(--blue)"><i class="bi bi-list-ul"></i></span>
      <span class="sec-title">Incident Records</span>
      <div class="sec-line"></div>
      <span style="font-family:var(--ff-mono);font-size:.68rem;color:var(--muted);white-space:nowrap">
        <?= number_format($total_records) ?> result<?= $total_records!=1?'s':'' ?>
        <?= $total_pages>1?' · Page '.$page.' of '.$total_pages:'' ?>
      </span>
    </div>
  </div>

  <!-- TABLE -->
  <div class="tbl-wrap">
    <div class="table-responsive">
      <table class="itbl">
        <thead>
          <tr>
            <th>ID</th>
            <th>Type</th>
            <th>Location</th>
            <th>Reporter</th>
            <th>Severity</th>
            <th>Status</th>
            <th>Reported</th>
            <th>Responder</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($incidents) > 0): ?>
            <?php foreach ($incidents as $idx => $inc):
              $sev = $sev_cfg[$inc['severity']] ?? $sev_cfg[1];
              $st  = $st_cfg[$inc['status']]   ?? ['label'=>ucfirst($inc['status']),'cls'=>'st-cancel'];
              $ico = $type_icons[$inc['incident_type']] ?? 'bi-exclamation-triangle';
            ?>
            <tr onclick="location.href='view.php?id=<?= $inc['id'] ?>'" style="animation:fadeUp .3s ease <?= min($idx*.03,.4) ?>s both">
              <td><span class="inc-id">#<?= str_pad($inc['id'],5,'0',STR_PAD_LEFT) ?></span></td>
              <td>
                <div class="inc-type-cell">
                  <span class="type-ico"><i class="bi <?= $ico ?>"></i></span>
                  <span style="font-size:.8rem;font-weight:500;color:var(--text)"><?= ucfirst(str_replace('_',' ',$inc['incident_type'])) ?></span>
                </div>
              </td>
              <td><span class="inc-loc"><?= safe($inc['location_name'],'—') ?></span></td>
              <td><span class="inc-rep"><?= safe($inc['reporter_name'],'Anonymous') ?></span></td>
              <td><span class="badge-sm <?= $sev['cls'] ?>"><i class="bi <?= $sev['icon'] ?>"></i><?= $sev['label'] ?></span></td>
              <td><span class="badge-sm <?= $st['cls'] ?>"><?= $st['label'] ?></span></td>
              <td><span class="inc-date"><?= date('M j, H:i', strtotime($inc['reported_at'])) ?></span></td>
              <td>
                <?php if (!empty($inc['responder_name'])): ?>
                  <span class="inc-resp"><i class="bi bi-person-fill me-1"></i><?= htmlspecialchars($inc['responder_name']) ?></span>
                <?php else: ?>
                  <span class="no-resp">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr class="empty-row">
              <td colspan="8">
                <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                <div style="font-family:var(--ff-head);font-size:1rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text);margin-bottom:.3rem">No Incidents Found</div>
                <div style="font-size:.82rem;color:var(--muted-2)">Try adjusting your search or filter criteria</div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- PAGINATION -->
  <?php if ($total_pages > 1): ?>
  <div class="pag-wrap">
    <div class="pag-info">
      Showing <strong><?= $offset+1 ?></strong>–<strong><?= min($offset+$records_per_page,$total_records) ?></strong>
      of <strong><?= number_format($total_records) ?></strong> incidents
    </div>
    <div class="pag">
      <a href="?<?= $page_qs($page-1) ?>" class="pag-btn <?= $page<=1?'disabled':'' ?>"><i class="bi bi-chevron-left"></i></a>
      <?php
        $sp = max(1, $page-2); $ep = min($total_pages, $page+2);
        if ($sp > 1) { echo '<a href="?'.$page_qs(1).'" class="pag-btn">1</a>'; if ($sp>2) echo '<span class="pag-ellipsis">…</span>'; }
        for ($i=$sp;$i<=$ep;$i++) echo '<a href="?'.$page_qs($i).'" class="pag-btn '.($i==$page?'on':'').'">'.$i.'</a>';
        if ($ep < $total_pages) { if ($ep<$total_pages-1) echo '<span class="pag-ellipsis">…</span>'; echo '<a href="?'.$page_qs($total_pages).'" class="pag-btn">'.$total_pages.'</a>'; }
      ?>
      <a href="?<?= $page_qs($page+1) ?>" class="pag-btn <?= $page>=$total_pages?'disabled':'' ?>"><i class="bi bi-chevron-right"></i></a>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /page -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function buildUrl() {
  const s = document.getElementById('searchInput').value;
  const sev = document.getElementById('severityFilter').value;
  const typ = document.getElementById('typeFilter').value;
  const p = new URLSearchParams();
  if (s)   p.set('search',   s);
  if (sev) p.set('severity', sev);
  if (typ) p.set('type',     typ);
  return 'all.php' + (p.toString() ? '?'+p.toString() : '');
}

document.getElementById('applyFilters').addEventListener('click', () => location.href = buildUrl());
document.getElementById('searchInput').addEventListener('keypress', e => { if (e.key==='Enter') location.href = buildUrl(); });
document.getElementById('exportCSV').addEventListener('click', () => {
  location.href = buildUrl().replace('all.php','export_incidents.php');
});
</script>
</body>
</html>