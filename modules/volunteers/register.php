<?php
/**
 * Volunteer Registration Module
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows volunteers to register their skills, availability, and location
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only logged-in users with volunteer role can access
if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

if (!hasRole(['volunteer', 'admin'])) {
    redirect('index.php');
}

$user_id = $_SESSION['user_id'];
$error = null;
$success = null;

// Check if volunteer already has a profile
$stmt = $pdo->prepare("SELECT * FROM volunteers WHERE user_id = ?");
$stmt->execute([$user_id]);
$existing_profile = $stmt->fetch();

// Skill options
$skill_options = [
    'medical' => '🩺 Medical (First Aid, Nursing, Doctor)',
    'rescue' => '🪢 Search & Rescue',
    'logistics' => '🚚 Logistics & Supply Chain',
    'communication' => '📡 Communication & Radio',
    'counseling' => '🤝 Psychological First Aid',
    'driving' => '🚗 Emergency Driving',
    'construction' => '🏗️ Construction & Debris Removal',
    'catering' => '🍲 Food Preparation & Distribution',
    'administration' => '📋 Administration & Coordination',
    'translation' => '🗣️ Translation Services',
    'other' => '📌 Other Skills'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'register_volunteer') {
        $skills = isset($_POST['skills']) ? implode(',', $_POST['skills']) : '';
        $availability_status = $_POST['availability_status'] ?? 'available';
        $latitude = (float)($_POST['latitude'] ?? 0);
        $longitude = (float)($_POST['longitude'] ?? 0);
        $experience_years = (int)($_POST['experience_years'] ?? 0);
        $certifications = trim($_POST['certifications'] ?? '');
        $phone_emergency = trim($_POST['phone_emergency'] ?? '');
        
        if (empty($skills)) {
            $error = "Please select at least one skill.";
        } else {
            try {
                if ($existing_profile) {
                    // Update existing profile
                    $stmt = $pdo->prepare("
                        UPDATE volunteers 
                        SET skills = ?, availability_status = ?, latitude = ?, longitude = ?,
                            experience_years = ?, certifications = ?, phone_emergency = ?, updated_at = NOW()
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$skills, $availability_status, $latitude, $longitude, 
                                   $experience_years, $certifications, $phone_emergency, $user_id]);
                    $success = "Your volunteer profile has been updated successfully!";
                } else {
                    // Create new profile
                    $stmt = $pdo->prepare("
                        INSERT INTO volunteers (user_id, skills, availability_status, latitude, longitude,
                                               experience_years, certifications, phone_emergency, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$user_id, $skills, $availability_status, $latitude, $longitude,
                                   $experience_years, $certifications, $phone_emergency]);
                    $success = "You have successfully registered as a volunteer!";
                }
                
                // Refresh profile data
                $stmt = $pdo->prepare("SELECT * FROM volunteers WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $existing_profile = $stmt->fetch();
                
            } catch (PDOException $e) {
                error_log("Volunteer registration failed: " . $e->getMessage());
                $error = "Failed to save profile. Please try again.";
            }
        }
    }
}

// Get current profile data for form pre-fill
$skills_array = $existing_profile ? explode(',', $existing_profile['skills']) : [];
$availability_status = $existing_profile['availability_status'] ?? 'available';
$experience_years = $existing_profile['experience_years'] ?? 0;
$certifications = $existing_profile['certifications'] ?? '';
$phone_emergency = $existing_profile['phone_emergency'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Registration - DisasterResponse</title>
    
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
        .navbar-brand {
            font-weight: 800;
            font-size: 1.25rem;
            color: var(--text) !important;
            text-decoration: none;
        }
        .navbar-brand .brand-accent { color: var(--red); }
        
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
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .card-header-custom {
            background: var(--surface2);
            border-bottom: 1px solid var(--border);
            padding: 0.85rem 1.25rem;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        
        .skill-option {
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 0.6rem;
            text-align: center;
            margin-bottom: 0.5rem;
        }
        .skill-option:hover { border-color: var(--red); background: rgba(239,68,68,0.1); }
        .skill-option.selected { border-color: var(--red); background: rgba(239,68,68,0.15); }
        
        #map { height: 250px; border-radius: 12px; margin-top: 0.5rem; }
        
        .status-badge-available { background: var(--green); color: white; }
        .status-badge-busy { background: var(--amber); color: #333; }
        .status-badge-unavailable { background: var(--muted); color: white; }
        
        @media (max-width: 768px) { .skill-option { font-size: 0.8rem; padding: 0.4rem; } }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="my_tasks.php">
            <i class="bi bi-heart-hand me-1 brand-accent"></i>Disaster<span class="brand-accent">Response</span>
            <span class="badge bg-success ms-2" style="font-size: 0.6rem;">VOLUNTEER</span>
        </a>
        <div class="d-flex gap-2">
            <a href="my_tasks.php" class="nav-pill">My Tasks</a>
            <a href="register.php" class="nav-pill active">Profile</a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-person-badge me-2" style="color: var(--red);"></i>
            Volunteer Registration
        </h1>
        <p class="text-muted mt-1">Register your skills and availability to help during emergencies</p>
    </div>
</div>

<div class="container pb-5">
    <div class="row">
        <!-- Registration Form -->
        <div class="col-lg-7">
            <div class="form-card">
                <div class="card-header-custom">
                    <i class="bi bi-pencil-square me-2" style="color: var(--red);"></i>
                    <?= $existing_profile ? 'Update Your Profile' : 'Register as a Volunteer' ?>
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" id="volunteerForm">
                        <input type="hidden" name="action" value="register_volunteer">
                        <input type="hidden" name="latitude" id="latitude" value="<?= $existing_profile['latitude'] ?? '' ?>">
                        <input type="hidden" name="longitude" id="longitude" value="<?= $existing_profile['longitude'] ?? '' ?>">
                        
                        <!-- Skills Selection -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Skills & Expertise <span class="text-danger">*</span></label>
                            <div class="row g-2">
                                <?php foreach ($skill_options as $key => $label): ?>
                                <div class="col-6 col-md-4">
                                    <div class="skill-option <?= in_array($key, $skills_array) ? 'selected' : '' ?>" data-skill="<?= $key ?>">
                                        <?= $label ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div id="selectedSkills" class="mt-2"></div>
                            <input type="hidden" name="skills" id="skillsInput" value="<?= htmlspecialchars(implode(',', $skills_array)) ?>">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Availability Status</label>
                                <select name="availability_status" class="form-select bg-dark text-white border-secondary">
                                    <option value="available" <?= $availability_status == 'available' ? 'selected' : '' ?>>🟢 Available - Ready for assignments</option>
                                    <option value="busy" <?= $availability_status == 'busy' ? 'selected' : '' ?>>🟡 Busy - Currently occupied</option>
                                    <option value="unavailable" <?= $availability_status == 'unavailable' ? 'selected' : '' ?>>⚪ Unavailable - Not available</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Years of Experience</label>
                                <select name="experience_years" class="form-select bg-dark text-white border-secondary">
                                    <option value="0" <?= $experience_years == 0 ? 'selected' : '' ?>>Less than 1 year</option>
                                    <option value="1" <?= $experience_years == 1 ? 'selected' : '' ?>>1-2 years</option>
                                    <option value="3" <?= $experience_years == 3 ? 'selected' : '' ?>>3-5 years</option>
                                    <option value="6" <?= $experience_years == 6 ? 'selected' : '' ?>>5-10 years</option>
                                    <option value="10" <?= $experience_years == 10 ? 'selected' : '' ?>>10+ years</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Certifications & Training</label>
                            <textarea name="certifications" class="form-control bg-dark text-white border-secondary" rows="2" 
                                      placeholder="e.g., First Aid Certified, CPR Training, Rescue Certified..."><?= htmlspecialchars($certifications) ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Emergency Contact Phone</label>
                            <input type="tel" name="phone_emergency" class="form-control bg-dark text-white border-secondary" 
                                   placeholder="Emergency contact number" value="<?= htmlspecialchars($phone_emergency) ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Your Location (Click on map to set)</label>
                            <div id="map"></div>
                            <div class="row mt-2">
                                <div class="col-6">
                                    <input type="text" id="latDisplay" class="form-control form-control-sm bg-dark text-white border-secondary" 
                                           readonly placeholder="Latitude" value="<?= $existing_profile['latitude'] ?? '' ?>">
                                </div>
                                <div class="col-6">
                                    <input type="text" id="lngDisplay" class="form-control form-control-sm bg-dark text-white border-secondary" 
                                           readonly placeholder="Longitude" value="<?= $existing_profile['longitude'] ?? '' ?>">
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-danger w-100 py-2">
                            <i class="bi bi-save me-2"></i><?= $existing_profile ? 'Update Profile' : 'Register as Volunteer' ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Info Sidebar -->
        <div class="col-lg-5">
            <div class="form-card">
                <div class="card-header-custom">
                    <i class="bi bi-info-circle me-2"></i>Why Volunteer?
                </div>
                <div class="card-body p-4">
                    <ul class="list-unstyled">
                        <li class="mb-3"><i class="bi bi-check-circle-fill text-success me-2"></i> Make a difference in your community</li>
                        <li class="mb-3"><i class="bi bi-check-circle-fill text-success me-2"></i> Gain valuable emergency response experience</li>
                        <li class="mb-3"><i class="bi bi-check-circle-fill text-success me-2"></i> Connect with other dedicated volunteers</li>
                        <li class="mb-3"><i class="bi bi-check-circle-fill text-success me-2"></i> Receive training and certification opportunities</li>
                        <li class="mb-3"><i class="bi bi-check-circle-fill text-success me-2"></i> Be part of the official response team</li>
                    </ul>
                    <hr class="border-secondary">
                    <div class="mt-3">
                        <strong>Your Role:</strong>
                        <p class="small text-muted mt-2">As a registered volunteer, you may be called upon to assist with search and rescue, first aid, logistics, shelter management, and other critical tasks during emergencies.</p>
                    </div>
                </div>
            </div>
            
            <!-- Status Guide -->
            <div class="form-card">
                <div class="card-header-custom">
                    <i class="bi bi-question-circle me-2"></i>Availability Guide
                </div>
                <div class="card-body p-3">
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge status-badge-available me-2">🟢 Available</span>
                        <span class="small text-muted">Ready to accept assignments</span>
                    </div>
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge status-badge-busy me-2">🟡 Busy</span>
                        <span class="small text-muted">Currently on a task</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="badge status-badge-unavailable me-2">⚪ Unavailable</span>
                        <span class="small text-muted">Not available for new tasks</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// Map initialization
let map = L.map('map').setView([-1.2921, 36.8219], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

let marker = null;
<?php if ($existing_profile && $existing_profile['latitude'] && $existing_profile['longitude']): ?>
    marker = L.marker([<?= $existing_profile['latitude'] ?>, <?= $existing_profile['longitude'] ?>]).addTo(map);
    map.setView([<?= $existing_profile['latitude'] ?>, <?= $existing_profile['longitude'] ?>], 13);
<?php endif; ?>

map.on('click', function(e) {
    if (marker) map.removeLayer(marker);
    marker = L.marker(e.latlng).addTo(map);
    document.getElementById('latitude').value = e.latlng.lat;
    document.getElementById('longitude').value = e.latlng.lng;
    document.getElementById('latDisplay').value = e.latlng.lat.toFixed(6);
    document.getElementById('lngDisplay').value = e.latlng.lng.toFixed(6);
});

// Skill selection
let selectedSkills = <?= json_encode($skills_array) ?>;
const skillMap = <?= json_encode($skill_options) ?>;

function updateSkillsUI() {
    document.querySelectorAll('.skill-option').forEach(opt => {
        const skill = opt.dataset.skill;
        if (selectedSkills.includes(skill)) {
            opt.classList.add('selected');
        } else {
            opt.classList.remove('selected');
        }
    });
    document.getElementById('skillsInput').value = selectedSkills.join(',');
}

document.querySelectorAll('.skill-option').forEach(opt => {
    opt.addEventListener('click', function() {
        const skill = this.dataset.skill;
        if (selectedSkills.includes(skill)) {
            selectedSkills = selectedSkills.filter(s => s !== skill);
        } else {
            selectedSkills.push(skill);
        }
        updateSkillsUI();
    });
});
</script>
</body>
</html>