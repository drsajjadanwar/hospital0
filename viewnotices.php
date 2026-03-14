<?php
// /public/viewnotices.php

session_start();
require_once __DIR__ . '/includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$currentUser = $_SESSION['user'];

// We'll display messages for errors or info
$message = '';

// Parse ?user_id
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// If a specific user is requested, we attempt to display their notices
$theUser = null;
$notices = [];

if ($user_id > 0) {
    // 1) Try to load that user
    $stmtUsr = $pdo->prepare("SELECT `user_id`, `username`, `full_name` FROM `users` WHERE `user_id` = ? LIMIT 1");
    $stmtUsr->execute([$user_id]);
    $theUser = $stmtUsr->fetch(PDO::FETCH_ASSOC);

    if (!$theUser) {
        $message = "User not found. Cannot display notices.";
        $user_id = 0; // revert to main listing
    } else {
        // 2) Load the notices for this user, newest first
        $stmtNotice = $pdo->prepare("
            SELECT `notice_id`, `notice_title`, `notice_body`, `created_at`
            FROM `user_notices`
            WHERE `user_id` = ?
            ORDER BY `created_at` DESC
        ");
        try {
            $stmtNotice->execute([$theUser['user_id']]);
            $notices = $stmtNotice->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $ex) {
            $message = "Error loading notices: " . $ex->getMessage();
            // Revert to main listing
            $user_id = 0;
            $theUser = null;
        }
    }
}

// If no user_id or theUser not found, we'll do the group-user listing
$groups = [];
if ($user_id === 0 || !$theUser) {
    // Load groups in alphabetical order
    try {
        $stmtGrp = $pdo->query("
            SELECT `group_id`, `group_name`
            FROM `groups`
            ORDER BY `group_name` ASC
        ");
        $groups = $stmtGrp->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) {
        $message = "Error loading groups: " . $ex->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>hospital0 - View Notices</title>
  <link rel="icon" href="/media/sitelogo.png" type="image/png">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Materialize CSS -->
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
  <!-- Custom CSS -->
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include_once __DIR__ . '/includes/header.php'; ?>

<div class="container">
  <h2 class="center-align" style="margin-top:30px;">View Notices</h2>
  <?php if ($message): ?>
    <p class="red-text center-align"><?php echo htmlspecialchars($message); ?></p>
  <?php endif; ?>

  <?php if ($user_id > 0 && $theUser): ?>
    <!-- Display the user's notices -->
    <h4 style="margin-top: 20px;">
      Notices for User:
      <!-- Username and full name in white -->
      <span style="color: #fff;">
        <?php echo htmlspecialchars($theUser['username'] . " - " . $theUser['full_name']); ?>
      </span>
    </h4>

    <!-- Link back to main listing -->
    <div style="margin-bottom: 30px;">
      <a href="viewnotices.php" class="btn-small">Back to All Users</a>
    </div>

    <?php if (count($notices) === 0): ?>
      <p>No notices found for this user.</p>
    <?php else: ?>
      <!-- Display notices, newest first -->
      <?php foreach ($notices as $idx => $nt): ?>
        <div style="background-color: #fff; color: #000; padding: 10px; margin-bottom: 20px;">
          <h5 style="margin-top: 0;">
            <?php echo htmlspecialchars($nt['notice_title']); ?>
          </h5>
          <p>
            <?php echo nl2br(htmlspecialchars($nt['notice_body'])); ?>
          </p>
          <small>Issued on: <?php echo $nt['created_at']; ?></small>
        </div>
        <?php if ($idx < count($notices) - 1): ?>
          <!-- Horizontal white line 2px at 20% width in center -->
          <hr style="width: 20%; background-color: #fff; height: 2px; border: none; margin: 20px auto;">
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php else: ?>
    <!-- Show groups and users listing, each user has link to viewnotices.php?user_id=XX -->
    <h4>All Groups &amp; Users</h4>
    <?php if (count($groups) === 0): ?>
      <p>No groups found.</p>
    <?php else: ?>
      <?php
      foreach ($groups as $gr) {
          $gid   = $gr['group_id'];
          $gname = $gr['group_name'];

          // fetch users in this group
          $stmtUsr = $pdo->prepare("
            SELECT `user_id`, `username`
            FROM `users`
            WHERE `group_id` = ?
            ORDER BY `user_id` ASC
          ");
          $stmtUsr->execute([$gid]);
          $users = $stmtUsr->fetchAll(PDO::FETCH_ASSOC);

          echo "<h5 style='margin-top:30px;'>".htmlspecialchars($gname)."</h5>";
          if (count($users) === 0) {
              echo "<p>No users in this group.</p>";
          } else {
              echo "<ul class='collection'>";
              foreach ($users as $u) {
                  echo "<li class='collection-item'>";
                  echo "<strong>User:</strong> <span style='color:#000;'>".htmlspecialchars($u['username'])."</span>";
                  // Link to open their notices
                  echo " &nbsp; <a href='viewnotices.php?user_id={$u['user_id']}' class='btn-small'>Open Notices</a>";
                  echo "</li>";
              }
              echo "</ul>";
          }
      }
      ?>
    <?php endif; ?>
  <?php endif; ?>

</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
</body>
</html>
