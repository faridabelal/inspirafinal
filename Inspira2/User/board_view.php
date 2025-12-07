<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);


session_start();
require_once "../includes/db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$board_id = $_GET["id"] ?? null;

if (!$board_id) {
    header("Location: boards.php");
    exit();
}

// 🧩 Verify board ownership
$stmt = $pdo->prepare("SELECT * FROM Board WHERE board_id = ? AND user_id = ?");
$stmt->execute([$board_id, $user_id]);
$board = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$board) {
    die("❌ Board not found or access denied.");
}

$msg = "";

// 🧠 Rename board
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["rename_board"])) {
    $new_name = trim($_POST["new_name"]);
    if (!empty($new_name)) {
        $update = $pdo->prepare("UPDATE Board SET name = ? WHERE board_id = ? AND user_id = ?");
        $update->execute([$new_name, $board_id, $user_id]);
        $msg = "✅ Board renamed successfully!";
        $board["name"] = $new_name;
    }
}

// 🗑️ Delete board
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_board"])) {
    $del = $pdo->prepare("DELETE FROM Board WHERE board_id = ? AND user_id = ?");
    $del->execute([$board_id, $user_id]);
    header("Location: boards.php?msg=" . urlencode("🗑️ Board deleted."));
    exit();
}

// 🗑️ Delete item from board
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_item"])) {
    $board_item_id = $_POST["board_item_id"];
    $del = $pdo->prepare("DELETE FROM Board_Item WHERE board_item_id = ?");
    $del->execute([$board_item_id]);
    $msg = "🗑️ Item removed!";
}

// 🩶 Fetch all media items
$query = $pdo->prepare("
    SELECT bi.board_item_id, mi.title, mi.poster, mi.type, mi.author_artist
    FROM Board_Item bi
    JOIN Media_Item mi ON bi.item_id = mi.item_id
    WHERE bi.board_id = ?
    ORDER BY bi.added_at DESC
");
$query->execute([$board_id]);
$items = $query->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($board["name"]) ?> - Inspira Board</title>
<style>
body {
  font-family: 'Poppins', sans-serif;
  background-color: #fafafa;
  color: #111;
  margin: 0;
  padding: 20px;
}
.board-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
}
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 20px;
}
.card {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 4px 10px rgba(0,0,0,0.1);
  padding: 10px;
  text-align: center;
  transition: transform .2s ease;
}
.card:hover { transform: translateY(-4px); }
.card img {
  width: 100%;
  border-radius: 10px;
  height: 240px;
  object-fit: cover;
}
.card h3 {
  font-size: 1rem;
  margin: 8px 0;
}
button {
  background: #9F9A7F;
  color: #fff;
  border: none;
  border-radius: 6px;
  padding: 5px 10px;
  cursor: pointer;
}
button:hover {
  background: #8c8770;
}
form.rename, form.delete {
  display: flex;
  justify-content: center;
  gap: 8px;
  margin-bottom: 15px;
}
form.rename input {
  padding: 6px 10px;
  border: 1px solid #ccc;
  border-radius: 8px;
  font-size: 1rem;
}
.message {
  text-align: center;
  color: #333;
  margin-bottom: 20px;
  font-weight: 500;
}
.back {
  text-decoration:none;
  color:#9F9A7F;
  font-weight:600;
}
</style>
</head>
<body>

<?php include "../includes/navbar.php"; ?>

<div class="board-header">
  <h1>📌 <?= htmlspecialchars($board["name"]) ?></h1>
  <a class="back" href="boards.php">⬅ Back to Boards</a>
</div>

<?php if ($msg): ?>
  <p class="message"><?= htmlspecialchars($msg) ?></p>
<?php endif; ?>

<!-- Rename Board -->
<form class="rename" method="POST">
  <input type="text" name="new_name" placeholder="Rename board..." required>
  <button type="submit" name="rename_board">✏️ Rename</button>
</form>

<!-- Delete Board -->
<form class="delete" method="POST" onsubmit="return confirm('Delete this board and all its items?');">
  <button type="submit" name="delete_board" style="background:#c04b4b;">🗑️ Delete Board</button>
</form>

<?php if (empty($items)): ?>
  <p style="text-align:center;">No items in this board yet. You can add some from <a href="../search.php">Search</a>.</p>
<?php else: ?>
  <div class="grid">
    <?php foreach ($items as $item): ?>
      <div class="card">
        <?php if ($item["poster"]): ?>
          <img src="<?= htmlspecialchars($item["poster"]) ?>" alt="Poster">
        <?php endif; ?>
        <h3><?= htmlspecialchars($item["title"]) ?></h3>
        <p><?= htmlspecialchars(strtoupper($item["type"])) ?></p>
        <?php if ($item["author_artist"]): ?>
          <p><i><?= htmlspecialchars($item["author_artist"]) ?></i></p>
        <?php endif; ?>
        <form method="POST" style="margin-top:8px;">
          <input type="hidden" name="board_item_id" value="<?= $item['board_item_id'] ?>">
          <button type="submit" name="delete_item">🗑️ Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

</body>
</html>

