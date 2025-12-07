<?php
session_start();
require_once "../includes/db.php";
require_once "../includes/header.php";
require_once "../includes/navbar.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../index.php");
    exit();
}

$stmt = $pdo->query("SELECT user_id, username, email, role, created_at, last_login FROM Users_SP ORDER BY created_at DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mt-4">
    <h2>All Users</h2>

    <table class="table table-bordered mt-3">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Created At</th>
                <th>Last Login</th>
                <th>Action</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= $u["user_id"] ?></td>
                    <td><?= htmlspecialchars($u["username"]) ?></td>
                    <td><?= htmlspecialchars($u["email"]) ?></td>
                    <td><?= $u["role"] ?></td>
                    <td><?= $u["created_at"] ?></td>
                    <td><?= $u["last_login"] ?? "Never" ?></td>
                    <td>
                        <?php if ($u["role"] !== "admin"): ?>
                            <a href="toggle_user.php?id=<?= $u["user_id"] ?>" class="btn btn-sm btn-danger">
                                <?= $u["role"] === "disabled" ? "Enable" : "Disable" ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">Admin</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once "../includes/footer.php"; ?>
