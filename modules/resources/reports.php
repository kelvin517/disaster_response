<?php
/**
 * Resource Utilization Reports
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

role_guard(['responder', 'admin']);

$date_range = $_GET['range'] ?? '30';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime("-{$date_range} days"));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
if($date_range!=='custom'){$start_date=date('Y-m-d',strtotime("-{$date_range} days"));$end_date=date('Y-m-d');}

$stmt = $pdo->prepare("SELECT DATE(requested_at) as date, COUNT(*) as total, SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) as delivered FROM resource_requests WHERE DATE(requested_at) BETWEEN ? AND ? GROUP BY DATE(requested_at) ORDER BY date ASC");
$stmt->execute([$start_date,$end_date]);$trends=$stmt->fetchAll();
$trend_dates=[];$trend_totals=[];$trend_delivered=[];
foreach($trends as $t){$trend_dates[]=date('M j',strtotime($t['date']));$trend_totals[]=$t['total'];$trend_delivered[]=$t['delivered'];}

$stmt=$pdo->prepare("SELECT resource_type, COUNT(*) as request_count, SUM(quantity) as total_quantity, SUM(CASE WHEN status='delivered' THEN quantity ELSE 0 END) as delivered_quantity FROM resource_requests WHERE DATE(requested_at) BETWEEN ? AND ? GROUP BY resource_type ORDER BY request_count DESC");
$stmt->execute([$start_date,$end_date]);$type_distribution=$stmt->fetchAll();

$stmt=$pdo->prepare("SELECT urgency, COUNT(*) as count, ROUND(AVG(TIMESTAMPDIFF(HOUR,requested_at,CASE WHEN status='delivered' THEN updated_at ELSE NOW() END))) as avg_response_hours FROM resource_requests WHERE DATE(requested_at) BETWEEN ? AND ? GROUP BY urgency");
$stmt->execute([$start_date,$end_date]);$urgency_stats=$stmt->fetchAll();

$stmt=$pdo->prepare("SELECT status, COUNT(*) as count FROM resource_requests WHERE DATE(requested_at) BETWEEN ? AND ? GROUP BY status");
$stmt->execute([$start_date,$end_date]);$status_distribution=$stmt->fetchAll();

$resource_types=['food'=>'🍲 Food','water'=>'💧 Water','medicine'=>'💊 Medicine','shelter'=>'🏠 Shelter','clothing'=>'👕 Clothing','blankets'=>'🛏️ Blankets','first_aid'=>'🩹 First Aid','transport'=>'🚛 Transport','other'=>'📦 Other'];
$range_label=$date_range==='custom'?"$start_date → $end_date":"Last $date_range days";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resource Reports — DisasterResponse</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--bg:#f0f2f5;--surface:#fff;--surface-2:#f7f8fa;--border:#e2e5ea;--navy:#0f1b2d;--red:#e8271d;--amber:#d97706;--blue:#1d6ef5;--green:#16a34a;--text:#0f1b2d;--muted:#6b7280;--ff-head:'Barlow Condensed',sans-serif;--ff-body:'Barlow',sans-serif;--ff-mono:'IBM Plex Mono',monospace;--r:8px;--r-lg:12px;--shadow:0 1px 3px rgba(15,27,45,.08);--ease:.18s ease}
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
.page{max-width:1200px;margin:0 auto;padding:1.5rem 1.25rem 4rem}
.filter-bar{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1rem;margin-bottom:1.5rem;display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end}
.filter-bar select{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r);padding:.5rem .75rem;font-family:var(--ff-mono)}
.filter-btn{background:var(--red);border:none;border-radius:var(--r);padding:.5rem 1rem;color:#fff;font-weight:600}
.print-btn{margin-left:auto;background:transparent;border:1px solid var(--border);border-radius:var(--r);padding:.5rem 1rem}
.dashboard-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);margin-bottom:1.5rem;overflow:hidden}
.card-header{background:var(--surface-2);border-bottom:1px solid var(--border);padding:.8rem 1.25rem;font-weight:700;text-transform:uppercase;font-size:.8rem}
.chart-container{padding:1.25rem}
.summary-table{width:100%;border-collapse:collapse}
.summary-table th,.summary-table td{padding:.75rem 1rem;text-align:left;border-bottom:1px solid var(--border)}
.summary-table th{background:var(--surface-2);font-family:var(--ff-head);font-size:.7rem;text-transform:uppercase}
.progress{height:6px;background:var(--bg);border-radius:4px;overflow:hidden}
@media(max-width:768px){.hero-title{font-size:1.35rem}}
</style>
</head>
<body>

<nav class="topbar">
  <a class="brand" href="reports.php"><i class="bi bi-graph-up" style="color:#fff;font-size:1.1rem"></i><div><span class="brand-text">DisasterResponse</span><span class="brand-sub">Analytics</span></div></a>
  <div class="nav-area"><a href="manage.php" class="npill"><i class="bi bi-list-check"></i> Requests</a><a href="inventory.php" class="npill"><i class="bi bi-box-seam"></i> Inventory</a><a href="reports.php" class="npill active"><i class="bi bi-graph-up"></i> Reports</a></div>
  <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</nav>

<div class="page-hero">
  <div class="container"><div class="hero-eyebrow"><i class="bi bi-graph-up me-1"></i>Analytics Module</div><div class="hero-title">Resource Analytics</div><div class="hero-sub">Request trends, fulfillment rates &amp; utilization metrics</div></div>
</div>

<div class="page">
  <div class="filter-bar">
    <form method="GET" style="display:contents">
      <select name="range" onchange="this.form.submit()">
        <option value="7" <?=$date_range==7?'selected':''?>>Last 7 days</option>
        <option value="30" <?=$date_range==30?'selected':''?>>Last 30 days</option>
        <option value="90" <?=$date_range==90?'selected':''?>>Last 90 days</option>
        <option value="365" <?=$date_range==365?'selected':''?>>Last year</option>
        <option value="custom" <?=$date_range=='custom'?'selected':''?>>Custom</option>
      </select>
      <?php if($date_range=='custom'):?>
        <input type="date" name="start_date" value="<?=$start_date?>">
        <input type="date" name="end_date" value="<?=$end_date?>">
        <button type="submit" class="filter-btn">Apply</button>
      <?php endif;?>
    </form>
    <button class="print-btn" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
  </div>

  <div class="dashboard-card"><div class="card-header"><i class="bi bi-bar-chart-steps me-2"></i>Request Trends</div><div class="chart-container"><canvas id="trendsChart" height="250"></canvas></div></div>

  <div class="row g-3">
    <div class="col-lg-6"><div class="dashboard-card"><div class="card-header"><i class="bi bi-pie-chart me-2"></i>Requests by Type</div><div class="chart-container"><canvas id="typeChart" height="250"></canvas></div></div></div>
    <div class="col-lg-6"><div class="dashboard-card"><div class="card-header"><i class="bi bi-speedometer2 me-2"></i>Urgency Distribution</div><div class="chart-container"><canvas id="urgencyChart" height="250"></canvas></div></div></div>
  </div>

  <div class="dashboard-card"><div class="card-header"><i class="bi bi-pie-chart-fill me-2"></i>Request Status</div><div class="chart-container"><canvas id="statusChart" height="200"></canvas></div></div>

  <div class="dashboard-card"><div class="card-header"><i class="bi bi-table me-2"></i>Resource Type Summary</div>
    <div class="table-responsive"><table class="summary-table"><thead><tr><th>Resource</th><th>Requests</th><th>Total Qty</th><th>Delivered</th><th>Rate</th></tr></thead><tbody>
    <?php foreach($type_distribution as $t): $rate=$t['total_quantity']>0?round(($t['delivered_quantity']/$t['total_quantity'])*100):0;?>
      <tr><td><?=$resource_types[$t['resource_type']]??$t['resource_type']?></td><td><?=$t['request_count']?></td><td><?=number_format($t['total_quantity'])?></td><td><?=number_format($t['delivered_quantity'])?></td><td><div class="progress" style="width:100px"><div class="progress-bar bg-success" style="width:<?=$rate?>%"></div></div><small><?=$rate?>%</small></td></tr>
    <?php endforeach;?>
    </tbody></table></div>
  </div>
</div>

<script>
new Chart(document.getElementById('trendsChart'),{type:'line',data:{labels:<?=json_encode($trend_dates)?>,datasets:[{label:'Total',data:<?=json_encode($trend_totals)?>,borderColor:'#e8271d',fill:true},{label:'Delivered',data:<?=json_encode($trend_delivered)?>,borderColor:'#16a34a',fill:true}]},options:{responsive:true}});
new Chart(document.getElementById('typeChart'),{type:'doughnut',data:{labels:<?=json_encode(array_column($type_distribution,'resource_type'))?>,datasets:[{data:<?=json_encode(array_column($type_distribution,'request_count'))?>,backgroundColor:['#e8271d','#d97706','#eab308','#16a34a','#0891b2','#7c3aed']}]},options:{responsive:true,plugins:{legend:{position:'bottom'}}}});
new Chart(document.getElementById('urgencyChart'),{type:'bar',data:{labels:<?=json_encode(array_column($urgency_stats,'urgency'))?>,datasets:[{label:'Requests',data:<?=json_encode(array_column($urgency_stats,'count'))?>,backgroundColor:'#e8271d'},{label:'Response (hrs)',data:<?=json_encode(array_column($urgency_stats,'avg_response_hours'))?>,backgroundColor:'#1d6ef5',yAxisID:'y1'}]},options:{responsive:true,scales:{y1:{position:'right'}}}});
new Chart(document.getElementById('statusChart'),{type:'doughnut',data:{labels:<?=json_encode(array_column($status_distribution,'status'))?>,datasets:[{data:<?=json_encode(array_column($status_distribution,'count'))?>,backgroundColor:['#f59e0b','#1d6ef5','#16a34a','#e8271d']}]},options:{responsive:true,plugins:{legend:{position:'bottom'}}}});
</script>
</body>
</html>