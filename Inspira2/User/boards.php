<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once "../includes/db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];

// 🪄 Handle new board creation
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create_board"])) {
    $name = trim($_POST["name"]);
    if (!empty($name)) {
        $stmt = $pdo->prepare("INSERT INTO Board (user_id, name) VALUES (?, ?)");
        $stmt->execute([$user_id, $name]);
        header("Location: boards.php?msg=" . urlencode("✅ Board created!"));
        exit();
    }
}

// 🩶 Fetch all boards
$stmt = $pdo->prepare("SELECT * FROM Board WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$boards = $stmt->fetchAll(PDO::FETCH_ASSOC);
$msg = $_GET["msg"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>📌 My Boards - Inspira</title>
<style>
body {
  font-family: 'Poppins', sans-serif;
  background-color: #fafafa;
  color: #111;
  margin: 0;
  padding: 20px;
}
h1 {
  text-align: center;
  margin-top: 25px;
  margin-bottom: 20px;
}
form {
  display: flex;
  justify-content: center;
  gap: 10px;
  margin-bottom: 25px;
}
form input[type="text"] {
  padding: 8px 12px;
  border: 1px solid #ccc;
  border-radius: 8px;
  font-size: 1rem;
  width: 250px;
}
form button {
  background: #9F9A7F;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 8px 16px;
  cursor: pointer;
  font-weight: 600;
}
form button:hover {
  background: #8c8770;
}
ul {
  list-style: none;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 15px;
}
li {
  background: #fff;
  border-radius: 15px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  width: 200px;
  height: 120px;
  transition: transform .2s ease, box-shadow .2s ease;
  overflow: hidden;
}

li:hover {
  transform: translateY(-4px);
  box-shadow: 0 6px 14px rgba(0,0,0,0.15);
}

a.board-link {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  text-decoration: none;
  color: #333;
  font-weight: 600;
  font-size: 1rem;
  cursor: pointer;
}

a.board-link:hover {
  background-color: #f6f3ed; /* soft hover highlight */
  color: #000;
}

.message {
  text-align: center;
  margin-bottom: 15px;
  color: #333;
  font-weight: 500;
}
</style>
</head>
<body>

<?php include "../includes/navbar.php"; ?>

<h1>Your Boards</h1>

<?php if ($msg): ?>
  <p class="message"><?= htmlspecialchars($msg) ?></p>
<?php endif; ?>

<form method="POST">
  <input type="text" name="name" placeholder="New board name" required>
  <button type="submit" name="create_board">Create</button>
</form>

<?php if (empty($boards)): ?>
  <p style="text-align:center;">You don’t have any boards yet. Create one above!</p>
<?php else: ?>
  <ul>
    <?php foreach ($boards as $b): ?>
      <li>
      <a class="board-link" href="/~febelal/Inspira/User/board_view.php?id=<?= $b['board_id'] ?>">

          <?= htmlspecialchars($b['name']) ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

</body>
</html>

