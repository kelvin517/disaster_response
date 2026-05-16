<?php
// register.php — Disaster Response System
require_once __DIR__ . '/../../includes/config/config.php';

if (!isset($pdo)) die("Database connection error.");

// Already logged in? Go to dashboard.
if (isLoggedIn()) {
    redirect('modules/incidents/report.php');
}

// ─── Admin secret code ────────────────────────────────────────────────────────
// Store this in your config or .env; never hard-code in production.
// Example: define('ADMIN_SECRET_CODE', 'your-strong-secret') in config.php
if (!defined('ADMIN_SECRET_CODE')) {
    define('ADMIN_SECRET_CODE', 'DRS-ADMIN-2025'); // ← change before deploying
}
// ─────────────────────────────────────────────────────────────────────────────

$error   = null;
$success = null;
$post    = []; // repopulate on error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post = $_POST;

    $full_name   = sanitize($_POST['full_name']   ?? '');
    $email       = sanitize($_POST['email']        ?? '');
    $phone       = sanitize($_POST['phone']        ?? '');
    $password    = $_POST['password']              ?? '';
    $role        = sanitize($_POST['role']         ?? 'victim');
    $admin_code  = $_POST['admin_code']            ?? '';

    $allowed_roles = ['victim', 'responder', 'volunteer', 'admin'];
    if (!in_array($role, $allowed_roles)) $role = 'victim';

    // Validate admin code when role is admin
    if ($role === 'admin' && $admin_code !== ADMIN_SECRET_CODE) {
        $error = "Invalid admin access code. Please check your code and try again.";
        $role  = 'admin'; // keep role selected so the form repopulates correctly
    } elseif (empty($full_name) || empty($email) || empty($phone) || empty($password)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "That email is already registered. <a href='login.php'>Log in?</a>";
        } else {
            $username       = $email;
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $pdo->prepare(
                "INSERT INTO users (full_name, email, phone, password, role, username)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            try {
                if ($stmt->execute([$full_name, $email, $phone, $hashedPassword, $role, $username])) {
                    $success = true;
                    $post    = []; // clear repopulation
                } else {
                    $error = "Registration failed. Please try again.";
                }
            } catch (PDOException $e) {
                $error = "A database error occurred. Please try again.";
                // Log: error_log($e->getMessage());
            }
        }
    }
}

$role_labels = [
    'victim'    => ['label' => 'Public / Victim',       'desc' => 'Report incidents & request assistance'],
    'responder' => ['label' => 'Emergency Responder',   'desc' => 'Manage and respond to active incidents'],
    'volunteer' => ['label' => 'Volunteer',              'desc' => 'Pick up tasks and support relief efforts'],
    'admin'     => ['label' => 'Administrator',          'desc' => 'Full system access & management'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — Disaster Response System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --red:   #e03131;
            --red-dk:#b91c1c;
            --ink:   #0f0f0f;
            --mist:  #f5f4f1;
            --smoke: #e8e6e1;
            --ash:   #9a9690;
            --white: #ffffff;
            --admin: #7c3aed;
            --admin-dk: #6d28d9;
            --admin-bg: #f5f3ff;
            --admin-border: #ddd6fe;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--mist);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .card {
            background: var(--white);
            border-radius: 16px;
            box-shadow: 0 8px 40px rgba(0,0,0,.08);
            width: 100%;
            max-width: 480px;
            overflow: hidden;
        }

        .card-header {
            background: var(--ink);
            padding: 2rem 2rem 1.6rem;
            position: relative;
            overflow: hidden;
        }
        .card-header::before {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(
                -55deg, transparent, transparent 40px,
                rgba(224,49,49,.07) 40px, rgba(224,49,49,.07) 41px
            );
        }
        .card-header .brand {
            position: relative;
            display: flex;
            align-items: center;
            gap: .6rem;
            margin-bottom: 1.2rem;
        }
        .brand-icon {
            width: 34px; height: 34px;
            background: var(--red);
            border-radius: 6px;
            display: grid; place-items: center;
        }
        .brand-icon svg { width: 18px; height: 18px; }
        .brand-name {
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: .95rem;
            color: var(--white);
        }
        .brand-name span { color: var(--red); }

        .card-header h2 {
            position: relative;
            font-family: 'Syne', sans-serif;
            font-weight: 800; font-size: 1.6rem;
            color: var(--white);
            line-height: 1.15;
        }
        .card-header p {
            position: relative;
            color: var(--ash);
            font-size: .88rem;
            margin-top: .35rem;
        }

        .card-body { padding: 2rem; }

        .alert {
            padding: .8rem 1rem;
            border-radius: 8px;
            font-size: .88rem;
            margin-bottom: 1.25rem;
        }
        .alert a { color: inherit; font-weight: 600; }
        .alert-danger  { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

        .field { margin-bottom: 1rem; }
        .field label {
            display: block; font-size: .78rem; font-weight: 500;
            color: var(--ink); margin-bottom: .35rem;
            letter-spacing: .04em; text-transform: uppercase;
        }
        .field input, .field select {
            width: 100%; padding: .72rem 1rem;
            border: 1.5px solid var(--smoke); border-radius: 8px;
            font-family: 'DM Sans', sans-serif; font-size: .93rem;
            color: var(--ink); background: var(--white);
            transition: border-color .15s; outline: none;
        }
        .field input:focus, .field select:focus { border-color: var(--red); }
        .field .hint { font-size: .78rem; color: var(--ash); margin-top: .3rem; }

        /* Role picker */
        .role-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: .5rem;
            margin-top: .35rem;
        }
        .role-opt { display: none; }
        .role-opt + label {
            display: flex; flex-direction: column;
            align-items: center; gap: .4rem;
            padding: .8rem .5rem;
            border: 1.5px solid var(--smoke); border-radius: 10px;
            cursor: pointer; transition: all .15s;
            text-align: center;
        }
        .role-opt + label .role-icon { font-size: 1.4rem; }
        .role-opt + label .role-name { font-size: .75rem; font-weight: 600; color: var(--ink); }
        .role-opt + label .role-desc { font-size: .68rem; color: var(--ash); line-height: 1.3; }
        .role-opt:checked + label {
            border-color: var(--red); background: #fef2f2;
        }
        /* Admin role special styling */
        #role_admin + label {
            border-style: dashed;
        }
        #role_admin:checked + label {
            border-color: var(--admin);
            border-style: solid;
            background: var(--admin-bg);
        }
        #role_admin:checked + label .role-name { color: var(--admin); }

        /* Admin code field — hidden by default, revealed via JS */
        .admin-code-field {
            overflow: hidden;
            max-height: 0;
            opacity: 0;
            transition: max-height .3s ease, opacity .3s ease, margin .3s ease;
            margin-bottom: 0;
        }
        .admin-code-field.visible {
            max-height: 120px;
            opacity: 1;
            margin-bottom: 1rem;
        }
        .admin-code-field input:focus {
            border-color: var(--admin) !important;
            box-shadow: 0 0 0 3px rgba(124,58,237,.1);
        }
        .admin-code-banner {
            display: flex;
            align-items: flex-start;
            gap: .6rem;
            padding: .7rem .9rem;
            background: var(--admin-bg);
            border: 1px solid var(--admin-border);
            border-radius: 8px;
            font-size: .8rem;
            color: var(--admin-dk);
            margin-bottom: .6rem;
        }
        .admin-code-banner svg { flex-shrink: 0; margin-top: 1px; }

        .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }

        .btn-primary {
            width: 100%; padding: .85rem; margin-top: .5rem;
            background: var(--red); color: var(--white); border: none;
            border-radius: 8px; font-family: 'Syne', sans-serif;
            font-weight: 700; font-size: 1rem; cursor: pointer;
            transition: background .15s, transform .1s;
        }
        .btn-primary:hover  { background: var(--red-dk); }
        .btn-primary:active { transform: scale(.98); }
        .btn-primary.admin-mode {
            background: var(--admin);
        }
        .btn-primary.admin-mode:hover { background: var(--admin-dk); }

        .card-footer {
            text-align: center; padding: 1.2rem 2rem 1.5rem;
            font-size: .88rem; color: var(--ash);
            border-top: 1px solid var(--smoke);
        }
        .card-footer a { color: var(--red); text-decoration: none; font-weight: 500; }
        .card-footer a:hover { text-decoration: underline; }

        .success-state { text-align: center; padding: 1rem 0 .5rem; }
        .success-state .check { font-size: 3rem; margin-bottom: 1rem; }
        .success-state h3 { font-family: 'Syne', sans-serif; font-size: 1.3rem; font-weight: 800; color: var(--ink); margin-bottom: .5rem; }
        .success-state p { color: var(--ash); font-size: .9rem; margin-bottom: 1.5rem; }

        /* Password visibility toggle */
        .input-wrap { position: relative; }
        .input-wrap input { padding-right: 2.8rem; }
        .toggle-pw {
            position: absolute; right: .85rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: var(--ash); padding: 0; line-height: 1;
            transition: color .15s;
        }
        .toggle-pw:hover { color: var(--ink); }
    </style>
</head>
<body>

<div class="card">
    <div class="card-header">
        <div class="brand">
            <div class="brand-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <div class="brand-name">Disaster<span>Response</span></div>
        </div>
        <h2>Create your account</h2>
        <p>Join the network. Make a difference.</p>
    </div>

    <div class="card-body">

        <?php if ($success): ?>
            <div class="success-state">
                <div class="check">✅</div>
                <h3>You're registered!</h3>
                <p>Your account has been created successfully. You can now sign in.</p>
                <a href="login.php" class="btn-primary" style="display:block;text-decoration:none;text-align:center;">Go to Login</a>
            </div>

        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <form method="POST" novalidate>

                <div class="field">
                    <label>I am a</label>
                    <div class="role-grid">
                        <?php
                        $icons = ['victim'=>'🆘','responder'=>'🚒','volunteer'=>'🤝','admin'=>'🛡️'];
                        foreach ($role_labels as $val => $info):
                            $checked = (($post['role'] ?? 'victim') === $val) ? 'checked' : '';
                        ?>
                        <input type="radio" class="role-opt" name="role"
                               id="role_<?= $val ?>" value="<?= $val ?>" <?= $checked ?>>
                        <label for="role_<?= $val ?>">
                            <span class="role-icon"><?= $icons[$val] ?></span>
                            <span class="role-name"><?= $info['label'] ?></span>
                            <span class="role-desc"><?= $info['desc'] ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Admin secret code (shown only when admin role is selected) -->
                <div class="admin-code-field <?= (($post['role'] ?? '') === 'admin') ? 'visible' : '' ?>" id="adminCodeWrap">
                    <div class="admin-code-banner">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0110 0v4"/>
                        </svg>
                        Administrator access requires a secret code issued by your system administrator.
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label for="admin_code">Admin Access Code</label>
                        <div class="input-wrap">
                            <input type="password" id="admin_code" name="admin_code"
                                   placeholder="Enter your access code"
                                   autocomplete="off"
                                   value="<?= (($post['role'] ?? '') === 'admin') ? htmlspecialchars($post['admin_code'] ?? '') : '' ?>">
                            <button type="button" class="toggle-pw" onclick="toggleCode(this)" aria-label="Show/hide code" tabindex="-1">
                                <svg id="eyeIconCode" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name"
                           value="<?= htmlspecialchars($post['full_name'] ?? '') ?>"
                           placeholder="Jane Doe" required>
                </div>

                <div class="row-2">
                    <div class="field">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email"
                               value="<?= htmlspecialchars($post['email'] ?? '') ?>"
                               placeholder="you@example.com" required>
                    </div>
                    <div class="field">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone"
                               value="<?= htmlspecialchars($post['phone'] ?? '') ?>"
                               placeholder="+254 700 000 000" required>
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input type="password" id="password" name="password"
                               placeholder="Min. 6 characters" required>
                        <button type="button" class="toggle-pw" onclick="togglePw(this)" aria-label="Show/hide password" tabindex="-1">
                            <svg id="eyeIconPw" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <p class="hint">At least 6 characters.</p>
                </div>

                <button type="submit" class="btn-primary" id="submitBtn">Create Account →</button>
            </form>

        <?php endif; ?>

    </div>

    <div class="card-footer">
        Already have an account? <a href="login.php">Sign in</a>
    </div>
</div>

<script>
    const radios     = document.querySelectorAll('.role-opt');
    const codeWrap   = document.getElementById('adminCodeWrap');
    const submitBtn  = document.getElementById('submitBtn');
    const codeInput  = document.getElementById('admin_code');

    function syncAdminUI() {
        const selected = document.querySelector('.role-opt:checked')?.value;
        const isAdmin  = selected === 'admin';

        codeWrap.classList.toggle('visible', isAdmin);
        submitBtn.classList.toggle('admin-mode', isAdmin);
        submitBtn.textContent = isAdmin ? '🛡️ Register as Administrator →' : 'Create Account →';

        if (isAdmin) {
            // Slight delay so the field is visible before focusing
            setTimeout(() => codeInput?.focus(), 320);
        } else {
            if (codeInput) codeInput.value = '';
        }
    }

    radios.forEach(r => r.addEventListener('change', syncAdminUI));
    syncAdminUI(); // run on page load (handles PHP repopulation)

    // Password visibility toggles
    function togglePw(btn) {
        const input = document.getElementById('password');
        const icon  = document.getElementById('eyeIconPw');
        toggle(input, icon);
    }
    function toggleCode(btn) {
        const input = document.getElementById('admin_code');
        const icon  = document.getElementById('eyeIconCode');
        toggle(input, icon);
    }
    function toggle(input, icon) {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        icon.innerHTML = show
            ? `<line x1="1" y1="1" x2="23" y2="23"/>
               <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
               <path d="M6.53 6.53A18.44 18.44 0 001 12s4 8 11 8a9.1 9.1 0 005.47-1.9"/>
               <line x1="1" y1="1" x2="23" y2="23"/>`
            : `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
               <circle cx="12" cy="12" r="3"/>`;
    }
</script>

</body>
</html>