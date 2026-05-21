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
<title>Resource Inventory — DisasterResponse</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ═══ TOKENS ══════════════════════════════════════════════════ */
:root {
  --bg:         #f0f2f5;
  --surface:    #ffffff;
  --surface-2:  #f7f8fa;
  --border:     #e2e5ea;
  --border-2:   #d0d4db;

  --navy:       #0f1b2d;
  --navy-2:     #1a2b42;

  --red:        #e8271d;
  --red-light:  #fff0ef;
  --amber:      #d97706;
  --amber-light:#fffbeb;
  --blue:       #1d6ef5;
  --blue-light: #eff5ff;
  --green:      #16a34a;
  --green-light:#f0fdf4;
  --teal:       #0891b2;
  --teal-light: #ecfeff;
  --purple:     #7c3aed;
  --purple-light:#f5f3ff;

  --text:       #0f1b2d;
  --text-2:     #374151;
  --muted:      #6b7280;
  --muted-2:    #9ca3af;

  --ff-head: 'Barlow Condensed', sans-serif;
  --ff-body: 'Barlow', sans-serif;
  --ff-mono: 'IBM Plex Mono', monospace;

  --r:       8px;
  --r-lg:    12px;
  --shadow:  0 1px 3px rgba(15,27,45,.08), 0 4px 16px rgba(15,27,45,.06);
  --shadow-lg:0 4px 24px rgba(15,27,45,.12);
  --ease:    .18s cubic-bezier(.4,0,.2,1);
}

*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }
body { font-family: var(--ff-body); background: var(--bg); color: var(--text); font-size: 14px; min-height: 100vh; }

::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-2); border-radius: 4px; }

/* ─── TOPBAR ─────────────────────────────────────────────── */
.topbar {
  background: var(--navy);
  height: 54px;
  display: flex; align-items: stretch;
  position: sticky; top: 0; z-index: 300;
  box-shadow: 0 2px 12px rgba(15,27,45,.35);
}
.brand {
  display: flex; align-items: center; gap: .5rem;
  padding: 0 2rem 0 1.25rem;
  background: var(--red);
  text-decoration: none;
  clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 100%, 0 100%);
  flex-shrink: 0;
}
.brand-text { font-family: var(--ff-head); font-weight: 800; font-size: 1.1rem; color: #fff; text-transform: uppercase; letter-spacing: .03em; }
.brand-sub  { font-family: var(--ff-mono); font-size: .5rem; font-weight: 600; color: rgba(255,255,255,.65); letter-spacing: .12em; text-transform: uppercase; display: block; margin-top: -2px; }
.nav-area {
  display: flex; align-items: center; padding: 0 .75rem; gap: .1rem; flex: 1;
  overflow-x: auto;
}
.nav-area::-webkit-scrollbar { height:0; }
.npill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .3rem .75rem; border-radius: 5px;
  color: rgba(255,255,255,.6); font-size: .78rem; font-weight: 500;
  text-decoration: none; white-space: nowrap;
  transition: all var(--ease);
}
.npill:hover { color: #fff; background: rgba(255,255,255,.1); }
.npill.active { color: #fff; background: rgba(255,255,255,.15); }
.logout-btn {
  display: flex; align-items: center; gap: .3rem;
  margin: auto 1.25rem;
  padding: .3rem .7rem; border-radius: 5px;
  border: 1px solid rgba(232,39,29,.4); background: rgba(232,39,29,.12);
  color: #ff7a74; font-size: .75rem; font-weight: 600;
  text-decoration: none; white-space: nowrap;
}
.logout-btn:hover { background: var(--red); color: #fff; border-color: var(--red); }

/* ─── HERO HEADER ─────────────────────────────────────────── */
.page-hero {
  background: var(--navy);
  padding: 1.4rem 0;
  border-bottom: 3px solid var(--red);
  position: relative; overflow: hidden;
}
.page-hero::before {
  content: '';
  position: absolute; right: -60px; top: -60px;
  width: 280px; height: 280px;
  background: radial-gradient(circle, rgba(232,39,29,.12) 0%, transparent 65%);
  pointer-events: none;
}
.hero-eyebrow {
  font-family: var(--ff-mono); font-size: .62rem; font-weight: 600;
  letter-spacing: .16em; text-transform: uppercase;
  color: var(--red); margin-bottom: .3rem;
}
.hero-title {
  font-family: var(--ff-head); font-weight: 800; font-size: 1.8rem;
  color: #fff; letter-spacing: .02em; text-transform: uppercase; line-height: 1.1;
}
.hero-sub { color: rgba(255,255,255,.45); font-size: .8rem; margin-top: .25rem; font-family: var(--ff-mono); }

/* ─── PAGE ────────────────────────────────────────────────── */
.page { max-width: 1400px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }

/* ─── STAT CARD ───────────────────────────────────────────── */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: .85rem;
  margin-bottom: 1.5rem;
}
@media (max-width: 900px) { .kpi-grid { grid-template-columns: repeat(2,1fr); } }

.kpi {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 1rem;
  text-align: center;
  box-shadow: var(--shadow);
  transition: all var(--ease);
}
.kpi:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
.kpi-num { font-family: var(--ff-head); font-size: 1.6rem; font-weight: 800; line-height: 1; }
.kpi-lbl { font-size: .67rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-top: .2rem; }

/* ─── ALERT BANNER ────────────────────────────────────────── */
.alert-banner {
  background: var(--amber-light);
  border-left: 4px solid var(--amber);
  border-radius: var(--r);
  padding: .8rem 1rem;
  margin-bottom: 1.5rem;
  display: flex;
  align-items: center;
  gap: .75rem;
  flex-wrap: wrap;
}
.alert-banner i { color: var(--amber); font-size: 1.2rem; }
.alert-banner .badge {
  background: var(--amber);
  color: #fff;
  padding: .2rem .7rem;
  border-radius: 20px;
  font-size: .7rem;
}

/* ─── INVENTORY CARD ──────────────────────────────────────── */
.inventory-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  margin-bottom: 1rem;
  box-shadow: var(--shadow);
  overflow: hidden;
  transition: all var(--ease);
}
.inventory-card:hover { transform: translateX(4px); border-color: var(--red); }
.inventory-header {
  padding: 1rem;
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: .5rem;
}
.inventory-title {
  font-weight: 700;
  font-size: 1rem;
  display: flex;
  align-items: center;
  gap: .5rem;
}
.inventory-title i { font-size: 1.2rem; }
.inventory-body { padding: 1rem; }
.quantity-display {
  font-size: 2.2rem;
  font-weight: 800;
  font-family: var(--ff-head);
  letter-spacing: -.02em;
}
.low-stock { color: var(--red); }
.medium-stock { color: var(--amber); }
.good-stock { color: var(--green); }
.stock-status { font-size: .7rem; margin-top: .2rem; }
.progress-track {
  height: 6px;
  background: var(--bg);
  border-radius: 4px;
  overflow: hidden;
  margin-top: .75rem;
}
.progress-fill { height: 100%; border-radius: 4px; transition: width .3s ease; }

/* ─── FORM CARD ───────────────────────────────────────────── */
.form-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  margin-bottom: 1rem;
  box-shadow: var(--shadow);
  overflow: hidden;
}
.form-header {
  padding: 1rem;
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: .5rem;
}
.form-body { padding: 1rem; }
.form-label {
  font-family: var(--ff-head);
  font-size: .7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--muted);
  margin-bottom: .3rem;
}
.form-select, .form-input {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: .6rem .8rem;
  font-size: .85rem;
  color: var(--text);
  width: 100%;
  outline: none;
}
.form-select:focus, .form-input:focus { border-color: var(--blue); }
.btn-submit {
  background: var(--red);
  border: none;
  border-radius: 30px;
  padding: .7rem;
  font-weight: 700;
  font-size: .8rem;
  text-transform: uppercase;
  width: 100%;
  transition: all var(--ease);
}
.btn-submit:hover { background: #c82333; transform: translateY(-1px); }

/* Chart container */
.chart-wrap { padding: 1rem; }

@keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
.inventory-card, .form-card, .kpi { animation: fadeUp .3s ease-out; }

@media (max-width: 768px) {
  .hero-title { font-size: 1.35rem; }
  .inventory-title { font-size: .9rem; }
  .quantity-display { font-size: 1.6rem; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar no-print">
  <a class="brand" href="../responders/dashboard.php">
    <i class="bi bi-box-seam-fill" style="color:#fff;font-size:1.1rem"></i>
    <div>
      <span class="brand-text">Disaster<span style="opacity:.7">Response</span></span>
      <span class="brand-sub">Resource Hub</span>
    </div>
  </a>
  <div class="nav-area">
    <a href="manage.php" class="npill"><i class="bi bi-list-check"></i> Requests</a>
    <a href="inventory.php" class="npill active"><i class="bi bi-box-seam"></i> Inventory</a>
    <a href="reports.php" class="npill"><i class="bi bi-graph-up"></i> Reports</a>
  </div>
  <a href="../auth/logout.php" class="logout-btn no-print" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</nav>

<!-- PAGE HERO -->
<div class="page-hero no-print">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-box-seam-fill me-1"></i>Supply Management</div>
    <div class="hero-title">Resource Inventory</div>
    <div class="hero-sub">Manage disaster relief supplies and resources</div>
  </div>
</div>

<!-- PAGE -->
<div class="page">

  <?php if (isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show" style="background: var(--green-light); color: var(--green); border: 1px solid rgba(22,163,74,.2); border-radius: var(--r);">
      <i class="bi bi-check-circle-fill me-2"></i><?= $success ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  
  <?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" style="background: var(--red-light); color: var(--red); border: 1px solid rgba(232,39,29,.2); border-radius: var(--r);">
      <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Low Stock Alert -->
  <?php if (count($low_stock) > 0): ?>
    <div class="alert-banner">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span><strong>Low Stock Alert!</strong> The following resources are running low:</span>
      <?php foreach ($low_stock as $stock): ?>
        <span class="badge"><?= ucfirst($stock['resource_type']) ?> (<?= $stock['total_quantity'] ?> left)</span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <!-- Inventory List -->
    <div class="col-lg-7">
      <?php foreach ($inventory as $item): 
        $icon = $resource_icons[$item['resource_type']] ?? '📦';
        $quantity = $item['total_quantity'];
        $stock_class = $quantity < 100 ? 'low-stock' : ($quantity < 500 ? 'medium-stock' : 'good-stock');
        $stock_text = $quantity < 100 ? 'Critical Stock' : ($quantity < 500 ? 'Low Stock' : 'Adequate');
        $percentage = min(($quantity / 1000) * 100, 100);
        $progress_color = $quantity < 100 ? 'var(--red)' : ($quantity < 500 ? 'var(--amber)' : 'var(--green)');
      ?>
        <div class="inventory-card">
          <div class="inventory-header">
            <div class="inventory-title">
              <span style="font-size:1.3rem"><?= $icon ?></span>
              <span><?= ucfirst($item['resource_type']) ?></span>
            </div>
            <div class="text-muted small">
              <i class="bi bi-database me-1"></i><?= $item['item_count'] ?> batches
            </div>
          </div>
          <div class="inventory-body">
            <div class="d-flex justify-content-between align-items-end">
              <div>
                <div class="quantity-display <?= $stock_class ?>"><?= number_format($quantity) ?></div>
                <div class="stock-status"><?= $stock_text ?></div>
              </div>
              <div class="text-end">
                <div class="small text-muted">Last updated</div>
                <div class="small fw-mono"><?= date('M j, H:i', strtotime($item['last_updated'])) ?></div>
              </div>
            </div>
            <div class="progress-track">
              <div class="progress-fill" style="width: <?= $percentage ?>%; background: <?= $progress_color ?>"></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      
      <?php if (count($inventory) == 0): ?>
        <div class="text-center text-muted py-5" style="background: var(--surface); border-radius: var(--r-lg);">
          <i class="bi bi-box-seam fs-1 d-block mb-2 opacity-25"></i>
          <p>No resources in inventory. Add resources using the form.</p>
        </div>
      <?php endif; ?>
    </div>
    
    <!-- Right Column -->
    <div class="col-lg-5">
      <!-- Add Resource Form -->
      <div class="form-card">
        <div class="form-header">
          <i class="bi bi-plus-circle text-danger"></i>
          <span>Add New Resource</span>
        </div>
        <div class="form-body">
          <form method="POST">
            <input type="hidden" name="action" value="add_resource">
            <div class="mb-3">
              <label class="form-label">Resource Type</label>
              <select name="resource_type" class="form-select" required>
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
              <label class="form-label">Quantity</label>
              <input type="number" name="quantity" class="form-input" required min="1">
            </div>
            <div class="mb-3">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="available">Available</option>
                <option value="reserved">Reserved</option>
                <option value="depleted">Depleted</option>
              </select>
            </div>
            <button type="submit" class="btn-submit">
              <i class="bi bi-plus-lg me-2"></i>Add to Inventory
            </button>
          </form>
        </div>
      </div>
      
      <!-- Inventory Distribution Chart -->
      <div class="form-card">
        <div class="form-header">
          <i class="bi bi-pie-chart text-danger"></i>
          <span>Inventory Distribution</span>
        </div>
        <div class="chart-wrap">
          <canvas id="inventoryChart" height="200"></canvas>
        </div>
      </div>
    </div>
  </div>
  
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
        backgroundColor: ['#e8271d', '#d97706', '#eab308', '#16a34a', '#0891b2', '#7c3aed', '#ec4899'],
        borderWidth: 0,
        hoverOffset: 8
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      cutout: '60%',
      plugins: {
        legend: { position: 'bottom', labels: { color: '#6b7280', font: { size: 10, family: "'IBM Plex Mono', monospace" }, usePointStyle: true } },
        tooltip: { backgroundColor: '#0f172a', titleColor: '#f1f5f9', bodyColor: '#94a3b8' }
      }
    }
  });
</script>
</body>
</html>