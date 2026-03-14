<?php
// /public/viewuserfile.php

session_start();
require_once __DIR__ . '/includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$currentUser = $_SESSION['user'];
// **GEMINI UPDATE: Grant admin rights to both Admins (1) and GMs (23)
$has_admin_rights = in_array($currentUser['group_id'], [1, 23], true);

$feedback_message = '';
$feedback_type = '';

// --- Helper Functions ---
function getFileIcon(string $filename): string {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    switch ($extension) {
        case 'pdf': return 'picture_as_pdf';
        case 'doc': case 'docx': return 'description';
        case 'jpg': case 'jpeg': case 'png': case 'gif': return 'image';
        case 'xls': case 'xlsx': return 'assessment';
        case 'zip': case 'rar': return 'archive';
        default: return 'attach_file';
    }
}

function formatBytes(int $bytes, int $precision = 2): string {
    if ($bytes === 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $base = log($bytes, 1024);
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $units[floor($base)];
}

// --- Parse Main Action ---
$action  = $_GET['action'] ?? '';
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$theUser = null;

// --- Handle POST Actions (Uploads/Deletes) before rendering ---
if ($has_admin_rights && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Action: Upload a new file
    if (isset($_POST['upload_file']) && $user_id > 0) {
        try {
            // **GEMINI UPDATE: Robust Error Handling for Uploads **
            if (!isset($_FILES['the_file']) || !is_uploaded_file($_FILES['the_file']['tmp_name'])) {
                throw new RuntimeException('No file was uploaded.');
            }
            
            // Check for upload errors
            if ($_FILES['the_file']['error'] !== UPLOAD_ERR_OK) {
                switch ($_FILES['the_file']['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        throw new RuntimeException('File is too large.');
                    case UPLOAD_ERR_PARTIAL:
                        throw new RuntimeException('File was only partially uploaded.');
                    case UPLOAD_ERR_NO_FILE:
                        throw new RuntimeException('No file was sent.');
                    default:
                        throw new RuntimeException('Unknown upload error.');
                }
            }

            // Check file size (e.g., 10MB limit)
            define('MAX_FILE_SIZE', 10 * 1024 * 1024);
            if ($_FILES['the_file']['size'] > MAX_FILE_SIZE) {
                throw new RuntimeException('Exceeded filesize limit of 10 MB.');
            }

            // Check file type (MIME type is more reliable but extension is simpler)
            $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'xls', 'xlsx', 'zip'];
            $originalName = basename($_FILES['the_file']['name']);
            $fileExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($fileExtension, $allowed_extensions)) {
                throw new RuntimeException('Invalid file format. Allowed types: ' . implode(', ', $allowed_extensions));
            }
            
            // **GEMINI UPDATE: Generate unique filename **
            $filename = pathinfo($originalName, PATHINFO_FILENAME);
            $randomSuffix = substr(bin2hex(random_bytes(4)), 0, 8); // 8 random hex chars
            $uniqueFilename = $filename . '_' . $randomSuffix . '.' . $fileExtension;

            $userFolder = __DIR__ . "/userdata/" . $user_id;
            if (!is_dir($userFolder)) {
                if (!mkdir($userFolder, 0777, true)) {
                    throw new RuntimeException('Failed to create user directory.');
                }
            }
            $finalPath = $userFolder . "/" . $uniqueFilename;

            if (!move_uploaded_file($_FILES['the_file']['tmp_name'], $finalPath)) {
                throw new RuntimeException('Failed to move uploaded file.');
            }
            
            // Store original name for display, but unique path for storage
            $relativePath = "userdata/" . $user_id . "/" . $uniqueFilename;
            $stmtInsert = $pdo->prepare("INSERT INTO user_files (user_id, file_name, file_path) VALUES (?, ?, ?)");
            $stmtInsert->execute([$user_id, $originalName, $relativePath]);
            
            $feedback_message = "File uploaded successfully.";
            $feedback_type = 'success';

        } catch (RuntimeException $e) {
            $feedback_message = $e->getMessage();
            $feedback_type = 'error';
        }
    }

    // Action: Remove a file (from modal)
    if (isset($_POST['remove_file']) && $user_id > 0) {
        $file_id = (int)($_POST['file_id'] ?? 0);
        $stmtF = $pdo->prepare("SELECT `file_path` FROM `user_files` WHERE `file_id` = ? AND `user_id` = ?");
        $stmtF->execute([$file_id, $user_id]);
        $fileRec = $stmtF->fetch(PDO::FETCH_ASSOC);
        if ($fileRec) {
            $stmtDel = $pdo->prepare("DELETE FROM `user_files` WHERE `file_id` = ?");
            $stmtDel->execute([$file_id]);
            $filePathOnDisk = __DIR__ . "/" . $fileRec['file_path'];
            if (file_exists($filePathOnDisk)) {
                unlink($filePathOnDisk);
            }
            $feedback_message = "File has been removed successfully.";
            $feedback_type = 'success';
        } else {
            $feedback_message = "File not found or access denied.";
            $feedback_type = 'error';
        }
    }
}


// --- Data Fetching for Views ---
if ($action === 'file_manager' && $user_id > 0) {
    // Fetch the specific user for the file manager view
    $stmtUser = $pdo->prepare("SELECT `user_id`, `username`, `full_name` FROM `users` WHERE `user_id`=? LIMIT 1");
    $stmtUser->execute([$user_id]);
    $theUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$theUser) {
        $feedback_message = "User not found. Cannot open File Manager.";
        $feedback_type = 'error';
        $action = ''; // Revert to the main listing view
    } else {
        // Load existing files for this user
        $stmtFiles = $pdo->prepare("SELECT * FROM `user_files` WHERE `user_id` = ? ORDER BY `uploaded_at` DESC");
        $stmtFiles->execute([$theUser['user_id']]);
        $userFiles = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    // Fetch all groups and users for the main listing view
    $searchTerm = trim($_GET['search'] ?? '');
    $groups = [];
    $stmtGrp = $pdo->query("SELECT `group_id`, `group_name` FROM `groups` ORDER BY `group_name` ASC");
    
    $userWhereClause = $searchTerm ? "AND (`username` LIKE :search OR `full_name` LIKE :search) " : "";
    
    foreach ($stmtGrp->fetchAll(PDO::FETCH_ASSOC) as $gr) {
        $stmtUsr = $pdo->prepare("SELECT `user_id`, `username`, `full_name` FROM `users` WHERE `group_id` = :gid {$userWhereClause} ORDER BY `username` ASC");
        $params = [':gid' => $gr['group_id']];
        if ($searchTerm) $params[':search'] = "%$searchTerm%";
        $stmtUsr->execute($params);
        $usersInGroup = $stmtUsr->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($usersInGroup)) {
            $groups[] = [
                'group_name' => $gr['group_name'],
                'users'      => $usersInGroup
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - User File Manager - hospital0</title>
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
        .collection .collection-item { background-color: rgba(255, 255, 255, 0.05); color: #fff; border-bottom: 1px solid rgba(255, 255, 255, 0.2); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .collection .collection-item:last-child { border-bottom: none; }
        .collection .collection-item .title { font-weight: 500; }
        .collection .collection-item .grey-text { font-size: 0.8rem; }
        
        .input-field input, .input-field .file-path { color: #fff !important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
        .input-field label { color: #bdbdbd !important; } .input-field label.active { color: #00e5ff !important; }
        .input-field input:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
        
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
    
    <?php if ($action === 'file_manager' && $theUser): // --- FILE MANAGER VIEW --- ?>
        <h3 class="center-align white-text" style="margin-top:30px;">File Manager</h3>
        <p class="center-align grey-text text-lighten-1" style="font-size:1.2rem;"><?= htmlspecialchars($theUser['full_name'] . ' (@' . $theUser['username'] . ')'); ?></p>
        <div class="center-align" style="margin-bottom: 20px;">
             <a href="viewuserfile.php" class="btn-flat waves-effect waves-light white-text"><i class="material-icons left">arrow_back</i>Back to User List</a>
             <a href="viewnotices.php?user_id=<?= $theUser['user_id']; ?>" class="btn waves-effect waves-light" style="background-color: #00bfa5;"><i class="material-icons left">notifications</i>View Notices</a>
        </div>

        <?php if ($feedback_message): ?>
            <div class="message-area <?= $feedback_type === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($feedback_message); ?></div>
        <?php endif; ?>

        <div class="glass-card">
            <h5 class="white-text">User Files</h5>
            <?php if (empty($userFiles)): ?>
                <p class="grey-text">No files have been uploaded for this user yet.</p>
            <?php else: ?>
                <ul class="collection">
                    <?php foreach ($userFiles as $f): ?>
                        <li class="collection-item">
                            <div style="display: flex; align-items: center; flex-grow: 1;">
                                <i class="material-icons circle teal" style="margin-right: 15px;"><?= getFileIcon($f['file_name']); ?></i>
                                <div>
                                    <span class="title"><?= htmlspecialchars($f['file_name']); ?></span>
                                    <p class="grey-text text-lighten-2" style="margin:0;">
                                        Uploaded: <?= date('d-M-Y H:i', strtotime($f['uploaded_at'])); ?> | 
                                        Size: <?= formatBytes(filesize(__DIR__ . '/' . $f['file_path'])); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="secondary-content" style="display: flex; align-items: center; gap: 10px; margin-top: 10px; margin-left: 10px;">
                                <a href="<?= htmlspecialchars($f['file_path']); ?>" target="_blank" class="btn-floating waves-effect waves-light blue" download><i class="material-icons">file_download</i></a>
                                <?php if ($has_admin_rights): ?>
                                    <button class="btn-floating waves-effect waves-light red modal-trigger" data-target="confirmModal" data-fileid="<?= $f['file_id']; ?>" data-filename="<?= htmlspecialchars($f['file_name']); ?>"><i class="material-icons">delete</i></button>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($has_admin_rights): ?>
            <div class="glass-card">
                 <h5 class="white-text">Upload New File</h5>
                <form method="POST" enctype="multipart/form-data" action="viewuserfile.php?action=file_manager&user_id=<?= $theUser['user_id']; ?>">
                    <div class="file-field input-field">
                        <div class="btn" style="background-color: #00bfa5;">
                            <span>Choose File</span>
                            <input type="file" name="the_file" required>
                        </div>
                        <div class="file-path-wrapper">
                            <input class="file-path validate" type="text" placeholder="Select a file to upload (Max 10 MB)">
                        </div>
                    </div>
                    <button type="submit" name="upload_file" class="btn-large waves-effect waves-light" style="background-color: #00bfa5;"><i class="material-icons left">cloud_upload</i>Upload File</button>
                </form>
            </div>
        <?php endif; ?>

    <?php else: // --- MAIN USER LISTING VIEW --- ?>

        <h3 class="center-align white-text" style="margin-top:30px;">User Directory</h3>
        <hr class="white-line">
        
        <?php if ($feedback_message): ?>
            <div class="message-area <?= $feedback_type === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($feedback_message); ?></div>
        <?php endif; ?>

        <div class="glass-card">
            <form method="get">
                <div class="input-field">
                    <i class="material-icons prefix">search</i>
                    <input id="search" type="text" name="search" value="<?= htmlspecialchars($searchTerm); ?>">
                    <label for="search">Search for a user by name or username...</label>
                </div>
                <div class="center-align">
                    <button type="submit" class="btn waves-effect waves-light" style="background-color:#00bfa5;">Search</button>
                    <a href="viewuserfile.php" class="btn waves-effect waves-light grey">Clear</a>
                </div>
            </form>
        </div>

        <?php if (empty($groups)): ?>
             <div class="glass-card center-align">
                <h5 class="white-text">No users found.</h5>
                <?php if (!empty($searchTerm)): ?>
                    <p class="grey-text">Your search for "<?= htmlspecialchars($searchTerm); ?>" did not match any users.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php foreach ($groups as $group): ?>
            <div class="glass-card">
                <h5 class="white-text"><?= htmlspecialchars($group['group_name']); ?></h5>
                <ul class="collection">
                    <?php foreach ($group['users'] as $u): ?>
                        <li class="collection-item">
                            <div>
                                <span class="title"><?= htmlspecialchars($u['full_name']); ?></span>
                                <p class="grey-text text-lighten-2" style="margin:0;">@<?= htmlspecialchars($u['username']); ?></p>
                            </div>
                            <div class="secondary-content" style="margin-top: 10px;">
                                <a href="viewnotices.php?user_id=<?= $u['user_id']; ?>" class="btn-small waves-effect waves-light grey darken-1"><i class="material-icons left">notifications</i>Notices</a>
                                <a href="viewuserfile.php?action=file_manager&user_id=<?= $u['user_id']; ?>" class="btn-small waves-effect waves-light" style="background-color:#00bfa5;"><i class="material-icons left">folder_open</i>Files</a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
<br>

<div id="confirmModal" class="modal">
    <div class="modal-content">
        <h4>Confirm Deletion</h4>
        <p>Are you sure you want to permanently delete the file: <br><strong id="modal-filename"></strong>?</p>
        <p class="grey-text">This action cannot be undone.</p>
    </div>
    <div class="modal-footer">
        <form method="POST" action="viewuserfile.php?action=file_manager&user_id=<?= $user_id; ?>" style="display: inline;">
            <input type="hidden" name="file_id" id="modal-file-id" value="">
            <button type="submit" name="remove_file" class="btn-flat waves-effect waves-light red-text text-lighten-1">Delete File</button>
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
            // Get file data from the button that triggered the modal
            const fileId = trigger.getAttribute('data-fileid');
            const fileName = trigger.getAttribute('data-filename');
            
            // Populate the modal with the file's data
            modal.querySelector('#modal-filename').textContent = fileName;
            modal.querySelector('#modal-file-id').value = fileId;
        }
    });
});
</script>
</body>
</html>