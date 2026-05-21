<?php
/**
 * Live Incident Map Module
 * Disaster Response & Resource Coordination System
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

role_guard(['responder', 'admin', 'volunteer']);

$action = $_GET['action'] ?? '';

if ($action === 'feed') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        $stmt = $pdo->prepare("
            SELECT i.id, i.incident_type, i.severity, i.description,
                   i.latitude, i.longitude, i.status, i.photo_path, i.reported_at,
                   COALESCE(u.full_name, 'Anonymous') AS reporter_name
            FROM   incidents i
            LEFT   JOIN users u ON u.id = i.reporter_id
            WHERE  i.status NOT IN ('resolved','cancelled','rejected')
            ORDER  BY i.severity DESC, i.reported_at DESC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $features = [];
        foreach ($rows as $r) {
            $features[] = [
                'type'     => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [(float)$r['longitude'], (float)$r['latitude']]],
                'properties' => [
                    'id' => (int)$r['id'], 'type' => $r['incident_type'],
                    'severity' => (int)$r['severity'], 'status' => $r['status'],
                    'description' => $r['description'], 'reporter' => $r['reporter_name'],
                    'photo' => $r['photo_path'], 'reported_at' => $r['reported_at'],
                ],
            ];
        }
        echo json_encode(['type' => 'FeatureCollection', 'features' => $features, 'generated' => date('c'), 'total' => count($features)]);
    } catch (PDOException $e) {
        error_log('Map feed error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
    exit;
}

if ($action === 'detail') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            SELECT i.*, COALESCE(u.full_name,'Anonymous') AS reporter_name,
                   COALESCE(u.phone,'N/A') AS reporter_phone,
                   COALESCE(r.full_name,'Unassigned') AS responder_name
            FROM   incidents i
            LEFT   JOIN users u ON u.id = i.reporter_id
            LEFT   JOIN users r ON r.id = i.assigned_to
            WHERE  i.id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
        echo json_encode($row);
    } catch (PDOException $e) {
        http_response_code(500); echo json_encode(['error' => 'Database error']);
    }
    exit;
}

if ($action === 'zones') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        $stmt = $pdo->prepare("SELECT id, name, description, hazard_level, geometry, status FROM danger_zones WHERE status = 'active'");
        $stmt->execute();
        $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hazard_colors = ['critical' => '#E8271A', 'high' => '#D97706', 'medium' => '#CA8A04', 'low' => '#16A34A'];
        $features = [];
        foreach ($zones as $zone) {
            $geometry = json_decode($zone['geometry'], true);
            if ($geometry) {
                $features[] = ['type' => 'Feature', 'geometry' => $geometry, 'properties' => [
                    'id' => $zone['id'], 'name' => $zone['name'], 'description' => $zone['description'],
                    'hazard_level' => $zone['hazard_level'], 'color' => $hazard_colors[$zone['hazard_level']] ?? '#E8271A',
                ]];
            }
        }
        echo json_encode(['type' => 'FeatureCollection', 'features' => $features, 'total' => count($features)]);
    } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => 'Database error']); }
    exit;
}

if ($action === 'shelters') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        $stmt = $pdo->prepare("SELECT id, name, type, capacity, current_occupancy, latitude, longitude, address, contact_phone, resources, status FROM shelters WHERE status = 'active'");
        $stmt->execute();
        $shelters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $features = [];
        foreach ($shelters as $s) {
            $features[] = ['type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [(float)$s['longitude'], (float)$s['latitude']]],
                'properties' => array_merge($s, ['capacity' => (int)$s['capacity'], 'current_occupancy' => (int)$s['current_occupancy']])
            ];
        }
        echo json_encode(['type' => 'FeatureCollection', 'features' => $features, 'total' => count($features)]);
    } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => 'Database error']); }
    exit;
}

/* ─── Full page render ─── */
$severity_meta = [
    1 => ['label' => 'Low',      'color' => '#16A34A', 'border' => '#15803D'],
    2 => ['label' => 'Medium',   'color' => '#CA8A04', 'border' => '#A16207'],
    3 => ['label' => 'High',     'color' => '#D97706', 'border' => '#B45309'],
    4 => ['label' => 'Critical', 'color' => '#E8271A', 'border' => '#B91C1C'],
];
$incident_type_icons = [
    'flood'             => '🌊',
    'fire'              => '🔥',
    'earthquake'        => '🏚️',
    'landslide'         => '⛰️',
    'drought'           => '☀️',
    'accident'          => '🚗',
    'building_collapse' => '🏗️',
    'disease_outbreak'  => '🦠',
    'other'             => '⚠️',
];
try {
    $counts = $pdo->query("SELECT severity, COUNT(*) AS cnt FROM incidents WHERE status NOT IN ('resolved','cancelled','rejected') GROUP BY severity")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) { $counts = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Incident Map — DisasterResponse</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root {
            --black: #080808;
            --surface: #111111;
            --card: #161616;
            --card2: #1C1C1C;
            --border: rgba(255,255,255,0.07);
            --border-hover: rgba(255,255,255,0.13);
            --red: #E8271A;
            --red-dim: rgba(232,39,26,0.1);
            --red-border: rgba(232,39,26,0.28);
            --green: #16A34A;
            --green-dim: rgba(22,163,74,0.1);
            --green-border: rgba(22,163,74,0.25);
            --amber: #D97706;
            --amber-dim: rgba(217,119,6,0.1);
            --text: #F0EDE8;
            --muted: #6B6865;
            --muted2: #9A9693;
            --heading: 'Bebas Neue', sans-serif;
            --body: 'DM Sans', sans-serif;
            --mono: 'DM Mono', monospace;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--body); background: var(--black); color: var(--text); overflow: hidden; height: 100vh; display: flex; flex-direction: column; }
        ::-webkit-scrollbar { width: 3px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--red); border-radius: 2px; }

        /* ─── NAV ─── */
        .nav {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 24px;
            background: rgba(8,8,8,0.97);
            border-bottom: 1px solid var(--border);
            flex-shrink: 0;
            z-index: 1000;
        }
        .nav-brand {
            font-family: var(--heading);
            font-size: 1.4rem; letter-spacing: 0.06em;
            color: var(--red); text-decoration: none;
            display: flex; align-items: center; gap: 8px;
        }
        .nav-brand span { color: var(--text); }
        .live-badge {
            display: inline-flex; align-items: center; gap: 5px;
            font-family: var(--mono);
            font-size: 0.6rem; letter-spacing: 0.15em; text-transform: uppercase;
            background: var(--red-dim); border: 1px solid var(--red-border);
            color: #F87171; padding: 3px 10px; border-radius: 100px;
        }
        .live-dot { width: 5px; height: 5px; background: var(--red); border-radius: 50%; animation: blink 1.4s infinite; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.2} }
        .nav-right { display: flex; align-items: center; gap: 6px; }
        .nav-btn {
            font-size: 0.72rem; font-weight: 500; letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--muted); text-decoration: none;
            padding: 7px 14px; border-radius: 6px;
            border: 1px solid transparent;
            transition: all 0.18s;
        }
        .nav-btn:hover { color: var(--text); border-color: var(--border); background: var(--card); }
        .nav-btn.red { color: var(--red); border-color: var(--red-border); background: var(--red-dim); }
        .nav-btn.red:hover { background: rgba(232,39,26,0.18); }

        /* ─── MAP WRAPPER ─── */
        #mapWrapper {
            display: flex;
            flex: 1;
            overflow: hidden;
            position: relative;
        }

        /* ─── SIDEBAR ─── */
        #sidebar {
            width: 320px;
            min-width: 320px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            z-index: 800;
            transition: transform 0.25s ease, min-width 0.25s ease, width 0.25s ease;
        }
        #sidebar.collapsed {
            transform: translateX(-100%);
            position: absolute;
            top: 0; left: 0; bottom: 0;
            min-width: 320px;
        }

        .sidebar-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 16px;
            background: var(--card2);
            border-bottom: 1px solid var(--border);
            flex-shrink: 0;
        }
        .sidebar-head-title {
            font-family: var(--mono);
            font-size: 0.63rem; letter-spacing: 0.18em; text-transform: uppercase;
            color: var(--muted2);
            display: flex; align-items: center; gap: 8px;
        }
        .sidebar-head-title i { color: var(--red); }
        .inc-count {
            font-family: var(--heading);
            font-size: 1.4rem; letter-spacing: 0.04em; line-height: 1;
            color: var(--red);
        }

        .filter-row {
            display: flex; gap: 8px;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            flex-shrink: 0;
        }
        .filter-select {
            flex: 1;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 7px;
            padding: 7px 10px;
            font-family: var(--body);
            font-size: 0.75rem;
            color: var(--muted2);
            outline: none;
            -webkit-appearance: none;
            cursor: pointer;
            transition: border-color 0.18s;
        }
        .filter-select:focus { border-color: var(--red-border); color: var(--text); }

        #incidentList { overflow-y: auto; flex: 1; }

        .inc-item {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            border-left: 3px solid transparent;
            cursor: pointer;
            transition: background 0.15s, border-left-color 0.15s;
        }
        .inc-item:hover { background: var(--card2); }
        .inc-item.active { background: var(--red-dim); border-left-color: var(--red); }

        .inc-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: 5px; }
        .inc-title { font-size: 0.85rem; font-weight: 600; line-height: 1.35; }
        .sev-pip {
            font-family: var(--mono);
            font-size: 0.58rem; letter-spacing: 0.1em; text-transform: uppercase;
            padding: 3px 8px; border-radius: 100px; border: 1px solid;
            white-space: nowrap; flex-shrink: 0;
        }
        .inc-desc { font-size: 0.77rem; color: var(--muted2); line-height: 1.5; margin-bottom: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .inc-meta { display: flex; align-items: center; gap: 8px; font-family: var(--mono); font-size: 0.62rem; color: var(--muted); }
        .status-pip {
            display: inline-block;
            padding: 2px 7px; border-radius: 100px;
            font-size: 0.58rem; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase;
            background: var(--card2); color: var(--muted2); border: 1px solid var(--border);
        }

        #listEmpty { padding: 40px 16px; text-align: center; color: var(--muted); font-size: 0.82rem; display: none; }
        #listEmpty i { font-size: 1.8rem; display: block; margin-bottom: 10px; opacity: 0.3; }
        #listLoading { padding: 40px 16px; text-align: center; }
        .spin { width: 22px; height: 22px; border: 2px solid var(--border); border-top-color: var(--red); border-radius: 50%; animation: spin 0.7s linear infinite; margin: 0 auto 10px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        #listLoading p { font-family: var(--mono); font-size: 0.68rem; color: var(--muted); letter-spacing: 0.1em; text-transform: uppercase; }

        /* ─── MAP ─── */
        #map { flex: 1; background: #0d0d0d; }

        /* ─── LAYER TOGGLE ─── */
        .layer-bar {
            position: absolute;
            top: 12px; left: 16px;
            z-index: 800;
            display: flex; gap: 6px;
        }
        .layer-btn {
            display: flex; align-items: center; gap: 7px;
            background: rgba(8,8,8,0.9);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 7px 13px;
            font-family: var(--mono);
            font-size: 0.63rem; letter-spacing: 0.12em; text-transform: uppercase;
            color: var(--muted);
            cursor: pointer;
            transition: all 0.18s;
            backdrop-filter: blur(10px);
            white-space: nowrap;
        }
        .layer-btn.on { color: var(--text); border-color: var(--border-hover); background: rgba(22,22,22,0.95); }
        .layer-btn input { display: none; }
        .layer-indicator { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }

        /* ─── SIDEBAR TOGGLE (mobile) ─── */
        #sidebarToggle {
            position: absolute;
            top: 12px; left: 16px;
            z-index: 900;
            display: none;
            background: rgba(8,8,8,0.9);
            border: 1px solid var(--border);
            color: var(--muted2);
            width: 36px; height: 36px;
            border-radius: 8px;
            align-items: center; justify-content: center;
            cursor: pointer; font-size: 1rem;
            backdrop-filter: blur(10px);
        }
        @media (max-width: 768px) {
            #sidebar { position: absolute; top: 0; left: 0; bottom: 0; z-index: 900; }
            #sidebarToggle { display: flex; }
            .layer-bar { left: 60px; }
        }

        /* ─── LEGEND ─── */
        #legend {
            position: absolute;
            bottom: 36px; right: 12px;
            z-index: 800;
            background: rgba(8,8,8,0.92);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
            min-width: 148px;
            backdrop-filter: blur(12px);
        }
        .legend-title {
            font-family: var(--mono);
            font-size: 0.58rem; letter-spacing: 0.2em; text-transform: uppercase;
            color: var(--muted); margin-bottom: 10px;
        }
        .legend-row { display: flex; align-items: center; gap: 8px; margin-bottom: 7px; font-size: 0.78rem; }
        .legend-row:last-child { margin-bottom: 0; }
        .legend-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
        .legend-count { margin-left: auto; font-family: var(--mono); font-size: 0.65rem; color: var(--muted); }
        .legend-sep { border-top: 1px solid var(--border); margin: 10px 0; }

        /* ─── DETAIL PANEL ─── */
        #detailPanel {
            position: absolute;
            top: 12px; right: 12px;
            width: 320px;
            background: rgba(8,8,8,0.95);
            border: 1px solid var(--border);
            border-radius: 14px;
            z-index: 810;
            display: none;
            overflow: hidden;
            backdrop-filter: blur(16px);
        }
        .detail-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 16px;
            background: var(--card2);
            border-bottom: 1px solid var(--border);
        }
        .detail-head-label {
            font-family: var(--mono);
            font-size: 0.62rem; letter-spacing: 0.15em; text-transform: uppercase;
            color: var(--muted);
        }
        .detail-close {
            width: 26px; height: 26px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--muted2);
            cursor: pointer;
            font-size: 0.75rem;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.15s;
        }
        .detail-close:hover { color: var(--text); border-color: var(--border-hover); }
        #detailContent { padding: 18px 16px; overflow-y: auto; max-height: calc(100vh - 160px); }

        .detail-icon { font-size: 2.2rem; margin-bottom: 10px; }
        .detail-id { font-family: var(--mono); font-size: 0.65rem; color: var(--muted); letter-spacing: 0.1em; margin-bottom: 4px; }
        .detail-type { font-size: 1.1rem; font-weight: 700; margin-bottom: 10px; }
        .detail-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
        .detail-sep { border-top: 1px solid var(--border); margin: 14px 0; }
        .detail-field { margin-bottom: 10px; }
        .detail-field-label { font-family: var(--mono); font-size: 0.6rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--muted); margin-bottom: 3px; }
        .detail-field-val { font-size: 0.82rem; color: var(--muted2); line-height: 1.55; }
        .detail-photo { width: 100%; height: 120px; object-fit: cover; border-radius: 8px; margin-bottom: 14px; border: 1px solid var(--border); }
        .detail-actions { display: flex; flex-direction: column; gap: 7px; margin-top: 16px; }
        .d-btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 10px 14px; border-radius: 8px; border: 1px solid;
            font-size: 0.78rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase;
            text-decoration: none; cursor: pointer; background: none;
            font-family: var(--body);
            transition: all 0.18s;
        }
        .d-btn-red { border-color: var(--red-border); color: #F87171; background: var(--red-dim); }
        .d-btn-red:hover { background: rgba(232,39,26,0.18); }
        .d-btn-ghost { border-color: var(--border); color: var(--muted2); }
        .d-btn-ghost:hover { border-color: var(--border-hover); color: var(--text); }

        /* ─── STATUS BAR ─── */
        #statusBar {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 28px;
            display: flex; align-items: center; gap: 14px;
            padding: 0 14px;
            background: rgba(8,8,8,0.9);
            border-top: 1px solid var(--border);
            z-index: 700;
            backdrop-filter: blur(10px);
        }
        #statusText { font-family: var(--mono); font-size: 0.63rem; color: var(--muted); letter-spacing: 0.08em; }
        #lastUpdated { font-family: var(--mono); font-size: 0.6rem; color: var(--muted); margin-left: auto; }
        .refresh-btn {
            font-family: var(--mono); font-size: 0.6rem; letter-spacing: 0.1em; text-transform: uppercase;
            color: var(--muted2); background: none; border: 1px solid var(--border);
            border-radius: 5px; padding: 3px 9px; cursor: pointer;
            transition: all 0.18s;
        }
        .refresh-btn:hover { color: var(--text); border-color: var(--border-hover); }

        /* ─── LEAFLET OVERRIDES ─── */
        .leaflet-container { background: #0d1117 !important; font-family: var(--body); }
        .leaflet-tile { filter: brightness(0.75) saturate(0.6) hue-rotate(180deg); }
        .leaflet-control-zoom a { background: var(--card) !important; color: var(--muted2) !important; border-color: var(--border) !important; font-size: 0.9rem; }
        .leaflet-control-zoom a:hover { background: var(--card2) !important; color: var(--text) !important; }
        .leaflet-control-layers { background: var(--card) !important; border: 1px solid var(--border) !important; border-radius: 8px !important; color: var(--muted2) !important; font-family: var(--mono); font-size: 0.72rem; }
        .leaflet-control-layers-toggle { background-color: var(--card) !important; }
        .leaflet-popup-content-wrapper { background: rgba(8,8,8,0.96) !important; color: var(--text) !important; border: 1px solid var(--border) !important; border-radius: 10px !important; box-shadow: 0 8px 32px rgba(0,0,0,0.5) !important; font-family: var(--body); backdrop-filter: blur(16px); }
        .leaflet-popup-tip { background: rgba(8,8,8,0.96) !important; }
        .leaflet-popup-close-button { color: var(--muted2) !important; }
        .leaflet-popup-close-button:hover { color: var(--text) !important; }

        /* ─── PULSE ANIMATION ─── */
        @keyframes pulse {
            0%   { box-shadow: 0 0 0 0 rgba(232,39,26,0.7); }
            70%  { box-shadow: 0 0 0 14px rgba(232,39,26,0); }
            100% { box-shadow: 0 0 0 0 rgba(232,39,26,0); }
        }
        .marker-pulse { animation: pulse 1.8s ease infinite; border-radius: 50%; }
    </style>
</head>
<body>

<!-- ─── NAV ─── -->
<nav class="nav">
    <div style="display:flex;align-items:center;gap:14px;">
        <a href="../responders/responders_dashboard.php" class="nav-brand"><i class="fas fa-map"></i><span>Live</span>Map</a>
        <div class="live-badge"><span class="live-dot"></span>Live Feed</div>
    </div>
    <div class="nav-right">
        <a href="../incidents/all.php" class="nav-btn">All Incidents</a>
        <a href="../responders/responders_dashboard.php" class="nav-btn red"><i class="fas fa-arrow-left" style="font-size:0.7rem;"></i> Dashboard</a>
    </div>
</nav>

<div id="mapWrapper">

    <!-- ─── SIDEBAR ─── -->
    <div id="sidebar">
        <div class="sidebar-head">
            <div class="sidebar-head-title"><i class="fas fa-triangle-exclamation"></i> Active Incidents</div>
            <div class="inc-count" id="incidentCount">—</div>
        </div>

        <div class="filter-row">
            <select id="filterSeverity" class="filter-select">
                <option value="">All Severities</option>
                <option value="4">Critical</option>
                <option value="3">High</option>
                <option value="2">Medium</option>
                <option value="1">Low</option>
            </select>
            <select id="filterType" class="filter-select">
                <option value="">All Types</option>
                <?php foreach ($incident_type_icons as $k => $icon): ?>
                    <option value="<?= $k ?>"><?= ucfirst(str_replace('_',' ',$k)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="incidentList">
            <div id="listLoading">
                <div class="spin"></div>
                <p>Loading incidents…</p>
            </div>
            <div id="listEmpty"><i class="fas fa-inbox"></i>No incidents match the filter</div>
        </div>
    </div>

    <!-- ─── MAP ─── -->
    <div id="map"></div>

    <!-- ─── SIDEBAR TOGGLE (mobile) ─── -->
    <button id="sidebarToggle" onclick="document.getElementById('sidebar').classList.toggle('collapsed');setTimeout(()=>map.invalidateSize(),260);">
        <i class="fas fa-bars"></i>
    </button>

    <!-- ─── LAYER BAR ─── -->
    <div class="layer-bar" id="layerBar">
        <label class="layer-btn on" id="btnIncidents">
            <input type="checkbox" id="toggleIncidents" checked>
            <span class="layer-indicator" style="background:var(--red);"></span>
            Incidents
        </label>
        <label class="layer-btn" id="btnZones">
            <input type="checkbox" id="toggleZones">
            <span class="layer-indicator" style="background:var(--amber);"></span>
            Zones
        </label>
        <label class="layer-btn" id="btnShelters">
            <input type="checkbox" id="toggleShelters">
            <span class="layer-indicator" style="background:var(--green);"></span>
            Shelters
        </label>
    </div>

    <!-- ─── LEGEND ─── */-->
    <div id="legend">
        <div class="legend-title">Severity</div>
        <?php foreach (array_reverse($severity_meta, true) as $sev => $m): ?>
        <div class="legend-row">
            <span class="legend-dot" style="background:<?= $m['color'] ?>;"></span>
            <span><?= $m['label'] ?></span>
            <span class="legend-count"><?= $counts[$sev] ?? 0 ?></span>
        </div>
        <?php endforeach; ?>
        <div class="legend-sep"></div>
        <div class="legend-row"><span class="legend-dot" style="background:var(--green);"></span><span>Shelter</span></div>
        <div class="legend-row"><span class="legend-dot" style="background:var(--amber);opacity:0.7;"></span><span>Danger Zone</span></div>
    </div>

    <!-- ─── DETAIL PANEL ─── -->
    <div id="detailPanel">
        <div class="detail-head">
            <span class="detail-head-label">Incident Detail</span>
            <button class="detail-close" onclick="closeDetail()"><i class="fas fa-xmark"></i></button>
        </div>
        <div id="detailContent">
            <div id="detailLoading" style="text-align:center;padding:30px 0;">
                <div class="spin" style="margin-bottom:10px;"></div>
                <p style="font-family:var(--mono);font-size:0.68rem;color:var(--muted);letter-spacing:0.1em;">LOADING…</p>
            </div>
        </div>
    </div>

    <!-- ─── STATUS BAR ─── -->
    <div id="statusBar">
        <span id="statusText">Initialising…</span>
        <span id="lastUpdated"></span>
        <button class="refresh-btn" onclick="forceRefresh()"><i class="fas fa-rotate-right" style="margin-right:4px;"></i>Refresh</button>
    </div>

</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const SEVERITY_META = <?= json_encode($severity_meta) ?>;
const TYPE_ICONS    = <?= json_encode($incident_type_icons) ?>;

// ─── MAP INIT ───
const map = L.map('map', { zoomControl: false }).setView([-1.2921, 36.8219], 7);
L.control.zoom({ position: 'bottomleft' }).addTo(map);

const tileOSM = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 });
const tileSat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: 'Tiles © Esri', maxZoom: 19 });
tileOSM.addTo(map);
L.control.layers({ 'Street': tileOSM, 'Satellite': tileSat }, {}, { position: 'topleft' }).addTo(map);

let incidentLayer = L.layerGroup().addTo(map);
let zoneLayer     = L.layerGroup();
let shelterLayer  = L.layerGroup();

let allFeatures = [];
let activeId    = null;
let pollTimer   = null;

// ─── MARKER FACTORY ───
function makeMarker(severity, type, status) {
    const m    = SEVERITY_META[severity] || SEVERITY_META[1];
    const ico  = TYPE_ICONS[type] || '⚠️';
    const isCrit = severity === 4 && status !== 'assigned';
    const sz   = isCrit ? 42 : 34;
    const fill = status === 'assigned' ? '#444' : m.color;
    const html = `<div style="width:${sz}px;height:${sz}px;background:${fill};border-radius:50%;border:2px solid ${m.border};display:flex;align-items:center;justify-content:center;font-size:${sz*0.42}px;${isCrit ? 'animation:pulse 1.8s ease infinite;' : ''}">${ico}</div>`;
    return L.divIcon({ html, className: '', iconSize: [sz, sz], iconAnchor: [sz/2, sz/2], popupAnchor: [0, -sz/2] });
}

function shelterMarker(pct) {
    const c = pct >= 90 ? '#E8271A' : pct >= 70 ? '#D97706' : '#16A34A';
    return L.divIcon({
        html: `<div style="width:28px;height:28px;background:${c};border-radius:50%;border:2px solid rgba(255,255,255,0.25);display:flex;align-items:center;justify-content:center;font-size:14px;">🏠</div>`,
        className: '', iconSize: [28, 28], iconAnchor: [14, 14]
    });
}

// ─── SIDEBAR RENDER ───
function getSevClass(sev) {
    const cols = {4:'rgba(232,39,26,0.12)',3:'rgba(217,119,6,0.1)',2:'rgba(202,138,4,0.1)',1:'rgba(22,163,74,0.1)'};
    const tc   = {4:'#F87171',3:'#FBBF24',2:'#FDE68A',1:'#86EFAC'};
    const bc   = {4:'rgba(232,39,26,0.28)',3:'rgba(217,119,6,0.25)',2:'rgba(202,138,4,0.22)',1:'rgba(22,163,74,0.25)'};
    const lb   = {4:'Critical',3:'High',2:'Medium',1:'Low'};
    return {bg:cols[sev]||cols[1], color:tc[sev]||tc[1], border:bc[sev]||bc[1], label:lb[sev]||lb[1]};
}

function renderSidebar() {
    const data = filteredFeatures();
    document.getElementById('listLoading').style.display = 'none';
    document.getElementById('incidentCount').textContent = data.length;

    const items = document.querySelectorAll('.inc-item');
    items.forEach(el => el.remove());

    const empty = document.getElementById('listEmpty');
    if (data.length === 0) { empty.style.display = 'block'; return; }
    empty.style.display = 'none';

    const list = document.getElementById('incidentList');
    data.forEach(f => {
        const p = f.properties;
        const s = getSevClass(p.severity);
        const ico = TYPE_ICONS[p.type] || '⚠️';
        const ts  = new Date(p.reported_at).toLocaleString('en-KE', { dateStyle: 'short', timeStyle: 'short' });
        const el  = document.createElement('div');
        el.className = 'inc-item' + (p.id === activeId ? ' active' : '');
        el.style.borderLeftColor = SEVERITY_META[p.severity]?.color || '#444';
        el.dataset.id  = p.id;
        el.dataset.lat = f.geometry.coordinates[1];
        el.dataset.lng = f.geometry.coordinates[0];
        el.innerHTML = `
            <div class="inc-header">
                <div class="inc-title">${ico} #${p.id} — ${cap(p.type.replace(/_/g,' '))}</div>
                <span class="sev-pip" style="background:${s.bg};color:${s.color};border-color:${s.border};">${s.label}</span>
            </div>
            <div class="inc-desc">${p.description || 'No description'}</div>
            <div class="inc-meta">${ts} <span class="status-pip">${p.status}</span></div>`;
        el.addEventListener('click', () => {
            flyTo(parseFloat(el.dataset.lat), parseFloat(el.dataset.lng));
            loadDetail(p.id);
        });
        list.insertBefore(el, empty);
    });
}

function renderMarkers() {
    incidentLayer.clearLayers();
    filteredFeatures().forEach(f => {
        const p  = f.properties;
        const [lng, lat] = f.geometry.coordinates;
        const m  = SEVERITY_META[p.severity] || SEVERITY_META[1];
        const ts = new Date(p.reported_at).toLocaleString('en-KE', { dateStyle: 'short', timeStyle: 'short' });
        const mk = L.marker([lat, lng], { icon: makeMarker(p.severity, p.type, p.status) });
        mk.bindPopup(`
            <div style="min-width:210px;font-family:'DM Sans',sans-serif;">
                <div style="font-weight:700;font-size:0.95rem;margin-bottom:6px;">${TYPE_ICONS[p.type]||'⚠️'} #${p.id} — ${cap(p.type.replace(/_/g,' '))}</div>
                <div style="display:flex;gap:6px;margin-bottom:8px;">
                    <span style="background:${m.color};color:#000;font-size:0.65rem;font-weight:700;padding:2px 8px;border-radius:100px;">${m.label}</span>
                    <span style="background:rgba(255,255,255,0.08);color:#9A9693;font-size:0.65rem;padding:2px 8px;border-radius:100px;border:1px solid rgba(255,255,255,0.07);">${p.status}</span>
                </div>
                <div style="font-size:0.8rem;color:#9A9693;margin-bottom:10px;line-height:1.55;">${(p.description||'').substring(0,110)}${(p.description||'').length>110?'…':''}</div>
                <div style="font-size:0.7rem;color:#6B6865;margin-bottom:10px;">${ts}</div>
                <button onclick="loadDetail(${p.id})" style="width:100%;background:#E8271A;border:none;border-radius:7px;padding:8px;font-size:0.75rem;font-weight:700;color:#fff;cursor:pointer;letter-spacing:0.06em;text-transform:uppercase;">View Details</button>
            </div>`);
        mk.on('click', () => { activeId = p.id; highlightItem(p.id); });
        incidentLayer.addLayer(mk);
    });
}

// ─── DETAIL PANEL ───
function loadDetail(id) {
    activeId = id;
    highlightItem(id);
    document.getElementById('detailPanel').style.display = 'block';
    document.getElementById('detailContent').innerHTML = `<div style="text-align:center;padding:30px 0;"><div class="spin" style="margin-bottom:10px;"></div><p style="font-family:var(--mono);font-size:0.68rem;color:var(--muted);letter-spacing:0.1em;">LOADING…</p></div>`;
    fetch(`map.php?action=detail&id=${id}`)
        .then(r => r.json())
        .then(d => {
            const m   = SEVERITY_META[d.severity] || SEVERITY_META[1];
            const ico = TYPE_ICONS[d.incident_type] || '⚠️';
            const ts  = new Date(d.reported_at).toLocaleString('en-KE', { dateStyle: 'medium', timeStyle: 'short' });
            const s   = getSevClass(d.severity);
            document.getElementById('detailContent').innerHTML = `
                <div class="detail-icon">${ico}</div>
                <div class="detail-id">INCIDENT #${d.id}</div>
                <div class="detail-type">${cap(d.incident_type.replace(/_/g,' '))}</div>
                <div class="detail-badges">
                    <span class="sev-pip" style="background:${s.bg};color:${s.color};border-color:${s.border};">${s.label}</span>
                    <span class="sev-pip" style="background:rgba(255,255,255,0.04);color:var(--muted2);border-color:var(--border);">${d.status}</span>
                </div>
                ${d.photo_path ? `<img src="${d.photo_path}" class="detail-photo" alt="Incident photo">` : ''}
                <div class="detail-field"><div class="detail-field-label">Description</div><div class="detail-field-val">${d.description || 'None provided'}</div></div>
                <div class="detail-field"><div class="detail-field-label">Reported</div><div class="detail-field-val">${ts}</div></div>
                <div class="detail-sep"></div>
                <div class="detail-field"><div class="detail-field-label">Reporter</div><div class="detail-field-val">${d.reporter_name}</div></div>
                <div class="detail-field"><div class="detail-field-label">Contact</div><div class="detail-field-val">${d.reporter_phone}</div></div>
                <div class="detail-field"><div class="detail-field-label">Responder</div><div class="detail-field-val">${d.responder_name}</div></div>
                <div class="detail-field"><div class="detail-field-label">Coordinates</div><div class="detail-field-val" style="font-family:var(--mono);font-size:0.72rem;">${parseFloat(d.latitude).toFixed(5)}, ${parseFloat(d.longitude).toFixed(5)}</div></div>
                <div class="detail-actions">
                    <a href="../incidents/view.php?id=${d.id}" class="d-btn d-btn-red"><i class="fas fa-eye"></i> Full Details</a>
                    <button onclick="map.flyTo([${d.latitude},${d.longitude}],16)" class="d-btn d-btn-ghost"><i class="fas fa-crosshairs"></i> Centre Map</button>
                </div>`;
        })
        .catch(() => { document.getElementById('detailContent').innerHTML = '<div style="color:#F87171;padding:20px;font-size:0.82rem;">Failed to load incident details.</div>'; });
}
function closeDetail() { document.getElementById('detailPanel').style.display = 'none'; activeId = null; highlightItem(null); }

// ─── ZONES & SHELTERS ───
function loadZones() {
    fetch('map.php?action=zones').then(r=>r.json()).then(data => {
        zoneLayer.clearLayers();
        data.features.forEach(f => {
            L.geoJSON(f, {
                style: { color: f.properties.color, weight: 2, fillOpacity: 0.18, opacity: 0.7 }
            }).bindPopup(`<div style="font-family:'DM Sans',sans-serif;"><strong>⚠️ ${f.properties.name}</strong><br><span style="font-size:0.78rem;color:#9A9693;">${f.properties.description||''}</span></div>`).addTo(zoneLayer);
        });
    });
}
function loadShelters() {
    fetch('map.php?action=shelters').then(r=>r.json()).then(data => {
        shelterLayer.clearLayers();
        data.features.forEach(f => {
            const p   = f.properties;
            const pct = p.capacity > 0 ? (p.current_occupancy / p.capacity) * 100 : 0;
            L.marker([f.geometry.coordinates[1], f.geometry.coordinates[0]], { icon: shelterMarker(pct) })
             .bindPopup(`<div style="font-family:'DM Sans',sans-serif;min-width:180px;">
                <strong>🏠 ${p.name}</strong><br>
                <span style="font-size:0.75rem;color:#9A9693;">${p.type||''}</span>
                <div style="margin:8px 0;font-size:0.82rem;">Capacity: ${p.current_occupancy}/${p.capacity}</div>
                <div style="height:4px;background:rgba(255,255,255,0.08);border-radius:2px;overflow:hidden;margin-bottom:8px;"><div style="height:100%;background:${pct>=90?'#E8271A':pct>=70?'#D97706':'#16A34A'};width:${pct}%;"></div></div>
                ${p.address?`<div style="font-size:0.78rem;color:#9A9693;">${p.address}</div>`:''}
                ${p.contact_phone?`<div style="font-size:0.78rem;color:#9A9693;">${p.contact_phone}</div>`:''}
             </div>`).addTo(shelterLayer);
        });
    });
}

// ─── FEED ───
function fetchFeed() {
    document.getElementById('statusText').textContent = 'Refreshing…';
    fetch('map.php?action=feed', { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            allFeatures = data.features || [];
            renderSidebar();
            renderMarkers();
            document.getElementById('lastUpdated').textContent = 'Updated: ' + new Date().toLocaleTimeString();
            document.getElementById('statusText').textContent = `${data.total} active incident${data.total !== 1 ? 's' : ''}`;
        })
        .catch(() => { document.getElementById('statusText').textContent = '⚠ Connection error — retrying…'; });
}
function forceRefresh() { clearTimeout(pollTimer); fetchFeed(); schedulePoll(); }
function schedulePoll() { pollTimer = setTimeout(() => { fetchFeed(); schedulePoll(); }, 30000); }

function filteredFeatures() {
    const sev  = document.getElementById('filterSeverity').value;
    const type = document.getElementById('filterType').value;
    return allFeatures.filter(f => (!sev || f.properties.severity == sev) && (!type || f.properties.type === type));
}
function flyTo(lat, lng) { map.flyTo([lat, lng], 15, { duration: 1 }); }
function highlightItem(id) { document.querySelectorAll('.inc-item').forEach(el => el.classList.toggle('active', parseInt(el.dataset.id) === id)); }
function cap(str) { return str.charAt(0).toUpperCase() + str.slice(1); }

// ─── LAYER BUTTONS ───
document.getElementById('toggleIncidents').addEventListener('change', e => {
    document.getElementById('btnIncidents').classList.toggle('on', e.target.checked);
    e.target.checked ? incidentLayer.addTo(map) : incidentLayer.remove();
});
document.getElementById('toggleZones').addEventListener('change', e => {
    document.getElementById('btnZones').classList.toggle('on', e.target.checked);
    if (e.target.checked) { loadZones(); zoneLayer.addTo(map); } else zoneLayer.remove();
});
document.getElementById('toggleShelters').addEventListener('change', e => {
    document.getElementById('btnShelters').classList.toggle('on', e.target.checked);
    if (e.target.checked) { loadShelters(); shelterLayer.addTo(map); } else shelterLayer.remove();
});

document.getElementById('filterSeverity').addEventListener('change', () => { renderSidebar(); renderMarkers(); });
document.getElementById('filterType').addEventListener('change',     () => { renderSidebar(); renderMarkers(); });

fetchFeed();
schedulePoll();
</script>
</body>
</html>