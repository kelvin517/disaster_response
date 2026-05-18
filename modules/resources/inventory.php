<?php
/**
 * Resource Inventory Management
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows responders to view and manage resource inventory levels
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

role_guard(['responder', 'admin']);

// Handle inventory update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_quantity') {
        $resource_id = (int)$_POST['resource_id'];
        $new_quantity = (int)$_POST['quantity'];
        
        $stmt = $pdo->prepare("UPDATE resources SET quantity = ?, updated_at = NOW() WHERE id = ?");
        if ($stmt->execute([$new_quantity, $resource_id])) {
            $success = "Inventory updated successfully.";
        } else {
            $error = "Failed to update inventory.";
        }
    }
    
    if ($_POST['action'] === 'add_resource') {
        $resource_type = $_POST['resource_type'];
        $quantity = (int)$_POST['quantity'];
        $status = $_POST['status'];
        
        $stmt = $pdo->prepare("
            INSERT INTO resources (resource_type, quantity, status, updated_at) 
            VALUES (?, ?, ?, NOW())
        ");
        if ($stmt->execute([$resource_type, $quantity, $status])) {
            $success = "Resource added successfully.";
        } else {
            $error = "Failed to add resource.";
        }
    }
}

// Fetch inventory grouped by resource type
$stmt = $pdo->prepare("
    SELECT 
        resource_type,
        SUM(quantity) as total_quantity,
        COUNT(*) as item_count,
        MAX(updated_at) as last_updated,
        GROUP_CONCAT(DISTINCT status) as statuses
    FROM resources
    GROUP BY resource_type
    ORDER BY resource_type
");
$stmt->execute();
$inventory = $stmt->fetchAll();

// Get low stock alerts (quantity < 100)
$stmt = $pdo->prepare("
    SELECT resource_type, SUM(quantity) as total_quantity
    FROM resources
    GROUP BY resource_type
    HAVING total_quantity < 100
");
$stmt->execute();
$low_stock = $stmt->fetchAll();

$resource_icons = [
    'food' => '🍲', 'water' => '💧', 'medicine' => '💊', 'shelter' => '🏠',
    'clothing' => '👕', 'blankets' => '🛏️', 'first_aid' => '🩹', 'transport' => '🚛',
    'rescue_team' => '🪢', 'medical_team' => '👨‍⚕️', 'other' => '📦'
];

$page_title = 'Resource Inventory';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resource Inventory - DisasterResponse</title>
    
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
        
        .inventory-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 1rem;
            overflow: hidden;
        }
        
        .quantity-display {
            font-size: 2rem;
            font-weight: 800;
        }
        
        .low-stock { color: #f87171; }
        .medium-stock { color: #fbbf24; }
        .good-stock { color: #4ade80; }
        
        .chart-container { padding: 1.25rem; }
        
        @media (max-width: 768px) {
            .stat-number { font-size: 1.2rem; }
            .quantity-display { font-size: 1.5rem; }
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
            <a href="manage.php" class="nav-pill">Requests</a>
            <a href="inventory.php" class="nav-pill active">Inventory</a>
            <a href="reports.php" class="nav-pill">Reports</a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-box-seam-fill me-2" style="color: var(--red);"></i>
            Resource Inventory
        </h1>
        <p class="text-muted mt-1">Manage disaster relief supplies and resources</p>
    </div>
</div>

<div class="container pb-5">
    
    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= $success ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Low Stock Alert -->
    <?php if (count($low_stock) > 0): ?>
        <div class="alert alert-warning mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Low Stock Alert!</strong> The following resources are running low:
            <?php foreach ($low_stock as $stock): ?>
                <span class="badge bg-warning text-dark ms-1"><?= ucfirst($stock['resource_type']) ?> (<?= $stock['total_quantity'] ?> left)</span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Inventory List -->
        <div class="col-lg-7">
            <?php foreach ($inventory as $item): 
                $icon = $resource_icons[$item['resource_type']] ?? '📦';
                $quantity = $item['total_quantity'];
                $stock_class = $quantity < 100 ? 'low-stock' : ($quantity < 500 ? 'medium-stock' : 'good-stock');
                $percentage = min(($quantity / 1000) * 100, 100);
            ?>
            <div class="inventory-card">
                <div class="p-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <h5 class="mb-1"><?= $icon ?> <?= ucfirst($item['resource_type']) ?></h5>
                            <div class="small text-muted">
                                <?= $item['item_count'] ?> item(s) • Last updated: <?= date('M j, H:i', strtotime($item['last_updated'])) ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="quantity-display <?= $stock_class ?>"><?= number_format($quantity) ?></div>
                            <div class="small text-muted">units available</div>
                        </div>
                    </div>
                    <div class="progress mt-2" style="height: 6px;">
                        <div class="progress-bar bg-<?= $quantity < 100 ? 'danger' : ($quantity < 500 ? 'warning' : 'success') ?>" 
                             style="width: <?= $percentage ?>%"></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (count($inventory) == 0): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-box-seam fs-1 d-block mb-2"></i>
                    <p>No resources in inventory. Add resources using the form.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Add Resource Form -->
        <div class="col-lg-5">
            <div class="inventory-card">
                <div class="p-3" style="background: var(--surface2);">
                    <h5 class="mb-3"><i class="bi bi-plus-circle me-2 text-danger"></i>Add Resource</h5>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_resource">
                        <div class="mb-3">
                            <label class="form-label small">Resource Type</label>
                            <select name="resource_type" class="form-select bg-dark text-white border-secondary" required>
                                <option value="">Select Type</option>
                                <option value="food">🍲 Food Supplies</option>
                                <option value="water">💧 Water</option>
                                <option value="medicine">💊 Medicine</option>
                                <option value="shelter">🏠 Shelter Materials</option>
                                <option value="clothing">👕 Clothing</option>
                                <option value="blankets">🛏️ Blankets</option>
                                <option value="first_aid">🩹 First Aid Kits</option>
                                <option value="transport">🚛 Transport Vehicles</option>
                                <option value="other">📦 Other Supplies</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Quantity</label>
                            <input type="number" name="quantity" class="form-control bg-dark text-white border-secondary" required min="1">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Status</label>
                            <select name="status" class="form-select bg-dark text-white border-secondary">
                                <option value="available">Available</option>
                                <option value="reserved">Reserved</option>
                                <option value="depleted">Depleted</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-danger w-100 rounded-pill">
                            <i class="bi bi-plus-lg me-2"></i>Add to Inventory
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Inventory Summary Chart -->
            <div class="inventory-card">
                <div class="p-3" style="background: var(--surface2);">
                    <h5 class="mb-3"><i class="bi bi-pie-chart me-2 text-danger"></i>Inventory Distribution</h5>
                    <canvas id="inventoryChart" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const inventoryData = <?php 
        $labels = [];
        $data = [];
        foreach ($inventory as $item) {
            $labels[] = ucfirst($item['resource_type']);
            $data[] = $item['total_quantity'];
        }
        echo json_encode(['labels' => $labels, 'data' => $data]);
    ?>;
    
    new Chart(document.getElementById('inventoryChart'), {
        type: 'doughnut',
        data: {
            labels: inventoryData.labels,
            datasets: [{
                data: inventoryData.data,
                backgroundColor: ['#ef4444', '#f59e0b', '#eab308', '#22c55e', '#06b6d4', '#8b5cf6', '#ec4899'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { position: 'bottom', labels: { color: '#94a3b8', font: { size: 10 } } } }
        }
    });
</script>
</body>
</html>