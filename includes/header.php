<?php
// /includes/header.php

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Determine if user is logged in
$isLoggedIn = isset($_SESSION['user']);
?>
<header class="header-container">
  <div class="top-bar">
    <img src="/media/logo.png" alt="Portal Logo" class="logo">

    <!-- Desktop Navigation -->
    <nav class="navbar desktop-nav">
      <ul>
        <!-- Home link -->
        <?php if ($isLoggedIn): ?>
          <li><a href="dashboard.php" class="nav-link white-text">Home</a></li>
        <?php else: ?>
          <li><a href="index.php" class="nav-link white-text">Home</a></li>
        <?php endif; ?>

        <li><a href="attendance.php" class="nav-link white-text">Attendance</a></li>
        <li><a href="office_notices.php" class="nav-link white-text">Office Notices</a></li>
        <li><a href="complaints.php" class="nav-link white-text">Complaints</a></li>
        <li><a href="https://work.anwar.health" class="nav-link white-text">Careers</a></li>

        <?php if ($isLoggedIn): ?>
          <li><a href="logout.php" class="nav-link white-text">Logout</a></li>
        <?php endif; ?>
      </ul>
    </nav>

    <!-- Hamburger (mobile) -->
    <button class="nav-toggle" id="navToggle">&#9776;</button>
  </div>

  <!-- Mobile Navigation Overlay -->
  <div class="mobile-nav-overlay" id="mobileNavOverlay">
    <div class="mobile-nav-menu">
      <button class="nav-toggle close-btn" id="closeNav">&times;</button>
      <ul>
        <?php if ($isLoggedIn): ?>
          <li><a href="dashboard.php" class="mobile-nav-link">Home</a></li>
        <?php else: ?>
          <li><a href="index.php" class="mobile-nav-link">Home</a></li>
        <?php endif; ?>

        <li><a href="attendance.php" class="mobile-nav-link">Attendance</a></li>
        <li><a href="office_notices.php" class="mobile-nav-link">Office Notices</a></li>
        <li><a href="complaints.php" class="mobile-nav-link">Complaints</a></li>
        <li><a href="#" class="mobile-nav-link">Careers</a></li>

        <?php if ($isLoggedIn): ?>
          <li><a href="logout.php" class="mobile-nav-link">Logout</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>

  <aside class="sidebar">
    <!-- Sidebar content -->
  </aside>
</header>
<hr class="header-line">

<!-- Styles -->
<style>
  .header-container {
    padding: 10px;
    background: transparent;
    border-bottom: none;
    position: relative;
    z-index: 1000;
  }
  .top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .logo { height: 60px; }
  .header-line {
    display: block;
    width: 100%;
    margin: 0 auto 20px auto;
    border: none;
    height: 2px;
    background: #fff;
  }
  /* Desktop nav */
  .desktop-nav ul { list-style: none; margin: 0; padding: 0; display: flex; }
  .desktop-nav ul li { margin-right: 15px; }
  .desktop-nav ul li a { color: #fff; text-decoration: none; }
  .desktop-nav ul li a:hover { text-decoration: underline; }

  /* Hamburger (hidden on desktop) */
  .nav-toggle {
    background: none; border: none; font-size: 2em; color: #333; cursor: pointer; display: none;
  }

  /* Mobile overlay */
  .mobile-nav-overlay {
    position: fixed; top: 0; right: -100%;
    width: 80%; max-width: 300px; height: 100%;
    background: rgba(0,0,0,0.9);
    transition: right .3s ease;
    z-index: 9999;
  }
  .mobile-nav-menu { padding: 20px; }
  .mobile-nav-menu ul { list-style: none; margin: 40px 0 0 0; padding: 0; }
  .mobile-nav-menu ul li { margin: 20px 0; }
  .mobile-nav-menu ul li a { color: #fff; text-decoration: none; font-size: 1.2em; }
  .mobile-nav-menu ul li a:hover { text-decoration: underline; }
  .mobile-nav-menu .close-btn { background: none; border: none; font-size: 2em; color: #fff; float: right; cursor: pointer; }

  /* Responsive */
  @media (max-width: 768px) {
    .desktop-nav { display: none; }
    .nav-toggle { display: block; }
  }
</style>

<!-- Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  const navToggle        = document.getElementById('navToggle');
  const mobileNavOverlay = document.getElementById('mobileNavOverlay');
  const closeNav         = document.getElementById('closeNav');
  let   isNavOpen        = false;

  function toggleMobileNav () {
    if (isNavOpen) {
      mobileNavOverlay.style.right = '-100%';
      isNavOpen = false;
    } else {
      mobileNavOverlay.style.right = '0';
      isNavOpen = true;
    }
  }

  navToggle.addEventListener('click', function (e) {
    e.stopPropagation();          // Prevent immediate close on the same click
    toggleMobileNav();
  });

  closeNav.addEventListener('click', toggleMobileNav);

  // Close when tapping overlay background
  mobileNavOverlay.addEventListener('click', function (e) {
    if (e.target === mobileNavOverlay) toggleMobileNav();
  });

  // NEW: Close when clicking *anywhere* outside the overlay
  document.addEventListener('click', function (e) {
    if (
      isNavOpen &&
      !mobileNavOverlay.contains(e.target) && // click outside overlay/menu
      e.target !== navToggle                  // ignore hamburger itself
    ) {
      toggleMobileNav();
    }
  });
});
</script>
