<?php
// /public/viewentry.php

session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

// Include your DB config or relevant file that defines $pdo
require_once __DIR__ . '/includes/config.php';

// Retrieve user info from session
$user = $_SESSION['user'] ?? [];
$allowedGroups = [1, 2, 3, 5, 8];
if (!in_array(($user['group_id'] ?? 0), $allowedGroups)) {
    echo "<h5>You do not have permission to view this entry.</h5>";
    exit;
}

// Grab the serial number from GET
$serial = isset($_GET['serial']) ? (int)$_GET['serial'] : 0;
if ($serial <= 0) {
    echo "<h5>Invalid or missing entry ID.</h5>";
    exit;
}

// Fetch that ledger record
$stmt = $pdo->prepare("SELECT * FROM generalledger WHERE serial_number = :serial");
$stmt->execute([':serial' => $serial]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entry) {
    echo "<h5>No ledger entry found for that ID.</h5>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>hospital0 - View Ledger Entry #<?php echo $serial; ?></title>
  <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
  <link rel="stylesheet" href="assets/css/style.css">

  <!-- Override text color in .collection-item to black, except for .red-text/.green-text -->
  <style>
    .collection .collection-item {
      color: #000 !important; /* Make text black */
    }
    .collection-item .red-text {
      color: red !important;
    }
    .collection-item .green-text {
      color: green !important;
    }
  </style>
</head>
<body>
<?php include_once __DIR__ . '/includes/header.php'; ?>

<div class="container">
  <h3 class="center-align" style="margin-top:40px;">
    Ledger Entry #<?php echo (int)$serial; ?>
  </h3>
  <hr />

  <div class="row">
    <div class="col s12 m8 offset-m2">
      <ul class="collection">
        <li class="collection-item">
          <strong>Date &amp; Time:</strong>
          <span><?php echo htmlspecialchars($entry['datetime']); ?></span>
        </li>
        <li class="collection-item">
          <strong>Description:</strong>
          <span><?php echo htmlspecialchars($entry['description']); ?></span>
        </li>
        <li class="collection-item">
          <strong>Amount (PKR):</strong>
          <?php
            $amt = (float)$entry['amount'];
            $amtFormatted = number_format($amt, 2);
            // highlight negative vs positive
            $color = ($amt < 0) ? 'red-text' : 'green-text';
          ?>
          <span class="<?php echo $color; ?>">
            <?php echo $amtFormatted; ?>
          </span>
        </li>
        <li class="collection-item">
          <strong>Added by:</strong>
          <span><?php echo htmlspecialchars($entry['user']); ?></span>
        </li>
      </ul>
      <div class="center-align" style="margin-top:20px;">
        <a href="viewledger.php" class="btn grey">Back to Ledger</a>
      </div>
    </div>
  </div>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
