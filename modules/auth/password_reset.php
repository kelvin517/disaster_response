<?php
// password_reset.php — Disaster Response System
require_once __DIR__ . '/../../includes/config/config.php';

if (!isset($pdo)) die("Database connection error.");

// Logged-in users don't need this page
if (isLoggedIn()) redirect('modules/incidents/report.php');

$error   = null;
$success = null;
$mode    = 'request'; // 'request' | 'reset'
$token   = null;
$user_id = null;

/* ── Step 1: Arriving via reset link ── */
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $mode  = 'reset';
    $token = trim($_GET['token']);

    $stmt = $pdo->prepare(
        "SELECT user_id, expires_at FROM password_resets
          WHERE token = ? AND used = 0"
    );
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset || strtotime($reset['expires_at']) < time()) {
        $error = "This reset link is invalid or has expired. Please request a new one.";
        $mode  = 'request';
        $token = null;
    } else {
        $user_id = $reset['user_id'];
    }
}

/* ── Step 2: Form submissions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* -- Request reset email -- */
    if ($action === 'request') {
        $email = sanitize($_POST['email'] ?? '');

        if (empty($email)) {
            $error = "Please enter your email address.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND is_active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                // Generic message — don't reveal whether email exists
                $success = "If that email is registered, a reset link has been sent.";
            } else {
                // Ensure the table exists
                $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
                    id         INT AUTO_INCREMENT PRIMARY KEY,
                    user_id    INT          NOT NULL,
                    token      VARCHAR(100) NOT NULL UNIQUE,
                    expires_at DATETIME     NOT NULL,
                    used       BOOLEAN      DEFAULT 0,
                    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
                )");

                // Invalidate previous unused tokens for this user
                $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0")
                    ->execute([$user['id']]);

                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)")
                    ->execute([$user['id'], $token, $expires]);

                $resetLink = BASE_URL . "modules/auth/password_reset.php?token=" . urlencode($token);

                // TODO: send $resetLink via email (e.g. PHPMailer)
                // For demo, we surface the link directly:
                $success = $resetLink; // handled in template
            }
        }
    }

    /* -- Submit new password -- */
    elseif ($action === 'reset') {
        $token        = $_POST['token']            ?? '';
        $new_password = $_POST['new_password']     ?? '';
        $confirm      = $_POST['confirm_password'] ?? '';

        $stmt = $pdo->prepare(
            "SELECT user_id FROM password_resets
              WHERE token = ? AND used = 0 AND expires_at > NOW()"
        );
        $stmt->execute([$token]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $error = "Invalid or expired reset token.";
        } elseif (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters.";
        } elseif ($new_password !== $confirm) {
            $error = "Passwords do not match.";
        } else {
            $hashed = password_hash($new_password, PASSWORD_BCRYPT);

            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([$hashed, $reset['user_id']]);

            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE token = ?")
                ->execute([$token]);

            // Set flash and redirect to login
            $_SESSION['message']      = "Password reset successfully. Please sign in.";
            $_SESSION['message_type'] = "success";
            redirect('modules/auth/login.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — Disaster Response System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --red:   #e03131; --red-dk: #b91c1c;
            --ink:   #0f0f0f; --mist:   #f5f4f1;
            --smoke: #e8e6e1; --ash:    #9a9690;
            --white: #ffffff;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--mist);
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 2rem 1rem;
        }

        .card {
            background: var(--white); border-radius: 16px;
            box-shadow: 0 8px 40px rgba(0,0,0,.08);
            width: 100%; max-width: 420px; overflow: hidden;
        }

        .card-header {
            background: var(--ink);
            padding: 1.8rem 2rem 1.5rem;
            position: relative; overflow: hidden;
        }
        .card-header::before {
            content: '';
            position: absolute; inset: 0;
            background: repeating-linear-gradient(
                -55deg, transparent, transparent 40px,
                rgba(224,49,49,.07) 40px, rgba(224,49,49,.07) 41px
            );
        }
        .card-header .brand {
            position: relative;
            display: flex; align-items: center; gap: .6rem;
            margin-bottom: 1.2rem;
        }
        .brand-icon {
            width: 34px; height: 34px; background: var(--red);
            border-radius: 6px; display: grid; place-items: center;
        }
        .brand-icon svg { width: 18px; height: 18px; }
        .brand-name {
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: .95rem; color: var(--white);
        }
        .brand-name span { color: var(--red); }

        .card-header h2 {
            position: relative;
            font-family: 'Syne', sans-serif; font-weight: 800;
            font-size: 1.5rem; color: var(--white); line-height: 1.15;
        }
        .card-header p {
            position: relative; color: var(--ash);
            font-size: .86rem; margin-top: .3rem;
        }

        .card-body { padding: 1.8rem 2rem; }

        .step-indicator {
            display: flex; align-items: center; gap: .5rem;
            margin-bottom: 1.5rem;
        }
        .step {
            display: flex; align-items: center; gap: .35rem;
            font-size: .78rem; color: var(--ash);
        }
        .step-num {
            width: 22px; height: 22px; border-radius: 50%;
            display: grid; place-items: center;
            font-size: .72rem; font-weight: 700;
            border: 1.5px solid var(--smoke); color: var(--ash);
        }
        .step.active .step-num { background: var(--red); border-color: var(--red); color: white; }
        .step.active { color: var(--ink); font-weight: 500; }
        .step-sep { flex: 1; height: 1px; background: var(--smoke); }

        .alert {
            padding: .8rem 1rem; border-radius: 8px;
            font-size: .88rem; margin-bottom: 1.2rem; line-height: 1.5;
        }
        .alert-danger  { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

        .demo-link-box {
            background: #f8fafc; border: 1.5px dashed #cbd5e1;
            border-radius: 8px; padding: .9rem 1rem;
            margin-top: .75rem; font-size: .8rem; color: #475569;
            word-break: break-all; line-height: 1.6;
        }
        .demo-link-box strong { display: block; margin-bottom: .3rem; color: var(--ink); }
        .demo-link-box a { color: var(--red); }

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

        .btn-primary {
            width: 100%; padding: .82rem; margin-top: .25rem;
            background: var(--red); color: var(--white); border: none;
            border-radius: 8px; font-family: 'Syne', sans-serif;
            font-weight: 700; font-size: 1rem; cursor: pointer;
            transition: background .15s, transform .1s;
        }
        .btn-primary:hover  { background: var(--red-dk); }
        .btn-primary:active { transform: scale(.98); }

        .card-footer {
            text-align: center; padding: 1rem 2rem 1.4rem;
            font-size: .86rem; color: var(--ash);
            border-top: 1px solid var(--smoke);
        }
        .card-footer a { color: var(--red); text-decoration: none; font-weight: 500; }
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
        <h2><?= ($mode === 'request') ? 'Forgot password?' : 'Set new password' ?></h2>
        <p><?= ($mode === 'request') ? "We'll send a reset link to your inbox." : "Choose a strong new password." ?></p>
    </div>

    <div class="card-body">

        <!-- Step indicator -->
        <div class="step-indicator">
            <div class="step <?= ($mode === 'request') ? 'active' : '' ?>">
                <div class="step-num">1</div> Request link
            </div>
            <div class="step-sep"></div>
            <div class="step <?= ($mode === 'reset') ? 'active' : '' ?>">
                <div class="step-num">2</div> Set password
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- REQUEST MODE -->
        <?php if ($mode === 'request' && !$success): ?>
            <form method="POST" novalidate>
                <input type="hidden" name="action" value="request">
                <div class="field">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email"
                           placeholder="you@example.com" required autocomplete="email">
                </div>
                <button type="submit" class="btn-primary">Send Reset Link</button>
            </form>

        <?php elseif ($mode === 'request' && $success): ?>
            <?php if (filter_var($success, FILTER_VALIDATE_URL)): ?>
                <!-- Demo: show link. In production, email is sent. -->
                <div class="alert alert-success">✓ Reset link generated!</div>
                <div class="demo-link-box">
                    <strong>⚠ Demo mode — copy this link:</strong>
                    <a href="<?= htmlspecialchars($success) ?>"><?= htmlspecialchars($success) ?></a>
                    <br><small>In production this would be emailed to the user.</small>
                </div>
            <?php else: ?>
                <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

        <!-- RESET MODE -->
        <?php elseif ($mode === 'reset'): ?>
            <form method="POST" novalidate>
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="field">
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password"
                           placeholder="Min. 6 characters" required>
                </div>
                <div class="field">
                    <label for="confirm_password">Confirm password</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           placeholder="Repeat password" required>
                </div>
                <button type="submit" class="btn-primary">Reset Password</button>
            </form>
        <?php endif; ?>

    </div>

    <div class="card-footer">
        <a href="login.php">← Back to login</a>
    </div>
</div>

</body>
</html>