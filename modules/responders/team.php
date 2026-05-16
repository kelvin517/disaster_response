<?php
/**
 * Responder Team Management
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Displays team members, allows location sharing, and coordinate team efforts
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only responders and admins can access
if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

if (!hasRole(['responder', 'admin'])) {
    redirect('index.php');
}

$user_id = $_SESSION['user_id'];

// Handle location update (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'update_location') {
        $latitude = (float)$_POST['latitude'];
        $longitude = (float)$_POST['longitude'];
        $accuracy = (int)($_POST['accuracy'] ?? 0);
        
        // Update or insert location
        $stmt = $pdo->prepare("
            INSERT INTO responder_locations (responder_id, latitude, longitude, accuracy, last_update)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            latitude = ?, longitude = ?, accuracy = ?, last_update = NOW()
        ");
        $stmt->execute([$user_id, $latitude, $longitude, $accuracy, $latitude, $longitude, $accuracy]);
        
        echo json_encode(['success' => true, 'message' => 'Location updated']);
        exit;
    }
    
    if ($_POST['action'] === 'get_team_locations') {
        $stmt = $pdo->prepare("
            SELECT 
                u.id, u.full_name, u.phone,
                rl.latitude, rl.longitude, rl.accuracy, rl.last_update,
                TIMESTAMPDIFF(MINUTE, rl.last_update, NOW()) as minutes_ago
            FROM users u
            LEFT JOIN responder_locations rl ON u.id = rl.responder_id
            WHERE u.role = 'responder' AND u.id != ?
            ORDER BY u.full_name
        ");
        $stmt->execute([$user_id]);
        $team = $stmt->fetchAll();
        
        echo json_encode($team);
        exit;
    }
}

// Fetch team members
$stmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.phone, u.email,
           rl.latitude, rl.longitude, rl.accuracy, rl.last_update,
           TIMESTAMPDIFF(MINUTE, rl.last_update, NOW()) as minutes_ago
    FROM users u
    LEFT JOIN responder_locations rl ON u.id = rl.responder_id
    WHERE u.role = 'responder' AND u.id != ?
    ORDER BY u.full_name
");
$stmt->execute([$user_id]);
$team_members = $stmt->fetchAll();

// Get current user's last known location
$stmt = $pdo->prepare("
    SELECT latitude, longitude, last_update, 
           TIMESTAMPDIFF(MINUTE, last_update, NOW()) as minutes_ago
    FROM responder_locations
    WHERE responder_id = ?
");
$stmt->execute([$user_id]);
$my_location = $stmt->fetch();

$page_title = 'Team Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Management - DisasterResponse</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    
    <style>
        :root {
            --bg: #0f172a;
            --surface: #1e293b;
            --surface2: #334155;
            --border: rgba(255,255,255,0.1);
            --red: #ef4444;
            --green: #22c55e;
            --blue: #3b82f6;
            --amber: #f59e0b;
            --text: #f1f5f9;
            --muted: #94a3b8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); }

        .navbar-modern {
            background: rgba(15,23,42,0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 0;
        }
        .navbar-brand {
            font-weight: 800;
            font-size: 1.25rem;
            color: var(--text) !important;
            text-decoration: none;
        }
        .navbar-brand .brand-accent { color: var(--red); }

        .nav-pill {
            padding: 0.35rem 0.85rem;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--muted);
            font-size: 0.8rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.18s ease;
        }
        .nav-pill:hover { border-color: var(--red); color: var(--red); background: rgba(239,68,68,0.15); }

        .page-header {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border-bottom: 1px solid var(--border);
            padding: 1.5rem 0;
            margin-bottom: 1.5rem;
        }

        #map { height: 400px; border-radius: 16px; border: 1px solid var(--border); }
        
        .team-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 1rem;
            transition: all 0.2s;
            cursor: pointer;
        }
        .team-card:hover { transform: translateX(5px); border-color: var(--red); background: var(--surface2); }
        
        .location-status {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .status-active { background: var(--green); box-shadow: 0 0 8px var(--green); }
        .status-stale { background: var(--amber); box-shadow: 0 0 8px var(--amber); }
        .status-offline { background: var(--muted); }
        
        .my-location-card {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }
        .pulse { animation: pulse 2s infinite; }
        
        @media (max-width: 768px) {
            #map { height: 300px; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="dashboard.php">
            <i class="bi bi-shield-fill-check me-1 brand-accent"></i>Disaster<span class="brand-accent">Response</span>
        </a>
        <div class="d-flex gap-2">
            <a href="dashboard.php" class="nav-pill">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a href="../mapping/map.php" class="nav-pill">
                <i class="bi bi-map me-1"></i>Live Map
            </a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-people-fill me-2" style="color: var(--red);"></i>
            Team Management
        </h1>
        <p class="text-muted mt-1">Track team locations and coordinate response efforts</p>
    </div>
</div>

<div class="container pb-5">
    
    <!-- My Location Status -->
    <div class="my-location-card">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h6 class="mb-1"><i class="bi bi-geo-alt-fill me-1" style="color: var(--red);"></i>My Location</h6>
                <div id="locationStatus" class="small text-muted">
                    <span class="location-status status-active pulse"></span>
                    <span id="locationText">Sharing live location...</span>
                </div>
            </div>
            <button id="shareLocationBtn" class="btn btn-sm btn-danger rounded-pill">
                <i class="bi bi-send me-1"></i>Share Now
            </button>
        </div>
        <div id="coordsDisplay" class="small text-muted mt-2"></div>
    </div>
    
    <div class="row">
        <!-- Map Column -->
        <div class="col-lg-7 mb-4">
            <div class="bg-dark rounded-3 p-3">
                <div id="map"></div>
                <div class="mt-2 text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    Team members' locations update automatically. Green = Active, Yellow = Stale (5+ min), Gray = Offline.
                </div>
            </div>
        </div>
        
        <!-- Team List Column -->
        <div class="col-lg-5">
            <h5 class="mb-3"><i class="bi bi-people me-2"></i>Team Members</h5>
            <div id="teamList">
                <?php if (count($team_members) > 0): ?>
                    <?php foreach ($team_members as $member): 
                        $is_active = ($member['minutes_ago'] !== null && $member['minutes_ago'] <= 5);
                        $is_stale = ($member['minutes_ago'] !== null && $member['minutes_ago'] > 5 && $member['minutes_ago'] <= 30);
                        $status_class = $is_active ? 'active' : ($is_stale ? 'stale' : 'offline');
                        $status_text = $is_active ? 'Active now' : ($is_stale ? $member['minutes_ago'] . ' min ago' : 'Offline');
                    ?>
                        <div class="team-card" onclick="zoomToMember(<?= $member['latitude'] ?? 'null' ?>, <?= $member['longitude'] ?? 'null' ?>, '<?= htmlspecialchars($member['full_name']) ?>')">
                            <div class="p-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold">
                                            <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($member['full_name']) ?>
                                        </div>
                                        <div class="small text-muted">
                                            <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($member['phone'] ?? 'No phone') ?>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="location-status status-<?= $status_class ?>"></span>
                                        <span class="small"><?= $status_text ?></span>
                                    </div>
                                </div>
                                <?php if ($member['latitude']): ?>
                                    <div class="mt-2 small text-muted">
                                        <i class="bi bi-geo-alt me-1"></i>
                                        <?= number_format($member['latitude'], 6) ?>, <?= number_format($member['longitude'], 6) ?>
                                    </div>
                                <?php else: ?>
                                    <div class="mt-2 small text-muted">
                                        <i class="bi bi-eye-slash me-1"></i>Location not shared
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-people fs-1 d-block mb-2"></i>
                        <p>No other team members found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// Initialize map
const map = L.map('map').setView([-1.2921, 36.8219], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Marker layer
let markerLayer = L.layerGroup().addTo(map);
let myMarker = null;

// Custom icons
const iconActive = L.divIcon({
    html: '<div style="background: #22c55e; width: 12px; height: 12px; border-radius: 50%; border: 2px solid white; box-shadow: 0 0 8px #22c55e;"></div>',
    className: '',
    iconSize: [12, 12],
    iconAnchor: [6, 6]
});

const iconStale = L.divIcon({
    html: '<div style="background: #f59e0b; width: 12px; height: 12px; border-radius: 50%; border: 2px solid white; box-shadow: 0 0 8px #f59e0b;"></div>',
    className: '',
    iconSize: [12, 12],
    iconAnchor: [6, 6]
});

const iconOffline = L.divIcon({
    html: '<div style="background: #94a3b8; width: 12px; height: 12px; border-radius: 50%; border: 2px solid white;"></div>',
    className: '',
    iconSize: [12, 12],
    iconAnchor: [6, 6]
});

const myIcon = L.divIcon({
    html: '<div style="background: #ef4444; width: 16px; height: 16px; border-radius: 50%; border: 3px solid white; box-shadow: 0 0 8px #ef4444;"></div>',
    className: '',
    iconSize: [16, 16],
    iconAnchor: [8, 8]
});

// Fetch team locations
function fetchTeamLocations() {
    fetch('team.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_team_locations'
    })
    .then(r => r.json())
    .then(team => {
        markerLayer.clearLayers();
        team.forEach(member => {
            if (member.latitude && member.longitude) {
                const minutesAgo = member.minutes_ago;
                let icon = iconOffline;
                if (minutesAgo <= 5) icon = iconActive;
                else if (minutesAgo <= 30) icon = iconStale;
                
                const marker = L.marker([member.latitude, member.longitude], { icon: icon })
                    .bindPopup(`
                        <strong>${member.full_name}</strong><br>
                        <small>📍 ${member.latitude.toFixed(6)}, ${member.longitude.toFixed(6)}</small><br>
                        <small>📱 ${member.phone || 'No phone'}</small><br>
                        <small>🕐 ${minutesAgo <= 5 ? 'Active now' : minutesAgo + ' minutes ago'}</small>
                    `);
                markerLayer.addLayer(marker);
            }
        });
    });
}

// Share location
function shareLocation() {
    if (!navigator.geolocation) {
        alert('Geolocation not supported');
        return;
    }
    
    navigator.geolocation.getCurrentPosition(position => {
        const lat = position.coords.latitude;
        const lng = position.coords.longitude;
        const acc = Math.round(position.coords.accuracy);
        
        fetch('team.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=update_location&latitude=${lat}&longitude=${lng}&accuracy=${acc}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const statusDiv = document.getElementById('locationStatus');
                statusDiv.innerHTML = '<span class="location-status status-active pulse"></span><span>Location shared successfully!</span>';
                document.getElementById('coordsDisplay').innerHTML = `📍 ${lat.toFixed(6)}, ${lng.toFixed(6)} (accuracy: ±${acc}m)`;
                setTimeout(() => {
                    statusDiv.innerHTML = '<span class="location-status status-active pulse"></span><span>Sharing live location...</span>';
                }, 3000);
                
                // Update my marker on map
                if (myMarker) map.removeLayer(myMarker);
                myMarker = L.marker([lat, lng], { icon: myIcon }).addTo(map);
                map.setView([lat, lng], 14);
                
                fetchTeamLocations();
            }
        });
    }, error => {
        alert('Could not get location: ' + error.message);
    });
}

function zoomToMember(lat, lng, name) {
    if (lat && lng) {
        map.setView([lat, lng], 15);
        L.popup().setLatLng([lat, lng]).setContent(`<strong>${name}</strong>`).openOn(map);
    } else {
        alert(`${name} hasn't shared their location yet.`);
    }
}

// Initial load and auto-refresh
document.getElementById('shareLocationBtn').addEventListener('click', shareLocation);
fetchTeamLocations();
setInterval(fetchTeamLocations, 30000);
</script>

</body>
</html>