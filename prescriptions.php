<?php
// /public/prescriptions.php - Modernized for high-tech theme
// All original functionality, including PDF generation and complex form logic, is preserved.

session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/fpdf.php';
require_once __DIR__ . '/includes/code128.php';

// --- User Authentication & Authorization ---
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$currentUser = $_SESSION['user'];
$currentUserId = (int)$currentUser['user_id'];
$currentUserGroupId = (int)($currentUser['group_id'] ?? 0);

$allowedGroups = [1, 2, 3, 4, 5, 6, 8, 10, 11, 20, 22, 23];
$no_rights = !in_array($currentUserGroupId, $allowedGroups, true);

$message = '';
$action = $_GET['action'] ?? '';
$perPage = 10;

// --- PDF Generation Classes & Functions ---
class MyriadPDFWithBarcode extends PDF_Code128
{
    function __construct($orientation='P', $unit='mm', $size='A4') {
        parent::__construct($orientation, $unit, $size);
        $this->SetTopMargin(50);
        $this->SetAutoPageBreak(true, 25);
        try {
            $this->AddFont('MyriadPro-Regular','','MyriadPro-Regular.php');
        } catch (Exception $e) {
            error_log("FPDF Font Error: " . $e->getMessage());
        }
    }
    function Header() {}
    function Footer() {}
    function rowData($label, $value, $labelWidth = 40, $lineHeight = 6){
        $this->SetFont('MyriadPro-Regular','',12);
        $this->SetTextColor(0,0,0);
        $this->Cell($labelWidth, $lineHeight, $label, 0, 0);
        $this->MultiCell(0, $lineHeight, $value, 0, 1);
    }
    function GetEffectiveBottomMargin() { return $this->bMargin; }
    function GetCurrentRightMargin() { return $this->rMargin; }
}

function generatePrescriptionPDF($pdo, $prescription_id) {
    $sql=<<<SQL
    SELECT pr.*, u.full_name AS doctor_name, u.username AS creator_username
    FROM prescriptions pr LEFT JOIN users u ON pr.created_by = u.user_id
    WHERE pr.prescription_id=? LIMIT 1
    SQL;
    $st = $pdo->prepare($sql);
    $st->execute([$prescription_id]);
    $pres = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pres) { error_log("PDF Gen: Prescription ID {$prescription_id} not found."); return null; }

    $patientRow = null;
    if (!empty($pres['mrn'])) {
        $stmtP = $pdo->prepare("SELECT mrn, full_name, gender, phone, address, age, age_unit FROM patients WHERE mrn=? LIMIT 1");
        $stmtP->execute([$pres['mrn']]);
        $patientRow = $stmtP->fetch(PDO::FETCH_ASSOC);
    }
    if (!$patientRow) {
         $patientRow = [ 'mrn' => $pres['mrn'] ?? 'N/A', 'full_name' => $pres['patient_name'], 'gender' => $pres['gender'] ?? null, 'age' => $pres['age'] ?? null, 'age_unit' => $pres['age_unit'] ?? null, 'phone' => null, 'address' => null ];
    }
    $stmtM = $pdo->prepare("SELECT * FROM prescription_medicines WHERE prescription_id=? ORDER BY med_id ASC");
    $stmtM->execute([$prescription_id]);
    $meds = $stmtM->fetchAll(PDO::FETCH_ASSOC);

    $pdfDir = __DIR__ . '/prescriptions/';
    @mkdir($pdfDir, 0777, true);
    $randomDigits = substr(bin2hex(random_bytes(4)), 0, 8);
    $safeMrn = preg_replace('/[^a-zA-Z0-9]/', '', $pres['mrn'] ?? '000');
    $pdfName = sprintf("prescription%s-%s-%s.pdf", $safeMrn, $prescription_id, $randomDigits);
    $pdfPathRel = 'prescriptions/'.$pdfName;
    $pdfFull    = $pdfDir . $pdfName;

    $pdf = new MyriadPDFWithBarcode();
    $pdf->AddPage();
    $pdf->SetFont('MyriadPro-Regular', '', 12);

    $pageW = $pdf->GetPageWidth();
    $logoPath = __DIR__.'/media/logoblack.png';
    if (file_exists($logoPath)) {
        $logoW = 80; $xLogo = ($pageW - $logoW) / 2;
        $pdf->Image($logoPath, $xLogo, 10, $logoW);
    } else { error_log("PDF Logo not found at: " . $logoPath); $pdf->Ln(20); }
    $pdf->Ln(1);
    $pdf->SetFont('MyriadPro-Regular', '', 16); $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell(0, 7, '11-C, Mehran Commerical, DC Colony, Gujranwala Cantt.', 0, 1, 'C');
    $pdf->SetFont('MyriadPro-Regular', '', 12);
    $pdf->Cell(0, 5, 'hospital0', 0, 1, 'C');
    $pdf->Ln(1);
    $pdf->SetDrawColor(50, 50, 50); $pdf->SetLineWidth(0.6);
    $lineWidth = 120; $xLine = ($pdf->GetPageWidth() - $lineWidth) / 2; $yLine = $pdf->GetY() + 2;
    $pdf->Line($xLine, $yLine, $xLine + $lineWidth, $yLine);
    $pdf->Ln(8);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('MyriadPro-Regular', '', 14);
    $pdf->Cell(0, 6, 'PRESCRIPTION', 0, 1, 'C'); $pdf->Ln(2);

    $barcodeData = sprintf("MRN%s-PID%s", $safeMrn, $prescription_id);
    $barcodeW = 70; $barcodeH = 10; $barcodeX = ($pdf->GetPageWidth() - $barcodeW) / 2; $barcodeY = $pdf->GetY() + 2;
    $pdf->Code128($barcodeX, $barcodeY, $barcodeData, $barcodeW, $barcodeH);
    $pdf->SetXY($barcodeX, $barcodeY + $barcodeH); $pdf->SetFont('MyriadPro-Regular', '', 7);
    $pdf->Cell($barcodeW, 4, $barcodeData, 0, 1, 'C'); $pdf->Ln(6);

    $pdf->SetFont('MyriadPro-Regular','',12);
    $pName = $patientRow['full_name'] ?? 'Unknown'; $pGender = $patientRow['gender'] ?? 'N/A'; $pPhone = $patientRow['phone'] ?? 'N/A'; $pAddr = $patientRow['address'] ?? 'N/A'; $pAgeStr = 'N/A';
    if (isset($patientRow['age']) && isset($patientRow['age_unit'])) {
         $ageVal = htmlspecialchars($patientRow['age']); $ageUnit = htmlspecialchars($patientRow['age_unit']);
         if ($ageVal == 1) { $ageUnit = rtrim($ageUnit, 's'); } else if (!str_ends_with($ageUnit, 's') && $ageUnit != '') { $ageUnit .= 's'; }
         $pAgeStr = $ageVal . ' ' . ucfirst($ageUnit);
    }
    $pdf->rowData('Patient Name:', $pName); $pdf->rowData('Age:', $pAgeStr); $pdf->rowData('Gender:', $pGender);
    $pdf->rowData('Phone:', $pPhone); $pdf->rowData('Address:', $pAddr); $pdf->rowData('MRN:', $pres['mrn'] ?? 'N/A');
    $pdf->rowData('Prescription Date:', !empty($pres['created_at']) ? date('d-M-Y H:i', strtotime($pres['created_at'])) : 'N/A'); $pdf->Ln(4);
    if(!empty($pres['signed_by'])){ $pdf->SetTextColor(0, 128, 0); $pdf->rowData('Status:', 'Signed/Locked on '.(!empty($pres['signed_at']) ? date('d-M-Y H:i', strtotime($pres['signed_at'])) : 'N/A'));
    } else { $pdf->SetTextColor(200, 0, 0); $pdf->rowData('Status:', 'Not Signed / Unlocked'); }
    $pdf->SetTextColor(0, 0, 0); $pdf->Ln(6);

    if(!empty($pres['body'])){ $pdf->SetFont('MyriadPro-Regular','',12); $pdf->MultiCell(0, 6, htmlspecialchars_decode($pres['body'])); $pdf->Ln(5); }
    if ($meds) {
        $pdf->SetFont('MyriadPro-Regular', '', 13); $pdf->Cell(0, 8, 'Medicines Prescribed:', 0, 1); $pdf->SetFont('MyriadPro-Regular', '', 14); $pdf->Ln(1);
        foreach($meds as $idx => $m) {
            $medLine = sprintf("%d. %s %s [%s] (%s) - %s", ($idx + 1), htmlspecialchars($m['medicine_name']), htmlspecialchars($m['dosage'] ?? ''), htmlspecialchars($m['route'] ?? 'N/A'), htmlspecialchars($m['frequency'] ?? ''), htmlspecialchars($m['duration'] ?? ''));
            $medLine = preg_replace('/\s{2,}/', ' ', trim($medLine));
            $pdf->MultiCell(0, 7, $medLine, 0, 'L'); $pdf->Ln(2);
        }
    } else { $pdf->SetFont('MyriadPro-Regular','',12); $pdf->Cell(0, 7, 'No specific medicines listed.', 0, 1); }

    $footerTextY = $pdf->GetPageHeight() - $pdf->GetEffectiveBottomMargin() - 15;
    if ($pdf->GetY() > $footerTextY - 5) { $pdf->Ln(8); $footerTextY = $pdf->GetY(); }
    if ($footerTextY > $pdf->GetPageHeight() - $pdf->GetEffectiveBottomMargin() - 10) { $footerTextY = $pdf->GetPageHeight() - $pdf->GetEffectiveBottomMargin() - 10; }
    $pdf->SetY($footerTextY);
    $pdf->SetFont('MyriadPro-Regular', '', 9); $pdf->SetTextColor(80, 80, 80);
    $creator = $pres['doctor_name'] ?? ($pres['creator_username'] ?? 'System');
    $pdf->Cell(0, 6, 'This prescription was electronically generated by ' . $creator . '.', 0, 1, 'C');
    $pdf->SetFont('MyriadPro-Regular', '', 10); $pdf->Cell(0, 6, 'hospital0', 0, 1, 'C');

    try {
        $pdf->Output('F', $pdfFull);
        $upd = $pdo->prepare("UPDATE prescriptions SET pdf_path=? WHERE prescription_id=?");
        $upd->execute([$pdfPathRel, $prescription_id]);
        return $pdfFull;
    } catch (Exception $e) { error_log("Error saving PDF for prescription ID {$prescription_id}: " . $e->getMessage()); return null; }
}

function forceDownload($filePath) {
    if (!file_exists($filePath) || !is_readable($filePath)) { echo "Error: Prescription file could not be found."; exit; }
    header('Content-Description: File Transfer'); header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    header('Content-Transfer-Encoding: binary'); header('Expires: 0'); header('Cache-Control: must-revalidate'); header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));
    ob_clean(); flush(); readfile($filePath); exit;
}

// --- AJAX MRN Lookup Handler ---
if(isset($_GET['action']) && $_GET['action']==='lookup_mrn'){
    $mrnAjax = trim($_GET['mrn'] ?? '');
    header('Content-Type: application/json');
    if($mrnAjax===''){ echo json_encode(['success'=>false,'error'=>'No MRN provided.']); exit; }
    $stmtAjax=$pdo->prepare("SELECT full_name FROM patients WHERE mrn=? LIMIT 1");
    $stmtAjax->execute([$mrnAjax]);
    $r=$stmtAjax->fetch(PDO::FETCH_ASSOC);
    if(!$r){ echo json_encode(['success'=>false,'error'=>'No patient found with that MRN']); }
    else { echo json_encode(['success'=>true, 'full_name'=>$r['full_name']]); }
    exit;
}

// --- Main Logic Router ---
$prescriptionData = null; $medicineData = [];
if (!$no_rights) {
    switch($action){
        case 'create':
            // Business logic for creation remains identical...
            if($_SERVER['REQUEST_METHOD']==='POST'){
                $mrn=trim($_POST['mrn']??''); $pname=trim($_POST['patient_name']??''); $body=trim($_POST['body']??''); $medicines=$_POST['medicine']??[]; $validationError=false;
                if($mrn===''||$pname===''){ $message="Error: Please enter MRN and ensure patient name is auto-populated."; $validationError=true;
                } else { $chk=$pdo->prepare("SELECT mrn, gender, age, age_unit FROM patients WHERE mrn=? LIMIT 1"); $chk->execute([$mrn]); $patientInfo=$chk->fetch(PDO::FETCH_ASSOC); if(!$patientInfo){ $message="Error: No patient found with MRN = $mrn"; $validationError=true; } }
                if(!$validationError && !empty($medicines)){ foreach($medicines as $index=>$m){ $mName=trim($m['name']??''); if($mName!==''){ $mDos=trim($m['dosage']??''); $mFreq=trim($m['frequency']??''); $mDur=trim($m['duration']??''); if($mDos===''||$mFreq===''||$mDur===''){ $message="Error: For medicine '{$mName}', please fill all fields."; $validationError=true; break; } } } }
                if(!$validationError){ $ins=$pdo->prepare("INSERT INTO prescriptions (mrn, patient_name, gender, age, age_unit, body, created_by) VALUES (?,?,?,?,?,?,?)");
                    try{ $ins->execute([$mrn, $pname, $patientInfo['gender'], $patientInfo['age'], $patientInfo['age_unit'], $body, $currentUserId]); $newId=$pdo->lastInsertId();
                        if(!empty($medicines)){ $sqlMed="INSERT INTO prescription_medicines (prescription_id, medicine_name, route, dosage, frequency, duration) VALUES (?,?,?,?,?,?)"; $stmtMed=$pdo->prepare($sqlMed);
                            foreach($medicines as $m){ $mName=trim($m['name']??''); if($mName==='')continue; $mRoute=$m['route']??'oral'; $mDos=trim($m['dosage']??''); $mFreq=trim($m['frequency']??''); $mDur=trim($m['duration']??''); $stmtMed->execute([$newId, $mName, $mRoute, $mDos, $mFreq, $mDur]); } }
                        header("Location: prescriptions.php?action=view&prescription_id={$newId}&status=created"); exit;
                    } catch(Exception $ex){ error_log("Error creating prescription: ".$ex->getMessage()); $message="Database Error: Could not create. ".$ex->getMessage(); } }
                if($validationError){ break; }
            }
            break;
        case 'update':
            // Business logic for update remains identical...
            $pid=(int)($_GET['prescription_id']??0); if($pid<1){$message="Error: No prescription ID provided."; $action=''; break;}
            $st=$pdo->prepare("SELECT * FROM prescriptions WHERE prescription_id=? LIMIT 1"); $st->execute([$pid]); $prescriptionData=$st->fetch(PDO::FETCH_ASSOC);
            if(!$prescriptionData){$message="Error: Prescription #{$pid} not found."; $action=''; break;}
            if(!empty($prescriptionData['signed_by'])){ $message="Error: Cannot update a signed/locked prescription (#{$pid})."; $action='view'; $_GET['prescription_id']=$pid; goto view_case_logic; break; }
            $mSt=$pdo->prepare("SELECT * FROM prescription_medicines WHERE prescription_id=? ORDER BY med_id ASC"); $mSt->execute([$pid]); $medicineData=$mSt->fetchAll(PDO::FETCH_ASSOC);
            if($_SERVER['REQUEST_METHOD']==='POST'){ $mrn=trim($_POST['mrn']??''); $pname=trim($_POST['patient_name']??''); $body=trim($_POST['body']??''); $medicines=$_POST['medicine']??[]; $validationError=false;
                if($mrn===''||$pname===''){ $message="Error: MRN or patient name empty."; $validationError=true;
                } else { $chk=$pdo->prepare("SELECT mrn, gender, age, age_unit FROM patients WHERE mrn=? LIMIT 1"); $chk->execute([$mrn]); $patientInfo=$chk->fetch(PDO::FETCH_ASSOC); if(!$patientInfo){ $message="Error: No patient found with MRN = $mrn"; $validationError=true; } }
                if(!$validationError && !empty($medicines)){ foreach($medicines as $index=>$m){ $mName=trim($m['name']??''); if($mName!==''){ $mDos=trim($m['dosage']??''); $mFreq=trim($m['frequency']??''); $mDur=trim($m['duration']??''); if($mDos===''||$mFreq===''||$mDur===''){ $message="Error: For '{$mName}', please fill all fields."; $validationError=true; break; } } } }
                if(!$validationError){ $upd=$pdo->prepare("UPDATE prescriptions SET mrn=?, patient_name=?, gender=?, age=?, age_unit=?, body=? WHERE prescription_id=? LIMIT 1");
                    $upd->execute([$mrn, $pname, $patientInfo['gender'], $patientInfo['age'], $patientInfo['age_unit'], $body, $pid]);
                    $pdo->prepare("DELETE FROM prescription_medicines WHERE prescription_id=?")->execute([$pid]);
                    if(!empty($medicines)){ $sqlMed="INSERT INTO prescription_medicines (prescription_id, medicine_name, route, dosage, frequency, duration) VALUES (?,?,?,?,?,?)"; $stmtMed=$pdo->prepare($sqlMed);
                        foreach($medicines as $m){ $mName=trim($m['name']??''); if($mName==='')continue; $mRoute=$m['route']??'oral'; $mDos=trim($m['dosage']??''); $mFreq=trim($m['frequency']??''); $mDur=trim($m['duration']??''); $stmtMed->execute([$pid, $mName, $mRoute, $mDos, $mFreq, $mDur]); } }
                    header("Location: prescriptions.php?action=view&prescription_id={$pid}&status=updated"); exit; }
                if($validationError){ break; }
            }
            break;
        case 'sign':
            // Business logic for signing remains identical...
            $pid=(int)($_GET['prescription_id']??0); if($pid<1){$message="Error: No prescription ID to sign."; $action=''; break;}
            $ch=$pdo->prepare("SELECT signed_by FROM prescriptions WHERE prescription_id=? LIMIT 1"); $ch->execute([$pid]); $rw=$ch->fetch(PDO::FETCH_ASSOC);
            if(!$rw){ $message="Error: Prescription #{$pid} not found."; $action=''; break; }
            if(!empty($rw['signed_by'])){ $message="Notice: Prescription #{$pid} is already signed."; $action='view'; $_GET['prescription_id']=$pid; goto view_case_logic; break; }
            $up=$pdo->prepare("UPDATE prescriptions SET signed_by=?, signed_at=NOW() WHERE prescription_id=? LIMIT 1"); $up->execute([$currentUserId, $pid]);
            generatePrescriptionPDF($pdo, $pid); header("Location: prescriptions.php?action=view&prescription_id={$pid}&status=signed"); exit;
            break;
        case 'retrieve':
            // Business logic for search remains identical...
            if($_SERVER['REQUEST_METHOD']==='POST'){ $searchName=trim($_POST['search_name']??''); $searchDate=trim($_POST['search_date']??'');
                $searchPage=(int)($_POST['search_page']??1); if($searchPage<1)$searchPage=1; $offset=($searchPage-1)*$perPage;
                $whereParts=[]; $params=[];
                if($searchName!==''){ $whereParts[]="(pr.patient_name LIKE :pname OR pt.full_name LIKE :fname)"; $params[':pname']="%$searchName%"; $params[':fname']="%$searchName%"; }
                if($searchDate!==''){ $whereParts[]="DATE(pr.created_at)=:dt"; $params[':dt']=$searchDate; }
                $whereSQL=$whereParts?"WHERE ".implode(" AND ",$whereParts):'';
                $cSQL="SELECT COUNT(pr.prescription_id) FROM prescriptions pr LEFT JOIN patients pt ON pt.mrn=pr.mrn $whereSQL";
                $stC=$pdo->prepare($cSQL); $stC->execute($params); $total=$stC->fetchColumn(); $searchTotalPages=(int)ceil($total/$perPage);
                $dSQL="SELECT pr.*, us.full_name AS doctor_name, pt.full_name AS patient_full_name FROM prescriptions pr LEFT JOIN users us ON pr.created_by=us.user_id LEFT JOIN patients pt ON pt.mrn=pr.mrn $whereSQL ORDER BY pr.created_at DESC LIMIT :lim OFFSET :off";
                $stD=$pdo->prepare($dSQL); foreach($params as $key=>$val){$stD->bindValue($key,$val);}
                $stD->bindValue(':lim',$perPage,PDO::PARAM_INT); $stD->bindValue(':off',$offset,PDO::PARAM_INT); $stD->execute();
                $searchPrescriptions=$stD->fetchAll(PDO::FETCH_ASSOC);
                if(!$searchPrescriptions){ $message="No matching prescriptions found."; }
            }
            break;
        case 'show':
            // Business logic for showing all remains identical...
            $page=(int)($_GET['page']??1); if($page<1)$page=1; $offset=($page-1)*$perPage;
            $cnt=$pdo->query("SELECT COUNT(*) FROM prescriptions"); $totalCount=$cnt->fetchColumn(); $totalPages=(int)ceil($totalCount/$perPage);
            $sqlShow="SELECT pr.*, us.full_name AS doctor_name, pt.full_name AS patient_full_name FROM prescriptions pr LEFT JOIN users us ON pr.created_by=us.user_id LEFT JOIN patients pt ON pt.mrn=pr.mrn ORDER BY pr.created_at DESC LIMIT :lim OFFSET :off";
            $sh=$pdo->prepare($sqlShow); $sh->bindValue(':lim',$perPage,PDO::PARAM_INT); $sh->bindValue(':off',$offset,PDO::PARAM_INT);
            $sh->execute(); $prescriptions=$sh->fetchAll(PDO::FETCH_ASSOC);
            if(!$prescriptions && $page==1){ $message="No prescriptions found in the system."; } elseif(!$prescriptions && $page>1){ $message="No more prescriptions on this page."; }
            break;
        case 'view':
            // Business logic for viewing and PDF handling remains identical...
            view_case_logic: $pid=(int)($_GET['prescription_id']??0); if($pid<1){ $message="Error: No ID specified."; $action=''; break; }
            if(isset($_GET['download'])||isset($_GET['print'])){ $pdfFullPath=generatePrescriptionPDF($pdo, $pid);
                if(!$pdfFullPath){ $message="Error: Could not generate or find the PDF file for prescription #{$pid}."; goto fetch_view_data; break; }
                if(isset($_GET['download'])){ forceDownload($pdfFullPath); exit; }
                if(isset($_GET['print'])){ $relativePath=str_replace(__DIR__.'/','',$pdfFullPath); $urlPath='/'.(strpos($relativePath,'prescriptions/')===0?$relativePath:"prescriptions/".basename($relativePath)); header("Location: $urlPath"); exit; }
            }
            fetch_view_data:
            $vSQL="SELECT pr.*, us.full_name AS doctor_name, pt.full_name AS patient_full_name, pt.gender AS patient_gender, pt.phone AS patient_phone, pt.address AS patient_address, pt.age AS patient_db_age, pt.age_unit AS patient_db_age_unit FROM prescriptions pr LEFT JOIN users us ON pr.created_by=us.user_id LEFT JOIN patients pt ON pt.mrn=pr.mrn WHERE pr.prescription_id=? LIMIT 1";
            $sv=$pdo->prepare($vSQL); $sv->execute([$pid]); $prescriptionData=$sv->fetch(PDO::FETCH_ASSOC);
            if(!$prescriptionData){ if(empty($message)){$message="Error: Prescription #{$pid} not found.";} $action=''; break; }
            $sm=$pdo->prepare("SELECT * FROM prescription_medicines WHERE prescription_id=? ORDER BY med_id ASC"); $sm->execute([$pid]); $medicineData=$sm->fetchAll(PDO::FETCH_ASSOC);
            if(isset($_GET['status'])){ switch($_GET['status']){ case 'created':$message="Success: Prescription #{$pid} created.";break; case 'updated':$message="Success: Prescription #{$pid} updated.";break; case 'signed':$message="Success: Prescription #{$pid} signed and locked.";break; } }
            break;
        default: $action = ''; break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - Prescriptions - hospital0</title>
    <link rel="icon" href="/media/sitelogo.png" type="image/png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* --- MODERNIZATION & BEAUTIFICATION STYLES --- */
        body { background-image: none !important; background-color: #121212 !important; overflow-x: hidden; display: flex; min-height: 100vh; flex-direction: column; }
        main { flex: 1 0 auto; }
        
        @keyframes move-twink-back { from { background-position: 0 0; } to { background-position: -10000px 5000px; } }
        .stars, .twinkling { position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; display: block; z-index: -3; }
        .stars { background: #000 url(/media/stars.png) repeat top center; }
        .twinkling { background: transparent url(/media/twinkling.png) repeat top center; animation: move-twink-back 200s linear infinite; }
        #dna-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; opacity: 0.3; }
        
        h3.center-align, h5.white-text { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
        .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }

        .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 2rem; }
        .card-panel.glass-card { padding: 2rem; }

        .input-field input:not([type]), .input-field input[type=text]:not(.browser-default), .input-field input[type=date]:not(.browser-default), .input-field input[type=number]:not(.browser-default), .input-field textarea.materialize-textarea {
            color: #ffffff !important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important;
        }
        .input-field label { color: #bdbdbd !important; }
        .input-field label.active { color: #00e5ff !important; }
        .input-field input:focus + label, .input-field textarea:focus + label { color: #00e5ff !important; }
        .input-field input:focus, .input-field textarea:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
        
        ul.dropdown-content { background-color: #2a2a2a; } .dropdown-content li>span { color: #ffffff; }
        .select-wrapper.active .caret { color: #00e5ff !important; }
        .select-wrapper .caret { color: #bdbdbd !important; }
        
        .message-area { padding: 10px 15px; margin: 20px 0; border-radius: 8px; text-align: center; border: 1px solid; }
        .message-area.error { background-color: rgba(244, 67, 54, 0.25); color: #ffcdd2; border-color: rgba(239, 154, 154, 0.5); }
        .message-area.success { background-color: rgba(76, 175, 80, 0.25); color: #c8e6c9; border-color: rgba(129, 199, 132, 0.5); }
        .message-area.notice { background-color: rgba(255, 235, 59, 0.25); color: #fff9c4; border-color: rgba(255, 245, 157, 0.5); }
        
        .btn, .btn-small { margin: 5px; } .btn .material-icons { vertical-align: middle; }
        
        .medicine-row { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; margin-bottom: 1rem !important; }
        .medicine-row .input-field { margin: 0 !important; flex-grow: 1; }
        .medicine-row .input-field.med-name { flex-basis: 25%; }
        .medicine-row .input-field.med-route { flex-basis: 15%; }
        .medicine-row .input-field.med-dosage { flex-basis: 15%; }
        .medicine-row .input-field.med-frequency { flex-basis: 15%; }
        .medicine-row .input-field.med-duration { flex-basis: 15%; }
        .medicine-row .input-field.med-remove { flex-basis: 5%; flex-grow: 0; }
        
        table.striped>tbody>tr:nth-child(odd) { background-color: rgba(255, 255, 255, 0.05); }
        table { border: none; }
        th { border-bottom: 1px solid rgba(255, 255, 255, 0.3); }
        td, th { padding: 15px 10px; }

        .pagination li a { color: #fff; }
        .pagination li.active { background-color: #00bfa5; }
        .pagination li.disabled a, .pagination li.disabled span { color: #777; }
    </style>
</head>
<body>

<!-- Background Elements -->
<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>

<main>
<div class="container">
    <h3 class="center-align white-text" style="margin-top:30px;">Prescriptions Management</h3>
    <hr class="white-line">

    <?php if ($no_rights): ?>
        <div class="card-panel red darken-2 center-align glass-card" style="padding: 20px; margin-top: 50px; backdrop-filter: none !important; background: rgba(211, 47, 47, 0.5) !important;">
            <h5 class="white-text"><i class="material-icons left">lock_outline</i>Access Denied</h5>
            <p>You do not have permission to access this module.</p>
        </div>
    <?php else: ?>
        <?php if($message):
            $messageType = 'notice';
            if (stripos($message, 'error') !== false) $messageType = 'error';
            if (stripos($message, 'success') !== false) $messageType = 'success';
        ?>
        <div class="message-area <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php // ====== Main Menu (Default View) ======
        if($action === ''): ?>
        <div class="glass-card">
            <h5 class="center-align white-text">Choose an action:</h5>
            <div class="row center-align" style="margin-top:40px;">
                <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 15px;">
                    <a href="?action=create" class="btn waves-effect waves-light" style="background-color:#00bfa5;"><i class="material-icons left">add_circle_outline</i>Create New</a>
                    <a href="?action=retrieve" class="btn waves-effect waves-light blue"><i class="material-icons left">search</i>Search</a>
                    <a href="?action=show" class="btn waves-effect waves-light grey"><i class="material-icons left">list</i>Show All</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php // ====== CREATE OR UPDATE FORM ======
        if($action==='create' || ($action==='update' && isset($prescriptionData))):
            $isUpdate = ($action === 'update');
            $formAction = $isUpdate ? "?action=update&prescription_id=".(int)$prescriptionData['prescription_id'] : "?action=create";
            $formTitle = $isUpdate ? "Update Prescription #".(int)$prescriptionData['prescription_id'] : "Create New Prescription";
        ?>
        <div class="glass-card">
            <h5 class="white-text"><?php echo $formTitle; ?></h5>
            <form method="POST" action="<?php echo $formAction; ?>">
              <div class="row">
                <div class="input-field col s12 m4">
                  <i class="material-icons prefix">account_circle</i>
                  <input type="text" id="mrn" name="mrn" required onblur="lookupMRN()" value="<?php echo htmlspecialchars($prescriptionData['mrn'] ?? ($_POST['mrn'] ?? '')); ?>">
                  <label for="mrn" class="active">Patient MRN *</label>
                </div>
                <div class="input-field col s12 m8">
                  <i class="material-icons prefix">person</i>
                  <input type="text" id="patient_name" name="patient_name" readonly required value="<?php echo htmlspecialchars($prescriptionData['patient_name'] ?? ($_POST['patient_name'] ?? '')); ?>">
                  <label for="patient_name" class="active">Patient Name (auto-filled) *</label>
                  <span class="helper-text" style="color:#bdbdbd;">Enter MRN and click away to lookup.</span>
                </div>
              </div>
              <div class="row">
                <div class="input-field col s12">
                  <i class="material-icons prefix">article</i>
                  <textarea id="body" name="body" class="materialize-textarea white-text" data-length="2048"><?php echo htmlspecialchars($prescriptionData['body'] ?? ($_POST['body'] ?? '')); ?></textarea>
                  <label for="body" class="active">Prescription Body (Instructions, Notes, etc.)</label>
                </div>
              </div>

              <h6 class="white-text" style="margin-top:2rem; text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Add Medicines (Optional)</h6>
              <div id="medicineContainer">
                <?php $medsToDisplay = $isUpdate ? $medicineData : ($_POST['medicine'] ?? []);
                if(!empty($medsToDisplay)):
                  foreach($medsToDisplay as $idx=>$m): ?>
                  <div class="row medicine-row">
                    <div class="input-field med-name"><input type="text" name="medicine[<?php echo $idx;?>][name]" placeholder="Medicine Name *" value="<?php echo htmlspecialchars($m['medicine_name'] ?? ($m['name'] ?? ''));?>" required></div>
                    <div class="input-field med-route">
                      <select name="medicine[<?php echo $idx;?>][route]" required>
                        <option value="oral" <?php if(($m['route']??'')==='oral')echo'selected';?>>oral</option>
                        <option value="topical" <?php if(($m['route']??'')==='topical')echo'selected';?>>topical</option>
                        <option value="IV" <?php if(($m['route']??'')==='IV')echo'selected';?>>IV</option>
                        <option value="IM" <?php if(($m['route']??'')==='IM')echo'selected';?>>IM</option>
                        <option value="INH" <?php if(($m['route']??'')==='INH')echo'selected';?>>INH</option>
                        <option value="Other" <?php if(($m['route']??'')==='Other')echo'selected';?>>Other</option>
                      </select>
                    </div>
                    <div class="input-field med-dosage"><input type="text" name="medicine[<?php echo $idx;?>][dosage]" placeholder="Dosage *" value="<?php echo htmlspecialchars($m['dosage'] ?? '');?>" required></div>
                    <div class="input-field med-frequency"><input type="text" name="medicine[<?php echo $idx;?>][frequency]" placeholder="Frequency *" value="<?php echo htmlspecialchars($m['frequency'] ?? '');?>" required></div>
                    <div class="input-field med-duration"><input type="text" name="medicine[<?php echo $idx;?>][duration]" placeholder="Duration *" value="<?php echo htmlspecialchars($m['duration'] ?? '');?>" required></div>
                    <div class="input-field med-remove"><button type="button" class="btn-small red" onclick="removeMedicineRow(this)"><i class="material-icons">remove</i></button></div>
                  </div>
                <?php endforeach; endif;?>
              </div>
              <button type="button" class="btn waves-effect waves-light" onclick="addMedicineRow()"><i class="material-icons left">add</i>Medicine</button>

              <div class="row center-align" style="margin-top:30px;">
                <button type="submit" class="btn waves-effect waves-light" style="background-color: #00bfa5;"><?php echo $isUpdate ? 'Save Changes' : 'Save Prescription'; ?></button>
                <a href="prescriptions.php" class="btn waves-effect waves-light grey">Cancel</a>
              </div>
            </form>
        </div>
        <?php endif; ?>


        <?php // ====== RETRIEVE FORM (Search) ======
        if($action==='retrieve' && $_SERVER['REQUEST_METHOD']!=='POST'): ?>
        <div class="glass-card">
            <h5 class="white-text">Search Prescriptions</h5>
            <form method="POST" action="?action=retrieve">
              <div class="row">
                <div class="input-field col s12 m6">
                  <i class="material-icons prefix">person_search</i>
                  <input id="search_name" type="text" name="search_name">
                  <label for="search_name">Patient Name (or partial)</label>
                </div>
                <div class="input-field col s12 m4">
                  <i class="material-icons prefix">event</i>
                  <input id="search_date" type="date" name="search_date" class="white-text">
                  <label for="search_date">Created Date</label>
                </div>
                 <div class="input-field col s12 m2">
                   <button type="submit" class="btn waves-effect waves-light blue">Search</button>
                 </div>
              </div>
               <a href="prescriptions.php" class="btn waves-effect waves-light grey">Back to Menu</a>
            </form>
        </div>
        <?php endif; ?>


        <?php // ====== RETRIEVE RESULTS / SHOW ALL RESULTS ======
        $resultsToShow = null; $paginationTotalPages = 0; $currentPage = 1; $searchParams = '';
        if ($action === 'retrieve' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($searchPrescriptions)) {
            $resultsToShow = $searchPrescriptions; $paginationTotalPages = $searchTotalPages ?? 0; $currentPage = $searchPage ?? 1;
            $searchParams = '&search_name='.urlencode($searchName ?? '').'&search_date='.urlencode($searchDate ?? '');
            echo '<h5 class="white-text">Search Results</h5>';
        } elseif ($action === 'show' && isset($prescriptions)) {
            $resultsToShow = $prescriptions; $paginationTotalPages = $totalPages ?? 0; $currentPage = $page ?? 1;
            echo '<h5 class="white-text">All Prescriptions (Newest First)</h5>';
        }
        if ($resultsToShow !== null && !empty($resultsToShow)): $startNum = ($currentPage - 1) * $perPage + 1;
        ?>
            <table class="striped responsive-table white-text glass-card">
              <thead><tr><th>#</th><th>Patient Name</th><th>MRN</th><th>Created At</th><th>Created By</th><th>Signed?</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach($resultsToShow as $idx=>$pr):
                  $isSigned = (!empty($pr['signed_by']));
                  $displayName = $pr['patient_full_name'] ?? ($pr['patient_name'] ?? 'N/A');
                ?>
                <tr>
                  <td><?php echo $startNum + $idx;?></td>
                  <td><?php echo htmlspecialchars($displayName);?></td>
                  <td><?php echo htmlspecialchars($pr['mrn'] ?? 'N/A');?></td>
                  <td><?php echo htmlspecialchars(date('d-M-Y H:i', strtotime($pr['created_at'])));?></td>
                  <td><?php echo htmlspecialchars($pr['doctor_name'] ?? 'Unknown');?></td>
                  <td><?php echo ($isSigned ? '<span style="color: lightgreen;">Yes</span>' : '<span style="color: yellow;">No</span>');?></td>
                  <td>
                    <a href="?action=view&prescription_id=<?php echo (int)$pr['prescription_id'];?>" class="btn-small waves-effect waves-light blue tooltipped" data-tooltip="View"><i class="material-icons">visibility</i></a>
                    <?php if (!$isSigned): ?>
                      <a href="?action=update&prescription_id=<?php echo (int)$pr['prescription_id'];?>" class="btn-small waves-effect waves-light grey tooltipped" data-tooltip="Update"><i class="material-icons">edit</i></a>
                      <a href="?action=sign&prescription_id=<?php echo (int)$pr['prescription_id'];?>" class="btn-small waves-effect waves-light green tooltipped" data-tooltip="Sign/Lock" onclick="return confirm('Are you sure? This cannot be undone.');"><i class="material-icons">lock_outline</i></a>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach;?>
              </tbody>
            </table>
            <?php if ($paginationTotalPages > 1): ?>
            <ul class="pagination center-align">
              <li class="<?php echo ($currentPage <= 1) ? 'disabled' : 'waves-effect'; ?>"><a href="?action=<?php echo $action; ?>&page=<?php echo $currentPage - 1; ?><?php echo $searchParams; ?>"><i class="material-icons">chevron_left</i></a></li>
              <?php $range = 2; $start = max(1, $currentPage - $range); $end = min($paginationTotalPages, $currentPage + $range);
                  if ($start > 1) { echo '<li class="waves-effect"><a href="?action='.$action.'&page=1'.$searchParams.'">1</a></li>'; if ($start > 2) { echo '<li class="disabled"><span>...</span></li>'; } }
                  for ($i = $start; $i <= $end; $i++): ?>
              <li class="<?php echo ($i == $currentPage) ? 'active blue' : 'waves-effect'; ?>"><a href="?action=<?php echo $action; ?>&page=<?php echo $i; ?><?php echo $searchParams; ?>"><?php echo $i; ?></a></li>
              <?php endfor;
                  if ($end < $paginationTotalPages) { if ($end < $paginationTotalPages - 1) { echo '<li class="disabled"><span>...</span></li>'; } echo '<li class="waves-effect"><a href="?action='.$action.'&page='.$paginationTotalPages.$searchParams.'">'.$paginationTotalPages.'</a></li>'; } ?>
              <li class="<?php echo ($currentPage >= $paginationTotalPages) ? 'disabled' : 'waves-effect'; ?>"><a href="?action=<?php echo $action; ?>&page=<?php echo $currentPage + 1; ?><?php echo $searchParams; ?>"><i class="material-icons">chevron_right</i></a></li>
            </ul>
            <?php endif; ?>
            <div class="center-align" style="margin-top: 20px;"><a href="prescriptions.php" class="btn waves-effect waves-light grey">Back to Menu</a></div>
        <?php elseif (($action === 'retrieve' && $_SERVER['REQUEST_METHOD'] === 'POST') || ($action === 'show')): ?>
            <p class="white-text center-align">No prescriptions found.</p>
            <div class="center-align" style="margin-top: 20px;"><a href="prescriptions.php" class="btn waves-effect waves-light grey">Back to Menu</a></div>
        <?php endif; ?>
        
        <?php // ====== VIEW SINGLE PRESCRIPTION ======
        if($action==='view' && !empty($prescriptionData)):
            $viewPres = $prescriptionData; $viewMeds = $medicineData; $isSigned = !empty($viewPres['signed_by']);
            $patientAgeStr = 'N/A';
            if (isset($viewPres['patient_db_age']) && isset($viewPres['patient_db_age_unit'])) {
                $ageVal=htmlspecialchars($viewPres['patient_db_age']); $ageUnit=htmlspecialchars($viewPres['patient_db_age_unit']); if($ageVal==1){$ageUnit=rtrim($ageUnit,'s');}else if(!str_ends_with($ageUnit,'s')&&$ageUnit!=''){$ageUnit.='s';} $patientAgeStr=$ageVal.' '.ucfirst($ageUnit);
            } elseif(isset($viewPres['age'])&&isset($viewPres['age_unit'])){$ageVal=htmlspecialchars($viewPres['age']);$ageUnit=htmlspecialchars($viewPres['age_unit']);if($ageVal==1){$ageUnit=rtrim($ageUnit,'s');}else if(!str_ends_with($ageUnit,'s')&&$ageUnit!=''){$ageUnit.='s';}$patientAgeStr=$ageVal.' '.ucfirst($ageUnit);}
        ?>
            <h5 class="white-text">Viewing Prescription #<?php echo (int)$viewPres['prescription_id']; ?></h5>
            <div class="card-panel glass-card white-text">
                <p><strong>Patient:</strong> <?php echo htmlspecialchars($viewPres['patient_full_name'] ?? ($viewPres['patient_name'] ?? 'N/A'));?> (MRN: <?php echo htmlspecialchars($viewPres['mrn'] ?? 'N/A'); ?>)<br>
                   <strong>Gender:</strong> <?php echo htmlspecialchars($viewPres['patient_gender'] ?? ($viewPres['gender'] ?? 'N/A'));?><br>
                   <strong>Age:</strong> <?php echo $patientAgeStr; ?><br>
                   <strong>Phone:</strong> <?php echo htmlspecialchars($viewPres['patient_phone'] ?? 'N/A');?><br>
                   <strong>Address:</strong> <?php echo htmlspecialchars($viewPres['patient_address'] ?? 'N/A');?></p>
                <hr style="border-color: rgba(255,255,255,0.3);">
                <p><strong>Created At:</strong> <?php echo htmlspecialchars(date('d-M-Y H:i', strtotime($viewPres['created_at'])));?><br>
                   <strong>Created By:</strong> <?php echo htmlspecialchars($viewPres['doctor_name'] ?? 'Unknown');?><br>
                   <strong>Status:</strong> <?php if($isSigned):?><span style="color:lightgreen;">Signed/Locked on <?php echo htmlspecialchars(date('d-M-Y H:i', strtotime($viewPres['signed_at'])));?></span><?php else:?><span style="color:yellow;">Not Signed / Unlocked</span><?php endif;?></p>
                <?php if(!empty($viewPres['body'])):?><hr style="border-color: rgba(255,255,255,0.3);">
                    <h6>Prescription Notes:</h6><div style="background-color:rgba(0,0,0,0.2); padding:15px; border-radius: 4px; margin-top: 5px; white-space: pre-wrap;"><?php echo nl2br(htmlspecialchars($viewPres['body']));?></div><?php endif;?>
                <?php if(!empty($viewMeds)):?><hr style="border-color: rgba(255,255,255,0.3);">
                    <h6>Medicines:</h6><ul style="background-color:rgba(0,0,0,0.2); padding:15px; border-radius: 4px; margin-top: 5px; list-style-type: decimal; padding-left: 30px;">
                    <?php foreach($viewMeds as $md):?><li><strong><?php echo htmlspecialchars($md['medicine_name']);?></strong> <?php echo htmlspecialchars($md['dosage']);?> [<?php echo htmlspecialchars($md['route']);?>] (<?php echo htmlspecialchars($md['frequency']);?>) - <?php echo htmlspecialchars($md['duration']);?></li><?php endforeach;?></ul>
                <?php else:?><hr style="border-color: rgba(255,255,255,0.3);"><p>No specific medicines listed.</p><?php endif;?>
            </div>
            <div class="row center-align action-buttons" style="margin-top:20px;">
              <a href="?action=view&prescription_id=<?php echo $viewPres['prescription_id'];?>&download=1" class="btn waves-effect waves-light purple"><i class="material-icons left">file_download</i> Download PDF</a>
              <a href="?action=view&prescription_id=<?php echo $viewPres['prescription_id'];?>&print=1" target="_blank" class="btn waves-effect waves-light orange"><i class="material-icons left">print</i> Print PDF</a>
              <?php if(!$isSigned):?>
                <a href="?action=update&prescription_id=<?php echo $viewPres['prescription_id'];?>" class="btn waves-effect waves-light grey"><i class="material-icons left">edit</i> Update</a>
                <a href="?action=sign&prescription_id=<?php echo $viewPres['prescription_id'];?>" class="btn waves-effect waves-light green" onclick="return confirm('Are you sure? This action cannot be undone.');"><i class="material-icons left">lock_outline</i> Sign/Lock</a>
              <?php endif;?>
              <a href="prescriptions.php" class="btn waves-effect waves-light grey">Back to Menu</a>
            </div>
        <?php endif; ?>
    <?php endif; // End of $no_rights check ?>
</div>
</main>

<?php include_once __DIR__ . '/includes/footer.php'; ?>

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
document.addEventListener('DOMContentLoaded', function() {
  M.FormSelect.init(document.querySelectorAll('select'));
  M.CharacterCounter.init(document.querySelectorAll('.materialize-textarea'));
  M.Tooltip.init(document.querySelectorAll('.tooltipped'));
  M.updateTextFields();
});

let medicineCount = document.querySelectorAll('#medicineContainer .medicine-row').length;
function addMedicineRow() {
  const container = document.getElementById('medicineContainer');
  const newIndex = medicineCount++;
  const row = document.createElement('div');
  row.classList.add('row', 'medicine-row');
  row.innerHTML = `
    <div class="input-field med-name"><input type="text" name="medicine[${newIndex}][name]" placeholder="Medicine Name *" required></div>
    <div class="input-field med-route"><select name="medicine[${newIndex}][route]" required><option value="oral" selected>oral</option><option value="topical">topical</option><option value="IV">IV</option><option value="IM">IM</option><option value="INH">INH</option><option value="Other">Other</option></select></div>
    <div class="input-field med-dosage"><input type="text" name="medicine[${newIndex}][dosage]" placeholder="Dosage *" required></div>
    <div class="input-field med-frequency"><input type="text" name="medicine[${newIndex}][frequency]" placeholder="Frequency *" required></div>
    <div class="input-field med-duration"><input type="text" name="medicine[${newIndex}][duration]" placeholder="Duration *" required></div>
    <div class="input-field med-remove"><button type="button" class="btn-small red" onclick="removeMedicineRow(this)"><i class="material-icons">remove</i></button></div>`;
  container.appendChild(row);
  M.FormSelect.init(row.querySelector('select'));
}

function removeMedicineRow(button) { button.closest('.medicine-row').remove(); }
function lookupMRN() { lookupPatientNameByMRN('mrn', 'patient_name'); }
function lookupMRNForUpdate() { lookupPatientNameByMRN('mrn', 'patient_name'); }

function lookupPatientNameByMRN(mrnInputId, patientNameInputId) {
    const mrnField = document.getElementById(mrnInputId); const nameField = document.getElementById(patientNameInputId); const mrnValue = mrnField.value.trim();
    if (!mrnValue) { nameField.value = ''; M.updateTextFields(); return; }
    nameField.value = 'Looking up...'; M.updateTextFields();
    fetch('prescriptions.php?action=lookup_mrn&mrn=' + encodeURIComponent(mrnValue))
    .then(response => response.json())
    .then(resp => {
        if (resp.success && resp.full_name) { nameField.value = resp.full_name; }
        else { nameField.value = ''; M.toast({html: resp.error || 'Patient not found.', classes: 'red lighten-1'}); }
        M.updateTextFields();
    })
    .catch(error => { console.error("AJAX Error:", error); nameField.value = ''; M.toast({html: 'Server communication error.', classes: 'red'}); M.updateTextFields(); });
}
</script>

</body>
</html>