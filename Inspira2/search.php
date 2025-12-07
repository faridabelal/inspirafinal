<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);
session_start();

require_once "includes/db.php";
require_once "api/openai.php";
require_once "api/tmdb.php";
require_once "api/books.php";
require_once "api/spotify.php";

function h($x) { return htmlspecialchars($x ?? "", ENT_QUOTES, "UTF-8"); }

/* ==========================================================
   Detect if user explicitly mentions a media category
   ========================================================== */
function detectExplicitCategoryFromQuery(string $query): ?string {
    $q = strtolower($query);

    if (preg_match('/\b(movie|movies|film|films)\b/', $q)) return "movies";
    if (preg_match('/\b(tv|show|shows|series)\b/', $q))   return "tv";
    if (preg_match('/\b(book|books|novel|novels)\b/', $q)) return "books";
    if (preg_match('/\b(song|songs|music|track|tracks)\b/', $q)) return "music";

    return null;
}

$query = "";
$errorMsg = "";

$movies = $tvShows = $books = $music = [];
$primaryCategory = "movies"; // sensible default for first load

/* ==========================================================
   RESTORE LAST SEARCH IF PAGE WAS RELOADED (GET REQUEST)
   ========================================================== */
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_SESSION["last_search_results"])) {
    $query = $_SESSION["last_search_query"];
    $movies = $_SESSION["last_search_results"]["movies"];
    $tvShows = $_SESSION["last_search_results"]["tv"];
    $books = $_SESSION["last_search_results"]["books"];
    $music = $_SESSION["last_search_results"]["music"];
    $primaryCategory = $_SESSION["last_search_results"]["primary"];
}


if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["search"])) {

    $query = trim($_POST["search"]);

    if ($query !== "") {

        try {
            /* ==========================================================
               1. Ask OpenAI to understand user intent
               ========================================================== */
            $analysis = analyzeQuery($query) ?? [];

            $detectedCategory = strtolower($analysis["category"] ?? "mixed");
            $refTitle         = $analysis["reference_title"] ?? null;
            $keywords         = $analysis["keywords"] ?? [];

            if (empty($keywords)) {
                $keywords = preg_split("/\s+/", $query);
            }

            /* ==========================================================
               2. Check if user explicitly specified a category
               ========================================================== */
            $explicitCategory = detectExplicitCategoryFromQuery($query);

            /* ==========================================================
               RULE 1: Explicit category → ONLY that category
               ========================================================== */
            if ($explicitCategory !== null) {

                $primaryCategory = $explicitCategory;

                if ($explicitCategory === "movies") {
                    $movies = getMovies($keywords, "neutral", $refTitle);
                } elseif ($explicitCategory === "tv") {
                    $tvShows = getTVShows($keywords, "neutral", $refTitle);
                } elseif ($explicitCategory === "books") {
                    $books = getBooks($keywords, "neutral", $refTitle);
                } elseif ($explicitCategory === "music") {
                    $music = getSongs($keywords, "neutral");
                }

            }

            /* ==========================================================
               RULE 2: No explicit category → cross-media
               ========================================================== */
            else {

                $primaryCategory = $detectedCategory;

                $movies  = getMovies($keywords, "neutral", $refTitle);
                $tvShows = getTVShows($keywords, "neutral", $refTitle);
                $books   = getBooks($keywords, "neutral", $refTitle);
                $music   = getSongs($keywords, "neutral");
            }

            /* ==========================================================
               SAVE SEARCH INTO SearchHistory_SP
               ========================================================== */
            if (isset($_SESSION["user_id"])) {
                $stmt = $pdo->prepare("INSERT INTO SearchHistory_SP (user_id, query) VALUES (?, ?)");
                $stmt->execute([$_SESSION["user_id"], $query]);
            }

              /* ==========================================================
              SAVE EXACT ITEM(S) INTO RecentResults_SP
              ========================================================== */

            if (isset($_SESSION["user_id"]) && !empty($refTitle)) {

                  $user_id = $_SESSION["user_id"];

                  // First detect explicit category
                  $explicit = detectExplicitCategoryFromQuery($query);

                  // List of exact matchers
                  $matches = [];

                  // CATEGORY EXPLICIT → only check that category
                  if ($explicit === "movies") {
                      if ($m = getExactMovie($refTitle)) {
                          $matches[] = ["type" => "movie", "item" => $m];
                      }
                  }
                  elseif ($explicit === "tv") {
                      if ($m = getExactTVShow($refTitle)) {
                          $matches[] = ["type" => "tv", "item" => $m];
                      }
                  }
                  elseif ($explicit === "books") {
                      if ($m = getExactBook($refTitle)) {
                          $matches[] = ["type" => "book", "item" => $m];
                      }
                  }
                  elseif ($explicit === "music") {
                      if ($m = getExactSong($refTitle)) {
                          $matches[] = ["type" => "song", "item" => $m];
                      }
                  }

                  // NO CATEGORY EXPLICIT → check ALL types
                  if ($explicit === null) {

                      if ($m = getExactMovie($refTitle)) $matches[] = ["type" => "movie", "item" => $m];
                      if ($m = getExactTVShow($refTitle)) $matches[] = ["type" => "tv", "item" => $m];
                      if ($m = getExactBook($refTitle))  $matches[] = ["type" => "book", "item" => $m];
                      if ($m = getExactSong($refTitle))  $matches[] = ["type" => "song", "item" => $m];
                  }

                  // Save ALL matches found
                  foreach ($matches as $m) {

                      $type = $m["type"];
                      $item = $m["item"];

                      if (!$item) continue;

                      // Normalize fields
                      $title  = $item["title"] ?? "";
                      $poster = $item["poster"] ?? ($item["cover"] ?? null);
                      $author = $item["author"] ?? ($item["artist"] ?? null);
                      $year   = $item["year"] ?? null;

                      // Check for existing entry
                      $check = $pdo->prepare("
                          SELECT id FROM RecentResults_SP
                          WHERE user_id = ? AND title = ? AND type = ?
                      ");
                      $check->execute([$user_id, $title, $type]);
                      $existingId = $check->fetchColumn();

                      if ($existingId) {
                          // Update timestamp + poster
                          $upd = $pdo->prepare("
                              UPDATE RecentResults_SP
                              SET created_at = NOW(), poster = ?, author_artist = ?
                              WHERE id = ?
                          ");
                          $upd->execute([$poster, $author ?? $year, $existingId]);
                      } else {
                          // Insert new
                          $ins = $pdo->prepare("
                              INSERT INTO RecentResults_SP (user_id, title, type, poster, author_artist)
                              VALUES (?, ?, ?, ?, ?)
                          ");
                          $ins->execute([
                              $user_id,
                              $title,
                              $type,
                              $poster,
                              $author ?? $year
                          ]);
                      }
                  }

                  // Keep only 10 newest
                  $cleanup = $pdo->prepare("
                      DELETE FROM RecentResults_SP
                      WHERE user_id = ?
                      AND id NOT IN (
                          SELECT id FROM (
                              SELECT id 
                              FROM RecentResults_SP 
                              WHERE user_id = ?
                              ORDER BY created_at DESC 
                              LIMIT 30
                          ) AS temp
                      )
                  ");
                  $cleanup->execute([$user_id, $user_id]);
                
            }

        } catch (Exception $e) {
            $errorMsg = "⚠️ " . $e->getMessage();
        }
    }
    /* ==========================================================
    SAVE SEARCH RESULTS TO SESSION + REDIRECT (PRG Pattern)
    ========================================================== */
    $_SESSION["last_search_query"] = $query;
    $_SESSION["last_search_results"] = [
        "movies" => $movies,
        "tv" => $tvShows,
        "books" => $books,
        "music" => $music,
        "primary" => $primaryCategory
    ];

    //header("Location: search.php");
    //exit();

}

/* ==========================================================
   FETCH USER BOARDS (for Add to Board dropdown)
   ========================================================== */
$userBoards = [];
if (isset($_SESSION["user_id"])) {
    $stmt = $pdo->prepare("SELECT board_id, name FROM Board WHERE user_id = ?");
    $stmt->execute([$_SESSION["user_id"]]);
    $userBoards = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Inspira Search</title>

<link rel="stylesheet" href="styling/dropdown.css">
<script src="scripts/dropdown.js" defer></script>

<style>
body {
  font-family: 'Poppins', sans-serif;
  background-color: #fafafa;
  color: #111;
  margin: 0;
  padding: 0;
}
.container {
  width: 90%;
  margin: 40px auto;
}
.search-bar {
  width: 100%;
  padding: 12px 18px;
  font-size: 1.1rem;
  border-radius: 30px;
  border: 1px solid #ccc;
  outline: none;
  margin-bottom: 30px;
}
.section {
  margin-top: 40px;
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
}
.card img {
  width: 100%;
  border-radius: 10px;
  height: 250px;
  object-fit: cover;
}
</style>

<style>
/* Modal Overlay */
#boardModal {
  display: none; 
  position: fixed;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: rgba(0,0,0,0.55);
  backdrop-filter: blur(3px);
  z-index: 9999;
  justify-content: center;
  align-items: center;
}

/* Modal Content */
#boardModal .modal-content {
  background: #fff;
  width: 320px;
  padding: 20px;
  border-radius: 14px;
  text-align: center;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  animation: pop 0.25s ease;
  font-family: 'Poppins', sans-serif;
}

@keyframes pop {
  from { transform: scale(0.85); opacity: 0; }
  to   { transform: scale(1); opacity: 1; }
}

#boardModal input[type="text"] {
  width: 90%;
  padding: 10px;
  margin: 12px 0;
  border-radius: 8px;
  border: 1px solid #ccc;
  font-size: 1rem;
}

#boardModal button {
  padding: 8px 16px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  font-weight: 600;
  font-family: 'Poppins', sans-serif;
}

#createBoardBtn {
  background: #9F9A7F;
  color: #fff;
  margin-top: 10px;
}

#closeModalBtn {
  background: #ccc;
  color: #333;
  margin-top: 10px;
}
</style>


</head>
<body>

<?php include "includes/navbar.php"; ?>

<div class="container">
  <h1 style="text-align:center;">What are you looking for today?</h1>

  <form method="POST" style="display:flex;justify-content:center;">
    <input type="text" name="search" class="search-bar"
           placeholder="Search for movies, shows, books, or songs..."
           value="<?= h($query) ?>" required>
    <button type="submit"
      style="margin-left:10px; height:50px; padding:8px 18px;
      border:none;border-radius:20px;background:#9F9A7F;
      color:#fff;font-weight:600; cursor:pointer;">Search</button>
  </form>

  <?php if ($errorMsg): ?>
    <p style="color:red; text-align:center;"><?= h($errorMsg) ?></p>
  <?php endif; ?>

<?php
/* ==========================================================
   SHOW RESULTS — detected category FIRST
   ========================================================== */
$order = ["movies", "tv", "books", "music"];
if (in_array($primaryCategory, $order)) {
    $order = array_merge([$primaryCategory], array_diff($order, [$primaryCategory]));
}

foreach ($order as $cat):

    if ($cat === "movies") {
        $list = $movies;
    } elseif ($cat === "tv") {
        $list = $tvShows;
    } elseif ($cat === "books") {
        $list = $books;
    } else {
        $list = $music;
    }

    if (empty($list)) continue;
?>

  <div class="section">
    <h2>
      <?php
        if ($cat === "movies") echo "Movie Recommendations";
        elseif ($cat === "tv") echo "TV Shows";
        elseif ($cat === "books") echo "Books";
        else echo "Songs";
      ?>
    </h2>

    <div class="grid">

    <?php if ($cat === "movies"): ?>
      <!-- MOVIE RESULTS -->
      <?php foreach ($movies as $m):
        $item = $m;
        $type = "movie";
        $menuId = md5($item['title'] . $type);
      ?>
      <div class="card">
        <?php if (!empty($item["poster"])): ?>
          <img src="<?= h($item["poster"]) ?>" alt="Poster">
        <?php endif; ?>
        <h3><?= h($item["title"]) ?> <?= $item["year"] ? "(" . h($item["year"]) . ")" : "" ?></h3>
        <p><?= h(substr($item["overview"], 0, 120)) ?>...</p>

        <div style="text-align:right;">
          <button class="more-btn" onclick="toggleMenu('menu_<?= $menuId ?>')">⋯</button>

          <div class="dropdown-menu" id="menu_<?= $menuId ?>">

            <form method="POST" action="User/add_item.php">
              <input type="hidden" name="title" value="<?= h($item['title']) ?>">
              <input type="hidden" name="poster" value="<?= h($item['poster']) ?>">
              <input type="hidden" name="type" value="movie">
              <button type="submit" name="favorite">❤️ Add to Favorites</button>
            </form>

            <div class="dropdown-divider"></div>

            <?php foreach ($userBoards as $b): ?>
              <form method="POST" action="User/add_item.php">
                <input type="hidden" name="title" value="<?= h($item['title']) ?>">
                <input type="hidden" name="poster" value="<?= h($item['poster']) ?>">
                <input type="hidden" name="type" value="movie">
                <input type="hidden" name="board_id" value="<?= h($b['board_id']) ?>">
                <button type="submit" name="save_board"><?= h($b['name']) ?></button>
              </form>
            <?php endforeach; ?>

            <div class="dropdown-divider"></div>

            <button type="button" class="open-board-modal"
                 onclick="openBoardModal('<?= h($item['title']) ?>', '<?= h($item['poster']) ?>', '<?= $type ?>')">
             ➕ Create New Board
            </button>

          </div>
        </div>
      </div>
      <?php endforeach; ?>

    <?php elseif ($cat === "tv"): ?>
      <!-- TV RESULTS -->
      <?php foreach ($tvShows as $s):
        $item = $s;
        $type = "tv";
        $menuId = md5(($item['title'] ?? '') . $type);
      ?>
      <div class="card">
        <?php if (!empty($item["poster"])): ?>
          <img src="<?= h($item["poster"]) ?>" alt="Poster">
        <?php endif; ?>
        <h3><?= h($item["title"]) ?> <?= $item["year"] ? "(" . h($item["year"]) . ")" : "" ?></h3>
        <p><?= h(substr($item["overview"], 0, 120)) ?>...</p>

        <div style="text-align:right;">
          <button class="more-btn" onclick="toggleMenu('menu_<?= $menuId ?>')">⋯</button>

          <div class="dropdown-menu" id="menu_<?= $menuId ?>">

            <form method="POST" action="User/add_item.php">
              <input type="hidden" name="title" value="<?= h($item['title']) ?>">
              <input type="hidden" name="poster" value="<?= h($item['poster']) ?>">
              <input type="hidden" name="type" value="tv">
              <button type="submit" name="favorite">❤️ Add to Favorites</button>
            </form>

            <div class="dropdown-divider"></div>

            <?php foreach ($userBoards as $b): ?>
              <form method="POST" action="User/add_item.php">
                <input type="hidden" name="title" value="<?= h($item['title']) ?>">
                <input type="hidden" name="poster" value="<?= h($item['poster']) ?>">
                <input type="hidden" name="type" value="tv">
                <input type="hidden" name="board_id" value="<?= h($b['board_id']) ?>">
                <button type="submit" name="save_board"> <?= h($b['name']) ?></button>
              </form>
            <?php endforeach; ?>

            <div class="dropdown-divider"></div>

            <button type="button" class="open-board-modal"
                 onclick="openBoardModal('<?= h($item['title']) ?>', '<?= h($item['poster']) ?>', '<?= $type ?>')">
             ➕ Create New Board
            </button>

          </div>
        </div>
      </div>
      <?php endforeach; ?>

    <?php elseif ($cat === "books"): ?>
      <!-- BOOK RESULTS -->
      <?php foreach ($books as $b):
        $item = $b;
        $type = "book";
        $menuId = md5(($item['title'] ?? '') . $type);
      ?>
      <div class="card">
        <?php if (!empty($item["cover"])): ?>
          <img src="<?= h($item["cover"]) ?>" alt="Cover">
        <?php endif; ?>
        <h3><?= h($item["title"]) ?></h3>
        <p><strong><?= h($item["author"]) ?></strong> <?= $item["year"] ? "(" . h($item["year"]) . ")" : "" ?></p>
        <p><?= h(substr($item["description"], 0, 100)) ?>...</p>

        <div style="text-align:right;">
          <button class="more-btn" onclick="toggleMenu('menu_<?= $menuId ?>')">⋯</button>

          <div class="dropdown-menu" id="menu_<?= $menuId ?>">

            <form method="POST" action="User/add_item.php">
              <input type="hidden" name="title" value="<?= h($item['title']) ?>">
              <input type="hidden" name="poster" value="<?= h($item['cover']) ?>">
              <input type="hidden" name="type" value="book">
              <button type="submit" name="favorite">❤️ Add to Favorites</button>
            </form>

            <div class="dropdown-divider"></div>

            <?php foreach ($userBoards as $b): ?>
              <form method="POST" action="User/add_item.php">
                <input type="hidden" name="title" value="<?= h($item['title']) ?>">
                <input type="hidden" name="poster" value="<?= h($item['cover']) ?>">
                <input type="hidden" name="type" value="book">
                <input type="hidden" name="board_id" value="<?= h($b['board_id']) ?>">
                <button type="submit" name="save_board"> <?= h($b['name']) ?></button>
              </form>
            <?php endforeach; ?>

            <div class="dropdown-divider"></div>

            <button type="button" class="open-board-modal"
                 onclick="openBoardModal('<?= h($item['title']) ?>', '<?= h($item['poster']) ?>', '<?= $type ?>')">
             ➕ Create New Board
            </button>

          </div>
        </div>
      </div>
      <?php endforeach; ?>

    <?php else: ?>
      <!-- SONG RESULTS (music) -->
      <?php foreach ($music as $track):
        $item = $track;
        $type = "song";
        $menuId = md5(($item['title'] ?? '') . $type);
      ?>
      <div class="card">
        <?php if (!empty($item["cover"])): ?>
          <img src="<?= h($item["cover"]) ?>" alt="Cover">
        <?php endif; ?>
        <h3><?= h($item["title"]) ?></h3>
        <p><?= h($item["artist"]) ?></p>
        <p><?= h($item["album"]) ?></p>

        <?php if (!empty($item["preview_url"])): ?>
          <audio controls style="width:100%; margin-top:10px;">
            <source src="<?= h($item["preview_url"]) ?>" type="audio/mpeg">
          </audio>
        <?php endif; ?>

        <div style="text-align:right;">
          <button class="more-btn" onclick="toggleMenu('menu_<?= $menuId ?>')">⋯</button>

          <div class="dropdown-menu" id="menu_<?= $menuId ?>">

            <form method="POST" action="User/add_item.php">
              <input type="hidden" name="title" value="<?= h($item['title']) ?>">
              <input type="hidden" name="poster" value="<?= h($item['cover']) ?>">
              <input type="hidden" name="type" value="song">
              <button type="submit" name="favorite">❤️ Add to Favorites</button>
            </form>

            <div class="dropdown-divider"></div>

            <?php foreach ($userBoards as $b): ?>
              <form method="POST" action="User/add_item.php">
                <input type="hidden" name="title" value="<?= h($item['title']) ?>">
                <input type="hidden" name="poster" value="<?= h($item['cover']) ?>">
                <input type="hidden" name="type" value="song">
                <input type="hidden" name="board_id" value="<?= h($b['board_id']) ?>">
                <button type="submit" name="save_board"> <?= h($b['name']) ?></button>
              </form>
            <?php endforeach; ?>

            <div class="dropdown-divider"></div>

            <button type="button" class="open-board-modal"
                 onclick="openBoardModal('<?= h($item['title']) ?>', '<?= h($item['poster']) ?>', '<?= $type ?>')">
             ➕ Create New Board
            </button>

          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

    </div> <!-- .grid -->
  </div> <!-- .section -->

<?php endforeach; ?>

<?php if (
  empty($movies) && empty($tvShows) &&
  empty($books) && empty($music) &&
  $_SERVER["REQUEST_METHOD"] === "POST"
): ?>
  <p style="text-align:center;">No results found for “<?= h($query) ?>”.</p>
<?php endif; ?>

</div> <!-- .container -->

<!-- 📌 Create Board Modal -->
<div id="boardModal" style="display:none;">
  <div class="modal-content">
    <h2>Name Your New Board</h2>

    <form method="POST" action="User/add_item.php">
      <input type="hidden" name="title" id="modal_title">
      <input type="hidden" name="poster" id="modal_poster">
      <input type="hidden" name="type" id="modal_type">

      <input type="text" name="board_name" placeholder="Enter board name..." required>

      <button type="submit" name="new_board_named" id="createBoardBtn">Create Board</button>
      <button type="button" id="closeModalBtn" onclick="closeBoardModal()">Cancel</button>
    </form>
  </div>
</div>

</body>
</html>
