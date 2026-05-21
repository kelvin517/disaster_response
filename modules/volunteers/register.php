<?php
/**
 * Volunteer Registration Module
 * Disaster Response & Resource Coordination System
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

if (!isLoggedIn()) redirect('modules/auth/login.php');
if (!hasRole(['volunteer', 'admin'])) redirect('index.php');

$user_id = $_SESSION['user_id'];
$error = null;
$success = null;

$stmt = $pdo->prepare("SELECT * FROM volunteers WHERE user_id = ?");
$stmt->execute([$user_id]);
$existing_profile = $stmt->fetch();

$skill_options = [
    'medical'        => ['icon' => 'fa-kit-medical',        'label' => 'Medical & First Aid'],
    'rescue'         => ['icon' => 'fa-person-running',      'label' => 'Search & Rescue'],
    'logistics'      => ['icon' => 'fa-truck',               'label' => 'Logistics & Supply'],
    'communication'  => ['icon' => 'fa-tower-broadcast',     'label' => 'Communication & Radio'],
    'counseling'     => ['icon' => 'fa-handshake-angle',     'label' => 'Psychological First Aid'],
    'driving'        => ['icon' => 'fa-car',                 'label' => 'Emergency Driving'],
    'construction'   => ['icon' => 'fa-helmet-safety',       'label' => 'Construction & Debris'],
    'catering'       => ['icon' => 'fa-utensils',            'label' => 'Food Preparation'],
    'administration' => ['icon' => 'fa-clipboard-list',      'label' => 'Admin & Coordination'],
    'translation'    => ['icon' => 'fa-language',            'label' => 'Translation Services'],
    'other'          => ['icon' => 'fa-plus',                'label' => 'Other Skills'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'register_volunteer') {
        $skills = isset($_POST['skills']) ? implode(',', array_map('trim', (array)$_POST['skills'])) : '';
        $availability_status = $_POST['availability_status'] ?? 'available';
        $latitude  = (float)($_POST['latitude'] ?? 0);
        $longitude = (float)($_POST['longitude'] ?? 0);
        $experience_years = (int)($_POST['experience_years'] ?? 0);
        $certifications   = trim($_POST['certifications'] ?? '');
        $phone_emergency  = trim($_POST['phone_emergency'] ?? '');

        if (empty($skills)) {
            $error = "Please select at least one skill.";
        } else {
            try {
                if ($existing_profile) {
                    $stmt = $pdo->prepare("UPDATE volunteers SET skills=?, availability_status=?, latitude=?, longitude=?, experience_years=?, certifications=?, phone_emergency=?, updated_at=NOW() WHERE user_id=?");
                    $stmt->execute([$skills, $availability_status, $latitude, $longitude, $experience_years, $certifications, $phone_emergency, $user_id]);
                    $success = "Your volunteer profile has been updated successfully!";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO volunteers (user_id, skills, availability_status, latitude, longitude, experience_years, certifications, phone_emergency, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
                    $stmt->execute([$user_id, $skills, $availability_status, $latitude, $longitude, $experience_years, $certifications, $phone_emergency]);
                    $success = "You have successfully registered as a volunteer!";
                }
                $stmt = $pdo->prepare("SELECT * FROM volunteers WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $existing_profile = $stmt->fetch();
            } catch (PDOException $e) {
                error_log("Volunteer registration failed: " . $e->getMessage());
                $error = "Failed to save profile. Please try again.";
            }
        }
    }
}

$skills_array       = $existing_profile ? explode(',', $existing_profile['skills']) : [];
$availability_status = $existing_profile['availability_status'] ?? 'available';
$experience_years   = $existing_profile['experience_years'] ?? 0;
$certifications     = $existing_profile['certifications'] ?? '';
$phone_emergency    = $existing_profile['phone_emergency'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $existing_profile ? 'Update Profile' : 'Volunteer Registration' ?> — DisasterResponse</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root {
            --black: #080808;
            --surface: #111111;
            --card: #161616;
            --card2: #1C1C1C;
            --border: rgba(255,255,255,0.07);
            --border-hover: rgba(255,255,255,0.13);
            --red: #E8271A;
            --red-dim: rgba(232,39,26,0.1);
            --red-border: rgba(232,39,26,0.28);
            --green: #16A34A;
            --green-dim: rgba(22,163,74,0.1);
            --green-border: rgba(22,163,74,0.25);
            --amber: #D97706;
            --amber-dim: rgba(217,119,6,0.1);
            --amber-border: rgba(217,119,6,0.22);
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
            position: sticky; top: 0; z-index: 200;
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 32px;
            background: rgba(8,8,8,0.92);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
        }
        .nav-brand {
            font-family: var(--heading);
            font-size: 1.5rem; letter-spacing: 0.06em;
            color: var(--red); text-decoration: none;
            display: flex; align-items: center; gap: 8px;
        }
        .nav-brand span { color: var(--text); }
        .nav-right { display: flex; align-items: center; gap: 6px; }
        .nav-link-pill {
            font-size: 0.75rem; font-weight: 500;
            letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--muted); text-decoration: none;
            padding: 7px 14px; border-radius: 6px;
            border: 1px solid transparent;
            transition: all 0.18s;
        }
        .nav-link-pill:hover { color: var(--text); border-color: var(--border); background: var(--card); }
        .nav-link-pill.active { color: var(--text); border-color: var(--border); background: var(--card); }
        .nav-link-pill.danger:hover { color: var(--red); border-color: var(--red-border); background: var(--red-dim); }

        /* ─── PAGE HEADER ─── */
        .page-header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 36px 32px 32px;
            position: relative;
            overflow: hidden;
        }
        .page-header::after {
            content: '';
            position: absolute; right: 0; top: 0; bottom: 0;
            width: 40%;
            background: radial-gradient(ellipse at right center, rgba(232,39,26,0.07) 0%, transparent 65%);
            pointer-events: none;
        }
        .page-header-inner { max-width: 1200px; margin: 0 auto; position: relative; z-index: 1; }
        .eyebrow {
            font-family: var(--mono);
            font-size: 0.65rem; letter-spacing: 0.2em;
            text-transform: uppercase; color: var(--red);
            margin-bottom: 8px;
        }
        .page-title {
            font-family: var(--heading);
            font-size: clamp(2.6rem, 5vw, 4rem);
            letter-spacing: 0.02em; line-height: 0.95;
        }
        .page-sub {
            font-size: 0.85rem; color: var(--muted2);
            margin-top: 8px; font-weight: 400;
        }

        /* ─── LAYOUT ─── */
        .page { max-width: 1200px; margin: 0 auto; padding: 28px 32px 80px; }
        .grid-main { display: grid; grid-template-columns: 1fr 360px; gap: 20px; align-items: start; }

        /* ─── TOAST ─── */
        .toast-bar {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 20px; border-radius: 10px;
            font-size: 0.85rem; font-weight: 500;
            margin-bottom: 20px; border: 1px solid;
        }
        .toast-success { background: var(--green-dim); border-color: var(--green-border); color: #4ADE80; }
        .toast-error { background: var(--red-dim); border-color: var(--red-border); color: #F87171; }

        /* ─── FORM BLOCKS ─── */
        .form-block {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .block-head {
            display: flex; align-items: center; gap: 10px;
            padding: 15px 20px;
            background: var(--card2);
            border-bottom: 1px solid var(--border);
            font-family: var(--mono);
            font-size: 0.65rem; letter-spacing: 0.18em;
            text-transform: uppercase; color: var(--muted2);
        }
        .block-head i { color: var(--red); font-size: 0.85rem; }
        .block-body { padding: 22px 20px; }

        /* ─── SKILL GRID ─── */
        .skill-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }
        .skill-tile {
            display: flex; align-items: center; gap: 12px;
            padding: 13px 14px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.18s;
            user-select: none;
        }
        .skill-tile:hover { border-color: var(--border-hover); background: var(--card2); }
        .skill-tile.active {
            background: var(--red-dim);
            border-color: var(--red-border);
        }
        .skill-tile.active .skill-icon { color: var(--red); }
        .skill-tile.active .skill-name { color: var(--text); }
        .skill-icon {
            width: 32px; height: 32px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem;
            color: var(--muted);
            flex-shrink: 0;
            transition: color 0.18s;
        }
        .skill-name {
            font-size: 0.82rem; font-weight: 500;
            color: var(--muted2);
            transition: color 0.18s;
        }
        .skill-check {
            margin-left: auto;
            width: 18px; height: 18px;
            border-radius: 50%;
            border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.55rem;
            color: transparent;
            flex-shrink: 0;
            transition: all 0.18s;
        }
        .skill-tile.active .skill-check {
            background: var(--red);
            border-color: var(--red);
            color: white;
        }
        .skill-hint {
            font-family: var(--mono);
            font-size: 0.65rem; color: var(--muted);
            margin-top: 12px; letter-spacing: 0.1em;
        }
        #skillsInput { display: none; }

        /* ─── FORM FIELDS ─── */
        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 18px; }
        .field { margin-bottom: 18px; }
        .field:last-child { margin-bottom: 0; }
        .field-label {
            display: block;
            font-family: var(--mono);
            font-size: 0.62rem; letter-spacing: 0.15em;
            text-transform: uppercase; color: var(--muted);
            margin-bottom: 8px;
        }
        .field-label .req { color: var(--red); margin-left: 3px; }
        .field-input, .field-select, .field-textarea {
            width: 100%;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 11px 14px;
            font-family: var(--body);
            font-size: 0.85rem;
            color: var(--text);
            outline: none;
            transition: border-color 0.18s;
            -webkit-appearance: none;
        }
        .field-input::placeholder, .field-textarea::placeholder { color: var(--muted); }
        .field-input:focus, .field-select:focus, .field-textarea:focus { border-color: rgba(232,39,26,0.45); }
        .field-textarea { resize: vertical; min-height: 88px; font-family: var(--body); }
        .field-select { cursor: pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236B6865' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }

        /* ─── MAP ─── */
        #map {
            height: 210px;
            border-radius: 10px;
            border: 1px solid var(--border);
            margin-top: 4px;
        }
        .leaflet-container { background: #1a1a1a; }
        .coord-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
        .coord-display {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 9px 12px;
            font-family: var(--mono);
            font-size: 0.72rem;
            color: var(--muted2);
            outline: none;
            width: 100%;
        }

        /* ─── SUBMIT ─── */
        .btn-submit {
            display: flex; align-items: center; justify-content: center; gap: 10px;
            width: 100%;
            background: var(--red);
            border: none; border-radius: 10px;
            padding: 15px;
            font-family: var(--body);
            font-size: 0.85rem; font-weight: 700;
            letter-spacing: 0.1em; text-transform: uppercase;
            color: white; cursor: pointer;
            transition: background 0.18s, transform 0.18s, box-shadow 0.18s;
            margin-top: 4px;
        }
        .btn-submit:hover {
            background: #C82216;
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(232,39,26,0.3);
        }

        /* ─── SIDEBAR CARDS ─── */
        .side-block {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .side-head {
            padding: 14px 18px;
            background: var(--card2);
            border-bottom: 1px solid var(--border);
            font-family: var(--mono);
            font-size: 0.63rem; letter-spacing: 0.18em;
            text-transform: uppercase; color: var(--muted);
        }
        .side-body { padding: 16px 18px; }

        .benefit-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.82rem; color: var(--muted2);
            line-height: 1.55;
        }
        .benefit-item:last-child { border-bottom: none; padding-bottom: 0; }
        .benefit-item i { color: #4ADE80; margin-top: 2px; flex-shrink: 0; font-size: 0.75rem; }

        .status-row {
            display: flex; align-items: center; gap: 14px;
            padding: 11px 0;
            border-bottom: 1px solid var(--border);
        }
        .status-row:last-child { border-bottom: none; padding-bottom: 0; }
        .status-pill-sm {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 0.68rem; font-weight: 600;
            letter-spacing: 0.08em; text-transform: uppercase;
            padding: 5px 12px; border-radius: 100px;
            border: 1px solid; white-space: nowrap;
            min-width: 108px; justify-content: center;
        }
        .pill-green { background: var(--green-dim); border-color: var(--green-border); color: #4ADE80; }
        .pill-amber { background: var(--amber-dim); border-color: var(--amber-border); color: #FBBF24; }
        .pill-gray { background: rgba(100,100,100,0.08); border-color: var(--border); color: var(--muted2); }
        .status-desc { font-size: 0.78rem; color: var(--muted); }

        .profile-badge {
            display: flex; align-items: center; gap: 14px;
            padding: 16px 18px;
        }
        .profile-avatar {
            width: 46px; height: 46px;
            background: var(--red-dim);
            border: 1px solid var(--red-border);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-family: var(--heading);
            font-size: 1.3rem;
            color: var(--red);
            flex-shrink: 0;
        }
        .profile-name { font-size: 0.92rem; font-weight: 600; }
        .profile-role {
            font-family: var(--mono);
            font-size: 0.62rem; letter-spacing: 0.12em;
            text-transform: uppercase; color: var(--muted);
            margin-top: 3px;
        }

        /* ─── REVEAL ─── */
        .reveal { opacity: 0; transform: translateY(14px); transition: opacity 0.5s ease, transform 0.5s ease; }
        .reveal.in { opacity: 1; transform: translateY(0); }

        @media (max-width: 960px) {
            .nav { padding: 12px 16px; }
            .page-header { padding: 28px 16px 24px; }
            .page { padding: 20px 16px 60px; }
            .grid-main { grid-template-columns: 1fr; }
            .field-row { grid-template-columns: 1fr; }
            .skill-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ─── NAV ─── -->
<nav class="nav">
    <a href="my_tasks.php" class="nav-brand"><i class="fas fa-hands-helping"></i><span>Volunteer</span>HQ</a>
    <div class="nav-right">
        <a href="my_tasks.php" class="nav-link-pill">My Tasks</a>
        <a href="register.php" class="nav-link-pill active">Profile</a>
        <a href="../mapping/map.php" class="nav-link-pill">Map</a>
        <a href="../auth/logout.php" class="nav-link-pill danger" onclick="return confirm('Sign out?')">Logout</a>
    </div>
</nav>

<!-- ─── PAGE HEADER ─── -->
<div class="page-header">
    <div class="page-header-inner">
        <div class="eyebrow">// Volunteer Portal</div>
        <h1 class="page-title"><?= $existing_profile ? 'UPDATE PROFILE' : 'REGISTER AS VOLUNTEER' ?></h1>
        <p class="page-sub">Register your skills and availability to be matched with disaster response tasks</p>
    </div>
</div>

<div class="page">

    <?php if($success): ?>
    <div class="toast-bar toast-success reveal"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if($error): ?>
    <div class="toast-bar toast-error reveal"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="volunteerForm">
        <input type="hidden" name="action" value="register_volunteer">
        <input type="hidden" name="latitude" id="latitude" value="<?= $existing_profile['latitude'] ?? '' ?>">
        <input type="hidden" name="longitude" id="longitude" value="<?= $existing_profile['longitude'] ?? '' ?>">
        <input type="hidden" name="skills" id="skillsInput" value="<?= htmlspecialchars(implode(',', $skills_array)) ?>">

        <div class="grid-main">
            <!-- ─── MAIN COLUMN ─── -->
            <div>

                <!-- SKILLS -->
                <div class="form-block reveal">
                    <div class="block-head"><i class="fas fa-star"></i> Skills & Expertise</div>
                    <div class="block-body">
                        <div class="skill-grid">
                            <?php foreach ($skill_options as $key => $skill): ?>
                            <div class="skill-tile <?= in_array($key, $skills_array) ? 'active' : '' ?>" data-skill="<?= $key ?>">
                                <div class="skill-icon"><i class="fas <?= $skill['icon'] ?>"></i></div>
                                <span class="skill-name"><?= $skill['label'] ?></span>
                                <div class="skill-check"><i class="fas fa-check"></i></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="skill-hint" id="skillCount">
                            <?= count($skills_array) ?> skill<?= count($skills_array) !== 1 ? 's' : '' ?> selected
                        </div>
                    </div>
                </div>

                <!-- AVAILABILITY & EXPERIENCE -->
                <div class="form-block reveal">
                    <div class="block-head"><i class="fas fa-sliders"></i> Status & Experience</div>
                    <div class="block-body">
                        <div class="field-row">
                            <div class="field">
                                <label class="field-label">Availability Status</label>
                                <select name="availability_status" class="field-select">
                                    <option value="available" <?= $availability_status == 'available' ? 'selected' : '' ?>>Available — Ready now</option>
                                    <option value="busy"      <?= $availability_status == 'busy'      ? 'selected' : '' ?>>Busy — On a task</option>
                                    <option value="unavailable" <?= $availability_status == 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
                                </select>
                            </div>
                            <div class="field">
                                <label class="field-label">Experience Level</label>
                                <select name="experience_years" class="field-select">
                                    <option value="0"  <?= $experience_years == 0  ? 'selected' : '' ?>>Under 1 year</option>
                                    <option value="1"  <?= $experience_years == 1  ? 'selected' : '' ?>>1 – 2 years</option>
                                    <option value="3"  <?= $experience_years == 3  ? 'selected' : '' ?>>3 – 5 years</option>
                                    <option value="6"  <?= $experience_years == 6  ? 'selected' : '' ?>>5 – 10 years</option>
                                    <option value="10" <?= $experience_years == 10 ? 'selected' : '' ?>>10+ years</option>
                                </select>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field">
                                <label class="field-label">Emergency Contact Phone</label>
                                <input type="tel" name="phone_emergency" class="field-input" placeholder="+254 700 000 000" value="<?= htmlspecialchars($phone_emergency) ?>">
                            </div>
                            <div class="field">
                                <label class="field-label">Certifications & Training</label>
                                <input type="text" name="certifications" class="field-input" placeholder="e.g. First Aid, CPR, Rescue" value="<?= htmlspecialchars($certifications) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- LOCATION -->
                <div class="form-block reveal">
                    <div class="block-head"><i class="fas fa-map-pin"></i> Your Location</div>
                    <div class="block-body">
                        <div class="skill-hint" style="margin-bottom:10px;">Click anywhere on the map to pin your location</div>
                        <div id="map"></div>
                        <div class="coord-row">
                            <input type="text" id="latDisplay" class="coord-display" readonly placeholder="Latitude" value="<?= $existing_profile['latitude'] ?? '' ?>">
                            <input type="text" id="lngDisplay" class="coord-display" readonly placeholder="Longitude" value="<?= $existing_profile['longitude'] ?? '' ?>">
                        </div>
                    </div>
                </div>

                <div class="reveal">
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-<?= $existing_profile ? 'rotate' : 'user-plus' ?>"></i>
                        <?= $existing_profile ? 'Update My Profile' : 'Register as Volunteer' ?>
                    </button>
                </div>
            </div>

            <!-- ─── SIDEBAR ─── -->
            <div>

                <!-- PROFILE CARD -->
                <div class="side-block reveal">
                    <div class="profile-badge">
                        <div class="profile-avatar"><?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?></div>
                        <div>
                            <div class="profile-name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
                            <div class="profile-role">Volunteer — <?= $existing_profile ? 'Profile Active' : 'Not Registered' ?></div>
                        </div>
                    </div>
                </div>

                <!-- STATUS GUIDE -->
                <div class="side-block reveal">
                    <div class="side-head">Availability Guide</div>
                    <div class="side-body">
                        <div class="status-row">
                            <div class="status-pill-sm pill-green"><span style="width:6px;height:6px;background:currentColor;border-radius:50%;"></span>Available</div>
                            <div class="status-desc">Ready to accept new assignments</div>
                        </div>
                        <div class="status-row">
                            <div class="status-pill-sm pill-amber"><span style="width:6px;height:6px;background:currentColor;border-radius:50%;"></span>Busy</div>
                            <div class="status-desc">Currently working on a task</div>
                        </div>
                        <div class="status-row">
                            <div class="status-pill-sm pill-gray"><span style="width:6px;height:6px;background:currentColor;border-radius:50%;"></span>Unavailable</div>
                            <div class="status-desc">Cannot take assignments right now</div>
                        </div>
                    </div>
                </div>

                <!-- WHY VOLUNTEER -->
                <div class="side-block reveal">
                    <div class="side-head">Why Volunteer?</div>
                    <div class="side-body">
                        <div class="benefit-item"><i class="fas fa-check-circle"></i>Make a real difference in your community during crises</div>
                        <div class="benefit-item"><i class="fas fa-check-circle"></i>Gain hands-on emergency response experience</div>
                        <div class="benefit-item"><i class="fas fa-check-circle"></i>Connect with dedicated responders and NGOs</div>
                        <div class="benefit-item"><i class="fas fa-check-circle"></i>Receive training and certification opportunities</div>
                        <div class="benefit-item"><i class="fas fa-check-circle"></i>Be part of the official coordinated response team</div>
                    </div>
                </div>

            </div>
        </div>
    </form>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    // ─── MAP ───
    const mapEl = document.getElementById('map');
    const initLat = <?= ($existing_profile && $existing_profile['latitude']) ? $existing_profile['latitude'] : -1.2921 ?>;
    const initLng = <?= ($existing_profile && $existing_profile['longitude']) ? $existing_profile['longitude'] : 36.8219 ?>;

    const map = L.map('map').setView([initLat, initLng], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    const redIcon = L.divIcon({
        className: '',
        html: `<div style="width:14px;height:14px;background:#E8271A;border:2px solid #fff;border-radius:50%;box-shadow:0 2px 8px rgba(232,39,26,0.5);"></div>`,
        iconSize: [14, 14],
        iconAnchor: [7, 7]
    });

    let marker = null;
    <?php if ($existing_profile && $existing_profile['latitude'] && $existing_profile['longitude']): ?>
        marker = L.marker([<?= $existing_profile['latitude'] ?>, <?= $existing_profile['longitude'] ?>], {icon: redIcon}).addTo(map);
    <?php endif; ?>

    map.on('click', function(e) {
        if (marker) map.removeLayer(marker);
        marker = L.marker(e.latlng, {icon: redIcon}).addTo(map);
        document.getElementById('latitude').value = e.latlng.lat;
        document.getElementById('longitude').value = e.latlng.lng;
        document.getElementById('latDisplay').value = e.latlng.lat.toFixed(6);
        document.getElementById('lngDisplay').value = e.latlng.lng.toFixed(6);
    });

    // ─── SKILLS ───
    let selectedSkills = <?= json_encode($skills_array) ?>;

    function updateSkillsUI() {
        document.querySelectorAll('.skill-tile').forEach(tile => {
            tile.classList.toggle('active', selectedSkills.includes(tile.dataset.skill));
        });
        document.getElementById('skillsInput').value = selectedSkills.join(',');
        const n = selectedSkills.length;
        document.getElementById('skillCount').textContent = n + ' skill' + (n !== 1 ? 's' : '') + ' selected';
    }

    document.querySelectorAll('.skill-tile').forEach(tile => {
        tile.addEventListener('click', function() {
            const skill = this.dataset.skill;
            if (selectedSkills.includes(skill)) {
                selectedSkills = selectedSkills.filter(s => s !== skill);
            } else {
                selectedSkills.push(skill);
            }
            updateSkillsUI();
        });
    });

    // ─── REVEAL ───
    const reveals = document.querySelectorAll('.reveal');
    const obs = new IntersectionObserver(entries => {
        entries.forEach((e, i) => {
            if (e.isIntersecting) {
                setTimeout(() => e.target.classList.add('in'), i * 70);
                obs.unobserve(e.target);
            }
        });
    }, { threshold: 0.08 });
    reveals.forEach(el => obs.observe(el));
</script>
</body>
</html>