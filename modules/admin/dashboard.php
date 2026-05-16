<?php
/**
 * Admin Dashboard
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Complete administrative dashboard with system overview, user management,
 * analytics, and system configuration.
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admin can access
if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

if (!hasRole(['admin'])) {
    redirect('index.php');
}

$user_id = $_SESSION['user_id'];

// ============================================
// SYSTEM STATISTICS
// ============================================

// Incident Statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_incidents,
        SUM(CASE WHEN status = 'reported' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'acknowledged' THEN 1 ELSE 0 END) as acknowledged,
        SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN severity = 4 THEN 1 ELSE 0 END) as critical,
        SUM(CASE WHEN severity = 3 THEN 1 ELSE 0 END) as high,
        SUM(CASE WHEN severity = 2 THEN 1 ELSE 0 END) as medium,
        SUM(CASE WHEN severity = 1 THEN 1 ELSE 0 END) as low
    FROM incidents
");
$incident_stats = $stmt->fetch();

// Danger Zones Statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_zones,
        SUM(CASE WHEN hazard_level = 'critical' THEN 1 ELSE 0 END) as critical_zones,
        SUM(CASE WHEN hazard_level = 'high' THEN 1 ELSE 0 END) as high_zones,
        SUM(CASE WHEN hazard_level = 'medium' THEN 1 ELSE 0 END) as medium_zones,
        SUM(CASE WHEN hazard_level = 'low' THEN 1 ELSE 0 END) as low_zones
    FROM danger_zones
    WHERE status = 'active'
");
$zone_stats = $stmt->fetch();

// Shelters Statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_shelters,
        SUM(capacity) as total_capacity,
        SUM(current_occupancy) as total_occupancy
    FROM shelters
    WHERE status = 'active'
");
$shelter_stats = $stmt->fetch();

// User Statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
        SUM(CASE WHEN role = 'responder' THEN 1 ELSE 0 END) as responders,
        SUM(CASE WHEN role = 'volunteer' THEN 1 ELSE 0 END) as volunteers,
        SUM(CASE WHEN role = 'victim' THEN 1 ELSE 0 END) as victims,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users
    FROM users
");
$user_stats = $stmt->fetch();

// Resource Statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT resource_type) as resource_types,
        SUM(quantity) as total_units,
        SUM(CASE WHEN status = 'available' THEN quantity ELSE 0 END) as available_units
    FROM resources
");
$resource_stats = $stmt->fetch();

// Volunteer Statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_volunteers,
        SUM(CASE WHEN availability_status = 'available' THEN 1 ELSE 0 END) as available_volunteers,
        SUM(CASE WHEN availability_status = 'busy' THEN 1 ELSE 0 END) as busy_volunteers
    FROM volunteers
");
$volunteer_stats = $stmt->fetch();

// Recent Activity (last 7 days)
$stmt = $pdo->prepare("
    SELECT 
        DATE(reported_at) as date,
        COUNT(*) as count
    FROM incidents
    WHERE reported_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(reported_at)
    ORDER BY date ASC
");
$stmt->execute();
$weekly_activity = $stmt->fetchAll();

// Prepare chart data
$activity_dates = [];
$activity_counts = [];
foreach ($weekly_activity as $activity) {
    $activity_dates[] = date('M j', strtotime($activity['date']));
    $activity_counts[] = $activity['count'];
}

// Recent Incidents
$stmt = $pdo->prepare("
    SELECT i.*, u.full_name as reporter_name, r.full_name as responder_name
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id = u.id
    LEFT JOIN users r ON i.assigned_to = r.id
    ORDER BY i.reported_at DESC
    LIMIT 10
");
$stmt->execute();
$recent_incidents = $stmt->fetchAll();

// Recent Users
$stmt = $pdo->prepare("
    SELECT id, full_name, email, phone, role, is_active, created_at
    FROM users
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute();
$recent_users = $stmt->fetchAll();

// Response Time Stats
$stmt = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(HOUR, reported_at, updated_at)) as avg_response_hours
    FROM incidents
    WHERE status = 'resolved' AND updated_at IS NOT NULL
");
$response_time = $stmt->fetch();

// Severity distribution for chart
$stmt = $pdo->query("
    SELECT 
        CASE severity
            WHEN 1 THEN 'Low'
            WHEN 2 THEN 'Medium'
            WHEN 3 THEN 'High'
            WHEN 4 THEN 'Critical'
        END as label,
        COUNT(*) as count
    FROM incidents
    GROUP BY severity
    ORDER BY severity DESC
");
$severity_distribution = $stmt->fetchAll();

$severity_labels = [];
$severity_counts = [];
foreach ($severity_distribution as $sev) {
    $severity_labels[] = $sev['label'];
    $severity_counts[] = $sev['count'];
}

// Calculate shelter occupancy percentage
$shelter_occupancy = 0;
if (($shelter_stats['total_capacity'] ?? 0) > 0) {
    $shelter_occupancy = round(($shelter_stats['total_occupancy'] / $shelter_stats['total_capacity']) * 100);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - DisasterResponse</title>
    
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
            --red-glow: rgba(239,68,68,0.15);
            --green: #22c55e;
            --blue: #3b82f6;
            --amber: #f59e0b;
            --purple: #8b5cf6;
            --text: #f1f5f9;
            --muted: #94a3b8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg); }
        ::-webkit-scrollbar-thumb { background: var(--surface2); border-radius: 3px; }

        .navbar-modern {
            background: rgba(15,23,42,0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
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
        .nav-pill:hover {
            border-color: var(--red);
            color: var(--red);
            background: var(--red-glow);
        }
        .nav-pill.danger:hover {
            background: var(--red);
            color: white;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.25rem;
            transition: all 0.2s ease;
            animation: fadeUp 0.4s ease both;
        }
        .stat-card:hover { transform: translateY(-3px); border-color: var(--red); }
        .stat-number { font-size: 2rem; font-weight: 800; margin-bottom: 0.25rem; }
        .stat-label { font-size: 0.75rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.08em; }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }

        .dashboard-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 1.5rem;
            overflow: hidden;
            animation: fadeUp 0.45s ease both;
        }
        .card-header-custom {
            background: var(--surface2);
            border-bottom: 1px solid var(--border);
            padding: 0.85rem 1.25rem;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-header-custom i { color: var(--red); margin-right: 8px; }
        .view-all { font-size: 0.7rem; text-transform: none; color: var(--red); text-decoration: none; }

        .incident-item, .user-item {
            padding: 0.85rem 1.25rem;
            border-bottom: 1px solid var(--border);
            transition: background 0.15s;
            cursor: pointer;
        }
        .incident-item:hover, .user-item:hover { background: var(--surface2); }

        .severity-badge, .status-badge {
            padding: 0.2rem 0.65rem;
            border-radius: 4px;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .severity-critical { background: rgba(239,68,68,0.2); color: #f87171; border: 1px solid rgba(239,68,68,0.35); }
        .severity-high { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); }
        .severity-medium { background: rgba(59,130,246,0.12); color: #60a5fa; border: 1px solid rgba(59,130,246,0.25); }
        .severity-low { background: rgba(34,197,94,0.12); color: #4ade80; border: 1px solid rgba(34,197,94,0.25); }

        .status-reported { background: rgba(239,68,68,0.15); color: #f87171; }
        .status-acknowledged { background: rgba(245,158,11,0.12); color: #fbbf24; }
        .status-in-progress { background: rgba(59,130,246,0.12); color: #60a5fa; }
        .status-resolved { background: rgba(34,197,94,0.12); color: #4ade80; }

        .btn-action {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.5rem 1rem;
            color: var(--text);
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-action:hover { border-color: var(--red); color: var(--red); background: var(--red-glow); }

        .chart-container { padding: 1.25rem; }

        .progress-bar-custom {
            height: 8px;
            border-radius: 4px;
            background: var(--surface2);
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .stat-number { font-size: 1.5rem; }
            .stat-icon { width: 36px; height: 36px; font-size: 1rem; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between gap-2">
        <a class="navbar-brand" href="admin_dashboard.php">
            <i class="bi bi-shield-lock-fill me-1 brand-accent"></i>Disaster<span class="brand-accent">Response</span>
            <span class="badge bg-danger ms-2" style="font-size: 0.6rem;">ADMIN</span>
        </a>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <span class="text-muted small d-none d-md-block">
                <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?>
            </span>
            <a href="admin_dashboard.php" class="nav-pill">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a href="../mapping/map.php" class="nav-pill">
                <i class="bi bi-map me-1"></i>Live Map
            </a>
            <a href="../incidents/pending.php" class="nav-pill">
                <i class="bi bi-clock-history me-1"></i>Pending
                <?php if (($incident_stats['pending'] ?? 0) > 0): ?>
                    <span class="badge bg-danger ms-1"><?= $incident_stats['pending'] ?></span>
                <?php endif; ?>
            </a>
            <a href="users.php" class="nav-pill">
                <i class="bi bi-people me-1"></i>Users
            </a>
            <a href="../auth/logout.php" class="nav-pill danger"
               onclick="return confirm('Logout?');">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">
    
    <!-- Welcome Banner -->
    <div class="dashboard-card mb-4">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="fw-bold mb-1 fs-3">Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?>!</h1>
                    <p class="text-muted mb-0">System Overview & Analytics</p>
                </div>
                <div class="col-md-4 text-end">
                    <i class="bi bi-shield-check" style="font-size: 3rem; color: var(--red); opacity: 0.3;"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Incident Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?= number_format($incident_stats['total_incidents'] ?? 0) ?></div>
                        <p class="stat-label">Total Incidents</p>
                    </div>
                    <div class="stat-icon" style="background: rgba(239,68,68,0.15);">
                        <i class="bi bi-exclamation-triangle-fill" style="color: var(--red);"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?= number_format($incident_stats['pending'] ?? 0) ?></div>
                        <p class="stat-label">Pending</p>
                    </div>
                    <div class="stat-icon" style="background: rgba(245,158,11,0.15);">
                        <i class="bi bi-clock-history" style="color: var(--amber);"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?= number_format($incident_stats['in_progress'] ?? 0) ?></div>
                        <p class="stat-label">In Progress</p>
                    </div>
                    <div class="stat-icon" style="background: rgba(59,130,246,0.15);">
                        <i class="bi bi-truck" style="color: var(--blue);"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?= number_format($incident_stats['resolved'] ?? 0) ?></div>
                        <p class="stat-label">Resolved</p>
                    </div>
                    <div class="stat-icon" style="background: rgba(34,197,94,0.15);">
                        <i class="bi bi-check-circle-fill" style="color: var(--green);"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Infrastructure Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?= number_format($zone_stats['total_zones'] ?? 0) ?></div>
                        <p class="stat-label">Danger Zones</p>
                    </div>
                    <div class="stat-icon" style="background: rgba(239,68,68,0.15);">
                        <i class="bi bi-exclamation-octagon-fill" style="color: var(--red);"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?= number_format($shelter_stats['total_shelters'] ?? 0) ?></div>
                        <p class="stat-label">Safe Shelters</p>
                    </div>
                    <div class="stat-icon" style="background: rgba(34,197,94,0.15);">
                        <i class="bi bi-building-fill" style="color: var(--green);"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?= number_format($resource_stats['resource_types'] ?? 0) ?></div>
                        <p class="stat-label">Resource Types</p>
                    </div>
                    <div class="stat-icon" style="background: rgba(59,130,246,0.15);">
                        <i class="bi bi-box-seam-fill" style="color: var(--blue);"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?= number_format($user_stats['responders'] ?? 0) ?></div>
                        <p class="stat-label">Responders</p>
                    </div>
                    <div class="stat-icon" style="background: rgba(239,68,68,0.15);">
                        <i class="bi bi-shield-fill" style="color: var(--red);"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- User & Volunteer Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?= number_format($user_stats['total_users'] ?? 0) ?></div>
                        <p class="stat-label">Total Users</p>
                    </div>
                    <div class="stat-icon" style="background: rgba(139,92,246,0.15);">
                        <i class="bi bi-people-fill" style="color: var(--purple);"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?= number_format($volunteer_stats['total_volunteers'] ?? 0) ?></div>
                        <p class="stat-label">Total Volunteers</p>
                    </div>
                    <div class="stat-icon" style="background: rgba(59,130,246,0.15);">
                        <i class="bi bi-person-heart" style="color: var(--blue);"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?= number_format($volunteer_stats['available_volunteers'] ?? 0) ?></div>
                        <p class="stat-label">Available Volunteers</p>
                    </div>
                    <div class="stat-icon" style="background: rgba(34,197,94,0.15);">
                        <i class="bi bi-check-circle-fill" style="color: var(--green);"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?= number_format($resource_stats['available_units'] ?? 0) ?></div>
                        <p class="stat-label">Available Units</p>
                    </div>
                    <div class="stat-icon" style="background: rgba(34,197,94,0.15);">
                        <i class="bi bi-box-seam" style="color: var(--green);"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Shelter Occupancy -->
    <?php if (($shelter_stats['total_shelters'] ?? 0) > 0): ?>
    <div class="dashboard-card mb-4">
        <div class="card-header-custom">
            <span><i class="bi bi-building-fill"></i>Shelter Occupancy</span>
            <span class="badge bg-<?= $shelter_occupancy >= 90 ? 'danger' : ($shelter_occupancy >= 70 ? 'warning' : 'success') ?>">
                <?= $shelter_occupancy ?>% Full
            </span>
        </div>
        <div class="card-body p-4">
            <div class="progress-bar-custom">
                <div class="progress-fill" style="width: <?= $shelter_occupancy ?>%; background: <?= $shelter_occupancy >= 90 ? '#ef4444' : ($shelter_occupancy >= 70 ? '#f59e0b' : '#22c55e'); ?>"></div>
            </div>
            <div class="d-flex justify-content-between mt-2">
                <small class="text-muted">Occupied: <?= number_format($shelter_stats['total_occupancy'] ?? 0) ?></small>
                <small class="text-muted">Capacity: <?= number_format($shelter_stats['total_capacity'] ?? 0) ?></small>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Charts -->
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-bar-chart-fill"></i>Weekly Incident Trends</span>
                </div>
                <div class="chart-container">
                    <canvas id="weeklyChart" height="250"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-pie-chart-fill"></i>Incidents by Severity</span>
                </div>
                <div class="chart-container">
                    <canvas id="severityChart" height="250"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Recent Incidents -->
        <div class="col-lg-7">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-exclamation-triangle-fill"></i>Recent Incidents</span>
                    <a href="../incidents/all.php" class="view-all">View All →</a>
                </div>
                <div>
                    <?php if (count($recent_incidents) > 0): ?>
                        <?php foreach ($recent_incidents as $incident): 
                            $severity_class = '';
                            switch($incident['severity']) {
                                case 4: $severity_class = 'critical'; break;
                                case 3: $severity_class = 'high'; break;
                                case 2: $severity_class = 'medium'; break;
                                default: $severity_class = 'low';
                            }
                            $status_class = str_replace('-', '_', $incident['status']);
                        ?>
                            <div class="incident-item" onclick="window.location.href='../incidents/view.php?id=<?= $incident['id'] ?>'">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold mb-1">
                                            #<?= str_pad($incident['id'], 5, '0', STR_PAD_LEFT) ?> - <?= htmlspecialchars($incident['location_name'] ?? 'Unknown location') ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?= ucfirst(str_replace('_', ' ', $incident['incident_type'])) ?> • 
                                            Reported by <?= htmlspecialchars($incident['reporter_name'] ?? 'Anonymous') ?> • 
                                            <?= date('M j, H:i', strtotime($incident['reported_at'])) ?>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="severity-badge severity-<?= $severity_class ?>">
                                            <?= ucfirst($severity_class) ?>
                                        </span>
                                        <span class="status-badge status-<?= $status_class ?> d-block mt-1">
                                            <?= ucfirst(str_replace('-', ' ', $incident['status'])) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">No incidents found</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-lg-5">
            <!-- Response Time -->
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-stopwatch-fill"></i>Response Metrics</span>
                </div>
                <div class="card-body p-4">
                    <div class="text-center">
                        <div class="display-4 fw-bold" style="color: var(--red);">
                            <?= round($response_time['avg_response_hours'] ?? 0) ?>h
                        </div>
                        <p class="text-muted small">Average Response Time</p>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-lightning-charge-fill"></i>Quick Actions</span>
                </div>
                <div class="card-body p-3">
                    <div class="row g-2">
                        <div class="col-6">
                            <a href="../incidents/pending.php" class="btn-action d-block text-center">
                                <i class="bi bi-check2-circle me-1"></i> Verify Incidents
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="users.php" class="btn-action d-block text-center">
                                <i class="bi bi-person-plus me-1"></i> Manage Users
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="../mapping/danger_zones.php" class="btn-action d-block text-center">
                                <i class="bi bi-exclamation-triangle me-1"></i> Danger Zones
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="../mapping/shelters.php" class="btn-action d-block text-center">
                                <i class="bi bi-building me-1"></i> Safe Shelters
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="../mapping/map.php" class="btn-action d-block text-center">
                                <i class="bi bi-map me-1"></i> Live Map
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="../alerts/broadcast.php" class="btn-action d-block text-center">
                                <i class="bi bi-megaphone me-1"></i> Broadcast Alert
                            </a>
                        </div>
                        <div class="col-12 mt-2">
                            <a href="../resources/manage.php" class="btn-action d-block text-center">
                                <i class="bi bi-box-seam me-1"></i> Manage Resources
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Users -->
    <div class="dashboard-card">
        <div class="card-header-custom">
            <span><i class="bi bi-person-plus-fill"></i>Recent Registrations</span>
            <a href="users.php" class="view-all">Manage Users →</a>
        </div>
        <div>
            <?php if (count($recent_users) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_users as $user): ?>
                                <tr onclick="window.location.href='edit_user.php?id=<?= $user['id'] ?>'" style="cursor: pointer;">
                                    <td><?= htmlspecialchars($user['full_name']) ?></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $user['role'] == 'admin' ? 'danger' : ($user['role'] == 'responder' ? 'warning' : 'secondary') ?>">
                                            <?= ucfirst($user['role']) ?>
                                        </span>
                                     </td>
                                    <td>
                                        <span class="badge bg-<?= $user['is_active'] ? 'success' : 'secondary' ?>">
                                            <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                     </td>
                                    <td><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                                 </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center text-muted py-4">No users found</div>
            <?php endif; ?>
        </div>
    </div>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Weekly Activity Chart
    const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
    new Chart(weeklyCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($activity_dates) ?>,
            datasets: [{
                label: 'Incidents',
                data: <?= json_encode($activity_counts) ?>,
                borderColor: '#ef4444',
                backgroundColor: 'rgba(239,68,68,0.1)',
                borderWidth: 2,
                pointBackgroundColor: '#ef4444',
                pointBorderColor: '#fff',
                pointRadius: 4,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { labels: { color: '#94a3b8' } }
            },
            scales: {
                y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8' } },
                x: { grid: { display: false }, ticks: { color: '#94a3b8' } }
            }
        }
    });

    // Severity Distribution Chart
    const severityCtx = document.getElementById('severityChart').getContext('2d');
    new Chart(severityCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($severity_labels) ?>,
            datasets: [{
                data: <?= json_encode($severity_counts) ?>,
                backgroundColor: ['#4ade80', '#60a5fa', '#fbbf24', '#f87171'],
                borderWidth: 0,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: '#94a3b8', font: { size: 11 }, padding: 12 }
                }
            }
        }
    });
</script>
</body>
</html>