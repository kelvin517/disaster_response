<?php
/**
 * Volunteer Task Progress
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows volunteers to update task progress and mark steps as complete
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only volunteers can access
if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

if (!hasRole(['volunteer', 'admin'])) {
    redirect('index.php');
}

$user_id = $_SESSION['user_id'];
$task_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = null;
$success = null;

// Fetch task details
$stmt = $pdo->prepare("
    SELECT vt.*, i.incident_type, i.severity, i.location_name, i.description as incident_desc,
           i.latitude, i.longitude, u.full_name as assigned_by_name
    FROM volunteer_tasks vt
    JOIN incidents i ON vt.incident_id = i.id
    JOIN users u ON vt.assigned_by = u.id
    WHERE vt.id = ? AND vt.volunteer_id = ?
");
$stmt->execute([$task_id, $user_id]);
$task = $stmt->fetch();

if (!$task) {
    redirect('my_tasks.php');
}

// Handle progress update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_progress') {
        $progress_step = $_POST['progress_step'];
        $update_notes = trim($_POST['update_notes']);
        
        // Log progress update
        $stmt = $pdo->prepare("
            INSERT INTO task_progress (task_id, progress_step, notes, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$task_id, $progress_step, $update_notes]);
        
        $success = "Progress updated successfully!";
    }
    
    if ($_POST['action'] === 'mark_complete') {
        $completion_notes = trim($_POST['completion_notes']);
        
        $stmt = $pdo->prepare("
            UPDATE volunteer_tasks 
            SET status = 'completed', 
                completion_notes = ?, 
                completed_at = NOW() 
            WHERE id = ? AND volunteer_id = ?
        ");
        $stmt->execute([$completion_notes, $task_id, $user_id]);
        
        // Update volunteer availability back to available
        $stmt = $pdo->prepare("UPDATE volunteers SET availability_status = 'available' WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        $success = "Task marked as complete! Thank you for your service.";
        redirect('my_tasks.php');
    }
}

// Fetch progress history
$stmt = $pdo->prepare("
    SELECT * FROM task_progress 
    WHERE task_id = ? 
    ORDER BY created_at ASC
");
$stmt->execute([$task_id]);
$progress_history = $stmt->fetchAll();

$progress_steps = [
    1 => '✅ Acknowledge Task',
    2 => '📍 En Route to Location',
    3 => '🛬 Arrived on Scene',
    4 => '🛠️ Task in Progress',
    5 => '📋 Report Submitted',
    6 => '✔️ Task Complete'
];

$current_step = count($progress_history) + 1;
if ($current_step > 6) $current_step = 6;

$severity_colors = [
    1 => '#28a745',
    2 => '#ffc107',
    3 => '#fd7e14',
    4 => '#dc3545'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Progress - DisasterResponse</title>
    
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
        
        .page-header {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border-bottom: 1px solid var(--border);
            padding: 1.5rem 0;
            margin-bottom: 1.5rem;
        }
        
        .task-card, .progress-card {
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
        
        #map { height: 250px; border-radius: 12px; margin-bottom: 1rem; }
        
        .step-timeline {
            position: relative;
            padding-left: 2rem;
        }
        .step-timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border);
        }
        .step-item {
            position: relative;
            padding-bottom: 1.5rem;
        }
        .step-icon {
            position: absolute;
            left: -1.2rem;
            top: 0;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--surface2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }
        .step-icon.completed { background: var(--green); color: white; }
        .step-icon.current { background: var(--red); color: white; animation: pulse 1.5s infinite; }
        @keyframes pulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.7); }
            50% { box-shadow: 0 0 0 8px rgba(239,68,68,0); }
        }
        
        .severity-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .step-timeline { padding-left: 1.5rem; }
            .step-icon { left: -1rem; width: 22px; height: 22px; font-size: 0.6rem; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="my_tasks.php">
            <i class="bi bi-check2-circle me-1" style="color: var(--red);"></i>Task<span style="color: var(--red);">Progress</span>
        </a>
        <div class="d-flex gap-2">
            <a href="my_tasks.php" class="nav-pill">My Tasks</a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-clipboard-check me-2" style="color: var(--red);"></i>
            Task Progress
        </h1>
        <p class="text-muted mt-1">Update your progress and mark steps as complete</p>
    </div>
</div>

<div class="container pb-5">
    <div class="row">
        <!-- Task Details -->
        <div class="col-lg-7">
            <div class="task-card">
                <div class="card-header-custom">
                    <i class="bi bi-info-circle me-2"></i>Task Details
                </div>
                <div class="card-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small fw-semibold">Incident</label>
                            <p class="mb-0 fw-semibold">#<?= str_pad($task['incident_id'], 5, '0', STR_PAD_LEFT) ?> - <?= ucfirst($task['incident_type']) ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small fw-semibold">Severity</label>
                            <p class="mb-0">
                                <span class="severity-badge" style="background: <?= $severity_colors[$task['severity']] ?>20; color: <?= $severity_colors[$task['severity']] ?>;">
                                    <?= ucfirst($task['severity']) ?> Severity
                                </span>
                            </p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="text-muted small fw-semibold">Location</label>
                            <p class="mb-0"><?= htmlspecialchars($task['location_name'] ?? 'Coordinates below') ?></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="text-muted small fw-semibold">Task Description</label>
                            <p class="mb-0"><?= nl2br(htmlspecialchars($task['task_description'])) ?></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="text-muted small fw-semibold">Assigned By</label>
                            <p class="mb-0"><?= htmlspecialchars($task['assigned_by_name']) ?></p>
                        </div>
                    </div>
                    
                    <!-- Location Map -->
                    <?php if ($task['latitude'] && $task['longitude']): ?>
                    <div class="mt-3">
                        <label class="text-muted small fw-semibold">Incident Location</label>
                        <div id="map"></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Progress Tracking -->
        <div class="col-lg-5">
            <div class="progress-card">
                <div class="card-header-custom">
                    <i class="bi bi-graph-up me-2"></i>Progress Timeline
                </div>
                <div class="card-body p-4">
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    
                    <!-- Progress Steps -->
                    <div class="step-timeline">
                        <?php for ($i = 1; $i <= 6; $i++): 
                            $is_completed = $i <= count($progress_history);
                            $is_current = $i == $current_step && !$is_completed && $task['status'] != 'completed';
                        ?>
                            <div class="step-item">
                                <div class="step-icon <?= $is_completed ? 'completed' : ($is_current ? 'current' : '') ?>">
                                    <?php if ($is_completed): ?>
                                        <i class="bi bi-check-lg"></i>
                                    <?php else: ?>
                                        <?= $i ?>
                                    <?php endif; ?>
                                </div>
                                <div class="ms-3">
                                    <div class="fw-semibold small"><?= $progress_steps[$i] ?></div>
                                    <?php if ($is_current && $task['status'] != 'completed'): ?>
                                        <form method="POST" class="mt-2">
                                            <input type="hidden" name="action" value="update_progress">
                                            <input type="hidden" name="progress_step" value="<?= $i ?>">
                                            <textarea name="update_notes" class="form-control form-control-sm bg-dark text-white border-secondary mb-2" rows="2" placeholder="Add notes about this step..."></textarea>
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="bi bi-check-lg"></i> Mark Complete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                    
                    <!-- Completion Form -->
                    <?php if ($task['status'] != 'completed' && $current_step > 6): ?>
                        <div class="mt-4 pt-3 border-top">
                            <form method="POST">
                                <input type="hidden" name="action" value="mark_complete">
                                <div class="mb-3">
                                    <label class="form-label">Completion Notes</label>
                                    <textarea name="completion_notes" class="form-control bg-dark text-white border-secondary" rows="3" placeholder="What was accomplished? Any recommendations?"></textarea>
                                </div>
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-check2-circle me-2"></i>Mark Task Complete
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Progress History -->
                    <?php if (count($progress_history) > 0): ?>
                        <div class="mt-4 pt-3 border-top">
                            <label class="text-muted small fw-semibold">Activity Log</label>
                            <?php foreach ($progress_history as $log): ?>
                                <div class="mt-2 p-2 bg-dark rounded small">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-success">✓ <?= $progress_steps[$log['progress_step']] ?></span>
                                        <span class="text-muted"><?= date('M j, H:i', strtotime($log['created_at'])) ?></span>
                                    </div>
                                    <?php if ($log['notes']): ?>
                                        <div class="text-muted mt-1"><?= htmlspecialchars($log['notes']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($task['latitude'] && $task['longitude']): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    var map = L.map('map').setView([<?= $task['latitude'] ?>, <?= $task['longitude'] ?>], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);
    L.marker([<?= $task['latitude'] ?>, <?= $task['longitude'] ?>]).addTo(map)
        .bindPopup('Incident Location').openPopup();
</script>
<?php endif; ?>
</body>
</html>