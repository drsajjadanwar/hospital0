<?php
// /public/ticket.php - Revised Nov 10, 2025
// - Added ENT and Rizwan departments.
// - CRITICAL FIX: Added database logic to save the generated PDF invoice path
//   to the new 'visit_invoices' table. This is done inside the main
//   transaction to ensure data integrity.
// - Added Gynaecology and Radiology departments.
// - Added tracking for the patient's last visited department.
// - Updated ledger description format.
// - Replaced PDF text header with a logo file.
// - Implemented JS to disable the submit button and redirect on success.
// - Fixed FPDF font error by setting a default font early.
// - Corrected post-submission UI and actions.
// - Switched to JPG for logo to ensure compatibility.
// - Adjusted logo to display at full page width.
// - Patched ledger description logic to ensure department code is always included.

session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/config.php'; // must define $pdo
require_once __DIR__ . '/includes/fpdf.php';   // FPDF library

// --- Database Check and User Setup ---
$user = $_SESSION['user'] ?? [];
$userId = isset($user['user_id']) ? (int)$user['user_id'] : 0;
$currentUserFullName = 'Unknown User'; // Default

try {
     if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("Database connection is not available. Check config.php.");
     }
     $stmt = $pdo->prepare("SELECT group_id, full_name, username FROM users WHERE user_id=? LIMIT 1");
     $stmt->execute([$userId]);
     $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
     if (!$dbUser) {
         session_destroy();
         header("Location: login.php?error=UserNotFound");
         exit;
     }
     // Update session user data from DB
     $user['group_id']  = (int)$dbUser['group_id'];
     $user['full_name'] = $dbUser['full_name'] ?: $dbUser['username'];
     $user['username']  = $dbUser['username'];
     $_SESSION['user']  = $user;
     $currentUserFullName = $user['full_name']; // Use for display/PDF
} catch(Exception $ex) {
     error_log("Ticket.php - User Check DB error: " . $ex->getMessage());
     die("A database error occurred while verifying user information. Please try again later or contact support.");
}

// --- Access Control ---
$allowedGroups = [1, 5, 6, 8, 10, 23]; // Groups allowed to issue tickets
$no_rights = (!in_array($user['group_id'], $allowedGroups));

// --- Utility Functions ---

/**
 * Generate random numeric MRN (10 digits)
 */
function generateRandomMRN($pdo, $length = 10) {
    $maxAttempts = 10;
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $digits = '0123456789';
        $mrn = '';
        for ($i = 0; $i < $length; $i++) {
            $mrn .= $digits[random_int(0, strlen($digits) - 1)];
        }
        $stmt = $pdo->prepare("SELECT 1 FROM patients WHERE mrn = ? LIMIT 1");
        $stmt->execute([$mrn]);
        if ($stmt->fetchColumn() === false) {
            return $mrn;
        }
    }
    throw new Exception("Failed to generate a unique MRN after $maxAttempts attempts.");
}

/**
 * Get the latest visit number for a patient.
 */
function getLatestVisitNumber($pdo, $patientId) {
    $stmt = $pdo->prepare("SELECT MAX(visit_number) FROM visits WHERE patient_id = ?");
    $stmt->execute([(int)$patientId]);
    $maxVisit = $stmt->fetchColumn();
    return ($maxVisit === false || $maxVisit === null) ? 0 : (int)$maxVisit;
}

/**
 * Get short code for a department for ledger entries.
 */
function getDepartmentShortCode($department) {
    $dept_val = is_string($department) ? trim($department) : '';
    switch ($dept_val) {
        case 'Gynaecology': return 'GYN';
        case 'Radiology': return 'RAD';
        case 'Aesthetics': return 'AES';
        case 'ER': return 'ER';
        case 'Dental': return 'DEN';
        case 'InternalMedicine': return 'IM';
        case 'ERAdmission': return 'ERADM';
        case 'Psychologist': return 'PSY';
        case 'Physiotherapy': return 'PHY';
        // -- UPDATED --
        case 'ENT': return 'ENT';
        case 'Rizwan': return 'RIZ';
        // -- END UPDATE --
        default: return 'OTH';
    }
}


// --- PDF Generation Class ---
class MyriadPDF extends FPDF
{
    function __construct($orientation='P', $unit='mm', $size='A4')
    {
        parent::__construct($orientation, $unit, $size);
    }
    function Header() {}
    function Footer() {}
}

// --- AJAX Request Handler ---
if (isset($_GET['action']) && $_GET['action'] === 'lookup_patient') {
    header('Content-Type: application/json');
    $mrn = trim($_GET['mrn'] ?? '');
    $response = ['success' => false, 'error' => 'Invalid request'];

    if (empty($mrn)) {
        $response['error'] = 'MRN cannot be empty.';
    } else {
        try {
             if (!isset($pdo) || !($pdo instanceof PDO)) { throw new Exception("Database connection lost."); }

             $stmt = $pdo->prepare("SELECT patient_id, full_name FROM patients WHERE mrn = ? LIMIT 1");
             $stmt->execute([$mrn]);
             $patient = $stmt->fetch(PDO::FETCH_ASSOC);

             if (!$patient) {
                 $response['error'] = "No patient found with MRN: " . htmlspecialchars($mrn);
             } else {
                 $latestVisit = getLatestVisitNumber($pdo, $patient['patient_id']);
                 $response = [
                     'success' => true,
                     'full_name' => $patient['full_name'],
                     'latest_visit' => $latestVisit
                 ];
             }
        } catch (Exception $e) {
            $response['error'] = 'Database error: ' . $e->getMessage();
            error_log("AJAX lookup_patient error: " . $e->getMessage());
        }
    }
    echo json_encode($response);
    exit;
}


// --- Main Page Logic ---
$error_message   = '';
$success_message = '';
$pdfLink         = null;
$pdfBaseName     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$no_rights) {

    $isNewPatient = isset($_POST['is_new_patient']) && $_POST['is_new_patient'] === '1';
    $department   = trim($_POST['department'] ?? 'ER');
    $amount_recv  = trim($_POST['amount_received'] ?? '0');

    $patientId = null;
    $mrnUsed   = '';
    $nextVisitNumber = 1;

    $fullname  = '';
    $age_value = '0';
    $age_unit  = 'years';
    $gender    = 'Male';
    $phone     = '';
    $address   = '';

    $timeNow = date('Y-m-d H:i:s');

    // -- MODIFIED -- Start transaction earlier to include all operations
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        $error_message = "Database connection error. Please try again later.";
    } else {
        $pdo->beginTransaction();

        try {
            // 1) Create or find the patient
            if ($isNewPatient) {
                $fullname  = trim($_POST['fullname'] ?? '');
                $age_value = trim($_POST['age_value'] ?? '0');
                $age_unit  = trim($_POST['age_unit'] ?? 'years');
                $gender    = trim($_POST['gender'] ?? 'Male');
                $phone     = trim($_POST['phone'] ?? '');
                $address   = trim($_POST['address'] ?? '');

                if (empty($fullname) || empty($age_value) || empty($phone) || empty($address)) {
                     throw new Exception("For new patients, Name, Age, Phone, and Address are required.");
                }
                if (strpos($fullname, ' ') === false) {
                     throw new Exception("Please enter both a first and last name for the new patient.");
                }
                if (!preg_match('/^[0-9]{11}$/', $phone)) {
                     throw new Exception("Phone number must be exactly 11 digits.");
                }


                $mrnUsed = generateRandomMRN($pdo, 10);
                $nextVisitNumber = 1;

                $stmtP = $pdo->prepare("
                    INSERT INTO patients (mrn, full_name, gender, address, phone, age, age_unit, last_department)
                    VALUES (:mrn, :fn, :g, :ad, :ph, :ag, :au, :dept)
                ");
                $stmtP->execute([
                    ':mrn' => $mrnUsed, ':fn' => $fullname, ':g' => $gender,
                    ':ad' => mb_substr($address, 0, 255), ':ph' => $phone,
                    ':ag' => (int)$age_value, ':au' => $age_unit,
                    ':dept' => $department
                ]);
                $patientId = $pdo->lastInsertId();
                if (!$patientId) { throw new Exception("Failed to create new patient record."); }

            } else { // Existing Patient
                $existingMRN = trim($_POST['existing_mrn'] ?? '');
                if (empty($existingMRN)) {
                    throw new Exception("Please provide the existing MRN.");
                }

                $stmtE = $pdo->prepare("
                    SELECT patient_id, mrn, full_name, gender, address, phone, age, age_unit
                    FROM patients WHERE mrn = ? LIMIT 1
                ");
                $stmtE->execute([$existingMRN]);
                $pRow = $stmtE->fetch(PDO::FETCH_ASSOC);

                if (!$pRow) {
                    throw new Exception("No patient found with MRN: " . htmlspecialchars($existingMRN));
                }

                $patientId = (int)$pRow['patient_id'];
                $mrnUsed   = $pRow['mrn'];

                $fullname  = $pRow['full_name'] ?? 'N/A';
                $gender    = $pRow['gender'] ?? 'N/A';
                $address   = $pRow['address'] ?? 'N/A';
                $phone     = $pRow['phone'] ?? 'N/A';
                $age_value = (string)($pRow['age'] ?? '0');
                $age_unit  = $pRow['age_unit'] ?? 'years';

                $latestVisit = getLatestVisitNumber($pdo, $patientId);
                $nextVisitNumber = $latestVisit + 1;
                
                $stmtUpdateDept = $pdo->prepare("UPDATE patients SET last_department = :dept WHERE patient_id = :pid");
                $stmtUpdateDept->execute([':dept' => $department, ':pid' => $patientId]);
            }

            // 2) Insert row into visits
            $stmtV = $pdo->prepare("
                INSERT INTO visits (patient_id, visit_number, department, time_of_presentation, age_value, age_unit, total_amount)
                VALUES (:pid, :vn, :dep, :tp, :agev, :ageu, :amt)
            ");
            $stmtV->execute([
                ':pid' => $patientId, ':vn' => $nextVisitNumber, ':dep' => $department,
                ':tp' => $timeNow, ':agev' => (int)$age_value, ':ageu' => $age_unit,
                ':amt' => (float)$amount_recv
            ]);
            $visitId = $pdo->lastInsertId();
             if (!$visitId) { throw new Exception("Failed to create new visit record."); }

            // 3) Insert a ledger entry
            $ledgerAmount = abs((float)$amount_recv);
            if ($ledgerAmount > 0) {
                  $shortDept = getDepartmentShortCode($department);
                  $ledgerDesc = trim("{$shortDept} MRN {$mrnUsed} Visit #{$nextVisitNumber}");
                  $ledgerUser = $user['username'] ?? 'system';

                  $stmtLedger = $pdo->prepare("
                      INSERT INTO generalledger (datetime, description, amount, user)
                      VALUES (:dt, :ds, :am, :usr)
                  ");
                  $stmtLedger->execute([
                      ':dt' => $timeNow, ':ds' => $ledgerDesc,
                      ':am' => $ledgerAmount, ':usr' => $ledgerUser
                  ]);
            }

            // 4) Generate PDF
            $safe_mrn = preg_replace('/[^a-zA-Z0-9_-]/', '_', $mrnUsed);
            $safe_vnum = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$nextVisitNumber);
            $pdfBaseName = 'Ticket_'.$safe_mrn.'_Visit_'.$safe_vnum.'.pdf';
            $pdfDir  = __DIR__.'/patiententries/';
            if (!is_dir($pdfDir)) { 
                if (!@mkdir($pdfDir, 0775, true)) {
                    throw new Exception("Failed to create PDF directory. Check permissions.");
                }
            }

            $pdfPath = $pdfDir . $pdfBaseName;
            $n = 1;
            while (file_exists($pdfPath)) {
                $n++;
                $pdfBaseName = 'Ticket_' . $safe_mrn . '_Visit_' . $safe_vnum . '_' . $n . '.pdf';
                $pdfPath = $pdfDir . $pdfBaseName;
            }
            $pdfLink = 'patiententries/' . basename($pdfPath);

            // --- PDF Creation ---
            $pdf = new MyriadPDF('P','mm','A4');
            $pdf->AddFont('MyriadPro-Regular','','MyriadPro-Regular.php');

            $pdfLeftMargin = 15.0;
            $pdfRightMargin = 15.0;
            $pdfTopMargin = 10.0;
            $pdfBottomMargin = 15.0;

            $pdf->SetMargins($pdfLeftMargin, $pdfTopMargin);
            $pdf->SetAutoPageBreak(true, $pdfBottomMargin);
            $pdf->AddPage();
            
            $pdf->SetFont('MyriadPro-Regular','',12);

            $pageWidth = $pdf->GetPageWidth();
            $contentWidth = $pageWidth - $pdfLeftMargin - $pdfRightMargin;

            $logoPath = __DIR__.'/media/headerlogo.jpg';
            if (file_exists($logoPath) && is_readable($logoPath)) {
                try {
                    list($originalWidth, $originalHeight) = getimagesize($logoPath);
                    if ($originalWidth > 0 && $originalHeight > 0) {
                        $aspectRatio = $originalHeight / $originalWidth;
                        $logoWidth = $contentWidth;
                        $logoHeight = $logoWidth * $aspectRatio;
                        $pdf->Image($logoPath, $pdfLeftMargin, $pdfTopMargin, $logoWidth, $logoHeight, 'JPG');
                        $pdf->Ln($logoHeight + 5);
                    } else {
                        throw new Exception("Invalid image dimensions.");
                    }
                } catch (Exception $imgEx) {
                     error_log("FPDF Image Error in ticket.php: " . $imgEx->getMessage());
                     $pdf->Ln(10); $pdf->Cell(0, 6, '(Error loading logo)', 0, 1, 'C'); $pdf->Ln(10);
                }
            } else {
                error_log("FPDF Image not found or not readable: " . $logoPath);
                $pdf->Ln(30);
            }

            // Ticket Title
            $pdf->SetTextColor(0,0,0);
            $pdf->SetFont('MyriadPro-Regular','',16);
            $pdf->Cell(0,8,'PATIENT VISIT TICKET',0,1,'C');
            $pdf->Ln(8);

            $pdfRow = function($label, $value) use ($pdf, $contentWidth) {
                $labelWidth = 45; $valueWidth = $contentWidth - $labelWidth; $lineHeight = 7;
                $pdf->SetFont('MyriadPro-Regular','',11);
                $pdf->Cell($labelWidth, $lineHeight, $label, 0, 0, 'L');
                $pdf->SetFont('MyriadPro-Regular','',11);
                $currentY = $pdf->GetY();
                $pdf->MultiCell($valueWidth, $lineHeight, htmlspecialchars_decode((string)$value), 0, 'L');
                $pdf->SetY(max($currentY + $lineHeight, $pdf->GetY()));
            };

            $pdf->SetFont('MyriadPro-Regular','',12);
            $pdfRow('Patient Name:', $fullname);
            $pdfRow('Age:', $age_value .' '. $age_unit);
            $pdfRow('Gender:', $gender);
            $pdfRow('Phone:', $phone);
            $pdfRow('Address:', $address);
            $pdf->Ln(2);
            $pdfRow('MRN:', $mrnUsed);
            $pdfRow('Visit Number:', $nextVisitNumber);
            $pdfRow('Department:', $department);
            $pdf->Ln(2);
            $pdfRow('Time of Issue:', $timeNow);
            $pdfRow('Amount Received:', 'PKR '.number_format($ledgerAmount, 2));

            $pdf->Ln(15);
            $pdf->SetFont('MyriadPro-Regular','',9);
            $pdf->SetTextColor(100,100,100);
            $currentTime = new DateTime('now', new DateTimeZone('Asia/Karachi'));
            $pdf->Cell(0,6,'Ticket generated by '.$currentUserFullName.' on ' . $currentTime->format('Y-m-d H:i:s T'), 0, 1, 'C');
            $pdf->Ln(5);
            $pdf->SetFont('MyriadPro-Regular','',10);
            $pdf->Cell(0,6,'hospital0',0,1,'C');

            // -- MODIFIED -- Use error suppression on Output to prevent FPDF warnings breaking the transaction
            @$pdf->Output('F', $pdfPath);
             if (!file_exists($pdfPath) || filesize($pdfPath) === 0) {
                 throw new Exception("Failed to save the Ticket PDF file to disk. Check permissions for 'patiententries' folder.");
             }

            // -- NEW (CRITICAL FIX) --
            // 5) Insert PDF record into the new visit_invoices table
            $stmtInvoice = $pdo->prepare("
                INSERT INTO visit_invoices (mrn, visit_number, invoice_name, pdf_path, created_by)
                VALUES (:mrn, :vn, :name, :path, :uid)
            ");
            $stmtInvoice->execute([
                ':mrn'  => $mrnUsed,
                ':vn'   => $nextVisitNumber,
                ':name' => $pdfBaseName,
                ':path' => $pdfLink,
                ':uid'  => $userId
            ]);
            
            // Add stringent check
            if ($stmtInvoice->rowCount() === 0) {
                // If this fails, the whole transaction will be rolled back.
                throw new Exception("Failed to save the invoice link to the database. The visit was not created.");
            }
            // -- END NEW --


            // 6) Commit transaction
            $pdo->commit();

            $success_message = "Ticket saved successfully (MRN: $mrnUsed, Visit #: $nextVisitNumber).";

        } catch(Exception $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = "Error: " . $ex->getMessage();
             error_log("Ticket.php POST Error: " . $ex->getMessage() . "\nTrace: " . $ex->getTraceAsString());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>hospital0 - Issue New Ticket - Anwar Healthcare</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/media/sitelogo.png" type="image/png">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    /* --- NEW BEAUTIFICATION STYLES --- */

    /* Override the global gradient from style.css ONLY for this page */
    body {
        background-image: none !important;
        background-color: #121212 !important;
        display: flex; 
        min-height: 100vh; 
        flex-direction: column;
    }
    
    html, body {
        max-width: 100%;
        overflow-x: hidden;
    }

    main { flex: 1 0 auto; }
    
    /* --- Animated Starfield Background --- */
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

    /* --- 3D Canvas Background --- */
    #dna-canvas {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        z-index: -2; opacity: 0.25;
    }
    
    .container { width: 90%; max-width: 800px; margin: 20px auto;}
    .white-line { width: 60%; height: 1px; background: rgba(255, 255, 255, 0.3); border: none; margin: 20px auto; }
    
    .msg-ok { background: #4CAF50; color: #fff; padding: 12px 18px; margin: 15px 0; border-radius: 4px; display: flex; align-items: center; }
    .msg-err { background: #F44336; color: #fff; padding: 12px 18px; margin: 15px 0; border-radius: 4px; display: flex; align-items: center; }
    .msg-ok i, .msg-err i { margin-right: 10px; }
    
    /* Form input styling */
    input:not([type="radio"]):not([type="checkbox"]),
    textarea.materialize-textarea,
    select {
      border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important;
      color: #ffffff !important;
      box-shadow: none !important;
    }
    input:not([type="radio"]):not([type="checkbox"]):focus:not([readonly]),
    textarea.materialize-textarea:focus:not([readonly]),
    select:focus {
      border-bottom: 1px solid #00e5ff !important;
      box-shadow: 0 1px 0 0 #00e5ff !important;
    }
    .input-field label { color: #bdbdbd; }
    .input-field label.active { color: #00e5ff; }
    
    /* --- !! DEFINITIVE DROPDOWN FIX !! --- */
    /* This ensures the dropdown menu always appears on top of other content. */
    .dropdown-content { 
        background-color: #2a2a2a; 
        z-index: 9999 !important;
    }
    .dropdown-content li > span { color: #ffffff; }

    /* Radio button styling */
    [type="radio"]:checked+span:after, [type="radio"].with-gap:checked+span:after { background-color: #00e5ff; border-color: #00e5ff;}
    [type="radio"]:not(:checked)+span:before { border-color: #9e9e9e;}
    [type="radio"]+span { color: #e0e0e0; padding-left: 30px;}
    
    input[readonly]:not([type="radio"]):not([type="checkbox"]) {
        color: #bdbdbd !important;
        border-bottom: 1px dotted #757575 !important;
        background-color: rgba(255, 255, 255, 0.05) !important;
    }
    
    /* Title styling for a modern "glow" */
    h4.white-text, h6.white-text {
        font-weight: 300;
        text-shadow: 0 0 8px rgba(0, 229, 255, 0.5);
    }
    h6.card-title { 
        color: #00e5ff; 
        font-weight: 400; 
        border-bottom: 1px solid rgba(0, 229, 255, 0.4); 
        padding-bottom: 10px; margin-bottom: 25px;
    }
    h6.card-title i { vertical-align: middle; margin-right: 8px; }

    .btn, .btn-large, .btn-small { margin: 10px 5px; }
    
    /* --- Glassmorphism for Form Cards --- */
    .card {
        background: rgba(255, 255, 255, 0.08);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 25px;
    }

    .helper-text { color: #b0bec5; }
    /* FIX: Make MRN lookup helper text white */
    #existingMRNSection .helper-text { color: #ffffff !important; }

    #lookupResult { font-weight: bold; margin-left: 10px; }
  </style>
</head>
<body>

<div class="stars"></div>
<div class="twinkling"></div>
<canvas id="dna-canvas"></canvas>

<?php
    if (file_exists(__DIR__.'/includes/header.php')) {
        include_once __DIR__.'/includes/header.php';
    } else {
        echo '<nav><div class="nav-wrapper grey darken-3"><a href="#" class="brand-logo center">Anwar Healthcare Portal</a></div></nav>';
    }
?>

<main>
<div class="container">
  <?php if ($no_rights): ?>
    <div class="card red darken-2 center-align" style="padding: 20px; margin-top: 50px; backdrop-filter: none; background: #c62828;">
      <h5 class="white-text"><i class="material-icons left">lock_outline</i>Access Denied</h5>
      <p>You do not have permission to issue tickets.</p>
      <a href="dashboard.php" class="btn grey darken-1">Go to Dashboard</a>
    </div>
  <?php else: ?>
    <h4 class="center-align white-text" style="margin-top:30px;">Issue New Patient Ticket</h4>
    <hr class="white-line">

    <?php if (!empty($error_message)): ?>
      <div class="msg-err"><i class="material-icons">error_outline</i><?= htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    
    <?php if (!empty($success_message)): ?>
      <div class="card">
          <div class="center-align">
            <div class="msg-ok" style="justify-content: center;"><i class="material-icons">check_circle_outline</i><?= htmlspecialchars($success_message); ?></div>
            <div class="row" style="margin-top:40px;">
              <a href="<?= htmlspecialchars($pdfLink) ?>" class="btn-large waves-effect waves-light teal" download="<?= htmlspecialchars($pdfBaseName) ?>">
                  <i class="material-icons left">file_download</i>Download PDF
              </a>
              <a href="dashboard.php" class="btn-large waves-effect waves-light grey darken-1">
                  <i class="material-icons left">home</i>Go Home
              </a>
            </div>
          </div>
      </div>
      <?php
        if (isset($_POST['save_and_print']) && $pdfLink) {
             echo "<script>
                 document.addEventListener('DOMContentLoaded', function() {
                     var pdfWindow = window.open('".htmlspecialchars($pdfLink)."', '_blank');
                     if (pdfWindow) {
                         pdfWindow.focus();
                     } else {
                         alert('Popup blocked! Please allow popups for this site to print the ticket.');
                     }
                 });
             </script>";
        }
      ?>
    <?php else: ?>
      <form id="ticketForm" method="POST" action="ticket.php" onsubmit="return validateAndSubmitForm(this)">
        <div class="card">
          <h6 class="card-title white-text"><i class="material-icons">group_add</i>Patient Type</h6>
          <div class="row">
            <div class="col s12">
              <p style="margin-bottom: 15px;">
                <label>
                  <input name="is_new_patient" type="radio" value="1" checked onclick="toggleNewPatient(true)" />
                  <span>New Patient</span>
                </label>
              </p>
              <p>
                <label>
                  <input name="is_new_patient" type="radio" value="0" onclick="toggleNewPatient(false)" />
                  <span>Existing Patient</span>
                </label>
              </p>
            </div>
          </div>
          <div id="existingMRNSection" class="row" style="display:none; align-items: flex-end;">
            <div class="input-field col s12 m7 l7">
              <i class="material-icons prefix">badge</i>
              <input type="text" id="existing_mrn" name="existing_mrn" />
              <label for="existing_mrn">Enter Existing MRN</label>
              <span class="helper-text">Type MRN and press Tab or click away to lookup</span>
            </div>
            <div class="col s12 m5 l5" style="padding-bottom: 15px;">
              <span id="lookupResult"></span>
            </div>
          </div>
        </div>

        <div id="newPatientSection">
          <div class="card">
            <h6 class="card-title white-text"><i class="material-icons">person_add</i>New Patient Details</h6>
            <div class="row">
              <div class="input-field col s12 m8 l6">
                  <i class="material-icons prefix">account_circle</i>
                 <input type="text" id="fullname" name="fullname" required oninput="this.value = capitalizeWords(this.value)" />
                 <label for="fullname">Patient's Full Name</label>
              </div>
            </div>
            <div class="row">
              <div class="input-field col s6 m4 l3">
                  <i class="material-icons prefix">cake</i>
                 <input type="number" id="age_value" name="age_value" min="0" max="150" required />
                 <label for="age_value">Age</label>
              </div>
              <div class="input-field col s6 m4 l3">
                 <select id="age_unit" name="age_unit">
                    <option value="years" selected>Years</option>
                    <option value="months">Months</option>
                    <option value="days">Days</option>
                 </select>
                 <label for="age_unit">Unit</label>
              </div>
               <div class="input-field col s12 m4 l6">
                  <i class="material-icons prefix">wc</i>
                 <select id="gender" name="gender">
                    <option value="Male" selected>Male</option>
                    <option value="Female">Female</option>
                    <option value="Other">Other</option>
                 </select>
                 <label for="gender">Gender</label>
               </div>
            </div>
            <div class="row">
              <div class="input-field col s12 m6">
                  <i class="material-icons prefix">phone</i>
                 <input type="tel" id="phone" name="phone" placeholder="e.g., 03101234567" required inputmode="numeric" pattern="[0-9]{11}" minlength="11" maxlength="11" title="Please enter exactly 11 digits." />
                 <label for="phone">Phone Number</label>
              </div>
            </div>
            <div class="row">
              <div class="input-field col s12">
                  <i class="material-icons prefix">home</i>
                 <textarea id="address" name="address" class="materialize-textarea" data-length="250" required></textarea>
                 <label for="address">Address</label>
              </div>
            </div>
           </div>
        </div>

        <div class="card">
          <h6 class="card-title white-text"><i class="material-icons">assignment_ind</i>Visit Details</h6>
            <div class="row">
              <div class="input-field col s12 m8 l6">
                 <i class="material-icons prefix">business</i>
                 <select id="department" name="department">
                    <option value="ER" selected>ER</option>
                    <option value="Dental">Dental</option>
                    <option value="InternalMedicine">General Practice / Internal Medicine</option>
                    <option value="Aesthetics">Aesthetics</option>
                    <option value="Gynaecology">Gynaecology</option>
                    <option value="Radiology">Radiology</option>
                    <option value="ERAdmission">ER Indoor</option>
                    <option value="Psychologist">Clinical Psychologist</option>
                    <option value="Physiotherapy">Physiotherapy</option>
                    <!-- -- UPDATED -- -->
                    <option value="ENT">ENT</option>
                    <option value="Rizwan">Rizwan</option>
                    <!-- -- END UPDATE -- -->
                    <option value="Other">Other</option>
                 </select>
                 <label for="department">Department</label>
              </div>
            </div>
            <div class="row">
              <div class="input-field col s12 m4 l3">
                  <i class="material-icons prefix">confirmation_number</i>
                  <input type="number" min="1" id="visit_number" name="visit_number" value="1" readonly required />
                 <label for="visit_number" class="active">Visit Number</label>
              </div>
               <div class="col s12 m8 l9" id="previousVisitDisplay" style="display: none; padding-top: 20px;">
                   <p class="white-text" style="font-size: 0.9em;">(Previous Visit: <strong id="latestVisitNumber" class="cyan-text text-lighten-2">N/A</strong>)</p>
               </div>
            </div>
        </div>

        <div class="card">
          <h6 class="card-title white-text"><i class="material-icons">payment</i>Payment</h6>
            <div class="row">
              <div class="input-field col s12 m6 l4">
                 <i class="material-icons prefix">attach_money</i>
                 <input type="number" min="0" step="1" id="amount_received" name="amount_received" value="0" required />
                 <label for="amount_received">Amount Received (PKR)</label>
              </div>
            </div>
        </div>

        <div class="row center-align" style="margin-top:30px;">
          <div class="col s12">
            <button type="submit" id="submitBtn" class="btn-large waves-effect waves-light" name="save_and_print" style="background-color: #00bfa5;">
                <i class="material-icons left">print</i>Save &amp; Print Ticket
            </button>
            </div>
        </div>
      </form>
    <?php endif; ?>
  <?php endif; ?> </div> </main>

<?php
    if (file_exists(__DIR__.'/includes/footer.php')) {
        include_once __DIR__.'/includes/footer.php';
    } else {
        echo '<footer class="page-footer grey darken-3"><div class="footer-copyright grey darken-4"><div class="container center-align">© '.date("Y").' Anwar Healthcare</div></div></footer>';
    }
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  M.CharacterCounter.init(document.querySelectorAll('.materialize-textarea'));
  M.FormSelect.init(document.querySelectorAll('select'));
  toggleNewPatient(true);
  document.getElementById('existing_mrn')?.addEventListener('blur', lookupPatientInfo);
  M.updateTextFields();
});

// --- NEW SCRIPT FOR VALIDATION AND CAPITALIZATION ---

function capitalizeWords(str) {
    if (!str) return '';
    return str.toLowerCase().replace(/\b\w/g, char => char.toUpperCase());
}

function validateAndSubmitForm(form) {
    const isNewPatientRadio = form.querySelector('input[name="is_new_patient"][value="1"]');
    
    if (isNewPatientRadio && isNewPatientRadio.checked) {
        // Validation for new patients
        const fullNameInput = document.getElementById('fullname');
        const phoneInput = document.getElementById('phone');

        // 1. Full Name Check (must contain a space)
        if (fullNameInput.value.trim().indexOf(' ') === -1) {
            M.toast({html: 'Please enter both first and last name.', classes: 'red darken-2'});
            fullNameInput.focus();
            return false; // Prevent submission
        }

        // 2. Phone Number Check (must be 11 digits)
        const phonePattern = /^[0-9]{11}$/;
        if (!phonePattern.test(phoneInput.value)) {
            M.toast({html: 'Phone number must be exactly 11 digits.', classes: 'red darken-2'});
            phoneInput.focus();
            return false; // Prevent submission
        }
    }

    // If validation passes, disable the button and submit
    const submitButton = document.getElementById('submitBtn');
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.classList.remove('green');
        submitButton.classList.add('grey');
        submitButton.innerHTML = '<i class="material-icons left">hourglass_top</i>Processing...';
    }
    return true; // Allow submission
}


// --- ORIGINAL SCRIPT FOR FORM FUNCTIONALITY ---

function toggleNewPatient(isNew){
  const newPatientDiv     = document.getElementById('newPatientSection');
  const existingMRNDiv    = document.getElementById('existingMRNSection');
  const previousVisitDiv  = document.getElementById('previousVisitDisplay');
  const visitNumberInput  = document.getElementById('visit_number');
  const latestVisitSpan   = document.getElementById('latestVisitNumber');
  const lookupResultSpan  = document.getElementById('lookupResult');

  const fullNameInput  = document.getElementById('fullname');
  const ageValueInput  = document.getElementById('age_value');
  const phoneInput     = document.getElementById('phone');
  const addressInput   = document.getElementById('address');
  const existingMRNInput = document.getElementById('existing_mrn');

  if(isNew){
    newPatientDiv.style.display    = 'block';
    existingMRNDiv.style.display   = 'none';
    previousVisitDiv.style.display = 'none';
    visitNumberInput.value = '1';
    visitNumberInput.readOnly = true;
    if (latestVisitSpan) latestVisitSpan.textContent = 'N/A';
    if (lookupResultSpan) lookupResultSpan.textContent = '';
    fullNameInput.required  = true;
    ageValueInput.required  = true;
    phoneInput.required     = true;
    addressInput.required   = true;
    existingMRNInput.required = false;
  } else {
    newPatientDiv.style.display    = 'none';
    existingMRNDiv.style.display   = 'flex';
    existingMRNDiv.style.alignItems = 'flex-end';
    previousVisitDiv.style.display = 'block';
    visitNumberInput.value = '';
    visitNumberInput.readOnly = true;
    if (latestVisitSpan) latestVisitSpan.textContent = 'N/A';
    if (lookupResultSpan) {
        lookupResultSpan.textContent = 'Enter MRN to lookup...';
        lookupResultSpan.style.color = '#b0bec5';
    }
    fullNameInput.required  = false;
    ageValueInput.required  = false;
    phoneInput.required     = false;
    addressInput.required   = false;
    existingMRNInput.required = true;
    existingMRNInput.value = '';
    existingMRNInput.focus();
  }
  M.updateTextFields();
}

function lookupPatientInfo() {
    const mrnInput = document.getElementById('existing_mrn');
    const mrn = mrnInput.value.trim();
    const lookupResultSpan = document.getElementById('lookupResult');
    const visitNumberInput = document.getElementById('visit_number');
    const latestVisitSpan = document.getElementById('latestVisitNumber');

    if (!mrn) {
        lookupResultSpan.textContent = '';
        visitNumberInput.value = '';
        latestVisitSpan.textContent = 'N/A';
        return;
    }

    lookupResultSpan.textContent = 'Looking up...';
    lookupResultSpan.style.color = '#ffeb3b';

    fetch(`ticket.php?action=lookup_patient&mrn=${encodeURIComponent(mrn)}`)
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => { throw new Error(`HTTP error! ${response.status}: ${text}`); });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                lookupResultSpan.textContent = `Patient: ${data.full_name}`;
                lookupResultSpan.style.color = '#4caf50';
                const latestVisit = parseInt(data.latest_visit) || 0;
                const nextVisit = latestVisit + 1;
                visitNumberInput.value = nextVisit;
                latestVisitSpan.textContent = (latestVisit > 0) ? latestVisit : 'None';
                M.updateTextFields();
            } else {
                lookupResultSpan.textContent = data.error || 'Patient not found.';
                lookupResultSpan.style.color = '#f44336';
                visitNumberInput.value = '';
                latestVisitSpan.textContent = 'N/A';
                if (visitNumberInput.labels.length > 0) {
                     visitNumberInput.labels[0].classList.add('active');
                }
            }
        })
        .catch(error => {
            console.error('Fetch Error:', error);
            lookupResultSpan.textContent = 'Lookup failed.';
            lookupResultSpan.style.color = '#f44336';
            visitNumberInput.value = '';
            latestVisitSpan.textContent = 'N/A';
            if (visitNumberInput.labels.length > 0) {
                 visitNumberInput.labels[0].classList.add('active');
            }
        });
}
</script>

<script type="importmap">
    {
        "imports": {
            "three": "https://cdn.jsdelivr.net/npm/three@0.164.1/build/three.module.js"
        }
    }
</script>
<script type="module">
    // --- Three.js for 3D DNA Helix ---
    import * as THREE from 'three';

    // Basic setup: scene, camera, renderer
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
    const renderer = new THREE.WebGLRenderer({
        canvas: document.querySelector('#dna-canvas'),
        alpha: true // Make canvas transparent
    });
    renderer.setPixelRatio(window.devicePixelRatio);
    renderer.setSize(window.innerWidth, window.innerHeight);

    // Position camera
    camera.position.setZ(30);

    // --- Create the DNA Helix ---
    const dnaGroup = new THREE.Group();
    const radius = 5;
    const tubeRadius = 0.5;
    const radialSegments = 8;
    const tubularSegments = 64;
    const height = 40;
    const turns = 4;
    
    class HelixCurve extends THREE.Curve {
        constructor(scale = 1, turns = 5, offset = 0) {
            super();
            this.scale = scale;
            this.turns = turns;
            this.offset = offset;
        }
        getPoint(t) {
            const tx = Math.cos(this.turns * 2 * Math.PI * t + this.offset);
            const ty = t * height - height / 2;
            const tz = Math.sin(this.turns * 2 * Math.PI * t + this.offset);
            return new THREE.Vector3(tx, ty, tz).multiplyScalar(this.scale);
        }
    }

    const backboneMaterial = new THREE.MeshStandardMaterial({
        color: 0x2196f3, metalness: 0.5, roughness: 0.2
    });

    const path1 = new HelixCurve(radius, turns, 0);
    const path2 = new HelixCurve(radius, turns, Math.PI);
    const backbone1 = new THREE.Mesh(new THREE.TubeGeometry(path1, tubularSegments, tubeRadius, radialSegments, false), backboneMaterial);
    const backbone2 = new THREE.Mesh(new THREE.TubeGeometry(path2, tubularSegments, tubeRadius, radialSegments, false), backboneMaterial);
    dnaGroup.add(backbone1, backbone2);

    const pairMaterial = new THREE.MeshStandardMaterial({
        color: 0xffeb3b, metalness: 0.2, roughness: 0.5
    });

    const steps = 50;
    for (let i = 0; i <= steps; i++) {
        const t = i / steps;
        const point1 = path1.getPoint(t);
        const point2 = path2.getPoint(t);
        
        const direction = new THREE.Vector3().subVectors(point2, point1);
        const rungGeometry = new THREE.CylinderGeometry(0.3, 0.3, direction.length(), 6);
        const rung = new THREE.Mesh(rungGeometry, pairMaterial);
        
        rung.position.copy(point1).add(direction.multiplyScalar(0.5));
        rung.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), direction.normalize());
        
        dnaGroup.add(rung);
    }
    
    scene.add(dnaGroup);
    
    const ambientLight = new THREE.AmbientLight(0xffffff, 0.5);
    scene.add(ambientLight);
    const pointLight = new THREE.PointLight(0xffffff, 1);
    pointLight.position.set(5, 15, 15);
    scene.add(pointLight);

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

</body>
</html>