<?php
// /public/edituser.php - Modernized for consistent look and feel.

session_start();
require_once __DIR__ . '/includes/config.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$loggedInUser = $_SESSION['user'];
if ($loggedInUser['group_id'] != 1) {
    header("Location: dashboard.php?error=auth");
    exit;
}

$message = '';
$messageType = '';
$errors = [];
$inputData = [];
$editUser = null;
$allUsers = [];

$user_id_to_edit = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $inputData = $_POST;
    $postedUserId = (int)($_POST['user_id'] ?? 0);
    $user_id_to_edit = $postedUserId;

    $username        = trim($_POST['username'] ?? '');
    $full_name       = trim($_POST['full_name'] ?? '');
    $rawPassword     = $_POST['password'] ?? '';
    $date_of_joining = !empty($_POST['date_of_joining']) ? $_POST['date_of_joining'] : null;
    $phone_number    = trim($_POST['phone_number'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $postal_address  = trim($_POST['postal_address'] ?? '');
    $hours_per_week  = trim($_POST['hours_per_week'] ?? '');
    $monthly_pay     = trim($_POST['monthly_pay'] ?? '');
    $group_id        = $_POST['group_id'] ?? '';
    $nid             = trim($_POST['nid'] ?? '');

    if ($postedUserId < 1) $errors['form'] = "Invalid user ID submitted.";
    if ($username === '') $errors['username'] = "Username is required.";
    if ($full_name === '') $errors['full_name'] = "Full Name is required.";
    if ($phone_number === '') $errors['phone_number'] = "Phone Number is required.";
    if ($email === '') $errors['email'] = "Email Address is required.";
    if ($postal_address === '') $errors['postal_address'] = "Postal Address is required.";
    if ($hours_per_week === '') $errors['hours_per_week'] = "Working Hours / Week is required.";
    if ($monthly_pay === '') $errors['monthly_pay'] = "Total Monthly Pay is required.";
    if ($group_id === '') $errors['group_id'] = "Group selection is required.";
    if ($nid === '') $errors['nid'] = "National Identity Number is required.";
    
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = "Invalid Email Address format.";
    if ($phone_number !== '' && !preg_match('/^\d{11}$/', $phone_number)) $errors['phone_number'] = "Phone must be 11 digits.";
    if ($nid !== '' && !preg_match('/^\d{13}$/', $nid)) $errors['nid'] = "NID must be 13 digits.";

    if (empty($errors)) {
        try {
            $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
            $stmtCheck->execute([$username, $postedUserId]);
            if ($stmtCheck->fetch()) $errors['username'] = "Username already exists for another user.";

            $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $stmtCheck->execute([$email, $postedUserId]);
            if ($stmtCheck->fetch()) $errors['email'] = "Email is already registered by another user.";

        } catch (PDOException $e) { $errors['database'] = "Error checking existing data."; }
    }

    if (empty($errors)) {
        $params = [$username, $full_name, $date_of_joining, $phone_number, $email, $postal_address, $hours_per_week, $monthly_pay, $group_id, $nid];
        $passwordSqlPart = '';
        if ($rawPassword !== '') {
            $passwordSqlPart = ", password = ?";
            $params[] = password_hash($rawPassword, PASSWORD_DEFAULT);
        }
        $params[] = $postedUserId;

        $sql = "UPDATE users SET username = ?, full_name = ?, date_of_joining = ?, phone_number = ?, email = ?, postal_address = ?, hours_per_week = ?, monthly_pay = ?, group_id = ?, national_identity_number = ? $passwordSqlPart WHERE user_id = ?";
        try {
            $pdo->prepare($sql)->execute($params);
            $message = "User '{$username}' updated successfully!";
            $messageType = 'success';
            $user_id_to_edit = 0;
        } catch (PDOException $e) {
            $message = "Database Error: Could not update user.";
            $messageType = 'error';
        }
    } else {
        $message = "Please correct the errors below.";
        $messageType = 'error';
    }
}

if ($user_id_to_edit > 0) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($inputData)) {
        try {
            $stmtE = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmtE->execute([$user_id_to_edit]);
            $editUser = $stmtE->fetch(PDO::FETCH_ASSOC);
            if (!$editUser) {
                $message = "Error: No user found with ID {$user_id_to_edit}.";
                $messageType = 'error';
                $user_id_to_edit = 0;
            } else {
                $inputData = $editUser;
            }
        } catch (PDOException $e) {
             $message = "Database error fetching user data.";
             $messageType = 'error';
             $user_id_to_edit = 0;
        }
    } else {
         $stmtE = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
         $stmtE->execute([$user_id_to_edit]);
         $editUser = $stmtE->fetch(PDO::FETCH_ASSOC);
    }
}

if ($user_id_to_edit === 0) {
    try {
        $stmtAll = $pdo->query("SELECT u.user_id, u.username, u.full_name, g.group_name FROM users u LEFT JOIN `groups` g ON u.group_id = g.group_id ORDER BY u.user_id ASC");
        $allUsers = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
         $message = "Database error fetching user list.";
         $messageType = 'error';
    }
}

$groupOptions = '';
if ($user_id_to_edit > 0) {
    try {
        $stmtG = $pdo->query("SELECT `group_id`, `group_name` FROM `groups` ORDER BY `group_name` ASC");
        $groups = $stmtG->fetchAll(PDO::FETCH_ASSOC);
        foreach ($groups as $g) {
            $gid = (int)$g['group_id'];
            $gname = htmlspecialchars($g['group_name']);
            $selected = (isset($inputData['group_id']) && $inputData['group_id'] == $gid) ? 'selected' : '';
            $groupOptions .= "<option value=\"$gid\" $selected>$gname</option>\n";
        }
    } catch (Exception $ex) { $groupOptions = '<option value="" disabled>Error fetching groups</option>'; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>hospital0 - Edit User - hospital0</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/media/sitelogo.png" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    body { background-image: none !important; background-color: #121212 !important; color: #fff; }
    @keyframes move-twink-back { from { background-position: 0 0; } to { background-position: -10000px 5000px; } }
    .stars, .twinkling { position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; display: block; z-index: -3; }
    .stars { background: #000 url(/media/stars.png) repeat top center; }
    .twinkling { background: transparent url(/media/twinkling.png) repeat top center; animation: move-twink-back 200s linear infinite; }
    #dna-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; opacity: 0.3; }
    h3, h4 { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
    .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }
    .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 2.5rem; margin-top: 1.5rem; }
    .input-field input, .materialize-textarea, .select-wrapper input.select-dropdown { color:#fff!important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
    .input-field label { color:#bdbdbd!important; }
    .input-field label.active { color:#00e5ff!important; }
    .input-field input:focus, .materialize-textarea:focus, .select-wrapper input.select-dropdown:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
    ul.dropdown-content { background-color: #2a2a2a; } .dropdown-content li>span { color: #fff !important; }
    input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); }
    .input-field .helper-text[data-error] { color: #ff8a80; font-weight: bold; }
    .input-field input.invalid { border-bottom: 1px solid #ff8a80 !important; box-shadow: 0 1px 0 0 #ff8a80 !important; }
    .message-area { padding: 15px; margin-bottom: 20px; border-radius: 8px; text-align: center; border: 1px solid; }
    .message-area.success { background-color: rgba(76, 175, 80, 0.25); color: #c8e6c9; border-color: rgba(129, 199, 132, 0.5); }
    .message-area.error { background-color: rgba(244, 67, 54, 0.25); color: #ffcdd2; border-color: rgba(239, 154, 154, 0.5); }
    table { color: #fff; } table.striped>tbody>tr:nth-child(odd) { background-color: rgba(255, 255, 255, 0.05); }
    th { border-bottom: 1px solid rgba(255,255,255,0.3); }
  </style>
</head>
<body>
  <canvas id="dna-canvas"></canvas>
  <div class="stars"></div>
  <div class="twinkling"></div>
  <?php include_once __DIR__ . '/includes/header.php'; ?>

  <main>
    <div class="container">

      <?php if ($user_id_to_edit === 0): ?>
        <h3 class="center-align white-text" style="margin-top: 30px;">Manage Users</h3>
        <hr class="white-line">
        
        <?php if (!empty($message)): ?>
            <div class="message-area <?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="glass-card">
            <?php if (!empty($allUsers)): ?>
                <table class="striped responsive-table highlight">
                  <thead><tr><th>ID</th><th>Username</th><th>Full Name</th><th>Group</th><th>Actions</th></tr></thead>
                  <tbody>
                  <?php foreach ($allUsers as $u): ?>
                    <tr>
                      <td><?php echo $u['user_id']; ?></td>
                      <td><?php echo htmlspecialchars($u['username']); ?></td>
                      <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                      <td><?php echo htmlspecialchars($u['group_name'] ?? 'N/A'); ?></td>
                      <td><a href="edituser.php?id=<?php echo $u['user_id']; ?>" class="btn-small waves-effect waves-light" style="background-color: #00bfa5;"><i class="material-icons tiny">edit</i></a></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
            <?php else: ?>
                <p class="center-align">No users found.</p>
            <?php endif; ?>
        </div>
        <div class="center-align" style="margin-top: 20px;">
             <a href="adduser.php" class="btn waves-effect waves-light"><i class="material-icons left">add</i>Add New User</a>
         </div>

      <?php else: ?>
        <h3 class="center-align white-text" style="margin-top: 0;">Edit User: <?php echo htmlspecialchars($inputData['username'] ?? ''); ?></h3>
        <hr class="white-line">
        <div class="row"><div class="col s12 m10 offset-m1 l8 offset-l2">
          <div class="glass-card">
              <?php if (!empty($message)): ?>
                <div class="message-area <?php echo htmlspecialchars($messageType); ?>">
                  <?php echo htmlspecialchars($message); ?>
                  <?php if (!empty($errors)): ?><ul style="text-align: left; margin-top: 10px;"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul><?php endif; ?>
                </div>
              <?php endif; ?>

              <?php if ($editUser): ?>
              <form action="edituser.php?id=<?php echo $user_id_to_edit; ?>" method="POST" class="col s12">
                <input type="hidden" name="user_id" value="<?php echo (int)$editUser['user_id']; ?>">
                <input type="hidden" name="update_user" value="1">
                <div class="row">
                  <div class="input-field col s12 m6"><i class="material-icons prefix">account_circle</i><input id="username" type="text" name="username" required value="<?php echo htmlspecialchars($inputData['username'] ?? ''); ?>" class="<?php echo isset($errors['username']) ? 'invalid' : ''; ?>"><label for="username">Username *</label><?php if(isset($errors['username'])): ?><span class="helper-text" data-error="<?php echo htmlspecialchars($errors['username']); ?>"></span><?php endif; ?></div>
                  <div class="input-field col s12 m6"><i class="material-icons prefix">person</i><input id="full_name" type="text" name="full_name" required value="<?php echo htmlspecialchars($inputData['full_name'] ?? ''); ?>" class="<?php echo isset($errors['full_name']) ? 'invalid' : ''; ?>"><label for="full_name">Full Name *</label><?php if(isset($errors['full_name'])): ?><span class="helper-text" data-error="<?php echo htmlspecialchars($errors['full_name']); ?>"></span><?php endif; ?></div>
                </div>
                <div class="row">
                  <div class="input-field col s12 m6"><i class="material-icons prefix">lock_open</i><input id="password" type="password" name="password" placeholder="(Leave blank to keep existing)"><label for="password" class="active">New Password (Optional)</label></div>
                  <div class="input-field col s12 m6"><i class="material-icons prefix">date_range</i><input id="date_of_joining" type="date" name="date_of_joining" value="<?php echo htmlspecialchars($inputData['date_of_joining'] ?? ''); ?>"><label for="date_of_joining" class="active">Date of Joining</label></div>
                </div>
                <div class="row">
                  <div class="input-field col s12 m6"><i class="material-icons prefix">phone</i><input id="phone_number" type="tel" name="phone_number" required value="<?php echo htmlspecialchars($inputData['phone_number'] ?? ''); ?>" class="<?php echo isset($errors['phone_number']) ? 'invalid' : ''; ?>"><label for="phone_number">Phone Number *</label><?php if(isset($errors['phone_number'])): ?><span class="helper-text" data-error="<?php echo htmlspecialchars($errors['phone_number']); ?>"></span><?php endif; ?></div>
                  <div class="input-field col s12 m6"><i class="material-icons prefix">email</i><input id="email" type="email" name="email" required value="<?php echo htmlspecialchars($inputData['email'] ?? ''); ?>" class="<?php echo isset($errors['email']) ? 'invalid' : ''; ?>"><label for="email">Email Address *</label><?php if(isset($errors['email'])): ?><span class="helper-text" data-error="<?php echo htmlspecialchars($errors['email']); ?>"></span><?php endif; ?></div>
                </div>
                <div class="row">
                  <div class="input-field col s12 m6"><i class="material-icons prefix">home</i><input id="postal_address" type="text" name="postal_address" required value="<?php echo htmlspecialchars($inputData['postal_address'] ?? ''); ?>" class="<?php echo isset($errors['postal_address']) ? 'invalid' : ''; ?>"><label for="postal_address">Postal Address *</label><?php if(isset($errors['postal_address'])): ?><span class="helper-text" data-error="<?php echo htmlspecialchars($errors['postal_address']); ?>"></span><?php endif; ?></div>
                  <div class="input-field col s12 m6"><i class="material-icons prefix">access_time</i><input id="hours_per_week" type="number" name="hours_per_week" required min="0" value="<?php echo htmlspecialchars($inputData['hours_per_week'] ?? ''); ?>" class="<?php echo isset($errors['hours_per_week']) ? 'invalid' : ''; ?>"><label for="hours_per_week">Working Hours / Week *</label><?php if(isset($errors['hours_per_week'])): ?><span class="helper-text" data-error="<?php echo htmlspecialchars($errors['hours_per_week']); ?>"></span><?php endif; ?></div>
                </div>
                <div class="row">
                  <div class="input-field col s12 m6"><i class="material-icons prefix">attach_money</i><input id="monthly_pay" type="number" step="0.01" name="monthly_pay" required min="0" value="<?php echo htmlspecialchars($inputData['monthly_pay'] ?? ''); ?>" class="<?php echo isset($errors['monthly_pay']) ? 'invalid' : ''; ?>"><label for="monthly_pay">Monthly Pay (PKR) *</label><?php if(isset($errors['monthly_pay'])): ?><span class="helper-text" data-error="<?php echo htmlspecialchars($errors['monthly_pay']); ?>"></span><?php endif; ?></div>
                  <div class="input-field col s12 m6"><i class="material-icons prefix">fingerprint</i><input id="nid" type="text" name="nid" placeholder="CNIC without hyphens" pattern="\d{13}" title="Must be 13 digits" required value="<?php echo htmlspecialchars($inputData['national_identity_number'] ?? ($inputData['nid'] ?? '')); ?>" class="<?php echo isset($errors['nid']) ? 'invalid' : ''; ?>"><label for="nid" class="active">National Identity No *</label><?php if(isset($errors['nid'])): ?><span class="helper-text" data-error="<?php echo htmlspecialchars($errors['nid']); ?>"></span><?php endif; ?></div>
                </div>
                <div class="row">
                  <div class="input-field col s12 m6"><i class="material-icons prefix">group</i><select name="group_id" required class="<?php echo isset($errors['group_id']) ? 'invalid' : ''; ?>"><option value="" disabled>Select User Group *</option><?php echo $groupOptions; ?></select><label>Group *</label><?php if(isset($errors['group_id'])): ?><span class="helper-text" data-error="<?php echo htmlspecialchars($errors['group_id']); ?>"></span><?php endif; ?></div>
                  <div class="col s12 m6 center-align" style="margin-top: 25px;"><button type="submit" name="update_user" class="btn waves-effect waves-light btn-large" style="background-color: #00bfa5;"><i class="material-icons left">save</i> Update</button><a href="edituser.php" class="btn grey waves-effect waves-light btn-large">Cancel</a></div>
                </div>
              </form>
              <?php else: ?>
                 <p class="center-align red-text">Could not load user data to edit.</p>
                 <div class="center-align" style="margin-top: 20px;"><a href="edituser.php" class="btn grey waves-effect waves-light">Back to List</a></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </main>

  <?php include_once __DIR__ . '/includes/footer.php'; ?>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
  <script type="module">
    import * as THREE from 'https://cdn.jsdelivr.net/npm/three@0.164.1/build/three.module.js';
    const scene = new THREE.Scene(); const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
    const renderer = new THREE.WebGLRenderer({ canvas: document.querySelector('#dna-canvas'), alpha: true });
    renderer.setPixelRatio(window.devicePixelRatio); renderer.setSize(window.innerWidth, window.innerHeight); camera.position.setZ(30);
    const dnaGroup = new THREE.Group(); const radius = 5, tubeRadius = 0.5, radialSegments = 8, tubularSegments = 64, height = 40, turns = 4;
    class HelixCurve extends THREE.Curve { constructor(scale = 1, turns = 5, offset = 0) { super(); this.scale = scale; this.turns = turns; this.offset = offset; } getPoint(t) { const tx = Math.cos(this.turns * 2 * Math.PI * t + this.offset); const ty = t * height - height / 2; const tz = Math.sin(this.turns * 2 * Math.PI * t + this.offset); return new THREE.Vector3(tx, ty, tz).multiplyScalar(this.scale); } }
    const backboneMaterial = new THREE.MeshStandardMaterial({ color: 0x2196f3, metalness: 0.5, roughness: 0.2 });
    const path1 = new HelixCurve(radius, turns, 0); const path2 = new HelixCurve(radius, turns, Math.PI);
    dnaGroup.add(new THREE.Mesh(new THREE.TubeGeometry(path1, tubularSegments, tubeRadius, radialSegments, false), backboneMaterial)); dnaGroup.add(new THREE.Mesh(new THREE.TubeGeometry(path2, tubularSegments, tubeRadius, radialSegments, false), backboneMaterial));
    const pairMaterial = new THREE.MeshStandardMaterial({ color: 0xffeb3b, metalness: 0.2, roughness: 0.5 }); const steps = 50;
    for (let i = 0; i <= steps; i++) { const t = i / steps; const p1 = path1.getPoint(t); const p2 = path2.getPoint(t); const dir = new THREE.Vector3().subVectors(p2, p1); const rungGeom = new THREE.CylinderGeometry(0.3, 0.3, dir.length(), 6); const rung = new THREE.Mesh(rungGeom, pairMaterial); rung.position.copy(p1).add(dir.multiplyScalar(0.5)); rung.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), dir.normalize()); dnaGroup.add(rung); }
    scene.add(dnaGroup); scene.add(new THREE.AmbientLight(0xffffff, 0.5)); const pLight = new THREE.PointLight(0xffffff, 1); pLight.position.set(5, 15, 15); scene.add(pLight);
    function animate() { requestAnimationFrame(animate); dnaGroup.rotation.y += 0.005; dnaGroup.rotation.x += 0.001; renderer.render(scene, camera); } animate();
    window.addEventListener('resize', () => { camera.aspect = window.innerWidth / window.innerHeight; camera.updateProjectionMatrix(); renderer.setSize(window.innerWidth, window.innerHeight); });
  </script>
  <script>
    document.addEventListener('DOMContentLoaded', function() { M.AutoInit(); });
  </script>
</body>
</html>
