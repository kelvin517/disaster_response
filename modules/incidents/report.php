<?php
/**
 * Emergency Incident Reporting Module - Styled Version
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

if (!isLoggedIn()) redirect('modules/auth/login.php');

define('UPLOAD_DIR', __DIR__ . '/../../temp/uploads/');
define('UPLOAD_URL', '/temp/uploads/');
define('MAX_FILE_BYTES', 5 * 1024 * 1024);
define('ALLOWED_MIME', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('DUPLICATE_RADIUS_KM', 0.5);
define('DUPLICATE_TIME_WINDOW', 30);

$incident_types = [
    'flood' => '🌊 Flood', 'fire' => '🔥 Fire', 'earthquake' => '🏚️ Earthquake',
    'landslide' => '⛰️ Landslide', 'drought' => '☀️ Drought', 'accident' => '🚗 Road Accident',
    'building_collapse' => '🏗️ Building Collapse', 'disease_outbreak' => '🦠 Disease Outbreak', 'other' => '⚠️ Other'
];
$severity_labels = [1 => ['label' => 'Low', 'color' => 'success', 'icon' => '🟢'], 2 => ['label' => 'Medium', 'color' => 'warning', 'icon' => '🟡'], 3 => ['label' => 'High', 'color' => 'danger', 'icon' => '🟠'], 4 => ['label' => 'Critical', 'color' => 'dark', 'icon' => '🔴']];

$errors = []; $success = false; $incident_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please refresh.';
    } else {
        $type = trim($_POST['type'] ?? ''); $severity = (int)($_POST['severity'] ?? 0); $description = trim($_POST['description'] ?? '');
        $latitude = filter_var($_POST['latitude'] ?? '', FILTER_VALIDATE_FLOAT); $longitude = filter_var($_POST['longitude'] ?? '', FILTER_VALIDATE_FLOAT);
        $user_id = $_SESSION['user_id'] ?? 0; $location_name = trim($_POST['location_name'] ?? '');
        if (!array_key_exists($type, $incident_types)) $errors[] = 'Select valid incident type.';
        if (!array_key_exists($severity, $severity_labels)) $errors[] = 'Select valid severity level.';
        if ($latitude === false || $latitude < -90 || $latitude > 90) $errors[] = 'Invalid latitude.';
        if ($longitude === false || $longitude < -180 || $longitude > 180) $errors[] = 'Invalid longitude.';
        
        $photo_path = null;
        if (!empty($_FILES['photo']['name']) && empty($errors)) {
            $file = $_FILES['photo'];
            if ($file['error'] !== UPLOAD_ERR_OK) $errors[] = 'Photo upload failed.';
            elseif ($file['size'] > MAX_FILE_BYTES) $errors[] = 'Photo must be under 5 MB.';
            else {
                $mime = mime_content_type($file['tmp_name']);
                if (!in_array($mime, ALLOWED_MIME)) $errors[] = 'Invalid image type.';
                else {
                    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
                    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = uniqid('inc_', true) . '.' . strtolower($ext);
                    if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) $photo_path = UPLOAD_URL . $filename;
                    else $errors[] = 'Could not save photo.';
                }
            }
        }
        
        if (empty($errors)) {
            try {
                $columns = $pdo->query("DESCRIBE incidents")->fetchAll(PDO::FETCH_COLUMN);
                $fields = []; $values = []; $params = [];
                if (in_array('reporter_id', $columns)) { $fields[] = 'reporter_id'; $values[] = ':reporter_id'; $params[':reporter_id'] = $user_id; }
                if (in_array('incident_type', $columns)) { $fields[] = 'incident_type'; $values[] = ':incident_type'; $params[':incident_type'] = $type; }
                if (in_array('severity', $columns)) { $fields[] = 'severity'; $values[] = ':severity'; $params[':severity'] = $severity; }
                if (in_array('description', $columns)) { $fields[] = 'description'; $values[] = ':description'; $params[':description'] = $description; }
                if (in_array('latitude', $columns)) { $fields[] = 'latitude'; $values[] = ':latitude'; $params[':latitude'] = $latitude; }
                if (in_array('longitude', $columns)) { $fields[] = 'longitude'; $values[] = ':longitude'; $params[':longitude'] = $longitude; }
                if (in_array('location_name', $columns)) { $fields[] = 'location_name'; $values[] = ':location_name'; $params[':location_name'] = $location_name; }
                if (in_array('photo_path', $columns)) { $fields[] = 'photo_path'; $values[] = ':photo_path'; $params[':photo_path'] = $photo_path; }
                if (in_array('status', $columns)) { $fields[] = 'status'; $values[] = ':status'; $params[':status'] = 'reported'; }
                if (in_array('reported_at', $columns)) { $fields[] = 'reported_at'; $values[] = 'NOW()'; }
                $sql = "INSERT INTO incidents (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";
                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $value) $stmt->bindValue($key, $value);
                if ($stmt->execute()) { $incident_id = $pdo->lastInsertId(); $success = true; }
                else $errors[] = 'Database error occurred.';
            } catch (PDOException $e) { $errors[] = 'Database error: ' . $e->getMessage(); }
        }
    }
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Report Emergency — DisasterResponse</title>
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
body{font-family:var(--ff-body);background:var(--bg);color:var(--text);font-size:14px;min-height:100vh}
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
.page-hero{background:var(--navy);padding:1.8rem 0;border-bottom:3px solid var(--red);position:relative;overflow:hidden}
.page-hero::before{content:'';position:absolute;right:-60px;top:-60px;width:280px;height:280px;background:radial-gradient(circle,rgba(232,39,29,.12) 0%,transparent 65%);pointer-events:none}
.hero-eyebrow{font-family:var(--ff-mono);font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:var(--red);margin-bottom:.3rem}
.hero-title{font-family:var(--ff-head);font-weight:800;font-size:1.8rem;color:#fff;text-transform:uppercase}
.hero-sub{color:rgba(255,255,255,.45);font-size:.8rem}
.page{max-width:900px;margin:0 auto;padding:1.5rem 1.25rem 4rem}
.form-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--shadow);margin-bottom:1rem;overflow:hidden}
.card-header{background:var(--surface-2);border-bottom:1px solid var(--border);padding:.8rem 1.25rem;font-family:var(--ff-head);font-weight:700;font-size:.8rem;text-transform:uppercase;display:flex;align-items:center;gap:.6rem}
.card-header i{color:var(--red)}
.card-body{padding:1.25rem}
.form-label{font-family:var(--ff-head);font-size:.7rem;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:.3rem;display:block}
.form-input,.form-select,.form-textarea{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r);padding:.6rem .8rem;font-size:.85rem;width:100%;outline:none}
.form-textarea{resize:vertical;min-height:100px}
.btn-submit{background:var(--red);border:none;border-radius:40px;padding:.85rem;font-weight:700;font-size:.9rem;text-transform:uppercase;width:100%;transition:all var(--ease)}
.btn-submit:hover{background:#c82333;transform:translateY(-1px)}
.btn-cancel{background:transparent;border:1px solid var(--border);border-radius:40px;padding:.7rem;font-size:.8rem;text-align:center;text-decoration:none;color:var(--muted);display:block;transition:all var(--ease)}
.btn-cancel:hover{border-color:var(--red);color:var(--red);background:var(--red-light)}
.location-map{height:220px;border-radius:var(--r-lg);margin-top:.5rem;overflow:hidden}
.gps-status{border-radius:var(--r);padding:.7rem;margin-bottom:1rem}
.type-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem}
@media(max-width:768px){.type-grid{grid-template-columns:repeat(2,1fr)}}
.type-btn{padding:.6rem .3rem;text-align:center;border-radius:var(--r);cursor:pointer;transition:all var(--ease);border:1px solid var(--border);background:var(--surface)}
.type-btn.selected{background:var(--red);color:#fff;border-color:var(--red)}
.severity-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.6rem}
.severity-btn{padding:.6rem .3rem;text-align:center;border-radius:var(--r);cursor:pointer;transition:all var(--ease);border:1px solid var(--border)}
.severity-btn.selected{background:var(--red);color:#fff;border-color:var(--red)}
.drop-zone{border:2px dashed var(--border);border-radius:var(--r-lg);padding:1.5rem;text-align:center;cursor:pointer;transition:all var(--ease);background:var(--surface-2)}
.drop-zone:hover{border-color:var(--red);background:var(--red-light)}
.preview-img{max-height:150px;object-fit:cover;border-radius:var(--r);width:100%}
.alert-custom{border-radius:var(--r);padding:.8rem 1rem;margin-bottom:1rem}
.alert-danger{background:var(--red-light);color:var(--red);border:1px solid rgba(232,39,29,.2)}
.alert-success{background:#f0fdf4;color:#16a34a;border:1px solid rgba(22,163,74,.2)}
.progress-timeline{display:flex;justify-content:space-between;margin-bottom:1.5rem;padding:1rem;background:var(--surface-2);border-radius:var(--r-lg)}
.timeline-step{text-align:center;flex:1;font-size:.7rem;color:var(--muted)}
.timeline-step.completed{color:var(--green)}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.form-card{animation:fadeUp .3s ease-out}
</style>
</head>
<body>

<div class="topbar">
  <a class="brand" href="/disaster_response/index.php">
    <i class="bi bi-megaphone-fill" style="color:#fff"></i>
    <div><span class="brand-text">DisasterResponse</span><span class="brand-sub">Report</span></div>
  </a>
  <div class="nav-area">
    <a href="/disaster_response/dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="../messaging/inbox.php" class="npill"><i class="bi bi-envelope"></i> Messages</a>
  </div>
  <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</div>

<div class="page-hero">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-megaphone-fill me-1"></i>Public Safety</div>
    <div class="hero-title">Report Emergency</div>
    <div class="hero-sub">Your report helps responders reach those in need faster.</div>
  </div>
</div>

<div class="page">
  <div class="progress-timeline">
    <div class="timeline-step completed">1️⃣ Report Submitted</div>
    <div class="timeline-step">2️⃣ Under Review</div>
    <div class="timeline-step">3️⃣ Responders Dispatched</div>
    <div class="timeline-step">4️⃣ Resolved</div>
  </div>

  <div class="alert alert-warning alert-custom" style="background:var(--amber-light);color:var(--amber);border:1px solid rgba(217,119,6,.2)">
    <i class="bi bi-telephone-fill me-2"></i>⚠️ Life-threatening emergency? Call <strong>999</strong> or <strong>112</strong> immediately.
  </div>

  <?php if($success): ?>
  <div class="alert alert-success alert-custom"><i class="bi bi-check-circle-fill me-2"></i><strong>Incident #<?=$incident_id?> reported successfully!</strong> Emergency responders have been notified. <a href="status.php" class="alert-link">Track your report →</a></div>
  <?php endif; ?>

  <?php if(!empty($errors)): ?>
  <div class="alert alert-danger alert-custom"><i class="bi bi-exclamation-octagon-fill me-2"></i><strong>Please fix:</strong><ul class="mb-0 mt-2"><?php foreach($errors as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
  <?php endif; ?>

  <form id="incidentForm" method="POST" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?=$csrf?>">
    <input type="hidden" name="latitude" id="latitude">
    <input type="hidden" name="longitude" id="longitude">

    <!-- Location Card -->
    <div class="form-card">
      <div class="card-header"><i class="bi bi-geo-alt-fill"></i> Location <span class="badge bg-danger ms-2">Required</span></div>
      <div class="card-body">
        <div id="gpsStatus" class="gps-status alert alert-secondary d-flex align-items-center gap-2"><div class="spinner-border spinner-border-sm"></div><span>Detecting your location…</span></div>
        <div id="locationMap" class="location-map" style="display:none"></div>
        <div class="mb-3"><label class="form-label">Location Name</label><input type="text" name="location_name" class="form-input" placeholder="e.g., Mathare, Section 1"></div>
        <div class="row g-2"><div class="col-6"><label class="form-label">Latitude</label><input type="text" id="latDisplay" class="form-input" readonly placeholder="Waiting..."></div><div class="col-6"><label class="form-label">Longitude</label><input type="text" id="lngDisplay" class="form-input" readonly placeholder="Waiting..."></div></div>
        <button type="button" id="retryGps" class="btn btn-outline-secondary btn-sm mt-3 rounded-pill d-none"><i class="bi bi-arrow-clockwise"></i> Retry GPS</button>
        <div id="manualEntry" class="mt-3 p-3" style="background:var(--surface-2);border-radius:var(--r);display:none"><div class="mb-2"><i class="bi bi-pencil-square"></i> Enter coordinates manually</div><div class="row g-2"><div class="col-6"><input type="number" id="manualLat" class="form-input" placeholder="-1.2921"></div><div class="col-6"><input type="number" id="manualLng" class="form-input" placeholder="36.8219"></div></div><button type="button" id="applyManual" class="btn btn-warning btn-sm mt-2 rounded-pill">Apply</button></div>
      </div>
    </div>

    <!-- Incident Type Card -->
    <div class="form-card">
      <div class="card-header"><i class="bi bi-list-ul"></i> Incident Type <span class="badge bg-danger ms-2">Required</span></div>
      <div class="card-body">
        <div class="type-grid">
          <?php foreach($incident_types as $value => $label): ?>
          <div class="type-btn" data-type="<?=$value?>"><?=$label?></div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="type" id="incidentType" required>
      </div>
    </div>

    <!-- Severity Card -->
    <div class="form-card">
      <div class="card-header"><i class="bi bi-thermometer-half"></i> Severity Level <span class="badge bg-danger ms-2">Required</span></div>
      <div class="card-body">
        <div class="severity-grid">
          <?php foreach($severity_labels as $val => $meta): ?>
          <div class="severity-btn" data-severity="<?=$val?>"><?=$meta['icon']?> <?=$meta['label']?></div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="severity" id="severityLevel" required>
        <div class="mt-3 small text-muted"><i class="bi bi-info-circle"></i> <strong>Critical</strong> = immediate threat to life | <strong>High</strong> = serious injuries | <strong>Medium</strong> = contained | <strong>Low</strong> = minor</div>
      </div>
    </div>

    <!-- Description Card -->
    <div class="form-card">
      <div class="card-header"><i class="bi bi-chat-left-text"></i> Description</div>
      <div class="card-body">
        <textarea name="description" id="description" class="form-textarea" rows="4" maxlength="1000" placeholder="Describe what is happening: number of people affected, injuries, road conditions, specific hazards, urgent needs…"></textarea>
        <div class="text-end mt-1"><small class="text-muted"><span id="charCount">0</span> / 1000 characters</small></div>
      </div>
    </div>

    <!-- Photo Card -->
    <div class="form-card">
      <div class="card-header"><i class="bi bi-camera-fill"></i> Photo Evidence <span class="badge bg-secondary ms-2">Optional</span></div>
      <div class="card-body">
        <div id="dropZone" class="drop-zone">
          <i class="bi bi-cloud-upload fs-1 d-block mb-2"></i>
          <p class="mb-1">Drag & drop a photo, or <strong class="text-danger">click to browse</strong></p>
          <small class="text-muted">JPEG, PNG, WebP, GIF — max 5 MB</small>
          <input type="file" id="photoInput" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" class="d-none">
        </div>
        <div id="photoPreview" class="mt-3 d-none text-center"><img id="previewImg" src="" alt="Preview" class="preview-img"><br><button type="button" id="removePhoto" class="btn btn-sm btn-outline-danger mt-2 rounded-pill"><i class="bi bi-x-circle"></i> Remove</button></div>
      </div>
    </div>

    <div class="d-grid gap-2"><button type="submit" id="submitBtn" class="btn-submit"><i class="bi bi-send-fill me-2"></i>Submit Report</button><a href="/disaster_response/index.php" class="btn-cancel"><i class="bi bi-arrow-left me-2"></i>Cancel</a></div>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
let previewMap = null, previewMarker = null;

// Type selection
document.querySelectorAll('.type-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('selected'));
    this.classList.add('selected');
    document.getElementById('incidentType').value = this.dataset.type;
  });
});

// Severity selection
document.querySelectorAll('.severity-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.severity-btn').forEach(b => b.classList.remove('selected'));
    this.classList.add('selected');
    document.getElementById('severityLevel').value = this.dataset.severity;
  });
});

function setCoords(lat,lng,label){
  document.getElementById('latitude').value=lat;
  document.getElementById('longitude').value=lng;
  document.getElementById('latDisplay').value=lat.toFixed(6);
  document.getElementById('lngDisplay').value=lng.toFixed(6);
  const mapEl=document.getElementById('locationMap');
  mapEl.style.display='block';
  if(!previewMap){
    previewMap=L.map('locationMap').setView([lat,lng],15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(previewMap);
    previewMarker=L.marker([lat,lng],{draggable:true}).addTo(previewMap);
    previewMarker.bindPopup(label).openPopup();
    previewMarker.on('dragend',()=>{const pos=previewMarker.getLatLng();setCoords(pos.lat,pos.lng,'Adjusted');});
  } else {
    previewMap.setView([lat,lng],15);
    previewMarker.setLatLng([lat,lng]).openPopup();
    setTimeout(()=>previewMap.invalidateSize(),100);
  }
}

function gpsSuccess(pos){
  const lat=pos.coords.latitude,lng=pos.coords.longitude,acc=Math.round(pos.coords.accuracy);
  const status=document.getElementById('gpsStatus');
  status.className='gps-status alert alert-success d-flex align-items-center gap-2';
  status.innerHTML=`<i class="bi bi-check-circle-fill text-success"></i><span>Location captured <strong>${lat.toFixed(6)}, ${lng.toFixed(6)}</strong> <small>(±${acc}m)</small></span>`;
  document.getElementById('retryGps').classList.remove('d-none');
  setCoords(lat,lng,`Your location (±${acc}m)`);
}

function gpsError(err){
  const status=document.getElementById('gpsStatus');
  status.className='gps-status alert alert-warning d-flex align-items-center gap-2';
  status.innerHTML=`<i class="bi bi-exclamation-triangle-fill text-warning"></i><span>GPS unavailable: ${err.message}</span>`;
  document.getElementById('manualEntry').style.display='block';
  document.getElementById('retryGps').classList.remove('d-none');
}

function requestGPS(){
  if(!navigator.geolocation){gpsError({message:'Not supported'});return;}
  const status=document.getElementById('gpsStatus');
  status.className='gps-status alert alert-secondary d-flex align-items-center gap-2';
  status.innerHTML=`<div class="spinner-border spinner-border-sm"></div><span>Detecting location…</span>`;
  navigator.geolocation.getCurrentPosition(gpsSuccess,gpsError,{enableHighAccuracy:true,timeout:10000});
}

document.getElementById('retryGps').addEventListener('click',requestGPS);
document.getElementById('applyManual').addEventListener('click',function(){
  const lat=parseFloat(document.getElementById('manualLat').value),lng=parseFloat(document.getElementById('manualLng').value);
  if(isNaN(lat)||isNaN(lng)){alert('Invalid coordinates');return;}
  setCoords(lat,lng,'Manual');
  document.getElementById('gpsStatus').className='gps-status alert alert-info d-flex align-items-center gap-2';
  document.getElementById('gpsStatus').innerHTML='<i class="bi bi-pin-map-fill"></i> Using manual coordinates.';
});
requestGPS();

// Photo upload
const dropZone=document.getElementById('dropZone'),photoInput=document.getElementById('photoInput'),preview=document.getElementById('photoPreview'),previewImg=document.getElementById('previewImg');
function showPreview(file){if(!file||!file.type.startsWith('image/'))return;const reader=new FileReader();reader.onload=e=>{previewImg.src=e.target.result;preview.classList.remove('d-none');dropZone.style.display='none';};reader.readAsDataURL(file);}
dropZone.addEventListener('click',()=>photoInput.click());
dropZone.addEventListener('dragover',e=>{e.preventDefault();dropZone.classList.add('drag-over');});
dropZone.addEventListener('dragleave',()=>dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop',e=>{e.preventDefault();dropZone.classList.remove('drag-over');photoInput.files=e.dataTransfer.files;showPreview(e.dataTransfer.files[0]);});
photoInput.addEventListener('change',()=>showPreview(photoInput.files[0]));
document.getElementById('removePhoto').addEventListener('click',function(){photoInput.value='';preview.classList.add('d-none');dropZone.style.display='';});

// Character count
const desc=document.getElementById('description'),charCount=document.getElementById('charCount');
desc.addEventListener('input',()=>charCount.textContent=desc.value.length);

// Form validation
document.getElementById('incidentForm').addEventListener('submit',function(e){
  const lat=document.getElementById('latitude').value,lng=document.getElementById('longitude').value;
  if(!lat||!lng){e.preventDefault();alert('Location required. Please allow GPS access or enter coordinates manually.');return;}
  if(!document.getElementById('incidentType').value){e.preventDefault();alert('Please select an incident type.');return;}
  if(!document.getElementById('severityLevel').value){e.preventDefault();alert('Please select a severity level.');return;}
  const btn=document.getElementById('submitBtn');btn.disabled=true;btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';
});
</script>
</body>
</html>