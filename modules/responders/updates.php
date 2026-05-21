<?php
/**
 * Field Status Updates
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Allows responders to post real-time field updates, photos, and status changes
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

if (!isLoggedIn()) redirect('modules/auth/login.php');
if (!hasRole(['responder', 'admin'])) redirect('index.php');

$user_id = $_SESSION['user_id'];
$error = $success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'post_update') {
    $incident_id = (int)$_POST['incident_id'];
    $update_text = trim($_POST['update_text']);
    $status = $_POST['status'] ?? null;
    $photo_path = null;
    
    if (empty($update_text) && empty($_FILES['photo']['name'])) {
        $error = "Please enter an update or attach a photo.";
    } else {
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../temp/uploads/updates/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $filename = 'update_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $filename)) {
                $photo_path = '/temp/uploads/updates/' . $filename;
            }
        }
        
        $stmt = $pdo->prepare("INSERT INTO field_updates (responder_id, incident_id, update_text, status, photo_path, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($stmt->execute([$user_id, $incident_id, $update_text, $status, $photo_path])) {
            $success = "Field update posted successfully!";
            if ($status) {
                $stmt = $pdo->prepare("UPDATE incidents SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$status, $incident_id]);
            }
        } else {
            $error = "Failed to post update.";
        }
    }
}

$stmt = $pdo->prepare("SELECT i.id, i.incident_type, i.location_name, i.status FROM incidents i WHERE i.status NOT IN ('resolved', 'cancelled', 'rejected') ORDER BY i.severity DESC, i.reported_at DESC");
$stmt->execute();
$active_incidents = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT fu.*, u.full_name as responder_name, i.incident_type, i.location_name FROM field_updates fu JOIN users u ON fu.responder_id = u.id LEFT JOIN incidents i ON fu.incident_id = i.id ORDER BY fu.created_at DESC LIMIT 50");
$stmt->execute();
$updates = $stmt->fetchAll();

$status_options = [
    'en_route' => '🚑 En Route', 'arrived' => '📍 Arrived on Scene', 'assessing' => '🔍 Assessing Situation',
    'in_progress' => '🚨 Rescue in Progress', 'awaiting_resources' => '⏳ Awaiting Resources',
    'stabilized' => '✅ Situation Stabilized', 'resolved' => '✔️ Resolved'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Field Updates — DisasterResponse</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Barlow:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{--bg:#f0f2f5;--surface:#fff;--surface-2:#f7f8fa;--border:#e2e5ea;--navy:#0f1b2d;--red:#e8271d;--red-light:#fff0ef;--amber:#d97706;--blue:#1d6ef5;--green:#16a34a;--text:#0f1b2d;--muted:#6b7280;--ff-head:'Barlow Condensed',sans-serif;--ff-body:'Barlow',sans-serif;--ff-mono:'IBM Plex Mono',monospace;--r:8px;--r-lg:12px;--shadow:0 1px 3px rgba(15,27,45,.08),0 4px 16px rgba(15,27,45,.06);--ease:.18s ease}
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
.form-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--shadow);margin-bottom:1rem;overflow:hidden}
.card-header{background:var(--surface-2);border-bottom:1px solid var(--border);padding:.85rem 1.25rem;font-family:var(--ff-head);font-weight:700;font-size:.8rem;text-transform:uppercase;display:flex;align-items:center;gap:.5rem}
.card-header i{color:var(--red)}
.form-body{padding:1.25rem}
.form-label{font-family:var(--ff-head);font-size:.7rem;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:.3rem;display:block}
.form-select,.form-textarea{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r);padding:.6rem .8rem;font-size:.85rem;width:100%;outline:none}
.form-textarea{resize:vertical;min-height:100px}
.btn-submit{background:var(--red);border:none;border-radius:30px;padding:.7rem 1.2rem;font-weight:700;font-size:.8rem;text-transform:uppercase;color:#fff}
.btn-submit:hover{background:#c82333}
.update-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);margin-bottom:.75rem;box-shadow:var(--shadow);transition:all var(--ease)}
.update-card:hover{border-color:var(--red)}
.update-photo{max-height:200px;object-fit:cover;border-radius:var(--r);width:100%;margin-top:.5rem}
.timeline-dot{width:8px;height:8px;background:var(--red);border-radius:50%;display:inline-block;margin-right:10px}
.empty-state{text-align:center;padding:3rem;color:var(--muted-2)}
.empty-state i{font-size:2.5rem;margin-bottom:.75rem;opacity:.3}
@media(max-width:768px){.hero-title{font-size:1.35rem}}
</style>
</head>
<body>

<div class="topbar">
  <a class="brand" href="responders_dashboard.php">
    <i class="bi bi-chat-dots-fill" style="color:#fff"></i>
    <div><span class="brand-text">DisasterResponse</span><span class="brand-sub">Field Comms</span></div>
  </a>
  <div class="nav-area">
    <a href="responders_dashboard.php" class="npill"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="team.php" class="npill"><i class="bi bi-people"></i> Team</a>
    <a href="updates.php" class="npill active"><i class="bi bi-chat-dots"></i> Updates</a>
    <a href="../mapping/map.php" class="npill"><i class="bi bi-map"></i> Live Map</a>
  </div>
  <a href="../auth/logout.php" class="logout-btn" onclick="return confirm('Sign out?')"><i class="bi bi-box-arrow-right"></i> Logout</a>
</div>

<div class="page-hero">
  <div class="container">
    <div class="hero-eyebrow"><i class="bi bi-chat-dots-fill me-1"></i>Situational Awareness</div>
    <div class="hero-title">Field Updates</div>
    <div class="hero-sub">Real-time status updates from responders in the field</div>
  </div>
</div>

<div class="page">
  <div class="form-card">
    <div class="card-header"><i class="bi bi-pencil-square"></i> Post Field Update</div>
    <div class="form-body">
      <?php if($error):?><div class="alert alert-danger" style="background:var(--red-light);color:var(--red);border:1px solid rgba(232,39,29,.2);border-radius:var(--r);padding:.8rem;margin-bottom:1rem"><?=htmlspecialchars($error)?></div><?php endif;?>
      <?php if($success):?><div class="alert alert-success" style="background:#f0fdf4;color:#16a34a;border:1px solid rgba(22,163,74,.2);border-radius:var(--r);padding:.8rem;margin-bottom:1rem"><?=htmlspecialchars($success)?></div><?php endif;?>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="post_update">
        <div class="row g-3 mb-3">
          <div class="col-md-6"><label class="form-label">Incident</label><select name="incident_id" class="form-select" required><option value="">Select Incident</option><?php foreach($active_incidents as $inc):?><option value="<?=$inc['id']?>">#<?=str_pad($inc['id'],5,'0',STR_PAD_LEFT)?> - <?=ucfirst($inc['incident_type'])?> - <?=htmlspecialchars($inc['location_name']??'Unknown')?></option><?php endforeach;?></select></div>
          <div class="col-md-6"><label class="form-label">Status Update</label><select name="status" class="form-select"><option value="">No status change</option><?php foreach($status_options as $k=>$l):?><option value="<?=$k?>"><?=$l?></option><?php endforeach;?></select></div>
        </div>
        <div class="mb-3"><label class="form-label">Update Message</label><textarea name="update_text" class="form-textarea" placeholder="Describe the current situation, actions taken, resources needed..."></textarea></div>
        <div class="mb-3"><label class="form-label">Photo (Optional)</label><input type="file" name="photo" class="form-control" style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--r);padding:.5rem" accept="image/*"><small class="text-muted">Upload a photo from the field</small></div>
        <button type="submit" class="btn-submit"><i class="bi bi-send me-2"></i>Post Update</button>
      </form>
    </div>
  </div>

  <div class="d-flex align-items-center gap-2 mb-3"><i class="bi bi-clock-history text-danger"></i><h5 class="mb-0">Recent Field Updates</h5><span class="badge bg-secondary ms-2"><?=count($updates)?> updates</span></div>

  <?php if(count($updates)>0): foreach($updates as $update): ?>
  <div class="update-card p-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div><strong><i class="bi bi-person-circle me-1"></i><?=htmlspecialchars($update['responder_name'])?></strong><?php if($update['incident_id']):?><span class="badge bg-secondary ms-2">Incident #<?=str_pad($update['incident_id'],5,'0',STR_PAD_LEFT)?></span><?php endif;?><?php if($update['status'] && isset($status_options[$update['status']])):?><span class="badge ms-1" style="background:<?=$update['status']=='resolved'?'#16a34a':'#d97706'?>20;color:<?=$update['status']=='resolved'?'#16a34a':'#d97706'?>;border:1px solid <?=$update['status']=='resolved'?'rgba(22,163,74,.2)':'rgba(217,119,6,.2)'?>"><?=$status_options[$update['status']]?></span><?php endif;?></div>
      <small class="text-muted"><i class="bi bi-clock me-1"></i><?=date('M j, H:i',strtotime($update['created_at']))?></small>
    </div>
    <?php if($update['update_text']):?><p class="mt-2 mb-2 small text-muted"><?=nl2br(htmlspecialchars($update['update_text']))?></p><?php endif;?>
    <?php if($update['photo_path']):?><img src="<?=$update['photo_path']?>" class="update-photo" alt="Field update photo"><?php endif;?>
    <?php if($update['location_name']):?><div class="mt-2 small text-muted"><i class="bi bi-geo-alt me-1"></i><?=htmlspecialchars($update['location_name'])?></div><?php endif;?>
  </div>
  <?php endforeach; else: ?>
  <div class="empty-state"><i class="bi bi-chat-dots"></i><p>No field updates yet. Be the first to post an update!</p><a href="#" class="btn btn-danger btn-sm rounded-pill mt-2">Post First Update</a></div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>