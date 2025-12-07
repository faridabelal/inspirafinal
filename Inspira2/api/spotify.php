<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * spotify.php
 * AI-driven song recommendations for Inspira:
 * - OpenAI decides what songs to recommend (40–60 titles)
 * - Spotify is ONLY used to fetch metadata / previews
 * - No remixes, live, acoustic, slowed, covers, etc.
 *
 * Functions:
 *   getSongs(array $keywords, string $mood = "neutral", ?string $referenceSong = null): array
 *   getTrendingSongs(): array
 *   getExactSong(string $title): ?array
 */

/* ----------------------------------------------------
   Helper: get Spotify access token (client credentials)
---------------------------------------------------- */
function getSpotifyAccessToken(): ?string {
    $client_id     = "d176b6b11f2f4993978b94f7f9e73912";
    $client_secret = "138ff741627d4a05938718839c662375";

    $token_url = "https://accounts.spotify.com/api/token";
    $headers = [
        "Authorization: Basic " . base64_encode("$client_id:$client_secret"),
        "Content-Type: application/x-www-form-urlencoded"
    ];
    $body = "grant_type=client_credentials";

    $ch = curl_init($token_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body
    ]);

    $token_response = curl_exec($ch);
    curl_close($ch);

    if (!$token_response) return null;

    $token_data = json_decode($token_response, true);
    return $token_data["access_token"] ?? null;
}

/* ----------------------------------------------------
   Normalize Spotify track → Inspira shape
---------------------------------------------------- */
function normalizeTrack(array $t): array {
    return [
        "title"        => $t["name"] ?? "",
        "artist"       => $t["artists"][0]["name"] ?? "",
        "album"        => $t["album"]["name"] ?? "",
        "release_date" => $t["album"]["release_date"] ?? "",
        "cover"        => $t["album"]["images"][0]["url"] ?? null,
        "preview_url"  => $t["preview_url"] ?? null,
        "popularity"   => $t["popularity"] ?? 0,
        "type"         => "song"
    ];
}

/* ----------------------------------------------------
   OpenAI: recommend songs (titles + artists + year)
   - Handles BOTH:
     • songs like X
     • vibe-only queries (sad songs, fall songs, etc.)
   Returns: array of [ [title, artist, year], ... ]
---------------------------------------------------- */
function aiSongRecommendations(string $rawQuery): array {

    $openaiKey = "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";

    $systemPrompt = "
You are a music recommendation engine for a web app called Inspira.

Your job:
- Read the user's query.
- If they mention a specific song (e.g., 'songs like Teenage Dream'),
  recommend songs based on:
  1) Similar songs by the SAME main artist (where it makes sense),
  2) Songs from the same era (roughly ±5 years),
  3) Songs by similar artists and vibe (genre, mood, energy),
  4) Songs in the same general genre (pop, country, techno, etc.).
- If they give only a vibe (e.g., 'sad dark songs', 'fall songs',
  'christmas songs', 'country breakup songs'), recommend popular songs
  matching that vibe.

You must consider:
- Artist
- Era / release period
- Vibe (sad, happy, dark, chill, romantic, etc.)
- Genre (pop, rock, country, EDM, R&B, etc.)

You MUST respond with VALID JSON only, in this format:

{
  \"tracks\": [
    { \"title\": \"\", \"artist\": \"\", \"year\": 2010 },
    { \"title\": \"\", \"artist\": \"\", \"year\": 2011 },
    ...
  ]
}

Rules:
- Always return between 80 and 120 tracks if possible.
- NEVER include the anchor song itself (the song they gave).
- NEVER include remixes, live versions, acoustic versions,
  slowed, nightcore, covers, karaoke, or re-recordings in the titles.
- Titles must be the original, most well-known recording name.
- \"year\" should be the original release year (best estimate).
- Keep artists and titles clear and concise.
- Focus on English-language or globally popular songs.
";

    $payload = [
        "model" => "gpt-4o-mini",
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user",   "content" => $rawQuery]
        ],
        "temperature" => 0.25
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $openaiKey
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload)
    ]);

    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return [];

    $data    = json_decode($resp, true);
    $content = $data["choices"][0]["message"]["content"] ?? "{}";
    $parsed  = json_decode($content, true);

    if (!is_array($parsed) || empty($parsed["tracks"]) || !is_array($parsed["tracks"])) {
        return [];
    }

    $results = [];
    $seen    = [];

    foreach ($parsed["tracks"] as $t) {
        $title  = trim((string)($t["title"]  ?? ""));
        $artist = trim((string)($t["artist"] ?? ""));
        $year   = isset($t["year"]) ? (int)$t["year"] : null;

        if ($title === "" || $artist === "") continue;

        $key = mb_strtolower($title . "||" . $artist);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $results[] = [
            "title"  => $title,
            "artist" => $artist,
            "year"   => $year
        ];
    }

    return $results;
}

/* ----------------------------------------------------
   Spotify: fetch track by title + artist
   - Uses search q = track:"title" artist:"artist"
   - Filters out remixes, live, acoustic, etc.
---------------------------------------------------- */
function fetchSpotifyTrackByTitleArtist(string $title, string $artist, string $accessToken): ?array {

    if ($title === "" || $artist === "" || !$accessToken) return null;

    $q = 'track:"' . $title . '" artist:"' . $artist . '"';

    $url = "https://api.spotify.com/v1/search?q=" . urlencode($q) . "&type=track&limit=10";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $accessToken"]
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return null;

    $data   = json_decode($resp, true);
    $tracks = $data["tracks"]["items"] ?? [];
    if (empty($tracks)) return null;

    $bannedWords = ["remix", "live", "acoustic", "cover", "edit", "slowed", "reverb", "nightcore", "karaoke", "tribute", "demo", "remaster", "re-recorded", "version", "mix"];

    // Helper to test if candidate looks like a clean original
    $isClean = function($trackName) use ($bannedWords) {
        $nameLower = mb_strtolower($trackName);
        foreach ($bannedWords as $w) {
            if (str_contains($nameLower, $w)) {
                return false;
            }
        }
        return true;
    };

    // Loose artist match helper
    $normalizeName = function($s) {
        return preg_replace("/[^a-z0-9]/i", "", mb_strtolower($s));
    };

    $targetArtistNorm = $normalizeName($artist);

    // 1) Try to find best candidate with matching artist + clean title
    foreach ($tracks as $t) {
        $tArtist = $t["artists"][0]["name"] ?? "";
        $tName   = $t["name"] ?? "";

        if (!$isClean($tName)) continue;

        $candArtistNorm = $normalizeName($tArtist);
        similar_text($targetArtistNorm, $candArtistNorm, $pct);

        if ($pct >= 70) {
            return normalizeTrack($t);
        }
    }

    // 2) Fallback: any clean track, even if artist slightly off
    foreach ($tracks as $t) {
        $tName = $t["name"] ?? "";
        if (!$isClean($tName)) continue;

        return normalizeTrack($t);
    }

    return null;
}

/* ----------------------------------------------------
   MAIN: getSongs()
   - Called by search.php
   - Uses OpenAI to pick songs
   - Uses Spotify to fetch metadata
   Signature:
     getSongs(array $keywords, string $mood = "neutral", ?string $referenceSong = null)
   Existing 2-arg calls still work (referenceSong defaults to null).
---------------------------------------------------- */
function getSongs($keywords, $mood = "neutral", $referenceSong = null) {

    // Ensure keywords is always an array
    if (!is_array($keywords)) {
        $keywords = preg_split("/\s+/", (string)$keywords);
    }

    $joined = trim(implode(" ", $keywords));
    if (empty($joined) && empty($referenceSong)) {
        return [];
    }

    // Build query for OpenAI
    if (!empty($referenceSong)) {
        // e.g. songs like Teenage Dream
        $rawQuery = 'Give me songs similar to "' . trim($referenceSong) . '" in terms of artist, era, and vibe.';
    } else {
        // e.g. sad dark songs, country breakup songs, fall songs, christmas songs
        $rawQuery = "Recommend popular songs that match this vibe: " . $joined . " (mood: " . $mood . ").";
    }

    // 1) Ask OpenAI for track list (title + artist + year)
    $aiTracks = aiSongRecommendations($rawQuery);

    if (empty($aiTracks)) {
        // If AI fails for some reason, return empty here,
        // or you could fall back to your old pure-Spotify logic.
        return [];
    }

    // 2) Get Spotify token once
    $accessToken = getSpotifyAccessToken();
    if (!$accessToken) return [];

    // 3) For each AI track, fetch metadata from Spotify
    $results = [];
    $seen    = [];

    foreach ($aiTracks as $t) {
        $title  = $t["title"]  ?? "";
        $artist = $t["artist"] ?? "";

        if ($title === "" || $artist === "") continue;

        $meta = fetchSpotifyTrackByTitleArtist($title, $artist, $accessToken);
        if (!$meta) continue;

        $key = mb_strtolower($meta["title"] . "||" . $meta["artist"]);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $results[] = $meta;

        // Hard cap just in case AI returns too many
        if (count($results) >= 120) break;
    }

    
    // so usually you'll get enough if Spotify has them.
    return $results;
}

/* ----------------------------------------------------
   Trending Songs:
   Keep your existing Top 50 playlist logic.
---------------------------------------------------- */
function getTrendingSongs() {
    $access_token = getSpotifyAccessToken();
    if (!$access_token) return [];

    // Top 50 USA playlist (unchanged)
    $playlist_id = "37i9dQZEVXbLRQDuF5jeBp";
    $url = "https://api.spotify.com/v1/playlists/$playlist_id";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $access_token"]
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return [];

    $data   = json_decode($resp, true);
    $tracks = $data["tracks"]["items"] ?? [];

    $results = [];

    foreach ($tracks as $item) {
        $t = $item["track"] ?? null;
        if (!$t) continue;

        $results[] = [
            "title"        => $t["name"],
            "artist"       => $t["artists"][0]["name"] ?? "",
            "album"        => $t["album"]["name"] ?? "",
            "release_date" => $t["album"]["release_date"] ?? "",
            "cover"        => $t["album"]["images"][0]["url"] ?? null,
            "preview_url"  => $t["preview_url"] ?? null,
            "popularity"   => $t["popularity"] ?? 0,
            "type"         => "song"
        ];
    }

    usort($results, fn($a, $b) => ($b["popularity"] ?? 0) <=> ($a["popularity"] ?? 0));

    return array_slice($results, 0, 50);
}

/* ----------------------------------------------------
   Exact Song (for RecentResults_SP, etc.)
   You can leave this mostly as-is.
---------------------------------------------------- */
function getExactSong($title) {
    $access = getSpotifyAccessToken();
    if (!$access) return null;

    $query = urlencode('track:"' . $title . '"');
    $url   = "https://api.spotify.com/v1/search?q=$query&type=track&limit=10";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $access"]
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return null;

    $json   = json_decode($resp, true);
    $tracks = $json["tracks"]["items"] ?? [];

    if (empty($tracks)) return null;

    // Strict title match first
    foreach ($tracks as $t) {
        if (strcasecmp($t["name"], $title) === 0) {
            return [
                "title"  => $t["name"],
                "artist" => $t["artists"][0]["name"] ?? "",
                "album"  => $t["album"]["name"] ?? "",
                "poster" => $t["album"]["images"][0]["url"] ?? null
            ];
        }
    }

    // Fallback: contains
    foreach ($tracks as $t) {
        if (stripos($t["name"], $title) !== false) {
            return [
                "title"  => $t["name"],
                "artist" => $t["artists"][0]["name"] ?? "",
                "album"  => $t["album"]["name"] ?? "",
                "poster" => $t["album"]["images"][0]["url"] ?? null
            ];
        }
    }

    return null;
}

?>
