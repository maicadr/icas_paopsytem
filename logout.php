<?php
session_start();

// Destroy session
session_unset();
session_destroy();

// Delete cookies
setcookie('user_id', '', time() - 3600, "/");
setcookie('role', '', time() - 3600, "/");

// Redirect to login page
header("Location: index.php");
exit();
?>