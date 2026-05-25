<?php
/**
 * Track My Reports — Victim Status Page
 * Disaster Response & Resource Coordination System
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

if (!isLoggedIn()) redirect('modules/auth/login.php');

$user_id       = $_SESSION['user_id'];
$update_success = null;
$update_error   = null;

/* ─── ADD UPDATE ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_update') {
    $incident_id     = (int)$_POST['incident_id'];
    $additional_info = trim($_POST['additional_info'] ?? '');

    if (empty($additional_info)) {
        $update_error = "Please enter some additional information before submitting.";
    } else {
        // Verify the incident belongs to this user
        $chk = $pdo->prepare("SELECT id FROM incidents WHERE id = ? AND reporter_id = ?");
        $chk->execute([$incident_id, $user_id]);
        if (!$chk->fetch()) {
            $update_error = "You are not authorised to update this report.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO incident_updates (incident_id, user_id, update_text, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$incident_id, $user_id, $additional_info]);
                $update_success = "Update added successfully. Responders have been notified.";
            } catch (PDOException $e) {
                error_log("Failed to add update: " . $e->getMessage());
                $update_error = "Failed to save your update. Please try again.";
            }
        }
    }
}

/* ─── CANCEL REPORT ─── */
if (isset($_GET['cancel']) && ctype_digit((string)$_GET['cancel'])) {
    $incident_id = (int)$_GET['cancel'];
    $stmt = $pdo->prepare("SELECT id FROM incidents WHERE id = ? AND reporter_id = ? AND status IN ('reported','acknowledged')");
    $stmt->execute([$incident_id, $user_id]);
    if ($stmt->fetch()) {
        $pdo->prepare("UPDATE incidents SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$incident_id]);
        $update_success = "Your report has been cancelled.";
    } else {
        $update_error = "Unable to cancel — this report may already be in progress or resolved.";
    }
}

/* ─── FETCH REPORTS ─── */
$stmt = $pdo->prepare("
    SELECT i.*,
           CASE i.status
               WHEN 'reported'     THEN 'Report Received'
               WHEN 'acknowledged' THEN 'Under Review'
               WHEN 'in-progress'  THEN 'Responders En Route'
               WHEN 'resolved'     THEN 'Resolved'
               WHEN 'cancelled'    THEN 'Cancelled'
               WHEN 'rejected'     THEN 'Rejected'
               ELSE i.status
           END AS status_display,
           CASE i.status
               WHEN 'reported'     THEN 25
               WHEN 'acknowledged' THEN 50
               WHEN 'in-progress'  THEN 75
               WHEN 'resolved'     THEN 100
               WHEN 'cancelled'    THEN 100
               WHEN 'rejected'     THEN 100
               ELSE 0
           END AS progress_percent
    FROM incidents i
    WHERE i.reporter_id = ?
    ORDER BY i.reported_at DESC
");
$stmt->execute([$user_id]);
$my_reports = $stmt->fetchAll();

/* ─── FETCH UPDATES ─── */
$incident_updates = [];
if (!empty($my_reports)) {
    $ids          = array_column($my_reports, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT iu.*, u.full_name AS user_name
        FROM   incident_updates iu
        JOIN   users u ON u.id = iu.user_id
        WHERE  iu.incident_id IN ($placeholders)
        ORDER  BY iu.created_at DESC
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $upd) {
        $incident_updates[$upd['incident_id']][] = $upd;
    }
}

/* ─── QUICK STATS ─── */
$total    = count($my_reports);
$active   = count(array_filter($my_reports, fn($r) => in_array($r['status'], ['reported','acknowledged','in-progress'])));
$resolved = count(array_filter($my_reports, fn($r) => $r['status'] === 'resolved'));
$pending  = count(array_filter($my_reports, fn($r) => $r['status'] === 'reported'));

/* ─── HELPERS ─── */
function getSev(int $sev): array {
    return [
        1 => ['label' => 'Low',      'color' => '#16A34A', 'dim' => 'rgba(22,163,74,0.1)',    'border' => 'rgba(22,163,74,0.25)'],
        2 => ['label' => 'Medium',   'color' => '#CA8A04', 'dim' => 'rgba(202,138,4,0.1)',    'border' => 'rgba(202,138,4,0.22)'],
        3 => ['label' => 'High',     'color' => '#D97706', 'dim' => 'rgba(217,119,6,0.1)',    'border' => 'rgba(217,119,6,0.25)'],
        4 => ['label' => 'Critical', 'color' => '#E8271A', 'dim' => 'rgba(232,39,26,0.1)',    'border' => 'rgba(232,39,26,0.28)'],
    ][$sev] ?? ['label' => 'Unknown', 'color' => '#6B6865', 'dim' => 'rgba(107,104,101,0.1)', 'border' => 'rgba(107,104,101,0.2)'];
}

function statusMeta(string $status): array {
    return [
        'reported'     => ['label' => 'Received',   'color' => '#60A5FA', 'dim' => 'rgba(96,165,250,0.1)',  'border' => 'rgba(96,165,250,0.25)'],
        'acknowledged' => ['label' => 'Reviewing',  'color' => '#FBBF24', 'dim' => 'rgba(251,191,36,0.1)',  'border' => 'rgba(251,191,36,0.25)'],
        'in-progress'  => ['label' => 'En Route',   'color' => '#818CF8', 'dim' => 'rgba(129,140,248,0.1)', 'border' => 'rgba(129,140,248,0.25)'],
        'resolved'     => ['label' => 'Resolved',   'color' => '#4ADE80', 'dim' => 'rgba(74,222,128,0.1)',  'border' => 'rgba(74,222,128,0.25)'],
        'cancelled'    => ['label' => 'Cancelled',  'color' => '#6B6865', 'dim' => 'rgba(107,104,101,0.08)','border' => 'rgba(107,104,101,0.2)'],
        'rejected'     => ['label' => 'Rejected',   'color' => '#F87171', 'dim' => 'rgba(248,113,113,0.1)', 'border' => 'rgba(248,113,113,0.25)'],
    ][$status] ?? ['label' => ucfirst($status), 'color' => '#6B6865', 'dim' => 'rgba(107,104,101,0.08)', 'border' => 'rgba(107,104,101,0.2)'];
}

function progressColor(string $status): string {
    return match($status) {
        'resolved'  => '#4ADE80',
        'cancelled' => '#6B6865',
        'rejected'  => '#F87171',
        default     => '#E8271A',
    };
}

function incidentIcon(string $type): string {
    return match($type) {
        'flood'             => '🌊',
        'fire'              => '🔥',
        'earthquake'        => '🏚️',
        'landslide'         => '⛰️',
        'drought'           => '☀️',
        'accident'          => '🚗',
        'building_collapse' => '🏗️',
        'disease_outbreak'  => '🦠',
        default             => '⚠️',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reports — DisasterResponse</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --black:  #080808;
            --surface:#111111;
            --card:   #161616;
            --card2:  #1C1C1C;
            --border: rgba(255,255,255,0.07);
            --border-hover: rgba(255,255,255,0.13);
            --red:    #E8271A;
            --red-dim:rgba(232,39,26,0.1);
            --red-border:rgba(232,39,26,0.28);
            --text:   #F0EDE8;
            --muted:  #6B6865;
            --muted2: #9A9693;
            --heading:'Bebas Neue', sans-serif;
            --body:   'DM Sans', sans-serif;
            --mono:   'DM Mono', monospace;
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
            padding: 13px 32px;
            background: rgba(8,8,8,0.93);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
        }
        .nav-brand {
            font-family: var(--heading); font-size: 1.5rem; letter-spacing: 0.06em;
            color: var(--red); text-decoration: none;
            display: flex; align-items: center; gap: 8px;
        }
        .nav-brand span { color: var(--text); }
        .nav-right { display: flex; align-items: center; gap: 6px; }
        .nav-pill {
            font-size: 0.75rem; font-weight: 500; letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--muted); text-decoration: none; padding: 7px 14px;
            border-radius: 6px; border: 1px solid transparent; transition: all 0.18s;
        }
        .nav-pill:hover { color: var(--text); border-color: var(--border); background: var(--card); }
        .nav-pill.primary { color: #F87171; border-color: var(--red-border); background: var(--red-dim); }
        .nav-pill.primary:hover { background: rgba(232,39,26,0.18); }

        /* ─── PAGE HEADER ─── */
        .page-header {
            background: var(--surface); border-bottom: 1px solid var(--border);
            padding: 36px 32px 32px; position: relative; overflow: hidden;
        }
        .page-header::after {
            content: ''; position: absolute; right: 0; top: 0; bottom: 0; width: 45%;
            background: radial-gradient(ellipse at right center, rgba(232,39,26,0.07) 0%, transparent 65%);
            pointer-events: none;
        }
        .page-header-inner { max-width: 1100px; margin: 0 auto; position: relative; z-index: 1; display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 20px; }
        .eyebrow { font-family: var(--mono); font-size: 0.65rem; letter-spacing: 0.2em; text-transform: uppercase; color: var(--red); margin-bottom: 8px; }
        .page-title { font-family: var(--heading); font-size: clamp(2.6rem, 5vw, 4rem); letter-spacing: 0.02em; line-height: 0.95; }
        .page-sub { font-size: 0.85rem; color: var(--muted2); margin-top: 8px; }

        /* ─── LAYOUT ─── */
        .page { max-width: 1100px; margin: 0 auto; padding: 28px 32px 80px; }

        /* ─── TOASTS ─── */
        .toast-bar {
            display: flex; align-items: center; gap: 12px;
            padding: 13px 18px; border-radius: 10px;
            font-size: 0.84rem; font-weight: 500;
            margin-bottom: 20px; border: 1px solid;
        }
        .toast-success { background: rgba(22,163,74,0.1); border-color: rgba(22,163,74,0.25); color: #4ADE80; }
        .toast-error   { background: var(--red-dim); border-color: var(--red-border); color: #F87171; }
        .toast-bar button { margin-left: auto; background: none; border: none; cursor: pointer; color: inherit; opacity: 0.6; font-size: 1rem; padding: 0; line-height: 1; }
        .toast-bar button:hover { opacity: 1; }

        /* ─── STATS ROW ─── */
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 28px; }
        .stat-block {
            background: var(--card); border: 1px solid var(--border); border-radius: 12px;
            padding: 18px 20px; position: relative; overflow: hidden; transition: border-color 0.2s;
        }
        .stat-block:hover { border-color: var(--border-hover); }
        .stat-block::before { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px; }
        .stat-block.s-total::before  { background: #60A5FA; }
        .stat-block.s-active::before { background: var(--red); }
        .stat-block.s-done::before   { background: #4ADE80; }
        .stat-block.s-pending::before{ background: #FBBF24; }
        .stat-label { font-family: var(--mono); font-size: 0.62rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--muted); margin-bottom: 8px; }
        .stat-value { font-family: var(--heading); font-size: 2.8rem; letter-spacing: 0.02em; line-height: 1; }
        .stat-value.c-blue  { color: #60A5FA; }
        .stat-value.c-red   { color: #F87171; }
        .stat-value.c-green { color: #4ADE80; }
        .stat-value.c-amber { color: #FBBF24; }

        /* ─── REPORT CARD ─── */
        .report-card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 14px; overflow: hidden; margin-bottom: 16px;
            transition: border-color 0.2s;
        }
        .report-card:hover { border-color: var(--border-hover); }

        .rc-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 20px; background: var(--card2);
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap; gap: 10px;
        }
        .rc-head-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        .report-id {
            font-family: var(--mono); font-size: 0.72rem; letter-spacing: 0.1em;
            color: var(--red); background: var(--red-dim); border: 1px solid var(--red-border);
            padding: 4px 12px; border-radius: 100px;
        }
        .sev-pip {
            font-family: var(--mono); font-size: 0.6rem; letter-spacing: 0.1em; text-transform: uppercase;
            padding: 4px 10px; border-radius: 100px; border: 1px solid;
        }
        .status-pip {
            font-family: var(--mono); font-size: 0.6rem; letter-spacing: 0.1em; text-transform: uppercase;
            padding: 4px 10px; border-radius: 100px; border: 1px solid;
        }
        .rc-head-right { display: flex; gap: 8px; }
        .rc-btn {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 0.72rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase;
            padding: 7px 14px; border-radius: 7px; border: 1px solid;
            text-decoration: none; cursor: pointer; background: none; font-family: var(--body);
            transition: all 0.18s; white-space: nowrap;
        }
        .rc-btn-ghost { border-color: var(--border); color: var(--muted2); }
        .rc-btn-ghost:hover { border-color: var(--border-hover); color: var(--text); }
        .rc-btn-red { border-color: var(--red-border); color: #F87171; background: var(--red-dim); }
        .rc-btn-red:hover { background: rgba(232,39,26,0.18); }

        /* ─── RC BODY ─── */
        .rc-body { padding: 22px 20px; }
        .rc-icon { font-size: 2.2rem; margin-bottom: 12px; }
        .rc-type { font-size: 1.05rem; font-weight: 700; margin-bottom: 14px; }

        .rc-meta-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-bottom: 18px;
        }
        .meta-item {}
        .meta-label { font-family: var(--mono); font-size: 0.6rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--muted); margin-bottom: 4px; }
        .meta-val { font-size: 0.84rem; color: var(--muted2); }
        .meta-desc { grid-column: 1 / -1; }

        /* ─── PROGRESS ─── */
        .progress-section { margin-bottom: 20px; }
        .progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .progress-label { font-family: var(--mono); font-size: 0.6rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--muted); }
        .progress-pct { font-family: var(--mono); font-size: 0.65rem; color: var(--muted2); }
        .progress-track { height: 5px; background: rgba(255,255,255,0.06); border-radius: 3px; overflow: hidden; margin-bottom: 10px; }
        .progress-fill { height: 100%; border-radius: 3px; transition: width 0.6s cubic-bezier(0.4,0,0.2,1); }
        .progress-steps { display: flex; justify-content: space-between; }
        .step-label { font-family: var(--mono); font-size: 0.58rem; color: var(--muted); letter-spacing: 0.06em; }

        /* ─── UPDATES SECTION ─── */
        .updates-section { border-top: 1px solid var(--border); padding-top: 18px; }
        .updates-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .updates-title { font-family: var(--mono); font-size: 0.62rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--muted); display: flex; align-items: center; gap: 8px; }
        .toggle-update-btn {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 0.72rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--red); background: var(--red-dim); border: 1px solid var(--red-border);
            padding: 6px 12px; border-radius: 7px; cursor: pointer; font-family: var(--body);
            transition: background 0.18s;
        }
        .toggle-update-btn:hover { background: rgba(232,39,26,0.18); }

        .update-form { display: none; margin-bottom: 14px; }
        .update-form.open { display: block; }
        .update-form-inner { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 14px; }
        .update-textarea {
            width: 100%; background: var(--card); border: 1px solid var(--border);
            border-radius: 8px; padding: 10px 12px; font-family: var(--body);
            font-size: 0.84rem; color: var(--text); resize: vertical; min-height: 72px;
            outline: none; transition: border-color 0.18s; margin-bottom: 10px;
        }
        .update-textarea::placeholder { color: var(--muted); }
        .update-textarea:focus { border-color: var(--red-border); }

        .update-item {
            display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border);
        }
        .update-item:last-child { border-bottom: none; padding-bottom: 0; }
        .update-avatar {
            width: 30px; height: 30px; flex-shrink: 0;
            background: var(--card2); border: 1px solid var(--border);
            border-radius: 8px; display: flex; align-items: center; justify-content: center;
            font-family: var(--heading); font-size: 1rem; color: var(--muted2);
        }
        .update-body { flex: 1; }
        .update-meta { display: flex; align-items: center; gap: 8px; margin-bottom: 5px; }
        .update-name { font-size: 0.8rem; font-weight: 600; }
        .update-time { font-family: var(--mono); font-size: 0.62rem; color: var(--muted); }
        .update-text { font-size: 0.82rem; color: var(--muted2); line-height: 1.6; }

        .no-updates { padding: 16px 0; text-align: center; font-family: var(--mono); font-size: 0.68rem; letter-spacing: 0.1em; text-transform: uppercase; color: var(--muted); }

        /* ─── EMPTY STATE ─── */
        .empty-state { text-align: center; padding: 70px 30px; background: var(--card); border: 1px solid var(--border); border-radius: 14px; }
        .empty-state i { font-size: 2.5rem; color: var(--muted); opacity: 0.3; display: block; margin-bottom: 16px; }
        .empty-state h3 { font-family: var(--heading); font-size: 2rem; letter-spacing: 0.04em; margin-bottom: 8px; }
        .empty-state p { font-size: 0.85rem; color: var(--muted2); margin-bottom: 24px; }

        /* ─── HOTLINE BAR ─── */
        .hotline-bar {
            display: flex; align-items: center; justify-content: center; gap: 20px;
            padding: 14px 20px; margin-top: 32px;
            background: var(--card); border: 1px solid var(--border); border-radius: 10px;
            font-size: 0.82rem; color: var(--muted2); flex-wrap: wrap;
        }
        .hotline-bar strong { color: var(--text); }
        .hotline-bar .sep { width: 1px; height: 14px; background: var(--border); }

        /* ─── REVEAL ─── */
        .reveal { opacity: 0; transform: translateY(14px); transition: opacity 0.5s ease, transform 0.5s ease; }
        .reveal.in { opacity: 1; transform: translateY(0); }

        @media (max-width: 860px) {
            .nav { padding: 12px 16px; }
            .page-header { padding: 28px 16px 24px; }
            .page { padding: 20px 16px 60px; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .rc-meta-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ─── NAV ─── -->
<nav class="nav">
    <a href="/disaster_response/index.php" class="nav-brand"><i class="fas fa-hands-helping"></i><span>Disaster</span>Response</a>
    <div class="nav-right">
        <a href="/disaster_response/modules/incidents/report.php" class="nav-pill">Dashboard</a>
        <a href="report.php" class="nav-pill primary"><i class="fas fa-plus" style="font-size:0.65rem;"></i> New Report</a>
        <a href="/disaster_response/modules/auth/logout.php" class="nav-pill">Logout</a>
    </div>
</nav>

<!-- ─── PAGE HEADER ─── -->
<div class="page-header">
    <div class="page-header-inner">
        <div>
            <div class="eyebrow">// <?= htmlspecialchars($_SESSION['full_name']) ?></div>
            <h1 class="page-title">MY REPORTS</h1>
            <p class="page-sub">Track the status of every emergency you've reported</p>
        </div>
        <div style="font-family:var(--mono);font-size:0.62rem;color:var(--muted);letter-spacing:0.12em;"><?= strtoupper(date('D, M j Y')) ?></div>
    </div>
</div>

<div class="page">

    <!-- ─── TOASTS ─── -->
    <?php if ($update_success): ?>
    <div class="toast-bar toast-success reveal" id="toastSuccess">
        <i class="fas fa-check-circle"></i><?= htmlspecialchars($update_success) ?>
        <button onclick="this.parentElement.remove()" title="Dismiss">&times;</button>
    </div>
    <?php endif; ?>
    <?php if ($update_error): ?>
    <div class="toast-bar toast-error reveal" id="toastError">
        <i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($update_error) ?>
        <button onclick="this.parentElement.remove()" title="Dismiss">&times;</button>
    </div>
    <?php endif; ?>

    <?php if (empty($my_reports)): ?>

    <!-- ─── EMPTY STATE ─── -->
    <div class="empty-state reveal">
        <i class="fas fa-inbox"></i>
        <h3>NO REPORTS YET</h3>
        <p>You haven't submitted any emergency reports. If you're witnessing an emergency, report it now.</p>
        <a href="report.php" class="rc-btn rc-btn-red" style="display:inline-flex;">
            <i class="fas fa-exclamation-triangle"></i> Report an Emergency
        </a>
    </div>

    <?php else: ?>

    <!-- ─── STATS ─── -->
    <div class="stats-row reveal">
        <div class="stat-block s-total">
            <div class="stat-label">Total Reports</div>
            <div class="stat-value c-blue"><?= $total ?></div>
        </div>
        <div class="stat-block s-active">
            <div class="stat-label">Active</div>
            <div class="stat-value c-red"><?= $active ?></div>
        </div>
        <div class="stat-block s-done">
            <div class="stat-label">Resolved</div>
            <div class="stat-value c-green"><?= $resolved ?></div>
        </div>
        <div class="stat-block s-pending">
            <div class="stat-label">Awaiting Response</div>
            <div class="stat-value c-amber"><?= $pending ?></div>
        </div>
    </div>

    <!-- ─── REPORT CARDS ─── -->
    <?php foreach ($my_reports as $report):
        $sev        = getSev((int)$report['severity']);
        $stMeta     = statusMeta($report['status']);
        $canCancel  = in_array($report['status'], ['reported', 'acknowledged']);
        $hasUpdates = !empty($incident_updates[$report['id']]);
        $pct        = (int)$report['progress_percent'];
        $pColor     = progressColor($report['status']);
        $ico        = incidentIcon($report['incident_type']);
        $formId     = 'updateForm-' . $report['id'];
    ?>
    <div class="report-card reveal">

        <!-- HEAD -->
        <div class="rc-head">
            <div class="rc-head-left">
                <span class="report-id">INC-<?= str_pad($report['id'], 5, '0', STR_PAD_LEFT) ?></span>
                <span class="sev-pip" style="background:<?= $sev['dim'] ?>;color:<?= $sev['color'] ?>;border-color:<?= $sev['border'] ?>;"><?= $sev['label'] ?></span>
                <span class="status-pip" style="background:<?= $stMeta['dim'] ?>;color:<?= $stMeta['color'] ?>;border-color:<?= $stMeta['border'] ?>;"><?= $stMeta['label'] ?></span>
            </div>
            <div class="rc-head-right">
                <?php if ($canCancel): ?>
                <a href="?cancel=<?= $report['id'] ?>" class="rc-btn rc-btn-red"
                   onclick="return confirm('Cancel this report? This cannot be undone.')">
                    <i class="fas fa-xmark"></i> Cancel
                </a>
                <?php endif; ?>
                <a href="view.php?id=<?= $report['id'] ?>" class="rc-btn rc-btn-ghost">
                    <i class="fas fa-arrow-up-right"></i> View
                </a>
            </div>
        </div>

        <!-- BODY -->
        <div class="rc-body">
            <div class="rc-icon"><?= $ico ?></div>
            <div class="rc-type"><?= ucfirst(str_replace('_', ' ', $report['incident_type'])) ?></div>

            <div class="rc-meta-grid">
                <div class="meta-item">
                    <div class="meta-label">Location</div>
                    <div class="meta-val"><?= htmlspecialchars($report['location_name'] ?? 'GPS coordinates captured') ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Reported</div>
                    <div class="meta-val"><?= date('M j, Y · g:i A', strtotime($report['reported_at'])) ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Last Updated</div>
                    <div class="meta-val"><?= date('M j, Y · g:i A', strtotime($report['updated_at'] ?? $report['reported_at'])) ?></div>
                </div>
                <div class="meta-item meta-desc">
                    <div class="meta-label">Description</div>
                    <div class="meta-val" style="line-height:1.65;"><?= nl2br(htmlspecialchars(substr($report['description'], 0, 240))) ?><?= strlen($report['description']) > 240 ? '…' : '' ?></div>
                </div>
            </div>

            <!-- PROGRESS -->
            <div class="progress-section">
                <div class="progress-header">
                    <div class="progress-label">Response Progress</div>
                    <div class="progress-pct"><?= $pct ?>%</div>
                </div>
                <div class="progress-track">
                    <div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $pColor ?>;"></div>
                </div>
                <div class="progress-steps">
                    <?php foreach (['Received', 'Reviewing', 'En Route', 'Resolved'] as $step): ?>
                    <div class="step-label"><?= $step ?></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- UPDATES -->
            <div class="updates-section">
                <div class="updates-header">
                    <div class="updates-title">
                        <i class="fas fa-comments" style="color:var(--red);"></i>
                        Updates
                        <?php if ($hasUpdates): ?>
                        <span style="font-family:var(--heading);font-size:1rem;color:var(--red);"><?= count($incident_updates[$report['id']]) ?></span>
                        <?php endif; ?>
                    </div>
                    <button class="toggle-update-btn" onclick="toggleForm('<?= $formId ?>', this)">
                        <i class="fas fa-plus" id="icon-<?= $formId ?>"></i> Add Update
                    </button>
                </div>

                <!-- FORM -->
                <div class="update-form" id="<?= $formId ?>">
                    <div class="update-form-inner">
                        <form method="POST">
                            <input type="hidden" name="action" value="add_update">
                            <input type="hidden" name="incident_id" value="<?= $report['id'] ?>">
                            <textarea name="additional_info" class="update-textarea"
                                      placeholder="Provide additional information — e.g. situation worsened, people still trapped, updated count…" required></textarea>
                            <button type="submit" class="rc-btn rc-btn-red" style="font-size:0.75rem;">
                                <i class="fas fa-paper-plane"></i> Submit Update
                            </button>
                        </form>
                    </div>
                </div>

                <!-- EXISTING UPDATES -->
                <?php if ($hasUpdates): ?>
                <div class="updates-list">
                    <?php foreach ($incident_updates[$report['id']] as $upd): ?>
                    <div class="update-item">
                        <div class="update-avatar"><?= strtoupper(substr($upd['user_name'], 0, 1)) ?></div>
                        <div class="update-body">
                            <div class="update-meta">
                                <span class="update-name"><?= htmlspecialchars($upd['user_name']) ?></span>
                                <span class="update-time"><?= date('M j, g:i A', strtotime($upd['created_at'])) ?></span>
                            </div>
                            <div class="update-text"><?= nl2br(htmlspecialchars($upd['update_text'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="no-updates">No updates yet — be the first to add one</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- ─── HOTLINE BAR ─── -->
    <div class="hotline-bar reveal">
        <i class="fas fa-phone-alt" style="color:var(--red);"></i>
        Need help? Call <strong>999</strong>
        <span class="sep"></span>
        Red Cross: <strong>+254 700 123 456</strong>
        <span class="sep"></span>
        Emergency: <strong>112</strong>
    </div>

    <?php endif; ?>
</div>

<script>
    // ─── REVEAL ───
    const reveals = document.querySelectorAll('.reveal');
    const obs = new IntersectionObserver(entries => {
        entries.forEach((e, i) => {
            if (e.isIntersecting) {
                setTimeout(() => e.target.classList.add('in'), i * 65);
                obs.unobserve(e.target);
            }
        });
    }, { threshold: 0.06 });
    reveals.forEach(el => obs.observe(el));

    // ─── AUTO-DISMISS TOASTS ───
    setTimeout(() => {
        ['toastSuccess','toastError'].forEach(id => {
            const el = document.getElementById(id);
            if (el) { el.style.opacity = '0'; el.style.transform = 'translateY(-8px)'; el.style.transition = 'all 0.4s'; setTimeout(() => el.remove(), 400); }
        });
    }, 5000);

    // ─── UPDATE FORM TOGGLE ───
    function toggleForm(id, btn) {
        const form = document.getElementById(id);
        const icon = document.getElementById('icon-' + id);
        const isOpen = form.classList.toggle('open');
        icon.className = isOpen ? 'fas fa-minus' : 'fas fa-plus';
        btn.style.background = isOpen ? 'rgba(232,39,26,0.22)' : '';
    }
</script>
</body>
</html>