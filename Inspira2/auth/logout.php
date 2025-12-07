<?php
session_start();
session_unset();     // remove all session variables
session_destroy();   // destroy the session
header("Location: login.php"); // send user back to login page
exit();
?>
