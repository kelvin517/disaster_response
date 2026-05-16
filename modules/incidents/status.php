<?php
/**
 * Track My Reports - Victim Status Page
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows victims/public users to track the status of incidents they have reported
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only authenticated users can access
if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

$user_id = $_SESSION['user_id'];

// Handle adding additional information
$update_success = null;
$update_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_update') {
    $incident_id = (int)$_POST['incident_id'];
    $additional_info = trim($_POST['additional_info']);
    
    if (empty($additional_info)) {
        $update_error = "Please enter additional information.";
    } else {
        try {
            // Insert update into incident_updates table
            $stmt = $pdo->prepare("
                INSERT INTO incident_updates (incident_id, user_id, update_text, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$incident_id, $user_id, $additional_info]);
            $update_success = "Additional information added successfully. Responders have been notified.";
        } catch (PDOException $e) {
            error_log("Failed to add update: " . $e->getMessage());
            $update_error = "Failed to add update. Please try again.";
        }
    }
}

// Handle cancelling a report
if (isset($_GET['cancel']) && is_numeric($_GET['cancel'])) {
    $incident_id = (int)$_GET['cancel'];
    
    // Check if incident belongs to this user and is still cancellable
    $stmt = $pdo->prepare("
        SELECT id, status FROM incidents 
        WHERE id = ? AND reporter_id = ? AND status IN ('reported', 'acknowledged')
    ");
    $stmt->execute([$incident_id, $user_id]);
    $incident = $stmt->fetch();
    
    if ($incident) {
        $stmt = $pdo->prepare("UPDATE incidents SET status = 'cancelled' WHERE id = ?");
        $stmt->execute([$incident_id]);
        $update_success = "Your report has been cancelled.";
    } else {
        $update_error = "Unable to cancel this report. It may already be in progress or resolved.";
    }
}

// Fetch all incidents reported by this user
$stmt = $pdo->prepare("
    SELECT i.*, 
           CASE 
               WHEN i.status = 'reported' THEN '📋 Report Received'
               WHEN i.status = 'acknowledged' THEN '👀 Under Review'
               WHEN i.status = 'in-progress' THEN '🚑 Responders En Route'
               WHEN i.status = 'resolved' THEN '✅ Resolved'
               WHEN i.status = 'cancelled' THEN '❌ Cancelled'
               WHEN i.status = 'rejected' THEN '⚠️ Rejected'
               ELSE '📌 ' || i.status
           END as status_display,
           CASE 
               WHEN i.status = 'reported' THEN 25
               WHEN i.status = 'acknowledged' THEN 50
               WHEN i.status = 'in-progress' THEN 75
               WHEN i.status = 'resolved' THEN 100
               WHEN i.status = 'cancelled' THEN 100
               WHEN i.status = 'rejected' THEN 100
               ELSE 0
           END as progress_percent,
           CASE 
               WHEN i.status = 'reported' THEN 'progress-bar-striped progress-bar-animated'
               WHEN i.status = 'acknowledged' THEN 'progress-bar-striped progress-bar-animated'
               WHEN i.status = 'in-progress' THEN 'progress-bar-striped progress-bar-animated'
               WHEN i.status = 'resolved' THEN 'bg-success'
               WHEN i.status = 'cancelled' THEN 'bg-secondary'
               WHEN i.status = 'rejected' THEN 'bg-danger'
               ELSE ''
           END as progress_class
    FROM incidents i
    WHERE i.reporter_id = ?
    ORDER BY i.reported_at DESC
");
$stmt->execute([$user_id]);
$my_reports = $stmt->fetchAll();

// Get incident updates for each report
$incident_updates = [];
if (!empty($my_reports)) {
    $ids = array_column($my_reports, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT iu.*, u.full_name as user_name 
        FROM incident_updates iu
        JOIN users u ON iu.user_id = u.id
        WHERE iu.incident_id IN ($placeholders)
        ORDER BY iu.created_at DESC
    ");
    $stmt->execute($ids);
    $updates = $stmt->fetchAll();
    
    foreach ($updates as $update) {
        $incident_updates[$update['incident_id']][] = $update;
    }
}

// Get severity label helper
function getSeverityLabel($severity) {
    $labels = [
        1 => ['label' => 'Low', 'color' => '#28a745', 'icon' => '🟢'],
        2 => ['label' => 'Medium', 'color' => '#ffc107', 'icon' => '🟡'],
        3 => ['label' => 'High', 'color' => '#fd7e14', 'icon' => '🟠'],
        4 => ['label' => 'Critical', 'color' => '#dc3545', 'icon' => '🔴']
    ];
    return $labels[$severity] ?? ['label' => 'Unknown', 'color' => '#6c757d', 'icon' => '⚪'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reports - DisasterResponse</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; }
        
        .navbar-modern {
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            padding: 0.75rem 0;
        }
        
        .navbar-brand {
            font-weight: 800;
            font-size: 1.5rem;
            background: linear-gradient(135deg, #dc3545, #b91c1c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .page-header {
            background: linear-gradient(135deg, #dc3545, #b91c1c);
            border-radius: 0 0 30px 30px;
            padding: 2rem 0;
            color: white;
            margin-bottom: 2rem;
        }
        
        .report-card {
            background: white;
            border-radius: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }
        
        .card-header-custom {
            background: white;
            border-bottom: 1px solid #f0f0f0;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .incident-id {
            font-weight: 700;
            font-size: 1rem;
            background: #f8f9fa;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            color: #dc3545;
        }
        
        .severity-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .progress {
            height: 8px;
            border-radius: 10px;
            background-color: #e9ecef;
        }
        
        .progress-bar {
            border-radius: 10px;
            transition: width 0.5s ease;
        }
        
        .update-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
        }
        
        .update-item:last-child { margin-bottom: 0; }
        
        .btn-outline-danger-custom {
            border: 1px solid #dc3545;
            color: #dc3545;
            background: transparent;
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
            transition: all 0.2s;
        }
        
        .btn-outline-danger-custom:hover {
            background: #dc3545;
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 20px;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #dee2e6;
            margin-bottom: 1rem;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .report-card {
            animation: fadeIn 0.3s ease-out;
        }
        
        @media (max-width: 768px) {
            .page-header h1 { font-size: 1.5rem; }
            .card-header-custom { flex-direction: column; align-items: flex-start; }
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
            <a href="/disaster_response/modules/incidents/report.php" class="btn btn-outline-secondary btn-sm rounded-pill">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a href="report.php" class="btn btn-outline-danger btn-sm rounded-pill">
                <i class="bi bi-plus-circle me-1"></i>New Report
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
                    <i class="bi bi-clock-history me-2"></i>My Reports
                </h1>
                <p class="mb-0 opacity-75">Track the status of your emergency reports</p>
            </div>
            <div class="col-md-4 text-end">
                <i class="bi bi-journal-bookmark-fill" style="font-size: 3rem; opacity: 0.3;"></i>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    
    <!-- Success/Error Messages -->
    <?php if ($update_success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($update_success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($update_error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($update_error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (empty($my_reports)): ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <h5 class="mb-2">No reports yet</h5>
            <p class="text-muted mb-3">You haven't submitted any emergency reports.</p>
            <a href="report.php" class="btn btn-danger rounded-pill">
                <i class="bi bi-plus-circle me-2"></i>Report an Emergency
            </a>
        </div>
    <?php else: ?>
        
        <!-- Report Cards -->
        <?php foreach ($my_reports as $report): 
            $severity = getSeverityLabel($report['severity']);
            $can_cancel = in_array($report['status'], ['reported', 'acknowledged']);
            $has_updates = isset($incident_updates[$report['id']]);
        ?>
            <div class="report-card">
                <div class="card-header-custom">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <span class="incident-id">
                            <i class="bi bi-hash"></i>INC-<?= str_pad($report['id'], 5, '0', STR_PAD_LEFT) ?>
                        </span>
                        <span class="severity-badge" style="background: <?= $severity['color'] ?>20; color: <?= $severity['color'] ?>; border: 1px solid <?= $severity['color'] ?>40;">
                            <?= $severity['icon'] ?> <?= $severity['label'] ?> Severity
                        </span>
                        <span class="status-badge status-<?= $report['status'] ?>">
                            <?= $report['status_display'] ?>
                        </span>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($can_cancel): ?>
                            <a href="?cancel=<?= $report['id'] ?>" 
                               class="btn-outline-danger-custom"
                               onclick="return confirm('Are you sure you want to cancel this report?');">
                                <i class="bi bi-x-circle me-1"></i>Cancel
                            </a>
                        <?php endif; ?>
                        <a href="view.php?id=<?= $report['id'] ?>" class="btn-outline-danger-custom">
                            <i class="bi bi-eye me-1"></i>View Details
                        </a>
                    </div>
                </div>
                
                <div class="card-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small fw-semibold">Incident Type</label>
                            <p class="mb-0 fw-medium"><?= ucfirst(str_replace('_', ' ', $report['incident_type'])) ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small fw-semibold">Location</label>
                            <p class="mb-0 fw-medium"><?= htmlspecialchars($report['location_name'] ?? 'Location provided') ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small fw-semibold">Reported On</label>
                            <p class="mb-0 fw-medium"><?= date('F j, Y \a\t g:i A', strtotime($report['reported_at'])) ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="text-muted small fw-semibold">Last Updated</label>
                            <p class="mb-0 fw-medium"><?= date('F j, Y \a\t g:i A', strtotime($report['updated_at'] ?? $report['reported_at'])) ?></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="text-muted small fw-semibold">Description</label>
                            <p class="mb-0 small"><?= nl2br(htmlspecialchars($report['description'])) ?></p>
                        </div>
                        <div class="col-12">
                            <label class="text-muted small fw-semibold mb-2">Status Progress</label>
                            <div class="progress">
                                <div class="progress-bar <?= $report['progress_class'] ?>" 
                                     role="progressbar" 
                                     style="width: <?= $report['progress_percent'] ?>%;"
                                     aria-valuenow="<?= $report['progress_percent'] ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                </div>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <small class="text-muted">Reported</small>
                                <small class="text-muted">Under Review</small>
                                <small class="text-muted">Responders En Route</small>
                                <small class="text-muted">Resolved</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Incident Updates Section -->
                    <div class="mt-4 pt-3 border-top">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <label class="text-muted small fw-semibold">
                                <i class="bi bi-chat-dots me-1"></i>Updates & Timeline
                            </label>
                            <button class="btn btn-sm btn-link text-decoration-none text-danger" 
                                    type="button" 
                                    data-bs-toggle="collapse" 
                                    data-bs-target="#updateForm-<?= $report['id'] ?>">
                                <i class="bi bi-plus-circle"></i> Add Update
                            </button>
                        </div>
                        
                        <!-- Add Update Form -->
                        <div class="collapse mb-3" id="updateForm-<?= $report['id'] ?>">
                            <form method="POST" class="bg-light p-3 rounded">
                                <input type="hidden" name="action" value="add_update">
                                <input type="hidden" name="incident_id" value="<?= $report['id'] ?>">
                                <div class="mb-2">
                                    <textarea name="additional_info" 
                                              class="form-control" 
                                              rows="2" 
                                              placeholder="Provide additional information about this incident..."></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger btn-sm rounded-pill">
                                    <i class="bi bi-send me-1"></i>Submit Update
                                </button>
                            </form>
                        </div>
                        
                        <!-- Existing Updates -->
                        <?php if ($has_updates): ?>
                            <div class="updates-list">
                                <?php foreach ($incident_updates[$report['id']] as $update): ?>
                                    <div class="update-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-person-circle text-muted"></i>
                                                <strong class="small"><?= htmlspecialchars($update['user_name']) ?></strong>
                                            </div>
                                            <small class="text-muted"><?= date('M j, g:i A', strtotime($update['created_at'])) ?></small>
                                        </div>
                                        <p class="mb-0 small mt-1"><?= nl2br(htmlspecialchars($update['update_text'])) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center text-muted small py-2">
                                <i class="bi bi-chat"></i> No updates yet
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- Summary Stats -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="bg-white rounded-3 p-3 text-center">
                    <p class="mb-0 text-muted small">
                        <i class="bi bi-info-circle me-1"></i>
                        Need help? Contact our emergency coordination center at 
                        <strong>999</strong> or <strong>112</strong>
                    </p>
                </div>
            </div>
        </div>
        
    <?php endif; ?>
    
</div>

<!-- Required for Bootstrap collapse -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});
</script>

</body>
</html>