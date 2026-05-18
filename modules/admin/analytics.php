<?php
/**
 * Admin Analytics Dashboard
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Displays incident trends, response time analytics, and performance metrics
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admin can access
role_guard(['admin']);

// Date range filters
$date_range = $_GET['range'] ?? '30';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime("-{$date_range} days"));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

if ($date_range !== 'custom') {
    $start_date = date('Y-m-d', strtotime("-{$date_range} days"));
    $end_date = date('Y-m-d');
}

// ============================================
// INCIDENT TRENDS (Daily/Monthly)
// ============================================

// Daily incidents for chart
$stmt = $pdo->prepare("
    SELECT 
        DATE(reported_at) as date,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN severity = 4 THEN 1 ELSE 0 END) as critical,
        SUM(CASE WHEN severity = 3 THEN 1 ELSE 0 END) as high
    FROM incidents
    WHERE DATE(reported_at) BETWEEN ? AND ?
    GROUP BY DATE(reported_at)
    ORDER BY date ASC
");
$stmt->execute([$start_date, $end_date]);
$daily_trends = $stmt->fetchAll();

// Prepare chart data
$chart_dates = [];
$chart_totals = [];
$chart_resolved = [];
$chart_critical = [];
foreach ($daily_trends as $trend) {
    $chart_dates[] = date('M j', strtotime($trend['date']));
    $chart_totals[] = $trend['total'];
    $chart_resolved[] = $trend['resolved'];
    $chart_critical[] = $trend['critical'];
}

// ============================================
// RESPONSE TIME METRICS
// ============================================

// Average response time by severity
$stmt = $pdo->prepare("
    SELECT 
        severity,
        AVG(TIMESTAMPDIFF(MINUTE, reported_at, updated_at)) as avg_response_minutes,
        COUNT(*) as incident_count
    FROM incidents
    WHERE status = 'resolved' 
        AND updated_at IS NOT NULL
        AND DATE(reported_at) BETWEEN ? AND ?
    GROUP BY severity
    ORDER BY severity DESC
");
$stmt->execute([$start_date, $end_date]);
$response_by_severity = $stmt->fetchAll();

$response_labels = [];
$response_times = [];
$severity_names = [4 => 'Critical', 3 => 'High', 2 => 'Medium', 1 => 'Low'];
foreach ($response_by_severity as $resp) {
    $response_labels[] = $severity_names[$resp['severity']];
    $response_times[] = round($resp['avg_response_minutes']);
}

// Overall average response time
$stmt = $pdo->prepare("
    SELECT 
        AVG(TIMESTAMPDIFF(MINUTE, reported_at, updated_at)) as overall_avg,
        MIN(TIMESTAMPDIFF(MINUTE, reported_at, updated_at)) as fastest,
        MAX(TIMESTAMPDIFF(MINUTE, reported_at, updated_at)) as slowest
    FROM incidents
    WHERE status = 'resolved' 
        AND updated_at IS NOT NULL
        AND DATE(reported_at) BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$overall_response = $stmt->fetch();

// ============================================
// INCIDENT TYPE DISTRIBUTION
// ============================================

$stmt = $pdo->prepare("
    SELECT 
        incident_type,
        COUNT(*) as count,
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM incidents WHERE DATE(reported_at) BETWEEN ? AND ?), 1) as percentage
    FROM incidents
    WHERE DATE(reported_at) BETWEEN ? AND ?
    GROUP BY incident_type
    ORDER BY count DESC
");
$stmt->execute([$start_date, $end_date, $start_date, $end_date]);
$type_distribution = $stmt->fetchAll();

// ============================================
// RESPONDER PERFORMANCE
// ============================================

$stmt = $pdo->prepare("
    SELECT 
        u.full_name,
        COUNT(i.id) as incidents_handled,
        AVG(TIMESTAMPDIFF(MINUTE, i.reported_at, i.updated_at)) as avg_response_minutes,
        SUM(CASE WHEN i.status = 'resolved' THEN 1 ELSE 0 END) as resolved_count
    FROM users u
    JOIN incidents i ON i.assigned_to = u.id
    WHERE u.role = 'responder'
        AND DATE(i.reported_at) BETWEEN ? AND ?
    GROUP BY u.id
    ORDER BY incidents_handled DESC
    LIMIT 10
");
$stmt->execute([$start_date, $end_date]);
$responder_performance = $stmt->fetchAll();

// ============================================
// STATUS DISTRIBUTION
// ============================================

$stmt = $pdo->prepare("
    SELECT 
        status,
        COUNT(*) as count,
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM incidents WHERE DATE(reported_at) BETWEEN ? AND ?), 1) as percentage
    FROM incidents
    WHERE DATE(reported_at) BETWEEN ? AND ?
    GROUP BY status
    ORDER BY count DESC
");
$stmt->execute([$start_date, $end_date, $start_date, $end_date]);
$status_distribution = $stmt->fetchAll();

$status_labels = [];
$status_counts = [];
foreach ($status_distribution as $status) {
    $status_labels[] = ucfirst(str_replace('-', ' ', $status['status']));
    $status_counts[] = $status['count'];
}

// ============================================
// HOURLY INCIDENT PATTERNS
// ============================================

$stmt = $pdo->prepare("
    SELECT 
        HOUR(reported_at) as hour,
        COUNT(*) as count
    FROM incidents
    WHERE DATE(reported_at) BETWEEN ? AND ?
    GROUP BY HOUR(reported_at)
    ORDER BY hour ASC
");
$stmt->execute([$start_date, $end_date]);
$hourly_patterns = $stmt->fetchAll();

$hourly_labels = [];
$hourly_counts = [];
for ($i = 0; $i < 24; $i++) {
    $hourly_labels[] = sprintf("%02d:00", $i);
    $hourly_counts[$i] = 0;
}
foreach ($hourly_patterns as $pattern) {
    $hourly_counts[$pattern['hour']] = $pattern['count'];
}
$hourly_counts = array_values($hourly_counts);

// ============================================
// WEEKDAY PATTERNS
// ============================================

$stmt = $pdo->prepare("
    SELECT 
        DAYOFWEEK(reported_at) as dow,
        COUNT(*) as count
    FROM incidents
    WHERE DATE(reported_at) BETWEEN ? AND ?
    GROUP BY DAYOFWEEK(reported_at)
    ORDER BY dow ASC
");
$stmt->execute([$start_date, $end_date]);
$weekday_patterns = $stmt->fetchAll();

$weekday_labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$weekday_counts = array_fill(0, 7, 0);
foreach ($weekday_patterns as $pattern) {
    $index = $pattern['dow'] - 1;
    $weekday_counts[$index] = $pattern['count'];
}

$page_title = 'Analytics Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - DisasterResponse</title>
    
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
            --purple: #8b5cf6;
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
        .nav-pill.active { border-color: var(--red); color: var(--red); background: rgba(239,68,68,0.1); }

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
        .stat-number { font-size: 2rem; font-weight: 800; }
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
            align-items: center;
            justify-content: space-between;
        }
        .card-header-custom i { color: var(--red); margin-right: 8px; }

        .filter-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .chart-container { padding: 1.25rem; }

        @media (max-width: 768px) {
            .stat-number { font-size: 1.3rem; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="dashboard.php">
            <i class="bi bi-shield-lock-fill me-1 brand-accent"></i>Disaster<span class="brand-accent">Response</span>
            <span class="badge bg-danger ms-2" style="font-size: 0.6rem;">ADMIN</span>
        </a>
        <div class="d-flex gap-2">
            <a href="dashboard.php" class="nav-pill">Dashboard</a>
            <a href="analytics.php" class="nav-pill active">Analytics</a>
            <a href="export.php" class="nav-pill">Export</a>
            <a href="system_logs.php" class="nav-pill">Logs</a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-graph-up me-2" style="color: var(--red);"></i>
            Analytics Dashboard
        </h1>
        <p class="text-muted mt-1">Incident trends, response metrics, and performance analytics</p>
    </div>
</div>

<div class="container pb-5">
    
    <!-- Date Range Filter -->
    <div class="filter-bar">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted">Date Range</label>
                <select name="range" class="form-select bg-dark text-white border-secondary" onchange="this.form.submit()">
                    <option value="7" <?= $date_range == '7' ? 'selected' : '' ?>>Last 7 days</option>
                    <option value="14" <?= $date_range == '14' ? 'selected' : '' ?>>Last 14 days</option>
                    <option value="30" <?= $date_range == '30' ? 'selected' : '' ?>>Last 30 days</option>
                    <option value="90" <?= $date_range == '90' ? 'selected' : '' ?>>Last 90 days</option>
                    <option value="365" <?= $date_range == '365' ? 'selected' : '' ?>>Last year</option>
                    <option value="custom" <?= $date_range == 'custom' ? 'selected' : '' ?>>Custom range</option>
                </select>
            </div>
            <?php if ($date_range == 'custom'): ?>
            <div class="col-md-3">
                <label class="form-label small text-muted">Start Date</label>
                <input type="date" name="start_date" class="form-control bg-dark text-white border-secondary" value="<?= $start_date ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">End Date</label>
                <input type="date" name="end_date" class="form-control bg-dark text-white border-secondary" value="<?= $end_date ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-danger w-100">Apply Filter</button>
            </div>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Summary Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--red);"><?= array_sum($chart_totals) ?></div>
                <div class="stat-label">Total Incidents</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--green);"><?= round($overall_response['overall_avg'] ?? 0) ?> min</div>
                <div class="stat-label">Avg Response Time</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--blue);"><?= $overall_response['fastest'] ?? 0 ?> min</div>
                <div class="stat-label">Fastest Response</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--amber);"><?= $overall_response['slowest'] ?? 0 ?> min</div>
                <div class="stat-label">Slowest Response</div>
            </div>
        </div>
    </div>
    
    <!-- Incident Trends Chart -->
    <div class="dashboard-card">
        <div class="card-header-custom">
            <span><i class="bi bi-bar-chart-steps"></i>Incident Trends</span>
        </div>
        <div class="chart-container">
            <canvas id="trendsChart" height="300"></canvas>
        </div>
    </div>
    
    <div class="row">
        <!-- Response Time by Severity -->
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-clock-history"></i>Response Time by Severity</span>
                </div>
                <div class="chart-container">
                    <canvas id="responseChart" height="250"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Incident Type Distribution -->
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-pie-chart"></i>Incident Types</span>
                </div>
                <div class="chart-container">
                    <canvas id="typeChart" height="250"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Hourly Patterns -->
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-clock"></i>Hourly Incident Patterns</span>
                </div>
                <div class="chart-container">
                    <canvas id="hourlyChart" height="250"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Weekly Patterns -->
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <span><i class="bi bi-calendar-week"></i>Day of Week Patterns</span>
                </div>
                <div class="chart-container">
                    <canvas id="weeklyChart" height="250"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Responder Performance Table -->
    <div class="dashboard-card">
        <div class="card-header-custom">
            <span><i class="bi bi-trophy"></i>Top Responders</span>
        </div>
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Responder</th>
                        <th>Incidents Handled</th>
                        <th>Avg Response Time</th>
                        <th>Resolution Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($responder_performance) > 0): ?>
                        <?php foreach ($responder_performance as $index => $responder): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($responder['full_name']) ?></td>
                                <td><?= $responder['incidents_handled'] ?></td>
                                <td><?= round($responder['avg_response_minutes']) ?> min</td>
                                <td><?= round(($responder['resolved_count'] / max($responder['incidents_handled'], 1)) * 100) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted">No data available</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Trends Chart
    new Chart(document.getElementById('trendsChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($chart_dates) ?>,
            datasets: [
                {
                    label: 'Total Incidents',
                    data: <?= json_encode($chart_totals) ?>,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239,68,68,0.1)',
                    fill: true,
                    tension: 0.3
                },
                {
                    label: 'Resolved',
                    data: <?= json_encode($chart_resolved) ?>,
                    borderColor: '#22c55e',
                    backgroundColor: 'rgba(34,197,94,0.05)',
                    fill: true,
                    tension: 0.3
                },
                {
                    label: 'Critical',
                    data: <?= json_encode($chart_critical) ?>,
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245,158,11,0.05)',
                    fill: true,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { labels: { color: '#94a3b8' } } },
            scales: { y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8' } } }
        }
    });

    // Response Time Chart
    new Chart(document.getElementById('responseChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($response_labels) ?>,
            datasets: [{
                label: 'Avg Response Time (minutes)',
                data: <?= json_encode($response_times) ?>,
                backgroundColor: ['#f87171', '#fb923c', '#fbbf24', '#4ade80'],
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { labels: { color: '#94a3b8' } } },
            scales: { y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8' } } }
        }
    });

    // Type Distribution Chart
    new Chart(document.getElementById('typeChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_column($type_distribution, 'incident_type')) ?>,
            datasets: [{
                data: <?= json_encode(array_column($type_distribution, 'count')) ?>,
                backgroundColor: ['#ef4444', '#f59e0b', '#eab308', '#22c55e', '#06b6d4', '#8b5cf6', '#ec4899']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { position: 'bottom', labels: { color: '#94a3b8' } } }
        }
    });

    // Hourly Patterns Chart
    new Chart(document.getElementById('hourlyChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($hourly_labels) ?>,
            datasets: [{
                label: 'Incidents',
                data: <?= json_encode($hourly_counts) ?>,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59,130,246,0.1)',
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { labels: { color: '#94a3b8' } } },
            scales: { y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8' } } }
        }
    });

    // Weekly Patterns Chart
    new Chart(document.getElementById('weeklyChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($weekday_labels) ?>,
            datasets: [{
                label: 'Incidents',
                data: <?= json_encode($weekday_counts) ?>,
                backgroundColor: '#8b5cf6',
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { labels: { color: '#94a3b8' } } },
            scales: { y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8' } } }
        }
    });
</script>
</body>
</html>