<?php

/* ----------------------------------------
   Safe GET wrapper
-----------------------------------------*/
function safeGet($url) {
    $resp = @file_get_contents($url);
    if ($resp === false) return null;
    return json_decode($resp, true);
}

/* ----------------------------------------
   Normalizers (updated with language + adult)
-----------------------------------------*/
function normalizeMovie($m) {
    return [
        "title"             => $m["title"] ?? "",
        "poster"            => !empty($m["poster_path"]) ? "https://image.tmdb.org/t/p/w300".$m["poster_path"] : null,
        "year"              => !empty($m["release_date"]) ? substr($m["release_date"], 0, 4) : "",
        "overview"          => $m["overview"] ?? "",
        "popularity"        => $m["popularity"] ?? 0,
        "original_language" => $m["original_language"] ?? "en",
        "adult"             => $m["adult"] ?? false
    ];
}

function normalizeTV($s) {
    return [
        "title"             => $s["name"] ?? "",
        "poster"            => !empty($s["poster_path"]) ? "https://image.tmdb.org/t/p/w300".$s["poster_path"] : null,
        "year"              => !empty($s["first_air_date"]) ? substr($s["first_air_date"], 0, 4) : "",
        "overview"          => $s["overview"] ?? "",
        "popularity"        => $s["popularity"] ?? 0,
        "original_language" => $s["original_language"] ?? "en",
        "adult"             => $s["adult"] ?? false
    ];
}

/* ----------------------------------------
   Genre detection for mood queries
-----------------------------------------*/
function detectMovieGenreString(array $keywords) {
    $text = strtolower(implode(" ", $keywords));

    $map = [
        "romance" => [10749],
        "romantic" => [10749],
        "love" => [10749],
        "sad" => [18],
        "cry" => [18],
        "emotional" => [18],
        "drama" => [18],
        "comedy" => [35],
        "funny" => [35],
        "thriller" => [53],
        "crime" => [80],
        "mystery" => [9648],
        "sci-fi" => [878],
        "science fiction" => [878],
        "space" => [878, 12],
        "fantasy" => [14],
        "action" => [28],
        "adventure" => [12],
        "horror" => [27]
    ];

    $found = [];
    foreach ($map as $word => $ids) {
        if (str_contains($text, $word)) {
            $found = array_merge($found, $ids);
        }
    }

    return empty($found) ? null : implode(",", array_unique($found));
}

function detectTVGenreString(array $keywords) {
    return detectMovieGenreString($keywords);
}

/* ----------------------------------------
   UNIVERSAL FILTER: removes adult content,
   foreign-language media, and low-popularity items
-----------------------------------------*/
function filterAndSort($items) {

    $excludeLangs = ["ja", "ko", "zh"];

    $filtered = [];

    foreach ($items as $m) {
        if (empty($m["title"])) continue;

        if (!empty($m["adult"])) continue;

        $ov = strtolower($m["overview"] ?? "");
        if (str_contains($ov, "sex") || str_contains($ov, "erotic")) continue;

        $lang = strtolower($m["original_language"] ?? "en");
        if (in_array($lang, $excludeLangs)) continue;

        $filtered[] = $m;
    }

    // ⚠️ DO NOT REORDER TMDB relevance order
    // Only sort lightly by popularity DESC
    usort($filtered, function($a, $b) {
        return ($b["popularity"] ?? 0) <=> ($a["popularity"] ?? 0);
    });

    return array_slice($filtered, 0, 100);
}

/* ----------------------------------------
   MOVIES
-----------------------------------------*/
function getMovies(array $keywords, string $mood = "neutral", ?string $referenceMovie = null) {
    $apiKey = "4599f700ed0fc9177265d38768609421";
    $today  = date("Y-m-d");

    /* -----------------------
       CASE 1: “movies like X”
       Use similar + recommendations
    ------------------------*/
    if (!empty($referenceMovie)) {

        $searchUrl = "https://api.themoviedb.org/3/search/movie?api_key=$apiKey&query=".urlencode($referenceMovie);
        $search = safeGet($searchUrl);

        if (empty($search["results"])) return [];

        $movie = null;

        foreach ($search["results"] as $m) {
            $id = $m["id"];

            // check if TMDB actually HAS related movies
            $checkUrl = "https://api.themoviedb.org/3/movie/$id/similar?api_key=$apiKey&page=1";
            $check = safeGet($checkUrl);

            if (!empty($check["results"])) {
                $movie = $m;
                break;
            }
        }

        // fallback if none have similar results
        if ($movie === null) {
            $movie = $search["results"][0];
        }

        $movieId = $movie["id"];

        $results = [];

        /* -----------------------
        Similar Movies (pages 1–5)
        ------------------------*/
        for ($page = 1; $page <= 5; $page++) {
            $simUrl = "https://api.themoviedb.org/3/movie/$movieId/similar?api_key=$apiKey&page=$page";
            $sim = safeGet($simUrl);

            if (empty($sim["results"])) break;

            foreach ($sim["results"] as $m) {
                $results[] = normalizeMovie($m);
            }
        }

        /* -----------------------
        Recommended Movies (pages 1–5)
        ------------------------*/
        for ($page = 1; $page <= 5; $page++) {
            $recUrl = "https://api.themoviedb.org/3/movie/$movieId/recommendations?api_key=$apiKey&page=$page";
            $rec = safeGet($recUrl);

            if (empty($rec["results"])) break;

            foreach ($rec["results"] as $m) {
                $results[] = normalizeMovie($m);
            }
        }

        // Dedupe by title
        $unique = [];
        foreach ($results as $r) {
            $key = strtolower(trim($r["title"]));
            if ($key && !isset($unique[$key])) {
                $unique[$key] = $r;
            }
        }

        return filterAndSort(array_values($unique));
    }

    /* -----------------------
       CASE 2: no reference
       Keyword search + genre discover
    ------------------------*/
    $query = trim(implode(" ", $keywords));
    $results = [];

    // A) Search-based
    if ($query !== "") {
        $url = "https://api.themoviedb.org/3/search/movie?api_key=$apiKey&query=".urlencode($query)."&include_adult=false&page=1";
        $data = safeGet($url);

        foreach ($data["results"] ?? [] as $m) {
            $results[] = normalizeMovie($m);
        }
    }

    // B) Genre-based discover (when user says “romantic movies”, etc.)
    $genreStr = detectMovieGenreString($keywords);
    if ($genreStr) {
        $url = "https://api.themoviedb.org/3/discover/movie?api_key=$apiKey&with_genres=$genreStr&sort_by=popularity.desc&include_adult=false&page=1";
        $data = safeGet($url);

        foreach ($data["results"] ?? [] as $m) {
            $results[] = normalizeMovie($m);
        }
    }

    // Dedupe
    $unique = [];
    foreach ($results as $r) {
        $key = strtolower(trim($r["title"]));
        if ($key && !isset($unique[$key])) {
            $unique[$key] = $r;
        }
    }

    return filterAndSort(array_values($unique));
}

/* ----------------------------------------
   TV Shows (same design)
-----------------------------------------*/
function getTVShows(array $keywords, string $mood = "neutral", ?string $referenceShow = null) {
    $apiKey = "4599f700ed0fc9177265d38768609421";

    if (!empty($referenceShow)) {

        $searchUrl = "https://api.themoviedb.org/3/search/tv?api_key=$apiKey&query=".urlencode($referenceShow);
        $search = safeGet($searchUrl);

        if (empty($search["results"])) return [];

        $show = $search["results"][0];
        $showId = $show["id"];

        $results = [];

        // Similar
        $simUrl = "https://api.themoviedb.org/3/tv/$showId/similar?api_key=$apiKey&page=1";
        $sim = safeGet($simUrl);
        foreach ($sim["results"] ?? [] as $s) {
            $results[] = normalizeTV($s);
        }

        // Recommendations
        $recUrl = "https://api.themoviedb.org/3/tv/$showId/recommendations?api_key=$apiKey&page=1";
        $rec = safeGet($recUrl);
        foreach ($rec["results"] ?? [] as $s) {
            $results[] = normalizeTV($s);
        }

        // Dedupe
        $unique = [];
        foreach ($results as $r) {
            $key = strtolower(trim($r["title"]));
            if ($key && !isset($unique[$key])) {
                $unique[$key] = $r;
            }
        }

        return filterAndSort(array_values($unique));
    }

    // No reference → keyword + genre
    $query = trim(implode(" ", $keywords));
    $results = [];

    if ($query !== "") {
        $url = "https://api.themoviedb.org/3/search/tv?api_key=$apiKey&query=".urlencode($query)."&page=1";
        $data = safeGet($url);

        foreach ($data["results"] ?? [] as $s) {
            $results[] = normalizeTV($s);
        }
    }

    $genreStr = detectTVGenreString($keywords);
    if ($genreStr) {
        $url = "https://api.themoviedb.org/3/discover/tv?api_key=$apiKey&with_genres=$genreStr&sort_by=popularity.desc&page=1";
        $data = safeGet($url);

        foreach ($data["results"] ?? [] as $s) {
            $results[] = normalizeTV($s);
        }
    }

    // Dedupe
    $unique = [];
    foreach ($results as $r) {
        $key = strtolower(trim($r["title"]));
        if ($key && !isset($unique[$key])) {
            $unique[$key] = $r;
        }
    }

    return filterAndSort(array_values($unique));
}

/* ----------------------------------------
   EXACT MATCH (used for RecentItems)
-----------------------------------------*/
function getExactMovie($title) {
    $apiKey = "4599f700ed0fc9177265d38768609421";
    $url = "https://api.themoviedb.org/3/search/movie?api_key=$apiKey&query=".urlencode($title);

    $data = safeGet($url);
    if (empty($data["results"])) return null;

    $normalizedQuery = strtolower(preg_replace("/[^a-z0-9]/i", "", $title));

    foreach ($data["results"] as $m) {
        $candidate = strtolower(preg_replace("/[^a-z0-9]/i", "", $m["title"] ?? ""));
        similar_text($normalizedQuery, $candidate, $sim);

        if ($sim >= 70) {
            return normalizeMovie($m);
        }
    }
    return null;
}

function getExactTVShow($title) {
    $apiKey = "4599f700ed0fc9177265d38768609421";
    $url = "https://api.themoviedb.org/3/search/tv?api_key=$apiKey&query=".urlencode($title);

    $data = safeGet($url);
    if (empty($data["results"])) return null;

    $normalizedQuery = strtolower(preg_replace("/[^a-z0-9]/i", "", $title));

    foreach ($data["results"] as $s) {
        $candidate = strtolower(preg_replace("/[^a-z0-9]/i", "", $s["name"] ?? ""));
        similar_text($normalizedQuery, $candidate, $sim);

        if ($sim >= 70) {
            return normalizeTV($s);
        }
    }
    return null;
}

/* ----------------------------------------
   Trending Movies + TV
-----------------------------------------*/
function getTrendingMovies() {
    $apiKey = "4599f700ed0fc9177265d38768609421";
    $url = "https://api.themoviedb.org/3/trending/movie/week?api_key=$apiKey";

    $data = safeGet($url);
    if (empty($data["results"])) return [];

    $normalized = array_map("normalizeMovie", $data["results"]);
    return filterAndSort($normalized);
}

function getTrendingTV() {
    $apiKey = "4599f700ed0fc9177265d38768609421";
    $url = "https://api.themoviedb.org/3/trending/tv/week?api_key=$apiKey";

    $data = safeGet($url);
    if (empty($data["results"])) return [];

    $normalized = array_map("normalizeTV", $data["results"]);
    return filterAndSort($normalized);
}

?>
