<?php
/* /public/physiotherapy.php  — 27 May 2025 (Adapted from Aesthetics)
 * Senior Physiotherapist Portal – groups 1 (CMO) & 2 (Physiotherapists) - Adjust groups if needed
 * COMPLETE, READY-TO-DEPLOY FILE  – copy/paste as-is.
 * --------------------------------------------------------------------------- */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ───────────────────────────── 0 BOOTSTRAP ─────────────────────────────

session_start();
require_once __DIR__ . '/includes/config.php';   //  $pdo  (ERRMODE_EXCEPTION)
require_once __DIR__ . '/includes/fpdf.php';
require_once __DIR__ . '/includes/code128.php';

date_default_timezone_set('Asia/Karachi'); // Or your clinic's timezone

/* ───────────────────── 0  AUTHORISATION ───────────────────── */
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
$user = $_SESSION['user'];   $uid = (int)$user['user_id']; $gid = (int)$user['group_id'];
// Adjust group IDs if needed (e.g., [1, 3] if Physiotherapists are group 3)
if (!in_array($gid, [1, 20], true)) {
    include __DIR__.'/includes/header.php';
    echo '<div class="container center-align" style="padding-top:60px"><h4 class="red-text">Access denied</h4></div>';
    include __DIR__.'/includes/footer.php';
    exit;
}

/* ───────────────────── 1  HELPERS ───────────────────── */
function nextSessionNumber(PDO $pdo, string $mrn): int {
    $st = $pdo->prepare('SELECT COALESCE(MAX(session_number),0)+1 FROM physiotherapy WHERE mrn=?');
    $st->execute([$mrn]); return (int)$st->fetchColumn();
}
function latestVisitNumber(PDO $pdo, string $mrn): int {
    // Assumes 'patientregister' table has 'mrn' (lowercase) column and 'visit_number'
    $st = $pdo->prepare('SELECT COALESCE(MAX(visit_number),1) FROM patientregister WHERE mrn=?');
    $st->execute([$mrn]); return (int)$st->fetchColumn();
}

/* ───────────────────── 2  PDF  (ADAPTED FOR PHYSIOTHERAPY) ───────────────────── */
class PhysiotherapyPDF extends PDF_Code128 {
    function __construct() {
        parent::__construct('P','mm','A4');
        $this->SetMargins(15,22,15);
        $this->SetAutoPageBreak(true,25);
    }
    function Header(){} // Can be customized if needed
    function Footer() {
        $this->SetY(-20);
        $this->SetFont('Helvetica','',10);
        $this->SetTextColor(120,120,120);
        $this->Cell(0,6,'hospital0',0,0,'C'); // Or your clinic's name
    }
}

function generatePhysiotherapyPDF(PDO $pdo, string $mrn, int $sess): ?string {
    $q = $pdo->prepare(
        'SELECT physio.*, p.full_name
         FROM physiotherapy physio JOIN patients p ON p.mrn = physio.mrn
         WHERE physio.mrn = ? AND physio.session_number = ? LIMIT 1'
    );
    $q->execute([$mrn, $sess]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) { error_log("Physiotherapy PDF-gen: session not found for $mrn/$sess"); return null; }

    $outDir = __DIR__.'/physiotherapy/'; // Changed path
    if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
        error_log("Physiotherapy PDF-gen: cannot create $outDir"); return null;
    }
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $row['full_name']);
    $safeMrn  = preg_replace('/\D/', '', $mrn);
    $file     = "Physiotherapy_{$safeName}_{$safeMrn}_S{$sess}.pdf"; // Changed filename prefix
    $absPath  = $outDir . $file;
    $relPath  = 'physiotherapy/' . $file; // Changed path

    $pdf = new PhysiotherapyPDF();
    $pdf->SetTitle('Physiotherapy Treatment Summary', true);
    $pdf->AddPage();

    $pdf->SetFont('Helvetica','',20);
    $pdf->Cell(0,10,'hospital0',0,1,'C'); // Clinic Name
    $pdf->SetFont('Helvetica','',14);
    $pdf->Cell(0,8,'Physiotherapy Session Summary',0,1,'C'); // Changed title
    $pdf->Ln(4);

    $barcode = "PHYSIO-{$safeMrn}-S{$sess}"; // Changed barcode prefix
    $pdf->Code128(($pdf->GetPageWidth()-70)/2, $pdf->GetY(), $barcode, 70, 10);
    $pdf->Ln(16);

    $pdf->SetFont('Helvetica','',11);
    // Adapted labels for Physiotherapy
    $labels = [
        'Patient'               => $row['full_name'] ?? '-',
        'MRN'                   => $mrn,
        'Session #'             => $sess,
        'Visit #'               => $row['visit_number'] ?? '-',
        'Created'               => isset($row['created_at']) ? date('d-M-Y H:i', strtotime($row['created_at'])) : '-',
        'Presenting Complaint'  => $row['presenting_complaint'] ?? '-',
        'Clinical Impression'   => $row['clinical_impression'] ?? '-',
        'Treatment Plan'        => $row['treatment_plan'] ?? '-',
        'Treatment Administered'=> $row['treatment_administered_today'] ?? '-'
    ];
    foreach ($labels as $lbl => $val) {
        $pdf->SetFont('Helvetica','B',11);
        $pdf->Cell(50,6,"$lbl:",0,0); // Increased label width
        $pdf->SetFont('Helvetica','',11);
        $pdf->MultiCell(0,6, ($val === null || $val === '') ? '-' : $val,0,'L');
    }

    // Add treatment summary if locked
    if (!empty($row['is_locked']) && !empty($row['treatment_summary'])) {
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica','B',11);
        $pdf->MultiCell(0,6,'Overall Treatment Summary:',0,1);
        $pdf->SetFont('Helvetica','',11);
        $pdf->MultiCell(0,6, ($row['treatment_summary'] ?? '-') ?: '-', 0, 'L');
    }
    
    // You might want to add more fields to the PDF from the extensive list in the physiotherapy table.
    // For example: Objective Assessment, Home Exercise Program etc.
    // This is a basic summary.

    try {
        $pdf->Output('F', $absPath, true);
    } catch (Throwable $e) {
        error_log('Physiotherapy PDF-gen Output error: ' . $e->getMessage());
        return null;
    }

    $u = $pdo->prepare('UPDATE physiotherapy SET pdf_path=? WHERE mrn=? AND session_number=?');
    $u->execute([$relPath, $mrn, $sess]);

    return $absPath;
}
// ───────────────────────────── 3 ROUTER / LOGIC ─────────────────────────────

$action = $_GET['action'] ?? '';
$msg    = '';
$view = null;
$rows = [];
$showSignForm = false;
$showRetrieveForm = false;
$showAll = false;

// Variables for Create/Edit form
$showSessionForm = false;
$formData = [];
$formActionUrl = '';
$pageTitle = '';
$submitButtonText = '';
$formMode = 'create'; // 'create' or 'edit'


// 3.a  AJAX MRN Lookup
if ($action==='lookup_mrn'){
    header('Content-Type: application/json');
    $mrn_lookup = trim($_GET['mrn'] ?? '');
    if(!$mrn_lookup){ echo json_encode(['success'=>false, 'message'=>'MRN required.']); exit; }

    $p_stmt = $pdo->prepare('SELECT full_name, gender, age, age_unit FROM patients WHERE mrn=? LIMIT 1');
    $p_stmt->execute([$mrn_lookup]);
    $pat = $p_stmt->fetch(PDO::FETCH_ASSOC);

    if(!$pat){ echo json_encode(['success'=>false, 'message'=>'Patient not found.']); exit; }

    $pat['latest_visit'] = latestVisitNumber($pdo, $mrn_lookup);
    echo json_encode(['success'=>true] + $pat);
    exit;
}

// 3.b  AJAX Signature Save
if ($action==='save_signature' && $_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    $img = $_POST['img'] ?? '';
    if(!$img || !preg_match('/^data:image\/\w+;base64,/', $img)) {
        echo json_encode(['ok'=>false, 'message'=>'Invalid image data.']);
        exit;
    }

    $dir = __DIR__.'/physiotherapy/signatures/'; // Changed path
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            error_log("Failed to create signature directory: " . $dir);
            echo json_encode(['ok'=>false, 'message'=>'Failed to create signature directory. Check server logs.']);
            exit;
        }
    }

    $name = bin2hex(random_bytes(8)).'.png';
    $decodedImg = base64_decode(preg_replace('#^data:image/\w+;base64,#i','',$img));
    if (file_put_contents($dir.$name, $decodedImg) === false) {
        error_log("Failed to save signature file: " . $dir.$name);
        echo json_encode(['ok'=>false, 'message'=>'Failed to save signature file. Check server logs.']);
        exit;
    }

    echo json_encode(['ok'=>true,'path'=>'physiotherapy/signatures/'.$name]); // Changed path
    exit;
}

// 3.c  CREATE Session
if ($action==='create' && $_SERVER['REQUEST_METHOD']==='POST'){
    $mrn_form = trim($_POST['mrn'] ?? '');
    if($mrn_form===''){ $msg='Error – MRN required.'; goto render_physiotherapy; }

    $stmt_patient_check = $pdo->prepare('SELECT 1 FROM patients WHERE mrn=? LIMIT 1');
    $stmt_patient_check->execute([$mrn_form]);
    if(!$stmt_patient_check->fetchColumn()){
        $msg='Error – Patient not found with this MRN.'; goto render_physiotherapy;
    }

    $sess_create  = nextSessionNumber($pdo, $mrn_form);
    $visit_create = latestVisitNumber($pdo, $mrn_form);

    // Adapted columns for Physiotherapy
    $columns=[
        'mrn','session_number','visit_number','session_name',
        'presenting_complaint', 'history_of_presenting_complaint', 'relevant_medical_history',
        'functional_limitations', 'patient_goals', 'social_history',
        'observation', 'palpation', 'range_of_motion', 'muscle_strength', 'special_tests',
        'neurological_assessment', 'functional_tests', 'respiratory_assessment',
        'clinical_impression', 'treatment_plan',
        'treatment_administered_today', 'home_exercise_program', 'precautions_contraindications',
        'follow_up_recommended', 'notes',
        'consent_text','consent_signature_path','consent_signed_by','consent_signed_at',
        'is_locked','locked_by','locked_at','pdf_path','created_by'
        // treatment_summary is usually added at sign & lock
    ];
    $ph = rtrim(str_repeat('?,',count($columns)),',');
    $g  = fn($k)=>($_POST[$k] ?? '') !== '' ? trim($_POST[$k]) : null;
    $sigPath_create = $g('sig_path_physio'); // Changed ID

    $values=[
        $mrn_form, $sess_create, $visit_create, $g('session_name'),
        $g('presenting_complaint'), $g('history_of_presenting_complaint'), $g('relevant_medical_history'),
        $g('functional_limitations'), $g('patient_goals'), $g('social_history'),
        $g('observation'), $g('palpation'), $g('range_of_motion'), $g('muscle_strength'), $g('special_tests'),
        $g('neurological_assessment'), $g('functional_tests'), $g('respiratory_assessment'),
        $g('clinical_impression'), $g('treatment_plan'),
        $g('treatment_administered_today'), $g('home_exercise_program'), $g('precautions_contraindications'),
        $g('follow_up_recommended'), $g('notes'),
        $g('consent_text'), $sigPath_create,
        $sigPath_create ? $uid : null,
        $sigPath_create ? date('Y-m-d H:i:s') : null,
        0, null, null, null, $uid
    ];

    try{
        $insertStmt = $pdo->prepare('INSERT INTO physiotherapy ('.implode(',',$columns).") VALUES ($ph)");
        $insertStmt->execute($values);
        header("Location: physiotherapy.php?action=view&mrn=$mrn_form&session=$sess_create&status=created"); exit;
    }catch(Exception $e){ $msg='Database error: '.$e->getMessage(); error_log("Physiotherapy Create Error: ".$e->getMessage());}
} else if ($action==='create' && $_SERVER['REQUEST_METHOD']!=='POST') {
    $formMode = 'create';
    $pageTitle = 'Add New Physiotherapy Session'; // Changed title
    $formData = $_POST; 
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $formData = [];

    $formActionUrl = 'physiotherapy.php?action=create'; // Changed URL
    $submitButtonText = 'Save Session';
    $showSessionForm = true;
    goto render_physiotherapy;
}


// 3.d  SIGN & LOCK
if ($action==='sign'){
    $mrn_sign = $_GET['mrn'] ?? ''; $sess_sign = (int)($_GET['session'] ?? 0);
    if(!$mrn_sign || !$sess_sign){ $msg='Invalid parameters for signing.'; goto render_physiotherapy; }

    if($_SERVER['REQUEST_METHOD']==='POST'){
        $summary_sign = trim($_POST['treatment_summary'] ?? '');
        if (mb_strlen($summary_sign) < 10) {
             $msg = 'Error - Treatment summary is too short.'; $showSignForm = true;
             $_GET['mrn'] = $mrn_sign; $_GET['session'] = $sess_sign; 
             goto render_physiotherapy_sign_form_error;
        }
        if (mb_strlen($summary_sign) > 65000) { // Max TEXT length
            $msg = 'Error - Treatment summary is too long.'; $showSignForm = true;
            $_GET['mrn'] = $mrn_sign; $_GET['session'] = $sess_sign; 
            goto render_physiotherapy_sign_form_error;
        }

        $updateStmt = $pdo->prepare('UPDATE physiotherapy SET treatment_summary=?, is_locked=1, locked_by=?, locked_at=NOW() WHERE mrn=? AND session_number=? AND is_locked=0');
        $updateStmt->execute([$summary_sign, $uid, $mrn_sign, $sess_sign]);

        if ($updateStmt->rowCount() > 0) {
            $pdfPathGenerated = generatePhysiotherapyPDF($pdo, $mrn_sign, $sess_sign); // Changed call
            if ($pdfPathGenerated === null) {
                 error_log("PDF generation failed for $mrn_sign/$sess_sign after locking (Physiotherapy). Check permissions and FPDF setup.");
            }
            header("Location: physiotherapy.php?action=view&mrn=$mrn_sign&session=$sess_sign&status=signed"); // Changed URL
            exit;
        } else {
            $checkLockStmt = $pdo->prepare("SELECT is_locked FROM physiotherapy WHERE mrn=? AND session_number=?");
            $checkLockStmt->execute([$mrn_sign, $sess_sign]);
            $existing_lock = $checkLockStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_lock && $existing_lock['is_locked']) {
                $msg = "Session was already locked.";
            } else if ($existing_lock) {
                 $msg = "Error: Could not lock session. No changes made or session updated by another process.";
            } else {
                 $msg = "Error: Session not found for locking.";
            }
            $showSignForm = true;
            $_GET['mrn'] = $mrn_sign; $_GET['session'] = $sess_sign; 
            goto render_physiotherapy_sign_form_error;
        }
    }

    render_physiotherapy_sign_form_error: // Label for goto
    $mrn_for_view = $_GET['mrn'] ?? ''; 
    $sess_for_view = (int)($_GET['session'] ?? 0);

    if (!$mrn_for_view || !$sess_for_view) { 
        $msg = 'Error: Missing MRN or Session for displaying sign form.'; $action = ''; goto render_physiotherapy;
    }

    $stmt_view_for_sign = $pdo->prepare('SELECT physio.*,p.full_name FROM physiotherapy physio JOIN patients p ON p.mrn=physio.mrn WHERE physio.mrn=? AND physio.session_number=?');
    $stmt_view_for_sign->execute([$mrn_for_view, $sess_for_view]);
    $view = $stmt_view_for_sign->fetch(PDO::FETCH_ASSOC); 
    if(!$view){$msg='Session not found for signing.'; $action=''; goto render_physiotherapy;}
    $showSignForm = true;

    goto render_physiotherapy;
}

// 3.e  VIEW single session
if ($action==='view'){
    $mrn_view = $_GET['mrn'] ?? ''; $sess_view =(int)($_GET['session'] ?? 0);
    if(!$mrn_view || !$sess_view){ $msg='Missing parameters for view.'; $action = ''; goto render_physiotherapy; }

    $stmt_view = $pdo->prepare('SELECT physio.*,p.full_name FROM physiotherapy physio JOIN patients p ON p.mrn=physio.mrn WHERE physio.mrn=? AND physio.session_number=?');
    $stmt_view->execute([$mrn_view, $sess_view]);
    $view = $stmt_view->fetch(PDO::FETCH_ASSOC); 

    if(!$view){ $msg='Physiotherapy session not found.'; $action = ''; goto render_physiotherapy; } // Changed message
    goto render_physiotherapy;
}

// 3.f  RETRIEVE list
if ($action==='retrieve'){
    if($_SERVER['REQUEST_METHOD']!=='POST'){ $showRetrieveForm=true; goto render_physiotherapy; }

    $mrn_retrieve = trim($_POST['mrn'] ?? '');
    if(!$mrn_retrieve){ $msg='Enter MRN.'; $showRetrieveForm=true; goto render_physiotherapy; }

    try {
        $stmt_retrieve = $pdo->prepare('SELECT physio.session_number, physio.session_name, physio.created_at, p.full_name, physio.mrn FROM physiotherapy physio JOIN patients p ON p.mrn=physio.mrn WHERE physio.mrn=? ORDER BY session_number DESC');
        $stmt_retrieve->execute([$mrn_retrieve]);
        $rows = $stmt_retrieve->fetchAll(PDO::FETCH_ASSOC); 
    } catch (PDOException $e) {
        error_log("Physiotherapy Retrieve List PDOException: " . $e->getMessage());
        $msg = "Database error while retrieving sessions. Please check server logs.";
        $rows = []; 
    }
    $retrievedMrn = $mrn_retrieve;
    goto render_physiotherapy;
}

// 3.g  SHOW all sessions (paginated)
if ($action==='show'){
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 10;
    $total = 0; 
    $tp = 1;     
   
    try {
        $total_count_stmt = $pdo->query('SELECT COUNT(*) FROM physiotherapy'); // Changed table
        if ($total_count_stmt) {
            $total = (int)$total_count_stmt->fetchColumn();
        } else {
             error_log("Physiotherapy Show All: Failed to execute count query.");
             $msg = "Error: Could not retrieve total session count.";
        }

        $tp = ($total > 0) ? max(1, ceil($total / $perPage)) : 1;
        if($page > $tp) $page = $tp; 
        $off = ($page - 1) * $perPage;

        $query_show_all = "SELECT physio.mrn, physio.session_number, physio.session_name, physio.created_at, p.full_name
                           FROM physiotherapy physio
                           JOIN patients p ON p.mrn = physio.mrn
                           ORDER BY physio.created_at DESC
                           LIMIT $perPage OFFSET $off"; // Changed table
        $stmt_show_all = $pdo->query($query_show_all);

        if ($stmt_show_all) {
            $rows = $stmt_show_all->fetchAll(PDO::FETCH_ASSOC); 
        } else {
            error_log("Physiotherapy Show All: Failed to execute fetch rows query.");
            if (empty($msg)) $msg = "Error: Could not retrieve session list.";
        }

    } catch (PDOException $e) {
        error_log("Physiotherapy Show All PDOException: " . $e->getMessage());
        $msg = "Database error while retrieving sessions. Please check server logs for details.";
    }
    $showAll = true;
    goto render_physiotherapy;
}

// 3.h EDIT Session (Display form)
if ($action === 'edit') {
    $mrn_edit = $_GET['mrn'] ?? '';
    $sess_edit = (int)($_GET['session'] ?? 0);

    if (!$mrn_edit || !$sess_edit) {
        $msg = 'Error: Missing MRN or Session for editing.';
        $action = ''; 
        goto render_physiotherapy;
    }

    $stmt_edit = $pdo->prepare('SELECT physio.*, p.full_name, p.gender, p.age, p.age_unit FROM physiotherapy physio JOIN patients p ON p.mrn = physio.mrn WHERE physio.mrn = ? AND physio.session_number = ? LIMIT 1');
    $stmt_edit->execute([$mrn_edit, $sess_edit]);
    $sessionData = $stmt_edit->fetch(PDO::FETCH_ASSOC);

    if (!$sessionData) {
        $msg = 'Error: Physiotherapy session not found for editing.'; // Changed message
        $action = ''; 
        goto render_physiotherapy;
    }

    if (!empty($sessionData['is_locked'])) {
        header("Location: physiotherapy.php?action=view&mrn=" . urlencode($mrn_edit) . "&session=" . $sess_edit . "&status=locked_cant_edit"); // Changed URL
        exit;
    }

    $formMode = 'edit';
    $formData = $sessionData;
    $formData['visit_number_display'] = $sessionData['visit_number'];

    $pageTitle = 'Edit Physiotherapy Session (MRN: ' . htmlspecialchars($mrn_edit) . ', Session: ' . htmlspecialchars($sess_edit) . ')'; // Changed title
    $formActionUrl = "physiotherapy.php?action=update&mrn=" . urlencode($mrn_edit) . "&session=" . urlencode($sess_edit); // Changed URL
    $submitButtonText = 'Update Session';
    $showSessionForm = true;

    goto render_physiotherapy;
}

// 3.i UPDATE Session (Process form)
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mrn_update = $_GET['mrn'] ?? '';
    $sess_update = (int)($_GET['session'] ?? 0);

    if (!$mrn_update || !$sess_update) {
        $msg = 'Error: Invalid parameters for update.';
        $action = ''; 
        goto render_physiotherapy;
    }

    $stmt_check = $pdo->prepare('SELECT is_locked, consent_signature_path, consent_signed_by, consent_signed_at FROM physiotherapy WHERE mrn = ? AND session_number = ?');
    $stmt_check->execute([$mrn_update, $sess_update]);
    $currentSession = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$currentSession) {
        $msg = 'Error: Session not found for update.';
        $action = '';
        goto render_physiotherapy;
    }
    if ($currentSession['is_locked']) {
        header("Location: physiotherapy.php?action=view&mrn=" . urlencode($mrn_update) . "&session=" . $sess_update . "&status=locked_during_edit"); // Changed URL
        exit;
    }

    $g = fn($k) => ($_POST[$k] ?? '') !== '' ? trim($_POST[$k]) : null;

    $current_sig_path = $currentSession['consent_signature_path'];
    $posted_sig_path = $g('sig_path_physio'); // Changed ID

    $final_sig_path = $posted_sig_path;
    $final_sig_by = $currentSession['consent_signed_by'];
    $final_sig_at = $currentSession['consent_signed_at'];

    if ($posted_sig_path && $posted_sig_path !== $current_sig_path) { 
        $final_sig_by = $uid;
        $final_sig_at = date('Y-m-d H:i:s');
    } elseif ($posted_sig_path === '' && $current_sig_path) { 
        $final_sig_by = null;
        $final_sig_at = null;
        $final_sig_path = null; 
    }
    
    // Adapted fields for Physiotherapy
    $update_data = [
        'session_name' => $g('session_name'),
        'presenting_complaint' => $g('presenting_complaint'),
        'history_of_presenting_complaint' => $g('history_of_presenting_complaint'),
        'relevant_medical_history' => $g('relevant_medical_history'),
        'functional_limitations' => $g('functional_limitations'),
        'patient_goals' => $g('patient_goals'),
        'social_history' => $g('social_history'),
        'observation' => $g('observation'),
        'palpation' => $g('palpation'),
        'range_of_motion' => $g('range_of_motion'),
        'muscle_strength' => $g('muscle_strength'),
        'special_tests' => $g('special_tests'),
        'neurological_assessment' => $g('neurological_assessment'),
        'functional_tests' => $g('functional_tests'),
        'respiratory_assessment' => $g('respiratory_assessment'),
        'clinical_impression' => $g('clinical_impression'),
        'treatment_plan' => $g('treatment_plan'),
        'treatment_administered_today' => $g('treatment_administered_today'),
        'home_exercise_program' => $g('home_exercise_program'),
        'precautions_contraindications' => $g('precautions_contraindications'),
        'follow_up_recommended' => $g('follow_up_recommended'),
        'notes' => $g('notes'),
        'consent_text' => $g('consent_text'),
        'consent_signature_path' => $final_sig_path,
        'consent_signed_by' => $final_sig_by,
        'consent_signed_at' => $final_sig_at,
    ];

    $set_clauses = [];
    $execute_values = [];
    foreach ($update_data as $column => $value) {
        $set_clauses[] = "$column = ?";
        $execute_values[] = $value;
    }
    $execute_values[] = $mrn_update;
    $execute_values[] = $sess_update;

    $sql_update = 'UPDATE physiotherapy SET ' . implode(', ', $set_clauses) . ' WHERE mrn = ? AND session_number = ? AND is_locked = 0';

    try {
        $updateStmt = $pdo->prepare($sql_update);
        $updateStmt->execute($execute_values);

        if ($updateStmt->rowCount() > 0) {
            header("Location: physiotherapy.php?action=view&mrn=" . urlencode($mrn_update) . "&session=" . $sess_update . "&status=updated"); // Changed URL
            exit;
        } else {
            // Check if it was locked or no actual changes were made
            $stmt_check_again = $pdo->prepare('SELECT is_locked FROM physiotherapy WHERE mrn = ? AND session_number = ?');
            $stmt_check_again->execute([$mrn_update, $sess_update]);
            $session_after_attempt = $stmt_check_again->fetch(PDO::FETCH_ASSOC);
            if ($session_after_attempt && $session_after_attempt['is_locked']) {
                 header("Location: physiotherapy.php?action=view&mrn=" . urlencode($mrn_update) . "&session=" . $sess_update . "&status=locked_during_edit");
            } else {
                 header("Location: physiotherapy.php?action=view&mrn=" . urlencode($mrn_update) . "&session=" . $sess_update . "&status=update_failed_no_change");
            }
            exit;
        }
    } catch (Exception $e) {
        $msg = 'Database error during update: ' . $e->getMessage();
        error_log("Physiotherapy Update Error: " . $e->getMessage());
        
        $formMode = 'edit';
        $formData = $_POST; 
        $formData['mrn'] = $mrn_update;
        $formData['session_number'] = $sess_update;

        $stmt_patient_details = $pdo->prepare('SELECT full_name, gender, age, age_unit FROM patients WHERE mrn = ? LIMIT 1');
        $stmt_patient_details->execute([$mrn_update]);
        $patient_details_for_form = $stmt_patient_details->fetch(PDO::FETCH_ASSOC);
        if ($patient_details_for_form) {
            $formData = array_merge($formData, $patient_details_for_form);
        }
        
        $orig_sess_stmt = $pdo->prepare('SELECT visit_number FROM physiotherapy WHERE mrn=? AND session_number=?');
        $orig_sess_stmt->execute([$mrn_update, $sess_update]);
        $orig_sess_data = $orig_sess_stmt->fetch(PDO::FETCH_ASSOC);
        $formData['visit_number_display'] = $orig_sess_data['visit_number'] ?? latestVisitNumber($pdo, $mrn_update);


        $pageTitle = 'Edit Physiotherapy Session (Error updating)'; // Changed title
        $formActionUrl = "physiotherapy.php?action=update&mrn=" . urlencode($mrn_update) . "&session=" . urlencode($sess_update); // Changed URL
        $submitButtonText = 'Retry Update';
        $showSessionForm = true;
        goto render_physiotherapy;
    }
}


// fall-through → main menu

render_physiotherapy: // Changed label
if(isset($_GET['status'])){
    // Changed messages for Physiotherapy context
    if($_GET['status']==='created') $msg='Physiotherapy session created successfully.';
    if($_GET['status']==='signed')  $msg='Physiotherapy session signed & locked successfully.';
    if($_GET['status']==='updated') $msg='Physiotherapy session updated successfully.';
    if($_GET['status']==='locked_cant_edit') $msg='Error: Session is locked and cannot be edited.';
    if($_GET['status']==='locked_during_edit') $msg='Error: Session was locked before changes could be saved. No update occurred.';
    if($_GET['status']==='update_failed_no_change') $msg='Session not updated. Either no changes were made or it was locked.';
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>hospital0 - Physiotherapy Clinic Portal</title> <link rel="icon" href="/media/sitelogo.png" type="image/png"> <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css"> <style>
html,body{max-width:100%;overflow-x:hidden}
.white-line{width:50%;height:2px;background:#fff;border:none;margin:20px auto}
input[type=text]:not(.browser-default):focus:not([readonly]),
input[type=text]:not(.browser-default),
input[type=search]:not(.browser-default):focus:not([readonly]),
input[type=search]:not(.browser-default),
input[readonly],textarea.materialize-textarea:focus:not([readonly]),
textarea.materialize-textarea{color:#fff!important;background:transparent!important;border-bottom:1px solid #fff!important;box-shadow:0 1px 0 0 #fff!important}
label{color:#9e9e9e!important} label.active{color:#fff!important}
.input-field .prefix.active {color: #fff!important;}
hr.divider{border:none;height:2px;background:rgba(255,255,255,0.3);width:80%;margin:30px auto}
.sidenav{width:260px;background:#000!important} .sidenav a{color:#fff!important} .sidenav i{color:#fff!important}
.modal {background-color: #333 !important; color:white;}
.modal .modal-content {color:white;}
.modal .modal-footer {background-color: #333 !important;}
.btn-flat {color: #FFF !important;}
.btn-flat:focus {background-color: rgba(255,255,255,0.1) !important;}
select.browser-default { background-color: #333 !important; color:white !important; border: 1px solid #555 !important; border-radius: 2px;}
select.browser-default option { background-color: #333 !important; color: white !important; }
.input-field textarea.materialize-textarea + label {transform-origin: 0% 100%;}
p { line-height: 1.6em; }
input[readonly] { color: #bdbdbd !important; border-bottom: 1px dashed #757575 !important; }
</style></head><body>
<?php include_once __DIR__.'/includes/header.php'; ?>
<ul id="navPhysio" class="sidenav"> <li><a href="physiotherapy.php"><i class="material-icons">directions_run</i>Home</a></li> <li><a href="physiotherapy.php?action=create"><i class="material-icons">add_box</i>Add New Session</a></li> <li><a href="physiotherapy.php?action=retrieve"><i class="material-icons">search</i>Retrieve Session</a></li> <li><a href="physiotherapy.php?action=show"><i class="material-icons">view_list</i>Show All Sessions</a></li> </ul>
<a href="#" data-target="navPhysio" class="sidenav-trigger white-text" style="position:fixed;top:15px;right:15px;z-index:900"><i class="material-icons">menu</i></a>
<div class="container" style="margin-top:70px">
<h4 class="center-align white-text">Physiotherapy Clinic Portal</h4> <hr class="white-line"> <?php if($msg):?>
  <div class="card-panel <?=stripos($msg,'error')!==false || stripos($msg,'failed')!==false || stripos($msg,'locked')!==false ?'red lighten-1':'green lighten-1'?> white-text center"><?=htmlspecialchars($msg)?></div>
<?php endif;?>

<?php if($action==='' && !$showSessionForm): ?>
  <div class="row center" style="margin-top:40px">
    <a class="btn blue waves-effect waves-light" style="width:260px;margin:8px" href="physiotherapy.php?action=create">Add New Session</a><br> <a class="btn waves-effect waves-light"       style="width:260px;margin:8px" href="physiotherapy.php?action=retrieve">Retrieve Session</a><br> <a class="btn grey waves-effect waves-light" style="width:260px;margin:8px" href="physiotherapy.php?action=show">Show All Sessions</a> </div>
<?php endif;?>

<?php if($showRetrieveForm && $action==='retrieve'):?>
  <h5 class="white-text">Retrieve Physiotherapy Sessions by MRN</h5> <form method="POST" action="physiotherapy.php?action=retrieve"> <div class="input-field"><input id="mrn_r_retrieve_physio" name="mrn" type="text" class="validate white-text" required value="<?= htmlspecialchars($_POST['mrn'] ?? '') ?>"><label for="mrn_r_retrieve_physio">Patient MRN</label></div>
    <button type="submit" class="btn waves-effect waves-light">Search</button>
    <a href="physiotherapy.php" class="btn grey waves-effect waves-light">Back to Menu</a> </form>
<?php elseif(isset($retrievedMrn) && $action==='retrieve'): ?>
  <h5 class="white-text center">Physiotherapy Sessions for MRN: <?=htmlspecialchars($retrievedMrn)?></h5> <?php if(empty($rows)):?><p class="white-text center">No physiotherapy sessions found for this MRN.</p><?php else:?> <table class="striped responsive-table white-text">
      <thead><tr><th>Session #</th><th>Session Name</th><th>Patient Name</th><th>Date Created</th><th>Action</th></tr></thead><tbody>
      <?php foreach($rows as $r):?>
      <tr>
        <td><?=htmlspecialchars($r['session_number'])?></td>
        <td><?=htmlspecialchars($r['session_name']?:'-')?></td>
        <td><?=htmlspecialchars($r['full_name'])?></td>
        <td><?=date('d-M-Y H:i',strtotime($r['created_at']))?></td>
      <td><a class="btn-small blue waves-effect waves-light" href="physiotherapy.php?action=view&mrn=<?=urlencode($r['mrn'])?>&session=<?=urlencode($r['session_number'])?>">View</a></td></tr> <?php endforeach;?></tbody></table>
  <?php endif;?>
  <br><a href="physiotherapy.php?action=retrieve" class="btn grey waves-effect waves-light">New Search</a> <a href="physiotherapy.php" class="btn grey waves-effect waves-light">Back to Menu</a> <?php endif;?>


<?php if($showAll && $action === 'show'): ?>
  <h5 class="white-text center">All Physiotherapy Sessions (latest first)</h5> <?php if(empty($rows)):?><p class="center white-text">No physiotherapy sessions found (or error retrieving).</p><?php else:?> <table class="striped responsive-table white-text"><thead><tr><th>Session#</th><th>MRN</th><th>Patient Name</th><th>Session Name</th><th>Date Created</th><th>Action</th></tr></thead><tbody>
  <?php foreach($rows as $r):?>
    <tr>
      <td><?=htmlspecialchars($r['session_number'])?></td>
      <td><?=htmlspecialchars($r['mrn'])?></td>
      <td><?=htmlspecialchars($r['full_name'])?></td>
      <td><?=htmlspecialchars($r['session_name']?:'-')?></td>
      <td><?=date('d-M-Y H:i',strtotime($r['created_at']))?></td>
      <td><a class="btn-small blue waves-effect waves-light" href="physiotherapy.php?action=view&mrn=<?=urlencode($r['mrn'])?>&session=<?=urlencode($r['session_number'])?>">View</a></td></tr> <?php endforeach;?></tbody></table>
  <?php if($total > $perPage): ?>
      <ul class="pagination center">
        <li class="<?=$page <= 1 ? 'disabled' : 'waves-effect'?>"><a href="physiotherapy.php?action=show&page=<?=max(1, $page-1)?>"><i class="material-icons">chevron_left</i></a></li> <?php for($i = 1; $i <= $tp; $i++):?>
          <li class="<?=$i == $page ? 'active blue' : 'waves-effect'?>"><a href="physiotherapy.php?action=show&page=<?=$i?>"><?=$i?></a></li> <?php endfor;?>
        <li class="<?=$page >= $tp ? 'disabled' : 'waves-effect'?>"><a href="physiotherapy.php?action=show&page=min($tp, $page+1)?>"><i class="material-icons">chevron_right</i></a></li> </ul>
  <?php endif;?>
  <?php endif;?><br><a href="physiotherapy.php" class="btn grey waves-effect waves-light">Back to Menu</a> <?php endif;?>

<?php if(isset($view) && $view !== null && $action==='view'): ?>
  <div class="card-panel grey darken-3 white-text">
    <h5>Patient: <?=htmlspecialchars($view['full_name'] ?? 'N/A')?> (MRN: <?=htmlspecialchars($view['mrn'] ?? 'N/A')?>)</h5>
    <p><strong>Session #:</strong> <?=htmlspecialchars($view['session_number'] ?? '-')?>&nbsp;&nbsp;&nbsp;<strong>Visit #:</strong> <?=htmlspecialchars($view['visit_number'] ?? '-')?></p>
    <p><strong>Session Name:</strong> <?=htmlspecialchars($view['session_name'] ?: '-')?></p>
    <p><strong>Created:</strong> <?=isset($view['created_at']) ? date('d-M-Y H:i',strtotime($view['created_at'])) : '-'?> by User ID: <?=htmlspecialchars($view['created_by'] ?? '-')?></p>
    <p><strong>Status:</strong> <?=(isset($view['is_locked']) && $view['is_locked']) ?'<span style="color:lightgreen; font-weight:bold;">Locked</span> (Signed by User ID: '.htmlspecialchars($view['locked_by'] ?? '-').' on '.(isset($view['locked_at']) ? date('d-M-Y H:i',strtotime($view['locked_at'])) : '-').')':'<span style="color:yellow; font-weight:bold;">Open / Editable</span>'?></p>

    <hr style="border-color:rgba(255,255,255,.2)">
    <h6 class="white-text" style="font-size:1.2em; margin-bottom:10px;">Subjective Assessment</h6>
    <p><strong>Presenting Complaint:</strong><br><?=nl2br(htmlspecialchars($view['presenting_complaint']?:'-'))?></p>
    <p><strong>History of Presenting Complaint:</strong><br><?=nl2br(htmlspecialchars($view['history_of_presenting_complaint']?:'-'))?></p>
    <p><strong>Relevant Medical History:</strong><br><?=nl2br(htmlspecialchars($view['relevant_medical_history']?:'-'))?></p>
    <p><strong>Functional Limitations:</strong><br><?=nl2br(htmlspecialchars($view['functional_limitations']?:'-'))?></p>
    <p><strong>Patient Goals:</strong><br><?=nl2br(htmlspecialchars($view['patient_goals']?:'-'))?></p>
    <?php if(!empty($view['social_history'])):?><p><strong>Social History:</strong><br><?=nl2br(htmlspecialchars($view['social_history']))?></p><?php endif; ?>


    <hr style="border-color:rgba(255,255,255,.2)">
    <h6 class="white-text" style="font-size:1.2em; margin-bottom:10px;">Objective Assessment</h6>
    <p><strong>Observation:</strong><br><?=nl2br(htmlspecialchars($view['observation']?:'-'))?></p>
    <p><strong>Palpation:</strong><br><?=nl2br(htmlspecialchars($view['palpation']?:'-'))?></p>
    <p><strong>Range of Motion:</strong><br><?=nl2br(htmlspecialchars($view['range_of_motion']?:'-'))?></p>
    <p><strong>Muscle Strength:</strong><br><?=nl2br(htmlspecialchars($view['muscle_strength']?:'-'))?></p>
    <p><strong>Special Tests:</strong><br><?=nl2br(htmlspecialchars($view['special_tests']?:'-'))?></p>
    <?php if(!empty($view['neurological_assessment'])):?><p><strong>Neurological Assessment:</strong><br><?=nl2br(htmlspecialchars($view['neurological_assessment']))?></p><?php endif; ?>
    <?php if(!empty($view['functional_tests'])):?><p><strong>Functional Tests:</strong><br><?=nl2br(htmlspecialchars($view['functional_tests']))?></p><?php endif; ?>
    <?php if(!empty($view['respiratory_assessment'])):?><p><strong>Respiratory Assessment:</strong><br><?=nl2br(htmlspecialchars($view['respiratory_assessment']))?></p><?php endif; ?>

    <hr style="border-color:rgba(255,255,255,.2)">
    <h6 class="white-text" style="font-size:1.2em; margin-bottom:10px;">Diagnosis & Plan</h6>
    <p><strong>Clinical Impression:</strong><br><?=nl2br(htmlspecialchars($view['clinical_impression']?:'-'))?></p>
    <p><strong>Treatment Plan:</strong><br><?=nl2br(htmlspecialchars($view['treatment_plan']?:'-'))?></p>

    <hr style="border-color:rgba(255,255,255,.2)">
    <h6 class="white-text" style="font-size:1.2em; margin-bottom:10px;">Treatment & Follow-up</h6>
    <p><strong>Treatment Administered Today:</strong><br><?=nl2br(htmlspecialchars($view['treatment_administered_today']?:'-'))?></p>
    <p><strong>Home Exercise Program:</strong><br><?=nl2br(htmlspecialchars($view['home_exercise_program']?:'-'))?></p>
    <p><strong>Precautions/Contraindications:</strong><br><?=nl2br(htmlspecialchars($view['precautions_contraindications']?:'-'))?></p>
    <p><strong>Follow-up Recommended:</strong><br><?=nl2br(htmlspecialchars($view['follow_up_recommended']?:'-'))?></p>
    <?php if(!empty($view['notes'])):?><p><strong>Notes:</strong><br><?=nl2br(htmlspecialchars($view['notes']))?></p><?php endif; ?>


    <?php if(!empty($view['consent_text'])):?>
    <hr style="border-color:rgba(255,255,255,.2)">
    <p><strong>Consent Text:</strong><br><?=nl2br(htmlspecialchars($view['consent_text']))?></p>
        <?php if(!empty($view['consent_signature_path'])):?>
          <p><strong>Signature:</strong> <img src="/<?=htmlspecialchars($view['consent_signature_path'])?>" alt="Signature" style="max-width:200px; height:auto; border:1px solid #555; background:white;"></p>
          <p>(Signed by User ID: <?=htmlspecialchars($view['consent_signed_by'] ?? '-')?> on <?=isset($view['consent_signed_at']) ? date('d-M-Y H:i',strtotime($view['consent_signed_at'])) : '-'?>)</p>
        <?php else: ?>
          <p><strong>Signature:</strong> Not provided.</p>
        <?php endif; ?>
    <?php endif;?>

    <?php if(isset($view['is_locked']) && $view['is_locked'] && !empty($view['treatment_summary'])):?>
    <hr style="border-color:rgba(255,255,255,.2); margin-top:20px;">
    <p style="font-size:1.2em; font-weight:bold;">Overall Treatment Summary:</p> <p><?=nl2br(htmlspecialchars($view['treatment_summary']))?></p>
    <?php endif;?>
  </div>
  <div class="row center" style="margin-top:20px;">
    <?php if(!(isset($view['is_locked']) && $view['is_locked'])):?>
      <a class="btn orange waves-effect waves-light" href="physiotherapy.php?action=edit&mrn=<?=urlencode($view['mrn'])?>&session=<?=urlencode($view['session_number'])?>" style="margin-right: 5px;">Edit Session</a> <a class="btn green waves-effect waves-light modal-trigger" href="#signModalPhysio" style="margin-right: 5px;">Sign & Lock Session</a> <?php endif;?>
    <?php if(!empty($view['pdf_path'])):?><a class="btn purple waves-effect waves-light" href="/<?=htmlspecialchars($view['pdf_path'])?>" target="_blank" download style="margin-right: 5px;">Download PDF</a><?php endif;?>
    <a href="physiotherapy.php" class="btn grey waves-effect waves-light">Back to Menu</a> </div>

  <?php if(!(isset($view['is_locked']) && $view['is_locked'])):?>
  <div id="signModalPhysio" class="modal grey darken-3 white-text"> <div class="modal-content"><h5>Enter Overall Treatment Summary to Sign & Lock</h5> <form id="signFormPhysio" method="POST" action="physiotherapy.php?action=sign&mrn=<?=urlencode($view['mrn'])?>&session=<?=urlencode($view['session_number'])?>"> <div class="input-field"><textarea name="treatment_summary" id="treatment_summary_text_physio" class="materialize-textarea white-text" required data-length="65000" autofocus><?=htmlspecialchars($view['treatment_summary'] ?? '')?></textarea><label for="treatment_summary_text_physio" class="<?=!empty($view['treatment_summary']) ? 'active' : ''?>">Overall Treatment Summary (Required)</label></div>
        <p class="yellow-text text-lighten-2">Warning: Once saved, this session will be locked and the overall treatment summary cannot be further edited. Key findings and treatments can still be edited prior to locking.</p>
        <button type="submit" class="btn green waves-effect waves-light">Save Summary & Lock Session</button>
        <a href="#!" class="modal-close btn red waves-effect waves-light">Cancel</a>
      </form></div>
  </div>
  <?php endif;?>
<?php endif;?>


<?php if($showSessionForm): // Covers create (GET) and edit (GET) ?>
  <h5 class="white-text"><?= htmlspecialchars($pageTitle) ?></h5>
  <form method="POST" action="<?= htmlspecialchars($formActionUrl) ?>" id="physiotherapySessionForm"> <div class="row">
        <div class="input-field col s12 m4">
            <input id="mrn_form_physio" name="mrn" type="text" onblur="lookupMRNPhysio()" class="validate white-text" required value="<?= htmlspecialchars($formData['mrn'] ?? '') ?>" <?= ($formMode === 'edit' ? 'readonly' : '') ?>>
            <label for="mrn_form_physio">Patient MRN *</label>
        </div>
        <div class="input-field col s12 m5">
            <input id="full_name_display_physio" name="full_name_display" type="text" readonly value="<?= htmlspecialchars($formData['full_name'] ?? '') ?>">
            <label class="active">Full Name</label>
        </div>
        <div class="input-field col s6 m1">
             <input id="age_display_physio" name="age_display" type="text" readonly value="<?= htmlspecialchars(($formData['age'] ?? '') . (!empty($formData['age']) && !empty($formData['age_unit']) ? ' ' . $formData['age_unit'] : '')) ?>">
            <label class="active">Age</label></div>
        <div class="input-field col s6 m2">
            <input id="gender_display_physio" name="gender_display" type="text" readonly value="<?= htmlspecialchars($formData['gender'] ?? '') ?>">
            <label class="active">Gender</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12 m4">
            <input id="visit_number_display_physio" name="visit_number_display" type="text" readonly value="<?= htmlspecialchars($formData['visit_number_display'] ?? ($formMode === 'create' ? '' : ($formData['visit_number'] ?? ''))) ?>">
            <label class="active">Patient Visit # <?= ($formMode === 'edit' ? '(at time of session)' : '(auto-filled)') ?></label>
        </div>
        <div class="input-field col s12 m8">
            <input name="session_name" type="text" class="white-text" data-length="128" value="<?= htmlspecialchars($formData['session_name'] ?? '') ?>">
            <label>Session Name / Title (e.g., Initial Assessment, Knee Rehab S3)</label>
        </div>
    </div>

    <h6 class="white-text" style="font-size:1.3em; margin-top:30px;">Subjective Assessment</h6>
    <div class="input-field"><textarea name="presenting_complaint" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['presenting_complaint'] ?? '') ?></textarea><label>Presenting Complaint (Patient's words, main problem)</label></div>
    <div class="input-field"><textarea name="history_of_presenting_complaint" class="materialize-textarea white-text" data-length="4096"><?= htmlspecialchars($formData['history_of_presenting_complaint'] ?? '') ?></textarea><label>History of Presenting Complaint (Onset, mechanism, duration, pain scale, aggs/eases)</label></div>
    <div class="input-field"><textarea name="relevant_medical_history" class="materialize-textarea white-text" data-length="4096"><?= htmlspecialchars($formData['relevant_medical_history'] ?? '') ?></textarea><label>Relevant Medical & Surgical History (Past injuries, conditions, medications, allergies, red flags)</label></div>
    <div class="input-field"><textarea name="functional_limitations" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['functional_limitations'] ?? '') ?></textarea><label>Functional Limitations (Impact on ADLs, work, hobbies, sports)</label></div>
    <div class="input-field"><textarea name="patient_goals" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['patient_goals'] ?? '') ?></textarea><label>Patient Goals & Expectations (Short-term and Long-term)</label></div>
    <div class="input-field"><textarea name="social_history" class="materialize-textarea white-text" data-length="1024"><?= htmlspecialchars($formData['social_history'] ?? '') ?></textarea><label>Social History (Occupation, lifestyle, living situation, support system - if relevant)</label></div>

    <hr class="divider">
    <h6 class="white-text" style="font-size:1.3em;">Objective Assessment</h6>
    <div class="input-field"><textarea name="observation" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['observation'] ?? '') ?></textarea><label>Observation (Posture, gait, swelling, scars, alignment, assistive devices)</label></div>
    <div class="input-field"><textarea name="palpation" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['palpation'] ?? '') ?></textarea><label>Palpation (Tenderness, temperature, swelling, muscle tone, trigger points)</label></div>
    <div class="input-field"><textarea name="range_of_motion" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['range_of_motion'] ?? '') ?></textarea><label>Range of Motion (Active, Passive, goniometry if applicable, quality of movement)</label></div>
    <div class="input-field"><textarea name="muscle_strength" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['muscle_strength'] ?? '') ?></textarea><label>Muscle Strength (MMT grades, specific muscles/groups, dynamometry)</label></div>
    <div class="input-field"><textarea name="special_tests" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['special_tests'] ?? '') ?></textarea><label>Special Tests (Orthopedic/Neurological tests performed and results)</label></div>
    <div class="input-field"><textarea name="neurological_assessment" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['neurological_assessment'] ?? '') ?></textarea><label>Neurological Assessment (Reflexes, sensation/dermatomes, myotomes, coordination, balance - if applicable)</label></div>
    <div class="input-field"><textarea name="functional_tests" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['functional_tests'] ?? '') ?></textarea><label>Functional Tests (Gait analysis, balance tests, specific functional movements like sit-to-stand, squat)</label></div>
    <div class="input-field"><textarea name="respiratory_assessment" class="materialize-textarea white-text" data-length="1024"><?= htmlspecialchars($formData['respiratory_assessment'] ?? '') ?></textarea><label>Respiratory Assessment (If applicable: e.g. breathing pattern, auscultation, cough)</label></div>

    <hr class="divider">
    <h6 class="white-text" style="font-size:1.3em;">Clinical Impression & Plan</h6>
    <div class="input-field"><textarea name="clinical_impression" class="materialize-textarea white-text" data-length="4096"><?= htmlspecialchars($formData['clinical_impression'] ?? '') ?></textarea><label>Clinical Impression / Physiotherapy Diagnosis (Problem list, contributing factors)</label></div>
    <div class="input-field"><textarea name="treatment_plan" class="materialize-textarea white-text" data-length="6000"><?= htmlspecialchars($formData['treatment_plan'] ?? '') ?></textarea><label>Proposed Treatment Plan (Interventions, modalities, manual therapy, exercises, education, frequency, duration, prognosis)</label></div>

    <hr class="divider">
    <h6 class="white-text" style="font-size:1.3em;">Consent & Signature</h6>
    <?php
        $defaultPhysioConsentText = 'I, the undersigned, consent to undergo physiotherapy assessment and treatment for [Specify Condition/Area Here, e.g., Lower Back Pain, Post-operative Knee Rehabilitation] as discussed with my physiotherapist. I confirm that the nature of the proposed treatment plan, expected benefits, potential risks (including but not limited to temporary soreness, discomfort, muscle fatigue, and specific risks related to certain modalities or exercises), and alternative management options have been explained to me. I have had the opportunity to ask questions and all my questions have been answered to my satisfaction. I understand that results and recovery times are not guaranteed and may vary. I consent to relevant physical examination and the application of agreed-upon therapeutic interventions. I consent to photography/videography for medical records if deemed necessary for assessment or treatment planning, and if separately agreed, for educational or promotional purposes with anonymity. This consent is given voluntarily.';
        $consentTextValue = $formData['consent_text'] ?? ($formMode === 'create' && empty($formData['consent_text']) ? $defaultPhysioConsentText : ($formData['consent_text'] ?? ''));
    ?>
    <div class="input-field">
        <textarea name="consent_text" class="materialize-textarea white-text" style="min-height:120px"><?= htmlspecialchars($consentTextValue) ?></textarea>
        <label class="active">Consent Text (Edit as needed, especially [Specify Condition/Area Here])</label>
    </div>
    <input type="hidden" name="sig_path_physio" id="sig_path_physio" value="<?= htmlspecialchars($formData['consent_signature_path'] ?? '') ?>">
    <div class="card-panel grey darken-3" style="padding: 15px;">
        <button type="button" class="btn waves-effect waves-light" onclick="openSignaturePadPhysio()">
            <?= !empty($formData['consent_signature_path']) ? 'Replace Signature' : 'Add Patient/Guardian Signature' ?>
        </button>
        <?php if ($formMode === 'edit' && !empty($formData['consent_signature_path'])): ?>
            <button type="button" class="btn orange waves-effect waves-light" onclick="clearSignaturePhysio()" style="margin-left:10px;">Clear Signature</button>
        <?php endif; ?>
        <span id="sig-status-physio" class="white-text" style="margin-left:15px;"><?= !empty($formData['consent_signature_path']) ? 'Existing signature loaded.' : '' ?></span>
        <img id="sigPreviewPhysio" src="<?= !empty($formData['consent_signature_path']) ? ('/' . htmlspecialchars($formData['consent_signature_path'])) : '#' ?>" alt="Signature Preview" style="display:<?= !empty($formData['consent_signature_path']) ? 'block' : 'none' ?>; max-width:150px; height:auto; background:white; border:1px solid #ccc; margin-top:10px;">
    </div>

    <hr class="divider">
    <h6 class="white-text" style="font-size:1.3em;">Treatment Details (For This Session) & Future Plan</h6>
    <div class="input-field"><textarea name="treatment_administered_today" class="materialize-textarea white-text" data-length="6000"><?= htmlspecialchars($formData['treatment_administered_today'] ?? '') ?></textarea><label>Treatment Administered Today (Detailed description of procedures, exercises, modalities, patient response)</label></div>
    <div class="input-field"><textarea name="home_exercise_program" class="materialize-textarea white-text" data-length="4096"><?= htmlspecialchars($formData['home_exercise_program'] ?? '') ?></textarea><label>Home Exercise Program (HEP) (Specific exercises, sets, reps, frequency, instructions provided)</label></div>
    <div class="input-field"><textarea name="precautions_contraindications" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['precautions_contraindications'] ?? '') ?></textarea><label>Precautions / Contraindications / Advice Given</label></div>
    <div class="input-field"><input name="follow_up_recommended" type="text" class="white-text" data-length="100" value="<?= htmlspecialchars($formData['follow_up_recommended'] ?? '') ?>"><label>Follow-up Recommended (e.g., 2x/week, next appointment date)</label></div>
    <div class="input-field"><textarea name="notes" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['notes'] ?? '') ?></textarea><label>Other Notes (e.g., Patient feedback, deviations from plan, communications)</label></div>

    <div class="row center" style="margin-top:30px;">
        <button type="submit" class="btn green waves-effect waves-light"><?= htmlspecialchars($submitButtonText) ?></button>
        <a href="physiotherapy.php" class="btn grey waves-effect waves-light">Cancel & Back to Menu</a> </div>
  </form>
<?php endif;?>

</div><?php include_once __DIR__.'/includes/footer.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    M.Sidenav.init(document.querySelectorAll('.sidenav'),{edge:'right'});
    M.CharacterCounter.init(document.querySelectorAll('textarea.materialize-textarea, input.white-text[data-length]'));
    M.Modal.init(document.querySelectorAll('.modal'));
    M.FormSelect.init(document.querySelectorAll('select')); // If you add any selects

    <?php if ($showSignForm && isset($view) && is_array($view) && !($view['is_locked'] ?? true)): ?>
    const signModalElemPhysio = document.getElementById('signModalPhysio'); // Changed ID
    if (signModalElemPhysio) {
        const instance = M.Modal.getInstance(signModalElemPhysio);
        if (instance) {
            instance.open();
            const summaryTextareaPhysio = document.getElementById('treatment_summary_text_physio'); // Changed ID
            if (summaryTextareaPhysio) {
                summaryTextareaPhysio.focus();
                M.textareaAutoResize(summaryTextareaPhysio);
            }
        }
    }
    <?php endif; ?>
    document.querySelectorAll('textarea.materialize-textarea').forEach(ta => M.textareaAutoResize(ta));
    M.updateTextFields(); 
});

// MRN Lookup specifically for Physiotherapy form
function lookupMRNPhysio(){
  const mrnField = document.getElementById('mrn_form_physio'); 
  if (mrnField && mrnField.hasAttribute('readonly')) {
      return;
  }

  const nameField = document.getElementById('full_name_display_physio');
  const ageField = document.getElementById('age_display_physio');
  const genderField = document.getElementById('gender_display_physio');
  const visitField = document.getElementById('visit_number_display_physio');

  if(!mrnField || !mrnField.value.trim()){
      [nameField, ageField, genderField, visitField].forEach(el => { if(el) el.value=''; });
      M.updateTextFields();
      return;
  }
  fetch('physiotherapy.php?action=lookup_mrn&mrn='+encodeURIComponent(mrnField.value.trim())) // Changed URL
    .then(response => {
        if (!response.ok) { throw new Error('Network response was not ok: ' + response.statusText); }
        return response.json();
    })
    .then(data => {
      if(!data.success){
        M.toast({html: data.message || 'Patient not found', classes:'red darken-2'});
        [nameField, ageField, genderField, visitField].forEach(el => { if(el) el.value=''; });
      } else {
        if(nameField) nameField.value = data.full_name || '';
        if(ageField) ageField.value = (data.age !== null && data.age !== undefined ? String(data.age) + (data.age_unit ? ' ' + data.age_unit : '') : '');
        if(genderField) genderField.value = data.gender || '';
        if(visitField) visitField.value = data.latest_visit || ''; 
        M.toast({html: 'Patient details loaded.', classes: 'green darken-1'});
      }
      M.updateTextFields();
    }).catch(error => {
      M.toast({html:'MRN Lookup Error: ' + error.message, classes:'red darken-2'});
      [nameField, ageField, genderField, visitField].forEach(el => { if(el) el.value=''; });
      M.updateTextFields();
    });
}

// Signature Pad specifically for Physiotherapy form
let sigPadPhysio, sigModalInstancePhysio;
const sigCanvasPhysio = document.createElement('canvas');
sigCanvasPhysio.id = 'sigCanvasPhysio'; // Changed ID
sigCanvasPhysio.width = 500;
sigCanvasPhysio.height = 200;
sigCanvasPhysio.style.border = '1px solid #aaa';
sigCanvasPhysio.style.backgroundColor = 'white';

function openSignaturePadPhysio(){ // Changed function name
  if(!sigModalInstancePhysio){ // Changed variable name
    const modalHTML = `
      <div class="modal-content">
        <h5>Provide Signature</h5>
        <div id="sigCanvasContainerPhysio"></div> </div>
      <div class="modal-footer">
        <a href="#!" class="modal-close waves-effect waves-grey btn-flat">Cancel</a>
        <a href="#!" id="sigClearButtonPhysio" class="waves-effect waves-red btn-flat">Clear</a> <a href="#!" id="sigSaveButtonPhysio" class="waves-effect waves-green btn blue white-text">Save Signature</a> </div>`;
    const modalEl = document.createElement('div');
    modalEl.id = 'signatureModalPhysio'; // Changed ID
    modalEl.className = 'modal grey darken-3 white-text';
    modalEl.innerHTML = modalHTML;
    document.body.appendChild(modalEl);

    modalEl.querySelector('#sigCanvasContainerPhysio').appendChild(sigCanvasPhysio); // Changed IDs

    sigModalInstancePhysio = M.Modal.init(modalEl, { // Changed variable name
        dismissible: false,
        onOpenEnd: function() {
            if (!sigPadPhysio) { // Changed variable name
                sigPadPhysio = new SignaturePad(sigCanvasPhysio, { // Changed variable name
                    backgroundColor: 'rgb(255, 255, 255)'
                });
            }
            sigPadPhysio.clear(); // Changed variable name
        }
    });

    modalEl.querySelector('#sigClearButtonPhysio').onclick = () => sigPadPhysio.clear(); // Changed IDs & var name
    modalEl.querySelector('#sigSaveButtonPhysio').onclick = () => { // Changed ID
      if(sigPadPhysio.isEmpty()){ // Changed var name
        M.toast({html:'Signature is empty. Please sign.', classes:'yellow darken-3 black-text'});
        return;
      }
      const dataURL = sigPadPhysio.toDataURL('image/png'); // Changed var name
      fetch('physiotherapy.php?action=save_signature',{method:'POST',body:new URLSearchParams({img: dataURL})}) // Changed URL
        .then(response => response.json())
        .then(data => {
          if(data.ok){
            document.getElementById('sig_path_physio').value = data.path; // Changed ID
            const sigStatus = document.getElementById('sig-status-physio'); // Changed ID
            if(sigStatus) sigStatus.textContent = 'New signature captured. Save form to apply.';
            const sigPreview = document.getElementById('sigPreviewPhysio'); // Changed ID
            if(sigPreview) {
                sigPreview.src = dataURL;
                sigPreview.style.display = 'block';
            }
            const sigButton = document.querySelector('#physiotherapySessionForm button[onclick="openSignaturePadPhysio()"]'); // Changed form ID & function name
            if(sigButton) sigButton.textContent = 'Replace Signature';

            sigModalInstancePhysio.close(); // Changed var name
            M.toast({html:'Signature captured! Remember to save the session.', classes:'green darken-1'});
          } else {
            M.toast({html: data.message || 'Failed to save signature.', classes:'red darken-2'});
          }
        }).catch(error => M.toast({html:'Signature save error: ' + error.message, classes:'red darken-2'}));
    };
  }
  if (sigPadPhysio) sigPadPhysio.clear(); // Changed var name
  sigModalInstancePhysio.open(); // Changed var name
}

function clearSignaturePhysio() { // Changed function name
    document.getElementById('sig_path_physio').value = ''; // Changed ID
    const sigPreview = document.getElementById('sigPreviewPhysio'); // Changed ID
    sigPreview.src = '#';
    sigPreview.style.display = 'none';
    document.getElementById('sig-status-physio').textContent = 'Signature cleared. Save form to apply changes.'; // Changed ID
   
    const sigButton = document.querySelector('#physiotherapySessionForm button[onclick="openSignaturePadPhysio()"]'); // Changed form ID & function name
    if(sigButton) sigButton.textContent = 'Add Patient/Guardian Signature';

    M.toast({html:'Signature cleared. Remember to save the session to apply this change.', classes:'orange darken-2'});
}
</script>
</body></html>