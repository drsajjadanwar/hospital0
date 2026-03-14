<?php
// /public/lab_reporting.php  – 27 Apr 2025 (r19 pagination fix, header repeat)

/* ───── Bootstrap & auth (unchanged) ───── */
error_reporting(E_ALL); ini_set('display_errors',1);
session_start();
if(!isset($_SESSION['user'])){ header('Location: login.php'); exit; }
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/fpdf.php';
require_once __DIR__.'/includes/code128.php';
if(!in_array((int)($_SESSION['user']['group_id']??0),[1,6],true)){
    header('HTTP/1.1 403 Forbidden'); exit('Access denied'); }

$uname=$_SESSION['user']['username']??'unknown';
$ufull=$_SESSION['user']['full_name']??$uname;

/* ───── Flash (unchanged) ───── */
$err=$_SESSION['error_message']??''; $ok=$_SESSION['success_message']??'';
$pdfLink=$_SESSION['pdf_link']??null;
unset($_SESSION['error_message'],$_SESSION['success_message'],$_SESSION['pdf_link']);

/* ───── Orders list (unchanged) ───── */
try{
  // Fetching orders including the report_link for the action button logic
  $orders=$pdo->query("
    SELECT lo.order_id,lo.patient_mrn,
           p.full_name AS patient_name,
           GROUP_CONCAT(DISTINCT lt.test_name ORDER BY lt.test_name SEPARATOR ', ') AS tests,
           lo.order_date,lo.status,lo.report_link -- Added report_link here
      FROM lab_orders lo
      JOIN patients        p  ON p.mrn     = lo.patient_mrn
      JOIN lab_order_items loi ON loi.order_id = lo.order_id
      JOIN lab_tests       lt  ON lt.test_id   = loi.test_id
     WHERE lo.status IN ('Pending','Processing','Completed')
  GROUP BY lo.order_id
  ORDER BY CASE lo.status WHEN 'Pending' THEN 0 WHEN 'Processing' THEN 1 ELSE 2 END,
           lo.order_date ASC")->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){ $err.=$e->getMessage(); $orders=[]; }

/* ───── Single-order fetch (unchanged from r18) ───── */
$view=null; $initial_test_items=[]; $initial_test_name = 'Test Results';
if(isset($_GET['order_id'])){
  $oid=filter_var($_GET['order_id'],FILTER_VALIDATE_INT);
  if($oid){
    $hdr=$pdo->prepare("
      SELECT lo.*,p.full_name AS patient_name,p.gender,p.age,p.age_unit,
             (SELECT GROUP_CONCAT(DISTINCT lt2.test_name ORDER BY lt2.test_name SEPARATOR ', ')
                FROM lab_order_items loi2
                JOIN lab_tests lt2 ON lt2.test_id=loi2.test_id
               WHERE loi2.order_id=lo.order_id) AS tests_str
        FROM lab_orders lo
        JOIN patients p ON p.mrn=lo.patient_mrn
       WHERE lo.order_id=?");
    $hdr->execute([$oid]); $view=$hdr->fetch(PDO::FETCH_ASSOC);

    if($view){
      $initial_test_name = $view['tests_str'] ?: 'Test Results';
      $det=$pdo->prepare("
        SELECT loi.item_id,lt.test_name,lt.result_unit AS unit,lt.reference_range AS ref_range
          FROM lab_order_items loi
          JOIN lab_tests lt ON lt.test_id=loi.test_id
         WHERE loi.order_id=?
      ORDER BY lt.test_name");
      $det->execute([$oid]);
      $initial_test_items = $det->fetchAll(PDO::FETCH_ASSOC);

    } else $err.=' Order not found.';
  }
}

/* ───── PDF helpers (Header logic updated) ───── */
if(!defined('FPDF_FONTPATH')) define('FPDF_FONTPATH',__DIR__.'/includes/font/');

class PDF128 extends PDF_Code128{
  function __construct(){ parent::__construct();
    foreach(['A','B','C'] as $s)
      if(isset($this->SetFrom[$s])&&!is_array($this->SetFrom[$s]))
        $this->SetFrom[$s]=str_split($this->SetFrom[$s]);
 } }

class ReportPDF extends PDF128{
  public float $tblX; public array $tblW; public string $fontName = 'MyriadPro-Regular';
  private $patientHeaderData = []; // Store header info

  function __construct(){
    parent::__construct('P','mm','A4');
    try{
        $this->AddFont($this->fontName,'',$this->fontName.'.php');
        $this->SetFont($this->fontName,'',10);
    } catch(Exception){
        $this->fontName = 'Arial'; // Fallback
        $this->SetFont($this->fontName,'',10);
    }
  }

  function family(){ return $this->fontName; }

  function setTable(){
    $tot=$this->GetPageWidth()-$this->lMargin-$this->rMargin;
    $tbl=$tot*.95; $this->tblX=$this->lMargin+($tot-$tbl)/2;
    // Adjusted widths slightly: Parameter, Value, Unit, Ref Range, Notes
    $this->tblW=[round($tbl*.40,1),round($tbl*.15,1),round($tbl*.10,1),
                 round($tbl*.18,1),$tbl-round($tbl*.83,1)];
  }

  /* wrapped line counter (unchanged from r18) */
  function nb($w,$txt){
    $cw=&$this->CurrentFont['cw']; if(!isset($cw)) return 0;
    $wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
    $s=str_replace("\r",'',$txt); $nb=strlen($s);
    if($nb && $s[$nb-1]=="\n") $nb--;
    $i=$l=$nl=0; $sep=-1;
    while($i<$nb){
      $c=$s[$i];
      if($c=="\n"){ $i++; $sep=-1; $l=0; $nl++; continue; }
      if($c==' ') $sep=$i;
      $l+=$cw[ord($c)]??($cw[' ']??0);
      if($l>$wmax){
        if($sep==-1){ if($i==0) $i++; }
        else $i=$sep+1;
        $sep=-1; $l=0; $nl++;
      }else $i++;
    }
    return $nl + 1;
  }

  function assureBreak($h){
    if($this->GetY()+$h>$this->PageBreakTrigger){
      $this->AddPage($this->CurOrientation);
    }
  }

  /* header / footer (Header logic updated to repeat full header) */
  function SetPatientHeader(array $data) {
      $this->patientHeaderData = $data;
  }

  function Header(){
      $f = $this->family();
      $this->SetFont($f,'',10); // Reset font size at start
      $this->SetTextColor(0,0,0); // Reset text color

      // === Full Header Block (Repeated on Every Page) ===
      $W=$this->GetPageWidth();
      $logo=__DIR__.'/media/logo-print.png';
      // Save Y pos before logo
      $y_before_logo = $this->GetY();
      if ($y_before_logo < $this->tMargin) { // Ensure we don't overwrite top margin space if Y is too high
          $y_before_logo = $this->tMargin;
          $this->SetY($y_before_logo);
      }

      if(is_readable($logo)){
          $logo_y = $y_before_logo > 10 ? $y_before_logo : 10; // Place logo respecting margin or current Y
          $this->Image($logo,($W-80)/2, $logo_y, 80);
          $this->SetY($logo_y + 35); // Set Y below the logo + space
      } else {
          // If no logo, add equivalent vertical space based on where logo *would* have ended
          $logo_y = $y_before_logo > 10 ? $y_before_logo : 10;
          $this->SetY($logo_y + 35); // Add space anyway
      }

      $this->SetFont($f,'',16); $this->SetTextColor(100,100,100);
      $this->Cell(0,7,'hospital0',0,1,'C');
      $this->SetFont($f,'',12); $this->Cell(0,5,'www.hospital0',0,1,'C');
      $this->Ln(5); // Reduced space before patient details
      $this->SetTextColor(0,0,0); // Reset text color

      // --- Print Patient Details ---
      $this->SetFont($f,'',11); // Slightly smaller for details
      $colWidth = ($this->GetPageWidth() - $this->lMargin - $this->rMargin) / 2 - 5; // Two columns
      $lineHeight = 6;
      $y_start_details = $this->GetY();

      $this->Cell($colWidth, $lineHeight, 'Patient Name: ' . ($this->patientHeaderData['full_name'] ?? ''), 0, 0);
      $this->Cell($colWidth, $lineHeight, 'Order ID: ' . ($this->patientHeaderData['order_id'] ?? ''), 0, 1);

      $this->Cell($colWidth, $lineHeight, 'MRN: ' . ($this->patientHeaderData['patient_mrn'] ?? ''), 0, 0);
      $this->Cell($colWidth, $lineHeight, 'Order Date: ' . ($this->patientHeaderData['order_date'] ? date('d M Y, h:i A',strtotime($this->patientHeaderData['order_date'])) : ''), 0, 1);

      $ageStr = ($this->patientHeaderData['age']??0)>0 ? $this->patientHeaderData['age'].' '.$this->patientHeaderData['age_unit'] : 'N/A';
      $this->Cell($colWidth, $lineHeight, 'Age: ' . $ageStr, 0, 0);
      $this->Cell($colWidth, $lineHeight, 'Report Date: ' . date('d M Y'), 0, 1);

      $this->Cell($colWidth, $lineHeight, 'Gender: ' . ($this->patientHeaderData['gender'] ?? ''), 0, 0);
       // Page number info
       $this->Cell($colWidth, $lineHeight, 'Page: ' . $this->PageNo() . '/{nb}', 0, 1); // Use {nb} for total pages alias

      $y_end_details = $this->GetY();
      // Draw a line below patient details
      $this->Line($this->lMargin, $y_end_details + 1, $this->GetPageWidth() - $this->rMargin, $y_end_details + 1);
      $this->Ln(3); // Space after line

      // --- Barcode ---
      if(!empty($this->patientHeaderData['order_id'])) {
          $this->Code128(($this->GetPageWidth()-60)/2, $this->GetY(), (string)$this->patientHeaderData['order_id'], 60, 12);
          $this->SetY($this->GetY()+15); // Space after barcode
      } else {
          $this->Ln(5);
      }

       // Set table coords AFTER header adjustments
      $this->setTable();
       // Ensure Y position is sufficient before content starts
       if ($this->GetY() < $this->tMargin + 80) { // Adjust 80 based on estimated header height
           $this->SetY($this->tMargin + 80);
       }
       $this->Ln(2); // Small gap before test name or table header
  }

  function Footer(){ // Unchanged
    $f = $this->family();
    $this->SetY(-20);
    $this->SetFont($f,'',9); $this->SetTextColor(100,100,100);
    global $ufull; $this->Cell(0,6,'Report generated by '.$ufull.' on '.date('Y-m-d H:i:s T'),0,1,'C');
    $this->SetFont($f,'',10); $this->Cell(0,6,'hospital0',0,0,'C');
  }

  function headerRow(){ // Unchanged
    [$w0,$w1,$w2,$w3,$w4]=$this->tblW;
    $this->SetX($this->tblX);
    $this->SetFont($this->family(),'',10);
    $this->SetFillColor(220,220,220);
    $this->SetTextColor(0,0,0);
    $this->SetDrawColor(180,180,180);
    $this->SetLineWidth(0.2);

    $headerHeight = 7;
    $currentY = $this->GetY();
    $currentX = $this->tblX;
    foreach([['Parameter',$w0,'C'],['Value',$w1,'C'],['Unit',$w2,'C'],
             ['Ref Range',$w3,'C'],['Notes',$w4,'C']] as [$t,$w,$align]) {
      $this->SetXY($currentX, $currentY);
      $this->MultiCell($w, $headerHeight, $t, 1, $align, true);
      $currentX += $w;
    }
    $this->Ln($headerHeight);
  }

  function dataRow(array $r){ // Unchanged
    [$w0,$w1,$w2,$w3,$w4]=$this->tblW;
    $f = $this->family();
    $this->SetFont($f,'',10);
    $this->SetTextColor(0,0,0);
    $this->SetDrawColor(180,180,180);
    $this->SetLineWidth(0.2);

    $cellData = [
      [$w0, $r['parameter'] ?: ' ', 'L'],
      [$w1, $r['value'], 'R'],
      [$w2, $r['unit'], 'C'],
      [$w3, $r['ref'], 'C'],
      [$w4, $r['notes'], 'L']
    ];

    $maxLines = 0;
    foreach($cellData as [$w, $txt, $align]) {
        $maxLines = max($maxLines, $this->nb($w, $txt));
    }
    $rowH = $maxLines * 5.5;

    $this->assureBreak($rowH);

    $x0=$this->tblX;
    $y0=$this->GetY();
    $currentX = $x0;

    foreach($cellData as $i => [$w, $txt, $align]){
        $this->SetXY($currentX, $y0);
        $this->MultiCell($w, 5.5, $txt, 1, $align);
        $currentX += $w;
    }
    $this->SetY($y0 + $rowH);
  }
 }

/* ───────────────────────── POST – save + PDF (DB Update Confirmed) ─────────────────────── */
 if ($_SERVER['REQUEST_METHOD']==='POST'
    && ($_POST['action'] ?? '')==='save_results') {

    $pdo->beginTransaction();
    try {
        $oid = filter_var($_POST['order_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!$oid) throw new Exception('No order ID.');

        // --- Fetch Meta Data (unchanged) ---
        $metaQ = $pdo->prepare("
             SELECT lo.order_id, lo.patient_mrn, lo.order_date,
                    p.full_name, p.gender, p.age, p.age_unit
               FROM lab_orders lo
               JOIN patients   p ON p.mrn = lo.patient_mrn
              WHERE lo.order_id = ?
        ");
        $metaQ->execute([$oid]);
        $meta = $metaQ->fetch(PDO::FETCH_ASSOC);
        if (!$meta) throw new Exception('Order metadata not found.');


        // --- Process Form Data (unchanged) ---
        $tests_data = $_POST['tests'] ?? [];
        $reportData = [];

        foreach ($tests_data as $test_index => $test) {
            $current_test_name = trim($test['test_name'] ?? 'Untitled Test');
            $rows = [];
            if (isset($test['rows']) && is_array($test['rows'])) {
                foreach ($test['rows'] as $row_index => $row) {
                    if (!empty(trim($row['parameter'] ?? '')) || !empty(trim($row['value'] ?? ''))) {
                         $rows[] = [
                            'parameter' => trim($row['parameter'] ?? ''),
                            'value'     => trim($row['value'] ?? ''),
                            'unit'      => trim($row['unit'] ?? ''),
                            'ref'       => trim($row['ref'] ?? ''),
                            'notes'     => trim($row['notes'] ?? ''),
                        ];
                    }
                }
            }
            if (!empty($current_test_name) && count($rows) > 0) {
                 $reportData[] = [
                    'test_name' => $current_test_name,
                    'rows' => $rows
                 ];
            }
        }

        if (empty($reportData)) {
            throw new Exception('No results were entered to generate the report.');
        }

        /* --- Path (unchanged) --- */
        $dir = __DIR__.'/lab_reports/';
        if(!is_dir($dir)) @mkdir($dir,0775,true);
        $safe = preg_replace('/[^A-Za-z0-9_-]/','_',$meta['patient_mrn']);
        $file = $dir."LabReport_{$safe}_{$oid}.pdf";
        for($i=1;file_exists($file);$i++)
            $file=$dir."LabReport_{$safe}_{$oid}_{$i}.pdf";

        /* --- Build PDF --- */
        $pdf = new ReportPDF();
        $pdf->SetMargins(15, 10, 15);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->SetPatientHeader($meta);
        $pdf->AliasNbPages(); // Enable total page number alias {nb}
        $pdf->AddPage();
        $f = $pdf->family();

        // --- Loop through Tests (unchanged) ---
        foreach($reportData as $testIndex => $testGroup) {
            if ($testIndex > 0) {
                $pdf->AddPage(); // This will trigger the full Header method again
            }

            $pdf->SetFont($f, '', 12);
            $pdf->SetTextColor(0,0,0);
            $pdf->Cell(0, 8, $testGroup['test_name'], 0, 1, 'L');
            $pdf->Ln(2);

            $pdf->headerRow();

             if (empty($testGroup['rows'])) {
                 $pdf->SetFont($f,'',10);
                 $pdf->Cell(array_sum($pdf->tblW), 6, '(No parameters entered for this test)', 1, 1, 'C');
             } else {
                 foreach($testGroup['rows'] as $rowIndex => $rowData) {
                     $pdf->dataRow($rowData);
                 }
             }
            if ($testIndex < count($reportData) - 1) {
                 $pdf->Ln(5);
            }
        }

        $pdf->Output('F',$file);
        $rel='lab_reports/'.basename($file);

        /* --- Update DB (This part was already correct) --- */
        $pdo->prepare("
            UPDATE lab_orders
               SET status='Completed',        -- Set status
                   updated_at = NOW(),
                   report_link = ?           -- Save the link
             WHERE order_id = ?
        ")->execute([$rel,$oid]);

        $pdo->commit();
        $_SESSION['success_message']="Order #$oid completed & PDF generated.";
        $_SESSION['pdf_link']=$rel;
        header('Location: lab_reporting.php'); // Redirect to list view
        exit;

    } catch(Exception $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error_message']='Error: '.$e->getMessage();
        // Redirect back to the form page on error
        if (isset($oid) && $oid) {
             header('Location: lab_reporting.php?order_id='.$oid);
        } else {
             header('Location: lab_reporting.php');
        }
        exit;
    }
 }
 ?>
 <!DOCTYPE html>
 <html lang="en">
 <head>
 <meta charset="utf-8">
 <meta name="viewport" content="width=device-width,initial-scale=1">
 <title>hospital0 - Laboratory Reporting — hospital0</title>
 <link rel="icon" href="/media/sitelogo.png">
 <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
 <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
 <link rel="stylesheet" href="assets/css/style.css">
 <style>
 /* Styles (Unchanged from r18) */
 @font-face{font-family:'MyriadPro-Regular';src:url('/fonts/MyriadPro-Regular.ttf') format('truetype');}
 body{font-family:'MyriadPro-Regular',sans-serif;color:#fff;display:flex;flex-direction:column;min-height:100vh; background-color: #333;}
 main{flex:1 0 auto;} .container{width:95%;max-width:1400px;}
 .white-line-header{width:60%;height:1px;background:#757575;margin:20px auto 30px;border:0;}
 h1.site-title{font-size:2.5rem;font-weight:300;text-align:center;margin:0 0 10px;}
 h5.page-title{font-weight:300;text-align:center;margin:0 0 40px;position:relative;}
 h5.page-title:after{content:'';position:absolute;left:50%;transform:translateX(-50%);bottom:-12px;width:100%;max-width:320px;height:1px;background:rgba(255,255,255,.6);}
 .section-heading{font-size:1.4rem;color:#1de9b6;text-align:center;margin:30px 0 20px;}
 table{width:100%;color:#fff;}
 th{color:#1de9b6;padding:12px 8px; font-weight: normal;}
 td{padding:10px 8px; vertical-align: top;}
 tbody tr:hover{background:rgba(255,255,255,.05);}
 input[type="text"], textarea {
    border:none !important;
    border-bottom:1px solid #9e9e9e !important;
    background:transparent !important;
    color:#fff !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    height: 2rem !important;
    margin-bottom: 5px !important;
    padding: 0 5px !important;
 }
 input[type="text"]:focus, textarea:focus {
    border-bottom:1px solid #1de9b6 !important;
 }
 input.search-box{padding-left:0;} input.search-box::placeholder{color:#bbb;}
 .btn,.btn-small{background:#1de9b6;color:#333; font-weight: normal;}
 .btn:hover{background:#00bfa5;}
 .btn.grey{background:#616161!important;color:#fff!important;} .btn.grey:hover{background:#4e4e4e!important;}
 .btn.red{background:#e57373!important;color:#fff!important;} .btn.red:hover{background:#ef5350!important;}
 .banner{display:flex;align-items:center;padding:12px 18px;border-radius:4px;margin:20px 0;}
 .banner-err{background:#c62828;} .banner-ok{background:#2e7d32;} .banner i{margin-right:10px;}
 .pagination li.active a{background:#26a69a;} .pagination li.disabled a{color:#999;} .pagination li a {color: #1de9b6;} /* Link color */
 .test-block {
    border: 1px solid rgba(255,255,255,0.2);
    padding: 15px;
    margin-bottom: 25px;
    border-radius: 5px;
    background: rgba(0,0,0,0.1);
 }
 .test-header { margin-bottom: 15px; display: flex; align-items: center; }
 .test-header input { flex-grow: 1; margin-right: 10px; font-size: 1.1rem; }
 .test-header .btn-small { flex-shrink: 0; }
 .result-row td { padding-top: 5px; padding-bottom: 5px; }
 .action-buttons { margin-top: 15px; }
 </style>
 </head>
 <body>

 <?php include __DIR__.'/includes/header.php'; ?>
 <hr class="white-line-header">

 <main><div class="container">
 <h1 class="site-title">hospital0</h1>
 <h5 class="page-title">Laboratory Reporting System</h5>

 <?php if ($err): ?>
   <div class="banner banner-err"><i class="material-icons">error_outline</i><?=htmlspecialchars($err)?></div>
 <?php endif; ?>
 <?php if ($ok): ?>
   <div class="banner banner-ok"><i class="material-icons">check_circle_outline</i><?=htmlspecialchars($ok)?>
     <?php if ($pdfLink): ?>
       <a class="btn-small grey" style="margin-left: 15px;" target="_blank" href="<?=htmlspecialchars($pdfLink)?>">Get&nbsp;PDF</a>
     <?php endif; ?>
   </div>
 <?php endif; ?>

 <?php if (!$view): ?>
 <h4 class="section-heading">Lab Orders</h4>
 <div style="margin:25px 0;text-align:center;">
   <input id="searchBox" class="search-box" type="text" placeholder="Enter MRN or Patient Name">
 </div>

 <?php if ($orders): ?>
 <table id="orderTable" class="striped highlight responsive-table">
 <thead>
   <tr>
     <th>Order ID</th><th>MRN</th><th>Patient</th><th>Tests</th>
     <th>Order Date</th><th>Status</th><th>Action</th>
   </tr>
 </thead>
 <tbody>
 <?php foreach ($orders as $o): ?>
 <tr class="order-row"> <td class="order-id"><?= $o['order_id'] ?></td>
   <td class="patient-mrn"><?= htmlspecialchars($o['patient_mrn']) ?></td>
   <td class="patient-name"><?= htmlspecialchars($o['patient_name']) ?></td>
   <td class="tests-list"><?= htmlspecialchars($o['tests']) ?></td>
   <td class="order-date"><?= date('d M Y, h:i A',strtotime($o['order_date'])) ?></td>
   <td><span class="new badge blue lighten-1" data-badge-caption=""><?= htmlspecialchars($o['status']) ?></span></td>
   <td>
     <?php // This conditional logic correctly shows Get PDF if completed and link exists ?>
     <?php if ($o['status']==='Completed' && !empty($o['report_link'])): ?>
       <a class="btn-small grey" target="_blank" href="<?=htmlspecialchars($o['report_link'])?>">
         <i class="material-icons left">picture_as_pdf</i>Get PDF
       </a>
     <?php else: ?>
       <a class="btn-small" href="lab_reporting.php?order_id=<?=$o['order_id']?>">
         <i class="material-icons left">assignment</i>Results
       </a>
     <?php endif; ?>
   </td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 <ul id="pager" class="pagination center-align" style="margin-top:25px;"></ul>
 <?php else: ?>
 <p class="center-align">No lab orders found.</p>
 <?php endif; ?>

 <?php else: ?>
 <h4 class="section-heading">Enter Lab Results – Order #<?=$view['order_id']?></h4>

 <div class="row">
   <div class="col s12 m6">
     <div class="banner" style="background:rgba(0,0,0,.15);border:1px solid rgba(255,255,255,.15);">
       <div>
         <strong>MRN:</strong> <?=htmlspecialchars($view['patient_mrn'])?><br>
         <strong>Name:</strong> <?=htmlspecialchars($view['patient_name'])?><br>
         <strong>Age:</strong> <?= $view['age']>0 ? $view['age'].' '.$view['age_unit'] : 'N/A' ?><br>
         <strong>Gender:</strong> <?= htmlspecialchars($view['gender'] ?? 'N/A') ?>
       </div>
     </div>
   </div>
   <div class="col s12 m6">
     <div class="banner" style="background:rgba(0,0,0,.15);border:1px solid rgba(255,255,255,.15);">
       <div>
         <strong>Order Date:</strong> <?=date('d M Y, h:i A',strtotime($view['order_date']))?><br>
         <strong>Status:</strong> <?=htmlspecialchars($view['status'])?><br>
         <strong>Ordered By:</strong> <?=htmlspecialchars($view['ordered_by_user'] ?? 'N/A')?><br>
         <strong>Initial Test(s):</strong> <?=htmlspecialchars($initial_test_name)?>
       </div>
     </div>
   </div>
 </div>

 <form method="POST" id="reportForm">
 <input type="hidden" name="action" value="save_results">
 <input type="hidden" name="order_id" value="<?=$view['order_id']?>">

 <div id="testsContainer">
    <div class="test-block" data-test-index="0">
        <div class="test-header">
             <input type="text" name="tests[0][test_name]" value="<?= htmlspecialchars($initial_test_name) ?>" placeholder="Test Name / Section Title">
             <button type="button" class="btn-small red remove-test-btn" title="Remove this entire test block">X</button>
        </div>
        <table class="results-table responsive-table">
             <thead>
               <tr>
                 <th style="width:35%;">Parameter</th>
                 <th style="width:18%;">Value</th>
                 <th style="width:12%;">Unit</th>
                 <th style="width:18%;">Ref Range</th>
                 <th>Notes</th>
                 <th style="width: 50px;"></th> </tr>
             </thead>
             <tbody>
                <?php if (empty($initial_test_items)): ?>
                    <tr class="result-row" data-row-index="0">
                       <td><input type="text" name="tests[0][rows][0][parameter]" placeholder="Parameter Name"></td>
                       <td><input type="text" name="tests[0][rows][0][value]"></td>
                       <td><input type="text" name="tests[0][rows][0][unit]"></td>
                       <td><input type="text" name="tests[0][rows][0][ref]"></td>
                       <td><input type="text" name="tests[0][rows][0][notes]"></td>
                       <td><button type="button" class="btn-small red remove-row-btn">X</button></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($initial_test_items as $idx=>$it): $id=$it['item_id']; ?>
                     <tr class="result-row" data-row-index="<?= $idx ?>">
                       <td><input type="text" name="tests[0][rows][<?= $idx ?>][parameter]" value="<?=htmlspecialchars($it['test_name'])?>"></td>
                       <td><input type="text" name="tests[0][rows][<?= $idx ?>][value]"></td>
                       <td><input type="text" name="tests[0][rows][<?= $idx ?>][unit]" value="<?=htmlspecialchars($it['unit'] ?? '')?>"></td>
                       <td><input type="text" name="tests[0][rows][<?= $idx ?>][ref]"  value="<?=htmlspecialchars($it['ref_range'] ?? '')?>"></td>
                       <td><input type="text" name="tests[0][rows][<?= $idx ?>][notes]"></td>
                       <td><button type="button" class="btn-small red remove-row-btn">X</button></td>
                       <input type="hidden" name="tests[0][rows][<?= $idx ?>][item_id]" value="<?= $id ?>">
                     </tr>
                    <?php endforeach; ?>
                 <?php endif; ?>
             </tbody>
        </table>
        <div class="action-buttons">
             <button type="button" class="btn-small grey add-row-btn">
                <i class="material-icons left">add</i>Add Parameter Row
             </button>
        </div>
    </div></div><div class="row" style="margin-top:30px;">
    <div class="col s12 center-align">
        <button type="button" id="addTestBtn" class="btn grey">
            <i class="material-icons left">add_box</i>Add Another Test Section
        </button>
    </div>
 </div>


 <div class="row" style="margin-top:30px;">
   <div class="col s12 center-align">
     <button class="btn waves-effect waves-light" type="submit">
       <i class="material-icons right">save</i>Save & Generate PDF
     </button>
     <a href="lab_reporting.php" class="btn grey">Back to List</a>
   </div>
 </div>
 </form>
 <?php endif; ?>

 </div></main>

 <?php include __DIR__.'/includes/footer.php'; ?>

 <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
 <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
 <script>
 /* ───────── Pagination + Live Search (REVISED) ───────── */
 document.addEventListener('DOMContentLoaded',()=>{
   const orderTable = document.getElementById('orderTable');
   if(orderTable){
     const tableBody = orderTable.tBodies[0];
     const allRows = Array.from(tableBody.querySelectorAll('tr.order-row')); // Use the added class
     const pager = document.getElementById('pager');
     const searchBox = document.getElementById('searchBox');
     const PER = 10; // Items per page
     let currentPage = 1;
     let filteredRows = [...allRows]; // Initially, all rows are "filtered"

     const matchesSearch = (row, query) => {
         if (!query) return true; // No query matches all
         const mrn = row.querySelector('.patient-mrn')?.textContent.toLowerCase() || '';
         const name = row.querySelector('.patient-name')?.textContent.toLowerCase() || '';
         return mrn.includes(query) || name.includes(query);
     };

     const paint = () => {
         // 1. Apply search filter
         const query = searchBox.value.toLowerCase().trim();
         filteredRows = allRows.filter(row => matchesSearch(row, query));

         // 2. Calculate pagination variables
         const totalRows = filteredRows.length;
         const totalPages = Math.ceil(totalRows / PER) || 1;
         currentPage = Math.max(1, Math.min(currentPage, totalPages)); // Ensure currentPage is valid

         // 3. Render pagination controls
         pager.innerHTML = ''; // Clear existing controls
         const createPagerItem = (p, label, classes) => `<li class="${classes.join(' ')}"><a href="#!" data-page="${p}">${label}</a></li>`;

         let pagerHTML = createPagerItem(currentPage - 1, '«', ['waves-effect', currentPage === 1 ? 'disabled' : '']);
         for (let p = 1; p <= totalPages; p++) {
             pagerHTML += createPagerItem(p, p, ['waves-effect', p === currentPage ? 'active' : '']);
         }
         pagerHTML += createPagerItem(currentPage + 1, '»', ['waves-effect', currentPage === totalPages ? 'disabled' : '']);
         pager.innerHTML = pagerHTML;

         // 4. Show/Hide Rows based on pagination and filter
         const start = (currentPage - 1) * PER;
         const end = start + PER;

         allRows.forEach(row => row.style.display = 'none'); // Hide all rows initially
         filteredRows.slice(start, end).forEach(row => row.style.display = ''); // Show rows for the current page

         // Add event listeners to new pager buttons
         pager.querySelectorAll('a[data-page]').forEach(a => {
             if (!a.parentElement.classList.contains('disabled')) {
                 a.addEventListener('click', (e) => {
                     e.preventDefault();
                     currentPage = parseInt(a.dataset.page, 10);
                     paint(); // Re-paint everything
                 });
             }
         });
     };

     // Initial paint and search event listener
     searchBox.addEventListener('input', () => {
         currentPage = 1; // Reset to first page on search
         paint();
     });

     paint(); // Initial render
   }

  /* ───────── Dynamic Results Form (Unchanged from r18) ───────── */
   const testsContainer = document.getElementById('testsContainer');
   const addTestBtn = document.getElementById('addTestBtn');
   let testIndexCounter = testsContainer ? testsContainer.querySelectorAll('.test-block').length : 0;

   if (testsContainer) {
        // Event Delegation for Adding/Removing Rows/Tests
        testsContainer.addEventListener('click', function(event) {
            // Add Row Button Click
            if (event.target.closest('.add-row-btn')) { // Use closest to catch clicks on icon/text
                event.preventDefault();
                const testBlock = event.target.closest('.test-block');
                const testIndex = testBlock.dataset.testIndex;
                const tableBody = testBlock.querySelector('.results-table tbody');
                const rowIndex = tableBody.querySelectorAll('tr.result-row').length;

                const newRow = document.createElement('tr');
                newRow.classList.add('result-row');
                newRow.dataset.rowIndex = rowIndex;
                newRow.innerHTML = `
                    <td><input type="text" name="tests[${testIndex}][rows][${rowIndex}][parameter]" placeholder="Parameter Name"></td>
                    <td><input type="text" name="tests[${testIndex}][rows][${rowIndex}][value]"></td>
                    <td><input type="text" name="tests[${testIndex}][rows][${rowIndex}][unit]"></td>
                    <td><input type="text" name="tests[${testIndex}][rows][${rowIndex}][ref]"></td>
                    <td><input type="text" name="tests[${testIndex}][rows][${rowIndex}][notes]"></td>
                    <td><button type="button" class="btn-small red remove-row-btn">X</button></td>
                `;
                tableBody.appendChild(newRow);
                newRow.querySelector('input[type="text"]').focus();
            }

            // Remove Row Button Click
            if (event.target.classList.contains('remove-row-btn')) {
                event.preventDefault();
                const row = event.target.closest('tr.result-row');
                const tableBody = row.parentElement;
                if (tableBody.querySelectorAll('tr.result-row').length > 1) {
                     row.remove();
                } else {
                    M.toast({html: 'Cannot remove the last parameter row.'});
                }
            }

            // Remove Test Block Button Click
             if (event.target.classList.contains('remove-test-btn')) {
                event.preventDefault();
                const testBlock = event.target.closest('.test-block');
                 if (testsContainer.querySelectorAll('.test-block').length > 1) {
                     if(confirm('Are you sure you want to remove this entire test section and all its parameters?')) {
                        testBlock.remove();
                     }
                 } else {
                    M.toast({html: 'Cannot remove the only test section.'});
                 }
            }
        });

        // Add Test Button Click
        if (addTestBtn) {
            addTestBtn.addEventListener('click', function(event) {
                event.preventDefault();
                const testIndex = testIndexCounter++;

                const newTestBlock = document.createElement('div');
                newTestBlock.classList.add('test-block');
                newTestBlock.dataset.testIndex = testIndex;
                newTestBlock.innerHTML = `
                    <div class="test-header">
                        <input type="text" name="tests[${testIndex}][test_name]" value="" placeholder="New Test Name / Section Title">
                        <button type="button" class="btn-small red remove-test-btn" title="Remove this entire test block">X</button>
                    </div>
                    <table class="results-table responsive-table">
                        <thead>
                            <tr>
                                <th style="width:35%;">Parameter</th>
                                <th style="width:18%;">Value</th>
                                <th style="width:12%;">Unit</th>
                                <th style="width:18%;">Ref Range</th>
                                <th>Notes</th>
                                <th style="width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="result-row" data-row-index="0">
                                <td><input type="text" name="tests[${testIndex}][rows][0][parameter]" placeholder="Parameter Name"></td>
                                <td><input type="text" name="tests[${testIndex}][rows][0][value]"></td>
                                <td><input type="text" name="tests[${testIndex}][rows][0][unit]"></td>
                                <td><input type="text" name="tests[${testIndex}][rows][0][ref]"></td>
                                <td><input type="text" name="tests[${testIndex}][rows][0][notes]"></td>
                                <td><button type="button" class="btn-small red remove-row-btn">X</button></td>
                             </tr>
                        </tbody>
                    </table>
                    <div class="action-buttons">
                        <button type="button" class="btn-small grey add-row-btn">
                            <i class="material-icons left">add</i>Add Parameter Row
                        </button>
                    </div>
                `;
                testsContainer.appendChild(newTestBlock);
                 newTestBlock.querySelector('input[name$="[test_name]"]').focus();
            });
        }
   } // end if(testsContainer)

   // Initialize Materialize components
   M.AutoInit();

 });
 </script>

 </body>
 </html>