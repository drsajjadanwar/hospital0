<?php
// /public/terminateuser.php

session_start();
require_once __DIR__ . '/includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['user'];

// Check if user is an Admin (group_id 1) or General Manager (group_id 23)
if (!in_array($user['group_id'], [1, 23])) {
    header("Location: dashboard.php");
    exit;
}

$feedback_message = '';
$feedback_type = '';

// If form is submitted to terminate a user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['terminate_user'])) {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    if ($targetUserId > 0) {
        try {
            // Set terminated=1. The column is correctly wrapped in backticks.
            $stmt = $pdo->prepare("UPDATE `users` SET `terminated` = 1 WHERE `user_id` = ?");
            $stmt->execute([$targetUserId]);
            $feedback_message = "User terminated successfully.";
            $feedback_type = 'success';
        } catch (Exception $e) {
            $feedback_message = "Error terminating user: " . $e->getMessage();
            $feedback_type = 'error';
        }
    } else {
         $feedback_message = "Invalid user ID provided.";
         $feedback_type = 'error';
    }
}

// --- Data Fetching for Display ---
$groupedUsers = [];
try {
    $stmtG = $pdo->query("SELECT `group_id`, `group_name` FROM `groups` ORDER BY `group_name` ASC");
    $groups = $stmtG->fetchAll(PDO::FETCH_ASSOC);

    foreach ($groups as $g) {
        // *** GEMINI UPDATE: Added "AND `terminated` = 0" to show only active users ***
        $stmtU = $pdo->prepare("
          SELECT `user_id`, `username`
          FROM `users`
          WHERE `group_id` = ? AND `terminated` = 0
          ORDER BY `username` ASC
        ");
        $stmtU->execute([$g['group_id']]);
        
        $groupedUsers[] = [
            'group_id'   => $g['group_id'],
            'group_name' => $g['group_name'],
            'users'      => $stmtU->fetchAll(PDO::FETCH_ASSOC)
        ];
    }
} catch (Exception $ex) {
    error_log("DB Error in terminateuser.php: " . $ex->getMessage());
    $feedback_message = "Error loading user data.";
    $feedback_type = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - Terminate User - hospital0</title>
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <link rel="icon" href="/media/sitelogo.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background-image: none !important; background-color: #121212 !important; color: #fff; overflow-x: hidden; }
        @keyframes move-twink-back { from { background-position: 0 0; } to { background-position: -10000px 5000px; } }
        .stars, .twinking { position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; display: block; z-index: -3; }
        .stars { background: #000 url(/media/stars.png) repeat top center; }
        .twinkling { background: transparent url(/media/twinkling.png) repeat top center; animation: move-twink-back 200s linear infinite; }
        #dna-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; opacity: 0.3; }
        h3.center-align, h5.white-text { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
        .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }
        .container { max-width: 1280px; width: 95%; }
        
        .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 1.5rem; margin-top: 1.5rem; }
        
        .message-area { padding: 10px 15px; margin-bottom: 20px; border-radius: 8px; text-align: center; border: 1px solid; }
        .message-area.success { background-color: rgba(76, 175, 80, 0.25); color: #c8e6c9; border-color: rgba(129, 199, 132, 0.5); }
        .message-area.error { background-color: rgba(244, 67, 54, 0.25); color: #ffcdd2; border-color: rgba(239, 154, 154, 0.5); }

        .collection { background-color: transparent; border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 10px; }
        .collection .collection-item { background-color: rgba(255, 255, 255, 0.05); color: #fff; border-bottom: 1px solid rgba(255, 255, 255, 0.2); display: flex; justify-content: space-between; align-items: center; }
        .collection .collection-item:last-child { border-bottom: none; }
        
        /* Modal Styling */
        .modal { background-color: #2a2a2a; color: #fff; border-radius: 15px; border: 1px solid rgba(255, 255, 255, 0.2); }
        .modal .modal-content h4 { font-weight: 300; color: #ff8a65; }
        .modal .modal-footer { background-color: transparent; }
    </style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<main class="container">
    <h3 class="center-align white-text" style="margin-top:30px;">Terminate a User</h3>
    <hr class="white-line">

    <?php if ($feedback_message): ?>
        <div class="message-area <?= $feedback_type === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($feedback_message); ?>
        </div>
    <?php endif; ?>

    <?php foreach ($groupedUsers as $group): ?>
        <?php if (!empty($group['users'])): // Only show the group card if there are active users in it ?>
            <div class="glass-card">
                <h5 class="white-text"><?= htmlspecialchars($group['group_name']); ?></h5>
                <ul class="collection">
                    <?php foreach ($group['users'] as $u): ?>
                        <li class="collection-item">
                            <div><i class="material-icons left">person</i><?= htmlspecialchars($u['username']); ?></div>
                            <div>
                                <button class="btn-small waves-effect waves-light red modal-trigger" 
                                        data-target="confirmModal" 
                                        data-userid="<?= $u['user_id']; ?>" 
                                        data-username="<?= htmlspecialchars($u['username']); ?>">
                                    <i class="material-icons">gavel</i>
                                </button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</main>
<br>

<!-- Modal Structure -->
<div id="confirmModal" class="modal">
    <div class="modal-content">
        <h4>Confirm Termination</h4>
        <p>Are you sure you want to terminate the user <strong id="modal-username"></strong>? This action cannot be undone.</p>
    </div>
    <div class="modal-footer">
        <form method="POST" action="terminateuser.php" style="display: inline;">
            <input type="hidden" name="user_id" id="modal-user-id" value="">
            <button type="submit" name="terminate_user" class="btn-flat waves-effect waves-light red-text text-lighten-1">Confirm Termination</button>
        </form>
        <a href="#!" class="modal-close waves-effect waves-green btn-flat">Cancel</a>
    </div>
</div>

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
  M.AutoInit();

  var elems = document.querySelectorAll('.modal');
    var instances = M.Modal.init(elems, {
        onOpenStart: function(modal, trigger) {
            // Get user data from the button that triggered the modal
            const userId = trigger.getAttribute('data-userid');
            const userName = trigger.getAttribute('data-username');
            
            // Populate the modal with the user's data
            modal.querySelector('#modal-username').textContent = userName;
            modal.querySelector('#modal-user-id').value = userId;
        }
    });
});
</script>
</body>
</html>