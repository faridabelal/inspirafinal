<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * books.php
 * - AI-driven book recommendations (fiction OR non-fiction)
 * - Google Books used ONLY to fetch metadata for AI titles
 * - getExactBook() for RecentResults_SP
 * - getTrendingBooks() via NYT
 */

/* ----------------------------------------------------
   Normalize Google Books volumeInfo → Inspira shape
---------------------------------------------------- */
function normalizeBook(array $info): array {
    return [
        "title"       => $info["title"] ?? "",
        "author"      => implode(", ", $info["authors"] ?? ["Unknown"]),
        "year"        => substr($info["publishedDate"] ?? "", 0, 4),
        "description" => $info["description"] ?? "",
        "cover"       => $info["imageLinks"]["thumbnail"] ?? null,
        "categories"  => $info["categories"] ?? [],
        "type"        => "book"
    ];
}

/* ----------------------------------------------------
   Exact single-book fetch for RecentResults_SP
   Used by search.php → getExactBook($refTitle)
---------------------------------------------------- */
function getExactBook(string $title): ?array {
    if (trim($title) === "") return null;

    $query = urlencode('intitle:"' . $title . '"');
    $url   = "https://www.googleapis.com/books/v1/volumes?q=$query&maxResults=1&printType=books";

    $resp = @file_get_contents($url);
    if (!$resp) return null;

    $data = json_decode($resp, true);
    $info = $data["items"][0]["volumeInfo"] ?? null;
    if (!$info) return null;

    $norm = normalizeBook($info);

    return [
        "title"       => $norm["title"],
        "type"        => "book",
        "author"      => $norm["author"],
        "year"        => $norm["year"],
        "cover"       => $norm["cover"],
        "description" => $norm["description"]
    ];
}

/* ----------------------------------------------------
   AI: universal book recommender (fiction OR non-fiction)
   - rawQuery can be:
       "books similar to Pride and Prejudice"
       "romantic enemies-to-lovers books"
       "books like Surrounded by Idiots"
---------------------------------------------------- */
function aiBookRecommendations(string $rawQuery): array {

    
    $booksApiKey = "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";

    $systemPrompt = "
You are a book recommendation engine for a web app called Inspira.

Your job:
- Read the user's query.
- Decide whether they want FICTION or NON-FICTION.
- Then return a JSON object with a list of book titles that match
  the reference book OR the described vibe/topic.

You MUST auto-detect fiction vs non-fiction:

- If the query or reference is clearly a novel (romance, fantasy, thriller,
  mystery, YA, historical romance, etc.), recommend ONLY FICTION.
- If the query or reference is clearly about real-world topics
  (psychology, business, self-help, productivity, communication, history,
  biography, politics, science, etc.), recommend ONLY NON-FICTION.

Examples of NON-FICTION anchors:
- Surrounded by Idiots
- Atomic Habits
- The 7 Habits of Highly Effective People
- Thinking, Fast and Slow
- Outliers
- Sapiens

Examples of FICTION anchors:
- Pride and Prejudice
- Heart Bones
- The Hunger Games
- Me Before You
- The Fault in Our Stars
- The Night Circus

Output MUST be valid JSON:

{
  \"mode\": \"fiction\" | \"nonfiction\",
  \"books\": [
    \"Title 1\",
    \"Title 2\",
    \"Title 3\",
    ...
  ]
}

Rules:
- Return 30 to 40 book titles (no fewer than 20).
- Do NOT include the reference title itself.
- No duplicate titles.
- Titles ONLY (no authors, no summaries).
- Fiction mode: base similarity on plot, tropes, themes, relationships, tone.
- Non-fiction mode: base similarity on subject, topic, domain, and style
  (e.g., pop-psychology, management, self-help, history, etc.).
- Prefer popular, widely available English-language books that are likely
  to appear in Google Books.
";

    $payload = [
        "model" => "gpt-4o-mini",
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user",   "content" => $rawQuery]
        ],
        "temperature" => 0.2
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: " . "Bearer $booksApiKey"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return [];

    $data    = json_decode($resp, true);
    $content = $data["choices"][0]["message"]["content"] ?? "{}";
    $parsed  = json_decode($content, true);

    if (!is_array($parsed) || empty($parsed["books"]) || !is_array($parsed["books"])) {
        return [];
    }

    // clean + dedupe titles
    $titles = [];
    $seen   = [];

    foreach ($parsed["books"] as $t) {
        $t = trim((string)$t);
        if ($t === "") continue;

        $key = mb_strtolower($t);
        if (isset($seen[$key])) continue;

        $seen[$key] = true;
        $titles[]   = $t;
    }

    return $titles;
}

/* ----------------------------------------------------
   Google Books lookup for a single title
   (No logic, just metadata)
---------------------------------------------------- */
function fetchGoogleBookByTitle(string $title): ?array {

    if (trim($title) === "") return null;

    $query = urlencode('intitle:"' . $title . '"');
    $url   = "https://www.googleapis.com/books/v1/volumes?q=$query&maxResults=3&printType=books";

    $resp = @file_get_contents($url);
    if (!$resp) return null;

    $data  = json_decode($resp, true);
    $items = $data["items"] ?? [];
    if (empty($items)) return null;

    foreach ($items as $item) {
        $info = $item["volumeInfo"] ?? null;
        if (!$info) continue;

        $norm = normalizeBook($info);
        if (empty($norm["title"])) continue;

        return $norm;
    }

    return null;
}

/* ----------------------------------------------------
   Light post-filter: remove junk & the anchor itself
   (We are NOT using Google Books to detect fiction/non-fiction here,
    just to clean obvious garbage like summaries/study guides.)
---------------------------------------------------- */
function filterBookResults(array $books, ?string $referenceBook = null): array {

    $refNorm = null;
    if ($referenceBook) {
        $refNorm = mb_strtolower(preg_replace("/[^a-z0-9]/", "", $referenceBook));
    }

    $banWords = [
        "study guide", "summary", "analysis", "workbook", "notes",
        "sparknotes", "cliffnotes", "cliffsnotes",
        "teacher's guide", "student edition", "exam prep", "review guide"
    ];

    $filtered = [];

    foreach ($books as $b) {
        $title = $b["title"] ?? "";
        $desc  = $b["description"] ?? "";

        $tLower = mb_strtolower($title);
        $dLower = mb_strtolower($desc);

        // remove anchor book itself (any edition)
        if ($refNorm && $title !== "") {
            $tNorm = mb_strtolower(preg_replace("/[^a-z0-9]/", "", $title));
            similar_text($tNorm, $refNorm, $pct);
            if ($pct > 80) continue; // treat as same book
        }

        // filter obvious junk (summaries, study guides, etc.)
        foreach ($banWords as $w) {
            if (str_contains($tLower, $w) || str_contains($dLower, $w)) {
                continue 2; // skip this book
            }
        }

        // require cover + some description for nicer UI
        if (empty($b["cover"])) continue;
        if (empty(trim($desc))) continue;

        $filtered[] = $b;
    }

    return $filtered;
}

/* ----------------------------------------------------
   MAIN: getBooks()
   - Called by search.php
   - Uses AI to decide fiction vs non-fiction + recommend titles
   - Uses Google Books ONLY to fetch metadata for those titles
---------------------------------------------------- */
function getBooks(array $keywords, string $mood = "neutral", ?string $referenceBook = null): array {

    // Build the text we send to OpenAI
    if (!empty($referenceBook)) {
        // e.g. "books like Pride and Prejudice"
        $rawQuery = 'Give me books similar to "' . trim($referenceBook) . '"';
    } else {
        $joined = trim(implode(" ", $keywords));
        if ($joined === "") return [];

        // e.g. "romantic enemies-to-lovers books", "psychology books about communication"
        $rawQuery = "Recommend books that match this description: " . $joined;
    }

    // 1) Let AI choose 30–40 titles (fiction or non-fiction)
    $titles = aiBookRecommendations($rawQuery);
    if (empty($titles)) return [];

    // 2) Fetch metadata from Google Books for each title
    $results = [];
    $seen    = [];

    foreach ($titles as $t) {
        $meta = fetchGoogleBookByTitle($t);
        if (!$meta) continue;

        $key = mb_strtolower($meta["title"]);
        if (isset($seen[$key])) continue;

        $seen[$key] = true;
        $results[]  = $meta;
    }

    if (empty($results)) return [];

    // 3) Cleanup: remove junk + anchor itself
    $results = filterBookResults($results, $referenceBook);

    return $results;
}

/* ----------------------------------------------------
   Trending Books (NYT Bestsellers)
---------------------------------------------------- */
function getTrendingBooks(): array {

    $nytKey = "rgHVtRpDGGGV8jDRTS6ftcGojeismyMi";

    $lists = [
        "hardcover-fiction",
        "hardcover-nonfiction",
        "young-adult",
        "advice-how-to-and-miscellaneous"
    ];

    $results = [];

    foreach ($lists as $list) {
        $url  = "https://api.nytimes.com/svc/books/v3/lists/current/$list.json?api-key=$nytKey";
        $resp = @file_get_contents($url);
        if (!$resp) continue;

        $data  = json_decode($resp, true);
        $books = $data["results"]["books"] ?? [];

        foreach ($books as $b) {
            $results[] = [
                "title"       => $b["title"] ?? "",
                "author"      => $b["author"] ?? "",
                "cover"       => $b["book_image"] ?? null,
                "description" => $b["description"] ?? "",
                "rank"        => $b["rank"] ?? 0,
                "type"        => "book"
            ];
        }
    }

    usort($results, fn($a, $b) => ($a["rank"] ?? 9999) <=> ($b["rank"] ?? 9999));

    return array_slice($results, 0, 30);
}

?>


