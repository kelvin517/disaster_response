<?php
/**
 * Responder Dashboard - Complete Operational Command Center
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

if (!isLoggedIn()) redirect('modules/auth/login.php');
if (!hasRole(['responder', 'admin'])) redirect('index.php');

$user_id = $_SESSION['user_id'];

// ============================================
// STATISTICS
// ============================================

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM incidents WHERE status IN ('reported', 'acknowledged', 'in-progress', 'assigned')");
$stmt->execute();
$active_incidents = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM incidents WHERE status = 'reported'");
$stmt->execute();
$pending_count = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM incidents WHERE assigned_to = ? AND status NOT IN ('resolved', 'closed', 'cancelled')");
$stmt->execute([$user_id]);
$my_assigned = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread_messages = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT resource_type) as count FROM resources WHERE status = 'available' AND quantity > 0");
$stmt->execute();
$available_resources = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM responder_locations WHERE last_update >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
$stmt->execute();
$online_team = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM field_updates WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$stmt->execute();
$recent_updates_count = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM resource_requests WHERE status = 'pending'");
$stmt->execute();
$pending_resources = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM alerts WHERE expires_at > NOW()");
$stmt->execute();
$active_alerts = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM resources WHERE status = 'available' AND quantity < 100");
$stmt->execute();
$low_stock_alerts = $stmt->fetch()['count'];

// ============================================
// FETCH DATA
// ============================================

$stmt = $pdo->prepare("
    SELECT rr.*, u.full_name as requester_name
    FROM resource_requests rr JOIN users u ON rr.user_id = u.id
    WHERE rr.status = 'pending'
    ORDER BY CASE rr.urgency WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END ASC, rr.requested_at ASC LIMIT 5
");
$stmt->execute();
$recent_requests = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT i.*, u.full_name as reporter_name 
    FROM incidents i JOIN users u ON i.reporter_id = u.id 
    WHERE i.status = 'reported' 
    ORDER BY i.severity DESC, i.reported_at ASC LIMIT 5
");
$stmt->execute();
$pending_incidents = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT * FROM alerts WHERE expires_at > NOW() ORDER BY created_at DESC LIMIT 3
");
$stmt->execute();
$recent_alerts = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT i.*, u.full_name as reporter_name 
    FROM incidents i JOIN users u ON i.reporter_id = u.id 
    WHERE i.status IN ('reported', 'acknowledged', 'in-progress', 'assigned') 
    ORDER BY i.reported_at DESC LIMIT 5
");
$stmt->execute();
$recent_incidents = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT severity, COUNT(*) as count 
    FROM incidents WHERE status NOT IN ('resolved', 'closed', 'cancelled', 'rejected') 
    GROUP BY severity
");
$stmt->execute();
$severity_stats = $stmt->fetchAll();

$severity_labels = []; $severity_counts = [];
$severity_map = [1 => 'Low', 2 => 'Medium', 3 => 'High', 4 => 'Critical'];
foreach ($severity_stats as $stat) {
    $severity_labels[] = $severity_map[$stat['severity']] ?? 'Unknown';
    $severity_counts[] = $stat['count'];
}

$stmt = $pdo->prepare("
    SELECT fu.*, u.full_name as responder_name, i.incident_type, i.location_name
    FROM field_updates fu
    JOIN users u ON fu.responder_id = u.id
    LEFT JOIN incidents i ON fu.incident_id = i.id
    ORDER BY fu.created_at DESC LIMIT 5
");
$stmt->execute();
$recent_field_updates = $stmt->fetchAll();

$urgency_colors = ['critical' => '#e8271d', 'high' => '#fd7e14', 'medium' => '#ffc107', 'low' => '#28a745'];
$resource_types = ['food' => '🍲 Food', 'water' => '💧 Water', 'medicine' => '💊 Medicine', 'shelter' => '🏠 Shelter', 'clothing' => '👕 Clothing', 'blankets' => '🛏️ Blankets', 'first_aid' => '🩹 First Aid', 'transport' => '🚛 Transport', 'other' => '📦 Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Responder Dashboard — DisasterResponse</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
  --bg: #f0f2f5;
  --surface: #ffffff;
  --surface-2: #f7f8fa;
  --border: #e2e5ea;
  --border-2: #d0d4db;
  --navy: #0f1b2d;
  --navy-2: #1a2b42;
  --red: #e8271d;
  --red-light: #fff0ef;
  --amber: #d97706;
  --amber-light: #fffbeb;
  --blue: #1d6ef5;
  --blue-light: #eff5ff;
  --green: #16a34a;
  --green-light: #f0fdf4;
  --teal: #0891b2;
  --teal-light: #ecfeff;
  --purple: #7c3aed;
  --purple-light: #f5f3ff;
  --text: #0f1b2d;
  --text-2: #374151;
  --muted: #6b7280;
  --muted-2: #9ca3af;
  --ff-head: 'Barlow Condensed', sans-serif;
  --ff-body: 'Barlow', sans-serif;
  --ff-mono: 'IBM Plex Mono', monospace;
  --r: 8px;
  --r-lg: 12px;
  --shadow: 0 1px 3px rgba(15,27,45,.08), 0 4px 16px rgba(15,27,45,.06);
  --ease: .18s cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--ff-body);background:var(--bg);color:var(--text);font-size:14px}
.topbar{background:var(--navy);height:54px;display:flex;align-items:stretch;position:sticky;top:0;z-index:300}
.brand{display:flex;align-items:center;gap:.5rem;padding:0 2rem 0 1.25rem;background:var(--red);text-decoration:none;clip-path:polygon(0 0,calc(100% - 14px) 0,100% 100%,0 100%)}
.brand-text{font-family:var(--ff-head);font-weight:800;font-size:1.1rem;color:#fff;text-transform:uppercase}
.brand-sub{font-family:var(--ff-mono);font-size:.5rem;font-weight:600;color:rgba(255,255,255,.65);display:block;margin-top:-2px}
.nav-area{display:flex;align-items:center;padding:0 .75rem;gap:.1rem;flex:1;overflow-x:auto}
.npill{display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .75rem;border-radius:5px;color:rgba(255,255,255,.6);font-size:.78rem;text-decoration:none}
.npill:hover{color:#fff;background:rgba(255,255,255,.1)}
.npill.active{color:#fff;background:rgba(255,255,255,.15)}
.logout-btn{margin:auto 1.25rem;padding:.3rem .7rem;border-radius:5px;border:1px solid rgba(232,39,29,.4);background:rgba(232,39,29,.12);color:#ff7a74;font-size:.75rem;text-decoration:none}
.logout-btn:hover{background:var(--red);color:#fff}
.page-hero{background:var(--navy);padding:1.4rem 0;border-bottom:3px solid var(--red)}
.hero-eyebrow{font-family:var(--ff-mono);font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:var(--red);margin-bottom:.3rem}
.hero-title{font-family:var(--ff-head);font-weight:800;font-size:1.8rem;color:#fff;text-transform:uppercase}
.hero-sub{color:rgba(255,255,255,.45);font-size:.8rem}
.page{max-width:1400px;margin:0 auto;padding:1.5rem 1.25rem 4rem}
.kpi-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:.85rem;margin-bottom:1.5rem}
@media(max-width:900px){.kpi-grid{grid-template-columns:repeat(3,1fr)}}
.kpi{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:.9rem;text-align:center;box-shadow:var(--shadow);transition:all var(--ease);position:relative}
.kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--red);border-radius:var(--r-lg) var(--r-lg) 0 0}
.kpi:hover{transform:translateY(-3px)}
.kpi-num{font-family:var(--ff-head);font-size:1.5rem;font-weight:800}
.kpi-lbl{font-size:.65rem;font-weight:600;color:var(--muted);text-transform:uppercase}
.kpi-red .kpi-num{color:#e8271d}
.kpi-amber .kpi-num{color:#d97706}
.kpi-blue .kpi-num{color:#1d6ef5}
.kpi-green .kpi-num{color:#16a34a}
.kpi-teal .kpi-num{color:#0891b2}
.dashboard-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);margin-bottom:1rem;box-shadow:var(--shadow);overflow:hidden}
.card-header{background:var(--surface-2);border-bottom:1px solid var(--border);padding:.7rem 1rem;font-family:var(--ff-head);font-weight:700;font-size:.75rem;text-transform:uppercase;display:flex;justify-content:space-between;align-items:center}
.view-all{font-size:.68rem;color:var(--red);text-decoration:none}
.quick-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:.5rem;padding:.75rem}
@media(max-width:768px){.quick-grid{grid-template-columns:repeat(3,1fr)}}
.quick-btn{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r);padding:.7rem .3rem;text-align:center;text-decoration:none;transition:all var(--ease)}
.quick-btn:hover{transform:translateY(-2px);border-color:var(--red);background:var(--red-light)}
.quick-btn i{font-size:1.2rem;color:var(--red);display:block;margin-bottom:.3rem}
.quick-btn span{font-size:.65rem;font-weight:600;color:var(--muted);text-transform:uppercase}
.request-item,.incident-item,.alert-item{padding:.8rem 1rem;border-bottom:1px solid var(--border);cursor:pointer;transition:background var(--ease)}
.request-item:hover,.incident-item:hover,.alert-item:hover{background:var(--surface-2)}
.badge-urgency{padding:.2rem .6rem;border-radius:20px;font-size:.65rem;font-weight:600}
.sev-badge{padding:.15rem .5rem;border-radius:20px;font-size:.6rem;font-weight:700}
.sev-critical{background:rgba(232,39,29,.12);color:#e8271d}
.sev-high{background:rgba(217,119,6,.12);color:#d97706}
.sev-medium{background:rgba(29,110,245,.12);color:#1d6ef5}
.sev-low{background:rgba(22,163,74,.12);color:#16a34a}
.alert-emergency{background:#dc2626;color:#fff;padding:.15rem .5rem;border-radius:20px;font-size:.6rem}
.alert-danger{background:#e8271d;color:#fff;padding:.15rem .5rem;border-radius:20px;font-size:.6rem}
.alert-warning{background:#f59e0b;color:#333;padding:.15rem .5rem;border-radius:20px;font-size:.6rem}
.btn-verify{background:rgba(22,163,74,.12);border:1px solid rgba(22,163,74,.25);color:#16a34a;padding:.2rem .6rem;border-radius:20px;font-size:.65rem;text-decoration:none}
.btn-verify:hover{background:rgba(22,163,74,.2)}
.chart-wrap{padding:1rem}
.empty-state{text-align:center;padding:2rem;color:var(--muted-2)}
.empty-state i{font-size:2rem;margin-bottom:.5rem;opacity:.3}
@media(max-width:768px){.hero-title{font-size:1.35rem}}
</style>
</head>
<body>

<div class="topbar">
  <a class="brand" href="responders_dashboard.php">
    <i class="bi bi-shield-fill-check" style="color:#fff"></i>
    <div><span class="brand-text">DisasterResponse</span><span class="brand-sub">Responder Portal</span></div>
  </a>
  <div class="nav-area">
    <a href="responders_dashboard.php" class="npill active"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="team.php" class="npill"><i class="bi bi-people"></i> Team</a>
    <a href="updates.php" class="npill"><i class="bi bi-chat-dots"></i> Updates</a>
    <a href="../messaging/inbox.php" class="npill"><i class="bi bi-envelope"></i> Messages<?php if($unread_messages>0):?><span class="badge bg-danger ms-1"><?=$unread_messages?></span><?php endif;?></a>
    <a href="../resources/manage.php" class="npill"><i class="bi bi-box-seam"></i> Resources<?php if($pending_resources>0):?><span class="badge bg-danger ms-1"><?=$pending_resources?></span><?php endif;?></a>
    <a href="../incidents/pending.php" class="npill"><i class="bi bi-clock-history"></i> Pending<?php if($pending_count>0):?><span class="badge bg-danger ms-1"><?=$pending_count?></span><?php endif;?></a>
    <a href="../mapping/map.php" class="npill"><i class="bi bi-map"></i> Live Map</a>
  </div>
  <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</div>

<div class="page-hero">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-shield-fill-check me-1"></i>Command Centre</div>
    <div class="hero-title">Responder Dashboard</div>
    <div class="hero-sub">Real-time incident monitoring and resource coordination</div>
  </div>
</div>

<div class="page">
  <!-- KPI Grid -->
  <div class="kpi-grid">
    <div class="kpi"><div class="kpi-num kpi-red"><?= $active_incidents ?></div><div class="kpi-lbl">Active Incidents</div></div>
    <div class="kpi"><div class="kpi-num kpi-amber"><?= $pending_count ?></div><div class="kpi-lbl">Pending</div></div>
    <div class="kpi"><div class="kpi-num kpi-blue"><?= $my_assigned ?></div><div class="kpi-lbl">My Tasks</div></div>
    <div class="kpi"><div class="kpi-num kpi-teal"><?= $online_team ?></div><div class="kpi-lbl">Team Online</div></div>
    <div class="kpi"><div class="kpi-num kpi-amber"><?= $pending_resources ?></div><div class="kpi-lbl">Aid Requests</div></div>
    <div class="kpi"><div class="kpi-num kpi-green"><?= $active_alerts ?></div><div class="kpi-lbl">Active Alerts</div></div>
  </div>

  <!-- Quick Actions -->
  <div class="dashboard-card">
    <div class="card-header"><span><i class="bi bi-lightning-charge-fill me-1"></i>Quick Actions</span></div>
    <div class="quick-grid">
      <a href="../mapping/map.php" class="quick-btn"><i class="bi bi-map"></i><span>Map</span></a>
      <a href="../incidents/pending.php" class="quick-btn"><i class="bi bi-check2-circle"></i><span>Verify</span><?php if($pending_count>0):?><span class="badge bg-danger ms-1"><?=$pending_count?></span><?php endif;?></a>
      <a href="../resources/manage.php" class="quick-btn"><i class="bi bi-box-seam"></i><span>Aid</span><?php if($pending_resources>0):?><span class="badge bg-danger ms-1"><?=$pending_resources?></span><?php endif;?></a>
      <a href="team.php" class="quick-btn"><i class="bi bi-people"></i><span>Team</span></a>
      <a href="updates.php" class="quick-btn"><i class="bi bi-chat-dots"></i><span>Updates</span></a>
      <a href="../messaging/compose.php" class="quick-btn"><i class="bi bi-envelope"></i><span>Message</span></a>
    </div>
  </div>

  <?php if(count($recent_alerts) > 0): ?>
  <div class="dashboard-card">
    <div class="card-header"><span><i class="bi bi-bell-fill me-1"></i>Active Alerts</span><a href="../alerts/history.php" class="view-all">View All →</a></div>
    <?php foreach($recent_alerts as $alert): $alert_class = $alert['alert_type']=='emergency'?'emergency':($alert['alert_type']=='danger'?'danger':($alert['alert_type']=='warning'?'warning':'info')); ?>
    <div class="alert-item" onclick="window.location.href='../alerts/history.php'">
      <div class="d-flex justify-content-between">
        <div><span class="alert-<?=$alert_class?>" style="padding:.15rem .5rem;border-radius:20px;font-size:.6rem;font-weight:700"><?=strtoupper($alert['alert_type'])?></span><div class="fw-semibold small mt-1"><?=htmlspecialchars($alert['title'])?></div></div>
        <small class="text-muted"><?=date('H:i',strtotime($alert['created_at']))?></small>
      </div>
      <p class="small text-muted mb-0 mt-1"><?=htmlspecialchars(substr($alert['message'],0,80))?>…</p>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="dashboard-card">
        <div class="card-header"><span><i class="bi bi-box-seam me-1"></i>Pending Aid Requests</span><a href="../resources/manage.php" class="view-all">View All →</a></div>
        <?php if(count($recent_requests)>0): foreach($recent_requests as $req): ?>
        <div class="request-item" onclick="window.location.href='../resources/manage.php'">
          <div class="d-flex justify-content-between">
            <div><h6 class="mb-0 small"><i class="bi bi-person-circle"></i> <?=htmlspecialchars($req['requester_name'])?><span class="badge-urgency ms-1" style="background:<?=$urgency_colors[$req['urgency']]?>20;color:<?=$urgency_colors[$req['urgency']]?>"><?=strtoupper($req['urgency'])?></span></h6>
            <div class="small text-muted"><?=$resource_types[$req['resource_type']]??$req['resource_type']?> • Qty: <?=number_format($req['quantity'])?> • <?=date('M j, H:i',strtotime($req['requested_at']))?></div>
            <p class="small text-muted mb-0 mt-1">📍 <?=htmlspecialchars($req['location_name']??'Location provided')?></p></div>
            <div><a href="../resources/fulfill.php?id=<?=$req['id']?>&action=approve" class="btn-verify" onclick="event.stopPropagation()"><i class="bi bi-check2-circle"></i> Approve</a></div>
          </div>
        </div>
        <?php endforeach; else: ?><div class="empty-state"><i class="bi bi-inbox"></i><p>No pending aid requests</p></div><?php endif; ?>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="dashboard-card">
        <div class="card-header"><span><i class="bi bi-clock-history me-1"></i>Pending Verification</span><a href="../incidents/pending.php" class="view-all">View All →</a></div>
        <?php if(count($pending_incidents)>0): foreach($pending_incidents as $incident): $sev_label = match((int)$incident['severity']){4=>'CRITICAL',3=>'HIGH',2=>'MEDIUM',default=>'LOW'}; ?>
        <div class="incident-item" onclick="window.location.href='../incidents/verify.php?id=<?=$incident['id']?>'">
          <div class="d-flex justify-content-between">
            <div><h6 class="mb-0 small"><i class="bi bi-geo-alt-fill"></i> <?=htmlspecialchars($incident['location_name']??'Unknown')?></h6>
            <div class="small text-muted"><?=ucfirst($incident['incident_type'])?> • <?=htmlspecialchars($incident['reporter_name'])?> • <?=date('M j, H:i',strtotime($incident['reported_at']))?></div>
            <p class="small text-muted mb-0 mt-1"><?=htmlspecialchars(substr($incident['description'],0,60))?>…</p></div>
            <div class="text-end"><span class="sev-badge sev-<?=$incident['severity']==4?'critical':($incident['severity']==3?'high':($incident['severity']==2?'medium':'low'))?>"><?=$sev_label?></span><a href="../incidents/verify.php?id=<?=$incident['id']?>" class="btn-verify d-block mt-1" onclick="event.stopPropagation()"><i class="bi bi-check2-circle"></i> Verify</a></div>
          </div>
        </div>
        <?php endforeach; else: ?><div class="empty-state"><i class="bi bi-inbox"></i><p>No pending incidents</p></div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="dashboard-card">
        <div class="card-header"><span><i class="bi bi-pie-chart-fill me-1"></i>Incidents by Severity</span></div>
        <div class="chart-wrap"><canvas id="severityChart" height="180"></canvas></div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="dashboard-card">
        <div class="card-header"><span><i class="bi bi-chat-dots-fill me-1"></i>Recent Field Updates</span><a href="updates.php" class="view-all">Post Update →</a></div>
        <?php if(count($recent_field_updates)>0): foreach($recent_field_updates as $update): ?>
        <div class="incident-item">
          <div class="d-flex justify-content-between">
            <div><span class="fw-semibold small"><?=htmlspecialchars($update['responder_name'])?></span><span class="text-muted small ms-1">at incident #<?=str_pad($update['incident_id'],5,'0',STR_PAD_LEFT)?></span></div>
            <small class="text-muted"><?=date('H:i',strtotime($update['created_at']))?></small>
          </div>
          <p class="small text-muted mb-0 mt-1"><?=htmlspecialchars(substr($update['update_text']??'No message',0,80))?>…</p>
          <?php if($update['photo_path']): ?><i class="bi bi-image text-muted small mt-1"></i><?php endif; ?>
        </div>
        <?php endforeach; else: ?><div class="empty-state"><i class="bi bi-chat-dots"></i><p>No field updates yet.<br><a href="updates.php">Post the first update →</a></p></div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="dashboard-card">
    <div class="card-header"><span><i class="bi bi-exclamation-triangle-fill me-1"></i>Recent Active Incidents</span><a href="../incidents/all.php" class="view-all">View All →</a></div>
    <?php if(count($recent_incidents)>0): foreach($recent_incidents as $incident): $sev_label = match((int)$incident['severity']){4=>'CRITICAL',3=>'HIGH',2=>'MEDIUM',default=>'LOW'}; ?>
    <div class="incident-item" onclick="window.location.href='../incidents/view.php?id=<?=$incident['id']?>'">
      <div class="d-flex justify-content-between">
        <div><h6 class="mb-0 small"><i class="bi bi-geo-alt-fill"></i> <?=htmlspecialchars($incident['location_name']??'Unknown')?></h6>
        <div class="small text-muted"><?=ucfirst($incident['incident_type'])?> • <?=htmlspecialchars($incident['reporter_name'])?> • <?=date('M j, H:i',strtotime($incident['reported_at']))?></div>
        <p class="small text-muted mb-0 mt-1"><?=htmlspecialchars(substr($incident['description'],0,80))?>…</p></div>
        <div class="text-end"><span class="sev-badge sev-<?=$incident['severity']==4?'critical':($incident['severity']==3?'high':($incident['severity']==2?'medium':'low'))?>"><?=$sev_label?></span><span class="badge bg-secondary d-block mt-1"><?=ucfirst(str_replace('-',' ',$incident['status']))?></span></div>
      </div>
    </div>
    <?php endforeach; else: ?><div class="empty-state"><i class="bi bi-check-circle-fill"></i><p>No active incidents</p></div><?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
new Chart(document.getElementById('severityChart'), {
  type: 'doughnut',
  data: { labels: <?=json_encode($severity_labels)?>, datasets: [{ data: <?=json_encode($severity_counts)?>, backgroundColor: ['#4ade80','#fbbf24','#fb923c','#f87171'], borderWidth: 0, hoverOffset: 8 }] },
  options: { responsive: true, maintainAspectRatio: true, cutout: '68%', plugins: { legend: { position: 'bottom', labels: { color: '#6b7280', font: { size: 10, family: "'IBM Plex Mono', monospace" }, usePointStyle: true } } } }
});
</script>
</body>
</html>