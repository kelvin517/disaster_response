<?php
/**
 * System Logs & Audit Trail
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Displays system logs, user actions, and audit trail for security monitoring
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admin can access
role_guard(['admin']);

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filters
$log_type = $_GET['log_type'] ?? '';
$user_filter = $_GET['user_id'] ?? '';
$date_filter = $_GET['date'] ?? '';

// Build query
$where_conditions = [];
$params = [];

if ($log_type) {
    $where_conditions[] = "sl.action LIKE ?";
    $params[] = "%$log_type%";
}
if ($user_filter) {
    $where_conditions[] = "sl.user_id = ?";
    $params[] = $user_filter;
}
if ($date_filter) {
    $where_conditions[] = "DATE(sl.created_at) = ?";
    $params[] = $date_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM system_logs sl
    $where_clause
";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_logs = $stmt->fetch()['total'];
$total_pages = ceil($total_logs / $per_page);

// Fetch logs
$sql = "
    SELECT sl.*, u.full_name as user_name, u.role as user_role
    FROM system_logs sl
    LEFT JOIN users u ON sl.user_id = u.id
    $where_clause
    ORDER BY sl.created_at DESC
    LIMIT $offset, $per_page
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get users for filter dropdown
$stmt = $pdo->query("SELECT id, full_name, role FROM users ORDER BY full_name");
$users = $stmt->fetchAll();

// Get log type statistics
$stmt = $pdo->query("
    SELECT 
        SUBSTRING_INDEX(action, ' ', 1) as action_type,
        COUNT(*) as count
    FROM system_logs
    GROUP BY action_type
    ORDER BY count DESC
    LIMIT 10
");
$action_stats = $stmt->fetchAll();

$page_title = 'System Logs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - DisasterResponse</title>
    
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
        
        .filter-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .log-table {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }
        
        .log-entry {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--border);
            transition: background 0.2s;
        }
        .log-entry:hover { background: var(--surface2); }
        
        .log-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }
        .log-icon.login { background: rgba(34,197,94,0.15); color: #4ade80; }
        .log-icon.logout { background: rgba(245,158,11,0.15); color: #fbbf24; }
        .log-icon.create, .log-icon.update { background: rgba(59,130,246,0.15); color: #60a5fa; }
        .log-icon.delete { background: rgba(239,68,68,0.15); color: #f87171; }
        .log-icon.verify { background: rgba(34,197,94,0.15); color: #4ade80; }
        .log-icon.default { background: rgba(148,163,184,0.15); color: #94a3b8; }
        
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 0.75rem;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .log-entry { font-size: 0.8rem; }
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
            <a href="analytics.php" class="nav-pill">Analytics</a>
            <a href="export.php" class="nav-pill">Export</a>
            <a href="system_logs.php" class="nav-pill active">Logs</a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-journal-bookmark-fill me-2" style="color: var(--red);"></i>
            System Logs & Audit Trail
        </h1>
        <p class="text-muted mt-1">Track user actions and system events for security monitoring</p>
    </div>
</div>

<div class="container pb-5">
    
    <!-- Action Statistics -->
    <div class="row g-2 mb-4">
        <?php foreach ($action_stats as $stat): ?>
        <div class="col-md-2 col-4">
            <div class="stat-card">
                <div class="small text-muted"><?= ucfirst($stat['action_type']) ?></div>
                <div class="fs-4 fw-bold"><?= $stat['count'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Filters -->
    <div class="filter-bar">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted">Log Type</label>
                <select name="log_type" class="form-select bg-dark text-white border-secondary">
                    <option value="">All Actions</option>
                    <option value="login" <?= $log_type == 'login' ? 'selected' : '' ?>>Login</option>
                    <option value="logout" <?= $log_type == 'logout' ? 'selected' : '' ?>>Logout</option>
                    <option value="create" <?= $log_type == 'create' ? 'selected' : '' ?>>Create</option>
                    <option value="update" <?= $log_type == 'update' ? 'selected' : '' ?>>Update</option>
                    <option value="delete" <?= $log_type == 'delete' ? 'selected' : '' ?>>Delete</option>
                    <option value="verify" <?= $log_type == 'verify' ? 'selected' : '' ?>>Verify</option>
                    <option value="export" <?= $log_type == 'export' ? 'selected' : '' ?>>Export</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">User</label>
                <select name="user_id" class="form-select bg-dark text-white border-secondary">
                    <option value="">All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $user_filter == $user['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['full_name']) ?> (<?= $user['role'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Date</label>
                <input type="date" name="date" class="form-control bg-dark text-white border-secondary" value="<?= $date_filter ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-danger w-100">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
            </div>
            <div class="col-md-2">
                <a href="system_logs.php" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-arrow-repeat me-1"></i>Reset
                </a>
            </div>
        </form>
    </div>
    
    <!-- Logs Table -->
    <div class="log-table">
        <?php if (count($logs) > 0): ?>
            <?php foreach ($logs as $log): 
                // Determine icon class based on action
                $icon_class = 'default';
                $icon_icon = 'bi-question-circle';
                if (strpos($log['action'], 'login') !== false) {
                    $icon_class = 'login';
                    $icon_icon = 'bi-box-arrow-in-right';
                } elseif (strpos($log['action'], 'logout') !== false) {
                    $icon_class = 'logout';
                    $icon_icon = 'bi-box-arrow-right';
                } elseif (strpos($log['action'], 'create') !== false || strpos($log['action'], 'added') !== false) {
                    $icon_class = 'create';
                    $icon_icon = 'bi-plus-circle';
                } elseif (strpos($log['action'], 'update') !== false || strpos($log['action'], 'edited') !== false) {
                    $icon_class = 'update';
                    $icon_icon = 'bi-pencil-square';
                } elseif (strpos($log['action'], 'delete') !== false || strpos($log['action'], 'removed') !== false) {
                    $icon_class = 'delete';
                    $icon_icon = 'bi-trash';
                } elseif (strpos($log['action'], 'verify') !== false) {
                    $icon_class = 'verify';
                    $icon_icon = 'bi-check2-circle';
                }
            ?>
                <div class="log-entry">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="d-flex gap-3">
                            <div class="log-icon <?= $icon_class ?>">
                                <i class="bi <?= $icon_icon ?>"></i>
                            </div>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($log['action']) ?></div>
                                <div class="small text-muted">
                                    <?php if ($log['user_name']): ?>
                                        <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($log['user_name']) ?>
                                        <span class="badge bg-secondary ms-1"><?= $log['user_role'] ?></span>
                                    <?php else: ?>
                                        <i class="bi bi-person-x me-1"></i>System / Unknown
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="small text-muted">
                                <i class="bi bi-clock me-1"></i><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?>
                            </div>
                            <?php if ($log['ip_address']): ?>
                                <div class="small text-muted">
                                    <i class="bi bi-wifi me-1"></i><?= $log['ip_address'] ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($log['user_agent']): ?>
                        <div class="small text-muted mt-2">
                            <i class="bi bi-browser-chrome me-1"></i><?= htmlspecialchars(substr($log['user_agent'], 0, 100)) ?>...
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                <p>No logs found matching the criteria.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="d-flex justify-content-center mt-4">
        <nav>
            <ul class="pagination">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link bg-dark text-white border-secondary" href="?page=<?= $page - 1 ?>&log_type=<?= urlencode($log_type) ?>&user_id=<?= urlencode($user_filter) ?>&date=<?= urlencode($date_filter) ?>">
                            Previous
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= min(10, $total_pages); $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link <?= $i == $page ? 'bg-danger border-danger' : 'bg-dark text-white border-secondary' ?>" 
                           href="?page=<?= $i ?>&log_type=<?= urlencode($log_type) ?>&user_id=<?= urlencode($user_filter) ?>&date=<?= urlencode($date_filter) ?>">
                            <?= $i ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link bg-dark text-white border-secondary" href="?page=<?= $page + 1 ?>&log_type=<?= urlencode($log_type) ?>&user_id=<?= urlencode($user_filter) ?>&date=<?= urlencode($date_filter) ?>">
                            Next
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
    
    <div class="text-muted small text-center mt-3">
        <i class="bi bi-info-circle me-1"></i>
        Showing <?= count($logs) ?> of <?= $total_logs ?> log entries
    </div>
    
</div>

</body>
</html>