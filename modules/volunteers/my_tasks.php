<?php
/**
 * Volunteer My Tasks
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
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

// Handle task status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $task_id = $_POST['task_id'];
    $status = $_POST['status'];
    
    $stmt = $pdo->prepare("UPDATE volunteer_tasks SET status = ?, completed_at = NOW() WHERE id = ? AND volunteer_id = ?");
    if ($stmt->execute([$status, $task_id, $user_id])) {
        $success = "Task status updated successfully!";
    } else {
        $error = "Failed to update task status.";
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

// Get assigned tasks - FIXED: changed 'type' to 'incident_type'
$stmt = $pdo->prepare("
    SELECT vt.*, i.incident_type, i.severity, i.location_name, i.description as incident_desc,
           u.full_name as assigned_by_name
    FROM volunteer_tasks vt
    JOIN incidents i ON vt.incident_id = i.id
    JOIN users u ON vt.assigned_by = u.id
    WHERE vt.volunteer_id = ? AND vt.status IN ('assigned', 'in_progress')
    ORDER BY vt.created_at DESC
");
$stmt->execute([$user_id]);
$assigned_tasks = $stmt->fetchAll();

// Get completed tasks (last 5) - FIXED: changed 'type' to 'incident_type'
$stmt = $pdo->prepare("
    SELECT vt.*, i.incident_type, i.location_name
    FROM volunteer_tasks vt
    JOIN incidents i ON vt.incident_id = i.id
    WHERE vt.volunteer_id = ? AND vt.status = 'completed'
    ORDER BY vt.completed_at DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$completed_tasks = $stmt->fetchAll();

// Get volunteer profile info
$stmt = $pdo->prepare("SELECT skills, availability_status FROM volunteers WHERE user_id = ?");
$stmt->execute([$user_id]);
$volunteer_profile = $stmt->fetch();

if (!$volunteer_profile) {
    $stmt = $pdo->prepare("INSERT INTO volunteers (user_id, skills, availability_status) VALUES (?, '', 'available')");
    $stmt->execute([$user_id]);
    $volunteer_profile = ['skills' => '', 'availability_status' => 'available'];
}
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
        
        .stat-card {
            background: white;
            border-radius: 20px;
            border: none;
            padding: 1.2rem;
            transition: transform 0.2s;
            margin-bottom: 1rem;
        }
        
        .stat-card:hover { transform: translateY(-3px); }
        .stat-number { font-size: 1.8rem; font-weight: 800; margin-bottom: 0; }
        .stat-label { color: #6c757d; font-size: 0.8rem; }
        
        .dashboard-card {
            background: white;
            border-radius: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .card-header-custom {
            background: white;
            border-bottom: 2px solid #f0f0f0;
            padding: 1rem 1.5rem;
            font-weight: 600;
        }
        
        .task-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }
        
        .task-item:hover { background: #f8f9fa; }
        
        .severity-critical { background: #dc3545; color: white; }
        .severity-high { background: #fd7e14; color: white; }
        .severity-medium { background: #ffc107; color: #333; }
        .severity-low { background: #28a745; color: white; }
        
        .status-assigned { background: #fd7e14; color: white; }
        .status-progress { background: #17a2b8; color: white; }
        .status-completed { background: #28a745; color: white; }
        
        .welcome-section {
            background: linear-gradient(135deg, #dc3545, #b91c1c);
            border-radius: 20px;
            padding: 1.5rem;
            color: white;
            margin-bottom: 1.5rem;
        }
        
        .availability-badge {
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .dashboard-card, .stat-card { animation: fadeInUp 0.3s ease-out; }
        
        @media (max-width: 768px) {
            .stat-number { font-size: 1.3rem; }
            .welcome-section h1 { font-size: 1.3rem; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-modern sticky-top">
    <div class="container">
        <a class="navbar-brand" href="my_tasks.php">
            <i class="bi bi-shield-check me-2"></i>DisasterResponse
        </a>
        <div class="d-flex gap-2">
            <span class="text-muted small d-none d-md-block mt-2">
                <i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars($_SESSION['full_name']); ?>
            </span>
            <a href="../mapping/map.php" class="btn btn-outline-danger btn-sm rounded-pill">
                <i class="bi bi-map me-1"></i>Map
            </a>
            <a href="../auth/profile.php" class="btn btn-outline-secondary btn-sm rounded-pill">
                <i class="bi bi-person me-1"></i>Profile
            </a>
            <a href="../auth/logout.php" class="btn btn-outline-danger btn-sm rounded-pill"
               onclick="return confirm('Logout?');">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">
    
    <div class="welcome-section">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1 class="fw-bold mb-1">Hello, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h1>
                <p class="mb-0 opacity-75">You're logged in as a Volunteer. Here are your assigned tasks.</p>
            </div>
            <div class="col-md-4 text-end">
                <i class="bi bi-heart-hand" style="font-size: 3rem; opacity: 0.3;"></i>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <div class="stat-number"><?php echo $stats['total_tasks'] ?? 0; ?></div>
                <p class="stat-label">Total Tasks</p>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <div class="stat-number"><?php echo $stats['assigned_tasks'] ?? 0; ?></div>
                <p class="stat-label">Pending</p>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <div class="stat-number"><?php echo $stats['in_progress_tasks'] ?? 0; ?></div>
                <p class="stat-label">In Progress</p>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center">
                <div class="stat-number"><?php echo $stats['completed_tasks'] ?? 0; ?></div>
                <p class="stat-label">Completed</p>
            </div>
        </div>
    </div>
    
    <div class="dashboard-card mb-4">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <i class="bi bi-circle-fill me-2 <?php echo ($volunteer_profile['availability_status'] == 'available') ? 'text-success' : 'text-secondary'; ?>"></i>
                    <span class="fw-semibold">Availability:</span>
                    <span class="availability-badge bg-<?php echo ($volunteer_profile['availability_status'] == 'available') ? 'success' : 'secondary'; ?> bg-opacity-10">
                        <?php echo ucfirst($volunteer_profile['availability_status']); ?>
                    </span>
                </div>
                <div>
                    <i class="bi bi-tag me-1"></i>
                    <span class="small">Skills: <?php echo !empty($volunteer_profile['skills']) ? htmlspecialchars($volunteer_profile['skills']) : 'Not specified'; ?></span>
                    <a href="../auth/profile.php" class="btn btn-sm btn-link text-danger">Update Profile</a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-lg-7">
            <div class="dashboard-card">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-list-task text-danger me-2"></i>Active Tasks</span>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (count($assigned_tasks) > 0): ?>
                        <?php foreach ($assigned_tasks as $task): ?>
                            <div class="task-item">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-0 fw-semibold">
                                            <i class="bi bi-geo-alt-fill text-muted me-1"></i>
                                            <?php echo htmlspecialchars($task['location_name'] ?? 'Location TBD'); ?>
                                        </h6>
                                        <small class="text-muted">
                                            Incident: <?php echo ucfirst($task['incident_type']); ?> • 
                                            Assigned by: <?php echo htmlspecialchars($task['assigned_by_name']); ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="severity-badge severity-<?php echo $task['severity']; ?> d-inline-block mb-1">
                                            <?php echo ucfirst($task['severity']); ?>
                                        </span>
                                        <span class="status-badge status-<?php echo str_replace('_', '', $task['status']); ?> d-block">
                                            <?php echo str_replace('_', ' ', ucfirst($task['status'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <p class="small text-muted mb-2"><?php echo htmlspecialchars($task['task_description'] ?? $task['incident_desc']); ?></p>
                                <div class="d-flex gap-2">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <?php if ($task['status'] == 'assigned'): ?>
                                            <button type="submit" name="status" value="in_progress" class="btn btn-sm btn-primary rounded-pill">
                                                <i class="bi bi-play-fill"></i> Start Task
                                            </button>
                                        <?php elseif ($task['status'] == 'in_progress'): ?>
                                            <button type="submit" name="status" value="completed" class="btn btn-sm btn-success rounded-pill">
                                                <i class="bi bi-check-lg"></i> Mark Complete
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                    <a href="../incidents/view.php?id=<?php echo $task['incident_id']; ?>" class="btn btn-sm btn-outline-secondary rounded-pill">
                                        <i class="bi bi-info-circle"></i> View Details
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-check-circle-fill fs-1 mb-2 d-block"></i>
                            <p>No active tasks assigned.</p>
                            <small>Check back later or update your availability status.</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-lg-5">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <i class="bi bi-lightning-charge-fill text-danger me-2"></i>Quick Actions
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="../incidents/report.php" class="btn btn-outline-danger rounded-pill">
                            <i class="bi bi-exclamation-triangle me-2"></i>Report New Incident
                        </a>
                        <a href="../auth/profile.php" class="btn btn-outline-secondary rounded-pill">
                            <i class="bi bi-pencil-square me-2"></i>Update Skills/Availability
                        </a>
                        <a href="../mapping/map.php" class="btn btn-outline-info rounded-pill">
                            <i class="bi bi-map me-2"></i>View Incident Map
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-check2-circle text-success me-2"></i>Recently Completed</span>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (count($completed_tasks) > 0): ?>
                        <?php foreach ($completed_tasks as $task): ?>
                            <div class="task-item">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <small class="fw-semibold"><?php echo ucfirst($task['incident_type']); ?> Response</small>
                                        <div class="text-muted" style="font-size: 0.7rem;"><?php echo htmlspecialchars($task['location_name']); ?></div>
                                    </div>
                                    <small class="text-success"><?php echo date('M j', strtotime($task['completed_at'])); ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-3 text-center text-muted small">No completed tasks yet</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-body p-3 bg-light">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill text-warning fs-4"></i>
                        <div class="small">
                            <strong>Volunteer Tips:</strong><br>
                            • Keep your availability status updated<br>
                            • Respond quickly to task assignments<br>
                            • Always confirm task completion<br>
                            • Report any issues to coordinators
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>