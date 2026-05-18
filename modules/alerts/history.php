<?php
/**
 * Alert History
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Displays log of all sent alerts with filtering and search capabilities
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admin and responders can view alert history
role_guard(['admin', 'responder']);

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filters
$priority_filter = $_GET['priority'] ?? '';
$date_filter = $_GET['date'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$where_conditions = [];
$params = [];

if ($priority_filter) {
    $where_conditions[] = "a.priority = ?";
    $params[] = $priority_filter;
}
if ($date_filter) {
    $where_conditions[] = "DATE(a.created_at) = ?";
    $params[] = $date_filter;
}
if ($search) {
    $where_conditions[] = "(a.title LIKE ? OR a.message LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM alerts a
    $where_clause
";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_alerts = $stmt->fetch()['total'];
$total_pages = ceil($total_alerts / $per_page);

// Fetch alerts
$sql = "
    SELECT a.*, u.full_name as creator_name
    FROM alerts a
    JOIN users u ON a.created_by = u.id
    $where_clause
    ORDER BY a.created_at DESC
    LIMIT $offset, $per_page
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll();

$priority_levels = [
    'info' => ['label' => 'ℹ️ Info', 'color' => '#3b82f6', 'icon' => 'bi-info-circle'],
    'warning' => ['label' => '⚠️ Warning', 'color' => '#f59e0b', 'icon' => 'bi-exclamation-triangle'],
    'urgent' => ['label' => '🔴 Urgent', 'color' => '#ef4444', 'icon' => 'bi-exclamation-octagon'],
    'emergency' => ['label' => '🚨 Emergency', 'color' => '#dc2626', 'icon' => 'bi-megaphone-fill']
];

$page_title = 'Alert History';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alert History - DisasterResponse</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --bg: #0f172a;
            --surface: #1e293b;
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
        
        .alert-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }
        .alert-card:hover { border-color: var(--red); }
        
        .priority-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .filter-bar .row { gap: 0.5rem; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="history.php">
            <i class="bi bi-clock-history me-1 brand-accent"></i>Alert<span class="brand-accent">History</span>
        </a>
        <div class="d-flex gap-2">
            <a href="broadcast.php" class="nav-pill">Broadcast</a>
            <a href="queue.php" class="nav-pill">SMS Queue</a>
            <a href="../admin/admin_dashboard.php" class="nav-pill">Dashboard</a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-clock-history me-2" style="color: var(--red);"></i>
            Alert History
        </h1>
        <p class="text-muted mt-1">View all broadcasted alerts and their delivery status</p>
    </div>
</div>

<div class="container pb-5">
    
    <!-- Filters -->
    <div class="filter-bar">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted">Priority</label>
                <select name="priority" class="form-select bg-dark text-white border-secondary">
                    <option value="">All Priorities</option>
                    <option value="info" <?= $priority_filter == 'info' ? 'selected' : '' ?>>Informational</option>
                    <option value="warning" <?= $priority_filter == 'warning' ? 'selected' : '' ?>>Warning</option>
                    <option value="urgent" <?= $priority_filter == 'urgent' ? 'selected' : '' ?>>Urgent</option>
                    <option value="emergency" <?= $priority_filter == 'emergency' ? 'selected' : '' ?>>Emergency</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">Date</label>
                <input type="date" name="date" class="form-control bg-dark text-white border-secondary" value="<?= $date_filter ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted">Search</label>
                <input type="text" name="search" class="form-control bg-dark text-white border-secondary" placeholder="Title or message..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-danger w-100">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
            </div>
        </form>
    </div>
    
    <!-- Alerts List -->
    <?php if (count($alerts) > 0): ?>
        <?php foreach ($alerts as $alert): 
            $priority = $priority_levels[$alert['priority']] ?? $priority_levels['info'];
        ?>
            <div class="alert-card">
                <div class="p-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <span class="priority-badge" style="background: <?= $priority['color'] ?>20; color: <?= $priority['color'] ?>; border: 1px solid <?= $priority['color'] ?>40;">
                                <i class="bi <?= $priority['icon'] ?> me-1"></i><?= $priority['label'] ?>
                            </span>
                            <span class="badge bg-secondary ms-2"><?= ucfirst($alert['target_type']) ?></span>
                        </div>
                        <small class="text-muted">
                            <i class="bi bi-calendar me-1"></i><?= date('F j, Y \a\t g:i A', strtotime($alert['created_at'])) ?>
                        </small>
                    </div>
                    
                    <div class="mt-2">
                        <div class="fw-bold fs-6"><?= htmlspecialchars($alert['title']) ?></div>
                        <p class="small text-muted mt-2 mb-2"><?= nl2br(htmlspecialchars($alert['message'])) ?></p>
                        <div class="small text-muted">
                            <i class="bi bi-geo-alt me-1"></i>Target: <?= htmlspecialchars($alert['target_area'] ?? 'All users') ?>
                        </div>
                        <div class="small text-muted">
                            <i class="bi bi-person-circle me-1"></i>Sent by: <?= htmlspecialchars($alert['creator_name']) ?>
                        </div>
                        <?php if ($alert['send_sms']): ?>
                            <div class="small text-muted">
                                <i class="bi bi-chat-dots me-1"></i>SMS: Sent to affected users
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mt-3">
                        <a href="#" class="btn btn-sm btn-outline-danger rounded-pill" onclick="viewAlert(<?= htmlspecialchars(json_encode($alert)) ?>)">
                            <i class="bi bi-eye me-1"></i>View Details
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-center mt-4">
            <nav>
                <ul class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link bg-dark text-white border-secondary" href="?page=<?= $i ?>&priority=<?= urlencode($priority_filter) ?>&date=<?= urlencode($date_filter) ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            <p>No alerts found matching your criteria.</p>
            <a href="broadcast.php" class="btn btn-danger rounded-pill">
                <i class="bi bi-megaphone me-2"></i>Send First Alert
            </a>
        </div>
    <?php endif; ?>
    
</div>

<!-- Alert Detail Modal -->
<div class="modal fade" id="alertModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">Alert Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="alertModalBody">
                <!-- Dynamic content -->
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function viewAlert(alert) {
    const modalBody = document.getElementById('alertModalBody');
    const priorityColors = {
        info: '#3b82f6', warning: '#f59e0b', urgent: '#ef4444', emergency: '#dc2626'
    };
    modalBody.innerHTML = `
        <div class="mb-3">
            <span class="badge" style="background: ${priorityColors[alert.priority]}20; color: ${priorityColors[alert.priority]};">${alert.priority.toUpperCase()}</span>
        </div>
        <div class="mb-3">
            <label class="text-muted small">Title</label>
            <div class="fw-semibold">${escapeHtml(alert.title)}</div>
        </div>
        <div class="mb-3">
            <label class="text-muted small">Message</label>
            <div>${escapeHtml(alert.message).replace(/\n/g, '<br>')}</div>
        </div>
        <div class="mb-3">
            <label class="text-muted small">Target Audience</label>
            <div>${escapeHtml(alert.target_area || 'All users')}</div>
        </div>
        <div class="mb-3">
            <label class="text-muted small">Sent By</label>
            <div>${escapeHtml(alert.creator_name)}</div>
        </div>
        <div class="mb-3">
            <label class="text-muted small">Sent At</label>
            <div>${new Date(alert.created_at).toLocaleString()}</div>
        </div>
        <div class="mb-3">
            <label class="text-muted small">Expires</label>
            <div>${alert.expires_at ? new Date(alert.expires_at).toLocaleString() : '24 hours after creation'}</div>
        </div>
    `;
    const modal = new bootstrap.Modal(document.getElementById('alertModal'));
    modal.show();
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>