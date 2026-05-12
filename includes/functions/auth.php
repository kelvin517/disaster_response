<?php
function login($email, $password, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['email'] = $user['email'];
        return true;
    }
    return false;
}

function logout() {
    session_destroy();
    redirect('index.php');
}

function requireLogin() {
    if (!isLoggedIn()) redirect('modules/auth/login.php');
}

function requireRole($role) {
    requireLogin();
    if (!isRole($role)) redirect('index.php');
}
?>