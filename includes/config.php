<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'disaster_response_system');

// Application Configuration
define('APP_NAME', 'DisasterResponse');
define('SITE_NAME', 'DisasterResponse'); // For compatibility
define('APP_VERSION', '1.0.0');

// Base URL - CHANGE THIS to match your setup
define('BASE_URL', 'http://localhost/disaster_response/');

// Time Zone
date_default_timezone_set('Africa/Nairobi');

// Error Reporting - Enable for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Database connection function
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Set charset
    $conn->set_charset("utf8mb4");
    
    return $conn;
}

// Create global connection variable for backward compatibility
$conn = getDBConnection();

// Helper function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

// Helper function to check user role
function hasRole($role) {
    return isLoggedIn() && $_SESSION['user_role'] === $role;
}

// Helper function to redirect
function redirect($url) {
    header("Location: " . BASE_URL . $url);
    exit();
}

// Helper function to sanitize input
function sanitize($data) {
    global $conn;
    return mysqli_real_escape_string($conn, htmlspecialchars(trim($data)));
}
?>