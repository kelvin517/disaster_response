<?php
/**
 * Live Incident Map Module
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 *
 * Renders an interactive Leaflet.js map for responders/admins showing
 * all open incidents colour-coded by severity with click-through details.
 *
 * Endpoints served by this file:
 *   GET  map.php                  → full page (HTML)
 *   GET  map.php?action=feed      → JSON incident feed (AJAX polling)
 *   GET  map.php?action=detail&id → JSON single-incident detail (AJAX)
 *   GET  map.php?action=zones     → JSON danger zones (AJAX)
 *   GET  map.php?action=shelters  → JSON safe shelters (AJAX)
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only responders, admins, and volunteers can view the command map
role_guard(['responder', 'admin', 'volunteer']);

/* ═══════════════════════════════════════════════════════════════
   AJAX — JSON feed endpoints
═══════════════════════════════════════════════════════════════ */
$action = $_GET['action'] ?? '';

if ($action === 'feed') {
    /**
     * Returns all non-resolved incidents as GeoJSON FeatureCollection.
     * Clients poll this every 30 s for real-time updates.
     */
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    try {
        $stmt = $pdo->prepare("
            SELECT
                i.id,
                i.incident_type,
                i.severity,
                i.description,
                i.latitude,
                i.longitude,
                i.status,
                i.photo_path,
                i.reported_at,
                COALESCE(u.full_name, 'Anonymous') AS reporter_name
            FROM   incidents i
            LEFT   JOIN users u ON u.id = i.reporter_id
            WHERE  i.status NOT IN ('resolved', 'cancelled', 'rejected')
            ORDER  BY i.severity DESC, i.reported_at DESC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $features = [];
        foreach ($rows as $r) {
            $features[] = [
                'type'       => 'Feature',
                'geometry'   => [
                    'type'        => 'Point',
                    'coordinates' => [(float)$r['longitude'], (float)$r['latitude']],
                ],
                'properties' => [
                    'id'            => (int)$r['id'],
                    'type'          => $r['incident_type'],
                    'severity'      => (int)$r['severity'],
                    'status'        => $r['status'],
                    'description'   => $r['description'],
                    'reporter'      => $r['reporter_name'],
                    'photo'         => $r['photo_path'],
                    'reported_at'   => $r['reported_at'],
                ],
            ];
        }

        echo json_encode([
            'type'       => 'FeatureCollection',
            'features'   => $features,
            'generated'  => date('c'),
            'total'      => count($features),
        ]);

    } catch (PDOException $e) {
        error_log('Map feed error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
    exit;
}

if ($action === 'detail') {
    /**
     * Returns full detail for a single incident (used by the sidebar panel).
     */
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);

    try {
        $stmt = $pdo->prepare("
            SELECT
                i.*,
                COALESCE(u.full_name, 'Anonymous')    AS reporter_name,
                COALESCE(u.phone, 'N/A')          AS reporter_phone,
                COALESCE(r.full_name, 'Unassigned')   AS responder_name
            FROM   incidents i
            LEFT   JOIN users u ON u.id = i.reporter_id
            LEFT   JOIN users r ON r.id = i.assigned_to
            WHERE  i.id = :id
            LIMIT  1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

        echo json_encode($row);

    } catch (PDOException $e) {
        error_log('Incident detail error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
    exit;
}

if ($action === 'zones') {
    /**
     * Returns all active danger zones as GeoJSON FeatureCollection.
     */
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    try {
        $stmt = $pdo->prepare("
            SELECT id, name, description, hazard_level, geometry, status
            FROM danger_zones
            WHERE status = 'active'
        ");
        $stmt->execute();
        $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $features = [];
        $hazard_colors = [
            'critical' => '#dc3545',
            'high' => '#fd7e14',
            'medium' => '#ffc107',
            'low' => '#28a745'
        ];

        foreach ($zones as $zone) {
            $geometry = json_decode($zone['geometry'], true);
            if ($geometry) {
                $features[] = [
                    'type'       => 'Feature',
                    'geometry'   => $geometry,
                    'properties' => [
                        'id' => $zone['id'],
                        'name' => $zone['name'],
                        'description' => $zone['description'],
                        'hazard_level' => $zone['hazard_level'],
                        'color' => $hazard_colors[$zone['hazard_level']] ?? '#dc3545',
                        'status' => $zone['status']
                    ]
                ];
            }
        }

        echo json_encode([
            'type' => 'FeatureCollection',
            'features' => $features,
            'total' => count($features)
        ]);

    } catch (PDOException $e) {
        error_log('Danger zones error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
    exit;
}

if ($action === 'shelters') {
    /**
     * Returns all active safe shelters as GeoJSON FeatureCollection.
     */
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    try {
        $stmt = $pdo->prepare("
            SELECT id, name, type, capacity, current_occupancy, 
                   latitude, longitude, address, contact_phone, resources, status
            FROM shelters
            WHERE status = 'active'
        ");
        $stmt->execute();
        $shelters = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $features = [];
        $shelter_icons = [
            'school' => '🏫',
            'church' => '⛪',
            'community' => '🏛️',
            'government' => '🏢',
            'stadium' => '🏟️',
            'tent' => '⛺',
            'other' => '🏠'
        ];

        foreach ($shelters as $shelter) {
            $features[] = [
                'type'       => 'Feature',
                'geometry'   => [
                    'type' => 'Point',
                    'coordinates' => [(float)$shelter['longitude'], (float)$shelter['latitude']]
                ],
                'properties' => [
                    'id' => $shelter['id'],
                    'name' => $shelter['name'],
                    'type' => $shelter['type'],
                    'type_icon' => $shelter_icons[$shelter['type']] ?? '🏠',
                    'capacity' => (int)$shelter['capacity'],
                    'current_occupancy' => (int)$shelter['current_occupancy'],
                    'address' => $shelter['address'],
                    'contact_phone' => $shelter['contact_phone'],
                    'resources' => $shelter['resources'],
                    'status' => $shelter['status']
                ]
            ];
        }

        echo json_encode([
            'type' => 'FeatureCollection',
            'features' => $features,
            'total' => count($features)
        ]);

    } catch (PDOException $e) {
        error_log('Shelters error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   Full-page render
═══════════════════════════════════════════════════════════════ */

// Severity metadata (mirrors report.php — keep in sync)
$severity_meta = [
    1 => ['label' => 'Low',      'color' => '#28a745', 'border' => '#1e7e34'],
    2 => ['label' => 'Medium',   'color' => '#ffc107', 'border' => '#e0a800'],
    3 => ['label' => 'High',     'color' => '#fd7e14', 'border' => '#e96b02'],
    4 => ['label' => 'Critical', 'color' => '#dc3545', 'border' => '#bd2130'],
];

$incident_type_icons = [
    'flood'            => '🌊',
    'fire'             => '🔥',
    'earthquake'       => '🏚️',
    'landslide'        => '⛰️',
    'drought'          => '☀️',
    'accident'         => '🚗',
    'building_collapse'=> '🏗️',
    'disease_outbreak' => '🦠',
    'other'            => '⚠️',
];

// Summary counts for the legend panel
try {
    $counts = $pdo->query("
        SELECT severity, COUNT(*) AS cnt
        FROM   incidents
        WHERE  status NOT IN ('resolved', 'cancelled', 'rejected')
        GROUP  BY severity
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $counts = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Incident Map - DisasterResponse</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; overflow: hidden; background: #0f172a; }

        /* Navbar */
        .navbar-modern {
            background: rgba(15,23,42,0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 0.7rem 0;
            z-index: 1000;
            position: relative;
        }
        .navbar-brand {
            font-weight: 800;
            font-size: 1.25rem;
            color: white !important;
            text-decoration: none;
        }
        .navbar-brand .brand-accent { color: #ef4444; }

        #mapWrapper {
            display: flex;
            height: calc(100vh - 60px);
            position: relative;
        }

        /* Sidebar */
        #sidebar {
            width: 360px;
            min-width: 280px;
            background: #1e293b;
            border-right: 1px solid rgba(255,255,255,0.1);
            display: flex;
            flex-direction: column;
            z-index: 800;
            transition: transform 0.25s ease;
        }
        #sidebar.collapsed { transform: translateX(-100%); position: absolute; top: 0; bottom: 0; left: 0; }
        #sidebarToggle {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 900;
            display: none;
        }
        @media (max-width: 768px) {
            #sidebar { position: absolute; top: 0; bottom: 0; left: 0; z-index: 900; }
            #sidebarToggle { display: flex; }
            #sidebar:not(.collapsed) ~ #sidebarToggle { left: 370px; }
        }

        /* Map */
        #map { flex: 1; }

        /* Sidebar sections */
        .sidebar-header {
            background: #0f172a;
            color: white;
            padding: 1rem;
            font-weight: 600;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        #incidentList { overflow-y: auto; flex: 1; }
        .inc-item {
            border-left: 4px solid transparent;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            cursor: pointer;
            transition: background 0.15s;
            color: #e2e8f0;
        }
        .inc-item:hover { background: #334155; }
        .inc-item.active { background: #2d3748; border-left-color: #ef4444; }

        /* Legend */
        #legend {
            position: absolute;
            bottom: 20px;
            right: 12px;
            background: rgba(30,41,59,0.95);
            border-radius: 12px;
            padding: 12px 16px;
            z-index: 800;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            font-size: 0.8rem;
            min-width: 160px;
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.1);
            color: #e2e8f0;
        }
        .legend-dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }

        /* Detail Panel */
        #detailPanel {
            position: absolute;
            top: 10px;
            right: 12px;
            width: 340px;
            background: #1e293b;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
            z-index: 800;
            display: none;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.1);
            color: #e2e8f0;
        }
        #detailPanel .panel-close {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(0,0,0,0.5);
            border: none;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            cursor: pointer;
            z-index: 10;
        }

        /* Status Bar */
        #statusBar {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(15,23,42,0.9);
            color: #94a3b8;
            font-size: 0.7rem;
            padding: 6px 16px;
            z-index: 700;
            display: flex;
            gap: 1rem;
            align-items: center;
            backdrop-filter: blur(8px);
            border-top: 1px solid rgba(255,255,255,0.05);
        }

        /* Layer Toggle */
        .layer-toggle {
            position: absolute;
            top: 10px;
            left: 50px;
            background: #1e293b;
            border-radius: 8px;
            padding: 8px 12px;
            z-index: 800;
            display: flex;
            gap: 12px;
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .layer-toggle label {
            color: #e2e8f0;
            font-size: 0.75rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(220,53,69,0.7); }
            70% { box-shadow: 0 0 0 12px rgba(220,53,69,0); }
            100% { box-shadow: 0 0 0 0 rgba(220,53,69,0); }
        }
        .marker-critical { animation: pulse 1.4s infinite; }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="../responders/responders_dashboard.php">
            <i class="bi bi-map-fill me-1 brand-accent"></i>Disaster<span class="brand-accent">Response</span>
            <span class="badge bg-danger ms-2" style="font-size: 0.6rem;">LIVE MAP</span>
        </a>
        <div class="d-flex gap-2">
            <a href="../incidents/all.php" class="btn btn-outline-light btn-sm rounded-pill">
                <i class="bi bi-list-ul me-1"></i>All Incidents
            </a>
            <a href="../responders/responders_dashboard.php" class="btn btn-outline-danger btn-sm rounded-pill">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
        </div>
    </div>
</nav>

<div id="mapWrapper">

    <!-- Sidebar -->
    <div id="sidebar">
        <div class="sidebar-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-exclamation-triangle-fill me-2" style="color: #ef4444;"></i>Active Incidents</span>
            <span id="incidentCount" class="badge bg-danger">—</span>
        </div>

        <!-- Filters -->
        <div class="p-2 border-bottom border-secondary d-flex gap-1 flex-wrap">
            <select id="filterSeverity" class="form-select form-select-sm bg-dark text-white border-secondary" style="width:auto;flex:1">
                <option value="">All Severities</option>
                <option value="4">🔴 Critical</option>
                <option value="3">🟠 High</option>
                <option value="2">🟡 Medium</option>
                <option value="1">🟢 Low</option>
            </select>
            <select id="filterType" class="form-select form-select-sm bg-dark text-white border-secondary" style="width:auto;flex:1">
                <option value="">All Types</option>
                <?php foreach ($incident_type_icons as $k => $icon): ?>
                    <option value="<?= $k ?>"><?= $icon ?> <?= ucfirst(str_replace('_',' ',$k)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Incident List -->
        <div id="incidentList">
            <div class="text-center text-muted p-4" id="listPlaceholder">
                <div class="spinner-border spinner-border-sm text-danger"></div>
                <div class="mt-2 small">Loading incidents…</div>
            </div>
        </div>
    </div>

    <!-- Map -->
    <div id="map"></div>

    <!-- Layer Toggle -->
    <div class="layer-toggle">
        <label>
            <input type="checkbox" id="toggleIncidents" checked> 🔴 Incidents
        </label>
        <label>
            <input type="checkbox" id="toggleZones"> ⚠️ Danger Zones
        </label>
        <label>
            <input type="checkbox" id="toggleShelters"> 🏠 Shelters
        </label>
    </div>

    <!-- Sidebar Toggle (Mobile) -->
    <button id="sidebarToggle" class="btn btn-sm btn-dark shadow">
        <i class="bi bi-layout-sidebar"></i>
    </button>

    <!-- Legend -->
    <div id="legend">
        <div class="fw-semibold mb-2">Severity</div>
        <?php foreach (array_reverse($severity_meta, true) as $sev => $m): ?>
        <div class="d-flex align-items-center mb-1">
            <span class="legend-dot" style="background:<?= $m['color'] ?>"></span>
            <span><?= $m['label'] ?></span>
            <span class="ms-auto text-muted"><?= $counts[$sev] ?? 0 ?></span>
        </div>
        <?php endforeach; ?>
        <hr class="my-2 bg-secondary">
        <div class="d-flex align-items-center">
            <span class="legend-dot" style="background:#22c55e"></span>
            <span>Safe Shelter</span>
        </div>
        <div class="d-flex align-items-center mt-1">
            <span class="legend-dot" style="background:#6c757d"></span>
            <span>Danger Zone</span>
        </div>
    </div>

    <!-- Detail Panel -->
    <div id="detailPanel">
        <button class="panel-close" onclick="closeDetail()">✕</button>
        <div id="detailContent" class="p-3"></div>
    </div>

    <!-- Status Bar -->
    <div id="statusBar">
        <span id="statusText">Initialising map…</span>
        <span id="lastUpdated" class="ms-auto"></span>
        <button onclick="forceRefresh()" class="btn btn-sm btn-outline-light py-0 px-2" style="font-size:0.7rem">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>

</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// Configuration
const SEVERITY_META = <?= json_encode($severity_meta) ?>;
const TYPE_ICONS = <?= json_encode($incident_type_icons) ?>;

// Initialize map
const map = L.map('map', { zoomControl: false }).setView([-1.2921, 36.8219], 7);
L.control.zoom({ position: 'bottomleft' }).addTo(map);

// Base tile layers
const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19,
});
const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    attribution: 'Tiles © Esri',
    maxZoom: 19,
});
osm.addTo(map);
L.control.layers({ 'Street': osm, 'Satellite': satellite }, {}, { position: 'topleft' }).addTo(map);

// Layer groups
let incidentLayer = L.layerGroup().addTo(map);
let dangerZoneLayer = L.layerGroup().addTo(map);
let shelterLayer = L.layerGroup().addTo(map);

// State
let allFeatures = [];
let activeId = null;
let pollTimer = null;

// Custom icons
function makeIcon(severity, type, status) {
    const m = SEVERITY_META[severity] || SEVERITY_META[1];
    const emoji = TYPE_ICONS[type] || '⚠️';
    const fill = (status === 'assigned') ? '#6c757d' : m.color;
    const size = (severity === 4) ? 40 : 32;
    
    const svg = `
        <svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}">
            <circle cx="${size/2}" cy="${size/2}" r="${size/2 - 2}"
                    fill="${fill}" stroke="${m.border || '#555'}" stroke-width="2"
                    opacity="0.92"/>
            <text x="50%" y="55%" dominant-baseline="middle" text-anchor="middle"
                  font-size="${size * 0.45}px">${emoji}</text>
        </svg>`;
    
    return L.divIcon({
        html: `<div class="${severity === 4 && status !== 'assigned' ? 'marker-critical' : ''}">${svg}</div>`,
        className: '',
        iconSize: [size, size],
        iconAnchor: [size/2, size/2],
        popupAnchor: [0, -size/2],
    });
}

// Load danger zones
function loadDangerZones() {
    fetch('map.php?action=zones')
        .then(r => r.json())
        .then(data => {
            dangerZoneLayer.clearLayers();
            data.features.forEach(f => {
                const zone = L.geoJSON(f, {
                    style: {
                        color: f.properties.color,
                        weight: 3,
                        fillOpacity: 0.3,
                        opacity: 0.8
                    },
                    popupContent: `<strong>⚠️ ${f.properties.name}</strong><br>${f.properties.description || ''}<br><span class="badge bg-danger">${f.properties.hazard_level}</span>`
                }).addTo(dangerZoneLayer);
            });
        })
        .catch(err => console.error('Error loading zones:', err));
}

// Load shelters
function loadShelters() {
    fetch('map.php?action=shelters')
        .then(r => r.json())
        .then(data => {
            shelterLayer.clearLayers();
            data.features.forEach(f => {
                const p = f.properties;
                const percent = (p.current_occupancy / p.capacity) * 100;
                const statusColor = percent >= 90 ? '#ef4444' : (percent >= 70 ? '#f59e0b' : '#22c55e');
                
                const marker = L.marker([f.geometry.coordinates[1], f.geometry.coordinates[0]], {
                    icon: L.divIcon({
                        html: `<div style="background: ${statusColor}; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                                    <span style="font-size: 14px;">${p.type_icon}</span>
                                </div>`,
                        className: '',
                        iconSize: [28, 28],
                        iconAnchor: [14, 14]
                    })
                }).bindPopup(`
                    <div style="min-width: 200px;">
                        <strong>🏠 ${p.name}</strong><br>
                        <small>${p.type}</small><br>
                        <div class="mt-2">Capacity: ${p.current_occupancy}/${p.capacity}</div>
                        <div class="progress mt-1" style="height: 6px;">
                            <div class="progress-bar bg-${percent >= 90 ? 'danger' : (percent >= 70 ? 'warning' : 'success')}" style="width: ${percent}%"></div>
                        </div>
                        ${p.address ? `<div class="mt-2"><i class="bi bi-geo-alt"></i> ${p.address}</div>` : ''}
                        ${p.contact_phone ? `<div><i class="bi bi-telephone"></i> ${p.contact_phone}</div>` : ''}
                        ${p.resources ? `<div class="mt-2 small"><strong>Resources:</strong> ${p.resources}</div>` : ''}
                    </div>
                `);
                marker.addTo(shelterLayer);
            });
        })
        .catch(err => console.error('Error loading shelters:', err));
}

// Load incidents feed
function fetchFeed() {
    document.getElementById('statusText').textContent = 'Refreshing…';
    
    fetch('map.php?action=feed', { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            allFeatures = data.features || [];
            renderSidebar();
            renderMarkers();
            const now = new Date().toLocaleTimeString();
            document.getElementById('lastUpdated').textContent = `Updated: ${now}`;
            document.getElementById('statusText').textContent = `${data.total} active incident${data.total !== 1 ? 's' : ''}`;
        })
        .catch(err => {
            console.error('Feed error:', err);
            document.getElementById('statusText').textContent = '⚠️ Connection error — retrying…';
        });
}

function forceRefresh() { clearTimeout(pollTimer); fetchFeed(); schedulePoll(); }
function schedulePoll() { pollTimer = setTimeout(() => { fetchFeed(); schedulePoll(); }, 30000); }

// Filter functions
function filteredFeatures() {
    const sev = document.getElementById('filterSeverity').value;
    const type = document.getElementById('filterType').value;
    return allFeatures.filter(f =>
        (!sev || f.properties.severity == sev) &&
        (!type || f.properties.type === type)
    );
}

// Render sidebar
function renderSidebar() {
    const list = document.getElementById('incidentList');
    const data = filteredFeatures();
    
    document.getElementById('incidentCount').textContent = data.length;
    
    if (data.length === 0) {
        list.innerHTML = '<div class="text-center text-muted p-4 small">No incidents match the filter.</div>';
        return;
    }
    
    list.innerHTML = data.map(f => {
        const p = f.properties;
        const m = SEVERITY_META[p.severity] || SEVERITY_META[1];
        const ico = TYPE_ICONS[p.type] || '⚠️';
        const ts = new Date(p.reported_at).toLocaleString('en-KE', { dateStyle: 'short', timeStyle: 'short' });
        return `
            <div class="inc-item ${p.id === activeId ? 'active' : ''}"
                 style="border-left-color: ${m.color}"
                 data-id="${p.id}"
                 data-lat="${f.geometry.coordinates[1]}"
                 data-lng="${f.geometry.coordinates[0]}">
                <div class="d-flex justify-content-between align-items-start">
                    <span class="fw-semibold">${ico} #${p.id} — ${capitalize(p.type.replace('_', ' '))}</span>
                    <span class="badge" style="background: ${m.color}">${m.label}</span>
                </div>
                <div class="text-muted small mt-1 text-truncate">${p.description || 'No description'}</div>
                <div class="text-muted small mt-1">${ts} • ${statusBadge(p.status)}</div>
            </div>`;
    }).join('');
    
    // Add click handlers
    list.querySelectorAll('.inc-item').forEach(el => {
        el.addEventListener('click', () => {
            const id = parseInt(el.dataset.id);
            const lat = parseFloat(el.dataset.lat);
            const lng = parseFloat(el.dataset.lng);
            flyToIncident(id, lat, lng);
            loadDetail(id);
        });
    });
}

// Render markers
function renderMarkers() {
    incidentLayer.clearLayers();
    
    filteredFeatures().forEach(f => {
        const p = f.properties;
        const [lng, lat] = f.geometry.coordinates;
        const marker = L.marker([lat, lng], { icon: makeIcon(p.severity, p.type, p.status) });
        
        const m = SEVERITY_META[p.severity] || SEVERITY_META[1];
        const ts = new Date(p.reported_at).toLocaleString('en-KE', { dateStyle: 'short', timeStyle: 'short' });
        marker.bindPopup(`
            <div style="min-width: 200px;">
                <strong>#${p.id} — ${capitalize(p.type.replace('_', ' '))}</strong><br>
                <span class="badge" style="background: ${m.color}; color: white;">${m.label}</span>
                <span class="badge bg-secondary ms-1">${p.status}</span><br>
                <small class="text-muted">${ts}</small>
                <hr>
                <div style="font-size: 0.85rem;">${(p.description || '').substring(0, 120)}${(p.description || '').length > 120 ? '…' : ''}</div>
                <button onclick="loadDetail(${p.id})" class="btn btn-sm btn-danger mt-2 w-100">View Details</button>
            </div>
        `);
        
        marker.on('click', () => {
            activeId = p.id;
            highlightSidebarItem(p.id);
        });
        
        incidentLayer.addLayer(marker);
    });
}

// Load detail panel
function loadDetail(id) {
    activeId = id;
    highlightSidebarItem(id);
    
    const panel = document.getElementById('detailPanel');
    const content = document.getElementById('detailContent');
    content.innerHTML = '<div class="text-center p-3"><div class="spinner-border spinner-border-sm text-danger"></div></div>';
    panel.style.display = 'block';
    
    fetch(`map.php?action=detail&id=${id}`)
        .then(r => r.json())
        .then(d => {
            const m = SEVERITY_META[d.severity] || SEVERITY_META[1];
            const ico = TYPE_ICONS[d.incident_type] || '⚠️';
            const ts = new Date(d.reported_at).toLocaleString('en-KE', { dateStyle: 'medium', timeStyle: 'short' });
            
            content.innerHTML = `
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span style="font-size: 1.8rem">${ico}</span>
                    <div>
                        <div class="fw-bold fs-5">Incident #${d.id}</div>
                        <div class="small text-muted">${capitalize(d.incident_type.replace('_', ' '))}</div>
                    </div>
                </div>
                <div class="mb-2">
                    <span class="badge me-1" style="background: ${m.color}">${m.label} Severity</span>
                    ${statusBadge(d.status)}
                </div>
                <div class="small text-muted mb-2">${ts}</div>
                <p class="small mb-2">${d.description || '<em>No description provided.</em>'}</p>
                ${d.photo_path ? `<img src="${d.photo_path}" class="img-fluid rounded mb-2" style="max-height: 120px; object-fit: cover; width: 100%;">` : ''}
                <hr class="my-2 bg-secondary">
                <div class="small">
                    <div><strong>Reporter:</strong> ${d.reporter_name}</div>
                    <div><strong>Phone:</strong> ${d.reporter_phone}</div>
                    <div><strong>Responder:</strong> ${d.responder_name}</div>
                    <div class="mt-1"><strong>GPS:</strong> ${parseFloat(d.latitude).toFixed(5)}, ${parseFloat(d.longitude).toFixed(5)}</div>
                </div>
                <div class="d-grid gap-1 mt-3">
                    <a href="../incidents/view.php?id=${d.id}" class="btn btn-sm btn-danger">
                        <i class="bi bi-eye me-1"></i>Full Details
                    </a>
                    <button onclick="map.flyTo([${d.latitude},${d.longitude}], 16)" class="btn btn-sm btn-outline-light">
                        <i class="bi bi-crosshair me-1"></i>Centre Map
                    </button>
                </div>
            `;
        })
        .catch(() => { content.innerHTML = '<div class="text-danger p-2 small">Failed to load details.</div>'; });
}

function closeDetail() {
    document.getElementById('detailPanel').style.display = 'none';
    activeId = null;
    highlightSidebarItem(null);
}

function flyToIncident(id, lat, lng) {
    map.flyTo([lat, lng], 15, { duration: 1 });
}

function highlightSidebarItem(id) {
    document.querySelectorAll('.inc-item').forEach(el => {
        el.classList.toggle('active', parseInt(el.dataset.id) === id);
    });
}

function capitalize(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function statusBadge(status) {
    const colors = { reported: 'danger', acknowledged: 'warning', 'in-progress': 'info', resolved: 'success', cancelled: 'secondary', rejected: 'danger' };
    return `<span class="badge bg-${colors[status] || 'secondary'}">${status.replace('-', ' ')}</span>`;
}

// Layer toggles
document.getElementById('toggleIncidents').addEventListener('change', (e) => {
    if (e.target.checked) incidentLayer.addTo(map);
    else incidentLayer.remove();
});
document.getElementById('toggleZones').addEventListener('change', (e) => {
    if (e.target.checked) { loadDangerZones(); dangerZoneLayer.addTo(map); }
    else dangerZoneLayer.remove();
});
document.getElementById('toggleShelters').addEventListener('change', (e) => {
    if (e.target.checked) { loadShelters(); shelterLayer.addTo(map); }
    else shelterLayer.remove();
});

// Filter listeners
document.getElementById('filterSeverity').addEventListener('change', () => { renderSidebar(); renderMarkers(); });
document.getElementById('filterType').addEventListener('change', () => { renderSidebar(); renderMarkers(); });

// Sidebar toggle
document.getElementById('sidebarToggle').addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('collapsed');
    setTimeout(() => map.invalidateSize(), 300);
});

// Initial load
fetchFeed();
schedulePoll();
loadDangerZones();
loadShelters();
</script>
</body>
</html>