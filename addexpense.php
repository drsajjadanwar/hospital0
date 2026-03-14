<?php
// /public/addexpense.php – 05 July 2025
// Dark theme • sky-blue accent • NO “View Ledger” button
// Updated to disable submit button and show post-success options.

session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/config.php';

/* ──────────────────────────────────────────────
   Authorisation
   ────────────────────────────────────────────── */
$allowedGroups = [1, 2, 3, 4, 5, 6, 8, 10, 23]; // Admin, Finance, etc.
if (!in_array((int) ($_SESSION['user']['group_id'] ?? 0), $allowedGroups, true)) {
    // We will show an access denied message within the styled page.
}

$username        = $_SESSION['user']['username'] ?? 'unknown';
$error_message   = '';
$success_message = '';

/* ──────────────────────────────────────────────
   Handle submission
   ────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((int) ($_SESSION['user']['group_id'] ?? 0), $allowedGroups, true)) {
    $amountPKR   = trim($_POST['amount_pkr'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($amountPKR === '' || $description === '') {
        $error_message = 'Please fill in both description and amount.';
    } elseif (!is_numeric($amountPKR) || (float) $amountPKR <= 0) {
        $error_message = 'Amount must be a positive number.';
    } else {
        try {
            $debitAmount = -abs((float) $amountPKR); // store as negative
            $pdo->prepare("
                INSERT INTO generalledger (datetime, description, amount, user)
                VALUES (NOW(), ?, ?, ?)
            ")->execute([$description, $debitAmount, $username]);
            $success_message = 'Expense has been added to the ledger.';
        } catch (Exception $ex) {
            $error_message = 'Error adding expense: ' . $ex->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>hospital0 - Add Expense – hospital0</title>
  <link rel="icon" href="/media/sitelogo.png" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">

  <style>
    /* --- NEW BEAUTIFICATION STYLES --- */
    body {
        background-image: none !important;
        background-color: #121212 !important;
        display: flex;
        min-height: 100vh;
        flex-direction: column;
        overflow-x: hidden;
    }

    /* --- Animated Starfield & DNA Background --- */
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

    main {flex:1 0 auto;display:flex;align-items:center;justify-content:center;padding-bottom:30px;}
    .expense-container {max-width:480px;width:90%;}
    
    /* --- Glassmorphism UI --- */
    .card-panel {
        background: rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: 15px;
        padding: 30px 25px 20px;
    }
    
    h4.center-align {
        font-weight: 300;
        text-shadow: 0 0 8px rgba(0, 229, 255, 0.5);
    }

    .input-field input:not([type]),
    .input-field input[type=text]:not(.browser-default),
    .input-field input[type=number]:not(.browser-default){
        border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important;
        box-shadow: none !important;
        color: #fff !important;
    }
    .input-field input:focus:not([readonly]){
        border-bottom: 1px solid #00e5ff !important;
        box-shadow: 0 1px 0 0 #00e5ff !important;
    }
    .input-field label{color:#bdbdbd;}
    .input-field label.active{color:#00e5ff;}
    .input-field .prefix{color:#bdbdbd;}
    .input-field .prefix.active{color:#00e5ff;}

    /* Modern Messages */
    .msg {
        padding: 10px 15px; margin-bottom: 20px; border-radius: 8px; text-align: center;
        font-weight: 500; font-size: .9em; border: 1px solid;
    }
    .msg-error { background-color: rgba(244, 67, 54, 0.25); color: #ffcdd2; border-color: rgba(239, 154, 154, 0.5); }
    .msg-success { background-color: rgba(76, 175, 80, 0.25); color: #c8e6c9; border-color: rgba(129, 199, 132, 0.5); }

    /* Buttons */
    .btn-custom { background-color: #00bfa5; width:100%; }
    .btn-custom:hover { background-color: #1de9b6; }
    .btn.disabled, .btn:disabled { background-color: #BDBDBD !important; }
    #post-submission-options .btn { margin-top: 10px; }
  </style>
</head>
<body>

<div class="stars"></div>
<div class="twinkling"></div>
<canvas id="dna-canvas"></canvas>

  <?php include_once __DIR__ . '/includes/header.php'; ?>

  <main>
   <div class="expense-container">
     <div class="card-panel white-text">
        <h4 class="center-align" style="margin-bottom:25px;">Add Expense</h4>

        <?php if (!in_array((int) ($_SESSION['user']['group_id'] ?? 0), $allowedGroups, true)): ?>
            <p class="msg msg-error">You do not have permission to perform this action.</p>
            <div class="center-align">
                <a href="dashboard.php" class="btn waves-effect waves-light grey darken-1">
                    <i class="material-icons left">home</i>Go Home
                </a>
            </div>
        <?php elseif (!empty($success_message)): ?>
            <p class="msg msg-success"><?= htmlspecialchars($success_message) ?></p>
            <div class="row" id="post-submission-options">
              <div class="col s12 m6">
                <a href="addexpense.php" class="btn waves-effect waves-light btn-custom">
                   <i class="material-icons left">add</i>Add Another
                </a>
              </div>
              <div class="col s12 m6">
                <a href="dashboard.php" class="btn waves-effect waves-light grey darken-1" style="width:100%;">
                   <i class="material-icons left">home</i>Go Home
                </a>
              </div>
            </div>
        <?php else: ?>
            <?php if (!empty($error_message)): ?>
                <p class="msg msg-error"><?= htmlspecialchars($error_message) ?></p>
            <?php endif; ?>

            <form action="addexpense.php" method="POST" onsubmit="disableOnSubmit(this)">
              <div class="row">
                <div class="input-field col s12">
                  <i class="material-icons prefix">description</i>
                  <input id="description" name="description" type="text" required
                         value="<?= htmlspecialchars($_POST['description'] ?? '') ?>">
                  <label for="description">Expense Description</label>
                </div>
              </div>
              <div class="row">
                <div class="input-field col s12">
                  <i class="material-icons prefix">attach_money</i>
                  <input id="amount_pkr" name="amount_pkr" type="number" step="0.01" min="0"
                         placeholder="PKR" required
                         value="<?= htmlspecialchars($_POST['amount_pkr'] ?? '') ?>">
                  <label for="amount_pkr">Amount (PKR)</label>
                </div>
              </div>
              <div class="row center-align">
                <button id="submit-btn" type="submit" class="btn waves-effect waves-light btn-custom" style="margin-top:10px;">
                   <i class="material-icons left">save</i>Save Expense
                </button>
              </div>
            </form>
        <?php endif; ?>
     </div>
   </div>
  </main>

  <?php include_once __DIR__ . '/includes/footer.php'; ?>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', () => { M.updateTextFields(); });
    function disableOnSubmit(form) {
        const submitButton = form.querySelector('#submit-btn');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="material-icons left">hourglass_empty</i> Saving...';
            submitButton.classList.add('disabled');
        }
    }
  </script>

  <script type="importmap">{"imports": {"three": "https://unpkg.com/three@0.164.1/build/three.module.js"}}</script>
  <script type="module">
      import * as THREE from 'three';
      const scene = new THREE.Scene();
      const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
      const renderer = new THREE.WebGLRenderer({ canvas: document.querySelector('#dna-canvas'), alpha: true });
      renderer.setPixelRatio(window.devicePixelRatio);
      renderer.setSize(window.innerWidth, window.innerHeight);
      camera.position.setZ(30);
      const dnaGroup = new THREE.Group();
      const radius = 5, tubeRadius = 0.5, radialSegments = 8, tubularSegments = 64, height = 40, turns = 4;
      class HelixCurve extends THREE.Curve {
          constructor(scale = 1, turns = 5, offset = 0) { super(); this.scale = scale; this.turns = turns; this.offset = offset; }
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
          const p1 = path1.getPoint(t);
          const p2 = path2.getPoint(t);
          const dir = new THREE.Vector3().subVectors(p2, p1);
          const rungGeom = new THREE.CylinderGeometry(0.3, 0.3, dir.length(), 6);
          const rung = new THREE.Mesh(rungGeom, pairMaterial);
          rung.position.copy(p1).add(dir.multiplyScalar(0.5));
          rung.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), dir.normalize());
          dnaGroup.add(rung);
      }
      scene.add(dnaGroup);
      scene.add(new THREE.AmbientLight(0xffffff, 0.5));
      const pLight = new THREE.PointLight(0xffffff, 1);
      pLight.position.set(5, 15, 15);
      scene.add(pLight);
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