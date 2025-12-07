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
    SELECT 
        B.board_id,
        B.name,
        U.username,
        B.created_at,
        COUNT(BI.item_id) AS items
    FROM Board B
    JOIN Users_SP U ON B.user_id = U.user_id
    LEFT JOIN Board_Item BI ON B.board_id = BI.board_id
    GROUP BY B.board_id
    ORDER BY B.created_at DESC
");
$boards = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mt-4">
    <h2>All Boards</h2>

    <table class="table table-bordered mt-3">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Board Name</th>
                <th>User</th>
                <th>Items</th>
                <th>Created At</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($boards as $b): ?>
                <tr>
                    <td><?= $b["board_id"] ?></td>
                    <td><?= htmlspecialchars($b["name"]) ?></td>
                    <td><?= htmlspecialchars($b["username"]) ?></td>
                    <td><?= $b["items"] ?></td>
                    <td><?= $b["created_at"] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../includes/footer.php"; ?>
