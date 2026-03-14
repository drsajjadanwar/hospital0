<?php
// /public/sendmessage.php

// Uncomment these lines in development to see PHP errors (remove in production):
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/includes/config.php';

// 1) Check if user is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['group_id'] != 1) {
    header("Location: login.php");
    exit;
}

// Variables for success/error message
$message = '';
$messageType = 'red-text'; // default error style

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 2) Capture form data
    $subject    = trim($_POST['subject'] ?? '');
    $content    = trim($_POST['content'] ?? '');
    $recipients = $_POST['recipients'] ?? [];
    $sender_id  = $_SESSION['user']['user_id'];

    // 3) Validate required fields
    if ($subject === '' || $content === '' || empty($recipients)) {
        $message = "All fields (Subject, Message Body, Recipients) are required.";
    } else {
        try {
            // 4) Insert into messages table
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, content)
                VALUES (?, ?)
            ");
            // Combine subject + body for demonstration
            $finalContent = "Subject: $subject\n\n$content";
            $stmt->execute([$sender_id, $finalContent]);
            $message_id = $pdo->lastInsertId();

            // 5) Insert into message_recipients
            if (in_array('all', $recipients)) {
                // If "All" is selected, fetch all user_ids from the users table
                $stmtAll = $pdo->query("SELECT user_id FROM users");
                $allUsers = $stmtAll->fetchAll();
                foreach ($allUsers as $u) {
                    $mr = $pdo->prepare("
                        INSERT INTO message_recipients (message_id, recipient_id)
                        VALUES (?, ?)
                    ");
                    $mr->execute([$message_id, $u['user_id']]);
                }
            } else {
                // Insert for each selected user
                foreach ($recipients as $r) {
                    $mr = $pdo->prepare("
                        INSERT INTO message_recipients (message_id, recipient_id)
                        VALUES (?, ?)
                    ");
                    $mr->execute([$message_id, $r]);
                }
            }

            // 6) Success
            $message = "Message sent successfully!";
            $messageType = 'green-text';

        } catch (Exception $e) {
            // 7) Database or other error
            $message = "Error sending message: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>hospital0 - Send a Message</title>
  <link rel="icon" href="/media/sitelogo.png" type="image/png">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  
  <!-- Materialize CSS -->
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
  <!-- Custom CSS -->
  <link rel="stylesheet" href="assets/css/style.css">

  <style>
    /* Force white text in all inputs and textareas */
    .input-field input[type="text"],
    .input-field input[type="date"],
    .input-field input[type="password"],
    .input-field input[type="number"],
    .materialize-textarea {
      color: #fff !important;
    }
    .input-field label {
      color: #fff !important;
    }
    .input-field input:focus + label,
    .materialize-textarea:focus:not([readonly]) + label {
      color: #90caf9 !important;
    }
  </style>
</head>
<body>
<?php include_once __DIR__ . '/includes/header.php'; ?>

<div class="container">
  <h4 class="center-align">Send a Message</h4>
  
  <!-- Display success/error message -->
  <?php if ($message): ?>
    <p class="<?php echo $messageType; ?> center-align">
      <?php echo htmlspecialchars($message); ?>
    </p>
  <?php endif; ?>

  <!-- Message Form -->
  <form method="POST" class="col s12">
    <div class="row">
      <!-- Recipients -->
      <div class="input-field col s12">
        <select name="recipients[]" multiple required>
          <option value="all">All Users</option>
          <?php
          // Show each user
          $stmtU = $pdo->query("SELECT user_id, username FROM users");
          while ($u = $stmtU->fetch()) {
              echo '<option value="'.$u['user_id'].'">'.htmlspecialchars($u['username']).'</option>';
          }
          ?>
        </select>
        <label>Select Recipients (multiple allowed)</label>
      </div>
    </div>
    <div class="row">
      <!-- Subject -->
      <div class="input-field col s12">
        <input id="subject" type="text" name="subject" required>
        <label for="subject" class="active">Subject</label>
      </div>
    </div>
    <div class="row">
      <!-- Message Body -->
      <div class="input-field col s12">
        <textarea id="content" name="content" class="materialize-textarea" required></textarea>
        <label for="content" class="active">Message Body</label>
      </div>
    </div>
    <div class="row center-align">
      <button type="submit" class="btn">Send Message</button>
    </div>
  </form>
</div>

<?php include_once __DIR__ . '/includes/footer.php'; ?>

<!-- Materialize JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Initialize the select
  var elems = document.querySelectorAll('select');
  M.FormSelect.init(elems);
  // Auto-resize textarea
  M.textareaAutoResize(document.getElementById('content'));
});
</script>
</body>
</html>

