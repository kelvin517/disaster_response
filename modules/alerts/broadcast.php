<?php
/**
 * Broadcast Alert Module
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows administrators to broadcast emergency alerts by county, radius, or to all users
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admin and responders can broadcast alerts
role_guard(['admin', 'responder']);

$error = null;
$success = null;

// Kenyan counties list
$kenyan_counties = [
    'Nairobi', 'Mombasa', 'Kisumu', 'Nakuru', 'Kiambu', 'Machakos', 'Kajiado', 'Kisii',
    'Kakamega', 'Bungoma', 'Busia', 'Trans Nzoia', 'Uasin Gishu', 'Elgeyo Marakwet',
    'Nandi', 'Baringo', 'Laikipia', 'Samburu', 'Turkana', 'Marsabit', 'Isiolo', 'Meru',
    'Tharaka Nithi', 'Embu', 'Kitui', 'Makueni', 'Nyandarua', 'Nyeri', 'Kirinyaga',
    'Murang\'a', 'Kilifi', 'Kwale', 'Tana River', 'Lamu', 'Taita Taveta', 'Garissa',
    'Wajir', 'Mandera', 'Siaya', 'Homa Bay', 'Migori', 'Nyamira', 'Kericho', 'Bomet',
    'Vihiga', 'West Pokot'
];

// Alert priority levels
$priority_levels = [
    'info' => ['label' => 'ℹ️ Informational', 'color' => '#3b82f6', 'icon' => 'bi-info-circle', 'sms' => false],
    'warning' => ['label' => '⚠️ Warning', 'color' => '#f59e0b', 'icon' => 'bi-exclamation-triangle', 'sms' => true],
    'urgent' => ['label' => '🔴 Urgent', 'color' => '#ef4444', 'icon' => 'bi-exclamation-octagon', 'sms' => true],
    'emergency' => ['label' => '🚨 Emergency', 'color' => '#dc2626', 'icon' => 'bi-megaphone-fill', 'sms' => true]
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'broadcast') {
    $title = trim($_POST['title']);
    $message = trim($_POST['message']);
    $priority = $_POST['priority'];
    $target_type = $_POST['target_type'];
    $target_county = $_POST['target_county'] ?? '';
    $target_radius_lat = (float)($_POST['target_radius_lat'] ?? 0);
    $target_radius_lng = (float)($_POST['target_radius_lng'] ?? 0);
    $target_radius_km = (float)($_POST['target_radius_km'] ?? 10);
    $send_sms = isset($_POST['send_sms']) && $priority_levels[$priority]['sms'];
    
    if (empty($title) || empty($message)) {
        $error = "Please enter both title and message.";
    } elseif (!array_key_exists($priority, $priority_levels)) {
        $error = "Invalid priority level.";
    } else {
        try {
            // Build target area description
            $target_area = '';
            if ($target_type === 'all') {
                $target_area = 'All Users';
            } elseif ($target_type === 'county') {
                $target_area = "County: $target_county";
            } elseif ($target_type === 'radius') {
                $target_area = "Radius: {$target_radius_km}km around ({$target_radius_lat}, {$target_radius_lng})";
            }
            
            // Insert alert into database
            $stmt = $pdo->prepare("
                INSERT INTO alerts (alert_type, title, message, priority, target_area, target_type, 
                                   target_county, target_latitude, target_longitude, target_radius_km,
                                   send_sms, created_by, created_at, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))
            ");
            $stmt->execute([
                $priority, $title, $message, $priority, $target_area, $target_type,
                $target_county, $target_radius_lat, $target_radius_lng, $target_radius_km,
                $send_sms, $_SESSION['user_id']
            ]);
            $alert_id = $pdo->lastInsertId();
            
            // Queue SMS if enabled
            if ($send_sms) {
                // Fetch users based on target criteria
                $user_query = "SELECT id, phone, full_name FROM users WHERE phone IS NOT NULL AND phone != '' AND is_active = 1";
                $user_params = [];
                
                if ($target_type === 'county' && $target_county) {
                    $user_query .= " AND county = ?";
                    $user_params[] = $target_county;
                } elseif ($target_type === 'radius' && $target_radius_lat && $target_radius_lng) {
                    // For radius, we'll handle in the loop with distance calculation
                    // This is a simplified version - in production, use spatial queries
                }
                
                $stmt = $pdo->prepare($user_query);
                $stmt->execute($user_params);
                $users = $stmt->fetchAll();
                
                // Queue SMS for each user
                $sms_sent = 0;
                foreach ($users as $user) {
                    // For radius filtering, check distance
                    if ($target_type === 'radius' && $target_radius_lat && $target_radius_lng) {
                        // Simple distance check - in production use proper geolocation
                        // For now, we'll skip radius filtering or implement basic check if user has coordinates
                        continue;
                    }
                    
                    $sms_message = "[{$priority_levels[$priority]['label']}] $title\n\n$message\n\nReply STOP to unsubscribe";
                    $sms_message = substr($sms_message, 0, 160); // SMS length limit
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO sms_queue (alert_id, recipient_phone, recipient_name, message, status, created_at)
                        VALUES (?, ?, ?, ?, 'pending', NOW())
                    ");
                    $stmt->execute([$alert_id, $user['phone'], $user['full_name'], $sms_message]);
                    $sms_sent++;
                }
                
                $success = "Alert broadcast successfully! $sms_sent SMS messages queued for delivery.";
            } else {
                $success = "Alert broadcast successfully! (SMS disabled for this priority level)";
            }
            
            // Log the action
            $stmt = $pdo->prepare("
                INSERT INTO system_logs (user_id, action, ip_address, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$_SESSION['user_id'], "Broadcasted alert: $title", $_SERVER['REMOTE_ADDR']]);
            
        } catch (PDOException $e) {
            error_log("Broadcast failed: " . $e->getMessage());
            $error = "Failed to broadcast alert. Please try again.";
        }
    }
}

// Get recent alerts for preview
$stmt = $pdo->prepare("
    SELECT a.*, u.full_name as creator_name
    FROM alerts a
    JOIN users u ON a.created_by = u.id
    ORDER BY a.created_at DESC
    LIMIT 5
");
$stmt->execute();
$recent_alerts = $stmt->fetchAll();

$page_title = 'Broadcast Alert';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Broadcast Alert - DisasterResponse</title>
    
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
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); }
        
        .navbar-modern {
            background: rgba(15,23,42,0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 0;
        }
        .navbar-brand .brand-accent { color: var(--red); }
        
        .page-header {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border-bottom: 1px solid var(--border);
            padding: 1.5rem 0;
            margin-bottom: 1.5rem;
        }
        
        .form-card, .alert-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .card-header-custom {
            background: var(--surface2);
            border-bottom: 1px solid var(--border);
            padding: 0.85rem 1.25rem;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.85rem;
        }
        
        .priority-option {
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
        }
        .priority-option:hover { border-color: var(--red); background: rgba(239,68,68,0.1); }
        .priority-option.selected { border-color: var(--red); background: rgba(239,68,68,0.15); }
        
        #map { height: 300px; border-radius: 12px; margin-top: 0.5rem; }
        
        .alert-preview {
            background: var(--surface2);
            border-radius: 12px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        @media (max-width: 768px) {
            .priority-option { font-size: 0.8rem; padding: 0.75rem; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="broadcast.php">
            <i class="bi bi-megaphone-fill me-1 brand-accent"></i>Alert<span class="brand-accent">System</span>
        </a>
        <div class="d-flex gap-2">
            <a href="history.php" class="nav-pill">History</a>
            <a href="queue.php" class="nav-pill">SMS Queue</a>
            <a href="../admin/admin_dashboard.php" class="nav-pill">Dashboard</a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-megaphone-fill me-2" style="color: var(--red);"></i>
            Broadcast Emergency Alert
        </h1>
        <p class="text-muted mt-1">Send alerts to affected communities by county, radius, or to all users</p>
    </div>
</div>

<div class="container pb-5">
    <div class="row">
        <!-- Broadcast Form -->
        <div class="col-lg-7">
            <div class="form-card">
                <div class="card-header-custom">
                    <i class="bi bi-pencil-square me-2"></i>New Alert
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" id="alertForm">
                        <input type="hidden" name="action" value="broadcast">
                        
                        <!-- Alert Title -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Alert Title</label>
                            <input type="text" name="title" class="form-control bg-dark text-white border-secondary" required placeholder="e.g., Flash Flood Warning - Mathare Area">
                        </div>
                        
                        <!-- Priority Level -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Priority Level</label>
                            <div class="row g-2">
                                <?php foreach ($priority_levels as $key => $level): ?>
                                    <div class="col-md-3 col-6">
                                        <div class="priority-option" data-priority="<?= $key ?>" style="border-color: <?= $level['color'] ?>40;">
                                            <i class="bi <?= $level['icon'] ?> fs-2" style="color: <?= $level['color'] ?>"></i>
                                            <div class="fw-semibold small mt-1"><?= $level['label'] ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="priority" id="selected_priority" required>
                        </div>
                        
                        <!-- Target Audience -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Target Audience</label>
                            <div class="mb-2">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="target_type" id="target_all" value="all" checked>
                                    <label class="form-check-label">All Users</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="target_type" id="target_county" value="county">
                                    <label class="form-check-label">Specific County</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="target_type" id="target_radius" value="radius">
                                    <label class="form-check-label">Geographic Radius</label>
                                </div>
                            </div>
                            
                            <!-- County Selection -->
                            <div id="county_select" style="display: none;">
                                <select name="target_county" class="form-select bg-dark text-white border-secondary">
                                    <option value="">Select County</option>
                                    <?php foreach ($kenyan_counties as $county): ?>
                                        <option value="<?= $county ?>"><?= $county ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Radius Selection -->
                            <div id="radius_select" style="display: none;">
                                <div class="row">
                                    <div class="col-6">
                                        <label class="small text-muted">Latitude</label>
                                        <input type="text" name="target_radius_lat" id="radius_lat" class="form-control bg-dark text-white border-secondary" placeholder="-1.2921">
                                    </div>
                                    <div class="col-6">
                                        <label class="small text-muted">Longitude</label>
                                        <input type="text" name="target_radius_lng" id="radius_lng" class="form-control bg-dark text-white border-secondary" placeholder="36.8219">
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <label class="small text-muted">Radius (km)</label>
                                    <input type="number" name="target_radius_km" class="form-control bg-dark text-white border-secondary" value="10" min="1" max="500">
                                </div>
                                <div id="map"></div>
                                <small class="text-muted">Click on map to set coordinates</small>
                            </div>
                        </div>
                        
                        <!-- Alert Message -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Alert Message</label>
                            <textarea name="message" class="form-control bg-dark text-white border-secondary" rows="4" required placeholder="Describe the emergency, affected areas, and recommended actions..."></textarea>
                            <div class="text-end small text-muted mt-1">
                                <span id="charCount">0</span> characters
                            </div>
                        </div>
                        
                        <!-- SMS Option (shown based on priority) -->
                        <div id="sms_option" class="mb-3 alert alert-info" style="display: none;">
                            <i class="bi bi-chat-dots me-2"></i>
                            <span id="sms_message">SMS will be sent to affected users for this priority level.</span>
                        </div>
                        
                        <button type="submit" class="btn btn-danger w-100 py-2">
                            <i class="bi bi-megaphone me-2"></i>Broadcast Alert
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Recent Alerts -->
        <div class="col-lg-5">
            <div class="form-card">
                <div class="card-header-custom">
                    <i class="bi bi-clock-history me-2"></i>Recent Alerts
                    <a href="history.php" class="small text-danger">View All →</a>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (count($recent_alerts) > 0): ?>
                        <?php foreach ($recent_alerts as $alert): 
                            $priority_color = $priority_levels[$alert['priority']]['color'] ?? '#6c757d';
                        ?>
                            <div class="p-3 border-bottom border-secondary">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <span class="badge" style="background: <?= $priority_color ?>20; color: <?= $priority_color ?>;">
                                            <?= ucfirst($alert['priority']) ?>
                                        </span>
                                        <div class="fw-semibold mt-1"><?= htmlspecialchars($alert['title']) ?></div>
                                    </div>
                                    <small class="text-muted"><?= date('M j, H:i', strtotime($alert['created_at'])) ?></small>
                                </div>
                                <p class="small text-muted mt-1"><?= htmlspecialchars(substr($alert['message'], 0, 80)) ?>...</p>
                                <div class="small text-muted">
                                    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($alert['creator_name']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-megaphone fs-1 d-block mb-2"></i>
                            <p>No alerts sent yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Alert Preview -->
            <div class="form-card">
                <div class="card-header-custom">
                    <i class="bi bi-eye me-2"></i>Live Preview
                </div>
                <div class="card-body p-3">
                    <div id="preview" class="alert-preview">
                        <div class="text-muted small text-center">Fill in the form to see preview</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// Priority selection
document.querySelectorAll('.priority-option').forEach(opt => {
    opt.addEventListener('click', function() {
        document.querySelectorAll('.priority-option').forEach(o => o.classList.remove('selected'));
        this.classList.add('selected');
        document.getElementById('selected_priority').value = this.dataset.priority;
        
        // Show/hide SMS option
        const priority = this.dataset.priority;
        const smsDiv = document.getElementById('sms_option');
        const smsEnabled = (priority === 'urgent' || priority === 'emergency');
        if (smsEnabled) {
            smsDiv.style.display = 'block';
            document.getElementById('sms_message').innerHTML = '📱 SMS will be sent to affected users for this priority level.';
        } else if (priority === 'warning') {
            smsDiv.style.display = 'block';
            document.getElementById('sms_message').innerHTML = '⚠️ SMS will be sent for this warning level.';
        } else {
            smsDiv.style.display = 'none';
        }
        
        updatePreview();
    });
});

// Target type toggles
document.querySelectorAll('input[name="target_type"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.getElementById('county_select').style.display = this.value === 'county' ? 'block' : 'none';
        document.getElementById('radius_select').style.display = this.value === 'radius' ? 'block' : 'none';
        updatePreview();
    });
});

// Live preview
const titleInput = document.querySelector('input[name="title"]');
const messageInput = document.querySelector('textarea[name="message"]');

function updatePreview() {
    const title = titleInput?.value || 'Alert Title';
    const message = messageInput?.value || 'Alert message will appear here.';
    const priority = document.getElementById('selected_priority')?.value || 'info';
    const priorityData = {
        info: { color: '#3b82f6', icon: 'bi-info-circle', label: 'INFORMATIONAL' },
        warning: { color: '#f59e0b', icon: 'bi-exclamation-triangle', label: 'WARNING' },
        urgent: { color: '#ef4444', icon: 'bi-exclamation-octagon', label: 'URGENT' },
        emergency: { color: '#dc2626', icon: 'bi-megaphone-fill', label: 'EMERGENCY' }
    };
    const p = priorityData[priority] || priorityData.info;
    
    document.getElementById('preview').innerHTML = `
        <div style="border-left: 4px solid ${p.color};" class="ps-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi ${p.icon}" style="color: ${p.color}"></i>
                <span class="badge" style="background: ${p.color}20; color: ${p.color};">${p.label}</span>
            </div>
            <div class="fw-bold">${escapeHtml(title)}</div>
            <div class="small mt-1">${escapeHtml(message)}</div>
            <div class="small text-muted mt-2">This alert will be sent immediately</div>
        </div>
    `;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

titleInput?.addEventListener('input', updatePreview);
messageInput?.addEventListener('input', updatePreview);

// Character counter
messageInput?.addEventListener('input', function() {
    document.getElementById('charCount').textContent = this.value.length;
});

// Map for radius selection
let map = null;
let marker = null;

document.getElementById('target_radius').addEventListener('change', function() {
    if (this.checked && !map) {
        map = L.map('map').setView([-1.2921, 36.8219], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        
        map.on('click', function(e) {
            if (marker) map.removeLayer(marker);
            marker = L.marker(e.latlng).addTo(map);
            document.getElementById('radius_lat').value = e.latlng.lat.toFixed(6);
            document.getElementById('radius_lng').value = e.latlng.lng.toFixed(6);
        });
    }
    setTimeout(() => { if (map) map.invalidateSize(); }, 100);
});
</script>
</body>
</html>