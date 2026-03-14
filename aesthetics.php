<?php
/* /public/aesthetics.php  — 25 May 2025 (Adapted from Dental)
 * Senior Aesthetician Portal – groups 1 (CMO) & 2 (Aestheticians) - Adjust groups if needed
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
if (!in_array($gid, [1, 2], true)) { // Adjust group IDs if needed
    include __DIR__.'/includes/header.php';
    echo '<div class="container center-align" style="padding-top:60px"><h4 class="red-text">Access denied</h4></div>';
    include __DIR__.'/includes/footer.php';
    exit;
}

/* ───────────────────── 1  HELPERS ───────────────────── */
function nextSessionNumber(PDO $pdo, string $mrn): int {
    $st = $pdo->prepare('SELECT COALESCE(MAX(session_number),0)+1 FROM aesthetics WHERE mrn=?');
    $st->execute([$mrn]); return (int)$st->fetchColumn();
}
function latestVisitNumber(PDO $pdo, string $mrn): int {
    // Assumes 'patientregister' table has 'mrn' (lowercase) column
    $st = $pdo->prepare('SELECT COALESCE(MAX(visit_number),1) FROM patientregister WHERE mrn=?');
    $st->execute([$mrn]); return (int)$st->fetchColumn();
}

/* ───────────────────── 2  PDF  (FIXED) ───────────────────── */
class AestheticsPDF extends PDF_Code128 {
    function __construct() {
        parent::__construct('P','mm','A4');
        $this->SetMargins(15,22,15);
        $this->SetAutoPageBreak(true,25);
    }
    function Header(){}
    function Footer() {
        $this->SetY(-20);
        $this->SetFont('Helvetica','',10);
        $this->SetTextColor(120,120,120);
        $this->Cell(0,6,'hospital0',0,0,'C'); // Or your clinic's name
    }
}

function generateAestheticsPDF(PDO $pdo, string $mrn, int $sess): ?string {
    $q = $pdo->prepare(
        'SELECT a.*, p.full_name
            FROM aesthetics a JOIN patients p ON p.mrn = a.mrn
            WHERE a.mrn = ? AND a.session_number = ? LIMIT 1'
    );
    $q->execute([$mrn, $sess]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) { error_log("Aesthetics PDF-gen: session not found for $mrn/$sess"); return null; }

    $outDir = __DIR__.'/aesthetics/';
    if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
        error_log("Aesthetics PDF-gen: cannot create $outDir"); return null;
    }
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $row['full_name']);
    $safeMrn  = preg_replace('/\D/', '', $mrn);
    $file     = "Aesthetics_{$safeName}_{$safeMrn}_S{$sess}.pdf";
    $absPath  = $outDir . $file;
    $relPath  = 'aesthetics/' . $file;

    $pdf = new AestheticsPDF();
    $pdf->SetTitle('Aesthetics Treatment Summary', true);
    $pdf->AddPage();

    $pdf->SetFont('Helvetica','',20);
    $pdf->Cell(0,10,'hospital0',0,1,'C');
    $pdf->SetFont('Helvetica','',14);
    $pdf->Cell(0,8,'Aesthetics Treatment Summary',0,1,'C');
    $pdf->Ln(4);

    $barcode = "AESTHETICS-{$safeMrn}-S{$sess}";
    $pdf->Code128(($pdf->GetPageWidth()-70)/2, $pdf->GetY(), $barcode, 70, 10);
    $pdf->Ln(16);

    $pdf->SetFont('Helvetica','',11);
    $labels = [
        'Patient'        => $row['full_name'] ?? '-',
        'MRN'            => $mrn,
        'Session #'      => $sess,
        'Visit #'        => $row['visit_number'] ?? '-',
        'Created'        => isset($row['created_at']) ? date('d-M-Y H:i', strtotime($row['created_at'])) : '-',
        'Assessment'     => $row['provisional_assessment'] ?? '-',
        'Treatment Plan' => $row['treatment_plan'] ?? '-',
        'Treatment Done' => $row['treatment_done_today'] ?? '-'
    ];
    foreach ($labels as $lbl => $val) {
        $pdf->SetFont('Helvetica','B',11);
        $pdf->Cell(40,6,"$lbl:",0,0);
        $pdf->SetFont('Helvetica','',11);
        $pdf->MultiCell(0,6, ($val === null || $val === '') ? '-' : $val,0,'L');
    }

    $pdf->Ln(2);
    $pdf->SetFont('Helvetica','B',11);
    $pdf->MultiCell(0,6,'Treatment Summary:',0,1);
    $pdf->SetFont('Helvetica','',11);
    $pdf->MultiCell(0,6, ($row['treatment_summary'] ?? '-') ?: '-', 0, 'L');

    try {
        $pdf->Output('F', $absPath, true);
    } catch (Throwable $e) {
        error_log('Aesthetics PDF-gen Output error: ' . $e->getMessage());
        return null;
    }

    $u = $pdo->prepare('UPDATE aesthetics SET pdf_path=? WHERE mrn=? AND session_number=?');
    $u->execute([$relPath, $mrn, $sess]);

    return $absPath;
}
// ───────────────────────────── 3 ROUTER / LOGIC ─────────────────────────────

$action = $_GET['action'] ?? '';
$msg    = '';
$view = null; // Initialize $view
$rows = []; // Initialize $rows
$showSignForm = false; // Initialize
$showRetrieveForm = false; // Initialize
$showAll = false; // Initialize

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

    $dir = __DIR__.'/aesthetics/signatures/';
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

    echo json_encode(['ok'=>true,'path'=>'aesthetics/signatures/'.$name]);
    exit;
}

// 3.c  CREATE Session
if ($action==='create' && $_SERVER['REQUEST_METHOD']==='POST'){
    $mrn_form = trim($_POST['mrn'] ?? '');
    if($mrn_form===''){ $msg='Error – MRN required.'; goto render_aesthetics; }

    $stmt_patient_check = $pdo->prepare('SELECT 1 FROM patients WHERE mrn=? LIMIT 1');
    $stmt_patient_check->execute([$mrn_form]);
    if(!$stmt_patient_check->fetchColumn()){
        $msg='Error – Patient not found with this MRN.'; goto render_aesthetics;
    }

    $sess_create  = nextSessionNumber($pdo, $mrn_form);
    $visit_create = latestVisitNumber($pdo, $mrn_form);

    $columns=[
        'mrn','session_number','visit_number','session_name',
        'presenting_complaint', 'history_presenting_complaint', 'relevant_medical_history',
        'previous_aesthetic_treatments', 'skin_type_assessment', 'patient_goals', 'treatment_area',
        'provisional_assessment', 'treatment_plan', 'treatment_done_today', 'products_used',
        'post_treatment_instructions', 'follow_up_recommended', 'notes',
        'consent_text','consent_signature_path','consent_signed_by','consent_signed_at',
        'is_locked','locked_by','locked_at','pdf_path','created_by'
    ];
    $ph = rtrim(str_repeat('?,',count($columns)),',');
    $g  = fn($k)=>($_POST[$k] ?? '') !== '' ? trim($_POST[$k]) : null;
    $sigPath_create = $g('sig_path');

    $values=[
        $mrn_form, $sess_create, $visit_create, $g('session_name'),
        $g('presenting_complaint'), $g('history_presenting_complaint'), $g('relevant_medical_history'),
        $g('previous_aesthetic_treatments'), $g('skin_type_assessment'), $g('patient_goals'), $g('treatment_area'),
        $g('provisional_assessment'), $g('treatment_plan'), $g('treatment_done_today'), $g('products_used'),
        $g('post_treatment_instructions'), $g('follow_up_recommended'), $g('notes'),
        $g('consent_text'), $sigPath_create,
        $sigPath_create ? $uid : null,
        $sigPath_create ? date('Y-m-d H:i:s') : null,
        0, null, null, null, $uid
    ];

    try{
        $insertStmt = $pdo->prepare('INSERT INTO aesthetics ('.implode(',',$columns).") VALUES ($ph)");
        $insertStmt->execute($values);
        header("Location: aesthetics.php?action=view&mrn=$mrn_form&session=$sess_create&status=created"); exit;
    }catch(Exception $e){ $msg='Database error: '.$e->getMessage(); error_log("Aesthetics Create Error: ".$e->getMessage());}
} else if ($action==='create' && $_SERVER['REQUEST_METHOD']!=='POST') { // Prepare for create form display
    $formMode = 'create';
    $pageTitle = 'Add New Aesthetic Session';
    $formData = $_POST; // Could hold data if a previous attempt failed and re-rendered (not current flow)
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $formData = []; // Ensure fresh form for GET

    $formActionUrl = '?action=create';
    $submitButtonText = 'Save Session';
    $showSessionForm = true;
    goto render_aesthetics;
}


// 3.d  SIGN & LOCK
if ($action==='sign'){
    $mrn_sign = $_GET['mrn'] ?? ''; $sess_sign = (int)($_GET['session'] ?? 0);
    if(!$mrn_sign || !$sess_sign){ $msg='Invalid parameters for signing.'; goto render_aesthetics; }

    if($_SERVER['REQUEST_METHOD']==='POST'){
        $summary_sign = trim($_POST['treatment_summary'] ?? '');
        if (mb_strlen($summary_sign) < 10) {
             $msg = 'Error - Treatment summary is too short.'; $showSignForm = true;
             $_GET['mrn'] = $mrn_sign; $_GET['session'] = $sess_sign; 
             goto render_aesthetics_sign_form_error;
        }
        if (mb_strlen($summary_sign) > 65000) {
            $msg = 'Error - Treatment summary is too long.'; $showSignForm = true;
            $_GET['mrn'] = $mrn_sign; $_GET['session'] = $sess_sign; 
            goto render_aesthetics_sign_form_error;
        }

        $updateStmt = $pdo->prepare('UPDATE aesthetics SET treatment_summary=?, is_locked=1, locked_by=?, locked_at=NOW() WHERE mrn=? AND session_number=? AND is_locked=0');
        $updateStmt->execute([$summary_sign, $uid, $mrn_sign, $sess_sign]);

        if ($updateStmt->rowCount() > 0) {
            $pdfPathGenerated = generateAestheticsPDF($pdo, $mrn_sign, $sess_sign);
            if ($pdfPathGenerated === null) {
                 error_log("PDF generation failed for $mrn_sign/$sess_sign after locking. Check permissions and FPDF setup.");
            }
            header("Location: aesthetics.php?action=view&mrn=$mrn_sign&session=$sess_sign&status=signed");
            exit;
        } else {
            $checkLockStmt = $pdo->prepare("SELECT is_locked FROM aesthetics WHERE mrn=? AND session_number=?");
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
            goto render_aesthetics_sign_form_error;
        }
    }

    render_aesthetics_sign_form_error:
    $mrn_for_view = $_GET['mrn'] ?? ''; 
    $sess_for_view = (int)($_GET['session'] ?? 0);

    if (!$mrn_for_view || !$sess_for_view) { 
        $msg = 'Error: Missing MRN or Session for displaying sign form.'; $action = ''; goto render_aesthetics;
    }

    $stmt_view_for_sign = $pdo->prepare('SELECT a.*,p.full_name FROM aesthetics a JOIN patients p ON p.mrn=a.mrn WHERE a.mrn=? AND a.session_number=?');
    $stmt_view_for_sign->execute([$mrn_for_view, $sess_for_view]);
    $view = $stmt_view_for_sign->fetch(PDO::FETCH_ASSOC); 
    if(!$view){$msg='Session not found for signing.'; $action=''; goto render_aesthetics;}
    $showSignForm = true;

    goto render_aesthetics;
}

// 3.e  VIEW single session
if ($action==='view'){
    $mrn_view = $_GET['mrn'] ?? ''; $sess_view =(int)($_GET['session'] ?? 0);
    if(!$mrn_view || !$sess_view){ $msg='Missing parameters for view.'; $action = ''; goto render_aesthetics; }

    $stmt_view = $pdo->prepare('SELECT a.*,p.full_name FROM aesthetics a JOIN patients p ON p.mrn=a.mrn WHERE a.mrn=? AND a.session_number=?');
    $stmt_view->execute([$mrn_view, $sess_view]);
    $view = $stmt_view->fetch(PDO::FETCH_ASSOC); 

    if(!$view){ $msg='Aesthetic session not found.'; $action = ''; goto render_aesthetics; }
    goto render_aesthetics;
}

// 3.f  RETRIEVE list
if ($action==='retrieve'){
    if($_SERVER['REQUEST_METHOD']!=='POST'){ $showRetrieveForm=true; goto render_aesthetics; }

    $mrn_retrieve = trim($_POST['mrn'] ?? '');
    if(!$mrn_retrieve){ $msg='Enter MRN.'; $showRetrieveForm=true; goto render_aesthetics; }

    try {
        $stmt_retrieve = $pdo->prepare('SELECT a.session_number, a.session_name, a.created_at, p.full_name, a.mrn AS session_mrn FROM aesthetics a JOIN patients p ON p.mrn=a.mrn WHERE a.mrn=? ORDER BY session_number DESC');
        $stmt_retrieve->execute([$mrn_retrieve]);
        $rows = $stmt_retrieve->fetchAll(PDO::FETCH_ASSOC); 
    } catch (PDOException $e) {
        error_log("Aesthetics Retrieve List PDOException: " . $e->getMessage());
        $msg = "Database error while retrieving sessions. Please check server logs.";
        $rows = []; 
    }
    $retrievedMrn = $mrn_retrieve;
    goto render_aesthetics;
}

// 3.g  SHOW all sessions (paginated)
if ($action==='show'){
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 10;
    $total = 0;   // Initialize $total
    $tp = 1;      // Initialize $tp
    
    try {
        $total_count_stmt = $pdo->query('SELECT COUNT(*) FROM aesthetics');
        if ($total_count_stmt) {
            $total = (int)$total_count_stmt->fetchColumn();
        } else {
             error_log("Aesthetics Show All: Failed to execute count query (returned false).");
             $msg = "Error: Could not retrieve total session count.";
        }

        $tp = ($total > 0) ? max(1, ceil($total / $perPage)) : 1;
        if($page > $tp) $page = $tp; 
        $off = ($page - 1) * $perPage;

        $query_show_all = "SELECT a.mrn, a.session_number, a.session_name, a.created_at, p.full_name
                            FROM aesthetics a
                            JOIN patients p ON p.mrn = a.mrn
                            ORDER BY a.created_at DESC
                            LIMIT $perPage OFFSET $off";
        $stmt_show_all = $pdo->query($query_show_all);

        if ($stmt_show_all) {
            $rows = $stmt_show_all->fetchAll(PDO::FETCH_ASSOC); 
        } else {
            error_log("Aesthetics Show All: Failed to execute fetch rows query (returned false).");
            if (empty($msg)) $msg = "Error: Could not retrieve session list.";
        }

    } catch (PDOException $e) {
        error_log("Aesthetics Show All PDOException: " . $e->getMessage());
        $msg = "Database error while retrieving sessions. Please check server logs for details.";
    }
    $showAll = true;
    goto render_aesthetics;
}

// 3.h EDIT Session (Display form)
if ($action === 'edit') {
    $mrn_edit = $_GET['mrn'] ?? '';
    $sess_edit = (int)($_GET['session'] ?? 0);

    if (!$mrn_edit || !$sess_edit) {
        $msg = 'Error: Missing MRN or Session for editing.';
        $action = ''; 
        goto render_aesthetics;
    }

    $stmt_edit = $pdo->prepare('SELECT a.*, p.full_name, p.gender, p.age, p.age_unit FROM aesthetics a JOIN patients p ON p.mrn = a.mrn WHERE a.mrn = ? AND a.session_number = ? LIMIT 1');
    $stmt_edit->execute([$mrn_edit, $sess_edit]);
    $sessionData = $stmt_edit->fetch(PDO::FETCH_ASSOC);

    if (!$sessionData) {
        $msg = 'Error: Aesthetic session not found for editing.';
        $action = ''; 
        goto render_aesthetics;
    }

    if (!empty($sessionData['is_locked'])) {
        header("Location: aesthetics.php?action=view&mrn=" . urlencode($mrn_edit) . "&session=" . $sess_edit . "&status=locked_cant_edit");
        exit;
    }

    $formMode = 'edit';
    $formData = $sessionData;
    $formData['visit_number_display'] = $sessionData['visit_number']; // Use stored visit_number

    $pageTitle = 'Edit Aesthetic Session (MRN: ' . htmlspecialchars($mrn_edit) . ', Session: ' . htmlspecialchars($sess_edit) . ')';
    $formActionUrl = "?action=update&mrn=" . urlencode($mrn_edit) . "&session=" . urlencode($sess_edit);
    $submitButtonText = 'Update Session';
    $showSessionForm = true;

    goto render_aesthetics;
}

// 3.i UPDATE Session (Process form)
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mrn_update = $_GET['mrn'] ?? '';
    $sess_update = (int)($_GET['session'] ?? 0);

    if (!$mrn_update || !$sess_update) {
        $msg = 'Error: Invalid parameters for update.';
        $action = ''; 
        goto render_aesthetics;
    }

    $stmt_check = $pdo->prepare('SELECT is_locked, consent_signature_path, consent_signed_by, consent_signed_at FROM aesthetics WHERE mrn = ? AND session_number = ?');
    $stmt_check->execute([$mrn_update, $sess_update]);
    $currentSession = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$currentSession) {
        $msg = 'Error: Session not found for update.';
        $action = '';
        goto render_aesthetics;
    }
    if ($currentSession['is_locked']) {
        header("Location: aesthetics.php?action=view&mrn=" . urlencode($mrn_update) . "&session=" . $sess_update . "&status=locked_during_edit");
        exit;
    }

    $g = fn($k) => ($_POST[$k] ?? '') !== '' ? trim($_POST[$k]) : null;

    $current_sig_path = $currentSession['consent_signature_path'];
    $posted_sig_path = $g('sig_path');

    $final_sig_path = $posted_sig_path;
    $final_sig_by = $currentSession['consent_signed_by'];
    $final_sig_at = $currentSession['consent_signed_at'];

    if ($posted_sig_path && $posted_sig_path !== $current_sig_path) { // New or changed signature
        $final_sig_by = $uid;
        $final_sig_at = date('Y-m-d H:i:s');
    } elseif ($posted_sig_path === '' && $current_sig_path) { // Signature cleared (was 'null' but '' from form)
        $final_sig_by = null;
        $final_sig_at = null;
        $final_sig_path = null; // Explicitly set path to null if cleared
    }
    // If $posted_sig_path === $current_sig_path, by/at remain original.
    // If both empty, by/at remain null.

    $update_data = [
        'session_name' => $g('session_name'),
        'presenting_complaint' => $g('presenting_complaint'),
        'history_presenting_complaint' => $g('history_presenting_complaint'),
        'relevant_medical_history' => $g('relevant_medical_history'),
        'previous_aesthetic_treatments' => $g('previous_aesthetic_treatments'),
        'skin_type_assessment' => $g('skin_type_assessment'),
        'patient_goals' => $g('patient_goals'),
        'treatment_area' => $g('treatment_area'),
        'provisional_assessment' => $g('provisional_assessment'),
        'treatment_plan' => $g('treatment_plan'),
        'treatment_done_today' => $g('treatment_done_today'),
        'products_used' => $g('products_used'),
        'post_treatment_instructions' => $g('post_treatment_instructions'),
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

    $sql_update = 'UPDATE aesthetics SET ' . implode(', ', $set_clauses) . ' WHERE mrn = ? AND session_number = ? AND is_locked = 0';

    try {
        $updateStmt = $pdo->prepare($sql_update);
        $updateStmt->execute($execute_values);

        if ($updateStmt->rowCount() > 0) {
            header("Location: aesthetics.php?action=view&mrn=" . urlencode($mrn_update) . "&session=" . $sess_update . "&status=updated");
            exit;
        } else {
            $stmt_check_again = $pdo->prepare('SELECT is_locked FROM aesthetics WHERE mrn = ? AND session_number = ?');
            $stmt_check_again->execute([$mrn_update, $sess_update]);
            $session_after_attempt = $stmt_check_again->fetch(PDO::FETCH_ASSOC);
            if ($session_after_attempt && $session_after_attempt['is_locked']) {
                 header("Location: aesthetics.php?action=view&mrn=" . urlencode($mrn_update) . "&session=" . $sess_update . "&status=locked_during_edit");
            } else {
                 header("Location: aesthetics.php?action=view&mrn=" . urlencode($mrn_update) . "&session=" . $sess_update . "&status=update_failed_no_change");
            }
            exit;
        }
    } catch (Exception $e) {
        $msg = 'Database error during update: ' . $e->getMessage();
        error_log("Aesthetics Update Error: " . $e->getMessage());
        
        // Re-populate form for correction
        $formMode = 'edit';
        $formData = $_POST; 
        // Restore identifiers and non-POSTed patient data for the form
        $formData['mrn'] = $mrn_update;
        $formData['session_number'] = $sess_update; // Though not directly used in form display values other than title

        $stmt_patient_details = $pdo->prepare('SELECT full_name, gender, age, age_unit FROM patients WHERE mrn = ? LIMIT 1');
        $stmt_patient_details->execute([$mrn_update]);
        $patient_details_for_form = $stmt_patient_details->fetch(PDO::FETCH_ASSOC);
        if ($patient_details_for_form) {
            $formData = array_merge($formData, $patient_details_for_form); // Overwrite/add patient details
        }
        // Fetch original visit number for display
        $orig_sess_stmt = $pdo->prepare('SELECT visit_number FROM aesthetics WHERE mrn=? AND session_number=?');
        $orig_sess_stmt->execute([$mrn_update, $sess_update]);
        $orig_sess_data = $orig_sess_stmt->fetch(PDO::FETCH_ASSOC);
        $formData['visit_number_display'] = $orig_sess_data['visit_number'] ?? latestVisitNumber($pdo, $mrn_update);


        $pageTitle = 'Edit Aesthetic Session (Error trying to update)';
        $formActionUrl = "?action=update&mrn=" . urlencode($mrn_update) . "&session=" . urlencode($sess_update);
        $submitButtonText = 'Retry Update';
        $showSessionForm = true;
        goto render_aesthetics;
    }
}


// fall-through → main menu

render_aesthetics:
if(isset($_GET['status'])){
    if($_GET['status']==='created') $msg='Aesthetic session created successfully.';
    if($_GET['status']==='signed')  $msg='Aesthetic session signed & locked successfully.';
    if($_GET['status']==='updated') $msg='Aesthetic session updated successfully.';
    if($_GET['status']==='locked_cant_edit') $msg='Error: Session is locked and cannot be edited.';
    if($_GET['status']==='locked_during_edit') $msg='Error: Session was locked before changes could be saved. No update occurred.';
    if($_GET['status']==='update_failed_no_change') $msg='Session not updated. Either no changes were made or it was locked.';

}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>hospital0 - Aesthetics Clinic Portal</title> <link rel="icon" href="/media/sitelogo.png" type="image/png"> <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
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
input[readonly] { color: #bdbdbd !important; border-bottom: 1px dashed #757575 !important; } /* Style for readonly fields */
</style></head><body>
<?php include_once __DIR__.'/includes/header.php'; ?>
<ul id="nav" class="sidenav">
  <li><a href="aesthetics.php"><i class="material-icons">spa</i>Home</a></li> <li><a href="?action=create"><i class="material-icons">add_box</i>Add New Session</a></li> <li><a href="?action=retrieve"><i class="material-icons">search</i>Retrieve Session</a></li>
  <li><a href="?action=show"><i class="material-icons">view_list</i>Show All Sessions</a></li> </ul>
<a href="#" data-target="nav" class="sidenav-trigger white-text" style="position:fixed;top:15px;right:15px;z-index:900"><i class="material-icons">menu</i></a>
<div class="container" style="margin-top:70px">
<h4 class="center-align white-text">Aesthetics Clinic Portal</h4> <hr class="white-line">
<?php if($msg):?>
  <div class="card-panel <?=stripos($msg,'error')!==false || stripos($msg,'failed')!==false || stripos($msg,'locked')!==false ?'red lighten-1':'green lighten-1'?> white-text center"><?=htmlspecialchars($msg)?></div>
<?php endif;?>

<?php if($action==='' && !$showSessionForm): // Default menu, ensure form not showing ?>
  <div class="row center" style="margin-top:40px">
    <a class="btn blue waves-effect waves-light" style="width:260px;margin:8px" href="?action=create">Add New Session</a><br>
    <a class="btn waves-effect waves-light"     style="width:260px;margin:8px" href="?action=retrieve">Retrieve Session</a><br>
    <a class="btn grey waves-effect waves-light" style="width:260px;margin:8px" href="?action=show">Show All Sessions</a>
  </div>
<?php endif;?>

<?php if($showRetrieveForm && $action==='retrieve'):?>
  <h5 class="white-text">Retrieve Aesthetic Sessions by MRN</h5>
  <form method="POST" action="?action=retrieve">
    <div class="input-field"><input id="mrn_r_retrieve" name="mrn" type="text" class="validate white-text" required value="<?= htmlspecialchars($_POST['mrn'] ?? '') ?>"><label for="mrn_r_retrieve">Patient MRN</label></div>
    <button type="submit" class="btn waves-effect waves-light">Search</button>
    <a href="aesthetics.php" class="btn grey waves-effect waves-light">Back to Menu</a>
  </form>
<?php elseif(isset($retrievedMrn) && $action==='retrieve'): ?>
  <h5 class="white-text center">Aesthetic Sessions for MRN: <?=htmlspecialchars($retrievedMrn)?></h5>
  <?php if(empty($rows)):?><p class="white-text center">No aesthetic sessions found for this MRN.</p><?php else:?>
    <table class="striped responsive-table white-text">
      <thead><tr><th>Session #</th><th>Session Name</th><th>Patient Name</th><th>Date Created</th><th>Action</th></tr></thead><tbody>
      <?php foreach($rows as $r):?>
      <tr>
        <td><?=htmlspecialchars($r['session_number'])?></td>
        <td><?=htmlspecialchars($r['session_name']?:'-')?></td>
        <td><?=htmlspecialchars($r['full_name'])?></td>
        <td><?=date('d-M-Y H:i',strtotime($r['created_at']))?></td>
      <td><a class="btn-small blue waves-effect waves-light" href="?action=view&mrn=<?=urlencode($r['session_mrn'])?>&session=<?=urlencode($r['session_number'])?>">View</a></td></tr>
      <?php endforeach;?></tbody></table>
  <?php endif;?>
  <br><a href="?action=retrieve" class="btn grey waves-effect waves-light">New Search</a>
  <a href="aesthetics.php" class="btn grey waves-effect waves-light">Back to Menu</a>
<?php endif;?>


<?php if($showAll && $action === 'show'): ?>
  <h5 class="white-text center">All Aesthetic Sessions (latest first)</h5>
  <?php if(empty($rows)):?><p class="center white-text">No aesthetic sessions found in the system (or an error occurred retrieving them).</p><?php else:?>
  <table class="striped responsive-table white-text"><thead><tr><th>Session#</th><th>MRN</th><th>Patient Name</th><th>Session Name</th><th>Date Created</th><th>Action</th></tr></thead><tbody>
  <?php foreach($rows as $r):?>
    <tr>
      <td><?=htmlspecialchars($r['session_number'])?></td>
      <td><?=htmlspecialchars($r['mrn'])?></td>
      <td><?=htmlspecialchars($r['full_name'])?></td>
      <td><?=htmlspecialchars($r['session_name']?:'-')?></td>
      <td><?=date('d-M-Y H:i',strtotime($r['created_at']))?></td>
      <td><a class="btn-small blue waves-effect waves-light" href="?action=view&mrn=<?=urlencode($r['mrn'])?>&session=<?=urlencode($r['session_number'])?>">View</a></td></tr>
  <?php endforeach;?></tbody></table>
  <?php if($total > $perPage): ?>
      <ul class="pagination center">
        <li class="<?=$page <= 1 ? 'disabled' : 'waves-effect'?>"><a href="?action=show&page=<?=max(1, $page-1)?>"><i class="material-icons">chevron_left</i></a></li>
        <?php for($i = 1; $i <= $tp; $i++):?>
          <li class="<?=$i == $page ? 'active blue' : 'waves-effect'?>"><a href="?action=show&page=<?=$i?>"><?=$i?></a></li>
        <?php endfor;?>
        <li class="<?=$page >= $tp ? 'disabled' : 'waves-effect'?>"><a href="?action=show&page=min($tp, $page+1)?>"><i class="material-icons">chevron_right</i></a></li>
      </ul>
  <?php endif;?>
  <?php endif;?><br><a href="aesthetics.php" class="btn grey waves-effect waves-light">Back to Menu</a>
<?php endif;?>

<?php if(isset($view) && $view !== null && $action==='view'): ?>
  <div class="card-panel grey darken-3 white-text">
    <h5>Patient: <?=htmlspecialchars($view['full_name'] ?? 'N/A')?> (MRN: <?=htmlspecialchars($view['mrn'] ?? 'N/A')?>)</h5>
    <p><strong>Session #:</strong> <?=htmlspecialchars($view['session_number'] ?? '-')?>&nbsp;&nbsp;&nbsp;<strong>Visit #:</strong> <?=htmlspecialchars($view['visit_number'] ?? '-')?></p>
    <p><strong>Session Name:</strong> <?=htmlspecialchars($view['session_name'] ?: '-')?></p>
    <p><strong>Created:</strong> <?=isset($view['created_at']) ? date('d-M-Y H:i',strtotime($view['created_at'])) : '-'?> by User ID: <?=htmlspecialchars($view['created_by'] ?? '-')?></p>
    <p><strong>Status:</strong> <?=(isset($view['is_locked']) && $view['is_locked']) ?'<span style="color:lightgreen; font-weight:bold;">Locked</span> (Signed by User ID: '.htmlspecialchars($view['locked_by'] ?? '-').' on '.(isset($view['locked_at']) ? date('d-M-Y H:i',strtotime($view['locked_at'])) : '-').')':'<span style="color:yellow; font-weight:bold;">Open / Editable</span>'?></p>

    <hr style="border-color:rgba(255,255,255,.2)">
    <p><strong>Presenting Complaint:</strong><br><?=nl2br(htmlspecialchars($view['presenting_complaint']?:'-'))?></p>
    <p><strong>History of Presenting Complaint:</strong><br><?=nl2br(htmlspecialchars($view['history_presenting_complaint']?:'-'))?></p>
    <p><strong>Relevant Medical History:</strong><br><?=nl2br(htmlspecialchars($view['relevant_medical_history']?:'-'))?></p>
    <p><strong>Previous Aesthetic Treatments:</strong><br><?=nl2br(htmlspecialchars($view['previous_aesthetic_treatments']?:'-'))?></p>
    <p><strong>Skin Type / Assessment:</strong><br><?=nl2br(htmlspecialchars($view['skin_type_assessment']?:'-'))?></p>
    <p><strong>Patient Goals:</strong><br><?=nl2br(htmlspecialchars($view['patient_goals']?:'-'))?></p>
    <p><strong>Treatment Area(s):</strong><br><?=nl2br(htmlspecialchars($view['treatment_area']?:'-'))?></p>

    <hr style="border-color:rgba(255,255,255,.2)">
    <p><strong>Provisional Assessment:</strong><br><?=nl2br(htmlspecialchars($view['provisional_assessment']?:'-'))?></p>
    <p><strong>Treatment Plan:</strong><br><?=nl2br(htmlspecialchars($view['treatment_plan']?:'-'))?></p>
    <p><strong>Treatment Done Today:</strong><br><?=nl2br(htmlspecialchars($view['treatment_done_today']?:'-'))?></p>
    <p><strong>Products Used:</strong><br><?=nl2br(htmlspecialchars($view['products_used']?:'-'))?></p>
    <p><strong>Post-Treatment Instructions:</strong><br><?=nl2br(htmlspecialchars($view['post_treatment_instructions']?:'-'))?></p>
    <p><strong>Follow-up Recommended:</strong><br><?=nl2br(htmlspecialchars($view['follow_up_recommended']?:'-'))?></p>
    <p><strong>Notes:</strong><br><?=nl2br(htmlspecialchars($view['notes']?:'-'))?></p>

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
    <p style="font-size:1.2em; font-weight:bold;">Treatment Summary:</p> <p><?=nl2br(htmlspecialchars($view['treatment_summary']))?></p>
    <?php endif;?>
  </div>
  <div class="row center" style="margin-top:20px;">
    <?php if(!(isset($view['is_locked']) && $view['is_locked'])):?>
      <a class="btn orange waves-effect waves-light" href="?action=edit&mrn=<?=urlencode($view['mrn'])?>&session=<?=urlencode($view['session_number'])?>" style="margin-right: 5px;">Edit Session</a>
      <a class="btn green waves-effect waves-light modal-trigger" href="#signModalAesthetics" style="margin-right: 5px;">Sign & Lock Session</a>
    <?php endif;?>
    <?php if(!empty($view['pdf_path'])):?><a class="btn purple waves-effect waves-light" href="/<?=htmlspecialchars($view['pdf_path'])?>" target="_blank" download style="margin-right: 5px;">Download PDF</a><?php endif;?>
    <a href="aesthetics.php" class="btn grey waves-effect waves-light">Back to Menu</a>
  </div>

  <?php if(!(isset($view['is_locked']) && $view['is_locked'])):?>
  <div id="signModalAesthetics" class="modal grey darken-3 white-text"> <div class="modal-content"><h5>Enter Treatment Summary to Sign & Lock</h5> <form id="signFormAesthetics" method="POST" action="?action=sign&mrn=<?=urlencode($view['mrn'])?>&session=<?=urlencode($view['session_number'])?>">
        <div class="input-field"><textarea name="treatment_summary" id="treatment_summary_text" class="materialize-textarea white-text" required data-length="65000" autofocus><?=htmlspecialchars($view['treatment_summary'] ?? '')?></textarea><label for="treatment_summary_text" class="<?=!empty($view['treatment_summary']) ? 'active' : ''?>">Treatment Summary (Required)</label></div>
        <p class="yellow-text text-lighten-2">Warning: Once saved, this session will be locked and the treatment summary cannot be further edited.</p>
        <button type="submit" class="btn green waves-effect waves-light">Save & Lock Session</button>
        <a href="#!" class="modal-close btn red waves-effect waves-light">Cancel</a>
      </form></div>
  </div>
  <?php endif;?>
<?php endif;?>


<?php if($showSessionForm): // Covers create (GET) and edit (GET) ?>
  <h5 class="white-text"><?= htmlspecialchars($pageTitle) ?></h5>
  <form method="POST" action="<?= htmlspecialchars($formActionUrl) ?>" id="aestheticsSessionForm">
    <div class="row">
        <div class="input-field col s12 m4">
            <input id="mrn_form_main" name="mrn" type="text" onblur="lookupMRNAesthetics()" class="validate white-text" required value="<?= htmlspecialchars($formData['mrn'] ?? '') ?>" <?= ($formMode === 'edit' ? 'readonly' : '') ?>>
            <label for="mrn_form_main">Patient MRN *</label>
        </div>
        <div class="input-field col s12 m5">
            <input id="full_name_display" name="full_name_display" type="text" readonly value="<?= htmlspecialchars($formData['full_name'] ?? '') ?>">
            <label class="active">Full Name</label>
        </div>
        <div class="input-field col s6 m1">
             <input id="age_display" name="age_display" type="text" readonly value="<?= htmlspecialchars(($formData['age'] ?? '') . (!empty($formData['age']) && !empty($formData['age_unit']) ? ' ' . $formData['age_unit'] : '')) ?>">
            <label class="active">Age</label></div>
        <div class="input-field col s6 m2">
            <input id="gender_display" name="gender_display" type="text" readonly value="<?= htmlspecialchars($formData['gender'] ?? '') ?>">
            <label class="active">Gender</label>
        </div>
    </div>
    <div class="row">
        <div class="input-field col s12 m4">
            <input id="visit_number_display" name="visit_number_display" type="text" readonly value="<?= htmlspecialchars($formData['visit_number_display'] ?? ($formMode === 'create' ? '' : ($formData['visit_number'] ?? ''))) ?>">
            <label class="active">Patient Visit # <?= ($formMode === 'edit' ? '(at time of session)' : '(auto-filled)') ?></label>
        </div>
        <div class="input-field col s12 m8">
            <input name="session_name" type="text" class="white-text" data-length="128" value="<?= htmlspecialchars($formData['session_name'] ?? '') ?>">
            <label>Session Name / Title (e.g., Botox Consultation, Laser Session 1)</label>
        </div>
    </div>

    <h6 class="white-text" style="font-size:1.3em; margin-top:30px;">Patient Information & Goals</h6>
    <div class="input-field"><textarea name="presenting_complaint" class="materialize-textarea white-text" data-length="512"><?= htmlspecialchars($formData['presenting_complaint'] ?? '') ?></textarea><label>Presenting Complaint (Patient's words)</label></div>
    <div class="input-field"><textarea name="history_presenting_complaint" class="materialize-textarea white-text" data-length="1024"><?= htmlspecialchars($formData['history_presenting_complaint'] ?? '') ?></textarea><label>History of Presenting Complaint (Duration, severity, evolution)</label></div>
    <div class="input-field"><textarea name="relevant_medical_history" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['relevant_medical_history'] ?? '') ?></textarea><label>Relevant Medical History (Allergies, skin conditions, medications, pregnancy, chronic illness, surgeries)</label></div>
    <div class="input-field"><textarea name="previous_aesthetic_treatments" class="materialize-textarea white-text" data-length="1024"><?= htmlspecialchars($formData['previous_aesthetic_treatments'] ?? '') ?></textarea><label>Previous Aesthetic Treatments (Type, date, outcome, any adverse reactions)</label></div>
    <div class="input-field"><input name="skin_type_assessment" type="text" class="white-text" data-length="100" value="<?= htmlspecialchars($formData['skin_type_assessment'] ?? '') ?>"><label>Skin Type / Assessment (e.g., Fitzpatrick, oily/dry, sensitivity, specific conditions)</label></div>
    <div class="input-field"><textarea name="patient_goals" class="materialize-textarea white-text" data-length="1024"><?= htmlspecialchars($formData['patient_goals'] ?? '') ?></textarea><label>Patient Goals & Expectations</label></div>
    <div class="input-field"><input name="treatment_area" type="text" class="white-text" data-length="255" value="<?= htmlspecialchars($formData['treatment_area'] ?? '') ?>"><label>Treatment Area(s) of Concern</label></div>

    <hr class="divider">
    <h6 class="white-text" style="font-size:1.3em;">Assessment & Plan</h6>
    <div class="input-field"><textarea name="provisional_assessment" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['provisional_assessment'] ?? '') ?></textarea><label>Provisional Assessment / Clinical Findings</label></div>
    <div class="input-field"><textarea name="treatment_plan" class="materialize-textarea white-text" data-length="4096"><?= htmlspecialchars($formData['treatment_plan'] ?? '') ?></textarea><label>Proposed Treatment Plan (Procedures, products, sessions, timeline, cost if applicable)</label></div>

    <hr class="divider">
    <h6 class="white-text" style="font-size:1.3em;">Consent & Signature</h6>
    <?php
        $defaultConsentText = 'I, the undersigned, consent to undergo the aesthetic procedure(s) of [Specify Procedure(s) Here, e.g., Botulinum Toxin Injections, Dermal Fillers, Laser Treatment] as discussed with my practitioner. I confirm that the nature of the procedure(s), expected benefits, potential risks (including but not limited to bruising, swelling, redness, pain, infection, allergic reaction, asymmetry, unsatisfactory results, and specific risks related to the named procedure), and alternative treatments have been explained to me. I have had the opportunity to ask questions and all my questions have been answered to my satisfaction. I understand that results are not guaranteed and may vary. I consent to photography/videography for medical records and, if separately agreed, for educational or promotional purposes with anonymity. This consent is given voluntarily.';
        $consentTextValue = $formData['consent_text'] ?? ($formMode === 'create' && empty($formData['consent_text']) ? $defaultConsentText : ($formData['consent_text'] ?? ''));
    ?>
    <div class="input-field">
        <textarea name="consent_text" class="materialize-textarea white-text" style="min-height:120px"><?= htmlspecialchars($consentTextValue) ?></textarea>
        <label class="active">Consent Text (Edit as needed, especially [Specify Procedure(s) Here])</label>
    </div>
    <input type="hidden" name="sig_path" id="sig_path_aesthetics" value="<?= htmlspecialchars($formData['consent_signature_path'] ?? '') ?>">
    <div class="card-panel grey darken-3" style="padding: 15px;">
        <button type="button" class="btn waves-effect waves-light" onclick="openSignaturePadAesthetics()">
            <?= !empty($formData['consent_signature_path']) ? 'Replace Signature' : 'Add Patient/Guardian Signature' ?>
        </button>
        <?php if ($formMode === 'edit' && !empty($formData['consent_signature_path'])): ?>
            <button type="button" class="btn orange waves-effect waves-light" onclick="clearSignatureAesthetics()" style="margin-left:10px;">Clear Signature</button>
        <?php endif; ?>
        <span id="sig-status-aesthetics" class="white-text" style="margin-left:15px;"><?= !empty($formData['consent_signature_path']) ? 'Existing signature loaded.' : '' ?></span>
        <img id="sigPreviewAesthetics" src="<?= !empty($formData['consent_signature_path']) ? ('/' . htmlspecialchars($formData['consent_signature_path'])) : '#' ?>" alt="Signature Preview" style="display:<?= !empty($formData['consent_signature_path']) ? 'block' : 'none' ?>; max-width:150px; height:auto; background:white; border:1px solid #ccc; margin-top:10px;">
    </div>

    <hr class="divider">
    <h6 class="white-text" style="font-size:1.3em;">Treatment Details (Post-Procedure)</h6>
    <div class="input-field"><textarea name="treatment_done_today" class="materialize-textarea white-text" data-length="4096"><?= htmlspecialchars($formData['treatment_done_today'] ?? '') ?></textarea><label>Treatment Done Today (Detailed description of procedure performed)</label></div>
    <div class="input-field"><textarea name="products_used" class="materialize-textarea white-text" data-length="1024"><?= htmlspecialchars($formData['products_used'] ?? '') ?></textarea><label>Products Used (Name, brand, batch no., expiry, quantity, site/depth if applicable)</label></div>
    <div class="input-field"><textarea name="post_treatment_instructions" class="materialize-textarea white-text" data-length="2048"><?= htmlspecialchars($formData['post_treatment_instructions'] ?? '') ?></textarea><label>Post-Treatment Instructions & Care</label></div>
    <div class="input-field"><input name="follow_up_recommended" type="text" class="white-text" data-length="100" value="<?= htmlspecialchars($formData['follow_up_recommended'] ?? '') ?>"><label>Follow-up Recommended (e.g., 2 weeks, specific date)</label></div>
    <div class="input-field"><textarea name="notes" class="materialize-textarea white-text" data-length="1024"><?= htmlspecialchars($formData['notes'] ?? '') ?></textarea><label>Other Notes (e.g., Patient feedback, constraints, next steps)</label></div>

    <div class="row center" style="margin-top:30px;">
        <button type="submit" class="btn green waves-effect waves-light"><?= htmlspecialchars($submitButtonText) ?></button>
        <a href="aesthetics.php" class="btn grey waves-effect waves-light">Cancel & Back to Menu</a>
    </div>
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
    M.FormSelect.init(document.querySelectorAll('select'));

    <?php if ($showSignForm && isset($view) && is_array($view) && !($view['is_locked'] ?? true)): ?>
    const signModalElem = document.getElementById('signModalAesthetics');
    if (signModalElem) {
        const instance = M.Modal.getInstance(signModalElem);
        if (instance) {
            instance.open();
            const summaryTextarea = document.getElementById('treatment_summary_text');
            if (summaryTextarea) {
                summaryTextarea.focus();
                M.textareaAutoResize(summaryTextarea);
            }
        }
    }
    <?php endif; ?>
    document.querySelectorAll('textarea.materialize-textarea').forEach(ta => M.textareaAutoResize(ta));
    M.updateTextFields(); // This will activate labels for pre-filled fields
});

function lookupMRNAesthetics(){
  const mrnField = document.getElementById('mrn_form_main'); // Changed ID to be unique
  // If in edit mode (MRN field is readonly), don't perform lookup
  if (mrnField && mrnField.hasAttribute('readonly')) {
      return;
  }

  const nameField = document.getElementById('full_name_display'); // Changed ID
  const ageField = document.getElementById('age_display'); // Changed ID
  const genderField = document.getElementById('gender_display'); // Changed ID
  const visitField = document.getElementById('visit_number_display'); // Changed ID

  if(!mrnField || !mrnField.value.trim()){
      [nameField, ageField, genderField, visitField].forEach(el => { if(el) el.value=''; });
      M.updateTextFields();
      return;
  }
  fetch('aesthetics.php?action=lookup_mrn&mrn='+encodeURIComponent(mrnField.value.trim()))
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
        if(visitField) visitField.value = data.latest_visit || ''; // For create form, this is latest_visit
        M.toast({html: 'Patient details loaded.', classes: 'green darken-1'});
      }
      M.updateTextFields();
    }).catch(error => {
      M.toast({html:'MRN Lookup Error: ' + error.message, classes:'red darken-2'});
      [nameField, ageField, genderField, visitField].forEach(el => { if(el) el.value=''; });
      M.updateTextFields();
    });
}

let sigPadAesthetics, sigModalInstanceAesthetics;
const sigCanvasAesthetics = document.createElement('canvas');
sigCanvasAesthetics.id = 'sigCanvasAesthetics';
sigCanvasAesthetics.width = 500;
sigCanvasAesthetics.height = 200;
sigCanvasAesthetics.style.border = '1px solid #aaa';
sigCanvasAesthetics.style.backgroundColor = 'white';

function openSignaturePadAesthetics(){
  if(!sigModalInstanceAesthetics){
    const modalHTML = `
      <div class="modal-content">
        <h5>Provide Signature</h5>
        <div id="sigCanvasContainerAesthetics"></div>
      </div>
      <div class="modal-footer">
        <a href="#!" class="modal-close waves-effect waves-grey btn-flat">Cancel</a>
        <a href="#!" id="sigClearButtonAesthetics" class="waves-effect waves-red btn-flat">Clear</a>
        <a href="#!" id="sigSaveButtonAesthetics" class="waves-effect waves-green btn blue white-text">Save Signature</a>
      </div>`;
    const modalEl = document.createElement('div');
    modalEl.id = 'signatureModalAesthetics';
    modalEl.className = 'modal grey darken-3 white-text';
    modalEl.innerHTML = modalHTML;
    document.body.appendChild(modalEl);

    modalEl.querySelector('#sigCanvasContainerAesthetics').appendChild(sigCanvasAesthetics);

    sigModalInstanceAesthetics = M.Modal.init(modalEl, {
        dismissible: false,
        onOpenEnd: function() {
            if (!sigPadAesthetics) {
                sigPadAesthetics = new SignaturePad(sigCanvasAesthetics, {
                    backgroundColor: 'rgb(255, 255, 255)'
                });
            }
            sigPadAesthetics.clear();
        }
    });

    modalEl.querySelector('#sigClearButtonAesthetics').onclick = () => sigPadAesthetics.clear();
    modalEl.querySelector('#sigSaveButtonAesthetics').onclick = () => {
      if(sigPadAesthetics.isEmpty()){
        M.toast({html:'Signature is empty. Please sign.', classes:'yellow darken-3 black-text'});
        return;
      }
      const dataURL = sigPadAesthetics.toDataURL('image/png');
      fetch('aesthetics.php?action=save_signature',{method:'POST',body:new URLSearchParams({img: dataURL})})
        .then(response => response.json())
        .then(data => {
          if(data.ok){
            document.getElementById('sig_path_aesthetics').value = data.path;
            const sigStatus = document.getElementById('sig-status-aesthetics');
            if(sigStatus) sigStatus.textContent = 'New signature captured. Save form to apply.';
            const sigPreview = document.getElementById('sigPreviewAesthetics');
            if(sigPreview) {
                sigPreview.src = dataURL;
                sigPreview.style.display = 'block';
            }
            // Update button text
            const sigButton = document.querySelector('#aestheticsSessionForm button[onclick="openSignaturePadAesthetics()"]');
            if(sigButton) sigButton.textContent = 'Replace Signature';

            sigModalInstanceAesthetics.close();
            M.toast({html:'Signature captured! Remember to save the session.', classes:'green darken-1'});
          } else {
            M.toast({html: data.message || 'Failed to save signature.', classes:'red darken-2'});
          }
        }).catch(error => M.toast({html:'Signature save error: ' + error.message, classes:'red darken-2'}));
    };
  }
  if (sigPadAesthetics) sigPadAesthetics.clear();
  sigModalInstanceAesthetics.open();
}

function clearSignatureAesthetics() {
    document.getElementById('sig_path_aesthetics').value = ''; // Set to empty string to indicate clearing
    const sigPreview = document.getElementById('sigPreviewAesthetics');
    sigPreview.src = '#';
    sigPreview.style.display = 'none';
    document.getElementById('sig-status-aesthetics').textContent = 'Signature cleared. Save form to apply changes.';
    
    const sigButton = document.querySelector('#aestheticsSessionForm button[onclick="openSignaturePadAesthetics()"]');
    if(sigButton) sigButton.textContent = 'Add Patient/Guardian Signature';

    M.toast({html:'Signature cleared. Remember to save the session to apply this change.', classes:'orange darken-2'});
}
</script>
</body></html>