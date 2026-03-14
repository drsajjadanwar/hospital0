<?php
// /public/files.php — Revised (15‑May‑2025) -> Modernized by Gemini (04-Oct-2025)
// -----------------------------------------------------------------------------
// * Modernized UI to match sophisticated dark theme with glassmorphism.
// * Preserved all core EMR functionality, including dynamic forms and signatures.
// * Restyled all components (menus, tables, forms, modals) for a clean, consistent look.
// * Ensured functional integrity of existing PHP and JavaScript logic.
// -----------------------------------------------------------------------------

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();

require_once __DIR__.'/includes/config.php';

// 1. PDO GUARANTEE
if(!isset($pdo) || !$pdo instanceof PDO){
    error_log("files.php: PDO missing or invalid. Check includes/config.php.");
    http_response_code(500);
    exit('Database configuration error. Please contact administrator.');
}

// 2. LOGIN
if(!isset($_SESSION['user'])){
    header('Location: login.php');
    exit;
}
$currentUser   = $_SESSION['user'];
$currentUserId = (int)($currentUser['user_id']??0);
$userFullName  = htmlspecialchars($currentUser['full_name']??'Unknown User');

// 3. REQUEST STATE
$action         = $_GET['action']           ?? '';
$intent         = $_GET['intent']           ?? '';
$actionAfterMrn = $_GET['action_after_mrn'] ?? '';
$mrn            = $_GET['mrn']              ?? null;
$emr_visit_number_get = isset($_GET['emr_visit_number']) ? (int)$_GET['emr_visit_number'] : null;

$perPage        = 10;
$pageTitle      = 'Indoor Patient Files EMR';
$feedback_message = '';
$feedback_type = '';
$patient        = null;
$visitNumber    = 1;

if($mrn && $actionAfterMrn){ $action = $actionAfterMrn; }

// 4. HELPER
function emr_enc($val){
    if(!isset($val)) return null;
    if(is_array($val)){
        $val = array_values(array_filter($val,fn($v)=>$v!=='' && $v!==null));
        return $val ? json_encode($val,JSON_UNESCAPED_UNICODE) : null;
    }
    $trim = trim($val);
    return $trim==='' ? null : $trim;
}

// 5. INSERT/UPDATE
function openNewFile(PDO $pdo, array $form, int $userId): bool {
    $sql = "INSERT INTO patient_emr (
                mrn, visit_number, presenting_complaints,
                systemic_review_general, systemic_review_cv, systemic_review_resp,
                systemic_review_gi, systemic_review_gu, systemic_review_neuro, systemic_review_psych,
                examination_findings_text, focused_examination_text, tests_ordered,
                past_medical_history, current_medications,
                allergies_option, allergy_details, working_diagnosis, differentials, drug_chart,
                consents, doctors_progress_notes, nurses_progress_notes,
                consultations_text, tests_text, vitals_text, anaesthesia_text,
                discharge_type, discharge_details, created_by, created_at, updated_at
            ) VALUES (
                :mrn, :visit, :pres,
                :sg, :scv, :sresp,
                :sgi, :sgu, :sneuro, :spsych,
                :exam, :fexam, :tests,
                :pmh, :meds,
                :allopt, :alldet, :wdx, :diff, :drug,
                :cons, :docn, :nursen,
                :consult, :ttext, :vital, :anaes,
                :dtype, :ddet, :uid, CURRENT_TIMESTAMP(), CURRENT_TIMESTAMP()
            ) ON DUPLICATE KEY UPDATE
                presenting_complaints   = VALUES(presenting_complaints),
                systemic_review_general = VALUES(systemic_review_general),
                systemic_review_cv      = VALUES(systemic_review_cv),
                systemic_review_resp    = VALUES(systemic_review_resp),
                systemic_review_gi      = VALUES(systemic_review_gi),
                systemic_review_gu      = VALUES(systemic_review_gu),
                systemic_review_neuro   = VALUES(systemic_review_neuro),
                systemic_review_psych   = VALUES(systemic_review_psych),
                examination_findings_text= VALUES(examination_findings_text),
                focused_examination_text = VALUES(focused_examination_text),
                tests_ordered           = VALUES(tests_ordered),
                past_medical_history    = VALUES(past_medical_history),
                current_medications     = VALUES(current_medications),
                allergies_option        = VALUES(allergies_option),
                allergy_details         = VALUES(allergy_details),
                working_diagnosis       = VALUES(working_diagnosis),
                differentials           = VALUES(differentials),
                drug_chart              = VALUES(drug_chart),
                consents                = VALUES(consents),
                doctors_progress_notes  = VALUES(doctors_progress_notes),
                nurses_progress_notes   = VALUES(nurses_progress_notes),
                consultations_text      = VALUES(consultations_text),
                tests_text              = VALUES(tests_text),
                vitals_text             = VALUES(vitals_text),
                anaesthesia_text        = VALUES(anaesthesia_text),
                discharge_type          = VALUES(discharge_type),
                discharge_details       = VALUES(discharge_details),
                updated_at              = CURRENT_TIMESTAMP(),
                created_by              = VALUES(created_by)
                ";

    try {
        $st = $pdo->prepare($sql);
        if (!$st) {
            $errorInfo = $pdo->errorInfo();
            error_log('files.php openNewFile error: PDO::prepare failed. Error: ' . ($errorInfo[2] ?? 'Unknown error'));
            return false;
        }

        $b  = fn($paramName, $valueToBind, $pdoType = PDO::PARAM_STR) => $st->bindValue($paramName, $valueToBind, $pdoType);

        $b(':mrn',   emr_enc($form['mrn']));
        $b(':visit', (int)($form['visit_number'] ?? 1), PDO::PARAM_INT);
        $b(':pres',  emr_enc($form['presenting_complaints'] ?? null));
        $b(':sg',    emr_enc($form['systemic_review_general'] ?? null));
        $b(':scv',   emr_enc($form['systemic_review_cv'] ?? null));
        $b(':sresp', emr_enc($form['systemic_review_resp'] ?? null));
        $b(':sgi',   emr_enc($form['systemic_review_gi'] ?? null));
        $b(':sgu',   emr_enc($form['systemic_review_gu'] ?? null));
        $b(':sneuro',emr_enc($form['systemic_review_neuro'] ?? null));
        $b(':spsych',emr_enc($form['systemic_review_psych'] ?? null));
        $b(':exam',  emr_enc($form['examination_findings_text'] ?? null));
        $b(':fexam', emr_enc($form['focused_examination_text'] ?? null));
        $b(':tests', emr_enc($form['tests_ordered'] ?? null));
        $b(':pmh',   emr_enc($form['past_medical_history'] ?? null));
        $b(':meds',  emr_enc($form['current_medications'] ?? null));
        $b(':allopt',emr_enc($form['allergies_option'] ?? null));
        $b(':alldet',emr_enc($form['allergy_details'] ?? null));
        $b(':wdx',   emr_enc($form['working_diagnosis'] ?? null));
        $b(':diff',  emr_enc($form['differentials'] ?? null));
        $b(':drug',  emr_enc($form['drug_chart'] ?? null));
        
        $cons = [];
        $signatureDir = __DIR__ . '/signatures/';
        if (!is_dir($signatureDir)) {
            if (!mkdir($signatureDir, 0775, true) && !is_dir($signatureDir)) {
                 error_log('files.php openNewFile error: Failed to create signature directory: ' . $signatureDir);
            }
        }

        if(!empty($form['consent_given_by']) && is_array($form['consent_given_by'])){
            for($i=0; $i < count($form['consent_given_by']); $i++){
                if(trim($form['consent_given_by'][$i] ?? '') !== ''){
                    $signaturePath = null;
                    if (!empty($form['signature_data'][$i])) {
                        $dataURL = $form['signature_data'][$i];
                        if (preg_match('/^data:image\/png;base64,/', $dataURL)) {
                            $base64Data = substr($dataURL, strpos($dataURL, ',') + 1);
                            $imageData = base64_decode($base64Data);
                            if ($imageData) {
                                $randomFilename = mt_rand(100000000000, 999999999999) . '.png';
                                $filePath = $signatureDir . $randomFilename;
                                if (file_put_contents($filePath, $imageData)) {
                                    $signaturePath = 'signatures/' . $randomFilename;
                                }
                            }
                        } else {
                             if(strpos($form['signature_data'][$i], 'signatures/') === 0) {
                                 $signaturePath = $form['signature_data'][$i];
                             }
                        }
                    }

                    $cons[] = [
                        'given_by' => trim($form['consent_given_by'][$i] ?? ''),
                        'relation' => trim($form['relation'][$i] ?? ''),
                        'cnic'     => trim($form['cnic'][$i] ?? ''),
                        'signature'=> $signaturePath,
                    ];
                }
            }
        }
        $b(':cons',  emr_enc($cons));
        $b(':docn',  emr_enc($form['doctors_progress_notes'] ?? null));
        $b(':nursen',emr_enc($form['nurses_progress_notes'] ?? null));
        $b(':consult',emr_enc($form['consultations_text'] ?? null));
        $b(':ttext', emr_enc($form['tests_text'] ?? null));
        $b(':vital', emr_enc($form['vitals_text'] ?? null));
        $b(':anaes', emr_enc($form['anaesthesia_text'] ?? null));
        $b(':dtype', emr_enc($form['discharge_type'] ?? null));
        $b(':ddet',  emr_enc($form['discharge_details'] ?? null));
        $b(':uid',   (string)$userId, PDO::PARAM_STR); 

        return $st->execute();

    } catch(PDOException $ex) {
        error_log('files.php openNewFile PDOException: '. $ex->getMessage() . ' SQL: ' . $sql . ' MRN: ' . ($form['mrn'] ?? 'N/A'));
        return false;
    }
}

// --- DATA FETCHING & BUSINESS LOGIC ---
if($mrn){
    try{
        $st = $pdo->prepare('SELECT * FROM patients WHERE mrn = :mrn');
        $st->execute([':mrn' => $mrn]);
        $patient = $st->fetch(PDO::FETCH_ASSOC);

        if($patient){
            $vs = $pdo->prepare('SELECT MAX(visit_number) FROM patient_emr WHERE mrn = :mrn_emr');
            $vs->execute([':mrn_emr' => $mrn]);
            $lastVisit = (int)$vs->fetchColumn();
            $visitNumber = $lastVisit > 0 ? $lastVisit + 1 : 1;
            
            if ($emr_visit_number_get !== null) {
                 $visitNumber = $emr_visit_number_get;
            }

        }else{
            $feedback_message = 'Patient with MRN '.htmlspecialchars($mrn).' not found.';
            $feedback_type = 'error';
            $action = ''; 
            if ($actionAfterMrn === 'view_all_global') $action = 'view_all_global';
        }
    }catch(PDOException $ex){
        error_log('files.php patient lookup PDOException: '. $ex->getMessage() . ' MRN: ' . $mrn);
        $feedback_message = 'Database error while fetching patient record.';
        $feedback_type = 'error';
        $patient = null;
    }
}

function paginate($total,$page,$per){
    $pages = max(ceil($total/$per),1);
    $currentPage = max(min($page,$pages),1);
    $offset = ($currentPage-1)*$per;
    return [$pages, $currentPage, $offset];
}

function getAllFilesForPatient(PDO $pdo,string $mrn,int $page,int $per):array{
    try {
        $countSt = $pdo->prepare('SELECT COUNT(*) FROM patient_emr WHERE mrn = :mrn_count');
        $countSt->execute([':mrn_count' => $mrn]);
        $totalRecords = (int)$countSt->fetchColumn();
    } catch (PDOException $ex) { return ['error' => 'Could not count files.']; }

    [$totalPages,$currentPage,$offset] = paginate($totalRecords,$page,$per);

    try {
        $listSt = $pdo->prepare('SELECT mrn, visit_number, working_diagnosis, created_at FROM patient_emr WHERE mrn = :mrn ORDER BY created_at DESC LIMIT :lim OFFSET :off');
        $listSt->bindValue(':mrn',$mrn); $listSt->bindValue(':lim',$per,PDO::PARAM_INT); $listSt->bindValue(':off',$offset,PDO::PARAM_INT);
        $listSt->execute();
        $files = $listSt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) { return ['error' => 'Could not retrieve files.']; }
    
    return ['files' => $files, 'totalPages' => $totalPages, 'currentPage' => $currentPage, 'totalRecords' => $totalRecords];
}

function searchFilesByTerm(PDO $pdo,string $mrn,string $term,int $page,int $per):array{
    $like = '%'.$term.'%';
    try {
        $countSt  = $pdo->prepare('SELECT COUNT(*) FROM patient_emr WHERE mrn = :mrn AND (presenting_complaints LIKE :q OR working_diagnosis LIKE :q OR differentials LIKE :q)');
        $countSt->execute([':mrn'=>$mrn,':q'=>$like]);
        $totalRecords = (int)$countSt->fetchColumn();
    } catch (PDOException $ex) { return ['error' => 'Could not count search results.']; }

    [$totalPages,$currentPage,$offset] = paginate($totalRecords,$page,$per);

    try {
        $listSt  = $pdo->prepare('SELECT mrn, visit_number, working_diagnosis, created_at FROM patient_emr WHERE mrn = :mrn AND (presenting_complaints LIKE :q OR working_diagnosis LIKE :q OR differentials LIKE :q) ORDER BY created_at DESC LIMIT :lim OFFSET :off');
        $listSt->bindValue(':mrn',$mrn); $listSt->bindValue(':q',$like); $listSt->bindValue(':lim',$per,PDO::PARAM_INT); $listSt->bindValue(':off',$offset,PDO::PARAM_INT);
        $listSt->execute();
        $files = $listSt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) { return ['error' => 'Could not retrieve search results.']; }
    
    return ['files' => $files, 'totalPages' => $totalPages, 'currentPage' => $currentPage, 'totalRecords' => $totalRecords];
}

function getAllFilesGlobal(PDO $pdo,int $page,int $per):array{
    try {
        $countQuery = 'SELECT COUNT(DISTINCT pe.mrn, pe.visit_number) FROM patient_emr pe JOIN patients p ON p.mrn COLLATE utf8mb4_unicode_ci = pe.mrn';
        $count = (int)$pdo->query($countQuery)->fetchColumn();
    } catch (PDOException $ex) { return ['error' => 'Could not count all files.']; }

    [$totalPages,$currentPage,$offset] = paginate($count,$page,$per);

    try {
        $sql = 'SELECT pe.mrn AS emr_mrn, pe.visit_number AS emr_visit_number, pe.created_at, p.full_name AS patient_name FROM patient_emr pe JOIN patients p ON p.mrn COLLATE utf8mb4_unicode_ci = pe.mrn ORDER BY pe.created_at DESC LIMIT :lim OFFSET :off';
        $st = $pdo->prepare($sql);
        $st->bindValue(':lim',$per,PDO::PARAM_INT); $st->bindValue(':off',$offset,PDO::PARAM_INT);
        $st->execute();
        $files = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) { return ['error' => 'Could not retrieve all files.']; }
    
    return ['files' => $files, 'totalPages' => $totalPages, 'currentPage' => $currentPage, 'totalRecords' => $count];
}

function retrieveFileByMrnAndVisitNumber(PDO $pdo, string $mrnToRetrieve, int $visitNumberToRetrieve){
    try {
        $st = $pdo->prepare('SELECT * FROM patient_emr WHERE mrn = :mrn AND visit_number = :visit_num');
        $st->execute([':mrn' => $mrnToRetrieve, ':visit_num' => $visitNumberToRetrieve]);
        return $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) { error_log('files.php retrieveFileByMrnAndVisitNumber PDOException: '. $ex->getMessage()); return null; }
}

// --- ACTION HANDLING ---
$searchResults = []; $patientFiles = []; $allFilesGlobalData = [];

if($patient && $action==='open' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (empty($_POST['mrn']) || $_POST['mrn'] !== $patient['mrn']) {
        $feedback_message = 'MRN mismatch or missing in submission.'; $feedback_type = 'error';
    } elseif (empty($_POST['visit_number']) || !filter_var($_POST['visit_number'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
        $feedback_message = 'Visit number is missing or invalid.'; $feedback_type = 'error';
    } else {
        if(openNewFile($pdo,$_POST, (int)$currentUserId)){ 
            $feedback_message = 'File saved for MRN '.htmlspecialchars($patient['mrn']).' (Visit '.$_POST['visit_number'].')'; $feedback_type = 'success';
        }else{
            $feedback_message = 'Error – file could not be saved.'; $feedback_type = 'error';
        }
    }
}

if($patient && $action==='search'){
    $currentPageSearch = (int)($_GET['page'] ?? 1);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $term = trim($_POST['search_term'] ?? '');
        if($term===''){
            $feedback_message = 'Enter something to search.'; $feedback_type = 'error';
        }else{
            $searchResults = searchFilesByTerm($pdo,$patient['mrn'],$term,$currentPageSearch,$perPage);
            if(isset($searchResults['error'])) { $feedback_message = $searchResults['error']; $feedback_type = 'error'; }
            elseif(empty($searchResults['files'])) {
                $feedback_message = 'No files found matching your search term: ' . htmlspecialchars($term); $feedback_type = 'info';
            }
        }
    }
}

if($patient && $action==='show_patient_files'){
    $currentPagePatient = (int)($_GET['page'] ?? 1);
    $patientFiles = getAllFilesForPatient($pdo,$patient['mrn'],$currentPagePatient,$perPage);
    if(isset($patientFiles['error'])) { $feedback_message = $patientFiles['error']; $feedback_type = 'error'; }
    elseif(empty($patientFiles['files'])) {
        $feedback_message = 'No files found for MRN: ' . htmlspecialchars($patient['mrn']); $feedback_type = 'info';
    }
}

if($action==='view_all_global'){
    $pageTitle = 'All Patient Files';
    $currentPageGlobal = (int)($_GET['page'] ?? 1);
    $allFilesGlobalData = getAllFilesGlobal($pdo,$currentPageGlobal,$perPage);
    if(isset($allFilesGlobalData['error'])) { $feedback_message = $allFilesGlobalData['error']; $feedback_type = 'error'; }
    elseif(empty($allFilesGlobalData['files'])) {
        $feedback_message = 'No files found in the system.'; $feedback_type = 'info';
    }
}

if (!function_exists('generatePaginationLinks')) {
    function generatePaginationLinks($currentPage, $totalPages, $baseUrl, $pageParam = 'page') {
        if ($totalPages <= 1) return '';
        $html = '<ul class="pagination">';
        $disabledPrev = ($currentPage <= 1) ? 'disabled' : '';
        $html .= "<li class=\"waves-effect {$disabledPrev}\"><a href=\"{$baseUrl}&{$pageParam}=" . ($currentPage - 1) . "\"><i class=\"material-icons\">chevron_left</i></a></li>";

        for ($i = 1; $i <= $totalPages; $i++) {
            $active = ($i == $currentPage) ? 'active' : '';
            $html .= "<li class=\"waves-effect {$active}\" style=\"background-color: " . ($active ? '#00bfa5' : 'transparent') . ";\"><a href=\"{$baseUrl}&{$pageParam}={$i}\">{$i}</a></li>";
        }
        
        $disabledNext = ($currentPage >= $totalPages) ? 'disabled' : '';
        $html .= "<li class=\"waves-effect {$disabledNext}\"><a href=\"{$baseUrl}&{$pageParam}=" . ($currentPage + 1) . "\"><i class=\"material-icons\">chevron_right</i></a></li>";
        $html .= '</ul>';
        return $html;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>hospital0 - <?= htmlspecialchars($pageTitle); ?></title>
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
        h3, h4, h5 { font-weight: 300; text-shadow: 0 0 8px rgba(0, 229, 255, 0.5); }
        .white-line { width: 50%; background: rgba(255,255,255,0.3); height: 1px; border: none; margin: 20px auto 40px auto; }
        .container { max-width: 1400px; width: 95%; }
        
        .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 15px; padding: 1.5rem; margin-top: 1.5rem; }
        
        .message-area { padding: 10px 15px; margin-bottom: 20px; border-radius: 8px; text-align: center; border: 1px solid; }
        .message-area.success { background-color: rgba(76, 175, 80, 0.25); color: #c8e6c9; border-color: rgba(129, 199, 132, 0.5); }
        .message-area.error { background-color: rgba(244, 67, 54, 0.25); color: #ffcdd2; border-color: rgba(239, 154, 154, 0.5); }
        .message-area.info { background-color: rgba(3, 169, 244, 0.25); color: #b3e5fc; border-color: rgba(79, 195, 247, 0.5); }
        
        table.striped>tbody>tr:nth-child(odd) { background-color: rgba(255, 255, 255, 0.05); }
        th { border-bottom: 1px solid rgba(255, 255, 255, 0.3); } td, th { padding: 15px 10px; }
        .pagination li a { color: #fff; } .pagination li.active { background-color: #00bfa5; } .pagination li.disabled a { color: #757575; }

        .input-field input, .input-field .select-dropdown, textarea.materialize-textarea { color: #fff !important; border-bottom: 1px solid rgba(255, 255, 255, 0.5) !important; box-shadow: none !important; }
        .input-field input[readonly] { color: #bdbdbd !important; -webkit-text-fill-color: #bdbdbd !important; }
        .input-field label { color: #bdbdbd !important; } .input-field label.active { color: #00e5ff !important; }
        .input-field input:focus, .input-field textarea:focus { border-bottom: 1px solid #00e5ff !important; box-shadow: 0 1px 0 0 #00e5ff !important; }
        ul.dropdown-content { background-color: #2a2a2a; } .dropdown-content li>span { color: #fff !important; }
        [type="checkbox"]+span:not(.lever) { color: #fff; } [type="checkbox"]:checked+span:not(.lever):before { border-color: transparent #00bfa5 #00bfa5 transparent; }
        [type="radio"]+span { color: #fff; } [type="radio"]:checked+span:after, [type="radio"].with-gap:checked+span:after { background-color: #00bfa5; } [type="radio"]:checked+span:after, [type="radio"].with-gap:checked+span:before, [type="radio"].with-gap:checked+span:after { border: 2px solid #00bfa5; }

        .overlay-sidebar { height: 100%; width: 0; position: fixed; z-index: 9999; top: 0; right: 0; background-color: rgba(20, 20, 20, 0.95); backdrop-filter: blur(5px); overflow-x: hidden; transition: 0.3s; padding-top: 60px; }
        .overlay-sidebar a { padding: 8px 8px 8px 32px; text-decoration: none; font-size: 1.1em; color: #bdbdbd; display: block; transition: 0.2s; }
        .overlay-sidebar a:hover { color: #00e5ff; background-color: rgba(0, 229, 255, 0.1); } 
        .hamburger-icon { font-size: 1.5em; cursor: pointer; margin-left: 10px; vertical-align: middle;}
        
        .bordered-section { border: 1px solid rgba(255, 255, 255, 0.2); padding: 20px; margin: 20px 0; border-radius: 10px; }
        .signature-box { border: 1px dashed #fff; width: 100%; max-width:400px; height: 200px; background: rgba(0,0,0,0.2); margin-top: 10px; display:flex; align-items:center; justify-content:center; border-radius:5px; }
        .signature-box img { max-width:100%; max-height:100%; object-fit:contain; }
        
        #signatureModal { display: none; position: fixed; z-index: 1005; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.8); backdrop-filter: blur(5px); justify-content: center; align-items: center; }
        #signatureModal .modal-content-custom { background-color: #2a2a2a; width: 450px; max-width: 90%; padding: 20px; border-radius: 15px; border: 1px solid rgba(255,255,255,0.2); }
        #signatureCanvas { border: 2px solid #fff; background-color: #fff; cursor: crosshair; margin-bottom: 10px; width:100%; }
        .main-menu-button { width: 90%; max-width: 450px; margin-bottom: 15px; height: 54px; }
    </style>
</head>
<body>

<canvas id="dna-canvas"></canvas>
<div class="stars"></div>
<div class="twinkling"></div>

<?php include_once __DIR__ . '/includes/header.php'; ?>
<main class="container">
  <h3 class="center-align white-text"><?= htmlspecialchars($pageTitle); ?></h3>

  <?php if ($feedback_message): ?>
    <div class="message-area <?= htmlspecialchars($feedback_type); ?>"><?= htmlspecialchars($feedback_message); ?></div>
  <?php endif; ?>

  <?php if (!$patient && !$mrn && !in_array($action, ['view_all_global', 'prompt_mrn'])): // --- MAIN MENU --- ?>
    <div class="glass-card center-align" style="margin-top:40px;">
        <a href="?action=prompt_mrn&intent=open" class="btn-large waves-effect waves-light main-menu-button" style="background-color:#00bfa5;"><i class="material-icons left">create</i>Create or Open New File</a><br>
        <a href="?action=prompt_mrn&intent=search" class="btn-large waves-effect waves-light main-menu-button" style="background-color:#00bfa5;"><i class="material-icons left">search</i>Retrieve Patient's File</a><br>
        <a href="?action=view_all_global" class="btn-large waves-effect waves-light grey main-menu-button"><i class="material-icons left">list_alt</i>View All Files (System-Wide)</a>
    </div>
  <?php endif; ?>

  <?php if ($action === 'prompt_mrn' && !$patient): // --- MRN PROMPT --- ?>
    <div class="row"><div class="col s12 m8 offset-m2 l6 offset-l3">
        <div class="glass-card">
            <h5 class="center-align white-text">Enter Patient MRN</h5>
            <p class="center-align grey-text text-lighten-1">Please provide the Medical Record Number to <?= htmlspecialchars(str_replace('_', ' ', $intent)); ?>.</p>
            <form method="GET" action="files.php">
                <input type="hidden" name="intent" value="<?= htmlspecialchars($intent); ?>">
                <input type="hidden" name="action_after_mrn" value="<?= htmlspecialchars($intent); ?>">
                <div class="input-field">
                    <i class="material-icons prefix">account_circle</i>
                    <input id="mrn_input_prompt" name="mrn" type="text" class="validate" required autofocus>
                    <label for="mrn_input_prompt">Medical Record Number (MRN)</label>
                </div>
                <div class="center-align" style="margin-top: 20px;">
                    <button type="submit" class="btn waves-effect waves-light" style="background-color:#00bfa5;"><i class="material-icons right">arrow_forward</i>Fetch Patient</button>
                    <a href="files.php" class="btn waves-effect waves-light grey" style="margin-left: 10px;">Cancel</a>
                </div>
            </form>
        </div>
    </div></div>
  <?php endif; ?>

  <?php if ($action === 'view_all_global' && !empty($allFilesGlobalData['files'])): // --- GLOBAL FILE LIST --- ?>
    <div class="glass-card">
        <table class="striped responsive-table highlight white-text">
            <thead><tr><th>MRN</th><th>Visit</th><th>Patient Name</th><th>File Creation</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($allFilesGlobalData['files'] as $file): ?>
                <tr>
                    <td><?= htmlspecialchars($file['emr_mrn']); ?></td>
                    <td><?= htmlspecialchars($file['emr_visit_number']); ?></td>
                    <td><?= htmlspecialchars($file['patient_name']); ?></td>
                    <td><?= htmlspecialchars(date("d M Y, h:i A", strtotime($file['created_at']))); ?></td>
                    <td><a href="files.php?mrn=<?= htmlspecialchars($file['emr_mrn']); ?>&action=open&emr_visit_number=<?= htmlspecialchars($file['emr_visit_number']); ?>" class="btn-small waves-effect" style="background-color:#00bfa5;">View/Edit</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?= generatePaginationLinks($allFilesGlobalData['currentPage'], $allFilesGlobalData['totalPages'], "?action=view_all_global"); ?>
        <div class="center-align" style="margin-top: 20px;"><a href="files.php" class="btn waves-effect waves-light grey"><i class="material-icons left">arrow_back</i>Back to Main Menu</a></div>
    </div>
  <?php endif; ?>

  <?php if ($patient): // --- PATIENT-SPECIFIC VIEWS --- ?>
    
    <?php if (!in_array($action, ['open', 'search', 'show_patient_files']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && $action ==='open') ): ?>
        <div class="glass-card center-align">
            <p class="white-text" style="font-size:1.2rem;"><strong>Patient:</strong> <?= htmlspecialchars($patient['full_name']); ?> (MRN: <?= htmlspecialchars($patient['mrn']); ?>)</p>
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action ==='open' && $feedback_type === 'success') : ?>
                <a href="files.php?mrn=<?= htmlspecialchars($patient['mrn']); ?>" class="btn waves-effect waves-light main-menu-button" style="background-color:#00bfa5;">Patient Menu (<?= htmlspecialchars($patient['mrn']); ?>)</a><br>
                <a href="files.php" class="btn grey waves-effect main-menu-button">EMR Home</a>
            <?php else : ?>
                <h5 class="white-text">Patient Actions</h5>
                <a href="?mrn=<?= htmlspecialchars($patient['mrn']); ?>&action=open" class="btn waves-effect main-menu-button" style="background-color:#00bfa5;"><i class="material-icons left">add_circle_outline</i>Create New File (Visit <?= htmlspecialchars($visitNumber); ?>)</a><br>
                <a href="?mrn=<?= htmlspecialchars($patient['mrn']); ?>&action=search" class="btn waves-effect main-menu-button" style="background-color:#00bfa5;"><i class="material-icons left">find_in_page</i>Retrieve Existing File</a><br>
                <a href="?mrn=<?= htmlspecialchars($patient['mrn']); ?>&action=show_patient_files" class="btn waves-effect main-menu-button" style="background-color:#00bfa5;"><i class="material-icons left">folder_shared</i>View All Patient Files</a><br>
                <a href="files.php" class="btn grey waves-effect main-menu-button" style="margin-top:10px;"><i class="material-icons left">arrow_back</i> EMR Main Menu</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php
    $emrData = null;
    if ($action === 'open' && $emr_visit_number_get !== null && $patient) {
        $emrData = retrieveFileByMrnAndVisitNumber($pdo, $patient['mrn'], $emr_visit_number_get);
        if ($emrData) {
            $visitNumber = $emrData['visit_number'];
            $jsonFields = ['systemic_review_general', 'systemic_review_cv', 'systemic_review_resp', 'systemic_review_gi', 'systemic_review_gu', 'systemic_review_neuro', 'systemic_review_psych', 'tests_ordered', 'past_medical_history', 'current_medications', 'consents', 'doctors_progress_notes', 'nurses_progress_notes'];
            foreach ($jsonFields as $field) {
                $emrData[$field] = !empty($emrData[$field]) ? json_decode($emrData[$field], true) : [];
            }
        } else {
            if ($emr_visit_number_get !== null) {
                $feedback_message = "EMR File for Visit " . htmlspecialchars($emr_visit_number_get) . " not found. Displaying form for a new entry.";
                $feedback_type = 'info';
            }
        }
    }
    ?>

    <?php if ($action === 'open' && $_SERVER['REQUEST_METHOD'] === 'GET'): // --- EMR FORM --- ?>
      <div id="mySidebar" class="overlay-sidebar">
          <a href="#demographics" onclick="toggleSidebar()">1. Demographics</a>
          <a href="#presenting" onclick="toggleSidebar()">2. Presenting Complaints</a>
          <a href="#systemic_review" onclick="toggleSidebar()">3. Systemic Review</a>
          <a href="#examination_findings" onclick="toggleSidebar()">4. Examination Findings</a>
          <a href="#focused_examination" onclick="toggleSidebar()">5. Focused Examination</a>
          <a href="#tests_ordered" onclick="toggleSidebar()">6. Tests Ordered</a>
          <a href="#medical_history" onclick="toggleSidebar()">7. Medical History</a>
          <a href="#diagnosis" onclick="toggleSidebar()">8. Diagnosis & Differentials</a>
          <a href="#drugchat" onclick="toggleSidebar()">9. Drug Chart</a>
          <a href="#consents" onclick="toggleSidebar()">10. Consents</a>
          <a href="#docnotes" onclick="toggleSidebar()">11. Doctors' Notes</a>
          <a href="#nursenotes" onclick="toggleSidebar()">12. Nurses' Notes</a>
          <a href="#consultations" onclick="toggleSidebar()">13. Consultations</a>
          <a href="#tests" onclick="toggleSidebar()">14. Test Results</a>
          <a href="#vitals" onclick="toggleSidebar()">15. Vitals Monitoring</a>
          <a href="#anaesthesia" onclick="toggleSidebar()">16. Anaesthesia/Surgeries</a>
          <a href="#discharge" onclick="toggleSidebar()">17. Discharge</a>
      </div>
      
      <div class="glass-card">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap;">
            <h4 class="white-text"><?= ($emrData && $emr_visit_number_get !== null) ? 'Edit' : 'Create New'; ?> File</h4>
            <span class="hamburger-icon white-text" onclick="toggleSidebar()">&#9776; SECTIONS</span>
        </div>
        <p><strong>Patient:</strong> <?= htmlspecialchars($patient['full_name']); ?> | <strong>MRN:</strong> <?= htmlspecialchars($patient['mrn']); ?> | <strong>Visit:</strong> <?= htmlspecialchars($visitNumber); ?></p>
        <hr style="background-color: rgba(255,255,255,0.2); border:0; height:1px; margin-bottom: 2rem;">
      
      <form method="POST" action="?mrn=<?= htmlspecialchars($patient['mrn']); ?>&action=open<?= ($emrData) ? '&emr_visit_number='.$emrData['visit_number'] : ''; ?>">
        <input type="hidden" name="mrn" value="<?= htmlspecialchars($patient['mrn']); ?>">
        <input type="hidden" name="visit_number" value="<?= htmlspecialchars($visitNumber); ?>">
        
        <section id="demographics" class="bordered-section"><h5>1. Demographics</h5><div class="row"><div class="input-field col s12 m6 l3"><input value="<?= htmlspecialchars($patient['mrn']); ?>" readonly><label class="active">MRN</label></div><div class="input-field col s12 m6 l3"><input value="<?= htmlspecialchars($patient['full_name']); ?>" readonly><label class="active">Name</label></div><div class="input-field col s12 m6 l3"><input value="<?= htmlspecialchars($patient['gender']); ?>" readonly><label class="active">Gender</label></div><div class="input-field col s12 m6 l3"><input value="<?= htmlspecialchars($patient['phone']); ?>" readonly><label class="active">Phone</label></div><div class="input-field col s12"><input value="<?= htmlspecialchars($patient['address']); ?>" readonly><label class="active">Address</label></div></div></section>
        <section id="presenting" class="bordered-section"><h5>2. Presenting Complaints</h5><div class="input-field"><textarea id="f_presenting_complaints" name="presenting_complaints" class="materialize-textarea" maxlength="512"><?= htmlspecialchars($emrData['presenting_complaints'] ?? ''); ?></textarea><label for="f_presenting_complaints">Presenting Complaints (Max 512 chars)</label></div></section>
        
        <section id="systemic_review" class="bordered-section">
            <h5>3. Systemic Review</h5>
            <?php $systemic_options = ['general' => ['Weight loss','Rashes','Joint Pains','Fever','Fatigue'],'cv' => ['Chest pain','Palpitations','Dyspnoea','Pedal Oedema','Orthopnoea'],'resp' => ['Cough','Dyspnoea','Wheeze','Sputum','Haemoptysis'],'gi' => ['Abdominal Pain','Altered Bowel Habits','Heartburn','Malaena','Nausea/Vomiting','Jaundice'],'gu' => ['Discharge','Dysuria','Frequency','Haematuria','Incontinence'],'neuro' => ['Numbness','Weakness','Vision Changes','Headache','Seizures','Dizziness'],'psych' => ['Depression','Anxiety','Insomnia','Hallucinations','Mood swings']]; $system_map = ['general' => 'General','cv' => 'Cardiovascular','resp' => 'Respiratory','gi' => 'Gastrointestinal','gu' => 'Genitourinary','neuro' => 'Neurological','psych' => 'Psychiatric']; ?>
            <div class="row">
            <?php foreach ($system_map as $key => $displayName): ?>
                <div class="col s12 m6 l4"><h6><?= $displayName; ?></h6>
                <?php foreach ($systemic_options[$key] as $option): $fieldName = 'systemic_review_'.$key; $decodedValue = $emrData[$fieldName] ?? []; $isChecked = in_array($option, is_array($decodedValue) ? $decodedValue : []); ?>
                    <p><label><input type="checkbox" name="<?= $fieldName; ?>[]" value="<?= htmlspecialchars($option); ?>" <?= $isChecked ? 'checked' : ''; ?>/><span><?= htmlspecialchars($option); ?></span></label></p>
                <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </section>

        <section id="examination_findings" class="bordered-section"><h5>4. Examination Findings</h5><div class="input-field"><textarea id="f_examination_findings_text" name="examination_findings_text" class="materialize-textarea" maxlength="512" placeholder="Core Examination..."><?= htmlspecialchars($emrData['examination_findings_text'] ?? ''); ?></textarea><label for="f_examination_findings_text">Examination Findings (Max 512 chars)</label></div></section>
        <section id="focused_examination" class="bordered-section"><h5>5. Focused Examination Findings</h5><div class="input-field"><textarea id="f_focused_examination_text" name="focused_examination_text" class="materialize-textarea" maxlength="512" placeholder="Findings of focused clinical exam..."><?= htmlspecialchars($emrData['focused_examination_text'] ?? ''); ?></textarea><label for="f_focused_examination_text">Focused Examination Findings (Max 512 chars)</label></div></section>
        
        <section id="tests_ordered" class="bordered-section"><h5>6. Tests Ordered</h5><div id="testsOrderedContainer"><?php $tests_ordered_data = $emrData['tests_ordered'] ?? []; if(!empty($tests_ordered_data)): foreach($tests_ordered_data as $idx => $test):?><div class="row" id="test_row_<?= $idx; ?>"><div class="input-field col s10"><input type="text" name="tests_ordered[]" id="test_ordered_<?= $idx; ?>" value="<?= htmlspecialchars($test); ?>"><label for="test_ordered_<?= $idx; ?>" class="active">Test #<?= $idx + 1; ?></label></div><div class="col s2"><button type="button" class="btn-floating red" style="margin-top:20px;" onclick="this.closest('.row').remove()"><i class="material-icons">remove</i></button></div></div><?php endforeach; endif;?></div><button type="button" class="btn" onclick="addTestField()">Add Test</button></section>
        
        <section id="medical_history" class="bordered-section"><h5>7. Medical History</h5><h6>Past Medical History</h6><div id="pastMedicalHistoryContainer"><?php $past_medical_history_data = $emrData['past_medical_history'] ?? []; if (!empty($past_medical_history_data)): foreach ($past_medical_history_data as $idx => $condition):?><div class="row" id="disease_row_<?= $idx; ?>"><div class="input-field col s10"><input type="text" name="past_medical_history[]" id="disease_<?= $idx; ?>" value="<?= htmlspecialchars($condition); ?>"><label for="disease_<?= $idx; ?>" class="active">Condition #<?= $idx + 1; ?></label></div><div class="col s2"><button type="button" class="btn-floating red" style="margin-top:20px;" onclick="this.closest('.row').remove()"><i class="material-icons">remove</i></button></div></div><?php endforeach; endif;?></div><button type="button" class="btn" onclick="addDiseaseField()">Add Condition</button><h6>Current Medications</h6><div id="currentMedicationsContainer"><?php $current_medications_data = $emrData['current_medications'] ?? []; if (!empty($current_medications_data)): foreach ($current_medications_data as $idx => $medication):?><div class="row" id="medication_row_<?= $idx; ?>"><div class="input-field col s10"><input type="text" name="current_medications[]" id="medication_<?= $idx; ?>" value="<?= htmlspecialchars($medication); ?>"><label for="medication_<?= $idx; ?>" class="active">Medication #<?= $idx + 1; ?></label></div><div class="col s2"><button type="button" class="btn-floating red" style="margin-top:20px;" onclick="this.closest('.row').remove()"><i class="material-icons">remove</i></button></div></div><?php endforeach; endif;?></div><button type="button" class="btn" onclick="addMedicationField()">Add Medication</button><h6>Allergies</h6><p><label><input name="allergies_option" type="radio" value="No Known" onclick="toggleAllergyTextbox(false)" <?= (($emrData['allergies_option'] ?? '') === 'No Known') ? 'checked' : ''; ?> /><span>No Known Allergies</span></label></p><p><label><input name="allergies_option" type="radio" value="Known" onclick="toggleAllergyTextbox(true)" <?= (($emrData['allergies_option'] ?? '') === 'Known') ? 'checked' : ''; ?> /><span>Known Allergies</span></label></p><p><label><input name="allergies_option" type="radio" value="Not Asked" onclick="toggleAllergyTextbox(false)" <?= (($emrData['allergies_option'] ?? 'Not Asked') === 'Not Asked' || empty($emrData['allergies_option'])) ? 'checked' : ''; ?> /><span>Not Asked / Deferred</span></label></p><div class="input-field"><textarea id="f_allergy_details" name="allergy_details" class="materialize-textarea" maxlength="128" style="<?= (($emrData['allergies_option'] ?? '') === 'Known') ? 'display:block;' : 'display:none;'; ?>"><?= htmlspecialchars($emrData['allergy_details'] ?? ''); ?></textarea><label for="f_allergy_details" style="<?= (($emrData['allergies_option'] ?? '') === 'Known') ? 'display:block;' : 'display:none;'; ?>" class="<?= !empty($emrData['allergy_details']) ? 'active' : ''; ?>">Describe Allergies</label></div></section>
        
        <section id="diagnosis" class="bordered-section"><h5>8. Diagnosis & Differentials</h5><div class="input-field"><input type="text" id="f_working_diagnosis" name="working_diagnosis" maxlength="64" value="<?= htmlspecialchars($emrData['working_diagnosis'] ?? ''); ?>"><label for="f_working_diagnosis" class="<?= !empty($emrData['working_diagnosis']) ? 'active' : ''; ?>">Working Diagnosis</label></div><div class="input-field"><textarea id="f_differentials" name="differentials" class="materialize-textarea" maxlength="128"><?= htmlspecialchars($emrData['differentials'] ?? ''); ?></textarea><label for="f_differentials">Differentials</label></div></section>
        <section id="drugchat" class="bordered-section"><h5>9. Drug Chart</h5><div class="input-field"><textarea id="f_drug_chart" name="drug_chart" class="materialize-textarea"><?= htmlspecialchars($emrData['drug_chart'] ?? ''); ?></textarea><label for="f_drug_chart">Drug Orders & Chart</label></div></section>
        
        <section id="consents" class="bordered-section"><h5>10. Consents</h5><?php $consents_data = $emrData['consents'] ?? []; if(empty($consents_data)) { $consents_data = [['given_by'=>'','relation'=>'','cnic'=>'','signature'=>null]]; } ?><?php foreach($consents_data as $idx => $consent): $consentBoxId = 'consentBox_'.$idx;?><div class="bordered-section" id="<?= $consentBoxId; ?>"><h6 class="white-text"><?= $idx === 0 ? 'Informed Consent for Admission' : 'Additional Consent'; ?></h6><div class="row"><div class="input-field col s12 m6"><input type="text" id="consent_given_by_<?= $idx; ?>" name="consent_given_by[]" value="<?= htmlspecialchars($consent['given_by'] ?? ''); ?>"><label for="consent_given_by_<?= $idx; ?>">Consent Given By</label></div><div class="input-field col s12 m3"><select id="relation_<?= $idx; ?>" name="relation[]"><?php $relOpts = ['Husband','Wife','Father','Mother','Son','Daughter','Relative', 'Self']; foreach($relOpts as $rOpt):?><option value="<?= $rOpt;?>" <?= (($consent['relation']??'')===$rOpt)?'selected':'';?>><?= $rOpt;?></option><?php endforeach;?></select><label>Relation</label></div><div class="input-field col s12 m3"><input type="text" id="cnic_<?= $idx; ?>" name="cnic[]" value="<?= htmlspecialchars($consent['cnic'] ?? ''); ?>"><label for="cnic_<?= $idx; ?>">CNIC Number</label></div></div><div class="row" style="margin-top: 10px;"><div class="col s12 m4"><button type="button" class="btn" onclick="openSignatureModal('consentSignature_<?= $idx; ?>')">Add Signature</button></div><div class="col s12 m8"><input type="hidden" id="consentSignature_<?= $idx; ?>" name="signature_data[]" value="<?= htmlspecialchars($consent['signature'] ?? ''); ?>"><div class="signature-box" id="sigPreview_consentSignature_<?= $idx; ?>"><?php if(!empty($consent['signature'])):?><img src="<?= htmlspecialchars( (strpos($consent['signature'], 'signatures/') === 0 ? '/'.$consent['signature'] : $consent['signature']) ); ?>" alt="Signature"><?php endif;?></div></div></div><?php if ($idx > 0): ?><button type="button" class="btn red" style="margin-top:10px;" onclick="this.closest('.bordered-section').remove()">Remove Consent</button><?php endif; ?></div><?php endforeach; ?><div id="additionalConsentsContainer"></div><button type="button" class="btn" onclick="addConsentBox()">Add Consent</button></section>
        
        <section id="docnotes" class="bordered-section"><h5>11. Doctors' Progress Notes</h5><div id="doctorsProgressNotesContainer"><?php $notes_data = $emrData['doctors_progress_notes'] ?? []; if(!empty($notes_data)): foreach($notes_data as $key => $note):?><div class="bordered-section" id="doctor_note_box_<?= $key;?>"><h6>By <?= htmlspecialchars($note['author']??'N/A'); ?> at <?= htmlspecialchars(isset($note['timestamp']) ? date('d M Y, H:i', strtotime($note['timestamp'])):'N/A');?> <button type="button" class="btn-floating red right" onclick="this.closest('.bordered-section').remove()"><i class="material-icons">remove</i></button></h6><textarea class="materialize-textarea" name="doctors_progress_notes[<?= $key;?>][note]"><?= htmlspecialchars($note['note']??'');?></textarea><input type="hidden" name="doctors_progress_notes[<?= $key;?>][timestamp]" value="<?= htmlspecialchars($note['timestamp']??'');?>"><input type="hidden" name="doctors_progress_notes[<?= $key;?>][author]" value="<?= htmlspecialchars($note['author']??'');?>"></div><?php endforeach; endif; ?></div><button type="button" class="btn" onclick="addDoctorNote()">Add Doctor's Note</button></section>
        
        <section id="nursenotes" class="bordered-section"><h5>12. Nurses' Progress Notes</h5><div id="nursesProgressNotesContainer"><?php $notes_data = $emrData['nurses_progress_notes'] ?? []; if(!empty($notes_data)): foreach($notes_data as $key => $note):?><div class="bordered-section" id="nurse_note_box_<?= $key;?>"><h6>By <?= htmlspecialchars($note['author']??'N/A'); ?> at <?= htmlspecialchars(isset($note['timestamp']) ? date('d M Y, H:i', strtotime($note['timestamp'])):'N/A');?> <button type="button" class="btn-floating red right" onclick="this.closest('.bordered-section').remove()"><i class="material-icons">remove</i></button></h6><textarea class="materialize-textarea" name="nurses_progress_notes[<?= $key;?>][note]"><?= htmlspecialchars($note['note']??'');?></textarea><input type="hidden" name="nurses_progress_notes[<?= $key;?>][timestamp]" value="<?= htmlspecialchars($note['timestamp']??'');?>"><input type="hidden" name="nurses_progress_notes[<?= $key;?>][author]" value="<?= htmlspecialchars($note['author']??'');?>"></div><?php endforeach; endif; ?></div><button type="button" class="btn" onclick="addNurseNote()">Add Nurse's Note</button></section>

        <section id="consultations" class="bordered-section"><h5>13. Consultations</h5><div class="input-field"><textarea name="consultations_text" class="materialize-textarea"><?= htmlspecialchars($emrData['consultations_text'] ?? ''); ?></textarea><label>Consultation Details</label></div></section>
        <section id="tests" class="bordered-section"><h5>14. Test Results</h5><div class="input-field"><textarea name="tests_text" class="materialize-textarea"><?= htmlspecialchars($emrData['tests_text'] ?? ''); ?></textarea><label>Test Results Summary</label></div></section>
        <section id="vitals" class="bordered-section"><h5>15. Vitals Monitoring</h5><div class="input-field"><textarea name="vitals_text" class="materialize-textarea"><?= htmlspecialchars($emrData['vitals_text'] ?? ''); ?></textarea><label>Vitals Log / Chart Notes</label></div></section>
        <section id="anaesthesia" class="bordered-section"><h5>16. Anaesthesia & Surgeries</h5><div class="input-field"><textarea name="anaesthesia_text" class="materialize-textarea"><?= htmlspecialchars($emrData['anaesthesia_text'] ?? ''); ?></textarea><label>Anaesthesia Records & Surgery Notes</label></div></section>
        <section id="discharge" class="bordered-section"><h5>17. Discharge</h5><div><?php $dtOpts = ["Active/In-progress", "Discharge", "Discharge on Request", "Leaving Against Medical Advice", "Refer", "Expired"]; $currentDischargeType = $emrData['discharge_type'] ?? 'Active/In-progress'; foreach ($dtOpts as $dtOpt):?><p><label><input name="discharge_type" type="radio" value="<?= $dtOpt;?>" <?= ($currentDischargeType === $dtOpt) ? 'checked' : '';?>><span><?= $dtOpt;?></span></label></p><?php endforeach;?></div><div class="input-field"><textarea name="discharge_details" class="materialize-textarea" maxlength="1024" placeholder="Discharge Summary..."><?= htmlspecialchars($emrData['discharge_details'] ?? ''); ?></textarea><label>Discharge Summary & Instructions</label></div></section>
        
        <div class="center-align" style="margin-top:30px;"><button type="submit" class="btn-large waves-effect waves-light green"><i class="material-icons right">save</i>Save File</button><a href="?mrn=<?= htmlspecialchars($patient['mrn']); ?>" class="btn-large waves-effect waves-light grey" style="margin-left: 10px;">Cancel</a></div>
      </form>
      </div>
    <?php endif; ?>

    <?php if ($action === 'search'): // --- SEARCH VIEW --- ?>
        <div class="glass-card">
            <h5 class="white-text">Retrieve File for MRN: <?= htmlspecialchars($patient['mrn']); ?></h5>
            <form method="POST" action="?mrn=<?= htmlspecialchars($patient['mrn']); ?>&action=search&page=1">
                <div class="input-field"><input id="s_search_term" name="search_term" type="text" required><label for="s_search_term">Search Term (in complaints, diagnosis...)</label></div>
                <div class="center-align"><button type="submit" class="btn waves-effect" style="background-color:#00bfa5;"><i class="material-icons right">search</i>Search</button><a href="?mrn=<?= htmlspecialchars($patient['mrn']); ?>" class="btn grey waves-effect" style="margin-left:10px;">Cancel</a></div>
            </form>
            <?php if (!empty($searchResults['files'])): ?>
                <h5 class="white-text" style="margin-top:30px;">Search Results for "<?= htmlspecialchars($_POST['search_term'] ?? ''); ?>"</h5>
                <table class="striped responsive-table highlight white-text"><thead><tr><th>Visit</th><th>Diagnosis</th><th>Created</th><th>Action</th></tr></thead><tbody><?php foreach($searchResults['files'] as $file): ?><tr><td><?= htmlspecialchars($file['visit_number']); ?></td><td><?= htmlspecialchars($file['working_diagnosis'] ?? 'N/A'); ?></td><td><?= htmlspecialchars(date("d M Y", strtotime($file['created_at']))); ?></td><td><a href="?mrn=<?= htmlspecialchars($file['mrn']); ?>&action=open&emr_visit_number=<?= $file['visit_number']; ?>" class="btn-small" style="background-color:#00bfa5;">View/Edit</a></td></tr><?php endforeach; ?></tbody></table>
                <?= generatePaginationLinks($searchResults['currentPage'], $searchResults['totalPages'], "?mrn=".htmlspecialchars($patient['mrn'])."&action=search&search_term=".urlencode($_POST['search_term']??'')); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'show_patient_files' && !empty($patientFiles['files'])): // --- PATIENT FILE LIST --- ?>
        <div class="glass-card">
            <h5 class="white-text">All Files for MRN: <?= htmlspecialchars($patient['mrn']); ?></h5>
            <table class="striped responsive-table highlight white-text"><thead><tr><th>Visit</th><th>Diagnosis</th><th>Created</th><th>Action</th></tr></thead><tbody><?php foreach($patientFiles['files'] as $file): ?><tr><td><?= htmlspecialchars($file['visit_number']); ?></td><td><?= htmlspecialchars($file['working_diagnosis'] ?? 'N/A'); ?></td><td><?= htmlspecialchars(date("d M Y", strtotime($file['created_at']))); ?></td><td><a href="?mrn=<?= htmlspecialchars($file['mrn']); ?>&action=open&emr_visit_number=<?= $file['visit_number']; ?>" class="btn-small" style="background-color:#00bfa5;">View/Edit</a></td></tr><?php endforeach; ?></tbody></table>
            <?= generatePaginationLinks($patientFiles['currentPage'], $patientFiles['totalPages'], "?mrn=".htmlspecialchars($patient['mrn'])."&action=show_patient_files"); ?>
            <div class="center-align" style="margin-top:20px;"><a href="?mrn=<?= htmlspecialchars($patient['mrn']); ?>" class="btn grey waves-effect">Back to Patient Menu</a></div>
        </div>
    <?php endif; ?>

  <?php endif; ?>
</main>

<div id="signatureModal"><div class="modal-content-custom"><h5 class="white-text">Add Signature</h5><canvas id="signatureCanvas" width="400" height="200"></canvas><div style="display:flex; justify-content:space-between; margin-top:10px;"><button type="button" class="btn red waves-effect" onclick="resetSignature()">Reset</button><button type="button" class="btn grey waves-effect" onclick="closeSignatureModal()">Close</button><button type="button" class="btn green waves-effect" onclick="saveSignature()">Save</button></div></div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
<script type="module">
    // 3D Background Animation
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
    document.addEventListener('DOMContentLoaded', () => { 
        M.AutoInit();
        M.updateTextFields(); 
        const allSelects = document.querySelectorAll('select');
        M.FormSelect.init(allSelects);
        const textAreas = document.querySelectorAll('.materialize-textarea'); 
        M.CharacterCounter.init(textAreas); 
        textAreas.forEach(ta => M.textareaAutoResize(ta));
    });

    const userFullName = "<?= addslashes($userFullName); ?>";
    let signatureModal = null, signatureCanvas = null, ctx = null, currentSigId = "", currentSigInputTarget = null, currentSigPreviewTarget = null, currentSigTimestampTarget = null;

    function toggleSidebar() { 
        const sidebar = document.getElementById("mySidebar"); 
        if (!sidebar) return; 
        sidebar.style.width = (!sidebar.style.width || sidebar.style.width === "0px") ? "280px" : "0"; 
    }

    document.addEventListener('click', function(e) { 
        const sidebar = document.getElementById("mySidebar"); 
        const hamburger = document.querySelector('.hamburger-icon'); 
        if (sidebar && sidebar.style.width === "280px" && !sidebar.contains(e.target) && (!hamburger || !hamburger.contains(e.target))) { 
            sidebar.style.width = "0"; 
        }
    });

    let consentCounter = <?= !empty($emrData['consents']) ? count($emrData['consents']) : 1; ?>;
    function addConsentBox() {
        const container = document.getElementById('additionalConsentsContainer'); if (!container) return;
        const newIndex = consentCounter++;

        const box = document.createElement('div'); box.className = 'bordered-section'; box.id = `consentBox_extra_${newIndex}`;
        let newSigInputId = `consentSignature_extra_${newIndex}`;

        box.innerHTML = `<h6>Additional Consent</h6> 
            <div class="row">
                <div class="input-field col s12 m6"><input type="text" name="consent_given_by[]" id="consent_given_by_extra_${newIndex}"><label for="consent_given_by_extra_${newIndex}">Consent Given By</label></div>
                <div class="input-field col s12 m3"><select name="relation[]" id="relation_extra_${newIndex}"><option value="" disabled selected>Select</option><?php $relOpts = ['Husband','Wife','Father','Mother','Son','Daughter','Relative', 'Self']; foreach($relOpts as $rOpt):?><option value="<?= $rOpt;?>"><?= $rOpt;?></option><?php endforeach;?></select><label>Relation</label></div>
                <div class="input-field col s12 m3"><input type="text" name="cnic[]" id="cnic_extra_${newIndex}"><label for="cnic_extra_${newIndex}">CNIC Number</label></div>
            </div>
            <div class="row" style="margin-top:10px;">
                <div class="col s12 m4"><button type="button" class="btn" onclick="openSignatureModal('${newSigInputId}')">Add Signature</button></div>
                <div class="col s12 m8"><input type="hidden" id="${newSigInputId}" name="signature_data[]"><div class="signature-box" id="sigPreview_${newSigInputId}"></div></div>
            </div>
            <button type="button" class="btn red" style="margin-top:10px;" onclick="this.closest('.bordered-section').remove()">Remove Consent</button>`;
        container.appendChild(box);
        M.FormSelect.init(box.querySelectorAll('select')); 
        M.updateTextFields();
    }

    let testCounter = <?= !empty($emrData['tests_ordered']) ? count($emrData['tests_ordered']) : 0; ?>;
    function addTestField() {const c = document.getElementById('testsOrderedContainer'); if(!c)return; testCounter++; const r = document.createElement('div'); r.className='row'; r.id=`test_row_${testCounter}`; r.innerHTML=`<div class="input-field col s10"><input type="text" name="tests_ordered[]" id="test_ordered_${testCounter}"><label for="test_ordered_${testCounter}">Test #${testCounter+1}</label></div><div class="col s2"><button type="button" class="btn-floating red" style="margin-top:20px;" onclick="this.closest('.row').remove()"><i class="material-icons">remove</i></button></div>`; c.appendChild(r); M.updateTextFields(); }

    let diseaseCounter = <?= !empty($emrData['past_medical_history']) ? count($emrData['past_medical_history']) : 0; ?>;
    function addDiseaseField() { const c=document.getElementById('pastMedicalHistoryContainer'); if(!c)return; diseaseCounter++; const r=document.createElement('div');r.className='row';r.id=`disease_row_${diseaseCounter}`; r.innerHTML=`<div class="input-field col s10"><input type="text" name="past_medical_history[]" id="disease_${diseaseCounter}"><label for="disease_${diseaseCounter}">Condition #${diseaseCounter+1}</label></div><div class="col s2"><button type="button" class="btn-floating red" style="margin-top:20px;" onclick="this.closest('.row').remove()"><i class="material-icons">remove</i></button></div>`; c.appendChild(r); M.updateTextFields(); }

    let medicationCounter = <?= !empty($emrData['current_medications']) ? count($emrData['current_medications']) : 0; ?>;
    function addMedicationField() { medicationCounter++; const c=document.getElementById('currentMedicationsContainer'); if(!c)return; const r=document.createElement('div');r.className='row';r.id=`medication_row_${medicationCounter}`; r.innerHTML=`<div class="input-field col s10"><input type="text" name="current_medications[]" id="medication_${medicationCounter}"><label for="medication_${medicationCounter}">Medication #${medicationCounter+1}</label></div><div class="col s2"><button type="button" class="btn-floating red" style="margin-top:20px;" onclick="this.closest('.row').remove()"><i class="material-icons">remove</i></button></div>`; c.appendChild(r); M.updateTextFields(); }

    let currentDoctorNoteKey = <?= !empty($emrData['doctors_progress_notes']) ? count($emrData['doctors_progress_notes']) : 0; ?>;
    function addDoctorNote() {
        const container = document.getElementById("doctorsProgressNotesContainer"); if (!container) return;
        const noteKey = `new_doc_note_${currentDoctorNoteKey++}`;
        const box=document.createElement("div"); box.className="bordered-section"; box.id=noteKey;
        const now=new Date(), timestamp=now.toLocaleString('en-GB'), isoTimestamp = now.toISOString();
        box.innerHTML=`<h6>By ${userFullName} at ${timestamp} <button type="button" class="btn-floating red right" onclick="this.closest('.bordered-section').remove()"><i class="material-icons">remove</i></button></h6><textarea class="materialize-textarea" name="doctors_progress_notes[${noteKey}][note]" placeholder="SOAP..."></textarea><input type="hidden" name="doctors_progress_notes[${noteKey}][timestamp]" value="${isoTimestamp}"><input type="hidden" name="doctors_progress_notes[${noteKey}][author]" value="${userFullName}">`;
        container.prepend(box); M.textareaAutoResize(box.querySelector('textarea')); M.updateTextFields();
    }

    let currentNurseNoteKey = <?= !empty($emrData['nurses_progress_notes']) ? count($emrData['nurses_progress_notes']) : 0; ?>;
    function addNurseNote() {
        const container = document.getElementById("nursesProgressNotesContainer"); if (!container) return;
        const noteKey = `new_nurse_note_${currentNurseNoteKey++}`;
        const box=document.createElement("div"); box.className="bordered-section"; box.id=noteKey;
        const now=new Date(), timestamp=now.toLocaleString('en-GB'), isoTimestamp = now.toISOString();
        box.innerHTML=`<h6>By ${userFullName} at ${timestamp} <button type="button" class="btn-floating red right" onclick="this.closest('.bordered-section').remove()"><i class="material-icons">remove</i></button></h6><textarea class="materialize-textarea" name="nurses_progress_notes[${noteKey}][note]" placeholder="Observations, interventions..."></textarea><input type="hidden" name="nurses_progress_notes[${noteKey}][timestamp]" value="${isoTimestamp}"><input type="hidden" name="nurses_progress_notes[${noteKey}][author]" value="${userFullName}">`;
        container.prepend(box); M.textareaAutoResize(box.querySelector('textarea')); M.updateTextFields();
    }

    function toggleAllergyTextbox(show) { const af=document.getElementById('f_allergy_details'),al=document.querySelector('label[for="f_allergy_details"]'); if(!af||!al)return; af.style.display=show?'block':'none';al.style.display=show?'block':'none'; if(show)af.setAttribute('required','required');else{af.value='';af.removeAttribute('required');} M.updateTextFields(); M.textareaAutoResize(af); if(show) af.focus(); }

    function openSignatureModal(sigInputId) { 
        currentSigId=sigInputId; 
        currentSigInputTarget = document.getElementById(currentSigId);
        currentSigPreviewTarget = document.getElementById(`sigPreview_${currentSigId}`);
        
        signatureModal=document.getElementById("signatureModal"); if(!signatureModal)return; 
        signatureModal.style.display="flex"; 
        signatureCanvas=document.getElementById("signatureCanvas"); if(!signatureCanvas)return; 
        ctx=signatureCanvas.getContext("2d"); 
        ctx.fillStyle="#FFFFFF"; ctx.fillRect(0,0,signatureCanvas.width,signatureCanvas.height); 
        ctx.strokeStyle="#000000"; ctx.lineWidth=2; let drawing=false; 
        const getPos=(c,e)=>{const r=c.getBoundingClientRect();const t=e.touches?e.touches[0]:e;return{x:t.clientX-r.left,y:t.clientY-r.top};};
        const startDraw=(e)=>{drawing=true;const p=getPos(signatureCanvas,e);ctx.beginPath();ctx.moveTo(p.x,p.y);};
        const endDraw=()=>{drawing=false;};
        const doDraw=(e)=>{if(drawing){const p=getPos(signatureCanvas,e);ctx.lineTo(p.x,p.y);ctx.stroke();}};
        
        signatureCanvas.onmousedown=startDraw;
        signatureCanvas.onmouseup=endDraw;
        signatureCanvas.onmouseout=endDraw;
        signatureCanvas.onmousemove=doDraw;
        signatureCanvas.ontouchstart=(e)=>{e.preventDefault();startDraw(e);};
        signatureCanvas.ontouchend=(e)=>{e.preventDefault();endDraw(e);};
        signatureCanvas.ontouchmove=(e)=>{e.preventDefault();doDraw(e);};
    }

    function closeSignatureModal() { if(signatureModal)signatureModal.style.display="none"; if(signatureCanvas){signatureCanvas.onmousedown=null;signatureCanvas.onmouseup=null;signatureCanvas.onmouseout=null;signatureCanvas.onmousemove=null;signatureCanvas.ontouchstart=null;signatureCanvas.ontouchend=null;signatureCanvas.ontouchmove=null;}}
    function resetSignature() { if(ctx&&signatureCanvas){ctx.clearRect(0,0,signatureCanvas.width,signatureCanvas.height);ctx.fillStyle="#FFFFFF";ctx.fillRect(0,0,signatureCanvas.width,signatureCanvas.height);}}
    function saveSignature() { 
        if(!ctx||!signatureCanvas)return; 
        const pxBuf=new Uint32Array(ctx.getImageData(0,0,signatureCanvas.width,signatureCanvas.height).data.buffer); 
        if(!pxBuf.some(c=>c!==0xFFFFFFFF && c!==0x00000000)){ M.toast({html: 'Please provide a signature before saving.'}); return;} 
        const dURL=signatureCanvas.toDataURL("image/png"); 
        if(currentSigInputTarget) currentSigInputTarget.value=dURL; 
        if(currentSigPreviewTarget){currentSigPreviewTarget.innerHTML='';const i=document.createElement('img');i.src=dURL; currentSigPreviewTarget.appendChild(i);} 
        closeSignatureModal();
    }
</script>
</body>
</html>