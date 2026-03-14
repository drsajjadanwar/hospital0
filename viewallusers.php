<?php
// /public/viewallusers.php

session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/config.php';

// Retrieve current user info from session
$user    = $_SESSION['user'] ?? [];
$userId  = $user['user_id'] ?? 0;
$grpId   = $user['group_id'] ?? 0;

$stmtTotalUsers = $pdo->query("SELECT COUNT(*) FROM `users`");
$totalUsers = $stmtTotalUsers->fetchColumn();

$stmtGroups = $pdo->query("SELECT COUNT(DISTINCT `group_id`) FROM `users`");
$totalGroups = $stmtGroups->fetchColumn();

$error_message    = '';
$currentEmployees = [];
$pastEmployees    = [];

try {
    // Current employees: terminated=0
    $sqlCurr = "
      SELECT u.`user_id`, u.`username`, g.`group_name`, u.`terminated`
      FROM `users` AS u
      LEFT JOIN `groups` AS g ON u.`group_id` = g.`group_id`
      WHERE u.`terminated` = 0
      ORDER BY u.`user_id` ASC
    ";
    $stmtCurr = $pdo->prepare($sqlCurr);
    $stmtCurr->execute();
    $currentEmployees = $stmtCurr->fetchAll(PDO::FETCH_ASSOC);

    // Past employees: terminated=1
    $sqlPast = "
      SELECT u.`user_id`, u.`username`, g.`group_name`, u.`terminated`
      FROM `users` AS u
      LEFT JOIN `groups` AS g ON u.`group_id` = g.`group_id`
      WHERE u.`terminated` = 1
      ORDER BY u.`user_id` ASC
    ";
    $stmtPast = $pdo->prepare($sqlPast);
    $stmtPast->execute();
    $pastEmployees = $stmtPast->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $ex) {
    $error_message = "DB Error: " . $ex->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - View All Users - hospital0</title>
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <link rel="icon" href="/media/sitelogo.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background-image: none !important; background-color: #121212 !important; color: #fff; overflow-x: hidden; }
        @keyframes move-twink-back { from { background-position: 0 0; } to { background-position: -10000px 5000px; } }
        .stars, .twinkling { position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; display: block; z-index: -3; }
        .stars { background: #000 url(/media/stars.png) repeat top center; }
        .twinkling { background: transparent url(/media/twinkling.png) repeat top center; animation: move-twink-back 200s linear infinite; }
        #dna-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; opacity: 0.3; }
        h3.center-align, h5.white-text { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
        .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }
        .container { max-width: 1600px; width: 95%; }
        
        .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 2rem; margin-top: 1.5rem; }
        
        .stat-box { background: rgba(0, 0, 0, 0.2); padding: 20px; border-radius: 10px; text-align: center; color: white; margin-bottom: 1rem; border: 1px solid rgba(255, 255, 255, 0.1); height: 100%; }
        .stat-box h5 { margin: 0 0 10px 0; font-size: 1.1rem; color: #bdbdbd; text-transform: uppercase; }
        .stat-box p { margin: 0; font-size: 2.2rem; font-weight: bold; }
        
        table.striped>tbody>tr:nth-child(odd) { background-color: rgba(255, 255, 255, 0.05); }
        th { border-bottom: 1px solid rgba(255, 255, 255, 0.3); } td, th { padding: 15px 10px; }

        /* Custom Tab Styles for Dark Theme */
        .tabs { background-color: transparent; }
        .tabs .tab a { color: rgba(255, 255, 255, 0.7); }
        .tabs .tab a:hover, .tabs .tab a.active { color: #00e5ff; }
        .tabs .indicator { background-color: #00e5ff; }
        #currentEmps, #pastEmps { padding-top: 20px; }
    </style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<main class="container">
    <h3 class="center-align white-text" style="margin-top:30px;">Employee Directory</h3>
    <hr class="white-line">

    <?php if ($error_message): ?>
        <div class="glass-card center-align red-text text-lighten-2"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    
    <?php if (!empty($message)): ?>
      <p class="red-text center-align">
        <?php echo htmlspecialchars($message); ?>
      </p>
    <?php endif; ?>

    <div class="row" style="margin-top: 2rem;">
        <div class="col s12 m6">
            <div class="stat-box">
                <h5>Total Users</h5>
                <p class="white-text"><?php echo (int)$totalUsers; ?></p>
            </div>
        </div>
        <div class="col s12 m6">
            <div class="stat-box">
                <h5>Total Groups</h5>
                <p class="white-text"><?php echo (int)$totalGroups; ?></p>
            </div>
        </div>
    </div>

    <div class="glass-card">
        <div class="row">
            <div class="col s12">
                <ul class="tabs">
                    <li class="tab col s6"><a class="active" href="#currentEmps">Current Employees</a></li>
                    <li class="tab col s6"><a href="#pastEmps">Past Employees</a></li>
                </ul>
            </div>
            
            <div id="currentEmps" class="col s12">
                <?php if (!empty($currentEmployees)): ?>
                    <table class="striped highlight responsive-table">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Username</th>
                                <th>Group Name</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($currentEmployees as $usrRow): ?>
                            <tr>
                                <td><?php echo (int)$usrRow['user_id']; ?></td>
                                <td><?php echo htmlspecialchars($usrRow['username']); ?></td>
                                <td><?php echo htmlspecialchars($usrRow['group_name'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="center-align grey-text">No current employees found.</p>
                <?php endif; ?>
            </div>

            <div id="pastEmps" class="col s12">
                <?php if (!empty($pastEmployees)): ?>
                    <table class="striped highlight responsive-table">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Username</th>
                                <th>Group Name</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pastEmployees as $usrRow): ?>
                            <tr>
                                <td><?php echo (int)$usrRow['user_id']; ?></td>
                                <td><?php echo htmlspecialchars($usrRow['username']); ?></td>
                                <td><?php echo htmlspecialchars($usrRow['group_name'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="center-align grey-text">No past employees found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
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