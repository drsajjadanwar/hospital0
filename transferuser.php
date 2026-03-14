<?php
// /public/transferuser.php
session_start();
require_once __DIR__ . '/includes/config.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['group_id'] != 1) {
    header("Location: login.php");
    exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'];
    $group_id = $_POST['new_group_id'];

    $stmt = $pdo->prepare("UPDATE users SET group_id = ? WHERE user_id = ?");
    $stmt->execute([$group_id, $user_id]);
    $message = "User transferred successfully.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>hospital0 - Transfer User</title>
  <link rel="icon" href="/media/sitelogo.png" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include_once __DIR__ . '/includes/header.php'; ?>

<div class="container">
  <h4 class="center-align">Transfer User to Another Group</h4>
  <?php if ($message): ?>
    <p class="green-text center-align"><?php echo htmlspecialchars($message); ?></p>
  <?php endif; ?>
  <form method="POST" class="col s12">
    <div class="row">
      <div class="input-field col s12 m6">
        <select name="user_id" required>
          <option value="" disabled selected>Select User</option>
          <?php
          $stmtU = $pdo->query("SELECT user_id, username FROM users WHERE terminated = 0");
          while ($u = $stmtU->fetch()) {
              echo '<option value="'.$u['user_id'].'">'.htmlspecialchars($u['username']).'</option>';
          }
          ?>
        </select>
        <label>Select User</label>
      </div>
      <div class="input-field col s12 m6">
        <select name="new_group_id" required>
          <option value="" disabled selected>Select New Group</option>
          <?php
          $stmtG = $pdo->query("SELECT group_id, group_name FROM `groups`");
          while ($g = $stmtG->fetch()) {
              echo '<option value="'.$g['group_id'].'">'.htmlspecialchars($g['group_name']).'</option>';
          }
          ?>
        </select>
        <label>Select Group</label>
      </div>
    </div>
    <div class="row center-align">
      <button type="submit" class="btn waves-effect waves-light">Transfer</button>
    </div>
  </form>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    M.FormSelect.init(document.querySelectorAll('select'));
});
</script>
</body>
</html>