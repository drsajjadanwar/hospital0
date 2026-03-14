<?php
session_start();
require_once __DIR__ . '/includes/config.php';    //  brings in $pdo

// ---------- Require login ----------
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$user      = $_SESSION['user'];
$groupId   = (int)($user['group_id'] ?? 0);
$username  = $user['username'] ?? 'unknown';
$isPoster  = in_array($groupId, [1, 2, 3, 5, 23], true);   // can create notices

/* ----------------------------------------------------------
    1)  Handle submission
    -------------------------------------------------------- */
$message='';
if ($isPoster && $_SERVER['REQUEST_METHOD']==='POST') {
    $title = trim($_POST['notice_title'] ?? '');
    $body  = trim($_POST['notice_body']  ?? '');
    $tuid  = (int)($_POST['target_user_id'] ?? 0);     // 0 = all
    if ($title=='' || $body=='') {
        $message='Error: Please fill in both title and body.';
    } else {
        $tuname = null;
        if ($tuid>0) {
            $q=$pdo->prepare("SELECT username FROM users WHERE user_id=? LIMIT 1");
            $q->execute([$tuid]);
            $tuname = $q->fetchColumn() ?: null;
        }
        /* store */
        $ins=$pdo->prepare("
            INSERT INTO office_notices
              (notice_title, notice_body, issued_by, target_user_id, target_username)
            VALUES (?,?,?,?,?)
        ");
        $ins->execute([$title,$body,$username,$tuid?:null,$tuname]);
        /* optional: also push into user_notices so it shows in viewnotices.php */
        if ($tuid>0) {
            $pdo->prepare("INSERT INTO user_notices
                      (user_id, notice_title, notice_body, created_at)
                      VALUES (?,?,?,NOW())")
                ->execute([$tuid,$title,$body]);
        }
        $message='Success: Office notice issued successfully.';
    }
}

/* ----------------------------------------------------------
    2)  Pagination fetch (10 / page)
    -------------------------------------------------------- */
$per=10; $page=max(1,(int)($_GET['page']??1));
$rows=(int)$pdo->query("SELECT COUNT(*) FROM office_notices")->fetchColumn();
$total=max(1,ceil($rows/$per)); if($page>$total)$page=$total; $off=($page-1)*$per;

$st=$pdo->prepare("
  SELECT notice_title, notice_body, issued_by, created_at,
         COALESCE(target_username,'ALL') AS tgt
  FROM office_notices
  ORDER BY created_at DESC
  LIMIT :lim OFFSET :off
");
$st->bindValue(':lim',$per,\PDO::PARAM_INT);
$st->bindValue(':off',$off,\PDO::PARAM_INT);
$st->execute();
$notices=$st->fetchAll(PDO::FETCH_ASSOC);

/* ---------- fetch all users for dropdown ---------- */
$users=[];
if($isPoster){
  $users=$pdo->query("SELECT user_id,username FROM users ORDER BY username ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>hospital0 - Office Notices — hospital0</title>
<link rel="icon" href="/media/sitelogo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
    /* --- NEW BEAUTIFICATION STYLES --- */
    body {
        background-image: none !important;
        background-color: #121212 !important;
        overflow-x: hidden;
    }
    
    @keyframes move-twink-back { from { background-position: 0 0; } to { background-position: -10000px 5000px; } }
    .stars, .twinkling { position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; display: block; z-index: -3; }
    .stars { background: #000 url(/media/stars.png) repeat top center; }
    .twinkling { background: transparent url(/media/twinkling.png) repeat top center; animation: move-twink-back 200s linear infinite; }
    #dna-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; opacity: 0.3; }

    h3.center-align { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
    .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }
    
    /* Glassmorphism for Form and Cards */
    .glass-card {
        background: rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: 15px;
        padding: 2rem;
        margin-bottom: 2rem;
    }

    .notice-card {
        background: rgba(255, 255, 255, 0.12); /* Slightly more opaque for readability */
        color: #fff;
        padding: 20px;
        margin-bottom: 25px;
        border-radius: 15px;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }
    .notice-card h4 {
        margin-top: 0; margin-bottom: 10px;
        font-weight: 400; color: #00e5ff;
        text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);
    }
    .notice-card p {
        line-height: 1.7; /* Increased line height for readability */
        font-size: 1.05rem;
    }
    .notice-footer {
        margin-top: 20px;
        font-size: 0.9rem;
        color: #bdbdbd; /* Lighter grey for footer */
        border-top: 1px solid rgba(255, 255, 255, 0.2);
        padding-top: 10px;
    }

    /* Form Inputs */
    .input-field input, .materialize-textarea { color:#fff!important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
    .input-field label { color:#bdbdbd!important; }
    .input-field label.active { color:#00e5ff!important; }
    .input-field input:focus, .materialize-textarea:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
    
    ul.dropdown-content { background-color: #2a2a2a; } .dropdown-content li>span { color: #ffffff; }

    /* Messages */
    .message-area { padding: 10px 15px; margin-bottom: 20px; border-radius: 8px; text-align: center; border: 1px solid; }
    .message-area.success { background-color: rgba(76, 175, 80, 0.25); color: #c8e6c9; border-color: rgba(129, 199, 132, 0.5); }
    .message-area.error { background-color: rgba(244, 67, 54, 0.25); color: #ffcdd2; border-color: rgba(239, 154, 154, 0.5); }
    
    /* Pagination */
    .pagination li a{color:#fff;} .pagination .active{background:#00bfa5;}
</style>
</head><body>

<!-- Background Elements -->
<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__.'/includes/header.php'; ?>

<div class="container">
  <h3 class="center-align white-text" style="margin-top:30px;">Office Notices</h3>
  <hr class="white-line">

  <?php if($message): 
    $msg_class = (stripos($message, 'error') !== false) ? 'error' : 'success';
  ?>
    <p class="message-area <?php echo $msg_class; ?>"><?=htmlspecialchars($message)?></p>
  <?php endif;?>

  <?php if($isPoster): ?>
  <div class="glass-card">
      <form method="POST">
        <div class="row">
          <div class="input-field col s12">
            <i class="material-icons prefix">title</i>
            <input id="notice_title" type="text" name="notice_title" required>
            <label for="notice_title">Notice Title</label>
          </div>
        </div>
        <div class="row">
          <div class="input-field col s12">
            <i class="material-icons prefix">article</i>
            <textarea id="notice_body" name="notice_body" class="materialize-textarea" required></textarea>
            <label for="notice_body">Notice Details</label>
          </div>
        </div>
        <div class="row">
          <div class="input-field col s12 m6">
            <i class="material-icons prefix">group</i>
            <select name="target_user_id">
              <option value="0" selected>For ALL users</option>
              <?php foreach($users as $u): ?>
                <option value="<?=$u['user_id']?>"><?=htmlspecialchars($u['username'])?></option>
              <?php endforeach; ?>
            </select>
            <label>Target User (optional)</label>
          </div>
        </div>
        <div class="row center-align">
          <button type="submit" class="btn waves-effect waves-light" style="background-color: #00bfa5;">Post Notice</button>
        </div>
      </form>
  </div>
  <hr class="white-line">
  <?php endif; ?>

  <?php if(!$notices): ?>
    <h5 class="center-align grey-text" style="margin:40px;">No notices found.</h5>
  <?php else: ?>
    <?php foreach($notices as $n): ?>
      <div class="notice-card">
        <h4><?=htmlspecialchars($n['notice_title'])?></h4>
        <?php if($n['tgt']!=='ALL'): ?>
          <p style="font-weight:bold; color: #ffeb3b;">[Private notice for <?=htmlspecialchars($n['tgt']);?>]</p>
        <?php endif; ?>
        <p><?=nl2br(htmlspecialchars($n['notice_body']))?></p>
        <div class="notice-footer">
          Notice issued by <strong><?=htmlspecialchars($n['issued_by'])?></strong>
          at <?=htmlspecialchars(date('d-M-Y H:i', strtotime($n['created_at'])))?> |
          <em>For <?=($n['tgt']==='ALL'?'All Users':htmlspecialchars($n['tgt']))?></em>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if($total>1): ?>
      <ul class="pagination center-align">
        <?php for($p=1;$p<=$total;$p++): ?>
          <li class="<?=($p==$page)?'active':'waves-effect';?>">
            <a href="?page=<?=$p?>"><?=$p?></a>
          </li>
        <?php endfor;?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php include_once __DIR__.'/includes/footer.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script type="module">
    // DNA Helix animation code... (same as other pages)
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
  M.FormSelect.init(document.querySelectorAll('select'));
  const noticeBody = document.getElementById('notice_body');
  if (noticeBody) {
      M.textareaAutoResize(noticeBody);
  }
});
</script>
</body></html>