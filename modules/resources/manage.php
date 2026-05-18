<?php
/**
 * Manage Resource Requests
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows responders to view and manage pending resource requests
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only responders and admins can manage requests
role_guard(['responder', 'admin']);

// Filters
$status_filter = $_GET['status'] ?? 'pending';
$urgency_filter = $_GET['urgency'] ?? '';
$type_filter = $_GET['type'] ?? '';

// Build query
$where_conditions = ["1=1"];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "rr.status = ?";
    $params[] = $status_filter;
}
if ($urgency_filter) {
    $where_conditions[] = "rr.urgency = ?";
    $params[] = $urgency_filter;
}
if ($type_filter) {
    $where_conditions[] = "rr.resource_type = ?";
    $params[] = $type_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Fetch requests
$sql = "
    SELECT rr.*, u.full_name as requester_name, u.phone as requester_phone, 
           i.incident_type, i.location_name as incident_location
    FROM resource_requests rr
    LEFT JOIN users u ON rr.user_id = u.id
    LEFT JOIN incidents i ON rr.incident_id = i.id
    WHERE $where_clause
    ORDER BY 
        CASE rr.urgency 
            WHEN 'critical' THEN 1 
            WHEN 'high' THEN 2 
            WHEN 'medium' THEN 3 
            WHEN 'low' THEN 4 
        END ASC,
        rr.requested_at ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Get statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned,
        SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) as in_transit,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN urgency = 'critical' THEN 1 ELSE 0 END) as critical,
        SUM(CASE WHEN urgency = 'high' THEN 1 ELSE 0 END) as high
    FROM resource_requests
";
$stats = $pdo->query($stats_sql)->fetch();

$urgency_colors = [
    'critical' => 'dark',
    'high' => 'danger',
    'medium' => 'warning',
    'low' => 'success'
];

$resource_types = [
    'food' => '🍲 Food', 'water' => '💧 Water', 'medicine' => '💊 Medicine',
    'shelter' => '🏠 Shelter', 'clothing' => '👕 Clothing', 'blankets' => '🛏️ Blankets',
    'first_aid' => '🩹 First Aid', 'transport' => '🚛 Transport', 'other' => '📦 Other'
];

$page_title = 'Manage Resource Requests';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Resource Requests - DisasterResponse</title>
    
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
        
        .nav-pill {
            padding: 0.35rem 0.85rem;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--muted);
            font-size: 0.8rem;
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
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
        }
        .stat-number { font-size: 1.8rem; font-weight: 800; }
        .stat-label { font-size: 0.7rem; color: var(--muted); text-transform: uppercase; }
        
        .request-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }
        .request-card:hover { border-color: var(--red); }
        
        .urgency-critical { border-left: 4px solid #dc3545; }
        .urgency-high { border-left: 4px solid #fd7e14; }
        .urgency-medium { border-left: 4px solid #ffc107; }
        .urgency-low { border-left: 4px solid #28a745; }
        
        .filter-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .btn-status {
            padding: 0.2rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .stat-number { font-size: 1.2rem; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="../responders/dashboard.php">
            <i class="bi bi-shield-fill-check me-1 brand-accent"></i>Disaster<span class="brand-accent">Response</span>
        </a>
        <div class="d-flex gap-2">
            <a href="manage.php" class="nav-pill active">Resource Requests</a>
            <a href="inventory.php" class="nav-pill">Inventory</a>
            <a href="reports.php" class="nav-pill">Reports</a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-box-seam-fill me-2" style="color: var(--red);"></i>
            Resource Request Management
        </h1>
        <p class="text-muted mt-1">View and fulfill resource requests from affected communities</p>
    </div>
</div>

<div class="container pb-5">
    
    <!-- Statistics Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-2 col-4">
            <div class="stat-card">
                <div class="stat-number" style="color: #ef4444;"><?= $stats['pending'] ?? 0 ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="stat-card">
                <div class="stat-number" style="color: #f59e0b;"><?= $stats['assigned'] ?? 0 ?></div>
                <div class="stat-label">Assigned</div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="stat-card">
                <div class="stat-number" style="color: #3b82f6;"><?= $stats['in_transit'] ?? 0 ?></div>
                <div class="stat-label">In Transit</div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="stat-card">
                <div class="stat-number" style="color: #22c55e;"><?= $stats['delivered'] ?? 0 ?></div>
                <div class="stat-label">Delivered</div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="stat-card">
                <div class="stat-number" style="color: #dc3545;"><?= $stats['critical'] ?? 0 ?></div>
                <div class="stat-label">Critical</div>
            </div>
        </div>
        <div class="col-md-2 col-4">
            <div class="stat-card">
                <div class="stat-number" style="color: #fd7e14;"><?= $stats['high'] ?? 0 ?></div>
                <div class="stat-label">High Urgency</div>
            </div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted">Status</label>
                <select name="status" class="form-select bg-dark text-white border-secondary">
                    <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="assigned" <?= $status_filter == 'assigned' ? 'selected' : '' ?>>Assigned</option>
                    <option value="in_transit" <?= $status_filter == 'in_transit' ? 'selected' : '' ?>>In Transit</option>
                    <option value="delivered" <?= $status_filter == 'delivered' ? 'selected' : '' ?>>Delivered</option>
                    <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Requests</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">Urgency</label>
                <select name="urgency" class="form-select bg-dark text-white border-secondary">
                    <option value="">All Urgencies</option>
                    <option value="critical" <?= $urgency_filter == 'critical' ? 'selected' : '' ?>>Critical</option>
                    <option value="high" <?= $urgency_filter == 'high' ? 'selected' : '' ?>>High</option>
                    <option value="medium" <?= $urgency_filter == 'medium' ? 'selected' : '' ?>>Medium</option>
                    <option value="low" <?= $urgency_filter == 'low' ? 'selected' : '' ?>>Low</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">Resource Type</label>
                <select name="type" class="form-select bg-dark text-white border-secondary">
                    <option value="">All Types</option>
                    <?php foreach ($resource_types as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $type_filter == $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-danger w-100">Apply Filters</button>
            </div>
        </form>
    </div>
    
    <!-- Requests List -->
    <?php if (count($requests) > 0): ?>
        <?php foreach ($requests as $request): ?>
            <div class="request-card urgency-<?= $request['urgency'] ?>">
                <div class="p-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <h6 class="mb-0">#REQ-<?= str_pad($request['id'], 5, '0', STR_PAD_LEFT) ?></h6>
                                <span class="badge bg-<?= $urgency_colors[$request['urgency']] ?>">
                                    <?= ucfirst($request['urgency']) ?> Urgency
                                </span>
                                <span class="badge bg-secondary"><?= ucfirst(str_replace('_', ' ', $request['status'])) ?></span>
                            </div>
                            <div class="mt-2">
                                <strong><i class="bi bi-box-seam me-1"></i><?= $resource_types[$request['resource_type']] ?? ucfirst($request['resource_type']) ?></strong>
                                <span class="text-muted ms-2">x <?= number_format($request['quantity']) ?> units</span>
                            </div>
                            <div class="small text-muted mt-1">
                                <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($request['requester_name'] ?? 'Anonymous') ?>
                                <span class="mx-1">•</span>
                                <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($request['requester_phone'] ?? 'No phone') ?>
                            </div>
                            <?php if ($request['delivery_location']): ?>
                                <div class="small text-muted">
                                    <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($request['delivery_location']) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($request['notes']): ?>
                                <div class="small text-muted mt-1">
                                    <i class="bi bi-chat-text me-1"></i><?= htmlspecialchars(substr($request['notes'], 0, 100)) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <div class="small text-muted">
                                <i class="bi bi-clock me-1"></i><?= date('M j, H:i', strtotime($request['requested_at'])) ?>
                            </div>
                            <?php if ($request['status'] == 'pending'): ?>
                                <a href="fulfill.php?id=<?= $request['id'] ?>" class="btn btn-sm btn-success mt-2 rounded-pill">
                                    <i class="bi bi-check-lg me-1"></i>Fulfill
                                </a>
                            <?php elseif ($request['status'] == 'assigned'): ?>
                                <a href="fulfill.php?id=<?= $request['id'] ?>" class="btn btn-sm btn-primary mt-2 rounded-pill">
                                    <i class="bi bi-truck me-1"></i>Mark In Transit
                                </a>
                            <?php elseif ($request['status'] == 'in_transit'): ?>
                                <a href="fulfill.php?id=<?= $request['id'] ?>" class="btn btn-sm btn-success mt-2 rounded-pill">
                                    <i class="bi bi-check2-all me-1"></i>Mark Delivered
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            <p>No resource requests found matching the criteria.</p>
        </div>
    <?php endif; ?>
    
</div>

</body>
</html>