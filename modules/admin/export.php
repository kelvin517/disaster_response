<?php
/**
 * Export Reports Module
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Generate and export incident reports in PDF and CSV formats
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admin can access
role_guard(['admin']);

// Get export parameters
$export_type = $_GET['type'] ?? 'incidents';
$format = $_GET['format'] ?? 'csv';
$date_range = $_GET['range'] ?? '30';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime("-{$date_range} days"));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

if ($date_range !== 'custom') {
    $start_date = date('Y-m-d', strtotime("-{$date_range} days"));
    $end_date = date('Y-m-d');
}

// Handle CSV Export
if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $export_type . '_export_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    if ($export_type === 'incidents') {
        // Incident export
        fputcsv($output, ['ID', 'Type', 'Severity', 'Location', 'Description', 'Status', 'Reported At', 'Resolved At', 'Reporter', 'Responder']);
        
        $stmt = $pdo->prepare("
            SELECT i.id, i.incident_type, i.severity, i.location_name, i.description, i.status, 
                   i.reported_at, i.updated_at, u.full_name as reporter, r.full_name as responder
            FROM incidents i
            LEFT JOIN users u ON i.reporter_id = u.id
            LEFT JOIN users r ON i.assigned_to = r.id
            WHERE DATE(i.reported_at) BETWEEN ? AND ?
            ORDER BY i.reported_at DESC
        ");
        $stmt->execute([$start_date, $end_date]);
        $incidents = $stmt->fetchAll();
        
        $severity_labels = ['', 'Low', 'Medium', 'High', 'Critical'];
        foreach ($incidents as $incident) {
            fputcsv($output, [
                'INC-' . str_pad($incident['id'], 5, '0', STR_PAD_LEFT),
                ucfirst(str_replace('_', ' ', $incident['incident_type'])),
                $severity_labels[$incident['severity']] ?? 'Unknown',
                $incident['location_name'],
                strip_tags($incident['description']),
                $incident['status'],
                $incident['reported_at'],
                $incident['updated_at'] ?? '',
                $incident['reporter'] ?? 'Anonymous',
                $incident['responder'] ?? 'Not assigned'
            ]);
        }
    } elseif ($export_type === 'users') {
        // User export
        fputcsv($output, ['ID', 'Name', 'Email', 'Phone', 'Role', 'Status', 'Registered At', 'Last Login']);
        
        $stmt = $pdo->prepare("
            SELECT id, full_name, email, phone, role, is_active, created_at, last_login
            FROM users
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        foreach ($users as $user) {
            fputcsv($output, [
                $user['id'],
                $user['full_name'],
                $user['email'],
                $user['phone'] ?? '',
                $user['role'],
                $user['is_active'] ? 'Active' : 'Inactive',
                $user['created_at'],
                $user['last_login'] ?? ''
            ]);
        }
    } elseif ($export_type === 'resources') {
        // Resource export
        fputcsv($output, ['Resource Type', 'Quantity', 'Status', 'Last Updated']);
        
        $stmt = $pdo->prepare("
            SELECT resource_type, SUM(quantity) as quantity, status, MAX(updated_at) as last_updated
            FROM resources
            GROUP BY resource_type
            ORDER BY resource_type
        ");
        $stmt->execute();
        $resources = $stmt->fetchAll();
        
        foreach ($resources as $resource) {
            fputcsv($output, [
                $resource['resource_type'],
                $resource['quantity'],
                $resource['status'],
                $resource['last_updated']
            ]);
        }
    }
    
    fclose($output);
    exit;
}

// For PDF, we'll use a simple HTML-based approach with print styles
$page_title = 'Export Reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Reports - DisasterResponse</title>
    
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
        
        .export-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            text-align: center;
            transition: transform 0.2s, border-color 0.2s;
            height: 100%;
        }
        .export-card:hover { transform: translateY(-5px); border-color: var(--red); }
        .export-icon { font-size: 3rem; margin-bottom: 1rem; }
        .export-title { font-size: 1.2rem; font-weight: 600; margin-bottom: 0.5rem; }
        
        .filter-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        @media print {
            .no-print { display: none; }
            body { background: white; color: black; }
            .card { border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

<nav class="navbar-modern no-print">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="navbar-brand" href="admin_dashboard.php">
            <i class="bi bi-shield-lock-fill me-1 brand-accent"></i>Disaster<span class="brand-accent">Response</span>
            <span class="badge bg-danger ms-2" style="font-size: 0.6rem;">ADMIN</span>
        </a>
        <div class="d-flex gap-2">
            <a href="admin_dashboard.php" class="nav-pill">Dashboard</a>
            <a href="analytics.php" class="nav-pill">Analytics</a>
            <a href="export.php" class="nav-pill active">Export</a>
            <a href="system_logs.php" class="nav-pill">Logs</a>
            <a href="../auth/logout.php" class="nav-pill" onclick="return confirm('Logout?');">Logout</a>
        </div>
    </div>
</nav>

<div class="page-header no-print">
    <div class="container">
        <h1 class="fw-bold mb-0">
            <i class="bi bi-download me-2" style="color: var(--red);"></i>
            Export Reports
        </h1>
        <p class="text-muted mt-1">Generate and export incident reports, user data, and resource inventory</p>
    </div>
</div>

<div class="container pb-5">
    
    <!-- Date Range Filter -->
    <div class="filter-bar no-print">
        <h5 class="mb-3"><i class="bi bi-funnel me-2"></i>Filter Data</h5>
        <form method="GET" class="row g-3">
            <input type="hidden" name="format" id="formatField" value="csv">
            <input type="hidden" name="type" id="typeField" value="incidents">
            
            <div class="col-md-3">
                <label class="form-label small text-muted">Date Range</label>
                <select name="range" class="form-select bg-dark text-white border-secondary" id="dateRange">
                    <option value="7" <?= $date_range == '7' ? 'selected' : '' ?>>Last 7 days</option>
                    <option value="14" <?= $date_range == '14' ? 'selected' : '' ?>>Last 14 days</option>
                    <option value="30" <?= $date_range == '30' ? 'selected' : '' ?>>Last 30 days</option>
                    <option value="90" <?= $date_range == '90' ? 'selected' : '' ?>>Last 90 days</option>
                    <option value="365" <?= $date_range == '365' ? 'selected' : '' ?>>Last year</option>
                    <option value="custom" <?= $date_range == 'custom' ? 'selected' : '' ?>>Custom range</option>
                </select>
            </div>
            <div class="col-md-3 custom-date" style="display: <?= $date_range == 'custom' ? 'block' : 'none' ?>">
                <label class="form-label small text-muted">Start Date</label>
                <input type="date" name="start_date" class="form-control bg-dark text-white border-secondary" value="<?= $start_date ?>">
            </div>
            <div class="col-md-3 custom-date" style="display: <?= $date_range == 'custom' ? 'block' : 'none' ?>">
                <label class="form-label small text-muted">End Date</label>
                <input type="date" name="end_date" class="form-control bg-dark text-white border-secondary" value="<?= $end_date ?>">
            </div>
        </form>
    </div>
    
    <!-- Export Options -->
    <div class="row g-4">
        <!-- Incidents Export -->
        <div class="col-md-4">
            <div class="export-card">
                <div class="export-icon">📋</div>
                <div class="export-title">Incident Reports</div>
                <p class="text-muted small">Export all incident data including type, severity, status, and response times</p>
                <div class="d-grid gap-2 mt-3">
                    <button onclick="exportData('incidents', 'csv')" class="btn btn-outline-danger">
                        <i class="bi bi-file-spreadsheet me-2"></i>Export CSV
                    </button>
                    <button onclick="exportData('incidents', 'pdf')" class="btn btn-outline-secondary">
                        <i class="bi bi-file-pdf me-2"></i>Export PDF
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Users Export -->
        <div class="col-md-4">
            <div class="export-card">
                <div class="export-icon">👥</div>
                <div class="export-title">User Data</div>
                <p class="text-muted small">Export all registered users with roles, contact info, and account status</p>
                <div class="d-grid gap-2 mt-3">
                    <button onclick="exportData('users', 'csv')" class="btn btn-outline-danger">
                        <i class="bi bi-file-spreadsheet me-2"></i>Export CSV
                    </button>
                    <button onclick="exportData('users', 'pdf')" class="btn btn-outline-secondary">
                        <i class="bi bi-file-pdf me-2"></i>Export PDF
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Resources Export -->
        <div class="col-md-4">
            <div class="export-card">
                <div class="export-icon">📦</div>
                <div class="export-title">Resource Inventory</div>
                <p class="text-muted small">Export available resources, quantities, and distribution status</p>
                <div class="d-grid gap-2 mt-3">
                    <button onclick="exportData('resources', 'csv')" class="btn btn-outline-danger">
                        <i class="bi bi-file-spreadsheet me-2"></i>Export CSV
                    </button>
                    <button onclick="exportData('resources', 'pdf')" class="btn btn-outline-secondary">
                        <i class="bi bi-file-pdf me-2"></i>Export PDF
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- PDF Preview Section (for PDF export) -->
    <div id="pdfPreview" style="display: none;">
        <div class="mt-4">
            <div class="dashboard-card" id="pdfContent">
                <!-- Content will be loaded here for PDF preview -->
            </div>
        </div>
    </div>
    
</div>

<script>
    // Show/hide custom date inputs
    document.getElementById('dateRange').addEventListener('change', function() {
        const customDates = document.querySelectorAll('.custom-date');
        if (this.value === 'custom') {
            customDates.forEach(el => el.style.display = 'block');
        } else {
            customDates.forEach(el => el.style.display = 'none');
        }
    });
    
    function exportData(type, format) {
        const range = document.getElementById('dateRange').value;
        let url = `export.php?type=${type}&format=${format}&range=${range}`;
        
        if (range === 'custom') {
            const startDate = document.querySelector('input[name="start_date"]').value;
            const endDate = document.querySelector('input[name="end_date"]').value;
            url += `&start_date=${startDate}&end_date=${endDate}`;
        }
        
        if (format === 'csv') {
            window.location.href = url;
        } else if (format === 'pdf') {
            // For PDF, open a new window with print-friendly version
            window.open(url, '_blank');
        }
    }
</script>
</body>
</html>