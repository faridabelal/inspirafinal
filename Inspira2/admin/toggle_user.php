<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once "../includes/db.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../index.php");
    exit();
}

$userId = $_GET["id"] ?? null;

if ($userId) {
    // Toggle role
    $stmt = $pdo->prepare("
        UPDATE Users_SP 
        SET role = CASE 
            WHEN role = 'disabled' THEN 'user'
            ELSE 'disabled'
        END
        WHERE user_id = ? AND role != 'admin'
    ");
    $stmt->execute([$userId]);
}

header("Location: users.php");
exit();
?>
