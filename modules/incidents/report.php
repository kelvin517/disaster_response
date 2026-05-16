<?php
/**
 * Emergency Incident Reporting Module
 * Disaster Response & Resource Coordination System — Kabarak University
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only authenticated users can report incidents
if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

// Enable error logging for debugging
error_log("=== Incident Report Submission Started ===");

// ── Constants ────────────────────────────────────────────────────────────────
define('UPLOAD_DIR',    __DIR__ . '/../../temp/uploads/');
define('UPLOAD_URL',    '/temp/uploads/');
define('MAX_FILE_BYTES', 5 * 1024 * 1024);   // 5 MB
define('ALLOWED_MIME',  ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('DUPLICATE_RADIUS_KM', 0.5);
define('DUPLICATE_TIME_WINDOW', 30);

$incident_types = [
    'flood'       => '🌊 Flood',
    'fire'        => '🔥 Fire',
    'earthquake'  => '🏚️ Earthquake',
    'landslide'   => '⛰️ Landslide',
    'drought'     => '☀️ Drought',
    'accident'    => '🚗 Road Accident',
    'building_collapse' => '🏗️ Building Collapse',
    'disease_outbreak'  => '🦠 Disease Outbreak',
    'other'       => '⚠️ Other',
];

$severity_labels = [
    1 => ['label' => 'Low',      'color' => 'success',  'icon' => '🟢'],
    2 => ['label' => 'Medium',   'color' => 'warning',  'icon' => '🟡'],
    3 => ['label' => 'High',     'color' => 'danger',   'icon' => '🟠'],
    4 => ['label' => 'Critical', 'color' => 'dark',     'icon' => '🔴'],
];

// ── POST handler ─────────────────────────────────────────────────────────────
$errors  = [];
$success = false;
$incident_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST request received");
    
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please refresh and try again.';
        error_log("CSRF validation failed");
    } else {
        // Sanitize inputs
        $type        = trim($_POST['type'] ?? '');
        $severity    = (int) ($_POST['severity'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $latitude    = filter_var($_POST['latitude'] ?? '', FILTER_VALIDATE_FLOAT);
        $longitude   = filter_var($_POST['longitude'] ?? '', FILTER_VALIDATE_FLOAT);
        $user_id     = $_SESSION['user_id'] ?? 0;
        $location_name = trim($_POST['location_name'] ?? '');

        error_log("User ID: $user_id, Type: $type, Severity: $severity");
        error_log("Lat: $latitude, Lng: $longitude");

        // Validation
        if (!array_key_exists($type, $incident_types)) {
            $errors[] = 'Please select a valid incident type.';
            error_log("Invalid incident type: $type");
        }
        if (!array_key_exists($severity, $severity_labels)) {
            $errors[] = 'Please select a valid severity level.';
            error_log("Invalid severity: $severity");
        }
        if ($latitude === false || $latitude < -90 || $latitude > 90) {
            $errors[] = 'Invalid latitude. Enable GPS or enter manually.';
            error_log("Invalid latitude: $latitude");
        }
        if ($longitude === false || $longitude < -180 || $longitude > 180) {
            $errors[] = 'Invalid longitude. Enable GPS or enter manually.';
            error_log("Invalid longitude: $longitude");
        }

        // Photo upload (optional)
        $photo_path = null;
        if (!empty($_FILES['photo']['name']) && empty($errors)) {
            $file = $_FILES['photo'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Photo upload failed (error code ' . $file['error'] . ').';
            } elseif ($file['size'] > MAX_FILE_BYTES) {
                $errors[] = 'Photo must be under 5 MB.';
            } else {
                $mime = mime_content_type($file['tmp_name']);
                if (!in_array($mime, ALLOWED_MIME, true)) {
                    $errors[] = 'Only JPEG, PNG, WebP, or GIF photos are accepted.';
                } else {
                    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
                    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = uniqid('inc_', true) . '.' . strtolower($ext);
                    $dest = UPLOAD_DIR . $filename;
                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        $photo_path = UPLOAD_URL . $filename;
                        error_log("Photo uploaded: $photo_path");
                    } else {
                        $errors[] = 'Could not save the photo. Please try again.';
                    }
                }
            }
        }

        // Save to database
        if (empty($errors)) {
            try {
                // First, let's check what columns exist in the incidents table
                $stmt = $pdo->query("DESCRIBE incidents");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                error_log("Available columns: " . implode(', ', $columns));
                
                // Build dynamic INSERT query based on actual columns
                $insertFields = [];
                $insertValues = [];
                $params = [];
                
                // Common columns that should exist
                if (in_array('reporter_id', $columns)) {
                    $insertFields[] = 'reporter_id';
                    $insertValues[] = ':reporter_id';
                    $params[':reporter_id'] = $user_id;
                } elseif (in_array('user_id', $columns)) {
                    $insertFields[] = 'user_id';
                    $insertValues[] = ':user_id';
                    $params[':user_id'] = $user_id;
                }
                
                if (in_array('incident_type', $columns)) {
                    $insertFields[] = 'incident_type';
                    $insertValues[] = ':incident_type';
                    $params[':incident_type'] = $type;
                } elseif (in_array('type', $columns)) {
                    $insertFields[] = 'type';
                    $insertValues[] = ':type';
                    $params[':type'] = $type;
                }
                
                if (in_array('severity', $columns)) {
                    $insertFields[] = 'severity';
                    $insertValues[] = ':severity';
                    $params[':severity'] = $severity;
                }
                
                if (in_array('description', $columns)) {
                    $insertFields[] = 'description';
                    $insertValues[] = ':description';
                    $params[':description'] = $description;
                }
                
                if (in_array('latitude', $columns)) {
                    $insertFields[] = 'latitude';
                    $insertValues[] = ':latitude';
                    $params[':latitude'] = $latitude;
                }
                
                if (in_array('longitude', $columns)) {
                    $insertFields[] = 'longitude';
                    $insertValues[] = ':longitude';
                    $params[':longitude'] = $longitude;
                }
                
                if (in_array('location_name', $columns)) {
                    $insertFields[] = 'location_name';
                    $insertValues[] = ':location_name';
                    $params[':location_name'] = $location_name;
                } elseif (in_array('location', $columns)) {
                    $insertFields[] = 'location';
                    $insertValues[] = ':location';
                    $params[':location'] = $location_name;
                }
                
                if (in_array('photo_path', $columns)) {
                    $insertFields[] = 'photo_path';
                    $insertValues[] = ':photo_path';
                    $params[':photo_path'] = $photo_path;
                }
                
                if (in_array('status', $columns)) {
                    $insertFields[] = 'status';
                    $insertValues[] = ':status';
                    $params[':status'] = 'reported';
                }
                
                if (in_array('reported_at', $columns)) {
                    $insertFields[] = 'reported_at';
                    $insertValues[] = 'NOW()';
                } elseif (in_array('created_at', $columns)) {
                    $insertFields[] = 'created_at';
                    $insertValues[] = 'NOW()';
                }
                
                // Build and execute query
                $sql = "INSERT INTO incidents (" . implode(', ', $insertFields) . ") 
                        VALUES (" . implode(', ', $insertValues) . ")";
                
                error_log("SQL Query: " . $sql);
                error_log("Params: " . json_encode($params));
                
                $stmt = $pdo->prepare($sql);
                
                // Bind parameters
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value);
                }
                
                if ($stmt->execute()) {
                    $incident_id = $pdo->lastInsertId();
                    $success = true;
                    error_log("Incident #{$incident_id} created successfully!");
                } else {
                    $errorInfo = $stmt->errorInfo();
                    error_log("Execute failed: " . print_r($errorInfo, true));
                    $errors[] = 'Database error: ' . $errorInfo[2];
                }
            } catch (PDOException $e) {
                error_log('PDO Exception: ' . $e->getMessage());
                $errors[] = 'A database error occurred. Please try again. Error: ' . $e->getMessage();
            }
        }
    }
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Report Emergency - DisasterResponse</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #e9edf2 100%); min-height: 100vh; }
        .navbar-modern { background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); box-shadow: 0 4px 20px rgba(0,0,0,0.05); padding: 0.75rem 0; }
        .navbar-brand { font-weight: 800; font-size: 1.5rem; background: linear-gradient(135deg, #dc3545, #b91c1c); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .report-hero { background: linear-gradient(135deg, #dc3545 0%, #b91c1c 100%); border-radius: 0 0 30px 30px; padding: 2rem 0; margin-bottom: 2rem; position: relative; overflow: hidden; }
        .report-hero::before { content: ''; position: absolute; top: -50%; right: -10%; width: 300px; height: 300px; background: rgba(255,255,255,0.1); border-radius: 50%; }
        .report-hero::after { content: ''; position: absolute; bottom: -30%; left: -5%; width: 200px; height: 200px; background: rgba(255,255,255,0.08); border-radius: 50%; }
        .form-card { background: white; border-radius: 24px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.08); transition: all 0.2s ease; margin-bottom: 1.5rem; overflow: hidden; }
        .form-card:hover { box-shadow: 0 15px 50px rgba(0,0,0,0.12); }
        .card-header-modern { background: white; border-bottom: 2px solid #f0f0f0; padding: 1.25rem 1.5rem; font-weight: 600; font-size: 1.1rem; }
        .card-header-modern i { color: #dc3545; margin-right: 8px; }
        .type-btn-modern { border-radius: 12px !important; padding: 0.75rem 0.5rem !important; font-weight: 500; transition: all 0.2s ease; }
        .btn-check:checked + .type-btn-modern { background: linear-gradient(135deg, #dc3545, #b91c1c) !important; color: white !important; border-color: transparent !important; transform: scale(1.02); box-shadow: 0 4px 12px rgba(220,53,69,0.3); }
        .severity-btn { border-radius: 12px !important; padding: 0.75rem 0.5rem !important; font-weight: 600; transition: all 0.2s ease; }
        .location-map { border-radius: 16px; overflow: hidden; border: 1px solid #e0e0e0; }
        .submit-btn { background: linear-gradient(135deg, #dc3545, #b91c1c); border: none; padding: 1rem; font-weight: 700; font-size: 1.1rem; border-radius: 16px; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(220,53,69,0.3); }
        .submit-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(220,53,69,0.4); }
        .alert-modern { border-radius: 16px; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .drop-zone { border: 2px dashed #cbd5e1; border-radius: 16px; background: #f8fafc; transition: all 0.2s ease; cursor: pointer; }
        .drop-zone:hover { border-color: #dc3545; background: #fef2f2; }
        .gps-status { border-radius: 12px; padding: 0.75rem 1rem; }
        .manual-entry { background: #fef9e6; border-radius: 12px; padding: 1rem; margin-top: 1rem; }
        .status-timeline { display: flex; justify-content: space-between; margin-top: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 12px; }
        .timeline-step { text-align: center; flex: 1; font-size: 0.7rem; color: #6c757d; }
        .timeline-step.completed { color: #28a745; }
        @media (max-width: 768px) {
            .report-hero { border-radius: 0 0 20px 20px; padding: 1.5rem 0; }
            .type-btn-modern, .severity-btn { font-size: 0.85rem; }
        }
        @keyframes slideIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .form-card { animation: slideIn 0.3s ease-out; }
        @keyframes pulse-subtle { 0%,100% { box-shadow: 0 4px 15px rgba(220,53,69,0.3); } 50% { box-shadow: 0 6px 20px rgba(220,53,69,0.5); } }
        .submit-btn { animation: pulse-subtle 2s infinite; }
        .submit-btn:hover { animation: none; }
    </style>
</head>
<body>

<nav class="navbar navbar-modern sticky-top">
    <div class="container">
        <a class="navbar-brand" href="/disaster_response/index.php">
            <i class="bi bi-shield-check me-2"></i>DisasterResponse
        </a>
        <div class="d-flex gap-2">
            <a href="/disaster_response/modules/incidents/report.php" class="btn btn-outline-secondary btn-sm rounded-pill">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a href="/disaster_response/modules/incidents/status.php" class="btn btn-outline-info btn-sm rounded-pill">
                <i class="bi bi-clock-history me-1"></i>My Reports
            </a>
            <a href="/disaster_response/modules/auth/logout.php" class="btn btn-outline-danger btn-sm rounded-pill">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<div class="report-hero">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1 class="text-white fw-bold mb-2" style="font-size: 2.5rem;">
                    <i class="bi bi-megaphone-fill me-2"></i>Report Emergency
                </h1>
                <p class="text-white-50 mb-0">Your report helps responders reach those in need faster. Every second counts.</p>
            </div>
            <div class="col-md-4 text-end">
                <i class="bi bi-exclamation-triangle-fill text-white" style="font-size: 4rem; opacity: 0.5;"></i>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    
    <div class="status-timeline mb-4">
        <div class="timeline-step completed">1️⃣ Report Submitted</div>
        <div class="timeline-step">2️⃣ Under Review</div>
        <div class="timeline-step">3️⃣ Responders Dispatched</div>
        <div class="timeline-step">4️⃣ Resolved</div>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            
            <div class="alert alert-warning alert-modern mb-4" role="alert">
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-telephone-fill fs-1"></i>
                    <div>
                        <strong class="fs-6">⚠️ Life-threatening emergency?</strong><br>
                        <span class="small">Call <strong class="fs-5">999</strong> or <strong class="fs-5">112</strong> immediately before reporting online.</span>
                    </div>
                </div>
            </div>

            <?php if ($success): ?>
            <div class="alert alert-success alert-modern d-flex gap-3 align-items-start" role="alert">
                <i class="bi bi-check-circle-fill fs-3 text-success"></i>
                <div>
                    <strong class="fs-6">Incident #<?= $incident_id ?> reported successfully!</strong><br>
                    Emergency responders have been notified. You can track this report on your 
                    <a href="/disaster_response/modules/incidents/status.php" class="alert-link">My Reports page</a>.
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-modern" role="alert">
                <i class="bi bi-exclamation-octagon-fill me-2"></i>
                <strong>Please fix the following:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form id="incidentForm" method="POST" action="report.php" enctype="multipart/form-data" novalidate>
                
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="latitude" id="latitude" value="<?= htmlspecialchars($_POST['latitude'] ?? '') ?>">
                <input type="hidden" name="longitude" id="longitude" value="<?= htmlspecialchars($_POST['longitude'] ?? '') ?>">

                <!-- Location Card -->
                <div class="form-card">
                    <div class="card-header-modern">
                        <i class="bi bi-geo-alt-fill"></i> Location
                        <span class="badge bg-danger ms-2">Required</span>
                    </div>
                    <div class="card-body p-4">
                        <div id="gpsStatus" class="gps-status alert alert-secondary mb-3 d-flex align-items-center gap-2">
                            <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
                            <span>Detecting your location…</span>
                        </div>
                        <div id="locationMap" style="height: 250px; border-radius: 16px; display: none;" class="mb-3 location-map"></div>
                        <div class="mb-3">
                            <label class="form-label small text-muted fw-semibold">Location Name</label>
                            <input type="text" name="location_name" id="location_name" class="form-control" 
                                   placeholder="e.g., Mathare, Section 1"
                                   value="<?= htmlspecialchars($_POST['location_name'] ?? '') ?>">
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small text-muted fw-semibold">Latitude</label>
                                <input type="text" id="latDisplay" class="form-control bg-light" readonly placeholder="Waiting for GPS…" style="border-radius: 10px;">
                            </div>
                            <div class="col-6">
                                <label class="form-label small text-muted fw-semibold">Longitude</label>
                                <input type="text" id="lngDisplay" class="form-control bg-light" readonly placeholder="Waiting for GPS…" style="border-radius: 10px;">
                            </div>
                        </div>
                        <button type="button" id="retryGps" class="btn btn-outline-secondary btn-sm mt-3 rounded-pill d-none">
                            <i class="bi bi-arrow-clockwise me-1"></i>Retry GPS
                        </button>
                        <div id="manualEntry" class="manual-entry d-none">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi bi-pencil-square text-warning"></i>
                                <span class="small fw-semibold">GPS unavailable — enter coordinates manually</span>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="number" id="manualLat" class="form-control form-control-sm" placeholder="e.g. -1.2921" step="any" min="-90" max="90" style="border-radius: 10px;">
                                </div>
                                <div class="col-6">
                                    <input type="number" id="manualLng" class="form-control form-control-sm" placeholder="e.g. 36.8219" step="any" min="-180" max="180" style="border-radius: 10px;">
                                </div>
                            </div>
                            <button type="button" id="applyManual" class="btn btn-sm btn-warning mt-2 rounded-pill">Apply Coordinates</button>
                        </div>
                    </div>
                </div>

                <!-- Incident Type Card -->
                <div class="form-card">
                    <div class="card-header-modern">
                        <i class="bi bi-list-ul"></i> Incident Type
                        <span class="badge bg-danger ms-2">Required</span>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-2">
                            <?php foreach ($incident_types as $value => $label): ?>
                            <div class="col-6 col-md-4">
                                <input type="radio" class="btn-check" name="type" id="type_<?= $value ?>" value="<?= $value ?>" <?= (($_POST['type'] ?? '') === $value) ? 'checked' : '' ?> required>
                                <label class="btn btn-outline-secondary w-100 text-start type-btn-modern" for="type_<?= $value ?>">
                                    <?= $label ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Severity Card -->
                <div class="form-card">
                    <div class="card-header-modern">
                        <i class="bi bi-thermometer-half"></i> Severity Level
                        <span class="badge bg-danger ms-2">Required</span>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-2">
                            <?php foreach ($severity_labels as $val => $meta): ?>
                            <div class="col-6 col-md-3">
                                <input type="radio" class="btn-check" name="severity" id="sev_<?= $val ?>" value="<?= $val ?>" <?= (((int)($_POST['severity'] ?? 0)) === $val) ? 'checked' : '' ?> required>
                                <label class="btn btn-outline-<?= $meta['color'] ?> w-100 fw-semibold severity-btn" for="sev_<?= $val ?>">
                                    <?= $meta['icon'] ?> <?= $meta['label'] ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-3 small text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            <strong>Critical</strong> = immediate threat to life &nbsp;|&nbsp;
                            <strong>High</strong> = serious injuries &nbsp;|&nbsp;
                            <strong>Medium</strong> = contained but growing &nbsp;|&nbsp;
                            <strong>Low</strong> = minor issues
                        </div>
                    </div>
                </div>

                <!-- Description Card -->
                <div class="form-card">
                    <div class="card-header-modern">
                        <i class="bi bi-chat-left-text"></i> Description
                    </div>
                    <div class="card-body p-4">
                        <textarea name="description" id="description" rows="5" class="form-control" maxlength="1000" placeholder="Describe what is happening: number of people affected, injuries, road conditions, specific hazards, urgent needs…" style="border-radius: 12px;"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        <div class="d-flex justify-content-end mt-2">
                            <small class="text-muted"><span id="charCount">0</span> / 1000 characters</small>
                        </div>
                    </div>
                </div>

                <!-- Photo Card -->
                <div class="form-card">
                    <div class="card-header-modern">
                        <i class="bi bi-camera-fill"></i> Photo Evidence
                        <span class="badge bg-secondary ms-2">Optional</span>
                    </div>
                    <div class="card-body p-4">
                        <div id="dropZone" class="drop-zone p-4 text-center">
                            <i class="bi bi-cloud-upload fs-1 d-block mb-2 text-muted"></i>
                            <p class="mb-1">Drag & drop a photo here, or <strong class="text-danger">click to browse</strong></p>
                            <small class="text-muted">JPEG, PNG, WebP, GIF — max 5 MB</small>
                            <input type="file" id="photoInput" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" class="d-none">
                        </div>
                        <div id="photoPreview" class="mt-3 d-none text-center">
                            <img id="previewImg" src="" alt="Preview" class="img-fluid rounded shadow-sm" style="max-height: 200px; object-fit: cover;">
                            <br>
                            <button type="button" id="removePhoto" class="btn btn-sm btn-outline-danger mt-2 rounded-pill">
                                <i class="bi bi-x-circle me-1"></i>Remove Photo
                            </button>
                        </div>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" id="submitBtn" class="btn submit-btn text-white">
                        <i class="bi bi-send-fill me-2"></i>Submit Incident Report
                    </button>
                    <a href="/disaster_response/index.php" class="btn btn-outline-secondary rounded-pill">
                        <i class="bi bi-arrow-left me-1"></i>Cancel
                    </a>
                </div>

            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
let previewMap = null;
let previewMarker = null;

function setCoords(lat, lng, label) {
    document.getElementById('latitude').value = lat;
    document.getElementById('longitude').value = lng;
    document.getElementById('latDisplay').value = lat.toFixed(6);
    document.getElementById('lngDisplay').value = lng.toFixed(6);
    const mapEl = document.getElementById('locationMap');
    mapEl.style.display = 'block';
    if (!previewMap) {
        previewMap = L.map('locationMap').setView([lat, lng], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(previewMap);
        previewMarker = L.marker([lat, lng], { draggable: true }).addTo(previewMap);
        previewMarker.bindPopup(label).openPopup();
        previewMarker.on('dragend', function () {
            const pos = previewMarker.getLatLng();
            setCoords(pos.lat, pos.lng, 'Adjusted location');
        });
    } else {
        previewMap.setView([lat, lng], 15);
        previewMarker.setLatLng([lat, lng]).openPopup();
        setTimeout(() => previewMap.invalidateSize(), 100);
    }
}

function gpsSuccess(pos) {
    const lat = pos.coords.latitude;
    const lng = pos.coords.longitude;
    const acc = Math.round(pos.coords.accuracy);
    const status = document.getElementById('gpsStatus');
    status.className = 'gps-status alert alert-success mb-3 d-flex align-items-center gap-2';
    status.innerHTML = `<i class="bi bi-check-circle-fill text-success"></i>
                        <span>Location captured <strong>${lat.toFixed(6)}, ${lng.toFixed(6)}</strong> <small class="text-muted">(±${acc}m accuracy)</small></span>`;
    document.getElementById('retryGps').classList.remove('d-none');
    setCoords(lat, lng, `Your location (±${acc}m)`);
}

function gpsError(err) {
    const status = document.getElementById('gpsStatus');
    status.className = 'gps-status alert alert-warning mb-3 d-flex align-items-center gap-2';
    status.innerHTML = `<i class="bi bi-exclamation-triangle-fill text-warning"></i>
                        <span>GPS unavailable: ${err.message}</span>`;
    document.getElementById('manualEntry').classList.remove('d-none');
    document.getElementById('retryGps').classList.remove('d-none');
}

function requestGPS() {
    if (!navigator.geolocation) {
        gpsError({ message: 'Geolocation not supported by this browser.' });
        return;
    }
    const status = document.getElementById('gpsStatus');
    status.className = 'gps-status alert alert-secondary mb-3 d-flex align-items-center gap-2';
    status.innerHTML = `<div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
                        <span>Detecting your location…</span>`;
    navigator.geolocation.getCurrentPosition(gpsSuccess, gpsError, {
        enableHighAccuracy: true,
        timeout: 10000,
        maximumAge: 0
    });
}

document.getElementById('retryGps').addEventListener('click', requestGPS);
document.getElementById('applyManual').addEventListener('click', function () {
    const lat = parseFloat(document.getElementById('manualLat').value);
    const lng = parseFloat(document.getElementById('manualLng').value);
    if (isNaN(lat) || isNaN(lng)) {
        alert('Please enter valid numeric coordinates.');
        return;
    }
    setCoords(lat, lng, 'Manual coordinates');
    document.getElementById('gpsStatus').className = 'gps-status alert alert-info mb-3';
    document.getElementById('gpsStatus').innerHTML = '<i class="bi bi-pin-map-fill me-2"></i>Using manually entered coordinates.';
});

requestGPS();

const dropZone = document.getElementById('dropZone');
const photoInput = document.getElementById('photoInput');
const preview = document.getElementById('photoPreview');
const previewImg = document.getElementById('previewImg');

function showPreview(file) {
    if (!file || !file.type.startsWith('image/')) return;
    const reader = new FileReader();
    reader.onload = e => {
        previewImg.src = e.target.result;
        preview.classList.remove('d-none');
        dropZone.style.display = 'none';
    };
    reader.readAsDataURL(file);
}

dropZone.addEventListener('click', () => photoInput.click());
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    photoInput.files = e.dataTransfer.files;
    showPreview(e.dataTransfer.files[0]);
});
photoInput.addEventListener('change', () => showPreview(photoInput.files[0]));

document.getElementById('removePhoto').addEventListener('click', function () {
    photoInput.value = '';
    preview.classList.add('d-none');
    dropZone.style.display = '';
});

const desc = document.getElementById('description');
const charCount = document.getElementById('charCount');
desc.addEventListener('input', () => charCount.textContent = desc.value.length);
charCount.textContent = desc.value.length;

document.getElementById('incidentForm').addEventListener('submit', function (e) {
    const lat = document.getElementById('latitude').value;
    const lng = document.getElementById('longitude').value;
    if (!lat || !lng) {
        e.preventDefault();
        alert('Location is required. Please allow GPS access or enter coordinates manually.');
        return;
    }
    if (!document.querySelector('input[name="type"]:checked')) {
        e.preventDefault();
        alert('Please select an incident type.');
        return;
    }
    if (!document.querySelector('input[name="severity"]:checked')) {
        e.preventDefault();
        alert('Please select a severity level.');
        return;
    }
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';
});
</script>
</body>
</html>