<?php
/**
 * Field Status Updates
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows responders to post real-time field updates, photos, and status changes
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only responders and admins can access
if (!isLoggedIn()) {
    redirect('modules/auth/login.php');
}

if (!hasRole(['responder', 'admin'])) {
    redirect('index.php');
}

$user_id = $_SESSION['user_id'];
$error = null;
$success = null;

// Handle posting a new update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'post_update') {
        $incident_id = (int)$_POST['incident_id'];
        $update_text = trim($_POST['update_text']);
        $status = $_POST['status'] ?? null;
        $photo_path = null;
        
        // Validate
        if (empty($update_text) && empty($_FILES['photo']['name'])) {
            $error = "Please enter an update or attach a photo.";
        } else {
            // Handle photo upload
            if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../../temp/uploads/updates/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                
                $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $filename = 'update_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $dest = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                    $photo_path = '/temp/uploads/updates/' . $filename;
                }
            }
            
            // Insert update
            $stmt = $pdo->prepare("
                INSERT INTO field_updates (responder_id, incident_id, update_text, status, photo_path, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            if ($stmt->execute([$user_id, $incident_id, $update_text, $status, $photo_path])) {
                $success = "Field update posted successfully!";
                
                // If status was changed, update incident status
                if ($status) {
                    $stmt = $pdo->prepare("UPDATE incidents SET status = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$status, $incident_id]);
                }
            } else {
                $error = "Failed to post update.";
            }
        }
    }
}

// Fetch active incidents for the dropdown
$stmt = $pdo->prepare("
    SELECT i.id, i.incident_type, i.location_name, i.status
    FROM incidents i
    WHERE i.status NOT IN ('resolved', 'cancelled', 'rejected')
    ORDER BY i.severity DESC, i.reported_at DESC
");
$stmt->execute();
$active_incidents = $stmt->fetchAll();

// Fetch recent updates (last 50)
$stmt = $pdo->prepare("
    SELECT fu.*, u.full_name as responder_name, i.incident_type, i.location_name
    FROM field_updates fu
    JOIN users u ON fu.responder_id = u.id
    LEFT JOIN incidents i ON fu.incident_id = i.id
    ORDER BY fu.created_at DESC
    LIMIT 50
");
$stmt->execute();
$updates = $stmt->fetchAll();

// Status options
$status_options = [
    'en_route' => '🚑 En Route',
    'arrived' => '📍 Arrived on Scene',
    'assessing' => '🔍 Assessing Situation',
    'in_progress' => '🚨 Rescue in Progress',
    'awaiting_resources' => '⏳ Awaiting Resources',
    'stabilized' => '✅ Situation Stabilized',
    'resolved' => '✔️ Resolved'
];

$page_title = 'Field Updates';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Field Updates - DisasterResponse</title>
    
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

        * { margin: 0; padding: 0; box-sizing: border-box; }
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
            font-weight: 500;
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

        .update-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }
        .update-card:hover { border-color: var(--red); }

        .update-form-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .form-header {
            background: var(--surface2);
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            font-weight: 600;
        }

        .update-photo {
            max-height: 200px;
            object-fit: cover;
            border-radius: 12px;
            width: 100%;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .timeline-line {
            position: relative;
            padding-left: 2rem;
        }
        .timeline-line::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0.5rem;
            bottom: -0.5rem;
            width: 2px;
            background: var(--border);
        }
        .timeline-line:last-child::before { display: none; }

        @media (max-width: 768px) {
            .update-card { margin-bottom: 1rem; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="dashboard.php">
            <i class="bi bi-shield-fill-check me-1 brand-accent"></i>Disaster<span class="brand-accent">Response</span>
        </a>
        <div class="d-flex gap-2">
            <a href="dashboard.php" class="nav-pill">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a href="team.php" class="nav-pill">
                <i class="bi bi-people me-1"></i>Team
            </a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-chat-dots-fill me-2" style="color: var(--red);"></i>
            Field Updates
        </h1>
        <p class="text-muted mt-1">Real-time status updates from responders in the field</p>
    </div>
</div>

<div class="container pb-5">
    
    <!-- Post Update Form -->
    <div class="update-form-card">
        <div class="form-header">
            <i class="bi bi-pencil-square me-2" style="color: var(--red);"></i>Post Field Update
        </div>
        <div class="card-body p-4">
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= $success ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="post_update">
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Incident</label>
                        <select name="incident_id" class="form-select bg-dark text-white border-secondary" required>
                            <option value="">Select Incident</option>
                            <?php foreach ($active_incidents as $incident): ?>
                                <option value="<?= $incident['id'] ?>">
                                    #<?= str_pad($incident['id'], 5, '0', STR_PAD_LEFT) ?> - <?= ucfirst($incident['incident_type']) ?> - <?= htmlspecialchars($incident['location_name'] ?? 'Unknown') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Update Status (Optional)</label>
                        <select name="status" class="form-select bg-dark text-white border-secondary">
                            <option value="">No status change</option>
                            <?php foreach ($status_options as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Update Message</label>
                    <textarea name="update_text" class="form-control bg-dark text-white border-secondary" rows="3" placeholder="Describe the current situation, actions taken, resources needed..."></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Photo (Optional)</label>
                    <input type="file" name="photo" class="form-control bg-dark text-white border-secondary" accept="image/*">
                    <small class="text-muted">Upload a photo from the field</small>
                </div>
                
                <button type="submit" class="btn btn-danger rounded-pill px-4">
                    <i class="bi bi-send me-2"></i>Post Update
                </button>
            </form>
        </div>
    </div>
    
    <!-- Updates Feed -->
    <h5 class="mb-3"><i class="bi bi-clock-history me-2"></i>Recent Field Updates</h5>
    
    <?php if (count($updates) > 0): ?>
        <div class="timeline-line">
            <?php foreach ($updates as $update): ?>
                <div class="update-card">
                    <div class="p-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <strong class="fw-semibold">
                                    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($update['responder_name']) ?>
                                </strong>
                                <?php if ($update['incident_id']): ?>
                                    <span class="badge bg-secondary ms-2">
                                        Incident #<?= str_pad($update['incident_id'], 5, '0', STR_PAD_LEFT) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($update['status'] && isset($status_options[$update['status']])): ?>
                                    <span class="badge bg-<?= $update['status'] == 'resolved' ? 'success' : 'warning' ?> ms-1">
                                        <?= $status_options[$update['status']] ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted"><?= date('M j, Y \a\t g:i A', strtotime($update['created_at'])) ?></small>
                        </div>
                        
                        <?php if ($update['update_text']): ?>
                            <p class="mb-2"><?= nl2br(htmlspecialchars($update['update_text'])) ?></p>
                        <?php endif; ?>
                        
                        <?php if ($update['photo_path']): ?>
                            <img src="<?= $update['photo_path'] ?>" class="update-photo mt-2" alt="Field update photo">
                        <?php endif; ?>
                        
                        <?php if ($update['location_name']): ?>
                            <div class="mt-2 small text-muted">
                                <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($update['location_name']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-chat-dots fs-1 d-block mb-2"></i>
            <p>No field updates yet. Be the first to post an update!</p>
        </div>
    <?php endif; ?>
    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>