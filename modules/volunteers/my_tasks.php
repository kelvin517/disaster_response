<?php
/**
 * Volunteer My Tasks
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Displays tasks assigned to the volunteer and allows task management
 * Integrated with progress tracking system
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

if (!hasRole(['volunteer', 'admin'])) {
    redirect('index.php');
}

$user_id = $_SESSION['user_id'];
$success = null;
$error = null;

// Handle task status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $task_id = (int)$_POST['task_id'];
        $status = $_POST['status'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE volunteer_tasks 
                SET status = ?, 
                    completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END 
                WHERE id = ? AND volunteer_id = ?
            ");
            if ($stmt->execute([$status, $status, $task_id, $user_id])) {
                // If task is completed, update volunteer availability back to available
                if ($status === 'completed') {
                    $stmt = $pdo->prepare("UPDATE volunteers SET availability_status = 'available' WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    // Add completion record to task_progress
                    $stmt = $pdo->prepare("
                        INSERT INTO task_progress (task_id, progress_step, notes, created_at)
                        VALUES (?, 6, 'Task marked as complete by volunteer', NOW())
                    ");
                    $stmt->execute([$task_id]);
                }
                $success = "Task status updated successfully!";
            } else {
                $error = "Failed to update task status.";
            }
        } catch (PDOException $e) {
            error_log("Task update failed: " . $e->getMessage());
            $error = "Failed to update task status.";
        }
    }
    
    // Handle task acceptance
    if ($_POST['action'] === 'accept_task') {
        $task_id = (int)$_POST['task_id'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE volunteer_tasks 
                SET status = 'in_progress' 
                WHERE id = ? AND volunteer_id = ? AND status = 'assigned'
            ");
            if ($stmt->execute([$task_id, $user_id])) {
                // Update volunteer status to busy
                $stmt = $pdo->prepare("UPDATE volunteers SET availability_status = 'busy' WHERE user_id = ?");
                $stmt->execute([$user_id]);
                
                // Add acceptance record to task_progress
                $stmt = $pdo->prepare("
                    INSERT INTO task_progress (task_id, progress_step, notes, created_at)
                    VALUES (?, 1, 'Task accepted by volunteer', NOW())
                ");
                $stmt->execute([$task_id]);
                
                $success = "Task accepted! You can now start working on it.";
            } else {
                $error = "Failed to accept task.";
            }
        } catch (PDOException $e) {
            error_log("Task acceptance failed: " . $e->getMessage());
            $error = "Failed to accept task.";
        }
    }
}

// Get volunteer stats
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_tasks,
        SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned_tasks,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tasks,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks
    FROM volunteer_tasks 
    WHERE volunteer_id = ?
");
$stmt->execute([$user_id]);
$stats = $stmt->fetch();
if (!$stats) {
    $stats = ['total_tasks' => 0, 'assigned_tasks' => 0, 'in_progress_tasks' => 0, 'completed_tasks' => 0];
}

// Get assigned tasks (pending acceptance)
$stmt = $pdo->prepare("
    SELECT vt.*, i.incident_type, i.severity, i.location_name, i.description as incident_desc,
           u.full_name as assigned_by_name, i.latitude, i.longitude
    FROM volunteer_tasks vt
    JOIN incidents i ON vt.incident_id = i.id
    JOIN users u ON vt.assigned_by = u.id
    WHERE vt.volunteer_id = ? AND vt.status = 'assigned'
    ORDER BY vt.created_at DESC
");
$stmt->execute([$user_id]);
$pending_tasks = $stmt->fetchAll();

// Get in-progress tasks
$stmt = $pdo->prepare("
    SELECT vt.*, i.incident_type, i.severity, i.location_name, i.description as incident_desc,
           u.full_name as assigned_by_name, i.latitude, i.longitude,
           (SELECT COUNT(*) FROM task_progress WHERE task_id = vt.id) as progress_count
    FROM volunteer_tasks vt
    JOIN incidents i ON vt.incident_id = i.id
    JOIN users u ON vt.assigned_by = u.id
    WHERE vt.volunteer_id = ? AND vt.status = 'in_progress'
    ORDER BY vt.created_at DESC
");
$stmt->execute([$user_id]);
$in_progress_tasks = $stmt->fetchAll();

// Get completed tasks (last 10)
$stmt = $pdo->prepare("
    SELECT vt.*, i.incident_type, i.location_name, vt.completed_at
    FROM volunteer_tasks vt
    JOIN incidents i ON vt.incident_id = i.id
    WHERE vt.volunteer_id = ? AND vt.status = 'completed'
    ORDER BY vt.completed_at DESC
    LIMIT 10
");
$stmt->execute([$user_id]);
$completed_tasks = $stmt->fetchAll();

// Get volunteer profile info
$stmt = $pdo->prepare("SELECT skills, availability_status, latitude, longitude FROM volunteers WHERE user_id = ?");
$stmt->execute([$user_id]);
$volunteer_profile = $stmt->fetch();

if (!$volunteer_profile) {
    $stmt = $pdo->prepare("INSERT INTO volunteers (user_id, skills, availability_status) VALUES (?, '', 'available')");
    $stmt->execute([$user_id]);
    $volunteer_profile = ['skills' => '', 'availability_status' => 'available', 'latitude' => null, 'longitude' => null];
}

// Calculate total points/completion rate
$completion_rate = $stats['total_tasks'] > 0 ? round(($stats['completed_tasks'] / $stats['total_tasks']) * 100) : 0;

$severity_colors = [
    1 => '#28a745',
    2 => '#ffc107',
    3 => '#fd7e14',
    4 => '#dc3545'
];

$severity_labels = [
    1 => 'LOW',
    2 => 'MEDIUM',
    3 => 'HIGH',
    4 => 'CRITICAL'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks - Volunteer Dashboard</title>
    
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

        * { margin: 0; padding: 0; box-sizing: border-box; }
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
            font-weight: 500;
            text-decoration: none;
            transition: all 0.18s ease;
        }
        .nav-pill:hover { border-color: var(--red); color: var(--red); background: rgba(239,68,68,0.15); }

        .welcome-section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-left: 3px solid var(--red);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.2rem;
            transition: all 0.2s;
            text-align: center;
        }
        .stat-card:hover { transform: translateY(-3px); border-color: var(--red); }
        .stat-number { font-size: 1.8rem; font-weight: 800; }
        .stat-label { font-size: 0.7rem; color: var(--muted); text-transform: uppercase; }

        .dashboard-card {
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header-custom i { color: var(--red); margin-right: 8px; }

        .task-item {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            transition: background 0.2s;
        }
        .task-item:hover { background: var(--surface2); }
        .task-item h6 {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        .task-item .meta {
            font-size: 0.7rem;
            color: var(--muted);
            font-family: monospace;
        }

        .severity-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .severity-4 { background: rgba(239,68,68,0.2); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
        .severity-3 { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.25); }
        .severity-2 { background: rgba(251,191,36,0.12); color: #fcd34d; border: 1px solid rgba(251,191,36,0.25); }
        .severity-1 { background: rgba(74,222,128,0.12); color: #86efac; border: 1px solid rgba(74,222,128,0.25); }

        .status-assigned { background: rgba(245,158,11,0.15); color: #fbbf24; }
        .status-progress { background: rgba(59,130,246,0.12); color: #60a5fa; }
        .status-completed { background: rgba(74,222,128,0.12); color: #86efac; }

        .availability-badge {
            padding: 0.3rem 1rem;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .btn-sm-custom {
            border-radius: 20px;
            padding: 0.3rem 1rem;
            font-size: 0.75rem;
        }

        .progress-ring {
            width: 60px;
            height: 60px;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .dashboard-card, .stat-card { animation: fadeInUp 0.3s ease-out; }

        @media (max-width: 768px) {
            .stat-number { font-size: 1.3rem; }
            .welcome-section h1 { font-size: 1.2rem; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="my_tasks.php">
            <i class="bi bi-heart-hand me-1 brand-accent"></i>Disaster<span class="brand-accent">Volunteer</span>
        </a>
        <div class="d-flex gap-2">
            <span class="text-muted small d-none d-md-block mt-2">
                <i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars($_SESSION['full_name']); ?>
            </span>
            <a href="register.php" class="nav-pill">
                <i class="bi bi-pencil-square me-1"></i>Profile
            </a>
            <a href="../mapping/map.php" class="nav-pill">
                <i class="bi bi-map me-1"></i>Map
            </a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">
    
    <div class="welcome-section">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1 class="fw-bold mb-1">
                    <span class="status-dot"></span>
                    Hello, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!
                </h1>
                <p class="mb-0 text-muted">You're logged in as a Volunteer. Here are your assigned tasks.</p>
            </div>
            <div class="col-md-4 text-end">
                <i class="bi bi-heart-hand" style="font-size: 3rem; color: var(--red); opacity: 0.3;"></i>
            </div>
        </div>
    </div>
    
    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--blue);"><?php echo $stats['total_tasks']; ?></div>
                <div class="stat-label">Total Tasks</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--amber);"><?php echo $stats['assigned_tasks']; ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--blue);"><?php echo $stats['in_progress_tasks']; ?></div>
                <div class="stat-label">In Progress</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--green);"><?php echo $stats['completed_tasks']; ?></div>
                <div class="stat-label">Completed</div>
                <div class="small text-muted mt-1"><?= $completion_rate ?>% rate</div>
            </div>
        </div>
    </div>
    
    <!-- Volunteer Profile Summary -->
    <div class="dashboard-card mb-4">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <i class="bi bi-circle-fill me-2 <?php echo ($volunteer_profile['availability_status'] == 'available') ? 'text-success' : 'text-secondary'; ?>"></i>
                    <span class="fw-semibold">Status:</span>
                    <span class="availability-badge <?php echo ($volunteer_profile['availability_status'] == 'available') ? 'bg-success bg-opacity-10 text-success' : 'bg-secondary bg-opacity-10 text-secondary'; ?>">
                        <?php echo ucfirst($volunteer_profile['availability_status']); ?>
                    </span>
                </div>
                <div>
                    <i class="bi bi-tag me-1"></i>
                    <span class="small">Skills: <?php echo !empty($volunteer_profile['skills']) ? htmlspecialchars(substr($volunteer_profile['skills'], 0, 50)) . (strlen($volunteer_profile['skills']) > 50 ? '...' : '') : 'Not specified'; ?></span>
                    <a href="register.php" class="btn btn-sm btn-link text-danger">Update</a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Pending Tasks (Awaiting Acceptance) -->
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-clock-history"></i>Pending Tasks</span>
                    <span class="badge bg-warning"><?php echo $stats['assigned_tasks']; ?></span>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (count($pending_tasks) > 0): ?>
                        <?php foreach ($pending_tasks as $task): ?>
                            <div class="task-item">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6>
                                            <i class="bi bi-geo-alt-fill text-muted me-1"></i>
                                            <?php echo htmlspecialchars($task['location_name'] ?? 'Location TBD'); ?>
                                        </h6>
                                        <div class="meta">
                                            <?php echo ucfirst($task['incident_type']); ?> • 
                                            Assigned by: <?php echo htmlspecialchars($task['assigned_by_name']); ?>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="severity-badge severity-<?php echo $task['severity']; ?>">
                                            <?php echo $severity_labels[$task['severity']] ?? 'LOW'; ?>
                                        </span>
                                    </div>
                                </div>
                                <p class="small text-muted mb-2"><?php echo htmlspecialchars(substr($task['task_description'] ?? $task['incident_desc'], 0, 100)); ?>...</p>
                                <div class="d-flex gap-2">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <input type="hidden" name="action" value="accept_task">
                                        <button type="submit" class="btn btn-sm btn-success rounded-pill">
                                            <i class="bi bi-check-lg"></i> Accept Task
                                        </button>
                                    </form>
                                    <a href="../incidents/view.php?id=<?php echo $task['incident_id']; ?>" class="btn btn-sm btn-outline-secondary rounded-pill">
                                        <i class="bi bi-info-circle"></i> Details
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-check-circle-fill fs-1 mb-2 d-block text-success"></i>
                            <p>No pending tasks.<br>Check back later!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- In Progress Tasks -->
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-play-fill"></i>In Progress</span>
                    <span class="badge bg-primary"><?php echo $stats['in_progress_tasks']; ?></span>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (count($in_progress_tasks) > 0): ?>
                        <?php foreach ($in_progress_tasks as $task): ?>
                            <div class="task-item">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6>
                                            <i class="bi bi-geo-alt-fill text-muted me-1"></i>
                                            <?php echo htmlspecialchars($task['location_name'] ?? 'Location TBD'); ?>
                                        </h6>
                                        <div class="meta">
                                            <?php echo ucfirst($task['incident_type']); ?> • 
                                            Progress: <?php echo $task['progress_count']; ?>/6 steps
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="severity-badge severity-<?php echo $task['severity']; ?>">
                                            <?php echo $severity_labels[$task['severity']] ?? 'LOW'; ?>
                                        </span>
                                    </div>
                                </div>
                                <p class="small text-muted mb-2"><?php echo htmlspecialchars(substr($task['task_description'] ?? $task['incident_desc'], 0, 80)); ?>...</p>
                                <div class="d-flex gap-2">
                                    <a href="progress.php?id=<?php echo $task['id']; ?>" class="btn btn-sm btn-primary rounded-pill">
                                        <i class="bi bi-arrow-right"></i> Update Progress
                                    </a>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="status" value="completed">
                                        <button type="submit" class="btn btn-sm btn-success rounded-pill" onclick="return confirm('Mark this task as complete?')">
                                            <i class="bi bi-check-lg"></i> Complete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-inbox fs-1 mb-2 d-block"></i>
                            <p>No tasks in progress.<br>Accept a task to get started!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Completed Tasks -->
        <div class="col-lg-7">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-check2-circle text-success"></i>Recently Completed</span>
                    <span class="badge bg-success"><?php echo $stats['completed_tasks']; ?> total</span>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (count($completed_tasks) > 0): ?>
                        <?php foreach ($completed_tasks as $task): ?>
                            <div class="task-item">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="fw-semibold"><?php echo ucfirst($task['incident_type']); ?> Response</div>
                                        <div class="meta">📍 <?php echo htmlspecialchars($task['location_name'] ?? 'Location'); ?></div>
                                    </div>
                                    <div class="text-end">
                                        <span class="text-success">✓ Completed</span>
                                        <div class="meta"><?php echo date('M j, Y', strtotime($task['completed_at'])); ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-check-circle fs-1 mb-2 d-block"></i>
                            <p>No completed tasks yet.<br>Your first task is waiting!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions & Tips -->
        <div class="col-lg-5">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-lightning-charge-fill"></i>Quick Actions</span>
                </div>
                <div class="card-body p-3">
                    <div class="d-grid gap-2">
                        <a href="../incidents/report.php" class="btn btn-outline-danger rounded-pill">
                            <i class="bi bi-exclamation-triangle me-2"></i>Report New Incident
                        </a>
                        <a href="register.php" class="btn btn-outline-secondary rounded-pill">
                            <i class="bi bi-pencil-square me-2"></i>Update Skills/Availability
                        </a>
                        <a href="../mapping/map.php" class="btn btn-outline-info rounded-pill">
                            <i class="bi bi-map me-2"></i>View Incident Map
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Tips Card -->
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-lightbulb-fill text-warning"></i>Volunteer Tips</span>
                </div>
                <div class="card-body p-3">
                    <ul class="small text-muted mb-0">
                        <li><i class="bi bi-check-circle text-success me-2"></i>Keep your availability status updated</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>Accept tasks that match your skills</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>Use the progress tracker for updates</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>Report any issues to coordinators</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>Complete tasks to earn recognition</li>
                    </ul>
                </div>
            </div>
            
            <!-- Call to Action -->
            <div class="dashboard-card">
                <div class="card-body p-3 bg-primary bg-opacity-10">
                    <div class="d-flex gap-2">
                        <i class="bi bi-heart-fill text-danger fs-4"></i>
                        <div class="small">
                            <strong>Thank you for volunteering!</strong><br>
                            Your contribution makes a difference in saving lives.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = bootstrap.Alert.getInstance(alert);
            if (bsAlert) bsAlert.close();
        });
    }, 5000);
</script>
</body>
</html>