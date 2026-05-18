<?php
/**
 * Assign Volunteer to Task
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows admins and responders to assign volunteers to incident tasks
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admins and responders can assign
role_guard(['admin', 'responder']);

$error = null;
$success = null;

// Get incident ID if provided
$incident_id = isset($_GET['incident_id']) ? (int)$_GET['incident_id'] : 0;

// Fetch available volunteers
$stmt = $pdo->prepare("
    SELECT v.*, u.full_name, u.phone, u.email
    FROM volunteers v
    JOIN users u ON v.user_id = u.id
    WHERE v.availability_status = 'available'
    ORDER BY u.full_name
");
$stmt->execute();
$available_volunteers = $stmt->fetchAll();

// Fetch active incidents for dropdown
$stmt = $pdo->prepare("
    SELECT id, incident_type, location_name, severity, status
    FROM incidents
    WHERE status NOT IN ('resolved', 'cancelled', 'rejected')
    ORDER BY severity DESC, reported_at DESC
");
$stmt->execute();
$incidents = $stmt->fetchAll();

// Handle assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign') {
    $volunteer_id = (int)$_POST['volunteer_id'];
    $incident_id = (int)$_POST['incident_id'];
    $task_description = trim($_POST['task_description']);
    $assigned_by = $_SESSION['user_id'];
    
    if (!$volunteer_id || !$incident_id || !$task_description) {
        $error = "Please fill all required fields.";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO volunteer_tasks (volunteer_id, incident_id, assigned_by, task_description, status, created_at)
                VALUES (?, ?, ?, ?, 'assigned', NOW())
            ");
            $stmt->execute([$volunteer_id, $incident_id, $assigned_by, $task_description]);
            
            // Update volunteer status to busy
            $stmt = $pdo->prepare("UPDATE volunteers SET availability_status = 'busy' WHERE user_id = ?");
            $stmt->execute([$volunteer_id]);
            
            $success = "Task assigned successfully!";
        } catch (PDOException $e) {
            error_log("Assignment failed: " . $e->getMessage());
            $error = "Failed to assign task.";
        }
    }
}

$skill_categories = [
    'medical' => '🩺 Medical', 'rescue' => '🪢 Rescue', 'logistics' => '🚛 Logistics',
    'communication' => '📡 Communication', 'driving' => '🚗 Driving', 'construction' => '🏗️ Construction',
    'catering' => '🍲 Catering', 'administration' => '📋 Admin', 'technical' => '💻 Technical',
    'other' => '📦 Other'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Volunteer Task - DisasterResponse</title>
    
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
        
        .form-card, .volunteer-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 1rem;
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
        
        .volunteer-card {
            cursor: pointer;
            transition: all 0.2s;
        }
        .volunteer-card:hover { border-color: var(--red); transform: translateX(5px); }
        .volunteer-card.selected { border-color: var(--red); background: rgba(239,68,68,0.1); }
        
        .skill-tag {
            display: inline-block;
            background: var(--surface2);
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            margin-right: 0.3rem;
            margin-bottom: 0.3rem;
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="assign.php">
            <i class="bi bi-person-plus-fill me-1 brand-accent"></i>Volunteer<span class="brand-accent">Assign</span>
        </a>
        <div class="d-flex gap-2">
            <a href="reports.php" class="nav-pill">Reports</a>
            <a href="../responders/dashboard.php" class="nav-pill">Dashboard</a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-person-plus-fill me-2" style="color: var(--red);"></i>
            Assign Volunteer Task
        </h1>
        <p class="text-muted mt-1">Match volunteers to incidents based on their skills</p>
    </div>
</div>

<div class="container pb-5">
    <div class="row">
        <!-- Assignment Form -->
        <div class="col-lg-5">
            <div class="form-card">
                <div class="card-header-custom">
                    <i class="bi bi-pencil-square me-2"></i>New Assignment
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="assign">
                        <input type="hidden" name="volunteer_id" id="selected_volunteer_id" required>
                        
                        <div class="mb-3">
                            <label class="form-label">Select Incident <span class="text-danger">*</span></label>
                            <select name="incident_id" class="form-select bg-dark text-white border-secondary" required>
                                <option value="">-- Select Incident --</option>
                                <?php foreach ($incidents as $incident): ?>
                                    <option value="<?= $incident['id'] ?>" <?= $incident_id == $incident['id'] ? 'selected' : '' ?>>
                                        #<?= str_pad($incident['id'], 5, '0', STR_PAD_LEFT) ?> - <?= ucfirst($incident['incident_type']) ?> - <?= htmlspecialchars($incident['location_name'] ?? 'Unknown') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Task Description <span class="text-danger">*</span></label>
                            <textarea name="task_description" class="form-control bg-dark text-white border-secondary" rows="3" required placeholder="Describe what the volunteer needs to do..."></textarea>
                        </div>
                        
                        <div class="alert alert-info small">
                            <i class="bi bi-info-circle me-1"></i>
                            Selected volunteer will be marked as "Busy" after assignment.
                        </div>
                        
                        <button type="submit" class="btn btn-danger w-100">
                            <i class="bi bi-send me-2"></i>Assign Task
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Available Volunteers List -->
        <div class="col-lg-7">
            <div class="form-card">
                <div class="card-header-custom">
                    <i class="bi bi-people me-2"></i>Available Volunteers
                    <span class="badge bg-success"><?= count($available_volunteers) ?> available</span>
                </div>
                <div class="p-3">
                    <?php if (count($available_volunteers) > 0): ?>
                        <?php foreach ($available_volunteers as $volunteer): 
                            $skills = explode(', ', $volunteer['skills']);
                        ?>
                            <div class="volunteer-card p-3" onclick="selectVolunteer(<?= $volunteer['user_id'] ?>, '<?= htmlspecialchars($volunteer['full_name']) ?>')" data-id="<?= $volunteer['user_id'] ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold">
                                            <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($volunteer['full_name']) ?>
                                        </div>
                                        <div class="small text-muted">
                                            <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($volunteer['phone'] ?? 'No phone') ?>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-success">Available</span>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <div class="small text-muted mb-1">Skills:</div>
                                    <?php foreach ($skills as $skill): ?>
                                        <span class="skill-tag"><?= $skill ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($volunteer['latitude'] && $volunteer['longitude']): ?>
                                    <div class="mt-2 small text-muted">
                                        <i class="bi bi-geo-alt me-1"></i>Location shared
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-people fs-1 d-block mb-2"></i>
                            <p>No available volunteers at the moment.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function selectVolunteer(id, name) {
    document.getElementById('selected_volunteer_id').value = id;
    
    // Highlight selected volunteer
    document.querySelectorAll('.volunteer-card').forEach(card => {
        card.classList.remove('selected');
        if (card.dataset.id == id) {
            card.classList.add('selected');
        }
    });
    
    // Show feedback
    const feedback = document.createElement('div');
    feedback.className = 'alert alert-success mt-2 small';
    feedback.innerHTML = `<i class="bi bi-check-circle me-1"></i>Selected: ${name}`;
    
    const existing = document.querySelector('.volunteer-selection-feedback');
    if (existing) existing.remove();
    feedback.classList.add('volunteer-selection-feedback');
    document.querySelector('.form-card .card-body').appendChild(feedback);
    setTimeout(() => feedback.remove(), 3000);
}
</script>
</body>
</html>