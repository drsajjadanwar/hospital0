<?php
/* /public/dental.php  — 31 May 2025 (patched 27 May 2025)
 * Senior Dental Surgeon Portal – groups 1 (CMO) & 3 (Dentists)
 * COMPLETE, READY-TO-DEPLOY FILE  – copy/paste as-is.
 * --------------------------------------------------------------------------- */

// ───────────────────────────── 0 BOOTSTRAP ─────────────────────────────
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/includes/config.php';   // sets $pdo (PDO::ATTR_ERRMODE = EXCEPTION)
require_once __DIR__ . '/includes/fpdf.php';
require_once __DIR__ . '/includes/code128.php';

date_default_timezone_set('Asia/Karachi');

/* ───────────────────── 0  AUTHORISATION ───────────────────── */
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
$user = $_SESSION['user'];     $uid = (int)$user['user_id']; $gid = (int)$user['group_id'];
if (!in_array($gid,[1,3],true)) {
    include __DIR__.'/includes/header.php';
    echo '<div class="container center-align" style="padding-top:60px"><h4 class="red-text">Access denied</h4></div>';
    include __DIR__.'/includes/footer.php';
    exit;
}

/* ───────────────────── 1  HELPERS ───────────────────── */
/* dental.mrn is lower-case in the table */
function nextSessionNumber(PDO $pdo,string $mrn):int {
    $st = $pdo->prepare('SELECT COALESCE(MAX(session_number),0)+1 FROM dental WHERE mrn=?');
    $st->execute([$mrn]);
    return (int)$st->fetchColumn();
}
function latestVisitNumber(PDO $pdo,string $mrn):int {
    $st=$pdo->prepare('SELECT COALESCE(MAX(visit_number),1) FROM patientregister WHERE MRN=?');
    $st->execute([$mrn]); return (int)$st->fetchColumn();
}

/* ───────────────────── 2  PDF GENERATION ───────────────────── */
class DentalPDF extends PDF_Code128 {
    function __construct(){
        parent::__construct('P','mm','A4');
        $this->SetMargins(15,22,15);
        $this->SetAutoPageBreak(true,25);
    }
    function Header(){}
    function Footer(){
        $this->SetY(-20);
        $this->SetFont('Helvetica','',10);
        $this->SetTextColor(120,120,120);
        $this->Cell(0,6,'hospital0',0,0,'C');
    }
}
/**
 * Generate & store discharge-summary PDF.
 * Returns absolute file-system path on success, null on failure.
 */
function generateDentalPDF(PDO $pdo,string $mrn,int $sess):?string {
    $q=$pdo->prepare('SELECT d.*,p.full_name FROM dental d JOIN patients p ON p.mrn=d.mrn WHERE d.mrn=? AND d.session_number=? LIMIT 1');
    $q->execute([$mrn,$sess]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row){ error_log("PDF-gen: session not found for $mrn/$sess"); return null; }

    $outDir=__DIR__.'/dental/';
    if(!is_dir($outDir)&&!mkdir($outDir,0777,true)&&!is_dir($outDir)){
        error_log("PDF-gen: cannot create $outDir"); return null;
    }
    $safeName=preg_replace('/[^a-zA-Z0-9_\-]/','_',$row['full_name']);
    $safeMrn =preg_replace('/\D/','',$mrn);
    $file    ="{$safeName}_{$safeMrn}_S{$sess}.pdf";
    $absPath =$outDir.$file;     // filesystem
    $relPath ='dental/'.$file;   // URL / DB

    $pdf=new DentalPDF();
    $pdf->SetTitle('Dental Discharge Summary',true);
    $pdf->AddPage();

    $pdf->SetFont('Helvetica','',20);
    $pdf->Cell(0,10,'hospital0',0,1,'C');
    $pdf->SetFont('Helvetica','',14);
    $pdf->Cell(0,8,'Dental Discharge Summary',0,1,'C');
    $pdf->Ln(4);

    $barcode="DENTAL-{$safeMrn}-S{$sess}";
    $pdf->Code128(($pdf->GetPageWidth()-60)/2,$pdf->GetY(),$barcode,60,10);
    $pdf->Ln(16);

    $pdf->SetFont('Helvetica','',11);
    $labels=[
        'Patient'        =>$row['full_name'],
        'MRN'            =>$mrn,
        'Session'        =>$sess,
        'Visit #'        =>$row['visit_number'],
        'Created'        =>date('d-M-Y H:i',strtotime($row['created_at'])),
        'Provisional Dx' =>$row['provisional_dx'],
        'Diagnosis'      =>$row['diagnosis'],
        'Treatment Done' =>$row['treatment_done_today']
    ];
    foreach($labels as $lbl=>$val){
        $pdf->SetFont('Helvetica','B',11);
        $pdf->Cell(40,6,"$lbl:",0,0);
        $pdf->SetFont('Helvetica','',11);
        $pdf->MultiCell(0,6,($val??'-')===''?'-':$val,0,'L');
    }
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica','B',11);
    $pdf->MultiCell(0,6,'Discharge Summary:',0,1);
    $pdf->SetFont('Helvetica','',11);
    $pdf->MultiCell(0,6,($row['discharge_summary']??'-')?:'-',0,'L');

    try{
        $pdf->Output('F',$absPath);           // store on disk
    }catch(Throwable $e){
        error_log('PDF-gen Output error: '.$e->getMessage());
        return null;
    }
    $pdo->prepare('UPDATE dental SET pdf_path=? WHERE mrn=? AND session_number=?')
        ->execute([$relPath,$mrn,$sess]);
    return $absPath;
}

/* ───────────────────── 3  ROUTER / LOGIC ───────────────────── */
$action = $_GET['action'] ?? '';
$msg    = '';
$edit = null; // Initialize $edit
$view = null; // Initialize $view
$rows = [];   // Initialize $rows

// 3.a AJAX MRN Lookup
if($action==='lookup_mrn'){
    header('Content-Type: application/json');
    $mrn=trim($_GET['mrn']??'');
    if(!$mrn){ echo json_encode(['success'=>false,'message'=>'MRN required.']); exit; }
    $p=$pdo->prepare('SELECT full_name,gender,age,age_unit FROM patients WHERE mrn=? LIMIT 1');
    $p->execute([$mrn]); $pat=$p->fetch(PDO::FETCH_ASSOC);
    if(!$pat){ echo json_encode(['success'=>false,'message'=>'Patient not found.']); exit; }
    $pat['latest_visit']=latestVisitNumber($pdo,$mrn);
    echo json_encode(['success'=>true]+$pat); exit;
}

// 3.b AJAX Signature Save
if($action==='save_signature' && $_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    $img=$_POST['img']??'';
    if(!$img||!preg_match('/^data:image\/\w+;base64,/', $img)){
        echo json_encode(['ok'=>false,'message'=>'Invalid image data.']); exit;
    }
    $dir=__DIR__.'/dental/signatures/';
    if(!is_dir($dir)&&!mkdir($dir,0777,true)&&!is_dir($dir)){
        echo json_encode(['ok'=>false,'message'=>'Failed to create signature directory.']); exit;
    }
    $name=bin2hex(random_bytes(8)).'.png';
    $decodedImg=base64_decode(preg_replace('#^data:image/\w+;base64,#i','',$img));
    if(file_put_contents($dir.$name,$decodedImg)===false){
        echo json_encode(['ok'=>false,'message'=>'Failed to save signature file.']); exit;
    }
    echo json_encode(['ok'=>true,'path'=>'dental/signatures/'.$name]); exit;
}

// 3.c CREATE Session (POST → DB insert)
if($action==='create' && $_SERVER['REQUEST_METHOD']==='POST'){
    $mrn = trim($_POST['mrn'] ?? '');
    if($mrn===''){ $msg='Error – MRN required.'; goto render; }

    $stmtChk = $pdo->prepare('SELECT 1 FROM patients WHERE mrn=? LIMIT 1');
    $stmtChk->execute([$mrn]);
    if (!$stmtChk->fetchColumn()) {
        $msg = 'Error – Patient not found with this MRN.';
        goto render;
    }
      
    $sess  = nextSessionNumber($pdo,$mrn);
    $visit = latestVisitNumber($pdo,$mrn);

    $columns=[
      'mrn','session_number','visit_number','session_name',
      'chief_complaint','chief_complaint_history','other_complaints',
      'dentist_visit_frequency','attendance_type','brush_frequency','previous_dental_tx',
      'jaw_problems','parafunctional_habits',
      'mh_fit_well','mh_medications','mh_medications_detail',
      'mh_allergies','mh_allergies_detail','mh_family_history','mh_family_history_detail',
      'mh_cardio_resp_eye','mh_cardio_detail','mh_pregnancy','mh_pregnancy_detail',
      'mh_menstrual_normal','mh_menstrual_detail',
      'sh_smoker','sh_smoker_detail','sh_alcohol','sh_alcohol_detail',
      'sh_diet','sh_diet_detail','sh_stress','sh_stress_detail',
      'sh_occupation','sh_occupation_detail',
      'notes_expectations','notes_constraints',
      'provisional_dx','investigations','incidental_findings','diagnosis','treatment_planned',
      'consent_text','consent_signature_path','consent_signed_by','consent_signed_at',
      'treatment_done_today','discharge_summary','is_locked','locked_by','locked_at','pdf_path','created_by'
    ];
    $ph = rtrim(str_repeat('?,',count($columns)),',');

    $g  = fn($k)=>($_POST[$k]??'')!=='' ? trim($_POST[$k]) : null;
    $yn = fn($k)=>isset($_POST[$k]) ? ($_POST[$k]==='yes' ? 1 : ($_POST[$k]==='no'?0:null)) : null;

    $attendanceType=$g('attendance_type');
    if($attendanceType!==null && !in_array($attendanceType,['symptomatic','asymptomatic'])) $attendanceType=null;

    $sigPath=$g('sig_path');

    $values=[
      $mrn,$sess,$visit,$g('session_name'),
      $g('chief_complaint'),$g('chief_complaint_history'),$g('other_complaints'),
      $g('dentist_visit_frequency'),$attendanceType,$g('brush_frequency'),$g('previous_dental_tx'),
      $g('jaw_problems'),$g('parafunctional_habits'),
      $yn('mh_fit_well'),$yn('mh_medications'),$g('mh_medications_detail'),
      $yn('mh_allergies'),$g('mh_allergies_detail'),$yn('mh_family_history'),$g('mh_family_history_detail'),
      $yn('mh_cardio_resp_eye'),$g('mh_cardio_detail'),$yn('mh_pregnancy'),$g('mh_pregnancy_detail'),
      $yn('mh_menstrual_normal'),$g('mh_menstrual_detail'),
      $yn('sh_smoker'),$g('sh_smoker_detail'),$yn('sh_alcohol'),$g('sh_alcohol_detail'),
      $yn('sh_diet'),$g('sh_diet_detail'),$yn('sh_stress'),$g('sh_stress_detail'),
      $yn('sh_occupation'),$g('sh_occupation_detail'),
      $g('notes_expectations'),$g('notes_constraints'),
      $g('provisional_dx'),$g('investigations'),$g('incidental_findings'),$g('diagnosis'),$g('treatment_planned'),
      $g('consent_text'),$sigPath,$sigPath?$uid:null,$sigPath?date('Y-m-d H:i:s'):null,
      $g('treatment_done_today'),null,0,null,null,null,$uid
    ];



    try{
        $pdo->prepare('INSERT INTO dental ('.implode(',',$columns).") VALUES ($ph)")->execute($values);
        header("Location: dental.php?action=view&mrn=$mrn&session=$sess&status=created"); exit;
    }catch(Exception $e){ $msg='Database error: '.$e->getMessage(); }
}

// 3.d  EDIT (open / modify until locked)
if ($action === 'edit') {

    /* -------------------------------------------------- 1  parameters */
    $mrn_param  = $_GET['mrn']  ?? '';
    $sess_param = (int)($_GET['session'] ?? 0);
    if (!$mrn_param || !$sess_param) { $msg = 'Missing parameters for edit.'; goto render; }

    /* ------------------------------------------- 2  GET → show form  */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $stmt = $pdo->prepare(
            // Corrected Query: Select age, age_unit, gender from patients table (p)
            'SELECT d.*, p.full_name AS patient_name, p.age, p.age_unit, p.gender
               FROM dental d
               JOIN patients p ON p.mrn = d.mrn
               LEFT JOIN patientregister pr ON pr.mrn = d.mrn AND pr.visit_number = d.visit_number
              WHERE d.mrn = ? AND d.session_number = ? AND d.is_locked = 0');
        // This execute() call was line 246 in the previous version
        $stmt->execute([$mrn_param, $sess_param]);
        $edit = $stmt->fetch(PDO::FETCH_ASSOC); 

        if (!$edit) {
            $msg    = "Session either not found or already locked.";
        }
        goto render;                  
    }

    /* ------------------------------------------- 3  POST → save       */
    // For POST, $mrn_param and $sess_param are from the GET params in the form's action URL
    $g  = fn($k) => ($_POST[$k] ?? '') !== '' ? trim($_POST[$k]) : null;
    $yn = fn($k) => isset($_POST[$k])
                      ? ($_POST[$k] === 'yes' ? 1
                          : ($_POST[$k] === 'no' ? 0 : null))
                      : null;

    $textCols = [
        'session_name','chief_complaint','chief_complaint_history','other_complaints',
        'dentist_visit_frequency','attendance_type','brush_frequency',
        'previous_dental_tx','jaw_problems','parafunctional_habits',
        'notes_expectations','notes_constraints','provisional_dx',
        'investigations','incidental_findings','diagnosis',
        'treatment_planned','treatment_done_today',
        'mh_medications_detail','mh_allergies_detail'
    ];
    $yesNoCols = ['mh_fit_well','mh_medications','mh_allergies']; // Add other Y/N fields if they are editable

    $set  = [];
    $vals = [];
    foreach ($textCols as $c) { $set[] = "$c = ?"; $vals[] = $g($c); }
    foreach ($yesNoCols as $c) { $set[] = "$c = ?"; $vals[] = $yn($c); }

    $vals[] = $mrn_param;   // WHERE mrn = ?
    $vals[] = $sess_param;  //       AND session_number = ?

    try {
        $update_stmt = $pdo->prepare('UPDATE dental SET ' . implode(',', $set) .
                         ' WHERE mrn = ? AND session_number = ? AND is_locked = 0');
        $update_stmt->execute($vals);

        if ($update_stmt->rowCount() > 0) {
            header("Location: dental.php?action=view&mrn=$mrn_param&session=$sess_param&status=updated");
            exit;
        } else {
            // Check if it was not found/locked or if no data actually changed
            $chkStmt = $pdo->prepare('SELECT 1 FROM dental WHERE mrn=? AND session_number=? AND is_locked=0');
            $chkStmt->execute([$mrn_param, $sess_param]);
            if (!$chkStmt->fetchColumn()) {
                 $msg = 'Update failed: Session may be locked, not found, or no new data provided.';
            } else {
                 // No rows affected, but record exists and is not locked - likely no data changed
                 header("Location: dental.php?action=view&mrn=$mrn_param&session=$sess_param&status=nochanges");
                 exit;
            }
        }
    } catch (Throwable $e) {
        $msg = 'Database error during update: ' . $e->getMessage();
    }
    // If we reach here due to an error or failed update without redirect
    // We might need to re-fetch $edit data to show the form with current values + error message
    $stmt = $pdo->prepare(
        'SELECT d.*, p.full_name AS patient_name , pr.age , pr.age_unit , pr.gender
           FROM dental   d
           JOIN patients p ON p.mrn = d.mrn
           LEFT JOIN patientregister pr ON pr.mrn = d.mrn AND pr.visit_number = d.visit_number
          WHERE d.mrn = ? AND d.session_number = ? AND d.is_locked = 0');
    $stmt->execute([$mrn_param, $sess_param]);
    $edit = $stmt->fetch(PDO::FETCH_ASSOC);
    goto render;
}   // ← END 3.d EDIT

// 3.e SIGN & LOCK
if($action==='sign'){
    $mrn_param=$_GET['mrn']??''; $sess_param=(int)($_GET['session']??0); // Use distinct param names
    if(!$mrn_param||!$sess_param){ $msg='Invalid parameters for sign.'; goto render; }

    // Fetch view data first, as it's needed for the form display logic and rendering, even if POST fails
    $stmt = $pdo->prepare(
        'SELECT d.*, p.full_name,
                us1.full_name AS consent_by_name,
                us2.full_name AS locked_by_name
           FROM dental d
           JOIN patients  p  ON p.MRN = d.mrn
      LEFT JOIN users    us1 ON us1.user_id = d.consent_signed_by
      LEFT JOIN users    us2 ON us2.user_id = d.locked_by
          WHERE d.mrn = ? AND d.session_number = ?');
    $stmt->execute([$mrn_param, $sess_param]);
    $view = $stmt->fetch(PDO::FETCH_ASSOC); // $view is populated

    if (!$view) {
        $msg = 'Error: Session not found for signing.';
        goto render;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($view['is_locked']) {
            $msg = 'Error: Session is already locked.';
            $showSignForm = true; // To re-show modal info if needed
            goto render;
        }
        $summary = trim($_POST['discharge_summary'] ?? '');   //  <= “discharge” not “charge”
        if (function_exists('mb_strlen') && mb_strlen($summary) > 65000) { // Check mb_strlen exists
            $msg = 'Error – Discharge summary too long (max 65000 chars).';
            $showSignForm = true;
            goto render;
        } elseif (!function_exists('mb_strlen') && strlen($summary) > 65000) { // Fallback if no mbstring
             $msg = 'Error – Discharge summary too long (max 65000 bytes). mbstring extension recommended.';
             $showSignForm = true;
             goto render;
        }

        $u = $pdo->prepare('UPDATE dental SET discharge_summary=?, is_locked=1, locked_by=?, locked_at=NOW() WHERE mrn=? AND session_number=? AND is_locked=0');
        $u->execute([$summary, $uid, $mrn_param, $sess_param]);
    
        if ($u->rowCount()) {
            generateDentalPDF($pdo, $mrn_param, $sess_param);
            header("Location: dental.php?action=view&mrn=$mrn_param&session=$sess_param&status=signed");
            exit;
        } else {
            $msg = 'Error: Could not lock session. It might have been locked by another user or not found.';
            $showSignForm = true; // To re-show modal
            // Re-fetch $view to get the absolute latest status
            $stmt->execute([$mrn_param, $sess_param]);
            $view = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } else { // GET request for sign, or if POST failed and needs to re-render
        if (!$view['is_locked']) {
            $showSignForm = true;
        }
    }
    goto render;
}

/* ---------- 3.f  VIEW single session (direct) ---------- */
if ($action === 'view') {
    $mrn_param  = $_GET['mrn']  ?? '';
    $sess_param = (int)($_GET['session'] ?? 0);
    if (!$mrn_param || !$sess_param) { $msg = 'Missing parameters for view.'; goto render; }

    $stmt = $pdo->prepare(
        'SELECT d.*, p.full_name,
                us1.full_name AS consent_by_name,
                us2.full_name AS locked_by_name
           FROM dental d
           JOIN patients  p  ON p.MRN = d.mrn
      LEFT JOIN users    us1 ON us1.user_id = d.consent_signed_by
      LEFT JOIN users    us2 ON us2.user_id = d.locked_by
          WHERE d.mrn = ? AND d.session_number = ?');
    $stmt->execute([$mrn_param, $sess_param]);
    $view = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$view) { $msg = 'Session not found.'; }
    goto render;
}

/* ---------- 3.g  RETRIEVE list ---------- */
if ($action === 'retrieve') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {           // allow plain GET too
        $showRetrieveForm = true;
        goto render;
    }

    $mrn_param = trim($_POST['mrn'] ?? ''); // Use distinct param name
    if (!$mrn_param) { $msg = 'Enter MRN.'; $showRetrieveForm = true; goto render; }

    $stmt = $pdo->prepare(
    'SELECT d.session_number,
            d.session_name,
            d.created_at,
            d.is_locked,
            p.full_name
       FROM dental d
       JOIN patients p ON p.mrn = d.mrn
      WHERE d.mrn = ?
   ORDER BY d.session_number DESC');
    $stmt->execute([$mrn_param]);
    $rows           = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $retrievedMrn = $mrn_param; // Use the fetched MRN for display
    goto render;
}

/* ---------- 3.h  SHOW all sessions (paginated) ---------- */
if ($action === 'show') {
    $page       = max(1, (int)($_GET['page'] ?? 1));
    $perPage    = 10;
    $offset     = ($page - 1) * $perPage;

    $total         = $pdo->query('SELECT COUNT(*) FROM dental')->fetchColumn();
    $totalPages    = max(1, ceil($total / $perPage));
    if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

    if ($total > 0) { // Only query if there's data
        $rows = $pdo->query("
            SELECT d.mrn,
                   d.session_number,
                   d.created_at,
                   d.is_locked,
                   p.full_name
              FROM dental d
              JOIN patients p ON p.MRN = d.mrn
          ORDER BY d.created_at DESC
             LIMIT $perPage OFFSET $offset")
            ->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = [];
    }


    $showAll = true;
    $tp      = $totalPages;
    goto render;
}

/* ---------- fall-through → main menu ---------- */
render:
/* flash-message helper */
$status = $_GET['status'] ?? '';
if ($status === 'created') $msg = 'Session created successfully.';
if ($status === 'signed')  $msg = 'Session signed & locked successfully.';
if ($status === 'updated') $msg = 'Session updated successfully.';
if ($status === 'nochanges') $msg = 'No changes were made to the session.';


/* ─────────────────────  HTML OUTPUT  ───────────────────── */
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>hospital0 - Senior Dental Surgeon Portal</title>
<link rel="icon" href="/media/sitelogo.png" type="image/png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
html,body{max-width:100%;overflow-x:hidden}
.white-line{width:50%;height:2px;background:#fff;border:none;margin:20px auto}
input[type=text]:not(.browser-default):focus:not([readonly]),
input[type=text]:not(.browser-default),
input[readonly],textarea.materialize-textarea:focus:not([readonly]),
textarea.materialize-textarea{color:#fff!important;background:transparent!important;border-bottom:1px solid #fff!important;box-shadow:0 1px 0 0 #fff!important}
label{color:#9e9e9e!important} label.active{color:#fff!important}
.input-field .prefix.active{color:#fff!important}
hr.divider{border:none;height:2px;background:rgba(255,255,255,0.3);width:80%;margin:30px auto}
.sidenav{width:260px;background:#000!important}.sidenav a{color:#fff!important}.sidenav i{color:#fff!important}
.modal{background-color:#333!important;color:white}.modal .modal-content{color:white}
.modal .modal-footer{background-color:#333!important}.btn-flat{color:#FFF!important}
.btn-flat:focus{background-color:rgba(255,255,255,0.1)!important}
select.browser-default { background-color: #333 !important; color: white !important; border: 1px solid #555 !important; border-radius: 2px; height: 3rem; }
</style></head><body>
<?php include_once __DIR__.'/includes/header.php'; ?>
<ul id="nav" class="sidenav">
  <li><a href="dental.php"><i class="material-icons">home</i>Home</a></li>
  <li><a href="?action=create"><i class="material-icons">add_circle_outline</i>Add New Session</a></li>
  <li><a href="?action=retrieve"><i class="material-icons">search</i>Retrieve Session</a></li>
  <li><a href="?action=show"><i class="material-icons">list_alt</i>Show All Sessions</a></li>
</ul>
<a href="#" data-target="nav" class="sidenav-trigger white-text" style="position:fixed;top:15px;right:15px;z-index:900"><i class="material-icons">menu</i></a>
<div class="container" style="margin-top:70px">
<h4 class="center-align white-text">Senior Dental Surgeon’s Portal</h4>
<hr class="white-line">
<?php if($msg):?>
  <div class="card-panel <?=stripos($msg,'error')!==false||stripos($msg,'failed')!==false||stripos($msg,'not found')!==false?'red lighten-1':'green lighten-1'?> white-text center"><?=htmlspecialchars($msg)?></div>
<?php endif;?>

<?php if($action===''):?>
  <div class="row center" style="margin-top:40px">
    <a class="btn blue waves-effect waves-light"  style="width:260px;margin:8px" href="?action=create">Add New Session</a><br>
    <a class="btn waves-effect waves-light"       style="width:260px;margin:8px" href="?action=retrieve">Retrieve Session</a><br>
    <a class="btn grey waves-effect waves-light"   style="width:260px;margin:8px" href="?action=show">Show All Sessions</a>
  </div>
<?php endif;?>

<?php if(isset($showRetrieveForm) && $action==='retrieve' && empty($retrievedMrn)):?>
  <h5 class="white-text center">Retrieve Dental Sessions</h5>
  <form method="POST" action="?action=retrieve">
    <div class="row">
        <div class="input-field col s12 m8 offset-m2">
            <input id="retrieve_mrn" name="mrn" type="text" class="white-text validate" required>
            <label for="retrieve_mrn">Enter Patient MRN</label>
        </div>
    </div>
    <div class="row center">
        <button type="submit" class="btn waves-effect waves-light">Search Sessions</button>
        <a href="dental.php" class="btn grey waves-effect waves-light">Cancel</a>
    </div>
  </form>
<?php elseif(isset($retrievedMrn) && $action==='retrieve'):?>
  <h5 class="white-text center">Sessions for MRN: <?=htmlspecialchars($retrievedMrn)?></h5>
  <?php if(empty($rows)):?><p class="white-text center">No dental sessions found for this MRN.</p>
  <?php else:?>
    <table class="striped responsive-table white-text">
      <thead><tr><th>#</th><th>Name</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody>
      <?php foreach($rows as $r):?>
      <tr>
        <td><?=$r['session_number']?></td>
        <td><?=htmlspecialchars($r['session_name']?:'-')?></td>
        <td><?=date('d-M-Y H:i',strtotime($r['created_at']))?></td>
        <td><?=$r['is_locked']?'Locked':'Open'?></td>
        <td>
          <?php if($r['is_locked']):?>
            <a class="btn-small blue" href="?action=view&mrn=<?=$retrievedMrn?>&session=<?=$r['session_number']?>">View</a>
          <?php else:?>
            <a class="btn-small green" href="?action=edit&mrn=<?=$retrievedMrn?>&session=<?=$r['session_number']?>">Open / Edit</a>
          <?php endif;?>
        </td>
      </tr>
      <?php endforeach;?>
      </tbody></table>
  <?php endif;?>
  <div class="row center" style="margin-top: 20px;">
    <a href="?action=retrieve" class="btn waves-effect waves-light">Search Another MRN</a>
    <a href="dental.php" class="btn grey waves-effect waves-light">Back to Menu</a>
  </div>
<?php endif;?>

<?php if(isset($showAll)&&$action==='show'):?>
  <h5 class="white-text center">All Dental Sessions (latest first)</h5>
  <?php if(empty($rows)):?><p class="center white-text">No dental sessions found.</p>
  <?php else:?>
  <table class="striped responsive-table white-text"><thead>
    <tr><th>#</th><th>MRN</th><th>Patient Name</th><th>Date</th><th>Status</th><th>Action</th></tr>
  </thead><tbody>
  <?php foreach($rows as $r):?>
    <tr>
      <td><?=$r['session_number']?></td>
      <td><?=$r['mrn']?></td>
      <td><?=htmlspecialchars($r['full_name'])?></td>
      <td><?=date('d-M-Y H:i',strtotime($r['created_at']))?></td>
      <td><?=$r['is_locked']?'Locked':'Open'?></td>
      <td>
        <?php if($r['is_locked']):?>
            <a class="btn-small blue" href="?action=view&mrn=<?=$r['mrn']?>&session=<?=$r['session_number']?>">View</a>
        <?php else:?>
            <a class="btn-small green" href="?action=edit&mrn=<?=$r['mrn']?>&session=<?=$r['session_number']?>">Open / Edit</a>
        <?php endif;?>
      </td>
    </tr>
  <?php endforeach;?>
  </tbody></table>
    <?php if ($tp > 1): ?>
    <ul class="pagination center">
        <li class="<?= $page <= 1 ? 'disabled' : 'waves-effect' ?>">
            <a href="<?= $page <= 1 ? '#!' : "?action=show&page=".($page-1) ?>"><i class="material-icons">chevron_left</i></a>
        </li>
        <?php for ($i = 1; $i <= $tp; $i++): ?>
            <li class="<?= $i == $page ? 'active blue' : 'waves-effect' ?>">
                <a href="?action=show&page=<?= $i ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
        <li class="<?= $page >= $tp ? 'disabled' : 'waves-effect' ?>">
            <a href="<?= $page >= $tp ? '#!' : "?action=show&page=".($page+1) ?>"><i class="material-icons">chevron_right</i></a>
        </li>
    </ul>
    <?php endif; ?>
  <?php endif;?>
   <div class="row center" style="margin-top: 20px;">
    <a href="dental.php" class="btn grey waves-effect waves-light">Back to Menu</a>
  </div>
<?php endif;?>

<?php if(isset($view)&&($action==='view'||$action==='sign')):?>
  <div class="card-panel grey darken-3 white-text">
    <h5>Patient: <?=htmlspecialchars($view['full_name'] ?? 'N/A')?> (MRN: <?=htmlspecialchars($view['mrn'] ?? 'N/A')?>)</h5>
    <p><strong>Session #:</strong> <?=$view['session_number'] ?? 'N/A'?>&nbsp;&nbsp;&nbsp;
        <strong>Visit #:</strong> <?=$view['visit_number'] ?? 'N/A'?></p>
    <p><strong>Session Name:</strong> <?=htmlspecialchars($view['session_name']?:'-')?></p>
    <p><strong>Created:</strong> <?= isset($view['created_at']) ? date('d-M-Y H:i',strtotime($view['created_at'])) : 'N/A'?>
        by User ID <?= $view['created_by'] ?? 'N/A' ?></p>
    <p><strong>Status:</strong>
        <?= isset($view['is_locked']) && $view['is_locked']
            ?'<span style="color:lightgreen;font-weight:bold;">Locked</span> (Signed by '.htmlspecialchars($view['locked_by_name']??'Unknown').')'
            :'<span style="color:yellow;font-weight:bold;">Open / Editable</span>'?>
    </p>

    <?php if(!empty($view['provisional_dx'])): ?> <p><strong>Provisional Diagnosis:</strong> <?=nl2br(htmlspecialchars($view['provisional_dx']))?></p> <?php endif; ?>
    <?php if(!empty($view['diagnosis'])): ?> <p><strong>Diagnosis:</strong> <?=nl2br(htmlspecialchars($view['diagnosis']))?></p> <?php endif; ?>
    <?php if(!empty($view['treatment_done_today'])): ?> <p><strong>Treatment Done:</strong> <?=nl2br(htmlspecialchars($view['treatment_done_today']))?></p> <?php endif; ?>


    <?php if($view['consent_text']):?>
      <hr style="border-color:rgba(255,255,255,.2)">
      <p><strong>Consent Text:</strong><br><?=nl2br(htmlspecialchars($view['consent_text']))?></p>
        <?php if($view['consent_signature_path']):?>
          <p><strong>Signature:</strong>
            <img src="/<?=htmlspecialchars($view['consent_signature_path'])?>" style="max-width:200px;height:auto;border:1px solid #555;background:white;">
          </p>
          <p>(Signed by <?=htmlspecialchars($view['consent_by_name']??'Unknown')?> on
              <?= isset($view['consent_signed_at']) ? date('d-M-Y H:i',strtotime($view['consent_signed_at'])) : 'N/A' ?>)</p>
        <?php else:?>
          <p><strong>Signature:</strong> Not provided.</p>
        <?php endif;?>
    <?php endif;?>

    <?php if($view['is_locked']&&$view['discharge_summary']):?>
      <hr style="border-color:rgba(255,255,255,.2);margin-top:20px;">
      <p style="font-size:1.2em;font-weight:bold;">Discharge Summary:</p>
      <p><?=nl2br(htmlspecialchars($view['discharge_summary']))?></p>
    <?php endif;?>
  </div>
  <div class="row center" style="margin-top:20px;">
    <?php if(!$view['is_locked']):?>
      <a class="btn green modal-trigger" href="#signModal">Sign & Lock Session</a>
      <a class="btn orange" href="?action=edit&mrn=<?=$view['mrn']?>&session=<?=$view['session_number']?>">Open / Edit</a>
    <?php endif;?>
    <?php if($view['pdf_path']):?>
        <a class="btn purple" href="/<?=htmlspecialchars($view['pdf_path'])?>" target="_blank" download>Download PDF</a>
    <?php endif;?>
    <a href="dental.php" class="btn grey">Back to Menu</a>
  </div>
  
  <?php if(!$view['is_locked']): ?>
  <div id="signModal" class="modal">
    <form method="POST" action="?action=sign&mrn=<?=htmlspecialchars($view['mrn'])?>&session=<?=htmlspecialchars($view['session_number'])?>">
        <div class="modal-content">
            <h4>Sign & Lock Dental Session</h4>
            <p>Patient: <?=htmlspecialchars($view['full_name'])?> (MRN: <?=htmlspecialchars($view['mrn'])?>) - Session #<?=$view['session_number']?></p>
            <div class="input-field">
                <textarea id="discharge_summary" name="discharge_summary" class="materialize-textarea white-text" required data-length="65000"><?=htmlspecialchars($view['discharge_summary'] ?? '')?></textarea>
                <label for="discharge_summary" class="<?=!empty($view['discharge_summary'])?'active':''?>">Discharge Summary (Required)</label>
            </div>
            <p>By clicking "Confirm & Lock", you are digitally signing this session. It cannot be edited further.</p>
        </div>
        <div class="modal-footer">
            <a href="#!" class="modal-close waves-effect waves-grey btn-flat">Cancel</a>
            <button type="submit" class="btn waves-effect waves-green blue white-text">Confirm & Lock</button>
        </div>
    </form>
  </div>
  <?php endif; ?>

<?php endif;?>

<?php
if(($action==='create'&&$_SERVER['REQUEST_METHOD']!=='POST')
    || (isset($edit)&&$action==='edit')){ // $edit variable is key for showing edit form
    $isEdit = isset($edit) && $edit !== false; // Ensure $edit is not false from a failed fetch
    $frm = $isEdit ? $edit : [];  // array of values for prefilling

    // If $isEdit is true, MRN, session etc. are from $edit (which got them from $mrn_param, $sess_param)
    // If create mode, these will be empty or from $_POST if form re-submission with error.
    $form_action_mrn = $isEdit ? htmlspecialchars($edit['mrn']) : '';
    $form_action_session = $isEdit ? htmlspecialchars($edit['session_number']) : '';
?>
<h5 class="white-text"><?= $isEdit ? 'Edit Dental Session' : 'Add New Dental Session' ?></h5>
<form method="POST"
      action="?action=<?= $isEdit?'edit':'create' ?><?= $isEdit?"&mrn=".$form_action_mrn."&session=".$form_action_session:'' ?>"
      id="createDentalSessionForm">
    <div class="row">
        <div class="input-field col s12 m4">
            <input id="mrn" name="mrn" type="text"
                   <?= $isEdit?'readonly':'' ?>
                   value="<?=htmlspecialchars($frm['mrn']?? ($_POST['mrn'] ?? ''))?>"
                   onblur="if(!this.readOnly) lookupMRN();" class="validate white-text" required>
            <label class="<?= ($isEdit || !empty($_POST['mrn']))?'active':'' ?>" for="mrn">Patient MRN *</label>
        </div>
        <div class="input-field col s12 m5">
            <input id="full_name" name="patient_name_display" type="text" readonly
                   value="<?=htmlspecialchars($frm['patient_name']??'')?>">
            <label class="active">Full Name</label>
        </div>
        <div class="input-field col s6 m1">
            <input id="age" name="age_display" type="text" readonly value="<?=htmlspecialchars($frm['age']??'') . (!empty($frm['age_unit']) ? ' '.htmlspecialchars($frm['age_unit']) : '')?>">
            <label class="active">Age</label>
        </div>
        <div class="input-field col s6 m2">
            <input id="gender" name="gender_display" type="text" readonly value="<?=htmlspecialchars($frm['gender']??'')?>">
            <label class="active">Gender</label>
        </div>
    </div>
    
    <div class="row">
      <div class="input-field col s12 m4">
          <input id="latest_visit" name="visit_number_display" type="text" readonly
                 value="<?= htmlspecialchars($frm['visit_number'] ?? '') ?>">
          <label class="active">Patient Visit #</label>
      </div>
      <div class="input-field col s12 m8">
          <input name="session_name" type="text" class="white-text" data-length="128"
                 value="<?= htmlspecialchars($frm['session_name'] ?? ($_POST['session_name'] ?? '')) ?>">
          <label class="<?= (isset($frm['session_name']) || !empty($_POST['session_name']))?'active':'' ?>">
             Session Name / Title (e.g. RCT Session 1, Consultation)
          </label>
      </div>
    </div><div class="row">
        <div class="input-field col s12">
            <textarea name="chief_complaint" class="materialize-textarea white-text"><?= htmlspecialchars($frm['chief_complaint'] ?? ($_POST['chief_complaint'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['chief_complaint']) || !empty($_POST['chief_complaint'])) ? 'active' : '' ?>">Chief Complaint</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12">
            <textarea name="chief_complaint_history" class="materialize-textarea white-text"><?= htmlspecialchars($frm['chief_complaint_history'] ?? ($_POST['chief_complaint_history'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['chief_complaint_history']) || !empty($_POST['chief_complaint_history'])) ? 'active' : '' ?>">History of Chief Complaint</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12">
            <textarea name="other_complaints" class="materialize-textarea white-text"><?= htmlspecialchars($frm['other_complaints'] ?? ($_POST['other_complaints'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['other_complaints']) || !empty($_POST['other_complaints'])) ? 'active' : '' ?>">Other Complaints & History</label>
        </div>
    </div>

    <div class="row">
        <div class="input-field col s12 m6">
            <input name="dentist_visit_frequency" type="text" class="white-text"
                   value="<?= htmlspecialchars($frm['dentist_visit_frequency'] ?? ($_POST['dentist_visit_frequency'] ?? '')) ?>">
            <label class="<?= (isset($frm['dentist_visit_frequency']) || !empty($_POST['dentist_visit_frequency'])) ? 'active' : '' ?>">Frequency of Dentist Visits</label>
        </div>
        <div class="input-field col s12 m6">
            <select name="attendance_type" class="browser-default">
                <option value="" disabled <?= empty($frm['attendance_type']) && empty($_POST['attendance_type']) ? 'selected' : '' ?>>Attendance Type (Symptomatic/Asymptomatic)</option>
                <option value="symptomatic" <?= (($frm['attendance_type'] ?? ($_POST['attendance_type'] ?? '')) === 'symptomatic') ? 'selected' : '' ?>>Symptomatic</option>
                <option value="asymptomatic" <?= (($frm['attendance_type'] ?? ($_POST['attendance_type'] ?? '')) === 'asymptomatic') ? 'selected' : '' ?>>Asymptomatic</option>
            </select>
        </div>
    </div>

    <div class="row">
        <div class="input-field col s12">
            <input name="brush_frequency" type="text" class="white-text"
                   value="<?= htmlspecialchars($frm['brush_frequency'] ?? ($_POST['brush_frequency'] ?? '')) ?>">
            <label class="<?= (isset($frm['brush_frequency']) || !empty($_POST['brush_frequency'])) ? 'active' : '' ?>">Brushing Frequency</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12">
            <textarea name="previous_dental_tx" class="materialize-textarea white-text"><?= htmlspecialchars($frm['previous_dental_tx'] ?? ($_POST['previous_dental_tx'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['previous_dental_tx']) || !empty($_POST['previous_dental_tx'])) ? 'active' : '' ?>">Previous Dental Tx</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12">
            <textarea name="jaw_problems" class="materialize-textarea white-text"><?= htmlspecialchars($frm['jaw_problems'] ?? ($_POST['jaw_problems'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['jaw_problems']) || !empty($_POST['jaw_problems'])) ? 'active' : '' ?>">Jaw Problems</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12">
            <textarea name="parafunctional_habits" class="materialize-textarea white-text"><?= htmlspecialchars($frm['parafunctional_habits'] ?? ($_POST['parafunctional_habits'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['parafunctional_habits']) || !empty($_POST['parafunctional_habits'])) ? 'active' : '' ?>">Parafunctional Habits</label>
        </div>
    </div>

    <div class="row">
        <div class="input-field col s12">
            <p class="white-text" style="margin-bottom: 5px;">Generally fit and well?</p>
            <label style="margin-right:30px;">
                <input name="mh_fit_well" type="radio" value="yes" <?= (($frm['mh_fit_well'] ?? null) == 1 || ($_POST['mh_fit_well'] ?? '') === 'yes') ? 'checked' : '' ?>>
                <span class="white-text">Yes</span>
            </label>
            <label>
                <input name="mh_fit_well" type="radio" value="no" <?= (($frm['mh_fit_well'] ?? null) === 0 || ($_POST['mh_fit_well'] ?? '') === 'no') ? 'checked' : '' ?>>
                <span class="white-text">No</span>
            </label>
        </div>
    </div>
     <div class="row">
        <div class="input-field col s12">
            <textarea name="notes_expectations" class="materialize-textarea white-text"><?= htmlspecialchars($frm['notes_expectations'] ?? ($_POST['notes_expectations'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['notes_expectations']) || !empty($_POST['notes_expectations'])) ? 'active' : '' ?>">Patient's Expectations</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12">
            <textarea name="notes_constraints" class="materialize-textarea white-text"><?= htmlspecialchars($frm['notes_constraints'] ?? ($_POST['notes_constraints'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['notes_constraints']) || !empty($_POST['notes_constraints'])) ? 'active' : '' ?>">Time or Financial Constraints</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12">
            <textarea name="provisional_dx" class="materialize-textarea white-text"><?= htmlspecialchars($frm['provisional_dx'] ?? ($_POST['provisional_dx'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['provisional_dx']) || !empty($_POST['provisional_dx'])) ? 'active' : '' ?>">Provisional Diagnosis</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12">
            <textarea name="investigations" class="materialize-textarea white-text"><?= htmlspecialchars($frm['investigations'] ?? ($_POST['investigations'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['investigations']) || !empty($_POST['investigations'])) ? 'active' : '' ?>">Investigations Required</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12">
            <textarea name="incidental_findings" class="materialize-textarea white-text"><?= htmlspecialchars($frm['incidental_findings'] ?? ($_POST['incidental_findings'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['incidental_findings']) || !empty($_POST['incidental_findings'])) ? 'active' : '' ?>">Incidental Findings</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12">
            <textarea name="diagnosis" class="materialize-textarea white-text"><?= htmlspecialchars($frm['diagnosis'] ?? ($_POST['diagnosis'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['diagnosis']) || !empty($_POST['diagnosis'])) ? 'active' : '' ?>">Definitive Diagnosis</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12">
            <textarea name="treatment_planned" class="materialize-textarea white-text"><?= htmlspecialchars($frm['treatment_planned'] ?? ($_POST['treatment_planned'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['treatment_planned']) || !empty($_POST['treatment_planned'])) ? 'active' : '' ?>">Treatment Planned</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12">
            <textarea name="treatment_done_today" class="materialize-textarea white-text"><?= htmlspecialchars($frm['treatment_done_today'] ?? ($_POST['treatment_done_today'] ?? '')) ?></textarea>
            <label class="<?= (isset($frm['treatment_done_today']) || !empty($_POST['treatment_done_today'])) ? 'active' : '' ?>">Treatment Done Today</label>
        </div>
    </div>

    <?php if (!$isEdit): // Example: Show consent only for new sessions ?>
    <hr class="divider">
    <h6 class="white-text">Consent</h6>
    <div class="row">
        <div class="input-field col s12">
            <textarea id="consent_text" name="consent_text" class="materialize-textarea white-text" data-length="2000"><?= htmlspecialchars($frm['consent_text'] ?? ($_POST['consent_text'] ?? 'Standard consent text...')) ?></textarea>
            <label for="consent_text" class="<?= (isset($frm['consent_text']) || !empty($_POST['consent_text'])) ? 'active' : '' ?>">Consent Text</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12">
            <button type="button" class="btn blue waves-effect waves-light" onclick="openSignaturePad()">Capture Signature</button>
            <input type="hidden" name="sig_path" id="sig_path" value="<?= htmlspecialchars($frm['consent_signature_path'] ?? '') ?>">
            <div id="sig-status" class="white-text" style="margin-top:10px;"></div>
            <img id="sigPreview" src="<?= isset($frm['consent_signature_path']) && $frm['consent_signature_path'] ? '/'.htmlspecialchars($frm['consent_signature_path']) : '' ?>" style="max-width:200px; height:auto; border:1px solid #555; background:white; margin-top:10px; <?= isset($frm['consent_signature_path']) && $frm['consent_signature_path'] ? '' : 'display:none;' ?>">
        </div>
    </div>
    <?php endif; ?>


    <div class="row center" style="margin-top:30px;">
        <button type="submit" class="btn green waves-effect waves-light">Save Session</button>
        <a href="dental.php" class="btn grey waves-effect waves-light">Cancel & Back to Menu</a>
    </div>
  

</form>
<?php } /* END form section */ ?>

</div>
<?php include_once __DIR__.'/includes/footer.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    M.Sidenav.init(document.querySelectorAll('.sidenav'),{edge:'right'});
    M.CharacterCounter.init(document.querySelectorAll('textarea.materialize-textarea'));
    M.Modal.init(document.querySelectorAll('.modal'));
    M.FormSelect.init(document.querySelectorAll('select')); // Initialize selects

    <?php if (isset($showSignForm) && $showSignForm && isset($view) && !$view['is_locked']): ?>
    const signModalElem = document.getElementById('signModal');
    if (signModalElem) {
        const instance = M.Modal.getInstance(signModalElem);
        if (instance) {
            instance.open();
        } else { // If modal was not auto-initialized by selector, init it now
            const newInstance = M.Modal.init(signModalElem);
            if (newInstance) newInstance.open();
        }
    }
    <?php endif; ?>

    <?php if (isset($isEdit) && $isEdit): ?>
    // If in edit mode, MRN field is readonly, so onblur won't fire by user.
    // Call lookupMRN to populate patient name, age, gender from 'patients' table.
    // The form itself is pre-filled by PHP from $frm (which includes patientregister data if available).
    // lookupMRN() may override these if 'patients' table data is different/more up-to-date.
    const mrnFieldForEdit = document.getElementById('mrn');
    if (mrnFieldForEdit && mrnFieldForEdit.value) { // Ensure MRN value exists
       // lookupMRN(); // This will fetch from patients table.
                      // $frm already has patient_name, age, gender from patientregister (via PHP query).
                      // Decide which source is authoritative for edit mode.
                      // For now, PHP prefill from $frm takes precedence.
    }
    // Ensure Materialize labels are active for prefilled fields in edit mode
    M.updateTextFields(); 
    <?php endif; ?>
});

function lookupMRN(){
  const mrnField = document.getElementById('mrn');
  const nameField = document.getElementById('full_name');
  const ageField = document.getElementById('age');
  const genderField = document.getElementById('gender');
  const visitField = document.getElementById('latest_visit'); // This is visit_number_display now

  if(!mrnField || !mrnField.value.trim()){
      if(nameField) nameField.value='';
      if(ageField) ageField.value='';
      if(genderField) genderField.value='';
      if(visitField) visitField.value=''; // This is for visit_number_display
      M.updateTextFields(); // Important for Materialize labels
      return;
  }
  fetch('dental.php?action=lookup_mrn&mrn='+encodeURIComponent(mrnField.value.trim()))
    .then(response => {
        if (!response.ok) { throw new Error('Network response was not ok: ' + response.statusText); }
        return response.json();
    })
    .then(data => {
      if(!data.success){
        M.toast({html: data.message || 'Patient not found', classes:'red darken-2'});
        if(nameField) nameField.value='';
        if(ageField) ageField.value='';
        if(genderField) genderField.value='';
        if(visitField) visitField.value=''; // This is for visit_number_display
      } else {
        if(nameField) nameField.value = data.full_name || '';
        if(ageField) ageField.value = (data.age !== null && data.age !== undefined ? String(data.age) + (data.age_unit ? ' ' + data.age_unit : '') : '');
        if(genderField) genderField.value = data.gender || '';
        if(visitField) visitField.value = data.latest_visit || ''; // This updates visit_number_display
        M.toast({html: 'Patient details loaded.', classes: 'green darken-1'});
      }
      M.updateTextFields(); // Ensure labels move correctly
    }).catch(error => {
        M.toast({html:'MRN Lookup Error: ' + error.message, classes:'red darken-2'});
        if(nameField) nameField.value='';
        if(ageField) ageField.value='';
        if(genderField) genderField.value='';
        if(visitField) visitField.value=''; // This is for visit_number_display
        M.updateTextFields();
    });
}

// Signature pad
let sigPad, sigModalInstance;
const sigCanvas = document.createElement('canvas');
sigCanvas.id = 'sigCanvas';
sigCanvas.width = 450; // set explicitly, adjusted for typical modal width
sigCanvas.height = 200; // set explicitly
sigCanvas.style.border = '1px solid #aaa';
sigCanvas.style.backgroundColor = 'white';
sigCanvas.style.maxWidth = '100%';


function resizeCanvas() { // May not be strictly needed if explicit width/height set
    // const ratio =  Math.max(window.devicePixelRatio || 1, 1);
    // const canvasContainer = document.getElementById('sigCanvasContainer');
    // if (canvasContainer) {
    //     sigCanvas.width = canvasContainer.offsetWidth * ratio;
    //     sigCanvas.height = (canvasContainer.offsetWidth * (2/4.5)) * ratio; // Maintain aspect ratio
    //     sigCanvas.getContext("2d").scale(ratio, ratio);
    //     if(sigPad) sigPad.clear();
    // }
}


function openSignaturePad(){
  if(!sigModalInstance){
    const modalHTML = `
      <div class="modal-content">
        <h5>Provide Signature</h5>
        <div id="sigCanvasContainer" style="text-align:center;"></div> </div>
      <div class="modal-footer">
        <a href="#!" class="modal-close waves-effect waves-grey btn-flat">Cancel</a>
        <a href="#!" id="sigClearButton" class="waves-effect waves-red btn-flat">Clear</a>
        <a href="#!" id="sigSaveButton" class="waves-effect waves-green btn blue white-text">Save Signature</a>
      </div>`;
    const modalEl = document.createElement('div');
    modalEl.id = 'signatureModal';
    modalEl.className = 'modal grey darken-3 white-text'; // Standard modal classes
    modalEl.innerHTML = modalHTML;
    document.body.appendChild(modalEl);
    
    modalEl.querySelector('#sigCanvasContainer').appendChild(sigCanvas);

    sigModalInstance = M.Modal.init(modalEl, {
        dismissible: false,
        onOpenEnd: function() { 
            if (!sigPad) { 
                sigPad = new SignaturePad(sigCanvas, {
                    backgroundColor: 'rgb(255, 255, 255)' 
                });
            }
            // resizeCanvas(); // Call if dynamic resizing is needed
            // window.addEventListener("resize", resizeCanvas);
            sigPad.clear();
        },
        onCloseEnd: function() {
            // window.removeEventListener("resize", resizeCanvas);
        }
    });

    modalEl.querySelector('#sigClearButton').onclick = () => sigPad.clear();
    modalEl.querySelector('#sigSaveButton').onclick = () => {
      if(sigPad.isEmpty()){
        M.toast({html:'Signature is empty. Please sign.', classes:'yellow darken-3 black-text'});
        return;
      }
      const dataURL = sigPad.toDataURL('image/png'); 
      fetch('dental.php?action=save_signature',{method:'POST',body:new URLSearchParams({img: dataURL})})
        .then(response => response.json())
        .then(data => {
          if(data.ok){
            document.getElementById('sig_path').value = data.path;
            const sigStatus = document.getElementById('sig-status');
            if(sigStatus) sigStatus.textContent = 'Signature captured successfully!';
            const sigPreview = document.getElementById('sigPreview');
            if(sigPreview) {
                sigPreview.src = dataURL;
                sigPreview.style.display = 'block';
            }
            sigModalInstance.close();
            M.toast({html:'Signature saved!', classes:'green darken-1'});
          } else {
            M.toast({html: data.message || 'Failed to save signature.', classes:'red darken-2'});
          }
        }).catch(error => M.toast({html:'Signature save error: ' + error.message, classes:'red darken-2'}));
    };
  }
  // If modal already exists, just clear pad and open
  if (sigPad) sigPad.clear();
  sigModalInstance.open();
}

</script>
</body></html>