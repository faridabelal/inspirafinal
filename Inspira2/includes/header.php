<?php
// Always start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load <head> section (Bootstrap, CSS, fonts)
require_once "head.php";

// Load the correct navbar based on user/admin/guest
require_once "navbar.php";
?>
