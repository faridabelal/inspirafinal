<?php
session_start();
require_once "../includes/db.php";
require_once "../includes/head.php";
require_once "../includes/navbar.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../index.php");
    exit();
}

// Get counts
$totalUsers = $pdo->query("SELECT COUNT(*) FROM Users_SP")->fetchColumn();
$totalBoards = $pdo->query("SELECT COUNT(*) FROM Board")->fetchColumn();
$totalSearches = $pdo->query("SELECT COUNT(*) FROM SearchHistory_SP")->fetchColumn();
?>

<div class="container mt-4">
    <h2>Admin Dashboard</h2>

    <div class="row mt-4">

        <div class="col-md-4">
            <div class="card text-center p-3 shadow">
                <h4>Users</h4>
                <p><?= $totalUsers ?></p>
                <a href="users.php" class="btn btn-dark">View Users</a>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card text-center p-3 shadow">
                <h4>Boards</h4>
                <p><?= $totalBoards ?></p>
                <a href="admin_boards.php" class="btn btn-dark">View Boards</a>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card text-center p-3 shadow">
                <h4>Search History</h4>
                <p><?= $totalSearches ?></p>
                <a href="searches.php" class="btn btn-dark">View Searches</a>
            </div>
        </div>

    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
