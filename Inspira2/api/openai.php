<?php

/* ============================================================
   analyzeQuery()
   FINAL VERSION – CLEAN, SIMPLE, SMART
   Handles:
   - movies like X
   - tv shows like X
   - books like X
   - songs like X
   - similar to X
   - standalone titles (auto-detect category)
   - mood/genre/vibe queries
============================================================ */

function analyzeQuery($query) {

    $lower = strtolower(trim($query));

    // Stop words used to detect when user typed a pure title
    $stopWords = [
        "movie","movies","film","films","tv","show","shows","series",
        "book","books","song","songs","music","like","similar","to"
    ];


    /* ----------------------------------------------------------
       1) HARD PATTERN DETECTION — “movies like X”, etc.
    ---------------------------------------------------------- */

    if (preg_match('/movies?\s+like\s+(.+)/i', $query, $m)) {
        return ["category"=>"movies", "reference_title"=>trim($m[1]), "keywords"=>[]];
    }

    if (preg_match('/films?\s+like\s+(.+)/i', $query, $m)) {
        return ["category"=>"movies", "reference_title"=>trim($m[1]), "keywords"=>[]];
    }

    if (preg_match('/(tv|series|show)s?\s+like\s+(.+)/i', $query, $m)) {
        return ["category"=>"tv", "reference_title"=>trim($m[2]), "keywords"=>[]];
    }

    if (preg_match('/books?\s+like\s+(.+)/i', $query, $m)) {
        return ["category"=>"books", "reference_title"=>trim($m[1]), "keywords"=>[]];
    }

    if (preg_match('/(songs?|music)\s+like\s+(.+)/i', $query, $m)) {
        return ["category"=>"music", "reference_title"=>trim($m[2]), "keywords"=>[]];
    }

    if (preg_match('/similar to\s+(.+)/i', $query, $m)) {
        return ["category"=>"movies", "reference_title"=>trim($m[1]), "keywords"=>[]];
    }



    /* ----------------------------------------------------------
       2) AUTO-DETECT TITLE — If query is SHORT & NOT a category
       Example:
       - interstellar
       - me before you
       - titanic
       - gilmore girls
       - the fault in our stars
    ---------------------------------------------------------- */

    $words = explode(" ", $lower);
    $containsCategoryWord = false;

    foreach ($words as $w) {
        if (in_array($w, $stopWords)) {
            $containsCategoryWord = true;
            break;
        }
    }

    // If query looks like a title (<=5 words and no category words)
    if (count($words) <= 5 && !$containsCategoryWord) {

        $titleGuess = trim($query);

        // Try detecting movie
        if (tryMatchMovie($titleGuess)) {
            return ["category"=>"movies", "reference_title"=>$titleGuess, "keywords"=>[]];
        }

        // Try detecting TV show
        if (tryMatchTV($titleGuess)) {
            return ["category"=>"tv", "reference_title"=>$titleGuess, "keywords"=>[]];
        }

        // Try detecting Book
        if (tryMatchBook($titleGuess)) {
            return ["category"=>"books", "reference_title"=>$titleGuess, "keywords"=>[]];
        }

        // Skip songs (Spotify auth needed)
        // Default fallback → Movies
        return [
            "category"=>"movies",
            "reference_title"=>$titleGuess,
            "keywords"=>[]
        ];
    }



    /* ----------------------------------------------------------
    3) AI FALLBACK — (NOW RETURNS ANCHOR MOVIE)
    ---------------------------------------------------------- */

    $apiKey = "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";

    $systemPrompt = "
    Your job: When the user describes a vibe, genre, or mood (like 'romantic movies' or 'sad films'),
    select ONE PERFECT reference title (anchor movie, TV show, book, or song).

    Return ONLY JSON:
    {
    \"category\": \"movies|tv|books|music|mixed\",
    \"reference_title\": \"A famous title\",
    \"keywords\": [\"keyword1\", \"keyword2\"]
    }

    Rules:
    - ALWAYS include a reference_title for movies/tv/books/music mood queries.
    - Choose a very popular, widely known English-language item.
    - For romance: The Notebook, Me Before You, The Fault in Our Stars, La La Land, Five Feet Apart.
    - For sad: The Fault in Our Stars, All the Bright Places.
    - For emotional teen romance: Five Feet Apart, Midnight Sun.
    - For sci-fi: Interstellar, The Martian, Arrival.
    - If unclear: category = \"mixed\" and reference_title = \"\".
    ";

    $payload = [
        "model" => "gpt-4o-mini",
        "messages" => [
            ["role"=>"system", "content"=>$systemPrompt],
            ["role"=>"user", "content"=>$query]
        ],
        "temperature" => 0
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    $content = $data["choices"][0]["message"]["content"] ?? "{}";
    $parsed = json_decode($content, true);

    // Fallback if AI returns invalid JSON
    if (!is_array($parsed)) {
        return [
            "category" => "mixed",
            "reference_title" => "",
            "keywords" => explode(" ", strtolower($query))
        ];
    }

    return [
        "category" => $parsed["category"] ?? "mixed",
        "reference_title" => $parsed["reference_title"] ?? "",
        "keywords" => $parsed["keywords"] ?? []
    ];
}


/* ============================================================
   Helper Functions for Auto-Category Detection
============================================================ */

function tryMatchMovie($title) {
    $apiKey = "4599f700ed0fc9177265d38768609421";
    $url = "https://api.themoviedb.org/3/search/movie?api_key=$apiKey&query=" . urlencode($title);
    $data = json_decode(@file_get_contents($url), true);
    return !empty($data["results"]);
}

function tryMatchTV($title) {
    $apiKey = "4599f700ed0fc9177265d38768609421";
    $url = "https://api.themoviedb.org/3/search/tv?api_key=$apiKey&query=" . urlencode($title);
    $data = json_decode(@file_get_contents($url), true);
    return !empty($data["results"]);
}

function tryMatchBook($title) {
    $url = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode($title) . "&maxResults=1";
    $data = json_decode(@file_get_contents($url), true);
    return !empty($data["items"]);
}

// Optional: Song matching requires Spotify OAuth → skip
function tryMatchSong($title) {
    return false;
}

?>
