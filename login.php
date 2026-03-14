<?php
// /public/login.php - 04 May 2025
// Feature: Check for password_reset_required flag and redirect if needed.
// Fix: Corrected styling for dark theme and added missing icon font link.
// UPDATE [by Gemini]: Added user suspension check before creating a session.
// UPDATE [by Gemini]: Added company logo to the login card.
// UPDATE [by Gemini]: Increased logo size to 90% of the card width.
// UPDATE [by Gemini]: Added user termination check to prevent login.

// Increase session lifetime to 30 days (in seconds)
ini_set('session.cookie_lifetime', 86400 * 30);    // 86400 seconds = 1 day
ini_set('session.gc_maxlifetime', 86400 * 30);

session_start();
require_once __DIR__ . '/includes/config.php';

// If the user is already logged in AND not forced to reset password, redirect to dashboard
if (isset($_SESSION['user']) && !empty($_SESSION['user']) && empty($_SESSION['force_password_reset'])) {
    header("Location: dashboard.php");
    exit;
}
// If they are logged in but forced to reset, redirect to the password reset page
elseif (isset($_SESSION['user']) && !empty($_SESSION['user']) && !empty($_SESSION['force_password_reset'])) {
    $current_page = basename($_SERVER['PHP_SELF']);
    if ($current_page != 'set_initial_password.php') {
        header("Location: set_initial_password.php");
        exit;
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        try {
            // *** GEMINI UPDATE: Added `terminated` to the SELECT query ***
            $stmt = $pdo->prepare("
                SELECT
                    user_id, username, password, full_name, email, group_id,
                    `terminated`,
                    password_reset_required,
                    suspended_until
                FROM users
                WHERE username = ?
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // *** GEMINI UPDATE: Added termination check as the HIGHEST priority ***
                if ((int)$user['terminated'] === 1) {
                    $error = "This account has been terminated and cannot be accessed.";
                }
                // Check if password reset is required
                elseif ((int)$user['password_reset_required'] === 1) {
                    $_SESSION['reset_user_id'] = $user['user_id'];
                    $_SESSION['reset_username'] = $user['username'];
                    $_SESSION['force_password_reset'] = true;
                    header("Location: set_initial_password.php");
                    exit;
                }
                // If not terminated or needing reset, verify the submitted password
                elseif (password_verify($password, $user['password'])) {
                    
                    // Check if the account is currently suspended
                    if ($user['suspended_until'] && strtotime($user['suspended_until']) > time()) {
                        $suspensionEndTime = date('d-M-Y h:i A', strtotime($user['suspended_until']));
                        $error = "Your account is suspended. Access will be restored on $suspensionEndTime.";
                    } else {
                        // All checks passed. Create the session.
                        unset($user['password'], $user['password_reset_required'], $user['suspended_until'], $user['terminated']);
                        $_SESSION['user'] = $user;
                        
                        unset($_SESSION['force_password_reset'], $_SESSION['reset_user_id'], $_SESSION['reset_username']);
                        
                        header("Location: dashboard.php");
                        exit;
                    }
                } else {
                    $error = "Invalid username or password.";
                }
            } else {
                $error = "Invalid username or password.";
            }
        } catch (PDOException $e) {
            error_log("Login database error: " . $e->getMessage());
            $error = "An error occurred during login. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/media/sitelogo.png" type="image/png">
  <title>hospital0 - Login - hospital0</title>
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
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
    .stars, .twinkling {
      position: fixed; top: 0; left: 0; right: 0; bottom: 0;
      width: 100%; height: 100%; display: block; z-index: -3;
    }
    .stars { background: #000 url(/media/stars.png) repeat top center; }
    .twinkling {
      background: transparent url(/media/twinkling.png) repeat top center;
      animation: move-twink-back 200s linear infinite;
    }
    #dna-canvas {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        z-index: -2; opacity: 0.3;
    }
    main {
      flex: 1 0 auto;
      display: flex;
      align-items: center;
      justify-content: center;
      padding-bottom: 30px;
    }
    .login-container {
       max-width: 450px;
       width: 90%;
    }
    .card-panel {
      background: rgba(255, 255, 255, 0.08);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 15px;
      padding: 30px 25px 20px 25px;
    }
    .login-logo {
        display: block;
        width: 90%;
        max-width: 350px;
        margin: 0 auto 20px auto;
    }
    h4.center-align {
        font-weight: 300;
        text-shadow: 0 0 8px rgba(0, 229, 255, 0.5);
    }
    .input-field input:not([type]),
    .input-field input[type=text]:not(.browser-default),
    .input-field input[type=password]:not(.browser-default) {
      border-bottom: 1px solid rgba(255, 255, 255, 0.5);
      box-shadow: none;
      color: #fff;
    }
    .input-field input:not([type]):focus:not([readonly]),
    .input-field input[type=text]:not(.browser-default):focus:not([readonly]),
    .input-field input[type=password]:not(.browser-default):focus:not([readonly]) {
      border-bottom: 1px solid #00e5ff;
      box-shadow: 0 1px 0 0 #00e5ff;
    }
    .input-field label { color: #bdbdbd; }
    .input-field label.active { color: #00e5ff; }
    .input-field .prefix { color: #bdbdbd; }
    .input-field .prefix.active { color: #00e5ff; }
    .message-area.error {
        background-color: rgba(244, 67, 54, 0.25);
        color: #ffcdd2;
        border: 1px solid rgba(239, 154, 154, 0.5);
        padding: 10px 15px;
        margin-bottom: 20px;
        border-radius: 4px;
        text-align: center;
        font-weight: 500;
        font-size: 0.9em;
    }
    .btn-custom {
        background-color: #00bfa5;
        width: 100%;
        margin-top: 10px;
    }
    .btn-custom:hover { background-color: #1de9b6; }
  </style>
</head>
<body>

<div class="stars"></div>
<div class="twinkling"></div>
<canvas id="dna-canvas"></canvas>

  <?php include_once __DIR__ . '/includes/header.php'; ?>

  <main>
    <div class="login-container">
       <div class="card-panel white-text">
        
        <img src="/media/login.png" alt="hospital0 Logo" class="login-logo">

        <h4 class="center-align" style="margin-bottom: 25px;">Login</h4>

        <?php if (!empty($error)): ?>
          <p class="message-area error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <form action="login.php" method="POST">
          <div class="row">
            <div class="input-field col s12">
              <i class="material-icons prefix">account_circle</i>
              <input id="username" type="text" name="username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
              <label for="username">Username</label>
            </div>
          </div>
          <div class="row">
            <div class="input-field col s12">
              <i class="material-icons prefix">lock</i>
              <input id="password" type="password" name="password" required>
              <label for="password">Password</label>
            </div>
          </div>
          <div class="row center-align">
            <button type="submit" class="btn waves-effect waves-light btn-custom">Login</button>
          </div>
        </form>
      </div>
    </div>
  </main>

  <?php include_once __DIR__ . '/includes/footer.php'; ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
  <script>
     document.addEventListener('DOMContentLoaded', function() {
         M.updateTextFields();
     });
  </script>

  <script type="module">
      import * as THREE from 'https://cdn.jsdelivr.net/npm/three@0.164.1/build/three.module.js';
      const scene = new THREE.Scene();
      const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
      const renderer = new THREE.WebGLRenderer({
          canvas: document.querySelector('#dna-canvas'),
          alpha: true
      });
      renderer.setPixelRatio(window.devicePixelRatio);
      renderer.setSize(window.innerWidth, window.innerHeight);
      camera.position.setZ(30);
      const dnaGroup = new THREE.Group();
      const radius = 5, tubeRadius = 0.5, radialSegments = 8, tubularSegments = 64, height = 40, turns = 4;
      class HelixCurve extends THREE.Curve {
          constructor(scale = 1, turns = 5, offset = 0) {
              super(); this.scale = scale; this.turns = turns; this.offset = offset;
          }
          getPoint(t) {
              const tx = Math.cos(this.turns * 2 * Math.PI * t + this.offset);
              const ty = t * height - height / 2;
              const tz = Math.sin(this.turns * 2 * Math.PI * t + this.offset);
              return new THREE.Vector3(tx, ty, tz).multiplyScalar(this.scale);
          }
      }
      const backboneMaterial = new THREE.MeshStandardMaterial({ color: 0x2196f3, metalness: 0.5, roughness: 0.2 });
      const path1 = new HelixCurve(radius, turns, 0);
      const path2 = new HelixCurve(radius, turns, Math.PI);
      dnaGroup.add(new THREE.Mesh(new THREE.TubeGeometry(path1, tubularSegments, tubeRadius, radialSegments, false), backboneMaterial));
      dnaGroup.add(new THREE.Mesh(new THREE.TubeGeometry(path2, tubularSegments, tubeRadius, radialSegments, false), backboneMaterial));
      const pairMaterial = new THREE.MeshStandardMaterial({ color: 0xffeb3b, metalness: 0.2, roughness: 0.5 });
      const steps = 50;
      for (let i = 0; i <= steps; i++) {
          const t = i / steps;
          const point1 = path1.getPoint(t);
          const point2 = path2.getPoint(t);
          const direction = new THREE.Vector3().subVectors(point2, point1);
          const rungGeometry = new THREE.CylinderGeometry(0.3, 0.3, direction.length(), 6);
          const rung = new THREE.Mesh(rungGeometry, pairMaterial);
          rung.position.copy(point1).add(direction.multiplyScalar(0.5));
          rung.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), direction.normalize());
          dnaGroup.add(rung);
      }
      scene.add(dnaGroup);
      scene.add(new THREE.AmbientLight(0xffffff, 0.5));
      const pointLight = new THREE.PointLight(0xffffff, 1);
      pointLight.position.set(5, 15, 15);
      scene.add(pointLight);
      function animate() {
          requestAnimationFrame(animate);
          dnaGroup.rotation.y += 0.005;
          dnaGroup.rotation.x += 0.001;
          renderer.render(scene, camera);
      }
      animate();
      window.addEventListener('resize', () => {
          camera.aspect = window.innerWidth / window.innerHeight;
          camera.updateProjectionMatrix();
          renderer.setSize(window.innerWidth, window.innerHeight);
      });
  </script>
</body>
</html>