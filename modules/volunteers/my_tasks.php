<?php
/**
 * Volunteer My Tasks - Complete Task Management
 * Disaster Response & Resource Coordination System
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

if (!isLoggedIn()) redirect('modules/auth/login.php');
if (!hasRole(['volunteer', 'admin'])) redirect('index.php');

$user_id = $_SESSION['user_id'];
$success = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $task_id = (int)$_POST['task_id'];
        $status = $_POST['status'];
        try {
            $stmt = $pdo->prepare("UPDATE volunteer_tasks SET status = ?, completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END WHERE id = ? AND volunteer_id = ?");
            if ($stmt->execute([$status, $status, $task_id, $user_id])) {
                if ($status === 'completed') {
                    $pdo->prepare("UPDATE volunteers SET availability_status = 'available' WHERE user_id = ?")->execute([$user_id]);
                    $pdo->prepare("INSERT INTO task_progress (task_id, progress_step, notes) VALUES (?, 6, 'Task completed')")->execute([$task_id]);
                }
                $success = "Task status updated!";
            } else $error = "Failed to update task.";
        } catch (PDOException $e) { $error = "Database error."; }
    }
    if ($_POST['action'] === 'accept_task') {
        $task_id = (int)$_POST['task_id'];
        try {
            $stmt = $pdo->prepare("UPDATE volunteer_tasks SET status = 'in_progress' WHERE id = ? AND volunteer_id = ? AND status = 'assigned'");
            if ($stmt->execute([$task_id, $user_id])) {
                $pdo->prepare("UPDATE volunteers SET availability_status = 'busy' WHERE user_id = ?")->execute([$user_id]);
                $pdo->prepare("INSERT INTO task_progress (task_id, progress_step, notes) VALUES (?, 1, 'Task accepted')")->execute([$task_id]);
                $success = "Task accepted! You can now start working.";
            } else $error = "Failed to accept task.";
        } catch (PDOException $e) { $error = "Database error."; }
    }
}

$stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='assigned' THEN 1 ELSE 0 END) as assigned, SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed FROM volunteer_tasks WHERE volunteer_id = ?");
$stmt->execute([$user_id]);
$stats = $stmt->fetch();
if (!$stats) $stats = ['total'=>0,'assigned'=>0,'in_progress'=>0,'completed'=>0];

$stmt = $pdo->prepare("SELECT COUNT(*) as unread FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread_messages = $stmt->fetch()['unread'];

$stmt = $pdo->prepare("SELECT vt.*, i.incident_type, i.severity, i.location_name, i.description as incident_desc, u.full_name as assigned_by_name FROM volunteer_tasks vt JOIN incidents i ON vt.incident_id = i.id JOIN users u ON vt.assigned_by = u.id WHERE vt.volunteer_id = ? AND vt.status = 'assigned' ORDER BY vt.created_at DESC");
$stmt->execute([$user_id]);
$pending_tasks = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT vt.*, i.incident_type, i.severity, i.location_name, i.description as incident_desc, u.full_name as assigned_by_name, (SELECT COUNT(*) FROM task_progress WHERE task_id = vt.id) as progress_count FROM volunteer_tasks vt JOIN incidents i ON vt.incident_id = i.id JOIN users u ON vt.assigned_by = u.id WHERE vt.volunteer_id = ? AND vt.status = 'in_progress' ORDER BY vt.created_at DESC");
$stmt->execute([$user_id]);
$in_progress_tasks = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT vt.*, i.incident_type, i.location_name FROM volunteer_tasks vt JOIN incidents i ON vt.incident_id = i.id WHERE vt.volunteer_id = ? AND vt.status = 'completed' ORDER BY vt.completed_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$completed_tasks = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT skills, availability_status FROM volunteers WHERE user_id = ?");
$stmt->execute([$user_id]);
$volunteer = $stmt->fetch();
if (!$volunteer) {
    $pdo->prepare("INSERT INTO volunteers (user_id, skills, availability_status) VALUES (?, '', 'available')")->execute([$user_id]);
    $volunteer = ['skills'=>'','availability_status'=>'available'];
}

$completion_rate = $stats['total'] > 0 ? round(($stats['completed'] / $stats['total']) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks — Volunteer Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
    /* ── Design Tokens ─────────────────────────────────────────────────── */
    :root {
        --bg:          #f5f6f8;
        --surface:     #ffffff;
        --surface-2:   #f0f2f5;
        --surface-3:   #e8ebf0;

        --border:      #e2e6ed;
        --border-2:    #d0d5de;

        --red:         #dc2626;
        --red-dim:     rgba(220,38,38,.08);
        --red-border:  rgba(220,38,38,.25);

        --amber:       #d97706;
        --amber-dim:   rgba(217,119,6,.09);
        --amber-border:rgba(217,119,6,.3);

        --green:       #16a34a;
        --green-dim:   rgba(22,163,74,.08);
        --green-border:rgba(22,163,74,.28);

        --blue:        #2563eb;
        --blue-dim:    rgba(37,99,235,.08);
        --blue-border: rgba(37,99,235,.25);

        --text:        #111827;
        --text-2:      #374151;
        --muted:       #9ca3af;
        --muted-2:     #6b7280;

        --ff-head: 'Syne', sans-serif;
        --ff-body: 'DM Sans', sans-serif;
        --ff-mono: 'DM Mono', monospace;

        --r-sm: 6px;
        --r-md: 10px;
        --r-lg: 14px;
        --ease: .18s cubic-bezier(.4,0,.2,1);

        --shadow-sm: 0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.05);
        --shadow-md: 0 4px 12px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.04);
        --shadow-lg: 0 10px 28px rgba(0,0,0,.1);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body { font-family: var(--ff-body); background: var(--bg); color: var(--text); min-height: 100vh; }
    ::-webkit-scrollbar { width: 5px; }
    ::-webkit-scrollbar-track { background: var(--bg); }
    ::-webkit-scrollbar-thumb { background: var(--border-2); border-radius: 3px; }

    /* ── Navbar ──────────────────────────────────────────────────────── */
    .nav {
        position: sticky; top: 0; z-index: 200;
        display: flex; align-items: center; justify-content: space-between;
        padding: 0 32px;
        height: 60px;
        background: var(--surface);
        border-bottom: 1px solid var(--border);
        box-shadow: var(--shadow-sm);
    }
    .nav-brand {
        font-family: var(--ff-head);
        font-size: 1.25rem; font-weight: 800;
        letter-spacing: -.01em;
        color: var(--red); text-decoration: none;
        display: flex; align-items: center; gap: 8px;
    }
    .nav-brand span { color: var(--text); }
    .nav-right { display: flex; align-items: center; gap: 4px; }
    .nav-user {
        font-size: .8rem; font-weight: 600; color: var(--text-2);
        padding: 0 14px;
        border-right: 1px solid var(--border);
        margin-right: 4px;
        white-space: nowrap;
    }
    .nav-pill {
        font-size: .75rem; font-weight: 500;
        color: var(--muted-2); text-decoration: none;
        padding: 6px 13px; border-radius: var(--r-sm);
        border: 1px solid transparent;
        transition: all var(--ease);
        position: relative; white-space: nowrap;
    }
    .nav-pill:hover { color: var(--text); background: var(--surface-2); border-color: var(--border); }
    .nav-pill.danger:hover { color: var(--red); background: var(--red-dim); border-color: var(--red-border); }
    .notif-dot {
        position: absolute; top: 4px; right: 7px;
        width: 6px; height: 6px;
        background: var(--red); border-radius: 50%;
        border: 1.5px solid var(--surface);
    }

    /* ── Page shell ─────────────────────────────────────────────────── */
    .page { max-width: 1280px; margin: 0 auto; padding: 28px 32px 80px; }

    /* ── Toasts ─────────────────────────────────────────────────────── */
    .toast-bar {
        display: flex; align-items: center; gap: 10px;
        padding: 13px 18px; border-radius: var(--r-md);
        font-size: .85rem; font-weight: 500;
        margin-bottom: 20px; border: 1px solid;
    }
    .toast-success { background: var(--green-dim); border-color: var(--green-border); color: var(--green); }
    .toast-error   { background: var(--red-dim);   border-color: var(--red-border);   color: var(--red); }

    /* ── Page header ─────────────────────────────────────────────────── */
    .page-header {
        display: flex; align-items: flex-end; justify-content: space-between;
        margin-bottom: 24px; padding-bottom: 20px;
        border-bottom: 1px solid var(--border);
        flex-wrap: wrap; gap: 16px;
    }
    .eyebrow {
        font-family: var(--ff-mono);
        font-size: .68rem; letter-spacing: .18em;
        text-transform: uppercase; color: var(--red);
        margin-bottom: 5px;
    }
    .page-title {
        font-family: var(--ff-head);
        font-size: clamp(2rem, 4vw, 3rem);
        font-weight: 800; letter-spacing: -.02em; line-height: 1;
        color: var(--text);
    }
    .status-pill {
        display: inline-flex; align-items: center; gap: 7px;
        font-size: .78rem; font-weight: 600;
        padding: 7px 16px; border-radius: 100px; border: 1.5px solid;
    }
    .status-pill.available { background: var(--green-dim); border-color: var(--green-border); color: var(--green); }
    .status-pill.busy      { background: var(--surface-2); border-color: var(--border-2);     color: var(--muted-2); }
    .status-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }

    /* ── Stats row ───────────────────────────────────────────────────── */
    .stats-row {
        display: grid; grid-template-columns: repeat(4,1fr);
        gap: 12px; margin-bottom: 24px;
    }
    .stat-block {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-lg);
        padding: 18px 20px;
        position: relative; overflow: hidden;
        box-shadow: var(--shadow-sm);
        transition: box-shadow var(--ease), transform var(--ease);
    }
    .stat-block:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
    .stat-block::after {
        content: '';
        position: absolute; bottom: 0; left: 0; right: 0;
        height: 3px; border-radius: 0 0 var(--r-lg) var(--r-lg);
    }
    .stat-block.s-total::after   { background: var(--blue); }
    .stat-block.s-pending::after { background: var(--amber); }
    .stat-block.s-progress::after{ background: var(--blue); }
    .stat-block.s-done::after    { background: var(--green); }

    .stat-icon {
        width: 36px; height: 36px; border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        font-size: .95rem; margin-bottom: 12px;
    }
    .si-blue   { background: var(--blue-dim);  color: var(--blue);  border: 1px solid var(--blue-border); }
    .si-amber  { background: var(--amber-dim); color: var(--amber); border: 1px solid var(--amber-border); }
    .si-green  { background: var(--green-dim); color: var(--green); border: 1px solid var(--green-border); }

    .stat-value {
        font-family: var(--ff-mono);
        font-size: 2.2rem; font-weight: 600; line-height: 1;
        margin-bottom: 4px;
    }
    .sv-blue   { color: var(--blue); }
    .sv-amber  { color: var(--amber); }
    .sv-green  { color: var(--green); }
    .sv-dark   { color: var(--text); }
    .stat-label { font-size: .72rem; color: var(--muted-2); text-transform: uppercase; letter-spacing: .07em; font-weight: 600; }
    .stat-sub   { font-size: .72rem; color: var(--muted); margin-top: 4px; }

    /* ── Skills bar ──────────────────────────────────────────────────── */
    .skills-bar {
        display: flex; align-items: center; gap: 16px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-md);
        padding: 12px 18px;
        margin-bottom: 24px;
        flex-wrap: wrap;
        box-shadow: var(--shadow-sm);
    }
    .skills-bar-label {
        font-family: var(--ff-mono);
        font-size: .65rem; letter-spacing: .15em;
        text-transform: uppercase; color: var(--muted);
        white-space: nowrap;
    }
    .skills-divider { width: 1px; height: 18px; background: var(--border-2); }
    .skills-text { font-size: .83rem; color: var(--muted-2); flex: 1; }
    .skills-update {
        font-size: .72rem; font-weight: 700;
        color: var(--red); text-decoration: none;
        letter-spacing: .07em; text-transform: uppercase;
        white-space: nowrap;
        display: flex; align-items: center; gap: 5px;
    }
    .skills-update:hover { text-decoration: underline; }

    /* ── Task columns ────────────────────────────────────────────────── */
    .tasks-columns {
        display: grid; grid-template-columns: 1fr 1fr;
        gap: 16px; margin-bottom: 16px;
    }
    .col-block {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-lg);
        overflow: hidden;
        box-shadow: var(--shadow-sm);
    }
    .col-head {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 18px;
        background: var(--surface-2);
        border-bottom: 1px solid var(--border);
    }
    .col-head-title {
        display: flex; align-items: center; gap: 8px;
        font-family: var(--ff-mono);
        font-size: .68rem; letter-spacing: .15em;
        text-transform: uppercase; color: var(--muted-2);
        font-weight: 500;
    }
    .col-count {
        font-family: var(--ff-mono);
        font-size: 1.2rem; font-weight: 600; line-height: 1;
    }

    /* ── Task card ───────────────────────────────────────────────────── */
    .task-card {
        padding: 16px 18px;
        border-bottom: 1px solid var(--border);
        transition: background var(--ease);
    }
    .task-card:last-child { border-bottom: none; }
    .task-card:hover { background: var(--surface-2); }

    .task-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 6px; }
    .task-location { font-size: .88rem; font-weight: 600; display: flex; align-items: center; gap: 6px; color: var(--text); }
    .task-location i { color: var(--red); font-size: .75rem; }
    .task-meta { font-size: .74rem; color: var(--muted-2); margin-top: 2px; }
    .task-desc { font-size: .81rem; color: var(--muted-2); line-height: 1.65; margin: 8px 0 12px; }
    .task-actions { display: flex; gap: 7px; flex-wrap: wrap; }

    /* ── Severity badges ─────────────────────────────────────────────── */
    .sev-badge {
        font-family: var(--ff-mono);
        font-size: .6rem; font-weight: 600;
        letter-spacing: .1em; text-transform: uppercase;
        padding: 3px 9px; border-radius: 100px; border: 1px solid;
        white-space: nowrap;
    }
    .sev-4, .sev-critical { background: rgba(220,38,38,.1);  color: #b91c1c; border-color: rgba(220,38,38,.3); }
    .sev-3, .sev-high     { background: rgba(217,119,6,.1);  color: #b45309; border-color: rgba(217,119,6,.3); }
    .sev-2, .sev-medium   { background: rgba(234,179,8,.1);  color: #92400e; border-color: rgba(234,179,8,.3); }
    .sev-1, .sev-low      { background: rgba(22,163,74,.08); color: #15803d; border-color: rgba(22,163,74,.28); }

    /* ── Progress bar ────────────────────────────────────────────────── */
    .progress-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .progress-track { flex: 1; height: 5px; background: var(--surface-3); border-radius: 3px; overflow: hidden; }
    .progress-fill { height: 100%; background: var(--blue); border-radius: 3px; transition: width .4s; }
    .progress-label { font-family: var(--ff-mono); font-size: .63rem; color: var(--muted); white-space: nowrap; }

    /* ── Buttons ─────────────────────────────────────────────────────── */
    .btn {
        display: inline-flex; align-items: center; gap: 6px;
        font-size: .75rem; font-weight: 600;
        letter-spacing: .05em; text-transform: uppercase;
        text-decoration: none; padding: 7px 15px;
        border-radius: var(--r-sm); border: 1.5px solid;
        cursor: pointer; transition: all var(--ease);
        white-space: nowrap; background: none;
        font-family: var(--ff-body);
    }
    .btn-accept  { border-color: var(--green-border); color: var(--green); background: var(--green-dim); }
    .btn-accept:hover  { background: rgba(22,163,74,.15); border-color: rgba(22,163,74,.45); }
    .btn-update  { border-color: var(--blue-border);  color: var(--blue);  background: var(--blue-dim); }
    .btn-update:hover  { background: rgba(37,99,235,.15); }
    .btn-complete{ border-color: var(--green-border); color: var(--green); background: var(--green-dim); }
    .btn-complete:hover{ background: rgba(22,163,74,.15); }
    .btn-ghost   { border-color: var(--border-2); color: var(--muted-2); background: transparent; }
    .btn-ghost:hover{ border-color: var(--border-2); color: var(--text); background: var(--surface-2); }

    /* ── Bottom grid ─────────────────────────────────────────────────── */
    .bottom-grid {
        display: grid; grid-template-columns: 1fr 320px;
        gap: 16px;
    }

    /* ── Completed list ──────────────────────────────────────────────── */
    .completed-item {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 18px; border-bottom: 1px solid var(--border); gap: 14px;
    }
    .completed-item:last-child { border-bottom: none; }
    .completed-left { display: flex; align-items: center; gap: 12px; }
    .check-icon {
        width: 32px; height: 32px;
        background: var(--green-dim); border: 1.5px solid var(--green-border);
        border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        color: var(--green); font-size: .8rem; flex-shrink: 0;
    }
    .completed-type { font-size: .87rem; font-weight: 600; color: var(--text); }
    .completed-loc  { font-size: .73rem; color: var(--muted); margin-top: 2px; }
    .completed-date { font-family: var(--ff-mono); font-size: .65rem; color: var(--muted); white-space: nowrap; }

    /* ── Sidebar ─────────────────────────────────────────────────────── */
    .sidebar-block {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-lg);
        overflow: hidden; margin-bottom: 12px;
        box-shadow: var(--shadow-sm);
    }
    .sidebar-head {
        padding: 12px 16px;
        background: var(--surface-2);
        border-bottom: 1px solid var(--border);
        font-family: var(--ff-mono);
        font-size: .63rem; letter-spacing: .17em;
        text-transform: uppercase; color: var(--muted-2);
        font-weight: 500;
    }
    .sidebar-body { padding: 12px; }

    .action-btn {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 12px; border-radius: var(--r-sm);
        border: 1px solid var(--border);
        color: var(--text-2); text-decoration: none;
        font-size: .81rem; font-weight: 500;
        transition: all var(--ease); margin-bottom: 6px;
    }
    .action-btn:last-child { margin-bottom: 0; }
    .action-btn:hover { border-color: var(--border-2); background: var(--surface-2); color: var(--text); }
    .action-btn i { width: 16px; text-align: center; color: var(--red); font-size: .85rem; }

    .tip-item {
        display: flex; align-items: flex-start; gap: 9px;
        padding: 8px 0; border-bottom: 1px solid var(--border);
        font-size: .8rem; color: var(--text-2); line-height: 1.5;
    }
    .tip-item:last-child { border-bottom: none; padding-bottom: 0; }
    .tip-item i { color: var(--green); margin-top: 2px; flex-shrink: 0; font-size: .72rem; }

    /* ── Empty state ─────────────────────────────────────────────────── */
    .empty { padding: 36px 16px; text-align: center; color: var(--muted); }
    .empty i { font-size: 1.8rem; margin-bottom: 10px; display: block; opacity: .35; }
    .empty p { font-size: .82rem; }

    /* ── Reveal ──────────────────────────────────────────────────────── */
    .reveal { opacity: 0; transform: translateY(14px); transition: opacity .45s ease, transform .45s ease; }
    .reveal.in { opacity: 1; transform: translateY(0); }

    @media (max-width: 900px) {
        .nav { padding: 0 16px; }
        .page { padding: 20px 16px 60px; }
        .stats-row { grid-template-columns: repeat(2,1fr); }
        .tasks-columns { grid-template-columns: 1fr; }
        .bottom-grid { grid-template-columns: 1fr; }
    }
    </style>
</head>
<body>

<!-- ── NAV ──────────────────────────────────────────────────────────── -->
<nav class="nav">
    <a href="my_tasks.php" class="nav-brand">
        <i class="fas fa-hands-helping"></i><span>Volunteer</span>HQ
    </a>
    <div class="nav-right">
        <div class="nav-user"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
        <a href="register.php" class="nav-pill">Profile</a>
        <a href="../messaging/inbox.php" class="nav-pill" style="position:relative;">
            Messages
            <?php if($unread_messages > 0): ?><span class="notif-dot"></span><?php endif; ?>
        </a>
        <a href="../mapping/map.php" class="nav-pill">Map</a>
        <a href="../auth/logout.php" class="nav-pill danger" onclick="return confirm('Logout?');">Logout</a>
    </div>
</nav>

<div class="page">

    <?php if($success): ?>
    <div class="toast-bar toast-success reveal"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if($error): ?>
    <div class="toast-bar toast-error reveal"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="page-header reveal">
        <div>
            <div class="eyebrow">// Volunteer Dashboard</div>
            <h1 class="page-title">My Tasks</h1>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
            <div class="status-pill <?= $volunteer['availability_status'] === 'available' ? 'available' : 'busy' ?>">
                <span class="status-dot"></span>
                <?= ucfirst($volunteer['availability_status']) ?>
            </div>
            <div style="font-family:var(--ff-mono);font-size:.62rem;color:var(--muted);letter-spacing:.1em;"><?= strtoupper(date('D, M j Y')) ?></div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-row reveal">
        <div class="stat-block s-total">
            <div class="stat-icon si-blue"><i class="fas fa-list-check"></i></div>
            <div class="stat-value sv-dark"><?= $stats['total'] ?></div>
            <div class="stat-label">Total Tasks</div>
        </div>
        <div class="stat-block s-pending">
            <div class="stat-icon si-amber"><i class="fas fa-clock"></i></div>
            <div class="stat-value sv-amber"><?= $stats['assigned'] ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-block s-progress">
            <div class="stat-icon si-blue"><i class="fas fa-bolt"></i></div>
            <div class="stat-value sv-blue"><?= $stats['in_progress'] ?></div>
            <div class="stat-label">In Progress</div>
        </div>
        <div class="stat-block s-done">
            <div class="stat-icon si-green"><i class="fas fa-circle-check"></i></div>
            <div class="stat-value sv-green"><?= $stats['completed'] ?></div>
            <div class="stat-label">Completed</div>
            <div class="stat-sub"><?= $completion_rate ?>% completion rate</div>
        </div>
    </div>

    <!-- Skills bar -->
    <div class="skills-bar reveal">
        <div class="skills-bar-label">Skills</div>
        <div class="skills-divider"></div>
        <div class="skills-text">
            <?= !empty($volunteer['skills'])
                ? htmlspecialchars(substr($volunteer['skills'], 0, 60)) . (strlen($volunteer['skills']) > 60 ? '…' : '')
                : '<span style="color:var(--muted);font-style:italic">No skills listed yet</span>' ?>
        </div>
        <a href="register.php" class="skills-update"><i class="fas fa-pen"></i> Update Skills</a>
    </div>

    <!-- Task columns -->
    <div class="tasks-columns">

        <!-- PENDING -->
        <div class="col-block reveal">
            <div class="col-head">
                <div class="col-head-title">
                    <i class="fas fa-clock" style="color:var(--amber)"></i> Pending Tasks
                </div>
                <div class="col-count" style="color:var(--amber)"><?= $stats['assigned'] ?></div>
            </div>
            <?php if(count($pending_tasks) > 0): foreach($pending_tasks as $task): ?>
            <div class="task-card">
                <div class="task-top">
                    <div>
                        <div class="task-location">
                            <i class="fas fa-map-marker-alt"></i>
                            <?= htmlspecialchars($task['location_name'] ?? 'Location TBD') ?>
                        </div>
                        <div class="task-meta">
                            <?= ucfirst($task['incident_type']) ?> &nbsp;·&nbsp; Assigned by <?= htmlspecialchars($task['assigned_by_name']) ?>
                        </div>
                    </div>
                    <span class="sev-badge sev-<?= $task['severity'] ?>">
                        <?= match((int)$task['severity']) {4=>'Critical',3=>'High',2=>'Medium',default=>'Low'} ?>
                    </span>
                </div>
                <p class="task-desc"><?= htmlspecialchars(substr($task['task_description'] ?? $task['incident_desc'], 0, 100)) ?>...</p>
                <div class="task-actions">
                    <form method="POST" style="display:contents;">
                        <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                        <input type="hidden" name="action" value="accept_task">
                        <button type="submit" class="btn btn-accept"><i class="fas fa-check"></i> Accept</button>
                    </form>
                    <a href="../incidents/view.php?id=<?= $task['incident_id'] ?>" class="btn btn-ghost">
                        <i class="fas fa-arrow-right"></i> Details
                    </a>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div class="empty"><i class="fas fa-inbox"></i><p>No pending tasks</p></div>
            <?php endif; ?>
        </div>

        <!-- IN PROGRESS -->
        <div class="col-block reveal">
            <div class="col-head">
                <div class="col-head-title">
                    <i class="fas fa-bolt" style="color:var(--blue)"></i> In Progress
                </div>
                <div class="col-count" style="color:var(--blue)"><?= $stats['in_progress'] ?></div>
            </div>
            <?php if(count($in_progress_tasks) > 0): foreach($in_progress_tasks as $task): ?>
            <div class="task-card">
                <div class="task-top">
                    <div>
                        <div class="task-location">
                            <i class="fas fa-map-marker-alt"></i>
                            <?= htmlspecialchars($task['location_name'] ?? 'Location TBD') ?>
                        </div>
                        <div class="task-meta">
                            <?= ucfirst($task['incident_type']) ?> &nbsp;·&nbsp; Assigned by <?= htmlspecialchars($task['assigned_by_name']) ?>
                        </div>
                    </div>
                    <span class="sev-badge sev-<?= $task['severity'] ?>">
                        <?= match((int)$task['severity']) {4=>'Critical',3=>'High',2=>'Medium',default=>'Low'} ?>
                    </span>
                </div>
                <div class="progress-row">
                    <div class="progress-track">
                        <div class="progress-fill" style="width:<?= min(100, ($task['progress_count'] / 6) * 100) ?>%"></div>
                    </div>
                    <div class="progress-label"><?= $task['progress_count'] ?>/6 steps</div>
                </div>
                <p class="task-desc"><?= htmlspecialchars(substr($task['task_description'] ?? $task['incident_desc'], 0, 90)) ?>...</p>
                <div class="task-actions">
                    <a href="progress.php?id=<?= $task['id'] ?>" class="btn btn-update"><i class="fas fa-pen"></i> Update</a>
                    <form method="POST" style="display:contents;">
                        <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="status" value="completed">
                        <button type="submit" class="btn btn-complete" onclick="return confirm('Mark as complete?')">
                            <i class="fas fa-check-double"></i> Complete
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div class="empty"><i class="fas fa-play-circle"></i><p>No tasks in progress</p></div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Bottom grid -->
    <div class="bottom-grid">

        <!-- Completed -->
        <div class="col-block reveal">
            <div class="col-head">
                <div class="col-head-title">
                    <i class="fas fa-check-circle" style="color:var(--green)"></i> Recently Completed
                </div>
                <div class="col-count" style="color:var(--green)"><?= $stats['completed'] ?></div>
            </div>
            <?php if(count($completed_tasks) > 0): foreach($completed_tasks as $task): ?>
            <div class="completed-item">
                <div class="completed-left">
                    <div class="check-icon"><i class="fas fa-check"></i></div>
                    <div>
                        <div class="completed-type"><?= ucfirst($task['incident_type']) ?> Response</div>
                        <div class="completed-loc">
                            <i class="fas fa-map-marker-alt" style="font-size:.63rem;color:var(--red);margin-right:3px;"></i>
                            <?= htmlspecialchars($task['location_name'] ?? 'Location') ?>
                        </div>
                    </div>
                </div>
                <div class="completed-date"><?= date('M j, Y', strtotime($task['completed_at'])) ?></div>
            </div>
            <?php endforeach; else: ?>
            <div class="empty"><i class="fas fa-trophy"></i><p>No completed tasks yet — keep going!</p></div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div>
            <div class="sidebar-block reveal">
                <div class="sidebar-head">Quick Actions</div>
                <div class="sidebar-body">
                    <a href="../incidents/report.php" class="action-btn"><i class="fas fa-exclamation-triangle"></i> Report Incident</a>
                    <a href="register.php"            class="action-btn"><i class="fas fa-user-edit"></i> Update Profile</a>
                    <a href="../messaging/compose.php" class="action-btn"><i class="fas fa-envelope"></i> Send Message</a>
                    <a href="../mapping/map.php"       class="action-btn"><i class="fas fa-map"></i> View Live Map</a>
                </div>
            </div>
            <div class="sidebar-block reveal">
                <div class="sidebar-head">Field Tips</div>
                <div class="sidebar-body" style="padding:10px 16px;">
                    <div class="tip-item"><i class="fas fa-check-circle"></i>Keep your availability status updated at all times</div>
                    <div class="tip-item"><i class="fas fa-check-circle"></i>Only accept tasks that match your listed skills</div>
                    <div class="tip-item"><i class="fas fa-check-circle"></i>Use the progress tracker to log each step taken</div>
                    <div class="tip-item"><i class="fas fa-check-circle"></i>Report blockers immediately to your coordinator</div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    const reveals = document.querySelectorAll('.reveal');
    const obs = new IntersectionObserver(entries => {
        entries.forEach((e, i) => {
            if (e.isIntersecting) {
                setTimeout(() => e.target.classList.add('in'), i * 60);
                obs.unobserve(e.target);
            }
        });
    }, { threshold: 0.08 });
    reveals.forEach(el => obs.observe(el));
</script>
</body>
</html>