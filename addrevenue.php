<?php
// /public/addrevenue.php  – Modernized for consistent look and feel.

/*------------------------------------------------------------------
  Bootstrap
-------------------------------------------------------------------*/
session_start();
if (!isset($_SESSION['user'])) { header("Location: login.php"); exit; }

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/fpdf.php';

/*------------------------------------------------------------------
  Access control
-------------------------------------------------------------------*/
$user      = $_SESSION['user'];
$group_id  = (int)($user['group_id'] ?? 0);
$allowed   = [1,2,3,4,5,6,8,10,20,22,23];
if (!in_array($group_id, $allowed, true)) {
    header("HTTP/1.1 403 Forbidden");
    exit('Access denied. You do not have permission to access this page.');
}
$username = $user['username'] ?? 'unknown';

/*------------------------------------------------------------------
  Utility helpers
-------------------------------------------------------------------*/
class MyriadPDF extends FPDF { function Header(){} function Footer(){} }
function nfloat($v){ return (float)preg_replace('/[^\d\.]/','', (string)$v); }

function buildServiceLines(array $d, array $p, float &$total): array
{
    $out=[]; $total=0.0;
    for($i=0;$i<count($d);$i++){
        $desc=trim($d[$i]??'');
        $pr  = nfloat($p[$i]??'');
        if($desc!=='' || $pr>0){
            $out[]="$desc (PKR " . number_format($pr, 2) . ")";
            $total+=$pr;
        }
    }
    return $out;
}

function splitSavedLines(string $txt): array
{
    $rows=[];
    foreach(explode("\n",$txt) as $ln){
        $ln = trim($ln);
        if($ln==='') continue;
        if(preg_match('/^(.*?)(?:\s*\(PKR\s*([\d\.,]+)\))?$/i',$ln,$m)){
             $desc = trim($m[1]);
             $price_str = $m[2] ?? '';
             $price = ($price_str !== '') ? nfloat($price_str) : '';
             $rows[]=['d'=>$desc,'p'=>$price];
        } else {
            $rows[]=['d'=>$ln,'p'=>''];
        }
    }
    return $rows;
}

function getLatestVisitNumberByMRN($pdo, $mrn) {
    $stmtP = $pdo->prepare("SELECT patient_id FROM patients WHERE mrn = ? LIMIT 1");
    $stmtP->execute([$mrn]);
    $patientId = $stmtP->fetchColumn();
    if (!$patientId) return 0;
    $stmtV = $pdo->prepare("SELECT MAX(visit_number) FROM visits WHERE patient_id = ?");
    $stmtV->execute([(int)$patientId]);
    $maxVisit = $stmtV->fetchColumn();
    return ($maxVisit === false || $maxVisit === null) ? 0 : (int)$maxVisit;
}

// --- AJAX Request Handler for Latest Visit Lookup ---
if (isset($_GET['action']) && $_GET['action'] === 'lookup_latest_visit') {
    header('Content-Type: application/json');
    $mrn = trim($_GET['mrn'] ?? '');
    $response = ['success' => false, 'error' => 'Invalid request'];

    if (empty($mrn)) {
        $response['error'] = 'MRN cannot be empty.';
    } else {
        try {
             if (!isset($pdo) || !($pdo instanceof PDO)) { throw new Exception("Database connection lost."); }
             $stmtPatientExists = $pdo->prepare("SELECT 1 FROM patients WHERE mrn = ?");
             $stmtPatientExists->execute([$mrn]);
             $patientExists = $stmtPatientExists->fetchColumn();
             
             if (!$patientExists) {
                  $response['error'] = "No patient found with MRN: " . htmlspecialchars($mrn);
             } else {
                $latestVisit = getLatestVisitNumberByMRN($pdo, $mrn);
                $response = [
                    'success' => true,
                    'latest_visit' => $latestVisit
                ];
             }
        } catch (Exception $e) {
            $response['error'] = 'Database error: ' . $e->getMessage();
             error_log("AJAX lookup_latest_visit error: " . $e->getMessage());
        }
    }
    echo json_encode($response);
    exit;
}

/*------------------------------------------------------------------
  Workflow state
-------------------------------------------------------------------*/
$visit      = $_SESSION['revenue_visit'] ?? null;
$error      = '';
$success    = '';
$pdfRel     = null;

if ($_SERVER['REQUEST_METHOD']==='GET' && empty($_GET)) {
    unset($_SESSION['revenue_visit']); $visit=null;
}

/*------------------------------------------------------------------
  Step A: locate visit by MRN + visit #
-------------------------------------------------------------------*/
if (isset($_POST['find_visit'])) {
    unset($_SESSION['revenue_visit']);
    $visit=null;
    $mrn = trim($_POST['entered_mrn'] ?? '');
    $vno = (int)($_POST['entered_visitnum'] ?? 0);

    if (!$mrn || $vno < 1) {
        $error='Please enter a valid MRN and Visit Number (must be 1 or greater).';
    } else {
        try {
            if (!isset($pdo) || !($pdo instanceof PDO)) { throw new Exception("Database connection is not available."); }
            $st=$pdo->prepare("
                SELECT v.*, p.full_name, p.mrn, p.gender, p.phone, p.address
                FROM visits v JOIN patients p ON v.patient_id=p.patient_id
                WHERE p.mrn=? AND v.visit_number=? LIMIT 1
            ");
            $st->execute([$mrn,$vno]);
            if ($fetched_visit=$st->fetch(PDO::FETCH_ASSOC)) {
                 $visit = $fetched_visit;
                 $visit['services_rendered'] = $visit['services_rendered'] ?? '';
                 $visit['total_amount'] = $visit['total_amount'] ?? 0.0;
                 $_SESSION['revenue_visit']=$visit;
            } else {
                $error="No visit found for MRN '" . htmlspecialchars($mrn) . "', Visit # " . htmlspecialchars($vno) . ".";
            }
        } catch (Exception $e) { $error = "Database error finding visit: " . $e->getMessage(); }
    }
}

/*------------------------------------------------------------------
  Step B: “Save Progress”
-------------------------------------------------------------------*/
if (isset($_POST['save_only']) && $visit) {
    $total = 0.0;
    $lines = buildServiceLines($_POST['services_desc']??[], $_POST['services_price']??[], $total);
    try{
        if (!isset($pdo) || !($pdo instanceof PDO)) { throw new Exception("Database connection lost."); }
        $pdo->beginTransaction();
        $st = $pdo->prepare("UPDATE visits SET services_rendered=?, total_amount=? WHERE visit_id=? LIMIT 1");
        if (!isset($visit['visit_id'])) { throw new Exception("Visit ID is missing."); }
        $st->execute([implode("\n",$lines), $total, $visit['visit_id']]);
        $pdo->commit();
        $_SESSION['rev_msg']='Progress saved successfully.';
        header("Location: addrevenue.php?progress=1&vid=".$visit['visit_id']);
        exit;
    } catch(Exception $e){
        if(isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        $error='Save progress error: '.$e->getMessage();
    }
}

/*------------------------------------------------------------------
  Step C:  “Save & Issue Receipt”
-------------------------------------------------------------------*/
if (isset($_POST['save_revenue']) && $visit) {
    $total = 0.0;
    $lines = buildServiceLines($_POST['services_desc']??[], $_POST['services_price']??[], $total);

     if (!isset($visit['visit_id'])) {
        $error = 'Critical Error: Visit ID is missing. Cannot process revenue.';
     } elseif (empty($lines) || $total <= 0) {
        $error = 'Cannot issue receipt with no services or zero/negative total amount.';
    } else {
        try{
            if (!isset($pdo) || !($pdo instanceof PDO)) { throw new Exception("Database connection lost."); }
            $pdo->beginTransaction();

            $st_visit = $pdo->prepare("UPDATE visits SET services_rendered=?, total_amount=? WHERE visit_id=? LIMIT 1");
            $st_visit->execute([implode("\n",$lines), $total, $visit['visit_id']]);

            $st_ledger = $pdo->prepare("INSERT INTO generalledger (datetime, description, amount, user) VALUES (NOW(), ?, ?, ?)");
            $ledger_desc = ($visit['mrn'] ?? 'N/A').' VISIT '.($visit['visit_number'] ?? 'N/A');
            $st_ledger->execute([$ledger_desc, $total, $username]);

            $receiptDir = __DIR__.'/patiententries/';
            if (!is_dir($receiptDir)) { @mkdir($receiptDir, 0775, true); }
            if (!is_writable($receiptDir)) { throw new Exception("Error: Receipt directory is not writable."); }

            $safe_mrn = preg_replace('/[^a-zA-Z0-9_-]/', '_', $visit['mrn']);
            $safe_vnum = $visit['visit_number'];
            $base = $safe_mrn.'_VISIT'.$safe_vnum.'_receipt';
            $file = $receiptDir.$base.'.pdf'; $n = 1; while (file_exists($file)) { $n++; $file = $receiptDir.$base.'_'.$n.'.pdf'; }
            $pdfRel = 'patiententries/'.basename($file);

            $pdf = new MyriadPDF('P','mm','A4');
            $pdf->AddFont('MyriadPro-Regular','','MyriadPro-Regular.php');
            $pdf->SetMargins(15, 10); $pdf->SetAutoPageBreak(true, 15); $pdf->AddPage();
            $contentWidth = $pdf->GetPageWidth() - 30;

            $logoPath = __DIR__.'/media/headerlogo.jpg';
            if (file_exists($logoPath)) {
                list($w, $h) = getimagesize($logoPath); $a = $h/$w;
                $pdf->Image($logoPath, 15, 10, $contentWidth, $contentWidth * $a); $pdf->Ln(($contentWidth * $a) + 5);
            }
            
            $pdf->SetFont('MyriadPro-Regular','',14); $pdf->SetTextColor(0,0,0);
            $pdf->Cell(0,6,'PAYMENT RECEIPT',0,1,'C'); $pdf->Ln(8);
            
            $row = function($label, $value) use ($pdf, $contentWidth) {
                $pdf->SetFont('MyriadPro-Regular','',11); $pdf->Cell(45, 7, $label, 0, 0, 'L');
                $pdf->SetFont('MyriadPro-Regular','',11);
                $y = $pdf->GetY(); $pdf->MultiCell($contentWidth-45, 7, htmlspecialchars_decode((string)$value), 0, 'L');
                $pdf->SetY(max($y + 7, $pdf->GetY()));
            };

            $row('Full Name:', ($visit['full_name'] ?? 'N/A'));
            $row('Age:', ((int)$visit['age_value'] > 0 ? htmlspecialchars((int)$visit['age_value'].' '.($visit['age_unit'] ?? '')) : 'N/A'));
            $row('Gender:', ($visit['gender'] ?? 'N/A'));
            $row('Phone:', ($visit['phone'] ?? 'N/A'));
            $row('Department:', ($visit['department'] ?? 'N/A'));
            $row('Time of Pres.:', ($visit['time_of_presentation'] ? date('Y-m-d H:i', strtotime($visit['time_of_presentation'])) : 'N/A'));
            $row('MRN:', ($visit['mrn'] ?? 'N/A'));
            $row('Visit #:', ($visit['visit_number'] ?? 'N/A'));
            $pdf->Ln(5);

            $pdf->SetFont('MyriadPro-Regular','',12); $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell($contentWidth, 8, 'Services Rendered', 0, 1, 'L', true); $pdf->Ln(2);
            $pdf->SetFont('MyriadPro-Regular','',11); $pdf->SetX(17);
            $pdf->MultiCell($contentWidth-2, 6, htmlspecialchars_decode(implode("\n", $lines)), 0, 'L'); $pdf->Ln(5);

            $pdf->SetFont('MyriadPro-Regular','',12); $pdf->SetFillColor(230, 230, 230);
            $pdf->Cell($contentWidth * 0.6, 9, 'Total Amount (PKR):', 0, 0, 'R', true);
            $pdf->SetFont('MyriadPro-Regular','',12);
            $pdf->Cell($contentWidth * 0.4, 9, number_format($total, 2), 0, 1, 'R', true); $pdf->Ln(10);
            
            $pdf->SetY(-30);
            $pdf->SetFont('MyriadPro-Regular','',9); $pdf->SetTextColor(100,100,100);
            $pdf->Cell(0,6,'Receipt generated by '.$username . ' on ' . date('Y-m-d H:i:s'), 0, 1, 'C');
            $pdf->Ln(5); $pdf->SetFont('MyriadPro-Regular','',10); $pdf->Cell(0,6,'hospital0',0,1,'C');

            $pdf->Output('F', $file);
            if (!file_exists($file)) { throw new Exception("Failed to save the PDF file."); }

            $pdo->commit();
            $success='Receipt generated successfully.';
            unset($_SESSION['revenue_visit']);
            $visit=null;

        }catch(Exception $e){
            if(isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            $error='Error processing revenue: '.$e->getMessage();
            error_log("Error in Step C (addrevenue.php): " . $e->getMessage());
            $pdfRel = null;
        }
    }
}

if (isset($_SESSION['rev_msg'])) {
    $success = htmlspecialchars($_SESSION['rev_msg']);
    unset($_SESSION['rev_msg']);
    if (isset($_GET['progress']) && isset($_GET['vid']) && !$visit) {
         $reloaded_visit_id = (int)$_GET['vid'];
         try {
             if (isset($pdo)) {
                 $st = $pdo->prepare("SELECT v.*, p.full_name, p.mrn, p.gender, p.phone, p.address FROM visits v JOIN patients p ON v.patient_id=p.patient_id WHERE v.visit_id = ? LIMIT 1");
                 $st->execute([$reloaded_visit_id]);
                 if ($reloaded_visit = $st->fetch(PDO::FETCH_ASSOC)) {
                     $visit = $reloaded_visit;
                     $visit['services_rendered'] = $visit['services_rendered'] ?? '';
                     $visit['total_amount'] = $visit['total_amount'] ?? 0.0;
                     $_SESSION['revenue_visit'] = $visit;
                 }
             }
         } catch (Exception $e) { error_log("Error reloading visit: " . $e->getMessage()); }
    }
}

$prefill = [];
if ($visit && !empty($visit['services_rendered'])) {
    $prefill = splitSavedLines($visit['services_rendered']);
}
if (empty($prefill)) { $prefill = [['d' => '', 'p' => '']]; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>hospital0 - Services Rendered — hospital0</title>
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
    
    h4.center-align { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
    .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }
    .container { width: 90%; max-width: 1200px; }
    
    .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 2.5rem; margin-top: 2rem; margin-bottom: 2rem; }
    .card-title { color: #00e5ff; font-weight: 400; border-bottom: 1px solid rgba(0, 229, 255, 0.3); padding-bottom: 15px; margin-bottom: 25px !important; }
    .card-title i { vertical-align: middle; margin-right: 10px; }
    
    .input-field input, .materialize-textarea { color:#fff!important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
    .input-field label { color:#bdbdbd!important; }
    .input-field label.active { color:#00e5ff!important; }
    .input-field input:focus, .materialize-textarea:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
    input[readonly] { color: #9e9e9e !important; border-bottom: 1px dotted rgba(255,255,255,0.3) !important; }
    
    .message-area { padding: 10px 15px; margin-bottom: 20px; border-radius: 8px; text-align: center; border: 1px solid; display: flex; align-items: center; justify-content: center;}
    .message-area i { margin-right: 10px; }
    .message-area.success { background-color: rgba(76, 175, 80, 0.25); color: #c8e6c9; border-color: rgba(129, 199, 132, 0.5); }
    .message-area.error { background-color: rgba(244, 67, 54, 0.25); color: #ffcdd2; border-color: rgba(239, 154, 154, 0.5); }
    
    .service-row .remove-srv-btn { margin-top: 15px; }
    #lookupStatus { margin-left: 15px; font-style: italic; color: #ffeb3b; }
    #lookupStatus.success { color: #4caf50; }
    #lookupStatus.error { color: #f44336; }
    #tot { text-align: right; font-size: 1.8rem; font-weight: bold; padding: 10px; border: none !important; background: transparent !important; color: #00e5ff; text-shadow: 0 0 5px rgba(0,229,255,0.5); }
</style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__.'/includes/header.php'; ?>

<main>
<div class="container">
  <h4 class="center-align white-text" style="margin-top:30px;">Add Services & Payment</h4>
  <hr class="white-line">

  <?php if($error):   ?><div class="message-area error"><i class="material-icons">error_outline</i><?=htmlspecialchars($error)?></div><?php endif;?>
  <?php if($success): ?><div class="message-area success"><i class="material-icons">check_circle_outline</i><?=htmlspecialchars($success)?></div><?php endif;?>

  <?php if($pdfRel): ?>
    <div class="glass-card center-align">
        <p class="white-text">Receipt generated. You can now download or print it.</p>
        <div class="row" style="margin-top:30px;">
            <a href="<?=htmlspecialchars($pdfRel)?>" download class="btn waves-effect waves-light blue darken-1"><i class="material-icons left">file_download</i>Download</a>
            <a href="<?=htmlspecialchars($pdfRel)?>" target="_blank" class="btn waves-effect waves-light teal darken-1"><i class="material-icons left">print</i>Print</a>
            <a href="addrevenue.php" class="btn waves-effect waves-light grey darken-1"><i class="material-icons left">add_circle_outline</i>New Entry</a>
        </div>
    </div>
  <?php elseif(isset($_GET['progress']) && !$visit && !$error): ?>
     <div class="glass-card center-align">
        <p class="white-text">Progress was saved. Search for the visit again to continue, or start a new entry.</p>
        <div class="row" style="margin-top:30px;">
            <a href="addrevenue.php" class="btn waves-effect waves-light"><i class="material-icons left">add_circle_outline</i>Start New Entry</a>
        </div>
    </div>
  <?php elseif(!$visit): ?>
    <div class="glass-card">
        <h5 class="card-title white-text"><i class="material-icons">search</i>Find Visit Record</h5>
        <form method="POST" action="addrevenue.php">
          <div class="row" style="margin-bottom: 0;">
            <div class="input-field col s12 m6">
              <i class="material-icons prefix">badge</i>
              <input id="mrn" name="entered_mrn" type="text" class="validate" required>
              <label for="mrn">Enter Patient MRN</label>
            </div>
            <div class="input-field col s12 m6">
               <i class="material-icons prefix">confirmation_number</i>
               <input id="visitnum" type="number" min="1" name="entered_visitnum" class="validate" required>
               <label for="visitnum">Enter Visit Number</label>
               <span id="lookupStatus"></span>
            </div>
          </div>
          <div class="row center-align" style="margin-top: 20px;">
              <button type="button" class="btn waves-effect waves-light grey" onclick="lookupLatestVisit()"><i class="material-icons left">help_outline</i>Lookup Visit #</button>
              <button type="submit" class="btn waves-effect waves-light" name="find_visit" style="background-color: #00bfa5;"><i class="material-icons left">pageview</i>Load Visit Data</button>
          </div>
        </form>
    </div>
  <?php else: ?>
    <div class="glass-card">
        <h5 class="card-title white-text"><i class="material-icons">person_pin</i>Visit Information</h5>
        <div class="row" style="margin-bottom: 5px; font-size: 1.1em;">
            <div class="col s12 m6">
                <strong>Name:</strong> <?=htmlspecialchars($visit['full_name'] ?? 'N/A')?><br>
                <strong>Age/Gender:</strong> <?= ((int)$visit['age_value'] > 0 ? htmlspecialchars((int)$visit['age_value'].' '.($visit['age_unit'] ?? '')) : 'N/A') ?> / <?=htmlspecialchars($visit['gender'] ?? 'N/A')?><br>
                <strong>Phone:</strong> <?=htmlspecialchars($visit['phone'] ?? 'N/A')?>
            </div>
            <div class="col s12 m6">
                <strong>MRN / Visit:</strong> <?=htmlspecialchars($visit['mrn'] ?? 'N/A')?> / <?=htmlspecialchars($visit['visit_number'] ?? 'N/A')?><br>
                <strong>Department:</strong> <?=htmlspecialchars($visit['department'] ?? 'N/A')?><br>
                <strong>Time:</strong> <?=htmlspecialchars($visit['time_of_presentation'] ?? 'N/A')?>
            </div>
        </div>
    </div>

    <div class="glass-card">
       <h5 class="card-title white-text"><i class="material-icons">receipt_long</i>Services Rendered</h5>
        <form method="POST" action="addrevenue.php" onsubmit="return validateAndCalcTot();">
          <div id="srvArea">
            <?php foreach($prefill as $idx => $r): ?>
            <div class="row service-row" style="margin-bottom: 0;">
              <div class="input-field col s7 m7">
                 <input id="desc_<?=$idx?>" name="services_desc[]" value="<?=htmlspecialchars($r['d'] ?? '')?>" type="text" class="validate service-desc-input" required>
                 <label for="desc_<?=$idx?>">Service Description</label>
              </div>
              <div class="input-field col s4 m4">
                 <input id="price_<?=$idx?>" type="number" step="0.01" min="0" name="services_price[]" value="<?=htmlspecialchars($r['p'] ?? '')?>" class="validate price-input" oninput="calcTot()" required>
                 <label for="price_<?=$idx?>">Price (PKR)</label>
              </div>
              <div class="col s1 m1 center-align">
                 <?php if ($idx > 0 || count($prefill) > 1): ?>
                 <button type="button" class="btn-floating btn-small waves-effect waves-light red remove-srv-btn" title="Remove Service"><i class="material-icons">remove</i></button>
                 <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <button type="button" class="btn waves-effect waves-light grey lighten-1 black-text" onclick="addSrv()"><i class="material-icons left">add</i>Add Service</button>
          <br><br>

          <div class="row right-align">
            <div class="col s8"><h5 class="white-text" style="font-weight: 300; margin-top:20px;">Total Amount (PKR)</h5></div>
            <div class="input-field col s4"><input id="tot" readonly></div>
          </div>

          <div class="row center-align" style="margin-top:30px; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 20px;">
            <button type="submit" class="btn waves-effect waves-light green darken-1" name="save_only"><i class="material-icons left">save</i>Save Progress</button>
            <button type="submit" class="btn waves-effect waves-light" name="save_revenue" style="background-color: #00bfa5;"><i class="material-icons left">receipt</i>Save & Issue Receipt</button>
          </div>
          <div class="row center-align"><p class="grey-text" style="font-size: 0.9rem;">Tap green to save for later. Tap teal to finalize, add to ledger, and generate the receipt.</p></div>
        </form>
    </div>
  <?php endif; ?>
</div>
</main>

<?php include_once __DIR__.'/includes/footer.php'; ?>

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
function addSrv(){
  const srvArea = document.getElementById('srvArea'); const newIndex = srvArea.querySelectorAll('.service-row').length;
  const newRow = document.createElement('div'); newRow.className = 'row service-row'; newRow.style.marginBottom = '0';
  newRow.innerHTML = `
      <div class="input-field col s7 m7"><input id="desc_${newIndex}" name="services_desc[]" type="text" class="validate service-desc-input" required><label for="desc_${newIndex}">Service Description</label></div>
      <div class="input-field col s4 m4"><input id="price_${newIndex}" type="number" step="0.01" min="0" name="services_price[]" class="validate price-input" oninput="calcTot()" required><label for="price_${newIndex}">Price (PKR)</label></div>
      <div class="col s1 m1 center-align"><button type="button" class="btn-floating btn-small waves-effect waves-light red remove-srv-btn" title="Remove Service"><i class="material-icons">remove</i></button></div>`;
  srvArea.appendChild(newRow); M.updateTextFields(); newRow.querySelector('.remove-srv-btn').addEventListener('click', removeSrvRow); updateRemoveButtons();
}
function removeSrvRow() { this.closest('.service-row').remove(); calcTot(); updateRemoveButtons(); }
function updateRemoveButtons() {
    const serviceRows = document.querySelectorAll('.service-row');
    serviceRows.forEach((row, index) => { const removeButton = row.querySelector('.remove-srv-btn'); if(removeButton){ removeButton.style.display = (serviceRows.length > 1) ? 'inline-block' : 'none'; } });
}
function calcTot(){
  let total = 0;
  document.querySelectorAll('.price-input').forEach(el => { const value = parseFloat(el.value); if (!isNaN(value) && value >= 0) { total += value; } });
  const totalField = document.getElementById('tot');
  if(totalField) { totalField.value = total.toFixed(2); M.updateTextFields(); }
  return total;
}
function validateAndCalcTot() {
    let isValid = true; let firstInvalidElement = null;
    document.querySelectorAll('.service-desc-input').forEach(el => { if (el.value.trim() === '') { isValid = false; if (!firstInvalidElement) firstInvalidElement = el; el.classList.add('invalid'); } else { el.classList.remove('invalid'); } });
    document.querySelectorAll('.price-input').forEach(el => { const value = parseFloat(el.value); if (isNaN(value) || value < 0) { isValid = false; if (!firstInvalidElement) firstInvalidElement = el; el.classList.add('invalid'); } else { el.classList.remove('invalid'); } });
    if (!isValid) { M.toast({html: 'Please fill descriptions and enter valid prices (0+).', classes: 'red'}); if (firstInvalidElement) { firstInvalidElement.focus(); } return false; }
    calcTot(); return true;
}
function lookupLatestVisit() {
    const mrnInput = document.getElementById('mrn'); const visitNumInput = document.getElementById('visitnum'); const statusSpan = document.getElementById('lookupStatus');
    const mrn = mrnInput.value.trim();
    if (!mrn) { statusSpan.textContent = ''; statusSpan.className = ''; visitNumInput.value = ''; M.updateTextFields(); return; }
    statusSpan.textContent = 'Looking up...'; statusSpan.className = '';
    fetch(`addrevenue.php?action=lookup_latest_visit&mrn=${encodeURIComponent(mrn)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const latestVisit = parseInt(data.latest_visit) || 0;
                visitNumInput.value = latestVisit > 0 ? latestVisit : '';
                statusSpan.textContent = latestVisit > 0 ? `Latest Visit: ${latestVisit}` : 'Patient found, no previous visits.';
                statusSpan.className = 'success';
            } else {
                statusSpan.textContent = data.error || 'Patient not found.'; statusSpan.className = 'error'; visitNumInput.value = '';
            }
            M.updateTextFields();
        })
        .catch(error => { console.error('Fetch Error:', error); statusSpan.textContent = 'Lookup failed.'; statusSpan.className = 'error'; visitNumInput.value = ''; M.updateTextFields(); });
}
document.addEventListener('DOMContentLoaded', () => {
  M.AutoInit(); calcTot();
  document.querySelectorAll('.remove-srv-btn').forEach(button => { button.addEventListener('click', removeSrvRow); });
  updateRemoveButtons(); M.updateTextFields();
  const mrnInput = document.getElementById('mrn');
  if (mrnInput) { mrnInput.addEventListener('blur', lookupLatestVisit); }
});
</script>
</body>
</html>