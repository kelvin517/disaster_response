<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'disaster_response_system');

// Application configuration
define('BASE_URL', 'http://localhost/disaster_response/');
define('APP_NAME', 'DisasterResponse');

// Set timezone
date_default_timezone_set('Africa/Nairobi');

// Enable error reporting (disable on production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create PDO connection
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ========== BASIC HELPER FUNCTIONS (NOT duplicated in auth.php) ==========

if (!function_exists('sanitize')) {
    function sanitize($data) {
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: " . BASE_URL . ltrim($url, '/'));
        exit();
    }
}

if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['role']);
    }
}

if (!function_exists('hasRole')) {
    function hasRole($role) {
        if (!isLoggedIn()) return false;
        if (is_array($role)) {
            return in_array($_SESSION['role'], $role);
        }
        return $_SESSION['role'] === $role;
    }
}

// NOTE: requireLogin() and requireRole() are defined in includes/functions/auth.php
// Do NOT define them here to avoid redeclaration error
?>