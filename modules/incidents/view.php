<?php
/**
 * Single Incident Detail View
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Get incident ID from URL parameter
$incident_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($incident_id <= 0) {
    redirect('all.php');
}

// Function to fetch complete incident data with reporter and responder info
function fetchIncidentData($pdo, $incident_id) {
    $stmt = $pdo->prepare("
        SELECT i.*, 
               u.full_name as reporter_name, 
               u.phone as reporter_phone,
               u.email as reporter_email,
               r.full_name as responder_name,
               r.phone as responder_phone
        FROM incidents i
        LEFT JOIN users u ON i.reporter_id = u.id
        LEFT JOIN users r ON i.assigned_to = r.id
        WHERE i.id = ?
    ");
    $stmt->execute([$incident_id]);
    return $stmt->fetch();
}

// Fetch incident details
$incident = fetchIncidentData($pdo, $incident_id);

if (!$incident) {
    redirect('all.php');
}

// Check if user has permission to view this incident
$is_reporter = (isLoggedIn() && isset($_SESSION['user_id']) && $_SESSION['user_id'] == ($incident['reporter_id'] ?? 0));
$is_responder = hasRole(['responder', 'admin']);

if (!$is_reporter && !$is_responder) {
    redirect('index.php');
}

// Handle status update (for responders/admins)
$status_update_success = null;
$status_update_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Handle adding update
    if ($_POST['action'] === 'add_update' && ($is_reporter || $is_responder)) {
        $additional_info = trim($_POST['additional_info'] ?? '');
        
        if (!empty($additional_info)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO incident_updates (incident_id, user_id, update_text, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$incident_id, $_SESSION['user_id'], $additional_info]);
                $status_update_success = "Update added successfully.";
            } catch (PDOException $e) {
                $status_update_error = "Failed to add update.";
                error_log("Add update failed: " . $e->getMessage());
            }
        }
    }
    
    if ($_POST['action'] === 'update_status' && $is_responder) {
        $new_status = $_POST['status'];
        $allowed_statuses = ['reported', 'acknowledged', 'in-progress', 'resolved', 'cancelled'];
        
        if (in_array($new_status, $allowed_statuses)) {
            $stmt = $pdo->prepare("UPDATE incidents SET status = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt->execute([$new_status, $incident_id])) {
                $status_update_success = "Incident status updated to: " . ucfirst(str_replace('-', ' ', $new_status));
                // Refresh incident data
                $incident = fetchIncidentData($pdo, $incident_id);
            } else {
                $status_update_error = "Failed to update status.";
            }
        }
    }
    
    // Handle assigning responder
    if ($_POST['action'] === 'assign_responder' && $is_responder) {
        $responder_id = (int)($_POST['responder_id'] ?? 0);
        if ($responder_id > 0) {
            $stmt = $pdo->prepare("UPDATE incidents SET assigned_to = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt->execute([$responder_id, $incident_id])) {
                $status_update_success = "Responder assigned successfully.";
                // Refresh incident data
                $incident = fetchIncidentData($pdo, $incident_id);
            } else {
                $status_update_error = "Failed to assign responder.";
            }
        }
    }
}

// Fetch incident updates timeline
$stmt = $pdo->prepare("
    SELECT iu.*, u.full_name as user_name, u.role as user_role
    FROM incident_updates iu
    JOIN users u ON iu.user_id = u.id
    WHERE iu.incident_id = ?
    ORDER BY iu.created_at ASC
");
$stmt->execute([$incident_id]);
$updates = $stmt->fetchAll();

// Fetch available responders for assignment dropdown
$responders = [];
if ($is_responder) {
    $stmt = $pdo->prepare("SELECT id, full_name, phone FROM users WHERE role = 'responder' ORDER BY full_name");
    $stmt->execute();
    $responders = $stmt->fetchAll();
}

// Severity configuration
$severity_config = [
    1 => ['label' => 'Low', 'color' => '#28a745', 'icon' => '🟢', 'class' => 'success'],
    2 => ['label' => 'Medium', 'color' => '#ffc107', 'icon' => '🟡', 'class' => 'warning'],
    3 => ['label' => 'High', 'color' => '#fd7e14', 'icon' => '🟠', 'class' => 'danger'],
    4 => ['label' => 'Critical', 'color' => '#dc3545', 'icon' => '🔴', 'class' => 'danger']
];

$current_severity = $severity_config[$incident['severity'] ?? 1] ?? $severity_config[1];

// Status configuration
$status_config = [
    'reported' => ['label' => '📋 Reported', 'class' => 'secondary', 'icon' => 'bi-clock-history'],
    'acknowledged' => ['label' => '👀 Under Review', 'class' => 'info', 'icon' => 'bi-eye'],
    'in-progress' => ['label' => '🚑 In Progress', 'class' => 'primary', 'icon' => 'bi-truck'],
    'resolved' => ['label' => '✅ Resolved', 'class' => 'success', 'icon' => 'bi-check-circle'],
    'cancelled' => ['label' => '❌ Cancelled', 'class' => 'secondary', 'icon' => 'bi-x-circle'],
    'rejected' => ['label' => '⚠️ Rejected', 'class' => 'danger', 'icon' => 'bi-exclamation-triangle']
];

$current_status = $status_config[$incident['status'] ?? 'reported'] ?? ['label' => ucfirst($incident['status'] ?? 'Unknown'), 'class' => 'secondary', 'icon' => 'bi-question-circle'];

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
$type_icon = $type_icons[$incident['incident_type'] ?? 'other'] ?? '📍';

// Helper function to safely output values
function safe($value, $default = 'Not provided') {
    if ($value === null || $value === '') {
        return $default;
    }
    return htmlspecialchars((string)$value);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incident #<?= str_pad($incident_id, 5, '0', STR_PAD_LEFT) ?> - DisasterResponse</title>
    
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
        
        .severity-badge {
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .location-map {
            height: 300px;
            border-radius: 16px;
            overflow: hidden;
        }
        
        .timeline-item {
            display: flex;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .timeline-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .timeline-content {
            flex: 1;
        }
        
        .update-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .update-item:last-child { margin-bottom: 0; }
        
        .btn-action {
            border-radius: 12px;
            padding: 0.5rem 1rem;
            font-weight: 500;
        }
        
        .photo-gallery img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 12px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .photo-gallery img:hover {
            transform: scale(1.02);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .info-card {
            animation: fadeIn 0.3s ease-out;
        }
        
        @media (max-width: 768px) {
            .page-header h1 { font-size: 1.5rem; }
            .timeline-item { flex-direction: column; gap: 0.5rem; }
            .timeline-icon { align-self: flex-start; }
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
            <a href="/disaster_response/modules/responders/responders_dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a href="all.php" class="btn btn-outline-danger btn-sm rounded-pill">
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
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Incident #<?= str_pad($incident_id, 5, '0', STR_PAD_LEFT) ?>
                </h1>
                <p class="mb-0 opacity-75">Detailed information about this emergency report</p>
            </div>
            <div class="col-md-4 text-end">
                <span class="status-badge bg-<?= $current_status['class'] ?> bg-opacity-10 text-<?= $current_status['class'] ?> border border-<?= $current_status['class'] ?> border-opacity-25">
                    <i class="bi <?= $current_status['icon'] ?>"></i>
                    <?= $current_status['label'] ?>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    
    <!-- Action Buttons for Responders -->
    <?php if ($is_responder && !in_array($incident['status'] ?? '', ['resolved', 'cancelled', 'rejected'])): ?>
        <div class="info-card mb-4">
            <div class="info-card-header">
                <i class="bi bi-gear-fill"></i> Actions
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <form method="POST" class="d-flex gap-2">
                            <input type="hidden" name="action" value="update_status">
                            <select name="status" class="form-select form-select-sm" style="width: auto;">
                                <option value="reported" <?= ($incident['status'] ?? '') == 'reported' ? 'selected' : '' ?>>📋 Reported</option>
                                <option value="acknowledged" <?= ($incident['status'] ?? '') == 'acknowledged' ? 'selected' : '' ?>>👀 Under Review</option>
                                <option value="in-progress" <?= ($incident['status'] ?? '') == 'in-progress' ? 'selected' : '' ?>>🚑 In Progress</option>
                                <option value="resolved" <?= ($incident['status'] ?? '') == 'resolved' ? 'selected' : '' ?>>✅ Resolved</option>
                                <option value="cancelled" <?= ($incident['status'] ?? '') == 'cancelled' ? 'selected' : '' ?>>❌ Cancelled</option>
                            </select>
                            <button type="submit" class="btn btn-danger btn-sm rounded-pill">
                                <i class="bi bi-check-lg"></i> Update Status
                            </button>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <form method="POST" class="d-flex gap-2">
                            <input type="hidden" name="action" value="assign_responder">
                            <select name="responder_id" class="form-select form-select-sm" style="width: auto;">
                                <option value="">-- Assign Responder --</option>
                                <?php foreach ($responders as $responder): ?>
                                    <option value="<?= $responder['id'] ?>" <?= (($incident['assigned_to'] ?? 0) == $responder['id']) ? 'selected' : '' ?>>
                                        <?= safe($responder['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill">
                                <i class="bi bi-person-plus"></i> Assign
                            </button>
                        </form>
                    </div>
                </div>
                
                <?php if ($status_update_success): ?>
                    <div class="alert alert-success alert-dismissible fade show mt-3 mb-0" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($status_update_success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($status_update_error): ?>
                    <div class="alert alert-danger alert-dismissible fade show mt-3 mb-0" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($status_update_error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Left Column - Main Info -->
        <div class="col-lg-7">
            <!-- Incident Details -->
            <div class="info-card">
                <div class="info-card-header">
                    <i class="bi bi-info-circle-fill"></i> Incident Details
                </div>
                <div class="card-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small fw-semibold">Incident Type</label>
                            <p class="mb-0 fw-medium fs-5">
                                <?= $type_icon ?> <?= ucfirst(str_replace('_', ' ', $incident['incident_type'] ?? 'Unknown')) ?>
                            </p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small fw-semibold">Severity</label>
                            <p class="mb-0">
                                <span class="severity-badge" style="background: <?= $current_severity['color'] ?>20; color: <?= $current_severity['color'] ?>; border: 1px solid <?= $current_severity['color'] ?>40;">
                                    <?= $current_severity['icon'] ?> <?= $current_severity['label'] ?>
                                </span>
                            </p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="text-muted small fw-semibold">Location</label>
                            <p class="mb-0 fw-medium"><?= safe($incident['location_name'] ?? null, 'Location coordinates provided below') ?></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="text-muted small fw-semibold">Coordinates</label>
                            <p class="mb-0">
                                <code>Lat: <?= $incident['latitude'] ?? 'N/A' ?></code> | 
                                <code>Lng: <?= $incident['longitude'] ?? 'N/A' ?></code>
                                <?php if (!empty($incident['latitude']) && !empty($incident['longitude'])): ?>
                                <a href="https://www.google.com/maps?q=<?= $incident['latitude'] ?>,<?= $incident['longitude'] ?>" target="_blank" class="ms-2 text-danger">
                                    <i class="bi bi-box-arrow-up-right"></i> Open in Google Maps
                                </a>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="text-muted small fw-semibold">Description</label>
                            <p class="mb-0"><?= nl2br(htmlspecialchars($incident['description'] ?? '')) ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small fw-semibold">Reported On</label>
                            <p class="mb-0"><?= date('F j, Y \a\t g:i A', strtotime($incident['reported_at'] ?? 'now')) ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small fw-semibold">Last Updated</label>
                            <p class="mb-0"><?= date('F j, Y \a\t g:i A', strtotime($incident['updated_at'] ?? $incident['reported_at'] ?? 'now')) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Location Map -->
            <?php if (!empty($incident['latitude']) && !empty($incident['longitude'])): ?>
            <div class="info-card">
                <div class="info-card-header">
                    <i class="bi bi-map-fill"></i> Incident Location Map
                </div>
                <div class="card-body p-4">
                    <div id="incidentMap" class="location-map"></div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Photos -->
            <?php if (!empty($incident['photo_path'])): ?>
            <div class="info-card">
                <div class="info-card-header">
                    <i class="bi bi-images"></i> Evidence Photos
                </div>
                <div class="card-body p-4">
                    <div class="photo-gallery">
                        <img src="<?= htmlspecialchars($incident['photo_path']) ?>" 
                             alt="Incident photo" 
                             class="img-fluid rounded"
                             onclick="window.open(this.src, '_blank')">
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Right Column - Reporter & Timeline -->
        <div class="col-lg-5">
            <!-- Reporter Information -->
            <div class="info-card">
                <div class="info-card-header">
                    <i class="bi bi-person-fill"></i> Reporter Information
                </div>
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="bg-light rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                            <i class="bi bi-person-fill fs-3 text-secondary"></i>
                        </div>
                        <div>
                            <h5 class="mb-0"><?= safe($incident['reporter_name'] ?? null, 'Anonymous') ?></h5>
                            <small class="text-muted">Reporter</small>
                        </div>
                    </div>
                    <div class="mb-2">
                        <i class="bi bi-envelope me-2 text-muted"></i>
                        <?php if (!empty($incident['reporter_email'])): ?>
                            <a href="mailto:<?= htmlspecialchars($incident['reporter_email']) ?>" class="text-decoration-none">
                                <?= htmlspecialchars($incident['reporter_email']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">Not provided</span>
                        <?php endif; ?>
                    </div>
                    <div class="mb-2">
                        <i class="bi bi-telephone me-2 text-muted"></i>
                        <?php if (!empty($incident['reporter_phone'])): ?>
                            <a href="tel:<?= htmlspecialchars($incident['reporter_phone']) ?>" class="text-decoration-none">
                                <?= htmlspecialchars($incident['reporter_phone']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">Not provided</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Assigned Responder -->
            <div class="info-card">
                <div class="info-card-header">
                    <i class="bi bi-person-badge-fill"></i> Assigned Responder
                </div>
                <div class="card-body p-4">
                    <?php if (!empty($incident['assigned_to']) && !empty($incident['responder_name'])): ?>
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-light rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                <i class="bi bi-shield-fill fs-4 text-danger"></i>
                            </div>
                            <div>
                                <h6 class="mb-0"><?= htmlspecialchars($incident['responder_name']) ?></h6>
                                <small class="text-muted">
                                    <?= !empty($incident['responder_phone']) ? '📞 ' . htmlspecialchars($incident['responder_phone']) : 'Assigned Responder' ?>
                                </small>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">
                            <i class="bi bi-clock-history me-2"></i>
                            No responder assigned yet. <?= $is_responder ? 'Use the form above to assign one.' : 'A responder will be assigned shortly.' ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Status Timeline -->
            <div class="info-card">
                <div class="info-card-header">
                    <i class="bi bi-clock-history"></i> Status Timeline
                </div>
                <div class="card-body p-4">
                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-envelope-paper-fill"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="fw-semibold">Report Submitted</div>
                                <small class="text-muted"><?= date('F j, Y \a\t g:i A', strtotime($incident['reported_at'] ?? 'now')) ?></small>
                                <p class="mb-0 small mt-1">Initial report received from <?= safe($incident['reporter_name'] ?? null, 'reporter') ?></p>
                            </div>
                        </div>
                        
                        <?php if (($incident['status'] ?? '') != 'reported' && ($incident['status'] ?? '') != 'rejected'): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon bg-info bg-opacity-10 text-info">
                                <i class="bi bi-eye-fill"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="fw-semibold">Acknowledged / Under Review</div>
                                <small class="text-muted">Status: Acknowledged</small>
                                <p class="mb-0 small mt-1">Report has been reviewed by response team</p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (($incident['status'] ?? '') == 'in-progress' || ($incident['status'] ?? '') == 'resolved'): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-truck"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="fw-semibold">Responders Dispatched</div>
                                <small class="text-muted">Status: In Progress</small>
                                <p class="mb-0 small mt-1">Emergency responders are en route to the location</p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (($incident['status'] ?? '') == 'resolved'): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon bg-success bg-opacity-10 text-success">
                                <i class="bi bi-check-circle-fill"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="fw-semibold">Incident Resolved</div>
                                <small class="text-muted">Status: Resolved</small>
                                <p class="mb-0 small mt-1">This incident has been marked as resolved</p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (($incident['status'] ?? '') == 'rejected'): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon bg-danger bg-opacity-10 text-danger">
                                <i class="bi bi-x-circle-fill"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="fw-semibold">Incident Rejected</div>
                                <small class="text-muted">Status: Rejected</small>
                                <p class="mb-0 small mt-1"><?= safe($incident['rejection_reason'] ?? null, 'No reason provided') ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Updates Timeline -->
            <div class="info-card">
                <div class="info-card-header">
                    <i class="bi bi-chat-dots-fill"></i> Updates & Communication
                </div>
                <div class="card-body p-4">
                    <?php if ($is_reporter || $is_responder): ?>
                        <form method="POST" class="mb-4">
                            <input type="hidden" name="action" value="add_update">
                            <div class="mb-2">
                                <textarea name="additional_info" 
                                          class="form-control" 
                                          rows="2" 
                                          placeholder="Add an update or comment about this incident..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger btn-sm rounded-pill">
                                <i class="bi bi-send me-1"></i> Post Update
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if (count($updates) > 0): ?>
                        <div class="updates-list">
                            <?php foreach ($updates as $update): ?>
                                <div class="update-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi <?= ($update['user_role'] ?? '') == 'responder' ? 'bi-shield-fill-check text-danger' : 'bi-person-circle text-muted' ?>"></i>
                                            <strong class="small"><?= htmlspecialchars($update['user_name'] ?? 'Unknown') ?></strong>
                                            <?php if (($update['user_role'] ?? '') == 'responder'): ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger" style="font-size: 0.6rem;">Responder</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted"><?= date('M j, g:i A', strtotime($update['created_at'] ?? 'now')) ?></small>
                                    </div>
                                    <p class="mb-0 small mt-2"><?= nl2br(htmlspecialchars($update['update_text'] ?? '')) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-chat-fs-2 mb-2 d-block" style="font-size: 2rem; opacity: 0.3;"></i>
                            <p class="small mb-0">No updates yet. Be the first to add one.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
<?php if (!empty($incident['latitude']) && !empty($incident['longitude'])): ?>
// Initialize map
var map = L.map('incidentMap').setView([<?= $incident['latitude'] ?>, <?= $incident['longitude'] ?>], 15);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Add marker for incident location
var marker = L.marker([<?= $incident['latitude'] ?>, <?= $incident['longitude'] ?>]).addTo(map);
marker.bindPopup(`
    <strong>Incident #<?= str_pad($incident_id, 5, '0', STR_PAD_LEFT) ?></strong><br>
    Type: <?= ucfirst(str_replace('_', ' ', $incident['incident_type'] ?? 'Unknown')) ?><br>
    Severity: <?= $current_severity['label'] ?>
`).openPopup();
<?php endif; ?>

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