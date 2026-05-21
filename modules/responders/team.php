<?php
/**
 * Responder Team Management
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Displays team members, allows location sharing, and coordinate team efforts
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

if (!isLoggedIn()) redirect('modules/auth/login.php');
if (!hasRole(['responder', 'admin'])) redirect('index.php');

$user_id = $_SESSION['user_id'];

// Handle location update (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'update_location') {
        $latitude = (float)$_POST['latitude'];
        $longitude = (float)$_POST['longitude'];
        $accuracy = (int)($_POST['accuracy'] ?? 0);
        
        $stmt = $pdo->prepare("
            INSERT INTO responder_locations (responder_id, latitude, longitude, accuracy, last_update)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE latitude = ?, longitude = ?, accuracy = ?, last_update = NOW()
        ");
        $stmt->execute([$user_id, $latitude, $longitude, $accuracy, $latitude, $longitude, $accuracy]);
        
        echo json_encode(['success' => true, 'message' => 'Location updated']);
        exit;
    }
    
    if ($_POST['action'] === 'get_team_locations') {
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.phone, rl.latitude, rl.longitude, rl.accuracy, rl.last_update,
                   TIMESTAMPDIFF(MINUTE, rl.last_update, NOW()) as minutes_ago
            FROM users u LEFT JOIN responder_locations rl ON u.id = rl.responder_id
            WHERE u.role = 'responder' AND u.id != ? ORDER BY u.full_name
        ");
        $stmt->execute([$user_id]);
        echo json_encode($stmt->fetchAll());
        exit;
    }
}

$stmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.phone, u.email,
           rl.latitude, rl.longitude, rl.accuracy, rl.last_update,
           TIMESTAMPDIFF(MINUTE, rl.last_update, NOW()) as minutes_ago
    FROM users u LEFT JOIN responder_locations rl ON u.id = rl.responder_id
    WHERE u.role = 'responder' AND u.id != ? ORDER BY u.full_name
");
$stmt->execute([$user_id]);
$team_members = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT latitude, longitude, last_update, TIMESTAMPDIFF(MINUTE, last_update, NOW()) as minutes_ago FROM responder_locations WHERE responder_id = ?");
$stmt->execute([$user_id]);
$my_location = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Team Management — DisasterResponse</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
:root {
  --bg: #f0f2f5; --surface: #ffffff; --surface-2: #f7f8fa; --border: #e2e5ea;
  --navy: #0f1b2d; --red: #e8271d; --red-light: #fff0ef; --amber: #d97706;
  --blue: #1d6ef5; --green: #16a34a; --text: #0f1b2d; --muted: #6b7280;
  --ff-head: 'Barlow Condensed', sans-serif; --ff-body: 'Barlow', sans-serif;
  --ff-mono: 'IBM Plex Mono', monospace; --r: 8px; --r-lg: 12px;
  --shadow: 0 1px 3px rgba(15,27,45,.08), 0 4px 16px rgba(15,27,45,.06);
  --ease: .18s ease;
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
.my-location-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:1rem;margin-bottom:1rem;box-shadow:var(--shadow)}
#map{height:380px;border-radius:var(--r-lg);margin-bottom:.5rem}
.team-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);margin-bottom:.75rem;cursor:pointer;transition:all var(--ease)}
.team-card:hover{transform:translateX(4px);border-color:var(--red);box-shadow:var(--shadow)}
.status-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px}
.status-active{background:var(--green);box-shadow:0 0 6px var(--green)}
.status-stale{background:var(--amber);box-shadow:0 0 6px var(--amber)}
.status-offline{background:#94a3b8}
@keyframes pulse{0%{opacity:1}50%{opacity:.5}100%{opacity:1}}
.pulse{animation:pulse 2s infinite}
@media(max-width:768px){.hero-title{font-size:1.35rem}#map{height:280px}}
</style>
</head>
<body>

<div class="topbar">
  <a class="brand" href="responders_dashboard.php">
    <i class="bi bi-people-fill" style="color:#fff"></i>
    <div><span class="brand-text">DisasterResponse</span><span class="brand-sub">Team Hub</span></div>
  </a>
  <div class="nav-area">
    <a href="responders_dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="team.php" class="npill active"><i class="bi bi-people"></i> Team</a>
    <a href="updates.php" class="npill"><i class="bi bi-chat-dots"></i> Updates</a>
    <a href="../mapping/map.php" class="npill"><i class="bi bi-map"></i> Live Map</a>
  </div>
  <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</div>

<div class="page-hero">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-people-fill me-1"></i>Coordination Hub</div>
    <div class="hero-title">Team Management</div>
    <div class="hero-sub">Track team locations and coordinate response efforts</div>
  </div>
</div>

<div class="page">
  <div class="my-location-card">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div><h6 class="mb-0"><i class="bi bi-geo-alt-fill text-danger me-1"></i>My Location</h6><div id="locationStatus" class="small text-muted"><span class="status-dot status-active pulse"></span><span id="locationText">Sharing live location...</span></div></div>
      <button id="shareLocationBtn" class="btn btn-danger btn-sm rounded-pill"><i class="bi bi-send me-1"></i>Share Now</button>
    </div>
    <div id="coordsDisplay" class="small text-muted mt-2"></div>
  </div>

  <div class="row g-3">
    <div class="col-lg-7">
      <div style="background:var(--surface);border-radius:var(--r-lg);padding:.75rem;box-shadow:var(--shadow)">
        <div id="map"></div>
        <div class="mt-2 small text-muted"><i class="bi bi-info-circle me-1"></i>Green = Active (now), Yellow = Stale (5-30 min), Gray = Offline (>30 min)</div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0"><i class="bi bi-people-fill text-danger me-2"></i>Team Members</h5><span class="badge bg-secondary"><?=count($team_members)?> members</span></div>
      <?php if(count($team_members)>0): foreach($team_members as $member): 
        $is_active = ($member['minutes_ago']!==null && $member['minutes_ago']<=5);
        $is_stale = ($member['minutes_ago']!==null && $member['minutes_ago']>5 && $member['minutes_ago']<=30);
        $status_class = $is_active?'active':($is_stale?'stale':'offline');
        $status_text = $is_active?'Active now':($is_stale?$member['minutes_ago'].' min ago':'Offline');
      ?>
      <div class="team-card p-3" onclick="zoomToMember(<?=$member['latitude']??'null'?>, <?=$member['longitude']??'null'?>, '<?=htmlspecialchars($member['full_name'])?>')">
        <div class="d-flex justify-content-between">
          <div><div class="fw-semibold"><i class="bi bi-person-circle me-1"></i><?=htmlspecialchars($member['full_name'])?></div><div class="small text-muted"><i class="bi bi-telephone me-1"></i><?=htmlspecialchars($member['phone']??'No phone')?></div></div>
          <div class="text-end"><span class="status-dot status-<?=$status_class?>"></span><span class="small"><?=$status_text?></span></div>
        </div>
        <?php if($member['latitude']):?><div class="mt-2 small text-muted"><i class="bi bi-geo-alt me-1"></i><?=number_format($member['latitude'],6)?>, <?=number_format($member['longitude'],6)?></div>
        <?php else:?><div class="mt-2 small text-muted"><i class="bi bi-eye-slash me-1"></i>Location not shared</div><?php endif;?>
      </div>
      <?php endforeach; else: ?>
      <div class="text-center text-muted py-5"><i class="bi bi-people fs-1 d-block mb-2 opacity-25"></i><p>No other team members found.</p></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const map = L.map('map').setView([-1.2921,36.8219],12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(map);
let markerLayer = L.layerGroup().addTo(map);
let myMarker = null;
const iconActive = L.divIcon({html:'<div style="background:#16a34a;width:12px;height:12px;border-radius:50%;border:2px solid white;box-shadow:0 0 8px #16a34a"></div>',className:'',iconSize:[12,12],iconAnchor:[6,6]});
const iconStale = L.divIcon({html:'<div style="background:#d97706;width:12px;height:12px;border-radius:50%;border:2px solid white;box-shadow:0 0 8px #d97706"></div>',className:'',iconSize:[12,12],iconAnchor:[6,6]});
const iconOffline = L.divIcon({html:'<div style="background:#94a3b8;width:12px;height:12px;border-radius:50%;border:2px solid white"></div>',className:'',iconSize:[12,12],iconAnchor:[6,6]});
const myIcon = L.divIcon({html:'<div style="background:#e8271d;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 0 8px #e8271d"></div>',className:'',iconSize:[16,16],iconAnchor:[8,8]});
function fetchTeamLocations(){fetch('team.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=get_team_locations'}).then(r=>r.json()).then(team=>{markerLayer.clearLayers();team.forEach(m=>{if(m.latitude&&m.longitude){let icon=iconOffline;if(m.minutes_ago<=5)icon=iconActive;else if(m.minutes_ago<=30)icon=iconStale;L.marker([m.latitude,m.longitude],{icon}).bindPopup(`<strong>${m.full_name}</strong><br><small>📍 ${m.latitude.toFixed(6)}, ${m.longitude.toFixed(6)}</small><br><small>📱 ${m.phone||'No phone'}</small><br><small>🕐 ${m.minutes_ago<=5?'Active now':m.minutes_ago+' min ago'}</small>`).addTo(markerLayer);}});});}
function shareLocation(){if(!navigator.geolocation){alert('Geolocation not supported');return;}navigator.geolocation.getCurrentPosition(pos=>{const lat=pos.coords.latitude,lng=pos.coords.longitude,acc=Math.round(pos.coords.accuracy);fetch('team.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=update_location&latitude=${lat}&longitude=${lng}&accuracy=${acc}`}).then(r=>r.json()).then(data=>{if(data.success){document.getElementById('locationStatus').innerHTML='<span class="status-dot status-active pulse"></span><span>Location shared successfully!</span>';document.getElementById('coordsDisplay').innerHTML=`📍 ${lat.toFixed(6)}, ${lng.toFixed(6)} (accuracy: ±${acc}m)`;setTimeout(()=>{document.getElementById('locationStatus').innerHTML='<span class="status-dot status-active pulse"></span><span>Sharing live location...</span>';},3000);if(myMarker)map.removeLayer(myMarker);myMarker=L.marker([lat,lng],{icon:myIcon}).addTo(map);map.setView([lat,lng],14);fetchTeamLocations();}});},error=>{alert('Could not get location: '+error.message);});}
function zoomToMember(lat,lng,name){if(lat&&lng){map.setView([lat,lng],15);L.popup().setLatLng([lat,lng]).setContent(`<strong>${name}</strong>`).openOn(map);}else{alert(`${name} hasn't shared their location yet.`);}}
document.getElementById('shareLocationBtn').addEventListener('click',shareLocation);
fetchTeamLocations();
setInterval(fetchTeamLocations,30000);
</script>
</body>
</html>