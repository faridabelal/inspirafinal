<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'includes/header.php';
?>

<div class="container-fluid p-0">
  <div class="row g-0 vh-100">
    <!-- Left side: overlapping images + search bar -->
    <div class="col-md-7 position-relative d-flex justify-content-center align-items-center bg-white overflow-hidden">

      <!-- Overlapping image layout -->
      <div class="image-stack position-relative">
        <img src="images/cinema.jpg" class="stack-img img1" alt="img1">
        <img src="images/books.jpg" class="stack-img img2" alt="img2">
        <img src="images/tvseries.jpg" class="stack-img img3" alt="img3">
        <img src="images/Spotify.jpg" class="stack-img img4" alt="img4">
      </div>

      <!-- Transparent search bar -->
      <div class="search-overlay text-center">
        <form action="search.php" method="GET" class="d-flex justify-content-center">
          <input type="text" name="query" class="form-control search-bar" placeholder="Look for movies, books, or music...">
          <button type="submit" class="btn btn-search ms-2">Search</button>
        </form>
      </div>
    </div>

    <!-- Right side: slogan -->
    <div class="col-md-5 d-flex flex-column justify-content-center align-items-start px-5">
      <h1 class="fw-bold mb-3" style="color:#000000; font-family:'Runtime','Poppins',sans-serif;">What’s your vibe?</h1>
      <p class="lead" style="color:#000000;">
        Want to watch a <span style="color:#9F9A7F;">modern version of Gilmore Girls?</span><br>
        Let Inspira find it for you.
      </p>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>





