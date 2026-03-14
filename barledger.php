<?php
// /public/barledger.php - Modernized Bar Ledger Dashboard (Corrected)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/config.php';
$pdo = $pdo ?? null;

// Authorization check
$allowedGroups = [1, 5, 8, 23];
if (!in_array((int)($_SESSION['user']['group_id'] ?? 0), $allowedGroups, true)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access Denied. You do not have permission to view this page.');
}

// --- Fetch active employees for the dropdown filter ---
try {
    // Corrected SQL query with backticks around column names
    $employees = $pdo->query("SELECT `user_id`, `full_name` FROM `users` WHERE `terminated` = 0 AND `suspended` = 0 ORDER BY `full_name` ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error fetching employees: " . $e->getMessage());
}

// --- Filter Logic ---
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;

// --- Data Fetching ---
$whereParts = ["datetime BETWEEN :start_date AND :end_date"];
$params = [':start_date' => $startDate . ' 00:00:00', ':end_date' => $endDate . ' 23:59:59'];

if ($employeeId > 0) {
    $whereParts[] = "employee_user_id = :employee_id";
    $params[':employee_id'] = $employeeId;
}
$whereSQL = 'WHERE ' . implode(' AND ', $whereParts);

// --- Initialize variables to avoid errors ---
$stats = ['total_purchases' => 0, 'total_credit_owed' => 0, 'total_paid_cash' => 0];
$entries = [];
$totalEntries = 0;
$totalPages = 1;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;

try {
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_purchases,
            SUM(CASE WHEN payment_method = 'Credit' THEN total_amount ELSE 0 END) as total_credit_owed,
            SUM(CASE WHEN payment_method = 'Cash' THEN total_amount ELSE 0 END) as total_paid_cash
        FROM barledger
        $whereSQL
    ");
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM barledger $whereSQL");
    $countStmt->execute($params);
    $totalEntries = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalEntries / $perPage);
    $page = min($page, $totalPages > 0 ? $totalPages : 1);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("SELECT * FROM barledger $whereSQL ORDER BY datetime DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $key => &$val) { $stmt->bindParam($key, $val); }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'no such table: barledger') !== false || strpos($e->getMessage(), "doesn't exist") !== false) {
        die("<div style='font-family:sans-serif;padding:2rem;color:red;'><b>Database Error:</b> The `barledger` table does not exist. Please run the SQL command provided to create it.</div>");
    }
    die("Database query error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - Bar Ledger - hospital0</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/media/sitelogo.png">
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
        h3.center-align { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
        .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }
        .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 2rem; margin-top: 1.5rem; }
        .stat-box { background: rgba(0, 0, 0, 0.2); padding: 20px; border-radius: 10px; text-align: center; color: white; margin-bottom: 1rem; border: 1px solid rgba(255, 255, 255, 0.1); }
        .stat-box h5 { margin: 0 0 10px 0; font-size: 1.1rem; color: #bdbdbd; text-transform: uppercase; }
        .stat-box p { margin: 0; font-size: 2.2rem; font-weight: bold; }
        p.green-text { color: #81c784 !important; } p.red-text { color: #e57373 !important; }
        .input-field input, .input-field .select-dropdown { color: #fff !important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; }
        .input-field label { color: #bdbdbd !important; } .input-field label.active { color: #00e5ff !important; }
        .input-field input:focus, .input-field .select-wrapper input.select-dropdown:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
        ul.dropdown-content { background-color: #2a2a2a; } .dropdown-content li>span { color: #fff !important; }
        table.striped>tbody>tr:nth-child(odd) { background-color: rgba(255, 255, 255, 0.05); }
        th { border-bottom: 1px solid rgba(255, 255, 255, 0.3); } td, th { padding: 15px 10px; }
        .pagination { text-align: center; margin: 30px 0; }
        .pagination a, .pagination .ellipsis { display:inline-block; padding: 8px 12px; margin: 0 4px; border-radius: 5px; }
        .pagination a { color: #fff; cursor: pointer; transition: background-color 0.3s; }
        .pagination a:hover { background-color: rgba(0, 229, 255, 0.2); }
        .ellipsis { cursor: default; } .active-page { font-weight: bold; background-color: #00bfa5; }
    </style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<main class="container">
    <h3 class="center-align white-text" style="margin-top:30px;">Bar Ledger</h3>
    <hr class="white-line">

    <div class="glass-card">
        <form method="get">
            <div class="row" style="align-items: flex-end;">
                <div class="input-field col s12 m6 l3"><i class="material-icons prefix">date_range</i><input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($startDate) ?>"><label for="start_date">Start Date</label></div>
                <div class="input-field col s12 m6 l3"><i class="material-icons prefix">date_range</i><input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDate) ?>"><label for="end_date">End Date</label></div>
                <div class="input-field col s12 m6 l4"><i class="material-icons prefix">person</i><select id="employee_id" name="employee_id"><option value="0" <?= $employeeId == 0 ? 'selected' : '' ?>>All Customers</option><?php foreach($employees as $emp): ?><option value="<?= $emp['user_id'] ?>" <?= $employeeId == $emp['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['full_name']) ?></option><?php endforeach; ?></select><label>Filter by Employee</label></div>
                <div class="input-field col s12 m6 l2"><button type="submit" class="btn waves-effect waves-light" style="width:100%; background-color:#00bfa5;"><i class="material-icons">filter_alt</i></button></div>
            </div>
        </form>
    </div>

    <div class="row" style="margin-top: 2rem;">
        <div class="col s12 m4"><div class="stat-box"><h5>Total Purchases</h5><p><?= (int)($stats['total_purchases'] ?? 0) ?></p></div></div>
        <div class="col s12 m4"><div class="stat-box"><h5>Total Paid (Cash)</h5><p class="green-text text-lighten-2">PKR <?= number_format($stats['total_paid_cash'] ?? 0, 2) ?></p></div></div>
        <div class="col s12 m4"><div class="stat-box"><h5>Total Owed (Credit)</h5><p class="red-text text-lighten-2">PKR <?= number_format($stats['total_credit_owed'] ?? 0, 2) ?></p></div></div>
    </div>

    <div class="glass-card">
        <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Ledger Entries</h5>
        <table class="striped responsive-table">
            <thead><tr><th>Date & Time</th><th>Invoice ID</th><th>Customer</th><th>Payment Method</th><th class="right-align">Total</th><th>Operator</th></tr></thead>
            <tbody>
                <?php if (empty($entries)): ?>
                    <tr><td colspan="6" class="center-align">No entries found for the selected period.</td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('d-M-Y H:i', strtotime($entry['datetime']))) ?></td>
                            <td><a href="bardata/<?= htmlspecialchars($entry['invoice_id']) ?>.pdf" target="_blank"><?= htmlspecialchars($entry['invoice_id']) ?></a></td>
                            <td><?= htmlspecialchars($entry['customer_name'] ?: 'Patient/Walk-in') ?></td>
                            <td><span class="new badge <?= $entry['payment_method'] === 'Credit' ? 'red' : 'green' ?>" data-badge-caption=""><?= htmlspecialchars($entry['payment_method']) ?></span></td>
                            <td class="right-align">PKR <?= number_format($entry['total_amount'], 2) ?></td>
                            <td><?= htmlspecialchars($entry['created_by_user']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="pagination">
            <?php
            if ($totalPages > 1) {
                $queryParams = http_build_query(['start_date' => $startDate, 'end_date' => $endDate, 'employee_id' => $employeeId]);
                if ($page > 1) echo '<a href="?page='.($page-1).'&'.$queryParams.'">«</a>';
                if ($page > 3) echo '<a href="?page=1&'.$queryParams.'">1</a><span class="ellipsis">...</span>';
                for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
                    echo '<a href="?page='.$i.'&'.$queryParams.'" class="'.($i == $page ? 'active-page' : '').'">'.$i.'</a>';
                }
                if ($page < $totalPages - 2) echo '<span class="ellipsis">...</span><a href="?page='.$totalPages.'&'.$queryParams.'">'.$totalPages.'</a>';
                if ($page < $totalPages) echo '<a href="?page='.($page+1).'&'.$queryParams.'">»</a>';
            }
            ?>
        </div>
    </div>
</main>
<br>
<?php include_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script type="module">
    import * as THREE from 'https://unpkg.com/three@0.164.1/build/three.module.js';
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