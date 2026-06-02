<?php
/**
 * Live Incident Map Module
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$action = $_GET['action'] ?? '';

/* ─── API: FEED ─── */
if ($action === 'feed') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        $stmt = $pdo->prepare("
            SELECT i.id, i.incident_type, i.severity, i.description,
                   i.latitude, i.longitude, i.status, i.photo_path, i.reported_at,
                   COALESCE(u.full_name,'Anonymous') AS reporter_name
            FROM incidents i
            LEFT JOIN users u ON u.id = i.reporter_id
            WHERE i.status NOT IN ('resolved','cancelled','rejected')
              AND i.latitude IS NOT NULL AND i.longitude IS NOT NULL
              AND i.latitude != 0 AND i.longitude != 0
            ORDER BY i.severity DESC, i.reported_at DESC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $features = [];
        foreach ($rows as $r) {
            $lat = (float)$r['latitude'];
            $lng = (float)$r['longitude'];
            if ($lat === 0.0 && $lng === 0.0) continue;
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
            
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
                'properties' => [
                    'id' => (int)$r['id'],
                    'type' => $r['incident_type'],
                    'severity' => (int)$r['severity'],
                    'status' => $r['status'],
                    'description' => $r['description'] ?? '',
                    'reporter' => $r['reporter_name'],
                    'photo' => $r['photo_path'],
                    'reported_at' => $r['reported_at'],
                ],
            ];
        }
        echo json_encode([
            'type' => 'FeatureCollection',
            'features' => $features,
            'generated' => date('c'),
            'total' => count($features),
        ]);
    } catch (PDOException $e) {
        error_log('Map feed error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error', 'features' => [], 'total' => 0]);
    }
    exit;
}

/* ─── API: DETAIL ─── */
if ($action === 'detail') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid ID']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT i.*,
                   COALESCE(u.full_name,'Anonymous') AS reporter_name,
                   COALESCE(u.phone,'N/A') AS reporter_phone,
                   COALESCE(r.full_name,'Unassigned') AS responder_name
            FROM incidents i
            LEFT JOIN users u ON u.id = i.reporter_id
            LEFT JOIN users r ON r.id = i.assigned_to
            WHERE i.id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        echo json_encode($row);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
    exit;
}

/* ─── API: ZONES ─── */
if ($action === 'zones') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        // Check if danger_zones table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'danger_zones'");
        if ($tableCheck->rowCount() === 0) {
            echo json_encode(['type' => 'FeatureCollection', 'features' => [], 'total' => 0]);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT id, name, description, hazard_level, geometry, status FROM danger_zones WHERE status = 'active'");
        $stmt->execute();
        $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $colors = ['critical' => '#E8271A', 'high' => '#D97706', 'medium' => '#CA8A04', 'low' => '#16A34A'];
        $features = [];
        foreach ($zones as $zone) {
            $geometry = json_decode($zone['geometry'], true);
            if (!$geometry || !isset($geometry['type'])) continue;
            $features[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [
                    'id' => $zone['id'],
                    'name' => $zone['name'],
                    'description' => $zone['description'] ?? '',
                    'hazard_level' => $zone['hazard_level'],
                    'color' => $colors[$zone['hazard_level']] ?? '#E8271A',
                ],
            ];
        }
        echo json_encode(['type' => 'FeatureCollection', 'features' => $features, 'total' => count($features)]);
    } catch (PDOException $e) {
        error_log('Zones error: ' . $e->getMessage());
        echo json_encode(['error' => 'Database error', 'features' => [], 'total' => 0]);
    }
    exit;
}

/* ─── API: SHELTERS ─── */
if ($action === 'shelters') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        // Check if shelters table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'shelters'");
        if ($tableCheck->rowCount() === 0) {
            echo json_encode(['type' => 'FeatureCollection', 'features' => [], 'total' => 0]);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT id, name, type, capacity, current_occupancy, latitude, longitude, address, contact_phone, resources, status FROM shelters WHERE status = 'active' AND latitude IS NOT NULL AND longitude IS NOT NULL");
        $stmt->execute();
        $shelters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $features = [];
        foreach ($shelters as $s) {
            $lat = (float)$s['latitude'];
            $lng = (float)$s['longitude'];
            if ($lat === 0.0 && $lng === 0.0) continue;
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
            
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
                'properties' => [
                    'id' => $s['id'],
                    'name' => $s['name'],
                    'type' => $s['type'] ?? 'General',
                    'capacity' => (int)($s['capacity'] ?? 0),
                    'current_occupancy' => (int)($s['current_occupancy'] ?? 0),
                    'address' => $s['address'] ?? '',
                    'contact_phone' => $s['contact_phone'] ?? '',
                    'resources' => $s['resources'] ?? '',
                    'status' => $s['status'],
                ],
            ];
        }
        echo json_encode(['type' => 'FeatureCollection', 'features' => $features, 'total' => count($features)]);
    } catch (PDOException $e) {
        error_log('Shelters error: ' . $e->getMessage());
        echo json_encode(['error' => 'Database error', 'features' => [], 'total' => 0]);
    }
    exit;
}

/* ─── FULL PAGE RENDER ─── */
$severity_meta = [
    1 => ['label' => 'Low', 'color' => '#16A34A', 'border' => '#15803D'],
    2 => ['label' => 'Medium', 'color' => '#CA8A04', 'border' => '#A16207'],
    3 => ['label' => 'High', 'color' => '#D97706', 'border' => '#B45309'],
    4 => ['label' => 'Critical', 'color' => '#E8271A', 'border' => '#B91C1C'],
];

$type_icons = [
    'flood' => 'bi-water', 'fire' => 'bi-fire', 'earthquake' => 'bi-house-exclamation',
    'landslide' => 'bi-triangle', 'drought' => 'bi-sun', 'accident' => 'bi-car-front',
    'building_collapse' => 'bi-buildings', 'disease_outbreak' => 'bi-bug', 'other' => 'bi-exclamation-triangle',
];

$type_emojis = [
    'flood' => '🌊', 'fire' => '🔥', 'earthquake' => '🏚️', 'landslide' => '⛰️',
    'drought' => '☀️', 'accident' => '🚗', 'building_collapse' => '🏗️', 'disease_outbreak' => '🦠', 'other' => '⚠️',
];

try {
    $counts = $pdo->query("
        SELECT severity, COUNT(*) AS cnt
        FROM incidents
        WHERE status NOT IN ('resolved','cancelled','rejected')
        GROUP BY severity
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $counts = [];
}

$total_active = array_sum($counts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Live Incident Map — DisasterResponse</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
<style>
/* ═══ TOKENS ══════════════════════════════════════════════════ */
:root {
  --bg: #f0f2f5;
  --surface: #ffffff;
  --surface-2: #f7f8fa;
  --border: #e2e5ea;
  --border-2: #d0d4db;
  --navy: #0f1b2d;
  --navy-2: #1a2b42;
  --red: #e8271d;
  --red-light: #fff0ef;
  --red-mid: #fecaca;
  --amber: #d97706;
  --amber-light: #fffbeb;
  --blue: #1d6ef5;
  --blue-light: #eff5ff;
  --green: #16a34a;
  --green-light: #f0fdf4;
  --teal: #0891b2;
  --purple: #7c3aed;
  --text: #0f1b2d;
  --text-2: #374151;
  --muted: #6b7280;
  --muted-2: #9ca3af;
  --ff-head: 'Barlow Condensed', sans-serif;
  --ff-body: 'Barlow', sans-serif;
  --ff-mono: 'IBM Plex Mono', monospace;
  --r: 8px;
  --r-lg: 12px;
  --ease: .18s cubic-bezier(.4, 0, .2, 1);
  --shadow: 0 1px 3px rgba(15, 27, 45, .1), 0 4px 16px rgba(15, 27, 45, .08);
}

*, *::before, *::after {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

html, body {
  height: 100%;
  overflow: hidden;
}

body {
  font-family: var(--ff-body);
  background: var(--bg);
  color: var(--text);
  display: flex;
  flex-direction: column;
}

::-webkit-scrollbar {
  width: 4px;
}

::-webkit-scrollbar-track {
  background: transparent;
}

::-webkit-scrollbar-thumb {
  background: var(--border-2);
  border-radius: 3px;
}

/* ─── TOPBAR ─────────────────────────────────────────────── */
.topbar {
  background: var(--navy);
  height: 50px;
  flex-shrink: 0;
  display: flex;
  align-items: stretch;
  box-shadow: 0 2px 12px rgba(15, 27, 45, .4);
  z-index: 1000;
  position: relative;
}

.brand {
  display: flex;
  align-items: center;
  gap: .5rem;
  padding: 0 1.75rem 0 1.1rem;
  background: var(--red);
  text-decoration: none;
  flex-shrink: 0;
  clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 100%, 0 100%);
}

.brand-text {
  font-family: var(--ff-head);
  font-weight: 800;
  font-size: 1.05rem;
  color: #fff;
  text-transform: uppercase;
  letter-spacing: .03em;
}

.brand-sub {
  font-family: var(--ff-mono);
  font-size: .48rem;
  font-weight: 600;
  color: rgba(255, 255, 255, .65);
  letter-spacing: .12em;
  text-transform: uppercase;
  display: block;
  margin-top: -2px;
}

.nav-center {
  display: flex;
  align-items: center;
  flex: 1;
  padding: 0 .75rem;
  gap: .1rem;
}

.live-chip {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  background: rgba(22, 163, 74, .15);
  border: 1px solid rgba(22, 163, 74, .3);
  color: #4ade80;
  font-family: var(--ff-mono);
  font-size: .6rem;
  font-weight: 600;
  letter-spacing: .12em;
  text-transform: uppercase;
  padding: .22rem .7rem;
  border-radius: 20px;
  margin-left: .5rem;
}

.live-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #4ade80;
  box-shadow: 0 0 6px #4ade80;
  animation: ldot 1.4s infinite;
}

@keyframes ldot {
  0%, 100% { opacity: 1; }
  50% { opacity: .2; }
}

.npill {
  display: inline-flex;
  align-items: center;
  gap: .3rem;
  padding: .28rem .7rem;
  border-radius: 5px;
  color: rgba(255, 255, 255, .6);
  font-size: .76rem;
  font-weight: 500;
  text-decoration: none;
  white-space: nowrap;
  transition: all var(--ease);
}

.npill:hover {
  color: #fff;
  background: rgba(255, 255, 255, .1);
}

.nav-right {
  display: flex;
  align-items: center;
  gap: .5rem;
  padding: 0 1rem;
  border-left: 1px solid rgba(255, 255, 255, .08);
}

.logout-btn {
  display: flex;
  align-items: center;
  gap: .3rem;
  padding: .25rem .6rem;
  border-radius: 5px;
  border: 1px solid rgba(232, 39, 29, .4);
  background: rgba(232, 39, 29, .12);
  color: #ff7a74;
  font-size: .72rem;
  font-weight: 600;
  text-decoration: none;
  transition: all var(--ease);
}

.logout-btn:hover {
  background: var(--red);
  color: #fff;
  border-color: var(--red);
}

/* ─── MAP SHELL ──────────────────────────────────────────── */
#shell {
  display: flex;
  flex: 1;
  overflow: hidden;
  position: relative;
}

/* ─── SIDEBAR ────────────────────────────────────────────── */
#sidebar {
  width: 320px;
  min-width: 320px;
  flex-shrink: 0;
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  transition: transform .25s ease;
  z-index: 600;
}

#sidebar.hidden {
  transform: translateX(-100%);
  position: absolute;
  top: 0;
  left: 0;
  bottom: 0;
  z-index: 700;
}

.sb-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: .7rem 1rem;
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}

.sb-head-left {
  display: flex;
  align-items: center;
  gap: .5rem;
}

.sb-head-icon {
  width: 26px;
  height: 26px;
  border-radius: 6px;
  background: var(--red-light);
  color: var(--red);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: .8rem;
}

.sb-head-title {
  font-family: var(--ff-head);
  font-size: .78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: var(--text-2);
}

.sb-count {
  font-family: var(--ff-head);
  font-size: 1.5rem;
  font-weight: 800;
  color: var(--red);
  line-height: 1;
}

.sb-filters {
  display: flex;
  gap: .5rem;
  padding: .6rem .75rem;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}

.sb-select {
  flex: 1;
  font-family: var(--ff-body);
  font-size: .76rem;
  background: var(--surface-2);
  color: var(--text);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: .32rem .6rem;
  outline: none;
  cursor: pointer;
  transition: border-color var(--ease);
}

.sb-select:focus {
  border-color: var(--blue);
}

#incidentList {
  overflow-y: auto;
  flex: 1;
}

.inc-item {
  padding: .8rem 1rem;
  border-bottom: 1px solid var(--border);
  border-left: 3px solid transparent;
  cursor: pointer;
  transition: background var(--ease), border-left-color var(--ease);
}

.inc-item:last-child {
  border-bottom: none;
}

.inc-item:hover {
  background: var(--surface-2);
}

.inc-item.active {
  background: #f0f4ff;
  border-left-color: var(--blue);
}

.inc-item.sev-4 {
  border-left-color: var(--red);
}

.inc-item.sev-4.active {
  background: var(--red-light);
}

.inc-item.sev-3 {
  border-left-color: var(--amber);
}

.inc-item.sev-2 {
  border-left-color: var(--blue);
}

.inc-item.sev-1 {
  border-left-color: var(--green);
}

.inc-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: .5rem;
  margin-bottom: .3rem;
}

.inc-id-type {
  font-size: .82rem;
  font-weight: 600;
  color: var(--text);
  line-height: 1.3;
}

.inc-desc {
  font-size: .74rem;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: .35rem;
}

.inc-foot {
  display: flex;
  align-items: center;
  gap: .5rem;
}

.inc-time {
  font-family: var(--ff-mono);
  font-size: .63rem;
  color: var(--muted-2);
}

.sev-chip {
  display: inline-flex;
  align-items: center;
  padding: .14rem .5rem;
  border-radius: 3px;
  font-family: var(--ff-mono);
  font-size: .58rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  white-space: nowrap;
}

.chip-4 {
  background: var(--red-light);
  color: var(--red);
  border: 1px solid var(--red-mid);
}

.chip-3 {
  background: var(--amber-light);
  color: var(--amber);
  border: 1px solid #fde68a;
}

.chip-2 {
  background: var(--blue-light);
  color: var(--blue);
  border: 1px solid #bfdbfe;
}

.chip-1 {
  background: var(--green-light);
  color: var(--green);
  border: 1px solid #bbf7d0;
}

.st-chip {
  background: var(--surface-2);
  color: var(--muted);
  border: 1px solid var(--border);
  display: inline-block;
  padding: .12rem .45rem;
  border-radius: 3px;
  font-family: var(--ff-mono);
  font-size: .58rem;
  font-weight: 600;
  text-transform: uppercase;
}

.list-state {
  text-align: center;
  padding: 2.5rem 1rem;
}

.list-state i {
  font-size: 1.8rem;
  display: block;
  margin-bottom: .6rem;
  color: var(--muted-2);
  opacity: .5;
}

.list-state p {
  font-size: .8rem;
  color: var(--muted-2);
}

.spin-wrap {
  text-align: center;
  padding: 2.5rem 1rem;
}

.spin {
  width: 22px;
  height: 22px;
  border: 2px solid var(--border);
  border-top-color: var(--red);
  border-radius: 50%;
  animation: spin .7s linear infinite;
  margin: 0 auto .6rem;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.spin-wrap p {
  font-family: var(--ff-mono);
  font-size: .65rem;
  color: var(--muted-2);
  letter-spacing: .1em;
  text-transform: uppercase;
}

/* ─── MAP ────────────────────────────────────────────────── */
#map {
  flex: 1;
  background: #e8ecf0;
  z-index: 0;
}

/* ─── FLOATING CONTROLS ──────────────────────────────────── */
.layer-bar {
  position: absolute;
  top: 10px;
  left: 10px;
  z-index: 500;
  display: flex;
  gap: .4rem;
  flex-wrap: wrap;
}

#sidebarToggle {
  position: absolute;
  top: 10px;
  left: 10px;
  z-index: 600;
  display: none;
  width: 34px;
  height: 34px;
  border-radius: var(--r);
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--text);
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: .9rem;
  box-shadow: var(--shadow);
}

.layer-btn {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: var(--r);
  padding: .32rem .75rem;
  font-family: var(--ff-mono);
  font-size: .63rem;
  font-weight: 600;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--muted);
  cursor: pointer;
  box-shadow: var(--shadow);
  transition: all var(--ease);
  white-space: nowrap;
  user-select: none;
}

.layer-btn.on {
  color: var(--text);
  border-color: var(--border-2);
}

.layer-btn input {
  display: none;
}

.layer-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}

/* ─── LEGEND ─────────────────────────────────────────────── */
#legend {
  position: absolute;
  bottom: 36px;
  right: 10px;
  z-index: 500;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 1rem 1.1rem;
  min-width: 150px;
  box-shadow: var(--shadow);
}

.legend-title {
  font-family: var(--ff-head);
  font-size: .66rem;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: .65rem;
}

.legend-row {
  display: flex;
  align-items: center;
  gap: .55rem;
  font-size: .78rem;
  color: var(--text-2);
  margin-bottom: .45rem;
}

.legend-row:last-child {
  margin-bottom: 0;
}

.legend-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex-shrink: 0;
}

.legend-count {
  margin-left: auto;
  font-family: var(--ff-mono);
  font-size: .63rem;
  color: var(--muted-2);
}

.legend-sep {
  border: none;
  border-top: 1px solid var(--border);
  margin: .6rem 0;
}

/* ─── DETAIL PANEL ───────────────────────────────────────── */
#detailPanel {
  position: absolute;
  top: 10px;
  right: 10px;
  z-index: 550;
  width: 320px;
  max-height: calc(100vh - 80px);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow);
  display: none;
  overflow: hidden;
}

.dp-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: .7rem 1rem;
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
}

.dp-head-label {
  font-family: var(--ff-head);
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: var(--text-2);
}

.dp-close {
  width: 24px;
  height: 24px;
  border-radius: 5px;
  background: var(--bg);
  border: 1px solid var(--border);
  color: var(--muted);
  cursor: pointer;
  font-size: .8rem;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all var(--ease);
}

.dp-close:hover {
  background: var(--red-light);
  color: var(--red);
  border-color: var(--red-mid);
}

#detailContent {
  padding: 1rem;
  max-height: calc(100vh - 200px);
  overflow-y: auto;
}

.dp-icon {
  font-size: 2rem;
  margin-bottom: .6rem;
}

.dp-id {
  font-family: var(--ff-mono);
  font-size: .62rem;
  color: var(--muted-2);
  letter-spacing: .1em;
  margin-bottom: .2rem;
}

.dp-type {
  font-family: var(--ff-head);
  font-size: 1.1rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: var(--text);
  margin-bottom: .6rem;
}

.dp-badges {
  display: flex;
  gap: .4rem;
  flex-wrap: wrap;
  margin-bottom: .9rem;
}

.dp-sep {
  border: none;
  border-top: 1px solid var(--border);
  margin: .75rem 0;
}

.dp-field {
  margin-bottom: .65rem;
}

.dp-label {
  font-family: var(--ff-mono);
  font-size: .58rem;
  font-weight: 600;
  letter-spacing: .14em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: .2rem;
}

.dp-val {
  font-size: .8rem;
  color: var(--text-2);
  line-height: 1.55;
}

.dp-val.mono {
  font-family: var(--ff-mono);
  font-size: .73rem;
}

.dp-photo {
  width: 100%;
  height: 110px;
  object-fit: cover;
  border-radius: var(--r);
  border: 1px solid var(--border);
  margin-bottom: .9rem;
}

.dp-actions {
  display: flex;
  flex-direction: column;
  gap: .5rem;
  margin-top: .9rem;
}

.dp-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: .4rem;
  padding: .5rem;
  border-radius: var(--r);
  border: 1.5px solid;
  font-family: var(--ff-head);
  font-size: .78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .07em;
  text-decoration: none;
  cursor: pointer;
  background: transparent;
  transition: all var(--ease);
}

.dp-btn-blue {
  border-color: rgba(29, 110, 245, .3);
  color: var(--blue);
  background: var(--blue-light);
}

.dp-btn-blue:hover {
  background: var(--blue);
  color: #fff;
  border-color: var(--blue);
}

.dp-btn-outline {
  border-color: var(--border);
  color: var(--muted);
}

.dp-btn-outline:hover {
  border-color: var(--navy);
  color: var(--navy);
}

/* ─── STATUS BAR ─────────────────────────────────────────── */
#statusBar {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 28px;
  z-index: 500;
  background: rgba(255, 255, 255, .9);
  border-top: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: 0 .85rem;
  backdrop-filter: blur(8px);
}

#statusText {
  font-family: var(--ff-mono);
  font-size: .62rem;
  color: var(--muted);
}

#lastUpdated {
  font-family: var(--ff-mono);
  font-size: .6rem;
  color: var(--muted-2);
  margin-left: auto;
}

.refresh-btn {
  font-family: var(--ff-mono);
  font-size: .6rem;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--muted);
  background: none;
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: .2rem .6rem;
  cursor: pointer;
  transition: all var(--ease);
}

.refresh-btn:hover {
  color: var(--text);
  border-color: var(--border-2);
  background: var(--surface);
}

/* ─── LEAFLET OVERRIDES ──────────────────────────────────── */
.leaflet-container {
  font-family: var(--ff-body) !important;
}

.leaflet-control-zoom a {
  background: var(--surface) !important;
  color: var(--text-2) !important;
  border-color: var(--border) !important;
}

.leaflet-control-zoom a:hover {
  background: var(--surface-2) !important;
}

.leaflet-control-layers {
  background: var(--surface) !important;
  border: 1px solid var(--border) !important;
  border-radius: var(--r) !important;
  font-family: var(--ff-mono) !important;
  font-size: .72rem !important;
  box-shadow: var(--shadow) !important;
}

.leaflet-popup-content-wrapper {
  background: var(--surface) !important;
  color: var(--text) !important;
  border: 1px solid var(--border) !important;
  border-radius: var(--r-lg) !important;
  box-shadow: var(--shadow) !important;
  font-family: var(--ff-body) !important;
}

.leaflet-popup-tip {
  background: var(--surface) !important;
}

/* ─── PULSE ANIMATION ─────────────────────────────────────── */
@keyframes pulse {
  0% { box-shadow: 0 0 0 0 rgba(232, 39, 29, .6); }
  70% { box-shadow: 0 0 0 12px rgba(232, 39, 29, 0); }
  100% { box-shadow: 0 0 0 0 rgba(232, 39, 29, 0); }
}

/* ─── RESPONSIVE ─────────────────────────────────────────── */
@media (max-width: 768px) {
  #sidebar {
    position: absolute;
    top: 0;
    left: 0;
    bottom: 0;
    box-shadow: var(--shadow);
    width: 280px;
    min-width: 280px;
  }
  
  #sidebarToggle {
    display: flex;
  }
  
  .layer-bar {
    left: 48px;
  }
  
  .nav-center .npill:not(.live-chip) {
    display: none;
  }
  
  #detailPanel {
    width: calc(100vw - 20px);
    right: 10px;
    left: 10px;
    top: 60px;
  }
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
      <span class="brand-sub">Command Center</span>
    </div>
  </a>
  <div class="nav-center">
    <span style="font-family:var(--ff-head);font-size:.95rem;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:.04em;display:flex;align-items:center;gap:.5rem">
      <i class="bi bi-map-fill" style="color:var(--blue)"></i> Live Incident Map
    </span>
    <span class="live-chip"><span class="live-dot"></span>Live Feed</span>
    <div style="flex:1"></div>
    <a href="../incidents/all.php" class="npill"><i class="bi bi-list-ul"></i> All Incidents</a>
    <a href="../incidents/report.php" class="npill"><i class="bi bi-plus-circle"></i> Report</a>
    <a href="../admin/admin_dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
  </div>
  <div class="nav-right">
    <span style="font-family:var(--ff-mono);font-size:.68rem;color:rgba(255,255,255,.5)" class="d-none d-md-block">
      <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?>
    </span>
    <a href="/disaster_response/modules/auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i></a>
  </div>
</nav>

<!-- SHELL -->
<div id="shell">

  <!-- SIDEBAR -->
  <div id="sidebar">
    <div class="sb-head">
      <div class="sb-head-left">
        <span class="sb-head-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
        <span class="sb-head-title">Active Incidents</span>
      </div>
      <span class="sb-count" id="incidentCount"><?= $total_active ?></span>
    </div>
    <div class="sb-filters">
      <select id="filterSeverity" class="sb-select">
        <option value="">All Severities</option>
        <option value="4">🔴 Critical</option>
        <option value="3">🟠 High</option>
        <option value="2">🟡 Medium</option>
        <option value="1">🟢 Low</option>
      </select>
      <select id="filterType" class="sb-select">
        <option value="">All Types</option>
        <?php foreach ($type_icons as $k => $ico): ?>
          <option value="<?= $k ?>"><?= ucfirst(str_replace('_', ' ', $k)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div id="incidentList">
      <div class="spin-wrap" id="listLoading"><div class="spin"></div><p>Loading incidents…</p></div>
      <div class="list-state" id="listEmpty" style="display:none"><i class="bi bi-inbox"></i><p>No incidents match the filter</p></div>
    </div>
  </div>

  <!-- MAP -->
  <div id="map"></div>

  <!-- MOBILE TOGGLE -->
  <button id="sidebarToggle" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>

  <!-- LAYER BAR -->
  <div class="layer-bar" id="layerBar">
    <label class="layer-btn on" id="btnIncidents">
      <input type="checkbox" id="toggleIncidents" checked>
      <span class="layer-dot" style="background:var(--red)"></span> Incidents
    </label>
    <label class="layer-btn" id="btnZones">
      <input type="checkbox" id="toggleZones">
      <span class="layer-dot" style="background:var(--amber)"></span> Danger Zones
    </label>
    <label class="layer-btn" id="btnShelters">
      <input type="checkbox" id="toggleShelters">
      <span class="layer-dot" style="background:var(--green)"></span> Shelters
    </label>
  </div>

  <!-- LEGEND -->
  <div id="legend">
    <div class="legend-title">Severity</div>
    <?php foreach (array_reverse($severity_meta, true) as $sev => $m): ?>
    <div class="legend-row">
      <span class="legend-dot" style="background:<?= $m['color'] ?>"></span>
      <span><?= $m['label'] ?></span>
      <span class="legend-count"><?= $counts[$sev] ?? 0 ?></span>
    </div>
    <?php endforeach; ?>
    <hr class="legend-sep">
    <div class="legend-row"><span class="legend-dot" style="background:var(--green)"></span><span>🏠 Shelter</span></div>
    <div class="legend-row"><span class="legend-dot" style="background:var(--amber);opacity:.7"></span><span>⚠️ Danger Zone</span></div>
  </div>

  <!-- DETAIL PANEL -->
  <div id="detailPanel">
    <div class="dp-head">
      <span class="dp-head-label"><i class="bi bi-info-circle-fill me-1" style="color:var(--blue)"></i>Incident Detail</span>
      <button class="dp-close" onclick="closeDetail()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="detailContent">
      <div class="spin-wrap"><div class="spin"></div><p>Loading…</p></div>
    </div>
  </div>

  <!-- STATUS BAR -->
  <div id="statusBar">
    <span id="statusText">Initialising map…</span>
    <span id="lastUpdated"></span>
    <button class="refresh-btn" onclick="forceRefresh()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
  </div>

</div><!-- /shell -->

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
/* ─── CONSTANTS ─── */
const SEV_META = <?= json_encode($severity_meta) ?>;
const TYPE_EMOJI = <?= json_encode($type_emojis) ?>;

const SEV_CHIP_CLS = {4: 'chip-4', 3: 'chip-3', 2: 'chip-2', 1: 'chip-1'};
const SEV_ITEM_CLS = {4: 'sev-4', 3: 'sev-3', 2: 'sev-2', 1: 'sev-1'};

/* ─── GLOBALS ─── */
let map = null;
let incLayer = null;
let zoneLayer = null;
let shelterLayer = null;
let allFeatures = [];
let activeId = null;
let pollTimer = null;
let isInitialized = false;

/* Helper Functions */
function cap(s) {
    if (!s) return '';
    return s.charAt(0).toUpperCase() + s.slice(1);
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function setStatus(msg) {
    const statusEl = document.getElementById('statusText');
    if (statusEl) statusEl.textContent = msg;
}

function highlightItem(id) {
    document.querySelectorAll('.inc-item').forEach(el => {
        const elId = parseInt(el.dataset.id);
        el.classList.toggle('active', elId === id);
    });
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        sidebar.classList.toggle('hidden');
        if (map) setTimeout(() => map.invalidateSize(), 260);
    }
}

/* ─── MARKER FACTORIES ─── */
function incMarker(severity, type, status) {
    const m = SEV_META[severity] || SEV_META[1];
    const emoji = TYPE_EMOJI[type] || '⚠️';
    const isCrit = severity == 4;
    const sz = isCrit ? 40 : 32;
    const pulse = isCrit ? 'animation:pulse 1.8s ease infinite;' : '';
    const html = `<div style="width:${sz}px;height:${sz}px;background:${m.color};border-radius:50%;border:2.5px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;font-size:${Math.round(sz * .44)}px;${pulse}">${emoji}</div>`;
    return L.divIcon({ html: html, className: '', iconSize: [sz, sz], iconAnchor: [sz / 2, sz / 2], popupAnchor: [0, -sz / 2] });
}

function shelterDot(pct) {
    const c = pct >= 90 ? '#e8271d' : (pct >= 70 ? '#d97706' : '#16a34a');
    return L.divIcon({
        html: `<div style="width:26px;height:26px;background:${c};border-radius:50%;border:2.5px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.2);display:flex;align-items:center;justify-content:center;font-size:13px;">🏠</div>`,
        className: '', iconSize: [26, 26], iconAnchor: [13, 13]
    });
}

/* ─── FILTER HELPER ─── */
function filtered() {
    const sev = document.getElementById('filterSeverity')?.value;
    const typ = document.getElementById('filterType')?.value;
    if (!allFeatures.length) return [];
    return allFeatures.filter(f => {
        if (!f || !f.properties) return false;
        return (!sev || f.properties.severity == sev) && (!typ || f.properties.type === typ);
    });
}

/* ─── SIDEBAR RENDER ─── */
function renderList() {
    const loadingEl = document.getElementById('listLoading');
    if (loadingEl) loadingEl.style.display = 'none';
    
    const data = filtered();
    const listContainer = document.getElementById('incidentList');
    const emptyEl = document.getElementById('listEmpty');
    const countEl = document.getElementById('incidentCount');
    
    if (countEl) countEl.textContent = data.length;
    
    // Remove old items
    if (listContainer) {
        document.querySelectorAll('.inc-item').forEach(el => el.remove());
    }
    
    if (!data.length) {
        if (emptyEl) emptyEl.style.display = '';
        return;
    }
    
    if (emptyEl) emptyEl.style.display = 'none';
    
    data.forEach(f => {
        if (!f || !f.properties) return;
        
        const p = f.properties;
        const sev = parseInt(p.severity);
        const m = SEV_META[sev] || SEV_META[1];
        const ico = TYPE_EMOJI[p.type] || '⚠️';
        const [lng, lat] = f.geometry.coordinates;
        const ts = new Date(p.reported_at).toLocaleString('en-KE', { dateStyle: 'short', timeStyle: 'short' });
        
        const el = document.createElement('div');
        el.className = `inc-item ${SEV_ITEM_CLS[sev] || ''} ${p.id === activeId ? 'active' : ''}`;
        el.dataset.id = p.id;
        el.dataset.lat = lat;
        el.dataset.lng = lng;
        el.innerHTML = `
          <div class="inc-top">
            <div class="inc-id-type">${ico} <span style="font-family:var(--ff-mono);font-size:.7rem;color:var(--muted-2)">#${p.id}</span> ${cap(p.type.replace(/_/g, ' '))}</div>
            <span class="sev-chip ${SEV_CHIP_CLS[sev] || 'chip-1'}">${m.label}</span>
          </div>
          <div class="inc-desc">${(p.description || 'No description provided').substring(0, 100)}</div>
          <div class="inc-foot">
            <span class="inc-time">${ts}</span>
            <span class="st-chip">${p.status}</span>
          </div>`;
        
        el.addEventListener('click', () => {
            if (map) {
                map.flyTo([parseFloat(el.dataset.lat), parseFloat(el.dataset.lng)], 15, { duration: 1 });
            }
            loadDetail(p.id);
        });
        
        if (listContainer) {
            listContainer.insertBefore(el, emptyEl);
        }
    });
}

/* ─── MAP MARKERS RENDER ─── */
function renderMarkers() {
    if (!incLayer) return;
    incLayer.clearLayers();
    
    filtered().forEach(f => {
        if (!f || !f.properties || !f.geometry) return;
        
        const p = f.properties;
        const sev = parseInt(p.severity);
        const m = SEV_META[sev] || SEV_META[1];
        const [lng, lat] = f.geometry.coordinates;
        const ico = TYPE_EMOJI[p.type] || '⚠️';
        const ts = new Date(p.reported_at).toLocaleString('en-KE', { dateStyle: 'short', timeStyle: 'short' });
        const desc = (p.description || '').substring(0, 120) + ((p.description || '').length > 120 ? '…' : '');
        
        const mk = L.marker([lat, lng], { icon: incMarker(sev, p.type, p.status) });
        mk.bindPopup(`
          <div style="min-width:200px">
            <div style="font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:1rem;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.45rem">${ico} #${p.id} — ${cap(p.type.replace(/_/g, ' '))}</div>
            <div style="display:flex;gap:.4rem;margin-bottom:.6rem;flex-wrap:wrap">
              <span style="background:${m.color};color:#fff;font-size:.62rem;font-weight:700;padding:.14rem .55rem;border-radius:3px;">${m.label}</span>
              <span style="background:#f7f8fa;color:#6b7280;font-size:.62rem;font-weight:600;padding:.14rem .5rem;border-radius:3px;border:1px solid #e2e5ea;">${p.status}</span>
            </div>
            <div style="font-size:.8rem;color:#374151;margin-bottom:.5rem;line-height:1.5">${desc}</div>
            <div style="font-family:'IBM Plex Mono',monospace;font-size:.65rem;color:#9ca3af;margin-bottom:.7rem">${ts}</div>
            <button onclick="loadDetail(${p.id})" style="width:100%;background:#1d6ef5;border:none;border-radius:6px;padding:.45rem;font-size:.75rem;font-weight:700;color:#fff;cursor:pointer;">VIEW DETAILS</button>
          </div>`);
        
        mk.on('click', () => {
            activeId = p.id;
            highlightItem(p.id);
        });
        
        incLayer.addLayer(mk);
    });
}

/* ─── DETAIL PANEL ─── */
function loadDetail(id) {
    activeId = id;
    highlightItem(id);
    const panel = document.getElementById('detailPanel');
    const content = document.getElementById('detailContent');
    
    if (panel) panel.style.display = 'block';
    if (content) content.innerHTML = '<div class="spin-wrap"><div class="spin"></div><p>Loading…</p></div>';
    
    fetch(`map.php?action=detail&id=${id}`)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(d => {
            if (d.error) throw new Error(d.error);
            const sev = parseInt(d.severity);
            const m = SEV_META[sev] || SEV_META[1];
            const ico = TYPE_EMOJI[d.incident_type] || '⚠️';
            const ts = new Date(d.reported_at).toLocaleString('en-KE', { dateStyle: 'medium', timeStyle: 'short' });
            const lat = parseFloat(d.latitude).toFixed(5);
            const lng = parseFloat(d.longitude).toFixed(5);
            
            if (content) {
                content.innerHTML = `
                  <div class="dp-icon">${ico}</div>
                  <div class="dp-id">INCIDENT #${d.id}</div>
                  <div class="dp-type">${cap(d.incident_type.replace(/_/g, ' '))}</div>
                  <div class="dp-badges">
                    <span class="sev-chip ${SEV_CHIP_CLS[sev] || 'chip-1'}">${m.label}</span>
                    <span class="st-chip">${d.status}</span>
                  </div>
                  ${d.photo_path ? `<img src="${d.photo_path}" class="dp-photo" alt="Evidence photo" onerror="this.style.display='none'">` : ''}
                  <div class="dp-field"><div class="dp-label">Description</div><div class="dp-val">${escapeHtml(d.description || 'None provided')}</div></div>
                  <div class="dp-field"><div class="dp-label">Reported</div><div class="dp-val">${ts}</div></div>
                  <hr class="dp-sep">
                  <div class="dp-field"><div class="dp-label">Reporter</div><div class="dp-val">${escapeHtml(d.reporter_name)}</div></div>
                  <div class="dp-field"><div class="dp-label">Contact</div><div class="dp-val">${d.reporter_phone || 'N/A'}</div></div>
                  <div class="dp-field"><div class="dp-label">Responder</div><div class="dp-val">${escapeHtml(d.responder_name)}</div></div>
                  <div class="dp-field"><div class="dp-label">Coordinates</div><div class="dp-val mono">${lat}, ${lng}</div></div>
                  <div class="dp-actions">
                    <a href="../incidents/view.php?id=${d.id}" class="dp-btn dp-btn-blue"><i class="bi bi-eye"></i> Full Details</a>
                    <button onclick="if(map) map.flyTo([${d.latitude},${d.longitude}],16)" class="dp-btn dp-btn-outline"><i class="bi bi-crosshair2"></i> Centre Map</button>
                  </div>`;
            }
        })
        .catch(err => {
            console.error('Detail error:', err);
            if (content) {
                content.innerHTML = `<div style="color:var(--red);padding:1rem;font-size:.82rem;text-align:center"><i class="bi bi-exclamation-triangle" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>${err.message || 'Failed to load details'}</div>`;
            }
        });
}

function closeDetail() {
    const panel = document.getElementById('detailPanel');
    if (panel) panel.style.display = 'none';
    activeId = null;
    highlightItem(null);
}

/* ─── ZONES (with error handling) ─── */
function loadZones() {
    fetch('map.php?action=zones')
        .then(r => r.json())
        .then(data => {
            if (zoneLayer) zoneLayer.clearLayers();
            if (data.features && data.features.length) {
                data.features.forEach(f => {
                    try {
                        if (f.geometry && f.geometry.type) {
                            L.geoJSON(f, {
                                style: { color: f.properties.color || '#d97706', weight: 2, fillOpacity: .15, opacity: .7 }
                            }).bindPopup(`<div><strong>⚠️ ${escapeHtml(f.properties.name || 'Danger Zone')}</strong><br>${escapeHtml(f.properties.description || '')}</div>`)
                            .addTo(zoneLayer);
                        }
                    } catch (e) {
                        console.warn('Zone GeoJSON error:', e);
                    }
                });
            }
        })
        .catch(err => console.warn('Zones fetch error (non-critical):', err));
}

/* ─── SHELTERS ─── */
function loadShelters() {
    fetch('map.php?action=shelters')
        .then(r => r.json())
        .then(data => {
            if (shelterLayer) shelterLayer.clearLayers();
            if (data.features && data.features.length) {
                data.features.forEach(f => {
                    const p = f.properties;
                    if (!p.latitude || !p.longitude) return;
                    
                    const pct = p.capacity > 0 ? (p.current_occupancy / p.capacity) * 100 : 0;
                    const pc = pct >= 90 ? '#e8271d' : (pct >= 70 ? '#d97706' : '#16a34a');
                    
                    L.marker([parseFloat(p.latitude), parseFloat(p.longitude)], { icon: shelterDot(pct) })
                        .bindPopup(`
                            <div style="min-width:170px">
                                <strong>🏠 ${escapeHtml(p.name || 'Shelter')}</strong><br>
                                <span style="font-size:.75rem;color:#6b7280">${escapeHtml(p.type || '')}</span>
                                <div style="margin:.5rem 0;font-size:.82rem">Occupancy: <strong>${p.current_occupancy || 0}/${p.capacity || 0}</strong></div>
                                <div style="height:5px;background:#f0f2f5;border-radius:4px;overflow:hidden;margin-bottom:.5rem">
                                    <div style="height:100%;background:${pc};width:${Math.min(pct, 100).toFixed(0)}%"></div>
                                </div>
                                ${p.address ? `<div style="font-size:.75rem;color:#6b7280">${escapeHtml(p.address)}</div>` : ''}
                                ${p.contact_phone ? `<div style="font-size:.75rem;color:#6b7280">${escapeHtml(p.contact_phone)}</div>` : ''}
                            </div>`)
                        .addTo(shelterLayer);
                });
            }
        })
        .catch(err => console.warn('Shelters fetch error (non-critical):', err));
}

/* ─── FEED ─── */
function fetchFeed() {
    setStatus('Refreshing incidents…');
    
    fetch('map.php?action=feed', { cache: 'no-store' })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            allFeatures = data.features || [];
            renderList();
            if (incLayer) renderMarkers();
            
            const lastUpdated = document.getElementById('lastUpdated');
            if (lastUpdated) {
                lastUpdated.textContent = 'Updated: ' + new Date().toLocaleTimeString();
            }
            
            setStatus(`${data.total || 0} active incident${data.total !== 1 ? 's' : ''} — auto-refreshes every 30s`);
        })
        .catch(err => {
            console.error('Feed error:', err);
            setStatus('⚠️ Connection error — retrying…');
        });
}

function forceRefresh() {
    if (pollTimer) clearTimeout(pollTimer);
    fetchFeed();
    schedulePoll();
}

function schedulePoll() {
    pollTimer = setTimeout(() => {
        fetchFeed();
        schedulePoll();
    }, 30000);
}

/* ─── MAP INITIALIZATION ─── */
function initMap() {
    if (isInitialized) return;
    
    map = L.map('map', { zoomControl: false }).setView([-1.2921, 36.8219], 7);
    L.control.zoom({ position: 'bottomleft' }).addTo(map);
    
    const tileStreet = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { 
        attribution: '© OpenStreetMap', 
        maxZoom: 19 
    });
    const tileSat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { 
        attribution: 'Tiles © Esri', 
        maxZoom: 19 
    });
    
    tileStreet.addTo(map);
    L.control.layers({ 'Street Map': tileStreet, 'Satellite': tileSat }, {}, { position: 'topleft' }).addTo(map);
    
    incLayer = L.layerGroup().addTo(map);
    zoneLayer = L.layerGroup();
    shelterLayer = L.layerGroup();
    
    isInitialized = true;
    
    // Initial data load
    fetchFeed();
    schedulePoll();
}

/* ─── LAYER TOGGLES ─── */
document.addEventListener('DOMContentLoaded', function() {
    // Initialize map
    initMap();
    
    // Setup layer toggles
    const toggleIncidents = document.getElementById('toggleIncidents');
    const toggleZones = document.getElementById('toggleZones');
    const toggleShelters = document.getElementById('toggleShelters');
    const btnIncidents = document.getElementById('btnIncidents');
    const btnZones = document.getElementById('btnZones');
    const btnShelters = document.getElementById('btnShelters');
    
    if (toggleIncidents) {
        toggleIncidents.addEventListener('change', e => {
            if (btnIncidents) btnIncidents.classList.toggle('on', e.target.checked);
            if (e.target.checked && incLayer && map) incLayer.addTo(map);
            else if (incLayer && map) incLayer.remove();
        });
    }
    
    if (toggleZones) {
        toggleZones.addEventListener('change', e => {
            if (btnZones) btnZones.classList.toggle('on', e.target.checked);
            if (e.target.checked) {
                loadZones();
                if (zoneLayer && map) zoneLayer.addTo(map);
            } else if (zoneLayer && map) {
                zoneLayer.remove();
            }
        });
    }
    
    if (toggleShelters) {
        toggleShelters.addEventListener('change', e => {
            if (btnShelters) btnShelters.classList.toggle('on', e.target.checked);
            if (e.target.checked) {
                loadShelters();
                if (shelterLayer && map) shelterLayer.addTo(map);
            } else if (shelterLayer && map) {
                shelterLayer.remove();
            }
        });
    }
    
    // Setup filters
    const filterSeverity = document.getElementById('filterSeverity');
    const filterType = document.getElementById('filterType');
    
    if (filterSeverity) {
        filterSeverity.addEventListener('change', () => {
            renderList();
            renderMarkers();
        });
    }
    
    if (filterType) {
        filterType.addEventListener('change', () => {
            renderList();
            renderMarkers();
        });
    }
});

// Ensure map resizes when sidebar toggles on mobile
window.addEventListener('resize', function() {
    if (map) setTimeout(() => map.invalidateSize(), 100);
});
</script>
</body>
</html>