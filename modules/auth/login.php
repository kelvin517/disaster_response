<?php
require_once __DIR__ . '/../../includes/config/config.php';

if (!isset($pdo)) die("Database connection error.");

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT id, full_name, email, password, role FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];

        // Redirect based on role
        switch ($user['role']) {
            case 'admin': redirect('modules/admin/dashboard.php'); break;
            case 'responder': redirect('modules/responders/dashboard.php'); break;
            case 'volunteer': redirect('modules/volunteers/my_tasks.php'); break;
            default: redirect('modules/incidents/report.php');
        }
    } else {
        $error = "Invalid email or password, or account inactive.";
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Login</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow">
                <div class="card-header bg-danger text-white"><h4>Login</h4></div>
                <div class="card-body">
                    <?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
                    <form method="POST">
                        <div class="mb-3"><label>Email</label><input type="email" name="email" class="form-control" required></div>
                        <div class="mb-3"><label>Password</label><input type="password" name="password" class="form-control" required></div>
                        <button type="submit" class="btn btn-danger w-100">Login</button>
                    </form>
                    <hr><p class="text-center"><a href="register.php">Create an account</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>