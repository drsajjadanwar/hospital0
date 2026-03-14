<?php
// /public/patientregister.php - Modernized with AJAX
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/config.php';

// Retrieve user info from session
$user = $_SESSION['user'] ?? [];
$userId = isset($user['user_id']) ? (int)$user['user_id'] : 0;

// Refresh user details from DB
try {
    $st = $pdo->prepare("SELECT group_id, full_name FROM users WHERE user_id=? LIMIT 1");
    $st->execute([$userId]);
    $rw = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rw) {
        session_destroy();
        header("Location: login.php");
        exit;
    }
    $user['group_id']  = (int)$rw['group_id'];
    $_SESSION['user']  = $user;
} catch(Exception $ex){
    die("A database error occurred. Please try again later.");
}

// Allowed groups
$allowedGroups = [1,2,3,4,5,6,8,10,20,22,23];
$no_rights = (!in_array($user['group_id'], $allowedGroups));

// --- Data Fetching & Pagination ---
$perPage = 10;
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($currentPage - 1) * $perPage;

$searchTerm = trim($_GET['search'] ?? '');
$allVisits = [];
$totalVisits = 0;
$totalPages = 1; // Default

if (!$no_rights) {
    try {
        $params = [];
        $searchSQL = "";

        if (!empty($searchTerm)) {
            $searchSQL = " WHERE (p.full_name LIKE :search OR p.mrn LIKE :search OR v.department LIKE :search)";
            $params[':search'] = "%$searchTerm%";
        }

        // Get total count for pagination
        $countStmt = $pdo->prepare("SELECT COUNT(v.visit_id) FROM visits v JOIN patients p ON v.patient_id = p.patient_id $searchSQL");
        $countStmt->execute($params);
        $totalVisits = (int)$countStmt->fetchColumn();
        $totalPages = ceil($totalVisits / $perPage);
        $currentPage = min($currentPage, $totalPages > 0 ? $totalPages : 1);
        $offset = ($currentPage - 1) * $perPage; // Recalculate offset

        // Fetch paginated visits
        $stmt = $pdo->prepare("
            SELECT v.visit_id, v.visit_number, v.department, v.time_of_presentation,
                   p.mrn, p.full_name, p.gender, p.phone, p.address, v.age_value, v.age_unit
            FROM visits v
            JOIN patients p ON v.patient_id = p.patient_id
            $searchSQL
            ORDER BY v.time_of_presentation DESC
            LIMIT :limit OFFSET :offset
        ");
        
        foreach ($params as $key => &$val) {
            $stmt->bindParam($key, $val);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $allVisits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $ex) {
        // Don't die on AJAX request, return error
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json', true, 500);
            echo json_encode(['error' => 'Database query error: ' . $ex->getMessage()]);
            exit;
        }
        die("Database query error: " . $ex->getMessage());
    }
}

// --- AJAX Rendering Functions ---

/**
 * Renders the HTML for the visit list.
 * @param array $allVisits
 * @return string HTML content
 */
function renderVisitListComponent($allVisits) {
    ob_start(); // Start output buffering
    if (empty($allVisits)) {
        echo '<h5 class="center-align grey-text" style="margin:40px;">No patient visits found.</h5>';
    } else {
        foreach ($allVisits as $visit) { 
            $ageVal = ($visit['age_value'] == null) ? 'N/A' : htmlspecialchars($visit['age_value']);
            $ageUnit = htmlspecialchars($visit['age_unit'] ?? '');
            $ageStr = ($ageVal !== 'N/A') ? "$ageVal $ageUnit" : "N/A";
        ?>
            <div class="visit-card">
                <h5><?= htmlspecialchars($visit['full_name']) ?> (MRN: <?= htmlspecialchars($visit['mrn']) ?>)</h5>
                <p><strong>Visit #:</strong> <?= htmlspecialchars($visit['visit_number']) ?></p>
                <p><strong>Department:</strong> <?= htmlspecialchars($visit['department']) ?></p>
                <p><strong>Date & Time:</strong> <?= htmlspecialchars(date('D, j M Y, h:i A', strtotime($visit['time_of_presentation']))) ?></p>
                <p><strong>Age at Visit:</strong> <?= $ageStr ?></p>
                <p><strong>Gender:</strong> <?= htmlspecialchars($visit['gender']) ?></p>
                <p><strong>Phone:</strong> <?= htmlspecialchars($visit['phone']) ?></p>
                <p><strong>Address:</strong> <?= htmlspecialchars($visit['address']) ?></p>
                <div style="margin-top: 15px;">
                    <a href="patientdata.php?mrn=<?= htmlspecialchars($visit['mrn']) ?>" class="btn waves-effect waves-light green"><i class="material-icons left">account_box</i>View Patient Data</a>
                </div>
            </div>
        <?php
        }
    }
    return ob_get_clean(); // Return buffered content
}

/**
 * Renders the HTML for the pagination.
 * @param int $totalPages
 * @param int $currentPage
 * @param string $searchTerm
 * @return string HTML content
 */
function renderPaginationComponent($totalPages, $currentPage, $searchTerm) {
    ob_start(); // Start output buffering
    if ($totalPages > 1) {
        $queryParams = http_build_query(['search' => $searchTerm]);
        if ($currentPage > 1) echo '<a href="?page='.($currentPage-1).'&'.$queryParams.'">«</a>';
        if ($currentPage > 3) echo '<a href="?page=1&'.$queryParams.'">1</a><span class="ellipsis">...</span>';
        for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
            echo '<a href="?page='.$i.'&'.$queryParams.'" class="'.($i == $currentPage ? 'active-page' : '').'">'.$i.'</a>';
        }
        if ($currentPage < $totalPages - 2) echo '<span class="ellipsis">...</span><a href="?page='.$totalPages.'&'.$queryParams.'">'.$totalPages.'</a>';
        if ($currentPage < $totalPages) echo '<a href="?page='.($currentPage+1).'&'.$queryParams.'">»</a>';
    }
    return ob_get_clean(); // Return buffered content
}

// --- AJAX Request Handler ---
// If 'ajax' param is set, only render components and exit.
if (isset($_GET['ajax'])) {
    if ($no_rights) {
        header('Content-Type: application/json', true, 403);
        echo json_encode(['visitsHtml' => '<h5 class="red-text">Access Denied</h5>', 'paginationHtml' => '']);
        exit;
    }
    
    $visitsHtml = renderVisitListComponent($allVisits);
    $paginationHtml = renderPaginationComponent($totalPages, $currentPage, $searchTerm);
    
    header('Content-Type: application/json');
    echo json_encode(['visitsHtml' => $visitsHtml, 'paginationHtml' => $paginationHtml]);
    exit;
}

// --- Full Page Render (if not AJAX) ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - Patient Visit Register</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        h3.center-align { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
        .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }
        
        .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 2rem; margin-top: 1.5rem; }
        
        .visit-card {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px; padding: 20px; margin-bottom: 20px; color: #e0e0e0;
        }
        .visit-card h5 { font-weight: 400; color: #00e5ff; margin-top: 0; }
        .visit-card p { margin: 6px 0; font-size: 1.05rem; }
        .visit-card strong { color: #bdbdbd; font-weight: 500; margin-right: 8px; }

        .input-field input[type="text"] { color: #fff !important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
        .input-field input[type="text"]:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
        .input-field label { color: #bdbdbd !important; } .input-field label.active { color: #00e5ff !important; }
        
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
    <h3 class="center-align white-text" style="margin-top:30px;">Patient Visit Register</h3>
    <hr class="white-line">

    <?php if ($no_rights): ?>
        <div class="glass-card center-align"><h5 class="red-text text-lighten-2">You do not have rights to view this page.</h5></div>
    <?php else: ?>
        <div class="glass-card">
            <form method="get" id="searchForm">
                <div class="row">
                    <div class="input-field col s12">
                        <i class="material-icons prefix">search</i>
                        <input id="searchBar" name="search" type="text" value="<?= htmlspecialchars($searchTerm) ?>">
                        <label for="searchBar">Search by Name, MRN, or Department...</label>
                    </div>
                    </div>
            </form>
        </div>

        <div id="visitList">
            <?php 
            // Initial page load render
            echo renderVisitListComponent($allVisits); 
            ?>
        </div>
        
        <div class="pagination" id="paginationContainer">
            <?php
            // Initial page load render
            echo renderPaginationComponent($totalPages, $currentPage, $searchTerm);
            ?>
        </div>
    <?php endif; ?>
</main>
<br>
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
document.addEventListener('DOMContentLoaded', function() {
    M.AutoInit();

    // --- AJAX Search and Pagination ---
    const searchBar = document.getElementById('searchBar');
    const visitListContainer = document.getElementById('visitList');
    const paginationContainer = document.getElementById('paginationContainer');
    let debounceTimer;

    // Function to fetch and update content
    async function fetchVisits(url) {
        // Add ajax=1 flag
        const ajaxUrl = new URL(url, window.location.origin + window.location.pathname);
        ajaxUrl.searchParams.set('ajax', '1');

        try {
            // Show a simple loading state
            visitListContainer.style.opacity = '0.5'; 
            
            const response = await fetch(ajaxUrl.href);
            if (!response.ok) {
                throw new Error(`Network response was not ok (${response.status})`);
            }
            const data = await response.json();
            
            if (data.error) {
                 throw new Error(data.error);
            }

            // Update the DOM with the new HTML
            visitListContainer.innerHTML = data.visitsHtml;
            paginationContainer.innerHTML = data.paginationHtml;
            visitListContainer.style.opacity = '1'; // Restore opacity

            // Update browser history
            const userFriendlyUrl = new URL(url, window.location.origin + window.location.pathname);
            window.history.pushState({}, '', userFriendlyUrl.href);

        } catch (error) {
            console.error('Error fetching visits:', error);
            visitListContainer.innerHTML = '<h5 class="center-align red-text" style="margin:40px;">Error loading data. Please try again.</h5>';
            visitListContainer.style.opacity = '1';
        }
    }

    // --- Event Listener for Search Bar (with Debounce) ---
    if (searchBar) {
        searchBar.addEventListener('input', function(e) {
            clearTimeout(debounceTimer);
            
            const searchTerm = e.target.value;
            // Create a new URL for the search
            const url = new URL(window.location.origin + window.location.pathname);
            url.searchParams.set('search', searchTerm);
            url.searchParams.set('page', '1'); // Reset to page 1 on new search

            debounceTimer = setTimeout(() => {
                fetchVisits(url.href);
            }, 300); // 300ms delay
        });
    }

    // --- Event Listener for Pagination (Event Delegation) ---
    if (paginationContainer) {
        paginationContainer.addEventListener('click', function(e) {
            // Only act on anchor tags
            const link = e.target.closest('a');
            if (link) {
                e.preventDefault(); // Stop full page load
                const url = link.href;
                fetchVisits(url);
            }
        });
    }

    // --- Handle Back/Forward button clicks ---
    window.addEventListener('popstate', function(e) {
        // location.href includes the full path and query string
        // On popstate, the URL is already updated, so just fetch it.
        fetchVisits(window.location.href);
    });

});
</script>
</body>
</html>