<?php
// Enable error reporting for debugging (remove after fixing)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Determine correct path to config.php
$configPath = __DIR__ . '/../../includes/config/config.php';

if (!file_exists($configPath)) {
    die("Config file not found at: " . $configPath);
}

require_once $configPath;

// Verify that $pdo is available
if (!isset($pdo)) {
    die("PDO object not available. Check your config.php");
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $full_name = sanitize($_POST['full_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = sanitize($_POST['role'] ?? 'victim');

    // Validation
    if (empty($full_name) || empty($email) || empty($phone) || empty($password)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "Email already registered. Please login.";
        } else {
            // Use email as username (unique and safe)
            $username = $email;

            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            // Modified INSERT to include username column
            $stmt = $pdo->prepare("INSERT INTO users (full_name, email, phone, password, role, username) VALUES (?, ?, ?, ?, ?, ?)");
            try {
                if ($stmt->execute([$full_name, $email, $phone, $hashedPassword, $role, $username])) {
                    $success = "Registration successful! You can now login.";
                } else {
                    $error = "Registration failed. Please try again.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Disaster Response System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .btn-danger { background-color: #dc3545; border: none; }
        .btn-danger:hover { background-color: #c82333; }
    </style>
</head>
<body>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-danger text-white text-center">
                    <h4 class="mb-0"><i class="fas fa-user-plus"></i> Create Account</h4>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($success); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" 
                                   value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="phone" name="phone" 
                                   value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                            <div class="form-text">Minimum 6 characters.</div>
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">I am a:</label>
                            <select class="form-select" id="role" name="role">
                                <option value="victim" <?php echo (isset($_POST['role']) && $_POST['role'] == 'victim') ? 'selected' : ''; ?>>Victim / Public (Need Help)</option>
                                <option value="responder" <?php echo (isset($_POST['role']) && $_POST['role'] == 'responder') ? 'selected' : ''; ?>>Emergency Responder</option>
                                <option value="volunteer" <?php echo (isset($_POST['role']) && $_POST['role'] == 'volunteer') ? 'selected' : ''; ?>>Volunteer (Want to Help)</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-danger w-100">Register</button>
                    </form>
                    <hr>
                    <p class="text-center mb-0">Already have an account? <a href="login.php">Login here</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>