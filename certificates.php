<?php


// /public/certificates.php - Revised 05 July 2025
// - Removed all code128.php dependencies and barcode generation to fix fatal TypeError.
// - PDF generation logic and class are mirrored from the working ticket.php implementation.
// - Retained 'Other' certificate type functionality and existing form logic.

session_start();
require_once __DIR__ . '/includes/config.php';  // must define $pdo
require_once __DIR__ . '/includes/fpdf.php';      // FPDF library

// --- Check if user is logged in ---
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$currentUser = $_SESSION['user'];
$currentUserId = (int)$currentUser['user_id'];
$currentUserGroupId = (int)($currentUser['group_id'] ?? 0);
$currentUserFullName = $currentUser['full_name'] ?? 'System User';

// --- Authorisation: Check User Group ---
$allowedGroups = [1, 2, 3, 4, 5, 22, 23]; // Admin, Doctor, Nurse, Records, Psychologists
if (!in_array($currentUserGroupId, $allowedGroups, true)) {
    // We will show the access denied message within the styled page itself
}

$message = '';
$action  = $_GET['action'] ?? ($_POST['action'] ?? '');

// --- Certificate Templates ---
$certificateTemplates = [
    'Discharge Letter' => [
        'title' => 'Discharge Letter',
        'body' => "Patient Name: {PATIENT_NAME}\nMRN: {MRN}\nAge/Gender: {AGE_GENDER}\n\nDate of Admission: [Enter Date of Admission]\nDate of Discharge: [Enter Date of Discharge]\n\nDiagnosis:\n[Enter Final Diagnosis]\n\nCondition on Discharge:\n[Describe Condition]\n\nTreatment Administered:\n[Summarize Treatment]\n\nMedications on Discharge:\n[List Medications and Dosages]\n\nFollow-up Instructions:\n[Provide Follow-up Details]"
    ],
    'Death Certificate' => [
        'title' => 'Certificate of Death',
        'body' => "This is to certify the death of:\n\nPatient Name: {PATIENT_NAME}\nMRN: {MRN}\nAge: {AGE}\nGender: {GENDER}\nAddress: {ADDRESS}\n\nDate of Death: [Enter Date of Death] at approximately [Enter Time of Death]\nPlace of Death: hospital0\n\nPrimary Cause of Death:\n[Enter Primary Cause of Death]\n\nSecondary/Contributing Causes (if any):\n[Enter Secondary Causes]\n\nI hereby certify that I attended the deceased and that the particulars stated above are true to the best of my knowledge and belief.\n\nRegistered Medical Practitioner,\n" . htmlspecialchars($currentUserFullName)
    ],
    'Sick Note' => [
        'title' => 'Medical Certificate for Sick Leave',
        'body' => "Patient Name: {PATIENT_NAME}\nMRN: {MRN}\n\nThis is to certify that the patient named above has been examined by me and is suffering from:\n[Enter Diagnosis/Condition]\n\nHe/She is unfit for duty / school from [Enter Start Date] to [Enter End Date] (inclusive).\n\nHe/She is advised to take rest for [Enter Number] days/weeks.\n\nDate of Issue: " . date('d-M-Y')
    ]
];

/**
 * PDF Generation Class
 */
class CertificatePDF extends FPDF
{
    function __construct($orientation='P', $unit='mm', $size='A4')
    {
        parent::__construct($orientation, $unit, $size);
    }
    function Header() {}
    function Footer() {}
}

/**
 * Generate Certificate PDF
 */
function generateCertificatePDF($pdo, $certificate_id, $loggedInUserFullName) {
    $sql = "SELECT c.*, u.full_name AS creator_db_full_name FROM certificates c LEFT JOIN users u ON c.created_by = u.user_id WHERE c.certificate_id=?";
    $st = $pdo->prepare($sql);
    $st->execute([$certificate_id]);
    $cert = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cert) { 
        error_log("PDF Gen Error: Certificate ID {$certificate_id} not found."); 
        return null; 
    }

    $pdfDir = __DIR__ . '/certificates/';
    if (!is_dir($pdfDir)) { @mkdir($pdfDir, 0777, true); }
    if (!is_writable($pdfDir)) {
        error_log("FATAL PDF ERROR: Directory is not writable: {$pdfDir}. Check permissions.");
        return null;
    }

    $randomDigits = substr(bin2hex(random_bytes(4)), 0, 8);
    $safeMrn = preg_replace('/[^a-zA-Z0-9]/', '', $cert['mrn'] ?? '000');
    $safeCertTypeForFile = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $cert['certificate_type']));
    $pdfName = sprintf("%s-%s-CID%s-%s.pdf", strtolower($safeCertTypeForFile), $safeMrn, $certificate_id, $randomDigits);

    $pdfPathRel = 'certificates/'.$pdfName;
    $pdfFull = $pdfDir . $pdfName;

    try {
        $pdf = new CertificatePDF('P','mm','A4');
        $pdf->AddFont('MyriadPro-Regular','','MyriadPro-Regular.php');
        
        $pdfLeftMargin = 15.0; $pdfRightMargin = 15.0; $pdfTopMargin = 10.0; $pdfBottomMargin = 25.0;

        $pdf->SetMargins($pdfLeftMargin, $pdfTopMargin, $pdfRightMargin);
        $pdf->SetAutoPageBreak(true, $pdfBottomMargin);
        $pdf->AddPage();
        
        $logoPath = __DIR__.'/media/headerlogo.jpg';
        if (file_exists($logoPath) && is_readable($logoPath)) {
            list($originalWidth, $originalHeight) = getimagesize($logoPath);
            $aspectRatio = $originalHeight / $originalWidth;
            $pageWidth = $pdf->GetPageWidth();
            $contentWidth = $pageWidth - $pdfLeftMargin - $pdfRightMargin;
            $logoWidth = $contentWidth;
            $logoHeight = $logoWidth * $aspectRatio;
            $pdf->Image($logoPath, $pdfLeftMargin, $pdfTopMargin, $logoWidth, $logoHeight, 'JPG');
            $pdf->Ln($logoHeight + 5);
        } else {
            $pdf->Ln(10); $pdf->SetFont('MyriadPro-Regular', '', 16); $pdf->Cell(0, 7, 'hospital0', 0, 1, 'C');
            $pdf->SetFont('MyriadPro-Regular', '', 12); $pdf->Cell(0, 5, 'www.hospital0', 0, 1, 'C'); $pdf->Ln(15);
        }

        $pdf_cert_type = iconv('UTF-8', 'windows-1252//TRANSLIT', $cert['certificate_type']);
        $pdf_patient_name = iconv('UTF-8', 'windows-1252//TRANSLIT', $cert['patient_name']);
        $pdf_mrn = iconv('UTF-8', 'windows-1252//TRANSLIT', $cert['mrn']);
        $pdf_title = iconv('UTF-8', 'windows-1252//TRANSLIT', $cert['title']);
        $pdf_body = iconv('UTF-8', 'windows-1252//TRANSLIT', $cert['body']);
        $pdf_loggedInUserFullName = iconv('UTF-8', 'windows-1252//TRANSLIT', $loggedInUserFullName);

        $pdf->SetTextColor(0,0,0); $pdf->SetFont('MyriadPro-Regular', '', 16); $pdf->Cell(0, 8, strtoupper($pdf_cert_type), 0, 1, 'C'); $pdf->Ln(23);

        $pdfRow = function($label, $value) use ($pdf) {
            $pdf->SetFont('MyriadPro-Regular','',11); $pdf->Cell(45, 7, $label, 0, 0, 'L');
            $pdf->SetFont('MyriadPro-Regular','',11); $pdf->MultiCell(0, 7, (string)$value, 0, 'L');
        };
        $pdfRow('Patient Name:', $pdf_patient_name);
        if($cert['mrn']) $pdfRow('MRN:', $pdf_mrn);
        $pdfRow('Issue Date:', date('d-M-Y H:i', strtotime($cert['created_at'])));
        $pdf->Ln(10);
        
        $pdf->SetFont('MyriadPro-Regular', '', 13); $pdf->MultiCell(0, 6, "Subject: " . $pdf_title, 0, 'L'); $pdf->Ln(5);
        $pdf->SetFont('MyriadPro-Regular', '', 11); $pdf->MultiCell(0, 6, $pdf_body);

        $pdf->SetY(-30); $pdf->SetFont('MyriadPro-Regular', '', 9); $pdf->SetTextColor(100,100,100);
        $pdf->Cell(0, 5, 'Generated by ' . $pdf_loggedInUserFullName . ' on ' . date('d-M-Y h:i A', strtotime($cert['created_at'])), 0, 1, 'C');
        $pdf->SetFont('MyriadPro-Regular','',10); $pdf->Cell(0,6,'hospital0',0,1,'C');

        $pdf->Output('F', $pdfFull);
        
        if (!file_exists($pdfFull) || filesize($pdfFull) == 0) {
             error_log("FATAL PDF ERROR: FPDF Output() failed to create a valid file at {$pdfFull}.");
             return null;
        }
        
        $pdo->prepare("UPDATE certificates SET pdf_path=? WHERE certificate_id=?")->execute([$pdfPathRel, $certificate_id]);
        return $pdfFull;

    } catch (Exception $e) {
        error_log("FATAL PDF ERROR: Exception during PDF generation for cert ID {$certificate_id}: " . $e->getMessage());
        return null;
    }
}

// AJAX MRN Lookup Handler
if (isset($_GET['action']) && $_GET['action'] === 'lookup_mrn_certs') {
    $mrnAjax = trim($_GET['mrn'] ?? '');
    header('Content-Type: application/json');
    if ($mrnAjax === '') {
        echo json_encode(['success' => false, 'error' => 'No MRN provided.']);
        exit;
    }
    $stmtAjax = $pdo->prepare("SELECT full_name, gender, age, age_unit, phone, address FROM patients WHERE mrn=? LIMIT 1");
    $stmtAjax->execute([$mrnAjax]);
    $r = $stmtAjax->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        echo json_encode(['success' => false, 'error' => 'No patient found with that MRN. You can proceed by manually entering details.']);
    } else {
        echo json_encode(['success' => true, 'full_name' => $r['full_name'], 'gender' => $r['gender'], 'age' => $r['age'], 'age_unit' => $r['age_unit'], 'phone' => $r['phone'], 'address' => $r['address']]);
    }
    exit;
}

/** Force PDF download */
function forceDownloadCert($filePath)
{
    if (!file_exists($filePath) || !is_readable($filePath)) {
        error_log("forceDownloadCert: File not found or not readable at {$filePath}");
        echo "Error: Certificate file could not be found. Please try generating it again or contact support.";
        exit;
    }
    header('Content-Description: File Transfer'); header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    header('Content-Transfer-Encoding: binary'); header('Expires: 0'); header('Cache-Control: must-revalidate'); header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));
    ob_clean(); flush(); readfile($filePath);
    exit;
}


$certificateData = null; $formValues = $_POST; $listCertificates = [];

if (!$no_rights) {
    switch ($action) {
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $mrn = trim($_POST['mrn'] ?? '');
                $patient_name = trim($_POST['patient_name'] ?? '');
                $gender = trim($_POST['gender'] ?? '');
                $age = trim($_POST['age'] ?? '');
                $age_unit = trim($_POST['age_unit'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $certificate_type = trim($_POST['certificate_type'] ?? '');
                $title = trim($_POST['title'] ?? '');
                $body = trim($_POST['body'] ?? '');

                $errors = [];
                if (empty($patient_name)) $errors[] = "Patient Name is required.";
                if (empty($certificate_type)) $errors[] = "Certificate Type is required.";
                if (empty($title)) $errors[] = "Certificate Title is required.";
                if (empty($body)) $errors[] = "Certificate Body is required.";
                if (!empty($age) && !is_numeric($age)) $errors[] = "Age must be a number.";

                if (empty($errors)) {
                    try {
                        $sql = "INSERT INTO certificates (mrn, patient_name, gender, age, age_unit, phone, address, certificate_type, title, body, created_by) VALUES (:mrn, :patient_name, :gender, :age, :age_unit, :phone, :address, :certificate_type, :title, :body, :created_by)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([':mrn' => !empty($mrn) ? $mrn : null, ':patient_name' => $patient_name, ':gender' => !empty($gender) ? $gender : null, ':age' => !empty($age) ? (int)$age : null, ':age_unit' => !empty($age_unit) ? $age_unit : null, ':phone' => !empty($phone) ? $phone : null, ':address' => !empty($address) ? $address : null, ':certificate_type' => $certificate_type, ':title' => $title, ':body' => $body, ':created_by' => $currentUserId]);
                        $newId = $pdo->lastInsertId();
                        $pdfPath = generateCertificatePDF($pdo, $newId, $currentUserFullName);
                        if ($pdfPath) { header("Location: certificates.php?action=view&id={$newId}&status=created"); exit; }
                        else { error_log("Certificate ID {$newId} created in DB, but PDF generation failed."); header("Location: certificates.php?action=view&id={$newId}&status=created_pdf_failed"); exit; }
                    } catch (PDOException $e) { error_log("DB Error creating certificate: " . $e->getMessage()); $message = "Database Error: Could not create certificate."; $formValues = $_POST;
                    } catch (Exception $e) { error_log("General Error creating certificate: " . $e->getMessage()); $message = "An unexpected error occurred: " . $e->getMessage(); $formValues = $_POST; }
                } else { $message = "Validation Errors: <br>" . implode("<br>", $errors); $formValues = $_POST; }
            } else { // Handle GET request for create form
                $formValues['mrn'] = trim($_GET['mrn_initial'] ?? ''); $formValues['certificate_type'] = trim($_GET['certificate_type_initial'] ?? ''); $templateBody = '';
                if ($formValues['certificate_type'] === 'Other') { $formValues['title'] = trim($_GET['other_type_title_initial'] ?? 'Custom Certificate'); $templateBody = "Patient Name: {PATIENT_NAME}\nMRN: {MRN}\n\n[Please enter the full text of the certificate here.]";
                } else {
                    if (empty($formValues['certificate_type'])) { $message = "Error: Please select a certificate type."; $action = ''; break; }
                    if (isset($certificateTemplates[$formValues['certificate_type']])) { $template = $certificateTemplates[$formValues['certificate_type']]; $formValues['title'] = $template['title']; $templateBody = $template['body'];
                    } else { $message = "Error: Invalid certificate type selected."; $action = ''; break; }
                }
                $patientDataForTemplate = ['{PATIENT_NAME}' => htmlspecialchars($_GET['patient_name_initial'] ?? ($formValues['patient_name'] ?? '[Patient Name]')), '{MRN}' => htmlspecialchars($formValues['mrn'] ?? '[MRN]'), '{AGE_GENDER}' => (htmlspecialchars($_GET['age_initial'] ?? ($formValues['age'] ?? '[Age]'))) . (isset($_GET['age_unit_initial']) ? ' ' . htmlspecialchars($_GET['age_unit_initial']) : (isset($formValues['age_unit']) ? ' ' . $formValues['age_unit'] : '')) . ' / ' . (htmlspecialchars($_GET['gender_initial'] ?? ($formValues['gender'] ?? '[Gender]'))), '{AGE}' => htmlspecialchars($_GET['age_initial'] ?? ($formValues['age'] ?? '[Age]')), '{GENDER}' => htmlspecialchars($_GET['gender_initial'] ?? ($formValues['gender'] ?? '[Gender]')), '{ADDRESS}' => htmlspecialchars($_GET['address_initial'] ?? ($formValues['address'] ?? '[Address]')), ];
                $formValues['body'] = str_replace(array_keys($patientDataForTemplate), array_values($patientDataForTemplate), $templateBody);
            }
            break;

        case 'view':
            $certId = (int)($_GET['id'] ?? 0); if ($certId < 1) { $message = "Error: No certificate ID specified for viewing."; $action = ''; break; }
            if (isset($_GET['download']) && $_GET['download'] == '1') {
                $st_check = $pdo->prepare("SELECT pdf_path, created_by FROM certificates WHERE certificate_id = ?"); $st_check->execute([$certId]); $certFile = $st_check->fetch(PDO::FETCH_ASSOC); $pdfFullPathToServe = null;
                if ($certFile && !empty($certFile['pdf_path']) && file_exists(__DIR__ . '/' . $certFile['pdf_path'])) { $pdfFullPathToServe = __DIR__ . '/' . $certFile['pdf_path'];
                } else {
                    error_log("PDF for Cert ID {$certId} not found. Attempting regeneration."); $creatorUserId = $certFile['created_by'] ?? $currentUserId;
                    $userStmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?"); $userStmt->execute([$creatorUserId]); $creatorUser = $userStmt->fetch(PDO::FETCH_ASSOC); $creatorNameToUse = $creatorUser['full_name'] ?? $currentUserFullName;
                    $regeneratedPath = generateCertificatePDF($pdo, $certId, $creatorNameToUse); if ($regeneratedPath && file_exists($regeneratedPath)) { $pdfFullPathToServe = $regeneratedPath; }
                    else { error_log("Failed to regenerate PDF for Cert ID {$certId}."); }
                }
                if ($pdfFullPathToServe) { forceDownloadCert($pdfFullPathToServe); } else { $_SESSION['flash_message'] = "Error: PDF for certificate #{$certId} could not be prepared."; header("Location: certificates.php?action=view&id={$certId}&status=pdf_error"); exit; }
            } elseif (isset($_GET['print']) && $_GET['print'] == '1') {
                $st_check = $pdo->prepare("SELECT pdf_path, created_by FROM certificates WHERE certificate_id = ?"); $st_check->execute([$certId]); $certFile = $st_check->fetch(PDO::FETCH_ASSOC); $relativePathForPrint = null;
                if ($certFile && !empty($certFile['pdf_path']) && file_exists(__DIR__ . '/' . $certFile['pdf_path'])) { $relativePathForPrint = '/' . $certFile['pdf_path'];
                } else {
                    error_log("PDF for Print Cert ID {$certId} not found. Attempting regeneration."); $creatorUserId = $certFile['created_by'] ?? $currentUserId;
                    $userStmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?"); $userStmt->execute([$creatorUserId]); $creatorUser = $userStmt->fetch(PDO::FETCH_ASSOC); $creatorNameToUse = $creatorUser['full_name'] ?? $currentUserFullName;
                    $regeneratedPath = generateCertificatePDF($pdo, $certId, $creatorNameToUse);
                    if ($regeneratedPath && file_exists($regeneratedPath)) { $relativePathForPrint = '/' . str_replace(__DIR__ . '/', '', $regeneratedPath); } else { error_log("Failed to regenerate PDF for Print Cert ID {$certId}."); }
                }
                if ($relativePathForPrint) { header("Location: " . $relativePathForPrint); exit; } else { $_SESSION['flash_message'] = "Error: PDF for certificate #{$certId} could not be prepared."; header("Location: certificates.php?action=view&id={$certId}&status=pdf_error"); exit; }
            }
            $sqlView = "SELECT c.*, u.full_name AS creator_db_full_name FROM certificates c LEFT JOIN users u ON c.created_by = u.user_id WHERE c.certificate_id = ?";
            $stmtView = $pdo->prepare($sqlView); $stmtView->execute([$certId]); $certificateData = $stmtView->fetch(PDO::FETCH_ASSOC);
            if (!$certificateData) { $message = "Error: Certificate #{$certId} not found."; $action = ''; break; }
            if (isset($_SESSION['flash_message'])) { $message = $_SESSION['flash_message']; unset($_SESSION['flash_message']); }
            elseif (isset($_GET['status'])) {
                if ($_GET['status'] === 'created') $message = "Success: Certificate #{$certId} created and PDF generated.";
                if ($_GET['status'] === 'created_pdf_failed') $message = "Notice: Certificate #{$certId} created, but the PDF could not be generated.";
                if ($_GET['status'] === 'pdf_error') $message = $message ?: "Error: There was an issue preparing the PDF.";
            }
            break;
        
        case 'list': 
            $stmtList = $pdo->prepare("SELECT c.certificate_id, c.patient_name, c.mrn, c.certificate_type, c.title, c.created_at, u.full_name as creator_name FROM certificates c LEFT JOIN users u ON c.created_by = u.user_id ORDER BY c.created_at DESC LIMIT 50");
            $stmtList->execute(); $listCertificates = $stmtList->fetchAll(PDO::FETCH_ASSOC); break;

        default: $action = ''; break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - Medical Certificates - hospital0</title>
    <link rel="icon" href="/media/sitelogo.png" type="image/png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* --- NEW BEAUTIFICATION STYLES --- */
        body {
            background-image: none !important;
            background-color: #121212 !important;
            overflow-x: hidden;
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }
        main { flex: 1 0 auto; }
        
        @keyframes move-twink-back { from { background-position: 0 0; } to { background-position: -10000px 5000px; } }
        .stars, .twinkling {
          position: fixed; top: 0; left: 0; right: 0; bottom: 0;
          width: 100%; height: 100%; display: block; z-index: -3;
        }
        .stars { background: #000 url(/media/stars.png) repeat top center; }
        .twinkling {
          background: transparent url(/media/twinkling.png) repeat top center;
          animation: move-twink-back 200s linear infinite;
        }
        #dna-canvas {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            z-index: -2; opacity: 0.3;
        }
        
        h3.center-align, h5.white-text {
            font-weight: 300;
            text-shadow: 0 0 8px rgba(0, 229, 255, 0.5);
        }
        .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }
        
        /* --- Glassmorphism UI --- */
        .card-panel, .glass-card, table {
            background: rgba(255, 255, 255, 0.08) !important;
            backdrop-filter: blur(12px) !important;
            -webkit-backdrop-filter: blur(12px) !important;
            border: 1px solid rgba(255, 255, 255, 0.15) !important;
            border-radius: 15px !important;
        }
        
        .input-field input:not([type]), .input-field input[type=text]:not(.browser-default), .input-field input[type=password]:not(.browser-default), .input-field input[type=email]:not(.browser-default), .input-field input[type=url]:not(.browser-default), .input-field input[type=time]:not(.browser-default), .input-field input[type=date]:not(.browser-default), .input-field input[type=datetime]:not(.browser-default), .input-field input[type=datetime-local]:not(.browser-default), .input-field input[type=tel]:not(.browser-default), .input-field input[type=number]:not(.browser-default), .input-field input[type=search]:not(.browser-default), .input-field textarea.materialize-textarea {
            color: #ffffff !important; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important;
            box-shadow: none !important;
        }
        .input-field label { color: #bdbdbd !important; }
        .input-field label.active { color: #00e5ff !important; }
        .input-field input:focus + label, .input-field textarea:focus + label { color: #00e5ff !important; }
        .input-field input:focus, .input-field textarea:focus {
            border-bottom: 1px solid #00e5ff !important;
            box-shadow: 0 1px 0 0 #00e5ff !important;
        }
        
        ul.dropdown-content { background-color: #2a2a2a; }
        .dropdown-content li>span { color: #ffffff; }

        .message-area { padding: 10px; margin: 20px 0; border-radius: 8px; text-align: center; border: 1px solid; }
        .message-area.error { background-color: rgba(244, 67, 54, 0.25); color: #ffcdd2; border-color: rgba(239, 154, 154, 0.5); }
        .message-area.success { background-color: rgba(76, 175, 80, 0.25); color: #c8e6c9; border-color: rgba(129, 199, 132, 0.5); }
        .message-area.notice { background-color: rgba(255, 235, 59, 0.25); color: #fff9c4; border-color: rgba(255, 245, 157, 0.5); }
        
        .btn, .btn-small { margin: 5px; }
        .btn .material-icons { vertical-align: middle; }
        textarea#body { min-height: 250px; line-height: 1.6; }
        
        table.striped>tbody>tr:nth-child(odd) { background-color: rgba(255, 255, 255, 0.05); }
        table { border: none; }
        th { border-bottom: 1px solid rgba(255, 255, 255, 0.3); }
        td, th { padding: 15px 10px; }
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
    <h3 class="center-align white-text" style="margin-top:30px;">Medical Certificates</h3>
    <hr class="white-line">

    <?php if ($no_rights): ?>
        <div class="card-panel red darken-2 center-align glass-card" style="padding: 20px; margin-top: 50px; backdrop-filter: none !important; background: rgba(211, 47, 47, 0.5) !important;">
            <h5 class="white-text"><i class="material-icons left">lock_outline</i>Access Denied</h5>
            <p>You do not have permission to access this module.</p>
        </div>
    <?php else: ?>

        <?php if ($message):
            $messageType = 'notice';
            if (stripos($message, 'error') !== false || stripos($message, 'failed') !== false) $messageType = 'error';
            elseif (stripos($message, 'success') !== false || stripos($message, 'created') !== false ) $messageType = 'success';
        ?>
            <div class="message-area <?php echo $messageType; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php // ====== INITIAL SELECTION FORM (Default View) ======
        if ($action === ''): ?>
        <div class="glass-card" style="padding: 2rem;">
            <h5 class="center-align white-text">Generate New Certificate</h5>
            <form method="GET" action="certificates.php">
                <input type="hidden" name="action" value="create">
                <div class="row">
                    <div class="input-field col s12 m6">
                        <i class="material-icons prefix white-text">account_circle</i>
                        <input type="text" id="mrn_initial" name="mrn_initial">
                        <label for="mrn_initial">Patient MRN (Optional - for auto-fill)</label>
                    </div>
                    <div class="input-field col s12 m6">
                        <i class="material-icons prefix white-text">description</i>
                        <select id="certificate_type_initial" name="certificate_type_initial" required>
                            <option value="" disabled selected>Choose Certificate Type *</option>
                            <?php foreach (array_keys($certificateTemplates) as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                            <?php endforeach; ?>
                            <option value="Other">Other</option>
                        </select>
                        <label for="certificate_type_initial">Certificate Type *</label>
                    </div>
                </div>
                <div class="row" id="other_type_title_wrapper" style="display:none;">
                    <div class="input-field col s12">
                        <i class="material-icons prefix white-text">subtitles</i>
                        <input type="text" id="other_type_title_initial" name="other_type_title_initial">
                        <label for="other_type_title_initial">Please specify title for 'Other' certificate</label>
                    </div>
                </div>
                <div class="row center-align">
                    <button type="submit" class="btn waves-effect waves-light" style="background-color: #00bfa5;">
                        <i class="material-icons left">send</i>Proceed to Create
                    </button>
                    <a href="?action=list" class="btn waves-effect waves-light blue" style="margin-left: 10px;">
                        <i class="material-icons left">list</i> View Issued Certificates
                    </a>
                </div>
            </form>
        </div>
        <?php endif; ?>


        <?php // ====== CREATE CERTIFICATE FORM ======
        if ($action === 'create' && ($_SERVER['REQUEST_METHOD'] === 'GET' || ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) ) ):
        ?>
        <h5 class="white-text">Create: <?php echo htmlspecialchars($formValues['certificate_type'] ?? 'Certificate'); ?></h5>
        <form method="POST" action="certificates.php?action=create" id="createCertificateForm">
            <input type="hidden" name="certificate_type" value="<?php echo htmlspecialchars($formValues['certificate_type'] ?? ''); ?>">
            
            <div class="glass-card demographics-section" style="padding: 1rem 2rem;">
                <h6 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);"><i class="material-icons left">person_outline</i>Patient Demographics</h6>
                <div class="row">
                    <div class="input-field col s12 m4">
                        <input type="text" id="mrn" name="mrn" value="<?php echo htmlspecialchars($formValues['mrn'] ?? ''); ?>" onblur="lookupMRN()">
                        <label for="mrn" class="<?php echo !empty($formValues['mrn']) ? 'active' : '';?>">Patient MRN (if available)</label>
                    </div>
                    <div class="input-field col s12 m8">
                        <input type="text" id="patient_name" name="patient_name" value="<?php echo htmlspecialchars($formValues['patient_name'] ?? ''); ?>" required>
                        <label for="patient_name" class="<?php echo !empty($formValues['patient_name']) ? 'active' : '';?>">Patient Full Name *</label>
                    </div>
                </div>
                <div class="row">
                    <div class="input-field col s12 m3">
                        <input type="number" id="age" name="age" value="<?php echo htmlspecialchars($formValues['age'] ?? ''); ?>">
                        <label for="age" class="<?php echo ($formValues['age'] ?? null) !== null && $formValues['age'] !== '' ? 'active' : '';?>">Age</label>
                    </div>
                    <div class="input-field col s12 m3">
                        <select id="age_unit" name="age_unit">
                            <option value="" <?php echo empty($formValues['age_unit']) ? 'selected' : ''; ?>>Unit</option>
                            <option value="Years" <?php echo ($formValues['age_unit'] ?? '') === 'Years' ? 'selected' : ''; ?>>Years</option>
                            <option value="Months" <?php echo ($formValues['age_unit'] ?? '') === 'Months' ? 'selected' : ''; ?>>Months</option>
                            <option value="Days" <?php echo ($formValues['age_unit'] ?? '') === 'Days' ? 'selected' : ''; ?>>Days</option>
                        </select>
                        <label>Age Unit</label> </div>
                    <div class="input-field col s12 m6">
                        <select id="gender" name="gender">
                            <option value="" <?php echo empty($formValues['gender']) ? 'selected' : ''; ?>>Select Gender</option>
                            <option value="Male" <?php echo ($formValues['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($formValues['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo ($formValues['gender'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                        <label>Gender</label> </div>
                </div>
                <div class="row">
                    <div class="input-field col s12 m6">
                        <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($formValues['phone'] ?? ''); ?>">
                        <label for="phone" class="<?php echo !empty($formValues['phone']) ? 'active' : '';?>">Phone Number</label>
                    </div>
                    <div class="input-field col s12 m6">
                        <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($formValues['address'] ?? ''); ?>">
                        <label for="address" class="<?php echo !empty($formValues['address']) ? 'active' : '';?>">Address</label>
                    </div>
                </div>
            </div>

            <div class="glass-card" style="padding: 1rem 2rem; margin-top: 1.5rem;">
                <div class="row">
                    <div class="input-field col s12">
                        <i class="material-icons prefix white-text">title</i>
                        <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($formValues['title'] ?? ''); ?>" required>
                        <label for="title" class="active">Certificate Title *</label> </div>
                </div>
                <div class="row">
                    <div class="input-field col s12">
                        <i class="material-icons prefix white-text">article</i>
                        <textarea id="body" name="body" class="materialize-textarea white-text" required><?php echo htmlspecialchars($formValues['body'] ?? ''); ?></textarea>
                        <label for="body" class="active">Certificate Body *</label> <span class="helper-text white-text" style="color: #bdbdbd !important;">Placeholders like [Enter X] should be replaced. {PATIENT_NAME}, {MRN} etc. were auto-filled if data was available.</span>
                    </div>
                </div>
            </div>

            <div class="row center-align" style="margin-top:30px;">
                <button type="submit" class="btn waves-effect waves-light" style="background-color: #00bfa5;">
                    <i class="material-icons left">save</i>Save Certificate
                </button>
                <a href="certificates.php" class="btn waves-effect waves-light grey">
                    <i class="material-icons left">cancel</i>Cancel
                </a>
            </div>
        </form>
        <?php endif; ?>


        <?php // ====== VIEW SINGLE CERTIFICATE ======
        if ($action === 'view' && !empty($certificateData)):
            $cert = $certificateData;
            $pAgeStr = 'N/A';
            if (isset($cert['age']) && $cert['age'] !== null && !empty($cert['age_unit'])) {
                $ageVal = htmlspecialchars($cert['age']); $ageUnit = htmlspecialchars($cert['age_unit']);
                if ($ageVal == 1) { $ageUnit = rtrim($ageUnit, 's'); }
                else if (!str_ends_with($ageUnit, 's') && $ageUnit != '') { $ageUnit .= 's'; }
                $pAgeStr = $ageVal . ' ' . ucfirst($ageUnit);
            } elseif (isset($cert['age']) && $cert['age'] !== null) { $pAgeStr = htmlspecialchars($cert['age']); }
        ?>
        <h5 class="white-text">Viewing Certificate #<?php echo (int)$cert['certificate_id']; ?>: <?php echo htmlspecialchars($cert['certificate_type']); ?></h5>
        <div class="card-panel glass-card white-text">
            <p>
                <strong>Patient:</strong> <?php echo htmlspecialchars($cert['patient_name']);?>
                <?php if(!empty($cert['mrn'])): ?> (MRN: <?php echo htmlspecialchars($cert['mrn']); ?>)<?php endif; ?><br>
                <?php if(!empty($cert['gender'])): ?><strong>Gender:</strong> <?php echo htmlspecialchars($cert['gender']);?><br><?php endif; ?>
                <?php if($pAgeStr !== 'N/A'): ?><strong>Age:</strong> <?php echo $pAgeStr; ?><br><?php endif; ?>
                <?php if(!empty($cert['phone'])): ?><strong>Phone:</strong> <?php echo htmlspecialchars($cert['phone']);?><br><?php endif; ?>
                <?php if(!empty($cert['address'])): ?><strong>Address:</strong> <?php echo htmlspecialchars($cert['address']);?><br><?php endif; ?>
            </p>
            <hr style="border-color: rgba(255,255,255,0.3);">
            <p>
                <strong>Certificate Title:</strong> <?php echo htmlspecialchars($cert['title']); ?><br>
                <strong>Issued At:</strong> <?php echo htmlspecialchars(date('d-M-Y H:i', strtotime($cert['created_at'])));?><br>
                <strong>Issued By:</strong> <?php echo htmlspecialchars($cert['creator_db_full_name'] ?? 'Unknown User');?><br>
            </p>
            <hr style="border-color: rgba(255,255,255,0.3);">
            <h6 class="white-text" style="text-shadow: 0 0 5px rgba(0, 229, 255, 0.5);">Certificate Content:</h6>
            <div style="background-color:rgba(0,0,0,0.2); padding:15px; border-radius: 4px; margin-top: 5px; white-space: pre-wrap;"><?php echo nl2br(htmlspecialchars($cert['body']));?></div>
        </div>

        <div class="row center-align action-buttons" style="margin-top:20px;">
            <a href="?action=view&id=<?php echo $cert['certificate_id'];?>&download=1" class="btn waves-effect waves-light purple">
                <i class="material-icons left">file_download</i> Download PDF
            </a>
            <a href="?action=view&id=<?php echo $cert['certificate_id'];?>&print=1" target="_blank" class="btn waves-effect waves-light orange">
                <i class="material-icons left">print</i> Print PDF
            </a>
            <a href="certificates.php" class="btn waves-effect waves-light grey">
                <i class="material-icons left">menu</i>Back to Menu
            </a>
        </div>
        <?php endif; ?>


        <?php // ====== LIST ISSUED CERTIFICATES ======
        if ($action === 'list' && isset($listCertificates)): ?>
            <h5 class="white-text">Recently Issued Certificates</h5>
            <?php if (empty($listCertificates)): ?>
                <p class="white-text center-align">No certificates found in the system.</p>
            <?php else: ?>
                <table class="striped responsive-table white-text glass-card">
                    <thead>
                        <tr>
                            <th>ID</th> <th>Patient Name</th> <th>MRN</th> <th>Type</th> <th>Title</th>
                            <th>Issued At</th> <th>Issued By</th> <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($listCertificates as $certItem): ?>
                        <tr>
                            <td><?php echo (int)$certItem['certificate_id']; ?></td>
                            <td><?php echo htmlspecialchars($certItem['patient_name']); ?></td>
                            <td><?php echo htmlspecialchars($certItem['mrn'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($certItem['certificate_type']); ?></td>
                            <td><?php echo htmlspecialchars($certItem['title']); ?></td>
                            <td><?php echo htmlspecialchars(date('d-M-Y H:i', strtotime($certItem['created_at']))); ?></td>
                            <td><?php echo htmlspecialchars($certItem['creator_name'] ?? 'Unknown'); ?></td>
                            <td>
                                <a href="?action=view&id=<?php echo (int)$certItem['certificate_id'];?>" class="btn-small waves-effect waves-light blue tooltipped" data-position="top" data-tooltip="View Certificate"><i class="material-icons">visibility</i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <div class="center-align" style="margin-top: 20px;">
                <a href="certificates.php" class="btn waves-effect waves-light grey">Back to Menu</a>
            </div>
        <?php endif; ?>

    <?php endif; // End of $no_rights check ?>
</div>
</main>
<?php include_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<!-- 3D DNA Helix JavaScript -->
<script type="importmap">{"imports": {"three": "https://unpkg.com/three@0.164.1/build/three.module.js"}}</script>
<script type="module">
    import * as THREE from 'three';
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
    const renderer = new THREE.WebGLRenderer({ canvas: document.querySelector('#dna-canvas'), alpha: true });
    renderer.setPixelRatio(window.devicePixelRatio);
    renderer.setSize(window.innerWidth, window.innerHeight);
    camera.position.setZ(30);
    const dnaGroup = new THREE.Group();
    const radius = 5, tubeRadius = 0.5, radialSegments = 8, tubularSegments = 64, height = 40, turns = 4;
    class HelixCurve extends THREE.Curve {
        constructor(scale = 1, turns = 5, offset = 0) { super(); this.scale = scale; this.turns = turns; this.offset = offset; }
        getPoint(t) {
            const tx = Math.cos(this.turns * 2 * Math.PI * t + this.offset);
            const ty = t * height - height / 2;
            const tz = Math.sin(this.turns * 2 * Math.PI * t + this.offset);
            return new THREE.Vector3(tx, ty, tz).multiplyScalar(this.scale);
        }
    }
    const backboneMaterial = new THREE.MeshStandardMaterial({ color: 0x2196f3, metalness: 0.5, roughness: 0.2 });
    const path1 = new HelixCurve(radius, turns, 0);
    const path2 = new HelixCurve(radius, turns, Math.PI);
    dnaGroup.add(new THREE.Mesh(new THREE.TubeGeometry(path1, tubularSegments, tubeRadius, radialSegments, false), backboneMaterial));
    dnaGroup.add(new THREE.Mesh(new THREE.TubeGeometry(path2, tubularSegments, tubeRadius, radialSegments, false), backboneMaterial));
    const pairMaterial = new THREE.MeshStandardMaterial({ color: 0xffeb3b, metalness: 0.2, roughness: 0.5 });
    const steps = 50;
    for (let i = 0; i <= steps; i++) {
        const t = i / steps;
        const p1 = path1.getPoint(t);
        const p2 = path2.getPoint(t);
        const dir = new THREE.Vector3().subVectors(p2, p1);
        const rungGeom = new THREE.CylinderGeometry(0.3, 0.3, dir.length(), 6);
        const rung = new THREE.Mesh(rungGeom, pairMaterial);
        rung.position.copy(p1).add(dir.multiplyScalar(0.5));
        rung.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), dir.normalize());
        dnaGroup.add(rung);
    }
    scene.add(dnaGroup);
    scene.add(new THREE.AmbientLight(0xffffff, 0.5));
    const pLight = new THREE.PointLight(0xffffff, 1);
    pLight.position.set(5, 15, 15);
    scene.add(pLight);
    function animate() {
        requestAnimationFrame(animate);
        dnaGroup.rotation.y += 0.005;
        dnaGroup.rotation.x += 0.001;
        renderer.render(scene, camera);
    }
    animate();
    window.addEventListener('resize', () => {
        camera.aspect = window.innerWidth / window.innerHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(window.innerWidth, window.innerHeight);
    });
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var selects = document.querySelectorAll('select');
    M.FormSelect.init(selects);
    var textareas = document.querySelectorAll('.materialize-textarea');
    M.CharacterCounter.init(textareas);
    textareas.forEach(ta => M.textareaAutoResize(ta));
    var tooltips = document.querySelectorAll('.tooltipped');
    M.Tooltip.init(tooltips);
    M.updateTextFields();

    const certTypeSelect = document.getElementById('certificate_type_initial');
    const otherTitleWrapper = document.getElementById('other_type_title_wrapper');
    const otherTitleInput = document.getElementById('other_type_title_initial');

    if (certTypeSelect) {
        certTypeSelect.addEventListener('change', function() {
            if (this.value === 'Other') {
                otherTitleWrapper.style.display = 'block';
                otherTitleInput.required = true;
            } else {
                otherTitleWrapper.style.display = 'none';
                otherTitleInput.required = false;
            }
        });
    }

    const mrnFieldOnCreate = document.getElementById('mrn');
    if (mrnFieldOnCreate && mrnFieldOnCreate.value.trim() !== '' && document.getElementById('createCertificateForm')) {
        const patientNameField = document.getElementById('patient_name');
        if (patientNameField && patientNameField.value.trim() === '') {
             lookupMRN(); 
        } else {
            updateBodyWithPatientData();
        }
    }
});

function lookupMRN() {
    const mrnField = document.getElementById('mrn');
    const mrnValue = mrnField.value.trim();
    if (!mrnValue) { updateBodyWithPatientData(); return; }
    M.toast({html: 'Looking up MRN...', displayLength: 1000});

    fetch('certificates.php?action=lookup_mrn_certs&mrn=' + encodeURIComponent(mrnValue))
    .then(response => response.json())
    .then(resp => {
        if (resp.success) {
            document.getElementById('patient_name').value = resp.full_name || '';
            document.getElementById('age').value = resp.age || '';
            const ageUnitSelect = document.getElementById('age_unit');
            if (resp.age_unit) ageUnitSelect.value = resp.age_unit; else ageUnitSelect.value = "";
            M.FormSelect.init(ageUnitSelect); 
            const genderSelect = document.getElementById('gender');
            if (resp.gender) genderSelect.value = resp.gender; else genderSelect.value = "";
            M.FormSelect.init(genderSelect); 
            document.getElementById('phone').value = resp.phone || '';
            document.getElementById('address').value = resp.address || '';
            M.toast({html: 'Patient details populated.', classes: 'green lighten-1'});
        } else {
            M.toast({html: resp.error || 'Patient not found.', classes: 'yellow darken-2'});
        }
        M.updateTextFields();
        updateBodyWithPatientData();
    })
    .catch(error => {
        M.toast({html: 'Error communicating with server.', classes: 'red'});
        console.error('Fetch Error:', error);
        M.updateTextFields();
        updateBodyWithPatientData();
    });
}

function updateBodyWithPatientData() {
    const bodyTextarea = document.getElementById('body'); if (!bodyTextarea) return;
    let currentBody = bodyTextarea.value;
    const patientData = {
        '{PATIENT_NAME}': document.getElementById('patient_name')?.value || '[Patient Name]',
        '{MRN}': document.getElementById('mrn')?.value || '[MRN]',
        '{AGE}': document.getElementById('age')?.value || '[Age]',
        '{GENDER}': document.getElementById('gender')?.value || '[Gender]',
        '{ADDRESS}': document.getElementById('address')?.value || '[Address]',
        '{AGE_GENDER}': (document.getElementById('age')?.value || '[Age]') + 
                        (document.getElementById('age_unit')?.value ? ' ' + document.getElementById('age_unit')?.value : '') + 
                        ' / ' + (document.getElementById('gender')?.value || '[Gender]')
    };
    const certificateTypeVal = document.querySelector('input[name="certificate_type"]')?.value;
    const globalTemplates = <?php echo json_encode($certificateTemplates); ?>;
    let baseTemplate = '';
    if (certificateTypeVal === 'Other') {
        baseTemplate = "Patient Name: {PATIENT_NAME}\nMRN: {MRN}\n\n[Please enter the full text of the certificate here.]";
    } else if (globalTemplates[certificateTypeVal]) {
        baseTemplate = globalTemplates[certificateTypeVal].body;
    }
    if (baseTemplate) {
        if (bodyTextarea.value.includes("[Enter") || bodyTextarea.value.length < baseTemplate.length + 50) {
            let newBody = baseTemplate;
            for (const placeholder in patientData) {
                const regex = new RegExp(placeholder.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');
                newBody = newBody.replace(regex, patientData[placeholder]);
            }
            bodyTextarea.value = newBody;
        }
    }
    M.textareaAutoResize(bodyTextarea);
    const bodyLabel = document.querySelector('label[for="body"]');
    if (bodyLabel && !bodyLabel.classList.contains('active')) { bodyLabel.classList.add('active'); }
}
</script>
</body>
</html>