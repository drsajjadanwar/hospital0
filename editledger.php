<?php
// /public/editledger.php

session_start();
require_once __DIR__ . '/includes/config.php';

// Ensure the user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['group_id'] != 1) {
    header("Location: login.php");
    exit;
}

$message = '';
$editEntry = null;  // Will hold the ledger row being edited, if any
$ledger_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 1) If we have an id in the query string, fetch that ledger row.
if ($ledger_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM ledger WHERE id = ?");
    $stmt->execute([$ledger_id]);
    $editEntry = $stmt->fetch();

    if (!$editEntry) {
        $message = "Ledger entry not found or invalid ID.";
        $ledger_id = 0;  // reset so we just show the table
    }
}

// 2) If the user submitted the form to update, process it
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ledger'])) {
    $entry_date = $_POST['entry_date'];
    $amount = $_POST['amount'];
    $type = $_POST['type']; // 'revenue' or 'expense'
    $description = trim($_POST['description']);
    $update_id = (int)$_POST['update_id'];

    // Double-check that this entry still exists
    $checkStmt = $pdo->prepare("SELECT id FROM ledger WHERE id = ?");
    $checkStmt->execute([$update_id]);
    if ($checkStmt->rowCount() === 0) {
        $message = "Ledger entry not found for update.";
    } else {
        // Perform the update
        $stmtUpdate = $pdo->prepare("
          UPDATE ledger
          SET entry_date = ?, amount = ?, type = ?, description = ?
          WHERE id = ?
        ");
        $stmtUpdate->execute([$entry_date, $amount, $type, $description, $update_id]);
        $message = "Ledger entry updated successfully.";

        // Clear the edit mode
        $ledger_id = 0;
        $editEntry = null;
    }
}

// 3) Fetch all ledger rows for display below
$stmtAll = $pdo->query("SELECT * FROM ledger ORDER BY entry_date DESC, id DESC");
$allEntries = $stmtAll->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>hospital0 - Edit Ledger</title>
  <link rel="icon" href="/media/sitelogo.png" type="image/png">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Materialize CSS -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
  <!-- Custom CSS -->
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <?php include_once __DIR__ . '/includes/header.php'; ?>

  <div class="container">
    <h4 class="center-align">Edit Ledger</h4>
    <?php if ($message): ?>
      <p class="green-text center-align"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <!-- If $editEntry is set, show the edit form -->
    <?php if ($editEntry): ?>
      <div class="card" style="margin-bottom: 30px;">
        <div class="card-content">
          <span class="card-title">Editing Entry #<?php echo $editEntry['id']; ?></span>
          <form method="POST" class="col s12">
            <input type="hidden" name="update_id" value="<?php echo $editEntry['id']; ?>">
            <div class="row">
              <!-- Date -->
              <div class="input-field col s12 m6">
                <input id="entry_date" type="date" name="entry_date" required
                       value="<?php echo htmlspecialchars($editEntry['entry_date']); ?>">
                <label for="entry_date" class="active">Date</label>
              </div>
              <!-- Amount -->
              <div class="input-field col s12 m6">
                <input id="amount" type="number" step="0.01" name="amount" required
                       value="<?php echo htmlspecialchars($editEntry['amount']); ?>">
                <label for="amount" class="active">Amount (PKR)</label>
              </div>
            </div>
            <div class="row">
              <!-- Type -->
              <div class="input-field col s12 m6">
                <select name="type" required>
                  <option value="revenue" <?php echo ($editEntry['type'] === 'revenue') ? 'selected' : ''; ?>>Revenue</option>
                  <option value="expense" <?php echo ($editEntry['type'] === 'expense') ? 'selected' : ''; ?>>Expense</option>
                </select>
                <label class="active">Type</label>
              </div>
              <!-- Description -->
              <div class="input-field col s12 m6">
                <input id="description" type="text" name="description"
                       value="<?php echo htmlspecialchars($editEntry['description']); ?>">
                <label for="description" class="active">Description (optional)</label>
              </div>
            </div>
            <div class="row center-align">
              <button type="submit" name="update_ledger" class="btn waves-effect waves-light">Update Entry</button>
              <a href="editledger.php" class="btn grey">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <!-- Display all ledger entries in a table with an Edit link -->
    <table class="striped responsive-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Date</th>
          <th>Amount</th>
          <th>Type</th>
          <th>Description</th>
          <th>Edit</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allEntries as $entry): ?>
          <tr>
            <td><?php echo $entry['id']; ?></td>
            <td><?php echo htmlspecialchars($entry['entry_date']); ?></td>
            <td><?php echo htmlspecialchars($entry['amount']); ?></td>
            <td><?php echo htmlspecialchars($entry['type']); ?></td>
            <td><?php echo htmlspecialchars($entry['description']); ?></td>
            <td>
              <a href="editledger.php?id=<?php echo $entry['id']; ?>" class="btn-small">
                Edit
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php include_once __DIR__ . '/includes/footer.php'; ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function() {
    var elems = document.querySelectorAll('select');
    M.FormSelect.init(elems);
  });
  </script>
</body>
</html>

