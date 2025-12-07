<?php
session_start();
require_once "../includes/db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["fav_id"])) {
    $fav_id = $_POST["fav_id"];
    $stmt = $pdo->prepare("DELETE FROM Favorites_SP WHERE fav_id = ? AND user_id = ?");
    $stmt->execute([$fav_id, $_SESSION["user_id"]]);
}

header("Location: favorites.php?msg=" . urlencode("Favorite removed!"));
exit();
?>
