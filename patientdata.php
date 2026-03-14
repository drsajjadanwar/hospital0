<?php
// /public/patientdata.php
// -- MODIFIED Nov 10, 2025
// - Rewrote 'get_invoices' case to be robust.
// - It now queries the new 'visit_invoices' table for reliable 'Ticket' PDF paths.
// - It PRESERVES the old logic for 'BAR' invoices from 'generalledger'
//   to ensure both systems work.

session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/config.php';
$pdo = $pdo ?? null;
date_default_timezone_set('Asia/Karachi');

// --- Security Check ---
$mrn = trim($_GET['mrn'] ?? '');
if (empty($mrn)) {
    header("Location: patientregister.php");
    exit;
}

// --- Authorization Check ---
$user = $_SESSION['user'] ?? [];
$allowedGroups = [1,3,23];
$no_rights = (!in_array((int)($user['group_id'] ?? 0), $allowedGroups, true));

// --- Handle AJAX Requests ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($no_rights) {
        echo json_encode(['error' => 'Access Denied.']);
        exit;
    }
    
    if (!$pdo) {
         echo json_encode(['error' => 'Database connection error.']);
         exit;
    }

    $response = ['html' => ''];
    try {
        switch ($_GET['action']) {
            
            // -- START: UPDATED INVOICE LOGIC --
            case 'get_invoices':
                $visit_number = (int)($_GET['visit'] ?? 0);
                if (empty($mrn) || $visit_number === 0) {
                    throw new Exception("Invalid MRN or Visit Number.");
                }

                $all_invoices = [];
                $html = '<ul>';

                // 1. Fetch "Ticket" invoices from the new dedicated table (This is the reliable way)
                $stmt_tickets = $pdo->prepare("
                    SELECT invoice_name, pdf_path FROM visit_invoices 
                    WHERE mrn = :mrn AND visit_number = :visit_number
                ");
                $stmt_tickets->execute([':mrn' => $mrn, ':visit_number' => $visit_number]);
                
                while ($row = $stmt_tickets->fetch(PDO::FETCH_ASSOC)) {
                    // Use pdf_path as key to prevent duplicates if any
                    $all_invoices[htmlspecialchars($row['pdf_path'])] = htmlspecialchars($row['invoice_name']);
                }

                // 2. Fetch "BAR" invoices from the general ledger (This is the legacy way, preserved)
                $stmt_bar = $pdo->prepare("
                    SELECT description FROM generalledger 
                    WHERE description LIKE :mrn_visit AND description LIKE 'BAR%'
                ");
                $stmt_bar->execute([':mrn_visit' => "%MRN $mrn%Visit #$visit_number%"]);
                
                while ($desc_raw = $stmt_bar->fetchColumn()) {
                    $desc = htmlspecialchars($desc_raw);
                    $pdfPath = 'bardata/' . $desc . '.pdf';
                    // Add to array, key prevents duplicates
                    $all_invoices[$pdfPath] = $desc;
                }
                
                // 3. Build the HTML response
                if (empty($all_invoices)) {
                    $html = '<p class="center-align">No invoices found for this visit.</p>';
                } else {
                    foreach ($all_invoices as $path => $name) {
                        $html .= '<li style="margin-bottom: 10px;"><a href="'.$path.'" target="_blank" class="btn waves-effect waves-light" style="background-color:#00bfa5; width: 100%;"><i class="material-icons left">receipt_long</i>'.$name.'</a></li>';
                    }
                    $html .= '</ul>';
                }
                $response['html'] = $html;
                break;
            // -- END: UPDATED INVOICE LOGIC --

            case 'get_prescriptions':
                $visit_date = $_GET['date'] ?? '';
                if (empty($mrn) || empty($visit_date)) {
                    throw new Exception("Invalid MRN or Visit Date.");
                }
                
                $stmt = $pdo->prepare("SELECT prescription_id, pdf_path, created_at FROM prescriptions WHERE mrn = :mrn AND DATE(created_at) = :visit_date");
                $stmt->execute([':mrn' => $mrn, ':visit_date' => $visit_date]);
                $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($prescriptions)) {
                    $response['html'] = '<p class="center-align">No prescriptions found for this patient on this date.</p>';
                } else {
                    $response['html'] .= '<ul>';
                    foreach ($prescriptions as $pr) {
                        $fileName = $pr['pdf_path'] ? basename($pr['pdf_path']) : 'Prescription #' . $pr['prescription_id'];
                        $filePath = $pr['pdf_path'] ? htmlspecialchars($pr['pdf_path']) : '#';
                        $response['html'] .= '<li style="margin-bottom: 10px;"><a href="'.$filePath.'" target="_blank" class="btn waves-effect waves-light" style="background-color:#0d47a1; width: 100%;"><i class="material-icons left">medication</i>'.$fileName.'</a></li>';
                    }
                    $response['html'] .= '</ul>';
                }
                break;

        }
    } catch (Exception $e) {
        error_log("Patientdata.php AJAX Error: " . $e->getMessage());
        $response['html'] = '<p class="center-align red-text">An error occurred: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    echo json_encode($response);
    exit;
}

// --- Normal Page Load ---
$patient = null;
$visits = [];
if (!$no_rights) {
    try {
        if (!$pdo) {
             throw new Exception("Database connection error.");
        }
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE mrn = ? LIMIT 1");
        $stmt->execute([$mrn]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$patient) {
            // If MRN is not found, redirect back
            header("Location: patientregister.php?error=notfound");
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT * FROM visits WHERE patient_id = ? ORDER BY time_of_presentation DESC
        ");
        $stmt->execute([$patient['patient_id']]);
        $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $ex) {
        die("Database query error: " . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - Patient Data: <?= htmlspecialchars($patient['full_name'] ?? 'N/A') ?></title>
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
        h3.center-align, h5.white-text { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
        .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }
        .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 2rem; margin-top: 1.5rem; }
        .visit-card { background: rgba(255, 255, 255, 0.12); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 10px; padding: 20px; margin-bottom: 20px; color: #e0e0e0; }
        .visit-card h5 { font-weight: 400; color: #00e5ff; margin-top: 0; }
        .visit-card p { margin: 6px 0; font-size: 1.05rem; }
        .visit-card strong { color: #bdbdbd; font-weight: 500; margin-right: 8px; }
        .btn-small { margin: 5px; }
        .modal { background-color: #212121; color: #fff; border-radius: 15px; border: 1px solid rgba(255, 255, 255, 0.2); }
        .modal .modal-content h5 { font-weight: 300; text-shadow: 0 0 5px rgba(0, 229, 255, 0.5); }
        .modal .modal-footer { background-color: rgba(0,0,0,0.2); }
        .modal .progress { background-color: rgba(0, 229, 255, 0.3); }
        .modal .indeterminate { background-color: #00e5ff; }
    </style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<main class="container">
    <?php if ($no_rights): ?>
        <div class="glass-card center-align" style="margin-top: 5rem;"><h5 class="red-text text-lighten-2">You do not have rights to view this page.</h5></div>
    <?php elseif (!$patient): ?>
        <div class="glass-card center-align" style="margin-top: 5rem;"><h5 class="red-text text-lighten-2">Patient not found.</h5></div>
    <?php else: ?>
        <h3 class="center-align white-text" style="margin-top:30px;"><?= htmlspecialchars($patient['full_name']) ?></h3>
        <h5 class="center-align grey-text text-lighten-1" style="margin-top:-10px;">MRN: <?= htmlspecialchars($patient['mrn']) ?></h5>
        <hr class="white-line">

        <div id="visitList">
            <?php if (empty($visits)): ?>
                <div class="glass-card center-align">
                    <h5 class="grey-text">No visits found for this patient.</h5>
                </div>
            <?php else: ?>
                <?php foreach ($visits as $visit): 
                    $visit_date = date('Y-m-d', strtotime($visit['time_of_presentation']));
                ?>
                    <div class="visit-card">
                        <h5>Visit #<?= htmlspecialchars($visit['visit_number']) ?> (<?= htmlspecialchars($visit['department']) ?>)</h5>
                        <p><strong>Date & Time:</strong> <?= htmlspecialchars(date('D, j M Y, h:i A', strtotime($visit['time_of_presentation']))) ?></p>
                        <p><strong>Age at Visit:</strong> <?= htmlspecialchars($visit['age_value']) ?> <?= htmlspecialchars($visit['age_unit']) ?></p>
                        <p><strong>Amount:</strong> PKR <?= htmlspecialchars(number_format($visit['total_amount'], 2)) ?></p>
                        
                        <div style="margin-top: 20px; border-top: 1px solid rgba(255,255,255,0.2); padding-top: 20px;">
                            <a class="btn-small waves-effect waves-light modal-trigger green btn-invoice"
                               href="#dataModal" 
                               data-mrn="<?= htmlspecialchars($patient['mrn']) ?>"
                               data-visit-number="<?= htmlspecialchars($visit['visit_number']) ?>">
                                <i class="material-icons left">receipt_long</i>Invoices
                            </a>
                            <a class="btn-small waves-effect waves-light modal-trigger blue darken-2 btn-prescription"
                               href="#dataModal"
                               data-mrn="<?= htmlspecialchars($patient['mrn']) ?>"
                               data-visit-date="<?= $visit_date ?>">
                                <i class="material-icons left">medication</i>Prescriptions
                            </a>
                            </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
    <?php endif; ?>
</main>
<br>

<div id="dataModal" class="modal modal-fixed-footer">
    <div class="modal-content">
        <h5 id="modalTitle">Loading...</h5>
        <div id="modalBody">
            <div class="progress"><div class="indeterminate"></div></div>
        </div>
    </div>
    <div class="modal-footer">
        <a href="#!" class="modal-close waves-effect waves-light btn-flat white-text">Close</a>
    </div>
</div>

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
    const dataModal = M.Modal.getInstance(document.getElementById('dataModal'));
    const modalTitle = document.getElementById('modalTitle');
    const modalBody = document.getElementById('modalBody');

    const showLoading = () => {
        modalBody.innerHTML = '<div class="progress"><div class="indeterminate"></div></div>';
    };

    document.querySelectorAll('.btn-invoice').forEach(btn => {
        btn.addEventListener('click', () => {
            modalTitle.textContent = 'Invoices for Visit #' + btn.dataset.visitNumber;
            showLoading();
            fetchData('get_invoices', { mrn: btn.dataset.mrn, visit: btn.dataset.visitNumber });
        });
    });

    document.querySelectorAll('.btn-prescription').forEach(btn => {
        btn.addEventListener('click', () => {
            modalTitle.textContent = 'Prescriptions for ' + btn.dataset.visitDate;
            showLoading();
            fetchData('get_prescriptions', { mrn: btn.dataset.mrn, date: btn.dataset.visitDate });
        });
    });

    // Removed '.btn-notes' event listener

    function fetchData(action, params) {
        const url = new URL(window.location.href);
        // Use searchParams.set to ensure correct URL formatting
        url.searchParams.set('action', action);
        for (const key in params) {
            url.searchParams.set(key, params[key]);
        }

        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Network error: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    modalBody.innerHTML = `<p class="center-align red-text">${data.error}</p>`;
                } else {
                    modalBody.innerHTML = data.html;
                }
            })
            .catch(err => {
                modalBody.innerHTML = '<p class="center-align red-text">Error loading data. Please try again.</p>';
                console.error('Fetch Error:', err);
            });
    }
});
</script>
</body>
</html>