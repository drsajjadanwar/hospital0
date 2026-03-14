<?php
// /public/suspenduser.php

session_start();
require_once __DIR__ . '/includes/config.php';

// Check for admin/authorized user
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];
// Allow Admins, Ops, and General Managers
if (!in_array($user['group_id'], [1, 5, 23], true)) {
    // Redirect non-authorized users to the dashboard
    header("Location: dashboard.php");
    exit;
}

$feedback_message = '';
$feedback_type = '';

// --- Handle Form Submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    // ACTION: Suspend a user
    if (isset($_POST['suspend_hours'])) {
        $hours = (int)($_POST['suspend_hours'] ?? 0);

        if ($targetUserId < 1 || $hours < 1) {
            $feedback_message = "Invalid user or suspension duration provided.";
            $feedback_type = 'error';
        } else {
            try {
                // Set the timestamp for when the suspension ends
                $stmt = $pdo->prepare("UPDATE users SET suspended_until = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE user_id = ?");
                $stmt->execute([$hours, $targetUserId]);
                $feedback_message = "User has been suspended for $hours hour(s).";
                $feedback_type = 'success';
            } catch (Exception $e) {
                $feedback_message = "Error suspending user: " . $e->getMessage();
                $feedback_type = 'error';
            }
        }
    }
}

// --- Data Fetching for Display ---
$groupedUsers = [];
try {
    // 1. Get all groups
    $stmtG = $pdo->query("SELECT group_id, group_name FROM `groups` ORDER BY group_name ASC");
    $groups = $stmtG->fetchAll(PDO::FETCH_ASSOC);

    // 2. For each group, get its users
    foreach ($groups as $g) {
        // *** GEMINI FIX: Wrapped `terminated` in backticks to prevent SQL syntax error ***
        $stmtU = $pdo->prepare("
            SELECT user_id, username 
            FROM users 
            WHERE group_id = ? 
              AND `terminated` = 0 
              AND (suspended_until IS NULL OR suspended_until <= NOW())
            ORDER BY username ASC
        ");
        $stmtU->execute([$g['group_id']]);
        $usersInGroup = $stmtU->fetchAll(PDO::FETCH_ASSOC);

        $groupedUsers[] = [
            'group_id' => $g['group_id'],
            'group_name' => $g['group_name'],
            'users' => $usersInGroup
        ];
    }
} catch (Exception $ex) {
    // Log the detailed error for the admin but show a generic message to the user
    error_log("Database Error in suspenduser.php: " . $ex->getMessage());
    $feedback_message = "Error loading user data. Please contact an administrator.";
    $feedback_type = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - Suspend User - hospital0</title>
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
        .collection .collection-item { background-color: rgba(255, 255, 255, 0.05); color: #fff; border-bottom: 1px solid rgba(255, 255, 255, 0.2); }
        .collection .collection-item:last-child { border-bottom: none; }
        
        .input-field input, .input-field .select-dropdown { color: #fff !important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
        .input-field label { color: #bdbdbd !important; } .input-field label.active { color: #00e5ff !important; }
        .input-field input:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
        
        .user-info { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .user-actions { margin-top: 1rem; }
    </style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<main class="container">
    <h3 class="center-align white-text" style="margin-top:30px;">Suspend an Active User</h3>
    <hr class="white-line">
    
    <?php if ($feedback_message): ?>
        <div class="message-area <?= $feedback_type === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($feedback_message); ?>
        </div>
    <?php endif; ?>

    <?php foreach ($groupedUsers as $group): ?>
        <div class="glass-card">
            <h5 class="white-text"><?= htmlspecialchars($group['group_name']); ?></h5>
            <?php if (empty($group['users'])): ?>
                <p class="grey-text">No active users in this group.</p>
            <?php else: ?>
                <ul class="collection">
                    <?php foreach ($group['users'] as $u): ?>
                        <li class="collection-item">
                            <div class="user-info">
                                <strong><i class="material-icons left">person</i><?= htmlspecialchars($u['username']); ?></strong>
                            </div>
                            
                            <div class="user-actions">
                                <form method="POST" class="row" style="margin:0; align-items:flex-end;">
                                    <input type="hidden" name="user_id" value="<?= $u['user_id']; ?>">
                                    <div class="input-field col s6 m4">
                                        <input id="suspend_hours_<?= $u['user_id']; ?>" type="number" name="suspend_hours" min="1" required>
                                        <label for="suspend_hours_<?= $u['user_id']; ?>">Hours to suspend</label>
                                    </div>
                                    <div class="input-field col s6 m4">
                                        <button type="submit" name="suspend_hours" class="btn waves-effect waves-light red">
                                            <i class="material-icons left">block</i>Suspend
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</main>
<br>
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
});
</script>
</body>
</html>