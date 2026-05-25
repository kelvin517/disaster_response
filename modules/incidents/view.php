<?php
/**
 * Single Incident Detail View
 * Disaster Response & Resource Coordination System
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

$incident_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($incident_id <= 0) redirect('all.php');

function fetchIncidentData($pdo, $id) {
    $stmt = $pdo->prepare("
        SELECT i.*,
               u.full_name AS reporter_name, u.phone AS reporter_phone, u.email AS reporter_email,
               r.full_name AS responder_name, r.phone AS responder_phone
        FROM   incidents i
        LEFT JOIN users u ON u.id = i.reporter_id
        LEFT JOIN users r ON r.id = i.assigned_to
        WHERE  i.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

$incident = fetchIncidentData($pdo, $incident_id);
if (!$incident) redirect('all.php');

$is_reporter = isLoggedIn() && isset($_SESSION['user_id']) && $_SESSION['user_id'] == ($incident['reporter_id'] ?? 0);
$is_responder = hasRole(['responder', 'admin']);
if (!$is_reporter && !$is_responder) redirect('index.php');

$status_update_success = null;
$status_update_error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_update' && ($is_reporter || $is_responder)) {
        $text = trim($_POST['additional_info'] ?? '');
        if ($text !== '') {
            try {
                $pdo->prepare("INSERT INTO incident_updates (incident_id, user_id, update_text, created_at) VALUES (?,?,?,NOW())")
                    ->execute([$incident_id, $_SESSION['user_id'], $text]);
                $status_update_success = "Update posted successfully.";
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $status_update_error = "Failed to post update.";
            }
        }
    }

    if ($_POST['action'] === 'update_status' && $is_responder) {
        $new = $_POST['status'] ?? '';
        $allowed = ['reported','acknowledged','in-progress','resolved','cancelled'];
        if (in_array($new, $allowed)) {
            if ($pdo->prepare("UPDATE incidents SET status=?, updated_at=NOW() WHERE id=?")->execute([$new, $incident_id])) {
                $status_update_success = "Status updated to: " . ucfirst(str_replace('-',' ',$new));
                $incident = fetchIncidentData($pdo, $incident_id);
            } else { $status_update_error = "Failed to update status."; }
        }
    }

    if ($_POST['action'] === 'assign_responder' && $is_responder) {
        $rid = (int)($_POST['responder_id'] ?? 0);
        if ($rid > 0) {
            if ($pdo->prepare("UPDATE incidents SET assigned_to=?, updated_at=NOW() WHERE id=?")->execute([$rid, $incident_id])) {
                $status_update_success = "Responder assigned successfully.";
                $incident = fetchIncidentData($pdo, $incident_id);
            } else { $status_update_error = "Failed to assign responder."; }
        }
    }
}

/* updates */
$stmt = $pdo->prepare("SELECT iu.*, u.full_name AS user_name, u.role AS user_role FROM incident_updates iu JOIN users u ON u.id = iu.user_id WHERE iu.incident_id = ? ORDER BY iu.created_at ASC");
$stmt->execute([$incident_id]);
$updates = $stmt->fetchAll();

/* responders list */
$responders = [];
if ($is_responder) {
    $stmt = $pdo->prepare("SELECT id, full_name, phone FROM users WHERE role='responder' ORDER BY full_name");
    $stmt->execute();
    $responders = $stmt->fetchAll();
}

/* config */
$sev_config = [
    1 => ['label'=>'Low',      'color'=>'#16A34A','dim'=>'rgba(22,163,74,0.1)',   'border'=>'rgba(22,163,74,0.25)'],
    2 => ['label'=>'Medium',   'color'=>'#CA8A04','dim'=>'rgba(202,138,4,0.1)',   'border'=>'rgba(202,138,4,0.22)'],
    3 => ['label'=>'High',     'color'=>'#D97706','dim'=>'rgba(217,119,6,0.1)',   'border'=>'rgba(217,119,6,0.25)'],
    4 => ['label'=>'Critical', 'color'=>'#E8271A','dim'=>'rgba(232,39,26,0.1)',   'border'=>'rgba(232,39,26,0.28)'],
];
$status_config = [
    'reported'     => ['label'=>'Received',  'color'=>'#60A5FA','dim'=>'rgba(96,165,250,0.1)',  'border'=>'rgba(96,165,250,0.25)'],
    'acknowledged' => ['label'=>'Reviewing', 'color'=>'#FBBF24','dim'=>'rgba(251,191,36,0.1)',  'border'=>'rgba(251,191,36,0.25)'],
    'in-progress'  => ['label'=>'En Route',  'color'=>'#818CF8','dim'=>'rgba(129,140,248,0.1)', 'border'=>'rgba(129,140,248,0.25)'],
    'resolved'     => ['label'=>'Resolved',  'color'=>'#4ADE80','dim'=>'rgba(74,222,128,0.1)',  'border'=>'rgba(74,222,128,0.25)'],
    'cancelled'    => ['label'=>'Cancelled', 'color'=>'#6B6865','dim'=>'rgba(107,104,101,0.08)','border'=>'rgba(107,104,101,0.2)'],
    'rejected'     => ['label'=>'Rejected',  'color'=>'#F87171','dim'=>'rgba(248,113,113,0.1)', 'border'=>'rgba(248,113,113,0.25)'],
];
$type_icons = ['flood'=>'🌊','fire'=>'🔥','earthquake'=>'🏚️','landslide'=>'⛰️','drought'=>'☀️','accident'=>'🚗','building_collapse'=>'🏗️','disease_outbreak'=>'🦠','other'=>'⚠️'];

$sev    = $sev_config[(int)($incident['severity'] ?? 1)] ?? $sev_config[1];
$stMeta = $status_config[$incident['status'] ?? 'reported'] ?? $status_config['reported'];
$typeIco = $type_icons[$incident['incident_type'] ?? 'other'] ?? '⚠️';

/* timeline steps */
$timeline_steps = [
    ['status'=>'reported',     'icon'=>'fa-file-alt',      'label'=>'Report Submitted',     'desc'=>'Initial emergency report received from '.(htmlspecialchars($incident['reporter_name'] ?? 'reporter'))],
    ['status'=>'acknowledged', 'icon'=>'fa-eye',           'label'=>'Under Review',          'desc'=>'Report is being assessed by the response coordination team'],
    ['status'=>'in-progress',  'icon'=>'fa-truck-fast',    'label'=>'Responders Dispatched', 'desc'=>'Emergency responders are en route to the location'],
    ['status'=>'resolved',     'icon'=>'fa-circle-check',  'label'=>'Resolved',              'desc'=>'Incident has been addressed and marked as resolved'],
];
$status_order = ['reported'=>0,'acknowledged'=>1,'in-progress'=>2,'resolved'=>3,'cancelled'=>3,'rejected'=>3];
$current_order = $status_order[$incident['status'] ?? 'reported'] ?? 0;

function safe($v, $d='Not provided') { return ($v===null||$v==='') ? $d : htmlspecialchars((string)$v); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>INC-<?= str_pad($incident_id,5,'0',STR_PAD_LEFT) ?> — DisasterResponse</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root {
            --black:#080808; --surface:#111111; --card:#161616; --card2:#1C1C1C;
            --border:rgba(255,255,255,0.07); --border-hover:rgba(255,255,255,0.13);
            --red:#E8271A; --red-dim:rgba(232,39,26,0.1); --red-border:rgba(232,39,26,0.28);
            --text:#F0EDE8; --muted:#6B6865; --muted2:#9A9693;
            --heading:'Bebas Neue',sans-serif; --body:'DM Sans',sans-serif; --mono:'DM Mono',monospace;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        html{scroll-behavior:smooth;}
        body{font-family:var(--body);background:var(--black);color:var(--text);min-height:100vh;}
        ::-webkit-scrollbar{width:3px;} ::-webkit-scrollbar-track{background:var(--black);} ::-webkit-scrollbar-thumb{background:var(--red);border-radius:2px;}

        /* ─── NAV ─── */
        .nav{position:sticky;top:0;z-index:100;display:flex;align-items:center;justify-content:space-between;padding:13px 32px;background:rgba(8,8,8,.93);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);}
        .nav-brand{font-family:var(--heading);font-size:1.5rem;letter-spacing:.06em;color:var(--red);text-decoration:none;display:flex;align-items:center;gap:8px;}
        .nav-brand span{color:var(--text);}
        .nav-right{display:flex;align-items:center;gap:6px;}
        .nav-pill{font-size:.75rem;font-weight:500;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);text-decoration:none;padding:7px 14px;border-radius:6px;border:1px solid transparent;transition:all .18s;}
        .nav-pill:hover{color:var(--text);border-color:var(--border);background:var(--card);}
        .nav-pill.red{color:#F87171;border-color:var(--red-border);background:var(--red-dim);}
        .nav-pill.red:hover{background:rgba(232,39,26,.18);}

        /* ─── PAGE HEADER ─── */
        .page-header{background:var(--surface);border-bottom:1px solid var(--border);padding:32px 32px 28px;position:relative;overflow:hidden;}
        .page-header::after{content:'';position:absolute;right:0;top:0;bottom:0;width:45%;background:radial-gradient(ellipse at right center,rgba(232,39,26,.07) 0%,transparent 65%);pointer-events:none;}
        .ph-inner{max-width:1160px;margin:0 auto;position:relative;z-index:1;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:16px;}
        .eyebrow{font-family:var(--mono);font-size:.65rem;letter-spacing:.2em;text-transform:uppercase;color:var(--red);margin-bottom:6px;}
        .page-title{font-family:var(--heading);font-size:clamp(2.4rem,5vw,3.6rem);letter-spacing:.02em;line-height:.95;}
        .page-sub{font-size:.84rem;color:var(--muted2);margin-top:7px;}

        /* ─── TOASTS ─── */
        .toast-bar{display:flex;align-items:center;gap:12px;padding:13px 18px;border-radius:10px;font-size:.84rem;font-weight:500;margin-bottom:16px;border:1px solid;}
        .toast-success{background:rgba(22,163,74,.1);border-color:rgba(22,163,74,.25);color:#4ADE80;}
        .toast-error{background:var(--red-dim);border-color:var(--red-border);color:#F87171;}
        .toast-bar button{margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;opacity:.6;font-size:1rem;line-height:1;}
        .toast-bar button:hover{opacity:1;}

        /* ─── LAYOUT ─── */
        .page{max-width:1160px;margin:0 auto;padding:28px 32px 80px;}
        .grid{display:grid;grid-template-columns:1fr 360px;gap:18px;align-items:start;}

        /* ─── BLOCK ─── */
        .block{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:16px;}
        .block-head{display:flex;align-items:center;gap:10px;padding:14px 18px;background:var(--card2);border-bottom:1px solid var(--border);font-family:var(--mono);font-size:.63rem;letter-spacing:.18em;text-transform:uppercase;color:var(--muted2);}
        .block-head i{color:var(--red);font-size:.85rem;}
        .block-body{padding:20px 18px;}

        /* ─── STATUS BADGE ─── */
        .status-pill{display:inline-flex;align-items:center;gap:7px;font-family:var(--mono);font-size:.62rem;letter-spacing:.12em;text-transform:uppercase;padding:6px 14px;border-radius:100px;border:1px solid;}

        /* ─── ACTIONS BLOCK ─── */
        .actions-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
        .field-select{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:9px 12px;font-family:var(--body);font-size:.82rem;color:var(--text);outline:none;-webkit-appearance:none;cursor:pointer;transition:border-color .18s;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236B6865' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:30px;}
        .field-select:focus{border-color:var(--red-border);}
        .rc-btn{display:inline-flex;align-items:center;gap:7px;font-size:.72rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;padding:9px 16px;border-radius:8px;border:1px solid;text-decoration:none;cursor:pointer;background:none;font-family:var(--body);transition:all .18s;white-space:nowrap;}
        .rc-btn-red{border-color:var(--red-border);color:#F87171;background:var(--red-dim);}
        .rc-btn-red:hover{background:rgba(232,39,26,.18);}
        .rc-btn-ghost{border-color:var(--border);color:var(--muted2);}
        .rc-btn-ghost:hover{border-color:var(--border-hover);color:var(--text);}

        /* ─── DETAIL GRID ─── */
        .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
        .detail-full{grid-column:1/-1;}
        .detail-label{font-family:var(--mono);font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;color:var(--muted);margin-bottom:5px;}
        .detail-val{font-size:.86rem;color:var(--muted2);line-height:1.65;}
        .detail-val strong{color:var(--text);}
        .detail-val a{color:var(--red);text-decoration:none;}
        .detail-val a:hover{text-decoration:underline;}
        code-block{font-family:var(--mono);font-size:.72rem;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:3px 8px;color:var(--muted2);}

        /* ─── SEV BADGE ─── */
        .sev-pip{font-family:var(--mono);font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;padding:4px 10px;border-radius:100px;border:1px solid;}

        /* ─── MAP ─── */
        #incidentMap{height:220px;border-radius:10px;border:1px solid var(--border);}
        .leaflet-container{background:#0d1117!important;font-family:var(--body);}
        .leaflet-tile{filter:brightness(.75) saturate(.6) hue-rotate(180deg);}
        .leaflet-control-zoom a{background:var(--card)!important;color:var(--muted2)!important;border-color:var(--border)!important;}
        .leaflet-popup-content-wrapper{background:rgba(8,8,8,.96)!important;color:var(--text)!important;border:1px solid var(--border)!important;border-radius:10px!important;font-family:var(--body);}
        .leaflet-popup-tip{background:rgba(8,8,8,.96)!important;}

        /* ─── PHOTO ─── */
        .incident-photo{width:100%;height:190px;object-fit:cover;border-radius:10px;border:1px solid var(--border);cursor:pointer;transition:filter .2s;}
        .incident-photo:hover{filter:brightness(1.08);}

        /* ─── PERSON CARD ─── */
        .person-row{display:flex;align-items:center;gap:14px;margin-bottom:16px;}
        .person-avatar{width:44px;height:44px;flex-shrink:0;background:var(--red-dim);border:1px solid var(--red-border);border-radius:12px;display:flex;align-items:center;justify-content:center;font-family:var(--heading);font-size:1.3rem;color:var(--red);}
        .person-name{font-size:.95rem;font-weight:600;}
        .person-role-label{font-family:var(--mono);font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-top:3px;}
        .contact-row{display:flex;align-items:center;gap:10px;font-size:.82rem;color:var(--muted2);margin-bottom:8px;}
        .contact-row i{color:var(--red);width:14px;text-align:center;font-size:.75rem;}
        .contact-row a{color:var(--muted2);text-decoration:none;} .contact-row a:hover{color:var(--text);}

        /* ─── TIMELINE ─── */
        .timeline{display:flex;flex-direction:column;gap:0;}
        .tl-item{display:flex;gap:14px;padding:14px 0;border-bottom:1px solid var(--border);position:relative;}
        .tl-item:last-child{border-bottom:none;padding-bottom:0;}
        .tl-icon{width:34px;height:34px;flex-shrink:0;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.8rem;border:1px solid;}
        .tl-icon.done{background:rgba(74,222,128,.1);border-color:rgba(74,222,128,.25);color:#4ADE80;}
        .tl-icon.active{background:var(--red-dim);border-color:var(--red-border);color:#F87171;}
        .tl-icon.pending{background:rgba(255,255,255,.03);border-color:var(--border);color:var(--muted);}
        .tl-label{font-size:.86rem;font-weight:600;margin-bottom:4px;}
        .tl-label.pending-text{color:var(--muted);}
        .tl-desc{font-size:.78rem;color:var(--muted2);line-height:1.55;}

        /* ─── UPDATES ─── */
        .update-form-inner{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:16px;}
        .update-textarea{width:100%;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-family:var(--body);font-size:.84rem;color:var(--text);resize:vertical;min-height:68px;outline:none;transition:border-color .18s;margin-bottom:10px;}
        .update-textarea::placeholder{color:var(--muted);}
        .update-textarea:focus{border-color:var(--red-border);}
        .update-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--border);}
        .update-item:last-child{border-bottom:none;padding-bottom:0;}
        .update-avatar{width:30px;height:30px;flex-shrink:0;background:var(--card2);border:1px solid var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;font-family:var(--heading);font-size:1rem;color:var(--muted2);}
        .update-avatar.resp{background:var(--red-dim);border-color:var(--red-border);color:var(--red);}
        .update-meta{display:flex;align-items:center;gap:8px;margin-bottom:4px;}
        .update-name{font-size:.8rem;font-weight:600;}
        .update-badge{font-family:var(--mono);font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;background:var(--red-dim);border:1px solid var(--red-border);color:#F87171;padding:2px 7px;border-radius:100px;}
        .update-time{font-family:var(--mono);font-size:.62rem;color:var(--muted);margin-left:auto;}
        .update-text{font-size:.82rem;color:var(--muted2);line-height:1.6;}
        .no-updates{padding:24px 0;text-align:center;font-family:var(--mono);font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);}

        /* ─── COORDS ─── */
        .coords-block{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;}
        .coord-chip{font-family:var(--mono);font-size:.68rem;background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:4px 10px;color:var(--muted2);}
        .map-link{font-family:var(--mono);font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;color:var(--red);text-decoration:none;display:inline-flex;align-items:center;gap:5px;margin-top:8px;}
        .map-link:hover{text-decoration:underline;}

        /* ─── REVEAL ─── */
        .reveal{opacity:0;transform:translateY(14px);transition:opacity .5s ease,transform .5s ease;}
        .reveal.in{opacity:1;transform:translateY(0);}

        @media(max-width:960px){
            .nav{padding:12px 16px;}
            .page-header{padding:24px 16px 20px;}
            .page{padding:18px 16px 60px;}
            .grid{grid-template-columns:1fr;}
            .detail-grid{grid-template-columns:1fr;}
            .actions-row{flex-direction:column;align-items:stretch;}
        }
    </style>
</head>
<body>

<!-- ─── NAV ─── -->
<nav class="nav">
    <a href="/disaster_response/index.php" class="nav-brand"><i class="fas fa-hands-helping"></i><span>Disaster</span>Response</a>
    <div class="nav-right">
        <a href="/disaster_response/modules/responders/responders_dashboard.php" class="nav-pill">Dashboard</a>
        <a href="all.php" class="nav-pill">All Incidents</a>
        <a href="/disaster_response/modules/auth/logout.php" class="nav-pill red">Logout</a>
    </div>
</nav>

<!-- ─── PAGE HEADER ─── -->
<div class="page-header">
    <div class="ph-inner">
        <div>
            <div class="eyebrow">// Incident Detail</div>
            <h1 class="page-title">INC-<?= str_pad($incident_id,5,'0',STR_PAD_LEFT) ?></h1>
            <p class="page-sub"><?= $typeIco ?> <?= ucfirst(str_replace('_',' ',$incident['incident_type'] ?? 'Unknown')) ?> &nbsp;·&nbsp; <?= date('M j, Y · g:i A', strtotime($incident['reported_at'] ?? 'now')) ?></p>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
            <span class="status-pill" style="background:<?= $stMeta['dim'] ?>;color:<?= $stMeta['color'] ?>;border-color:<?= $stMeta['border'] ?>;">
                <span style="width:6px;height:6px;background:currentColor;border-radius:50%;"></span>
                <?= $stMeta['label'] ?>
            </span>
            <span class="sev-pip" style="background:<?= $sev['dim'] ?>;color:<?= $sev['color'] ?>;border-color:<?= $sev['border'] ?>;"><?= $sev['label'] ?> Severity</span>
        </div>
    </div>
</div>

<div class="page">

    <?php if ($status_update_success): ?>
    <div class="toast-bar toast-success reveal" id="toastOk"><i class="fas fa-check-circle"></i><?= htmlspecialchars($status_update_success) ?><button onclick="this.parentElement.remove()">&times;</button></div>
    <?php endif; ?>
    <?php if ($status_update_error): ?>
    <div class="toast-bar toast-error reveal" id="toastErr"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($status_update_error) ?><button onclick="this.parentElement.remove()">&times;</button></div>
    <?php endif; ?>

    <!-- ─── RESPONDER ACTIONS ─── -->
    <?php if ($is_responder && !in_array($incident['status'] ?? '', ['resolved','cancelled','rejected'])): ?>
    <div class="block reveal" style="margin-bottom:18px;">
        <div class="block-head"><i class="fas fa-sliders"></i> Responder Actions</div>
        <div class="block-body">
            <div class="actions-row">
                <form method="POST" style="display:contents;">
                    <input type="hidden" name="action" value="update_status">
                    <select name="status" class="field-select">
                        <?php foreach(['reported'=>'Received','acknowledged'=>'Under Review','in-progress'=>'En Route','resolved'=>'Resolved','cancelled'=>'Cancelled'] as $val=>$lbl): ?>
                        <option value="<?= $val ?>" <?= ($incident['status']??'')===$val?'selected':'' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="rc-btn rc-btn-red"><i class="fas fa-rotate"></i> Update Status</button>
                </form>
                <form method="POST" style="display:contents;">
                    <input type="hidden" name="action" value="assign_responder">
                    <select name="responder_id" class="field-select">
                        <option value="">— Assign Responder —</option>
                        <?php foreach ($responders as $rsp): ?>
                        <option value="<?= $rsp['id'] ?>" <?= (($incident['assigned_to']??0)==$rsp['id'])?'selected':'' ?>><?= htmlspecialchars($rsp['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="rc-btn rc-btn-ghost"><i class="fas fa-user-plus"></i> Assign</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid">

        <!-- ─── LEFT ─── -->
        <div>

            <!-- INCIDENT DETAILS -->
            <div class="block reveal">
                <div class="block-head"><i class="fas fa-circle-info"></i> Incident Details</div>
                <div class="block-body">
                    <div style="font-size:2.4rem;margin-bottom:10px;"><?= $typeIco ?></div>
                    <div style="font-size:1.15rem;font-weight:700;margin-bottom:18px;"><?= ucfirst(str_replace('_',' ',$incident['incident_type']??'Unknown')) ?></div>
                    <div class="detail-grid">
                        <div>
                            <div class="detail-label">Severity</div>
                            <div class="detail-val">
                                <span class="sev-pip" style="background:<?= $sev['dim'] ?>;color:<?= $sev['color'] ?>;border-color:<?= $sev['border'] ?>;"><?= $sev['label'] ?></span>
                            </div>
                        </div>
                        <div>
                            <div class="detail-label">Status</div>
                            <div class="detail-val">
                                <span class="sev-pip" style="background:<?= $stMeta['dim'] ?>;color:<?= $stMeta['color'] ?>;border-color:<?= $stMeta['border'] ?>;"><?= $stMeta['label'] ?></span>
                            </div>
                        </div>
                        <div>
                            <div class="detail-label">Reported</div>
                            <div class="detail-val"><?= date('M j, Y · g:i A', strtotime($incident['reported_at']??'now')) ?></div>
                        </div>
                        <div>
                            <div class="detail-label">Last Updated</div>
                            <div class="detail-val"><?= date('M j, Y · g:i A', strtotime($incident['updated_at']??$incident['reported_at']??'now')) ?></div>
                        </div>
                        <div class="detail-full">
                            <div class="detail-label">Location</div>
                            <div class="detail-val"><?= safe($incident['location_name']??null,'Coordinates captured — see map below') ?></div>
                        </div>
                        <?php if (!empty($incident['latitude']) && !empty($incident['longitude'])): ?>
                        <div class="detail-full">
                            <div class="detail-label">Coordinates</div>
                            <div class="coords-block">
                                <span class="coord-chip">Lat: <?= number_format((float)$incident['latitude'],6) ?></span>
                                <span class="coord-chip">Lng: <?= number_format((float)$incident['longitude'],6) ?></span>
                            </div>
                            <a href="https://www.google.com/maps?q=<?= $incident['latitude'] ?>,<?= $incident['longitude'] ?>" target="_blank" class="map-link">
                                <i class="fas fa-arrow-up-right-from-square"></i> Open in Google Maps
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="detail-full">
                            <div class="detail-label">Description</div>
                            <div class="detail-val" style="line-height:1.75;"><?= nl2br(htmlspecialchars($incident['description']??'')) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MAP -->
            <?php if (!empty($incident['latitude']) && !empty($incident['longitude'])): ?>
            <div class="block reveal">
                <div class="block-head"><i class="fas fa-map-pin"></i> Incident Location</div>
                <div class="block-body" style="padding:14px;">
                    <div id="incidentMap"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- PHOTO -->
            <?php if (!empty($incident['photo_path'])): ?>
            <div class="block reveal">
                <div class="block-head"><i class="fas fa-image"></i> Evidence Photo</div>
                <div class="block-body" style="padding:14px;">
                    <img src="<?= htmlspecialchars($incident['photo_path']) ?>" alt="Incident photo" class="incident-photo"
                         onclick="window.open(this.src,'_blank')">
                    <div style="font-family:var(--mono);font-size:.62rem;color:var(--muted);margin-top:8px;letter-spacing:.08em;">Click to open full size</div>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <!-- ─── RIGHT ─── -->
        <div>

            <!-- REPORTER -->
            <div class="block reveal">
                <div class="block-head"><i class="fas fa-user"></i> Reporter</div>
                <div class="block-body">
                    <div class="person-row">
                        <div class="person-avatar"><?= strtoupper(substr($incident['reporter_name']??'?',0,1)) ?></div>
                        <div>
                            <div class="person-name"><?= safe($incident['reporter_name']??null,'Anonymous') ?></div>
                            <div class="person-role-label">Reporter</div>
                        </div>
                    </div>
                    <div class="contact-row"><i class="fas fa-envelope"></i>
                        <?php if (!empty($incident['reporter_email'])): ?>
                        <a href="mailto:<?= htmlspecialchars($incident['reporter_email']) ?>"><?= htmlspecialchars($incident['reporter_email']) ?></a>
                        <?php else: ?><span>Not provided</span><?php endif; ?>
                    </div>
                    <div class="contact-row"><i class="fas fa-phone"></i>
                        <?php if (!empty($incident['reporter_phone'])): ?>
                        <a href="tel:<?= htmlspecialchars($incident['reporter_phone']) ?>"><?= htmlspecialchars($incident['reporter_phone']) ?></a>
                        <?php else: ?><span>Not provided</span><?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RESPONDER -->
            <div class="block reveal">
                <div class="block-head"><i class="fas fa-shield-halved"></i> Assigned Responder</div>
                <div class="block-body">
                    <?php if (!empty($incident['assigned_to']) && !empty($incident['responder_name'])): ?>
                    <div class="person-row">
                        <div class="person-avatar" style="background:rgba(129,140,248,.1);border-color:rgba(129,140,248,.25);color:#818CF8;"><?= strtoupper(substr($incident['responder_name'],0,1)) ?></div>
                        <div>
                            <div class="person-name"><?= htmlspecialchars($incident['responder_name']) ?></div>
                            <div class="person-role-label">Field Responder</div>
                        </div>
                    </div>
                    <?php if (!empty($incident['responder_phone'])): ?>
                    <div class="contact-row"><i class="fas fa-phone"></i><a href="tel:<?= htmlspecialchars($incident['responder_phone']) ?>"><?= htmlspecialchars($incident['responder_phone']) ?></a></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="no-updates" style="padding:12px 0;text-align:left;">
                        <?= $is_responder ? 'Use the actions panel above to assign a responder.' : 'A responder will be assigned shortly.' ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TIMELINE -->
            <div class="block reveal">
                <div class="block-head"><i class="fas fa-timeline"></i> Response Timeline</div>
                <div class="block-body" style="padding:14px 18px;">
                    <div class="timeline">
                        <?php
                        $rejected = ($incident['status']??'') === 'rejected';
                        $cancelled = ($incident['status']??'') === 'cancelled';
                        foreach ($timeline_steps as $i => $step):
                            $done   = $current_order > $i || ($step['status'] === $incident['status']);
                            $active = $step['status'] === $incident['status'];
                            $iconClass = $done ? 'done' : ($active ? 'active' : 'pending');
                        ?>
                        <div class="tl-item">
                            <div class="tl-icon <?= $iconClass ?>">
                                <i class="fas <?= $step['icon'] ?>"></i>
                            </div>
                            <div>
                                <div class="tl-label <?= (!$done && !$active) ? 'pending-text' : '' ?>"><?= $step['label'] ?></div>
                                <div class="tl-desc"><?= $step['desc'] ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($rejected): ?>
                        <div class="tl-item">
                            <div class="tl-icon active"><i class="fas fa-xmark"></i></div>
                            <div>
                                <div class="tl-label">Rejected</div>
                                <div class="tl-desc"><?= safe($incident['rejection_reason']??null,'No reason provided') ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($cancelled): ?>
                        <div class="tl-item">
                            <div class="tl-icon pending"><i class="fas fa-ban"></i></div>
                            <div>
                                <div class="tl-label pending-text">Cancelled</div>
                                <div class="tl-desc">This report was cancelled.</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- UPDATES -->
            <div class="block reveal">
                <div class="block-head">
                    <i class="fas fa-comments"></i> Updates &amp; Communication
                    <?php if (count($updates)): ?>
                    <span style="margin-left:auto;font-family:var(--heading);font-size:1.1rem;color:var(--red);"><?= count($updates) ?></span>
                    <?php endif; ?>
                </div>
                <div class="block-body">
                    <?php if ($is_reporter || $is_responder): ?>
                    <div class="update-form-inner">
                        <form method="POST">
                            <input type="hidden" name="action" value="add_update">
                            <textarea name="additional_info" class="update-textarea" placeholder="Post an update or additional information about this incident…" required></textarea>
                            <button type="submit" class="rc-btn rc-btn-red" style="font-size:.75rem;"><i class="fas fa-paper-plane"></i> Post Update</button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if (count($updates) > 0): ?>
                    <div class="updates-list">
                        <?php foreach ($updates as $upd): $isResp = ($upd['user_role']??'')==='responder'; ?>
                        <div class="update-item">
                            <div class="update-avatar <?= $isResp?'resp':'' ?>"><?= strtoupper(substr($upd['user_name']??'?',0,1)) ?></div>
                            <div style="flex:1;">
                                <div class="update-meta">
                                    <span class="update-name"><?= htmlspecialchars($upd['user_name']??'Unknown') ?></span>
                                    <?php if ($isResp): ?><span class="update-badge">Responder</span><?php endif; ?>
                                    <span class="update-time"><?= date('M j, g:i A', strtotime($upd['created_at']??'now')) ?></span>
                                </div>
                                <div class="update-text"><?= nl2br(htmlspecialchars($upd['update_text']??'')) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="no-updates">No updates posted yet</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    <?php if (!empty($incident['latitude']) && !empty($incident['longitude'])): ?>
    const map = L.map('incidentMap').setView([<?= (float)$incident['latitude'] ?>, <?= (float)$incident['longitude'] ?>], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(map);
    const redIcon = L.divIcon({
        html: `<div style="width:14px;height:14px;background:#E8271A;border:2px solid #fff;border-radius:50%;box-shadow:0 2px 8px rgba(232,39,26,.6);"></div>`,
        className: '', iconSize: [14,14], iconAnchor: [7,7]
    });
    L.marker([<?= (float)$incident['latitude'] ?>, <?= (float)$incident['longitude'] ?>], {icon: redIcon})
     .bindPopup(`<div style="font-family:'DM Sans',sans-serif;"><strong>INC-<?= str_pad($incident_id,5,'0',STR_PAD_LEFT) ?></strong><br>${'<?= ucfirst(str_replace("_"," ",$incident["incident_type"]??"")) ?>'}<br><?= $sev['label'] ?> severity</div>`)
     .openPopup()
     .addTo(map);
    <?php endif; ?>

    /* ─── REVEAL ─── */
    const obs = new IntersectionObserver(entries => {
        entries.forEach((e,i) => {
            if (e.isIntersecting) { setTimeout(()=>e.target.classList.add('in'), i*60); obs.unobserve(e.target); }
        });
    }, {threshold:.06});
    document.querySelectorAll('.reveal').forEach(el => obs.observe(el));

    /* ─── AUTO-DISMISS TOASTS ─── */
    setTimeout(() => {
        ['toastOk','toastErr'].forEach(id => {
            const el = document.getElementById(id);
            if (el) { el.style.opacity='0'; el.style.transition='opacity .4s'; setTimeout(()=>el.remove(),400); }
        });
    }, 5000);
</script>
</body>
</html>