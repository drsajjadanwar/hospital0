<?php
// /public/set_initial_password.php - 04 May 2025
// Page for users to set their password on first login.
// UPDATE [by Gemini]: Modernized UI to match the sophisticated dark theme.

session_start();
require_once __DIR__ . '/includes/config.php'; // Database connection ($pdo)

// Check if user is in the required state (logged in via reset flag)
if (!isset($_SESSION['force_password_reset']) || !$_SESSION['force_password_reset'] || !isset($_SESSION['reset_user_id'])) {
    // If not in reset state, redirect to login
    session_unset(); // Clear potentially inconsistent session
    session_destroy();
    header("Location: login.php?error=session_expired");
    exit;
}

$userId = $_SESSION['reset_user_id'];
$username = $_SESSION['reset_username'] ?? 'User'; // Get username for display

// --- Initialize variables ---
$message = '';
$messageType = 'error'; // Default to error
$errors = [];

// --- Handle Form Submission (PHP Logic Remains the Same) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // --- Validation ---
    if (empty($newPassword)) {
        $errors['new_password'] = "New Password is required.";
    }
    if (empty($confirmPassword)) {
        $errors['confirm_password'] = "Please confirm your password.";
    }
    if (!empty($newPassword) && !empty($confirmPassword) && $newPassword !== $confirmPassword) {
        $errors['confirm_password'] = "Passwords do not match.";
    }
    if (!empty($newPassword) && strlen($newPassword) < 8) { // Example: Minimum 8 characters
         $errors['new_password'] = "Password must be at least 8 characters long.";
    }

    // --- Process Update if No Errors ---
    if (empty($errors)) {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        try {
            $sqlUpdate = "UPDATE users SET password = ?, password_reset_required = 0 WHERE user_id = ?";
            $stmtUpdate = $pdo->prepare($sqlUpdate);
            $stmtUpdate->execute([$hashedPassword, $userId]);

            $stmtFetch = $pdo->prepare("SELECT user_id, username, full_name, email, group_id FROM users WHERE user_id = ?");
            $stmtFetch->execute([$userId]);
            $user = $stmtFetch->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                 unset($_SESSION['reset_user_id'], $_SESSION['reset_username'], $_SESSION['force_password_reset']);
                 $_SESSION['user'] = $user;
                 $_SESSION['flash_message'] = "Password successfully set. Welcome!";
                 $_SESSION['flash_message_type'] = 'success';
                 header("Location: dashboard.php");
                 exit;
            } else {
                 throw new Exception("Could not retrieve user data after password update.");
            }
        } catch (PDOException $e) {
            error_log("Password update database error (user_id: {$userId}): " . $e->getMessage());
            $message = "Database Error: Could not update password. Please try again.";
            $messageType = 'error';
        } catch (Exception $e) {
            error_log("Password update general error (user_id: {$userId}): " . $e->getMessage());
            $message = "An unexpected error occurred: " . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "Please correct the errors below.";
        $messageType = 'error';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - Set Your Password - hospital0</title>
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <link rel="icon" href="/media/sitelogo.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background-image: none !important;
            background-color: #121212 !important;
            display: flex;
            min-height: 100vh;
            flex-direction: column;
            overflow-x: hidden;
        }
        @keyframes move-twink-back { from { background-position: 0 0; } to { background-position: -10000px 5000px; } }
        .stars, .twinkling { position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; display: block; z-index: -3; }
        .stars { background: #000 url(/media/stars.png) repeat top center; }
        .twinkling { background: transparent url(/media/twinkling.png) repeat top center; animation: move-twink-back 200s linear infinite; }
        #dna-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; opacity: 0.3; }
        main { flex: 1 0 auto; display: flex; align-items: center; justify-content: center; padding: 40px 0; }
        .password-container { max-width: 500px; width: 90%; }
        
        .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 30px 25px 20px 25px; }
        
        h4 { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
        
        .input-field input, .input-field .select-dropdown { color: #fff !important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
        .input-field label { color: #bdbdbd !important; } .input-field label.active { color: #00e5ff !important; }
        .input-field input:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
        .input-field .prefix { color: #bdbdbd; } .input-field .prefix.active { color: #00e5ff; }

        .input-field .helper-text { margin-left: 45px; }
        
        .message-area { padding: 10px 15px; margin-bottom: 20px; border-radius: 8px; text-align: center; border: 1px solid; }
        .message-area.success { background-color: rgba(76, 175, 80, 0.25); color: #c8e6c9; border-color: rgba(129, 199, 132, 0.5); }
        .message-area.error { background-color: rgba(244, 67, 54, 0.25); color: #ffcdd2; border-color: rgba(239, 154, 154, 0.5); }
        
        .btn-custom { background-color: #00bfa5; width: 100%; margin-top: 10px; }
        .btn-custom:hover { background-color: #1de9b6; }
    </style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<main>
    <div class="password-container">
        <div class="glass-card">
            <h4 class="center-align" style="margin-bottom: 15px;">Set Your Password</h4>
            <p class="center-align grey-text text-lighten-1" style="margin-bottom: 25px;">Welcome, <?php echo htmlspecialchars($username); ?>! Please set a strong password for your account.</p>

            <?php if (!empty($message)): ?>
                <div class="message-area <?php echo htmlspecialchars($messageType); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form action="set_initial_password.php" method="POST">
                <div class="row">
                    <div class="input-field col s12">
                        <i class="material-icons prefix">lock_outline</i>
                        <input id="new_password" type="password" name="new_password" required class="<?php echo isset($errors['new_password']) ? 'invalid' : ''; ?>">
                        <label for="new_password">New Password *</label>
                        <?php if(isset($errors['new_password'])): ?>
                            <span class="helper-text" data-error="<?php echo htmlspecialchars($errors['new_password']); ?>"></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row">
                    <div class="input-field col s12">
                        <i class="material-icons prefix">lock</i>
                        <input id="confirm_password" type="password" name="confirm_password" required class="<?php echo isset($errors['confirm_password']) ? 'invalid' : ''; ?>">
                        <label for="confirm_password">Confirm New Password *</label>
                        <?php if(isset($errors['confirm_password'])): ?>
                            <span class="helper-text" data-error="<?php echo htmlspecialchars($errors['confirm_password']); ?>"></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row center-align" style="margin-top: 20px;">
                    <button type="submit" class="btn waves-effect waves-light btn-custom">Set Password & Login</button>
                </div>
            </form>
        </div>
        <p class="center-align grey-text text-lighten-1" style="margin-top: 15px;"><small>Need help? Contact support.</small></p>
    </div>
</main>

<?php include_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script type="module">
    // 3D background animation
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
    document.addEventListener('DOMContentLoaded', function() {
        M.updateTextFields();
    });
</script>
</body>
</html>