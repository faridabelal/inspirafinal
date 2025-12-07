<?php
$host = "localhost";
$dbname = "febelal";
$username = "febelal";   // or your MySQL username
$password = "oleMissF25";       // your MySQL password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}
?>
