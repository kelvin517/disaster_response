<?php

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only logged-in users can request resources
if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

$user_id = $_SESSION['user_id'];
$error = null;
$success = null;

// Resource types with icons
$resource_types = [
    'food' => ['label' => '🍲 Food Supplies', 'unit' => 'meals', 'icon' => 'bi-egg-fried'],
    'water' => ['label' => '💧 Water', 'unit' => 'liters', 'icon' => 'bi-droplet'],
    'medicine' => ['label' => '💊 Medicine', 'unit' => 'doses', 'icon' => 'bi-capsule'],
    'shelter' => ['label' => '🏠 Shelter Materials', 'unit' => 'kits', 'icon' => 'bi-house'],
    'clothing' => ['label' => '👕 Clothing', 'unit' => 'items', 'icon' => 'bi-tshirt'],
    'blankets' => ['label' => '🛏️ Blankets', 'unit' => 'pieces', 'icon' => 'bi-bed'],
    'first_aid' => ['label' => '🩹 First Aid Kits', 'unit' => 'kits', 'icon' => 'bi-suitcase-medical'],
    'transport' => ['label' => '🚛 Transport', 'unit' => 'vehicles', 'icon' => 'bi-truck'],
    'rescue' => ['label' => '🪢 Rescue Equipment', 'unit' => 'sets', 'icon' => 'bi-life-preserver'],
    'other' => ['label' => '📦 Other Supplies', 'unit' => 'units', 'icon' => 'bi-box']
];

// Urgency levels
$urgency_levels = [
    'low' => ['label' => 'Low - Can wait 24-48 hours', 'color' => 'success', 'icon' => 'bi-thermometer-low'],
    'medium' => ['label' => 'Medium - Needed within 12 hours', 'color' => 'warning', 'icon' => 'bi-thermometer-half'],
    'high' => ['label' => 'High - Needed within 6 hours', 'color' => 'danger', 'icon' => 'bi-thermometer-high'],
    'critical' => ['label' => 'Critical - Immediate need', 'color' => 'dark', 'icon' => 'bi-exclamation-triangle']
];

// Get user's incidents for reference
$stmt = $pdo->prepare("
    SELECT id, incident_type, location_name, status 
    FROM incidents 
    WHERE reporter_id = ? AND status NOT IN ('resolved', 'cancelled')
    ORDER BY reported_at DESC 
    LIMIT 10
");
$stmt->execute([$user_id]);
$user_incidents = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $incident_id = !empty($_POST['incident_id']) ? (int)$_POST['incident_id'] : null;
    $resource_type = $_POST['resource_type'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 0);
    $urgency = $_POST['urgency'] ?? 'medium';
    $notes = trim($_POST['notes'] ?? '');
    $delivery_location = trim($_POST['delivery_location'] ?? '');
    
    // Validation
    if (empty($resource_type) || $quantity <= 0) {
        $error = "Please select a resource type and specify quantity.";
    } elseif ($quantity > 10000) {
        $error = "Quantity seems too high. Please contact emergency services directly if you need this amount.";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO resource_requests 
                (user_id, incident_id, resource_type, quantity, urgency, notes, delivery_location, status, requested_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([$user_id, $incident_id, $resource_type, $quantity, $urgency, $notes, $delivery_location]);
            $request_id = $pdo->lastInsertId();
            $success = "Resource request #{$request_id} submitted successfully! Responders have been notified.";
        } catch (PDOException $e) {
            error_log("Resource request failed: " . $e->getMessage());
            $error = "Failed to submit request. Please try again.";
        }
    }
}

$page_title = 'Request Resources';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Resources - DisasterResponse</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
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
        
        .form-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .resource-option {
            padding: 1rem;
            border: 1px solid var(--border);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 0.5rem;
        }
        .resource-option:hover { border-color: var(--red); background: rgba(239,68,68,0.1); }
        .resource-option.selected { border-color: var(--red); background: rgba(239,68,68,0.15); }
        
        @media (max-width: 768px) {
            .resource-option { padding: 0.75rem; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="/disaster_response/index.php">
            <i class="bi bi-shield-check me-1 brand-accent"></i>Disaster<span class="brand-accent">Response</span>
        </a>
        <div class="d-flex gap-2">
            <a href="status.php" class="nav-pill">
                <i class="bi bi-clock-history me-1"></i>My Requests
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
            <i class="bi bi-box-seam-fill me-2" style="color: var(--red);"></i>
            Request Resources
        </h1>
        <p class="text-muted mt-1">Request food, water, medicine, shelter, and other essential supplies</p>
    </div>
</div>

<div class="container pb-5">
    
    <div class="row justify-content-center">
        <div class="col-lg-8">
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= $success ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div class="form-card">
                <form method="POST">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-tag me-1 text-danger"></i>Resource Type
                        </label>
                        <div class="row g-2">
                            <?php foreach ($resource_types as $key => $type): ?>
                            <div class="col-md-6">
                                <div class="resource-option" data-resource="<?= $key ?>" onclick="selectResource('<?= $key ?>')">
                                    <div class="d-flex align-items-center gap-3">
                                        <i class="bi <?= $type['icon'] ?> fs-3" style="color: var(--red);"></i>
                                        <div>
                                            <div class="fw-semibold"><?= $type['label'] ?></div>
                                            <small class="text-muted"><?= $type['unit'] ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="resource_type" id="resource_type" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-calculator me-1 text-danger"></i>Quantity
                            </label>
                            <input type="number" name="quantity" class="form-control bg-dark text-white border-secondary" 
                                   placeholder="Enter quantity" required min="1" max="10000">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-speedometer2 me-1 text-danger"></i>Urgency
                            </label>
                            <select name="urgency" class="form-select bg-dark text-white border-secondary">
                                <?php foreach ($urgency_levels as $key => $level): ?>
                                    <option value="<?= $key ?>" style="color: <?= $level['color'] == 'danger' ? '#f87171' : ($level['color'] == 'warning' ? '#fbbf24' : '#4ade80') ?>">
                                        <?= $level['icon'] ?> <?= $level['label'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-geo-alt me-1 text-danger"></i>Delivery Location
                        </label>
                        <input type="text" name="delivery_location" class="form-control bg-dark text-white border-secondary" 
                               placeholder="e.g., Mathare, Section 1, near the school" required>
                        <small class="text-muted">Be as specific as possible to help responders find you.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-chat-text me-1 text-danger"></i>Additional Notes
                        </label>
                        <textarea name="notes" class="form-control bg-dark text-white border-secondary" rows="3" 
                                  placeholder="Any special requirements, number of people, accessibility issues..."></textarea>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-exclamation-triangle me-1 text-danger"></i>Related Incident (Optional)
                        </label>
                        <select name="incident_id" class="form-select bg-dark text-white border-secondary">
                            <option value="">-- Not related to a specific incident --</option>
                            <?php foreach ($user_incidents as $incident): ?>
                                <option value="<?= $incident['id'] ?>">
                                    #<?= str_pad($incident['id'], 5, '0', STR_PAD_LEFT) ?> - <?= ucfirst($incident['incident_type']) ?> (<?= $incident['location_name'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-danger w-100 py-2 rounded-pill">
                        <i class="bi bi-send me-2"></i>Submit Request
                    </button>
                </form>
            </div>
            
            <div class="text-center text-muted small">
                <i class="bi bi-info-circle me-1"></i>
                For life-threatening emergencies, always call <strong>999</strong> or <strong>112</strong> first.
            </div>
        </div>
    </div>
    
</div>

<script>
function selectResource(resource) {
    document.getElementById('resource_type').value = resource;
    document.querySelectorAll('.resource-option').forEach(opt => {
        opt.classList.remove('selected');
    });
    document.querySelector(`.resource-option[data-resource="${resource}"]`).classList.add('selected');
}
</script>

</body>
</html>