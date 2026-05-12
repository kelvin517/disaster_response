<?php
// Password Reset Handler (Request + Reset)
require_once __DIR__ . '/../../includes/config/config.php';

$error = null;
$success = null;
$mode = 'request'; // request or reset

// Check if we are in "reset" mode (token provided)
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $mode = 'reset';
    $token = $_GET['token'];

    // Verify token from database
    $stmt = $pdo->prepare("SELECT user_id, expires_at FROM password_resets WHERE token = ? AND used = 0");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset || strtotime($reset['expires_at']) < time()) {
        $error = "Invalid or expired reset link. Please request a new one.";
        $mode = 'request';
    } else {
        $user_id = $reset['user_id'];
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- REQUEST RESET LINK ---
    if ($action === 'request') {
        $email = sanitize($_POST['email'] ?? '');
        if (empty($email)) {
            $error = "Email address is required.";
        } else {
            // Check if user exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user) {
                $error = "No account found with that email address.";
            } else {
                // Generate unique token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Store token in database (create table if not exists)
                $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token VARCHAR(100) NOT NULL UNIQUE,
                    expires_at DATETIME NOT NULL,
                    used BOOLEAN DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
                $stmt->execute([$user['id'], $token, $expires]);

                // Build reset link
                $resetLink = BASE_URL . "modules/auth/password_reset.php?token=" . $token;

                // In a real system, send email here. For demo, display link.
                $success = "A password reset link has been generated. <br>
                            <strong>Demo link (copy and paste in browser):</strong><br>
                            <a href='$resetLink' target='_blank'>$resetLink</a><br>
                            <small>In production, this would be sent to your email.</small>";
            }
        }
    }

    // --- RESET PASSWORD (submit new password) ---
    elseif ($action === 'reset') {
        $token = $_POST['token'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate token again
        $stmt = $pdo->prepare("SELECT user_id FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()");
        $stmt->execute([$token]);
        $reset = $stmt->fetch();
        if (!$reset) {
            $error = "Invalid or expired reset token.";
        } elseif (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters.";
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            // Update user's password
            $hashed = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $reset['user_id']]);

            // Mark token as used
            $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
            $stmt->execute([$token]);

            $success = "Password has been reset successfully. <a href='login.php'>Click here to login</a>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Password Reset</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); margin-top: 80px; }
        .btn-danger { background-color: #dc3545; border: none; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card">
                <div class="card-header bg-danger text-white text-center">
                    <h4><?php echo ($mode === 'request') ? 'Reset Password' : 'Create New Password'; ?></h4>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>

                    <?php if ($mode === 'request' && !$success): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="request">
                            <div class="mb-3">
                                <label>Email Address</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-danger w-100">Send Reset Link</button>
                        </form>
                        <hr>
                        <p class="text-center"><a href="login.php">Back to Login</a></p>
                    <?php elseif ($mode === 'reset' && !$success): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="reset">
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                            <div class="mb-3">
                                <label>New Password</label>
                                <input type="password" name="new_password" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Confirm Password</label>
                                <input type="password" name="confirm_password" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-danger w-100">Reset Password</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>