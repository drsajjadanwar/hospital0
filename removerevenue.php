<?php
// /public/removerevenue.php

session_start();
require_once __DIR__ . '/includes/config.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['group_id'] != 1) {
    header("Location: login.php");
    exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Suppose you allow removing multiple revenue entries at once
    $selected_ids = $_POST['selected_ids'] ?? [];
    if (!empty($selected_ids)) {
        $in_placeholders = rtrim(str_repeat('?,', count($selected_ids)), ',');
        $sql = "DELETE FROM ledger WHERE id IN ($in_placeholders) AND type = 'revenue'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($selected_ids);
        $message = "Selected revenue entries removed.";
    } else {
        $message = "No revenue entries selected to remove.";
    }
}

// Fetch all revenue entries
$stmtAll = $pdo->query("
  SELECT * 
  FROM ledger 
  WHERE type = 'revenue'
  ORDER BY entry_date DESC
");
$revenues = $stmtAll->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>hospital0 - Remove Revenue</title>
  <link rel="icon" href="/media/sitelogo.png" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<style>
  th {
    text-align: center;
  }
</style>
<?php include_once __DIR__ . '/includes/header.php'; ?>

<div class="container">
  <h4 class="center-align">Remove Revenue</h4>
  <?php if ($message): ?>
    <p class="green-text center-align"><?php echo htmlspecialchars($message); ?></p>
  <?php endif; ?>
  
  <form method="POST">
<table class="striped responsive-table">
  <thead>
    <tr>
      <th>Select</th>
      <th>Date</th>
      <th>Amount</th>
      <th>Description</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($revenues as $rev): ?>
    <tr>
      <td class="center-align">
        <label>
          <input type="checkbox" name="selected_ids[]" value="<?php echo $rev['id']; ?>" />
          <span></span>
        </label>
      </td>
      <td class="center-align"><?php echo htmlspecialchars($rev['entry_date']); ?></td>
      <td class="center-align"><?php echo htmlspecialchars($rev['amount']); ?></td>
      <td class="center-align"><?php echo htmlspecialchars($rev['description']); ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
    <div class="row center-align" style="margin-top: 20px;">
      <button type="submit" class="btn red darken-2">Remove Selected</button>
    </div>
  </form>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
</body>
</html>
