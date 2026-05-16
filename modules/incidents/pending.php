<?php
/**
 * Pending Incidents List
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Shows all unverified incidents for admins/responders to review and verify
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admins and responders can access
role_guard(['admin', 'responder']);

// Fetch pending incidents (status = 'reported')
$stmt = $pdo->prepare("
    SELECT i.*, u.full_name as reporter_name, u.phone as reporter_phone
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id = u.id
    WHERE i.status = 'reported'
    ORDER BY i.severity DESC, i.reported_at ASC
");
$stmt->execute();
$pending_incidents = $stmt->fetchAll();

// Get count of pending incidents
$pending_count = count($pending_incidents);

// Severity configuration for badges
$severity_config = [
    1 => ['label' => 'Low', 'color' => '#28a745', 'icon' => '🟢', 'class' => 'success'],
    2 => ['label' => 'Medium', 'color' => '#ffc107', 'icon' => '🟡', 'class' => 'warning'],
    3 => ['label' => 'High', 'color' => '#fd7e14', 'icon' => '🟠', 'class' => 'danger'],
    4 => ['label' => 'Critical', 'color' => '#dc3545', 'icon' => '🔴', 'class' => 'danger']
];

// Incident type icons
$type_icons = [
    'flood' => '🌊',
    'fire' => '🔥',
    'earthquake' => '🏚️',
    'landslide' => '⛰️',
    'drought' => '☀️',
    'accident' => '🚗',
    'building_collapse' => '🏗️',
    'disease_outbreak' => '🦠',
    'other' => '⚠️'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Incidents - DisasterResponse</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; }
        
        .navbar-modern {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 0.75rem 0;
        }
        
        .navbar-brand {
            font-weight: 800;
            font-size: 1.5rem;
            background: linear-gradient(135deg, #dc3545, #b91c1c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .page-header {
            background: linear-gradient(135deg, #dc3545, #b91c1c);
            border-radius: 0 0 30px 30px;
            padding: 2rem 0;
            color: white;
            margin-bottom: 2rem;
        }
        
        .stats-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            text-align: center;
            transition: transform 0.2s;
            height: 100%;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: 800;
            color: #dc3545;
        }
        
        .pending-card {
            background: white;
            border-radius: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: all 0.2s;
        }
        
        .pending-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .card-header-custom {
            background: white;
            border-bottom: 1px solid #f0f0f0;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .incident-id {
            font-weight: 700;
            background: #f8f9fa;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            color: #dc3545;
            font-size: 0.85rem;
        }
        
        .severity-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .btn-verify-sm {
            background: linear-gradient(135deg, #28a745, #1e7e34);
            border: none;
            padding: 0.35rem 1rem;
            font-size: 0.8rem;
            border-radius: 20px;
            color: white;
            text-decoration: none;
        }
        
        .btn-verify-sm:hover {
            background: linear-gradient(135deg, #1e7e34, #155724);
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem;
            background: white;
            border-radius: 20px;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 1rem;
        }
        
        .time-ago {
            font-size: 0.7rem;
            color: #6c757d;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .pending-card {
            animation: fadeIn 0.3s ease-out;
        }
        
        @media (max-width: 768px) {
            .page-header h1 { font-size: 1.5rem; }
            .stats-number { font-size: 1.8rem; }
            .card-header-custom { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-modern sticky-top">
    <div class="container">
        <a class="navbar-brand" href="/disaster_response/index.php">
            <i class="bi bi-shield-check me-2"></i>DisasterResponse
        </a>
        <div class="d-flex gap-2">
            <span class="text-muted small d-none d-md-block mt-2">
                <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?>
                <span class="badge bg-danger ms-1"><?= ucfirst($_SESSION['role'] ?? '') ?></span>
            </span>
            <a href="all.php" class="btn btn-outline-secondary btn-sm rounded-pill">
                <i class="bi bi-list-ul me-1"></i>All Incidents
            </a>
            <a href="/disaster_response/modules/auth/logout.php" class="btn btn-outline-danger btn-sm rounded-pill">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1 class="fw-bold mb-2">
                    <i class="bi bi-clock-history me-2"></i>Pending Verification
                </h1>
                <p class="mb-0 opacity-75">Review and verify incoming incident reports</p>
            </div>
            <div class="col-md-4 text-end">
                <i class="bi bi-envelope-paper-fill" style="font-size: 3rem; opacity: 0.3;"></i>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    
    <!-- Statistics Row -->
    <div class="row mb-4">
        <div class="col-md-3 col-6 mb-3">
            <div class="stats-card">
                <div class="stats-number"><?= $pending_count ?></div>
                <p class="text-muted mb-0">Pending Verification</p>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="stats-card">
                <div class="stats-number" style="color: #fd7e14;"><?= count(array_filter($pending_incidents, fn($i) => $i['severity'] >= 3)) ?></div>
                <p class="text-muted mb-0">High/Critical</p>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="stats-card">
                <div class="stats-number" style="color: #17a2b8;"><?= date('H') ?>h</div>
                <p class="text-muted mb-0">Avg Response Time</p>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="stats-card">
                <div class="stats-number" style="color: #28a745;"><?= date('d/m') ?></div>
                <p class="text-muted mb-0">Today's Date</p>
            </div>
        </div>
    </div>
    
    <?php if (empty($pending_incidents)): ?>
        <div class="empty-state">
            <i class="bi bi-check-circle-fill text-success"></i>
            <h5 class="mb-2">No Pending Incidents</h5>
            <p class="text-muted mb-0">All incidents have been reviewed. Great work!</p>
            <a href="all.php" class="btn btn-outline-danger rounded-pill mt-3">
                <i class="bi bi-list-ul me-1"></i>View All Incidents
            </a>
        </div>
    <?php else: ?>
        
        <!-- Pending Incidents List -->
        <?php foreach ($pending_incidents as $incident): 
            $severity = $severity_config[$incident['severity']] ?? $severity_config[1];
            $type_icon = $type_icons[$incident['incident_type']] ?? '📍';
            $time_ago = time_ago(strtotime($incident['reported_at']));
        ?>
            <div class="pending-card">
                <div class="card-header-custom">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <span class="incident-id">
                            <i class="bi bi-hash"></i>INC-<?= str_pad($incident['id'], 5, '0', STR_PAD_LEFT) ?>
                        </span>
                        <span class="severity-badge" style="background: <?= $severity['color'] ?>20; color: <?= $severity['color'] ?>; border: 1px solid <?= $severity['color'] ?>40;">
                            <?= $severity['icon'] ?> <?= $severity['label'] ?> Severity
                        </span>
                        <span class="time-ago">
                            <i class="bi bi-clock"></i> <?= $time_ago ?>
                        </span>
                    </div>
                    <div>
                        <a href="verify.php?id=<?= $incident['id'] ?>" class="btn-verify-sm">
                            <i class="bi bi-check2-circle me-1"></i>Verify Now
                        </a>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fs-4"><?= $type_icon ?></span>
                                <div>
                                    <label class="text-muted small fw-semibold d-block">Incident Type</label>
                                    <span class="fw-medium"><?= ucfirst(str_replace('_', ' ', $incident['incident_type'])) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small fw-semibold">Reported By</label>
                            <p class="mb-0 fw-medium">
                                <?= htmlspecialchars($incident['reporter_name'] ?? 'Anonymous') ?>
                                <?php if ($incident['reporter_phone']): ?>
                                    <small class="text-muted">(<?= $incident['reporter_phone'] ?>)</small>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="text-muted small fw-semibold">Location</label>
                            <p class="mb-0">
                                <i class="bi bi-geo-alt-fill text-danger me-1"></i>
                                <?= htmlspecialchars($incident['location_name'] ?? 'Coordinates: ' . $incident['latitude'] . ', ' . $incident['longitude']) ?>
                            </p>
                        </div>
                        <div class="col-12">
                            <label class="text-muted small fw-semibold">Description</label>
                            <p class="mb-0 small text-muted"><?= htmlspecialchars(substr($incident['description'], 0, 150)) ?>...</p>
                        </div>
                    </div>
                    
                    <!-- Quick Action Buttons -->
                    <div class="mt-3 pt-2 border-top d-flex gap-2 flex-wrap">
                        <a href="verify.php?id=<?= $incident['id'] ?>" class="btn btn-sm btn-success rounded-pill">
                            <i class="bi bi-check-lg me-1"></i>Verify & Dispatch
                        </a>
                        <a href="view.php?id=<?= $incident['id'] ?>" class="btn btn-sm btn-outline-secondary rounded-pill">
                            <i class="bi bi-eye me-1"></i>View Details
                        </a>
                        <button onclick="showMap(<?= $incident['latitude'] ?>, <?= $incident['longitude'] ?>)" class="btn btn-sm btn-outline-info rounded-pill">
                            <i class="bi bi-map me-1"></i>View on Map
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- Refresh Button -->
        <div class="text-center mt-4">
            <a href="pending.php" class="btn btn-outline-danger rounded-pill">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh List
            </a>
        </div>
        
    <?php endif; ?>
</div>

<!-- Map Modal -->
<div class="modal fade" id="mapModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-map me-2"></i>Incident Location</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="modalMap" style="height: 400px; width: 100%;"></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
let modalMap = null;
let mapInitialized = false;

function showMap(lat, lng) {
    const modalElement = document.getElementById('mapModal');
    const modal = new bootstrap.Modal(modalElement);
    
    modalElement.addEventListener('shown.bs.modal', function () {
        if (modalMap) {
            modalMap.remove();
        }
        modalMap = L.map('modalMap').setView([lat, lng], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(modalMap);
        L.marker([lat, lng]).addTo(modalMap)
            .bindPopup('Incident Location')
            .openPopup();
    });
    
    modal.show();
}

// Auto-refresh every 60 seconds (optional)
setTimeout(function() {
    location.reload();
}, 60000);
</script>

</body>
</html>

<?php
/**
 * Helper function to calculate time ago
 */
function time_ago($timestamp) {
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . ' days ago';
    } else {
        return date('M j, Y', $timestamp);
    }
}
?>