<?php
/**
 * Volunteer Contribution Reports
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Displays volunteer contribution summaries and analytics
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admins and responders can access
role_guard(['admin', 'responder']);

$date_range = $_GET['range'] ?? '30';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime("-{$date_range} days"));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

if ($date_range !== 'custom') {
    $start_date = date('Y-m-d', strtotime("-{$date_range} days"));
    $end_date = date('Y-m-d');
}

// Volunteer statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_volunteers,
        SUM(CASE WHEN availability_status = 'available' THEN 1 ELSE 0 END) as available,
        SUM(CASE WHEN availability_status = 'busy' THEN 1 ELSE 0 END) as busy
    FROM volunteers
");
$stmt->execute();
$volunteer_stats = $stmt->fetch();

// Task completion stats
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_tasks,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned
    FROM volunteer_tasks
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$task_stats = $stmt->fetch();

// Top volunteers
$stmt = $pdo->prepare("
    SELECT 
        u.full_name,
        COUNT(vt.id) as tasks_completed,
        GROUP_CONCAT(DISTINCT vt.incident_id) as incidents
    FROM users u
    JOIN volunteers v ON u.id = v.user_id
    JOIN volunteer_tasks vt ON vt.volunteer_id = u.id
    WHERE vt.status = 'completed'
        AND DATE(vt.completed_at) BETWEEN ? AND ?
    GROUP BY u.id
    ORDER BY tasks_completed DESC
    LIMIT 10
");
$stmt->execute([$start_date, $end_date]);
$top_volunteers = $stmt->fetchAll();

// Skills distribution
$stmt = $pdo->prepare("
    SELECT skills FROM volunteers
");
$stmt->execute();
$all_volunteers = $stmt->fetchAll();

$skill_counts = [];
foreach ($all_volunteers as $vol) {
    $skills = explode(', ', $vol['skills']);
    foreach ($skills as $skill) {
        if (trim($skill)) {
            $skill_counts[$skill] = ($skill_counts[$skill] ?? 0) + 1;
        }
    }
}
arsort($skill_counts);
$top_skills = array_slice($skill_counts, 0, 10);

// Monthly task completion trend
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as tasks,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM volunteer_tasks
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC
");
$stmt->execute([$start_date, $end_date]);
$monthly_trends = $stmt->fetchAll();

$months = [];
$tasks = [];
$completed = [];
foreach ($monthly_trends as $trend) {
    $months[] = date('M Y', strtotime($trend['month'] . '-01'));
    $tasks[] = $trend['tasks'];
    $completed[] = $trend['completed'];
}

$page_title = 'Volunteer Reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Reports - DisasterResponse</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
        
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1rem;
            text-align: center;
        }
        .stat-number { font-size: 1.8rem; font-weight: 800; }
        
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
            text-transform: uppercase;
            font-size: 0.85rem;
        }
        .chart-container { padding: 1.25rem; }
        
        @media print {
            .no-print { display: none; }
            body { background: white; color: black; }
            .dashboard-card { border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern no-print">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="reports.php">
            <i class="bi bi-graph-up me-1 brand-accent"></i>Volunteer<span class="brand-accent">Reports</span>
        </a>
        <div class="d-flex gap-2">
            <a href="assign.php" class="nav-pill">Assign</a>
            <a href="../responders/dashboard.php" class="nav-pill">Dashboard</a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header no-print">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-people-fill me-2" style="color: var(--red);"></i>
            Volunteer Contribution Reports
        </h1>
        <p class="text-muted mt-1">Track volunteer engagement and task completion metrics</p>
    </div>
</div>

<div class="container pb-5">
    
    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--green);"><?= $volunteer_stats['total_volunteers'] ?? 0 ?></div>
                <div class="small text-muted">Total Volunteers</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--green);"><?= $volunteer_stats['available'] ?? 0 ?></div>
                <div class="small text-muted">Available Now</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--blue);"><?= $task_stats['total_tasks'] ?? 0 ?></div>
                <div class="small text-muted">Total Tasks</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--green);"><?= $task_stats['completed'] ?? 0 ?></div>
                <div class="small text-muted">Completed</div>
            </div>
        </div>
    </div>
    
    <!-- Monthly Trends Chart -->
    <div class="dashboard-card">
        <div class="card-header-custom">
            <i class="bi bi-bar-chart-steps me-2" style="color: var(--red);"></i>Monthly Task Trends
        </div>
        <div class="chart-container">
            <canvas id="trendsChart" height="250"></canvas>
        </div>
    </div>
    
    <div class="row">
        <!-- Top Volunteers -->
        <div class="col-lg-7">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <i class="bi bi-trophy me-2" style="color: var(--red);"></i>Top Volunteers
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr><th>Rank</th><th>Volunteer</th><th>Tasks Completed</th><th>Incidents</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_volunteers as $index => $vol): ?>
                                <tr><td><?= $index + 1 ?></td><td><?= htmlspecialchars($vol['full_name']) ?></td><td><?= $vol['tasks_completed'] ?></td><td><?= count(explode(',', $vol['incidents'])) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Top Skills -->
        <div class="col-lg-5">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <i class="bi bi-tags me-2" style="color: var(--red);"></i>Top Skills
                </div>
                <div class="p-3">
                    <?php foreach ($top_skills as $skill => $count): ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between small mb-1">
                                <span><?= $skill ?></span>
                                <span><?= $count ?> volunteers</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-danger" style="width: <?= ($count / max($top_skills)) * 100 ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Export Button -->
    <div class="text-center mt-3 no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary">
            <i class="bi bi-printer me-1"></i>Print Report
        </button>
    </div>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    new Chart(document.getElementById('trendsChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($months) ?>,
            datasets: [
                { label: 'Total Tasks', data: <?= json_encode($tasks) ?>, borderColor: '#ef4444', fill: true },
                { label: 'Completed', data: <?= json_encode($completed) ?>, borderColor: '#22c55e', fill: true }
            ]
        },
        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { labels: { color: '#94a3b8' } } } }
    });
</script>
</body>
</html>