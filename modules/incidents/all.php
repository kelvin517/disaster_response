<?php
/**
 * All Incidents List
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Displays all incidents with filtering, sorting, and search capabilities
 * Accessible by admins and responders only
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admins and responders can access
role_guard(['admin', 'responder']);

// Pagination settings
$records_per_page = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$severity_filter = $_GET['severity'] ?? '';
$type_filter = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($status_filter)) {
    $where_conditions[] = "i.status = ?";
    $params[] = $status_filter;
}

if (!empty($severity_filter)) {
    $where_conditions[] = "i.severity = ?";
    $params[] = $severity_filter;
}

if (!empty($type_filter)) {
    $where_conditions[] = "i.incident_type = ?";
    $params[] = $type_filter;
}

if (!empty($search)) {
    $where_conditions[] = "(i.location_name LIKE ? OR i.description LIKE ? OR u.full_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total records count for pagination
$count_sql = "
    SELECT COUNT(*) as total
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id = u.id
    $where_clause
";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Fetch incidents with filters and pagination
$sql = "
    SELECT i.*, 
           u.full_name as reporter_name,
           u.phone as reporter_phone,
           r.full_name as responder_name
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id = u.id
    LEFT JOIN users r ON i.assigned_to = r.id
    $where_clause
    ORDER BY i.reported_at DESC
    LIMIT $offset, $records_per_page
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$incidents = $stmt->fetchAll();

// Get statistics for filters
$stats_sql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'reported' THEN 1 ELSE 0 END) as reported,
        SUM(CASE WHEN status = 'acknowledged' THEN 1 ELSE 0 END) as acknowledged,
        SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN severity = 4 THEN 1 ELSE 0 END) as critical,
        SUM(CASE WHEN severity = 3 THEN 1 ELSE 0 END) as high,
        SUM(CASE WHEN severity = 2 THEN 1 ELSE 0 END) as medium,
        SUM(CASE WHEN severity = 1 THEN 1 ELSE 0 END) as low
    FROM incidents i
";
$stmt = $pdo->query($stats_sql);
$stats = $stmt->fetch();

// Get distinct incident types for filter dropdown
$stmt = $pdo->query("SELECT DISTINCT incident_type FROM incidents ORDER BY incident_type");
$incident_types = $stmt->fetchAll();

// Severity configuration
$severity_config = [
    1 => ['label' => 'Low', 'color' => '#28a745', 'icon' => '🟢', 'class' => 'success'],
    2 => ['label' => 'Medium', 'color' => '#ffc107', 'icon' => '🟡', 'class' => 'warning'],
    3 => ['label' => 'High', 'color' => '#fd7e14', 'icon' => '🟠', 'class' => 'danger'],
    4 => ['label' => 'Critical', 'color' => '#dc3545', 'icon' => '🔴', 'class' => 'danger']
];

// Status configuration
$status_config = [
    'reported' => ['label' => '📋 Reported', 'class' => 'secondary'],
    'acknowledged' => ['label' => '👀 Under Review', 'class' => 'info'],
    'in-progress' => ['label' => '🚑 In Progress', 'class' => 'primary'],
    'resolved' => ['label' => '✅ Resolved', 'class' => 'success'],
    'cancelled' => ['label' => '❌ Cancelled', 'class' => 'secondary'],
    'rejected' => ['label' => '⚠️ Rejected', 'class' => 'danger']
];

// Incident type icons
$type_icons = [
    'flood' => '🌊',
    'fire' => '🔥',
    'earthquake' => '🏚️',
    'landslide' => '⛰️',
    'drought' => '☀️',
    'accident' => '🚗',
    'building_collapse' => '🏗️',
    'disease_outbreak' => '🦠',
    'other' => '⚠️'
];

function safe($value, $default = '—') {
    return !empty($value) ? htmlspecialchars($value) : $default;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Incidents - DisasterResponse</title>
    
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
        
        .page-header {
            background: linear-gradient(135deg, #dc3545, #b91c1c);
            border-radius: 0 0 30px 30px;
            padding: 2rem 0;
            color: white;
            margin-bottom: 2rem;
        }
        
        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            text-align: center;
            transition: transform 0.2s;
            height: 100%;
            cursor: pointer;
        }
        
        .stats-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stats-card.active {
            border: 2px solid #dc3545;
            background: #fff5f5;
        }
        
        .stats-number {
            font-size: 1.8rem;
            font-weight: 800;
        }
        
        .stats-label {
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .incident-table {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .table th {
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1rem;
        }
        
        .table td {
            padding: 0.9rem 1rem;
            vertical-align: middle;
            font-size: 0.85rem;
        }
        
        .severity-badge {
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-badge {
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .incident-row {
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .incident-row:hover {
            background: #f8f9fa;
        }
        
        .pagination .page-link {
            color: #dc3545;
            border-radius: 8px;
            margin: 0 3px;
        }
        
        .pagination .active .page-link {
            background: #dc3545;
            border-color: #dc3545;
            color: white;
        }
        
        .btn-filter {
            border-radius: 20px;
            padding: 0.3rem 1rem;
            font-size: 0.8rem;
        }
        
        .search-box {
            border-radius: 30px;
            padding: 0.5rem 1rem;
            border: 1px solid #dee2e6;
        }
        
        .search-box:focus {
            border-color: #dc3545;
            box-shadow: none;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .incident-table {
            animation: fadeIn 0.3s ease-out;
        }
        
        @media (max-width: 768px) {
            .page-header h1 { font-size: 1.5rem; }
            .stats-number { font-size: 1.2rem; }
            .stats-label { font-size: 0.6rem; }
            .table { font-size: 0.75rem; }
            .table th, .table td { padding: 0.5rem; }
        }
        
        .export-btn {
            border-radius: 20px;
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-modern sticky-top">
    <div class="container">
        <a class="navbar-brand" href="/disaster_response/index.php">
            <i class="bi bi-shield-check me-2"></i>DisasterResponse
        </a>
        <div class="d-flex gap-2">
            <span class="text-muted small d-none d-md-block mt-2">
                <i class="bi bi-person-circle me-1"></i><?= safe($_SESSION['full_name'] ?? 'User') ?>
            </span>
            <a href="pending.php" class="btn btn-outline-warning btn-sm rounded-pill">
                <i class="bi bi-clock-history me-1"></i>Pending
                <?php if (($stats['reported'] ?? 0) > 0): ?>
                    <span class="badge bg-danger ms-1"><?= $stats['reported'] ?></span>
                <?php endif; ?>
            </a>
            <a href="/disaster_response/modules/auth/logout.php" class="btn btn-outline-danger btn-sm rounded-pill">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1 class="fw-bold mb-2">
                    <i class="bi bi-list-ul me-2"></i>All Incidents
                </h1>
                <p class="mb-0 opacity-75">View and manage all emergency reports</p>
            </div>
            <div class="col-md-4 text-end">
                <i class="bi bi-files" style="font-size: 3rem; opacity: 0.3;"></i>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    
    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-2 col-4 mb-2">
            <div class="stats-card <?= $status_filter == '' ? 'active' : '' ?>" onclick="window.location.href='all.php'">
                <div class="stats-number" style="color: #6c757d;"><?= $stats['total'] ?? 0 ?></div>
                <div class="stats-label">Total</div>
            </div>
        </div>
        <div class="col-md-2 col-4 mb-2">
            <div class="stats-card <?= $status_filter == 'reported' ? 'active' : '' ?>" onclick="window.location.href='all.php?status=reported'">
                <div class="stats-number" style="color: #dc3545;"><?= $stats['reported'] ?? 0 ?></div>
                <div class="stats-label">Reported</div>
            </div>
        </div>
        <div class="col-md-2 col-4 mb-2">
            <div class="stats-card <?= $status_filter == 'acknowledged' ? 'active' : '' ?>" onclick="window.location.href='all.php?status=acknowledged'">
                <div class="stats-number" style="color: #fd7e14;"><?= $stats['acknowledged'] ?? 0 ?></div>
                <div class="stats-label">Under Review</div>
            </div>
        </div>
        <div class="col-md-2 col-4 mb-2">
            <div class="stats-card <?= $status_filter == 'in-progress' ? 'active' : '' ?>" onclick="window.location.href='all.php?status=in-progress'">
                <div class="stats-number" style="color: #0ea5e9;"><?= $stats['in_progress'] ?? 0 ?></div>
                <div class="stats-label">In Progress</div>
            </div>
        </div>
        <div class="col-md-2 col-4 mb-2">
            <div class="stats-card <?= $status_filter == 'resolved' ? 'active' : '' ?>" onclick="window.location.href='all.php?status=resolved'">
                <div class="stats-number" style="color: #28a745;"><?= $stats['resolved'] ?? 0 ?></div>
                <div class="stats-label">Resolved</div>
            </div>
        </div>
        <div class="col-md-2 col-4 mb-2">
            <div class="stats-card <?= $status_filter == 'rejected' ? 'active' : '' ?>" onclick="window.location.href='all.php?status=rejected'">
                <div class="stats-number" style="color: #6c757d;"><?= $stats['rejected'] ?? 0 ?></div>
                <div class="stats-label">Rejected</div>
            </div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="row align-items-center g-2">
            <div class="col-md-3">
                <input type="text" id="searchInput" class="form-control search-box" 
                       placeholder="Search by location, reporter, description..." 
                       value="<?= safe($search) ?>">
            </div>
            <div class="col-md-2">
                <select id="severityFilter" class="form-select">
                    <option value="">All Severities</option>
                    <option value="4" <?= $severity_filter == '4' ? 'selected' : '' ?>>🔴 Critical</option>
                    <option value="3" <?= $severity_filter == '3' ? 'selected' : '' ?>>🟠 High</option>
                    <option value="2" <?= $severity_filter == '2' ? 'selected' : '' ?>>🟡 Medium</option>
                    <option value="1" <?= $severity_filter == '1' ? 'selected' : '' ?>>🟢 Low</option>
                </select>
            </div>
            <div class="col-md-2">
                <select id="typeFilter" class="form-select">
                    <option value="">All Types</option>
                    <?php foreach ($incident_types as $type): ?>
                        <option value="<?= $type['incident_type'] ?>" <?= $type_filter == $type['incident_type'] ? 'selected' : '' ?>>
                            <?= ucfirst(str_replace('_', ' ', $type['incident_type'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button id="applyFilters" class="btn btn-danger btn-sm rounded-pill">
                    <i class="bi bi-funnel me-1"></i> Apply Filters
                </button>
                <a href="all.php" class="btn btn-outline-secondary btn-sm rounded-pill">
                    <i class="bi bi-arrow-repeat me-1"></i> Reset
                </a>
            </div>
            <div class="col-md-2 text-end">
                <button id="exportCSV" class="btn btn-outline-success btn-sm export-btn">
                    <i class="bi bi-download me-1"></i> Export CSV
                </button>
            </div>
        </div>
    </div>
    
    <!-- Incidents Table -->
    <div class="incident-table">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>Location</th>
                        <th>Reporter</th>
                        <th>Severity</th>
                        <th>Status</th>
                        <th>Reported At</th>
                        <th>Responder</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($incidents) > 0): ?>
                        <?php foreach ($incidents as $incident): 
                            $severity = $severity_config[$incident['severity']] ?? $severity_config[1];
                            $status = $status_config[$incident['status']] ?? ['label' => ucfirst($incident['status']), 'class' => 'secondary'];
                            $type_icon = $type_icons[$incident['incident_type']] ?? '📍';
                        ?>
                            <tr class="incident-row" onclick="window.location.href='view.php?id=<?= $incident['id'] ?>'">
                                <td class="fw-semibold">#<?= str_pad($incident['id'], 5, '0', STR_PAD_LEFT) ?></td>
                                <td><?= $type_icon ?> <?= ucfirst(str_replace('_', ' ', $incident['incident_type'])) ?></td>
                                <td><?= safe($incident['location_name'], '—') ?></td>
                                <td><?= safe($incident['reporter_name'], 'Anonymous') ?></td>
                                <td>
                                    <span class="severity-badge" style="background: <?= $severity['color'] ?>20; color: <?= $severity['color'] ?>;">
                                        <?= $severity['icon'] ?> <?= $severity['label'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge bg-<?= $status['class'] ?> bg-opacity-10 text-<?= $status['class'] ?>">
                                        <?= $status['label'] ?>
                                    </span>
                                </td>
                                <td><?= date('M j, H:i', strtotime($incident['reported_at'])) ?></td>
                                <td><?= safe($incident['responder_name'], '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <i class="bi bi-inbox fs-1 text-muted d-block mb-2"></i>
                                <p class="text-muted mb-0">No incidents found</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-4">
        <div class="text-muted small">
            Showing <?= $offset + 1 ?> to <?= min($offset + $records_per_page, $total_records) ?> of <?= $total_records ?> incidents
        </div>
        <nav>
            <ul class="pagination mb-0">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page - 1 ?>&status=<?= urlencode($status_filter) ?>&severity=<?= urlencode($severity_filter) ?>&type=<?= urlencode($type_filter) ?>&search=<?= urlencode($search) ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&status=<?= urlencode($status_filter) ?>&severity=<?= urlencode($severity_filter) ?>&type=<?= urlencode($type_filter) ?>&search=<?= urlencode($search) ?>">
                            <?= $i ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&status=<?= urlencode($status_filter) ?>&severity=<?= urlencode($severity_filter) ?>&type=<?= urlencode($type_filter) ?>&search=<?= urlencode($search) ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Apply filters
document.getElementById('applyFilters').addEventListener('click', function() {
    const search = document.getElementById('searchInput').value;
    const severity = document.getElementById('severityFilter').value;
    const type = document.getElementById('typeFilter').value;
    
    let url = 'all.php?';
    if (search) url += `search=${encodeURIComponent(search)}&`;
    if (severity) url += `severity=${severity}&`;
    if (type) url += `type=${encodeURIComponent(type)}&`;
    
    window.location.href = url.slice(0, -1);
});

// Enter key in search box triggers filter
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('applyFilters').click();
    }
});

// Export to CSV
document.getElementById('exportCSV').addEventListener('click', function() {
    const search = document.getElementById('searchInput').value;
    const severity = document.getElementById('severityFilter').value;
    const type = document.getElementById('typeFilter').value;
    
    let url = 'export_incidents.php?';
    if (search) url += `search=${encodeURIComponent(search)}&`;
    if (severity) url += `severity=${severity}&`;
    if (type) url += `type=${encodeURIComponent(type)}&`;
    
    window.location.href = url.slice(0, -1);
});
</script>

</body>
</html>