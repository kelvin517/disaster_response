<?php
/**
 * Track My Reports — Victim Status Page
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

if (!isLoggedIn()) redirect('modules/auth/login.php');

$user_id        = $_SESSION['user_id'];
$update_success = null;
$update_error   = null;

/* ─── ADD UPDATE ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_update') {
    $incident_id     = (int)$_POST['incident_id'];
    $additional_info = trim($_POST['additional_info'] ?? '');
    if (empty($additional_info)) {
        $update_error = "Please enter some additional information before submitting.";
    } else {
        $chk = $pdo->prepare("SELECT id FROM incidents WHERE id = ? AND reporter_id = ?");
        $chk->execute([$incident_id, $user_id]);
        if (!$chk->fetch()) {
            $update_error = "You are not authorised to update this report.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO incident_updates (incident_id, user_id, update_text, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$incident_id, $user_id, $additional_info]);
                $update_success = "Update added successfully. Responders have been notified.";
            } catch (PDOException $e) {
                error_log("Failed to add update: " . $e->getMessage());
                $update_error = "Failed to save your update. Please try again.";
            }
        }
    }
}

/* ─── CANCEL REPORT ─── */
if (isset($_GET['cancel']) && ctype_digit((string)$_GET['cancel'])) {
    $incident_id = (int)$_GET['cancel'];
    $stmt = $pdo->prepare("SELECT id FROM incidents WHERE id = ? AND reporter_id = ? AND status IN ('reported','acknowledged')");
    $stmt->execute([$incident_id, $user_id]);
    if ($stmt->fetch()) {
        $pdo->prepare("UPDATE incidents SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$incident_id]);
        $update_success = "Your report has been cancelled.";
    } else {
        $update_error = "Unable to cancel — this report may already be in progress or resolved.";
    }
}

/* ─── FETCH REPORTS ─── */
$stmt = $pdo->prepare("
    SELECT i.*,
           CASE i.status
               WHEN 'reported'     THEN 25
               WHEN 'acknowledged' THEN 50
               WHEN 'in-progress'  THEN 75
               WHEN 'resolved'     THEN 100
               WHEN 'cancelled'    THEN 100
               WHEN 'rejected'     THEN 100
               ELSE 0
           END AS progress_percent
    FROM incidents i
    WHERE i.reporter_id = ?
    ORDER BY i.reported_at DESC
");
$stmt->execute([$user_id]);
$my_reports = $stmt->fetchAll();

/* ─── FETCH UPDATES ─── */
$incident_updates = [];
if (!empty($my_reports)) {
    $ids          = array_column($my_reports, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT iu.*, u.full_name AS user_name
        FROM   incident_updates iu
        JOIN   users u ON u.id = iu.user_id
        WHERE  iu.incident_id IN ($placeholders)
        ORDER  BY iu.created_at DESC
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $upd) {
        $incident_updates[$upd['incident_id']][] = $upd;
    }
}

/* ─── STATS ─── */
$total    = count($my_reports);
$active   = count(array_filter($my_reports, fn($r) => in_array($r['status'], ['reported','acknowledged','in-progress'])));
$resolved = count(array_filter($my_reports, fn($r) => $r['status'] === 'resolved'));
$pending  = count(array_filter($my_reports, fn($r) => $r['status'] === 'reported'));

/* ─── HELPERS ─── */
function getSev(int $s): array {
    return [
        1 => ['label'=>'Low',      'cls'=>'sev-low',  'icon'=>'bi-check-circle-fill'],
        2 => ['label'=>'Medium',   'cls'=>'sev-med',  'icon'=>'bi-info-circle-fill'],
        3 => ['label'=>'High',     'cls'=>'sev-high', 'icon'=>'bi-exclamation-triangle-fill'],
        4 => ['label'=>'Critical', 'cls'=>'sev-crit', 'icon'=>'bi-fire'],
    ][$s] ?? ['label'=>'Unknown','cls'=>'sev-low','icon'=>'bi-question-circle'];
}
function statusMeta(string $st): array {
    return [
        'reported'     => ['label'=>'Received',    'cls'=>'st-rep',    'bar'=>'#1d6ef5'],
        'acknowledged' => ['label'=>'Under Review', 'cls'=>'st-ack',    'bar'=>'#d97706'],
        'in-progress'  => ['label'=>'En Route',     'cls'=>'st-prog',   'bar'=>'#7c3aed'],
        'resolved'     => ['label'=>'Resolved',     'cls'=>'st-res',    'bar'=>'#16a34a'],
        'cancelled'    => ['label'=>'Cancelled',    'cls'=>'st-cancel', 'bar'=>'#9ca3af'],
        'rejected'     => ['label'=>'Rejected',     'cls'=>'st-reject', 'bar'=>'#e8271d'],
    ][$st] ?? ['label'=>ucfirst($st),'cls'=>'st-cancel','bar'=>'#9ca3af'];
}
function incidentIcon(string $type): string {
    return match($type) {
        'flood'             => 'bi-water',
        'fire'              => 'bi-fire',
        'earthquake'        => 'bi-house-exclamation',
        'landslide'         => 'bi-triangle',
        'drought'           => 'bi-sun',
        'accident'          => 'bi-car-front',
        'building_collapse' => 'bi-buildings',
        'disease_outbreak'  => 'bi-bug',
        default             => 'bi-exclamation-triangle',
    };
}
function incidentEmoji(string $type): string {
    return match($type) {
        'flood'=>'🌊','fire'=>'🔥','earthquake'=>'🏚️','landslide'=>'⛰️',
        'drought'=>'☀️','accident'=>'🚗','building_collapse'=>'🏗️','disease_outbreak'=>'🦠',
        default=>'⚠️',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Reports — DisasterResponse</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
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
html { scroll-behavior:smooth; }
body { font-family:var(--ff-body); background:var(--bg); color:var(--text); min-height:100vh; }
::-webkit-scrollbar { width:4px; }
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
.npill:hover { color:#fff; background:rgba(255,255,255,.1); }
.npill i { font-size:.85rem; }
.nav-right { display:flex; align-items:center; gap:.65rem; padding:0 1.25rem; border-left:1px solid rgba(255,255,255,.08); flex-shrink:0; }
.user-chip  { font-family:var(--ff-mono); font-size:.7rem; color:rgba(255,255,255,.6); white-space:nowrap; }
.new-report-btn {
  display:flex; align-items:center; gap:.3rem;
  padding:.3rem .8rem; border-radius:5px;
  background:var(--red); color:#fff; border:none;
  font-family:var(--ff-head); font-size:.8rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.07em;
  text-decoration:none; transition:all var(--ease);
}
.new-report-btn:hover { background:#c91f15; color:#fff; }
.logout-btn {
  display:flex; align-items:center; gap:.3rem;
  padding:.25rem .6rem; border-radius:5px;
  border:1px solid rgba(232,39,29,.4); background:rgba(232,39,29,.12);
  color:#ff7a74; font-size:.72rem; font-weight:600;
  text-decoration:none; transition:all var(--ease);
}
.logout-btn:hover { background:var(--red); color:#fff; border-color:var(--red); }

/* ─── PAGE HERO ──────────────────────────────────────────── */
.page-hero {
  background:var(--navy); padding:1.5rem 0 1.4rem;
  border-bottom:3px solid var(--red);
  position:relative; overflow:hidden;
}
.page-hero::before {
  content:''; position:absolute; right:-40px; top:-50px;
  width:250px; height:250px;
  background:radial-gradient(circle,rgba(232,39,29,.1) 0%,transparent 65%);
  pointer-events:none;
}
.hero-eyebrow { font-family:var(--ff-mono); font-size:.62rem; font-weight:600; letter-spacing:.16em; text-transform:uppercase; color:var(--red); margin-bottom:.35rem; }
.hero-title   { font-family:var(--ff-head); font-weight:800; font-size:2rem; color:#fff; letter-spacing:.02em; text-transform:uppercase; line-height:1.05; }
.hero-sub     { color:rgba(255,255,255,.45); font-size:.8rem; margin-top:.3rem; font-family:var(--ff-mono); }
.hero-date    { font-family:var(--ff-mono); font-size:.65rem; color:rgba(255,255,255,.35); margin-top:.25rem; }

/* ─── PAGE ───────────────────────────────────────────────── */
.page { max-width:880px; margin:0 auto; padding:1.5rem 1.25rem 5rem; }

/* ─── TOASTS ─────────────────────────────────────────────── */
.toast {
  display:flex; align-items:center; gap:.75rem;
  padding:.85rem 1.1rem; border-radius:var(--r-lg);
  font-size:.84rem; font-weight:500;
  margin-bottom:1.25rem; border:1px solid;
  animation:fadeUp .3s ease;
}
.toast i { font-size:1rem; flex-shrink:0; }
.toast button { margin-left:auto; background:none; border:none; cursor:pointer; color:inherit; opacity:.6; font-size:1rem; line-height:1; padding:0; }
.toast button:hover { opacity:1; }
.toast-ok  { background:var(--green-light); border-color:#bbf7d0; color:var(--green); }
.toast-err { background:var(--red-light);   border-color:var(--red-mid); color:var(--red); }

/* ─── KPI ROW ────────────────────────────────────────────── */
.kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:.85rem; margin-bottom:1.5rem; }
@media(max-width:640px){ .kpi-row { grid-template-columns:1fr 1fr; } }

.kpi {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r-lg); padding:1rem 1rem .85rem;
  box-shadow:var(--shadow); position:relative; overflow:hidden;
  transition:transform var(--ease), box-shadow var(--ease);
}
.kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.kpi:hover   { transform:translateY(-3px); box-shadow:var(--shadow-lg); }
.kpi-blue::before  { background:var(--blue); }
.kpi-red::before   { background:var(--red); }
.kpi-green::before { background:var(--green); }
.kpi-amber::before { background:var(--amber); }

.kpi-icon { width:34px; height:34px; border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:.95rem; margin-bottom:.65rem; }
.kpi-blue  .kpi-icon { background:var(--blue-light);   color:var(--blue); }
.kpi-red   .kpi-icon { background:var(--red-light);    color:var(--red); }
.kpi-green .kpi-icon { background:var(--green-light);  color:var(--green); }
.kpi-amber .kpi-icon { background:var(--amber-light);  color:var(--amber); }

.kpi-num { font-family:var(--ff-head); font-size:2rem; font-weight:800; line-height:1; letter-spacing:-.01em; }
.kpi-blue  .kpi-num { color:var(--blue); }
.kpi-red   .kpi-num { color:var(--red); }
.kpi-green .kpi-num { color:var(--green); }
.kpi-amber .kpi-num { color:var(--amber); }
.kpi-lbl { font-size:.67rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.1em; margin-top:.2rem; }

/* ─── SECTION LABEL ──────────────────────────────────────── */
.sec { display:flex; align-items:center; gap:.6rem; margin-bottom:.85rem; }
.sec-icon  { width:26px; height:26px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:.78rem; }
.sec-title { font-family:var(--ff-head); font-size:.78rem; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); }
.sec-line  { flex:1; height:1px; background:var(--border); }

/* ─── REPORT CARD ────────────────────────────────────────── */
.rcard {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r-xl); box-shadow:var(--shadow);
  overflow:hidden; margin-bottom:1.1rem;
  transition:transform var(--ease), box-shadow var(--ease);
  animation:fadeUp .4s ease both;
}
.rcard:hover { transform:translateY(-2px); box-shadow:var(--shadow-lg); }

/* Left severity stripe */
.rcard.sev-crit { border-left:4px solid var(--red); }
.rcard.sev-high { border-left:4px solid var(--amber); }
.rcard.sev-med  { border-left:4px solid var(--blue); }
.rcard.sev-low  { border-left:4px solid var(--green); }

/* Card header */
.rc-hd {
  display:flex; align-items:center; justify-content:space-between;
  padding:.75rem 1.25rem; background:var(--surface-2);
  border-bottom:1px solid var(--border); gap:.75rem; flex-wrap:wrap;
}
.rc-hd-left  { display:flex; align-items:center; gap:.55rem; flex-wrap:wrap; }
.rc-id {
  font-family:var(--ff-mono); font-size:.72rem; font-weight:700;
  color:var(--navy); background:rgba(15,27,45,.07);
  border:1px solid rgba(15,27,45,.12);
  padding:.2rem .6rem; border-radius:4px; white-space:nowrap;
}
.rc-hd-right { display:flex; gap:.45rem; }

/* badge variants */
.badge {
  display:inline-flex; align-items:center; gap:.25rem;
  padding:.2rem .6rem; border-radius:4px;
  font-family:var(--ff-mono); font-size:.62rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.06em; white-space:nowrap;
}
.sev-crit { background:var(--red-light);    color:var(--red);    border:1px solid var(--red-mid); }
.sev-high { background:var(--amber-light);  color:var(--amber);  border:1px solid #fde68a; }
.sev-med  { background:var(--blue-light);   color:var(--blue);   border:1px solid #bfdbfe; }
.sev-low  { background:var(--green-light);  color:var(--green);  border:1px solid #bbf7d0; }

.st-rep    { background:var(--blue-light);   color:var(--blue);   border:1px solid #bfdbfe; }
.st-ack    { background:var(--amber-light);  color:var(--amber);  border:1px solid #fde68a; }
.st-prog   { background:var(--purple-light); color:var(--purple); border:1px solid #ddd6fe; }
.st-res    { background:var(--green-light);  color:var(--green);  border:1px solid #bbf7d0; }
.st-cancel { background:#f9fafb; color:var(--muted); border:1px solid var(--border-2); }
.st-reject { background:var(--red-light);   color:var(--red);    border:1px solid var(--red-mid); }

/* action buttons */
.btn-act {
  display:inline-flex; align-items:center; gap:.3rem;
  padding:.28rem .75rem; border-radius:var(--r);
  font-family:var(--ff-body); font-size:.75rem; font-weight:500;
  text-decoration:none; border:1px solid var(--border);
  background:var(--surface-2); color:var(--text-2);
  cursor:pointer; transition:all var(--ease); white-space:nowrap;
}
.btn-act:hover { background:var(--surface); border-color:var(--border-2); color:var(--text); }
.btn-act.danger { border-color:rgba(232,39,29,.28); color:var(--red); background:var(--red-light); }
.btn-act.danger:hover { background:var(--red); color:#fff; border-color:var(--red); }
.btn-act.blue { border-color:rgba(29,110,245,.25); color:var(--blue); background:var(--blue-light); }
.btn-act.blue:hover { background:var(--blue); color:#fff; border-color:var(--blue); }

/* Card body */
.rc-body { padding:1.25rem; }

.type-row { display:flex; align-items:center; gap:.75rem; margin-bottom:1rem; }
.type-emoji-box {
  width:44px; height:44px; border-radius:var(--r-lg);
  background:var(--surface-2); border:1px solid var(--border);
  display:flex; align-items:center; justify-content:center; font-size:1.4rem; flex-shrink:0;
}
.type-name { font-family:var(--ff-head); font-size:1.15rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:var(--text); }

/* Meta grid */
.meta-grid { display:grid; grid-template-columns:1fr 1fr; gap:.9rem 1.25rem; margin-bottom:1rem; }
@media(max-width:520px){ .meta-grid { grid-template-columns:1fr; } }
.meta-full  { grid-column:1/-1; }
.meta-label { font-family:var(--ff-head); font-size:.63rem; font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:var(--muted); margin-bottom:.2rem; }
.meta-val   { font-size:.83rem; color:var(--text-2); line-height:1.55; }
.meta-val.mono { font-family:var(--ff-mono); font-size:.77rem; }

/* Progress bar */
.prog-section { margin-bottom:1.1rem; }
.prog-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:.4rem; }
.prog-label { font-family:var(--ff-head); font-size:.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.12em; color:var(--muted); }
.prog-pct   { font-family:var(--ff-mono); font-size:.68rem; color:var(--muted); }
.prog-track { height:7px; background:var(--bg); border-radius:5px; overflow:hidden; margin-bottom:.5rem; }
.prog-fill  { height:100%; border-radius:5px; transition:width .6s cubic-bezier(.4,0,.2,1); }
.prog-steps { display:flex; justify-content:space-between; }
.step-lbl   { font-family:var(--ff-mono); font-size:.58rem; color:var(--muted-2); }

/* Updates section */
.updates-section { border-top:1px solid var(--border); padding-top:1rem; }
.updates-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:.85rem; }
.updates-label {
  display:flex; align-items:center; gap:.45rem;
  font-family:var(--ff-head); font-size:.72rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.1em; color:var(--text-2);
}
.updates-count {
  display:inline-flex; align-items:center; justify-content:center;
  background:var(--red); color:#fff;
  font-family:var(--ff-mono); font-size:.58rem; font-weight:700;
  min-width:18px; height:18px; border-radius:9px; padding:0 .3rem;
}
.toggle-form-btn {
  display:inline-flex; align-items:center; gap:.3rem;
  padding:.28rem .75rem; border-radius:var(--r);
  font-family:var(--ff-head); font-size:.72rem; font-weight:700;
  text-transform:uppercase; letter-spacing:.07em;
  background:var(--blue-light); color:var(--blue);
  border:1px solid rgba(29,110,245,.28); cursor:pointer;
  transition:all var(--ease);
}
.toggle-form-btn:hover { background:var(--blue); color:#fff; border-color:var(--blue); }

/* Update form */
.upd-form { display:none; margin-bottom:.9rem; }
.upd-form.open { display:block; }
.upd-form-inner {
  background:var(--surface-2); border:1px solid var(--border);
  border-radius:var(--r-lg); padding:1rem;
}
.upd-textarea {
  width:100%; font-family:var(--ff-body); font-size:.84rem;
  background:var(--surface); color:var(--text);
  border:1.5px solid var(--border); border-radius:var(--r);
  padding:.6rem .85rem; resize:vertical; min-height:76px;
  outline:none; transition:border-color var(--ease), box-shadow var(--ease);
  margin-bottom:.75rem;
}
.upd-textarea::placeholder { color:var(--muted-2); }
.upd-textarea:focus { border-color:var(--blue); box-shadow:0 0 0 3px rgba(29,110,245,.1); }

/* Update items */
.upd-item { display:flex; gap:.75rem; padding:.85rem 0; border-bottom:1px solid var(--border); }
.upd-item:last-child { border-bottom:none; padding-bottom:0; }
.upd-avatar {
  width:32px; height:32px; flex-shrink:0;
  background:var(--surface-2); border:1px solid var(--border);
  border-radius:8px; display:flex; align-items:center; justify-content:center;
  font-family:var(--ff-head); font-size:1rem; font-weight:700; color:var(--muted);
}
.upd-body {}
.upd-meta { display:flex; align-items:center; gap:.5rem; margin-bottom:.3rem; }
.upd-name { font-size:.82rem; font-weight:600; color:var(--text); }
.upd-time { font-family:var(--ff-mono); font-size:.63rem; color:var(--muted-2); }
.upd-text { font-size:.81rem; color:var(--text-2); line-height:1.6; }
.no-updates { padding:1rem 0; font-family:var(--ff-mono); font-size:.65rem; letter-spacing:.1em; text-transform:uppercase; color:var(--muted-2); text-align:center; }

/* ─── EMPTY STATE ─────────────────────────────────────────── */
.empty-state {
  text-align:center; padding:4rem 2rem;
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r-xl); box-shadow:var(--shadow);
}
.empty-icon { width:72px; height:72px; border-radius:50%; background:var(--surface-2); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:1.8rem; color:var(--muted-2); margin:0 auto 1.1rem; }
.empty-title { font-family:var(--ff-head); font-size:1.4rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; margin-bottom:.4rem; }
.empty-sub  { font-size:.84rem; color:var(--muted); margin-bottom:1.3rem; }

/* ─── HOTLINE BAR ─────────────────────────────────────────── */
.hotline-bar {
  display:flex; align-items:center; justify-content:center; gap:1.25rem;
  padding:1rem 1.25rem; margin-top:1.75rem;
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--r-lg); box-shadow:var(--shadow);
  font-size:.82rem; color:var(--text-2); flex-wrap:wrap;
}
.hotline-bar strong { color:var(--text); font-weight:600; }
.hotline-bar i { color:var(--red); }
.h-sep { width:1px; height:14px; background:var(--border); flex-shrink:0; }

@keyframes fadeUp { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }

@media(max-width:600px){
  .hero-title { font-size:1.5rem; }
  .rc-hd { flex-direction:column; align-items:flex-start; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar">
  <a class="brand" href="/disaster_response/index.php">
    <i class="bi bi-shield-fill-exclamation" style="color:#fff;font-size:1rem"></i>
    <div>
      <span class="brand-text">Disaster<span style="opacity:.7">Response</span></span>
      <span class="brand-sub">Citizen Portal</span>
    </div>
  </a>
  <div class="nav-area">
    <a href="/disaster_response/modules/incidents/report.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="my_reports.php" class="npill" style="color:#fff;background:rgba(255,255,255,.12)"><i class="bi bi-list-check"></i> My Reports</a>
  </div>
  <div class="nav-right">
    <span class="user-chip d-none d-md-flex align-items-center gap-1"><i class="bi bi-person-circle"></i><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?></span>
    <a href="report.php" class="new-report-btn"><i class="bi bi-plus-lg"></i> New Report</a>
    <a href="/disaster_response/modules/auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i></a>
  </div>
</nav>

<!-- PAGE HERO -->
<div class="page-hero">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['full_name']) ?></div>
    <div class="hero-title">My Emergency Reports</div>
    <div class="hero-sub">Track the status of every incident you've submitted</div>
    <div class="hero-date"><?= strtoupper(date('l, F j, Y')) ?></div>
  </div>
</div>

<!-- PAGE -->
<div class="page">

  <!-- TOASTS -->
  <?php if ($update_success): ?>
  <div class="toast toast-ok" id="toastOk">
    <i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($update_success) ?>
    <button onclick="this.parentElement.remove()"><i class="bi bi-x-lg"></i></button>
  </div>
  <?php endif; ?>
  <?php if ($update_error): ?>
  <div class="toast toast-err" id="toastErr">
    <i class="bi bi-exclamation-triangle-fill"></i><?= htmlspecialchars($update_error) ?>
    <button onclick="this.parentElement.remove()"><i class="bi bi-x-lg"></i></button>
  </div>
  <?php endif; ?>

  <?php if (empty($my_reports)): ?>

  <!-- EMPTY STATE -->
  <div class="empty-state">
    <div class="empty-icon"><i class="bi bi-inbox"></i></div>
    <div class="empty-title">No Reports Yet</div>
    <div class="empty-sub">You haven't submitted any emergency reports. If you're witnessing an emergency, report it now.</div>
    <a href="report.php" class="btn-act danger" style="display:inline-flex;padding:.5rem 1.25rem">
      <i class="bi bi-exclamation-triangle-fill"></i> Report an Emergency
    </a>
  </div>

  <?php else: ?>

  <!-- KPI ROW -->
  <div class="kpi-row">
    <div class="kpi kpi-blue">
      <div class="kpi-icon"><i class="bi bi-stack"></i></div>
      <div class="kpi-num"><?= $total ?></div>
      <div class="kpi-lbl">Total Reports</div>
    </div>
    <div class="kpi kpi-red">
      <div class="kpi-icon"><i class="bi bi-activity"></i></div>
      <div class="kpi-num"><?= $active ?></div>
      <div class="kpi-lbl">Active</div>
    </div>
    <div class="kpi kpi-green">
      <div class="kpi-icon"><i class="bi bi-check-circle-fill"></i></div>
      <div class="kpi-num"><?= $resolved ?></div>
      <div class="kpi-lbl">Resolved</div>
    </div>
    <div class="kpi kpi-amber">
      <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
      <div class="kpi-num"><?= $pending ?></div>
      <div class="kpi-lbl">Awaiting Response</div>
    </div>
  </div>

  <!-- SECTION LABEL -->
  <div class="sec">
    <span class="sec-icon" style="background:var(--red-light);color:var(--red)"><i class="bi bi-list-check"></i></span>
    <span class="sec-title">Your Reports</span>
    <div class="sec-line"></div>
    <span style="font-family:var(--ff-mono);font-size:.68rem;color:var(--muted);white-space:nowrap"><?= $total ?> report<?= $total!=1?'s':'' ?></span>
  </div>

  <!-- REPORT CARDS -->
  <?php foreach ($my_reports as $idx => $report):
    $sev       = getSev((int)$report['severity']);
    $stm       = statusMeta($report['status']);
    $canCancel = in_array($report['status'], ['reported','acknowledged']);
    $upds      = $incident_updates[$report['id']] ?? [];
    $pct       = (int)$report['progress_percent'];
    $formId    = 'uf-'.$report['id'];
    $emoji     = incidentEmoji($report['incident_type']);
    $delay     = min($idx * .05, .4);
  ?>
  <div class="rcard <?= $sev['cls'] ?>" style="animation-delay:<?= $delay ?>s">

    <!-- HEAD -->
    <div class="rc-hd">
      <div class="rc-hd-left">
        <span class="rc-id"><i class="bi bi-hash"></i>INC-<?= str_pad($report['id'],5,'0',STR_PAD_LEFT) ?></span>
        <span class="badge <?= $sev['cls'] ?>"><i class="bi <?= $sev['icon'] ?>"></i><?= $sev['label'] ?></span>
        <span class="badge <?= $stm['cls'] ?>"><?= $stm['label'] ?></span>
      </div>
      <div class="rc-hd-right">
        <?php if ($canCancel): ?>
        <a href="?cancel=<?= $report['id'] ?>" class="btn-act danger"
           onclick="return confirm('Cancel this report? This cannot be undone.')">
          <i class="bi bi-x-circle"></i> Cancel
        </a>
        <?php endif; ?>
        <a href="view.php?id=<?= $report['id'] ?>" class="btn-act blue">
          <i class="bi bi-eye"></i> View
        </a>
      </div>
    </div>

    <!-- BODY -->
    <div class="rc-body">

      <!-- Type -->
      <div class="type-row">
        <div class="type-emoji-box"><?= $emoji ?></div>
        <div>
          <div class="type-name"><?= ucfirst(str_replace('_',' ',$report['incident_type'])) ?></div>
          <div style="font-family:var(--ff-mono);font-size:.67rem;color:var(--muted-2);margin-top:.15rem"><?= date('M j, Y · H:i', strtotime($report['reported_at'])) ?></div>
        </div>
      </div>

      <!-- Meta grid -->
      <div class="meta-grid">
        <div>
          <div class="meta-label">Location</div>
          <div class="meta-val"><i class="bi bi-geo-alt-fill" style="color:var(--red);margin-right:.25rem"></i><?= htmlspecialchars($report['location_name'] ?? 'GPS coordinates captured') ?></div>
        </div>
        <div>
          <div class="meta-label">Last Updated</div>
          <div class="meta-val mono"><?= date('M j, Y · H:i', strtotime($report['updated_at'] ?? $report['reported_at'])) ?></div>
        </div>
        <div class="meta-full">
          <div class="meta-label">Description</div>
          <div class="meta-val"><?= nl2br(htmlspecialchars(substr($report['description'],0,240))) ?><?= strlen($report['description'])>240?'…':'' ?></div>
        </div>
      </div>

      <!-- Progress -->
      <div class="prog-section">
        <div class="prog-head">
          <div class="prog-label">Response Progress</div>
          <div class="prog-pct"><?= $pct ?>%</div>
        </div>
        <div class="prog-track">
          <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $stm['bar'] ?>"></div>
        </div>
        <div class="prog-steps">
          <?php foreach (['Received','Reviewing','En Route','Resolved'] as $step): ?>
          <div class="step-lbl"><?= $step ?></div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Updates -->
      <div class="updates-section">
        <div class="updates-head">
          <div class="updates-label">
            <i class="bi bi-chat-text-fill" style="color:var(--blue)"></i>
            Updates
            <?php if ($upds): ?>
              <span class="updates-count"><?= count($upds) ?></span>
            <?php endif; ?>
          </div>
          <button class="toggle-form-btn" onclick="toggleForm('<?= $formId ?>',this)">
            <i class="bi bi-plus-lg" id="ico-<?= $formId ?>"></i> Add Update
          </button>
        </div>

        <!-- Form -->
        <div class="upd-form" id="<?= $formId ?>">
          <div class="upd-form-inner">
            <form method="POST">
              <input type="hidden" name="action" value="add_update">
              <input type="hidden" name="incident_id" value="<?= $report['id'] ?>">
              <textarea name="additional_info" class="upd-textarea"
                        placeholder="Add information — e.g. situation has worsened, people still trapped, updated numbers…" required></textarea>
              <button type="submit" class="btn-act blue" style="padding:.4rem 1rem">
                <i class="bi bi-send-fill"></i> Submit Update
              </button>
            </form>
          </div>
        </div>

        <!-- Existing updates -->
        <?php if ($upds): ?>
        <div>
          <?php foreach ($upds as $upd): ?>
          <div class="upd-item">
            <div class="upd-avatar"><?= strtoupper(substr($upd['user_name'],0,1)) ?></div>
            <div class="upd-body">
              <div class="upd-meta">
                <span class="upd-name"><?= htmlspecialchars($upd['user_name']) ?></span>
                <span class="upd-time"><?= date('M j, H:i', strtotime($upd['created_at'])) ?></span>
              </div>
              <div class="upd-text"><?= nl2br(htmlspecialchars($upd['update_text'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="no-updates">No updates yet — be the first to add one</div>
        <?php endif; ?>
      </div>

    </div>
  </div>
  <?php endforeach; ?>

  <!-- HOTLINE BAR -->
  <div class="hotline-bar">
    <i class="bi bi-telephone-fill"></i>
    Need urgent help? Call <strong>999</strong>
    <span class="h-sep"></span>
    Red Cross: <strong>+254 700 123 456</strong>
    <span class="h-sep"></span>
    Emergency: <strong>112</strong>
  </div>

  <?php endif; ?>
</div><!-- /page -->

<script>
// Auto-dismiss toasts
setTimeout(() => {
  ['toastOk','toastErr'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.transition = 'opacity .4s, transform .4s';
    el.style.opacity = '0'; el.style.transform = 'translateY(-8px)';
    setTimeout(() => el.remove(), 420);
  });
}, 5000);

// Toggle update form
function toggleForm(id, btn) {
  const form = document.getElementById(id);
  const ico  = document.getElementById('ico-' + id);
  const open = form.classList.toggle('open');
  ico.className  = open ? 'bi bi-dash-lg' : 'bi bi-plus-lg';
  btn.style.background = open ? 'var(--blue)' : '';
  btn.style.color      = open ? '#fff'        : '';
  btn.style.borderColor= open ? 'var(--blue)' : '';
  if (open) form.querySelector('textarea')?.focus();
}
</script>
</body>
</html>