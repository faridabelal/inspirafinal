<?php
session_start();
require_once "../includes/db.php";
require_once "../includes/header.php";
require_once "../includes/navbar.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../index.php");
    exit();
}

$stmt = $pdo->query("
    SELECT SH.history_id, U.username, SH.query, SH.created_at
    FROM SearchHistory_SP SH
    JOIN Users_SP U ON SH.user_id = U.user_id
    ORDER BY SH.created_at DESC
");
$searches = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mt-4">
    <h2>Search History</h2>

    <table class="table table-bordered mt-3">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>User</th>
                <th>Query</th>
                <th>Time</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($searches as $s): ?>
                <tr>
                    <td><?= $s["history_id"] ?></td>
                    <td><?= htmlspecialchars($s["username"]) ?></td>
                    <td><?= htmlspecialchars($s["query"]) ?></td>
                    <td><?= $s["created_at"] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../includes/footer.php"; ?>
