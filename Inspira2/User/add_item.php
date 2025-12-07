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

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../search.php");
    exit();
}

$title  = trim($_POST["title"] ?? '');
$poster = trim($_POST["poster"] ?? '');
$type   = trim($_POST["type"] ?? 'unknown');

if ($title === '' || $type === '') {
    header("Location: ../search.php?msg=" . urlencode("❌ Invalid item data."));
    exit();
}

// 1️⃣ Ensure Media_Item exists
try {
    $sel = $pdo->prepare("SELECT item_id FROM Media_Item WHERE title = ? AND type = ?");
    $sel->execute([$title, $type]);
    $media = $sel->fetch(PDO::FETCH_ASSOC);

    if ($media) {
        $item_id = (int)$media["item_id"];
        if (!empty($poster)) {
            $upd = $pdo->prepare("UPDATE Media_Item SET poster = ? WHERE item_id = ?");
            $upd->execute([$poster, $item_id]);
        }
    } else {
        $ins = $pdo->prepare("INSERT INTO Media_Item (title, type, poster, api_source) VALUES (?, ?, ?, 'api')");
        $ins->execute([$title, $type, $poster]);
        $item_id = (int)$pdo->lastInsertId();
    }
} catch (Throwable $e) {
    die("DB error (Media_Item): " . htmlspecialchars($e->getMessage()));
}

// 2️⃣ Add to Favorites or Board
try {
    // ❤️ Add to Favorites
    if (isset($_POST["favorite"])) {
        $checkFav = $pdo->prepare("SELECT 1 FROM Favorites_SP WHERE user_id = ? AND item_id = ?");
        $checkFav->execute([$user_id, $item_id]);

        if (!$checkFav->fetchColumn()) {
            $favStmt = $pdo->prepare("INSERT INTO Favorites_SP (user_id, item_id) VALUES (?, ?)");
            $favStmt->execute([$user_id, $item_id]);
            header("Location: favorites.php?msg=" . urlencode("✅ Added to favorites!"));
        } else {
            header("Location: favorites.php?msg=" . urlencode("⚠️ Already in favorites."));
        }
        exit();
    }

    // 🆕 Create board with user-provided name
    if (isset($_POST["new_board_named"])) {
        $boardName = trim($_POST["board_name"]);

        if ($boardName === "") {
            header("Location: ../search.php?msg=" . urlencode("⚠️ Board name cannot be empty."));
            exit();
        }

        // 1) Create board
        $stmt = $pdo->prepare("INSERT INTO Board (user_id, name) VALUES (?, ?)");
        $stmt->execute([$user_id, $boardName]);
        $board_id = (int)$pdo->lastInsertId();

        // 2) Attach media item
        $add = $pdo->prepare("INSERT INTO Board_Item (board_id, item_id) VALUES (?, ?)");
        $add->execute([$board_id, $item_id]);

        header("Location: ../User/boards.php?msg=" . urlencode("✅ Board created and item added!"));
        exit();
    }


    // 📌 Save to existing Board
    if (isset($_POST["save_board"])) {
        $board_id = trim($_POST["board_id"] ?? '');
        if ($board_id === '') {
            header("Location: boards.php?msg=" . urlencode("⚠️ No board selected."));
            exit();
        }

        $own = $pdo->prepare("SELECT 1 FROM Board WHERE board_id = ? AND user_id = ?");
        $own->execute([$board_id, $user_id]);
        if (!$own->fetchColumn()) {
            header("Location: boards.php?msg=" . urlencode("❌ You don't have access to this board."));
            exit();
        }

        $checkBoard = $pdo->prepare("SELECT 1 FROM Board_Item WHERE board_id = ? AND item_id = ?");
        $checkBoard->execute([$board_id, $item_id]);

        if (!$checkBoard->fetchColumn()) {
            $add = $pdo->prepare("INSERT INTO Board_Item (board_id, item_id) VALUES (?, ?)");
            $add->execute([$board_id, $item_id]);
            header("Location: boards.php?msg=" . urlencode("📌 Added to board!"));
        } else {
            header("Location: boards.php?msg" . urlencode("⚠️ Already saved to this board."));
        }
        exit();
    }

    // If no button clicked
    header("Location: ../search.php?msg=" . urlencode("⚠️ No action specified."));
    exit();

} catch (Throwable $e) {
    die("DB error (favorites/boards): " . htmlspecialchars($e->getMessage()));
}