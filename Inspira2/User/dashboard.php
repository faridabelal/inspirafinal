<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once "../includes/db.php";
require_once "../api/tmdb.php";
require_once "../api/books.php";
require_once "../api/spotify.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$username = $_SESSION["username"] ?? "User";

/* --------------------------
   Helpers
---------------------------*/

function h($v) { return htmlspecialchars($v ?? "", ENT_QUOTES, 'UTF-8'); }

function interleaveArrays($arrays, $max = 24) {
    $out = [];
    $i = 0;
    while (count($out) < $max) {
        $added = false;
        foreach ($arrays as $k => &$arr) {
            if (!empty($arr)) {
                $out[] = array_shift($arr);
                $added = true;
                if (count($out) >= $max) break;
            }
        }
        if (!$added) break;
        $i++;
        if ($i > 1000) break;
    }
    return $out;
}

function shortenTeaser($text, $max = 90) {
    $text = trim(strip_tags($text));
    if (strlen($text) <= $max) return $text;
    return substr($text, 0, $max) . "...";
}


/* Normalize items to a common shape */

function normalizeDashboardMovie($m) {
    return [
        "title" => $m["title"] ?? "",
        "poster" => $m["poster"] ?? null,
        "type" => "movie",
        "subtitle" => isset($m["year"]) ? $m["year"] : "",
        "desc" => $m["overview"] ?? "",
        "teaser" => shortenTeaser($m["overview"] ?? "")
    ];
}
function normalizeDashboardTV($s) {
    return [
        "title" => $s["title"] ?? "",
        "poster" => $s["poster"] ?? null,
        "type" => "tv",
        "subtitle" => isset($s["year"]) ? $s["year"] : "",
        "desc" => $s["overview"] ?? "",
        "teaser" => shortenTeaser($s["overview"] ?? "")
    ];
}
function normalizeDashboardBook($b) {
    return [
        "title" => $b["title"] ?? "",
        "poster" => $b["cover"] ?? null,
        "type" => "book",
        "subtitle" => trim(($b["author"] ?? "")) . (isset($b["year"]) && $b["year"] ? " · " . $b["year"] : ""),
        "desc" => $b["description"] ?? "",
        "teaser" => shortenTeaser($b["overview"] ?? "")
    ];
}
function normalizeDashboardSong($t) {
    return [
        "title" => $t["title"] ?? "",
        "poster" => $t["cover"] ?? null,
        "type" => "song",
        "subtitle" => trim(($t["artist"] ?? "") . (isset($t["album"]) ? " · " . $t["album"] : "")),
        "desc" => "",
        "teaser" => ""
    ];
}

/* Keywords + moods */

function extractKeywordsFromTitles($titles, $max = 6) {
    $text = strtolower(implode(" ", $titles));
    $tokens = preg_split('/[^a-z0-9]+/i', $text, -1, PREG_SPLIT_NO_EMPTY);
    $stop = [
        "the","a","an","of","and","to","in","on","for","with","at","by","is","it","this","that",
        "you","your","my","our","from","as","be","are","was","were","or","but","not","like",
        "movie","movies","film","films","show","shows","tv","series","song","songs","book","books"
    ];
    $freq = [];
    foreach ($tokens as $t) {
        if (strlen($t) < 3) continue;
        if (in_array($t, $stop)) continue;
        $freq[$t] = ($freq[$t] ?? 0) + 1;
    }
    arsort($freq);
    return array_slice(array_keys($freq), 0, $max);
}

function detectMoodFromText($text) {
    $text = strtolower($text);
    $moods = [
        "sad"       => ["sad", "cry", "heartbreaking", "tragic", "tearjerker"],
        "happy"     => ["happy", "feel good", "feel-good", "fun", "light"],
        "dark"      => ["dark", "twisted", "creepy", "disturbing"],
        "romantic"  => ["romantic", "love story", "love", "romcom"],
        "inspiring" => ["inspiring", "motivational", "uplifting"],
        "exciting"  => ["exciting", "thrilling", "action", "adrenaline"]
    ];
    foreach ($moods as $mood => $words) {
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) return $mood;
        }
    }
    return "neutral";
}

function getDominantType($favRows) {
    $counts = ["movie" => 0, "tv" => 0, "song" => 0, "book" => 0];
    foreach ($favRows as $r) {
        $t = strtolower($r["type"] ?? "");
        if (isset($counts[$t])) $counts[$t]++;
    }
    arsort($counts);
    $topType = array_key_first($counts);
    if ($counts[$topType] === 0) return null;
    return $topType;
}

/* --------------------------
   Pull user context
---------------------------*/

// Favorites
$favStmt = $pdo->prepare("
    SELECT m.title, m.type, m.author_artist, m.category
    FROM Favorites_SP f
    JOIN Media_Item m ON f.item_id = m.item_id
    WHERE f.user_id = ?
    ORDER BY f.created_at DESC
    LIMIT 10
");
$favStmt->execute([$user_id]);
$favRows = $favStmt->fetchAll(PDO::FETCH_ASSOC);

// Recent searches
$searchStmt = $pdo->prepare("
    SELECT query
    FROM SearchHistory_SP
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$searchStmt->execute([$user_id]);
$recentSearches = array_map(fn($r) => $r["query"], $searchStmt->fetchAll(PDO::FETCH_ASSOC));

// Recent exact items
$recentItemsStmt = $pdo->prepare("
    SELECT title, type, author_artist
    FROM RecentResults_SP
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
$recentItemsStmt->execute([$user_id]);
$recentItemsRows = $recentItemsStmt->fetchAll(PDO::FETCH_ASSOC);

/* --------------------------
   Build profile (Netflix-ish)
---------------------------*/

$titleSeeds = array_map(fn($r) => $r["title"], $favRows);
$titleSeeds = array_merge($titleSeeds, array_map(fn($r) => $r["title"], $recentItemsRows));

$keywordSeeds = extractKeywordsFromTitles(array_merge(
    $titleSeeds,
    $recentSearches
));
if (empty($keywordSeeds)) {
    $keywordSeeds = ["popular", "top"];
}

$moodText = implode(" ", $recentSearches);
$mood = detectMoodFromText($moodText);

$dominantType = getDominantType($favRows);

// Separate favorite titles by type (for “similar to X”)
$favMovieTitles = [];
$favTVTitles = [];
foreach ($favRows as $r) {
    if (strtolower($r["type"]) === "movie") $favMovieTitles[] = $r["title"];
    if (strtolower($r["type"]) === "tv")    $favTVTitles[]   = $r["title"];
}

/* Helper: merge arrays of movies and dedupe by title */

function mergeUniqueByTitle($base, $toAdd, $max = 100) {
    $seen = [];
    foreach ($base as $b) {
        $key = strtolower(trim($b["title"] ?? ""));
        if ($key) $seen[$key] = true;
    }
    foreach ($toAdd as $m) {
        $key = strtolower(trim($m["title"] ?? ""));
        if (!$key || isset($seen[$key])) continue;
        $base[] = $m;
        $seen[$key] = true;
        if (count($base) >= $max) break;
    }
    return $base;
}

/* --------------------------
   Recommended For You
   - Heavily weight "similar to favorite movies"
---------------------------*/

$recMovies = [];
$recTV     = [];
$recSongs  = [];
$recBooks  = [];

// Movies: use up to 2 favorite movie titles for "similar to X"
if (!empty($favMovieTitles)) {
    $topFavMovies = array_slice(array_unique($favMovieTitles), 0, 2);
    foreach ($topFavMovies as $ftitle) {
        $similar = getMovies([], $mood, $ftitle); // uses similar + mood blend
        $recMovies = mergeUniqueByTitle($recMovies, $similar, 120);
    }
}
// Fallback if no favorites or nothing returned
if (empty($recMovies)) {
    $recMovies = getMovies($keywordSeeds, $mood, null);
}

// TV: mood + keywords (your getTVShows doesn't support "similar" yet)
$recTV = getTVShows($keywordSeeds, $mood, null);

// Songs + Books: still based on keyword seeds + mood for variety
$recSongs = getSongs($keywordSeeds, $mood);
$recBooks = getBooks($keywordSeeds, $mood);

$recommended = interleaveArrays([
    array_map('normalizeDashboardMovie', $recMovies),
    array_map('normalizeDashboardTV', $recTV),
    array_map('normalizeDashboardSong', $recSongs),
    array_map('normalizeDashboardBook', $recBooks),
], 24);

/* --------------------------
   Based on Your Favorites
   - Even more strongly tied to favorites + recent items
---------------------------*/

$favBasedMovies = [];
$favBasedTV     = [];
$favBasedSongs  = [];
$favBasedBooks  = [];

// Use more favorite movie titles for “similar”
if (!empty($favMovieTitles)) {
    $favTitlesUnique = array_unique($favMovieTitles);
    $topFavForBased  = array_slice($favTitlesUnique, 0, 4);
    foreach ($topFavForBased as $ftitle) {
        $similar = getMovies([], "neutral", $ftitle);
        $favBasedMovies = mergeUniqueByTitle($favBasedMovies, $similar, 150);
    }
} else {
    // If no movie favorites, fall back to keyword-based
    $favBasedMovies = getMovies($keywordSeeds, "neutral", null);
}

// TV: keyword-based but using title seeds from favorites/recent
$favBasedKeywords = extractKeywordsFromTitles($titleSeeds);
if (empty($favBasedKeywords)) $favBasedKeywords = ["recommended"];
$favBasedTV    = getTVShows($favBasedKeywords, "neutral", null);
$favBasedSongs = getSongs($favBasedKeywords, "neutral");
$favBasedBooks = getBooks($favBasedKeywords, "neutral");

$basedOnFavorites = interleaveArrays([
    array_map('normalizeDashboardMovie', $favBasedMovies),
    array_map('normalizeDashboardTV', $favBasedTV),
    array_map('normalizeDashboardSong', $favBasedSongs),
    array_map('normalizeDashboardBook', $favBasedBooks),
], 24);

/* --------------------------
    Popular Now (Trending)
---------------------------*/

$popMovies = getTrendingMovies();
$popTV     = getTrendingTV();
$popSongs  = getTrendingSongs();
$popBooks  = getTrendingBooks();

$popular = interleaveArrays([
    array_map('normalizeDashboardMovie', $popMovies),
    array_map('normalizeDashboardTV', $popTV),
    array_map('normalizeDashboardSong', $popSongs),
    array_map('normalizeDashboardBook', $popBooks),
], 16);

// Boards for controls
$boardsStmt = $pdo->prepare("SELECT board_id, name FROM Board WHERE user_id = ?");
$boardsStmt->execute([$user_id]);
$userBoards = $boardsStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dashboard - Inspira</title>

<script src="../scripts/dropdown.js" defer></script>
<link rel="stylesheet" href="../styling/dropdown.css">

<style>
:root {
  --bg: #fafafa;
  --fg: #111;
  --card-bg:#fff;
  --brand:#9F9A7F;
  --muted:#666;
  --chip:#eee;
}
* { box-sizing: border-box; }
body {
  font-family:'Poppins',sans-serif;
  background:var(--bg);
  color:var(--fg);
  margin:0;
}
.wrap { padding: 20px; }

h1 { margin: 16px 0 6px; text-align:center; }
.quick-menu {
  display:flex; gap:12px; justify-content:center; flex-wrap:wrap;
  margin: 10px auto 24px;
}
.qcard {
  background:var(--card-bg);
  width:180px; height:100px;
  border-radius:12px;
  box-shadow:0 4px 12px rgba(0,0,0,0.08);
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  font-weight:600; text-decoration:none; color:#333;
  transition: transform .2s ease, color .2s ease;
}
.qcard:hover { transform: translateY(-3px); color:var(--brand); }

.section { margin: 26px auto; width: 96%; max-width: 1200px; }
.section h2 { margin: 0 6px 10px; font-weight:700; }

.rail {
  display:flex; gap:14px; overflow-x:auto; padding: 6px 6px 12px;
  scroll-snap-type: x mandatory;
}
.rail::-webkit-scrollbar { height: 8px; }
.rail::-webkit-scrollbar-thumb { background:#ddd; border-radius:8px; }

.card {
  background:var(--card-bg);
  width:200px; 
  min-width:200px;
  border-radius:14px;
  box-shadow:0 4px 12px rgba(0,0,0,0.08);
  padding:10px;
  scroll-snap-align: start;
  display:flex; 
  flex-direction:column; 
  align-items:center; 
  text-align:center;

  height: auto;          
}

.poster {
  width:100%; height:260px; border-radius:10px; object-fit:cover; background:#f0f0f0;
}
.title { 
 font-size:0.98rem; 
 font-weight:600; 
 margin:8px 0 4px; 
}
.subtitle { 
  font-size:0.85rem; 
  color:var(--muted); 
  margin-bottom:6px;
    display: -webkit-box;
  -webkit-line-clamp: 2;        /* ONLY hides if overflow */
  -webkit-box-orient: vertical;
  overflow: hidden;
  text-overflow: ellipsis;
  
}
.type-tag {
  font-size:0.70rem; letter-spacing:.08em; font-weight:700;
  border:1px solid #ddd; padding:3px 8px; border-radius:999px; display:inline-block; margin-bottom:8px;
}
.teaser {
  font-size: 0.78rem;
  color: #555;
  margin: 4px 0 6px;
  line-height: 1.2em;

  display: -webkit-box;
  -webkit-line-clamp: 2;         /* Only cuts off if long */
  -webkit-box-orient: vertical;
  overflow: hidden;
  text-overflow: ellipsis;
}

.card-text {
    height: 90px;               /* adjust if needed */
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    width: 100%;
    text-align: center;
}

.recent-section .title {
    display: -webkit-box;
    -webkit-line-clamp: 2;      /* exactly 2 lines max */
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
}


/* Modal overlay + box */
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

#boardModal .modal-box {
  background: #fff;
  width: 320px;
  padding: 20px;
  border-radius: 14px;
  text-align: center;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  animation: pop 0.25s ease;
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

.modal-btn {
  background: #9F9A7F;
  color: #fff;
  border: none;
  padding: 8px 16px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  margin-top: 10px;
}

.modal-close {
  background: #ccc;
  color: #333;
  border: none;
  padding: 8px 16px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  margin-top: 10px;
}


</style>

</head>
<body>

<?php include "../includes/navbar.php"; ?>
<div class="spacer"></div>

<div class="wrap">
  <h1>Welcome back, <?= h($username) ?></h1>

  <div class="quick-menu">
    <a href="../search.php" class="qcard">Search</a>
    <a href="favorites.php" class="qcard">Favorites</a>
    <a href="boards.php" class="qcard">Boards</a>
  </div>

  <!-- Recommended For You -->
  <div class="section">
    <h2>Recommended For You</h2>
    <?php if (empty($recommended)): ?>
      <p style="margin:8px 6px;color:#444;">No personalized picks yet — try a search or add a favorite.</p>
    <?php else: ?>
      <div class="rail">
        <?php foreach ($recommended as $item): ?>
          <div class="card">
            <?php if ($item["poster"]): ?>
              <img class="poster" src="<?= h($item["poster"]) ?>" alt="Poster" loading="lazy">
            <?php else: ?>
              <div class="poster"></div>
            <?php endif; ?>
            <div class="type-tag"><?= strtoupper(h($item["type"])) ?></div>
            <div class="card-text">
                <div class="title"><?= h($item["title"]) ?></div>

                <div class="subtitle">
                    <?= !empty($item["subtitle"]) ? h($item["subtitle"]) : '&nbsp;' ?>
                </div>

                <?php if (!empty($item["teaser"])): ?>
                    <div class="teaser"><?= h($item["teaser"]) ?></div>
                <?php else: ?>
                    <div class="teaser">&nbsp;</div>
                <?php endif; ?>
            </div>

            <div style="text-align:right; width:100%; margin-top:2px;">
              <?php 
                  $menuId = md5($item["title"] . $item["type"] . rand());
                  $type = $item["type"];
                  $poster = $item["poster"];
                  $title = $item["title"];
              ?>
              
              <button class="more-btn" onclick="toggleMenu('menu_<?= $menuId ?>')">⋯</button>

              <div class="dropdown-menu" id="menu_<?= $menuId ?>">

                  <!-- Add to Favorites -->
                  <form method="POST" action="add_item.php">
                      <input type="hidden" name="title" value="<?= h($title) ?>">
                      <input type="hidden" name="poster" value="<?= h($poster) ?>">
                      <input type="hidden" name="type" value="<?= h($type) ?>">
                      <button type="submit" name="favorite">❤️ Add to Favorites</button>
                  </form>

                  <div class="dropdown-divider"></div>

                  <!-- Add to Existing Board -->
                  <?php foreach ($userBoards as $b): ?>
                  <form method="POST" action="add_item.php">
                      <input type="hidden" name="title" value="<?= h($title) ?>">
                      <input type="hidden" name="poster" value="<?= h($poster) ?>">
                      <input type="hidden" name="type" value="<?= h($type) ?>">
                      <input type="hidden" name="board_id" value="<?= h($b['board_id']) ?>">
                      <button type="submit" name="save_board"><?= h($b['name']) ?></button>
                  </form>
                  <?php endforeach; ?>

                  <div class="dropdown-divider"></div>

                  <!-- Create New Board -->
                  <button type="button"
                          class="open-board-modal"
                          onclick="openBoardModal('<?= h($title) ?>', '<?= h($poster) ?>', '<?= h($type) ?>')">
                      ➕ Create New Board
                  </button>

              </div>
            </div>

          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Based on Your Favorites -->
  <div class="section">
    <h2>Based on Your Favorites</h2>
    <?php if (empty($favRows) && empty($recentItemsRows)): ?>
      <p style="margin:8px 6px;color:#444;">Add some favorites or search for items to unlock this section.</p>
    <?php else: ?>
      <?php $favBasedCards = $basedOnFavorites; ?>
      <?php if (empty($favBasedCards)): ?>
        <p style="margin:8px 6px;color:#444;">No similar items found yet.</p>
      <?php else: ?>
        <div class="rail">
          <?php foreach ($favBasedCards as $item): ?>
            <div class="card">
              <?php if ($item["poster"]): ?>
                <img class="poster" src="<?= h($item["poster"]) ?>" alt="Poster" loading="lazy">
              <?php else: ?>
                <div class="poster"></div>
              <?php endif; ?>
              <div class="type-tag"><?= strtoupper(h($item["type"])) ?></div>
              <div class="card-text">
                <div class="title"><?= h($item["title"]) ?></div>

                <div class="subtitle">
                    <?= !empty($item["subtitle"]) ? h($item["subtitle"]) : '&nbsp;' ?>
                </div>

                <?php if (!empty($item["teaser"])): ?>
                    <div class="teaser"><?= h($item["teaser"]) ?></div>
                <?php else: ?>
                    <div class="teaser">&nbsp;</div>
                <?php endif; ?>
            </div>


              <div style="text-align:right; width:100%; margin-top:2px;">
                <?php 
                    $menuId = md5($item["title"] . $item["type"] . rand());
                    $type = $item["type"];
                    $poster = $item["poster"];
                    $title = $item["title"];
                ?>
                
                <button class="more-btn" onclick="toggleMenu('menu_<?= $menuId ?>')">⋯</button>

                <div class="dropdown-menu" id="menu_<?= $menuId ?>">

                    <!-- Add to Favorites -->
                    <form method="POST" action="add_item.php">
                        <input type="hidden" name="title" value="<?= h($title) ?>">
                        <input type="hidden" name="poster" value="<?= h($poster) ?>">
                        <input type="hidden" name="type" value="<?= h($type) ?>">
                        <button type="submit" name="favorite">❤️ Add to Favorites</button>
                    </form>

                    <div class="dropdown-divider"></div>

                    <!-- Add to Existing Board -->
                    <?php foreach ($userBoards as $b): ?>
                    <form method="POST" action="add_item.php">
                        <input type="hidden" name="title" value="<?= h($title) ?>">
                        <input type="hidden" name="poster" value="<?= h($poster) ?>">
                        <input type="hidden" name="type" value="<?= h($type) ?>">
                        <input type="hidden" name="board_id" value="<?= h($b['board_id']) ?>">
                        <button type="submit" name="save_board"><?= h($b['name']) ?></button>
                    </form>
                    <?php endforeach; ?>

                    <div class="dropdown-divider"></div>

                    <!-- Create New Board -->
                    <button type="button"
                            class="open-board-modal"
                            onclick="openBoardModal('<?= h($title) ?>', '<?= h($poster) ?>', '<?= h($type) ?>')">
                        ➕ Create New Board
                    </button>

                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Popular Now -->
  <div class="section">
    <h2>Popular Now</h2>
    <?php $popularCards = $popular; ?>
    <?php if (empty($popularCards)): ?>
      <p style="margin:8px 6px;color:#444;">Couldn’t load popular picks right now.</p>
    <?php else: ?>
      <div class="rail">
        <?php foreach ($popularCards as $item): ?>
          <div class="card">
            <?php if ($item["poster"]): ?>
              <img class="poster" src="<?= h($item["poster"]) ?>" alt="Poster" loading="lazy">
            <?php else: ?>
              <div class="poster"></div>
            <?php endif; ?>
            <div class="type-tag"><?= strtoupper(h($item["type"])) ?></div>
            <div class="card-text">
                <div class="title"><?= h($item["title"]) ?></div>

                <div class="subtitle">
                    <?= !empty($item["subtitle"]) ? h($item["subtitle"]) : '&nbsp;' ?>
                </div>

                <?php if (!empty($item["teaser"])): ?>
                    <div class="teaser"><?= h($item["teaser"]) ?></div>
                <?php else: ?>
                    <div class="teaser">&nbsp;</div>
                <?php endif; ?>
            </div>


            <div style="text-align:right; width:100%; margin-top:2px;">
              <?php 
                  $menuId = md5($item["title"] . $item["type"] . rand());
                  $type = $item["type"];
                  $poster = $item["poster"];
                  $title = $item["title"];
              ?>
              
              <button class="more-btn" onclick="toggleMenu('menu_<?= $menuId ?>')">⋯</button>

              <div class="dropdown-menu" id="menu_<?= $menuId ?>">

                  <!-- Add to Favorites -->
                  <form method="POST" action="add_item.php">
                      <input type="hidden" name="title" value="<?= h($title) ?>">
                      <input type="hidden" name="poster" value="<?= h($poster) ?>">
                      <input type="hidden" name="type" value="<?= h($type) ?>">
                      <button type="submit" name="favorite">❤️ Add to Favorites</button>
                  </form>

                  <div class="dropdown-divider"></div>

                  <!-- Add to Existing Board -->
                  <?php foreach ($userBoards as $b): ?>
                  <form method="POST" action="add_item.php">
                      <input type="hidden" name="title" value="<?= h($title) ?>">
                      <input type="hidden" name="poster" value="<?= h($poster) ?>">
                      <input type="hidden" name="type" value="<?= h($type) ?>">
                      <input type="hidden" name="board_id" value="<?= h($b['board_id']) ?>">
                      <button type="submit" name="save_board"><?= h($b['name']) ?></button>
                  </form>
                  <?php endforeach; ?>

                  <div class="dropdown-divider"></div>

                  <!-- Create New Board -->
                  <button type="button"
                          class="open-board-modal"
                          onclick="openBoardModal('<?= h($title) ?>', '<?= h($poster) ?>', '<?= h($type) ?>')">
                      ➕ Create New Board
                  </button>

              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Recently Searched -->
  <div class="section recent-section">
    <h2>Recently Searched</h2>
    <?php
      $recentStmt = $pdo->prepare("
          SELECT title, type, poster, author_artist
          FROM RecentResults_SP
          WHERE user_id = ?
          ORDER BY created_at DESC
          LIMIT 30
      ");
      $recentStmt->execute([$user_id]);
      $recentItems = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <?php if (empty($recentItems)): ?>
      <p style="margin:8px 6px;color:#444;">No recent searches yet — try searching for something.</p>
    <?php else: ?>
      <div class="rail">
        <?php foreach ($recentItems as $item): ?>
          <div class="card">
            <?php if (!empty($item["poster"])): ?>
              <img class="poster" src="<?= htmlspecialchars($item["poster"]) ?>" alt="Poster" loading="lazy">
            <?php else: ?>
              <div class="poster" style="background:#e0e0e0;"></div>
            <?php endif; ?>

            <div class="type-tag"><?= strtoupper(htmlspecialchars($item["type"])) ?></div>
            <div class="title"><?= htmlspecialchars($item["title"]) ?></div>
            <?php if (!empty($item["author_artist"])): ?>
                <?php
                    // Show only the first author / name part before the first comma
                    $shortAuthor = explode(',', $item["author_artist"])[0];
                ?>
                <div class="subtitle"><?= htmlspecialchars(trim($shortAuthor)) ?></div>
            <?php else: ?>
                <div class="subtitle">&nbsp;</div>
            <?php endif; ?>

            <div style="text-align:right; width:100%; margin-top:6px;">
              <?php 
                  $menuId = md5($item["title"] . $item["type"] . rand());
                  $type = $item["type"];
                  $poster = $item["poster"];
                  $title = $item["title"];
              ?>
              
              <button class="more-btn" onclick="toggleMenu('menu_<?= $menuId ?>')">⋯</button>

              <div class="dropdown-menu" id="menu_<?= $menuId ?>">

                  <!-- Add to Favorites -->
                  <form method="POST" action="add_item.php">
                      <input type="hidden" name="title" value="<?= h($title) ?>">
                      <input type="hidden" name="poster" value="<?= h($poster) ?>">
                      <input type="hidden" name="type" value="<?= h($type) ?>">
                      <button type="submit" name="favorite">❤️ Add to Favorites</button>
                  </form>

                  <div class="dropdown-divider"></div>

                  <!-- Add to Existing Board -->
                  <?php foreach ($userBoards as $b): ?>
                  <form method="POST" action="add_item.php">
                      <input type="hidden" name="title" value="<?= h($title) ?>">
                      <input type="hidden" name="poster" value="<?= h($poster) ?>">
                      <input type="hidden" name="type" value="<?= h($type) ?>">
                      <input type="hidden" name="board_id" value="<?= h($b['board_id']) ?>">
                      <button type="submit" name="save_board"><?= h($b['name']) ?></button>
                  </form>
                  <?php endforeach; ?>

                  <div class="dropdown-divider"></div>

                  <!-- Create New Board -->
                  <button type="button"
                          class="open-board-modal"
                          onclick="openBoardModal('<?= h($title) ?>', '<?= h($poster) ?>', '<?= h($type) ?>')">
                      ➕ Create New Board
                  </button>

              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- create board dropdown  formmm -->
<div id="boardModal" class="modal-overlay" style="display:none;">
  <div class="modal-box">
    <h2>Name Your New Board</h2>

    <form method="POST" action="add_item.php">
      <input type="hidden" name="title" id="modal_title">
      <input type="hidden" name="poster" id="modal_poster">
      <input type="hidden" name="type" id="modal_type">

      <input type="text" name="board_name" class="modal-input" placeholder="Enter board name..." required>

      <button type="submit" name="new_board_named" class="modal-btn">Create Board</button>
      <button type="button" class="modal-close" onclick="closeBoardModal()">Cancel</button>
    </form>
  </div>
</div>


</body>
</html>
