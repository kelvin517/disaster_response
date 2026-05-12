<?php
// Logout script - terminates user session
require_once __DIR__ . '/../../includes/config/config.php';

// Destroy session completely
$_SESSION = array();

// Delete session cookie if set
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to home page with logout message
session_start(); // start a new session for flash message
$_SESSION['message'] = "You have been logged out successfully.";
$_SESSION['message_type'] = "success";
header("Location: " . BASE_URL . "index.php");
exit();
?>