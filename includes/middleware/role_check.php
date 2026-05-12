<?php
require_once __DIR__ . '/../../config.php';
$allowed = func_get_args();
if (!isLoggedIn() || !in_array($_SESSION['role'], $allowed)) redirect('index.php');
?>