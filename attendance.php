<?php
// /public/attendance.php – Modernized with high-tech theme & improved UX

// --- IP Address Restriction ---
$allowed_ip = '127.0.0.1';
$client_ip = $_SERVER['REMOTE_ADDR'];

if ($client_ip !== $allowed_ip) {
    header('HTTP/1.1 403 Forbidden');
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>hospital0 - Access Denied</title>";
    echo "<style>body{font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background-color: #f0f0f0;} .error-container{text-align: center; padding: 20px; border: 1px solid #ccc; background-color: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.1);}</style>";
    echo "</head><body><div class='error-container'>";
    echo "<h1>Access Denied</h1>";
    echo "<p>This page can only be accessed from a specific IP address.</p>";
    echo "<p><small>Your IP: " . htmlspecialchars($client_ip) . "</small></p>";
    echo "</div></body></html>";
    exit;
}


/* ------------------------------------------------------------------
    Bootstrapping & session
------------------------------------------------------------------ */
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/config.php';   // exposes $pdo
date_default_timezone_set('Asia/Karachi');

$user   = $_SESSION['user'];
$userId = (int)($user['user_id'] ?? 0);

/* ------------------------------------------------------------------
    Refresh user record and get group_id
------------------------------------------------------------------ */
$st = $pdo->prepare("SELECT group_id, full_name FROM users WHERE user_id = ? LIMIT 1");
$st->execute([$userId]);
if (!$st->rowCount()) { session_destroy(); header('Location: login.php'); exit; }
$userDB = $st->fetch(PDO::FETCH_ASSOC);
$user = array_merge($user, $userDB);
$_SESSION['user'] = $user;
$isAdmin = ($user['group_id'] === 1);
$viewingUserId = $userId;
$viewingUserName = $user['full_name'];

$allUsers = [];
if ($isAdmin) {
    if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
        $viewingUserId = (int)$_GET['user_id'];
        $userStmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
        $userStmt->execute([$viewingUserId]);
        $viewingUserName = $userStmt->fetchColumn() ?: 'Unknown User';
    } else {
        $allUsers = $pdo->query("SELECT user_id, full_name, username FROM users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
}


/* ------------------------------------------------------------------
    Handle AJAX actions
------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $action = $_POST['action'] ?? '';
    
    $targetUserId = $userId;
    if ($isAdmin && isset($_POST['user_id']) && is_numeric($_POST['user_id'])) {
        $targetUserId = (int)$_POST['user_id'];
    }

    try {
        if ($action === 'checkin') {
            if ($isAdmin) throw new RuntimeException('Admins cannot check in or out.');
            $open = $pdo->prepare("SELECT attendance_id FROM attendance_logs WHERE user_id = ? AND check_out IS NULL");
            $open->execute([$targetUserId]);
            if ($open->fetchColumn()) { throw new RuntimeException('Already checked-in. Please check out first.'); }
            $pdo->prepare("INSERT INTO attendance_logs (user_id, check_in) VALUES (?, NOW())")->execute([$targetUserId]);
            echo json_encode(['ok' => 1, 'message' => 'Checked in successfully.']); exit;
        }

        if ($action === 'checkout') {
            if ($isAdmin) throw new RuntimeException('Admins cannot check in or out.');
            $upd = $pdo->prepare("UPDATE attendance_logs SET check_out = NOW() WHERE user_id = ? AND check_out IS NULL ORDER BY attendance_id DESC LIMIT 1");
            $upd->execute([$targetUserId]);
            if (!$upd->rowCount()) { throw new RuntimeException('No open check-in found.'); }
            echo json_encode(['ok' => 1, 'message' => 'Checked out successfully.']); exit;
        }

        if ($action === 'get_logs') {
            $month = $_POST['month'] ?? date('n');
            $year = $_POST['year'] ?? date('Y');
            $startDate = date('Y-m-d', mktime(0, 0, 0, $month, 1, $year));
            $endDate = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
            $stmt = $pdo->prepare("SELECT check_in, check_out FROM attendance_logs WHERE user_id = ? AND check_in BETWEEN ? AND ? ORDER BY check_in DESC");
            $stmt->execute([$targetUserId, $startDate . ' 00:00:00', $endDate . ' 23:59:59']);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totalSeconds = 0;
            $formattedLogs = [];
            foreach ($logs as $log) {
                $checkInTime = new DateTime($log['check_in']);
                $logDuration = 'In Progress';
                if ($log['check_out']) {
                    $checkOutTime = new DateTime($log['check_out']);
                    $interval = $checkInTime->diff($checkOutTime);
                    $durationSeconds = ($interval->days * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
                    $totalSeconds += $durationSeconds;
                    $logDuration = sprintf('%02d:%02d:%02d', $interval->h, $interval->i, $interval->s);
                }
                $formattedLogs[] = [
                    'date' => $checkInTime->format('D, j M Y'),
                    'check_in' => $checkInTime->format('h:i:s A'),
                    'check_out' => $log['check_out'] ? (new DateTime($log['check_out']))->format('h:i:s A') : '---',
                    'duration' => $logDuration,
                ];
            }
            $totalHours = floor($totalSeconds / 3600);
            $totalMinutes = floor(($totalSeconds % 3600) / 60);
            $totalHoursFormatted = sprintf('%d hours, %d minutes', $totalHours, $totalMinutes);
            echo json_encode(['ok' => 1, 'logs' => $formattedLogs, 'total_hours_formatted' => $totalHoursFormatted ]);
            exit;
        }
        throw new RuntimeException('Unknown action requested.');
    } catch (Throwable $e) {
        http_response_code(400);
        error_log("Attendance AJAX Error: " . $e->getMessage());
        echo json_encode(['ok' => 0, 'error' => $e->getMessage()]);
        exit;
    }
}

// For non-AJAX page load, get current status for the logged-in user
$openShift = null;
if (!$isAdmin) {
    $openShiftStmt = $pdo->prepare("SELECT check_in FROM attendance_logs WHERE user_id = ? AND check_out IS NULL ORDER BY attendance_id DESC LIMIT 1");
    $openShiftStmt->execute([$userId]);
    $openShift = $openShiftStmt->fetch(PDO::FETCH_ASSOC);
}
$shiftOpen = (bool)$openShift;
$lastCheckIn = $openShift['check_in'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>hospital0 - Attendance - <?php echo htmlspecialchars($viewingUserName); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="/media/sitelogo.png" type="image/png">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
<link rel="stylesheet" href="assets/css/style.css">
<style>
    /* --- NEW BEAUTIFICATION STYLES --- */
    body { background-image: none !important; background-color: #121212 !important; color: #fff; overflow-x: hidden; }
    @keyframes move-twink-back { from { background-position: 0 0; } to { background-position: -10000px 5000px; } }
    .stars, .twinkling { position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; display: block; z-index: -3; }
    .stars { background: #000 url(/media/stars.png) repeat top center; }
    .twinkling { background: transparent url(/media/twinkling.png) repeat top center; animation: move-twink-back 200s linear infinite; }
    #dna-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; opacity: 0.3; }
    
    h3.center-align { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
    .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }

    .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 2rem; margin-top: 1.5rem; }
    
    #checkedInStatusMessage { font-size: 1.5rem; font-weight: 300; text-shadow: 0 0 5px rgba(129, 236, 132, 0.7); }
    
    .btn-large { box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
    .disabled-btn { pointer-events: none; opacity: 0.5 !important; background-color: #9e9e9e !important; }
    
    #attendanceTableContainer table { border: none; }
    #attendanceTableContainer th, #attendanceTableContainer td { padding: 12px 15px; text-align: center; }
    #attendanceTableContainer th { font-weight: bold; border-bottom: 1px solid rgba(255,255,255,0.3); }
    #attendanceTableContainer tr:nth-child(even) { background-color: rgba(255,255,255,0.05); }

    #monthlyTotalHours { margin: 25px 0; font-size: 2.2rem; font-weight: 300; color: #00e5ff; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
    
    .input-field label { color: #bdbdbd !important; }
    .select-wrapper input.select-dropdown { color: #fff !important; border-bottom: 1px solid rgba(255,255,255,0.5) !important; }
    ul.dropdown-content { background-color: #2a2a2a; } .dropdown-content li>span { color: #fff !important; }
    .select-wrapper.active .caret, .select-wrapper:focus .caret { color: #00e5ff !important; }
    .select-wrapper .caret { color: #bdbdbd !important; }

    .user-list-container { max-height: 50vh; overflow-y: auto; padding-right: 10px; }
    .collection { border: none; }
    .collection .collection-item { background-color: rgba(255,255,255,0.05); border-bottom: 1px solid rgba(255,255,255,0.1); color: #fff; transition: background-color 0.3s; }
    .collection .collection-item:hover { background-color: rgba(0, 229, 255, 0.1); }
</style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<div class="container">
  <h3 class="center-align" style="margin-top:30px;"><?php echo htmlspecialchars($viewingUserName); ?>'s Attendance</h3>
  <hr class="white-line">

  <?php if ($isAdmin && !isset($_GET['user_id'])): ?>
    <div class="glass-card">
        <h4 class="center-align" style="font-weight: 300; text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Select a User to View Attendance</h4>
        <div class="input-field"><i class="material-icons prefix">search</i><input id="userSearch" type="text" placeholder="Search for user..."><label for="userSearch"></label></div>
        <div class="user-list-container">
            <div class="collection" id="userList">
                <?php foreach ($allUsers as $u): ?>
                    <a href="?user_id=<?php echo $u['user_id']; ?>" class="collection-item">
                        <?php echo htmlspecialchars($u['full_name']); ?>
                        <span class="grey-text text-lighten-1"> (<?php echo htmlspecialchars($u['username']); ?>)</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
  <?php else: ?>
    <div class="glass-card">
        <?php if (!$isAdmin): ?>
          <?php if ($shiftOpen): ?>
              <h5 class="center-align green-text text-lighten-2" id="checkedInStatusMessage">
                  Checked in at <?php echo date('h:i A', strtotime($lastCheckIn)); ?>
              </h5>
          <?php endif; ?>
          <div class="center-align" style="margin-top:40px; margin-bottom: 30px;">
              <a id="btnCheckIn" class="btn-large waves-effect <?php echo $shiftOpen ? 'disabled-btn' : 'green darken-1'; ?>">
                  <i class="material-icons left">login</i>Check In
              </a>
              <a id="btnCheckOut" class="btn-large waves-effect <?php echo $shiftOpen ? 'red darken-1' : 'disabled-btn'; ?>">
                  <i class="material-icons left">logout</i>Check Out
              </a>
          </div>
        <?php endif; ?>

        <div class="row" style="margin-top: 2rem;">
          <div class="input-field col s12 m5 l4">
            <select id="monthSelector">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo ($m == date('n')) ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 10)); ?></option>
                <?php endfor; ?>
            </select>
            <label>Month</label>
          </div>
          <div class="input-field col s12 m4 l3">
            <select id="yearSelector">
                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
            <label>Year</label>
          </div>
        </div>
        
        <h3 id="monthlyTotalHours" class="center-align"></h3>
        <div id="attendanceTableContainer"></div>
    </div>
  <?php endif; ?>
</div>

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
document.addEventListener('DOMContentLoaded',()=>{
    M.AutoInit();
    
    const monthSelector = document.getElementById('monthSelector');
    const yearSelector = document.getElementById('yearSelector');
    const userSearchInput = document.getElementById('userSearch');
    
    if (userSearchInput) {
        userSearchInput.addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const userList = document.querySelectorAll('#userList a.collection-item');
            userList.forEach(item => {
                if (item.textContent.toLowerCase().includes(filter)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }

    if (monthSelector && yearSelector) {
        const urlParams = new URLSearchParams(window.location.search);
        const viewingUserId = urlParams.get('user_id') || <?php echo $userId; ?>;
        const fetchLogs = () => fetchAttendanceLogs(viewingUserId, monthSelector.value, yearSelector.value);
        fetchLogs();
        monthSelector.addEventListener('change', fetchLogs);
        yearSelector.addEventListener('change', fetchLogs);
    }

    const checkInBtn = document.getElementById('btnCheckIn');
    const checkOutBtn = document.getElementById('btnCheckOut');
    if(checkInBtn) checkInBtn.addEventListener('click', () => { if (!checkInBtn.classList.contains('disabled-btn')) handleAttendanceAction('checkin'); });
    if(checkOutBtn) checkOutBtn.addEventListener('click', () => { if (!checkOutBtn.classList.contains('disabled-btn')) handleAttendanceAction('checkout'); });
});

function handleAttendanceAction(actionType) {
    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: new URLSearchParams({action: actionType})
    })
    .then(r => r.json().then(data => ({ok: r.ok, data})))
    .then(({ok, data}) => {
        if (ok) {
            M.toast({html: data.message, classes: 'green'});
            setTimeout(() => window.location.reload(), 1500);
        } else { throw new Error(data.error || 'An unknown error occurred.'); }
    })
    .catch((error) => M.toast({html: `Error: ${error.message}`, classes: 'red'}));
}

function fetchAttendanceLogs(userId, month, year) {
    const tableContainer = document.getElementById('attendanceTableContainer');
    const totalHoursContainer = document.getElementById('monthlyTotalHours');
    if (!tableContainer || !totalHoursContainer) return;
    
    tableContainer.innerHTML = '<div class="progress"><div class="indeterminate"></div></div>';
    totalHoursContainer.innerHTML = '';

    fetch(window.location.href, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: new URLSearchParams({action: 'get_logs', user_id: userId, month: month, year: year})
    })
    .then(r => r.json().then(data => ({ok: r.ok, data})))
    .then(({ok, data}) => {
        if (ok) {
            if (data.logs && data.logs.length > 0) {
                let tableHTML = '<table class="striped responsive-table"><thead><tr><th>Date</th><th>Check In</th><th>Check Out</th><th>Duration</th></tr></thead><tbody>';
                data.logs.forEach(log => {
                    tableHTML += `<tr><td>${log.date}</td><td>${log.check_in}</td><td>${log.check_out}</td><td>${log.duration}</td></tr>`;
                });
                tableHTML += '</tbody></table>';
                tableContainer.innerHTML = tableHTML;
            } else {
                tableContainer.innerHTML = '<p class="center-align white-text">No attendance logs found for this period.</p>';
            }
            totalHoursContainer.textContent = `Total For This Month: ${data.total_hours_formatted || '0 hours, 0 minutes'}`;
        } else { throw new Error(data.error || 'Failed to load logs.'); }
    })
    .catch(error => {
        tableContainer.innerHTML = `<p class="center-align red-text text-lighten-2">Error: ${error.message}</p>`;
    });
}
</script>
</body>
</html>