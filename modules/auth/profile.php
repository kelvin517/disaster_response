<?php
// profile.php — Disaster Response System
require_once __DIR__ . '/../../includes/config/config.php';

if (!isLoggedIn()) {
    $_SESSION['message']      = "Please sign in to access your profile.";
    $_SESSION['message_type'] = "info";
    redirect('modules/auth/login.php');
}

$user_id = $_SESSION['user_id'];
$error   = null;
$success = null;

// Fetch current user data
$stmt = $pdo->prepare(
    "SELECT full_name, email, phone, role, profile_image FROM users WHERE id = ?"
);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    logout(); // ghost session
}

// ── POST handler ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* Update basic info */
    if ($action === 'update_profile') {
        $full_name = sanitize($_POST['full_name'] ?? '');
        $phone     = sanitize($_POST['phone']     ?? '');

        if (empty($full_name)) {
            $error = "Full name is required.";
        } else {
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?");
            if ($stmt->execute([$full_name, $phone, $user_id])) {
                $_SESSION['full_name']  = $full_name;
                $user['full_name']      = $full_name;
                $user['phone']          = $phone;
                $success = "Profile updated successfully.";
            } else {
                $error = "Failed to update profile. Please try again.";
            }
        }
    }

    /* Change password */
    elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $db_pass = $stmt->fetchColumn();

        if (!password_verify($current, $db_pass)) {
            $error = "Current password is incorrect.";
        } elseif (strlen($new) < 6) {
            $error = "New password must be at least 6 characters.";
        } elseif ($new !== $confirm) {
            $error = "New passwords do not match.";
        } else {
            $hashed = password_hash($new, PASSWORD_BCRYPT);
            $stmt   = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([$hashed, $user_id])) {
                $success = "Password changed successfully.";
            } else {
                $error = "Failed to change password. Please try again.";
            }
        }
    }

    /* Upload profile photo */
    elseif ($action === 'upload_photo' && isset($_FILES['profile_image'])) {
        $file = $_FILES['profile_image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = "No file uploaded or upload error occurred.";
        } else {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo         = new finfo(FILEINFO_MIME_TYPE);
            $mime          = $finfo->file($file['tmp_name']);

            if (!in_array($mime, $allowed_types)) {
                $error = "Only JPG, PNG, GIF, or WebP images are allowed.";
            } elseif ($file['size'] > MAX_FILE_SIZE) {
                $error = "File too large. Maximum size is 5 MB.";
            } else {
                $ext         = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename    = 'user_' . $user_id . '_' . time() . '.' . strtolower($ext);
                $destination = UPLOAD_DIR . $filename;

                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    // Delete old photo
                    if (!empty($user['profile_image']) && file_exists(UPLOAD_DIR . $user['profile_image'])) {
                        unlink(UPLOAD_DIR . $user['profile_image']);
                    }
                    $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?")
                        ->execute([$filename, $user_id]);
                    $_SESSION['profile_image'] = $filename;
                    $user['profile_image']     = $filename;
                    $success = "Profile picture updated.";
                } else {
                    $error = "Failed to save the image. Please try again.";
                }
            }
        }
    }
}

// Role badge config
$role_badge = [
    'admin'     => ['label' => 'Administrator', 'color' => '#ef4444'],
    'responder' => ['label' => 'Responder',      'color' => '#f97316'],
    'volunteer' => ['label' => 'Volunteer',       'color' => '#22c55e'],
    'victim'    => ['label' => 'Public',          'color' => '#3b82f6'],
];
$rb = $role_badge[$user['role']] ?? ['label' => ucfirst($user['role']), 'color' => '#9a9690'];

// Dashboard link per role
$dashboard_links = [
    'admin'     => BASE_URL . 'modules/admin/dashboard.php',
    'responder' => BASE_URL . 'modules/responders/dashboard.php',
    'volunteer' => BASE_URL . 'modules/volunteers/my_tasks.php',
    'victim'    => BASE_URL . 'modules/incidents/report.php',
];
$dash_url = $dashboard_links[$user['role']] ?? BASE_URL;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — Disaster Response System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --red:   #e03131; --red-dk: #b91c1c;
            --ink:   #0f0f0f; --mist:   #f0f2f5;
            --smoke: #e8e6e1; --ash:    #9a9690;
            --white: #ffffff;
            --role-color: <?= $rb['color'] ?>;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--mist);
            min-height: 100vh; padding: 2rem 1rem;
        }

        .page-wrapper {
            max-width: 700px; margin: 0 auto;
        }

        /* Topbar */
        .topbar {
            display: flex; align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }
        .topbar .brand {
            display: flex; align-items: center; gap: .6rem;
            text-decoration: none;
        }
        .brand-icon {
            width: 34px; height: 34px; background: var(--ink);
            border-radius: 6px; display: grid; place-items: center;
        }
        .brand-icon svg { width: 18px; height: 18px; }
        .brand-name {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: .95rem; color: var(--ink);
        }
        .brand-name span { color: var(--red); }
        .btn-dash {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .5rem 1rem; background: var(--white);
            border: 1.5px solid var(--smoke); border-radius: 8px;
            font-size: .82rem; font-weight: 500; color: var(--ink);
            text-decoration: none; transition: border-color .15s;
        }
        .btn-dash:hover { border-color: var(--red); color: var(--red); }

        /* Profile hero */
        .profile-hero {
            background: var(--ink);
            border-radius: 16px 16px 0 0;
            padding: 2rem 2rem 3rem;
            text-align: center;
            position: relative; overflow: hidden;
        }
        .profile-hero::before {
            content: '';
            position: absolute; inset: 0;
            background: repeating-linear-gradient(
                -55deg, transparent, transparent 40px,
                rgba(224,49,49,.06) 40px, rgba(224,49,49,.06) 41px
            );
        }
        .avatar-wrap {
            position: relative; display: inline-block; margin-bottom: 1rem;
        }
        .avatar {
            width: 96px; height: 96px; border-radius: 50%;
            border: 3px solid rgba(255,255,255,.15);
            object-fit: cover;
            position: relative; z-index: 1;
        }
        .avatar-placeholder {
            width: 96px; height: 96px; border-radius: 50%;
            background: rgba(255,255,255,.08);
            border: 3px solid rgba(255,255,255,.15);
            display: grid; place-items: center;
            font-size: 2.5rem;
        }
        .role-badge {
            position: relative;
            display: inline-block;
            padding: .25rem .75rem;
            border-radius: 20px;
            font-size: .72rem; font-weight: 700;
            background: var(--role-color);
            color: white; letter-spacing: .04em;
            text-transform: uppercase; margin-bottom: .5rem;
        }
        .profile-hero h2 {
            position: relative;
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 1.5rem; color: white;
        }
        .profile-hero p {
            position: relative; color: var(--ash); font-size: .88rem;
        }

        /* Card */
        .card {
            background: var(--white);
            border-radius: 0 0 16px 16px;
            box-shadow: 0 8px 40px rgba(0,0,0,.08);
            overflow: hidden;
        }

        /* Tabs */
        .tabs {
            display: flex; border-bottom: 1.5px solid var(--smoke);
        }
        .tab-btn {
            flex: 1; padding: 1rem .5rem;
            background: none; border: none; border-bottom: 2.5px solid transparent;
            font-family: 'DM Sans', sans-serif; font-size: .85rem; font-weight: 500;
            color: var(--ash); cursor: pointer; transition: all .15s;
            margin-bottom: -1.5px;
        }
        .tab-btn:hover { color: var(--ink); }
        .tab-btn.active { color: var(--red); border-bottom-color: var(--red); }

        .tab-panes { padding: 1.75rem 2rem; }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        .alert {
            padding: .8rem 1rem; border-radius: 8px;
            font-size: .88rem; margin-bottom: 1.2rem;
        }
        .alert-danger  { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

        .field { margin-bottom: 1rem; }
        .field label {
            display: block; font-size: .78rem; font-weight: 500;
            color: var(--ink); margin-bottom: .35rem;
            letter-spacing: .04em; text-transform: uppercase;
        }
        .field input {
            width: 100%; padding: .72rem 1rem;
            border: 1.5px solid var(--smoke); border-radius: 8px;
            font-family: 'DM Sans', sans-serif; font-size: .93rem;
            color: var(--ink); background: var(--white);
            transition: border-color .15s; outline: none;
        }
        .field input:focus { border-color: var(--red); }
        .field input:disabled { background: var(--mist); color: var(--ash); cursor: not-allowed; }
        .field .hint { font-size: .78rem; color: var(--ash); margin-top: .3rem; }

        .btn-primary {
            padding: .75rem 1.6rem;
            background: var(--red); color: var(--white); border: none;
            border-radius: 8px; font-family: 'Syne', sans-serif;
            font-weight: 700; font-size: .92rem; cursor: pointer;
            transition: background .15s, transform .1s;
        }
        .btn-primary:hover  { background: var(--red-dk); }
        .btn-primary:active { transform: scale(.98); }

        /* Photo preview */
        .photo-preview {
            width: 100px; height: 100px; border-radius: 12px;
            object-fit: cover; border: 2px solid var(--smoke);
            margin-bottom: 1rem; display: block;
        }
        .photo-placeholder {
            width: 100px; height: 100px; border-radius: 12px;
            background: var(--mist); border: 2px dashed var(--smoke);
            display: grid; place-items: center; font-size: 2rem;
            margin-bottom: 1rem;
        }

        .card-footer {
            padding: 1rem 2rem; border-top: 1px solid var(--smoke);
            text-align: right;
        }
        .logout-link {
            font-size: .85rem; color: var(--ash);
            text-decoration: none;
        }
        .logout-link:hover { color: var(--red); }
    </style>
</head>
<body>

<div class="page-wrapper">

    <!-- Top nav -->
    <div class="topbar">
        <a href="<?= BASE_URL ?>" class="brand">
            <div class="brand-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <div class="brand-name">Disaster<span>Response</span></div>
        </a>
        <a href="<?= htmlspecialchars($dash_url) ?>" class="btn-dash">← Dashboard</a>
    </div>

    <!-- Profile hero -->
    <div class="profile-hero">
        <div class="avatar-wrap">
            <?php if (!empty($user['profile_image']) && file_exists(UPLOAD_DIR . $user['profile_image'])): ?>
                <img src="<?= BASE_URL . 'temp/uploads/' . htmlspecialchars($user['profile_image']) ?>"
                     class="avatar" alt="Profile photo">
            <?php else: ?>
                <div class="avatar-placeholder">👤</div>
            <?php endif; ?>
        </div>
        <div>
            <div class="role-badge"><?= htmlspecialchars($rb['label']) ?></div>
        </div>
        <h2><?= htmlspecialchars($user['full_name']) ?></h2>
        <p><?= htmlspecialchars($user['email']) ?></p>
    </div>

    <!-- Card with tabs -->
    <div class="card">
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('edit', this)">Edit Profile</button>
            <button class="tab-btn" onclick="switchTab('password', this)">Password</button>
            <button class="tab-btn" onclick="switchTab('photo', this)">Photo</button>
        </div>

        <div class="tab-panes">

            <?php if ($error): ?>
                <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <!-- Edit Profile -->
            <div class="tab-pane active" id="pane-edit">
                <form method="POST" novalidate>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name"
                               value="<?= htmlspecialchars($user['full_name']) ?>" required>
                    </div>
                    <div class="field">
                        <label>Email</label>
                        <input type="email" value="<?= htmlspecialchars($user['email']) ?>" disabled>
                        <p class="hint">Email address cannot be changed.</p>
                    </div>
                    <div class="field">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone"
                               value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                               placeholder="+254 700 000 000">
                    </div>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </form>
            </div>

            <!-- Change Password -->
            <div class="tab-pane" id="pane-password">
                <form method="POST" novalidate>
                    <input type="hidden" name="action" value="change_password">
                    <div class="field">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    <div class="field">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password"
                               placeholder="Min. 6 characters" required>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    <button type="submit" class="btn-primary">Change Password</button>
                </form>
            </div>

            <!-- Profile Photo -->
            <div class="tab-pane" id="pane-photo">
                <?php if (!empty($user['profile_image']) && file_exists(UPLOAD_DIR . $user['profile_image'])): ?>
                    <img src="<?= BASE_URL . 'temp/uploads/' . htmlspecialchars($user['profile_image']) ?>"
                         class="photo-preview" alt="Current photo">
                <?php else: ?>
                    <div class="photo-placeholder">🖼</div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="action" value="upload_photo">
                    <div class="field">
                        <label for="profile_image">Select Image</label>
                        <input type="file" id="profile_image" name="profile_image"
                               accept="image/jpeg,image/png,image/gif,image/webp" required>
                        <p class="hint">JPG, PNG, GIF, or WebP — max 5 MB.</p>
                    </div>
                    <button type="submit" class="btn-primary">Upload Photo</button>
                </form>
            </div>

        </div>

        <div class="card-footer">
            <a href="logout.php" class="logout-link">Sign out →</a>
        </div>
    </div>

</div>

<script>
function switchTab(id, btn) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('pane-' + id).classList.add('active');
    btn.classList.add('active');
}

// Auto-open the right tab if there was a server-side error/success on a specific action
<?php
$active_pane = 'edit';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'change_password') $active_pane = 'password';
    elseif ($a === 'upload_photo') $active_pane = 'photo';
}
if ($active_pane !== 'edit') {
    echo "document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        const targetBtn = [...document.querySelectorAll('.tab-btn')].find(b => b.getAttribute('onclick').includes('{$active_pane}'));
        if (targetBtn) { targetBtn.classList.add('active'); }
        document.getElementById('pane-{$active_pane}').classList.add('active');
    });";
}
?>
</script>

</body>
</html>