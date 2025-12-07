<?php
$host = "mysql-199333d6-faridabelal2004-bcaa.g.aivencloud.com";
$port = "22124";
$dbname = "defaultdb";
$username = "avnadmin";   // or your MySQL username
$password = "AVNS_ZF6XkN5Zd-Gkg7IGTYc";       // your MySQL password

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
