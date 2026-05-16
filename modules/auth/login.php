<?php
// login.php — Disaster Response System
require_once __DIR__ . '/../../includes/config/config.php';

if (!isset($pdo)) die("Database connection error.");

// Already logged in? Redirect away.
if (isLoggedIn()) {
    $role = $_SESSION['role'] ?? 'victim';
    switch ($role) {
        case 'admin':     redirect('modules/admin/dashboard.php'); break;
        case 'responder': redirect('modules/responders/responders_dashboard.php'); break;
        case 'volunteer': redirect('modules/volunteers/my_tasks.php'); break;
        default:          redirect('modules/incidents/report.php');
    }
}

$error   = null;
$flash   = $_SESSION['message']      ?? null;
$ftype   = $_SESSION['message_type'] ?? 'info';
unset($_SESSION['message'], $_SESSION['message_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email']    ?? '');
    $password = $_POST['password']          ?? '';

    if (empty($email) || empty($password)) {
        $error = "Email and password are required.";
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, full_name, email, password, role
               FROM users
              WHERE email = ? AND is_active = 1"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Regenerate session ID to prevent fixation
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email']     = $user['email'];
            $_SESSION['role']      = $user['role'];

            switch ($user['role']) {
                case 'admin':     redirect('modules/admin/dashboard.php'); break;
                case 'responder': redirect('modules/responders/responders_dashboard.php'); break;
                case 'volunteer': redirect('modules/volunteers/my_tasks.php'); break;
                default:          redirect('modules/incidents/report.php');
            }
        } else {
            $error = "Invalid credentials or account is inactive.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Disaster Response System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --red:    #e03131;
            --red-dk: #b91c1c;
            --ink:    #0f0f0f;
            --mist:   #f5f4f1;
            --smoke:  #e8e6e1;
            --ash:    #9a9690;
            --white:  #ffffff;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--mist);
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1fr 1fr;
        }

        /* ── Left panel ── */
        .panel-left {
            background: var(--ink);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 3rem;
            position: relative;
            overflow: hidden;
        }
        .panel-left::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                repeating-linear-gradient(
                    -55deg,
                    transparent,
                    transparent 60px,
                    rgba(224,49,49,.06) 60px,
                    rgba(224,49,49,.06) 61px
                );
        }
        .brand {
            position: relative;
            display: flex;
            align-items: center;
            gap: .75rem;
        }
        .brand-icon {
            width: 42px; height: 42px;
            background: var(--red);
            border-radius: 8px;
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }
        .brand-icon svg { width: 22px; height: 22px; }
        .brand-name {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 1.1rem;
            color: var(--white);
            line-height: 1.1;
        }
        .brand-name span { color: var(--red); }

        .hero-text { position: relative; }
        .hero-text h1 {
            font-family: 'Syne', sans-serif;
            font-size: clamp(2.4rem, 4vw, 3.2rem);
            font-weight: 800;
            color: var(--white);
            line-height: 1.1;
            margin-bottom: 1rem;
        }
        .hero-text h1 em {
            font-style: normal;
            color: var(--red);
        }
        .hero-text p {
            color: var(--ash);
            font-size: .95rem;
            line-height: 1.7;
            max-width: 340px;
        }

        .role-chips {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: .5rem;
        }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.09);
            border-radius: 6px;
            padding: .55rem .9rem;
            color: var(--ash);
            font-size: .82rem;
            width: fit-content;
        }
        .chip-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
        }

        /* ── Right panel ── */
        .panel-right {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .form-card {
            width: 100%;
            max-width: 400px;
        }
        .form-card h2 {
            font-family: 'Syne', sans-serif;
            font-size: 1.9rem;
            font-weight: 800;
            color: var(--ink);
            margin-bottom: .3rem;
        }
        .form-card .subtitle {
            color: var(--ash);
            font-size: .9rem;
            margin-bottom: 2rem;
        }

        .alert {
            padding: .8rem 1rem;
            border-radius: 8px;
            font-size: .88rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: .5rem;
        }
        .alert-danger  { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .alert-info    { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }

        .field { margin-bottom: 1.1rem; }
        .field label {
            display: block;
            font-size: .82rem;
            font-weight: 500;
            color: var(--ink);
            margin-bottom: .4rem;
            letter-spacing: .02em;
            text-transform: uppercase;
        }
        .field input {
            width: 100%;
            padding: .75rem 1rem;
            border: 1.5px solid var(--smoke);
            border-radius: 8px;
            font-family: 'DM Sans', sans-serif;
            font-size: .95rem;
            color: var(--ink);
            background: var(--white);
            transition: border-color .15s;
            outline: none;
        }
        .field input:focus { border-color: var(--red); }

        .forgot {
            display: block;
            text-align: right;
            font-size: .82rem;
            color: var(--ash);
            text-decoration: none;
            margin-top: -.6rem;
            margin-bottom: 1.4rem;
        }
        .forgot:hover { color: var(--red); }

        .btn-primary {
            width: 100%;
            padding: .85rem;
            background: var(--red);
            color: var(--white);
            border: none;
            border-radius: 8px;
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: background .15s, transform .1s;
        }
        .btn-primary:hover  { background: var(--red-dk); }
        .btn-primary:active { transform: scale(.98); }

        .divider {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin: 1.5rem 0;
            color: var(--ash);
            font-size: .82rem;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--smoke);
        }

        .register-link {
            text-align: center;
            font-size: .88rem;
            color: var(--ash);
        }
        .register-link a { color: var(--red); text-decoration: none; font-weight: 500; }
        .register-link a:hover { text-decoration: underline; }

        @media (max-width: 768px) {
            body { grid-template-columns: 1fr; }
            .panel-left { display: none; }
        }
    </style>
</head>
<body>

<!-- Left branding panel -->
<div class="panel-left">
    <div class="brand">
        <div class="brand-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
        </div>
        <div class="brand-name">Disaster<span>Response</span></div>
    </div>

    <div class="hero-text">
        <h1>Coordinate.<br><em>Respond.</em><br>Recover.</h1>
        <p>A unified platform for emergency management — connecting victims, volunteers, and first responders when it matters most.</p>
    </div>

    <div class="role-chips">
        <div class="chip"><span class="chip-dot" style="background:#ef4444"></span> Admins — oversee all operations</div>
        <div class="chip"><span class="chip-dot" style="background:#f97316"></span> Responders — manage incidents in the field</div>
        <div class="chip"><span class="chip-dot" style="background:#22c55e"></span> Volunteers — pick up tasks &amp; help out</div>
        <div class="chip"><span class="chip-dot" style="background:#3b82f6"></span> Public — report incidents &amp; request aid</div>
    </div>
</div>

<!-- Right login panel -->
<div class="panel-right">
    <div class="form-card">
        <h2>Welcome back</h2>
        <p class="subtitle">Sign in to your account to continue.</p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($ftype) ?>">
                <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                ⚠ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       placeholder="you@example.com" required autocomplete="email">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       placeholder="••••••••" required autocomplete="current-password">
            </div>

            <a href="password_reset.php" class="forgot">Forgot password?</a>

            <button type="submit" class="btn-primary">Sign In</button>
        </form>

        <div class="divider">or</div>

        <p class="register-link">
            Don't have an account? <a href="register.php">Create one</a>
        </p>
    </div>
</div>

</body>
</html>