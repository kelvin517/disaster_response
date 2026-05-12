<?php
function validateEmail($email) { return filter_var($email, FILTER_VALIDATE_EMAIL); }
function validatePhone($phone) { return preg_match('/^\+?[0-9]{10,15}$/', $phone); }
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function verifyCSRFToken($token) { return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token); }
?>