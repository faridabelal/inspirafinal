<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>

<header style="
    background:#fff;
    padding:12px 25px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    box-shadow:0 2px 6px rgba(0,0,0,0.08);
    position:sticky;
    top:0;
    z-index:100;
">

  <!-- Logo -->
  <div style="font-family:'Poppins',sans-serif;font-weight:600;font-size:1.3rem;color:#000;">
    <a href="/~febelal/Inspira/index.php" style="text-decoration:none;color:#000;">Inspira</a>
  </div>

  <nav style="display:flex;gap:16px;">

    <?php if (isset($_SESSION["role"]) && $_SESSION["role"] === "admin"): ?>

        <!-- ADMIN NAV -->
        <a href="/~febelal/Inspira/admin/admin_dashboard.php" style="text-decoration:none;color:#333;font-weight:500;">Admin Dashboard</a>
        <a href="/~febelal/Inspira/auth/logout.php" style="text-decoration:none;color:#9F9A7F;font-weight:600;">Logout</a>

    <?php elseif (isset($_SESSION["user_id"])): ?>

        <!-- USER NAV -->
        <a href="/~febelal/Inspira/search.php" style="text-decoration:none;color:#333;font-weight:500;">Search</a>
        <a href="/~febelal/Inspira/User/boards.php" style="text-decoration:none;color:#333;font-weight:500;">Boards</a>
        <a href="/~febelal/Inspira/User/favorites.php" style="text-decoration:none;color:#333;font-weight:500;">Favorites</a>
        <a href="/~febelal/Inspira/User/dashboard.php" style="text-decoration:none;color:#333;font-weight:500;">Dashboard</a>
        <a href="/~febelal/Inspira/auth/logout.php" style="text-decoration:none;color:#9F9A7F;font-weight:600;">Logout</a>

    <?php else: ?>

        <!-- GUEST NAV -->  
        <a href="/~febelal/Inspira/auth/login.php" style="text-decoration:none;color:#333;font-weight:500;">Login</a>
        <a href="/~febelal/Inspira/auth/register.php" style="text-decoration:none;color:#333;font-weight:500;">Sign Up</a>

    <?php endif; ?>

  </nav>

</header>
