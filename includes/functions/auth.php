<?php
// Authentication helper functions
// Note: isLoggedIn(), hasRole(), redirect(), and sanitize() are defined in config.php

if (!function_exists('login')) {
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
}

if (!function_exists('logout')) {
    function logout() {
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        redirect('index.php');
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin() {
        if (!isLoggedIn()) {
            redirect('modules/auth/login.php');
        }
    }
}

if (!function_exists('requireRole')) {
    function requireRole($role) {
        requireLogin();
        if (!hasRole($role)) {
            redirect('index.php');
        }
    }
}

if (!function_exists('role_guard')) {
    function role_guard($allowed_roles) {
        requireLogin();
        if (!hasRole($allowed_roles)) {
            http_response_code(403);
            die("Access denied. You don't have permission to view this page.");
        }
    }
}
?>