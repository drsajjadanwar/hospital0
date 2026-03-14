<?php
// /public/ledgerext.php - Modernized Pharmacy Ledger (Chart Height Corrected)

session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/config.php';
$pdo = $pdo ?? null;

// Authorization check for specific group IDs
$allowedGroups = [1, 7, 23]; // Admin, Pharmacist, GM
$userGroupId = (int)($_SESSION['user']['group_id'] ?? 0);
if (!in_array($userGroupId, $allowedGroups, true)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access Denied. You do not have permission to view this page.');
}

// --- Date Filtering Logic ---
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');

$entries = [];
$totalSales = 0;
$totalExpenses = 0;
$chartData = [];
$totalEntries = 0;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

try {
    // --- Get Total Count for Pagination ---
    $countStmt = $pdo->prepare("
        (SELECT COUNT(*) FROM pharmacyledger WHERE datetime BETWEEN :start_date_ledger AND :end_date_ledger)
        UNION ALL
        (SELECT COUNT(*) FROM pharmacy_expenses WHERE expense_date BETWEEN :start_date_expenses AND :end_date_expenses)
    ");
    $countStmt->execute([
        ':start_date_ledger' => $startDate . ' 00:00:00',
        ':end_date_ledger' => $endDate . ' 23:59:59',
        ':start_date_expenses' => $startDate . ' 00:00:00',
        ':end_date_expenses' => $endDate . ' 23:59:59',
    ]);
    $counts = $countStmt->fetchAll(PDO::FETCH_COLUMN);
    $totalEntries = array_sum($counts);
    
    // --- Fetch Paginated Data ---
    $stmt = $pdo->prepare("
        (SELECT datetime, description, amount, 'sale' as type FROM pharmacyledger WHERE datetime BETWEEN :start_date_ledger AND :end_date_ledger)
        UNION ALL
        (SELECT expense_date as datetime, description, -amount as amount, 'expense' as type FROM pharmacy_expenses WHERE expense_date BETWEEN :start_date_expenses AND :end_date_expenses)
        ORDER BY datetime DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':start_date_ledger', $startDate . ' 00:00:00');
    $stmt->bindValue(':end_date_ledger', $endDate . ' 23:59:59');
    $stmt->bindValue(':start_date_expenses', $startDate . ' 00:00:00');
    $stmt->bindValue(':end_date_expenses', $endDate . ' 23:59:59');
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Calculate Totals and Chart Data (for the entire selected period, not just the page) ---
    $summaryStmt = $pdo->prepare("
        SELECT 
            (SELECT SUM(amount) FROM pharmacyledger WHERE amount > 0 AND datetime BETWEEN :start_date_ledger AND :end_date_ledger) as total_sales,
            (SELECT SUM(amount) FROM pharmacy_expenses WHERE expense_date BETWEEN :start_date_expenses AND :end_date_expenses) as total_expenses
    ");
    $summaryStmt->execute([
        ':start_date_ledger' => $startDate . ' 00:00:00',
        ':end_date_ledger' => $endDate . ' 23:59:59',
        ':start_date_expenses' => $startDate . ' 00:00:00',
        ':end_date_expenses' => $endDate . ' 23:59:59',
    ]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    $totalSales = $summary['total_sales'] ?? 0;
    $totalExpenses = $summary['total_expenses'] ?? 0;

    // --- Data for Chart ---
    $chartQuery = $pdo->prepare("
        SELECT 
            DATE(datetime) as day,
            SUM(CASE WHEN type = 'sale' THEN amount ELSE 0 END) as daily_sales,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as daily_expenses
        FROM (
            (SELECT datetime, amount, 'sale' as type FROM pharmacyledger WHERE amount > 0 AND datetime BETWEEN :start_date_ledger AND :end_date_ledger)
            UNION ALL
            (SELECT expense_date as datetime, amount, 'expense' as type FROM pharmacy_expenses WHERE expense_date BETWEEN :start_date_expenses AND :end_date_expenses)
        ) as combined
        GROUP BY day
        ORDER BY day ASC
    ");
    $chartQuery->execute([
        ':start_date_ledger' => $startDate . ' 00:00:00',
        ':end_date_ledger' => $endDate . ' 23:59:59',
        ':start_date_expenses' => $startDate . ' 00:00:00',
        ':end_date_expenses' => $endDate . ' 23:59:59',
    ]);
    $chartDataRaw = $chartQuery->fetchAll(PDO::FETCH_ASSOC);

    $chartLabels = array_map(function($row) { return date('M j', strtotime($row['day'])); }, $chartDataRaw);
    $chartSales = array_column($chartDataRaw, 'daily_sales');
    $chartExpenses = array_column($chartDataRaw, 'daily_expenses');

} catch (Throwable $e) {
    die("Database error. Please check the logs or contact support.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>hospital0 - Pharmacy Ledger - hospital0</title>
    <link rel="icon" href="/media/sitelogo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .input-field input[type="date"] { color: #fff !important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; }
        .input-field label { color: #bdbdbd !important; }
        .input-field label.active { color: #00e5ff !important; }

        table.striped>tbody>tr:nth-child(odd) { background-color: rgba(255, 255, 255, 0.05); }
        th { border-bottom: 1px solid rgba(255, 255, 255, 0.3); } td, th { padding: 15px 10px; }
        
        .pagination { text-align: center; margin: 30px 0; }
        .pagination .page-number, .pagination .ellipsis { display:inline-block; padding: 8px 12px; margin: 0 4px; cursor: pointer; color: #fff; border-radius: 5px; transition: background-color 0.3s; }
        .pagination .page-number:hover { background-color: rgba(0, 229, 255, 0.2); }
        .ellipsis { cursor: default; }
        .active-page { font-weight: bold; background-color: #00bfa5; }

        /* --- !! CHART CONTAINER FIX !! --- */
        .chart-container {
            position: relative;
            height: 400px; /* Set a fixed height */
            width: 100%;
            margin-top: 2rem;
        }
    </style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<main class="container">
    <h3 class="center-align white-text" style="margin-top:30px;">Pharmacy Ledger</h3>
    <hr class="white-line">

    <div class="glass-card">
        <form method="get">
            <div class="row" style="align-items: flex-end;">
                <div class="input-field col s12 m4"><i class="material-icons prefix">date_range</i><input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($startDate) ?>"><label for="start_date">Start Date</label></div>
                <div class="input-field col s12 m4"><i class="material-icons prefix">date_range</i><input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDate) ?>"><label for="end_date">End Date</label></div>
                <div class="input-field col s12 m4"><button type="submit" class="btn waves-effect waves-light" style="width:100%; background-color:#00bfa5;"><i class="material-icons left">filter_alt</i>Filter</button></div>
            </div>
        </form>
    </div>

    <div class="row" style="margin-top: 2rem;">
        <div class="col s12 m4"><div class="stat-box"><h5>Total Sales</h5><p class="green-text text-lighten-2">PKR <?= number_format($totalSales, 2) ?></p></div></div>
        <div class="col s12 m4"><div class="stat-box"><h5>Total Expenses</h5><p class="red-text text-lighten-2">PKR <?= number_format($totalExpenses, 2) ?></p></div></div>
        <div class="col s12 m4"><div class="stat-box"><h5>Net Profit</h5><p class="<?= ($totalSales - $totalExpenses) >= 0 ? 'green-text' : 'red-text'; ?> text-lighten-2">PKR <?= number_format($totalSales - $totalExpenses, 2) ?></p></div></div>
    </div>
    
    <div class="glass-card">
        <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Daily Performance Chart</h5>
        <div class="chart-container">
            <canvas id="dailyChart"></canvas>
        </div>
    </div>

    <div class="glass-card">
        <h5 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Ledger Entries</h5>
        <table class="striped responsive-table">
            <thead>
                <tr><th>Date & Time</th><th>Description</th><th>Debit (Expense)</th><th>Credit (Sale)</th><th>Balance</th></tr>
            </thead>
            <tbody>
                <?php if (empty($entries)): ?>
                    <tr><td colspan="5" class="center-align">No entries found for the selected period.</td></tr>
                <?php else: ?>
                    <?php
                    $balanceQuery = $pdo->prepare("SELECT SUM(amount) FROM ((SELECT datetime, amount FROM pharmacyledger WHERE datetime < :start_date) UNION ALL (SELECT expense_date as datetime, -amount as amount FROM pharmacy_expenses WHERE expense_date < :start_date)) as history");
                    $balanceQuery->execute([':start_date' => $startDate . ' 00:00:00']);
                    $runningBalance = (float)$balanceQuery->fetchColumn();
                    
                    $allPeriodEntriesStmt = $pdo->prepare("(SELECT datetime, amount FROM pharmacyledger WHERE datetime BETWEEN :start_date_ledger AND :end_date_ledger) UNION ALL (SELECT expense_date as datetime, -amount as amount FROM pharmacy_expenses WHERE expense_date BETWEEN :start_date_expenses AND :end_date_expenses) ORDER BY datetime ASC");
                    $allPeriodEntriesStmt->execute([':start_date_ledger' => $startDate . ' 00:00:00', ':end_date_ledger' => $endDate . ' 23:59:59', ':start_date_expenses' => $startDate . ' 00:00:00', ':end_date_expenses' => $endDate . ' 23:59:59']);
                    $allPeriodEntries = $allPeriodEntriesStmt->fetchAll(PDO::FETCH_ASSOC);

                    $entriesToCalculate = array_slice($allPeriodEntries, 0, $offset);
                    foreach(array_reverse($entriesToCalculate) as $entry){ $runningBalance += (float)$entry['amount']; }

                    $entriesForDisplay = array_reverse($allPeriodEntries);
                    $runningBalance = (float)$pdo->query("SELECT SUM(amount) FROM ((SELECT amount FROM pharmacyledger) UNION ALL (SELECT -amount FROM pharmacy_expenses)) as history")->fetchColumn();
                    ?>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('d-M-Y H:i', strtotime($entry['datetime']))) ?></td>
                            <td><?= htmlspecialchars($entry['description']) ?></td>
                            <td class="red-text text-lighten-2"><?= $entry['amount'] < 0 ? 'PKR ' . number_format(abs($entry['amount']), 2) : '-' ?></td>
                            <td class="green-text text-lighten-2"><?= $entry['amount'] > 0 ? 'PKR ' . number_format($entry['amount'], 2) : '-' ?></td>
                            <td>PKR <?= number_format($runningBalance, 2) ?></td>
                        </tr>
                        <?php $runningBalance -= (float)$entry['amount']; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="pagination">
            <?php
                $totalPages = ceil($totalEntries / $perPage);
                if ($totalPages > 1) {
                    $queryParams = http_build_query(['start_date' => $startDate, 'end_date' => $endDate]);
                    if ($page > 1) echo '<a href="?page='.($page-1).'&'.$queryParams.'" class="page-number">«</a>';
                    if ($totalPages > 1) echo '<a href="?page=1&'.$queryParams.'" class="page-number '.($page==1?'active-page':'').'">1</a>';
                    if ($page > 3) echo '<span class="ellipsis">...</span>';
                    for ($i = max(2, $page - 1); $i <= min($totalPages - 1, $page + 1); $i++) {
                        echo '<a href="?page='.$i.'&'.$queryParams.'" class="page-number '.($i==$page?'active-page':'').'">'.$i.'</a>';
                    }
                    if ($page < $totalPages - 2) echo '<span class="ellipsis">...</span>';
                    if ($totalPages > 1) echo '<a href="?page='.$totalPages.'&'.$queryParams.'" class="page-number '.($page==$totalPages?'active-page':'').'">'.$totalPages.'</a>';
                    if ($page < $totalPages) echo '<a href="?page='.($page+1).'&'.$queryParams.'" class="page-number">»</a>';
                }
            ?>
        </div>
    </div>
</main>

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

    const ctx = document.getElementById('dailyChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                label: 'Sales (PKR)',
                data: <?= json_encode($chartSales) ?>,
                backgroundColor: 'rgba(0, 229, 255, 0.6)',
                borderColor: 'rgba(0, 229, 255, 1)',
                borderWidth: 1
            }, {
                label: 'Expenses (PKR)',
                data: <?= json_encode($chartExpenses) ?>,
                backgroundColor: 'rgba(255, 138, 101, 0.6)',
                borderColor: 'rgba(255, 138, 101, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, ticks: { color: '#bdbdbd' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } },
                x: { ticks: { color: '#bdbdbd' }, grid: { color: 'rgba(255, 255, 255, 0.1)' } }
            },
            plugins: {
                legend: { labels: { color: '#ffffff' } },
                tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', titleColor: '#ffffff', bodyColor: '#ffffff' }
            }
        }
    });
});
</script>
</body>
</html>