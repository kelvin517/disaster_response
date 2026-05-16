<?php
/**
 * Responder Dashboard
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Displays active incidents, assigned tasks, and resource status for responders
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

// Get statistics for dashboard
$user_id = $_SESSION['user_id'];

// Active incidents count
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM incidents WHERE status IN ('reported', 'acknowledged', 'in-progress', 'assigned')");
$stmt->execute();
$active_incidents = $stmt->fetch()['count'];

// PENDING incidents count (for verification)
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM incidents WHERE status = 'reported'");
$stmt->execute();
$pending_count = $stmt->fetch()['count'];

// My assigned incidents
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM incidents WHERE assigned_to = ? AND status NOT IN ('resolved', 'closed', 'cancelled')");
$stmt->execute([$user_id]);
$my_assigned = $stmt->fetch()['count'];

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread_messages = $stmt->fetch()['count'];

// Available resources
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT resource_type) as count FROM resources WHERE status = 'available' AND quantity > 0");
$stmt->execute();
$available_resources = $stmt->fetch()['count'];

// Online team members count (from responder_locations)
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM responder_locations 
    WHERE last_update >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
");
$stmt->execute();
$online_team = $stmt->fetch()['count'];

// Recent field updates count
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM field_updates WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$stmt->execute();
$recent_updates_count = $stmt->fetch()['count'];

// Recent pending incidents (for quick verification)
$stmt = $pdo->prepare("
    SELECT i.*, u.full_name as reporter_name 
    FROM incidents i 
    JOIN users u ON i.reporter_id = u.id 
    WHERE i.status = 'reported' 
    ORDER BY i.severity DESC, i.reported_at ASC 
    LIMIT 5
");
$stmt->execute();
$pending_incidents = $stmt->fetchAll();

// Recent incidents (last 5)
$stmt = $pdo->prepare("
    SELECT i.*, u.full_name as reporter_name 
    FROM incidents i 
    JOIN users u ON i.reporter_id = u.id 
    WHERE i.status IN ('reported', 'acknowledged', 'in-progress', 'assigned') 
    ORDER BY i.reported_at DESC 
    LIMIT 5
");
$stmt->execute();
$recent_incidents = $stmt->fetchAll();

// Get severity distribution for chart
$stmt = $pdo->prepare("
    SELECT severity, COUNT(*) as count 
    FROM incidents 
    WHERE status NOT IN ('resolved', 'closed', 'cancelled', 'rejected') 
    GROUP BY severity
");
$stmt->execute();
$severity_stats = $stmt->fetchAll();

// Prepare severity data for JavaScript
$severity_labels = [];
$severity_counts = [];
$severity_map = [
    1 => 'Low',
    2 => 'Medium', 
    3 => 'High',
    4 => 'Critical'
];

foreach ($severity_stats as $stat) {
    $severity_labels[] = $severity_map[$stat['severity']] ?? 'Unknown';
    $severity_counts[] = $stat['count'];
}

// Get recent updates for activity feed
$stmt = $pdo->prepare("
    SELECT iu.*, u.full_name as user_name, i.incident_type, i.severity
    FROM incident_updates iu
    JOIN users u ON iu.user_id = u.id
    JOIN incidents i ON iu.incident_id = i.id
    ORDER BY iu.created_at DESC
    LIMIT 5
");
$stmt->execute();
$recent_updates = $stmt->fetchAll();

// Get recent field updates
$stmt = $pdo->prepare("
    SELECT fu.*, u.full_name as responder_name, i.incident_type, i.location_name
    FROM field_updates fu
    JOIN users u ON fu.responder_id = u.id
    LEFT JOIN incidents i ON fu.incident_id = i.id
    ORDER BY fu.created_at DESC
    LIMIT 5
");
$stmt->execute();
$recent_field_updates = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Responder Dashboard - DisasterResponse</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=JetBrains+Mono:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
    /* ── Design Tokens ──────────────────────────────────────── */
    :root {
        --bg:          #080a0f;
        --surface:     #0f1218;
        --surface-2:   #161b25;
        --surface-3:   #1c2233;
        --border:      rgba(255,255,255,.08);
        --border-2:    rgba(255,255,255,.13);
        --red:         #ef4444;
        --red-dim:     rgba(239,68,68,.14);
        --red-glow:    rgba(239,68,68,.28);
        --red-dk:      #b91c1c;
        --amber:       #f59e0b;
        --amber-dim:   rgba(245,158,11,.14);
        --teal:        #22d3ee;
        --teal-dim:    rgba(34,211,238,.12);
        --green:       #4ade80;
        --green-dim:   rgba(74,222,128,.12);
        --text:        #f1f5f9;
        --text-2:      #cbd5e1;
        --muted:       #475569;
        --muted-2:     #64748b;
        --ff-head:     'Syne', sans-serif;
        --ff-body:     'DM Sans', sans-serif;
        --ff-mono:     'JetBrains Mono', monospace;
        --r-sm:   5px;
        --r-md:   10px;
        --r-lg:   14px;
        --ease:   .2s cubic-bezier(.4,0,.2,1);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: var(--ff-body);
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
        background-image:
            radial-gradient(circle at 20% 20%, rgba(239,68,68,.04) 0%, transparent 50%),
            radial-gradient(circle at 80% 80%, rgba(34,211,238,.03) 0%, transparent 50%),
            radial-gradient(rgba(255,255,255,.04) 1px, transparent 1px);
        background-size: auto, auto, 28px 28px;
    }

    ::-webkit-scrollbar { width: 5px; }
    ::-webkit-scrollbar-track { background: var(--bg); }
    ::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 3px; }

    .navbar-modern {
        background: rgba(8,10,15,.95);
        backdrop-filter: blur(14px);
        border-bottom: 1px solid var(--border);
        padding: .65rem 0;
        position: sticky; top: 0; z-index: 200;
        box-shadow: 0 1px 0 rgba(239,68,68,.1);
    }

    .navbar-brand {
        font-family: var(--ff-head);
        font-weight: 800;
        font-size: 1.2rem;
        color: var(--text) !important;
        letter-spacing: -.02em;
        text-decoration: none;
        display: flex; align-items: center; gap: .4rem;
    }
    .brand-accent { color: var(--red); }

    .nav-pill {
        padding: .32rem .85rem;
        border-radius: var(--r-sm);
        border: 1px solid var(--border);
        background: transparent;
        color: var(--muted-2);
        font-family: var(--ff-body);
        font-size: .78rem;
        font-weight: 500;
        text-decoration: none;
        transition: all var(--ease);
        position: relative;
        white-space: nowrap;
    }
    .nav-pill:hover {
        border-color: rgba(239,68,68,.4);
        color: var(--red);
        background: var(--red-dim);
    }
    .nav-pill.active {
        border-color: rgba(239,68,68,.35);
        color: var(--text);
        background: var(--red-dim);
    }
    .nav-pill.danger {
        border-color: rgba(239,68,68,.25);
        color: var(--red);
    }
    .nav-pill.danger:hover {
        background: var(--red);
        color: #fff;
        border-color: var(--red);
    }
    .pending-badge {
        position: absolute;
        top: -7px; right: -7px;
        background: var(--red);
        color: #fff;
        font-size: .58rem;
        padding: .12rem .38rem;
        border-radius: 20px;
        font-weight: 700;
        font-family: var(--ff-mono);
        border: 1px solid var(--bg);
    }
    .user-chip {
        font-size: .74rem;
        color: var(--muted-2);
        padding: .28rem .7rem;
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--r-sm);
        font-family: var(--ff-mono);
        letter-spacing: .02em;
    }
    .online-ring {
        display: inline-block;
        width: 7px; height: 7px;
        background: var(--green);
        border-radius: 50%;
        box-shadow: 0 0 6px var(--green);
        animation: pulse-ring 2.2s ease-in-out infinite;
    }
    @keyframes pulse-ring {
        0%,100% { opacity: 1; transform: scale(1); }
        50%      { opacity: .5; transform: scale(.8); }
    }

    .welcome-section {
        position: relative;
        overflow: hidden;
        background: var(--surface);
        border: 1px solid var(--border);
        border-left: 3px solid var(--red);
        border-radius: var(--r-lg);
        padding: 1.5rem 1.75rem;
        margin-bottom: 1.5rem;
    }
    .welcome-section::before {
        content: '';
        position: absolute; inset: 0;
        background: repeating-linear-gradient(
            -52deg,
            transparent, transparent 40px,
            rgba(239,68,68,.025) 40px, rgba(239,68,68,.025) 41px
        );
        pointer-events: none;
    }
    .welcome-section::after {
        content: '';
        position: absolute; right: -50px; top: -50px;
        width: 220px; height: 220px;
        background: radial-gradient(circle, rgba(239,68,68,.1) 0%, transparent 70%);
        pointer-events: none;
    }
    .welcome-section h1 {
        font-family: var(--ff-head);
        font-weight: 800;
        font-size: 1.5rem;
        color: var(--text);
        letter-spacing: -.02em;
        position: relative;
        display: flex; align-items: center; gap: .55rem;
    }
    .welcome-section p {
        color: var(--muted-2);
        font-size: .85rem;
        margin-top: .35rem;
        position: relative;
        padding-left: .05rem;
    }
    .status-dot {
        display: inline-block;
        width: 9px; height: 9px;
        background: var(--green);
        border-radius: 50%;
        flex-shrink: 0;
        box-shadow: 0 0 8px var(--green);
        animation: pulse-dot 2s infinite;
    }
    @keyframes pulse-dot {
        0%,100% { opacity: 1; }
        50%      { opacity: .35; }
    }
    .welcome-icon-bg {
        font-size: 4.5rem;
        color: var(--red);
        opacity: .08;
        line-height: 1;
        position: relative;
    }
    .datetime-tag {
        display: inline-flex; align-items: center; gap: .4rem;
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--r-sm);
        padding: .25rem .65rem;
        font-size: .72rem;
        font-family: var(--ff-mono);
        color: var(--muted-2);
        margin-top: .7rem;
    }

    .stat-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-lg);
        padding: 1.3rem 1.4rem;
        margin-bottom: 1.25rem;
        position: relative;
        overflow: hidden;
        transition: transform var(--ease), border-color var(--ease), box-shadow var(--ease);
        animation: fadeUp .4s ease both;
        cursor: default;
    }
    .stat-card::before {
        content: '';
        position: absolute; inset: 0;
        background: linear-gradient(135deg, rgba(255,255,255,.025) 0%, transparent 60%);
        pointer-events: none;
    }
    .stat-card:hover { transform: translateY(-4px); }
    .stat-card.stat-red:hover   { border-color: rgba(239,68,68,.4);  box-shadow: 0 10px 36px rgba(239,68,68,.15); }
    .stat-card.stat-amber:hover { border-color: rgba(245,158,11,.4); box-shadow: 0 10px 36px rgba(245,158,11,.12); }
    .stat-card.stat-teal:hover  { border-color: rgba(34,211,238,.4); box-shadow: 0 10px 36px rgba(34,211,238,.12); }
    .stat-card.stat-green:hover { border-color: rgba(74,222,128,.4); box-shadow: 0 10px 36px rgba(74,222,128,.12); }
    .stat-card.stat-cyan:hover  { border-color: rgba(6,182,212,.4); box-shadow: 0 10px 36px rgba(6,182,212,.12); }

    .stat-number {
        font-family: var(--ff-mono);
        font-size: 2.4rem;
        font-weight: 600;
        line-height: 1;
        margin-bottom: .35rem;
    }
    .stat-red   .stat-number { color: var(--red); }
    .stat-amber .stat-number { color: var(--amber); }
    .stat-teal  .stat-number { color: var(--teal); }
    .stat-green .stat-number { color: var(--green); }
    .stat-cyan  .stat-number { color: #06b6d4; }

    .stat-label {
        font-size: .72rem;
        color: var(--muted-2);
        text-transform: uppercase;
        letter-spacing: .09em;
        font-weight: 600;
    }
    .stat-icon {
        width: 44px; height: 44px;
        border-radius: var(--r-md);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }
    .icon-red   { background: var(--red-dim);   color: var(--red);   border: 1px solid rgba(239,68,68,.25);  }
    .icon-amber { background: var(--amber-dim); color: var(--amber); border: 1px solid rgba(245,158,11,.22); }
    .icon-teal  { background: var(--teal-dim);  color: var(--teal);  border: 1px solid rgba(34,211,238,.22); }
    .icon-green { background: var(--green-dim); color: var(--green); border: 1px solid rgba(74,222,128,.22); }
    .icon-cyan  { background: rgba(6,182,212,.12); color: #06b6d4; border: 1px solid rgba(6,182,212,.22); }

    .stat-card::after {
        content: '';
        position: absolute; bottom: 0; left: 0;
        height: 2px; width: 0;
        transition: width .35s ease;
    }
    .stat-card:hover::after { width: 100%; }
    .stat-red::after   { background: var(--red);   }
    .stat-amber::after { background: var(--amber); }
    .stat-teal::after  { background: var(--teal);  }
    .stat-green::after { background: var(--green); }
    .stat-cyan::after  { background: #06b6d4; }

    .stat-card:nth-child(1) { animation-delay: .04s; }
    .stat-card:nth-child(2) { animation-delay: .09s; }
    .stat-card:nth-child(3) { animation-delay: .14s; }
    .stat-card:nth-child(4) { animation-delay: .19s; }
    .stat-card:nth-child(5) { animation-delay: .24s; }

    .dashboard-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-lg);
        margin-bottom: 1.25rem;
        overflow: hidden;
        animation: fadeUp .45s ease both;
    }

    .card-header-custom {
        background: var(--surface-2);
        border-bottom: 1px solid var(--border);
        padding: .8rem 1.3rem;
        font-family: var(--ff-head);
        font-weight: 700;
        font-size: .78rem;
        color: var(--text-2);
        letter-spacing: .09em;
        text-transform: uppercase;
        display: flex; align-items: center; justify-content: space-between;
        gap: .5rem;
    }
    .card-header-custom .hd-left { display: flex; align-items: center; gap: .5rem; }
    .hd-icon-wrap {
        width: 24px; height: 24px;
        border-radius: 6px;
        display: flex; align-items: center; justify-content: center;
        font-size: .8rem;
        flex-shrink: 0;
    }
    .hd-red   { background: var(--red-dim);   color: var(--red);   }
    .hd-amber { background: var(--amber-dim); color: var(--amber); }
    .hd-teal  { background: var(--teal-dim);  color: var(--teal);  }
    .card-header-custom .view-all {
        font-family: var(--ff-body);
        font-size: .73rem;
        font-weight: 500;
        text-transform: none;
        letter-spacing: 0;
        color: var(--red);
        text-decoration: none;
        padding: .18rem .6rem;
        border: 1px solid rgba(239,68,68,.25);
        border-radius: 4px;
        transition: all var(--ease);
        white-space: nowrap;
        flex-shrink: 0;
    }
    .card-header-custom .view-all:hover {
        background: var(--red);
        color: #fff;
        border-color: var(--red);
    }

    .incident-item {
        padding: .95rem 1.3rem;
        border-bottom: 1px solid var(--border);
        transition: background var(--ease);
        cursor: pointer;
    }
    .incident-item:last-child { border-bottom: none; }
    .incident-item:hover { background: var(--surface-2); }

    .incident-item h6 {
        font-family: var(--ff-body);
        font-weight: 600;
        font-size: .875rem;
        color: var(--text);
        margin-bottom: .25rem;
        display: flex; align-items: center; gap: .35rem;
    }
    .incident-item .meta {
        font-size: .72rem;
        color: var(--muted-2);
        font-family: var(--ff-mono);
        letter-spacing: .01em;
    }
    .incident-item .desc {
        font-size: .8rem;
        color: var(--muted-2);
        margin-top: .35rem;
        line-height: 1.5;
    }

    .severity-badge, .status-badge {
        padding: .22rem .65rem;
        border-radius: 4px;
        font-size: .66rem;
        font-weight: 700;
        font-family: var(--ff-mono);
        letter-spacing: .05em;
        display: inline-block;
        text-transform: uppercase;
    }
    .severity-4,
    .severity-critical { background: rgba(239,68,68,.18);  color: #fca5a5; border: 1px solid rgba(239,68,68,.4); }
    .severity-3,
    .severity-high     { background: rgba(251,146,60,.15); color: #fdba74; border: 1px solid rgba(251,146,60,.38); }
    .severity-2,
    .severity-medium   { background: rgba(251,191,36,.12); color: #fcd34d; border: 1px solid rgba(251,191,36,.35); }
    .severity-1,
    .severity-low      { background: rgba(74,222,128,.12); color: #86efac; border: 1px solid rgba(74,222,128,.35); }

    .status-reported     { background: rgba(239,68,68,.12);  color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }
    .status-acknowledged { background: rgba(251,146,60,.12); color: #fdba74; border: 1px solid rgba(251,146,60,.28); }
    .status-in_progress,
    .status-in-progress  { background: rgba(34,211,238,.12); color: #67e8f9; border: 1px solid rgba(34,211,238,.3); }
    .status-assigned     { background: rgba(148,163,184,.1); color: #cbd5e1; border: 1px solid rgba(148,163,184,.25); }
    .status-resolved     { background: rgba(74,222,128,.12); color: #86efac; border: 1px solid rgba(74,222,128,.3); }

    .quick-action-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        padding: 1.2rem .75rem;
        border-radius: var(--r-md);
        background: var(--surface-2);
        border: 1px solid var(--border);
        text-decoration: none;
        transition: all var(--ease);
        position: relative;
        overflow: hidden;
        min-height: 100px;
    }
    .quick-action-btn::before {
        content: '';
        position: absolute; inset: 0;
        background: linear-gradient(135deg, var(--red-dim) 0%, transparent 70%);
        opacity: 0;
        transition: opacity var(--ease);
    }
    .quick-action-btn:hover {
        border-color: rgba(239,68,68,.4);
        transform: translateY(-4px);
        box-shadow: 0 8px 28px rgba(239,68,68,.18);
    }
    .quick-action-btn:hover::before { opacity: 1; }
    .quick-action-btn i {
        font-size: 1.6rem;
        color: var(--red);
        position: relative;
        transition: transform var(--ease);
    }
    .quick-action-btn:hover i { transform: scale(1.1); }
    .quick-action-btn span {
        font-size: .72rem;
        font-weight: 600;
        color: var(--muted-2);
        text-transform: uppercase;
        letter-spacing: .08em;
        position: relative;
        transition: color var(--ease);
        text-align: center;
    }
    .quick-action-btn:hover span { color: var(--text); }

    .btn-verify-sm {
        background: rgba(74,222,128,.14);
        border: 1px solid rgba(74,222,128,.35);
        padding: .25rem .75rem;
        font-size: .69rem;
        border-radius: 4px;
        color: #86efac;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        font-family: var(--ff-mono);
        font-weight: 600;
        letter-spacing: .03em;
        transition: all var(--ease);
        white-space: nowrap;
    }
    .btn-verify-sm:hover {
        background: rgba(74,222,128,.25);
        border-color: rgba(74,222,128,.55);
        color: #bbf7d0;
    }

    .empty-state {
        padding: 2.75rem 1rem;
        text-align: center;
        color: var(--muted-2);
    }
    .empty-state i {
        font-size: 2.4rem;
        display: block;
        margin-bottom: .75rem;
        opacity: .3;
    }
    .empty-state p { font-size: .83rem; }

    .activity-name { font-size: .8rem; font-weight: 600; color: var(--red); }
    .activity-verb { font-size: .78rem; color: var(--muted-2); }
    .activity-time { font-size: .68rem; font-family: var(--ff-mono); color: var(--muted); white-space: nowrap; }
    .activity-quote {
        font-size: .78rem;
        color: var(--muted-2);
        margin-top: .3rem;
        line-height: 1.5;
        border-left: 2px solid rgba(239,68,68,.3);
        padding-left: .6rem;
    }

    .chart-wrap { padding: 1.25rem 1.1rem; }

    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(16px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .dashboard-card:nth-child(1) { animation-delay: .07s; }
    .dashboard-card:nth-child(2) { animation-delay: .13s; }
    .dashboard-card:nth-child(3) { animation-delay: .19s; }

    @media (max-width: 768px) {
        .stat-number { font-size: 1.8rem; }
        .welcome-section h1 { font-size: 1.2rem; }
        .welcome-icon-bg { display: none; }
        .nav-pill { font-size: .72rem; padding: .28rem .6rem; }
    }

    .container { max-width: 1280px; }
    </style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════
     NAVBAR
═══════════════════════════════════════════════════════════ -->
<nav class="navbar-modern">
    <div class="container d-flex align-items-center justify-content-between gap-2">

        <a class="navbar-brand" href="responders_dashboard.php">
            <i class="bi bi-shield-check brand-accent"></i>
            Disaster<span class="brand-accent">Response</span>
        </a>

        <div class="d-flex gap-2 align-items-center flex-wrap">
            <span class="user-chip d-none d-md-flex align-items-center gap-2">
                <span class="online-ring"></span>
                <?php echo htmlspecialchars($_SESSION['full_name']); ?>
            </span>
            <a href="responders_dashboard.php" class="nav-pill active">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a href="team.php" class="nav-pill">
                <i class="bi bi-people me-1"></i>Team
            </a>
            <a href="updates.php" class="nav-pill">
                <i class="bi bi-chat-dots me-1"></i>Updates
            </a>
            <a href="../incidents/pending.php" class="nav-pill" style="position:relative;">
                <i class="bi bi-clock-history me-1"></i>Pending
                <?php if ($pending_count > 0): ?>
                    <span class="pending-badge"><?php echo $pending_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="../mapping/map.php" class="nav-pill danger">
                <i class="bi bi-map me-1"></i>Live Map
            </a>
            <a href="../auth/logout.php" class="nav-pill danger"
               onclick="return confirm('Logout?');">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<!-- ═══════════════════════════════════════════════════════════
     MAIN
═══════════════════════════════════════════════════════════ -->
<div class="container py-4">

    <!-- ── Welcome Banner ──────────────────────────────────── -->
    <div class="welcome-section">
        <div class="row align-items-center">
            <div class="col-md-9">
                <h1>
                    <span class="status-dot"></span>
                    Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                </h1>
                <p>Logged in as Emergency Responder &mdash; Command Centre is live.</p>
                <div class="datetime-tag">
                    <i class="bi bi-clock"></i>
                    <span id="live-clock"></span>
                </div>
            </div>
            <div class="col-md-3 text-end d-none d-md-block">
                <div class="welcome-icon-bg">
                    <i class="bi bi-megaphone-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Stat Cards (5 columns) ──────────────────────────── -->
    <div class="row">
        <div class="col-md-2 col-6">
            <div class="stat-card stat-red">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?php echo $active_incidents; ?></div>
                        <p class="stat-label">Active Incidents</p>
                    </div>
                    <div class="stat-icon icon-red"><i class="bi bi-exclamation-triangle-fill"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stat-card stat-amber">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?php echo $pending_count; ?></div>
                        <p class="stat-label">Pending</p>
                    </div>
                    <div class="stat-icon icon-amber"><i class="bi bi-clock-history"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stat-card stat-teal">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?php echo $my_assigned; ?></div>
                        <p class="stat-label">My Assignments</p>
                    </div>
                    <div class="stat-icon icon-teal"><i class="bi bi-person-badge-fill"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stat-card stat-cyan">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?php echo $online_team; ?></div>
                        <p class="stat-label">Team Online</p>
                    </div>
                    <div class="stat-icon icon-cyan"><i class="bi bi-people-fill"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stat-card stat-green">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?php echo $available_resources; ?></div>
                        <p class="stat-label">Resource Types</p>
                    </div>
                    <div class="stat-icon icon-green"><i class="bi bi-box-seam-fill"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="stat-card stat-teal">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-number"><?php echo $recent_updates_count; ?></div>
                        <p class="stat-label">Updates (24h)</p>
                    </div>
                    <div class="stat-icon icon-teal"><i class="bi bi-chat-dots-fill"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Quick Actions ────────────────────────────────────── -->
    <div class="row mb-1">
        <div class="col-12">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <div class="hd-left">
                        <span class="hd-icon-wrap hd-red"><i class="bi bi-lightning-charge-fill"></i></span>
                        Quick Actions
                    </div>
                </div>
                <div class="p-3">
                    <div class="row g-3">
                        <div class="col-md-2 col-6">
                            <a href="../mapping/map.php" class="quick-action-btn">
                                <i class="bi bi-map"></i>
                                <span>Live Map</span>
                            </a>
                        </div>
                        <div class="col-md-2 col-6">
                            <a href="../incidents/pending.php" class="quick-action-btn">
                                <i class="bi bi-check2-circle"></i>
                                <span>Verify</span>
                                <?php if ($pending_count > 0): ?>
                                    <span class="pending-badge" style="position:static;display:inline-block;margin-left:4px;">
                                        <?php echo $pending_count; ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </div>
                        <div class="col-md-2 col-6">
                            <a href="team.php" class="quick-action-btn">
                                <i class="bi bi-people"></i>
                                <span>Team</span>
                            </a>
                        </div>
                        <div class="col-md-2 col-6">
                            <a href="updates.php" class="quick-action-btn">
                                <i class="bi bi-chat-dots"></i>
                                <span>Field Updates</span>
                            </a>
                        </div>
                        <div class="col-md-2 col-6">
                            <a href="../alerts/broadcast.php" class="quick-action-btn">
                                <i class="bi bi-megaphone"></i>
                                <span>Alert</span>
                            </a>
                        </div>
                        <div class="col-md-2 col-6">
                            <a href="../messaging/inbox.php" class="quick-action-btn">
                                <i class="bi bi-envelope"></i>
                                <span>Messages</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Pending Verification + Chart + Activity ───────────── -->
    <div class="row">

        <!-- Pending incidents -->
        <div class="col-lg-7">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <div class="hd-left">
                        <span class="hd-icon-wrap hd-amber"><i class="bi bi-clock-history"></i></span>
                        Pending Verification
                    </div>
                    <a href="../incidents/pending.php" class="view-all">View All →</a>
                </div>
                <div>
                    <?php if (count($pending_incidents) > 0): ?>
                        <?php foreach ($pending_incidents as $incident): ?>
                            <div class="incident-item">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div style="flex:1;min-width:0;">
                                        <h6>
                                            <i class="bi bi-geo-alt-fill" style="color:var(--amber);font-size:.8rem"></i>
                                            <?php echo htmlspecialchars($incident['location_name'] ?? 'Unknown location'); ?>
                                        </h6>
                                        <div class="meta">
                                            <?php echo ucfirst($incident['incident_type']); ?> &bull;
                                            <?php echo htmlspecialchars($incident['reporter_name']); ?> &bull;
                                            <?php echo date('M j, H:i', strtotime($incident['reported_at'])); ?>
                                        </div>
                                        <p class="desc"><?php echo htmlspecialchars(substr($incident['description'], 0, 100)); ?>…</p>
                                    </div>
                                    <div class="text-end flex-shrink-0">
                                        <?php
                                            $sev = $incident['severity'];
                                            $sev_label = match((int)$sev) { 4=>'CRITICAL', 3=>'HIGH', 2=>'MEDIUM', default=>'LOW' };
                                        ?>
                                        <span class="severity-badge severity-<?php echo $sev; ?> d-block mb-2">
                                            <?php echo $sev_label; ?>
                                        </span>
                                        <a href="../incidents/verify.php?id=<?php echo $incident['id']; ?>"
                                           class="btn-verify-sm">
                                            <i class="bi bi-check2-circle"></i> Verify
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle-fill" style="color:var(--green)"></i>
                            <p>No pending incidents. All caught up!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">

            <!-- Severity chart -->
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <div class="hd-left">
                        <span class="hd-icon-wrap hd-red"><i class="bi bi-pie-chart-fill"></i></span>
                        Incidents by Severity
                    </div>
                </div>
                <div class="chart-wrap">
                    <canvas id="severityChart" height="200"></canvas>
                </div>
            </div>

            <!-- Recent Field Updates Feed -->
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <div class="hd-left">
                        <span class="hd-icon-wrap hd-teal"><i class="bi bi-chat-dots-fill"></i></span>
                        Recent Field Updates
                    </div>
                    <a href="updates.php" class="view-all">Post Update →</a>
                </div>
                <div>
                    <?php if (count($recent_field_updates) > 0): ?>
                        <?php foreach ($recent_field_updates as $update): ?>
                            <div class="incident-item">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <span class="activity-name"><?php echo htmlspecialchars($update['responder_name']); ?></span>
                                        <span class="activity-verb ms-1">
                                            <?php if ($update['incident_id']): ?>
                                                at incident #<?= str_pad($update['incident_id'], 5, '0', STR_PAD_LEFT) ?>
                                            <?php else: ?>
                                                posted update
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <span class="activity-time">
                                        <?php echo date('M j, H:i', strtotime($update['created_at'])); ?>
                                    </span>
                                </div>
                                <div class="activity-quote mt-1">
                                    <?php echo htmlspecialchars(substr($update['update_text'] ?? 'No message', 0, 85)); ?>…
                                </div>
                                <?php if ($update['photo_path']): ?>
                                    <div class="mt-2">
                                        <i class="bi bi-image text-muted"></i>
                                        <span class="text-muted small">Photo attached</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state" style="padding:1.75rem 1rem">
                            <i class="bi bi-chat-dots"></i>
                            <p>No field updates yet.<br><a href="updates.php" style="color:var(--red)">Post the first update →</a></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- ── Recent Active Incidents ──────────────────────────── -->
    <div class="row">
        <div class="col-12">
            <div class="dashboard-card">
                <div class="card-header-custom">
                    <div class="hd-left">
                        <span class="hd-icon-wrap hd-red"><i class="bi bi-exclamation-triangle-fill"></i></span>
                        Recent Active Incidents
                    </div>
                    <a href="../incidents/all.php" class="view-all">View All →</a>
                </div>
                <div>
                    <?php if (count($recent_incidents) > 0): ?>
                        <?php foreach ($recent_incidents as $incident): ?>
                            <div class="incident-item"
                                 onclick="window.location.href='../incidents/view.php?id=<?php echo $incident['id']; ?>'">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div style="flex:1;min-width:0;">
                                        <h6>
                                            <i class="bi bi-geo-alt-fill" style="color:var(--red);font-size:.8rem"></i>
                                            <?php echo htmlspecialchars($incident['location_name'] ?? 'Unknown location'); ?>
                                        </h6>
                                        <div class="meta">
                                            <?php echo ucfirst($incident['incident_type']); ?> &bull;
                                            <?php echo htmlspecialchars($incident['reporter_name']); ?> &bull;
                                            <?php echo date('M j, H:i', strtotime($incident['reported_at'])); ?>
                                        </div>
                                        <p class="desc"><?php echo htmlspecialchars(substr($incident['description'], 0, 110)); ?>…</p>
                                    </div>
                                    <div class="text-end flex-shrink-0">
                                        <?php
                                            $sev2 = $incident['severity'];
                                            $sev2_label = match((int)$sev2) { 4=>'CRITICAL', 3=>'HIGH', 2=>'MEDIUM', default=>'LOW' };
                                            $st = str_replace('-', '_', $incident['status']);
                                        ?>
                                        <span class="severity-badge severity-<?php echo $sev2; ?> d-block mb-1">
                                            <?php echo $sev2_label; ?>
                                        </span>
                                        <span class="status-badge status-<?php echo $st; ?>">
                                            <?php echo ucfirst(str_replace('-', ' ', $incident['status'])); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle-fill" style="color:var(--green)"></i>
                            <p>No active incidents at the moment.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    /* ── Doughnut chart ─────────────────────────────────────── */
    const ctx = document.getElementById('severityChart').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($severity_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($severity_counts); ?>,
                backgroundColor: ['#4ade8022','#fbbf2422','#fb923c22','#f8717122'],
                borderColor:     ['#4ade80',  '#fbbf24',  '#fb923c',  '#f87171'],
                borderWidth: 2,
                hoverOffset: 10,
                hoverBackgroundColor: ['#4ade8044','#fbbf2444','#fb923c44','#f8717144'],
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '70%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: '#94a3b8',
                        font: { family: "'JetBrains Mono', monospace", size: 11 },
                        padding: 16,
                        boxWidth: 10,
                        boxHeight: 10,
                        usePointStyle: true,
                        pointStyleWidth: 10,
                    }
                }
            }
        }
    });

    /* ── Live clock ─────────────────────────────────────────── */
    function updateClock() {
        const el = document.getElementById('live-clock');
        if (!el) return;
        const now = new Date();
        el.textContent = now.toLocaleString('en-KE', {
            weekday: 'short', year: 'numeric',
            month:   'short', day:  'numeric',
            hour:    '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false
        });
    }
    updateClock();
    setInterval(updateClock, 1000);
</script>
</body>
</html>