<?php
/**
 * Incident Verification & Dispatch Module
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only accessible by admins and responders
role_guard(['admin', 'responder']);

$incident_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($incident_id <= 0) {
    redirect('pending.php');
}

// Fetch incident details with reporter info
$stmt = $pdo->prepare("
    SELECT i.*, u.full_name as reporter_name, u.phone as reporter_phone, u.email as reporter_email
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id = u.id
    WHERE i.id = ? AND i.status = 'reported'
");
$stmt->execute([$incident_id]);
$incident = $stmt->fetch();

if (!$incident) {
    $_SESSION['error'] = "Incident not found or already processed.";
    redirect('pending.php');
}

// Fetch available responders for assignment
$stmt = $pdo->prepare("SELECT id, full_name, phone FROM users WHERE role = 'responder' ORDER BY full_name");
$stmt->execute();
$responders = $stmt->fetchAll();

// Fetch available resources for dispatch
$stmt = $pdo->prepare("
    SELECT resource_type, SUM(quantity) as total_quantity 
    FROM resources 
    WHERE status = 'available' AND quantity > 0
    GROUP BY resource_type
");
$stmt->execute();
$resources = $stmt->fetchAll();

$resource_types = [
    'food' => '🍲 Food Supplies',
    'water' => '💧 Water',
    'medicine' => '💊 Medical Supplies',
    'shelter' => '🏠 Shelter Materials',
    'clothing' => '👕 Clothing',
    'blankets' => '🛏️ Blankets',
    'first_aid' => '🩹 First Aid Kits',
    'transport' => '🚛 Transport Vehicles',
    'rescue_team' => '🪢 Rescue Team',
    'medical_team' => '👨‍⚕️ Medical Team'
];

$success = null;
$error = null;
$debug_info = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'verify') {
        try {
            // Begin transaction
            $pdo->beginTransaction();
            $debug_info[] = "Transaction started";
            
            // Check if verified_by column exists
            $stmt = $pdo->query("SHOW COLUMNS FROM incidents LIKE 'verified_by'");
            $has_verified_by = $stmt->rowCount() > 0;
            $debug_info[] = "Has verified_by column: " . ($has_verified_by ? "Yes" : "No");
            
            // Update status to acknowledged
            if ($has_verified_by) {
                $stmt = $pdo->prepare("
                    UPDATE incidents 
                    SET status = 'acknowledged', 
                        verified_by = ?, 
                        verified_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $incident_id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE incidents 
                    SET status = 'acknowledged', 
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$incident_id]);
            }
            $debug_info[] = "Incident status updated to acknowledged";
            
            // Assign responder if selected
            if (!empty($_POST['assign_to'])) {
                $stmt = $pdo->prepare("UPDATE incidents SET assigned_to = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$_POST['assign_to'], $incident_id]);
                $debug_info[] = "Responder assigned: " . $_POST['assign_to'];
            }
            
            // Check if resource_dispatches table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'resource_dispatches'");
            $has_dispatches_table = $stmt->rowCount() > 0;
            $debug_info[] = "Has resource_dispatches table: " . ($has_dispatches_table ? "Yes" : "No");
            
            // Dispatch resources if selected
            if (!empty($_POST['resources']) && $has_dispatches_table) {
                foreach ($_POST['resources'] as $resource_type => $quantity) {
                    $quantity = (int)$quantity;
                    if ($quantity > 0) {
                        // Log resource dispatch
                        $stmt = $pdo->prepare("
                            INSERT INTO resource_dispatches 
                            (incident_id, resource_type, quantity, dispatched_by, dispatched_at)
                            VALUES (?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$incident_id, $resource_type, $quantity, $_SESSION['user_id']]);
                        $debug_info[] = "Dispatched $quantity of $resource_type";
                        
                        // Update resource inventory
                        $stmt = $pdo->prepare("
                            UPDATE resources 
                            SET quantity = quantity - ?, updated_at = NOW()
                            WHERE resource_type = ? AND status = 'available' AND quantity >= ?
                            LIMIT 1
                        ");
                        $stmt->execute([$quantity, $resource_type, $quantity]);
                    }
                }
            }
            
            // Check if incident_updates table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'incident_updates'");
            $has_updates_table = $stmt->rowCount() > 0;
            $debug_info[] = "Has incident_updates table: " . ($has_updates_table ? "Yes" : "No");
            
            // Add verification note
            if (!empty($_POST['verification_note']) && $has_updates_table) {
                $stmt = $pdo->prepare("
                    INSERT INTO incident_updates (incident_id, user_id, update_text, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$incident_id, $_SESSION['user_id'], $_POST['verification_note']]);
                $debug_info[] = "Verification note added";
            }
            
            $pdo->commit();
            $debug_info[] = "Transaction committed successfully";
            
            // Log debug info
            error_log("Verification successful: " . implode(" | ", $debug_info));
            
            $_SESSION['success'] = "Incident #{$incident_id} verified and dispatched successfully!";
            redirect('modules/incidents/view.php?id=' . $incident_id);
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_msg = $e->getMessage();
            error_log("Verification failed: " . $error_msg);
            error_log("Debug info: " . implode(" | ", $debug_info));
            $error = "Failed to verify incident: " . $error_msg;
        }
    }
    
    if ($action === 'reject') {
        try {
            $reason = trim($_POST['reason'] ?? 'No reason provided');
            
            // Update status to rejected
            $stmt = $pdo->prepare("
                UPDATE incidents 
                SET status = 'rejected', 
                    rejection_reason = ?, 
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reason, $incident_id]);
            
            // Check if incident_updates table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'incident_updates'");
            $has_updates_table = $stmt->rowCount() > 0;
            
            // Add rejection note
            if ($has_updates_table) {
                $stmt = $pdo->prepare("
                    INSERT INTO incident_updates (incident_id, user_id, update_text, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$incident_id, $_SESSION['user_id'], "Incident rejected. Reason: " . $reason]);
            }
            
            $_SESSION['success'] = "Incident #{$incident_id} has been marked as rejected.";
            redirect('modules/incidents/pending.php');
            
        } catch (PDOException $e) {
            error_log("Rejection failed: " . $e->getMessage());
            $error = "Failed to reject incident: " . $e->getMessage();
        }
    }
}

// Severity configuration
$severity_config = [
    1 => ['label' => 'Low', 'color' => '#28a745', 'icon' => '🟢'],
    2 => ['label' => 'Medium', 'color' => '#ffc107', 'icon' => '🟡'],
    3 => ['label' => 'High', 'color' => '#fd7e14', 'icon' => '🟠'],
    4 => ['label' => 'Critical', 'color' => '#dc3545', 'icon' => '🔴']
];
$current_severity = $severity_config[$incident['severity']] ?? $severity_config[1];

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
$type_icon = $type_icons[$incident['incident_type']] ?? '📍';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Incident #<?= str_pad($incident_id, 5, '0', STR_PAD_LEFT) ?> - DisasterResponse</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    
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
        
        .info-card {
            background: white;
            border-radius: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .info-card-header {
            background: white;
            border-bottom: 2px solid #f0f0f0;
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 1rem;
        }
        
        .info-card-header i {
            color: #dc3545;
            margin-right: 8px;
        }
        
        .verification-card {
            background: white;
            border-radius: 20px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .verification-header {
            background: linear-gradient(135deg, #dc3545, #b91c1c);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
        }
        
        .severity-badge {
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-verify {
            background: linear-gradient(135deg, #28a745, #1e7e34);
            border: none;
            padding: 0.75rem;
            font-weight: 600;
            border-radius: 12px;
        }
        
        .btn-verify:hover {
            background: linear-gradient(135deg, #1e7e34, #155724);
        }
        
        .btn-reject {
            background: linear-gradient(135deg, #dc3545, #b91c1c);
            border: none;
            padding: 0.75rem;
            font-weight: 600;
            border-radius: 12px;
        }
        
        .location-map {
            height: 250px;
            border-radius: 16px;
            overflow: hidden;
        }
        
        .resource-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: 10px;
            background: #f8f9fa;
            margin-bottom: 0.5rem;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .info-card, .verification-card {
            animation: fadeIn 0.3s ease-out;
        }
        
        @media (max-width: 768px) {
            .page-header h1 { font-size: 1.5rem; }
        }
        
        .debug-info {
            font-size: 0.7rem;
            font-family: monospace;
            background: #f8f9fa;
            padding: 0.5rem;
            border-radius: 8px;
            margin-top: 1rem;
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
            <a href="pending.php" class="btn btn-outline-danger btn-sm rounded-pill">
                <i class="bi bi-clock-history me-1"></i>Pending Incidents
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
                    <i class="bi bi-check2-circle me-2"></i>
                    Verify Incident #<?= str_pad($incident_id, 5, '0', STR_PAD_LEFT) ?>
                </h1>
                <p class="mb-0 opacity-75">Review and dispatch resources for this incident</p>
            </div>
            <div class="col-md-4 text-end">
                <span class="severity-badge" style="background: <?= $current_severity['color'] ?>20; color: <?= $current_severity['color'] ?>; border: 1px solid <?= $current_severity['color'] ?>40;">
                    <?= $current_severity['icon'] ?> <?= $current_severity['label'] ?> Severity
                </span>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Left Column - Incident Details -->
        <div class="col-lg-6">
            <!-- Incident Summary -->
            <div class="info-card">
                <div class="info-card-header">
                    <i class="bi bi-info-circle-fill"></i> Incident Details
                </div>
                <div class="card-body p-4">
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="text-muted small fw-semibold">Incident Type</label>
                            <p class="mb-0 fs-5">
                                <?= $type_icon ?> <?= ucfirst(str_replace('_', ' ', $incident['incident_type'])) ?>
                            </p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="text-muted small fw-semibold">Location</label>
                            <p class="mb-0"><?= htmlspecialchars($incident['location_name'] ?? 'Coordinates provided below') ?></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="text-muted small fw-semibold">Coordinates</label>
                            <p class="mb-0">
                                <code>Lat: <?= $incident['latitude'] ?></code> | 
                                <code>Lng: <?= $incident['longitude'] ?></code>
                            </p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="text-muted small fw-semibold">Description</label>
                            <p class="mb-0"><?= nl2br(htmlspecialchars($incident['description'])) ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small fw-semibold">Reported By</label>
                            <p class="mb-0"><?= htmlspecialchars($incident['reporter_name'] ?? 'Anonymous') ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small fw-semibold">Contact</label>
                            <p class="mb-0">
                                <?php if ($incident['reporter_phone']): ?>
                                    <a href="tel:<?= $incident['reporter_phone'] ?>"><?= $incident['reporter_phone'] ?></a>
                                <?php else: ?>
                                    Not provided
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="text-muted small fw-semibold">Reported At</label>
                            <p class="mb-0"><?= date('F j, Y \a\t g:i A', strtotime($incident['reported_at'])) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Location Map -->
            <div class="info-card">
                <div class="info-card-header">
                    <i class="bi bi-map-fill"></i> Incident Location
                </div>
                <div class="card-body p-4">
                    <div id="incidentMap" class="location-map"></div>
                    <div class="mt-3">
                        <a href="https://www.google.com/maps?q=<?= $incident['latitude'] ?>,<?= $incident['longitude'] ?>" 
                           target="_blank" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Open in Google Maps
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Photo Evidence -->
            <?php if (!empty($incident['photo_path'])): ?>
            <div class="info-card">
                <div class="info-card-header">
                    <i class="bi bi-image-fill"></i> Evidence Photo
                </div>
                <div class="card-body p-4">
                    <img src="<?= htmlspecialchars($incident['photo_path']) ?>" 
                         alt="Incident evidence" 
                         class="img-fluid rounded"
                         style="max-height: 300px; width: 100%; object-fit: cover;">
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Right Column - Verification Form -->
        <div class="col-lg-6">
            <div class="verification-card">
                <div class="verification-header">
                    <i class="bi bi-shield-check me-2"></i> Verification Actions
                </div>
                <div class="card-body p-4">
                    
                    <!-- Verify Form -->
                    <form method="POST" id="verifyForm">
                        <input type="hidden" name="action" value="verify">
                        
                        <!-- Assign Responder -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-person-badge me-1 text-danger"></i> Assign Responder
                            </label>
                            <select name="assign_to" class="form-select">
                                <option value="">-- Select Responder --</option>
                                <?php foreach ($responders as $responder): ?>
                                    <option value="<?= $responder['id'] ?>">
                                        <?= htmlspecialchars($responder['full_name']) ?> 
                                        <?= $responder['phone'] ? ' - ' . $responder['phone'] : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Optional - can be assigned later</div>
                        </div>
                        
                        <!-- Dispatch Resources -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-box-seam me-1 text-danger"></i> Dispatch Resources
                            </label>
                            <div class="border rounded p-3">
                                <?php if (count($resources) > 0): ?>
                                    <?php foreach ($resources as $resource): 
                                        $type = $resource['resource_type'];
                                        $icons = [
                                            'food' => '🍲', 'water' => '💧', 'medicine' => '💊',
                                            'shelter' => '🏠', 'clothing' => '👕', 'blankets' => '🛏️',
                                            'first_aid' => '🩹', 'transport' => '🚛'
                                        ];
                                        $icon = $icons[$type] ?? '📦';
                                    ?>
                                        <div class="resource-checkbox">
                                            <div class="flex-grow-1">
                                                <span class="fw-semibold"><?= $icon ?> <?= ucfirst($type) ?></span>
                                                <small class="text-muted d-block">Available: <?= $resource['total_quantity'] ?> units</small>
                                            </div>
                                            <div style="width: 120px;">
                                                <input type="number" 
                                                       name="resources[<?= $type ?>]" 
                                                       class="form-control form-control-sm" 
                                                       placeholder="Qty" 
                                                       min="0" 
                                                       max="<?= $resource['total_quantity'] ?>"
                                                       value="0">
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted mb-0 small">No resources available. <a href="../resources/manage.php">Add resources</a></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Verification Note -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-chat-text me-1 text-danger"></i> Verification Note (Optional)
                            </label>
                            <textarea name="verification_note" class="form-control" rows="3" 
                                      placeholder="Add internal notes about this verification..."></textarea>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="d-grid gap-3">
                            <button type="submit" class="btn btn-verify text-white" 
                                    onclick="return confirm('Verify this incident? Resources will be dispatched and the reporter will be notified.');">
                                <i class="bi bi-check-circle-fill me-2"></i> Verify & Dispatch
                            </button>
                        </div>
                    </form>
                    
                    <hr class="my-4">
                    
                    <!-- Reject Form -->
                    <form method="POST" id="rejectForm">
                        <input type="hidden" name="action" value="reject">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-danger">
                                <i class="bi bi-x-circle me-1"></i> Rejection Reason
                            </label>
                            <textarea name="reason" class="form-control" rows="2" 
                                      placeholder="Why is this incident being rejected? (e.g., false alarm, duplicate report, insufficient information)"></textarea>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-reject text-white"
                                    onclick="return confirm('Are you sure you want to reject this incident? The reporter will be notified.');">
                                <i class="bi bi-x-circle-fill me-2"></i> Reject Incident
                            </button>
                        </div>
                    </form>
                    
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// Initialize map
var map = L.map('incidentMap').setView([<?= $incident['latitude'] ?>, <?= $incident['longitude'] ?>], 15);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Add marker for incident location
var marker = L.marker([<?= $incident['latitude'] ?>, <?= $incident['longitude'] ?>]).addTo(map);
marker.bindPopup(`
    <strong>Incident #<?= str_pad($incident_id, 5, '0', STR_PAD_LEFT) ?></strong><br>
    Type: <?= ucfirst(str_replace('_', ' ', $incident['incident_type'])) ?><br>
    Severity: <?= $current_severity['label'] ?>
`).openPopup();

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = bootstrap.Alert.getInstance(alert);
            if (bsAlert) bsAlert.close();
        });
    }, 5000);
});
</script>

</body>
</html>