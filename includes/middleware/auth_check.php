<?php
require_once __DIR__ . '/../../config.php';
if (!isLoggedIn()) redirect('modules/auth/login.php');
?>