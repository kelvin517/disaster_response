<?php
/**
 * Danger Zones Management
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows administrators to draw and manage danger zones on the map
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admin can manage danger zones
role_guard(['admin']);

// Handle CRUD operations
$message = null;
$error = null;

// Create/Update danger zone
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save') {
        $name = sanitize($_POST['name']);
        $description = sanitize($_POST['description']);
        $hazard_level = sanitize($_POST['hazard_level']);
        $geometry = $_POST['geometry']; // GeoJSON polygon
        $status = sanitize($_POST['status'] ?? 'active');
        
        if (isset($_POST['zone_id']) && !empty($_POST['zone_id'])) {
            // Update existing
            $stmt = $pdo->prepare("
                UPDATE danger_zones 
                SET name = ?, description = ?, hazard_level = ?, geometry = ?, status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            if ($stmt->execute([$name, $description, $hazard_level, $geometry, $status, $_POST['zone_id']])) {
                $message = "Danger zone updated successfully.";
            } else {
                $error = "Failed to update danger zone.";
            }
        } else {
            // Create new
            $stmt = $pdo->prepare("
                INSERT INTO danger_zones (name, description, hazard_level, geometry, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            if ($stmt->execute([$name, $description, $hazard_level, $geometry, $status, $_SESSION['user_id']])) {
                $message = "Danger zone created successfully.";
            } else {
                $error = "Failed to create danger zone.";
            }
        }
    }
    
    // Delete danger zone
    if ($_POST['action'] === 'delete') {
        $zone_id = (int)$_POST['zone_id'];
        $stmt = $pdo->prepare("DELETE FROM danger_zones WHERE id = ?");
        if ($stmt->execute([$zone_id])) {
            $message = "Danger zone deleted successfully.";
        } else {
            $error = "Failed to delete danger zone.";
        }
    }
}

// Fetch existing danger zones
$stmt = $pdo->prepare("SELECT * FROM danger_zones ORDER BY hazard_level DESC, created_at DESC");
$stmt->execute();
$danger_zones = $stmt->fetchAll();

// Hazard levels configuration
$hazard_levels = [
    'critical' => ['label' => 'Critical - Immediate Danger', 'color' => '#dc3545', 'icon' => '🔴'],
    'high' => ['label' => 'High - Severe Risk', 'color' => '#fd7e14', 'icon' => '🟠'],
    'medium' => ['label' => 'Medium - Caution Advised', 'color' => '#ffc107', 'icon' => '🟡'],
    'low' => ['label' => 'Low - Monitor Situation', 'color' => '#28a745', 'icon' => '🟢']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Danger Zones Management - DisasterResponse</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #0f172a; color: #f1f5f9; }
        
        .navbar-modern {
            background: rgba(15,23,42,0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 0.75rem 0;
        }
        
        .navbar-brand {
            font-weight: 800;
            font-size: 1.25rem;
            color: white !important;
            text-decoration: none;
        }
        .navbar-brand .brand-accent { color: #ef4444; }
        
        .page-header {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 1.5rem 0;
            margin-bottom: 1.5rem;
        }
        
        #map {
            height: 500px;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .zone-card {
            background: #1e293b;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }
        
        .zone-card:hover {
            border-color: #ef4444;
            transform: translateX(5px);
        }
        
        .hazard-critical { border-left: 4px solid #dc3545; }
        .hazard-high { border-left: 4px solid #fd7e14; }
        .hazard-medium { border-left: 4px solid #ffc107; }
        .hazard-low { border-left: 4px solid #28a745; }
        
        .btn-sm { border-radius: 8px; }
        
        @media (max-width: 768px) {
            #map { height: 350px; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="../admin/admin_dashboard.php">
            <i class="bi bi-shield-lock-fill me-1 brand-accent"></i>Disaster<span class="brand-accent">Response</span>
            <span class="badge bg-danger ms-2" style="font-size: 0.6rem;">ADMIN</span>
        </a>
        <div class="d-flex gap-2">
            <a href="../mapping/map.php" class="btn btn-outline-light btn-sm rounded-pill">
                <i class="bi bi-map me-1"></i>View Map
            </a>
            <a href="../admin/admin_dashboard.php" class="btn btn-outline-danger btn-sm rounded-pill">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-exclamation-triangle-fill me-2" style="color: #ef4444;"></i>
            Danger Zones Management
        </h1>
        <p class="text-muted mt-1">Draw and manage hazard areas on the map</p>
    </div>
</div>

<div class="container pb-5">
    
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Map Column -->
        <div class="col-lg-8 mb-4">
            <div class="bg-dark rounded-3 p-3">
                <div id="map"></div>
                <div class="mt-2 text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    Use the drawing tools on the left to draw polygons. Click "Save Zone" after drawing.
                </div>
            </div>
        </div>
        
        <!-- Zones List Column -->
        <div class="col-lg-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Active Danger Zones</h5>
                <button class="btn btn-sm btn-danger" onclick="clearDrawing()">
                    <i class="bi bi-trash me-1"></i>Clear Drawing
                </button>
            </div>
            
            <div id="zonesList">
                <?php if (count($danger_zones) > 0): ?>
                    <?php foreach ($danger_zones as $zone): 
                        $hazard = $hazard_levels[$zone['hazard_level']] ?? $hazard_levels['medium'];
                    ?>
                        <div class="zone-card hazard-<?= $zone['hazard_level'] ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1"><?= htmlspecialchars($zone['name']) ?></h6>
                                    <span class="badge" style="background: <?= $hazard['color'] ?>20; color: <?= $hazard['color'] ?>;">
                                        <?= $hazard['icon'] ?> <?= $hazard['label'] ?>
                                    </span>
                                    <p class="small text-muted mt-2 mb-0"><?= htmlspecialchars($zone['description'] ?? 'No description') ?></p>
                                </div>
                                <div class="btn-group-vertical">
                                    <button class="btn btn-sm btn-outline-warning mb-1" onclick="editZone(<?= htmlspecialchars(json_encode($zone)) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Delete this danger zone?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="zone_id" value="<?= $zone['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-map fs-1 d-block mb-2"></i>
                        <p>No danger zones defined.<br>Use the drawing tools on the map to create one.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Save Zone Form Modal -->
    <div class="modal fade" id="saveZoneModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Save Danger Zone</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="saveZoneForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="zone_id" id="zone_id">
                        <input type="hidden" name="geometry" id="geometry">
                        
                        <div class="mb-3">
                            <label class="form-label">Zone Name</label>
                            <input type="text" name="name" id="zone_name" class="form-control bg-dark text-white border-secondary" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="zone_description" class="form-control bg-dark text-white border-secondary" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Hazard Level</label>
                            <select name="hazard_level" id="zone_hazard" class="form-select bg-dark text-white border-secondary">
                                <?php foreach ($hazard_levels as $key => $level): ?>
                                    <option value="<?= $key ?>" style="color: <?= $level['color'] ?>">
                                        <?= $level['icon'] ?> <?= $level['label'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="zone_status" class="form-select bg-dark text-white border-secondary">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Save Zone</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>

<script>
// Initialize map
var map = L.map('map').setView([-1.2921, 36.8219], 7);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Draw control
var drawnItems = new L.FeatureGroup();
map.addLayer(drawnItems);

var drawControl = new L.Control.Draw({
    edit: { featureGroup: drawnItems },
    draw: {
        polygon: {
            shapeOptions: { color: '#ef4444', weight: 3 },
            allowIntersection: false,
            showArea: true
        },
        rectangle: { shapeOptions: { color: '#ef4444', weight: 3 } },
        circle: false,
        marker: false,
        polyline: false,
        circlemarker: false
    }
});
map.addControl(drawControl);

var currentDrawing = null;

// Handle drawing creation
map.on('draw:created', function(e) {
    var layer = e.layer;
    drawnItems.clearLayers();
    drawnItems.addLayer(layer);
    currentDrawing = layer;
    
    // Extract geometry
    var geometry = layer.toGeoJSON().geometry;
    document.getElementById('geometry').value = JSON.stringify(geometry);
    
    // Show modal
    var modal = new bootstrap.Modal(document.getElementById('saveZoneModal'));
    modal.show();
});

// Load existing zones
<?php foreach ($danger_zones as $zone): 
    $geometry = json_decode($zone['geometry'], true);
    if ($geometry):
?>
    var zoneGeo = <?= json_encode($geometry) ?>;
    var zoneLayer = L.geoJSON(zoneGeo, {
        style: {
            color: '<?= $hazard_levels[$zone['hazard_level']]['color'] ?? '#dc3545' ?>',
            weight: 3,
            fillOpacity: 0.3
        },
        popupContent: '<strong><?= addslashes($zone['name']) ?></strong><br><?= addslashes($zone['description'] ?? '') ?><br><span class="badge bg-danger"><?= $zone['hazard_level'] ?></span>'
    }).addTo(map);
<?php endif; endforeach; ?>

function clearDrawing() {
    drawnItems.clearLayers();
    currentDrawing = null;
    document.getElementById('geometry').value = '';
}

function editZone(zone) {
    document.getElementById('zone_id').value = zone.id;
    document.getElementById('zone_name').value = zone.name;
    document.getElementById('zone_description').value = zone.description || '';
    document.getElementById('zone_hazard').value = zone.hazard_level;
    document.getElementById('zone_status').value = zone.status;
    document.getElementById('geometry').value = zone.geometry;
    
    // Show zone on map
    var geo = JSON.parse(zone.geometry);
    drawnItems.clearLayers();
    var layer = L.geoJSON(geo).addTo(drawnItems);
    map.fitBounds(layer.getBounds());
    
    var modal = new bootstrap.Modal(document.getElementById('saveZoneModal'));
    modal.show();
}
</script>

</body>
</html>