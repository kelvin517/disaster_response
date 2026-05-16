<?php
/**
 * Safe Shelters Management
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows administrators to register and manage safe shelters on the map
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admin can manage shelters
role_guard(['admin']);

// Handle CRUD operations
$message = null;
$error = null;

// Create/Update shelter
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save') {
        $name = sanitize($_POST['name']);
        $type = sanitize($_POST['type']);
        $capacity = (int)$_POST['capacity'];
        $current_occupancy = (int)$_POST['current_occupancy'];
        $latitude = (float)$_POST['latitude'];
        $longitude = (float)$_POST['longitude'];
        $address = sanitize($_POST['address']);
        $contact_phone = sanitize($_POST['contact_phone']);
        $resources = sanitize($_POST['resources']);
        $status = sanitize($_POST['status']);
        
        if (isset($_POST['shelter_id']) && !empty($_POST['shelter_id'])) {
            // Update existing
            $stmt = $pdo->prepare("
                UPDATE shelters 
                SET name = ?, type = ?, capacity = ?, current_occupancy = ?, 
                    latitude = ?, longitude = ?, address = ?, contact_phone = ?, 
                    resources = ?, status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            if ($stmt->execute([$name, $type, $capacity, $current_occupancy, $latitude, 
                               $longitude, $address, $contact_phone, $resources, $status, $_POST['shelter_id']])) {
                $message = "Shelter updated successfully.";
            } else {
                $error = "Failed to update shelter.";
            }
        } else {
            // Create new
            $stmt = $pdo->prepare("
                INSERT INTO shelters (name, type, capacity, current_occupancy, latitude, longitude, 
                                     address, contact_phone, resources, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            if ($stmt->execute([$name, $type, $capacity, $current_occupancy, $latitude, $longitude,
                               $address, $contact_phone, $resources, $status, $_SESSION['user_id']])) {
                $message = "Shelter created successfully.";
            } else {
                $error = "Failed to create shelter.";
            }
        }
    }
    
    // Delete shelter
    if ($_POST['action'] === 'delete') {
        $shelter_id = (int)$_POST['shelter_id'];
        $stmt = $pdo->prepare("DELETE FROM shelters WHERE id = ?");
        if ($stmt->execute([$shelter_id])) {
            $message = "Shelter deleted successfully.";
        } else {
            $error = "Failed to delete shelter.";
        }
    }
}

// Fetch existing shelters
$stmt = $pdo->prepare("SELECT * FROM shelters ORDER BY name ASC");
$stmt->execute();
$shelters = $stmt->fetchAll();

// Shelter types
$shelter_types = [
    'school' => '🏫 School',
    'church' => '⛪ Church/Mosque',
    'community' => '🏛️ Community Center',
    'government' => '🏢 Government Building',
    'stadium' => '🏟️ Stadium',
    'tent' => '⛺ Tent Camp',
    'other' => '🏠 Other'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Safe Shelters Management - DisasterResponse</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    
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
        .navbar-brand .brand-accent { color: #22c55e; }
        
        .page-header {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 1.5rem 0;
            margin-bottom: 1.5rem;
        }
        
        #map {
            height: 400px;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .shelter-card {
            background: #1e293b;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
            border-left: 4px solid #22c55e;
        }
        
        .shelter-card:hover {
            transform: translateX(5px);
            border-color: #22c55e;
        }
        
        .capacity-bar {
            height: 6px;
            background: #334155;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .capacity-fill {
            height: 100%;
            background: #22c55e;
            border-radius: 3px;
            transition: width 0.3s;
        }
        
        .capacity-fill.warning { background: #f59e0b; }
        .capacity-fill.danger { background: #ef4444; }
        
        @media (max-width: 768px) {
            #map { height: 300px; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="../admin/admin_dashboard.php">
            <i class="bi bi-shield-lock-fill me-1 brand-accent"></i>Disaster<span class="brand-accent">Response</span>
            <span class="badge bg-success ms-2" style="font-size: 0.6rem;">ADMIN</span>
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
            <i class="bi bi-building-fill me-2" style="color: #22c55e;"></i>
            Safe Shelters Management
        </h1>
        <p class="text-muted mt-1">Register and manage evacuation shelters</p>
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
        <div class="col-lg-7 mb-4">
            <div class="bg-dark rounded-3 p-3">
                <div id="map"></div>
                <div class="mt-2 text-muted small">
                    <i class="bi bi-info-circle me-1"></i>
                    Click on the map to add a new shelter at that location.
                </div>
            </div>
        </div>
        
        <!-- Shelters List Column -->
        <div class="col-lg-5">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="bi bi-building me-2"></i>Registered Shelters</h5>
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#shelterModal" onclick="resetForm()">
                    <i class="bi bi-plus-circle me-1"></i>Add Shelter
                </button>
            </div>
            
            <div id="sheltersList" style="max-height: 500px; overflow-y: auto;">
                <?php if (count($shelters) > 0): ?>
                    <?php foreach ($shelters as $shelter): 
                        $capacity_percent = ($shelter['capacity'] > 0) ? ($shelter['current_occupancy'] / $shelter['capacity']) * 100 : 0;
                        $capacity_class = '';
                        if ($capacity_percent >= 90) $capacity_class = 'danger';
                        elseif ($capacity_percent >= 70) $capacity_class = 'warning';
                    ?>
                        <div class="shelter-card" onclick="zoomToShelter(<?= $shelter['latitude'] ?>, <?= $shelter['longitude'] ?>)">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1"><?= htmlspecialchars($shelter['name']) ?></h6>
                                    <span class="badge bg-secondary"><?= $shelter_types[$shelter['type']] ?? $shelter['type'] ?></span>
                                    <p class="small text-muted mt-2 mb-1">
                                        <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($shelter['address'] ?? 'Address not specified') ?>
                                    </p>
                                </div>
                                <div class="btn-group-vertical">
                                    <button class="btn btn-sm btn-outline-warning mb-1" onclick="editShelter(<?= htmlspecialchars(json_encode($shelter)) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Delete this shelter?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="shelter_id" value="<?= $shelter['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="mt-2">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span>Capacity: <?= $shelter['current_occupancy'] ?> / <?= $shelter['capacity'] ?></span>
                                    <span><?= round($capacity_percent) ?>%</span>
                                </div>
                                <div class="capacity-bar">
                                    <div class="capacity-fill <?= $capacity_class ?>" style="width: <?= $capacity_percent ?>%"></div>
                                </div>
                            </div>
                            <?php if ($shelter['resources']): ?>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <i class="bi bi-box-seam me-1"></i><?= htmlspecialchars(substr($shelter['resources'], 0, 50)) ?>...
                                    </small>
                                </div>
                            <?php endif; ?>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($shelter['contact_phone'] ?? 'N/A') ?>
                                </small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-building fs-1 d-block mb-2"></i>
                        <p>No shelters registered.<br>Click "Add Shelter" to get started.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Shelter Form Modal -->
<div class="modal fade" id="shelterModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="bi bi-building-add me-2"></i>Add/Edit Shelter</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="shelterForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="shelter_id" id="shelter_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Shelter Name *</label>
                            <input type="text" name="name" id="shelter_name" class="form-control bg-dark text-white border-secondary" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Type</label>
                            <select name="type" id="shelter_type" class="form-select bg-dark text-white border-secondary">
                                <?php foreach ($shelter_types as $key => $label): ?>
                                    <option value="<?= $key ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Capacity (people)</label>
                            <input type="number" name="capacity" id="shelter_capacity" class="form-control bg-dark text-white border-secondary" value="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Current Occupancy</label>
                            <input type="number" name="current_occupancy" id="shelter_occupancy" class="form-control bg-dark text-white border-secondary" value="0">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Latitude</label>
                            <input type="text" name="latitude" id="shelter_lat" class="form-control bg-dark text-white border-secondary" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Longitude</label>
                            <input type="text" name="longitude" id="shelter_lng" class="form-control bg-dark text-white border-secondary" readonly>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" id="shelter_address" class="form-control bg-dark text-white border-secondary" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Phone</label>
                            <input type="text" name="contact_phone" id="shelter_phone" class="form-control bg-dark text-white border-secondary">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="shelter_status" class="form-select bg-dark text-white border-secondary">
                                <option value="active">Active</option>
                                <option value="full">Full</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Available Resources</label>
                        <textarea name="resources" id="shelter_resources" class="form-control bg-dark text-white border-secondary" rows="2" 
                                  placeholder="e.g., Food, Water, Medical, Blankets"></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Click on the map to set the shelter location, or enter coordinates manually.
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Shelter</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// Initialize map
var map = L.map('map').setView([-1.2921, 36.8219], 7);
var markersLayer = new L.LayerGroup().addTo(map);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Shelter icon
var shelterIcon = L.divIcon({
    html: '<i class="bi bi-building" style="font-size: 24px; color: #22c55e; text-shadow: 0 0 5px rgba(0,0,0,0.5);"></i>',
    className: '',
    iconSize: [24, 24],
    iconAnchor: [12, 12]
});

// Load existing shelters
<?php foreach ($shelters as $shelter): ?>
    L.marker([<?= $shelter['latitude'] ?>, <?= $shelter['longitude'] ?>], { icon: shelterIcon })
        .addTo(markersLayer)
        .bindPopup('<strong><?= addslashes($shelter['name']) ?></strong><br><?= addslashes($shelter['address'] ?? '') ?><br>Capacity: <?= $shelter['current_occupancy'] ?>/<?= $shelter['capacity'] ?>');
<?php endforeach; ?>

// Click on map to set location
var selectedMarker = null;
map.on('click', function(e) {
    if (selectedMarker) {
        markersLayer.removeLayer(selectedMarker);
    }
    selectedMarker = L.marker(e.latlng, { icon: shelterIcon }).addTo(markersLayer);
    document.getElementById('shelter_lat').value = e.latlng.lat;
    document.getElementById('shelter_lng').value = e.latlng.lng;
});

function resetForm() {
    document.getElementById('shelter_id').value = '';
    document.getElementById('shelterForm').reset();
    if (selectedMarker) {
        markersLayer.removeLayer(selectedMarker);
        selectedMarker = null;
    }
}

function editShelter(shelter) {
    document.getElementById('shelter_id').value = shelter.id;
    document.getElementById('shelter_name').value = shelter.name;
    document.getElementById('shelter_type').value = shelter.type;
    document.getElementById('shelter_capacity').value = shelter.capacity;
    document.getElementById('shelter_occupancy').value = shelter.current_occupancy;
    document.getElementById('shelter_lat').value = shelter.latitude;
    document.getElementById('shelter_lng').value = shelter.longitude;
    document.getElementById('shelter_address').value = shelter.address || '';
    document.getElementById('shelter_phone').value = shelter.contact_phone || '';
    document.getElementById('shelter_status').value = shelter.status;
    document.getElementById('shelter_resources').value = shelter.resources || '';
    
    // Show marker on map
    if (selectedMarker) markersLayer.removeLayer(selectedMarker);
    selectedMarker = L.marker([shelter.latitude, shelter.longitude], { icon: shelterIcon }).addTo(markersLayer);
    map.setView([shelter.latitude, shelter.longitude], 15);
    
    var modal = new bootstrap.Modal(document.getElementById('shelterModal'));
    modal.show();
}

function zoomToShelter(lat, lng) {
    map.setView([lat, lng], 16);
}
</script>

</body>
</html>