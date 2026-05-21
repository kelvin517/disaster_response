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
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --black: #080808;
            --surface: #111111;
            --card: #161616;
            --card2: #1C1C1C;
            --border: rgba(255,255,255,0.07);
            --border-hover: rgba(255,255,255,0.13);
            --red: #E8271A;
            --red-dim: rgba(232,39,26,0.12);
            --red-border: rgba(232,39,26,0.3);
            --amber: #D97706;
            --amber-dim: rgba(217,119,6,0.12);
            --amber-border: rgba(217,119,6,0.25);
            --green: #16A34A;
            --green-dim: rgba(22,163,74,0.1);
            --green-border: rgba(22,163,74,0.25);
            --blue: #2563EB;
            --blue-dim: rgba(37,99,235,0.1);
            --blue-border: rgba(37,99,235,0.25);
            --text: #F0EDE8;
            --muted: #6B6865;
            --muted2: #9A9693;
            --heading: 'Bebas Neue', sans-serif;
            --body: 'DM Sans', sans-serif;
            --mono: 'DM Mono', monospace;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body { font-family: var(--body); background: var(--black); color: var(--text); min-height: 100vh; }
        ::-webkit-scrollbar { width: 3px; }
        ::-webkit-scrollbar-track { background: var(--black); }
        ::-webkit-scrollbar-thumb { background: var(--red); border-radius: 2px; }

        /* ─── NAV ─── */
        .nav {
            position: sticky; top: 0; z-index: 100;
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 32px;
            background: rgba(8,8,8,0.92);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
        }
        .nav-brand {
            font-family: var(--heading);
            font-size: 1.5rem;
            letter-spacing: 0.06em;
            color: var(--red);
            text-decoration: none;
            display: flex; align-items: center; gap: 8px;
        }
        .nav-brand span { color: var(--text); }
        .nav-right { display: flex; align-items: center; gap: 6px; }
        .nav-user {
            font-size: 0.78rem;
            font-weight: 500;
            color: var(--muted2);
            padding: 6px 14px;
            border-right: 1px solid var(--border);
            margin-right: 4px;
        }
        .nav-link-pill {
            font-size: 0.75rem;
            font-weight: 500;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--muted);
            text-decoration: none;
            padding: 7px 14px;
            border-radius: 6px;
            border: 1px solid transparent;
            transition: all 0.18s;
            position: relative;
        }
        .nav-link-pill:hover { color: var(--text); border-color: var(--border); background: var(--card); }
        .nav-link-pill.danger:hover { color: var(--red); border-color: var(--red-border); background: var(--red-dim); }
        .notif-dot {
            position: absolute; top: 3px; right: 6px;
            width: 7px; height: 7px;
            background: var(--red);
            border-radius: 50%;
            border: 2px solid var(--black);
        }

        /* ─── LAYOUT ─── */
        .page { max-width: 1280px; margin: 0 auto; padding: 32px 32px 80px; }

        /* ─── TOASTS ─── */
        .toast-bar {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 20px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 24px;
            border: 1px solid;
        }
        .toast-success { background: var(--green-dim); border-color: var(--green-border); color: #4ADE80; }
        .toast-error { background: var(--red-dim); border-color: var(--red-border); color: #F87171; }

        /* ─── HERO HEADER ─── */
        .page-header {
            display: flex; align-items: flex-end; justify-content: space-between;
            margin-bottom: 32px;
            padding-bottom: 28px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap; gap: 16px;
        }
        .page-header-left .eyebrow {
            font-family: var(--mono);
            font-size: 0.68rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--red);
            margin-bottom: 6px;
        }
        .page-title {
            font-family: var(--heading);
            font-size: clamp(2.8rem, 5vw, 4.2rem);
            letter-spacing: 0.02em;
            line-height: 0.95;
            color: var(--text);
        }
        .status-pill {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 0.78rem; font-weight: 600;
            padding: 8px 18px;
            border-radius: 100px;
            border: 1px solid;
        }
        .status-pill.available { background: var(--green-dim); border-color: var(--green-border); color: #4ADE80; }
        .status-pill.busy { background: rgba(100,100,100,0.1); border-color: var(--border); color: var(--muted2); }
        .status-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }

        /* ─── STATS ROW ─── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 32px;
        }
        .stat-block {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px 22px;
            position: relative;
            overflow: hidden;
            transition: border-color 0.2s;
        }
        .stat-block:hover { border-color: var(--border-hover); }
        .stat-block::before {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 2px;
        }
        .stat-block.s-total::before { background: var(--blue); }
        .stat-block.s-pending::before { background: var(--amber); }
        .stat-block.s-progress::before { background: var(--blue); }
        .stat-block.s-done::before { background: var(--green); }
        .stat-label {
            font-family: var(--mono);
            font-size: 0.65rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 10px;
        }
        .stat-value {
            font-family: var(--heading);
            font-size: 3rem;
            letter-spacing: 0.02em;
            line-height: 1;
        }
        .stat-value.c-blue { color: #60A5FA; }
        .stat-value.c-amber { color: #FBBF24; }
        .stat-value.c-green { color: #4ADE80; }
        .stat-sub {
            font-size: 0.72rem;
            color: var(--muted);
            margin-top: 6px;
        }

        /* ─── SKILLS BAR ─── */
        .skills-bar {
            display: flex; align-items: center; gap: 20px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 20px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }
        .skills-bar-label {
            font-family: var(--mono);
            font-size: 0.65rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: var(--muted);
            white-space: nowrap;
        }
        .skills-text {
            font-size: 0.82rem;
            color: var(--muted2);
            flex: 1;
        }
        .skills-update {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--red);
            text-decoration: none;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .skills-update:hover { text-decoration: underline; }
        .skills-divider { width: 1px; height: 20px; background: var(--border); }

        /* ─── TASK COLUMNS ─── */
        .tasks-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .col-block {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }
        .col-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--card2);
        }
        .col-head-title {
            display: flex; align-items: center; gap: 10px;
            font-family: var(--mono);
            font-size: 0.68rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--muted2);
        }
        .col-head-title i { font-size: 0.9rem; }
        .col-count {
            font-family: var(--heading);
            font-size: 1.3rem;
            letter-spacing: 0.04em;
            line-height: 1;
        }

        /* ─── TASK CARD ─── */
        .task-card {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            transition: background 0.15s;
        }
        .task-card:last-child { border-bottom: none; }
        .task-card:hover { background: var(--card2); }

        .task-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
        .task-location {
            font-size: 0.9rem;
            font-weight: 600;
            display: flex; align-items: center; gap: 7px;
            color: var(--text);
        }
        .task-location i { color: var(--red); font-size: 0.8rem; }
        .task-meta {
            font-size: 0.76rem;
            color: var(--muted);
            margin-top: 3px;
        }
        .task-desc {
            font-size: 0.82rem;
            color: var(--muted2);
            line-height: 1.65;
            margin: 10px 0 14px;
        }
        .task-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        /* ─── SEVERITY BADGES ─── */
        .sev-badge {
            font-family: var(--mono);
            font-size: 0.6rem;
            font-weight: 500;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 100px;
            border: 1px solid;
            white-space: nowrap;
        }
        .sev-4, .sev-critical { background: var(--red-dim); border-color: var(--red-border); color: #F87171; }
        .sev-3, .sev-high { background: var(--amber-dim); border-color: var(--amber-border); color: #FBBF24; }
        .sev-2, .sev-medium { background: rgba(234,179,8,0.1); border-color: rgba(234,179,8,0.2); color: #FDE68A; }
        .sev-1, .sev-low { background: var(--green-dim); border-color: var(--green-border); color: #86EFAC; }

        /* ─── PROGRESS BAR ─── */
        .progress-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .progress-track {
            flex: 1;
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
        }
        .progress-fill { height: 100%; background: var(--blue); border-radius: 2px; transition: width 0.4s; }
        .progress-label {
            font-family: var(--mono);
            font-size: 0.65rem;
            color: var(--muted);
            white-space: nowrap;
        }

        /* ─── BUTTONS ─── */
        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            text-decoration: none;
            padding: 8px 18px;
            border-radius: 8px;
            border: 1px solid;
            cursor: pointer;
            transition: all 0.18s;
            white-space: nowrap;
            background: none;
        }
        .btn-accept { border-color: var(--green-border); color: #4ADE80; background: var(--green-dim); }
        .btn-accept:hover { background: rgba(22,163,74,0.18); border-color: rgba(22,163,74,0.4); }
        .btn-update { border-color: var(--blue-border); color: #60A5FA; background: var(--blue-dim); }
        .btn-update:hover { background: rgba(37,99,235,0.18); border-color: rgba(37,99,235,0.4); }
        .btn-complete { border-color: var(--green-border); color: #4ADE80; background: var(--green-dim); }
        .btn-complete:hover { background: rgba(22,163,74,0.18); }
        .btn-ghost { border-color: var(--border); color: var(--muted); background: transparent; }
        .btn-ghost:hover { border-color: var(--border-hover); color: var(--text); }
        .btn-solid-red { border-color: var(--red-border); color: #F87171; background: var(--red-dim); }
        .btn-solid-red:hover { background: rgba(232,39,26,0.2); }

        /* ─── BOTTOM GRID ─── */
        .bottom-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 20px;
        }

        /* ─── COMPLETED LIST ─── */
        .completed-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            gap: 16px;
        }
        .completed-item:last-child { border-bottom: none; }
        .completed-left { display: flex; align-items: center; gap: 12px; }
        .check-icon {
            width: 32px; height: 32px;
            background: var(--green-dim);
            border: 1px solid var(--green-border);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: #4ADE80;
            font-size: 0.8rem;
            flex-shrink: 0;
        }
        .completed-type { font-size: 0.88rem; font-weight: 600; }
        .completed-loc { font-size: 0.75rem; color: var(--muted); margin-top: 2px; }
        .completed-date {
            font-family: var(--mono);
            font-size: 0.65rem;
            color: var(--muted);
            white-space: nowrap;
        }

        /* ─── RIGHT SIDEBAR ─── */
        .sidebar-block {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .sidebar-head {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            font-family: var(--mono);
            font-size: 0.65rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--muted);
            background: var(--card2);
        }
        .sidebar-body { padding: 14px 18px; }
        .action-btn {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--muted2);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.18s;
            margin-bottom: 8px;
        }
        .action-btn:last-child { margin-bottom: 0; }
        .action-btn:hover { border-color: var(--border-hover); color: var(--text); background: var(--card2); }
        .action-btn i { width: 18px; text-align: center; color: var(--red); }

        .tip-item {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 9px 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.8rem;
            color: var(--muted2);
            line-height: 1.5;
        }
        .tip-item:last-child { border-bottom: none; padding-bottom: 0; }
        .tip-item i { color: #4ADE80; margin-top: 2px; flex-shrink: 0; font-size: 0.75rem; }

        /* ─── EMPTY STATE ─── */
        .empty {
            padding: 40px 20px;
            text-align: center;
            color: var(--muted);
        }
        .empty i { font-size: 2rem; margin-bottom: 12px; display: block; opacity: 0.4; }
        .empty p { font-size: 0.82rem; }

        /* ─── REVEAL ─── */
        .reveal { opacity: 0; transform: translateY(16px); transition: opacity 0.5s ease, transform 0.5s ease; }
        .reveal.in { opacity: 1; transform: translateY(0); }

        @media (max-width: 900px) {
            .page { padding: 20px 16px 60px; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .tasks-columns { grid-template-columns: 1fr; }
            .bottom-grid { grid-template-columns: 1fr; }
            .nav { padding: 12px 16px; }
        }
    </style>
</head>
<body>

<!-- ─── NAV ─── -->
<nav class="nav">
    <a href="my_tasks.php" class="nav-brand"><i class="fas fa-hands-helping"></i><span>Volunteer</span>HQ</a>
    <div class="nav-right">
        <div class="nav-user"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
        <a href="register.php" class="nav-link-pill">Profile</a>
        <a href="../messaging/inbox.php" class="nav-link-pill" style="position:relative;">
            Messages
            <?php if($unread_messages > 0): ?><span class="notif-dot"></span><?php endif; ?>
        </a>
        <a href="../mapping/map.php" class="nav-link-pill">Map</a>
        <a href="../auth/logout.php" class="nav-link-pill danger" onclick="return confirm('Logout?');">Logout</a>
    </div>
</nav>

<div class="page">

    <?php if($success): ?>
    <div class="toast-bar toast-success reveal"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if($error): ?>
    <div class="toast-bar toast-error reveal"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ─── PAGE HEADER ─── -->
    <div class="page-header reveal">
        <div class="page-header-left">
            <div class="eyebrow">// Volunteer Dashboard</div>
            <h1 class="page-title">MY TASKS</h1>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:10px;">
            <div class="status-pill <?= $volunteer['availability_status'] === 'available' ? 'available' : 'busy' ?>">
                <span class="status-dot"></span>
                <?= ucfirst($volunteer['availability_status']) ?>
            </div>
            <div style="font-family:var(--mono);font-size:0.62rem;color:var(--muted);letter-spacing:0.12em;"><?= strtoupper(date('D, M j Y')) ?></div>
        </div>
    </div>

    <!-- ─── STATS ─── -->
    <div class="stats-row reveal">
        <div class="stat-block s-total">
            <div class="stat-label">Total Tasks</div>
            <div class="stat-value c-blue"><?= $stats['total'] ?></div>
        </div>
        <div class="stat-block s-pending">
            <div class="stat-label">Pending</div>
            <div class="stat-value c-amber"><?= $stats['assigned'] ?></div>
        </div>
        <div class="stat-block s-progress">
            <div class="stat-label">In Progress</div>
            <div class="stat-value c-blue"><?= $stats['in_progress'] ?></div>
        </div>
        <div class="stat-block s-done">
            <div class="stat-label">Completed</div>
            <div class="stat-value c-green"><?= $stats['completed'] ?></div>
            <div class="stat-sub"><?= $completion_rate ?>% completion rate</div>
        </div>
    </div>

    <!-- ─── SKILLS BAR ─── -->
    <div class="skills-bar reveal">
        <div class="skills-bar-label">Skills</div>
        <div class="skills-divider"></div>
        <div class="skills-text">
            <?= !empty($volunteer['skills']) ? htmlspecialchars(substr($volunteer['skills'], 0, 60)) . (strlen($volunteer['skills']) > 60 ? '...' : '') : 'No skills listed yet' ?>
        </div>
        <a href="register.php" class="skills-update"><i class="fas fa-pen" style="font-size:0.6rem;"></i> Update Skills</a>
    </div>

    <!-- ─── TASK COLUMNS ─── -->
    <div class="tasks-columns">

        <!-- PENDING -->
        <div class="col-block reveal">
            <div class="col-head">
                <div class="col-head-title"><i class="fas fa-clock" style="color:var(--amber);"></i> Pending Tasks</div>
                <div class="col-count" style="color:var(--amber);"><?= $stats['assigned'] ?></div>
            </div>
            <?php if(count($pending_tasks) > 0): foreach($pending_tasks as $task): ?>
            <div class="task-card">
                <div class="task-top">
                    <div>
                        <div class="task-location"><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($task['location_name'] ?? 'Location TBD') ?></div>
                        <div class="task-meta"><?= ucfirst($task['incident_type']) ?> &nbsp;·&nbsp; Assigned by <?= htmlspecialchars($task['assigned_by_name']) ?></div>
                    </div>
                    <span class="sev-badge sev-<?= $task['severity'] ?>"><?= match((int)$task['severity']) {4=>'Critical',3=>'High',2=>'Medium',default=>'Low'} ?></span>
                </div>
                <p class="task-desc"><?= htmlspecialchars(substr($task['task_description'] ?? $task['incident_desc'], 0, 100)) ?>...</p>
                <div class="task-actions">
                    <form method="POST" style="display:contents;">
                        <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                        <input type="hidden" name="action" value="accept_task">
                        <button type="submit" class="btn btn-accept"><i class="fas fa-check"></i> Accept</button>
                    </form>
                    <a href="../incidents/view.php?id=<?= $task['incident_id'] ?>" class="btn btn-ghost"><i class="fas fa-arrow-right"></i> Details</a>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div class="empty"><i class="fas fa-inbox"></i><p>No pending tasks</p></div>
            <?php endif; ?>
        </div>

        <!-- IN PROGRESS -->
        <div class="col-block reveal">
            <div class="col-head">
                <div class="col-head-title"><i class="fas fa-bolt" style="color:#60A5FA;"></i> In Progress</div>
                <div class="col-count" style="color:#60A5FA;"><?= $stats['in_progress'] ?></div>
            </div>
            <?php if(count($in_progress_tasks) > 0): foreach($in_progress_tasks as $task): ?>
            <div class="task-card">
                <div class="task-top">
                    <div>
                        <div class="task-location"><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($task['location_name'] ?? 'Location TBD') ?></div>
                        <div class="task-meta"><?= ucfirst($task['incident_type']) ?> &nbsp;·&nbsp; Assigned by <?= htmlspecialchars($task['assigned_by_name']) ?></div>
                    </div>
                    <span class="sev-badge sev-<?= $task['severity'] ?>"><?= match((int)$task['severity']) {4=>'Critical',3=>'High',2=>'Medium',default=>'Low'} ?></span>
                </div>
                <div class="progress-row">
                    <div class="progress-track"><div class="progress-fill" style="width:<?= min(100, ($task['progress_count'] / 6) * 100) ?>%;"></div></div>
                    <div class="progress-label"><?= $task['progress_count'] ?>/6 steps</div>
                </div>
                <p class="task-desc"><?= htmlspecialchars(substr($task['task_description'] ?? $task['incident_desc'], 0, 90)) ?>...</p>
                <div class="task-actions">
                    <a href="progress.php?id=<?= $task['id'] ?>" class="btn btn-update"><i class="fas fa-pen"></i> Update</a>
                    <form method="POST" style="display:contents;">
                        <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="status" value="completed">
                        <button type="submit" class="btn btn-complete" onclick="return confirm('Mark as complete?')"><i class="fas fa-check-double"></i> Complete</button>
                    </form>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div class="empty"><i class="fas fa-play-circle"></i><p>No tasks in progress</p></div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ─── BOTTOM GRID ─── -->
    <div class="bottom-grid">

        <!-- COMPLETED -->
        <div class="col-block reveal">
            <div class="col-head">
                <div class="col-head-title"><i class="fas fa-check-circle" style="color:#4ADE80;"></i> Recently Completed</div>
                <div class="col-count" style="color:#4ADE80;"><?= $stats['completed'] ?></div>
            </div>
            <?php if(count($completed_tasks) > 0): foreach($completed_tasks as $task): ?>
            <div class="completed-item">
                <div class="completed-left">
                    <div class="check-icon"><i class="fas fa-check"></i></div>
                    <div>
                        <div class="completed-type"><?= ucfirst($task['incident_type']) ?> Response</div>
                        <div class="completed-loc"><i class="fas fa-map-marker-alt" style="font-size:0.65rem;color:var(--red);margin-right:4px;"></i><?= htmlspecialchars($task['location_name'] ?? 'Location') ?></div>
                    </div>
                </div>
                <div class="completed-date"><?= date('M j, Y', strtotime($task['completed_at'])) ?></div>
            </div>
            <?php endforeach; else: ?>
            <div class="empty"><i class="fas fa-trophy"></i><p>No completed tasks yet — keep going!</p></div>
            <?php endif; ?>
        </div>

        <!-- SIDEBAR -->
        <div>
            <div class="sidebar-block reveal">
                <div class="sidebar-head">Quick Actions</div>
                <div class="sidebar-body" style="padding:12px;">
                    <a href="../incidents/report.php" class="action-btn"><i class="fas fa-exclamation-triangle"></i> Report Incident</a>
                    <a href="register.php" class="action-btn"><i class="fas fa-user-edit"></i> Update Profile</a>
                    <a href="../messaging/compose.php" class="action-btn"><i class="fas fa-envelope"></i> Send Message</a>
                    <a href="../mapping/map.php" class="action-btn"><i class="fas fa-map"></i> View Live Map</a>
                </div>
            </div>
            <div class="sidebar-block reveal">
                <div class="sidebar-head">Field Tips</div>
                <div class="sidebar-body" style="padding:12px 18px;">
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