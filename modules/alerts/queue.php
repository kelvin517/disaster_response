<?php
/**
 * SMS Queue Management
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Displays and manages pending SMS messages in the queue
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admin can access SMS queue
role_guard(['admin']);

// Handle retry failed messages
if (isset($_GET['retry']) && is_numeric($_GET['retry'])) {
    $id = (int)$_GET['retry'];
    $stmt = $pdo->prepare("UPDATE sms_queue SET status = 'pending', attempts = 0, error_message = NULL WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['success'] = "Message queued for retry.";
    redirect('queue.php');
}

// Handle cancel message
if (isset($_GET['cancel']) && is_numeric($_GET['cancel'])) {
    $id = (int)$_GET['cancel'];
    $stmt = $pdo->prepare("UPDATE sms_queue SET status = 'cancelled' WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['success'] = "Message cancelled.";
    redirect('queue.php');
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filter
$status_filter = $_GET['status'] ?? 'pending';

// Build query
$where = "WHERE status = ?";
$params = [$status_filter];

$count_sql = "SELECT COUNT(*) as total FROM sms_queue $where";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total = $stmt->fetch()['total'];
$total_pages = ceil($total / $per_page);

$sql = "
    SELECT sq.*, a.title as alert_title
    FROM sms_queue sq
    LEFT JOIN alerts a ON sq.alert_id = a.id
    $where
    ORDER BY sq.created_at ASC
    LIMIT $offset, $per_page
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$queue_items = $stmt->fetchAll();

// Get queue statistics
$stmt = $pdo->query("
    SELECT 
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM sms_queue
");
$stats = $stmt->fetch();

$page_title = 'SMS Queue';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Queue - DisasterResponse</title>
    
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
        .stat-number { font-size: 1.5rem; font-weight: 800; }
        
        .queue-table {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }
        
        .filter-btn {
            padding: 0.35rem 1rem;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.85rem;
        }
        .filter-btn.active { background: var(--red); color: white; }
        
        .status-pending { color: var(--amber); }
        .status-sent { color: var(--green); }
        .status-failed { color: var(--red); }
        
        @media (max-width: 768px) {
            .stat-number { font-size: 1.2rem; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="queue.php">
            <i class="bi bi-chat-dots-fill me-1 brand-accent"></i>SMS<span class="brand-accent">Queue</span>
        </a>
        <div class="d-flex gap-2">
            <a href="broadcast.php" class="nav-pill">Broadcast</a>
            <a href="history.php" class="nav-pill">History</a>
            <a href="../admin/admin_dashboard.php" class="nav-pill">Dashboard</a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-chat-dots-fill me-2" style="color: var(--red);"></i>
            SMS Queue Management
        </h1>
        <p class="text-muted mt-1">Manage pending SMS messages for emergency alerts</p>
    </div>
</div>

<div class="container pb-5">
    
    <!-- Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--amber);"><?= number_format($stats['pending'] ?? 0) ?></div>
                <div class="small text-muted">Pending</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--green);"><?= number_format($stats['sent'] ?? 0) ?></div>
                <div class="small text-muted">Sent</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--red);"><?= number_format($stats['failed'] ?? 0) ?></div>
                <div class="small text-muted">Failed</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--blue);"><?= number_format(($stats['sent'] ?? 0) + ($stats['pending'] ?? 0)) ?></div>
                <div class="small text-muted">Total Processed</div>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="d-flex gap-2 mb-4 flex-wrap">
        <a href="?status=pending" class="filter-btn <?= $status_filter == 'pending' ? 'active bg-danger' : 'text-muted' ?>">Pending</a>
        <a href="?status=sent" class="filter-btn <?= $status_filter == 'sent' ? 'active bg-danger' : 'text-muted' ?>">Sent</a>
        <a href="?status=failed" class="filter-btn <?= $status_filter == 'failed' ? 'active bg-danger' : 'text-muted' ?>">Failed</a>
        <a href="?status=cancelled" class="filter-btn <?= $status_filter == 'cancelled' ? 'active bg-danger' : 'text-muted' ?>">Cancelled</a>
    </div>
    
    <!-- Queue Table -->
    <div class="queue-table">
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Recipient</th>
                        <th>Phone</th>
                        <th>Message</th>
                        <th>Alert</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($queue_items) > 0): ?>
                        <?php foreach ($queue_items as $item): ?>
                            <tr>
                                <td>#<?= $item['id'] ?></td>
                                <td><?= htmlspecialchars($item['recipient_name'] ?? 'Unknown') ?></td>
                                <td><?= htmlspecialchars($item['recipient_phone']) ?></td>
                                <td style="max-width: 300px;">
                                    <div class="small"><?= htmlspecialchars(substr($item['message'], 0, 80)) ?>...</div>
                                </td>
                                <td><?= $item['alert_title'] ? htmlspecialchars(substr($item['alert_title'], 0, 30)) : '—' ?></td>
                                <td>
                                    <?php if ($item['status'] == 'pending'): ?>
                                        <span class="status-pending"><i class="bi bi-clock-history"></i> Pending</span>
                                    <?php elseif ($item['status'] == 'sent'): ?>
                                        <span class="status-sent"><i class="bi bi-check-circle"></i> Sent</span>
                                    <?php elseif ($item['status'] == 'failed'): ?>
                                        <span class="status-failed"><i class="bi bi-exclamation-triangle"></i> Failed</span>
                                    <?php else: ?>
                                        <span class="text-muted"><?= ucfirst($item['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $item['attempts'] ?? 0 ?></td>
                                <td><small><?= date('M j, H:i', strtotime($item['created_at'])) ?></small></td>
                                <td>
                                    <?php if ($item['status'] == 'failed'): ?>
                                        <a href="?retry=<?= $item['id'] ?>" class="btn btn-sm btn-outline-warning" onclick="return confirm('Retry this message?')">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($item['status'] == 'pending'): ?>
                                        <a href="?cancel=<?= $item['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Cancel this message?')">
                                            <i class="bi bi-x-circle"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="9" class="text-center text-muted py-5">No messages in queue<?= $status_filter != 'all' ? " with status '$status_filter'" : '' ?>.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="d-flex justify-content-center mt-4">
        <nav>
            <ul class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link bg-dark text-white border-secondary" href="?page=<?= $i ?>&status=<?= $status_filter ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>