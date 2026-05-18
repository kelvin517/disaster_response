<?php
/**
 * Resource Utilization Reports
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Displays resource utilization charts and allows export of reports
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only responders and admins can access
role_guard(['responder', 'admin']);

$date_range = $_GET['range'] ?? '30';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime("-{$date_range} days"));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

if ($date_range !== 'custom') {
    $start_date = date('Y-m-d', strtotime("-{$date_range} days"));
    $end_date = date('Y-m-d');
}

// Resource request trends
$stmt = $pdo->prepare("
    SELECT 
        DATE(requested_at) as date,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered
    FROM resource_requests
    WHERE DATE(requested_at) BETWEEN ? AND ?
    GROUP BY DATE(requested_at)
    ORDER BY date ASC
");
$stmt->execute([$start_date, $end_date]);
$trends = $stmt->fetchAll();

$trend_dates = [];
$trend_totals = [];
$trend_delivered = [];
foreach ($trends as $trend) {
    $trend_dates[] = date('M j', strtotime($trend['date']));
    $trend_totals[] = $trend['total'];
    $trend_delivered[] = $trend['delivered'];
}

// Resource type distribution
$stmt = $pdo->prepare("
    SELECT 
        resource_type,
        COUNT(*) as request_count,
        SUM(quantity) as total_quantity,
        SUM(CASE WHEN status = 'delivered' THEN quantity ELSE 0 END) as delivered_quantity
    FROM resource_requests
    WHERE DATE(requested_at) BETWEEN ? AND ?
    GROUP BY resource_type
    ORDER BY request_count DESC
");
$stmt->execute([$start_date, $end_date]);
$type_distribution = $stmt->fetchAll();

// Urgency distribution
$stmt = $pdo->prepare("
    SELECT 
        urgency,
        COUNT(*) as count,
        ROUND(AVG(TIMESTAMPDIFF(HOUR, requested_at, 
            CASE WHEN status = 'delivered' THEN updated_at ELSE NOW() END))) as avg_response_hours
    FROM resource_requests
    WHERE DATE(requested_at) BETWEEN ? AND ?
    GROUP BY urgency
");
$stmt->execute([$start_date, $end_date]);
$urgency_stats = $stmt->fetchAll();

// Status distribution
$stmt = $pdo->prepare("
    SELECT 
        status,
        COUNT(*) as count
    FROM resource_requests
    WHERE DATE(requested_at) BETWEEN ? AND ?
    GROUP BY status
");
$stmt->execute([$start_date, $end_date]);
$status_distribution = $stmt->fetchAll();

$resource_types = [
    'food' => '🍲 Food', 'water' => '💧 Water', 'medicine' => '💊 Medicine',
    'shelter' => '🏠 Shelter', 'clothing' => '👕 Clothing', 'blankets' => '🛏️ Blankets',
    'first_aid' => '🩹 First Aid', 'transport' => '🚛 Transport', 'rescue' => '🪢 Rescue',
    'other' => '📦 Other'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resource Reports - DisasterResponse</title>
    
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
        
        .filter-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
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
            <i class="bi bi-graph-up me-1 brand-accent"></i>Resource<span class="brand-accent">Reports</span>
        </a>
        <div class="d-flex gap-2">
            <a href="manage.php" class="nav-pill">Requests</a>
            <a href="inventory.php" class="nav-pill">Inventory</a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header no-print">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-graph-up me-2" style="color: var(--red);"></i>
            Resource Utilization Reports
        </h1>
        <p class="text-muted mt-1">Analytics and insights on resource requests and distribution</p>
    </div>
</div>

<div class="container pb-5">
    
    <!-- Date Filter -->
    <div class="filter-bar no-print">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted">Date Range</label>
                <select name="range" class="form-select bg-dark text-white border-secondary" onchange="this.form.submit()">
                    <option value="7" <?= $date_range == '7' ? 'selected' : '' ?>>Last 7 days</option>
                    <option value="30" <?= $date_range == '30' ? 'selected' : '' ?>>Last 30 days</option>
                    <option value="90" <?= $date_range == '90' ? 'selected' : '' ?>>Last 90 days</option>
                    <option value="365" <?= $date_range == '365' ? 'selected' : '' ?>>Last year</option>
                    <option value="custom" <?= $date_range == 'custom' ? 'selected' : '' ?>>Custom range</option>
                </select>
            </div>
            <?php if ($date_range == 'custom'): ?>
            <div class="col-md-3">
                <input type="date" name="start_date" class="form-control bg-dark text-white border-secondary" value="<?= $start_date ?>">
            </div>
            <div class="col-md-3">
                <input type="date" name="end_date" class="form-control bg-dark text-white border-secondary" value="<?= $end_date ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-danger w-100">Apply Filter</button>
            </div>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Export Button -->
    <div class="text-end mb-3 no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Print Report
        </button>
    </div>
    
    <!-- Request Trends Chart -->
    <div class="dashboard-card">
        <div class="card-header-custom">
            <i class="bi bi-bar-chart-steps me-2" style="color: var(--red);"></i>Request Trends
        </div>
        <div class="chart-container">
            <canvas id="trendsChart" height="250"></canvas>
        </div>
    </div>
    
    <div class="row">
        <!-- Type Distribution -->
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <i class="bi bi-pie-chart me-2" style="color: var(--red);"></i>Requests by Resource Type
                </div>
                <div class="chart-container">
                    <canvas id="typeChart" height="250"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Urgency Distribution -->
        <div class="col-lg-6">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <i class="bi bi-speedometer2 me-2" style="color: var(--red);"></i>Urgency & Response Time
                </div>
                <div class="chart-container">
                    <canvas id="urgencyChart" height="250"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Status Distribution -->
    <div class="dashboard-card">
        <div class="card-header-custom">
            <i class="bi bi-pie-chart-fill me-2" style="color: var(--red);"></i>Request Status Distribution
        </div>
        <div class="chart-container">
            <canvas id="statusChart" height="200"></canvas>
        </div>
    </div>
    
    <!-- Summary Table -->
    <div class="dashboard-card">
        <div class="card-header-custom">
            <i class="bi bi-table me-2" style="color: var(--red);"></i>Resource Type Summary
        </div>
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr><th>Resource Type</th><th>Request Count</th><th>Total Quantity</th><th>Delivered</th><th>Fulfillment Rate</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($type_distribution as $type): ?>
                        <tr>
                            <td><?= $resource_types[$type['resource_type']] ?? $type['resource_type'] ?></td>
                            <td><?= $type['request_count'] ?></td>
                            <td><?= number_format($type['total_quantity']) ?></td>
                            <td><?= number_format($type['delivered_quantity']) ?></td>
                            <td>
                                <?php $rate = $type['total_quantity'] > 0 ? round(($type['delivered_quantity'] / $type['total_quantity']) * 100) : 0; ?>
                                <div class="progress" style="height: 6px; width: 100px;">
                                    <div class="progress-bar bg-success" style="width: <?= $rate ?>%"></div>
                                </div>
                                <small><?= $rate ?>%</small>
                             </td>
                         </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Trends Chart
    new Chart(document.getElementById('trendsChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($trend_dates) ?>,
            datasets: [
                { label: 'Total Requests', data: <?= json_encode($trend_totals) ?>, borderColor: '#ef4444', fill: true },
                { label: 'Delivered', data: <?= json_encode($trend_delivered) ?>, borderColor: '#22c55e', fill: true }
            ]
        },
        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { labels: { color: '#94a3b8' } } } }
    });

    // Type Distribution Chart
    new Chart(document.getElementById('typeChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_column($type_distribution, 'resource_type')) ?>,
            datasets: [{ data: <?= json_encode(array_column($type_distribution, 'request_count')) ?>, backgroundColor: ['#ef4444','#f59e0b','#eab308','#22c55e','#06b6d4','#8b5cf6'] }]
        },
        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom', labels: { color: '#94a3b8' } } } }
    });

    // Urgency Chart
    new Chart(document.getElementById('urgencyChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($urgency_stats, 'urgency')) ?>,
            datasets: [
                { label: 'Requests', data: <?= json_encode(array_column($urgency_stats, 'count')) ?>, backgroundColor: '#ef4444' },
                { label: 'Avg Response (hrs)', data: <?= json_encode(array_column($urgency_stats, 'avg_response_hours')) ?>, backgroundColor: '#3b82f6', yAxisID: 'y1' }
            ]
        },
        options: { responsive: true, maintainAspectRatio: true, scales: { y1: { position: 'right', grid: { drawOnChartArea: false } } } }
    });

    // Status Distribution Chart
    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_column($status_distribution, 'status')) ?>,
            datasets: [{ data: <?= json_encode(array_column($status_distribution, 'count')) ?>, backgroundColor: ['#f59e0b','#3b82f6','#22c55e','#ef4444','#6c757d'] }]
        },
        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom', labels: { color: '#94a3b8' } } } }
    });
</script>
</body>
</html>