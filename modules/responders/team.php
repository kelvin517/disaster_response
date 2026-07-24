<?php
/**
 * Responder Team Management
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

if (!isLoggedIn())             redirect('modules/auth/login.php');
if (!hasRole(['responder','admin'])) redirect('index.php');

$user_id = $_SESSION['user_id'];

/* ─── AJAX: update_location ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'update_location') {
        $lat = (float)$_POST['latitude'];
        $lng = (float)$_POST['longitude'];
        $acc = (int)($_POST['accuracy'] ?? 0);

        // Validate coords
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            http_response_code(400);
            echo json_encode(['success'=>false,'message'=>'Invalid coordinates']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO responder_locations (responder_id, latitude, longitude, accuracy, last_update)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE latitude=?, longitude=?, accuracy=?, last_update=NOW()
            ");
            $stmt->execute([$user_id,$lat,$lng,$acc,$lat,$lng,$acc]);
            echo json_encode(['success'=>true,'message'=>'Location updated','lat'=>$lat,'lng'=>$lng,'acc'=>$acc]);
        } catch (PDOException $e) {
            error_log('Location update error: '.$e->getMessage());
            http_response_code(500);
            echo json_encode(['success'=>false,'message'=>'Database error']);
        }
        exit;
    }

    if ($_POST['action'] === 'get_team_locations') {
        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.phone,
                       rl.latitude, rl.longitude, rl.accuracy, rl.last_update,
                       TIMESTAMPDIFF(MINUTE, rl.last_update, NOW()) AS minutes_ago
                FROM   users u
                LEFT   JOIN responder_locations rl ON u.id = rl.responder_id
                WHERE  u.role = 'responder' AND u.id != ?
                ORDER  BY u.full_name
            ");
            $stmt->execute([$user_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error'=>'Database error']);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error'=>'Unknown action']);
    exit;
}

/* ─── FETCH TEAM MEMBERS ─── */
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.phone, u.email,
               rl.latitude, rl.longitude, rl.accuracy, rl.last_update,
               TIMESTAMPDIFF(MINUTE, rl.last_update, NOW()) AS minutes_ago
        FROM   users u
        LEFT   JOIN responder_locations rl ON u.id = rl.responder_id
        WHERE  u.role = 'responder' AND u.id != ?
        ORDER  BY u.full_name
    ");
    $stmt->execute([$user_id]);
    $team_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $team_members = [];
}

/* ─── MY SAVED LOCATION ─── */
try {
    $stmt = $pdo->prepare("
        SELECT latitude, longitude, accuracy, last_update,
               TIMESTAMPDIFF(MINUTE, last_update, NOW()) AS minutes_ago
        FROM   responder_locations WHERE responder_id = ?
    ");
    $stmt->execute([$user_id]);
    $my_location = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $my_location = null;
}

/* ─── COUNTS ─── */
$total_members = count($team_members);
$active_count  = count(array_filter($team_members, fn($m) => $m['minutes_ago'] !== null && $m['minutes_ago'] <= 5));
$stale_count   = count(array_filter($team_members, fn($m) => $m['minutes_ago'] !== null && $m['minutes_ago'] > 5 && $m['minutes_ago'] <= 30));
$offline_count = count(array_filter($team_members, fn($m) => $m['minutes_ago'] === null || $m['minutes_ago'] > 30));
$located_count = count(array_filter($team_members, fn($m) => !empty($m['latitude'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Team Management — DisasterResponse</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
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
::-webkit-scrollbar { width:4px; height:4px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:var(--border-2); border-radius:3px; }

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
.npill:hover  { color:#fff; background:rgba(255,255,255,.1); }
.npill.active { color:#fff; background:rgba(255,255,255,.15); }
.npill i { font-size:.85rem; }
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
  border-bottom:3px solid var(--teal);
  position:relative; overflow:hidden;
}
.page-hero::before {
  content:''; position:absolute; right:-40px; top:-40px;
  width:220px; height:220px;
  background:radial-gradient(circle,rgba(8,145,178,.12) 0%,transparent 65%);
  pointer-events:none;
}
.hero-eyebrow { font-family:var(--ff-mono); font-size:.62rem; font-weight:600; letter-spacing:.16em; text-transform:uppercase; color:var(--teal); margin-bottom:.3rem; }
.hero-title   { font-family:var(--ff-head); font-weight:800; font-size:1.8rem; color:#fff; letter-spacing:.02em; text-transform:uppercase; line-height:1.1; }
.hero-sub     { color:rgba(255,255,255,.45); font-size:.8rem; margin-top:.25rem; font-family:var(--ff-mono); }

/* ─── PAGE ───────────────────────────────────────────────── */
.page { max-width:1400px; margin:0 auto; padding:1.5rem 1.25rem 4rem; }

/* ─── KPI ROW ────────────────────────────────────────────── */
.kpi-row { display:grid; grid-template-columns:repeat(5,1fr); gap:.75rem; margin-bottom:1.25rem; }
@media(max-width:900px){ .kpi-row { grid-template-columns:repeat(3,1fr); } }
@media(max-width:560px){ .kpi-row { grid-template-columns:1fr 1fr; } }

.kpi {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r-lg); padding:.9rem .95rem .8rem;
  box-shadow:var(--shadow); position:relative; overflow:hidden;
  transition:transform var(--ease), box-shadow var(--ease);
}
.kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.kpi:hover   { transform:translateY(-3px); box-shadow:var(--shadow-lg); }
.kpi-teal::before   { background:var(--teal); }
.kpi-green::before  { background:var(--green); }
.kpi-amber::before  { background:var(--amber); }
.kpi-blue::before   { background:var(--blue); }
.kpi-muted::before  { background:var(--muted-2); }

.kpi-icon { width:32px; height:32px; border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:.9rem; margin-bottom:.55rem; }
.kpi-teal  .kpi-icon { background:var(--teal-light);   color:var(--teal); }
.kpi-green .kpi-icon { background:var(--green-light);  color:var(--green); }
.kpi-amber .kpi-icon { background:var(--amber-light);  color:var(--amber); }
.kpi-blue  .kpi-icon { background:var(--blue-light);   color:var(--blue); }
.kpi-muted .kpi-icon { background:var(--surface-2);    color:var(--muted); }

.kpi-num { font-family:var(--ff-head); font-size:1.7rem; font-weight:800; line-height:1; letter-spacing:-.01em; }
.kpi-teal  .kpi-num { color:var(--teal); }
.kpi-green .kpi-num { color:var(--green); }
.kpi-amber .kpi-num { color:var(--amber); }
.kpi-blue  .kpi-num { color:var(--blue); }
.kpi-muted .kpi-num { color:var(--muted); }
.kpi-lbl { font-size:.65rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.1em; margin-top:.15rem; }

/* ─── MY LOCATION CARD ───────────────────────────────────── */
.loc-card {
  background:var(--surface); border:1px solid var(--border);
  border-left:4px solid var(--green); border-radius:var(--r-lg);
  padding:1rem 1.25rem; margin-bottom:1.1rem; box-shadow:var(--shadow);
  display:flex; align-items:center; justify-content:space-between;
  gap:1rem; flex-wrap:wrap;
}
.loc-card-left { display:flex; align-items:flex-start; gap:.85rem; }
.loc-icon { width:40px; height:40px; border-radius:var(--r); background:var(--green-light); color:var(--green); display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
.loc-label { font-family:var(--ff-head); font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--text-2); margin-bottom:.25rem; }
.loc-status { display:flex; align-items:center; gap:.4rem; font-family:var(--ff-mono); font-size:.7rem; color:var(--muted); }
.loc-coords { font-family:var(--ff-mono); font-size:.7rem; color:var(--muted-2); margin-top:.2rem; }

.status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; flex-shrink:0; }
.dot-active  { background:var(--green); box-shadow:0 0 6px var(--green); }
.dot-stale   { background:var(--amber); box-shadow:0 0 5px var(--amber); }
.dot-offline { background:var(--muted-2); }

@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
.pulse-anim { animation:pulse 2s infinite; }

.btn-share {
  display:inline-flex; align-items:center; gap:.4rem;
  padding:.45rem 1.1rem; border:none; border-radius:var(--r-lg);
  background:var(--green); color:#fff;
  font-family:var(--ff-head); font-size:.82rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.07em;
  cursor:pointer; transition:all var(--ease); white-space:nowrap;
  box-shadow:0 2px 8px rgba(22,163,74,.35);
}
.btn-share:hover { background:#15803d; box-shadow:0 4px 14px rgba(22,163,74,.45); }
.btn-share:disabled { opacity:.6; cursor:not-allowed; }

/* ─── SECTION LABEL ──────────────────────────────────────── */
.sec { display:flex; align-items:center; gap:.6rem; margin-bottom:.85rem; }
.sec-icon  { width:26px; height:26px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:.78rem; }
.sec-title { font-family:var(--ff-head); font-size:.78rem; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); }
.sec-line  { flex:1; height:1px; background:var(--border); }
.sec-count { font-family:var(--ff-mono); font-size:.68rem; color:var(--muted-2); white-space:nowrap; }

/* ─── MAP PANEL ──────────────────────────────────────────── */
.map-panel {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r-xl); box-shadow:var(--shadow); overflow:hidden;
}
.map-panel-hd {
  display:flex; align-items:center; justify-content:space-between;
  padding:.7rem 1rem; background:var(--surface-2);
  border-bottom:1px solid var(--border);
}
.map-panel-title { font-family:var(--ff-head); font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--text-2); display:flex; align-items:center; gap:.45rem; }
#teamMap { height:400px; }
.map-legend {
  display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
  padding:.6rem 1rem;
  background:var(--surface-2); border-top:1px solid var(--border);
  font-size:.72rem; color:var(--muted);
}
.legend-item { display:flex; align-items:center; gap:.35rem; }
.legend-dot  { width:9px; height:9px; border-radius:50%; flex-shrink:0; }

/* ─── TEAM LIST PANEL ────────────────────────────────────── */
.team-panel {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r-xl); box-shadow:var(--shadow); overflow:hidden;
}
.team-panel-hd {
  display:flex; align-items:center; justify-content:space-between;
  padding:.7rem 1rem; background:var(--surface-2);
  border-bottom:1px solid var(--border);
}
.team-panel-title { font-family:var(--ff-head); font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--text-2); display:flex; align-items:center; gap:.45rem; }
.member-count { font-family:var(--ff-mono); font-size:.68rem; background:var(--teal-light); color:var(--teal); border:1px solid rgba(8,145,178,.25); padding:.12rem .5rem; border-radius:20px; }

/* ─── MEMBER CARD ────────────────────────────────────────── */
.member-card {
  display:flex; align-items:center; gap:.85rem;
  padding:.9rem 1rem; border-bottom:1px solid var(--border);
  cursor:pointer; transition:background var(--ease), border-left-color var(--ease);
  border-left:3px solid transparent;
}
.member-card:last-child { border-bottom:none; }
.member-card:hover { background:var(--surface-2); }
.member-card.is-active  { border-left-color:var(--green); background:var(--green-light); }
.member-card.is-stale   { border-left-color:var(--amber); }
.member-card.is-offline { border-left-color:var(--muted-2); }

.member-avatar {
  width:38px; height:38px; flex-shrink:0;
  border-radius:10px; background:var(--surface-2); border:1px solid var(--border);
  display:flex; align-items:center; justify-content:center;
  font-family:var(--ff-head); font-size:1.1rem; font-weight:800; color:var(--muted);
}
.member-avatar.av-active { background:var(--green-light); color:var(--green); border-color:rgba(22,163,74,.25); }
.member-avatar.av-stale  { background:var(--amber-light); color:var(--amber); border-color:rgba(217,119,6,.25); }

.member-info { flex:1; min-width:0; }
.member-name { font-size:.85rem; font-weight:600; color:var(--text); margin-bottom:.15rem; }
.member-phone { font-family:var(--ff-mono); font-size:.68rem; color:var(--muted-2); }
.member-coords { font-family:var(--ff-mono); font-size:.65rem; color:var(--muted-2); margin-top:.15rem; display:flex; align-items:center; gap:.25rem; }

.member-status { text-align:right; flex-shrink:0; }
.status-badge {
  display:inline-flex; align-items:center; gap:.3rem;
  padding:.18rem .55rem; border-radius:4px;
  font-family:var(--ff-mono); font-size:.6rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.06em; white-space:nowrap;
}
.sb-active  { background:var(--green-light);  color:var(--green);  border:1px solid #bbf7d0; }
.sb-stale   { background:var(--amber-light);  color:var(--amber);  border:1px solid #fde68a; }
.sb-offline { background:#f9fafb; color:var(--muted); border:1px solid var(--border-2); }

.zoom-btn {
  display:inline-flex; align-items:center; justify-content:center;
  width:28px; height:28px; border-radius:6px;
  background:var(--surface-2); border:1px solid var(--border);
  color:var(--muted); font-size:.8rem; cursor:pointer;
  transition:all var(--ease); margin-top:.35rem; flex-shrink:0;
}
.zoom-btn:hover { background:var(--teal-light); color:var(--teal); border-color:rgba(8,145,178,.3); }
.zoom-btn:disabled { opacity:.4; cursor:not-allowed; }

/* ─── EMPTY ──────────────────────────────────────────────── */
.empty-team { text-align:center; padding:3rem 1rem; }
.empty-team i { font-size:2rem; display:block; margin-bottom:.6rem; color:var(--muted-2); opacity:.4; }
.empty-team p { font-size:.82rem; color:var(--muted-2); }

/* ─── TOAST ──────────────────────────────────────────────── */
#toast {
  position:fixed; bottom:1.25rem; right:1.25rem; z-index:9999;
  display:flex; align-items:center; gap:.65rem;
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r-lg); box-shadow:var(--shadow-lg);
  padding:.75rem 1.1rem; font-size:.82rem;
  max-width:320px; transform:translateY(120%); opacity:0;
  transition:all .3s cubic-bezier(.4,0,.2,1);
}
#toast.show { transform:translateY(0); opacity:1; }
#toast.t-ok  { border-left:4px solid var(--green); }
#toast.t-err { border-left:4px solid var(--red); }
#toast i { font-size:1rem; flex-shrink:0; }
#toast.t-ok  i { color:var(--green); }
#toast.t-err i { color:var(--red); }

/* ─── LEAFLET OVERRIDES ──────────────────────────────────── */
.leaflet-container { font-family:var(--ff-body) !important; }
.leaflet-control-zoom a { background:var(--surface) !important; color:var(--text-2) !important; border-color:var(--border) !important; }
.leaflet-control-zoom a:hover { background:var(--surface-2) !important; }
.leaflet-popup-content-wrapper { background:var(--surface) !important; color:var(--text) !important; border:1px solid var(--border) !important; border-radius:var(--r-lg) !important; box-shadow:var(--shadow) !important; font-family:var(--ff-body) !important; }
.leaflet-popup-tip { background:var(--surface) !important; }
.leaflet-popup-close-button { color:var(--muted) !important; }

@keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }

@media(max-width:992px){ #teamMap { height:300px; } }
@media(max-width:600px){ .hero-title { font-size:1.35rem; } .kpi-row { grid-template-columns:1fr 1fr; } }
</style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar">
  <a class="brand" href="responders_dashboard.php">
    <i class="bi bi-shield-fill-exclamation" style="color:#fff;font-size:1rem"></i>
    <div>
      <span class="brand-text">Disaster<span style="opacity:.7">Response</span></span>
      <span class="brand-sub">Responder Hub</span>
    </div>
  </a>
  <div class="nav-area">
    <a href="responders_dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="team.php" class="npill active"><i class="bi bi-people-fill"></i> Team</a>
    <a href="updates.php" class="npill"><i class="bi bi-chat-dots"></i> Updates</a>
    <a href="../mapping/map.php" class="npill"><i class="bi bi-map"></i> Live Map</a>
    <a href="../incidents/pending.php" class="npill"><i class="bi bi-clock-history"></i> Pending</a>
  </div>
  <div class="nav-right">
    <span class="user-chip d-none d-md-flex align-items-center gap-1">
      <i class="bi bi-person-circle"></i><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?>
    </span>
    <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')">
      <i class="bi bi-box-arrow-right"></i> Logout
    </a>
  </div>
</nav>

<!-- PAGE HERO -->
<div class="page-hero">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-people-fill me-1"></i>Coordination Hub</div>
    <div class="hero-title">Team Management</div>
    <div class="hero-sub">Track team locations &amp; coordinate field response efforts in real time</div>
  </div>
</div>

<!-- PAGE -->
<div class="page">

  <!-- KPI ROW -->
  <div class="kpi-row">
    <div class="kpi kpi-teal">
      <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
      <div class="kpi-num"><?= $total_members ?></div>
      <div class="kpi-lbl">Team Members</div>
    </div>
    <div class="kpi kpi-green">
      <div class="kpi-icon"><i class="bi bi-broadcast"></i></div>
      <div class="kpi-num"><?= $active_count ?></div>
      <div class="kpi-lbl">Active Now</div>
    </div>
    <div class="kpi kpi-amber">
      <div class="kpi-icon"><i class="bi bi-clock-history"></i></div>
      <div class="kpi-num"><?= $stale_count ?></div>
      <div class="kpi-lbl">Last Seen &lt; 30m</div>
    </div>
    <div class="kpi kpi-muted">
      <div class="kpi-icon"><i class="bi bi-wifi-off"></i></div>
      <div class="kpi-num"><?= $offline_count ?></div>
      <div class="kpi-lbl">Offline</div>
    </div>
    <div class="kpi kpi-blue">
      <div class="kpi-icon"><i class="bi bi-geo-alt-fill"></i></div>
      <div class="kpi-num"><?= $located_count ?></div>
      <div class="kpi-lbl">Location Known</div>
    </div>
  </div>

  <!-- MY LOCATION CARD -->
  <div class="loc-card">
    <div class="loc-card-left">
      <div class="loc-icon"><i class="bi bi-geo-alt-fill"></i></div>
      <div>
        <div class="loc-label">My Location</div>
        <div class="loc-status" id="locStatus">
          <?php if ($my_location && $my_location['minutes_ago'] !== null && $my_location['minutes_ago'] <= 30): ?>
            <span class="status-dot dot-active pulse-anim"></span>
            <span id="locText">Shared <?= $my_location['minutes_ago'] <= 1 ? 'just now' : $my_location['minutes_ago'].' min ago' ?></span>
          <?php else: ?>
            <span class="status-dot dot-offline"></span>
            <span id="locText">Not yet shared this session</span>
          <?php endif; ?>
        </div>
        <div class="loc-coords" id="locCoords">
          <?php if ($my_location): ?>
            <i class="bi bi-pin-map"></i>
            <?= number_format((float)$my_location['latitude'],6) ?>, <?= number_format((float)$my_location['longitude'],6) ?>
            <?php if ($my_location['accuracy']): ?>· ±<?= $my_location['accuracy'] ?>m<?php endif; ?>
          <?php else: ?>
            <i class="bi bi-dash"></i> No saved location
          <?php endif; ?>
        </div>
      </div>
    </div>
    <button id="shareBtn" class="btn-share" onclick="shareLocation()">
      <i class="bi bi-send-fill"></i> Share My Location
    </button>
  </div>

  <!-- MAIN GRID -->
  <div class="row g-3">

    <!-- MAP -->
    <div class="col-lg-7">
      <div class="map-panel">
        <div class="map-panel-hd">
          <div class="map-panel-title">
            <i class="bi bi-map-fill" style="color:var(--teal)"></i> Team Location Map
          </div>
          <div style="font-family:var(--ff-mono);font-size:.65rem;color:var(--muted-2)" id="mapUpdated">Auto-refreshes every 30s</div>
        </div>
        <div id="teamMap"></div>
        <div class="map-legend">
          <span style="font-family:var(--ff-head);font-size:.7rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)">Legend</span>
          <span class="legend-item"><span class="legend-dot" style="background:var(--red)"></span> You</span>
          <span class="legend-item"><span class="legend-dot" style="background:var(--green)"></span> Active (&lt; 5m)</span>
          <span class="legend-item"><span class="legend-dot" style="background:var(--amber)"></span> Stale (5–30m)</span>
          <span class="legend-item"><span class="legend-dot" style="background:var(--muted-2)"></span> Offline (&gt; 30m)</span>
        </div>
      </div>
    </div>

    <!-- TEAM LIST -->
    <div class="col-lg-5">
      <div class="team-panel">
        <div class="team-panel-hd">
          <div class="team-panel-title">
            <i class="bi bi-people-fill" style="color:var(--teal)"></i> Field Team
          </div>
          <span class="member-count"><?= $total_members ?> member<?= $total_members!=1?'s':'' ?></span>
        </div>

        <?php if (!empty($team_members)): ?>
          <div id="memberList">
            <?php foreach ($team_members as $m):
              $mins = $m['minutes_ago'];
              $has_loc  = !empty($m['latitude']);
              $is_active = $has_loc && $mins !== null && $mins <= 5;
              $is_stale  = $has_loc && $mins !== null && $mins > 5 && $mins <= 30;
              $card_cls  = $is_active ? 'is-active' : ($is_stale ? 'is-stale' : 'is-offline');
              $av_cls    = $is_active ? 'av-active' : ($is_stale ? 'av-stale' : '');
              $sb_cls    = $is_active ? 'sb-active'  : ($is_stale ? 'sb-stale' : 'sb-offline');
              $sb_lbl    = $is_active ? 'Active' : ($is_stale ? $mins.'m ago' : 'Offline');
              $dot_cls   = $is_active ? 'dot-active' : ($is_stale ? 'dot-stale' : 'dot-offline');
            ?>
            <div class="member-card <?= $card_cls ?>"
                 onclick="zoomToMember(<?= $has_loc ? (float)$m['latitude'] : 'null' ?>, <?= $has_loc ? (float)$m['longitude'] : 'null' ?>, '<?= htmlspecialchars(addslashes($m['full_name'])) ?>')"
                 data-id="<?= $m['id'] ?>">
              <div class="member-avatar <?= $av_cls ?>"><?= strtoupper(substr($m['full_name'],0,1)) ?></div>
              <div class="member-info">
                <div class="member-name">
                  <span class="status-dot <?= $dot_cls ?> <?= $is_active?'pulse-anim':'' ?>" style="margin-right:.3rem"></span>
                  <?= htmlspecialchars($m['full_name']) ?>
                </div>
                <div class="member-phone">
                  <i class="bi bi-telephone" style="margin-right:.25rem"></i>
                  <?= htmlspecialchars($m['phone'] ?? 'No phone') ?>
                </div>
                <?php if ($has_loc): ?>
                <div class="member-coords">
                  <i class="bi bi-geo-alt" style="color:var(--teal)"></i>
                  <?= number_format((float)$m['latitude'],5) ?>, <?= number_format((float)$m['longitude'],5) ?>
                  <?php if ($m['accuracy']): ?><span style="color:var(--muted-2)">±<?= $m['accuracy'] ?>m</span><?php endif; ?>
                </div>
                <?php else: ?>
                <div class="member-coords"><i class="bi bi-eye-slash"></i> Location not shared</div>
                <?php endif; ?>
              </div>
              <div class="member-status">
                <span class="status-badge <?= $sb_cls ?>"><?= $sb_lbl ?></span>
                <br>
                <button class="zoom-btn" title="Centre on map" <?= !$has_loc?'disabled':'' ?>
                        onclick="event.stopPropagation();zoomToMember(<?= $has_loc?(float)$m['latitude']:'null' ?>,<?= $has_loc?(float)$m['longitude']:'null' ?>,'<?= htmlspecialchars(addslashes($m['full_name'])) ?>')">
                  <i class="bi bi-crosshair2"></i>
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="empty-team">
            <i class="bi bi-people"></i>
            <p>No other team members found.</p>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div><!-- /page -->

<!-- TOAST -->
<div id="toast"><i id="toastIcon" class="bi bi-check-circle-fill"></i><span id="toastMsg"></span></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
/* ─── MAP INIT ─── */
const map = L.map('teamMap', { zoomControl:false }).setView([-1.2921, 36.8219], 10);
L.control.zoom({ position:'bottomleft' }).addTo(map);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution:'© OpenStreetMap contributors', maxZoom:19
}).addTo(map);

const teamLayer = L.layerGroup().addTo(map);
let myMarker = null;

/* ─── MARKER FACTORIES ─── */
function makeMarker(color, size, pulse) {
  const pAnim = pulse ? 'animation:pulse 1.8s ease infinite;' : '';
  return L.divIcon({
    html:`<div style="width:${size}px;height:${size}px;background:${color};border-radius:50%;border:2.5px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.2);${pAnim}"></div>`,
    className:'', iconSize:[size,size], iconAnchor:[size/2,size/2]
  });
}
const iconActive  = makeMarker('#16a34a', 14, true);
const iconStale   = makeMarker('#d97706', 12, false);
const iconOffline = makeMarker('#9ca3af', 10, false);
const iconMe      = makeMarker('#e8271d', 18, true);

/* ─── POPUP TEMPLATE ─── */
function memberPopup(name, lat, lng, phone, mins, acc) {
  const status = mins === null || mins > 30 ? 'Offline'
               : mins <= 5  ? 'Active now'
               : `${mins} min ago`;
  return `<div style="font-family:'Barlow',sans-serif;min-width:170px">
    <div style="font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:.95rem;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.4rem">${name}</div>
    <div style="font-size:.78rem;color:#6b7280;margin-bottom:.25rem"><i class="bi bi-telephone"></i> ${phone||'No phone'}</div>
    <div style="font-family:'IBM Plex Mono',monospace;font-size:.7rem;color:#374151;margin-bottom:.25rem">${lat.toFixed(5)}, ${lng.toFixed(5)}</div>
    ${acc?`<div style="font-size:.72rem;color:#9ca3af">±${acc}m accuracy</div>`:''}
    <div style="margin-top:.4rem"><span style="background:${mins<=5?'#f0fdf4':mins<=30?'#fffbeb':'#f9fafb'};color:${mins<=5?'#16a34a':mins<=30?'#d97706':'#9ca3af'};border:1px solid ${mins<=5?'#bbf7d0':mins<=30?'#fde68a':'#e2e5ea'};font-family:'IBM Plex Mono',monospace;font-size:.6rem;font-weight:700;padding:.15rem .5rem;border-radius:4px;text-transform:uppercase">${status}</span></div>
  </div>`;
}

/* ─── FETCH TEAM LOCATIONS ─── */
function fetchTeamLocations() {
  fetch('team.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=get_team_locations'
  })
  .then(r => { if (!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
  .then(team => {
    teamLayer.clearLayers();
    let bounds = [];

    team.forEach(m => {
      const lat = parseFloat(m.latitude);
      const lng = parseFloat(m.longitude);
      if (!m.latitude || isNaN(lat) || isNaN(lng)) return;

      const mins = parseInt(m.minutes_ago);
      const icon = mins <= 5  ? iconActive
                 : mins <= 30 ? iconStale
                 :              iconOffline;

      L.marker([lat,lng],{icon})
       .bindPopup(memberPopup(m.full_name, lat, lng, m.phone, mins, m.accuracy))
       .addTo(teamLayer);
      bounds.push([lat,lng]);

      /* Update sidebar card status badge */
      const card = document.querySelector(`.member-card[data-id="${m.id}"]`);
      if (card) {
        const badge = card.querySelector('.status-badge');
        if (badge) {
          badge.className = `status-badge ${mins<=5?'sb-active':mins<=30?'sb-stale':'sb-offline'}`;
          badge.textContent = mins<=5?'Active':mins<=30?mins+'m ago':'Offline';
        }
      }
    });

    document.getElementById('mapUpdated').textContent = 'Updated: '+new Date().toLocaleTimeString();

    // Fit bounds if we have locations
    if (bounds.length > 0) {
      if (myMarker) bounds.push(myMarker.getLatLng());
      map.fitBounds(L.latLngBounds(bounds).pad(.2));
    }
  })
  .catch(err => console.warn('Team location fetch error:', err));
}

/* ─── SHARE MY LOCATION ─── */
function shareLocation() {
  const btn = document.getElementById('shareBtn');
  if (!navigator.geolocation) {
    showToast('Geolocation is not supported by your browser.', 'err');
    return;
  }
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-arrow-repeat" style="animation:spin .7s linear infinite"></i> Getting location…';

  navigator.geolocation.getCurrentPosition(
    pos => {
      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;
      const acc = Math.round(pos.coords.accuracy);

      fetch('team.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`action=update_location&latitude=${lat}&longitude=${lng}&accuracy=${acc}`
      })
      .then(r => { if (!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
      .then(data => {
        if (data.success) {
          // Update my marker
          if (myMarker) map.removeLayer(myMarker);
          myMarker = L.marker([lat,lng],{icon:iconMe})
            .bindPopup(`<div style="font-family:'Barlow',sans-serif"><strong>You</strong><br><span style="font-family:'IBM Plex Mono',monospace;font-size:.72rem">${lat.toFixed(5)}, ${lng.toFixed(5)}</span></div>`)
            .addTo(map);
          map.setView([lat,lng], 14);

          // Update sidebar
          document.getElementById('locText').textContent = 'Location shared just now';
          document.getElementById('locCoords').innerHTML = `<i class="bi bi-pin-map"></i> ${lat.toFixed(6)}, ${lng.toFixed(6)} · ±${acc}m`;

          showToast('Location shared successfully!', 'ok');
          fetchTeamLocations();
        } else {
          throw new Error(data.message || 'Server error');
        }
      })
      .catch(err => {
        showToast('Failed to share location: '+err.message, 'err');
      })
      .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send-fill"></i> Share My Location';
      });
    },
    err => {
      showToast('Could not get location: '+err.message, 'err');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-send-fill"></i> Share My Location';
    },
    { enableHighAccuracy:true, timeout:10000, maximumAge:30000 }
  );
}

/* ─── ZOOM TO MEMBER ─── */
function zoomToMember(lat, lng, name) {
  if (lat === null || lng === null || isNaN(lat) || isNaN(lng)) {
    showToast(`${name} hasn't shared their location yet.`, 'err');
    return;
  }
  map.flyTo([lat,lng], 16, {duration:.9});
  L.popup()
   .setLatLng([lat,lng])
   .setContent(`<strong style="font-family:'Barlow Condensed',sans-serif;text-transform:uppercase">${name}</strong>`)
   .openOn(map);
}

/* ─── TOAST ─── */
let toastTimer = null;
function showToast(msg, type) {
  const el   = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  const text = document.getElementById('toastMsg');
  el.className = `show t-${type}`;
  icon.className = `bi ${type==='ok'?'bi-check-circle-fill':'bi-exclamation-triangle-fill'}`;
  text.textContent = msg;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 4000);
}

/* ─── BOOT ─── */
fetchTeamLocations();
setInterval(fetchTeamLocations, 30000);

/* CSS spin for button loading state */
const style = document.createElement('style');
style.textContent='@keyframes spin{to{transform:rotate(360deg)}}@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}';
document.head.appendChild(style);
</script>
</body>
</html>