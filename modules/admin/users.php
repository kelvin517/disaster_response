<?php
/**
 * User Management
 * Disaster Response & Resource Coordination System
 * Admin only - Manage system users
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

role_guard(['admin']);

// Handle user status toggle
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ? AND role != 'admin'");
    $stmt->execute([$user_id]);
    redirect('users.php');
}

// Handle user deletion
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
    $stmt->execute([$user_id]);
    $_SESSION['success'] = "User deleted successfully.";
    redirect('users.php');
}

// Fetch all users
$stmt = $pdo->prepare("
    SELECT id, full_name, email, phone, role, is_active, created_at, last_login
    FROM users
    ORDER BY created_at DESC
");
$stmt->execute();
$users = $stmt->fetchAll();

$page_title = 'User Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - DisasterResponse</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #0f172a; color: #f1f5f9; font-family: 'Inter', sans-serif; }
        .card { background: #1e293b; border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; }
        .table-dark { background: #1e293b; }
        .table-dark td, .table-dark th { border-color: rgba(255,255,255,0.05); }
        .btn-sm { border-radius: 8px; }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-people-fill me-2"></i>User Management</h1>
        <a href="admin_dashboard.php" class="btn btn-outline-danger">← Back to Dashboard</a>
    </div>
    
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td>#<?= $user['id'] ?></td>
                            <td><?= htmlspecialchars($user['full_name']) ?></td>
                            <td><?= htmlspecialchars($user['email']) ?></td>
                            <td><?= htmlspecialchars($user['phone'] ?? '—') ?></td>
                            <td>
                                <span class="badge bg-<?= $user['role'] == 'admin' ? 'danger' : ($user['role'] == 'responder' ? 'warning' : 'secondary') ?>">
                                    <?= ucfirst($user['role']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?= $user['is_active'] ? 'success' : 'secondary' ?>">
                                    <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                            <td>
                                <?php if ($user['role'] != 'admin'): ?>
                                    <a href="?toggle_status=1&id=<?= $user['id'] ?>" class="btn btn-sm btn-outline-<?= $user['is_active'] ? 'secondary' : 'success' ?>">
                                        <i class="bi bi-<?= $user['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                    </a>
                                    <a href="?delete=1&id=<?= $user['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this user?')">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>