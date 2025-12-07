<?php
session_start();
require_once "../includes/db.php";


if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$msg = $_GET["msg"] ?? "";

// 🩶 Fetch all favorites with media info
$stmt = $pdo->prepare("
    SELECT f.fav_id, m.title, m.poster, m.type, m.author_artist, m.category
    FROM Favorites_SP f
    JOIN Media_Item m ON f.item_id = m.item_id
    WHERE f.user_id = ?
    ORDER BY f.created_at DESC
");
$stmt->execute([$user_id]);
$favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Favorites - Inspira</title>
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
  margin-bottom: 30px;
}
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 20px;
}
.card {
  background: #fff;
  border-radius: 15px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  padding: 12px;
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
.card h3 { font-size: 1rem; margin: 10px 0 5px; }
.card p { font-size: 0.9rem; color: #555; }
button {
  background: #9F9A7F;
  color: #fff;
  border: none;
  border-radius: 6px;
  padding: 5px 10px;
  cursor: pointer;
}
.message {
  text-align: center;
  color: #333;
  margin-bottom: 15px;
}
</style>
</head>
<body>

<?php include "../includes/navbar.php"; ?>

<h1>My Favorites</h1>

<?php if ($msg): ?>
  <p class="message"><?= htmlspecialchars($msg) ?></p>
<?php endif; ?>

<?php if (empty($favorites)): ?>
  <p style="text-align:center;">You don’t have any favorites yet.</p>
<?php else: ?>
  <div class="grid">
    <?php foreach ($favorites as $f): ?>
      <div class="card">
        <?php if ($f["poster"]): ?>
          <img src="<?= htmlspecialchars($f["poster"]) ?>" alt="Poster">
        <?php endif; ?>
        <h3><?= htmlspecialchars($f["title"]) ?></h3>
        <p><?= strtoupper(htmlspecialchars($f["type"])) ?></p>
        <?php if ($f["author_artist"]): ?>
          <p><i><?= htmlspecialchars($f["author_artist"]) ?></i></p>
        <?php endif; ?>
        <form method="POST" action="remove_favorite.php">
          <input type="hidden" name="fav_id" value="<?= $f['fav_id'] ?>">
          <button type="submit">🗑️ Remove</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

</body>
</html>


