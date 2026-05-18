<?php
/**
 * Fulfill Resource Request
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows responders to update request status (assigned, in_transit, delivered)
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

role_guard(['responder', 'admin']);

$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($request_id <= 0) {
    redirect('manage.php');
}

// Fetch request details
$stmt = $pdo->prepare("
    SELECT rr.*, u.full_name as requester_name, u.phone as requester_phone
    FROM resource_requests rr
    LEFT JOIN users u ON rr.user_id = u.id
    WHERE rr.id = ?
");
$stmt->execute([$request_id]);
$request = $stmt->fetch();

if (!$request) {
    redirect('manage.php');
}

$error = null;
$success = null;

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_status = $_POST['status'];
    $notes = trim($_POST['notes'] ?? '');
    
    $allowed_statuses = ['assigned', 'in_transit', 'delivered'];
    if (!in_array($new_status, $allowed_statuses)) {
        $error = "Invalid status.";
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE resource_requests 
                SET status = ?, fulfilled_at = NOW(), responder_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_status, $notes, $request_id]);
            $success = "Request status updated to " . ucfirst(str_replace('_', ' ', $new_status));
            
            // Refresh request data
            $stmt = $pdo->prepare("SELECT * FROM resource_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
        } catch (PDOException $e) {
            $error = "Failed to update status.";
        }
    }
}

$status_flow = [
    'pending' => ['next' => 'assigned', 'label' => 'Assign to Responder', 'icon' => 'bi-person-plus'],
    'assigned' => ['next' => 'in_transit', 'label' => 'Mark In Transit', 'icon' => 'bi-truck'],
    'in_transit' => ['next' => 'delivered', 'label' => 'Mark Delivered', 'icon' => 'bi-check2-all']
];

$current_status = $request['status'];
$next_action = $status_flow[$current_status] ?? null;

$page_title = 'Fulfill Resource Request';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fulfill Request - DisasterResponse</title>
    
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
        
        .page-header {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border-bottom: 1px solid var(--border);
            padding: 1.5rem 0;
            margin-bottom: 1.5rem;
        }
        
        .info-card, .form-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .status-timeline {
            display: flex;
            justify-content: space-between;
            margin-top: 1rem;
            padding: 1rem;
            background: var(--surface2);
            border-radius: 12px;
        }
        .timeline-step {
            text-align: center;
            flex: 1;
            font-size: 0.7rem;
            color: var(--muted);
        }
        .timeline-step.completed { color: #22c55e; }
        .timeline-step.current { color: var(--red); font-weight: 600; }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="../responders/dashboard.php">
            <i class="bi bi-shield-fill-check me-1 brand-accent"></i>Disaster<span class="brand-accent">Response</span>
        </a>
        <div class="d-flex gap-2">
            <a href="manage.php" class="nav-pill">
                <i class="bi bi-arrow-left me-1"></i>Back to Requests
            </a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-box-seam-fill me-2" style="color: var(--red);"></i>
            Fulfill Request #REQ-<?= str_pad($request_id, 5, '0', STR_PAD_LEFT) ?>
        </h1>
        <p class="text-muted mt-1">Update status and add notes for this resource request</p>
    </div>
</div>

<div class="container pb-5">
    
    <div class="row justify-content-center">
        <div class="col-lg-8">
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= $success ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Request Details -->
            <div class="info-card">
                <h5 class="mb-3"><i class="bi bi-info-circle me-2 text-danger"></i>Request Details</h5>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <label class="text-muted small">Requester</label>
                        <p class="mb-0"><?= htmlspecialchars($request['requester_name'] ?? 'Anonymous') ?></p>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="text-muted small">Phone</label>
                        <p class="mb-0"><?= htmlspecialchars($request['requester_phone'] ?? 'Not provided') ?></p>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="text-muted small">Resource Type</label>
                        <p class="mb-0"><?= ucfirst($request['resource_type']) ?></p>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="text-muted small">Quantity</label>
                        <p class="mb-0"><?= number_format($request['quantity']) ?> units</p>
                    </div>
                    <div class="col-12 mb-2">
                        <label class="text-muted small">Delivery Location</label>
                        <p class="mb-0"><?= htmlspecialchars($request['delivery_location'] ?? 'Not specified') ?></p>
                    </div>
                    <?php if ($request['notes']): ?>
                    <div class="col-12 mb-2">
                        <label class="text-muted small">Requester Notes</label>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($request['notes'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Status Timeline -->
            <div class="info-card">
                <h5 class="mb-3"><i class="bi bi-clock-history me-2 text-danger"></i>Request Status</h5>
                <div class="status-timeline">
                    <div class="timeline-step <?= in_array($request['status'], ['pending', 'assigned', 'in_transit', 'delivered']) ? 'completed' : '' ?>">
                        📋 Requested
                    </div>
                    <div class="timeline-step <?= in_array($request['status'], ['assigned', 'in_transit', 'delivered']) ? 'completed' : ($request['status'] == 'pending' ? 'current' : '') ?>">
                        👤 Assigned
                    </div>
                    <div class="timeline-step <?= in_array($request['status'], ['in_transit', 'delivered']) ? 'completed' : ($request['status'] == 'assigned' ? 'current' : '') ?>">
                        🚚 In Transit
                    </div>
                    <div class="timeline-step <?= $request['status'] == 'delivered' ? 'completed current' : '' ?>">
                        ✅ Delivered
                    </div>
                </div>
            </div>
            
            <!-- Update Form -->
            <?php if ($next_action && $request['status'] != 'delivered'): ?>
            <div class="form-card">
                <h5 class="mb-3"><i class="bi bi-arrow-repeat me-2 text-danger"></i>Update Status</h5>
                <form method="POST">
                    <input type="hidden" name="status" value="<?= $next_action['next'] ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Update Notes (Optional)</label>
                        <textarea name="notes" class="form-control bg-dark text-white border-secondary" rows="3" 
                                  placeholder="Add notes about this update (e.g., estimated delivery time, responder name, vehicle number...)"></textarea>
                    </div>
                    <button type="submit" class="btn btn-danger w-100 py-2 rounded-pill">
                        <i class="bi <?= $next_action['icon'] ?> me-2"></i><?= $next_action['label'] ?>
                    </button>
                </form>
            </div>
            <?php elseif ($request['status'] == 'delivered'): ?>
            <div class="form-card text-center">
                <i class="bi bi-check-circle-fill fs-1 text-success mb-2 d-block"></i>
                <h5>Request Completed</h5>
                <p class="text-muted">This request has been marked as delivered.</p>
                <a href="manage.php" class="btn btn-outline-danger rounded-pill">Back to Requests</a>
            </div>
            <?php endif; ?>
            
        </div>
    </div>
    
</div>

</body>
</html>