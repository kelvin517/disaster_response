<?php
// register.php — Disaster Response System
require_once __DIR__ . '/../../includes/config/config.php';

if (!isset($pdo)) die("Database connection error.");

// Already logged in? Go to dashboard.
if (isLoggedIn()) {
    redirect('modules/incidents/report.php');
}

$error   = null;
$success = null;
$post    = []; // repopulate on error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post = $_POST;

    $full_name = sanitize($_POST['full_name'] ?? '');
    $email     = sanitize($_POST['email']     ?? '');
    $phone     = sanitize($_POST['phone']     ?? '');
    $password  = $_POST['password']           ?? '';
    $role      = sanitize($_POST['role']      ?? 'victim');

    $allowed_roles = ['victim', 'responder', 'volunteer'];
    if (!in_array($role, $allowed_roles)) $role = 'victim';

    if (empty($full_name) || empty($email) || empty($phone) || empty($password)) {
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
            grid-template-columns: repeat(3, 1fr);
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
                        <?php foreach ($role_labels as $val => $info):
                            $icons = ['victim'=>'🆘','responder'=>'🚒','volunteer'=>'🤝'];
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
                    <input type="password" id="password" name="password"
                           placeholder="Min. 6 characters" required>
                    <p class="hint">At least 6 characters.</p>
                </div>

                <button type="submit" class="btn-primary">Create Account →</button>
            </form>

        <?php endif; ?>

    </div>

    <div class="card-footer">
        Already have an account? <a href="login.php">Sign in</a>
    </div>
</div>

</body>
</html>