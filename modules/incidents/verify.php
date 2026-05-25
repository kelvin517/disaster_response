<?php
/**
 * Incident Verification & Dispatch Module
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

role_guard(['admin', 'responder']);

$incident_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($incident_id <= 0) redirect('pending.php');

$stmt = $pdo->prepare("
    SELECT i.*, u.full_name as reporter_name, u.phone as reporter_phone, u.email as reporter_email
    FROM incidents i LEFT JOIN users u ON i.reporter_id = u.id
    WHERE i.id = ? AND i.status = 'reported'
");
$stmt->execute([$incident_id]);
$incident = $stmt->fetch();

if (!$incident) {
    $_SESSION['error'] = "Incident not found or already processed.";
    redirect('pending.php');
}

$stmt = $pdo->prepare("SELECT id, full_name, phone FROM users WHERE role = 'responder' ORDER BY full_name");
$stmt->execute();
$responders = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT resource_type, SUM(quantity) as total_quantity FROM resources WHERE status = 'available' AND quantity > 0 GROUP BY resource_type");
$stmt->execute();
$resources = $stmt->fetchAll();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'verify') {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->query("SHOW COLUMNS FROM incidents LIKE 'verified_by'");
            $has_vb = $stmt->rowCount() > 0;
            if ($has_vb) {
                $stmt = $pdo->prepare("UPDATE incidents SET status='acknowledged', verified_by=?, verified_at=NOW(), updated_at=NOW() WHERE id=?");
                $stmt->execute([$_SESSION['user_id'], $incident_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE incidents SET status='acknowledged', updated_at=NOW() WHERE id=?");
                $stmt->execute([$incident_id]);
            }
            if (!empty($_POST['assign_to'])) {
                $stmt = $pdo->prepare("UPDATE incidents SET assigned_to=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$_POST['assign_to'], $incident_id]);
            }
            $stmt = $pdo->query("SHOW TABLES LIKE 'resource_dispatches'");
            $has_dt = $stmt->rowCount() > 0;
            if (!empty($_POST['resources']) && $has_dt) {
                foreach ($_POST['resources'] as $rt => $qty) {
                    $qty = (int)$qty;
                    if ($qty > 0) {
                        $stmt = $pdo->prepare("INSERT INTO resource_dispatches (incident_id,resource_type,quantity,dispatched_by,dispatched_at) VALUES (?,?,?,?,NOW())");
                        $stmt->execute([$incident_id,$rt,$qty,$_SESSION['user_id']]);
                        $stmt = $pdo->prepare("UPDATE resources SET quantity=quantity-?, updated_at=NOW() WHERE resource_type=? AND status='available' AND quantity>=? LIMIT 1");
                        $stmt->execute([$qty,$rt,$qty]);
                    }
                }
            }
            $stmt = $pdo->query("SHOW TABLES LIKE 'incident_updates'");
            $has_ut = $stmt->rowCount() > 0;
            if (!empty($_POST['verification_note']) && $has_ut) {
                $stmt = $pdo->prepare("INSERT INTO incident_updates (incident_id,user_id,update_text,created_at) VALUES (?,?,?,NOW())");
                $stmt->execute([$incident_id,$_SESSION['user_id'],$_POST['verification_note']]);
            }
            $pdo->commit();
            $_SESSION['success'] = "Incident #{$incident_id} verified and dispatched successfully!";
            redirect('modules/incidents/view.php?id='.$incident_id);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Failed to verify incident: ".$e->getMessage();
        }
    }

    if ($action === 'reject') {
        try {
            $reason = trim($_POST['reason'] ?? 'No reason provided');
            $stmt = $pdo->prepare("UPDATE incidents SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$reason, $incident_id]);
            $stmt = $pdo->query("SHOW TABLES LIKE 'incident_updates'");
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare("INSERT INTO incident_updates (incident_id,user_id,update_text,created_at) VALUES (?,?,?,NOW())");
                $stmt->execute([$incident_id,$_SESSION['user_id'],"Incident rejected. Reason: ".$reason]);
            }
            $_SESSION['success'] = "Incident #{$incident_id} has been marked as rejected.";
            redirect('modules/incidents/pending.php');
        } catch (PDOException $e) {
            $error = "Failed to reject incident: ".$e->getMessage();
        }
    }
}

$severity_config = [
    1 => ['label'=>'Low',      'cls'=>'sev-low',  'icon'=>'bi-check-circle-fill'],
    2 => ['label'=>'Medium',   'cls'=>'sev-med',  'icon'=>'bi-info-circle-fill'],
    3 => ['label'=>'High',     'cls'=>'sev-high', 'icon'=>'bi-exclamation-triangle-fill'],
    4 => ['label'=>'Critical', 'cls'=>'sev-crit', 'icon'=>'bi-fire'],
];
$sev = $severity_config[$incident['severity']] ?? $severity_config[1];

$type_icons = [
    'flood'=>'bi-water','fire'=>'bi-fire','earthquake'=>'bi-house-exclamation',
    'landslide'=>'bi-triangle','drought'=>'bi-sun','accident'=>'bi-car-front',
    'building_collapse'=>'bi-buildings','disease_outbreak'=>'bi-bug','other'=>'bi-exclamation-triangle',
];
$type_icon = $type_icons[$incident['incident_type']] ?? 'bi-exclamation-triangle';

$res_icons = [
    'food'=>'bi-basket-fill','water'=>'bi-droplet-fill','medicine'=>'bi-capsule',
    'shelter'=>'bi-house-fill','clothing'=>'bi-bag-fill','blankets'=>'bi-moon-fill',
    'first_aid'=>'bi-heart-pulse-fill','transport'=>'bi-truck','rescue_team'=>'bi-people-fill','medical_team'=>'bi-hospital-fill',
];

$inc_num = 'INC-'.str_pad($incident_id,5,'0',STR_PAD_LEFT);
$card_cls = match((int)$incident['severity']) { 4=>'crit', 3=>'high', 2=>'med', default=>'low' };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify <?= $inc_num ?> — DisasterResponse</title>
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
  --shadow-lg: 0 4px 24px rgba(15,27,45,.14);
  --ease: .18s cubic-bezier(.4,0,.2,1);
}
*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }
body { font-family: var(--ff-body); background: var(--bg); color: var(--text); font-size: 14px; min-height: 100vh; }
::-webkit-scrollbar { width:5px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:var(--border-2); border-radius:4px; }

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
.nav-right { display:flex; align-items:center; gap:.65rem; padding:0 1.25rem; border-left:1px solid rgba(255,255,255,.08); flex-shrink:0; }
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
  background: var(--navy); padding: 1.4rem 0;
  border-bottom: 3px solid var(--green);
  position: relative; overflow: hidden;
}
.page-hero::before {
  content:''; position:absolute; right:-40px; top:-40px;
  width:220px; height:220px;
  background: radial-gradient(circle, rgba(22,163,74,.1) 0%, transparent 65%);
  pointer-events:none;
}
.hero-breadcrumb {
  display: flex; align-items: center; gap: .45rem;
  font-family:var(--ff-mono); font-size:.62rem; color:rgba(255,255,255,.4);
  margin-bottom:.4rem;
}
.hero-breadcrumb a { color:rgba(255,255,255,.5); text-decoration:none; transition:color var(--ease); }
.hero-breadcrumb a:hover { color:#fff; }
.hero-breadcrumb i { font-size:.55rem; }
.hero-title-row { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
.hero-title { font-family:var(--ff-head); font-weight:800; font-size:1.75rem; color:#fff; letter-spacing:.02em; text-transform:uppercase; line-height:1.1; }
.hero-title span { color:var(--green); }
.hero-sub   { color:rgba(255,255,255,.45); font-size:.8rem; margin-top:.25rem; font-family:var(--ff-mono); }

/* severity badge in hero */
.sev-hero {
  display:inline-flex; align-items:center; gap:.4rem;
  padding:.35rem .9rem; border-radius:6px;
  font-family:var(--ff-mono); font-size:.72rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.07em; white-space:nowrap;
  flex-shrink:0; margin-top:.3rem;
}
.sh-crit { background:rgba(232,39,29,.2); border:1.5px solid rgba(232,39,29,.45); color:#fca5a5; }
.sh-high { background:rgba(217,119,6,.2);  border:1.5px solid rgba(217,119,6,.4);  color:#fcd34d; }
.sh-med  { background:rgba(29,110,245,.2); border:1.5px solid rgba(29,110,245,.4); color:#93c5fd; }
.sh-low  { background:rgba(22,163,74,.18); border:1.5px solid rgba(22,163,74,.35); color:#86efac; }

/* ─── PAGE ───────────────────────────────────────────────── */
.page { max-width: 1280px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }

/* ─── ALERT ──────────────────────────────────────────────── */
.alert-err {
  display:flex; align-items:flex-start; gap:.75rem;
  background:var(--red-light); border:1px solid var(--red-mid);
  border-left:4px solid var(--red); border-radius:var(--r-lg);
  padding:.9rem 1.1rem; margin-bottom:1.25rem;
  font-size:.84rem; color:#991b1b; animation:fadeUp .3s ease;
}
.alert-err i { font-size:1rem; color:var(--red); flex-shrink:0; margin-top:.05rem; }
.alert-err button { margin-left:auto; background:none; border:none; color:#991b1b; cursor:pointer; font-size:1rem; line-height:1; padding:.1rem; }

/* ─── PANELS ─────────────────────────────────────────────── */
.panel {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r-xl); box-shadow:var(--shadow);
  overflow:hidden; margin-bottom:1.1rem;
  animation:fadeUp .4s ease both;
}
.panel.border-crit { border-top:3px solid var(--red); }
.panel.border-high { border-top:3px solid var(--amber); }
.panel.border-med  { border-top:3px solid var(--blue); }
.panel.border-low  { border-top:3px solid var(--green); }

.panel-hd {
  display:flex; align-items:center; gap:.6rem;
  padding:.8rem 1.25rem; background:var(--surface-2);
  border-bottom:1px solid var(--border);
}
.panel-hd-icon { width:28px; height:28px; border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:.85rem; }
.panel-title { font-family:var(--ff-head); font-size:.82rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--text-2); }
.panel-body { padding:1.25rem; }

/* ─── INFO FIELDS ────────────────────────────────────────── */
.field-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
@media(max-width:560px){ .field-grid { grid-template-columns:1fr; } }
.field-full { grid-column:1/-1; }

.field-label {
  font-family:var(--ff-head); font-size:.65rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.12em; color:var(--muted);
  margin-bottom:.25rem; display:block;
}
.field-val {
  font-size:.855rem; font-weight:500; color:var(--text);
  display:flex; align-items:center; gap:.4rem;
  line-height:1.4;
}
.field-val i { color:var(--muted-2); font-size:.85rem; flex-shrink:0; }
.field-val.mono { font-family:var(--ff-mono); font-size:.78rem; }

.type-icon { width:34px; height:34px; border-radius:var(--r); background:var(--surface-2); border:1px solid var(--border); display:inline-flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }

.desc-block {
  background:var(--surface-2); border:1px solid var(--border);
  border-radius:var(--r); padding:.75rem 1rem;
  font-size:.83rem; color:var(--text-2); line-height:1.6;
}

.coord-chip {
  display:inline-flex; align-items:center; gap:.35rem;
  background:rgba(15,27,45,.06); border:1px solid var(--border);
  border-radius:4px; padding:.18rem .55rem;
  font-family:var(--ff-mono); font-size:.72rem; color:var(--text-2);
}

.contact-link { color:var(--blue); text-decoration:none; font-family:var(--ff-mono); font-size:.8rem; }
.contact-link:hover { text-decoration:underline; }

.gmaps-btn {
  display:inline-flex; align-items:center; gap:.35rem;
  padding:.32rem .8rem; border-radius:var(--r);
  border:1px solid var(--border); background:var(--surface-2);
  color:var(--text-2); font-size:.75rem; font-weight:500;
  text-decoration:none; transition:all var(--ease);
  margin-top:.75rem;
}
.gmaps-btn:hover { border-color:var(--blue); color:var(--blue); background:var(--blue-light); }

/* ─── MAP ────────────────────────────────────────────────── */
#incidentMap { height:260px; border-radius:var(--r-lg); overflow:hidden; }

/* ─── EVIDENCE PHOTO ─────────────────────────────────────── */
.evidence-img { width:100%; max-height:280px; object-fit:cover; border-radius:var(--r-lg); border:1px solid var(--border); }

/* ─── FORM ELEMENTS ──────────────────────────────────────── */
.form-label-styled {
  font-family:var(--ff-head); font-size:.72rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.1em; color:var(--text-2);
  margin-bottom:.4rem; display:flex; align-items:center; gap:.35rem;
}
.form-label-styled i { font-size:.85rem; }

.form-select-styled, .form-textarea-styled {
  font-family:var(--ff-body); font-size:.84rem;
  background:var(--surface-2); color:var(--text);
  border:1.5px solid var(--border); border-radius:var(--r);
  padding:.55rem .85rem; width:100%; outline:none;
  transition:border-color var(--ease), box-shadow var(--ease);
  resize:vertical;
}
.form-select-styled:focus, .form-textarea-styled:focus {
  border-color:var(--blue); box-shadow:0 0 0 3px rgba(29,110,245,.1);
}
.form-hint { font-size:.7rem; color:var(--muted-2); margin-top:.3rem; font-family:var(--ff-mono); }

/* Resource row */
.res-row {
  display:flex; align-items:center; gap:.75rem;
  padding:.65rem .85rem; border-radius:var(--r);
  border:1px solid var(--border); background:var(--surface-2);
  margin-bottom:.5rem; transition:border-color var(--ease), background var(--ease);
}
.res-row:hover { border-color:var(--border-2); background:var(--surface); }
.res-icon { width:32px; height:32px; border-radius:7px; background:var(--surface); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:.9rem; color:var(--teal); flex-shrink:0; }
.res-info { flex:1; min-width:0; }
.res-name { font-size:.82rem; font-weight:600; color:var(--text); }
.res-avail { font-family:var(--ff-mono); font-size:.67rem; color:var(--muted); }
.res-qty {
  width:90px; flex-shrink:0;
  font-family:var(--ff-mono); font-size:.8rem;
  background:var(--surface); color:var(--text);
  border:1.5px solid var(--border); border-radius:var(--r);
  padding:.3rem .55rem; text-align:center; outline:none;
  transition:border-color var(--ease), box-shadow var(--ease);
}
.res-qty:focus { border-color:var(--teal); box-shadow:0 0 0 3px rgba(8,145,178,.1); }

.no-resources {
  text-align:center; padding:1.25rem;
  font-size:.82rem; color:var(--muted-2);
}
.no-resources a { color:var(--blue); text-decoration:none; }

/* ─── ACTION BUTTONS ─────────────────────────────────────── */
.divider-label {
  display:flex; align-items:center; gap:.75rem;
  margin:1.25rem 0;
}
.divider-line { flex:1; height:1px; background:var(--border); }
.divider-text { font-family:var(--ff-mono); font-size:.62rem; font-weight:600; letter-spacing:.1em; text-transform:uppercase; color:var(--muted-2); white-space:nowrap; }

.btn-verify-main {
  display:flex; align-items:center; justify-content:center; gap:.5rem;
  width:100%; padding:.75rem; border:none; border-radius:var(--r-lg);
  background:var(--green); color:#fff;
  font-family:var(--ff-head); font-size:.95rem; font-weight:800;
  text-transform:uppercase; letter-spacing:.08em;
  cursor:pointer; transition:all var(--ease);
  box-shadow:0 3px 12px rgba(22,163,74,.35);
}
.btn-verify-main:hover { background:#15803d; box-shadow:0 5px 20px rgba(22,163,74,.45); transform:translateY(-1px); }
.btn-verify-main i { font-size:1.05rem; }

.btn-reject-main {
  display:flex; align-items:center; justify-content:center; gap:.5rem;
  width:100%; padding:.65rem; border:1.5px solid var(--red); border-radius:var(--r-lg);
  background:transparent; color:var(--red);
  font-family:var(--ff-head); font-size:.88rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.07em;
  cursor:pointer; transition:all var(--ease);
}
.btn-reject-main:hover { background:var(--red); color:#fff; box-shadow:0 4px 14px rgba(232,39,29,.3); transform:translateY(-1px); }

/* severity badges inline */
.sev-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .65rem; border-radius:4px; font-family:var(--ff-mono); font-size:.63rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
.sev-crit { background:var(--red-light); color:var(--red); border:1px solid var(--red-mid); }
.sev-high { background:var(--amber-light); color:var(--amber); border:1px solid #fde68a; }
.sev-med  { background:var(--blue-light); color:var(--blue); border:1px solid #bfdbfe; }
.sev-low  { background:var(--green-light); color:var(--green); border:1px solid #bbf7d0; }

@keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
@media(max-width:768px){ .hero-title { font-size:1.3rem; } }
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
    <a href="pending.php" class="npill"><i class="bi bi-clock-history"></i> Pending</a>
    <a href="all.php" class="npill"><i class="bi bi-list-ul"></i> All Incidents</a>
    <a href="../analytics/incidents.php" class="npill"><i class="bi bi-graph-up"></i> Analytics</a>
    <a href="../mapping/map.php" class="npill"><i class="bi bi-map"></i> Live Map</a>
  </div>
  <div class="nav-right">
    <span style="font-family:var(--ff-mono);font-size:.7rem;color:rgba(255,255,255,.5)" class="d-none d-md-block">
      <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?>
    </span>
    <a href="/disaster_response/modules/auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
  </div>
</nav>

<!-- PAGE HERO -->
<div class="page-hero">
  <div class="container">
    <div class="hero-breadcrumb">
      <a href="pending.php"><i class="bi bi-clock-history"></i> Pending</a>
      <i class="bi bi-chevron-right"></i>
      <span>Verify Incident</span>
    </div>
    <div class="hero-title-row">
      <div>
        <div class="hero-title">Verify <span><?= $inc_num ?></span></div>
        <div class="hero-sub">Review details, assign responder &amp; dispatch resources</div>
      </div>
      <span class="sev-hero sh-<?= $card_cls ?>">
        <i class="bi <?= $sev['icon'] ?>"></i><?= $sev['label'] ?> Severity
      </span>
    </div>
  </div>
</div>

<!-- PAGE -->
<div class="page">

  <?php if ($error): ?>
  <div class="alert-err">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <div><?= htmlspecialchars($error) ?></div>
    <button onclick="this.parentElement.remove()"><i class="bi bi-x-lg"></i></button>
  </div>
  <?php endif; ?>

  <div class="row g-3">

    <!-- ── LEFT COL ──────────────────────────────────────────── -->
    <div class="col-lg-6">

      <!-- Incident Details -->
      <div class="panel border-<?= $card_cls ?>" style="animation-delay:.05s">
        <div class="panel-hd">
          <span class="panel-hd-icon" style="background:var(--blue-light);color:var(--blue)"><i class="bi bi-info-circle-fill"></i></span>
          <span class="panel-title">Incident Details</span>
          <span class="sev-badge sev-<?= $card_cls ?> ms-auto"><i class="bi <?= $sev['icon'] ?>"></i><?= $sev['label'] ?></span>
        </div>
        <div class="panel-body">
          <div class="field-grid">
            <div>
              <span class="field-label">Incident Type</span>
              <div class="field-val">
                <span class="type-icon"><i class="bi <?= $type_icon ?>"></i></span>
                <?= ucfirst(str_replace('_',' ',$incident['incident_type'])) ?>
              </div>
            </div>
            <div>
              <span class="field-label">Reported</span>
              <div class="field-val mono"><i class="bi bi-clock"></i><?= date('M j, Y · H:i', strtotime($incident['reported_at'])) ?></div>
            </div>
            <div class="field-full">
              <span class="field-label">Location</span>
              <div class="field-val"><i class="bi bi-geo-alt-fill" style="color:var(--red)"></i><?= htmlspecialchars($incident['location_name'] ?? 'Coordinates only') ?></div>
            </div>
            <div class="field-full">
              <span class="field-label">Coordinates</span>
              <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.1rem">
                <span class="coord-chip"><i class="bi bi-geo"></i> Lat: <?= $incident['latitude'] ?></span>
                <span class="coord-chip"><i class="bi bi-geo"></i> Lng: <?= $incident['longitude'] ?></span>
              </div>
            </div>
            <div>
              <span class="field-label">Reporter</span>
              <div class="field-val"><i class="bi bi-person-fill"></i><?= htmlspecialchars($incident['reporter_name'] ?? 'Anonymous') ?></div>
            </div>
            <div>
              <span class="field-label">Contact</span>
              <div class="field-val">
                <?php if ($incident['reporter_phone']): ?>
                  <i class="bi bi-telephone-fill"></i>
                  <a href="tel:<?= $incident['reporter_phone'] ?>" class="contact-link"><?= htmlspecialchars($incident['reporter_phone']) ?></a>
                <?php else: ?>
                  <i class="bi bi-dash"></i><span style="color:var(--muted-2)">Not provided</span>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($incident['reporter_email']): ?>
            <div class="field-full">
              <span class="field-label">Email</span>
              <div class="field-val"><i class="bi bi-envelope-fill"></i><a href="mailto:<?= $incident['reporter_email'] ?>" class="contact-link"><?= htmlspecialchars($incident['reporter_email']) ?></a></div>
            </div>
            <?php endif; ?>
            <div class="field-full">
              <span class="field-label">Description</span>
              <div class="desc-block"><?= nl2br(htmlspecialchars($incident['description'])) ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Map -->
      <div class="panel" style="animation-delay:.1s">
        <div class="panel-hd">
          <span class="panel-hd-icon" style="background:var(--teal-light);color:var(--teal)"><i class="bi bi-map-fill"></i></span>
          <span class="panel-title">Incident Location</span>
        </div>
        <div class="panel-body">
          <div id="incidentMap"></div>
          <a href="https://www.google.com/maps?q=<?= $incident['latitude'] ?>,<?= $incident['longitude'] ?>" target="_blank" class="gmaps-btn">
            <i class="bi bi-box-arrow-up-right"></i> Open in Google Maps
          </a>
        </div>
      </div>

      <!-- Photo Evidence -->
      <?php if (!empty($incident['photo_path'])): ?>
      <div class="panel" style="animation-delay:.15s">
        <div class="panel-hd">
          <span class="panel-hd-icon" style="background:var(--purple-light,#f5f3ff);color:var(--purple)"><i class="bi bi-image-fill"></i></span>
          <span class="panel-title">Evidence Photo</span>
        </div>
        <div class="panel-body">
          <img src="<?= htmlspecialchars($incident['photo_path']) ?>" alt="Incident evidence" class="evidence-img">
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /left col -->

    <!-- ── RIGHT COL ─────────────────────────────────────────── -->
    <div class="col-lg-6">

      <!-- VERIFY FORM -->
      <div class="panel border-green" style="animation-delay:.05s;border-top:3px solid var(--green)">
        <div class="panel-hd">
          <span class="panel-hd-icon" style="background:var(--green-light);color:var(--green)"><i class="bi bi-shield-check"></i></span>
          <span class="panel-title">Verification &amp; Dispatch</span>
        </div>
        <div class="panel-body">

          <form method="POST" id="verifyForm">
            <input type="hidden" name="action" value="verify">

            <!-- Assign Responder -->
            <div style="margin-bottom:1.2rem">
              <label class="form-label-styled"><i class="bi bi-person-badge-fill" style="color:var(--blue)"></i>Assign Responder</label>
              <select name="assign_to" class="form-select-styled">
                <option value="">— Select a responder (optional) —</option>
                <?php foreach ($responders as $r): ?>
                  <option value="<?= $r['id'] ?>">
                    <?= htmlspecialchars($r['full_name']) ?><?= $r['phone'] ? ' · '.$r['phone'] : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-hint"><i class="bi bi-info-circle me-1"></i>Can also be assigned after verification</div>
            </div>

            <!-- Dispatch Resources -->
            <div style="margin-bottom:1.2rem">
              <label class="form-label-styled"><i class="bi bi-box-seam-fill" style="color:var(--teal)"></i>Dispatch Resources</label>
              <?php if (count($resources) > 0): ?>
                <?php foreach ($resources as $res):
                  $rt   = $res['resource_type'];
                  $rico = $res_icons[$rt] ?? 'bi-box-seam';
                ?>
                <div class="res-row">
                  <div class="res-icon"><i class="bi <?= $rico ?>"></i></div>
                  <div class="res-info">
                    <div class="res-name"><?= ucfirst(str_replace('_',' ',$rt)) ?></div>
                    <div class="res-avail"><?= number_format($res['total_quantity']) ?> units available</div>
                  </div>
                  <input type="number" name="resources[<?= $rt ?>]" class="res-qty"
                         placeholder="0" min="0" max="<?= $res['total_quantity'] ?>" value="0">
                </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="no-resources"><i class="bi bi-inbox" style="display:block;font-size:1.5rem;margin-bottom:.4rem;opacity:.35"></i>No resources available. <a href="../resources/manage.php">Add resources →</a></div>
              <?php endif; ?>
            </div>

            <!-- Verification Note -->
            <div style="margin-bottom:1.25rem">
              <label class="form-label-styled"><i class="bi bi-chat-text-fill" style="color:var(--amber)"></i>Verification Note <span style="font-weight:400;text-transform:none;font-size:.7rem;color:var(--muted)">(optional)</span></label>
              <textarea name="verification_note" class="form-textarea-styled" rows="3" placeholder="Add internal notes about this verification, assessment, or dispatch decision…"></textarea>
            </div>

            <button type="submit" class="btn-verify-main"
                    onclick="return confirm('Verify incident <?= $inc_num ?>? Resources will be dispatched and the reporter notified.')">
              <i class="bi bi-check2-circle"></i> Verify &amp; Dispatch
            </button>
          </form>

          <!-- Reject Section -->
          <div class="divider-label">
            <div class="divider-line"></div>
            <span class="divider-text">or reject incident</span>
            <div class="divider-line"></div>
          </div>

          <form method="POST" id="rejectForm">
            <input type="hidden" name="action" value="reject">
            <div style="margin-bottom:.85rem">
              <label class="form-label-styled"><i class="bi bi-x-circle-fill" style="color:var(--red)"></i>Rejection Reason</label>
              <textarea name="reason" class="form-textarea-styled" rows="2"
                        placeholder="e.g. False alarm, duplicate report, insufficient information…"></textarea>
              <div class="form-hint"><i class="bi bi-exclamation-triangle me-1"></i>The reporter will be notified with this reason</div>
            </div>
            <button type="submit" class="btn-reject-main"
                    onclick="return confirm('Reject incident <?= $inc_num ?>? This action cannot be undone.')">
              <i class="bi bi-x-circle-fill"></i> Reject Incident
            </button>
          </form>

        </div>
      </div>

      <!-- Info Note -->
      <div style="background:var(--surface);border:1px solid var(--border);border-left:4px solid var(--blue);border-radius:var(--r-lg);padding:.9rem 1.1rem;box-shadow:var(--shadow);display:flex;gap:.75rem;align-items:flex-start;">
        <i class="bi bi-lightbulb-fill" style="color:var(--amber);font-size:1rem;flex-shrink:0;margin-top:.1rem"></i>
        <div>
          <div style="font-family:var(--ff-head);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text);margin-bottom:.3rem">Verification Guidelines</div>
          <ul style="font-size:.79rem;color:var(--text-2);padding-left:1.1rem;margin:0;line-height:1.7">
            <li>Cross-check location coordinates on the map before dispatching</li>
            <li>Assign the nearest available responder whenever possible</li>
            <li>Dispatch only the resources required — avoid over-allocation</li>
            <li>Use rejection only for false alarms or confirmed duplicates</li>
          </ul>
        </div>
      </div>

    </div><!-- /right col -->
  </div>

</div><!-- /page -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// Map
const lat = <?= (float)$incident['latitude'] ?>;
const lng = <?= (float)$incident['longitude'] ?>;
const map = L.map('incidentMap').setView([lat, lng], 15);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '© OpenStreetMap contributors'
}).addTo(map);

const dotIcon = L.divIcon({
  html: `<div style="background:#e8271d;width:18px;height:18px;border-radius:50%;border:3px solid #fff;box-shadow:0 2px 10px rgba(0,0,0,.35)"></div>`,
  iconSize:[18,18], iconAnchor:[9,9], className:''
});
L.marker([lat,lng],{icon:dotIcon}).addTo(map)
  .bindPopup(`<strong style="font-family:sans-serif;font-size:13px"><?= $inc_num ?></strong><br><span style="font-size:12px;color:#555"><?= ucfirst(str_replace('_',' ',$incident['incident_type'])) ?> · <?= $sev['label'] ?> Severity</span>`)
  .openPopup();

// highlight qty input when changed from 0
document.querySelectorAll('.res-qty').forEach(input => {
  input.addEventListener('input', function(){
    this.parentElement.style.borderColor = parseInt(this.value) > 0 ? 'var(--teal)' : '';
    this.parentElement.style.background  = parseInt(this.value) > 0 ? 'var(--teal-light)' : '';
  });
});
</script>
</body>
</html>