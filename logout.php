<?php
session_start();
session_unset();
session_destroy();

// Redirect bersih ke login.php
header("Location: login.php");
exit();
?>