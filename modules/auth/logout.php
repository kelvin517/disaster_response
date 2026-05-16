<?php
session_start();
session_destroy();
header("Location: /disaster_response/index.php");
exit();
?>