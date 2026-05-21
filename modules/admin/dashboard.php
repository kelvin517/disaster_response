<?php
/**
 * Admin Dashboard - Complete System Overview
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

if (!class_exists('Logger')) {
    require_once __DIR__ . '/../../includes/Logger.php';
}

$logger = Logger::getInstance();
$logger->info("Admin dashboard accessed", 'audit.log');

if (!isLoggedIn()) redirect('modules/auth/login.php');
if (!hasRole(['admin'])) redirect('index.php');

$user_id = $_SESSION['user_id'];

// ─── STATISTICS ───────────────────────────────────────────────
$stmt = $pdo->query("SELECT COUNT(*) as total_incidents, SUM(CASE WHEN status='reported' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status='acknowledged' THEN 1 ELSE 0 END) as acknowledged, SUM(CASE WHEN status='in-progress' THEN 1 ELSE 0 END) as in_progress, SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) as resolved, SUM(CASE WHEN severity=4 THEN 1 ELSE 0 END) as critical, SUM(CASE WHEN severity=3 THEN 1 ELSE 0 END) as high, SUM(CASE WHEN severity=2 THEN 1 ELSE 0 END) as medium, SUM(CASE WHEN severity=1 THEN 1 ELSE 0 END) as low FROM incidents");
$incident_stats = $stmt->fetch();

$stmt = $pdo->query("SELECT COUNT(*) as total_zones, SUM(CASE WHEN hazard_level='critical' THEN 1 ELSE 0 END) as critical_zones FROM danger_zones WHERE status='active'");
$zone_stats = $stmt->fetch();

$stmt = $pdo->query("SELECT COUNT(*) as total_shelters, SUM(capacity) as total_capacity, SUM(current_occupancy) as total_occupancy FROM shelters WHERE status='active'");
$shelter_stats = $stmt->fetch();

$stmt = $pdo->query("SELECT COUNT(*) as total_users, SUM(CASE WHEN role='admin' THEN 1 ELSE 0 END) as admins, SUM(CASE WHEN role='responder' THEN 1 ELSE 0 END) as responders, SUM(CASE WHEN role='volunteer' THEN 1 ELSE 0 END) as volunteers, SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active_users FROM users");
$user_stats = $stmt->fetch();

$stmt = $pdo->query("SELECT COUNT(DISTINCT resource_type) as resource_types, SUM(quantity) as total_units, SUM(CASE WHEN status='available' THEN quantity ELSE 0 END) as available_units FROM resources");
$resource_stats = $stmt->fetch();

$stmt = $pdo->query("SELECT COUNT(*) as total_volunteers, SUM(CASE WHEN availability_status='available' THEN 1 ELSE 0 END) as available_volunteers FROM volunteers");
$volunteer_stats = $stmt->fetch();

$stmt = $pdo->query("SELECT COUNT(*) as total_requests, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending_requests FROM resource_requests");
$resource_req_stats = $stmt->fetch();

$stmt = $pdo->prepare("SELECT COUNT(*) as unread FROM messages WHERE receiver_id=? AND is_read=0");
$stmt->execute([$user_id]);
$unread_messages = $stmt->fetch()['unread'];

$stmt = $pdo->query("SELECT COUNT(*) as active_alerts FROM alerts WHERE expires_at > NOW()");
$alert_stats = $stmt->fetch();

$stmt = $pdo->prepare("SELECT DATE(reported_at) as date, COUNT(*) as count FROM incidents WHERE reported_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(reported_at) ORDER BY date ASC");
$stmt->execute();
$weekly_activity = $stmt->fetchAll();

$activity_dates = []; $activity_counts = [];
foreach ($weekly_activity as $a) { $activity_dates[] = date('M j', strtotime($a['date'])); $activity_counts[] = $a['count']; }

$stmt = $pdo->prepare("SELECT i.*, u.full_name as reporter_name FROM incidents i LEFT JOIN users u ON i.reporter_id=u.id ORDER BY i.reported_at DESC LIMIT 8");
$stmt->execute();
$recent_incidents = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, full_name, email, role, is_active, created_at FROM users ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$recent_users = $stmt->fetchAll();

$stmt = $pdo->query("SELECT AVG(TIMESTAMPDIFF(HOUR, reported_at, updated_at)) as avg_response_hours FROM incidents WHERE status='resolved' AND updated_at IS NOT NULL");
$response_time = $stmt->fetch();

$stmt = $pdo->query("SELECT CASE severity WHEN 1 THEN 'Low' WHEN 2 THEN 'Medium' WHEN 3 THEN 'High' WHEN 4 THEN 'Critical' END as label, COUNT(*) as count FROM incidents GROUP BY severity ORDER BY severity DESC");
$severity_distribution = $stmt->fetchAll();
$severity_labels = []; $severity_counts = [];
foreach ($severity_distribution as $s) { $severity_labels[] = $s['label']; $severity_counts[] = $s['count']; }

$shelter_occupancy = ($shelter_stats['total_capacity'] ?? 0) > 0 ? round(($shelter_stats['total_occupancy'] / $shelter_stats['total_capacity']) * 100) : 0;
$completion_rate = ($incident_stats['total_incidents'] ?? 0) > 0 ? round(($incident_stats['resolved'] / $incident_stats['total_incidents']) * 100) : 0;
$php_version = phpversion();
$db_size = $pdo->query("SELECT ROUND(SUM(data_length + index_length)/1024/1024,2) as size FROM information_schema.tables WHERE table_schema=DATABASE()")->fetch()['size'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — DisasterResponse</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ═══ DESIGN SYSTEM ═══════════════════════════════════════════ */
:root {
  --bg:        #f0f2f5;
  --surface:   #ffffff;
  --surface-2: #f7f8fa;
  --border:    #e2e5ea;
  --border-2:  #d0d4db;

  --navy:      #0f1b2d;
  --navy-2:    #1a2b42;
  --navy-3:    #243550;

  --red:       #e8271d;
  --red-light: #fff0ef;
  --red-mid:   #ffc9c6;

  --amber:     #d97706;
  --amber-light:#fffbeb;

  --blue:      #1d6ef5;
  --blue-light:#eff5ff;

  --green:     #16a34a;
  --green-light:#f0fdf4;

  --teal:      #0891b2;
  --teal-light:#ecfeff;

  --purple:    #7c3aed;
  --purple-light:#f5f3ff;

  --text:      #0f1b2d;
  --text-2:    #374151;
  --muted:     #6b7280;
  --muted-2:   #9ca3af;

  --ff-head: 'Barlow Condensed', sans-serif;
  --ff-body: 'Barlow', sans-serif;
  --ff-mono: 'IBM Plex Mono', monospace;

  --r: 8px;
  --r-lg: 12px;
  --shadow: 0 1px 3px rgba(15,27,45,.08), 0 4px 16px rgba(15,27,45,.06);
  --shadow-lg: 0 4px 24px rgba(15,27,45,.12);
  --ease: .18s cubic-bezier(.4,0,.2,1);
}

*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }

body {
  font-family: var(--ff-body);
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  font-size: 14px;
}

::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-2); border-radius: 4px; }

/* ═══ TOPBAR ══════════════════════════════════════════════════ */
.topbar {
  background: var(--navy);
  padding: 0;
  position: sticky; top: 0; z-index: 300;
  box-shadow: 0 2px 12px rgba(15,27,45,.35);
}
.topbar-inner {
  display: flex; align-items: stretch;
  height: 54px;
  gap: 0;
}
.brand {
  display: flex; align-items: center; gap: .5rem;
  padding: 0 1.4rem 0 1.25rem;
  background: var(--red);
  text-decoration: none;
  white-space: nowrap;
  position: relative;
  clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 100%, 0 100%);
  padding-right: 2rem;
}
.brand-text {
  font-family: var(--ff-head);
  font-weight: 800; font-size: 1.15rem;
  color: #fff; letter-spacing: .03em;
  text-transform: uppercase;
}
.brand-sub {
  font-family: var(--ff-mono);
  font-size: .52rem; font-weight: 600;
  color: rgba(255,255,255,.7);
  letter-spacing: .12em; text-transform: uppercase;
  display: block; margin-top: -2px;
}
.nav-area {
  display: flex; align-items: center;
  padding: 0 .75rem; gap: .1rem;
  flex: 1;
  overflow-x: auto;
}
.nav-area::-webkit-scrollbar { height: 0; }
.npill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .32rem .75rem;
  border-radius: 5px;
  border: none; background: transparent;
  color: rgba(255,255,255,.62);
  font-family: var(--ff-body); font-size: .78rem; font-weight: 500;
  text-decoration: none;
  white-space: nowrap;
  transition: all var(--ease);
  cursor: pointer;
}
.npill:hover { color: #fff; background: rgba(255,255,255,.1); }
.npill.active { color: #fff; background: rgba(255,255,255,.15); }
.npill i { font-size: .85rem; }
.nbadge {
  background: var(--red); color: #fff;
  font-size: .52rem; font-weight: 700; font-family: var(--ff-mono);
  min-width: 16px; height: 16px; border-radius: 8px;
  display: inline-flex; align-items: center; justify-content: center;
  padding: 0 .3rem;
}
.user-area {
  display: flex; align-items: center; gap: .75rem;
  padding: 0 1.25rem;
  border-left: 1px solid rgba(255,255,255,.08);
}
.user-label {
  font-family: var(--ff-mono); font-size: .7rem; font-weight: 500;
  color: rgba(255,255,255,.7); white-space: nowrap;
}
.logout-btn {
  display: flex; align-items: center; gap: .3rem;
  padding: .3rem .7rem;
  border-radius: 5px;
  border: 1px solid rgba(232,39,29,.4);
  background: rgba(232,39,29,.12);
  color: #ff7a74;
  font-size: .75rem; font-weight: 600;
  text-decoration: none;
  transition: all var(--ease);
  white-space: nowrap;
}
.logout-btn:hover { background: var(--red); color: #fff; border-color: var(--red); }

/* ═══ PAGE ════════════════════════════════════════════════════ */
.page { max-width: 1480px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }

/* ═══ HERO ════════════════════════════════════════════════════ */
.hero {
  background: var(--navy);
  border-radius: var(--r-lg);
  padding: 1.5rem 2rem;
  margin-bottom: 1.75rem;
  display: flex; align-items: center; justify-content: space-between;
  gap: 1.5rem;
  position: relative; overflow: hidden;
}
.hero::before {
  content: '';
  position: absolute; top: -40px; right: -20px;
  width: 250px; height: 250px;
  background: radial-gradient(circle, rgba(232,39,29,.18) 0%, transparent 70%);
  pointer-events: none;
}
.hero::after {
  content: '';
  position: absolute; bottom: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, var(--red) 0%, #ff6b35 35%, var(--amber) 65%, transparent 100%);
}
.hero-title {
  font-family: var(--ff-head);
  font-size: 1.75rem; font-weight: 800;
  color: #fff; letter-spacing: .02em;
  line-height: 1.1;
  text-transform: uppercase;
}
.hero-title span { color: var(--red); }
.hero-sub {
  color: rgba(255,255,255,.5);
  font-size: .8rem; margin-top: .3rem;
  font-family: var(--ff-mono); letter-spacing: .03em;
}
.hero-status {
  display: flex; align-items: center; gap: .5rem;
  margin-top: .8rem;
}
.status-pill {
  display: inline-flex; align-items: center; gap: .4rem;
  background: rgba(22,163,74,.15);
  border: 1px solid rgba(22,163,74,.35);
  border-radius: 20px;
  padding: .25rem .75rem;
  font-size: .7rem; font-weight: 600; font-family: var(--ff-mono);
  color: #4ade80;
  letter-spacing: .05em;
}
.live-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #4ade80;
  box-shadow: 0 0 8px #4ade80;
  animation: pulse 2s infinite;
}
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.85)} }

.clock-box {
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: var(--r);
  padding: .5rem 1rem;
  text-align: center;
  white-space: nowrap;
}
.clock-time {
  font-family: var(--ff-mono); font-size: 1.3rem; font-weight: 600;
  color: #fff; letter-spacing: .05em;
}
.clock-date {
  font-family: var(--ff-mono); font-size: .65rem;
  color: rgba(255,255,255,.45); margin-top: 2px;
}

/* ═══ SECTION LABEL ═══════════════════════════════════════════ */
.sec-label {
  display: flex; align-items: center; gap: .6rem;
  margin-bottom: .85rem;
}
.sec-icon {
  width: 26px; height: 26px; border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  font-size: .78rem; flex-shrink: 0;
}
.sec-title {
  font-family: var(--ff-head);
  font-size: .78rem; font-weight: 700;
  letter-spacing: .14em; text-transform: uppercase;
  color: var(--muted);
}
.sec-line { flex: 1; height: 1px; background: var(--border); }

/* ═══ STAT TILES ══════════════════════════════════════════════ */
.tiles-scroll {
  overflow-x: auto;
  padding-bottom: .5rem;
  margin-bottom: 1.5rem;
}
.tiles-scroll::-webkit-scrollbar { height: 3px; }
.tiles-row { display: flex; gap: .75rem; min-width: max-content; }

.tile {
  width: 168px; flex-shrink: 0;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 1rem 1rem .85rem;
  box-shadow: var(--shadow);
  position: relative; overflow: hidden;
  transition: transform var(--ease), box-shadow var(--ease);
  cursor: default;
}
.tile::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 3px;
  transition: height var(--ease);
}
.tile:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
.tile:hover::before { height: 4px; }

.tile-red::before    { background: var(--red); }
.tile-amber::before  { background: var(--amber); }
.tile-blue::before   { background: var(--blue); }
.tile-green::before  { background: var(--green); }
.tile-teal::before   { background: var(--teal); }
.tile-purple::before { background: var(--purple); }
.tile-navy::before   { background: var(--navy); }

.tile-icon {
  width: 34px; height: 34px; border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; margin-bottom: .75rem;
}
.tile-red    .tile-icon { background: var(--red-light);    color: var(--red); }
.tile-amber  .tile-icon { background: var(--amber-light);  color: var(--amber); }
.tile-blue   .tile-icon { background: var(--blue-light);   color: var(--blue); }
.tile-green  .tile-icon { background: var(--green-light);  color: var(--green); }
.tile-teal   .tile-icon { background: var(--teal-light);   color: var(--teal); }
.tile-purple .tile-icon { background: var(--purple-light); color: var(--purple); }
.tile-navy   .tile-icon { background: rgba(15,27,45,.08);  color: var(--navy); }

.tile-num {
  font-family: var(--ff-head);
  font-size: 2rem; font-weight: 800; line-height: 1;
  letter-spacing: -.01em;
}
.tile-red    .tile-num { color: var(--red); }
.tile-amber  .tile-num { color: var(--amber); }
.tile-blue   .tile-num { color: var(--blue); }
.tile-green  .tile-num { color: var(--green); }
.tile-teal   .tile-num { color: var(--teal); }
.tile-purple .tile-num { color: var(--purple); }
.tile-navy   .tile-num { color: var(--navy); }

.tile-lbl {
  font-size: .67rem; font-weight: 600; color: var(--muted);
  text-transform: uppercase; letter-spacing: .1em;
  margin-top: .2rem; line-height: 1.3;
}

/* ═══ PERF METRICS ═══════════════════════════════════════════ */
.perf-row {
  display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: 1.5rem;
}
.perf-card {
  flex: 1; min-width: 180px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 1rem 1.1rem;
  box-shadow: var(--shadow);
  display: flex; align-items: center; gap: .9rem;
}
.perf-icon {
  width: 42px; height: 42px; border-radius: var(--r);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; flex-shrink: 0;
}
.perf-num {
  font-family: var(--ff-head);
  font-size: 1.75rem; font-weight: 800; line-height: 1;
}
.perf-sub { font-size: .65rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .1em; margin-top: .15rem; }
.perf-bar { height: 5px; border-radius: 4px; background: var(--bg); margin-top: .5rem; overflow: hidden; }
.perf-fill { height: 100%; border-radius: 4px; transition: width .5s ease; }

/* ═══ CARDS ═══════════════════════════════════════════════════ */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow);
  overflow: hidden;
  margin-bottom: 1.1rem;
  animation: fadeUp .4s ease both;
}
.card-hd {
  display: flex; align-items: center; justify-content: space-between;
  padding: .8rem 1.25rem;
  border-bottom: 1px solid var(--border);
  background: var(--surface-2);
}
.card-hd-left { display: flex; align-items: center; gap: .55rem; }
.card-hd-icon {
  width: 28px; height: 28px; border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  font-size: .85rem; flex-shrink: 0;
}
.card-hd-title {
  font-family: var(--ff-head);
  font-size: .82rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .1em;
  color: var(--text-2);
}
.card-link {
  font-size: .72rem; font-weight: 600; color: var(--blue);
  text-decoration: none; padding: .2rem .6rem;
  border: 1px solid rgba(29,110,245,.22); border-radius: 5px;
  transition: all var(--ease);
  white-space: nowrap;
}
.card-link:hover { background: var(--blue); color: #fff; border-color: var(--blue); }

/* ═══ INCIDENT ROWS ═══════════════════════════════════════════ */
.inc-row {
  padding: .85rem 1.25rem;
  border-bottom: 1px solid var(--border);
  transition: background var(--ease);
  cursor: pointer;
  display: flex; align-items: flex-start;
  gap: 1rem;
}
.inc-row:last-child { border-bottom: none; }
.inc-row:hover { background: #f8f9ff; }
.inc-num {
  font-family: var(--ff-mono); font-size: .7rem; font-weight: 600;
  color: var(--muted-2); white-space: nowrap;
  padding-top: .05rem;
}
.inc-title {
  font-weight: 600; font-size: .855rem; color: var(--text);
  margin-bottom: .18rem;
}
.inc-meta {
  font-family: var(--ff-mono); font-size: .69rem; color: var(--muted);
}
.inc-badges { display: flex; flex-direction: column; align-items: flex-end; gap: .3rem; flex-shrink: 0; }

/* Severity/Status badges */
.badge {
  padding: .22rem .65rem; border-radius: 4px;
  font-size: .63rem; font-weight: 700;
  font-family: var(--ff-mono); letter-spacing: .05em;
  text-transform: uppercase; display: inline-block; white-space: nowrap;
}
.b-critical { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.b-high     { background: #fff7ed; color: #d97706; border: 1px solid #fed7aa; }
.b-medium   { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
.b-low      { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.b-reported     { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.b-acknowledged { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
.b-in_progress  { background: #ecfeff; color: #0891b2; border: 1px solid #a5f3fc; }
.b-resolved     { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.b-cancelled    { background: #f9fafb; color: #6b7280; border: 1px solid #e5e7eb; }

/* ═══ GRID LAYOUT ═════════════════════════════════════════════ */
.main-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 290px;
  gap: 1.1rem;
  align-items: start;
  margin-bottom: 1.25rem;
}

/* ═══ QUICK ACTIONS ═══════════════════════════════════════════ */
.qa-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .6rem; padding: .9rem; }
.qa-btn {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: .35rem; padding: .85rem .4rem;
  border-radius: var(--r);
  background: var(--surface-2);
  border: 1px solid var(--border);
  text-decoration: none;
  transition: all var(--ease);
  position: relative; overflow: hidden;
  min-height: 74px;
}
.qa-btn:hover { background: var(--navy); border-color: var(--navy); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(15,27,45,.15); }
.qa-btn i { font-size: 1.2rem; color: var(--navy); transition: color var(--ease); }
.qa-btn:hover i { color: #fff; }
.qa-btn span { font-size: .62rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .09em; text-align: center; transition: color var(--ease); }
.qa-btn:hover span { color: rgba(255,255,255,.75); }

/* Alert display */
.alert-display {
  padding: 1.5rem 1rem; text-align: center;
}
.alert-num {
  font-family: var(--ff-head);
  font-size: 3rem; font-weight: 800;
  color: var(--red); line-height: 1;
}
.alert-lbl { font-size: .7rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .12em; margin-top: .3rem; }

/* ═══ SHELTER BAR ═════════════════════════════════════════════ */
.shelter-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow);
  margin-bottom: 1.1rem;
  overflow: hidden;
}
.shelter-hd {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap;
  padding: .85rem 1.25rem;
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  gap: .5rem;
}
.shelter-body { padding: .85rem 1.25rem; }
.shelter-track {
  height: 10px; border-radius: 6px; background: var(--bg);
  overflow: hidden;
}
.shelter-fill { height: 100%; border-radius: 6px; transition: width .5s ease; }
.shelter-meta { display: flex; justify-content: space-between; margin-top: .5rem; }
.shelter-stat { font-family: var(--ff-mono); font-size: .7rem; color: var(--muted); }

/* ═══ USERS TABLE ═════════════════════════════════════════════ */
.tbl { width: 100%; border-collapse: collapse; }
.tbl thead tr { background: var(--surface-2); }
.tbl thead th {
  padding: .75rem 1.25rem;
  font-family: var(--ff-head);
  font-size: .7rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .12em;
  color: var(--muted); border-bottom: 2px solid var(--border);
  white-space: nowrap;
}
.tbl tbody tr { border-bottom: 1px solid var(--border); transition: background var(--ease); cursor: pointer; }
.tbl tbody tr:last-child { border-bottom: none; }
.tbl tbody tr:hover { background: #f4f6fb; }
.tbl td { padding: .8rem 1.25rem; font-size: .83rem; color: var(--text-2); vertical-align: middle; }
.t-name { font-weight: 600; color: var(--text); }
.t-email { font-family: var(--ff-mono); font-size: .71rem; color: var(--muted); }
.t-date { font-family: var(--ff-mono); font-size: .7rem; color: var(--muted-2); }

.role-chip {
  padding: .2rem .6rem; border-radius: 4px;
  font-family: var(--ff-mono); font-size: .62rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .05em;
}
.r-admin     { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.r-responder { background: var(--teal-light); color: var(--teal); border: 1px solid #a5f3fc; }
.r-volunteer { background: var(--green-light); color: var(--green); border: 1px solid #bbf7d0; }
.r-victim    { background: var(--purple-light); color: var(--purple); border: 1px solid #ddd6fe; }

.status-dot { display: inline-flex; align-items: center; gap: .3rem; font-size: .78rem; }
.dot { width: 7px; height: 7px; border-radius: 50%; }
.dot-on  { background: var(--green); box-shadow: 0 0 5px rgba(22,163,74,.5); }
.dot-off { background: var(--muted-2); }

/* ═══ CHART ═══════════════════════════════════════════════════ */
.chart-pad { padding: 1.1rem 1.25rem; }

/* ═══ EMPTY STATE ═════════════════════════════════════════════ */
.empty { text-align: center; padding: 2.5rem 1rem; color: var(--muted-2); }
.empty i { font-size: 2rem; display: block; margin-bottom: .5rem; opacity: .35; }

@keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

/* ═══ RESPONSIVE ══════════════════════════════════════════════ */
@media (max-width: 1100px) {
  .main-grid { grid-template-columns: 1fr 1fr; }
  .main-grid .col-right { grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; }
}
@media (max-width: 768px) {
  .hero { flex-direction: column; }
  .hero-title { font-size: 1.35rem; }
  .main-grid { grid-template-columns: 1fr; }
  .main-grid .col-right { grid-column: auto; display: block; }
  .brand-text { font-size: 1rem; }
  .perf-row { flex-direction: column; }
}
</style>
</head>
<body>

<!-- ═══ TOPBAR ════════════════════════════════════════════════ -->
<nav class="topbar">
  <div class="container-fluid px-0">
    <div class="topbar-inner">
      <a class="brand" href="admin_dashboard.php">
        <i class="bi bi-shield-fill-exclamation" style="color:#fff;font-size:1.15rem"></i>
        <div>
          <span class="brand-text">Disaster<span style="opacity:.7">Response</span></span>
          <span class="brand-sub">Admin Console</span>
        </div>
      </a>
      <div class="nav-area">
        <a href="dashboard.php" class="npill active"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="../analytics/incidents.php" class="npill"><i class="bi bi-graph-up"></i> Analytics</a>
        <a href="export.php" class="npill"><i class="bi bi-download"></i> Export</a>
        <a href="system_logs.php" class="npill"><i class="bi bi-journal-bookmark-fill"></i> Logs</a>
        <a href="../messaging/inbox.php" class="npill">
          <i class="bi bi-envelope"></i> Messages
          <?php if ($unread_messages > 0): ?><span class="nbadge"><?= $unread_messages ?></span><?php endif; ?>
        </a>
        <a href="../resources/manage.php" class="npill"><i class="bi bi-box-seam"></i> Resources</a>
        <a href="../mapping/map.php" class="npill"><i class="bi bi-map"></i> Live Map</a>
        <a href="../incidents/pending.php" class="npill">
          <i class="bi bi-clock-history"></i> Pending
          <?php if (($incident_stats['pending'] ?? 0) > 0): ?><span class="nbadge"><?= $incident_stats['pending'] ?></span><?php endif; ?>
        </a>
        <a href="users.php" class="npill"><i class="bi bi-people"></i> Users</a>
      </div>
      <div class="user-area">
        <span class="user-label d-none d-lg-block"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></span>
        <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
      </div>
    </div>
  </div>
</nav>

<!-- ═══ PAGE ══════════════════════════════════════════════════ -->
<div class="page">

  <!-- HERO -->
  <div class="hero">
    <div>
      <div class="hero-title">Welcome back, <span><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></span></div>
      <div class="hero-sub">SYSTEM OVERVIEW &amp; ANALYTICS DASHBOARD</div>
      <div class="hero-status">
        <span class="status-pill"><span class="live-dot"></span> ALL SYSTEMS OPERATIONAL</span>
        <span style="font-family:var(--ff-mono);font-size:.65rem;color:rgba(255,255,255,.35)">PHP <?= $php_version ?> &bull; <?= $db_size ?> MB</span>
      </div>
    </div>
    <div class="clock-box">
      <div class="clock-time" id="clkTime">00:00:00</div>
      <div class="clock-date" id="clkDate"></div>
    </div>
  </div>

  <!-- ── INCIDENTS ──────────────────────────────────────────── -->
  <div class="sec-label">
    <span class="sec-icon" style="background:var(--red-light);color:var(--red)"><i class="bi bi-exclamation-triangle-fill"></i></span>
    <span class="sec-title">Incident Overview</span>
    <div class="sec-line"></div>
  </div>
  <div class="tiles-scroll">
    <div class="tiles-row">
      <div class="tile tile-navy">
        <div class="tile-icon"><i class="bi bi-stack"></i></div>
        <div class="tile-num"><?= number_format($incident_stats['total_incidents'] ?? 0) ?></div>
        <div class="tile-lbl">Total Incidents</div>
      </div>
      <div class="tile tile-red">
        <div class="tile-icon"><i class="bi bi-clock-history"></i></div>
        <div class="tile-num"><?= number_format($incident_stats['pending'] ?? 0) ?></div>
        <div class="tile-lbl">Pending Review</div>
      </div>
      <div class="tile tile-amber">
        <div class="tile-icon"><i class="bi bi-hourglass-split"></i></div>
        <div class="tile-num"><?= number_format($incident_stats['acknowledged'] ?? 0) ?></div>
        <div class="tile-lbl">Acknowledged</div>
      </div>
      <div class="tile tile-blue">
        <div class="tile-icon"><i class="bi bi-truck"></i></div>
        <div class="tile-num"><?= number_format($incident_stats['in_progress'] ?? 0) ?></div>
        <div class="tile-lbl">In Progress</div>
      </div>
      <div class="tile tile-green">
        <div class="tile-icon"><i class="bi bi-check-circle-fill"></i></div>
        <div class="tile-num"><?= number_format($incident_stats['resolved'] ?? 0) ?></div>
        <div class="tile-lbl">Resolved</div>
      </div>
      <div class="tile tile-red" style="border-left: 3px solid var(--red);">
        <div class="tile-icon"><i class="bi bi-fire"></i></div>
        <div class="tile-num"><?= number_format($incident_stats['critical'] ?? 0) ?></div>
        <div class="tile-lbl">Critical Severity</div>
      </div>
    </div>
  </div>

  <!-- ── INFRASTRUCTURE ─────────────────────────────────────── -->
  <div class="sec-label">
    <span class="sec-icon" style="background:var(--blue-light);color:var(--blue)"><i class="bi bi-buildings-fill"></i></span>
    <span class="sec-title">Infrastructure &amp; Resources</span>
    <div class="sec-line"></div>
  </div>
  <div class="tiles-scroll">
    <div class="tiles-row">
      <div class="tile tile-red">
        <div class="tile-icon"><i class="bi bi-exclamation-octagon-fill"></i></div>
        <div class="tile-num"><?= number_format($zone_stats['total_zones'] ?? 0) ?></div>
        <div class="tile-lbl">Danger Zones</div>
      </div>
      <div class="tile tile-green">
        <div class="tile-icon"><i class="bi bi-building-fill"></i></div>
        <div class="tile-num"><?= number_format($shelter_stats['total_shelters'] ?? 0) ?></div>
        <div class="tile-lbl">Active Shelters</div>
      </div>
      <div class="tile tile-blue">
        <div class="tile-icon"><i class="bi bi-box-seam-fill"></i></div>
        <div class="tile-num"><?= number_format($resource_stats['resource_types'] ?? 0) ?></div>
        <div class="tile-lbl">Resource Types</div>
      </div>
      <div class="tile tile-teal">
        <div class="tile-icon"><i class="bi bi-boxes"></i></div>
        <div class="tile-num"><?= number_format($resource_stats['available_units'] ?? 0) ?></div>
        <div class="tile-lbl">Available Units</div>
      </div>
      <div class="tile tile-amber">
        <div class="tile-icon"><i class="bi bi-hourglass"></i></div>
        <div class="tile-num"><?= number_format($resource_req_stats['pending_requests'] ?? 0) ?></div>
        <div class="tile-lbl">Pending Aid Requests</div>
      </div>
    </div>
  </div>

  <!-- ── PEOPLE ─────────────────────────────────────────────── -->
  <div class="sec-label">
    <span class="sec-icon" style="background:var(--green-light);color:var(--green)"><i class="bi bi-people-fill"></i></span>
    <span class="sec-title">People &amp; Community</span>
    <div class="sec-line"></div>
  </div>
  <div class="tiles-scroll">
    <div class="tiles-row">
      <div class="tile tile-purple">
        <div class="tile-icon"><i class="bi bi-people-fill"></i></div>
        <div class="tile-num"><?= number_format($user_stats['total_users'] ?? 0) ?></div>
        <div class="tile-lbl">Total Users</div>
      </div>
      <div class="tile tile-navy">
        <div class="tile-icon"><i class="bi bi-person-check-fill"></i></div>
        <div class="tile-num"><?= number_format($user_stats['active_users'] ?? 0) ?></div>
        <div class="tile-lbl">Active Users</div>
      </div>
      <div class="tile tile-red">
        <div class="tile-icon"><i class="bi bi-shield-fill"></i></div>
        <div class="tile-num"><?= number_format($user_stats['responders'] ?? 0) ?></div>
        <div class="tile-lbl">Responders</div>
      </div>
      <div class="tile tile-green">
        <div class="tile-icon"><i class="bi bi-person-heart"></i></div>
        <div class="tile-num"><?= number_format($volunteer_stats['available_volunteers'] ?? 0) ?></div>
        <div class="tile-lbl">Available Volunteers</div>
      </div>
      <div class="tile tile-blue">
        <div class="tile-icon"><i class="bi bi-bell-fill"></i></div>
        <div class="tile-num"><?= $alert_stats['active_alerts'] ?? 0 ?></div>
        <div class="tile-lbl">Active Alerts</div>
      </div>
    </div>
  </div>

  <!-- ── PERFORMANCE ────────────────────────────────────────── -->
  <div class="sec-label">
    <span class="sec-icon" style="background:var(--amber-light);color:var(--amber)"><i class="bi bi-speedometer2"></i></span>
    <span class="sec-title">Performance Metrics</span>
    <div class="sec-line"></div>
  </div>
  <div class="perf-row" style="margin-bottom:1.5rem;">
    <div class="perf-card">
      <div class="perf-icon" style="background:var(--green-light);color:var(--green)"><i class="bi bi-check-circle-fill"></i></div>
      <div style="flex:1">
        <div class="perf-num" style="color:var(--green)"><?= $completion_rate ?>%</div>
        <div class="perf-sub">Completion Rate</div>
        <div class="perf-bar"><div class="perf-fill" style="width:<?= $completion_rate ?>%;background:var(--green)"></div></div>
      </div>
    </div>
    <div class="perf-card">
      <div class="perf-icon" style="background:var(--amber-light);color:var(--amber)"><i class="bi bi-stopwatch-fill"></i></div>
      <div>
        <div class="perf-num" style="color:var(--amber)"><?= round($response_time['avg_response_hours'] ?? 0) ?><span style="font-size:1rem;font-weight:400"> hrs</span></div>
        <div class="perf-sub">Avg Response Time</div>
      </div>
    </div>
    <div class="perf-card">
      <div class="perf-icon" style="background:var(--red-light);color:var(--red)"><i class="bi bi-fire"></i></div>
      <div>
        <div class="perf-num" style="color:var(--red)"><?= number_format($incident_stats['critical'] ?? 0) ?></div>
        <div class="perf-sub">Critical Incidents</div>
      </div>
    </div>
    <div class="perf-card">
      <div class="perf-icon" style="background:var(--teal-light);color:var(--teal)"><i class="bi bi-database-fill-check"></i></div>
      <div>
        <div class="perf-num" style="color:var(--teal)"><?= $db_size ?><span style="font-size:1rem;font-weight:400"> MB</span></div>
        <div class="perf-sub">Database Size</div>
      </div>
    </div>
  </div>

  <!-- Shelter Occupancy -->
  <?php if (($shelter_stats['total_shelters'] ?? 0) > 0):
    $oc = $shelter_occupancy;
    $oc_col = $oc >= 90 ? 'var(--red)' : ($oc >= 70 ? 'var(--amber)' : 'var(--green)');
    $oc_cls = $oc >= 90 ? 'b-critical' : ($oc >= 70 ? 'b-high' : 'b-low');
  ?>
  <div class="shelter-card">
    <div class="shelter-hd">
      <div style="display:flex;align-items:center;gap:.6rem">
        <span class="card-hd-icon" style="background:var(--blue-light);color:var(--blue);width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.85rem"><i class="bi bi-building-fill"></i></span>
        <span class="card-hd-title">Shelter Occupancy</span>
        <span class="badge <?= $oc_cls ?>"><?= $oc ?>% Occupied</span>
      </div>
      <div style="display:flex;gap:1.5rem">
        <span style="font-size:.78rem;color:var(--muted)"><i class="bi bi-people me-1"></i><?= number_format($shelter_stats['total_occupancy'] ?? 0) ?> Occupants</span>
        <span style="font-size:.78rem;color:var(--muted)"><i class="bi bi-building me-1"></i><?= number_format($shelter_stats['total_capacity'] ?? 0) ?> Capacity</span>
      </div>
    </div>
    <div class="shelter-body">
      <div class="shelter-track"><div class="shelter-fill" style="width:<?= $oc ?>%;background:<?= $oc_col ?>"></div></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── 3-COL GRID ──────────────────────────────────────────── -->
  <div class="main-grid">

    <!-- LEFT: Recent Incidents -->
    <div class="col-left">
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-left">
            <span class="card-hd-icon" style="background:var(--red-light);color:var(--red)"><i class="bi bi-exclamation-triangle-fill"></i></span>
            <span class="card-hd-title">Recent Incidents</span>
          </div>
          <a href="../incidents/all.php" class="card-link">View All →</a>
        </div>
        <?php if (count($recent_incidents) > 0): ?>
          <?php foreach ($recent_incidents as $inc):
            $sc = match((int)$inc['severity']) { 4=>'critical', 3=>'high', 2=>'medium', default=>'low' };
            $stc = str_replace(['-',' '], '_', strtolower($inc['status']));
          ?>
          <div class="inc-row" onclick="location.href='../incidents/view.php?id=<?= $inc['id'] ?>'">
            <div class="inc-num">#<?= str_pad($inc['id'],5,'0',STR_PAD_LEFT) ?></div>
            <div style="flex:1;min-width:0">
              <div class="inc-title"><?= htmlspecialchars($inc['location_name'] ?? 'Unknown') ?></div>
              <div class="inc-meta"><?= ucfirst(str_replace('_',' ',$inc['incident_type'])) ?> &bull; <?= htmlspecialchars($inc['reporter_name'] ?? 'Anonymous') ?> &bull; <?= date('M j, H:i', strtotime($inc['reported_at'])) ?></div>
            </div>
            <div class="inc-badges">
              <span class="badge b-<?= $sc ?>"><?= ucfirst($sc) ?></span>
              <span class="badge b-<?= $stc ?>"><?= ucfirst(str_replace('-',' ',$inc['status'])) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty"><i class="bi bi-inbox"></i><p>No recent incidents.</p></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- MID: Charts -->
    <div class="col-mid">
      <div class="card" style="margin-bottom:1.1rem">
        <div class="card-hd">
          <div class="card-hd-left">
            <span class="card-hd-icon" style="background:var(--blue-light);color:var(--blue)"><i class="bi bi-bar-chart-fill"></i></span>
            <span class="card-hd-title">Weekly Activity</span>
          </div>
        </div>
        <div class="chart-pad"><canvas id="weeklyChart" height="185"></canvas></div>
      </div>
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-left">
            <span class="card-hd-icon" style="background:var(--purple-light);color:var(--purple)"><i class="bi bi-pie-chart-fill"></i></span>
            <span class="card-hd-title">Severity Distribution</span>
          </div>
        </div>
        <div class="chart-pad"><canvas id="severityChart" height="185"></canvas></div>
      </div>
    </div>

    <!-- RIGHT: Quick Actions + Alerts -->
    <div class="col-right">
      <div class="card" style="margin-bottom:1.1rem">
        <div class="card-hd">
          <div class="card-hd-left">
            <span class="card-hd-icon" style="background:var(--amber-light);color:var(--amber)"><i class="bi bi-lightning-charge-fill"></i></span>
            <span class="card-hd-title">Quick Actions</span>
          </div>
        </div>
        <div class="qa-grid">
          <a href="../incidents/pending.php" class="qa-btn"><i class="bi bi-check2-circle"></i><span>Verify</span></a>
          <a href="../resources/manage.php" class="qa-btn"><i class="bi bi-box-seam"></i><span>Aid</span></a>
          <a href="users.php" class="qa-btn"><i class="bi bi-people"></i><span>Users</span></a>
          <a href="../analytics/incidents.php" class="qa-btn"><i class="bi bi-graph-up"></i><span>Analytics</span></a>
          <a href="export.php" class="qa-btn"><i class="bi bi-download"></i><span>Export</span></a>
          <a href="system_logs.php" class="qa-btn"><i class="bi bi-journal-bookmark"></i><span>Logs</span></a>
          <a href="../mapping/danger_zones.php" class="qa-btn"><i class="bi bi-exclamation-triangle"></i><span>Zones</span></a>
          <a href="../mapping/shelters.php" class="qa-btn"><i class="bi bi-building"></i><span>Shelters</span></a>
          <a href="../mapping/map.php" class="qa-btn"><i class="bi bi-map"></i><span>Map</span></a>
        </div>
      </div>
      <div class="card">
        <div class="card-hd">
          <div class="card-hd-left">
            <span class="card-hd-icon" style="background:var(--red-light);color:var(--red)"><i class="bi bi-bell-fill"></i></span>
            <span class="card-hd-title">Active Alerts</span>
          </div>
          <a href="../alerts/history.php" class="card-link">History →</a>
        </div>
        <div class="alert-display">
          <div class="alert-num"><?= $alert_stats['active_alerts'] ?? 0 ?></div>
          <div class="alert-lbl">Active Emergency Alerts</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── USERS TABLE ─────────────────────────────────────────── -->
  <div class="card">
    <div class="card-hd">
      <div class="card-hd-left">
        <span class="card-hd-icon" style="background:var(--blue-light);color:var(--blue)"><i class="bi bi-person-plus-fill"></i></span>
        <span class="card-hd-title">Recent Registrations</span>
      </div>
      <a href="users.php" class="card-link">Manage All →</a>
    </div>
    <?php if (count($recent_users) > 0): ?>
    <div class="table-responsive">
      <table class="tbl">
        <thead>
          <tr>
            <th><i class="bi bi-person me-1"></i>Name</th>
            <th><i class="bi bi-envelope me-1"></i>Email</th>
            <th><i class="bi bi-tag me-1"></i>Role</th>
            <th><i class="bi bi-circle-fill me-1"></i>Status</th>
            <th><i class="bi bi-calendar me-1"></i>Joined</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent_users as $u):
            $rc = match(strtolower($u['role'])) { 'admin'=>'r-admin', 'responder'=>'r-responder', 'volunteer'=>'r-volunteer', default=>'r-victim' };
          ?>
          <tr onclick="location.href='edit_user.php?id=<?= $u['id'] ?>'">
            <td><div class="t-name"><?= htmlspecialchars($u['full_name']) ?></div></td>
            <td><div class="t-email"><?= htmlspecialchars($u['email']) ?></div></td>
            <td><span class="role-chip <?= $rc ?>"><?= ucfirst($u['role']) ?></span></td>
            <td>
              <span class="status-dot">
                <span class="dot <?= $u['is_active'] ? 'dot-on' : 'dot-off' ?>"></span>
                <span style="font-size:.78rem;color:<?= $u['is_active'] ? 'var(--green)' : 'var(--muted)' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span>
              </span>
            </td>
            <td><span class="t-date"><?= date('M j, Y', strtotime($u['created_at'])) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="empty"><i class="bi bi-person-x"></i><p>No users found.</p></div>
    <?php endif; ?>
  </div>

</div><!-- /page -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Weekly Chart
new Chart(document.getElementById('weeklyChart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($activity_dates) ?>,
    datasets: [{
      label: 'Incidents',
      data: <?= json_encode($activity_counts) ?>,
      backgroundColor: 'rgba(29,110,245,.14)',
      borderColor: '#1d6ef5',
      borderWidth: 2,
      borderRadius: 5,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: true,
    plugins: { legend: { labels: { color: '#6b7280', font: { family:"'Barlow',sans-serif", size:11 } } } },
    scales: {
      y: { grid: { color:'rgba(0,0,0,.05)' }, ticks: { color:'#9ca3af', font:{ family:"'IBM Plex Mono',monospace", size:10 } } },
      x: { grid: { display:false }, ticks: { color:'#9ca3af', font:{ family:"'IBM Plex Mono',monospace", size:10 } } }
    }
  }
});

// Severity Chart
new Chart(document.getElementById('severityChart').getContext('2d'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($severity_labels) ?>,
    datasets: [{
      data: <?= json_encode($severity_counts) ?>,
      backgroundColor: ['#f0fdf4','#eff6ff','#fffbeb','#fef2f2'],
      borderColor: ['#16a34a','#2563eb','#d97706','#dc2626'],
      borderWidth: 2.5,
      hoverOffset: 8
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: true, cutout: '70%',
    plugins: { legend: { position:'bottom', labels: { color:'#6b7280', font:{ family:"'IBM Plex Mono',monospace", size:10 }, usePointStyle:true, padding:12 } } }
  }
});

// Clock
function tick() {
  const now = new Date();
  document.getElementById('clkTime').textContent = now.toLocaleTimeString('en-KE',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false});
  document.getElementById('clkDate').textContent = now.toLocaleDateString('en-KE',{weekday:'short',year:'numeric',month:'short',day:'numeric'});
}
tick(); setInterval(tick, 1000);
</script>
</body>
</html>