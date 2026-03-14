<?php
// /public/appointments.php — Modernized with high-tech theme & revise functionality
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/config.php';
$pdo = $pdo ?? null;

// Refresh user details
$user = $_SESSION['user'] ?? [];
$userId = (int)($user['user_id'] ?? 0);
try {
    $st = $pdo->prepare("SELECT group_id, full_name FROM users WHERE user_id = ? LIMIT 1");
    $st->execute([$userId]);
    $rw = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $user['group_id'] = (int)($rw['group_id'] ?? 0);
    $user['full_name'] = $rw['full_name'] ?: ($user['username'] ?? '');
    $_SESSION['user'] = $user;
} catch (Throwable $e) {
    $user['group_id'] = 0;
}

// Rights check
$allowedGroups = [1, 2, 3, 4, 5, 6, 8, 10, 20, 22, 23];
$no_rights = !in_array($user['group_id'], $allowedGroups, true);

// Handle AJAX POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$no_rights) {
    header('Content-Type: application/json; charset=UTF-8');
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'create':
                $patient = trim($_POST['patient_name'] ?? ''); $aptTime = trim($_POST['appointment_time'] ?? '');
                $dept = trim($_POST['department'] ?? ''); $phone = trim($_POST['phone'] ?? '');
                if (!$patient || !$aptTime || !$dept) { throw new RuntimeException('Missing required fields.'); }
                $ins = $pdo->prepare("INSERT INTO appointments (patient_name, appointment_time, department, phone, created_by) VALUES (?,?,?,?,?)");
                $ins->execute([$patient, $aptTime, $dept, $phone, $userId]);
                echo json_encode(['ok' => 1, 'id' => $pdo->lastInsertId()]);
                exit;
            case 'complete':
                $aptId = (int)($_POST['appointment_id'] ?? 0);
                $pdo->prepare("UPDATE appointments SET status='completed' WHERE appointment_id=? LIMIT 1")->execute([$aptId]);
                echo json_encode(['ok' => 1]);
                exit;
            case 'dismiss':
                $aptId = (int)($_POST['appointment_id'] ?? 0); $reason = substr(trim($_POST['reason'] ?? ''), 0, 128);
                if (!$reason) throw new RuntimeException('Reason required.');
                $pdo->prepare("UPDATE appointments SET status='dismissed', dismiss_reason=? WHERE appointment_id=? LIMIT 1")->execute([$reason, $aptId]);
                echo json_encode(['ok' => 1]);
                exit;
            case 'revise_time':
                $aptId = (int)($_POST['appointment_id'] ?? 0); $newTime = trim($_POST['new_time'] ?? '');
                if (!$aptId || !$newTime) { throw new RuntimeException('Missing required fields for revision.'); }
                $pdo->prepare("UPDATE appointments SET appointment_time=? WHERE appointment_id=? AND status='active' LIMIT 1")->execute([$newTime, $aptId]);
                echo json_encode(['ok' => 1]);
                exit;
            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => 0, 'error' => $e->getMessage()]);
        exit;
    }
}

// Fetch all appointment data
$allAppointments = [];
if (!$no_rights) {
    $allAppointments = $pdo->query("SELECT * FROM appointments ORDER BY appointment_time DESC")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - Patient Appointments</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/media/sitelogo.png" type="image/png">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
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
        
        .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 1rem 2rem; }
        
        table { border-radius: 8px; overflow: hidden; }
        table.striped>tbody>tr:nth-child(odd) { background-color: rgba(255, 255, 255, 0.05); }
        th { border-bottom: 1px solid rgba(255, 255, 255, 0.3); }
        td, th { padding: 15px 10px; }
        
        .pagination { text-align: center; margin: 20px 0; }
        .page-number, .ellipsis { display:inline-block; padding: 8px 12px; margin: 0 4px; cursor: pointer; color: #fff; border-radius: 5px; transition: background-color 0.3s; }
        .page-number:hover { background-color: rgba(0, 229, 255, 0.2); }
        .ellipsis { cursor: default; }
        .active-page { font-weight: bold; background-color: #00bfa5; }
        
        .modal { background-color: #212121; color: #fff; border-radius: 15px; border: 1px solid rgba(255, 255, 255, 0.2); }
        .modal .modal-content h5 { font-weight: 300; text-shadow: 0 0 5px rgba(0, 229, 255, 0.5); }
        .modal .modal-footer { background-color: rgba(0,0,0,0.2); }
        .modal .input-field input { color: #fff !important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
        .modal .input-field label { color: #bdbdbd !important; }
        .modal .input-field label.active { color: #00e5ff !important; }
        .modal .input-field input:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
        input[type="datetime-local"]::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; }

        .filter-btn { background-color: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2); transition: background-color 0.3s; }
        .filter-btn.active { background-color: #00bfa5; color: #121212; border-color: #00bfa5; }
    </style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<main>
<div class="container">
    <h3 class="center-align white-text" style="margin-top:30px;">Patient Appointments</h3>
    <?php if (!$no_rights): ?>
    <div class="center-align" style="margin:25px 0;">
      <a class="btn-large waves-effect waves-light modal-trigger" href="#newApptModal" style="background-color: #00bfa5;">
        <i class="material-icons left">add_circle</i>New Appointment
      </a>
    </div>
    <?php endif; ?>
    <hr class="white-line">

    <?php if ($no_rights): ?>
        <div class="glass-card center-align"><h5 class="red-text text-lighten-2">You do not have rights to view this page.</h5></div>
    <?php else: ?>
    <div class="glass-card">
        <div class="row">
            <div class="col s12 center-align" style="margin-bottom: 2rem;">
                <a class="btn waves-effect waves-light filter-btn active" data-status="active">Active</a>
                <a class="btn waves-effect waves-light filter-btn" data-status="completed">Completed</a>
                <a class="btn waves-effect waves-light filter-btn" data-status="dismissed">Dismissed</a>
            </div>
            <div class="col s12">
                <table class="striped highlight responsive-table">
                    <thead id="tableHeader"></thead>
                    <tbody id="tableBody"></tbody>
                </table>
                <div id="pagination" class="pagination"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
</main>

<div id="newApptModal" class="modal"><div class="modal-content"><h5>Create New Appointment</h5><form id="formNewAppt"><div class="row"><div class="input-field col s12 m6"><input id="patient_name" name="patient_name" type="text" required><label for="patient_name">Patient Name</label></div><div class="input-field col s12 m6"><input id="appointment_time" name="appointment_time" type="datetime-local" required><label for="appointment_time" class="active">Time</label></div></div><div class="row"><div class="input-field col s12 m6"><input id="department" name="department" type="text" required><label for="department">Department</label></div><div class="input-field col s12 m6"><input id="phone" name="phone" type="tel"><label for="phone">Phone</label></div></div></form></div><div class="modal-footer"><a href="#!" class="modal-close waves-effect btn-flat">Cancel</a><button class="btn waves-effect waves-light" id="btnSaveAppt" style="background-color:#00bfa5;"><i class="material-icons left">save</i>Save</button></div></div>
<div id="dismissReasonModal" class="modal"><div class="modal-content"><h5>Dismiss Appointment</h5><p>Please provide a reason (max 128 characters):</p><div class="input-field"><input id="dismiss_reason" type="text" maxlength="128"><label for="dismiss_reason">Reason</label></div></div><div class="modal-footer"><a href="#!" class="modal-close waves-effect btn-flat">Cancel</a><a href="#!" class="modal-close waves-effect waves-light btn red" id="btnConfirmDismiss"><i class="material-icons left">cancel</i>Dismiss</a></div></div>
<div id="reviseTimeModal" class="modal"><div class="modal-content"><h5>Revise Appointment Time</h5><div class="input-field"><input id="new_appointment_time" type="datetime-local" required><label for="new_appointment_time" class="active">New Date & Time</label></div></div><div class="modal-footer"><a href="#!" class="modal-close waves-effect btn-flat">Cancel</a><a href="#!" class="modal-close waves-effect waves-light btn" id="btnConfirmRevise" style="background-color:#00bfa5;"><i class="material-icons left">update</i>Update Time</a></div></div>

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
document.addEventListener('DOMContentLoaded', () => {
    M.AutoInit();
    const allData = <?php echo json_encode($allAppointments, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
    const PAGE_SIZE = 10;
    let currentPage = 1;
    let currentStatus = 'active';
    let dismissId = 0, reviseId = 0;

    const escapeHtml = str => str.replace(/[&<>"']/g, s=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[s]));
    const formatDate = str => (new Date(str)).toLocaleString('en-GB', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit', hour12: true });

    const tableHeader = document.getElementById('tableHeader');
    const tableBody = document.getElementById('tableBody');
    const paginationDiv = document.getElementById('pagination');

    function renderTable() {
        const filteredData = allData.filter(item => item.status === currentStatus);
        
        // Sort active appointments ascending (soonest first), others descending (latest first)
        filteredData.sort((a, b) => {
            const timeA = new Date(a.appointment_time).getTime();
            const timeB = new Date(b.appointment_time).getTime();
            return currentStatus === 'active' ? timeA - timeB : timeB - timeA;
        });

        tableBody.innerHTML = '';
        const totalPages = Math.ceil(filteredData.length / PAGE_SIZE);
        currentPage = Math.min(currentPage, totalPages) || 1;
        const pageData = filteredData.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);
        
        let headerHtml = '<tr><th>Patient</th><th>Time</th><th>Department</th><th>Phone</th>';
        if (currentStatus === 'dismissed') {
            headerHtml += '<th>Reason</th>';
        } else {
            headerHtml += '<th>Actions</th>';
        }
        headerHtml += '</tr>';
        tableHeader.innerHTML = headerHtml;

        if (pageData.length === 0) {
            tableBody.innerHTML = `<tr><td colspan="5" class="center-align">No ${currentStatus} appointments.</td></tr>`;
        } else {
            pageData.forEach(row => {
                let actionHtml = '';
                if (currentStatus === 'active') {
                    actionHtml = `<a href="#!" class="revise-appt tooltipped" data-id="${row.appointment_id}" data-time="${row.appointment_time.replace(' ', 'T')}" data-tooltip="Revise Time"><i class="material-icons amber-text text-lighten-2">edit_calendar</i></a>
                                  <a href="#!" class="complete-appt tooltipped" data-id="${row.appointment_id}" data-tooltip="Complete"><i class="material-icons green-text">check_circle</i></a>
                                  <a href="#!" class="dismiss-appt tooltipped" data-id="${row.appointment_id}" data-tooltip="Dismiss"><i class="material-icons red-text">cancel</i></a>`;
                } else if (currentStatus === 'completed') {
                    actionHtml = `<span class="green-text"><i class="material-icons">check_circle</i> Completed</span>`;
                } else if (currentStatus === 'dismissed') {
                    actionHtml = escapeHtml(row.dismiss_reason || 'N/A');
                }
                const rowHtml = `<tr>
                    <td>${escapeHtml(row.patient_name)}</td>
                    <td>${formatDate(row.appointment_time)}</td>
                    <td>${escapeHtml(row.department)}</td>
                    <td>${escapeHtml(row.phone || '')}</td>
                    <td>${actionHtml}</td>
                </tr>`;
                tableBody.insertAdjacentHTML('beforeend', rowHtml);
            });
        }
        renderPagination(totalPages);
        M.Tooltip.init(document.querySelectorAll('.tooltipped'));
    }

    function renderPagination(totalPages) {
        paginationDiv.innerHTML = '';
        if (totalPages <= 1) return;

        const createPageElement = (pageNumber, text = pageNumber) => {
            const el = document.createElement('span');
            el.className = 'page-number' + (pageNumber === currentPage ? ' active-page' : '');
            el.textContent = text;
            el.onclick = () => { currentPage = pageNumber; renderTable(); };
            return el;
        };
        const createEllipsis = () => { const el = document.createElement('span'); el.className = 'ellipsis'; el.textContent = '...'; return el; };

        if (currentPage > 1) paginationDiv.appendChild(createPageElement(currentPage - 1, '«'));
        paginationDiv.appendChild(createPageElement(1));
        if (currentPage > 3) paginationDiv.appendChild(createEllipsis());
        for (let i = Math.max(2, currentPage - 1); i <= Math.min(totalPages - 1, currentPage + 1); i++) { paginationDiv.appendChild(createPageElement(i)); }
        if (currentPage < totalPages - 2) paginationDiv.appendChild(createEllipsis());
        if (totalPages > 1) paginationDiv.appendChild(createPageElement(totalPages));
        if (currentPage < totalPages) paginationDiv.appendChild(createPageElement(currentPage + 1, '»'));
    }

    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentStatus = btn.dataset.status;
            currentPage = 1;
            renderTable();
        });
    });

    // --- Event Handlers for Actions ---
    tableBody.addEventListener('click', e => {
        const completeBtn = e.target.closest('.complete-appt');
        const dismissBtn = e.target.closest('.dismiss-appt');
        const reviseBtn = e.target.closest('.revise-appt');
        
        if (completeBtn) {
            if (confirm('Mark this appointment as completed?')) {
                performAction('complete', { appointment_id: completeBtn.dataset.id });
            }
        }
        if (dismissBtn) {
            dismissId = dismissBtn.dataset.id;
            M.Modal.getInstance(document.getElementById('dismissReasonModal')).open();
        }
        if (reviseBtn) {
            reviseId = reviseBtn.dataset.id;
            document.getElementById('new_appointment_time').value = reviseBtn.dataset.time;
            M.Modal.getInstance(document.getElementById('reviseTimeModal')).open();
        }
    });

    document.getElementById('btnConfirmDismiss').addEventListener('click', () => {
        const reason = document.getElementById('dismiss_reason').value.trim();
        if (!reason) { M.toast({html: 'Reason is required.'}); return; }
        performAction('dismiss', { appointment_id: dismissId, reason: reason });
    });

    document.getElementById('btnConfirmRevise').addEventListener('click', () => {
        const newTime = document.getElementById('new_appointment_time').value;
        if (!newTime) { M.toast({html: 'New date and time are required.'}); return; }
        performAction('revise_time', { appointment_id: reviseId, new_time: newTime });
    });
    
    document.getElementById('btnSaveAppt').addEventListener('click', () => {
        const form = document.getElementById('formNewAppt');
        if (!form.reportValidity()) return;
        const formData = new FormData(form);
        formData.append('action', 'create');
        performAction('create', Object.fromEntries(formData.entries()), true);
    });

    function performAction(action, data, isForm = false) {
        const body = isForm ? new FormData() : new URLSearchParams();
        body.append('action', action);
        for (const key in data) { body.append(key, data[key]); }

        fetch(location.href, { method: 'POST', body: body })
            .then(r => r.json())
            .then(resp => {
                if (resp.ok) {
                    M.toast({html: `Appointment ${action}d successfully!`});
                    location.reload(); // Easiest way to refresh all data
                } else { M.toast({html: `Error: ${resp.error}`}); }
            })
            .catch(() => M.toast({html: 'An unexpected error occurred.'}));
    }

    renderTable(); // Initial render
});
</script>

</body>
</html>